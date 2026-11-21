<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Helpers.php';
require_once __DIR__ . '/core/Feriados.php';
require_once __DIR__ . '/models/AcademicModel.php';

Auth::checkRole(ROLES_ALL);
$user = Auth::currentUser($pdo);
$rol  = $user['rol'];
$userId = (int)$user['id'];
$miInst = (int)($user['institucion_id'] ?? 0);

$cicloActivo = $miInst ? AcademicModel::cicloActivo($pdo, $miInst) : null;
$añoActivo = $cicloActivo ? (int)$cicloActivo['año'] : (int)date('Y');

$tz = new DateTimeZone('America/Argentina/Buenos_Aires');
$hoy = new DateTime('now', $tz);
$hoy->setTime(0, 0, 0);

// ============================================================
// Mes a mostrar (?mes=YYYY-MM). Solo se permite navegar dentro del año activo.
// ============================================================
$mesParam = $_GET['mes'] ?? '';
if (!preg_match('/^\d{4}-\d{2}$/', $mesParam)) {
    $mesParam = $hoy->format('Y-m');
}
$primerDiaMes = DateTime::createFromFormat('!Y-m', $mesParam, $tz);
if (!$primerDiaMes) $primerDiaMes = new DateTime('now', $tz);
$primerDiaMes->modify('first day of this month');
$primerDiaMes->setTime(0, 0, 0);

// Forzar el año activo: si el mes elegido es de otro año, volver al año activo
if ((int)$primerDiaMes->format('Y') !== $añoActivo) {
    $primerDiaMes = new DateTime("$añoActivo-01-01", $tz);
}

$mesAnterior = (clone $primerDiaMes)->modify('-1 month')->format('Y-m');
$mesSiguiente = (clone $primerDiaMes)->modify('+1 month')->format('Y-m');
$esMesActual = $primerDiaMes->format('Y-m') === $hoy->format('Y-m');

// Si el anterior o siguiente se va del año activo, bloquearlo
$puedeIrAnterior = (int)(clone $primerDiaMes)->modify('-1 month')->format('Y') === $añoActivo;
$puedeIrSiguiente = (int)(clone $primerDiaMes)->modify('+1 month')->format('Y') === $añoActivo;

// ============================================================
// TAREAS SEGÚN ROL (solo del año activo)
// ============================================================
if ($rol === 'alumno') {
    $divisionesEnAño = AcademicModel::divisionesDelAlumnoEnAño($pdo, $userId, $añoActivo);
    $materias = AcademicModel::materiasAlumnoEnAño($pdo, $userId, $añoActivo);
    $materiaIds = array_map('intval', array_column($materias, 'id'));
    $tareas = (!empty($divisionesEnAño) && !empty($materiaIds))
        ? $mongoDB->selectCollection('tareas')->find([
            'materia_id' => ['$in' => $materiaIds],
            'divisiones_ids' => ['$in' => $divisionesEnAño],
            'año_lectivo' => $añoActivo,
        ], ['sort' => ['fecha_vencimiento' => 1]])->toArray()
        : [];
} elseif ($rol === 'familiar') {
    $alumnos = AcademicModel::alumnosDeFamiliar($pdo, $userId);
    $divs = []; $mats = [];
    foreach ($alumnos as $aid) {
        foreach (AcademicModel::divisionesDelAlumnoEnAño($pdo, $aid, $añoActivo) as $d) $divs[] = $d;
        foreach (AcademicModel::materiasAlumnoEnAño($pdo, $aid, $añoActivo) as $m) $mats[] = (int)$m['id'];
    }
    $tareas = (!empty($divs) && !empty($mats))
        ? $mongoDB->selectCollection('tareas')->find([
            'materia_id' => ['$in' => array_unique($mats)],
            'divisiones_ids' => ['$in' => array_unique($divs)],
            'año_lectivo' => $añoActivo,
        ], ['sort' => ['fecha_vencimiento' => 1]])->toArray()
        : [];
} elseif ($rol === 'docente') {
    $tareas = $mongoDB->selectCollection('tareas')->find([
        'docente_id' => $userId,
        'año_lectivo' => $añoActivo,
    ], ['sort' => ['fecha_vencimiento' => 1]])->toArray();
} else {
    $stmt = $pdo->prepare("SELECT id FROM materias WHERE institucion_id = ?");
    $stmt->execute([$miInst]);
    $matIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $tareas = $mongoDB->selectCollection('tareas')->find([
        'materia_id' => ['$in' => $matIds],
        'año_lectivo' => $añoActivo,
    ], ['sort' => ['fecha_vencimiento' => 1], 'limit' => 500])->toArray();
}

