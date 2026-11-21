<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Helpers.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

header('Content-Type: application/json; charset=utf-8');
Auth::checkRole(ROLES_ALL);

$action = $_GET['action'] ?? '';
$userId = (int)$_SESSION['user_id'];

const VENTANA_EDICION_MINUTOS = 24 * 60; // 24 horas

try {
    switch ($action) {

        // ============================================================
        // EDITAR MENSAJE (solo dueño, dentro de 24hs)
        // El adjunto NO se toca: se edita únicamente el texto.
        // ============================================================
        case 'editar':
            $msgId = $_POST['msg_id'] ?? '';
            $nuevoTexto = trim($_POST['texto'] ?? '');
            if (!$msgId) jsonResponse(['ok' => false, 'mensaje' => 'ID requerido'], 400);
            if ($nuevoTexto === '') jsonResponse(['ok' => false, 'mensaje' => 'El mensaje no puede estar vacío'], 400);

            try {
                $msg = $mongoDB->selectCollection('mensajes')->findOne(['_id' => new ObjectId($msgId)]);
            } catch (Exception $e) {
                jsonResponse(['ok' => false, 'mensaje' => 'ID inválido'], 400);
            }
            if (!$msg) jsonResponse(['ok' => false, 'mensaje' => 'Mensaje no encontrado'], 404);
            if ((int)$msg['de'] !== $userId) jsonResponse(['ok' => false, 'mensaje' => 'Sin permiso'], 403);

            $fechaMsg = $msg['fecha']->toDateTime();
            $ahora = new DateTime('now');
            $diffMin = ($ahora->getTimestamp() - $fechaMsg->getTimestamp()) / 60;
            if ($diffMin > VENTANA_EDICION_MINUTOS) {
                jsonResponse(['ok' => false, 'mensaje' => 'Ya pasaron más de 24hs. No se puede editar.'], 403);
            }

            $mongoDB->selectCollection('mensajes')->updateOne(
                ['_id' => $msg['_id']],
                ['$set' => [
                    'texto' => $nuevoTexto,
                    'editado' => true,
                    'fecha_edicion' => new UTCDateTime(),
                ]]
            );
            jsonResponse(['ok' => true]);
            break;

        // ============================================================
        // ELIMINAR PARA MÍ (cualquier participante del chat puede hacerlo)
        // No borra el documento; agrega el userId a 'eliminado_para'.
        // ============================================================
        case 'eliminar_para_mi':
            $msgId = $_POST['msg_id'] ?? '';
            if (!$msgId) jsonResponse(['ok' => false, 'mensaje' => 'ID requerido'], 400);

            try {
                $msg = $mongoDB->selectCollection('mensajes')->findOne(['_id' => new ObjectId($msgId)]);
            } catch (Exception $e) {
                jsonResponse(['ok' => false, 'mensaje' => 'ID inválido'], 400);
            }
            if (!$msg) jsonResponse(['ok' => false, 'mensaje' => 'Mensaje no encontrado'], 404);

            // Solo el emisor o el receptor pueden "eliminar para mí"
            $esEmisor   = (int)$msg['de'] === $userId;
            $esReceptor = (int)$msg['para'] === $userId;
            if (!$esEmisor && !$esReceptor) {
                jsonResponse(['ok' => false, 'mensaje' => 'Sin permiso'], 403);
            }

            $mongoDB->selectCollection('mensajes')->updateOne(
                ['_id' => $msg['_id']],
                ['$addToSet' => ['eliminado_para' => $userId]]
            );
            jsonResponse(['ok' => true]);
            break;

        // ============================================================
        // ELIMINAR PARA TODOS (solo dueño, dentro de 24hs)
        // ============================================================
        case 'eliminar_para_todos':
            $msgId = $_POST['msg_id'] ?? '';
            if (!$msgId) jsonResponse(['ok' => false, 'mensaje' => 'ID requerido'], 400);

            try {
                $msg = $mongoDB->selectCollection('mensajes')->findOne(['_id' => new ObjectId($msgId)]);
            } catch (Exception $e) {
                jsonResponse(['ok' => false, 'mensaje' => 'ID inválido'], 400);
            }
            if (!$msg) jsonResponse(['ok' => false, 'mensaje' => 'Mensaje no encontrado'], 404);
            if ((int)$msg['de'] !== $userId) jsonResponse(['ok' => false, 'mensaje' => 'Sin permiso'], 403);

            $fechaMsg = $msg['fecha']->toDateTime();
            $ahora = new DateTime('now');
            $diffMin = ($ahora->getTimestamp() - $fechaMsg->getTimestamp()) / 60;
            if ($diffMin > VENTANA_EDICION_MINUTOS) {
                jsonResponse(['ok' => false, 'mensaje' => 'Ya pasaron más de 24hs. No se puede eliminar para todos.'], 403);
            }

            $mongoDB->selectCollection('mensajes')->deleteOne(['_id' => $msg['_id']]);
            jsonResponse(['ok' => true]);
            break;

        default:
            jsonResponse(['ok' => false, 'mensaje' => 'Acción desconocida'], 400);
    }
} catch (Exception $e) {
    error_log('mensajes_ajax ERROR: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'mensaje' => 'Error del servidor'], 500);
}