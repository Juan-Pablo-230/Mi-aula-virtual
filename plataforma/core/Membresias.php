<?php
/**
 * Membresías: una persona del personal puede trabajar en más de una institución, con un rol distinto
 * en cada una (p. ej. docente en una y directivo en otra).
 *
 *  - La institución/rol "principal" vive en usuarios (institucion_id, rol).
 *  - Las demás van en usuarios_instituciones (usuario_id, institucion_id, rol).
 *  - La institución activa de la sesión se guarda en $_SESSION['inst_activa'] y Auth::checkRole()
 *    la valida en cada petición, copiando rol e institución a $_SESSION['rol'] / ['institucion_id'].
 */
class Membresias {
    const ROLES = ['directivo', 'secretario', 'docente'];

    public static function esPersonal(?string $rol): bool { return in_array($rol, self::ROLES, true); }

    /** Todas las instituciones del usuario con su rol en cada una (la principal primero). */
    public static function contextos(PDO $pdo, int $usuarioId): array {
        $st = $pdo->prepare("SELECT u.rol, u.institucion_id, i.nombre FROM usuarios u LEFT JOIN instituciones i ON i.id = u.institucion_id WHERE u.id = ?");
        $st->execute([$usuarioId]);
        $u = $st->fetch();
        if (!$u || !$u['institucion_id']) return [];
        $out = [['institucion_id' => (int)$u['institucion_id'], 'nombre' => (string)$u['nombre'], 'rol' => $u['rol'], 'principal' => true]];
        if (!self::esPersonal($u['rol'])) return $out;
        try {
            $st = $pdo->prepare("SELECT ui.institucion_id, ui.rol, i.nombre FROM usuarios_instituciones ui JOIN instituciones i ON i.id = ui.institucion_id WHERE ui.usuario_id = ? ORDER BY i.nombre");
            $st->execute([$usuarioId]);
            foreach ($st as $r) $out[] = ['institucion_id' => (int)$r['institucion_id'], 'nombre' => (string)$r['nombre'], 'rol' => $r['rol'], 'principal' => false];
        } catch (Throwable $e) { error_log('Membresias::contextos: ' . $e->getMessage()); }
        return $out;
    }

    /** Rol y institución efectivos según la institución pedida (si no es válida, la principal). */
    public static function resolver(PDO $pdo, int $usuarioId, string $rolPrincipal, ?int $instPrincipal, int $instPedida): array {
        $instPrincipal = (int)$instPrincipal;
        if ($instPedida > 0 && $instPedida !== $instPrincipal && self::esPersonal($rolPrincipal)) {
            try {
                $st = $pdo->prepare("SELECT rol FROM usuarios_instituciones WHERE usuario_id = ? AND institucion_id = ?");
                $st->execute([$usuarioId, $instPedida]);
                $rol = $st->fetchColumn();
                if ($rol) return [(string)$rol, $instPedida];
            } catch (Throwable $e) { error_log('Membresias::resolver: ' . $e->getMessage()); }
        }
        return [$rolPrincipal, $instPrincipal];
    }

    /** Rol del usuario en esa institución (principal o secundaria), o null si no trabaja ahí. */
    public static function rolEn(PDO $pdo, int $usuarioId, int $instId): ?string {
        $st = $pdo->prepare("SELECT rol FROM usuarios WHERE id = ? AND institucion_id = ?");
        $st->execute([$usuarioId, $instId]);
        $r = $st->fetchColumn();
        if ($r) return (string)$r;
        try {
            $st = $pdo->prepare("SELECT rol FROM usuarios_instituciones WHERE usuario_id = ? AND institucion_id = ?");
            $st->execute([$usuarioId, $instId]);
            $r = $st->fetchColumn();
            return $r ? (string)$r : null;
        } catch (Throwable $e) { return null; }
    }

    public static function esSecundaria(PDO $pdo, int $usuarioId, int $instId): bool {
        try {
            $st = $pdo->prepare("SELECT 1 FROM usuarios_instituciones WHERE usuario_id = ? AND institucion_id = ?");
            $st->execute([$usuarioId, $instId]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) { return false; }
    }

    public static function agregar(PDO $pdo, int $usuarioId, int $instId, string $rol): void {
        if (!self::esPersonal($rol)) throw new MensajeUsuarioException('Solo el personal puede trabajar en más de una institución');
        $pdo->prepare("INSERT INTO usuarios_instituciones (usuario_id, institucion_id, rol) VALUES (?, ?, ?)
                       ON DUPLICATE KEY UPDATE rol = VALUES(rol)")->execute([$usuarioId, $instId, $rol]);
    }

    /** Quita la membresía secundaria y sus asignaciones docentes en esa institución. */
    public static function quitar(PDO $pdo, int $usuarioId, int $instId): void {
        AcademicModel::borrarAsignacionesDocente($pdo, $usuarioId, $instId);
        $pdo->prepare("DELETE FROM usuarios_instituciones WHERE usuario_id = ? AND institucion_id = ?")->execute([$usuarioId, $instId]);
    }

    /**
     * Antes de borrar a un usuario de su institución principal: si trabaja en otras, la primera
     * pasa a ser su principal y el usuario NO se borra. Devuelve true si se promovió (no borrar).
     */
    public static function promoverSiTieneOtras(PDO $pdo, int $usuarioId, int $instPrincipal): bool {
        try {
            $st = $pdo->prepare("SELECT institucion_id, rol FROM usuarios_instituciones WHERE usuario_id = ? ORDER BY creado_en LIMIT 1");
            $st->execute([$usuarioId]);
            $m = $st->fetch();
        } catch (Throwable $e) { return false; }
        if (!$m) return false;
        AcademicModel::borrarAsignacionesDocente($pdo, $usuarioId, $instPrincipal);
        $pdo->prepare("UPDATE usuarios SET institucion_id = ?, rol = ? WHERE id = ?")->execute([(int)$m['institucion_id'], $m['rol'], $usuarioId]);
        $pdo->prepare("DELETE FROM usuarios_instituciones WHERE usuario_id = ? AND institucion_id = ?")->execute([$usuarioId, (int)$m['institucion_id']]);
        return true;
    }
}
