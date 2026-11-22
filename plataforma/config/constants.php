<?php
define('APP_NAME', 'Mi Aula Virtual');
define('APP_URL', getenv('APP_URL') ?: 'http://localhost');

function iniBytes(string $valor): int {
    $valor = trim($valor);
    if ($valor === '') return 0;
    $unit = strtolower(substr($valor, -1));
    $num  = (float)$valor;
    return match ($unit) {
        'g' => (int)($num * 1024 * 1024 * 1024),
        'm' => (int)($num * 1024 * 1024),
        'k' => (int)($num * 1024),
        default => (int)$num,
    };
}

$uploadMax = ini_get('upload_max_filesize') ?: '2M';
$postMax   = ini_get('post_max_size') ?: '8M';
$uploadBytes = iniBytes($uploadMax);
$postBytes   = iniBytes($postMax);
$limiteBytes = min($uploadBytes, $postBytes);

define('UPLOAD_MAX_BYTES', $limiteBytes);
define('UPLOAD_MAX_MB', round($limiteBytes / 1024 / 1024, 2));
define('UPLOAD_MAX_LABEL', $uploadMax);
// Recomendado: definir UPLOAD_DIR en el .env con una ruta FUERA del directorio web
// (ej. /var/www/mav_uploads). Si no se define, se usa plataforma/uploads/, que queda
// protegida por FileHandler::prepararDirectorio() (.htaccess) y por guardar los
// archivos sin extensión.
$uploadDirCfg = $_ENV['UPLOAD_DIR'] ?? getenv('UPLOAD_DIR') ?: (__DIR__ . '/../uploads');
define('UPLOAD_DIR', rtrim($uploadDirCfg, '/\\') . '/');

// ============================================================
// ROLES
// ============================================================
define('ROLES_SUPER',    ['madre']);
define('ROLES_ADMIN',    ['directivo', 'secretario', 'madre']);
define('ROLES_DOCENTES', ['directivo', 'secretario', 'docente', 'madre']);
define('ROLES_ALL',      ['directivo', 'secretario', 'docente', 'alumno', 'familiar', 'madre']);

define('ESTADOS_TAREA', ['pendiente', 'entregada', 'aprobada', 'rechazada']);
define('CALIF_CUALITATIVAS', ['M', 'R', 'B-', 'B', 'MB', 'MB+', 'EX']);
define('CALIF_A_NUMERICO', [
    'M' => 2, 'R' => 4, 'B-' => 6, 'B' => 7,
    'MB' => 8, 'MB+' => 9, 'EX' => 10,
]);

define('PASSWORD_MIN', 8);
define('PASSWORD_MAX', 128);
define('PASSWORD_TEMPORAL', 'Temporal123');