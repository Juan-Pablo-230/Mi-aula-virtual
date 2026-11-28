<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Helpers.php';
require_once __DIR__ . '/core/GradeHelpers.php';
require_once __DIR__ . '/core/Feriados.php';
require_once __DIR__ . '/core/AsistenciaReglas.php';
require_once __DIR__ . '/models/AcademicModel.php';
require_once __DIR__ . '/core/Acceso.php';

Auth::checkRole(ROLES_ALL);
$user = Auth::currentUser($pdo);
$rol  = $user['rol'];
$userId = (int)$user['id'];
$miInst = (int)($user['institucion_id'] ?? 0);
$esAlumno = ($rol === 'alumno');
$esFamiliar = ($rol === 'familiar');

// La madre no pertenece a una institución: elige cuál ver. El resto trabaja con la suya.
$esMadre = ($rol === 'madre');
$instSel = $miInst;
$institucionesMadre = [];
if ($esMadre) {
    $institucionesMadre = $pdo->query("SELECT id, nombre FROM instituciones ORDER BY nombre")->fetchAll();
    $idsInst = array_map('intval', array_column($institucionesMadre, 'id'));
    $instSel = (int)($_GET['institucion_id'] ?? 0);
    if (!in_array($instSel, $idsInst, true)) $instSel = $idsInst[0] ?? 0;
}

$cicloActivo = $instSel ? AcademicModel::cicloActivo($pdo, $instSel) : null;
$añoActual = $cicloActivo ? (int)$cicloActivo['año'] : (int)date('Y');