// ============================================================
// EVENTOS (solo del año activo)
// ============================================================
$eventos = $mongoDB->selectCollection('eventos_calendario')->find([], ['sort' => ['fecha' => 1], 'limit' => 500])->toArray();

// ============================================================
// INDEXAR POR Y-M-D
// ============================================================
$porFecha = [];
$detalleMaterias = [];
$stmt = $pdo->query("SELECT id, nombre FROM materias");
foreach ($stmt as $m) $detalleMaterias[(int)$m['id']] = $m['nombre'];

$autoresCache = [];
function nombreAutor(PDO $pdo, int $id, array &$cache): string {
    if (isset($cache[$id])) return $cache[$id];
    $stmt = $pdo->prepare("SELECT nombre, apellido FROM usuarios WHERE id = ?");
    $stmt->execute([$id]);
    $u = $stmt->fetch();
    $cache[$id] = $u ? ($u['apellido'] . ', ' . $u['nombre']) : 'Desconocido';
    return $cache[$id];
}

foreach ($tareas as $t) {
    $f = $t['fecha_vencimiento']->toDateTime();
    $f->setTimezone($tz);
    $ymd = $f->format('Y-m-d');
    $mat = $detalleMaterias[(int)$t['materia_id']] ?? 'Materia';
    $porFecha[$ymd][] = [
        'id' => (string)$t['_id'],
        'tipo' => 'tarea',
        'titulo' => $t['titulo'],
        'detalle' => 'Tarea · ' . $mat,
        'hora' => $f->format('H:i'),
        'link' => '/plataforma/tarea_detalle.php?id=' . (string)$t['_id'],
        'descripcion' => $t['descripcion'] ?? '',
        'materia' => $mat,
        'autor' => nombreAutor($pdo, (int)$t['docente_id'], $autoresCache),
        'archivo_id' => !empty($t['archivo_id']) ? (string)$t['archivo_id'] : null,
        'archivo_nombre' => $t['archivo_nombre'] ?? null,
        'tipo_entrega' => $t['tipo_entrega'] ?? null,
        'fecha_completa' => $f->format('d/m/Y H:i'),
        'fecha_fin' => null,
        'todo_el_dia' => false,
    ];
}
foreach ($eventos as $e) {
    $f = $e['fecha']->toDateTime();
    $f->setTimezone($tz);
    // Solo eventos del año activo
    if ((int)$f->format('Y') !== $añoActivo) continue;

    $ymd = $f->format('Y-m-d');
    $todoDia = !empty($e['todo_el_dia']);
    $horaTxt = $todoDia ? '' : ($e['hora_inicio'] ?? $f->format('H:i'));
    $matNombre = null;
    if (!empty($e['materia_id']) && isset($detalleMaterias[(int)$e['materia_id']])) {
        $matNombre = $detalleMaterias[(int)$e['materia_id']];
    }
    $fechaFinTxt = null;
    if (!empty($e['fecha_fin'])) {
        $ff = $e['fecha_fin']->toDateTime();
        $ff->setTimezone($tz);
        $fechaFinTxt = $todoDia ? $ff->format('d/m/Y') : $ff->format('d/m/Y H:i');
    }
    $porFecha[$ymd][] = [
        'id' => (string)$e['_id'],
        'tipo' => 'evento',
        'titulo' => $e['titulo'],
        'detalle' => $e['descripcion'] ?? '',
        'hora' => $horaTxt,
        'link' => null,
        'descripcion' => $e['descripcion'] ?? '',
        'materia' => $matNombre,
        'autor' => !empty($e['creado_por']) ? nombreAutor($pdo, (int)$e['creado_por'], $autoresCache) : null,
        'archivo_id' => null,
        'archivo_nombre' => null,
        'tipo_entrega' => null,
        'fecha_completa' => $todoDia ? $f->format('d/m/Y') . ' (todo el día)' : $f->format('d/m/Y H:i'),
        'fecha_fin' => $fechaFinTxt,
        'todo_el_dia' => $todoDia,
    ];
}

