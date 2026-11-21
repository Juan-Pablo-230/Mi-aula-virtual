<?php
class UserModel {
    public static function findById(PDO $pdo, int $id): ?array {
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function findByDni(PDO $pdo, string $dni): ?array {
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE dni = ?");
        $stmt->execute([$dni]);
        return $stmt->fetch() ?: null;
    }

    public static function create(PDO $pdo, array $d): int {
        $stmt = $pdo->prepare("
            INSERT INTO usuarios (nombre, apellido, dni, email, password_hash, rol, primer_ingreso)
            VALUES (?, ?, ?, ?, ?, ?, 1)
        ");
        $stmt->execute([
            $d['nombre'], $d['apellido'], $d['dni'],
            $d['email'] ?? null,
            Auth::hashPassword($d['password']), $d['rol']
        ]);
        return (int)$pdo->lastInsertId();
    }

    public static function update(PDO $pdo, int $id, array $d): void {
        $sql = "UPDATE usuarios SET nombre = ?, apellido = ?, dni = ?, email = ?, rol = ?";
        $params = [$d['nombre'], $d['apellido'], $d['dni'], $d['email'] ?? null, $d['rol']];
        if (isset($d['activo'])) { $sql .= ", activo = ?"; $params[] = (int)$d['activo']; }
        if (!empty($d['password'])) { $sql .= ", password_hash = ?"; $params[] = Auth::hashPassword($d['password']); }
        if (!empty($d['foto'])) { $sql .= ", foto_perfil = ?"; $params[] = $d['foto']; }
        $sql .= " WHERE id = ?";
        $params[] = $id;
        $pdo->prepare($sql)->execute($params);
    }

    public static function updatePerfil(PDO $pdo, int $id, ?string $email, ?string $password, ?string $foto): void {
        $sql = "UPDATE usuarios SET email = ?";
        $params = [$email];
        if ($password) { $sql .= ", password_hash = ?"; $params[] = Auth::hashPassword($password); }
        if ($foto) { $sql .= ", foto_perfil = ?"; $params[] = $foto; }
        $sql .= " WHERE id = ?";
        $params[] = $id;
        $pdo->prepare($sql)->execute($params);
    }

    public static function updatePerfilCompleto(
        PDO $pdo,
        int $id,
        ?string $nombre,
        ?string $apellido,
        ?string $dni,
        ?string $email,
        ?string $password,
        ?string $foto
    ): int {
        $sets = [];
        $params = [];

        if ($nombre !== null && $nombre !== '') { $sets[] = "nombre = ?"; $params[] = $nombre; }
        if ($apellido !== null && $apellido !== '') { $sets[] = "apellido = ?"; $params[] = $apellido; }
        if ($dni !== null && $dni !== '') { $sets[] = "dni = ?"; $params[] = $dni; }
        if ($email !== null && $email !== '') { $sets[] = "email = ?"; $params[] = $email; }
        if ($password) { $sets[] = "password_hash = ?"; $params[] = Auth::hashPassword($password); }
        if ($foto) { $sets[] = "foto_perfil = ?"; $params[] = $foto; }

        if (empty($sets)) return 0;

        $params[] = $id;
        $sql = "UPDATE usuarios SET " . implode(', ', $sets) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public static function completarPrimerIngreso(PDO $pdo, int $id, array $d): void {
        $stmt = $pdo->prepare("
            UPDATE usuarios
            SET nombre = ?, apellido = ?, email = ?, password_hash = ?, primer_ingreso = 0
            WHERE id = ?
        ");
        $stmt->execute([
            $d['nombre'],
            $d['apellido'],
            $d['email'] ?? null,
            Auth::hashPassword($d['password']),
            $id,
        ]);
    }

    public static function delete(PDO $pdo, int $id): void {
        $pdo->prepare("DELETE FROM usuarios WHERE id = ?")->execute([$id]);
    }
}