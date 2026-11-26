<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Helpers.php';

header('Content-Type: application/json; charset=utf-8');
Auth::checkRole(['directivo', 'secretario', 'docente']);

$userId = (int)$_SESSION['user_id'];
$contextos = Membresias::contextos($pdo, $userId);

switch ($_GET['action'] ?? '') {
    case 'listar':
        jsonResponse(['ok' => true, 'actual' => (int)$_SESSION['institucion_id'], 'instituciones' => $contextos]);

    case 'cambiar':
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonResponse(['ok' => false, 'mensaje' => 'Método inválido'], 405);
        $destino = (int)($_POST['institucion_id'] ?? 0);
        foreach ($contextos as $c) {
            if ($c['institucion_id'] === $destino) {
                $_SESSION['inst_activa'] = $destino; // checkRole lo valida de nuevo en cada petición
                jsonResponse(['ok' => true, 'nombre' => $c['nombre'], 'rol' => $c['rol']]);
            }
        }
        jsonResponse(['ok' => false, 'mensaje' => 'No trabajás en esa institución'], 403);

    default:
        jsonResponse(['ok' => false, 'mensaje' => 'Acción inválida'], 400);
}
