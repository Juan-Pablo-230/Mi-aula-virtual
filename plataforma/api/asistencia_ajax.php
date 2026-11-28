<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Helpers.php';
require_once __DIR__ . '/../core/Acceso.php';
require_once __DIR__ . '/../core/Feriados.php';
require_once __DIR__ . '/../core/AsistenciaReglas.php';
require_once __DIR__ . '/../models/AcademicModel.php';

header('Content-Type: application/json; charset=utf-8');
Auth::checkRole(['docente', 'directivo', 'secretario', 'madre']);

$action = $_GET['action'] ?? '';
$userId = (int)$_SESSION['user_id'];
$rol    = $_SESSION['rol'];
$esDirectivo = in_array($rol, ['directivo', 'secretario', 'madre']);
$yo = Acceso::yo();
// Períodos sin asistencia: solo directivo y secretario (con institución), no la cuenta madre
$puedeBloquear = in_array($rol, ['directivo', 'secretario'], true) && $yo['inst'] > 0;

$colAsistencia  = $mongoDB->selectCollection('asistencia');
$colReglas      = $mongoDB->selectCollection('reglas_asistencia');
$colLibres      = $mongoDB->selectCollection('alumno_libre');
$colTopesAlumno = $mongoDB->selectCollection('topes_alumno');

// ============================================================
// HELPER: calcular faltas y evaluar libre
// ============================================================
/**
 * Tras guardar la asistencia de una MATERIA en un curso por día completo: si la materia lleva asistencia propia,
 * sus faltas suman al total general del curso, así que hay que reevaluar el LIBRE general (clave 0).
 */
function evaluarGeneralTrasMateria(PDO $pdo, $mongoDB, int $alumnoId, int $materiaId, int $divisionId): ?array {
    if ($materiaId <= 0 || AsistenciaReglas::pesoPropio($pdo, $materiaId) === null) return null;
    if (!AsistenciaReglas::divisionPorDia($pdo, $divisionId)) return null;
    return evaluarLibre($pdo, $mongoDB, $alumnoId, 0, AsistenciaReglas::asignacionDeDivision($pdo, $divisionId));
}

function evaluarLibre(PDO $pdo, $mongoDB, int $alumnoId, int $materiaId, int $asigId = 0): array {
    $colAsistencia  = $mongoDB->selectCollection('asistencia');
    $colLibres      = $mongoDB->selectCollection('alumno_libre');
    $colReglas      = $mongoDB->selectCollection('reglas_asistencia');
    $colTopesAlumno = $mongoDB->selectCollection('topes_alumno');

    $tope = 10;
    // materia_id 0 = asistencia por día completo: el tope general es del curso (asignación)
    $regla = $colReglas->findOne($materiaId > 0 ? ['materia_id' => $materiaId] : ['materia_id' => 0, 'asignacion_id' => $asigId]);
    if ($regla && isset($regla['faltas_maximas'])) {
        $tope = (int)$regla['faltas_maximas'];
    }
    $topeAlumno = $colTopesAlumno->findOne(['materia_id' => $materiaId, 'alumno_id' => $alumnoId]);
    $topePersonal = false;
    if ($topeAlumno && isset($topeAlumno['tope'])) {
        $tope = (int)$topeAlumno['tope'];
        $topePersonal = true;
    }

    // Faltas que cuentan (con fracciones en el día completo; la asistencia por materia de un curso por día completo no cuenta)
    $faltas = AsistenciaReglas::faltasPorAlumno($pdo, $mongoDB, [$alumnoId], $materiaId)[$alumnoId] ?? 0.0;

    $yaLibre = $colLibres->findOne(['alumno_id' => $alumnoId, 'materia_id' => $materiaId]);

    if ($faltas >= $tope && !$yaLibre) {
        $colLibres->insertOne([
            'alumno_id' => $alumnoId,
            'materia_id' => $materiaId,
            'motivo' => 'Superó el tope de ' . $tope . ' faltas (' . AsistenciaReglas::fmtFaltas($faltas) . ')',
            'fecha' => new MongoDB\BSON\UTCDateTime(),
            'registrado_por' => (int)$_SESSION['user_id'],
        ]);
        return ['libre' => true, 'faltas' => $faltas, 'tope' => $tope, 'tope_personal' => $topePersonal];
    }

    return ['libre' => $yaLibre !== null, 'faltas' => $faltas, 'tope' => $tope, 'tope_personal' => $topePersonal];
}