$fecha = (string)($_GET['fecha'] ?? '');
if (!AsistenciaReglas::formatoValido($fecha)) $fecha = date('Y-m-d');
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
    if ($instSel) {
        $stmt = $pdo->prepare("SELECT DISTINCT año FROM ciclos_lectivos WHERE institucion_id = ? ORDER BY año DESC");
        $stmt->execute([$instSel]);
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
    $materiaIds[] = AsistenciaReglas::MATERIA_DIA; // asistencias por día completo (cursos configurados así)
    $divisionesEnAño = AcademicModel::divisionesDelAlumnoEnAño($pdo, $alumnoSel, $añoSel);

    $filtro = [
        'alumno_id' => $alumnoSel,
        'materia_id' => ['$in' => $materiaIds],
    ];
    if (!empty($divisionesEnAño)) $filtro['division_id'] = ['$in' => $divisionesEnAño];
    // El año se filtra en la consulta (antes se traían 500 registros y se filtraba después)
    $filtro['fecha'] = [
        '$gte' => new MongoDB\BSON\UTCDateTime(strtotime($añoSel . '-01-01 00:00:00') * 1000),
        '$lt'  => new MongoDB\BSON\UTCDateTime(strtotime(($añoSel + 1) . '-01-01 00:00:00') * 1000),
    ];
    $asistencias = $mongoDB->selectCollection('asistencia')->find($filtro, ['sort' => ['fecha' => -1], 'limit' => 1500])->toArray();

    // Valor de falta de cada registro (las fracciones son del día completo; por materia una falta vale 1)
    $valorFalta = function($a): float {
        if (isset($a['valor_falta'])) return (float)$a['valor_falta'];
        return ($a['estado'] ?? '') === 'ausente' ? 1.0 : 0.0;
    };
    // Resumen de faltas: día completo (suma con fracciones) y por materia
    $resumenFaltas = [];
    $hayCursoPorDia = false;
    $divDiaAlumno = AsistenciaReglas::divisionesDiaIds($pdo);
    foreach ($asistencias as $a) {
        $clave = (int)$a['materia_id'];
        $resumenFaltas[$clave] = ($resumenFaltas[$clave] ?? 0.0) + $valorFalta($a);
        if ($clave > 0 && in_array((int)($a['division_id'] ?? 0), $divDiaAlumno, true)) $hayCursoPorDia = true;
    }

    // Materias de cursos por día completo: ¿llevan asistencia propia (computa) o solo se informan?
    $propiaMat = [];
    foreach ($resumenFaltas as $mid => $_) if ($mid > 0) $propiaMat[$mid] = AsistenciaReglas::pesoPropio($pdo, (int)$mid);
    // Total acumulado del día completo = faltas del día + faltas de materias con asistencia propia × lo que suma cada una
    $totalGeneral = $resumenFaltas[AsistenciaReglas::MATERIA_DIA] ?? 0.0;
    $sumaMaterias = 0.0;
    foreach ($propiaMat as $mid => $peso) if ($peso && isset($resumenFaltas[$mid]) && $hayCursoPorDia) $sumaMaterias += $resumenFaltas[$mid] * $peso;
    $totalGeneral += $sumaMaterias;
    $matMap = [AsistenciaReglas::MATERIA_DIA => '🗓️ Día completo (todas las materias)'];
    foreach ($materias as $m) $matMap[(int)$m['id']] = $m['nombre'];

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
                                <option value="<?= (int)$aid ?>" <?= $aid === $alumnoSel ? 'selected' : '' ?>><?= h(nombreCompleto($hijo)) ?></option>
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

        <?php if (!empty($resumenFaltas)): ?>
        <div class="panel-vidrio" style="margin-bottom:1rem;">
            <h2>Resumen de faltas <?= $añoSel ?></h2>
            <table class="tabla-alumnos">
                <thead><tr><th>Materia / Día</th><th>Faltas</th></tr></thead>
                <tbody>
                <?php foreach ($matMap as $mid => $mnombre): if (!isset($resumenFaltas[$mid])) continue; ?>
                    <tr><td><?= h($mnombre) ?><?php if ($hayCursoPorDia && $mid > 0): ?> <small style="color:#a5c3e8;"><?= !empty($propiaMat[$mid]) ? '(asistencia propia: cada falta suma ' . h(AsistenciaReglas::fmtFaltas((float)$propiaMat[$mid])) . ' al total del día completo)' : '(informativa)' ?></small><?php endif; ?></td><td><strong><?= h(AsistenciaReglas::fmtFaltas($resumenFaltas[$mid])) ?></strong></td></tr>
                <?php endforeach; ?>
                <?php if ($sumaMaterias > 0): ?>
                    <tr><td><strong>Total acumulado (día completo + materias que suman)</strong></td><td><strong><?= h(AsistenciaReglas::fmtFaltas($totalGeneral)) ?></strong></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            <?php if ($hayCursoPorDia): ?>
                <small style="color:#a5c3e8;">En los cursos con asistencia por día completo, la asistencia de cada materia se informa pero no suma al tope para quedar libre, salvo las materias que llevan asistencia propia, que suman al total del día completo.</small>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="panel-vidrio">
            <h2>Historial <?= $añoSel ?></h2>
            <?php if (empty($asistencias)): ?>
                <p class="alerta info">Sin registros para el año <?= $añoSel ?>.</p>
            <?php else: ?>
                <table class="tabla-alumnos">
                    <thead><tr><th>Fecha</th><th>Materia / Día</th><th>Estado</th></tr></thead>
                    <tbody>
                        <?php foreach ($asistencias as $a):
                            $f = $a['fecha']->toDateTime();
                            $f->setTimezone(new DateTimeZone('America/Argentina/Buenos_Aires')); ?>
                            <tr>
                                <td><?= h($f->format('d/m/Y')) ?></td>
                                <td><?= h($matMap[(int)$a['materia_id']] ?? '—') ?></td>
                                <?php $vf = $valorFalta($a); $esDiaReg = (int)$a['materia_id'] === AsistenciaReglas::MATERIA_DIA; ?>
                                <td><?php if ($a['estado'] === 'presente' || $vf <= 0): ?>
                                        <span class="badge ok">Presente</span>
                                    <?php elseif ($esDiaReg && $vf < 1): ?>
                                        <span class="badge warn">Falta <?= h(AsistenciaReglas::etiquetaFalta($vf)) ?></span>
                                    <?php else: ?>
                                        <span class="badge danger"><?= $esDiaReg ? 'Falta 1' : 'Ausente' ?></span>
                                    <?php endif; ?></td>
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
    $stmt = $pdo->prepare("SELECT DISTINCT m.id, m.nombre FROM asignaciones_docente ad JOIN materias m ON m.id = ad.materia_id WHERE ad.docente_id = ?" . sqlMateriaDeInstActiva('m.id') . " ORDER BY m.nombre");
    $stmt->execute([$userId]);
} else {
    $stmt = $pdo->prepare("SELECT id, nombre FROM materias WHERE institucion_id = ? ORDER BY nombre");
    $stmt->execute([$instSel]);
}
$materias = $stmt->fetchAll();

