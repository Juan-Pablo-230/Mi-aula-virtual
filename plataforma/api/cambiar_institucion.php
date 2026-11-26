<?php
// Cambia la institución con la que trabaja el personal que pertenece a más de una.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Helpers.php';

header('Content-Type: application/json; charset=utf-8');
Auth::checkRole(['directivo', 'secretario', 'docente']);

$id = (int)($_POST['institucion_id'] ?? 0);
$permitidas = array_map('intval', array_column(Auth::institucionesDelPersonal($pdo, (int)$_SESSION['user_id']), 'id'));
if (!in_array($id, $permitidas, true)) {
    jsonResponse(['ok' => false, 'mensaje' => 'No pertenecés a esa institución'], 403);
}
$_SESSION['inst_activa'] = $id;
$_SESSION['institucion_id'] = $id;
jsonResponse(['ok' => true]);
