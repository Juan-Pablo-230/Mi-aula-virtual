<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Helpers.php';

Auth::checkRole(ROLES_ALL);
if (($_SESSION['rol'] ?? '') === 'madre') {
    header('Location: /plataforma/admin/instituciones.php');
    exit;
}
Auth::checkRole(['directivo', 'secretario']);

$userActual = Auth::currentUser($pdo);
$miInst = (int)($userActual['institucion_id'] ?? 0);
if (!$miInst) die('Tu usuario no tiene institución asignada.');

$contar = function (string $sql) use ($pdo, $miInst): int {
    $st = $pdo->prepare($sql);
    $st->execute([$miInst]);
    return (int)$st->fetchColumn();
};
$totalAlumnos    = $contar("SELECT COUNT(*) FROM usuarios WHERE rol='alumno' AND institucion_id = ?");
$totalDocentes   = $contar("SELECT COUNT(*) FROM usuarios WHERE rol='docente' AND institucion_id = ?");
$totalCursos     = $contar("SELECT COUNT(*) FROM asignaciones WHERE institucion_id = ?");
$totalDivisiones = $contar("
    SELECT COUNT(*) FROM divisiones d
    JOIN asignaciones a ON a.id = d.asignacion_id
    WHERE a.institucion_id = ?
");
$totalMaterias   = $contar("SELECT COUNT(*) FROM materias WHERE institucion_id = ?");

try { $totalPublicaciones = $mongoPublicaciones->countDocuments(); } catch (Exception $e) { $totalPublicaciones = '—'; }
try { $totalArchivos     = $mongoArchivos->countDocuments(); }       catch (Exception $e) { $totalArchivos = '—'; }
try { $totalBibliografia = $mongoBibliografia->countDocuments(); }   catch (Exception $e) { $totalBibliografia = '—'; }

// Alumnos por curso
$alumnosPorCurso = $pdo->query("
    SELECT a.nombre AS curso, COUNT(ad.alumno_id) AS total
    FROM asignaciones a
    LEFT JOIN divisiones d ON d.asignacion_id = a.id
    LEFT JOIN alumnos_division ad ON ad.division_id = d.id
    GROUP BY a.id ORDER BY a.nombre
")->fetchAll();

// Alumnos por división
$alumnosPorDivision = $pdo->query("
    SELECT CONCAT(a.nombre,' ',d.nombre) AS division, COUNT(ad.alumno_id) AS total
    FROM divisiones d JOIN asignaciones a ON a.id = d.asignacion_id
    LEFT JOIN alumnos_division ad ON ad.division_id = d.id
    GROUP BY d.id ORDER BY a.nombre, d.nombre
")->fetchAll();

// Asistencias por estado en rango (Mongo)
$asistenciaPorEstado = [];
try {
    $desdeMongo = new MongoDB\BSON\UTCDateTime(strtotime($desde . ' 00:00:00') * 1000);
    $hastaMongo = new MongoDB\BSON\UTCDateTime(strtotime($hasta . ' 23:59:59') * 1000);
    $agg = $mongoDB->selectCollection('asistencia')->aggregate([
        ['$match' => ['fecha' => ['$gte' => $desdeMongo, '$lte' => $hastaMongo]]],
        ['$group' => ['_id' => '$estado', 'total' => ['$sum' => 1]]],
    ])->toArray();
    foreach ($agg as $r) $asistenciaPorEstado[$r['_id']] = (int)$r['total'];
} catch (Exception $e) { /* Mongo opcional */ }

// Publicaciones por materia
$pubsPorMateria = [];
try {
    $agg = $mongoPublicaciones->aggregate([
        ['$group' => ['_id' => '$materia_id', 'total' => ['$sum' => 1]]],
    ])->toArray();
    foreach ($agg as $r) $pubsPorMateria[(int)$r['_id']] = (int)$r['total'];
} catch (Exception $e) {}
$nombresMaterias = [];
foreach ($pdo->query("SELECT id, nombre FROM materias ORDER BY nombre") as $m) $nombresMaterias[(int)$m['id']] = $m['nombre'];

$labelsMaterias = []; $datosMaterias = [];
foreach ($pubsPorMateria as $mid => $total) { $labelsMaterias[] = $nombresMaterias[$mid] ?? "Materia $mid"; $datosMaterias[] = $total; }
?>
<?php include __DIR__ . '/../views/header.php'; ?>
<?php include __DIR__ . '/../views/menu.php'; ?>
<main class="contenido-principal">
    <h1>📈 Estadísticas</h1>
    <?php include __DIR__ . '/../views/admin_nav.php'; ?>

    <div class="panel-vidrio stat-filtros">
        <form method="get">
            <label>Desde <input type="date" name="desde" value="<?= h($desde) ?>"></label>
            <label>Hasta <input type="date" name="hasta" value="<?= h($hasta) ?>"></label>
            <button class="btn-primario" type="submit">Aplicar</button>
        </form>
    </div>

    <div class="grid-estadisticas">
        <div class="stat-card panel-vidrio"><h3><?= $totalAlumnos ?></h3><p>Alumnos</p></div>
        <div class="stat-card panel-vidrio"><h3><?= $totalDocentes ?></h3><p>Docentes</p></div>
        <div class="stat-card panel-vidrio"><h3><?= $totalCursos ?></h3><p>Cursos</p></div>
        <div class="stat-card panel-vidrio"><h3><?= $totalDivisiones ?></h3><p>Divisiones</p></div>
        <div class="stat-card panel-vidrio"><h3><?= $totalMaterias ?></h3><p>Materias</p></div>
        <div class="stat-card panel-vidrio"><h3><?= $totalPublicaciones ?></h3><p>Publicaciones</p></div>
        <div class="stat-card panel-vidrio"><h3><?= $totalArchivos ?></h3><p>Archivos</p></div>
        <div class="stat-card panel-vidrio"><h3><?= $totalBibliografia ?></h3><p>Bibliografía</p></div>
    </div>

    <div class="chart-grid">
        <div class="panel-vidrio chart-container"><h3>Alumnos por curso</h3><canvas id="chartCursos"></canvas></div>
        <div class="panel-vidrio chart-container"><h3>Alumnos por división</h3><canvas id="chartDivisiones"></canvas></div>
        <div class="panel-vidrio chart-container"><h3>Asistencias por estado (<?= h($desde) ?> → <?= h($hasta) ?>)</h3><canvas id="chartAsistencia"></canvas></div>
        <div class="panel-vidrio chart-container"><h3>Publicaciones por materia</h3><canvas id="chartPublicaciones"></canvas></div>
    </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const CHART_DATA = {
    cursos:      { labels: <?= jsonJs(array_column($alumnosPorCurso, 'curso')) ?>,  data: <?= jsonJs(array_map('intval', array_column($alumnosPorCurso, 'total'))) ?> },
    divisiones:  { labels: <?= jsonJs(array_column($alumnosPorDivision, 'division')) ?>, data: <?= jsonJs(array_map('intval', array_column($alumnosPorDivision, 'total'))) ?> },
    asistencia:  { labels: <?= jsonJs(array_keys($asistenciaPorEstado)) ?>, data: <?= jsonJs(array_values($asistenciaPorEstado)) ?> },
    publicaciones: { labels: <?= jsonJs($labelsMaterias) ?>, data: <?= jsonJs($datosMaterias) ?> },
};
const chartColors = ['#2c74b3', '#7dd3fc', '#15803d', '#ca8a04', '#b91c1c', '#a855f7', '#0ea5e9', '#f472b6'];

function crearBarra(id, cfg) {
    const c = document.getElementById(id); if (!c) return;
    new Chart(c, {
        type: 'bar',
        data: { labels: cfg.labels, datasets: [{ label: cfg.label || '', data: cfg.data, backgroundColor: chartColors, borderRadius: 6 }] },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: !!cfg.label, labels: { color: '#eaf2ff' } } },
            scales: {
                x: { ticks: { color: '#cfe2ff', maxRotation: 60, minRotation: 0 }, grid: { color: 'rgba(255,255,255,0.05)' } },
                y: { ticks: { color: '#cfe2ff' }, grid: { color: 'rgba(255,255,255,0.05)' }, beginAtZero: true },
            },
        }
    });
}
crearBarra('chartCursos',       { labels: CHART_DATA.cursos.labels,       data: CHART_DATA.cursos.data,       label: 'Alumnos' });
crearBarra('chartDivisiones',   { labels: CHART_DATA.divisiones.labels,   data: CHART_DATA.divisiones.data,   label: 'Alumnos' });
crearBarra('chartAsistencia',   { labels: CHART_DATA.asistencia.labels,   data: CHART_DATA.asistencia.data,   label: 'Registros' });
crearBarra('chartPublicaciones',{ labels: CHART_DATA.publicaciones.labels,data: CHART_DATA.publicaciones.data,label: 'Publicaciones' });
</script>
<?php include __DIR__ . '/../views/footer.php'; ?>