<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Helpers.php';
require_once __DIR__ . '/../core/Acceso.php';
require_once __DIR__ . '/../core/GradeHelpers.php';

use MongoDB\BSON\ObjectId;

header('Content-Type: application/json; charset=utf-8');
Auth::checkRole(ROLES_DOCENTES);
$yo = Acceso::yo();

$action = $_GET['action'] ?? '';

try {
    switch ($action) {

        // ============================================================
        // AGREGAR NOTA EXTRA
        // ============================================================
        case 'agregar_extra':
            $alumnoId  = (int)($_POST['alumno_id'] ?? 0);
            $materiaId = (int)($_POST['materia_id'] ?? 0);
            $concepto  = trim($_POST['concepto'] ?? '');
            $nota      = $_POST['nota'] ?? '';

            if (!$alumnoId || !$materiaId || $concepto === '' || $nota === '') {
                jsonResponse(['ok' => false, 'mensaje' => 'Faltan datos'], 400);
            }
            if (!is_numeric($nota) || $nota < 0 || $nota > 10) {
                jsonResponse(['ok' => false, 'mensaje' => 'Nota debe ser entre 0 y 10'], 400);
            }

            // Validar que el docente tenga acceso a la materia
            // Docente: solo alumnos de SUS divisiones; directivo: solo su institución
            if (!Acceso::puedeGestionarAlumnoMateria($pdo, $yo, $alumnoId, $materiaId)) {
                jsonResponse(['ok' => false, 'mensaje' => 'No tenés acceso a ese alumno en esa materia'], 403);
            }

            $mongoDB->selectCollection('calificaciones_extra')->insertOne([
                'alumno_id'   => $alumnoId,
                'materia_id'  => $materiaId,
                'concepto'    => $concepto,
                'nota'        => (float)$nota,
                'creada_por'  => (int)$_SESSION['user_id'],
                'fecha'       => new MongoDB\BSON\UTCDateTime(),
            ]);

            // Invalidar promedio confirmado
            invalidarPromedio($mongoDB, $alumnoId, $materiaId);

            jsonResponse(['ok' => true]);
            break;

        // ============================================================
        // ELIMINAR NOTA EXTRA
        // ============================================================
        case 'eliminar_extra':
            $extraId = $_POST['extra_id'] ?? '';
            if (!$extraId) jsonResponse(['ok' => false, 'mensaje' => 'ID requerido'], 400);

            try {
                $extra = $mongoDB->selectCollection('calificaciones_extra')->findOne([
                    '_id' => new ObjectId($extraId)
                ]);
            } catch (Exception $e) {
                jsonResponse(['ok' => false, 'mensaje' => 'ID inválido'], 400);
            }
            if (!$extra) jsonResponse(['ok' => false, 'mensaje' => 'Nota no encontrada'], 404);

            $esDueno = (int)$extra['creada_por'] === (int)$_SESSION['user_id'];
            if (!$esDueno && !Acceso::puedeGestionarAlumnoMateria($pdo, $yo, (int)$extra['alumno_id'], (int)$extra['materia_id'])) {
                jsonResponse(['ok' => false, 'mensaje' => 'Sin permiso'], 403);
            }

            $mongoDB->selectCollection('calificaciones_extra')->deleteOne(['_id' => $extra['_id']]);

            // Invalidar promedio confirmado
            invalidarPromedio($mongoDB, (int)$extra['alumno_id'], (int)$extra['materia_id']);

            jsonResponse(['ok' => true]);
            break;

        // ============================================================
        // CONFIRMAR PROMEDIO
        // ============================================================
        case 'confirmar_promedio':
            $alumnoId  = (int)($_POST['alumno_id'] ?? 0);
            $materiaId = (int)($_POST['materia_id'] ?? 0);
            $formato   = $_POST['formato'] ?? 'numerico'; // 'numerico' o 'cualitativo'

            if (!$alumnoId || !$materiaId) {
                jsonResponse(['ok' => false, 'mensaje' => 'Faltan datos'], 400);
            }
            if (!in_array($formato, ['numerico', 'cualitativo'])) {
                jsonResponse(['ok' => false, 'mensaje' => 'Formato inválido'], 400);
            }

            // Docente: solo alumnos de SUS divisiones; directivo: solo su institución
            if (!Acceso::puedeGestionarAlumnoMateria($pdo, $yo, $alumnoId, $materiaId)) {
                jsonResponse(['ok' => false, 'mensaje' => 'No tenés acceso a ese alumno en esa materia'], 403);
            }

            // Calcular el promedio actual
            $cals = obtenerCalificacionesAlumno($mongoDB, $alumnoId, $materiaId);
            $promedio = calcularPromedio($cals);
            if ($promedio === null) {
                jsonResponse(['ok' => false, 'mensaje' => 'Sin calificaciones para promediar'], 400);
            }

            // Guardar/actualizar
            $mongoDB->selectCollection('promedios_confirmados')->updateOne(
                ['alumno_id' => $alumnoId, 'materia_id' => $materiaId],
                ['$set' => [
                    'promedio'           => $promedio,
                    'formato'            => $formato,
                    'confirmado'         => true,
                    'confirmado_por'     => (int)$_SESSION['user_id'],
                    'fecha_confirmacion' => new MongoDB\BSON\UTCDateTime(),
                ]],
                ['upsert' => true]
            );

            jsonResponse([
                'ok' => true,
                'promedio' => $promedio,
                'formato' => $formato,
                'promedio_letra' => numericoACalificacion($promedio),
            ]);
            break;

        default:
            jsonResponse(['ok' => false, 'mensaje' => 'Acción desconocida'], 400);
    }
} catch (Exception $e) {
    error_log("calificaciones_ajax ERROR: " . $e->getMessage());
    jsonResponse(['ok' => false, 'mensaje' => 'Error del servidor'], 500);
}