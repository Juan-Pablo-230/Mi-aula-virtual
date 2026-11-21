<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Helpers.php';
require_once __DIR__ . '/core/FileHandler.php';
require_once __DIR__ . '/models/AcademicModel.php';

Auth::checkRole(ROLES_ALL);
$user = Auth::currentUser($pdo);
$rol  = $user['rol'];
$esInstitucional = in_array($rol, ['directivo', 'secretario', 'madre']);
$miInst = (int)($user['institucion_id'] ?? 0);
$esAdmin = esAdmin();
$puedeEditarEliminar = esDocenteOAdmin();

$cicloActivo = $miInst ? AcademicModel::cicloActivo($pdo, $miInst) : null;
$añoActual = $cicloActivo ? (int)$cicloActivo['año'] : (int)date('Y');

// AÑOS
$añosDisponibles = [];
if ($rol === 'alumno') {
    $añosDisponibles = AcademicModel::añosLectivosAlumno($pdo, (int)$user['id']);
} elseif ($rol === 'familiar') {
    foreach (AcademicModel::alumnosDeFamiliar($pdo, (int)$user['id']) as $aid) {
        foreach (AcademicModel::añosLectivosAlumno($pdo, $aid) as $a) {
            if (!in_array($a, $añosDisponibles)) $añosDisponibles[] = $a;
        }
    }
    rsort($añosDisponibles);
} else {
    if ($miInst) {
        $stmt = $pdo->prepare("SELECT DISTINCT año FROM ciclos_lectivos WHERE institucion_id = ? ORDER BY año DESC");
        $stmt->execute([$miInst]);
        $añosDisponibles = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
if (empty($añosDisponibles)) $añosDisponibles = [$añoActual];
$añoSel = (int)($_GET['año'] ?? $añoActual);
if (!in_array($añoSel, $añosDisponibles)) $añoSel = $añosDisponibles[0];

// MATERIAS
if ($rol === 'alumno') {
    $materias = AcademicModel::materiasAlumnoEnAño($pdo, (int)$user['id'], $añoSel);
    $divisionesEnAño = AcademicModel::divisionesDelAlumnoEnAño($pdo, (int)$user['id'], $añoSel);
} elseif ($rol === 'familiar') {
    $materias = []; $divisionesEnAño = [];
    foreach (AcademicModel::alumnosDeFamiliar($pdo, (int)$user['id']) as $aid) {
        foreach (AcademicModel::materiasAlumnoEnAño($pdo, $aid, $añoSel) as $m) $materias[$m['id']] = $m;
        foreach (AcademicModel::divisionesDelAlumnoEnAño($pdo, $aid, $añoSel) as $d) $divisionesEnAño[] = $d;
    }
    $materias = array_values($materias);
    $divisionesEnAño = array_values(array_unique($divisionesEnAño));
} elseif ($rol === 'docente') {
    $stmt = $pdo->prepare("SELECT m.id, m.nombre, GROUP_CONCAT(DISTINCT ad.division_id) AS divisiones FROM asignaciones_docente ad JOIN materias m ON m.id = ad.materia_id WHERE ad.docente_id = ? GROUP BY m.id, m.nombre ORDER BY m.nombre");
    $stmt->execute([$user['id']]);
    $filas = $stmt->fetchAll();
    $materias = [];
    foreach ($filas as $f) $materias[] = ['id' => (int)$f['id'], 'nombre' => $f['nombre'], 'divisiones' => $f['divisiones'] ? array_map('intval', explode(',', $f['divisiones'])) : []];
    $divisionesEnAño = [];
} else {
    $stmt = $pdo->prepare("SELECT m.id, m.nombre, GROUP_CONCAT(DISTINCT ad.division_id) AS divisiones FROM materias m LEFT JOIN asignaciones_docente ad ON ad.materia_id = m.id WHERE m.institucion_id = ? GROUP BY m.id, m.nombre ORDER BY m.nombre");
    $stmt->execute([$miInst]);
    $filas = $stmt->fetchAll();
    $materias = [];
    foreach ($filas as $f) $materias[] = ['id' => (int)$f['id'], 'nombre' => $f['nombre'], 'divisiones' => $f['divisiones'] ? array_map('intval', explode(',', $f['divisiones'])) : []];
    $divisionesEnAño = [];
}
$materiaIds = array_column($materias, 'id');

// CURSOS POR MATERIA
$materiasCursos = [];
if (!empty($materiaIds)) {
    $ph = implode(',', array_fill(0, count($materiaIds), '?'));
    $stmt = $pdo->prepare("SELECT materia_id, asignacion_id FROM materias_asignaciones WHERE materia_id IN ($ph)");
    $stmt->execute($materiaIds);
    foreach ($stmt as $row) $materiasCursos[(int)$row['materia_id']][] = (int)$row['asignacion_id'];
}

$divisionesPorCurso = [];
$stmt = $pdo->prepare("SELECT d.id, d.nombre AS division, a.nombre AS curso, a.id AS asignacion_id FROM divisiones d JOIN asignaciones a ON a.id = d.asignacion_id WHERE a.institucion_id = ? ORDER BY a.nombre, d.nombre");
$stmt->execute([$miInst]);
foreach ($stmt as $row) $divisionesPorCurso[] = $row;

$divisionesDocentePorMateria = [];
if ($rol === 'docente') {
    $stmt = $pdo->prepare("SELECT materia_id, division_id FROM asignaciones_docente WHERE docente_id = ?");
    $stmt->execute([$user['id']]);
    foreach ($stmt as $row) $divisionesDocentePorMateria[(int)$row['materia_id']][] = (int)$row['division_id'];
}

// FILTRO
$andConditions = [];

if ($rol === 'alumno') {
    $andConditions[] = ['$or' => [
        ['materia_id' => ['$in' => array_map('intval', $materiaIds)], 'año_lectivo' => $añoSel, 'divisiones_ids' => ['$in' => $divisionesEnAño]],
        ['institucional' => true, 'año_lectivo' => $añoSel],
    ]];
} elseif ($rol === 'familiar') {
    $andConditions[] = ['$or' => [
        ['materia_id' => ['$in' => array_map('intval', $materiaIds)], 'año_lectivo' => $añoSel, 'divisiones_ids' => ['$in' => $divisionesEnAño]],
        ['institucional' => true, 'año_lectivo' => $añoSel],
    ]];
} elseif ($rol === 'docente') {
    $andConditions[] = ['$or' => [
        ['materia_id' => ['$in' => array_map('intval', $materiaIds)]],
        ['institucional' => true],
    ]];
    $andConditions[] = ['año_lectivo' => ['$exists' => true]];
} elseif ($rol === 'madre') {
    $instFiltro = $_GET['institucion_id'] ?? '';
    if ($instFiltro !== '' && $instFiltro !== '0' && $instFiltro !== 'todas') {
        $andConditions[] = ['institucion_destino_id' => (int)$instFiltro];
    }
} else {
    if ($miInst) {
        $stmt = $pdo->prepare("SELECT id FROM materias WHERE institucion_id = ?");
        $stmt->execute([$miInst]);
        $matIdsInst = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $andConditions[] = ['$or' => [
            ['materia_id' => ['$in' => $matIdsInst]],
            ['institucional' => true],
        ]];
    } else {
        $andConditions[] = ['_id' => ['$in' => []]];
    }
}

if (empty($andConditions)) {
    $filtroFinal = ($rol === 'madre') ? [] : ['_id' => ['$in' => []]];
} elseif (count($andConditions) === 1) {
    $filtroFinal = $andConditions[0];
} else {
    $filtroFinal = ['$and' => $andConditions];
}

$pubsRaw = $mongoPublicaciones->find($filtroFinal, ['sort' => ['fecha' => -1], 'limit' => 200])->toArray();

// Filtro por institución y roles (post-consulta)
$pubsRaw = array_values(array_filter($pubsRaw, function($p) use ($rol, $miInst) {
    $pInst = $p['institucion_destino_id'] ?? null;
    $pRoles = $p['roles_destino'] ?? [];
    if ($rol === 'madre') return true;
    if ($pInst !== null && (int)$pInst !== (int)$miInst) return false;
    if (!empty($pRoles) && is_array($pRoles) && !in_array($rol, $pRoles)) return false;
    return true;
}));

$pubs = [];
$autoresCache = [];
foreach ($pubsRaw as $p) {
    $institucional = !empty($p['institucional']);
    $añoPub = isset($p['año_lectivo']) ? (int)$p['año_lectivo'] : (isset($p['fecha']) ? (int)$p['fecha']->toDateTime()->format('Y') : null);
    if (in_array($rol, ['alumno', 'familiar']) && $añoPub !== null && $añoPub !== $añoSel) continue;

    if ($institucional) $materiaNombre = null;
    else {
        $stmt = $pdo->prepare("SELECT nombre FROM materias WHERE id = ?");
        $stmt->execute([$p['materia_id']]);
        $materiaNombre = $stmt->fetchColumn() ?: 'Materia';
    }

    $autorNombre = !empty($p['autor_nombre']) ? $p['autor_nombre'] : null;
    if (!$autorNombre && !empty($p['autor_id'])) {
        $aid = (int)$p['autor_id'];
        if (!isset($autoresCache[$aid])) {
            $stmt = $pdo->prepare("SELECT nombre, apellido FROM usuarios WHERE id = ?");
            $stmt->execute([$aid]);
            $a = $stmt->fetch();
            $autoresCache[$aid] = $a ? ($a['apellido'] . ', ' . $a['nombre']) : 'Desconocido';
        }
        $autorNombre = $autoresCache[$aid];
    }
    if (!$autorNombre) $autorNombre = 'Desconocido';

    $divsNombres = [];
    $divsIds = $p['divisiones_ids'] ?? [];
    if (is_object($divsIds)) $divsIds = (array)$divsIds;
    $divsIds = array_map('intval', $divsIds);
    if (!empty($divsIds)) {
        $placeholders = implode(',', array_fill(0, count($divsIds), '?'));
        $stmt = $pdo->prepare("SELECT d.nombre AS division, a.nombre AS curso FROM divisiones d JOIN asignaciones a ON a.id = d.asignacion_id WHERE d.id IN ($placeholders)");
        $stmt->execute($divsIds);
        foreach ($stmt as $d) $divsNombres[] = $d['curso'] . ' ' . $d['division'];
    }

    $pubs[] = [
        'id' => (string)$p['_id'],
        'titulo' => $p['titulo'],
        'contenido' => $p['contenido'],
        'institucional' => $institucional,
        'año_lectivo' => $añoPub,
        'materia_nombre' => $materiaNombre,
        'autor_nombre' => $autorNombre,
        'autor_id' => (int)$p['autor_id'],
        'fecha' => $p['fecha']->toDateTime()->format('d/m/Y H:i'),
        'editado' => !empty($p['editado']),
        'institucion_destino_id' => $p['institucion_destino_id'] ?? null,
        'roles_destino' => $p['roles_destino'] ?? [],
        'archivo_id' => $p['archivo_id'] ?? null,
        'archivo_nombre' => $p['archivo_nombre'] ?? null,
        'vt_estado' => $p['vt_estado'] ?? 'ok',
        'divisiones' => $divsNombres,
    ];
}
?>
<?php include __DIR__ . '/views/header.php'; ?>
<?php include __DIR__ . '/views/menu.php'; ?>
<main class="contenido-principal">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem; margin-bottom:1rem;">
        <h1 style="margin:0;">📰 Publicaciones</h1>
        <?php if (esDocenteOAdmin()): ?>
            <button class="btn-primario" onclick="abrirModalPub()">➕ Nueva publicación</button>
        <?php endif; ?>
    </div>

    <?php if ($rol === 'madre'): ?>
        <div class="panel-vidrio" style="padding:0.75rem 1rem; margin-bottom:1rem; display:flex; align-items:center; gap:1rem; flex-wrap:wrap;">
            <strong style="color:#7dd3fc;">Ver institución:</strong>
            <form method="get" style="display:flex; gap:0.5rem; align-items:center;">
                <input type="hidden" name="año" value="<?= $añoSel ?>">
                <?php $instituciones = $pdo->query("SELECT id, nombre FROM instituciones WHERE activo = 1 ORDER BY nombre")->fetchAll(); ?>
                <?php $instFiltroSel = $_GET['institucion_id'] ?? 'todas'; ?>
                <select name="institucion_id" onchange="this.form.submit()" style="padding:0.4rem 0.6rem; border-radius:0.5rem; border:0.0625rem solid var(--borde-vidrio); background:rgba(255,255,255,0.06); color:#fff;">
                    <option value="todas" <?= $instFiltroSel === 'todas' ? 'selected' : '' ?>>— Todas las instituciones —</option>
                    <?php foreach ($instituciones as $i): ?>
                        <option value="<?= (int)$i['id'] ?>" <?= (string)$instFiltroSel === (string)$i['id'] ? 'selected' : '' ?>><?= h($i['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    <?php endif; ?>

    <?php if (count($añosDisponibles) > 1): ?>
        <div class="panel-vidrio" style="padding:0.75rem 1rem; margin-bottom:1rem; display:flex; align-items:center; gap:1rem; flex-wrap:wrap;">
            <strong style="color:#7dd3fc;">Ver año:</strong>
            <div style="display:flex; gap:0.35rem; flex-wrap:wrap;">
                <?php foreach ($añosDisponibles as $a): ?>
                    <a href="?año=<?= $a ?><?= $rol === 'madre' && isset($_GET['institucion_id']) ? '&institucion_id=' . h($_GET['institucion_id']) : '' ?>" class="btn-mini <?= $a === $añoSel ? 'activo' : '' ?>" style="<?= $a === $añoSel ? 'background:var(--celeste);color:#fff;' : '' ?>">
                        <?= $a ?><?= ($cicloActivo && (int)$cicloActivo['año'] === $a) ? ' ·' : '' ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="feed-publicaciones">
        <?php foreach ($pubs as $p):
            $esAutor = ((int)$p['autor_id'] === (int)$user['id']);
            $puedeEditarEsta = $puedeEditarEliminar && ($esAutor || $esInstitucional || $esAdmin);
        ?>
            <article class="panel-vidrio publicacion <?= !empty($p['institucional']) ? 'publicacion-institucional' : '' ?>" data-pub-id="<?= h($p['id']) ?>">
                <?php if (!empty($p['institucional'])): ?>
                    <div class="badge-institucional">📢 Institucional</div>
                    <?php if ($rol === 'madre'): ?>
                        <?php
                        $instDestNom = 'Todas las instituciones';
                        if (!empty($p['institucion_destino_id'])) {
                            $stmt = $pdo->prepare("SELECT nombre FROM instituciones WHERE id = ?");
                            $stmt->execute([(int)$p['institucion_destino_id']]);
                            $instDestNom = $stmt->fetchColumn() ?: 'Institución desconocida';
                        }
                        $rolesDestNom = !empty($p['roles_destino']) ? implode(', ', array_map('ucfirst', $p['roles_destino'])) : 'Todos los roles';
                        ?>
                        <div style="font-size:0.72rem; color:#a5c3e8; margin-bottom:0.4rem;">
                            🎯 <?= h($instDestNom) ?> · 👥 <?= h($rolesDestNom) ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($p['año_lectivo'] && $p['año_lectivo'] !== $añoActual): ?>
                    <div class="badge-año" style="display:inline-block; background:#a855f7; color:#fff; font-size:0.7rem; padding:0.2rem 0.6rem; border-radius:0.5rem; margin-bottom:0.4rem; font-weight:700;">
                        Año lectivo <?= (int)$p['año_lectivo'] ?>
                    </div>
                <?php endif; ?>
                <div style="display:flex; justify-content:space-between; align-items:start; gap:0.5rem;">
                    <div style="flex:1; min-width:0;">
                        <h3><?= h($p['titulo']) ?><?php if (!empty($p['editado'])): ?> <span style="font-size:0.7rem; font-style:italic; opacity:0.7; font-weight:400;">(editado)</span><?php endif; ?></h3>
                        <small>Por <?= h($p['autor_nombre']) ?> · <?= h($p['fecha']) ?><?php if (!empty($p['materia_nombre'])): ?> · <?= h($p['materia_nombre']) ?><?php endif; ?></small>
                    </div>
                    <?php if ($puedeEditarEsta): ?>
                        <div style="display:flex; gap:0.35rem; flex-shrink:0;">
                            <button type="button" class="btn-secundario" style="padding:0.3rem 0.6rem; font-size:0.8rem;"
                                onclick='abrirEditarPub(<?= json_encode(["id" => $p["id"], "titulo" => $p["titulo"], "contenido" => $p["contenido"], "año_lectivo" => $p["año_lectivo"]]) ?>)'>✏️</button>
                            <button type="button" class="btn-peligro-sm" onclick="eliminarPublicacion('<?= h($p['id']) ?>')">🗑️</button>
                        </div>
                    <?php endif; ?>
                </div>
                <p><?= nl2br(h($p['contenido'])) ?></p>
                <?php if (!empty($p['divisiones'])): ?>
                    <div class="enviado-para"><strong>Enviado para:</strong> <?= h(implode(', ', $p['divisiones'])) ?></div>
                <?php endif; ?>
                <?php if (!empty($p['archivo_id'])): ?>
                    <div style="margin-top:0.75rem;">
                        <?php if ($p['vt_estado'] === 'sospechoso' && $p['autor_id'] != $user['id']): ?>
                            <div class="alerta warn">⚠️ Archivo marcado como sospechoso por VirusTotal.</div>
                        <?php endif; ?>
                        <a class="btn-secundario" href="/plataforma/descargar.php?id=<?= h((string)$p['archivo_id']) ?>" target="_blank">📎 <?= h($p['archivo_nombre']) ?></a>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
        <?php if (empty($pubs)): ?><p class="alerta info">No hay publicaciones.</p><?php endif; ?>
    </div>
</main>

<?php if (esDocenteOAdmin()): ?>
<div class="modal-fondo oculto" id="modalPub">
    <div class="modal-caja">
        <h3>Nueva publicación</h3>
        <form id="formPub" enctype="multipart/form-data">
            <?php if ($esInstitucional): ?>
                <label class="label-checkbox">
                    <input type="checkbox" name="institucional" value="1" id="chkInstitucional" onchange="toggleInstitucional()">
                    <span>📢 <strong>Publicación institucional</strong> (visible para todos, sin materia)</span>
                </label>
            <?php endif; ?>

            <?php if ($rol === 'madre'): ?>
                <div id="seccionDestinoInstitucional" style="display:none; padding:0.75rem 1rem; background:rgba(0,0,0,0.15); border-radius:0.5rem; margin-bottom:0.5rem;">
                    <label>Institución destino
                        <select name="institucion_destino_id" id="pubInstitucionDestino">
                            <option value="todas">— Todas las instituciones —</option>
                            <?php
                            $instituciones = $pdo->query("SELECT id, nombre FROM instituciones WHERE activo = 1 ORDER BY nombre")->fetchAll();
                            foreach ($instituciones as $i):
                            ?>
                                <option value="<?= (int)$i['id'] ?>"><?= h($i['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Roles destino
                        <small style="color:#a5c3e8; font-size:0.75rem; display:block; margin-bottom:0.35rem;">
                            Si no marcás ninguno, todos los roles verán la publicación.
                        </small>
                        <div style="display:flex; flex-wrap:wrap; gap:0.5rem; padding:0.5rem; background:rgba(0,0,0,0.15); border-radius:0.5rem;">
                            <?php foreach (['directivo' => 'Directivos', 'secretario' => 'Secretarios', 'docente' => 'Docentes', 'alumno' => 'Alumnos', 'familiar' => 'Familiares'] as $rKey => $rLabel): ?>
                                <label class="label-checkbox" style="margin:0; padding:0.35rem 0.6rem; background:rgba(255,255,255,0.05); border-radius:0.35rem;">
                                    <input type="checkbox" name="roles_destino[]" value="<?= $rKey ?>">
                                    <span><?= $rLabel ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </label>
                </div>
            <?php endif; ?>

            <div id="seccionMateria">
                <label>Materia
                    <select name="materia_id" id="pubMateria" onchange="actualizarDivisiones()">
                        <option value="">— Elegí una materia —</option>
                        <?php foreach ($materias as $m): ?>
                            <option value="<?= (int)$m['id'] ?>"
                                    data-cursos="<?= implode(',', $materiasCursos[(int)$m['id']] ?? []) ?>"
                                    data-divisiones-docente="<?= $rol === 'docente' ? implode(',', $divisionesDocentePorMateria[(int)$m['id']] ?? []) : '' ?>">
                                <?= h($m['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>División
                    <select name="division_id" id="pubDivision">
                        <option value="">— Elegí una materia primero —</option>
                    </select>
                </label>

                <label class="label-checkbox">
                    <input type="checkbox" name="aplicar_a_todas" value="1" id="chkAplicarTodas"
                           onchange="
                               var sel = document.getElementById('pubDivision');
                               if (!sel) return;
                               if (this.checked) {
                                   sel.selectedIndex = 0;
                                   sel.style.pointerEvents = 'none';
                                   sel.style.opacity = '0.5';
                                   sel.style.cursor = 'not-allowed';
                                   sel.removeAttribute('required');
                               } else {
                                   sel.style.pointerEvents = '';
                                   sel.style.opacity = '';
                                   sel.style.cursor = '';
                                   sel.setAttribute('required', 'required');
                               }
                           ">
                    <span>Publicar en <strong>todas las divisiones</strong> donde dicto esta materia</span>
                </label>
            </div>

            <?php if (count($añosDisponibles) > 1): ?>
                <label>Año lectivo
                    <select name="año_lectivo" required>
                        <?php foreach ($añosDisponibles as $a): ?>
                            <option value="<?= $a ?>" <?= $a === $añoSel ? 'selected' : '' ?>>
                                <?= $a ?><?= ($cicloActivo && (int)$cicloActivo['año'] === $a) ? ' (actual)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php else: ?>
                <input type="hidden" name="año_lectivo" value="<?= (int)$añoActual ?>">
            <?php endif; ?>

            <label>Título <input type="text" name="titulo" maxlength="200" required></label>
            <label>Contenido <textarea name="contenido" rows="5"></textarea></label>
            <label>Adjunto (opcional)
                <input type="file" name="archivo" accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip,.rar,.7z">
            </label>

            <div id="pubError" class="alerta danger" style="display:none;"></div>
            <div class="modal-acciones">
                <button type="button" class="btn-secundario" onclick="cerrarModal('modalPub')">Cancelar</button>
                <button type="submit" class="btn-primario">Publicar</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-fondo oculto" id="modalEditarPub">
    <div class="modal-caja">
        <h3>✏️ Editar publicación</h3>
        <form id="formEditarPub">
            <input type="hidden" name="pub_id" id="editPubId">
            <label>Título <input type="text" name="titulo" id="editPubTitulo" maxlength="200" required></label>
            <label>Contenido <textarea name="contenido" id="editPubContenido" rows="5"></textarea></label>
            <label>Año lectivo <input type="number" name="año_lectivo" id="editPubAño" min="2020" max="2100" required></label>
            <div id="editPubError" class="alerta danger" style="display:none;"></div>
            <div class="modal-acciones">
                <button type="button" class="btn-secundario" onclick="cerrarModal('modalEditarPub')">Cancelar</button>
                <button type="submit" class="btn-primario" id="btnGuardarEditPub">Guardar cambios</button>
            </div>
        </form>
    </div>
</div>
<script>
window.CICLO_ACTIVO_AÑO = <?= (int)$añoActual ?>;
window.DIVISIONES_POR_CURSO = <?= json_encode($divisionesPorCurso) ?>;
</script>
<script src="/assets/js/publicaciones.js?v=<?= time() ?>"></script>
<?php endif; ?>
<?php include __DIR__ . '/views/footer.php'; ?>