// ============================================================
// FERIADOS (solo del año activo)
// ============================================================
$feriados = obtenerFeriados($añoActivo);
foreach ($feriados as $ymd) {
    $porFecha[$ymd][] = [
        'id' => 'feriado_' . $ymd,
        'tipo' => 'feriado',
        'titulo' => 'Feriado nacional',
        'detalle' => '',
        'hora' => '',
        'link' => null,
        'descripcion' => 'Día no laborable en todo el territorio nacional.',
        'materia' => null,
        'autor' => null,
        'archivo_id' => null,
        'archivo_nombre' => null,
        'tipo_entrega' => null,
        'fecha_completa' => date('d/m/Y', strtotime($ymd)),
        'fecha_fin' => null,
        'todo_el_dia' => true,
    ];
}

// ============================================================
// CONSTRUIR GRILLA
// ============================================================
$diaSemanaInicio = (int)$primerDiaMes->format('N');
$offsetInicio = $diaSemanaInicio - 1;
$inicioGrilla = clone $primerDiaMes;
$inicioGrilla->modify("-{$offsetInicio} days");

$totalCeldas = 42;
$mesActualStr = $primerDiaMes->format('Y-m');

$celdas = [];
$cursor = clone $inicioGrilla;
for ($i = 0; $i < $totalCeldas; $i++) {
    $ymd = $cursor->format('Y-m-d');
    $esDelMes = $cursor->format('Y-m') === $mesActualStr;
    $esHoy = $ymd === $hoy->format('Y-m-d');
    $esPasado = $cursor < $hoy;
    $celdas[] = [
        'fecha' => clone $cursor,
        'ymd' => $ymd,
        'esDelMes' => $esDelMes,
        'esHoy' => $esHoy,
        'esPasado' => $esPasado,
        'items' => $porFecha[$ymd] ?? [],
    ];
    $cursor->modify('+1 day');
}

$mesesES = [1=>'enero','febrero','marzo','abril','mayo','junio',
            'julio','agosto','septiembre','octubre','noviembre','diciembre'];
$mesActualES = $mesesES[(int)$primerDiaMes->format('n')];
$añoMostrar = $primerDiaMes->format('Y');

