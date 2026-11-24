<?php
require_once __DIR__ . '/Security.php';

class Auth {
    // Límites de intentos fallidos de login (ventana deslizante)
    const LOGIN_VENTANA_SEG   = 900;  // 15 minutos
    const LOGIN_MAX_DNI_IP    = 5;    // mismo DNI desde la misma IP
    const LOGIN_MAX_IP        = 30;   // cualquier DNI desde la misma IP
    // Mismo DNI desde cualquier IP: NO bloquea (si bloqueara, cualquiera podría dejar sin acceso a un
    // usuario ajeno enviando intentos fallidos a propósito); solo deja una alerta en el log.
    const LOGIN_ALERTA_DNI_TOTAL = 20;

    // Páginas/endpoints permitidos mientras el usuario no completó el primer ingreso
    const PRIMER_INGRESO_PERMITIDOS = ['primer_ingreso.php', 'primer_ingreso_ajax.php', 'logout.php', 'session_check.php'];

    public static function hashPassword(string $password): string {
        return password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost'   => 4,
            'threads'     => 2
        ]);
    }

    public static function verifyPassword(string $password, string $hash): bool {
        return password_verify($password, $hash);
    }

    // ------------------------------------------------------------
    // Rate limit de login (colección Mongo `login_intentos`, con TTL)
    // ------------------------------------------------------------
    private static function ipCliente(): string {
        // Se usa REMOTE_ADDR a propósito: X-Forwarded-For lo puede falsificar el cliente.
        // Si hay proxy/CDN delante, configurá el servidor web para que REMOTE_ADDR sea la IP real.
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    private static function intentos($col, array $filtro): int {
        $desde = new MongoDB\BSON\UTCDateTime((time() - self::LOGIN_VENTANA_SEG) * 1000);
        return $col->countDocuments($filtro + ['fecha' => ['$gte' => $desde]]);
    }

    private static function loginBloqueado($col, string $dni, string $ip): bool {
        return self::intentos($col, ['dni' => $dni, 'ip' => $ip]) >= self::LOGIN_MAX_DNI_IP
            || self::intentos($col, ['ip' => $ip])               >= self::LOGIN_MAX_IP;
    }

    public static function login(PDO $pdo, string $dni, string $password, $mongoDB = null): array {
        $col = null;
        $ip  = self::ipCliente();
        $dni = mb_substr($dni, 0, 40);

        if ($mongoDB !== null) {
            $col = $mongoDB->selectCollection('login_intentos');
            try { $col->createIndex(['fecha' => 1], ['expireAfterSeconds' => self::LOGIN_VENTANA_SEG * 4]); }
            catch (Throwable $e) { error_log('login_intentos index: ' . $e->getMessage()); }

            if (self::loginBloqueado($col, $dni, $ip)) {
                http_response_code(429);
                return ['ok' => false, 'mensaje' => 'Demasiados intentos fallidos. Esperá 15 minutos e intentá de nuevo.'];
            }
        }

        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE dni = ? AND activo = 1");
        $stmt->execute([$dni]);
        $user = $stmt->fetch();

        // Si el usuario no existe se verifica contra un hash falso para que el tiempo
        // de respuesta no revele qué DNI están registrados.
        static $hashFalso = null;
        if ($hashFalso === null) $hashFalso = self::hashPassword('dummy-' . bin2hex(random_bytes(8)));
        $hashReal = $user['password_hash'] ?? $hashFalso;
        $passOk   = self::verifyPassword($password, $hashReal);

        if (!$user || !$passOk) {
            if ($col) {
                $col->insertOne(['dni' => $dni, 'ip' => $ip, 'fecha' => new MongoDB\BSON\UTCDateTime()]);
                if (self::intentos($col, ['dni' => $dni]) === self::LOGIN_ALERTA_DNI_TOTAL) {
                    error_log('Alerta de seguridad: ' . self::LOGIN_ALERTA_DNI_TOTAL . ' intentos fallidos al mismo DNI desde distintas IP en 15 min (posible ataque distribuido)');
                }
            }
            return ['ok' => false, 'mensaje' => 'DNI o contraseña incorrectos'];
        }

        if ($col) $col->deleteMany(['dni' => $dni, 'ip' => $ip]);

        // Evita fijación de sesión: nueva ID al autenticarse
        session_regenerate_id(true);
        $_SESSION = [];
        $_SESSION['user_id']        = $user['id'];
        $_SESSION['rol']            = $user['rol'];
        $_SESSION['nombre']         = $user['nombre'] . ' ' . $user['apellido'];
        $_SESSION['institucion_id'] = $user['institucion_id'] ?? null;
        $_SESSION['foto_perfil']    = $user['foto_perfil'];
        csrfToken(); // genera el token CSRF de la nueva sesión
        $_SESSION['pw_firma']         = self::firmaPassword((string)$user['password_hash']);
        $_SESSION['ultima_actividad'] = time();

        if ($user['primer_ingreso']) {
            return ['ok' => true, 'redirect' => '/plataforma/primer_ingreso.php'];
        }
        return ['ok' => true, 'redirect' => '/plataforma/publicaciones.php'];
    }

    /** Huella corta del hash de contraseña: si la contraseña cambia, las sesiones viejas dejan de valer. */
    public static function firmaPassword(string $hash): string {
        return substr(hash('sha256', $hash), 0, 24);
    }

    /**
     * Tras cambiar la contraseña propia: la sesión actual sigue valiendo (con ID nueva) y
     * todas las demás sesiones de esa cuenta quedan invalidadas.
     */
    public static function refrescarSesionTrasCambioPassword(PDO $pdo, int $userId): void {
        $stmt = $pdo->prepare("SELECT password_hash FROM usuarios WHERE id = ?");
        $stmt->execute([$userId]);
        $hash = (string)$stmt->fetchColumn();
        session_regenerate_id(true);
        $_SESSION['pw_firma'] = self::firmaPassword($hash);
    }

    public static function logout(): void {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    private static function sesionInvalida(string $mensaje, int $code): void {
        http_response_code($code);
        if (pedidoEsJson()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'mensaje' => $mensaje]);
            exit;
        }
        header('Location: /index.html?expirada=1');
        exit;
    }

    public static function checkRole(array $rolesPermitidos): void {
        global $pdo;

        if (!isset($_SESSION['user_id'])) {
            self::sesionInvalida('Tu sesión expiró. Iniciá sesión de nuevo.', 401);
        }

        // Revalidar contra la base en cada petición: si desactivaron o cambiaron de rol
        // al usuario, el cambio rige de inmediato y no recién cuando vence la sesión.
        $stmt = $pdo->prepare("SELECT rol, activo, primer_ingreso, institucion_id, password_hash FROM usuarios WHERE id = ?");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $u = $stmt->fetch();
        if (!$u || !(int)$u['activo']) {
            self::logout();
            self::sesionInvalida('Tu sesión expiró. Iniciá sesión de nuevo.', 401);
        }

        // Vencimiento por inactividad
        $ahora = time();
        if (isset($_SESSION['ultima_actividad']) && ($ahora - (int)$_SESSION['ultima_actividad']) > SESION_INACTIVIDAD_SEG) {
            self::logout();
            self::sesionInvalida('Tu sesión expiró por inactividad. Iniciá sesión de nuevo.', 401);
        }
        $_SESSION['ultima_actividad'] = $ahora;

        // Si la contraseña cambió desde otra sesión, esta ya no vale
        $firma = self::firmaPassword((string)$u['password_hash']);
        if (isset($_SESSION['pw_firma']) && !hash_equals($_SESSION['pw_firma'], $firma)) {
            self::logout();
            self::sesionInvalida('Tu contraseña cambió. Iniciá sesión de nuevo.', 401);
        }
        $_SESSION['pw_firma'] = $firma;
        $_SESSION['rol']            = $u['rol'];
        $_SESSION['institucion_id'] = $u['institucion_id'];

        if (!in_array($u['rol'], $rolesPermitidos, true)) {
            self::sesionInvalida('No tenés permiso para esta acción.', 403);
        }

        // Primer ingreso obligatorio: hasta cambiar la contraseña temporal no se puede usar nada más.
        if ((int)$u['primer_ingreso'] && !in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), self::PRIMER_INGRESO_PERMITIDOS, true)) {
            if (pedidoEsJson()) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'mensaje' => 'Primero completá tu primer ingreso y cambiá la contraseña.']);
                exit;
            }
            header('Location: /plataforma/primer_ingreso.php');
            exit;
        }
    }

    public static function currentUser(PDO $pdo): ?array {
        if (!isset($_SESSION['user_id'])) return null;
        $stmt = $pdo->prepare("SELECT id, nombre, apellido, dni, email, rol, institucion_id, foto_perfil FROM usuarios WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetch() ?: null;
    }
}
