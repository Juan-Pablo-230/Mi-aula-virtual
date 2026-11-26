<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Helpers.php';
require_once __DIR__ . '/core/GradeHelpers.php';
require_once __DIR__ . '/models/AcademicModel.php';
require_once __DIR__ . '/core/Acceso.php';

Auth::checkRole(ROLES_ALL);
$user = Auth::currentUser($pdo);
$rol = $user['rol'];
$userId = (int)$user['id'];
$miInst = (int)($user['institucion_id'] ?? 0);

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

$alumnoId = 0;
if ($rol === 'alumno') $alumnoId = $userId;
elseif ($rol === 'familiar') {
    $ids = AcademicModel::alumnosDeFamiliar($pdo, $userId);
    $alumnoId = (int)($_GET['alumno_id'] ?? ($ids[0] ?? 0));
    if (!$alumnoId || !in_array($alumnoId, $ids)) die('Alumno inválido');
} else {
    $alumnoId = (int)($_GET['alumno_id'] ?? 0);
    // Docentes y directivos solo pueden ver boletines de alumnos de SU institución
    if ($alumnoId && !Acceso::puedeVerAlumno($pdo, Acceso::yo(), $alumnoId)) die('Alumno inválido');
}

$materias = $alumnoId ? AcademicModel::materiasAlumnoEnAño($pdo, $alumnoId, $añoSel) : [];
$boletin = [];
// Promedios confirmados (MongoDB): una sola consulta para todas las materias del alumno
$confPorMateria = [];
if ($alumnoId && $materias) {
    $cur = $mongoDB->selectCollection('promedios_confirmados')->find(['alumno_id' => $alumnoId, 'materia_id' => ['$in' => array_map(fn($m) => (int)$m['id'], $materias)]]);
    foreach ($cur as $c) {
        $añoC = isset($c['año_lectivo']) ? (int)$c['año_lectivo'] : null;
        if ($añoC !== null && $añoC !== $añoSel) continue;
        $confPorMateria[(int)$c['materia_id']] = $c;
    }
}
foreach ($materias as $m) {
    $conf = $confPorMateria[(int)$m['id']] ?? null;
    $ok = $conf && !empty($conf['confirmado']);
    $boletin[] = ['materia' => $m['nombre'], 'promedio' => $ok ? (float)$conf['promedio'] : null, 'pendiente' => !$ok];
}
?>
<?php include __DIR__ . '/views/header.php'; ?>
<?php include __DIR__ . '/views/menu.php'; ?>
<main class="contenido-principal">
    <h1>📄 Boletín <?= $añoSel ?></h1>

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

    <?php if (empty($boletin)): ?>
        <div class="panel-vidrio"><p class="alerta info">Sin materias para el año <?= $añoSel ?>.</p></div>
    <?php else: ?>
        <table class="tabla-alumnos panel-vidrio">
            <thead><tr><th>Materia</th><th>Promedio</th></tr></thead>
            <tbody>
            <?php foreach ($boletin as $b): ?>
                <tr><td><?= h($b['materia']) ?></td><td><?= $b['pendiente'] ? '<span class="pendiente">Pendiente</span>' : '<strong>' . h(formatoNota($b['promedio'])) . '</strong>' ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</main>
<?php include __DIR__ . '/views/footer.php'; ?>