// El materia_id de la URL debe estar entre las materias permitidas (la madre puede ver todas)
$materiasPermitidas = array_map('intval', array_column($materias, 'id'));
if ($materiaId && !in_array($materiaId, $materiasPermitidas, true)) {
    if ($esMadre) $materiaId = 0;   // cambió de institución: la materia anterior ya no corresponde
    else redirect('/plataforma/asistencia.php');
}

// Cursos que toman asistencia por día completo (en lugar de por materia)
$yoAsist = Acceso::yo();
$divisionesDia = AsistenciaReglas::divisionesPorDia($pdo, $yoAsist, $instSel);
$divisionesDiaIds = array_map('intval', array_column($divisionesDia, 'id'));
$divisionDia = (int)($_GET['division_dia'] ?? 0);
if ($divisionDia && !in_array($divisionDia, $divisionesDiaIds, true)) $divisionDia = 0;
if ($divisionDia) $materiaId = 0;   // se elige una cosa o la otra
$modoDia = $divisionDia > 0;

if (!$materiaId && !$modoDia) {
    if (!empty($materias)) $materiaId = (int)$materias[0]['id'];
    elseif (!empty($divisionesDia)) { $divisionDia = (int)$divisionesDia[0]['id']; $modoDia = true; }
}
// Clave con la que se guardan las asistencias: la materia, o 0 si es por día completo
$claveMateria = $modoDia ? AsistenciaReglas::MATERIA_DIA : $materiaId;
$asigDia = $modoDia ? AsistenciaReglas::asignacionDeDivision($pdo, $divisionDia) : 0;

$alumnos = [];
$asistenciaHoy = [];
$faltasPorAlumno = [];
$libresPorAlumno = [];
$libresGenerales = [];
$topesPorAlumno = [];
$diasConAsistencia = [];
$topeMateria = 10;
$valorHoy = [];
$faltasTotalesMateria = [];
$divDiaIds = AsistenciaReglas::divisionesDiaIds($pdo);                 // divisiones de cursos por día completo
$pesoPropio = (!$modoDia && $materiaId) ? AsistenciaReglas::pesoPropio($pdo, $materiaId) : null;   // cuánto suma al total general si lleva asistencia propia
$cuentaPropia = $pesoPropio !== null;
$puedeCargarDia = AsistenciaReglas::puedeCargarDiaCompleto($rol);       // solo el secretario pasa el día completo
$puedeGuardar = $modoDia ? $puedeCargarDia : true;

