<?php
require_once __DIR__ . '/../../vendor/autoload.php';

use MongoDB\Client;

if (file_exists(__DIR__ . '/../../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../..');
    $dotenv->load();
}

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/../core/Security.php';

try {
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $_ENV['DB_HOST'] ?? '127.0.0.1',
        $_ENV['DB_PORT'] ?? '3306',
        $_ENV['DB_NAME'] ?? 'miaulavirtual'
    );
    $pdo = new PDO($dsn, $_ENV['DB_USER'] ?? 'root', $_ENV['DB_PASS'] ?? '', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    error_log('Conexión MariaDB: ' . $e->getMessage());
    http_response_code(500);
    die('Servicio no disponible. Intentá más tarde.');
}

try {
    $mongoClient = new Client($_ENV['MONGO_URI']);
    $mongoDB = $mongoClient->selectDatabase($_ENV['MONGO_DB'] ?? 'miaulavirtual');
    $mongoArchivos      = $mongoDB->selectCollection('archivos');
    $mongoPublicaciones = $mongoDB->selectCollection('publicaciones');
    $mongoBibliografia  = $mongoDB->selectCollection('bibliografia');
} catch (Exception $e) {
    error_log('Conexión MongoDB: ' . $e->getMessage());
    http_response_code(500);
    die('Servicio no disponible. Intentá más tarde.');
}

// Nunca mostrar errores internos al usuario: se registran en el log y se responde algo genérico.
// También atrapa errores de tipo (TypeError) que no cubren los catch (Exception) de cada endpoint,
// por ejemplo cuando llega "campo[]=x" donde se esperaba un texto.
ini_set('display_errors', '0');
set_exception_handler(function (Throwable $e) {
    error_log('Excepción no controlada: ' . get_class($e) . ': ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) http_response_code(500);
    if (function_exists('pedidoEsJson') && pedidoEsJson()) {
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'mensaje' => 'Error del servidor']);
    } else {
        if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
        echo 'Ocurrió un error en el servidor. Intentá de nuevo.';
    }
});

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', (string)SESION_INACTIVIDAD_SEG);
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_path', '/');
    ini_set('session.cookie_secure', esHttps() ? '1' : '0');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

aplicarCabecerasSeguridad();
csrfVerificar();
limitarCamposPost();

date_default_timezone_set('America/Argentina/Buenos_Aires');