<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Helpers.php';
require_once __DIR__ . '/../core/Acceso.php';

header('Content-Type: application/json; charset=utf-8');
Auth::checkRole(ROLES_DOCENTES);
$yo = Acceso::yo();

$action = $_GET['action'] ?? '';
try {
    switch ($action) {
        case 'crear':
            $titulo = trim($_POST['titulo'] ?? '');
            $desc = trim($_POST['descripcion'] ?? '');
            $fecha = $_POST['fecha'] ?? '';
            if ($titulo === '' || $fecha === '') jsonResponse(['ok' => false, 'mensaje' => 'Faltan datos'], 400);
            $dt = new DateTime($fecha, new DateTimeZone('America/Argentina/Buenos_Aires'));

            // Cada evento pertenece a una institución. Solo la cuenta "madre" puede crear
            // eventos globales (institucion_id = null) o para otra institución.
            if (Acceso::esMadre($yo)) {
                $instEvento = (int)($_POST['institucion_id'] ?? 0);
                $instEvento = $instEvento > 0 ? $instEvento : null;
            } else {
                if ($yo['inst'] <= 0) jsonResponse(['ok' => false, 'mensaje' => 'Tu usuario no tiene institución asignada'], 403);
                $instEvento = $yo['inst'];
            }

            $mongoDB->selectCollection('eventos_calendario')->insertOne([
                'institucion_id' => $instEvento,
                'titulo' => $titulo, 'descripcion' => $desc,
                'fecha' => new MongoDB\BSON\UTCDateTime($dt),
                'creado_por' => (int)$_SESSION['user_id'],
                'creado_en' => new MongoDB\BSON\UTCDateTime(),
            ]);
            jsonResponse(['ok' => true]);
            break;
        case 'eliminar':
            if (!in_array($_SESSION['rol'], ['directivo', 'secretario', 'madre'])) jsonResponse(['ok' => false, 'mensaje' => 'Sin permiso'], 403);
            $id = $_POST['id'] ?? '';
            if (!$id || !is_string($id) || !preg_match('/^[a-f0-9]{24}$/i', $id)) jsonResponse(['ok' => false, 'mensaje' => 'ID inválido'], 400);
            $col = $mongoDB->selectCollection('eventos_calendario');
            $evento = $col->findOne(['_id' => new MongoDB\BSON\ObjectId($id)]);
            if (!$evento) jsonResponse(['ok' => false, 'mensaje' => 'Evento no encontrado'], 404);
            // Un directivo solo borra eventos de SU institución (los globales/antiguos solo la madre)
            if (!Acceso::esMadre($yo) && (int)($evento['institucion_id'] ?? 0) !== $yo['inst']) {
                jsonResponse(['ok' => false, 'mensaje' => 'Sin permiso'], 403);
            }
            $col->deleteOne(['_id' => $evento['_id']]);
            jsonResponse(['ok' => true]);
            break;
        default:
            jsonResponse(['ok' => false, 'mensaje' => 'Acción desconocida'], 400);
    }
} catch (Throwable $e) { error_log('eventos_ajax ERROR: ' . $e->getMessage()); jsonResponse(['ok' => false, 'mensaje' => 'Error del servidor'], 500); }