<?php
function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * json_encode seguro para incrustar en <script> o dentro de atributos HTML (onclick='...').
 * Escapa < > & ' " como \uXXXX, así un dato con "</script>" o comillas no puede salirse del contexto.
 */
function jsonJs($v, int $flags = 0): string {
    return json_encode($v, $flags | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

/**
 * Excepción cuyo mensaje SÍ es apto para mostrar al usuario final.
 * Cualquier otra excepción se registra en el log y se responde con un mensaje genérico.
 */
class MensajeUsuarioException extends Exception {}

function respuestaErrorInterno(Throwable $e, string $contexto): void {
    if ($e instanceof MensajeUsuarioException) {
        jsonResponse(['ok' => false, 'mensaje' => $e->getMessage()], 400);
    }
    error_log($contexto . ' ERROR: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'mensaje' => 'Ocurrió un error en el servidor. Intentá de nuevo.'], 500);
}

function validarPassword(string $p, ?string $dni = null): array {
    $len = mb_strlen($p);
    if ($len < PASSWORD_MIN) return ['ok' => false, 'msg' => 'Mínimo ' . PASSWORD_MIN . ' caracteres'];
    if ($len > PASSWORD_MAX) return ['ok' => false, 'msg' => 'Máximo ' . PASSWORD_MAX . ' caracteres'];
    if (!preg_match('/\p{L}/u', $p) || !preg_match('/\d/', $p)) {
        return ['ok' => false, 'msg' => 'Debe incluir al menos una letra y un número'];
    }
    if (strcasecmp($p, PASSWORD_TEMPORAL) === 0) {
        return ['ok' => false, 'msg' => 'No podés usar la contraseña temporal; elegí una nueva'];
    }
    if ($dni !== null && $dni !== '' && strcasecmp($p, $dni) === 0) {
        return ['ok' => false, 'msg' => 'La contraseña no puede ser igual a tu DNI'];
    }
    return ['ok' => true];
}

function esMadre(): bool {
    return ($_SESSION['rol'] ?? '') === 'madre';
}

function esAdmin(): bool {
    return in_array($_SESSION['rol'] ?? '', ROLES_ADMIN);
}

function esDocenteOAdmin(): bool {
    return in_array($_SESSION['rol'] ?? '', ROLES_DOCENTES);
}

function fotoPerfilUrl(?string $foto): string {
    if (!empty($foto)) return $foto;
    return '/assets/img/anonimo.png';
}

function añoLectivoActual(PDO $pdo, int $institucionId): int {
    $stmt = $pdo->prepare("SELECT año FROM ciclos_lectivos WHERE institucion_id = ? AND activo = 1 LIMIT 1");
    $stmt->execute([$institucionId]);
    $año = $stmt->fetchColumn();
    return $año ? (int)$año : (int)date('Y');
}

function cicloActual(PDO $pdo, int $institucionId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM ciclos_lectivos WHERE institucion_id = ? AND activo = 1 LIMIT 1");
    $stmt->execute([$institucionId]);
    $ciclo = $stmt->fetch();
    if ($ciclo) return $ciclo;

    $stmt = $pdo->prepare("SELECT * FROM ciclos_lectivos WHERE institucion_id = ? AND año = ? LIMIT 1");
    $stmt->execute([$institucionId, (int)date('Y')]);
    return $stmt->fetch() ?: null;
}