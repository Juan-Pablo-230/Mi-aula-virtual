<?php
/**
 * Utilidades de seguridad: cabeceras, CSRF y detección de peticiones tipo API.
 * Se carga desde config/database.php, justo después de iniciar la sesión.
 */

function esHttps(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function aplicarCabecerasSeguridad(): void {
    if (headers_sent()) return;
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    if (esHttps()) {
        header('Strict-Transport-Security: max-age=15552000');
    }
}

function pedidoEsJson(): bool {
    return (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
        || strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;
}

// ============================================================
// CSRF
// ============================================================
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Exige token CSRF en toda petición que modifica estado (POST/PUT/PATCH/DELETE)
 * de un usuario con sesión iniciada. El token viaja en el campo `csrf_token`
 * (formularios) o en la cabecera `X-CSRF-Token` (fetch), que inyecta aulaviva.js.
 *
 * Exentos:
 *  - auth_ajax.php (login: todavía no hay sesión ni token).
 *  - Peticiones sin sesión: las rechaza Auth::checkRole() con 401.
 */
function csrfVerificar(): void {
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($metodo, ['GET', 'HEAD', 'OPTIONS'], true)) return;
    if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'auth_ajax.php') return;
    if (!isset($_SESSION['user_id'])) return;

    $esperado = $_SESSION['csrf_token'] ?? '';
    $recibido = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

    if ($esperado !== '' && is_string($recibido) && hash_equals($esperado, $recibido)) return;

    $mensaje = 'Token de seguridad inválido o vencido. Recargá la página e intentá de nuevo '
             . '(también puede pasar si el archivo supera el límite de tamaño).';
    http_response_code(419);
    if (pedidoEsJson()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'mensaje' => $mensaje]);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo $mensaje;
    }
    exit;
}
