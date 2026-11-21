<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Helpers.php';

header('Content-Type: application/json; charset=utf-8');
Auth::checkRole(ROLES_ALL);

jsonResponse([
    'ok' => true,
    'upload_max_bytes' => UPLOAD_MAX_BYTES,
    'upload_max_mb' => UPLOAD_MAX_MB,
    'upload_max_label' => UPLOAD_MAX_LABEL,
]);