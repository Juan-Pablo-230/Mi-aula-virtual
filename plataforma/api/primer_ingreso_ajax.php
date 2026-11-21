<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Helpers.php';
require_once __DIR__ . '/../models/UserModel.php';

header('Content-Type: application/json; charset=utf-8');
Auth::checkRole(ROLES_ALL);

$userId = (int)$_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();
if (!$user) jsonResponse(['ok' => false, 'mensaje' => 'Usuario no encontrado'], 404);

if (!$user['primer_ingreso']) {
    jsonResponse(['ok' => false, 'mensaje' => 'Ya completaste el primer ingreso'], 400);
}

// ============================================================
// Detectar si es dueño nuevo (puede editar nombre/apellido)
// ============================================================
$esDuenioNuevo = (
    ($user['nombre'] === 'Dueño' && strpos((string)$user['apellido'], 'Institución ') === 0)
    || $user['email'] === null
);

$nombre    = trim($_POST['nombre'] ?? '');
$apellido  = trim($_POST['apellido'] ?? '');
$email     = trim($_POST['email'] ?? '');
$password  = $_POST['password'] ?? '';
$password2 = $_POST['password2'] ?? '';

// Si NO es dueño nuevo, ignorar lo que venga en nombre/apellido
if (!$esDuenioNuevo) {
    $nombre   = $user['nombre'];
    $apellido = $user['apellido'];
}

// Validaciones
if ($nombre === '' || $apellido === '') {
    jsonResponse(['ok' => false, 'mensaje' => 'Nombre y apellido son obligatorios']);
}

if ($email === '') {
    jsonResponse(['ok' => false, 'mensaje' => 'El correo electrónico es obligatorio']);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['ok' => false, 'mensaje' => 'Correo electrónico inválido']);
}
$stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ? AND id != ?");
$stmt->execute([$email, $userId]);
if ($stmt->fetchColumn()) {
    jsonResponse(['ok' => false, 'mensaje' => 'Ese correo ya está en uso']);
}

if ($password !== $password2) {
    jsonResponse(['ok' => false, 'mensaje' => 'Las contraseñas no coinciden']);
}
$val = validarPassword($password);
if (!$val['ok']) {
    jsonResponse(['ok' => false, 'mensaje' => $val['msg']]);
}

try {
    UserModel::completarPrimerIngreso($pdo, $userId, [
        'nombre'   => $nombre,
        'apellido' => $apellido,
        'email'    => $email,
        'password' => $password,
    ]);

    $_SESSION['nombre'] = $nombre . ' ' . $apellido;

    jsonResponse(['ok' => true]);
} catch (Exception $e) {
    error_log('primer_ingreso_ajax ERROR: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'mensaje' => 'Error del servidor'], 500);
}