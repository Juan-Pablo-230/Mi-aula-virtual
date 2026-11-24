<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Helpers.php';
require_once __DIR__ . '/../core/Acceso.php';
require_once __DIR__ . '/../core/FileHandler.php';

use MongoDB\BSON\ObjectId;

header('Content-Type: application/json; charset=utf-8');
Auth::checkRole(ROLES_DOCENTES);
$yo = Acceso::yo();

$action = $_GET['action'] ?? '';

try {
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if (empty($_POST) && $contentLength > 0) {
        jsonResponse([
            'ok' => false,
            'mensaje' => "⚠️ El archivo es demasiado grande para el servidor (límite: " . UPLOAD_MAX_LABEL . ")."
        ], 413);
    }

    switch ($action) {

        // ============================================================
        // CREAR TAREA
        // ============================================================
        case 'crear':
            $materiaId    = (int)($_POST['materia_id'] ?? 0);
            $divisionId   = (int)($_POST['division_id'] ?? 0);
            $titulo       = trim($_POST['titulo'] ?? '');
            $descripcion  = trim($_POST['descripcion'] ?? '');
            $fechaVenc    = $_POST['fecha_vencimiento'] ?? '';
            $tipoEntrega  = $_POST['tipo_entrega'] ?? '';
            $aplicarTodas = !empty($_POST['aplicar_a_todas']);

            if (!$materiaId || $titulo === '' || $fechaVenc === '') {
                jsonResponse(['ok' => false, 'mensaje' => 'Completá todos los campos obligatorios'], 400);
            }
            if (!in_array($tipoEntrega, ['documento', 'multimedia'])) {
                jsonResponse(['ok' => false, 'mensaje' => 'Seleccioná el tipo de entrega'], 400);
            }
            if ($divisionId <= 0 && !$aplicarTodas) {
                jsonResponse(['ok' => false, 'mensaje' => 'Seleccioná una división o marcá "Crear en todas las divisiones".'], 400);
            }

            // Verificar asignación del docente
            if ($_SESSION['rol'] === 'docente') {
                $stmt = $pdo->prepare("
                    SELECT 1 FROM asignaciones_docente
                    WHERE docente_id = ? AND materia_id = ? AND division_id = ?
                ");
                $stmt->execute([$_SESSION['user_id'], $materiaId, $divisionId]);
                if (!$stmt->fetchColumn() && !$aplicarTodas) {
                    jsonResponse(['ok' => false, 'mensaje' => 'No tenés asignada esa materia en esa división'], 403);
                }
            }

            // Determinar divisiones destino (solo IDs)
            $divisionesIds = [];
            if ($aplicarTodas && $_SESSION['rol'] === 'docente') {
                $stmt = $pdo->prepare("
                    SELECT DISTINCT division_id FROM asignaciones_docente
                    WHERE docente_id = ? AND materia_id = ?
                ");
                $stmt->execute([$_SESSION['user_id'], $materiaId]);
                $divisionesIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            } else {
                $divisionesIds = [$divisionId];
            }

            if (empty($divisionesIds)) {
                jsonResponse(['ok' => false, 'mensaje' => 'No hay divisiones disponibles'], 400);
            }

                        // Validar que cada división pertenezca a un curso asociado a la materia
            foreach ($divisionesIds as $divId) {
                $stmt = $pdo->prepare("
                    SELECT 1 FROM divisiones d
                    JOIN materias_asignaciones ma ON ma.asignacion_id = d.asignacion_id
                    WHERE d.id = ? AND ma.materia_id = ?
                ");
                $stmt->execute([$divId, $materiaId]);
                if (!$stmt->fetchColumn()) {
                    jsonResponse(['ok' => false, 'mensaje' => 'La división seleccionada no corresponde a los cursos de esta materia'], 400);
                }
            }

            // Autorización: docentes solo sus materias/divisiones; directivos solo su institución
            if (!Acceso::puedeCrearEn($pdo, $yo, $materiaId, $divisionesIds)) {
                jsonResponse(['ok' => false, 'mensaje' => 'No tenés permiso para crear tareas en esa materia o división'], 403);
            }

            // Título duplicado
            $yaExiste = $mongoDB->selectCollection('tareas')->findOne([
                'titulo'         => $titulo,
                'docente_id'     => (int)$_SESSION['user_id'],
                'materia_id'     => $materiaId,
                'divisiones_ids' => $divisionesIds[0] ?? 0,
            ]);
            if ($yaExiste) {
                jsonResponse(['ok' => false, 'mensaje' => 'Ya existe una tarea con ese título en esta materia y división'], 400);
            }

            try {
                $dt = new DateTime($fechaVenc, new DateTimeZone('America/Argentina/Buenos_Aires'));
                $fechaMongo = new MongoDB\BSON\UTCDateTime($dt);
            } catch (Exception $e) {
                jsonResponse(['ok' => false, 'mensaje' => 'Fecha inválida'], 400);
            }

            // Adjunto (opcional)
            $archivoId     = null;
            $archivoNombre = null;
            $vtEstado      = 'ok';

            if (!empty($_FILES['archivo_tarea']['tmp_name'])) {
                $fh = new FileHandler($mongoArchivos);
                $r = $fh->procesar($_FILES['archivo_tarea'], 'documento');

                if ($r['estado'] === 'malicioso') {
                    jsonResponse(['ok' => false, 'mensaje' => '⚠️ El archivo adjunto contiene malware según VirusTotal.'], 400);
                }
                if ($r['estado'] === 'error') {
                    jsonResponse(['ok' => false, 'mensaje' => 'Adjunto: ' . $r['mensaje']], 400);
                }

                $archivoId = new ObjectId($r['archivo_id']);
                $archivoNombre = $r['nombre'];
                $vtEstado = $r['estado'];
            }

            // Insertar UNA sola tarea con array de divisiones
            $mongoDB->selectCollection('tareas')->insertOne([
                'titulo'            => $titulo,
                'descripcion'       => $descripcion,
                'docente_id'        => (int)$_SESSION['user_id'],
                'materia_id'        => $materiaId,
                'divisiones_ids'    => $divisionesIds,
                'fecha_vencimiento' => $fechaMongo,
                'permite_entrega'   => true,
                'tipo_entrega'      => $tipoEntrega,
                'archivo_id'        => $archivoId,
                'archivo_nombre'    => $archivoNombre,
                'vt_estado'         => $vtEstado,
                'fecha_creacion'    => new MongoDB\BSON\UTCDateTime(),
            ]);

            jsonResponse(['ok' => true]);
            break;

        // ============================================================
        // CALIFICAR ENTREGA
        // ============================================================
        case 'calificar':
            $entregaId = $_POST['entrega_id'] ?? '';
            if (!$entregaId) jsonResponse(['ok' => false, 'mensaje' => 'ID de entrega requerido'], 400);

            try {
                $entrega = $mongoDB->selectCollection('entregas')->findOne([
                    '_id' => new ObjectId($entregaId)
                ]);
            } catch (Exception $e) {
                jsonResponse(['ok' => false, 'mensaje' => 'ID inválido'], 400);
            }
            if (!$entrega) jsonResponse(['ok' => false, 'mensaje' => 'Entrega no encontrada'], 404);

            $tarea = $mongoDB->selectCollection('tareas')->findOne(['_id' => $entrega['tarea_id']]);
            if (!$tarea) jsonResponse(['ok' => false, 'mensaje' => 'Tarea no encontrada'], 404);

            if (!Acceso::puedeAdministrarTarea($pdo, $yo, $tarea)) {
                jsonResponse(['ok' => false, 'mensaje' => 'No tenés permiso para calificar esta entrega'], 403);
            }

            $estado = $_POST['estado'] ?? 'entregada';
            if (!in_array($estado, ['aprobada', 'rechazada'])) {
                jsonResponse(['ok' => false, 'mensaje' => 'Estado inválido'], 400);
            }

            $calificacion = $_POST['calificacion'] ?? '';
            if ($calificacion !== '' && !in_array($calificacion, CALIF_CUALITATIVAS)) {
                jsonResponse(['ok' => false, 'mensaje' => 'Calificación inválida'], 400);
            }

            $nota = $_POST['nota_numerica'] ?? '';
            if ($nota !== '' && (!is_numeric($nota) || $nota < 0 || $nota > 10)) {
                jsonResponse(['ok' => false, 'mensaje' => 'Nota debe ser entre 0 y 10'], 400);
            }

            $vista = $_POST['vista_calificacion'] ?? 'letras';
            if (!in_array($vista, ['letras', 'numerica'], true)) {
                jsonResponse(['ok' => false, 'mensaje' => 'Tipo de calificación a mostrar inválido'], 400);
            }
            if ($estado === 'aprobada') {
                if ($vista === 'letras' && $calificacion === '') {
                    jsonResponse(['ok' => false, 'mensaje' => 'Elegiste mostrar la calificación en letras pero no cargaste ninguna'], 400);
                }
                if ($vista === 'numerica' && $nota === '') {
                    jsonResponse(['ok' => false, 'mensaje' => 'Elegiste mostrar la nota numérica pero no cargaste ninguna'], 400);
                }
            }

            $update = [
                'vista_calificacion' => $vista,
                'estado'           => $estado,
                'calificacion'     => $calificacion ?: null,
                'nota_numerica'    => $nota !== '' ? (float)$nota : null,
                'comentario'       => trim($_POST['comentario'] ?? '') ?: null,
                'fecha_correccion' => new MongoDB\BSON\UTCDateTime(),
                'corregido_por'    => (int)$_SESSION['user_id'],
            ];

            $mongoDB->selectCollection('entregas')->updateOne(
                ['_id' => $entrega['_id']],
                ['$set' => $update]
            );

            $mongoDB->selectCollection('promedios_confirmados')->updateOne(
                ['alumno_id' => (int)$entrega['alumno_id'], 'materia_id' => (int)$tarea['materia_id']],
                ['$set' => ['confirmado' => false, 'fecha_confirmacion' => null]]
            );

            jsonResponse(['ok' => true]);
            break;

        // ============================================================
        // ELIMINAR TAREA
        // ============================================================
        case 'eliminar':
            $tareaId = $_POST['tarea_id'] ?? '';
            if (!$tareaId) jsonResponse(['ok' => false, 'mensaje' => 'ID de tarea requerido'], 400);

            try {
                $tarea = $mongoDB->selectCollection('tareas')->findOne([
                    '_id' => new ObjectId($tareaId)
                ]);
            } catch (Exception $e) {
                jsonResponse(['ok' => false, 'mensaje' => 'ID inválido'], 400);
            }
            if (!$tarea) jsonResponse(['ok' => false, 'mensaje' => 'Tarea no encontrada'], 404);

            if (!Acceso::puedeAdministrarTarea($pdo, $yo, $tarea)) {
                jsonResponse(['ok' => false, 'mensaje' => 'No tenés permiso'], 403);
            }

            $archivoIds = [];
            if (!empty($tarea['archivo_id'])) $archivoIds[] = $tarea['archivo_id'];

            $entregas = $mongoDB->selectCollection('entregas')
                ->find(['tarea_id' => $tarea['_id']], ['projection' => ['archivo_id' => 1]])
                ->toArray();
            foreach ($entregas as $e) {
                if (!empty($e['archivo_id'])) $archivoIds[] = $e['archivo_id'];
            }

            $del = $mongoDB->selectCollection('entregas')->deleteMany(['tarea_id' => $tarea['_id']]);
            $mongoDB->selectCollection('tareas')->deleteOne(['_id' => $tarea['_id']]);

            foreach ($archivoIds as $aid) {
                $usado = $mongoDB->selectCollection('entregas')->countDocuments(['archivo_id' => $aid])
                      + $mongoPublicaciones->countDocuments(['archivo_id' => $aid])
                      + $mongoBibliografia->countDocuments(['archivo_id' => $aid])
                      // El mismo archivo (deduplicado por hash) puede estar en otra tarea o en un mensaje
                      + $mongoDB->selectCollection('tareas')->countDocuments(['archivo_id' => $aid])
                      + $mongoDB->selectCollection('mensajes')->countDocuments(['archivo_id' => $aid]);

                if ($usado === 0) {
                    $archivo = $mongoArchivos->findOne(['_id' => $aid]);
                    if ($archivo && !empty($archivo['nombre_guardado'])) {
                        $ruta = UPLOAD_DIR . $archivo['nombre_guardado'];
                        if (file_exists($ruta)) @unlink($ruta);
                    }
                    $mongoArchivos->deleteOne(['_id' => $aid]);
                }
            }

            jsonResponse([
                'ok' => true,
                'entregas_eliminadas' => $del->getDeletedCount(),
            ]);
            break;

        default:
            jsonResponse(['ok' => false, 'mensaje' => 'Acción desconocida'], 400);
    }
} catch (Throwable $e) {
    error_log('tareas_ajax ERROR: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(['ok' => false, 'mensaje' => 'Error del servidor'], 500);
}