// ============================================================
// ÁMBITO: asistencia por materia (como hasta ahora) o por día completo del curso
// ============================================================
/**
 * Si llega division_dia, se trabaja con la asistencia por día de esa división (solo si su curso está
 * configurado así). Si no, con la materia (materia_id). En modo día las asistencias se guardan con materia_id = 0.
 */
function resolverAmbito(PDO $pdo, array $yo, bool $escritura = false): array {
    $divDia = (int)($_POST['division_dia'] ?? 0);
    if ($divDia > 0) {
        // Pasar la asistencia del día completo: solo el secretario (dirección y la madre pueden verla)
        if ($escritura && !AsistenciaReglas::puedeCargarDiaCompleto((string)$yo['rol'])) {
            jsonResponse(['ok' => false, 'mensaje' => 'Solo el secretario puede pasar la asistencia por día completo. Los docentes cargan la de su materia.'], 403);
        }
        if (!AsistenciaReglas::divisionPorDia($pdo, $divDia)) {
            jsonResponse(['ok' => false, 'mensaje' => 'Ese curso no toma asistencia por día'], 400);
        }
        if (!Acceso::puedeGestionarDivisionDia($pdo, $yo, $divDia)) {
            jsonResponse(['ok' => false, 'mensaje' => 'No tenés acceso a esa división'], 403);
        }
        return ['dia' => true, 'materiaId' => AsistenciaReglas::MATERIA_DIA, 'divisionDia' => $divDia,
                'asigId' => AsistenciaReglas::asignacionDeDivision($pdo, $divDia)];
    }
    $materiaId = (int)($_POST['materia_id'] ?? 0);
    if (!$materiaId) jsonResponse(['ok' => false, 'mensaje' => 'Materia requerida'], 400);
    if (!Acceso::puedeGestionarMateria($pdo, $yo, $materiaId)) {
        jsonResponse(['ok' => false, 'mensaje' => 'No tenés acceso a esa materia'], 403);
    }
    return ['dia' => false, 'materiaId' => $materiaId, 'divisionDia' => 0, 'asigId' => 0];
}

function alumnoEnAmbito(PDO $pdo, array $yo, array $amb, int $alumnoId): bool {
    return $amb['dia']
        ? Acceso::puedeGestionarAlumnoDia($pdo, $yo, $alumnoId, $amb['divisionDia'])
        : Acceso::puedeGestionarAlumnoMateria($pdo, $yo, $alumnoId, $amb['materiaId']);
}

