<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Helpers.php';
require_once __DIR__ . '/../core/Feriados.php';

header('Content-Type: application/json; charset=utf-8');
Auth::checkRole(['docente', 'directivo', 'secretario', 'madre']);

$action = $_GET['action'] ?? '';
$userId = (int)$_SESSION['user_id'];
$rol    = $_SESSION['rol'];
$esDirectivo = in_array($rol, ['directivo', 'secretario', 'madre']);

$colAsistencia  = $mongoDB->selectCollection('asistencia');
$colReglas      = $mongoDB->selectCollection('reglas_asistencia');
$colLibres      = $mongoDB->selectCollection('alumno_libre');
$colTopesAlumno = $mongoDB->selectCollection('topes_alumno');

// ============================================================
// HELPER: calcular faltas y evaluar libre
// ============================================================
function evaluarLibre($mongoDB, int $alumnoId, int $materiaId): array {
    $colAsistencia  = $mongoDB->selectCollection('asistencia');
    $colLibres      = $mongoDB->selectCollection('alumno_libre');
    $colReglas      = $mongoDB->selectCollection('reglas_asistencia');
    $colTopesAlumno = $mongoDB->selectCollection('topes_alumno');

    $tope = 10;
    $regla = $colReglas->findOne(['materia_id' => $materiaId]);
    if ($regla && isset($regla['faltas_maximas'])) {
        $tope = (int)$regla['faltas_maximas'];
    }
    $topeAlumno = $colTopesAlumno->findOne(['materia_id' => $materiaId, 'alumno_id' => $alumnoId]);
    $topePersonal = false;
    if ($topeAlumno && isset($topeAlumno['tope'])) {
        $tope = (int)$topeAlumno['tope'];
        $topePersonal = true;
    }

    $faltas = $colAsistencia->countDocuments([
        'alumno_id' => $alumnoId,
        'materia_id' => $materiaId,
        'estado' => 'ausente',
    ]);

    $yaLibre = $colLibres->findOne(['alumno_id' => $alumnoId, 'materia_id' => $materiaId]);

    if ($faltas >= $tope && !$yaLibre) {
        $colLibres->insertOne([
            'alumno_id' => $alumnoId,
            'materia_id' => $materiaId,
            'motivo' => "Superó el tope de {$tope} faltas",
            'fecha' => new MongoDB\BSON\UTCDateTime(),
            'registrado_por' => (int)$_SESSION['user_id'],
        ]);
        return ['libre' => true, 'faltas' => $faltas, 'tope' => $tope, 'tope_personal' => $topePersonal];
    }

    return ['libre' => $yaLibre !== null, 'faltas' => $faltas, 'tope' => $tope, 'tope_personal' => $topePersonal];
}

