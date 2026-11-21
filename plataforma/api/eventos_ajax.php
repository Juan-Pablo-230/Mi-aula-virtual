<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Helpers.php';

header('Content-Type: application/json; charset=utf-8');
Auth::checkRole(ROLES_DOCENTES);

$action = $_GET['action'] ?? '';
try {
    switch ($action) {
        case 'crear':
            $titulo = trim($_POST['titulo'] ?? '');
            $desc = trim($_POST['descripcion'] ?? '');
            $fecha = $_POST['fecha'] ?? '';
            if ($titulo === '' || $fecha === '') jsonResponse(['ok' => false, 'mensaje' => 'Faltan datos'], 400);
            $dt = new DateTime($fecha, new DateTimeZone('America/Argentina/Buenos_Aires'));
            $mongoDB->selectCollection('eventos_calendario')->insertOne([
                'titulo' => $titulo, 'descripcion' => $desc,
                'fecha' => new MongoDB\BSON\UTCDateTime($dt),
                'creado_por' => (int)$_SESSION['user_id'],
                'creado_en' => new MongoDB\BSON\UTCDateTime(),
            ]);
            jsonResponse(['ok' => true]);
            break;
        case 'eliminar':
            if (!in_array($_SESSION['rol'], ['directivo', 'secretario'])) jsonResponse(['ok' => false, 'mensaje' => 'Sin permiso'], 403);
            $id = $_POST['id'] ?? '';
            if (!$id) jsonResponse(['ok' => false, 'mensaje' => 'ID requerido'], 400);
            $mongoDB->selectCollection('eventos_calendario')->deleteOne(['_id' => new MongoDB\BSON\ObjectId($id)]);
            jsonResponse(['ok' => true]);
            break;
        default:
            jsonResponse(['ok' => false, 'mensaje' => 'Acción desconocida'], 400);
    }
} catch (Exception $e) { jsonResponse(['ok' => false, 'mensaje' => 'Error del servidor'], 500); }