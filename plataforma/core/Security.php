<?php
/**
 * Utilidades de seguridad: cabeceras, CSRF y detección de peticiones tipo API.
 * Se carga desde config/database.php, justo después de iniciar la sesión.
 */

/**
 * ¿La petición llegó por HTTPS? X-Forwarded-Proto solo se respeta si el servidor está detrás
 * de un proxy/balanceador propio y se declaró en el .env con TRUST_PROXY=1; si no, cualquier
 * cliente podría falsearlo.
 */
function esHttps(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    $confiaProxy = ($_ENV['TRUST_PROXY'] ?? getenv('TRUST_PROXY') ?: '0') === '1';
    return $confiaProxy && (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
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


// ============================================================
// LÍMITES DE LARGO EN CAMPOS DE TEXTO
// ============================================================
/**
 * Rechaza (400) cualquier campo POST que supere el largo máximo de su tipo.
 * Evita que alguien guarde textos gigantes. Los campos no listados usan LARGO_POR_DEFECTO.
 * Solo se mira $_POST: los archivos tienen su propio límite de tamaño.
 */
const LARGO_POR_DEFECTO = 1000;
const LARGOS_CAMPOS = [
    'titulo' => 200, 'nombre' => 100, 'apellido' => 100, 'nombre_familiar' => 100,
    'email' => 255, 'dni' => 20, 'dni_familiar' => 20, 'concepto' => 150,
    'cursadas_json' => 12000, 'direccion' => 255, 'pais' => 100, 'localidad' => 150, 'calle' => 150, 'altura' => 6, 'telefono' => 50, 'duenio_dni' => 20,
    'contenido' => 10000, 'descripcion' => 10000,
    'comentario' => 2000, 'comentario_alumno' => 2000,
    'mensaje' => 5000, 'texto' => 5000,
    'password' => 128, 'password2' => 128, 'password_actual' => 128,
    'cambios' => 200000,   // JSON de asistencia: un objeto con todos los alumnos de la materia
];

function limitarCamposPost(): void {
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($metodo !== 'POST' || empty($_POST)) return;

    $revisar = function ($valor, string $campo) use (&$revisar) {
        if (is_array($valor)) {
            foreach ($valor as $v) { $r = $revisar($v, $campo); if ($r !== null) return $r; }
            return null;
        }
        if (!is_string($valor)) return null;
        $max = LARGOS_CAMPOS[$campo] ?? LARGO_POR_DEFECTO;
        return mb_strlen($valor) > $max ? $campo . ' (máximo ' . $max . ' caracteres)' : null;
    };
    foreach ($_POST as $campo => $valor) {
        $r = $revisar($valor, (string)$campo);
        if ($r === null) continue;
        http_response_code(400);
        $mensaje = 'El texto es demasiado largo en el campo ' . $r . '.';
        if (pedidoEsJson()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'mensaje' => $mensaje]);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo $mensaje;
        }
        exit;
    }
}