if ($materiaId || $modoDia) {
  if ($modoDia) {
    // Por día: todos los alumnos activos de la división
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.id, u.nombre, u.apellido, h.division_id
        FROM usuarios u
        JOIN alumnos_division_historial h ON h.alumno_id = u.id AND h.activo = 1 AND h.año_lectivo = ?
        WHERE h.division_id = ? AND u.rol = 'alumno' AND u.activo = 1
        ORDER BY u.apellido, u.nombre
    ");
    $stmt->execute([$añoSel, $divisionDia]);
  } else {
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.id, u.nombre, u.apellido, h.division_id
        FROM usuarios u
        JOIN alumnos_division_historial h ON h.alumno_id = u.id AND h.activo = 1 AND h.año_lectivo = ?
        JOIN asignaciones_docente adoc ON adoc.division_id = h.division_id
        JOIN divisiones dv ON dv.id = h.division_id
        JOIN asignaciones asg ON asg.id = dv.asignacion_id
        LEFT JOIN alumno_materia_exclusion ame ON ame.alumno_id = u.id AND ame.materia_id = adoc.materia_id
        WHERE adoc.materia_id = ? AND u.rol = 'alumno' AND u.activo = 1 AND ame.alumno_id IS NULL
        " . AcademicModel::sqlCursaMateria('u.id', 'h.division_id', 'adoc.materia_id') . "
        ORDER BY u.apellido, u.nombre
    ");
    $stmt->execute([$añoSel, $materiaId]);
  }
    $alumnos = $stmt->fetchAll();

    if (!empty($alumnos)) {
        $aIds = array_map(fn($a) => (int)$a['id'], $alumnos);
        $regla = $mongoDB->selectCollection('reglas_asistencia')->findOne($modoDia ? ['materia_id' => 0, 'asignacion_id' => $asigDia] : ['materia_id' => $materiaId]);
        $topeMateria = (int)($regla['faltas_maximas'] ?? 10);
        $topesP = $mongoDB->selectCollection('topes_alumno')->find(['materia_id' => $claveMateria, 'alumno_id' => ['$in' => $aIds]])->toArray();
        foreach ($topesP as $t) $topesPorAlumno[(int)$t['alumno_id']] = (int)$t['tope'];

        $fIni = new MongoDB\BSON\UTCDateTime(strtotime($fecha . ' 00:00:00') * 1000);
        $fFin = new MongoDB\BSON\UTCDateTime(strtotime($fecha . ' 23:59:59') * 1000);
        $regs = $mongoDB->selectCollection('asistencia')->find(['materia_id' => $claveMateria, 'alumno_id' => ['$in' => $aIds], 'fecha' => ['$gte' => $fIni, '$lte' => $fFin]])->toArray();
        foreach ($regs as $r) {
            $asistenciaHoy[(int)$r['alumno_id']] = $r['estado'];
            $valorHoy[(int)$r['alumno_id']] = isset($r['valor_falta']) ? (float)$r['valor_falta'] : ($r['estado'] === 'ausente' ? 1.0 : 0.0);
        }

        // Faltas que cuentan para el tope (con fracciones en el día completo; ver AsistenciaReglas::faltasPorAlumno)
        $faltasPorAlumno = AsistenciaReglas::faltasPorAlumno($pdo, $mongoDB, $aIds, $claveMateria);
        // Faltas totales de la materia, también las que no computan (cursos por día completo): se muestran igual
        if (!$modoDia && !empty($divDiaIds)) {
            $todas = $mongoDB->selectCollection('asistencia')->aggregate([
                ['$match' => ['materia_id' => $claveMateria, 'alumno_id' => ['$in' => $aIds], 'estado' => 'ausente']],
                ['$group' => ['_id' => '$alumno_id', 'total' => ['$sum' => 1]]]
            ])->toArray();
            foreach ($todas as $f) $faltasTotalesMateria[(int)$f['_id']] = (int)$f['total'];
        }

        $libres = $mongoDB->selectCollection('alumno_libre')->find(['materia_id' => $claveMateria, 'alumno_id' => ['$in' => $aIds]])->toArray();
        foreach ($libres as $l) $libresPorAlumno[(int)$l['alumno_id']] = true;
        // En una materia, quien quedó libre por la asistencia general (día completo, clave 0) también figura libre
        $libresGenerales = [];
        if (!$modoDia) {
            $lg = $mongoDB->selectCollection('alumno_libre')->find(['materia_id' => 0, 'alumno_id' => ['$in' => $aIds]])->toArray();
            foreach ($lg as $l) { $libresGenerales[(int)$l['alumno_id']] = true; $libresPorAlumno[(int)$l['alumno_id']] = true; }
        }

        $filtroExistentes = ['materia_id' => $claveMateria];
        if ($modoDia) $filtroExistentes['division_id'] = $divisionDia;   // por día: solo los días de ESTA división
        $existentes = $mongoDB->selectCollection('asistencia')->find($filtroExistentes, ['projection' => ['fecha' => 1]])->toArray();
        foreach ($existentes as $e) {
            $f = $e['fecha']->toDateTime();
            $f->setTimezone(new DateTimeZone('America/Argentina/Buenos_Aires'));
            $diasConAsistencia[] = $f->format('Y-m-d');
        }
        $diasConAsistencia = array_values(array_unique($diasConAsistencia));
    }
}