try {
    switch ($action) {

    case 'guardar_lote':
    $materiaId = (int)($_POST['materia_id'] ?? 0);
    $fecha     = $_POST['fecha'] ?? date('Y-m-d');
    $cambios   = json_decode($_POST['cambios'] ?? '{}', true);

    if (!$materiaId || !is_array($cambios) || empty($cambios)) {
        jsonResponse(['ok' => false, 'mensaje' => 'No hay cambios para guardar'], 400);
    }

    // Validar domingo (se permite sábado)
    $diaSemana = (int)date('w', strtotime($fecha)); // 0=Domingo
    if ($diaSemana === 0) {
        jsonResponse(['ok' => false, 'mensaje' => 'No se puede tomar asistencia los domingos.'], 400);
    }

    // Validar feriado
    $añoFecha = (int)date('Y', strtotime($fecha));
    $feriados = obtenerFeriados($añoFecha);
    if (in_array($fecha, $feriados)) {
        jsonResponse(['ok' => false, 'mensaje' => 'No se puede tomar asistencia en un feriado nacional.'], 400);
    }

    $fechaInicio = new MongoDB\BSON\UTCDateTime(strtotime($fecha . ' 00:00:00') * 1000);
    $libres = [];

    foreach ($cambios as $alumnoId => $estado) {
        $alumnoId = (int)$alumnoId;
        if (!in_array($estado, ['presente', 'ausente'])) continue;

        // Obtener división
        $stmt = $pdo->prepare("SELECT division_id FROM alumnos_division WHERE alumno_id = ?");
        $stmt->execute([$alumnoId]);
        $divisionId = (int)$stmt->fetchColumn();
        if (!$divisionId) continue;

        // Upsert
        $colAsistencia->updateOne(
            ['alumno_id' => $alumnoId, 'materia_id' => $materiaId, 'fecha' => $fechaInicio],
            ['$set' => [
                'division_id' => $divisionId,
                'estado' => $estado,
                'fecha_actualizacion' => new MongoDB\BSON\UTCDateTime(),
            ]],
            ['upsert' => true]
        );

        // Evaluar libre
        $resultado = evaluarLibre($mongoDB, $alumnoId, $materiaId);
        if ($resultado['libre'] && $estado === 'ausente') {
            $stmt = $pdo->prepare("SELECT nombre, apellido FROM usuarios WHERE id = ?");
            $stmt->execute([$alumnoId]);
            $u = $stmt->fetch();
            if ($u) $libres[] = $u['apellido'] . ', ' . $u['nombre'];
        }
    }

    jsonResponse(['ok' => true, 'libres' => $libres]);
    break;

        // ============================================================
        // GUARDAR UN SOLO ALUMNO (tap en la card)
        // ============================================================
        case 'guardar_uno':
            $materiaId = (int)($_POST['materia_id'] ?? 0);
            $alumnoId  = (int)($_POST['alumno_id'] ?? 0);
            $fecha     = $_POST['fecha'] ?? date('Y-m-d');
            $estado    = $_POST['estado'] ?? 'presente';

            if (!$materiaId || !$alumnoId || !in_array($estado, ['presente', 'ausente'])) {
                jsonResponse(['ok' => false, 'mensaje' => 'Datos inválidos'], 400);
            }

            // Validar domingo (se permite sábado)
            $diaSemana = (int)date('w', strtotime($fecha)); // 0=Domingo
            if ($diaSemana === 0) {
                jsonResponse(['ok' => false, 'mensaje' => 'No se puede tomar asistencia los domingos.'], 400);
            }

            // Validar feriado (dinámico)
            $añoFecha = (int)date('Y', strtotime($fecha));
            $feriados = obtenerFeriados($añoFecha);
            if (in_array($fecha, $feriados)) {
                jsonResponse(['ok' => false, 'mensaje' => 'No se puede tomar asistencia en un feriado nacional.'], 400);
            }

            $stmt = $pdo->prepare("SELECT division_id FROM alumnos_division WHERE alumno_id = ?");
            $stmt->execute([$alumnoId]);
            $divisionId = (int)$stmt->fetchColumn();
            if (!$divisionId) jsonResponse(['ok' => false, 'mensaje' => 'Alumno sin división'], 400);

            $fechaInicio = new MongoDB\BSON\UTCDateTime(strtotime($fecha . ' 00:00:00') * 1000);
            $colAsistencia->updateOne(
                ['alumno_id' => $alumnoId, 'materia_id' => $materiaId, 'fecha' => $fechaInicio],
                ['$set' => [
                    'division_id' => $divisionId,
                    'estado' => $estado,
                    'fecha_actualizacion' => new MongoDB\BSON\UTCDateTime(),
                ]],
                ['upsert' => true]
            );

            $resultado = evaluarLibre($mongoDB, $alumnoId, $materiaId);
            jsonResponse([
                'ok' => true,
                'libre' => $resultado['libre'],
                'faltas' => $resultado['faltas'],
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
            $materiaId = (int)($_POST['materia_id'] ?? 0);
            $faltasMax = max(1, (int)($_POST['faltas_maximas'] ?? 10));
            if (!$materiaId) jsonResponse(['ok' => false, 'mensaje' => 'Materia requerida'], 400);

            $colReglas->updateOne(
                ['materia_id' => $materiaId],
                ['$set' => ['faltas_maximas' => $faltasMax]],
                ['upsert' => true]
            );
            jsonResponse(['ok' => true]);
            break;

        // ============================================================
        // GUARDAR TOPE PERSONAL POR ALUMNO (SOLO DIRECTIVO)
        // ============================================================
        case 'guardar_tope_alumno':
            if (!$esDirectivo) {
                jsonResponse(['ok' => false, 'mensaje' => 'Solo dirección puede cambiar topes individuales'], 403);
            }
            $materiaId = (int)($_POST['materia_id'] ?? 0);
            $alumnoId  = (int)($_POST['alumno_id'] ?? 0);
            $tope      = max(1, (int)($_POST['tope'] ?? 10));
            if (!$materiaId || !$alumnoId) jsonResponse(['ok' => false, 'mensaje' => 'Faltan datos'], 400);

            $colTopesAlumno->updateOne(
                ['materia_id' => $materiaId, 'alumno_id' => $alumnoId],
                ['$set' => ['tope' => $tope]],
                ['upsert' => true]
            );
            jsonResponse(['ok' => true]);
            break;

        // ============================================================
        // QUITAR LIBRE (SOLO DIRECTIVO)
        // ============================================================
        case 'quitar_libre':
            if (!$esDirectivo) {
                jsonResponse(['ok' => false, 'mensaje' => 'Solo dirección puede quitar el estado LIBRE'], 403);
            }
            $alumnoId  = (int)($_POST['alumno_id'] ?? 0);
            $materiaId = (int)($_POST['materia_id'] ?? 0);
            if (!$alumnoId || !$materiaId) jsonResponse(['ok' => false, 'mensaje' => 'Faltan datos'], 400);

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