$nombresDias = ['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'];
?>
<?php include __DIR__ . '/views/header.php'; ?>
<?php include __DIR__ . '/views/menu.php'; ?>
<main class="contenido-principal">
    <div class="calendario-bar">
        <h1 style="margin:0;">📅 <?= h(ucfirst($mesActualES) . ' ' . $añoMostrar) ?> <small style="color:#a5c3e8; font-size:0.9rem;">(ciclo lectivo <?= $añoActivo ?>)</small></h1>
        <div class="calendario-nav">
            <?php if ($puedeIrAnterior): ?>
                <a class="btn-secundario" href="?mes=<?= h($mesAnterior) ?>">← Anterior</a>
            <?php else: ?>
                <span class="btn-secundario" style="opacity:0.4; cursor:not-allowed;">← Anterior</span>
            <?php endif; ?>
            <a class="btn-primario <?= $esMesActual ? 'btn-hoy-actual' : '' ?>" href="?mes=<?= h($hoy->format('Y-m')) ?>">📍 Hoy</a>
            <?php if ($puedeIrSiguiente): ?>
                <a class="btn-secundario" href="?mes=<?= h($mesSiguiente) ?>">Siguiente →</a>
            <?php else: ?>
                <span class="btn-secundario" style="opacity:0.4; cursor:not-allowed;">Siguiente →</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="calendario-mensual panel-vidrio">
        <div class="calendario-header-dias">
            <?php foreach ($nombresDias as $nd): ?>
                <div class="calendario-dia-nombre"><?= h($nd) ?></div>
            <?php endforeach; ?>
        </div>
        <div class="calendario-grid">
            <?php foreach ($celdas as $celda):
                $f = $celda['fecha'];
                $esOtroMes = !$celda['esDelMes'];
                $esHoy = $celda['esHoy'];
                $esFeriado = !empty(array_filter($celda['items'], fn($it) => $it['tipo'] === 'feriado'));
                $clases = ['calendario-dia'];
                if ($esOtroMes) $clases[] = 'otro-mes';
                if ($esHoy) $clases[] = 'hoy';
                if ($esFeriado) $clases[] = 'feriado';
                $cantItems = count($celda['items']);
            ?>
                <div class="<?= implode(' ', $clases) ?> calendario-dia-clickeable"
                     data-ymd="<?= h($celda['ymd']) ?>"
                     onclick="abrirDia('<?= h($celda['ymd']) ?>')">
                    <div class="calendario-dia-num"><?= h($f->format('j')) ?></div>
                    <div class="calendario-dia-items">
                        <?php
                        $itemsMostrar = array_slice($celda['items'], 0, 3);
                        foreach ($itemsMostrar as $it):
                            $icono = $it['tipo'] === 'tarea' ? '📝' : ($it['tipo'] === 'feriado' ? '🎉' : '📅');
                        ?>
                            <div class="calendario-item calendario-item-<?= h($it['tipo']) ?>" title="<?= h($it['titulo'] . ($it['hora'] ? ' · ' . $it['hora'] : '')) ?>">
                                <span class="calendario-item-icono"><?= $icono ?></span>
                                <span class="calendario-item-titulo">
                                    <?php if ($it['hora']): ?><strong><?= h($it['hora']) ?></strong> <?php endif; ?>
                                    <?= h(mb_strimwidth($it['titulo'], 0, 22, '…')) ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($cantItems > 3): ?>
                            <div class="calendario-mas">+<?= $cantItems - 3 ?> más</div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="calendario-leyenda">
        <span><span class="leyenda-punto leyenda-tarea"></span> Tareas</span>
        <span><span class="leyenda-punto leyenda-evento"></span> Eventos</span>
        <span><span class="leyenda-punto leyenda-feriado"></span> Feriados</span>
        <span><span class="leyenda-punto leyenda-hoy"></span> Hoy</span>
    </div>
</main>

<div class="modal-fondo oculto" id="modalDia">
    <div class="modal-caja">
        <h3 id="diaTitulo">Eventos del día</h3>
        <div id="diaContenido"></div>
        <div class="modal-acciones">
            <button type="button" class="btn-secundario" onclick="cerrarModal('modalDia')">Cerrar</button>
        </div>
    </div>
</div>

<div class="modal-fondo oculto" id="modalDetalle">
    <div class="modal-caja">
        <h3 id="detalleTitulo">Detalle</h3>
        <div id="detalleContenido"></div>
        <div class="modal-acciones">
            <button type="button" class="btn-secundario" onclick="cerrarModal('modalDetalle')">Cerrar</button>
        </div>
    </div>
</div>

<script>
window.CALENDARIO_DATA = <?= json_encode($porFecha, JSON_UNESCAPED_UNICODE) ?>;
window.CALENDARIO_AÑO_ACTIVO = <?= (int)$añoActivo ?>;
</script>
<script src="/assets/js/calendario.js"></script>
<?php include __DIR__ . '/views/footer.php'; ?>