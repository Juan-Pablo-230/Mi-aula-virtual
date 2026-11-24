<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Helpers.php';
require_once __DIR__ . '/core/GradeHelpers.php';
require_once __DIR__ . '/core/Feriados.php';
require_once __DIR__ . '/models/AcademicModel.php';

Auth::checkRole(ROLES_ALL);
$user = Auth::currentUser($pdo);
$rol  = $user['rol'];
$userId = (int)$user['id'];
$miInst = (int)($user['institucion_id'] ?? 0);
$esAlumno = ($rol === 'alumno');
$esFamiliar = ($rol === 'familiar');

$cicloActivo = $miInst ? AcademicModel::cicloActivo($pdo, $miInst) : null;
$añoActual = $cicloActivo ? (int)$cicloActivo['año'] : (int)date('Y');

$fecha = $_GET['fecha'] ?? date('Y-m-d');
$añoFecha = (int)date('Y', strtotime($fecha));
$feriados = obtenerFeriados($añoFecha);

// ============================================================
// AÑOS DISPONIBLES
// ============================================================
$añosDisponibles = [];
if ($esAlumno) {
    $añosDisponibles = AcademicModel::añosLectivosAlumno($pdo, $userId);
} elseif ($esFamiliar) {
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
// VISTA ALUMNO / FAMILIAR
// ============================================================
if ($esAlumno || $esFamiliar) {
    if ($esAlumno) { $alumnosIds = [$userId]; }
    else { $alumnosIds = AcademicModel::alumnosDeFamiliar($pdo, $userId); }

    $alumnoSel = (int)($_GET['alumno_id'] ?? ($alumnosIds[0] ?? 0));
    if (!$alumnoSel || !in_array($alumnoSel, $alumnosIds)) die('Alumno inválido');

    $stmt = $pdo->prepare("SELECT nombre, apellido FROM usuarios WHERE id = ?");
    $stmt->execute([$alumnoSel]);
    $alumnoData = $stmt->fetch();

    $materias = AcademicModel::materiasAlumnoEnAño($pdo, $alumnoSel, $añoSel);
    $materiaIds = array_map('intval', array_column($materias, 'id'));
    $divisionesEnAño = AcademicModel::divisionesDelAlumnoEnAño($pdo, $alumnoSel, $añoSel);

    $filtro = [
        'alumno_id' => $alumnoSel,
        'materia_id' => ['$in' => $materiaIds],
    ];
    if (!empty($divisionesEnAño)) $filtro['division_id'] = ['$in' => $divisionesEnAño];
    $asistencias = $mongoDB->selectCollection('asistencia')->find($filtro, ['sort' => ['fecha' => -1], 'limit' => 500])->toArray();

    // Filtrar por año
    $asistencias = array_values(array_filter($asistencias, function($a) use ($añoSel) {
        return (int)$a['fecha']->toDateTime()->format('Y') === $añoSel;
    }));

    $matMap = []; foreach ($materias as $m) $matMap[(int)$m['id']] = $m['nombre'];

    include __DIR__ . '/views/header.php';
    include __DIR__ . '/views/menu.php';
    ?>
    <main class="contenido-principal">
        <h1>🗓️ Asistencia de <?= h($alumnoData['nombre'] . ' ' . $alumnoData['apellido']) ?></h1>
        <?php if (count($alumnosIds) > 1): ?>
            <div class="panel-vidrio" style="padding:1rem; margin-bottom:1rem;">
                <form method="get">
                    <input type="hidden" name="año" value="<?= $añoSel ?>">
                    <label>Ver alumno:
                        <select name="alumno_id" onchange="this.form.submit()">
                            <?php foreach ($alumnosIds as $aid):
                                $stmt = $pdo->prepare("SELECT nombre, apellido FROM usuarios WHERE id = ?");
                                $stmt->execute([$aid]);
                                $hijo = $stmt->fetch(); ?>
                                <option value="<?= (int)$aid ?>" <?= $aid === $alumnoSel ? 'selected' : '' ?>><?= h($hijo['apellido'] . ', ' . $hijo['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </form>
            </div>
        <?php endif; ?>

        <?php if (count($añosDisponibles) > 1): ?>
            <div class="panel-vidrio" style="padding:0.75rem 1rem; margin-bottom:1rem; display:flex; align-items:center; gap:1rem; flex-wrap:wrap;">
                <strong style="color:#7dd3fc;">Ver año:</strong>
                <div style="display:flex; gap:0.35rem; flex-wrap:wrap;">
                    <?php foreach ($añosDisponibles as $a): ?>
                        <a href="?año=<?= $a ?>&alumno_id=<?= $alumnoSel ?>" class="btn-mini" style="<?= $a === $añoSel ? 'background:var(--celeste);color:#fff;' : 'background:rgba(255,255,255,0.08);color:#cfe2ff;' ?>">
                            <?= $a ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="panel-vidrio">
            <h2>Historial <?= $añoSel ?></h2>
            <?php if (empty($asistencias)): ?>
                <p class="alerta info">Sin registros para el año <?= $añoSel ?>.</p>
            <?php else: ?>
                <table class="tabla-alumnos">
                    <thead><tr><th>Fecha</th><th>Materia</th><th>Estado</th></tr></thead>
                    <tbody>
                        <?php foreach ($asistencias as $a):
                            $f = $a['fecha']->toDateTime();
                            $f->setTimezone(new DateTimeZone('America/Argentina/Buenos_Aires')); ?>
                            <tr>
                                <td><?= h($f->format('d/m/Y')) ?></td>
                                <td><?= h($matMap[(int)$a['materia_id']] ?? '—') ?></td>
                                <td><span class="badge <?= $a['estado'] === 'presente' ? 'ok' : ($a['estado'] === 'ausente' ? 'danger' : 'warn') ?>"><?= h(ucfirst($a['estado'])) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </main>
    <?php
    include __DIR__ . '/views/footer.php';
    exit;
}

// ============================================================
// VISTA DOCENTE / ADMIN
// ============================================================
$materiaId = (int)($_GET['materia_id'] ?? 0);
if ($rol === 'docente') {
    $stmt = $pdo->prepare("SELECT DISTINCT m.id, m.nombre FROM asignaciones_docente ad JOIN materias m ON m.id = ad.materia_id WHERE ad.docente_id = ? ORDER BY m.nombre");
    $stmt->execute([$userId]);
} else {
    $stmt = $pdo->prepare("SELECT id, nombre FROM materias WHERE institucion_id = ? ORDER BY nombre");
    $stmt->execute([$miInst]);
}
$materias = $stmt->fetchAll();

// El materia_id de la URL debe estar entre las materias permitidas (la madre puede ver todas)
$materiasPermitidas = array_map('intval', array_column($materias, 'id'));
if ($materiaId && $rol !== 'madre' && !in_array($materiaId, $materiasPermitidas, true)) {
    redirect('/plataforma/asistencia.php');
}

if (!$materiaId && !empty($materias)) $materiaId = (int)$materias[0]['id'];

$alumnos = [];
$asistenciaHoy = [];
$faltasPorAlumno = [];
$libresPorAlumno = [];
$topesPorAlumno = [];
$diasConAsistencia = [];
$topeMateria = 10;

if ($materiaId) {
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.id, u.nombre, u.apellido, h.division_id
        FROM usuarios u
        JOIN alumnos_division_historial h ON h.alumno_id = u.id AND h.activo = 1 AND h.año_lectivo = ?
        JOIN asignaciones_docente adoc ON adoc.division_id = h.division_id
        LEFT JOIN alumno_materia_exclusion ame ON ame.alumno_id = u.id AND ame.materia_id = adoc.materia_id
        WHERE adoc.materia_id = ? AND u.rol = 'alumno' AND u.activo = 1 AND ame.alumno_id IS NULL
        ORDER BY u.apellido, u.nombre
    ");
    $stmt->execute([$añoSel, $materiaId]);
    $alumnos = $stmt->fetchAll();

    if (!empty($alumnos)) {
        $aIds = array_map(fn($a) => (int)$a['id'], $alumnos);
        $regla = $mongoDB->selectCollection('reglas_asistencia')->findOne(['materia_id' => $materiaId]);
        $topeMateria = (int)($regla['faltas_maximas'] ?? 10);
        $topesP = $mongoDB->selectCollection('topes_alumno')->find(['materia_id' => $materiaId, 'alumno_id' => ['$in' => $aIds]])->toArray();
        foreach ($topesP as $t) $topesPorAlumno[(int)$t['alumno_id']] = (int)$t['tope'];

        $fIni = new MongoDB\BSON\UTCDateTime(strtotime($fecha . ' 00:00:00') * 1000);
        $fFin = new MongoDB\BSON\UTCDateTime(strtotime($fecha . ' 23:59:59') * 1000);
        $regs = $mongoDB->selectCollection('asistencia')->find(['materia_id' => $materiaId, 'alumno_id' => ['$in' => $aIds], 'fecha' => ['$gte' => $fIni, '$lte' => $fFin]])->toArray();
        foreach ($regs as $r) $asistenciaHoy[(int)$r['alumno_id']] = $r['estado'];

        $faltas = $mongoDB->selectCollection('asistencia')->aggregate([
            ['$match' => ['materia_id' => $materiaId, 'alumno_id' => ['$in' => $aIds], 'estado' => 'ausente']],
            ['$group' => ['_id' => '$alumno_id', 'total' => ['$sum' => 1]]]
        ])->toArray();
        foreach ($faltas as $f) $faltasPorAlumno[(int)$f['_id']] = (int)$f['total'];

        $libres = $mongoDB->selectCollection('alumno_libre')->find(['materia_id' => $materiaId, 'alumno_id' => ['$in' => $aIds]])->toArray();
        foreach ($libres as $l) $libresPorAlumno[(int)$l['alumno_id']] = true;

        $existentes = $mongoDB->selectCollection('asistencia')->find(['materia_id' => $materiaId], ['projection' => ['fecha' => 1]])->toArray();
        foreach ($existentes as $e) {
            $f = $e['fecha']->toDateTime();
            $f->setTimezone(new DateTimeZone('America/Argentina/Buenos_Aires'));
            $diasConAsistencia[] = $f->format('Y-m-d');
        }
        $diasConAsistencia = array_values(array_unique($diasConAsistencia));
    }
}

$esDirectivoVista = in_array($rol, ['directivo', 'secretario', 'madre'], true);
$mostrarLibres = !empty($_GET['mostrar_libres']);
$alumnosVisibles = [];
foreach ($alumnos as $a) {
    if ($mostrarLibres || !isset($libresPorAlumno[(int)$a['id']])) $alumnosVisibles[] = $a;
}

function topeDeAlumno(int $aid, array $t, int $def): int {
    return $t[$aid] ?? $def;
}

$feriadosJson = jsonJs($feriados);
$diasJson = jsonJs($diasConAsistencia);

include __DIR__ . '/views/header.php';
include __DIR__ . '/views/menu.php';
?>
<main class="contenido-principal">
    <h1>🗓️ Asistencia</h1>

    <?php if (count($añosDisponibles) > 1): ?>
        <div class="panel-vidrio" style="padding:0.75rem 1rem; margin-bottom:1rem; display:flex; align-items:center; gap:1rem; flex-wrap:wrap;">
            <strong style="color:#7dd3fc;">Ver año:</strong>
            <div style="display:flex; gap:0.35rem; flex-wrap:wrap;">
                <?php foreach ($añosDisponibles as $a): ?>
                    <a href="?año=<?= $a ?>&materia_id=<?= $materiaId ?>" class="btn-mini" style="<?= $a === $añoSel ? 'background:var(--celeste);color:#fff;' : 'background:rgba(255,255,255,0.08);color:#cfe2ff;' ?>">
                        <?= $a ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="panel-vidrio" style="padding:1rem; margin-bottom:1rem;">
        <form method="get" style="display:flex; gap:1rem; flex-wrap:wrap; align-items:center;">
            <input type="hidden" name="año" value="<?= $añoSel ?>">
            <label style="margin:0;">Materia:
                <select name="materia_id" onchange="this.form.submit()">
                    <?php foreach ($materias as $m): ?>
                        <option value="<?= (int)$m['id'] ?>" <?= $m['id'] == $materiaId ? 'selected' : '' ?>><?= h($m['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label style="margin:0;">Fecha:
                <input type="date" name="fecha" id="fechaAsistencia" value="<?= h($fecha) ?>" style="width:11rem; margin:0;">
            </label>
            <label style="margin:0;"><input type="checkbox" name="mostrar_libres" value="1" <?= $mostrarLibres ? 'checked' : '' ?> onchange="this.form.submit()"> Mostrar libres</label>
            <?php if ($esDirectivoVista && $materiaId): ?>
            <label style="margin:0; flex-basis:100%;">⚙️ Tope general de faltas (queda LIBRE al llegar a este número):
                <span style="display:inline-flex; gap:0.5rem; align-items:center;">
                    <input type="number" id="topeMateria" class="input-tope" value="<?= (int)$topeMateria ?>" min="1" max="999" onkeydown="if(event.key==='Enter'){event.preventDefault();guardarTopeMateria();}">
                    <button type="button" class="btn-primario" onclick="guardarTopeMateria()">Guardar tope</button>
                </span>
            </label>
            <?php endif; ?>
            <?php if ($esDirectivoVista && $materiaId): ?>
            <small style="color:#a5c3e8; flex-basis:100%;">Aplica a todos los alumnos de la materia, salvo los que tengan un tope personal (botón ✏️ en cada tarjeta).</small>
            <?php endif; ?>
        </form>
    </div>

    <div id="fechaError" class="alerta danger" style="display:none; margin-bottom:1rem;"></div>

    <?php if ($materiaId): ?>
        <?php if (empty($alumnosVisibles)): ?>
            <div class="panel-vidrio"><p class="alerta info">Sin alumnos para el año <?= $añoSel ?>.</p></div>
        <?php else: ?>
            <div class="asistencia-grid">
                <?php foreach ($alumnosVisibles as $a):
                    $aid = (int)$a['id'];
                    $estado = $asistenciaHoy[$aid] ?? 'presente';
                    $esLibre = isset($libresPorAlumno[$aid]);
                    $faltas = $faltasPorAlumno[$aid] ?? 0;
                    $tope = topeDeAlumno($aid, $topesPorAlumno, $topeMateria);
                    $clase = $esLibre ? 'libre' : ($estado === 'ausente' ? 'ausente' : 'presente');
                ?>
                    <div class="alumno-card <?= $clase ?>" data-alumno-id="<?= $aid ?>" data-estado="<?= h($estado) ?>" data-original="<?= h($estado) ?>" data-libre="<?= $esLibre ? '1' : '0' ?>" onclick="toggleAsistencia(this, <?= $aid ?>)">
                        <div class="alumno-card-nombre"><?= h($a['apellido']) ?><br><?= h($a['nombre']) ?></div>
                        <div class="alumno-card-info">
                            <?php if ($esLibre): ?>🔒 Libre
                            <?php else: ?>Faltas: <strong><?= $faltas ?>/<?= $tope ?></strong>
                            <?php endif; ?>
                        </div>
                        <?php if ($esDirectivoVista): ?>
                            <button type="button" class="btn-mini" style="margin-top:0.35rem; font-size:0.7rem;" title="<?= isset($topesPorAlumno[$aid]) ? 'Tope personal' : 'Tope general' ?> — cambiar tope de este alumno" onclick="event.stopPropagation(); cambiarTope(<?= $aid ?>, <?= (int)$tope ?>, <?= h(jsonJs($a['apellido'] . ', ' . $a['nombre'])) ?>)">✏️ Tope<?= isset($topesPorAlumno[$aid]) ? ' (personal)' : '' ?></button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="asistencia-guardar" style="margin-top:1rem; text-align:center;">
                <button class="btn-primario" id="btnGuardarAsistencia" onclick="guardarAsistencia()">💾 Guardar asistencia</button>
                <span id="avisoCambios" style="display:none; color:#fbbf24; margin-left:1rem;">Cambios sin guardar</span>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</main>
<?php if ($esDirectivoVista): ?>
<div class="modal-fondo oculto" id="modalTope">
    <div class="modal-caja" style="min-width: 22rem;">
        <h3>✏️ Tope de faltas del alumno</h3>
        <p id="modalTopeAlumno" style="color:#cfe2ff; font-size:0.9rem;"></p>
        <form id="formTope">
            <label>Cantidad de faltas para quedar LIBRE
                <input type="number" id="topeAlumnoInput" min="1" max="999" required>
            </label>
            <div id="topeError" class="alerta danger" style="display:none;"></div>
            <div class="modal-acciones">
                <button type="button" class="btn-secundario" onclick="cerrarModalTope()">Cancelar</button>
                <button type="submit" class="btn-primario">Guardar</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
<script>
window.MATERIA_ID = <?= (int)$materiaId ?>;
window.FECHA = <?= jsonJs($fecha) ?>;
window.ES_DIRECTIVO = <?= in_array($rol, ['directivo','secretario','madre']) ? 'true' : 'false' ?>;
window.FERIADOS = <?= $feriadosJson ?>;
window.DIAS_CON_ASISTENCIA = <?= $diasJson ?>;
</script>
<script src="/assets/js/asistencia.js?v=<?= time() ?>"></script>
<?php include __DIR__ . '/views/footer.php'; ?>