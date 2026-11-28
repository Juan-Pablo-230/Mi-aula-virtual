<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Helpers.php';
require_once __DIR__ . '/models/AcademicModel.php';
require_once __DIR__ . '/core/Acceso.php';

Auth::checkRole(ROLES_ALL);
$user = Auth::currentUser($pdo);
$rol  = $user['rol'];
$miInst = (int)($user['institucion_id'] ?? 0);
$esAdmin = esAdmin();

// ============================================================
// MATERIAS SEGÚN ROL
// ============================================================
// Alumno / familiar: las materias dependen del AÑO LECTIVO elegido (conservan acceso a los años anteriores)
$esAlumnoFam = in_array($rol, ['alumno', 'familiar'], true);
$añoSel = 0; $añosMat = []; $divsAño = [];
if ($esAlumnoFam) {
    $alumnosVista = $rol === 'alumno' ? [(int)$user['id']] : AcademicModel::alumnosDeFamiliar($pdo, (int)$user['id']);
    foreach ($alumnosVista as $aidV) $añosMat = array_merge($añosMat, AcademicModel::añosLectivosAlumno($pdo, $aidV));
    $cicloAct = $miInst ? AcademicModel::cicloActivo($pdo, $miInst) : null;
    if ($cicloAct && !in_array((int)$cicloAct['año'], $añosMat, true)) $añosMat[] = (int)$cicloAct['año'];
    $añosMat = array_values(array_unique($añosMat)); rsort($añosMat);
    $añoSel = (int)($_GET['año'] ?? 0);
    if (!in_array($añoSel, $añosMat, true)) $añoSel = $cicloAct ? (int)$cicloAct['año'] : (int)($añosMat[0] ?? date('Y'));

    $materias = []; $visto = [];
    foreach ($alumnosVista as $aidV) {
        foreach (AcademicModel::materiasAlumnoEnAño($pdo, $aidV, $añoSel) as $mv) {
            if (!isset($visto[$mv['id']])) { $visto[$mv['id']] = 1; $materias[] = $mv; }
        }
        // Cursadas del año: la que sigue vigente se marca como activa
        $stH = $pdo->prepare("SELECT h.division_id, MAX(CASE WHEN h.activo = 1 AND (h.ciclo_lectivo_id IS NULL OR cl.activo = 1) THEN 2 WHEN h.activo = 1 THEN 1 ELSE 0 END) AS activa
            FROM alumnos_division_historial h LEFT JOIN ciclos_lectivos cl ON cl.id = h.ciclo_lectivo_id
            WHERE h.alumno_id = ? AND h.año_lectivo = ? GROUP BY h.division_id");
        $stH->execute([$aidV, $añoSel]);
        foreach ($stH->fetchAll() as $hv) $divsAño[(int)$hv['division_id']] = max((int)($divsAño[(int)$hv['division_id']] ?? 0), (int)$hv['activa']);
        $stT = $pdo->prepare("SELECT COUNT(*) FROM alumnos_division_historial WHERE alumno_id = ?");
        $stT->execute([$aidV]);
        if ((int)$stT->fetchColumn() === 0) { // sin historial: se usa la cursada actual
            $stC = $pdo->prepare("SELECT division_id FROM alumnos_division WHERE alumno_id = ?");
            $stC->execute([$aidV]);
            foreach ($stC->fetchAll(PDO::FETCH_COLUMN) as $dv) $divsAño[(int)$dv] = 2;
        }
    }
    usort($materias, fn($a, $b) => strcasecmp($a['nombre'], $b['nombre']));
} elseif ($rol === 'docente') {
    $stmt = $pdo->prepare("SELECT DISTINCT m.id, m.nombre FROM asignaciones_docente ad JOIN materias m ON m.id = ad.materia_id WHERE ad.docente_id = ?" . sqlMateriaDeInstActiva('m.id') . " ORDER BY m.nombre");
    $stmt->execute([$user['id']]);
    $materias = $stmt->fetchAll();
} else {
    $stmt = $pdo->prepare("SELECT id, nombre FROM materias WHERE institucion_id = ? ORDER BY nombre");
    $stmt->execute([$miInst]);
    $materias = $stmt->fetchAll();
}

$materiaIdsPermitidas = array_map('intval', array_column($materias, 'id'));
$materiaId = (int)($_GET['id'] ?? 0);
if ($materiaId > 0 && !in_array($materiaId, $materiaIdsPermitidas)) redirect('/plataforma/materias.php' . ($esAlumnoFam ? '?año=' . $añoSel : ''));
$sufAño = $esAlumnoFam ? '&año=' . $añoSel : '';

// ============================================================
// CACHE DE AUTORES
// ============================================================
$autoresCache = [];
function nombreAutorMaterias(PDO $pdo, int $autorId, array &$cache): string {
    if (isset($cache[$autorId])) return $cache[$autorId];
    $stmt = $pdo->prepare("SELECT nombre, apellido FROM usuarios WHERE id = ?");
    $stmt->execute([$autorId]);
    $u = $stmt->fetch();
    $cache[$autorId] = $u ? (nombreCompleto($u)) : 'Docente';
    return $cache[$autorId];
}

function etiquetaCursada(int $e): string {
    if ($e === 2) return '<span style="color:#86efac;">● Activa</span>';
    if ($e === 1) return '<span style="color:#93c5fd;">Pendiente de activar</span>';
    return '<span style="color:#fbbf24;">Cursada cerrada</span>';
}

/** Alumno/familiar: deja solo las cursadas del año elegido y marca cuál sigue activa. */
function soloCursadasDelAño(array $divs, array $divsAño): array {
    $out = [];
    foreach ($divs as $d) {
        if (!isset($divsAño[(int)$d['id']])) continue;
        $d['estadoC'] = (int)$divsAño[(int)$d['id']]; $d['activa'] = $d['estadoC'] === 2;
        $out[] = $d;
    }
    return $out;
}

// ============================================================
// VISTA 1: GRILLA
// ============================================================
if ($materiaId === 0) {
    $materiasDivisiones = [];
    foreach ($materias as $m) {
        $mid = (int)$m['id'];
        $divisiones = [];

        $stmtCursos = $pdo->prepare("SELECT asignacion_id FROM materias_asignaciones WHERE materia_id = ?");
        $stmtCursos->execute([$mid]);
        $cursosIds = array_map('intval', $stmtCursos->fetchAll(PDO::FETCH_COLUMN));

        if (!empty($cursosIds)) {
            $ph = implode(',', array_fill(0, count($cursosIds), '?'));

            if ($rol === 'alumno') {
                $stmtDivsAlumno = $pdo->prepare("
                    SELECT DISTINCT division_id FROM alumnos_division_historial
                    WHERE alumno_id = ? AND division_id IN (
                        SELECT id FROM divisiones WHERE asignacion_id IN ($ph)
                    )
                ");
                $stmtDivsAlumno->execute(array_merge([(int)$user['id']], $cursosIds));
                $divsPermitidas = array_map('intval', $stmtDivsAlumno->fetchAll(PDO::FETCH_COLUMN));
                if (empty($divsPermitidas)) { $materiasDivisiones[$mid] = []; continue; }
                $phDivs = implode(',', array_fill(0, count($divsPermitidas), '?'));
                $stmt = $pdo->prepare("
                    SELECT d.id, d.nombre AS division, a.nombre AS curso, n.nombre AS nivel
                    FROM divisiones d
                    JOIN asignaciones a ON a.id = d.asignacion_id
                    JOIN niveles n ON n.id = a.nivel_id
                    WHERE d.id IN ($phDivs)
                    ORDER BY n.id, a.nombre, d.nombre
                ");
                $stmt->execute($divsPermitidas);
                $divisiones = $stmt->fetchAll();

            } elseif ($rol === 'familiar') {
                $alumnos = AcademicModel::alumnosDeFamiliar($pdo, (int)$user['id']);
                if (empty($alumnos)) { $materiasDivisiones[$mid] = []; continue; }
                $phA = implode(',', array_fill(0, count($alumnos), '?'));
                $stmtDivsFam = $pdo->prepare("
                    SELECT DISTINCT division_id FROM alumnos_division_historial
                    WHERE alumno_id IN ($phA) AND division_id IN (
                        SELECT id FROM divisiones WHERE asignacion_id IN ($ph)
                    )
                ");
                $stmtDivsFam->execute(array_merge($alumnos, $cursosIds));
                $divsPermitidas = array_map('intval', $stmtDivsFam->fetchAll(PDO::FETCH_COLUMN));
                if (empty($divsPermitidas)) { $materiasDivisiones[$mid] = []; continue; }
                $phDivs = implode(',', array_fill(0, count($divsPermitidas), '?'));
                $stmt = $pdo->prepare("
                    SELECT d.id, d.nombre AS division, a.nombre AS curso, n.nombre AS nivel
                    FROM divisiones d
                    JOIN asignaciones a ON a.id = d.asignacion_id
                    JOIN niveles n ON n.id = a.nivel_id
                    WHERE d.id IN ($phDivs)
                    ORDER BY n.id, a.nombre, d.nombre
                ");
                $stmt->execute($divsPermitidas);
                $divisiones = $stmt->fetchAll();

            } elseif ($rol === 'docente') {
                $stmt = $pdo->prepare("
                    SELECT DISTINCT d.id, d.nombre AS division, a.nombre AS curso, n.nombre AS nivel
                    FROM divisiones d
                    JOIN asignaciones a ON a.id = d.asignacion_id
                    JOIN niveles n ON n.id = a.nivel_id
                    JOIN asignaciones_docente ad ON ad.division_id = d.id
                    WHERE d.asignacion_id IN ($ph) AND ad.docente_id = ? AND ad.materia_id = ?
                    ORDER BY n.id, a.nombre, d.nombre
                ");
                $stmt->execute(array_merge($cursosIds, [(int)$user['id'], $mid]));
                $divisiones = $stmt->fetchAll();

            } else {
                $stmt = $pdo->prepare("
                    SELECT d.id, d.nombre AS division, a.nombre AS curso, n.nombre AS nivel
                    FROM divisiones d
                    JOIN asignaciones a ON a.id = d.asignacion_id
                    JOIN niveles n ON n.id = a.nivel_id
                    WHERE d.asignacion_id IN ($ph)
                    ORDER BY n.id, a.nombre, d.nombre
                ");
                $stmt->execute($cursosIds);
                $divisiones = $stmt->fetchAll();
            }
        }

        if ($esAlumnoFam) $divisiones = soloCursadasDelAño($divisiones, $divsAño);
        $materiasDivisiones[$mid] = $divisiones;
    }
    ?>
    <?php include __DIR__ . '/views/header.php'; ?>
    <?php include __DIR__ . '/views/menu.php'; ?>
    <main class="contenido-principal">
        <h1>📚 Materias</h1>
        <?php if ($esAlumnoFam): ?>
            <form method="get" class="panel-vidrio" style="padding:0.75rem 1rem; margin-bottom:1rem; display:flex; gap:1rem; align-items:center;">
                <label style="margin:0;">Año lectivo:
                    <select name="año" onchange="this.form.submit()">
                        <?php foreach ($añosMat as $a): ?><option value="<?= (int)$a ?>" <?= $a === $añoSel ? 'selected' : '' ?>><?= (int)$a ?></option><?php endforeach; ?>
                    </select>
                </label>
            </form>
            <?php if (empty($materias)): ?><p class="alerta info">No hay materias para el año <?= (int)$añoSel ?>.</p><?php endif; ?>
        <?php endif; ?>
        <div class="grid-materias">
            <?php foreach ($materias as $m):
                $mid = (int)$m['id'];
                $divs = $materiasDivisiones[$mid] ?? [];
                $tieneMultiples = count($divs) > 1;
            ?>
                <a href="javascript:void(0)"
                   class="panel-vidrio materia-item-link"
                   data-materia-id="<?= $mid ?>"
                   data-multiples="<?= $tieneMultiples ? '1' : '0' ?>"
                   data-divisiones="<?= h(json_encode($divs)) ?>" data-año="<?= (int)$añoSel ?>">
                    <div class="materia-item-icono">📖</div>
                    <h3><?= h($m['nombre']) ?></h3>
                    <?php if (count($divs) === 1): ?>
                        <small style="color:#a5c3e8;"><?= h($divs[0]['curso'] . ' ' . $divs[0]['division']) ?><?php if ($esAlumnoFam): ?> · <?= etiquetaCursada((int)($divs[0]['estadoC'] ?? 0)) ?><?php endif; ?></small>
                    <?php elseif (count($divs) > 1): ?>
                        <small style="color:#a5c3e8;"><?= count($divs) ?> cursadas disponibles</small>
                    <?php else: ?>
                        <small style="color:#a5c3e8;">Sin cursada asignada</small>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </main>

    <div class="modal-fondo oculto" id="modalCursada">
        <div class="modal-caja" style="min-width: 400px; max-width: 90vw;">
            <h3>Elegí una cursada</h3>
            <p style="color:#a5c3e8; font-size:0.85rem; margin-bottom:1rem;">Esta materia tiene varias cursadas. ¿A cuál querés acceder?</p>
            <div id="cursadaLista" style="display:flex; flex-direction:column; gap:0.5rem; max-height:24rem; overflow-y:auto;"></div>
            <div class="modal-acciones">
                <button type="button" class="btn-secundario" onclick="cerrarModal('modalCursada')">Cancelar</button>
            </div>
        </div>
    </div>

    <script>
    window.MATERIAS_URL_BASE = '/plataforma/materias.php';
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.materia-item-link').forEach(card => {
            card.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const materiaId = parseInt(this.dataset.materiaId, 10);
                const multiples = this.dataset.multiples === '1';
                let divisiones = [];
                try { divisiones = JSON.parse(this.dataset.divisiones || '[]'); } catch (err) {}
                window.AÑO_SEL = this.dataset['año'] || '';
                abrirMateria(materiaId, multiples, divisiones);
            });
        });
    });
    function abrirMateria(materiaId, multiples, divisiones) {
        if (!multiples || !divisiones || divisiones.length === 0) {
            const url = window.MATERIAS_URL_BASE + '?id=' + materiaId + (window.AÑO_SEL && window.AÑO_SEL !== '0' ? '&año=' + window.AÑO_SEL : '');
            window.location.href = divisiones && divisiones.length === 1 ? url + '&division_id=' + divisiones[0].id : url;
            return;
        }
        const lista = document.getElementById('cursadaLista');
        lista.innerHTML = divisiones.map(d => `
            <button type="button" data-division-id="${d.id}" data-materia-id="${materiaId}"
                    class="btn-secundario btn-cursada"
                    style="display:flex; align-items:center; gap:0.75rem; padding:0.75rem 1rem; text-align:left; width:100%; cursor:pointer;">
                <span style="font-size:1.2rem;">🏫</span>
                <span>
                    <strong style="display:block; color:#fff;">${escapeHtml(d.curso)} ${escapeHtml(d.division)}
                        ${d.estadoC === 2 ? '<span style="color:#86efac; font-size:0.8rem;">● Activa</span>' : (d.estadoC === 1 ? '<span style="color:#93c5fd; font-size:0.8rem;">Pendiente de activar</span>' : (d.estadoC === 0 ? '<span style="color:#fbbf24; font-size:0.8rem;">Cursada cerrada</span>' : ''))}</strong>
                    <small style="color:#a5c3e8;">${escapeHtml(d.nivel)}</small>
                </span>
            </button>
        `).join('');
        lista.querySelectorAll('.btn-cursada').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                window.location.href = window.MATERIAS_URL_BASE + '?id=' + this.dataset.materiaId + '&division_id=' + this.dataset.divisionId + (window.AÑO_SEL && window.AÑO_SEL !== '0' ? '&año=' + window.AÑO_SEL : '');
            });
        });
        document.getElementById('modalCursada').classList.remove('oculto');
    }
    function escapeHtml(s) {
        return String(s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }
    document.getElementById('modalCursada')?.addEventListener('click', function(e) { if (e.target === this) cerrarModal('modalCursada'); });
    document.addEventListener('keydown', function(e) { if (e.key === 'Escape') cerrarModal('modalCursada'); });
    </script>

    <?php include __DIR__ . '/views/footer.php'; ?>
    <?php exit;
}

