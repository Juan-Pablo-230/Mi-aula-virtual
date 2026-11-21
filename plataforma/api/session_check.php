<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) jsonResponse(['ok' => false, 'mensaje' => 'Sesión expirada'], 401);
jsonResponse(['ok' => true, 'user_id' => $_SESSION['user_id'], 'rol' => $_SESSION['rol']]);