$esDirectivoVista = in_array($rol, ['directivo', 'secretario', 'madre'], true);
// Solo directivo y secretario definen los períodos en los que no se puede pasar asistencia
$puedeBloquear = in_array($rol, ['directivo', 'secretario'], true) && $miInst > 0;
$instMateria = $modoDia ? (int)Acceso::instDeDivision($pdo, $divisionDia) : ($materiaId ? (int)Acceso::instDeMateria($pdo, $materiaId) : $instSel);
$periodosBloqueados = AsistenciaReglas::periodos($mongoDB, $instMateria);
$errorFecha = ($materiaId || $modoDia) ? AsistenciaReglas::validarFecha($pdo, $mongoDB, $claveMateria, $fecha, $divisionDia) : null;
$ocultarLibres = !empty($_GET['ocultar_libres']);   // por defecto los libres se muestran (bloqueados)
$alumnosVisibles = [];
foreach ($alumnos as $a) {
    if (!$ocultarLibres || !isset($libresPorAlumno[(int)$a['id']])) $alumnosVisibles[] = $a;
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
                    <a href="?año=<?= $a ?>&materia_id=<?= $materiaId ?>&division_dia=<?= $divisionDia ?><?= $esMadre ? '&institucion_id=' . $instSel : '' ?>" class="btn-mini" style="<?= $a === $añoSel ? 'background:var(--celeste);color:#fff;' : 'background:rgba(255,255,255,0.08);color:#cfe2ff;' ?>">
                        <?= $a ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="panel-vidrio" style="padding:1rem; margin-bottom:1rem;">
        <form method="get" style="display:flex; gap:1rem; flex-wrap:wrap; align-items:center;">
            <input type="hidden" name="año" value="<?= $añoSel ?>">
            <input type="hidden" name="materia_id" id="hiddenMateria" value="<?= (int)$materiaId ?>">
            <input type="hidden" name="division_dia" id="hiddenDivisionDia" value="<?= (int)$divisionDia ?>">
            <?php if ($esMadre): ?>
            <label style="margin:0;">Institución:
                <select name="institucion_id" id="selectInstitucion" onchange="document.getElementById('hiddenMateria').value=0;document.getElementById('hiddenDivisionDia').value=0;this.form.submit();">
                    <?php foreach ($institucionesMadre as $im): ?>
                        <option value="<?= (int)$im['id'] ?>" <?= (int)$im['id'] === $instSel ? 'selected' : '' ?>><?= h($im['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php endif; ?>
            <label style="margin:0;"><?= empty($divisionesDia) ? 'Materia:' : 'Materia / curso:' ?>
                <select onchange="cambiarClase(this)">
                    <?php if (!empty($divisionesDia)): ?><optgroup label="Por materia"><?php endif; ?>
                    <?php foreach ($materias as $m): ?>
                        <option value="m:<?= (int)$m['id'] ?>" <?= (!$modoDia && $m['id'] == $materiaId) ? 'selected' : '' ?>><?= h($m['nombre']) ?></option>
                    <?php endforeach; ?>
                    <?php if (!empty($divisionesDia)): ?></optgroup>
                    <optgroup label="Asistencia por día completo">
                        <?php foreach ($divisionesDia as $dd): ?>
                            <option value="d:<?= (int)$dd['id'] ?>" <?= ($modoDia && (int)$dd['id'] === $divisionDia) ? 'selected' : '' ?>>🗓️ <?= h($dd['curso'] . ' ' . $dd['division']) ?></option>
                        <?php endforeach; ?>
                    </optgroup><?php endif; ?>
                </select>
            </label>
            <label style="margin:0;">Fecha:
                <input type="date" name="fecha" id="fechaAsistencia" value="<?= h($fecha) ?>" max="<?= h(date('Y-m-d')) ?>" style="width:11rem; margin:0;">
            </label>
            <label style="margin:0;"><input type="checkbox" name="ocultar_libres" value="1" <?= $ocultarLibres ? 'checked' : '' ?> onchange="this.form.submit()"> Ocultar libres</label>
            <?php if ($puedeBloquear): ?>
                <button type="button" class="btn-secundario" onclick="abrirModalPeriodos()">🚫 Períodos sin asistencia</button>
            <?php endif; ?>
            <?php if ($esDirectivoVista && ($materiaId || $modoDia)): ?>
            <label style="margin:0; flex-basis:100%;">⚙️ Tope general de faltas<?= $modoDia ? ' del curso' : '' ?> (queda LIBRE al llegar a este número):
                <span style="display:inline-flex; gap:0.5rem; align-items:center;">
                    <input type="number" id="topeMateria" class="input-tope" value="<?= (int)$topeMateria ?>" min="1" max="999" onkeydown="if(event.key==='Enter'){event.preventDefault();guardarTopeMateria();}">
                    <button type="button" class="btn-primario" onclick="guardarTopeMateria()">Guardar tope</button>
                </span>
            </label>
            <?php endif; ?>
            <?php if (!empty($periodosBloqueados)): ?>
            <small style="color:#fbbf24; flex-basis:100%;">🚫 Sin asistencia:
                <?php foreach ($periodosBloqueados as $i => $pb): ?><?= $i ? ' · ' : '' ?><?= h(AsistenciaReglas::fmt($pb['desde'])) ?> al <?= h(AsistenciaReglas::fmt($pb['hasta'])) ?><?= $pb['motivo'] !== '' ? ' (' . h($pb['motivo']) . ')' : '' ?><?php endforeach; ?>
            </small>
            <?php endif; ?>
            <?php if ($esDirectivoVista && ($materiaId || $modoDia)): ?>
            <small style="color:#a5c3e8; flex-basis:100%;">Aplica a todos los alumnos <?= $modoDia ? 'del curso' : 'de la materia' ?>, salvo los que tengan un tope personal (botón ✏️ en cada tarjeta).</small>
            <?php endif; ?>
        </form>
    </div>

    <div id="fechaError" class="alerta danger" style="display:none; margin-bottom:1rem;"></div>
    <?php if ($errorFecha): ?>
        <div class="alerta danger" style="margin-bottom:1rem;">⚠️ <?= h($errorFecha) ?> Elegí otra fecha para pasar lista.</div>
    <?php endif; ?>

    <?php if ($modoDia): ?>
        <div class="alerta info" style="margin-bottom:1rem;">🗓️ <strong>Asistencia por día completo.</strong> La asistencia que se carga acá vale para todo el día: cada alumno puede tener presente, ¼, ½, ¾ o 1 falta, y se suman al total.
            <?php $pr = AsistenciaReglas::materiasPropias($pdo); if ($pr): ?><br>➕ También suman al total las faltas de las materias con asistencia propia: <?= h(implode(', ', array_map(fn($p) => $p['nombre'] . ' (×' . AsistenciaReglas::fmtFaltas($p['peso']) . ')', $pr))) ?>.<?php endif; ?>
            <?php if (!$puedeCargarDia): ?><br>🔒 Solo el <strong>secretario</strong> pasa la asistencia por día completo; vos la ves en modo lectura. Los docentes cargan la asistencia de su materia.<?php endif; ?></div>
    <?php elseif ($materiaId && !empty($alumnosVisibles) && array_filter($alumnosVisibles, fn($a) => in_array((int)$a['division_id'], $divDiaIds, true))): ?>
        <div class="alerta info" style="margin-bottom:1rem;">ℹ️ Algunos alumnos cursan en un curso con asistencia por día completo. Su asistencia en esta materia <strong>se guarda y la ve el alumno</strong>.
            <?= $cuentaPropia ? 'Esta materia lleva <strong>asistencia propia</strong>: cada falta suma <strong>' . h(AsistenciaReglas::fmtFaltas((float)$pesoPropio)) . '</strong> al total acumulado del curso.' : 'No suma al total del curso.' ?></div>
    <?php endif; ?>

    <?php if ($materiaId || $modoDia): ?>
        <?php if (empty($alumnosVisibles)): ?>
            <div class="panel-vidrio"><p class="alerta info">Sin alumnos para el año <?= $añoSel ?>.</p></div>
        <?php else: ?>
            <div class="asistencia-grid">
                <?php foreach ($alumnosVisibles as $a):
                    $aid = (int)$a['id'];
                    $estado = $asistenciaHoy[$aid] ?? 'presente';
                    $valor = (float)($valorHoy[$aid] ?? 0.0);
                    $esLibre = isset($libresPorAlumno[$aid]);
                    $faltas = (float)($faltasPorAlumno[$aid] ?? 0);
                    $tope = topeDeAlumno($aid, $topesPorAlumno, $topeMateria);
                    $clase = $esLibre ? 'libre' : ($estado === 'ausente' ? 'ausente' : 'presente');
                    // Materia de un curso por día completo (sin asistencia propia): se guarda y se muestra, pero no computa
                    $enCursoDia = !$modoDia && in_array((int)$a['division_id'], $divDiaIds, true);
                    $conClick = !$modoDia || $esLibre;   // por día completo se elige el valor con los botones ¼ ½ ¾ 1
                ?>
                    <div class="alumno-card <?= $clase ?>" data-alumno-id="<?= $aid ?>" data-estado="<?= h($estado) ?>" data-original="<?= h($estado) ?>" data-valor="<?= $valor ?>" data-original-valor="<?= $valor ?>" data-libre="<?= $esLibre ? (isset($libresGenerales[$aid]) ? '2' : '1') : '0' ?>" <?= $conClick ? 'onclick="toggleAsistencia(this, ' . $aid . ')"' : '' ?>>
                        <div class="alumno-card-nombre"><?= h($a['apellido']) ?><br><?= h($a['nombre']) ?></div>
                        <div class="alumno-card-info">
                            <?php if ($esLibre): ?>🔒 Libre<?= isset($libresGenerales[$aid]) ? ' <small>(asistencia general)</small>' : '' ?>
                            <?php elseif ($enCursoDia): ?>Faltas: <strong><?= (int)($faltasTotalesMateria[$aid] ?? 0) ?></strong> <?php if ($cuentaPropia): ?><small title="Cada falta suma este valor al total del curso">(suma <?= h(AsistenciaReglas::fmtFaltas((float)$pesoPropio)) ?> c/u al total)</small><?php else: ?><small title="Curso por día completo: se guarda pero no suma al total">(no suma)</small><?php endif; ?>
                            <?php else: ?>Faltas: <strong class="faltas-total"><?= h(AsistenciaReglas::fmtFaltas($faltas)) ?>/<?= $tope ?></strong>
                            <?php endif; ?>
                        </div>
                        <?php if ($modoDia && !$esLibre): ?>
                            <div class="falta-botones" role="group" aria-label="Faltas del día">
                                <?php foreach (AsistenciaReglas::VALORES_FALTA as $vf): ?>
                                    <button type="button" class="falta-btn <?= abs($vf - $valor) < 0.001 ? 'activo' : '' ?>" data-valor="<?= $vf ?>"
                                            title="<?= $vf == 0 ? 'Presente' : 'Falta ' . AsistenciaReglas::etiquetaFalta($vf) ?>"
                                            <?= $puedeCargarDia ? 'onclick="event.stopPropagation(); setFalta(this, ' . $aid . ', ' . $vf . ')"' : 'disabled' ?>><?= $vf == 0 ? '✓' : AsistenciaReglas::etiquetaFalta($vf) ?></button>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($esDirectivoVista): ?>
                            <button type="button" class="btn-mini" style="margin-top:0.35rem; font-size:0.7rem;" title="<?= isset($topesPorAlumno[$aid]) ? 'Tope personal' : 'Tope general' ?> — cambiar tope de este alumno" onclick="event.stopPropagation(); cambiarTope(<?= $aid ?>, <?= (int)$tope ?>, <?= h(jsonJs(nombreCompleto($a))) ?>)">✏️ Tope<?= isset($topesPorAlumno[$aid]) ? ' (personal)' : '' ?></button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($puedeGuardar): ?>
            <div class="asistencia-guardar" style="margin-top:1rem; text-align:center;">
                <button class="btn-primario" id="btnGuardarAsistencia" onclick="guardarAsistencia()" <?= $errorFecha ? 'disabled title="' . h($errorFecha) . '"' : '' ?>>💾 Guardar asistencia</button>
                <span id="avisoCambios" style="display:none; color:#fbbf24; margin-left:1rem;">Cambios sin guardar</span>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</main>
<?php if ($puedeBloquear): ?>
<div class="modal-fondo oculto" id="modalPeriodos">
    <div class="modal-caja" style="min-width: 30rem;">
        <h3>🚫 Períodos sin asistencia</h3>
        <p style="color:#cfe2ff; font-size:0.85rem; margin-bottom:0.75rem;">En estos rangos de fechas no se puede pasar lista en ninguna materia de la institución (receso, paro, acto, etc.).</p>
        <div id="listaPeriodos" style="max-height:14rem; overflow-y:auto; margin-bottom:0.5rem;">
            <?php if (empty($periodosBloqueados)): ?>
                <p class="alerta info" style="margin:0;">No hay períodos cargados.</p>
            <?php else: foreach ($periodosBloqueados as $pb): ?>
                <div class="panel-vidrio" style="padding:0.5rem 0.75rem; margin-bottom:0.4rem; display:flex; justify-content:space-between; align-items:center; gap:0.5rem;">
                    <span><strong><?= h(AsistenciaReglas::fmt($pb['desde'])) ?> → <?= h(AsistenciaReglas::fmt($pb['hasta'])) ?></strong><?= $pb['motivo'] !== '' ? '<br><small style="color:#a5c3e8;">' . h($pb['motivo']) . '</small>' : '' ?></span>
                    <button type="button" class="btn-peligro-sm" title="Quitar período" onclick="eliminarPeriodo('<?= h($pb['id']) ?>')">🗑️</button>
                </div>
            <?php endforeach; endif; ?>
        </div>
        <form id="formPeriodo">
            <div style="display:flex; gap:0.75rem; flex-wrap:wrap;">
                <label style="flex:1; min-width:9rem;">Desde <input type="date" name="desde" id="periodoDesde" required></label>
                <label style="flex:1; min-width:9rem;">Hasta <input type="date" name="hasta" id="periodoHasta" required></label>
            </div>
            <label>Motivo (opcional) <input type="text" name="motivo" id="periodoMotivo" maxlength="150" placeholder="Ej: Receso invernal" autocomplete="off"></label>
            <div id="periodoError" class="alerta danger" style="display:none;"></div>
            <div class="modal-acciones">
                <button type="button" class="btn-secundario" onclick="cerrarModalPeriodos()">Cerrar</button>
                <button type="submit" class="btn-primario">Agregar período</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
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
window.MATERIA_ID = <?= (int)$claveMateria ?>;
window.DIVISION_DIA = <?= (int)$divisionDia ?>;
window.FECHA = <?= jsonJs($fecha) ?>;
window.ES_DIRECTIVO = <?= in_array($rol, ['directivo','secretario','madre']) ? 'true' : 'false' ?>;
window.MODO_DIA = <?= $modoDia ? 'true' : 'false' ?>;
window.FERIADOS = <?= $feriadosJson ?>;
window.PERIODOS_BLOQUEADOS = <?= jsonJs($periodosBloqueados) ?>;
window.HOY = <?= jsonJs(date('Y-m-d')) ?>;
window.DIAS_CON_ASISTENCIA = <?= $diasJson ?>;
</script>
<script src="/assets/js/asistencia.js?v=<?= time() ?>"></script>
<?php include __DIR__ . '/views/footer.php'; ?>