// ============================================================
// VISTA 2: CONTENIDO
// ============================================================
$stmt = $pdo->prepare("SELECT id, nombre FROM materias WHERE id = ?");
$stmt->execute([$materiaId]);
$materia = $stmt->fetch();
if (!$materia) redirect('/plataforma/materias.php');

$divisionesTodas = [];
$stmtCursos = $pdo->prepare("SELECT asignacion_id FROM materias_asignaciones WHERE materia_id = ?");
$stmtCursos->execute([$materiaId]);
$cursosIds = array_map('intval', $stmtCursos->fetchAll(PDO::FETCH_COLUMN));

if (!empty($cursosIds)) {
    $ph = implode(',', array_fill(0, count($cursosIds), '?'));

    if ($rol === 'alumno') {
        $stmtDivsAlumno = $pdo->prepare("
            SELECT DISTINCT division_id FROM alumnos_division_historial
            WHERE alumno_id = ? AND division_id IN (SELECT id FROM divisiones WHERE asignacion_id IN ($ph))
        ");
        $stmtDivsAlumno->execute(array_merge([(int)$user['id']], $cursosIds));
        $divsPermitidas = array_map('intval', $stmtDivsAlumno->fetchAll(PDO::FETCH_COLUMN));
        if (!empty($divsPermitidas)) {
            $phDivs = implode(',', array_fill(0, count($divsPermitidas), '?'));
            $stmt = $pdo->prepare("SELECT d.id, d.nombre AS division, a.nombre AS curso, n.nombre AS nivel FROM divisiones d JOIN asignaciones a ON a.id = d.asignacion_id JOIN niveles n ON n.id = a.nivel_id WHERE d.id IN ($phDivs) ORDER BY n.id, a.nombre, d.nombre");
            $stmt->execute($divsPermitidas);
            $divisionesTodas = $stmt->fetchAll();
        }
    } elseif ($rol === 'familiar') {
        $alumnosFam = AcademicModel::alumnosDeFamiliar($pdo, (int)$user['id']);
        if (!empty($alumnosFam)) {
            $phA = implode(',', array_fill(0, count($alumnosFam), '?'));
            $stmtDivsFam = $pdo->prepare("
                SELECT DISTINCT division_id FROM alumnos_division_historial
                WHERE alumno_id IN ($phA) AND division_id IN (SELECT id FROM divisiones WHERE asignacion_id IN ($ph))
            ");
            $stmtDivsFam->execute(array_merge($alumnosFam, $cursosIds));
            $divsPermitidas = array_map('intval', $stmtDivsFam->fetchAll(PDO::FETCH_COLUMN));
            if (!empty($divsPermitidas)) {
                $phDivs = implode(',', array_fill(0, count($divsPermitidas), '?'));
                $stmt = $pdo->prepare("SELECT d.id, d.nombre AS division, a.nombre AS curso, n.nombre AS nivel FROM divisiones d JOIN asignaciones a ON a.id = d.asignacion_id JOIN niveles n ON n.id = a.nivel_id WHERE d.id IN ($phDivs) ORDER BY n.id, a.nombre, d.nombre");
                $stmt->execute($divsPermitidas);
                $divisionesTodas = $stmt->fetchAll();
            }
        }
    } elseif ($rol === 'docente') {
        $stmt = $pdo->prepare("
            SELECT DISTINCT d.id, d.nombre AS division, a.nombre AS curso, n.nombre AS nivel
            FROM divisiones d JOIN asignaciones a ON a.id = d.asignacion_id
            JOIN niveles n ON n.id = a.nivel_id
            JOIN asignaciones_docente ad ON ad.division_id = d.id
            WHERE d.asignacion_id IN ($ph) AND ad.docente_id = ? AND ad.materia_id = ?
            ORDER BY n.id, a.nombre, d.nombre
        ");
        $stmt->execute(array_merge($cursosIds, [(int)$user['id'], $materiaId]));
        $divisionesTodas = $stmt->fetchAll();
    } else {
        $stmt = $pdo->prepare("
            SELECT d.id, d.nombre AS division, a.nombre AS curso, n.nombre AS nivel
            FROM divisiones d JOIN asignaciones a ON a.id = d.asignacion_id
            JOIN niveles n ON n.id = a.nivel_id
            WHERE d.asignacion_id IN ($ph)
            ORDER BY n.id, a.nombre, d.nombre
        ");
        $stmt->execute($cursosIds);
        $divisionesTodas = $stmt->fetchAll();
    }
}

if ($esAlumnoFam) $divisionesTodas = soloCursadasDelAño($divisionesTodas, $divsAño);
$divisionSel = (int)($_GET['division_id'] ?? 0);
$divisionesIds = array_map('intval', array_column($divisionesTodas, 'id'));
if ($divisionSel <= 0 || !in_array($divisionSel, $divisionesIds)) {
    $divisionSel = !empty($divisionesTodas) ? (int)$divisionesTodas[0]['id'] : 0;
}

$divisionesVisibles = $divisionSel > 0 ? array_filter($divisionesTodas, fn($d) => $d['id'] == $divisionSel) : $divisionesTodas;
$tab = $_GET['tab'] ?? 'publicaciones';
if (!in_array($tab, ['publicaciones', 'tareas', 'bibliografia'])) $tab = 'publicaciones';

$estadoPorTarea = [];
if ($rol === 'alumno') {
    foreach ($mongoDB->selectCollection('entregas')->find(['alumno_id' => (int)$user['id']]) as $e) {
        $estadoPorTarea[(string)$e['tarea_id']] = $e['estado'];
    }
}

$datosDivisiones = [];
foreach ($divisionesVisibles as $div) {
    $filtroP = ['materia_id' => $materiaId, '$or' => [['divisiones_ids' => $div['id']], ['divisiones_ids' => ['$in' => [$div['id']]]]]];
    $publicaciones = $mongoPublicaciones->find($filtroP, ['sort' => ['fecha' => -1], 'limit' => 30])->toArray();
    $tareas = $mongoDB->selectCollection('tareas')->find(['materia_id' => $materiaId, 'divisiones_ids' => $div['id']], ['sort' => ['fecha_vencimiento' => -1], 'limit' => 30])->toArray();
    $tareas = Acceso::filtrarTareasParaUsuario($pdo, ['id' => (int)$user['id'], 'rol' => $rol], $tareas);
    $filtroB = ['materia_id' => $materiaId, '$or' => [['division_id' => null], ['division_id' => (int)$div['id']]]];
    $bibliografia = $mongoBibliografia->find($filtroB, ['sort' => ['fecha' => -1], 'limit' => 30])->toArray();
    $archivoIds = []; foreach ($bibliografia as $b) if (!empty($b['archivo_id'])) $archivoIds[] = $b['archivo_id'];
    $archivosBib = [];
    if (!empty($archivoIds)) foreach ($mongoArchivos->find(['_id' => ['$in' => $archivoIds]]) as $a) $archivosBib[(string)$a['_id']] = $a;
    $datosDivisiones[] = ['div' => $div, 'publicaciones' => $publicaciones, 'tareas' => $tareas, 'bibliografia' => $bibliografia, 'archivosBib' => $archivosBib];
}
?>
<?php include __DIR__ . '/views/header.php'; ?>
<?php include __DIR__ . '/views/menu.php'; ?>
<main class="contenido-principal">
    <div style="display:flex; align-items:center; gap:1rem; margin-bottom:1rem; flex-wrap:wrap;">
        <a href="/plataforma/materias.php<?= $esAlumnoFam ? '?año=' . (int)$añoSel : '' ?>" class="btn-secundario">← Volver</a>
        <h1 style="margin:0;">📖 <?= h($materia['nombre']) ?></h1>
    </div>

    <?php if (count($divisionesTodas) > 1): ?>
        <div class="panel-vidrio" style="padding:0.75rem 1rem; margin-bottom:1rem;">
            <form method="get" style="display:flex; gap:1rem; align-items:center;">
                <input type="hidden" name="id" value="<?= $materiaId ?>">
                <input type="hidden" name="tab" value="<?= h($tab) ?>">
                <?php if ($esAlumnoFam): ?><input type="hidden" name="año" value="<?= (int)$añoSel ?>"><?php endif; ?>
                <label style="margin:0;">División:
                    <select name="division_id" onchange="this.form.submit()">
                        <?php foreach ($divisionesTodas as $d): ?>
                            <option value="<?= (int)$d['id'] ?>" <?= $d['id'] == $divisionSel ? 'selected' : '' ?>><?= h($d['curso'] . ' ' . $d['division']) ?><?= $esAlumnoFam ? ' — ' . strip_tags(etiquetaCursada((int)($d['estadoC'] ?? 0))) : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </form>
        </div>
    <?php endif; ?>

    <nav class="admin-nav">
        <a href="?id=<?= $materiaId ?>&division_id=<?= $divisionSel ?>&tab=publicaciones<?= $sufAño ?>" class="admin-nav-item <?= $tab === 'publicaciones' ? 'activo' : '' ?>">📰 Publicaciones</a>
        <a href="?id=<?= $materiaId ?>&division_id=<?= $divisionSel ?>&tab=tareas<?= $sufAño ?>" class="admin-nav-item <?= $tab === 'tareas' ? 'activo' : '' ?>">📝 Tareas</a>
        <a href="?id=<?= $materiaId ?>&division_id=<?= $divisionSel ?>&tab=bibliografia<?= $sufAño ?>" class="admin-nav-item <?= $tab === 'bibliografia' ? 'activo' : '' ?>">📚 Bibliografía</a>
    </nav>

    <?php if (empty($datosDivisiones)): ?>
        <div class="panel-vidrio"><p class="alerta info">Sin divisiones.</p></div>
    <?php else: ?>
        <?php foreach ($datosDivisiones as $datos): $div = $datos['div']; ?>
            <section class="panel-vidrio" style="margin-bottom:1.5rem;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
                    <h2 style="margin:0;">🏫 <?= h($div['curso'] . ' ' . $div['division']) ?><?php if ($esAlumnoFam): ?> <small style="font-size:0.8rem;"><?= etiquetaCursada((int)($div['estadoC'] ?? 0)) ?></small><?php endif; ?></h2>
                </div>

                <?php if ($tab === 'publicaciones'): ?>
                    <?php if (empty($datos['publicaciones'])): ?><p class="alerta info">Sin publicaciones.</p>
                    <?php else: foreach ($datos['publicaciones'] as $p):
                        $autorNombre = !empty($p['autor_nombre']) ? $p['autor_nombre'] : (!empty($p['autor_id']) ? nombreAutorMaterias($pdo, (int)$p['autor_id'], $autoresCache) : 'Docente');
                        $esMia = !empty($p['autor_id']) && (int)$p['autor_id'] === (int)$user['id'];
                        $puedeEditar = esDocenteOAdmin() && ($esMia || $esAdmin);
                        $pubId = (string)$p['_id'];
                        $pubAño = isset($p['año_lectivo']) ? (int)$p['año_lectivo'] : (int)$p['fecha']->toDateTime()->format('Y');
                        $pubEditado = !empty($p['editado']);
                    ?>
                        <article class="panel-vidrio" style="margin-bottom:1rem;" data-pub-id="<?= h($pubId) ?>">
                            <div style="display:flex; justify-content:space-between; align-items:start; gap:0.5rem;">
                                <div style="flex:1; min-width:0;">
                                    <h4><?= h($p['titulo']) ?><?php if ($pubEditado): ?> <span style="font-size:0.7rem; font-style:italic; opacity:0.7; font-weight:400;">(editado)</span><?php endif; ?></h4>
                                    <small>Por <?= h($autorNombre) ?> · <?= $p['fecha']->toDateTime()->format('d/m/Y H:i') ?></small>
                                </div>
                                <?php if ($puedeEditar): ?>
                                    <div style="display:flex; gap:0.35rem; flex-shrink:0;">
                                        <button type="button" class="btn-secundario" style="padding:0.3rem 0.6rem; font-size:0.8rem;"
                                            onclick='abrirEditarPubMat(<?= jsonJs(["id" => $pubId, "titulo" => $p["titulo"], "contenido" => $p["contenido"], "año_lectivo" => $pubAño]) ?>)'>✏️</button>
                                        <button type="button" class="btn-peligro-sm" onclick="eliminarPubMat('<?= h($pubId) ?>')">🗑️</button>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <p><?= nl2br(h($p['contenido'])) ?></p>
                            <?php if (!empty($p['archivo_id'])): ?><a class="btn-secundario" href="/plataforma/descargar.php?id=<?= h((string)$p['archivo_id']) ?>" target="_blank">📎 <?= h($p['archivo_nombre']) ?></a><?php endif; ?>
                        </article>
                    <?php endforeach; endif; ?>

                <?php elseif ($tab === 'tareas'): ?>
                    <?php if (empty($datos['tareas'])): ?><p class="alerta info">Sin tareas.</p>
                    <?php else: ?>
                        <table class="tabla-alumnos">
                            <thead><tr><th>Título</th><th>Vence</th><?php if ($rol === 'alumno'): ?><th>Estado</th><?php endif; ?><th></th></tr></thead>
                            <tbody>
                                <?php foreach ($datos['tareas'] as $t): $fv = $t['fecha_vencimiento']->toDateTime(); $fv->setTimezone(new DateTimeZone('America/Argentina/Buenos_Aires')); ?>
                                    <tr>
                                        <td><?= h($t['titulo']) ?></td>
                                        <td><?= h($fv->format('d/m/Y H:i')) ?></td>
                                        <?php if ($rol === 'alumno'): ?><td><span class="badge"><?= h(ucfirst($estadoPorTarea[(string)$t['_id']] ?? 'pendiente')) ?></span></td><?php endif; ?>
                                        <td><a class="btn-secundario" href="/plataforma/tarea_detalle.php?id=<?= h((string)$t['_id']) ?>">Ver</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                <?php elseif ($tab === 'bibliografia'): ?>
                    <?php if (empty($datos['bibliografia'])): ?><p class="alerta info">Sin bibliografía.</p>
                    <?php else: ?>
                        <div class="grid-bibliografia">
                            <?php foreach ($datos['bibliografia'] as $b):
                                $arch = $datos['archivosBib'][(string)$b['archivo_id']] ?? null;
                                if (!$arch) continue;
                                $mime = $arch['mime'] ?? '';
                                $esImg = strpos($mime, 'image/') === 0; $esVid = strpos($mime, 'video/') === 0;
                                $esAud = strpos($mime, 'audio/') === 0; $esPdf = $mime === 'application/pdf';
                            ?>
                                <div class="panel-vidrio bib-card">
                                    <div class="bib-icono"><?= $esImg ? '🖼️' : ($esVid ? '🎬' : ($esAud ? '🎵' : ($esPdf ? '📄' : '📎'))) ?></div>
                                    <h4><?= h($arch['nombre_original']) ?></h4>
                                    <a class="btn-secundario" href="/plataforma/descargar.php?id=<?= h((string)$arch['_id']) ?>" <?= ($esPdf||$esImg||$esVid||$esAud) ? 'target="_blank"' : '' ?>>Ver</a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
</main>

<!-- Modal Editar Publicación (Vista 2) -->
<div class="modal-fondo oculto" id="modalEditarPubMat">
    <div class="modal-caja">
        <h3>✏️ Editar publicación</h3>
        <form id="formEditarPubMat">
            <input type="hidden" name="pub_id" id="editPubMatId">
            <label>Título <input type="text" name="titulo" id="editPubMatTitulo" maxlength="200" required></label>
            <label>Contenido <textarea name="contenido" id="editPubMatContenido" rows="5"></textarea></label>
            <label>Año lectivo
                <select name="año_lectivo" id="editPubMatAño" required>
                    <?php
                    $añosEdicion = [];
                    if (!empty($miInst)) {
                        $stA = $pdo->prepare("SELECT DISTINCT año FROM ciclos_lectivos WHERE institucion_id = ? ORDER BY año DESC");
                        $stA->execute([$miInst]);
                        $añosEdicion = array_map('intval', $stA->fetchAll(PDO::FETCH_COLUMN));
                    }
                    if (empty($añosEdicion)) $añosEdicion = [(int)date('Y')];
                    foreach ($añosEdicion as $aE): ?><option value="<?= $aE ?>"><?= $aE ?></option><?php endforeach; ?>
                </select>
            </label>
            <div id="editPubMatError" class="alerta danger" style="display:none;"></div>
            <div class="modal-acciones">
                <button type="button" class="btn-secundario" onclick="cerrarModal('modalEditarPubMat')">Cancelar</button>
                <button type="submit" class="btn-primario" id="btnGuardarEditPubMat">Guardar cambios</button>
            </div>
        </form>
    </div>
</div>

<script>
function abrirEditarPubMat(pub) {
    document.getElementById('editPubMatId').value = pub.id;
    document.getElementById('editPubMatTitulo').value = pub.titulo || '';
    document.getElementById('editPubMatContenido').value = pub.contenido || '';
    const selAñoMat = document.getElementById('editPubMatAño');
    const añoPubMat = String(pub.año_lectivo || new Date().getFullYear());
    if (![...selAñoMat.options].some(o => o.value === añoPubMat)) selAñoMat.add(new Option(añoPubMat, añoPubMat));
    selAñoMat.value = añoPubMat;
    document.getElementById('editPubMatError').style.display = 'none';
    document.getElementById('btnGuardarEditPubMat').disabled = false;
    document.getElementById('btnGuardarEditPubMat').textContent = 'Guardar cambios';
    document.getElementById('modalEditarPubMat').classList.remove('oculto');
}

document.getElementById('formEditarPubMat')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const err = document.getElementById('editPubMatError');
    const btn = document.getElementById('btnGuardarEditPubMat');
    if (err) err.style.display = 'none';
    if (btn) { btn.disabled = true; btn.textContent = 'Guardando...'; }
    try {
        const fd = new FormData(e.target);
        const r = await fetchConSesion('/plataforma/api/publicaciones_ajax.php?action=editar', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.ok) {
            mostrarToast('Publicación actualizada', 'ok');
            setTimeout(() => location.reload(), 700);
        } else {
            if (err) { err.textContent = d.mensaje || 'Error al guardar'; err.style.display = 'block'; }
            if (btn) { btn.disabled = false; btn.textContent = 'Guardar cambios'; }
        }
    } catch (ex) {
        if (ex.message === 'Sesión expirada') return;
        if (err) { err.textContent = 'Error de red: ' + ex.message; err.style.display = 'block'; }
        if (btn) { btn.disabled = false; btn.textContent = 'Guardar cambios'; }
    }
});

async function eliminarPubMat(id) {
    if (!confirm('¿Eliminar esta publicación? Esta acción no se puede deshacer.')) return;
    const fd = new FormData();
    fd.append('pub_id', id);
    try {
        const r = await fetchConSesion('/plataforma/api/publicaciones_ajax.php?action=eliminar_publicacion', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.ok) {
            mostrarToast('Publicación eliminada', 'ok');
            setTimeout(() => location.reload(), 700);
        } else {
            mostrarToast(d.mensaje || 'Error al eliminar', 'error');
        }
    } catch (ex) {
        if (ex.message === 'Sesión expirada') return;
        mostrarToast('Error de red: ' + ex.message, 'error');
    }
}
</script>

<?php include __DIR__ . '/views/footer.php'; ?>