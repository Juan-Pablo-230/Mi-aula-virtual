<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Helpers.php';

$action = $_GET['action'] ?? '';
if ($action === 'login') {
    $dni = trim($_POST['dni'] ?? '');
    $pass = $_POST['password'] ?? '';
    if (!$dni || !$pass) jsonResponse(['ok' => false, 'mensaje' => 'Completá los campos']);
    jsonResponse(Auth::login($pdo, $dni, $pass));
}
jsonResponse(['ok' => false, 'mensaje' => 'Acción desconocida'], 400);