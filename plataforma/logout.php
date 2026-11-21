<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Auth.php';
Auth::logout();
header('Location: /index.html');
exit;