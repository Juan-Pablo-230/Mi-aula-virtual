<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Auth.php';

// Solo por POST (con token CSRF, que valida config/database.php): si fuera por GET, cualquier
// página externa podría cerrarle la sesión a un usuario con un simple enlace o imagen.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: ' . (isset($_SESSION['user_id']) ? '/plataforma/publicaciones.php' : '/index.html'));
    exit;
}
Auth::logout();
header('Location: /index.html');
exit;
