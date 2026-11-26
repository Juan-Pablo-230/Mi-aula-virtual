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
            $añoLect   = (int)($_POST['año_lectivo'] ?? 0);

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
                'año_lectivo' => $añoLect > 0 ? $añoLect : (int)date('Y'),
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

            // Hay que seguir teniendo acceso al alumno y la materia (haber creado la nota no alcanza:
            // un docente al que ya le quitaron la asignación no puede tocar sus notas)
            if (!Acceso::puedeGestionarAlumnoMateria($pdo, $yo, (int)$extra['alumno_id'], (int)$extra['materia_id'])) {
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
            $añoLect   = (int)($_POST['año_lectivo'] ?? 0) ?: (int)date('Y');

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
            $cals = obtenerCalificacionesLote($mongoDB, [$alumnoId], $materiaId, $añoLect)[$alumnoId] ?? [];
            $promedio = calcularPromedio($cals);
            if ($promedio === null) {
                jsonResponse(['ok' => false, 'mensaje' => 'Sin calificaciones para promediar'], 400);
            }

            // Guardar/actualizar
            $mongoDB->selectCollection('promedios_confirmados')->updateOne(
                ['alumno_id' => $alumnoId, 'materia_id' => $materiaId],
                ['$set' => [
                    'año_lectivo'        => $añoLect,
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
                'promedio_formato' => formatoNota($promedio),
            ]);
            break;

        // ============================================================
        // CALIFICACIÓN GLOBAL DE UNA MATERIA (p. ej. "Conducta"):
        // se crea una sola vez y se aplica a todos los alumnos con la nota elegida.
        // Después cada nota se puede corregir/borrar de forma individual.
        // ============================================================
        case 'crear_global':
            $materiaId = (int)($_POST['materia_id'] ?? 0);
            $concepto  = trim($_POST['concepto'] ?? '');
            $añoLect   = (int)($_POST['año_lectivo'] ?? 0) ?: (int)date('Y');
            $notasIn   = json_decode((string)($_POST['notas'] ?? ''), true);
            if (!$materiaId || $concepto === '' || mb_strlen($concepto) > 120 || !is_array($notasIn)) {
                jsonResponse(['ok' => false, 'mensaje' => 'Completá el nombre y las notas'], 400);
            }
            $notasPorAlumno = [];
            foreach ($notasIn as $aid => $n) {
                $n = str_replace(',', '.', trim((string)$n));
                if ($n === '') continue;
                if (!is_numeric($n) || (float)$n < 0 || (float)$n > 10) {
                    jsonResponse(['ok' => false, 'mensaje' => 'Las notas deben estar entre 0 y 10'], 400);
                }
                $notasPorAlumno[(int)$aid] = round((float)$n, 2);
            }
            if (!$notasPorAlumno) jsonResponse(['ok' => false, 'mensaje' => 'Cargá la nota de al menos un alumno'], 400);
            $ids = array_keys($notasPorAlumno);
            foreach ($ids as $aid) {
                if (!Acceso::puedeGestionarAlumnoMateria($pdo, $yo, $aid, $materiaId)) {
                    jsonResponse(['ok' => false, 'mensaje' => 'No tenés acceso a uno de los alumnos en esa materia'], 403);
                }
            }
            $globalId = bin2hex(random_bytes(12));
            $docs = [];
            foreach ($ids as $aid) {
                $docs[] = [
                    'alumno_id' => $aid, 'materia_id' => $materiaId, 'concepto' => $concepto,
                    'nota' => $notasPorAlumno[$aid], 'año_lectivo' => $añoLect, 'global_id' => $globalId,
                    'creada_por' => (int)$_SESSION['user_id'], 'fecha' => new MongoDB\BSON\UTCDateTime(),
                ];
            }
            $mongoDB->selectCollection('calificaciones_extra')->insertMany($docs);
            foreach ($ids as $aid) invalidarPromedio($mongoDB, $aid, $materiaId);
            jsonResponse(['ok' => true, 'cargadas' => count($docs)]);
            break;

        case 'eliminar_global':
            $globalId = (string)($_POST['global_id'] ?? '');
            if (!preg_match('/^[a-f0-9]{24}$/', $globalId)) jsonResponse(['ok' => false, 'mensaje' => 'ID inválido'], 400);
            $col = $mongoDB->selectCollection('calificaciones_extra');
            $filas = $col->find(['global_id' => $globalId])->toArray();
            foreach ($filas as $f) {
                if (!Acceso::puedeGestionarAlumnoMateria($pdo, $yo, (int)$f['alumno_id'], (int)$f['materia_id'])) {
                    jsonResponse(['ok' => false, 'mensaje' => 'Sin permiso'], 403);
                }
            }
            $col->deleteMany(['global_id' => $globalId]);
            foreach ($filas as $f) invalidarPromedio($mongoDB, (int)$f['alumno_id'], (int)$f['materia_id']);
            jsonResponse(['ok' => true, 'eliminadas' => count($filas)]);
            break;

        default:
            jsonResponse(['ok' => false, 'mensaje' => 'Acción desconocida'], 400);
    }
} catch (Exception $e) {
    error_log("calificaciones_ajax ERROR: " . $e->getMessage());
    jsonResponse(['ok' => false, 'mensaje' => 'Error del servidor'], 500);
}