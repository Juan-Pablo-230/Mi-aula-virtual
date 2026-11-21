<?php
class Auth {
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

    public static function login(PDO $pdo, string $dni, string $password): array {
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE dni = ? AND activo = 1");
        $stmt->execute([$dni]);
        $user = $stmt->fetch();
        if (!$user || !self::verifyPassword($password, $user['password_hash'])) {
            return ['ok' => false, 'mensaje' => 'DNI o contraseña incorrectos'];
        }
        $_SESSION['user_id']        = $user['id'];
        $_SESSION['rol']            = $user['rol'];
        $_SESSION['nombre']         = $user['nombre'] . ' ' . $user['apellido'];
        $_SESSION['institucion_id'] = $user['institucion_id'] ?? null;
        $_SESSION['foto_perfil']    = $user['foto_perfil'];

        if ($user['primer_ingreso']) {
            return ['ok' => true, 'redirect' => '/plataforma/primer_ingreso.php'];
        }
        return ['ok' => true, 'redirect' => '/plataforma/publicaciones.php'];
    }

    public static function logout(): void {
        $_SESSION = [];
        session_destroy();
    }

    public static function checkRole(array $rolesPermitidos): void {
        if (!isset($_SESSION['user_id']) || !in_array($_SESSION['rol'], $rolesPermitidos)) {
            http_response_code(401);
            $wantsJson = (
                !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
            ) || (
                strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
            ) || (
                strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false
            );

            if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'mensaje' => 'Tu sesión expiró. Iniciá sesión de nuevo.']);
                exit;
            }
            header('Location: /index.html?expirada=1');
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