/** División del alumno para guardar el registro: la del curso en modo día; la vigente en modo materia. */
function divisionParaRegistro(PDO $pdo, array $amb, int $alumnoId): int {
    if ($amb['dia']) return $amb['divisionDia'];
    $st = $pdo->prepare("
        SELECT ad.division_id
        FROM alumnos_division ad
        JOIN divisiones d ON d.id = ad.division_id
        JOIN asignaciones a ON a.id = d.asignacion_id
        JOIN materias m ON m.id = ?
        WHERE ad.alumno_id = ? AND (m.institucion_id IS NULL OR m.institucion_id = a.institucion_id)
        " . AcademicModel::sqlCursaMateria('ad.alumno_id', 'ad.division_id', 'm.id') . "
        ORDER BY EXISTS (SELECT 1 FROM materias_asignaciones ma WHERE ma.materia_id = m.id AND ma.asignacion_id = d.asignacion_id) DESC, ad.division_id LIMIT 1
    ");
    $st->execute([$amb['materiaId'], $alumnoId]);
    return (int)$st->fetchColumn();
}

/**
 * Normaliza lo que manda la pantalla a [estado, valor_falta].
 * Por materia: 'presente' / 'ausente' (ausente = 1 falta).
 * Por día completo: además 0, 0.25, 0.5, 0.75 o 1 (fracciones de falta).
 * @return array{0:string,1:float}|null null si el valor no es válido
 */
function normalizarFalta($v, bool $dia): ?array {
    if ($v === 'presente') return ['presente', 0.0];
    if ($v === 'ausente')  return ['ausente', 1.0];
    if (!$dia || !is_numeric($v)) return null;
    $f = round((float)$v, 2);
    foreach (AsistenciaReglas::VALORES_FALTA as $ok) {
        if (abs($f - $ok) < 0.001) return [$ok > 0 ? 'ausente' : 'presente', $ok];
    }
    return null;
}

try {
    switch ($action) {

    case 'guardar_lote':
    $amb       = resolverAmbito($pdo, $yo, true);
    $materiaId = $amb['materiaId'];
    $fecha     = $_POST['fecha'] ?? date('Y-m-d');
    $cambios   = json_decode($_POST['cambios'] ?? '{}', true);

    if (!is_array($cambios) || empty($cambios)) {
        jsonResponse(['ok' => false, 'mensaje' => 'No hay cambios para guardar'], 400);
    }

    // Fecha válida, no futura, ni domingo, ni feriado, dentro del ciclo y fuera de períodos bloqueados
    $errorFecha = AsistenciaReglas::validarFecha($pdo, $mongoDB, $materiaId, (string)$fecha, $amb['divisionDia']);
    if ($errorFecha) jsonResponse(['ok' => false, 'mensaje' => $errorFecha], 400);

    $fechaInicio = new MongoDB\BSON\UTCDateTime(strtotime($fecha . ' 00:00:00') * 1000);
    $libres = [];

    foreach ($cambios as $alumnoId => $valorRecibido) {
        $alumnoId = (int)$alumnoId;
        $norm = normalizarFalta($valorRecibido, $amb['dia']);
        if ($norm === null) continue;
        [$estado, $valorFalta] = $norm;
        // Solo alumnos que este usuario puede gestionar en esta materia / división
        if (!alumnoEnAmbito($pdo, $yo, $amb, $alumnoId)) continue;

        $divisionId = divisionParaRegistro($pdo, $amb, $alumnoId);
        if (!$divisionId) continue;

        // Upsert
        $colAsistencia->updateOne(
            ['alumno_id' => $alumnoId, 'materia_id' => $materiaId, 'fecha' => $fechaInicio],
            ['$set' => [
                'division_id' => $divisionId,
                'estado' => $estado,
                'valor_falta' => $valorFalta,
                'fecha_actualizacion' => new MongoDB\BSON\UTCDateTime(),
            ]],
            ['upsert' => true]
        );

        // Evaluar libre
        $resultado = evaluarLibre($pdo, $mongoDB, $alumnoId, $materiaId, $amb['asigId']);
        $general = evaluarGeneralTrasMateria($pdo, $mongoDB, $alumnoId, $materiaId, (int)$divisionId);
        if ($general && $general['libre']) $resultado['libre'] = true;
        if ($resultado['libre'] && $estado === 'ausente') {
            $stmt = $pdo->prepare("SELECT nombre, apellido FROM usuarios WHERE id = ?");
            $stmt->execute([$alumnoId]);
            $u = $stmt->fetch();
            if ($u) $libres[] = nombreCompleto($u);
        }
    }

    jsonResponse(['ok' => true, 'libres' => $libres]);
    break;

        // ============================================================
        // GUARDAR UN SOLO ALUMNO (tap en la card)
        // ============================================================
        case 'guardar_uno':
            $amb       = resolverAmbito($pdo, $yo, true);
            $materiaId = $amb['materiaId'];
            $alumnoId  = (int)($_POST['alumno_id'] ?? 0);
            $fecha     = $_POST['fecha'] ?? date('Y-m-d');
            $norm      = normalizarFalta($_POST['estado'] ?? 'presente', $amb['dia']);

            if (!$alumnoId || $norm === null) {
                jsonResponse(['ok' => false, 'mensaje' => 'Datos inválidos'], 400);
            }
            [$estado, $valorFalta] = $norm;
            if (!alumnoEnAmbito($pdo, $yo, $amb, $alumnoId)) {
                jsonResponse(['ok' => false, 'mensaje' => 'No tenés permiso sobre ese alumno'], 403);
            }

            $errorFecha = AsistenciaReglas::validarFecha($pdo, $mongoDB, $materiaId, (string)$fecha, $amb['divisionDia']);
            if ($errorFecha) jsonResponse(['ok' => false, 'mensaje' => $errorFecha], 400);

            $divisionId = divisionParaRegistro($pdo, $amb, $alumnoId);
            if (!$divisionId) jsonResponse(['ok' => false, 'mensaje' => 'Alumno sin división'], 400);

            $fechaInicio = new MongoDB\BSON\UTCDateTime(strtotime($fecha . ' 00:00:00') * 1000);
            $colAsistencia->updateOne(
                ['alumno_id' => $alumnoId, 'materia_id' => $materiaId, 'fecha' => $fechaInicio],
                ['$set' => [
                    'division_id' => $divisionId,
                    'estado' => $estado,
                    'valor_falta' => $valorFalta,
                    'fecha_actualizacion' => new MongoDB\BSON\UTCDateTime(),
                ]],
                ['upsert' => true]
            );

            $resultado = evaluarLibre($pdo, $mongoDB, $alumnoId, $materiaId, $amb['asigId']);
            $general = evaluarGeneralTrasMateria($pdo, $mongoDB, $alumnoId, $materiaId, (int)$divisionId);
            if ($general && $general['libre']) $resultado['libre'] = true;
            jsonResponse([
                'ok' => true,
                'libre' => $resultado['libre'],
                'faltas' => $resultado['faltas'],
                'faltas_texto' => AsistenciaReglas::fmtFaltas((float)$resultado['faltas']),
                'tope' => $resultado['tope'],
                'tope_personal' => $resultado['tope_personal'],
            ]);
            break;

        // ============================================================
        // GUARDAR REGLA GENERAL (SOLO DIRECTIVO)
        // ============================================================
        case 'guardar_regla':
            if (!$esDirectivo) {
                jsonResponse(['ok' => false, 'mensaje' => 'Solo dirección puede cambiar el tope general'], 403);
            }
            $amb       = resolverAmbito($pdo, $yo);
            $materiaId = $amb['materiaId'];
            $faltasMax = max(1, (int)($_POST['faltas_maximas'] ?? 10));

            if ($amb['dia']) {
                // Asistencia por día: el tope general es del curso completo
                $colReglas->updateOne(
                    ['materia_id' => 0, 'asignacion_id' => $amb['asigId']],
                    ['$set' => ['faltas_maximas' => $faltasMax]],
                    ['upsert' => true]
                );
                $stDivs = $pdo->prepare("SELECT id FROM divisiones WHERE asignacion_id = ?");
                $stDivs->execute([$amb['asigId']]);
                $conFaltas = $colAsistencia->distinct('alumno_id', [
                    'materia_id' => 0, 'estado' => 'ausente',
                    'division_id' => ['$in' => array_map('intval', $stDivs->fetchAll(PDO::FETCH_COLUMN))],
                ]);
            } else {
                $set = ['faltas_maximas' => $faltasMax];
                $colReglas->updateOne(
                    ['materia_id' => $materiaId],
                    ['$set' => $set],
                    ['upsert' => true]
                );
                $conFaltas = $colAsistencia->distinct('alumno_id', ['materia_id' => $materiaId, 'estado' => 'ausente']);
            }
            // Reevaluar a quienes ya tienen faltas (si bajó el tope, pueden quedar libres)
            foreach ($conFaltas as $aid) evaluarLibre($pdo, $mongoDB, (int)$aid, $materiaId, $amb['asigId']);
            jsonResponse(['ok' => true]);
            break;

        // ============================================================
        // GUARDAR TOPE PERSONAL POR ALUMNO (SOLO DIRECTIVO)
        // ============================================================
        case 'guardar_tope_alumno':
            if (!$esDirectivo) {
                jsonResponse(['ok' => false, 'mensaje' => 'Solo dirección puede cambiar topes individuales'], 403);
            }
            $amb       = resolverAmbito($pdo, $yo);
            $materiaId = $amb['materiaId'];
            $alumnoId  = (int)($_POST['alumno_id'] ?? 0);
            $tope      = max(1, (int)($_POST['tope'] ?? 10));
            if (!$alumnoId) jsonResponse(['ok' => false, 'mensaje' => 'Faltan datos'], 400);
            if (!alumnoEnAmbito($pdo, $yo, $amb, $alumnoId)) {
                jsonResponse(['ok' => false, 'mensaje' => 'Ese alumno no pertenece a tu institución'], 403);
            }

            $colTopesAlumno->updateOne(
                ['materia_id' => $materiaId, 'alumno_id' => $alumnoId],
                ['$set' => ['tope' => $tope]],
                ['upsert' => true]
            );
            evaluarLibre($pdo, $mongoDB, $alumnoId, $materiaId, $amb['asigId']);
            jsonResponse(['ok' => true]);
            break;

        // ============================================================
        // PERÍODOS SIN ASISTENCIA (SOLO DIRECTIVO O SECRETARIO DE LA INSTITUCIÓN)
        // ============================================================
        case 'guardar_periodo':
            if (!$puedeBloquear) {
                jsonResponse(['ok' => false, 'mensaje' => 'Solo dirección o secretaría puede definir períodos sin asistencia'], 403);
            }
            $desde  = (string)($_POST['desde'] ?? '');
            $hasta  = (string)($_POST['hasta'] ?? '');
            $motivo = trim((string)($_POST['motivo'] ?? ''));
            if (!AsistenciaReglas::formatoValido($desde) || !AsistenciaReglas::formatoValido($hasta)) {
                jsonResponse(['ok' => false, 'mensaje' => 'Fechas inválidas'], 400);
            }
            if ($hasta < $desde) {
                jsonResponse(['ok' => false, 'mensaje' => 'La fecha "hasta" no puede ser anterior a "desde"'], 400);
            }
            if ((strtotime($hasta) - strtotime($desde)) > 366 * 86400) {
                jsonResponse(['ok' => false, 'mensaje' => 'El período no puede superar un año'], 400);
            }
            if (mb_strlen($motivo) > 150) {
                jsonResponse(['ok' => false, 'mensaje' => 'El motivo es demasiado largo (máximo 150 caracteres)'], 400);
            }
            $mongoDB->selectCollection('periodos_sin_asistencia')->insertOne([
                'institucion_id' => $yo['inst'],
                'desde'  => $desde,
                'hasta'  => $hasta,
                'motivo' => $motivo,
                'creado_por' => $userId,
                'creado_en'  => new MongoDB\BSON\UTCDateTime(),
            ]);
            jsonResponse(['ok' => true]);
            break;

        case 'eliminar_periodo':
            if (!$puedeBloquear) {
                jsonResponse(['ok' => false, 'mensaje' => 'Solo dirección o secretaría puede definir períodos sin asistencia'], 403);
            }
            $pid = (string)($_POST['id'] ?? '');
            if (!preg_match('/^[a-f0-9]{24}$/i', $pid)) jsonResponse(['ok' => false, 'mensaje' => 'ID inválido'], 400);
            // Solo períodos de la propia institución
            $r = $mongoDB->selectCollection('periodos_sin_asistencia')->deleteOne([
                '_id' => new MongoDB\BSON\ObjectId($pid),
                'institucion_id' => $yo['inst'],
            ]);
            if ($r->getDeletedCount() === 0) jsonResponse(['ok' => false, 'mensaje' => 'Período no encontrado'], 404);
            jsonResponse(['ok' => true]);
            break;

        // ============================================================
        // QUITAR LIBRE (SOLO DIRECTIVO)
        // ============================================================
        case 'quitar_libre':
            if (!$esDirectivo) {
                jsonResponse(['ok' => false, 'mensaje' => 'Solo dirección puede quitar el estado LIBRE'], 403);
            }
            $amb       = resolverAmbito($pdo, $yo);
            $materiaId = $amb['materiaId'];
            $alumnoId  = (int)($_POST['alumno_id'] ?? 0);
            if (!$alumnoId) jsonResponse(['ok' => false, 'mensaje' => 'Faltan datos'], 400);
            if (!alumnoEnAmbito($pdo, $yo, $amb, $alumnoId)) {
                jsonResponse(['ok' => false, 'mensaje' => 'Ese alumno no pertenece a tu institución'], 403);
            }

            $colLibres->deleteOne(['alumno_id' => $alumnoId, 'materia_id' => $materiaId]);
            $colAsistencia->updateMany(
                ['alumno_id' => $alumnoId, 'materia_id' => $materiaId, 'estado' => 'libre'],
                ['$set' => ['estado' => 'ausente']]
            );
            jsonResponse(['ok' => true]);
            break;

        default:
            jsonResponse(['ok' => false, 'mensaje' => 'Acción desconocida'], 400);
    }
} catch (Exception $e) {
    error_log("asistencia_ajax ERROR: " . $e->getMessage());
    jsonResponse(['ok' => false, 'mensaje' => 'Error del servidor'], 500);
}