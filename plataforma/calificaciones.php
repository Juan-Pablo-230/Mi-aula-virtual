<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Helpers.php';
require_once __DIR__ . '/core/GradeHelpers.php';
require_once __DIR__ . '/models/AcademicModel.php';
require_once __DIR__ . '/core/MongoIndices.php';

MongoIndices::asegurar($mongoDB); // índices que aceleran la carga (una sola vez)

Auth::checkRole(ROLES_ALL);
$user = Auth::currentUser($pdo);
$rol  = $user['rol'];
$userId = (int)$user['id'];
$miInst = (int)($user['institucion_id'] ?? 0);
$puedeCalificar = in_array($rol, ROLES_DOCENTES);

$cicloActivo = $miInst ? AcademicModel::cicloActivo($pdo, $miInst) : null;
$añoActual = $cicloActivo ? (int)$cicloActivo['año'] : (int)date('Y');

// ============================================================
// AÑOS DISPONIBLES
// ============================================================
$añosDisponibles = [];
if ($rol === 'alumno') {
    $añosDisponibles = AcademicModel::añosLectivosAlumno($pdo, $userId);
} elseif ($rol === 'familiar') {
    foreach (AcademicModel::alumnosDeFamiliar($pdo, $userId) as $aid) {
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

// ============================================================
// MATERIAS SEGÚN ROL Y AÑO
// ============================================================
if ($rol === 'alumno') {
    $materias = AcademicModel::materiasAlumnoEnAño($pdo, $userId, $añoSel);
} elseif ($rol === 'familiar') {
    $alumnosIds = AcademicModel::alumnosDeFamiliar($pdo, $userId);
    $materias = [];
    foreach ($alumnosIds as $aid) {
        foreach (AcademicModel::materiasAlumnoEnAño($pdo, $aid, $añoSel) as $m) {
            $materias[$m['id']] = $m;
        }
    }
    $materias = array_values($materias);
} elseif ($rol === 'docente') {
    $stmt = $pdo->prepare("SELECT DISTINCT m.id, m.nombre FROM asignaciones_docente ad JOIN materias m ON m.id = ad.materia_id WHERE ad.docente_id = ?" . sqlMateriaDeInstActiva('m.id') . " ORDER BY m.nombre");
    $stmt->execute([$userId]);
    $materias = $stmt->fetchAll();
} else {
    $stmt = $pdo->prepare("SELECT id, nombre FROM materias WHERE institucion_id = ? ORDER BY nombre");
    $stmt->execute([$miInst]);
    $materias = $stmt->fetchAll();
}

$materiaIdsPermitidas = array_map('intval', array_column($materias, 'id'));
$materiaId = (int)($_GET['id'] ?? 0);
if ($materiaId > 0 && !in_array($materiaId, $materiaIdsPermitidas)) redirect('/plataforma/calificaciones.php');

if ($materiaId === 0) {
    ?>
    <?php include __DIR__ . '/views/header.php'; ?>
    <?php include __DIR__ . '/views/menu.php'; ?>
    <main class="contenido-principal">
        <h1>📊 Calificaciones</h1>

        <?php if (count($añosDisponibles) > 1): ?>
            <div class="panel-vidrio" style="padding:0.75rem 1rem; margin-bottom:1rem; display:flex; align-items:center; gap:1rem; flex-wrap:wrap;">
                <strong style="color:#7dd3fc;">Ver año:</strong>
                <div style="display:flex; gap:0.35rem; flex-wrap:wrap;">
                    <?php foreach ($añosDisponibles as $a): ?>
                        <a href="?año=<?= $a ?>" class="btn-mini" style="<?= $a === $añoSel ? 'background:var(--celeste);color:#fff;' : 'background:rgba(255,255,255,0.08);color:#cfe2ff;' ?>">
                            <?= $a ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="grid-materias">
            <?php foreach ($materias as $m): ?>
                <a href="?id=<?= (int)$m['id'] ?>&año=<?= $añoSel ?>" class="panel-vidrio materia-item-link">
                    <div class="materia-item-icono">📊</div>
                    <h3><?= h($m['nombre']) ?></h3>
                </a>
            <?php endforeach; ?>
        </div>
        <?php if (empty($materias)): ?>
            <p class="alerta info">No hay materias para el año <?= $añoSel ?>.</p>
        <?php endif; ?>

    </main>
    <?php include __DIR__ . '/views/footer.php'; ?>
    <?php exit;
}

$stmt = $pdo->prepare("SELECT id, nombre FROM materias WHERE id = ?"); $stmt->execute([$materiaId]); $materia = $stmt->fetch();

if ($rol === 'alumno') {
    $alumnos = [['id' => $userId, 'nombre' => $user['nombre'], 'apellido' => $user['apellido']]];
} elseif ($rol === 'familiar') {
    $ids = AcademicModel::alumnosDeFamiliar($pdo, $userId);
    $alumnos = [];
    foreach ($ids as $aid) { $stmt = $pdo->prepare("SELECT id, nombre, apellido FROM usuarios WHERE id = ?"); $stmt->execute([$aid]); $a = $stmt->fetch(); if ($a) $alumnos[] = $a; }
} else {
    if ($rol === 'docente') $stmt = $pdo->prepare("SELECT DISTINCT d.id FROM asignaciones_docente ad JOIN divisiones d ON d.id = ad.division_id WHERE ad.docente_id = ? AND ad.materia_id = ?");
    else $stmt = $pdo->prepare("SELECT DISTINCT d.id FROM asignaciones_docente ad JOIN divisiones d ON d.id = ad.division_id JOIN asignaciones a ON a.id = d.asignacion_id WHERE ad.materia_id = ? AND a.institucion_id = ?");
    $stmt->execute($rol === 'docente' ? [$userId, $materiaId] : [$materiaId, $miInst]);
    $divIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $alumnos = [];
    if (!empty($divIds)) {
        $ph = implode(',', array_fill(0, count($divIds), '?'));
        $stmt = $pdo->prepare("
            SELECT DISTINCT u.id, u.nombre, u.apellido
            FROM usuarios u
            JOIN alumnos_division_historial h ON h.alumno_id = u.id AND h.activo = 1 AND h.año_lectivo = ?
            WHERE h.division_id IN ($ph) AND u.rol = 'alumno' AND u.activo = 1
            " . AcademicModel::sqlCursaMateria('u.id', 'h.division_id', (string)(int)$materiaId) . "
            ORDER BY u.apellido, u.nombre
        ");
        $stmt->execute(array_merge([$añoSel], $divIds));
        $alumnos = $stmt->fetchAll();
    }
}

// Una sola tanda de consultas para todos los alumnos (antes eran varias por alumno y por tarea)
$alumnosIdsLote = array_map(fn($a) => (int)$a['id'], $alumnos);
$calsLote  = obtenerCalificacionesLote($mongoDB, $alumnosIdsLote, $materiaId, $añoSel);
$confLote  = obtenerPromediosConfirmadosLote($mongoDB, $alumnosIdsLote, $materiaId, $añoSel);
$datos = [];
foreach ($alumnos as $a) {
    $aid  = (int)$a['id'];
    $cals = $calsLote[$aid] ?? [];
    $prom = calcularPromedio($cals);
    $conf = $confLote[$aid] ?? null;
    $datos[] = ['alumno' => $a, 'cals' => $cals, 'promedio' => $prom, 'conf' => $conf, 'confirmado' => $conf && !empty($conf['confirmado'])];
}
?>
<?php include __DIR__ . '/views/header.php'; ?>
<?php include __DIR__ . '/views/menu.php'; ?>
<main class="contenido-principal">
    <div style="display:flex; align-items:center; gap:1rem; margin-bottom:1rem; flex-wrap:wrap;">
        <a href="/plataforma/calificaciones.php?año=<?= $añoSel ?>" class="btn-secundario">← Volver</a>
        <h1 style="margin:0;">📊 <?= h($materia['nombre']) ?> — <?= $añoSel ?></h1>
        <?php if ($puedeCalificar && $alumnos): ?>
            <button class="btn-primario" style="margin-left:auto;" onclick="abrirNuevaGlobal()">🌐 Calificación global</button>
        <?php endif; ?>
    </div>
    <?php if ($puedeCalificar && $alumnos): ?>
        <p style="color:#a5c3e8; font-size:0.85rem; margin-top:-0.5rem;">Una calificación global (por ejemplo, Conducta) se crea una vez y se carga a todos los alumnos con la nota que elijas; después podés corregir la de cada uno.</p>
    <?php endif; ?>

    <?php if (count($añosDisponibles) > 1): ?>
        <div class="panel-vidrio" style="padding:0.75rem 1rem; margin-bottom:1rem; display:flex; align-items:center; gap:1rem; flex-wrap:wrap;">
            <strong style="color:#7dd3fc;">Ver año:</strong>
            <div style="display:flex; gap:0.35rem; flex-wrap:wrap;">
                <?php foreach ($añosDisponibles as $a): ?>
                    <a href="?id=<?= $materiaId ?>&año=<?= $a ?>" class="btn-mini" style="<?= $a === $añoSel ? 'background:var(--celeste);color:#fff;' : 'background:rgba(255,255,255,0.08);color:#cfe2ff;' ?>">
                        <?= $a ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php foreach ($datos as $d): $a = $d['alumno']; $prom = $d['promedio']; $conf = $d['confirmado']; ?>
        <div class="panel-vidrio" style="margin-bottom:1.5rem;">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.75rem;">
                <h3 style="margin:0;"><?= $rol !== 'alumno' ? '👤 ' . h(nombreCompleto($a)) : '👤 Mis calificaciones' ?></h3>
                <?php if ($puedeCalificar): ?>
                    <button class="btn-secundario" onclick="abrirAgregarExtra(<?= (int)$a['id'] ?>)">➕ Cargar nota</button>
                <?php endif; ?>
            </div>
            <?php if (empty($d['cals'])): ?>
                <p class="alerta info" style="margin-top:1rem;">Sin calificaciones.</p>
            <?php else: ?>
                <table class="tabla-alumnos">
                    <thead><tr><th>Origen</th><th>Nota</th><?php if ($puedeCalificar): ?><th></th><?php endif; ?></tr></thead>
                    <tbody>
                        <?php foreach ($d['cals'] as $c): ?>
                            <tr>
                                <td><?= $c['origen'] === 'tarea' ? '📝 ' : (!empty($c['global_id']) ? '🌐 ' : '➕ ') ?><?= h($c['concepto']) ?></td>
                                <td><?= h(formatoNota(notaNumericaDe($c))) ?></td>
                                <?php if ($puedeCalificar): ?>
                                    <td><?php if ($c['origen'] === 'extra'): ?><button class="btn-peligro-sm" title="Eliminar esta nota" onclick="eliminarExtra('<?= h($c['id']) ?>')">🗑️</button>
                                        <?php if (!empty($c['global_id'])): ?><button class="btn-peligro-sm" title="Eliminar esta global para todos los alumnos" onclick="eliminarGlobal('<?= h($c['global_id']) ?>', <?= h(jsonJs($c['concepto'])) ?>)">🌐🗑️</button><?php endif; ?><?php endif; ?></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td><strong>Promedio</strong></td>
                            <td><strong><?= h(formatoNota($prom)) ?></strong>
                                <small style="margin-left:0.4rem; color:<?= $conf ? '#86efac' : '#fbbf24' ?>;"><?= $conf ? '✅ Confirmado' : '⏳ Pendiente de confirmación' ?></small></td>
                            <?php if ($puedeCalificar): ?><td></td><?php endif; ?>
                        </tr>
                    </tfoot>
                </table>
            <?php endif; ?>
            <div style="margin-top:1rem; padding:1rem; background:rgba(255,255,255,0.04); border-radius:10px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.75rem;">
                <div>
                    <strong>Promedio:</strong>
                    <?php if ($prom === null): ?><span class="pendiente">Sin calificaciones</span>
                    <?php elseif ($conf): ?><span class="promedio-valor"><?= h(formatoNota($prom)) ?></span> <small style="color:#86efac;">✅ Confirmado</small>
                    <?php else: ?><span class="promedio-valor"><?= h(formatoNota($prom)) ?></span> <span class="pendiente">Pendiente de confirmación</span><?php endif; ?>
                </div>
                <?php if ($puedeCalificar && $prom !== null && !$conf): ?>
                    <button class="btn-primario" onclick="abrirConfirmarPromedio(<?= (int)$a['id'] ?>, <?= (float)$prom ?>, '<?= h(formatoNota($prom)) ?>')">✅ Confirmar promedio</button>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</main>

<?php if ($puedeCalificar): ?>
<div class="modal-fondo oculto" id="modalExtra">
    <div class="modal-caja">
        <h3>➕ Cargar calificación</h3>
        <form id="formExtra">
            <input type="hidden" name="alumno_id" id="extraAlumnoId">
            <input type="hidden" name="materia_id" value="<?= (int)$materiaId ?>">
            <input type="hidden" name="año_lectivo" value="<?= $añoSel ?>">
            <label>Concepto <input type="text" name="concepto" required maxlength="120" placeholder="Ej: Trabajo práctico N°1"></label>
            <label>Nota (0-10) <input type="number" name="nota" min="0" max="10" step="0.01" required></label>
            <div class="modal-acciones">
                <button type="button" class="btn-secundario" onclick="cerrarModal('modalExtra')">Cancelar</button>
                <button type="submit" class="btn-primario">Guardar</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-fondo oculto" id="modalGlobal">
    <div class="modal-caja">
        <h3>🌐 Calificación global</h3>
        <form id="formGlobal">
            <input type="hidden" name="materia_id" value="<?= (int)$materiaId ?>">
            <input type="hidden" name="año_lectivo" value="<?= $añoSel ?>">
            <label>Nombre <input type="text" name="concepto" required maxlength="120" placeholder="Ej: Conducta"></label>
            <div style="max-height:50vh; overflow:auto; margin-top:0.75rem;">
                <table class="tabla-alumnos" style="width:100%;">
                    <thead><tr><th>Apellido y nombre</th><th style="width:7rem;">Nota</th></tr></thead>
                    <tbody>
                    <?php foreach ($alumnos as $al): ?>
                        <tr>
                            <td><?= h(nombreCompleto($al)) ?></td>
                            <td><input type="number" min="0" max="10" step="0.01" class="nota-global" data-alumno="<?= (int)$al['id'] ?>" placeholder="0 - 10" style="width:6rem;"></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p style="color:#a5c3e8; font-size:0.85rem;">Los alumnos con la nota vacía quedan sin calificación global.</p>
            <div class="modal-acciones">
                <button type="button" class="btn-secundario" onclick="cerrarModal('modalGlobal')">Cancelar</button>
                <button type="submit" class="btn-primario">Cargar a todos</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-fondo oculto" id="modalConfirmar">
    <div class="modal-caja">
        <h3>✅ Confirmar promedio</h3>
        <form id="formConfirmar">
            <input type="hidden" name="alumno_id" id="confAlumnoId">
            <input type="hidden" name="materia_id" value="<?= (int)$materiaId ?>">
            <input type="hidden" name="año_lectivo" value="<?= $añoSel ?>">
            <p>Promedio calculado: <strong><span id="confPromedio"></span></strong></p>
            <label>Formato
                <select name="formato">
                    <option value="numerico">Numérico</option>
                    <option value="cualitativo">Cualitativo</option>
                </select>
            </label>
            <div class="modal-acciones">
                <button type="button" class="btn-secundario" onclick="cerrarModal('modalConfirmar')">Cancelar</button>
                <button type="submit" class="btn-primario">Confirmar</button>
            </div>
        </form>
    </div>
</div>
<script src="/assets/js/calificaciones.js"></script>
<?php endif; ?>
<?php include __DIR__ . '/views/footer.php'; ?>