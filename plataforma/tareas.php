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

$cicloActivo = $miInst ? AcademicModel::cicloActivo($pdo, $miInst) : null;
$añoActual = $cicloActivo ? (int)$cicloActivo['año'] : (int)date('Y');

// ============================================================
// AÑOS DISPONIBLES
// ============================================================
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

// ============================================================
// FILTRO DE TAREAS
// ============================================================
$filtro = [];
$estadoPorTarea = [];

if ($rol === 'docente') {
    $filtro['docente_id'] = (int)$user['id'];
} elseif ($rol === 'alumno') {
    $divisionesEnAño = AcademicModel::divisionesDelAlumnoEnAño($pdo, (int)$user['id'], $añoSel);
    $materias = AcademicModel::materiasAlumnoEnAño($pdo, (int)$user['id'], $añoSel);
    $materiaIds = array_map('intval', array_column($materias, 'id'));
    if (empty($materiaIds) || empty($divisionesEnAño)) {
        $filtro['_id'] = ['$in' => []];
    } else {
        $filtro['materia_id'] = ['$in' => $materiaIds];
        $filtro['divisiones_ids'] = ['$in' => $divisionesEnAño];
        $filtro['año_lectivo'] = $añoSel;
    }
} elseif ($rol === 'familiar') {
    $alumnos = AcademicModel::alumnosDeFamiliar($pdo, (int)$user['id']);
    $divisiones = []; $materiaIds = [];
    foreach ($alumnos as $aid) {
        foreach (AcademicModel::divisionesDelAlumnoEnAño($pdo, $aid, $añoSel) as $d) $divisiones[] = $d;
        foreach (AcademicModel::materiasAlumnoEnAño($pdo, $aid, $añoSel) as $m) $materiaIds[] = (int)$m['id'];
    }
    if (!empty($divisiones) && !empty($materiaIds)) {
        $filtro['materia_id'] = ['$in' => array_unique($materiaIds)];
        $filtro['divisiones_ids'] = ['$in' => array_unique($divisiones)];
        $filtro['año_lectivo'] = $añoSel;
    } else {
        $filtro['_id'] = ['$in' => []];
    }
} elseif (in_array($rol, ['directivo', 'secretario'])) {
    // Directivos: solo tareas de las materias de SU institución
    $stmt = $pdo->prepare("SELECT id FROM materias WHERE institucion_id = ?");
    $stmt->execute([$miInst]);
    $matIdsInst = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $filtro['materia_id'] = ['$in' => $matIdsInst];
}

$tareasRaw = $mongoDB->selectCollection('tareas')->find($filtro, ['sort' => ['fecha_vencimiento' => -1], 'limit' => 200])->toArray();
// Las tareas que el docente no asignó a este alumno no aparecen
$tareasRaw = Acceso::filtrarTareasParaUsuario($pdo, ['id' => (int)$user['id'], 'rol' => $rol], $tareasRaw);

$tareas = [];
foreach ($tareasRaw as $t) {
    $stmt = $pdo->prepare("SELECT nombre FROM materias WHERE id = ?");
    $stmt->execute([$t['materia_id']]);
    $materiaNombre = $stmt->fetchColumn() ?: 'Materia';
    $divsNombres = [];
    $divsIds = $t['divisiones_ids'] ?? [];
    if (is_object($divsIds)) $divsIds = (array)$divsIds;
    $divsIds = array_map('intval', $divsIds);
    if (!empty($divsIds)) {
        $placeholders = implode(',', array_fill(0, count($divsIds), '?'));
        $stmt = $pdo->prepare("SELECT d.nombre AS division, a.nombre AS curso FROM divisiones d JOIN asignaciones a ON a.id = d.asignacion_id WHERE d.id IN ($placeholders)");
        $stmt->execute($divsIds);
        foreach ($stmt as $d) $divsNombres[] = $d['curso'] . ' ' . $d['division'];
    }
    $t['materia_nombre'] = $materiaNombre;
    $t['divisiones_nombres'] = $divsNombres;
    $t['año_lectivo_calc'] = isset($t['año_lectivo']) ? (int)$t['año_lectivo'] : (int)$t['fecha_vencimiento']->toDateTime()->format('Y');
    if ($rol === 'alumno') {
        $entregas = $mongoDB->selectCollection('entregas')->find(['alumno_id' => (int)$user['id']])->toArray();
        $estados = [];
        foreach ($entregas as $e) $estados[(string)$e['tarea_id']] = $e['estado'];
        $t['mi_estado'] = $estados[(string)$t['_id']] ?? 'pendiente';
    }
    $tareas[] = $t;
}
?>
<?php include __DIR__ . '/views/header.php'; ?>
<?php include __DIR__ . '/views/menu.php'; ?>
<main class="contenido-principal">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem; margin-bottom:1rem;">
        <h1 style="margin:0;">📝 Tareas</h1>
        <?php if (esDocenteOAdmin()): ?>
            <a class="btn-primario" href="/plataforma/tarea_detalle.php?nueva=1">➕ Nueva tarea</a>
        <?php endif; ?>
    </div>

    <?php if (count($añosDisponibles) > 1): ?>
        <div class="panel-vidrio" style="padding:0.75rem 1rem; margin-bottom:1rem; display:flex; align-items:center; gap:1rem; flex-wrap:wrap;">
            <strong style="color:#7dd3fc;">Ver año:</strong>
            <div style="display:flex; gap:0.35rem; flex-wrap:wrap;">
                <?php foreach ($añosDisponibles as $a): ?>
                    <a href="?año=<?= $a ?>" class="btn-mini" style="<?= $a === $añoSel ? 'background:var(--celeste);color:#fff;' : 'background:rgba(255,255,255,0.08);color:#cfe2ff;' ?>">
                        <?= $a ?><?= ($cicloActivo && (int)$cicloActivo['año'] === $a) ? ' ·' : '' ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php if ($añoSel !== $añoActual): ?>
                <a href="?año=<?= $añoActual ?>" class="btn-secundario" style="margin-left:auto;">← Año actual</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="panel-vidrio">
        <?php if (empty($tareas)): ?>
            <p class="alerta info">No hay tareas para el año <?= (int)$añoSel ?>.</p>
        <?php else: ?>
            <table class="tabla-alumnos">
                <thead><tr><th>Título</th><th>Materia</th><th>Divisiones</th><th>Año</th><th>Vence</th><?php if ($rol === 'alumno'): ?><th>Mi estado</th><?php endif; ?><th></th></tr></thead>
                <tbody>
                    <?php foreach ($tareas as $t): ?>
                        <?php $fv = $t['fecha_vencimiento']->toDateTime(); $fv->setTimezone(new DateTimeZone('America/Argentina/Buenos_Aires')); ?>
                        <tr>
                            <td><?= h($t['titulo']) ?></td>
                            <td><?= h($t['materia_nombre']) ?></td>
                            <td><?= h(implode(', ', $t['divisiones_nombres'])) ?></td>
                            <td><?= (int)$t['año_lectivo_calc'] ?></td>
                            <td><?= h($fv->format('d/m/Y H:i')) ?></td>
                            <?php if ($rol === 'alumno'): ?>
                                <td><span class="badge"><?= h(ucfirst($t['mi_estado'] ?? 'pendiente')) ?></span></td>
                            <?php endif; ?>
                            <td><a class="btn-secundario" href="/plataforma/tarea_detalle.php?id=<?= h((string)$t['_id']) ?>">Ver</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</main>
<script src="/assets/js/tareas.js"></script>
<?php include __DIR__ . '/views/footer.php'; ?>