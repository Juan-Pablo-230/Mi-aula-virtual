<?php
function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
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

function validarPassword(string $p): array {
    $len = strlen($p);
    if ($len < PASSWORD_MIN) return ['ok' => false, 'msg' => 'Mínimo ' . PASSWORD_MIN . ' caracteres'];
    if ($len > PASSWORD_MAX) return ['ok' => false, 'msg' => 'Máximo ' . PASSWORD_MAX . ' caracteres'];
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
    return 'https://www.pngall.com/wp-content/uploads/5/Profile-PNG-Pic.png';
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