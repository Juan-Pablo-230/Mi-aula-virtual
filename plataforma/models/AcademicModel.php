<?php
class AcademicModel {

    public static function niveles(PDO $pdo): array {
        return $pdo->query("SELECT * FROM niveles ORDER BY id")->fetchAll();
    }

    public static function orientaciones(PDO $pdo): array {
        return $pdo->query("SELECT * FROM orientaciones ORDER BY nombre")->fetchAll();
    }

    public static function asignaciones(PDO $pdo): array {
        return $pdo->query("
            SELECT a.*, n.nombre AS nivel FROM asignaciones a JOIN niveles n ON n.id = a.nivel_id ORDER BY n.id, a.nombre
        ")->fetchAll();
    }

    public static function divisionesDeAsignacion(PDO $pdo, int $asignacionId): array {
        $stmt = $pdo->prepare("
            SELECT d.*, o.nombre AS orientacion FROM divisiones d
            LEFT JOIN orientaciones o ON o.id = d.orientacion_id
            WHERE d.asignacion_id = ? ORDER BY d.nombre
        ");
        $stmt->execute([$asignacionId]);
        return $stmt->fetchAll();
    }

    public static function ciclosLectivos(PDO $pdo, int $institucionId): array {
        $stmt = $pdo->prepare("SELECT * FROM ciclos_lectivos WHERE institucion_id = ? ORDER BY año DESC");
        $stmt->execute([$institucionId]);
        return $stmt->fetchAll();
    }

    public static function cicloActivo(PDO $pdo, int $institucionId): ?array {
        $stmt = $pdo->prepare("SELECT * FROM ciclos_lectivos WHERE institucion_id = ? AND activo = 1 LIMIT 1");
        $stmt->execute([$institucionId]);
        return $stmt->fetch() ?: null;
    }

    public static function cicloPorAño(PDO $pdo, int $institucionId, int $año): ?array {
        $stmt = $pdo->prepare("SELECT * FROM ciclos_lectivos WHERE institucion_id = ? AND año = ? LIMIT 1");
        $stmt->execute([$institucionId, $año]);
        return $stmt->fetch() ?: null;
    }

    public static function crearCiclo(PDO $pdo, int $institucionId, int $año, string $fechaInicio, string $fechaFin, bool $activar = false): int {
        if ($activar) {
            $pdo->prepare("UPDATE ciclos_lectivos SET activo = 0 WHERE institucion_id = ?")->execute([$institucionId]);
        }
        $stmt = $pdo->prepare("
            INSERT INTO ciclos_lectivos (institucion_id, año, fecha_inicio, fecha_fin, activo)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$institucionId, $año, $fechaInicio, $fechaFin, $activar ? 1 : 0]);
        return (int)$pdo->lastInsertId();
    }

    public static function activarCiclo(PDO $pdo, int $institucionId, int $cicloId): void {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE ciclos_lectivos SET activo = 0 WHERE institucion_id = ?")->execute([$institucionId]);
        $pdo->prepare("UPDATE ciclos_lectivos SET activo = 1 WHERE id = ? AND institucion_id = ?")->execute([$cicloId, $institucionId]);
        $pdo->commit();
    }

    public static function añosLectivosAlumno(PDO $pdo, int $alumnoId): array {
        $stmt = $pdo->prepare("
            SELECT DISTINCT año_lectivo FROM alumnos_division_historial
            WHERE alumno_id = ?
            ORDER BY año_lectivo DESC
        ");
        $stmt->execute([$alumnoId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function divisionesDelAlumnoEnAño(PDO $pdo, int $alumnoId, int $año): array {
        $stmt = $pdo->prepare("
            SELECT division_id FROM alumnos_division_historial
            WHERE alumno_id = ? AND año_lectivo = ?
        ");
        $stmt->execute([$alumnoId, $año]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function historialAlumno(PDO $pdo, int $alumnoId): array {
        $stmt = $pdo->prepare("
            SELECT h.*, d.nombre AS division_nombre, a.nombre AS curso_nombre, n.nombre AS nivel_nombre
            FROM alumnos_division_historial h
            JOIN divisiones d ON d.id = h.division_id
            JOIN asignaciones a ON a.id = d.asignacion_id
            JOIN niveles n ON n.id = a.nivel_id
            WHERE h.alumno_id = ?
            ORDER BY h.año_lectivo DESC, h.fecha_desde DESC
        ");
        $stmt->execute([$alumnoId]);
        return $stmt->fetchAll();
    }

    public static function alumnosDeDivisionEnAño(PDO $pdo, int $divisionId, int $año): array {
        $stmt = $pdo->prepare("
            SELECT DISTINCT alumno_id FROM alumnos_division_historial
            WHERE division_id = ? AND año_lectivo = ?
        ");
        $stmt->execute([$divisionId, $año]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function divisionesDocenteMateria(PDO $pdo, int $docenteId, int $materiaId): array {
        $stmt = $pdo->prepare("
            SELECT DISTINCT d.id, d.nombre, a.nombre AS curso, n.nombre AS nivel
            FROM asignaciones_docente ad
            JOIN divisiones d ON d.id = ad.division_id
            JOIN asignaciones a ON a.id = d.asignacion_id
            JOIN niveles n ON n.id = a.nivel_id
            WHERE ad.docente_id = ? AND ad.materia_id = ?
            ORDER BY n.id, a.nombre, d.nombre
        ");
        $stmt->execute([$docenteId, $materiaId]);
        return $stmt->fetchAll();
    }

    /**
     * Fragmento SQL (subconsulta) que devuelve (division_id, materia_id) para TODAS las
     * materias que le corresponden a una división:
     *   - las materias vinculadas al curso/año de la división (materias_asignaciones),
     *     respetando la orientación (materia sin orientación = común a todas), y
     *   - las que ya tengan un docente asignado a esa división (asignaciones_docente).
     * Así el alumno ve todo lo de su año aunque todavía no haya docente cargado.
     */
    private static function sqlMateriasDeDivision(): string {
        return "
            SELECT d.id AS division_id, ma.materia_id
            FROM divisiones d
            JOIN materias_asignaciones ma ON ma.asignacion_id = d.asignacion_id
            JOIN materias mm ON mm.id = ma.materia_id
            WHERE mm.orientacion_id IS NULL
               OR d.orientacion_id IS NULL
               OR mm.orientacion_id = d.orientacion_id
            UNION
            SELECT adoc.division_id, adoc.materia_id FROM asignaciones_docente adoc
        ";
    }

    public static function materiasAlumno(PDO $pdo, int $alumnoId): array {
        $stmt = $pdo->prepare("
            SELECT DISTINCT m.id, m.nombre, ad.division_id AS division_id
            FROM alumnos_division ad
            JOIN (" . self::sqlMateriasDeDivision() . ") md ON md.division_id = ad.division_id
            JOIN materias m ON m.id = md.materia_id
            LEFT JOIN alumno_materia_exclusion ame ON ame.alumno_id = ad.alumno_id AND ame.materia_id = m.id
            WHERE ad.alumno_id = ? AND ame.alumno_id IS NULL ORDER BY m.nombre
        ");
        $stmt->execute([$alumnoId]);
        return $stmt->fetchAll();
    }

    public static function materiasAlumnoEnAño(PDO $pdo, int $alumnoId, int $año): array {
        // Divisiones del alumno en ese año (historial). Si no hay historial para ese año
        // pero cursa hoy en una división y el año pedido es el activo/actual, se usa la actual.
        $stmt = $pdo->prepare("
            SELECT DISTINCT m.id, m.nombre
            FROM (
                SELECT h.alumno_id, h.division_id FROM alumnos_division_historial h
                WHERE h.alumno_id = ? AND h.año_lectivo = ?
                UNION
                SELECT ad.alumno_id, ad.division_id FROM alumnos_division ad
                WHERE ad.alumno_id = ?
                  AND NOT EXISTS (SELECT 1 FROM alumnos_division_historial hx WHERE hx.alumno_id = ad.alumno_id)
            ) x
            JOIN (" . self::sqlMateriasDeDivision() . ") md ON md.division_id = x.division_id
            JOIN materias m ON m.id = md.materia_id
            LEFT JOIN alumno_materia_exclusion ame ON ame.alumno_id = x.alumno_id AND ame.materia_id = m.id
            WHERE ame.alumno_id IS NULL
            ORDER BY m.nombre
        ");
        $stmt->execute([$alumnoId, $año, $alumnoId]);
        return $stmt->fetchAll();
    }

    public static function alumnosDeFamiliar(PDO $pdo, int $familiarId): array {
        $stmt = $pdo->prepare("SELECT alumno_id FROM familiares_alumnos WHERE familiar_id = ?");
        $stmt->execute([$familiarId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function materiasDeFamiliar(PDO $pdo, int $familiarId): array {
        $alumnos = self::alumnosDeFamiliar($pdo, $familiarId);
        if (empty($alumnos)) return [];
        $placeholders = implode(',', array_fill(0, count($alumnos), '?'));
        $stmt = $pdo->prepare("
            SELECT DISTINCT m.id, m.nombre FROM alumnos_division ad
            JOIN (" . self::sqlMateriasDeDivision() . ") md ON md.division_id = ad.division_id
            JOIN materias m ON m.id = md.materia_id
            LEFT JOIN alumno_materia_exclusion ame ON ame.alumno_id = ad.alumno_id AND ame.materia_id = m.id
            WHERE ad.alumno_id IN ($placeholders) AND ame.alumno_id IS NULL ORDER BY m.nombre
        ");
        $stmt->execute($alumnos);
        return $stmt->fetchAll();
    }

    public static function divisionesDeFamiliar(PDO $pdo, int $familiarId): array {
        $alumnos = self::alumnosDeFamiliar($pdo, $familiarId);
        if (empty($alumnos)) return [];
        $placeholders = implode(',', array_fill(0, count($alumnos), '?'));
        $stmt = $pdo->prepare("SELECT DISTINCT division_id FROM alumnos_division WHERE alumno_id IN ($placeholders)");
        $stmt->execute($alumnos);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Asigna (o corrige) la división actual de un alumno, manteniendo sincronizados
     * alumnos_division y alumnos_division_historial (ciclo lectivo activo de su institución).
     * Es una corrección de carga, no un pase de año: si ya hay una fila activa en el ciclo
     * actual se actualiza su división en lugar de crear historia nueva.
     */
    public static function asignarDivisionAlumno(PDO $pdo, int $alumnoId, int $divisionId): void {
        $pdo->prepare("DELETE FROM alumnos_division WHERE alumno_id = ?")->execute([$alumnoId]);
        if ($divisionId <= 0) {
            $pdo->prepare("UPDATE alumnos_division_historial SET activo = 0, fecha_hasta = COALESCE(fecha_hasta, CURDATE()) WHERE alumno_id = ? AND activo = 1")
                ->execute([$alumnoId]);
            return;
        }
        $pdo->prepare("INSERT INTO alumnos_division (alumno_id, division_id) VALUES (?, ?)")->execute([$alumnoId, $divisionId]);

        $st = $pdo->prepare("SELECT a.institucion_id FROM divisiones d JOIN asignaciones a ON a.id = d.asignacion_id WHERE d.id = ?");
        $st->execute([$divisionId]);
        $instId = (int)$st->fetchColumn();
        if (!$instId) return;
        $ciclo = self::cicloActivo($pdo, $instId);
        if (!$ciclo) return; // sin ciclo activo: se usa alumnos_division como respaldo

        $st = $pdo->prepare("SELECT id, ciclo_lectivo_id FROM alumnos_division_historial WHERE alumno_id = ? AND activo = 1 LIMIT 1");
        $st->execute([$alumnoId]);
        $activa = $st->fetch();
        if ($activa && (int)$activa['ciclo_lectivo_id'] === (int)$ciclo['id']) {
            $pdo->prepare("UPDATE alumnos_division_historial SET division_id = ? WHERE id = ?")
                ->execute([$divisionId, (int)$activa['id']]);
            return;
        }
        if ($activa) {
            $pdo->prepare("UPDATE alumnos_division_historial SET activo = 0, fecha_hasta = CURDATE() WHERE id = ?")
                ->execute([(int)$activa['id']]);
        }
        $pdo->prepare("
            INSERT INTO alumnos_division_historial
            (alumno_id, division_id, ciclo_lectivo_id, año_lectivo, fecha_desde, fecha_hasta, estado, activo)
            VALUES (?, ?, ?, ?, CURDATE(), NULL, 'cursando', 1)
        ")->execute([$alumnoId, $divisionId, (int)$ciclo['id'], (int)$ciclo['año']]);
    }

    public static function transferirAlumnos(
        PDO $pdo,
        array $alumnoIds,
        int $motivo,
        int $divisionDestinoId,
        int $cicloDestinoId,
        string $fecha
    ): array {
        if (empty($alumnoIds)) return ['ok' => false, 'mensaje' => 'Sin alumnos'];

        $stmtCiclo = $pdo->prepare("SELECT * FROM ciclos_lectivos WHERE id = ?");
        $stmtCiclo->execute([$cicloDestinoId]);
        $ciclo = $stmtCiclo->fetch();
        if (!$ciclo) return ['ok' => false, 'mensaje' => 'Ciclo destino inválido'];

        $stmtDiv = $pdo->prepare("
            SELECT d.id, d.asignacion_id, a.institucion_id
            FROM divisiones d JOIN asignaciones a ON a.id = d.asignacion_id
            WHERE d.id = ?
        ");
        $stmtDiv->execute([$divisionDestinoId]);
        $divDestino = $stmtDiv->fetch();
        if (!$divDestino) return ['ok' => false, 'mensaje' => 'División destino inválida'];

        $estados = ['promocion' => 'aprobado', 'repite' => 'repite', 'transferencia' => 'transferido'];
        if (!isset($estados[$motivo])) return ['ok' => false, 'mensaje' => 'Motivo inválido'];
        $estado = $estados[$motivo];

        $procesados = 0;

        foreach ($alumnoIds as $alumnoId) {
            $alumnoId = (int)$alumnoId;
            if ($alumnoId <= 0) continue;

            $stmtActiva = $pdo->prepare("
                SELECT id, division_id, ciclo_lectivo_id
                FROM alumnos_division_historial
                WHERE alumno_id = ? AND activo = 1
                LIMIT 1
            ");
            $stmtActiva->execute([$alumnoId]);
            $activa = $stmtActiva->fetch();

            if ($activa) {
                $pdo->prepare("
                    UPDATE alumnos_division_historial
                    SET fecha_hasta = ?, estado = ?, activo = 0
                    WHERE id = ?
                ")->execute([$fecha, $estado, (int)$activa['id']]);
            }

            $divFinal = ($motivo === 'repite' && $activa)
                ? (int)$activa['division_id']
                : $divisionDestinoId;

            $pdo->prepare("
                INSERT INTO alumnos_division_historial
                (alumno_id, division_id, ciclo_lectivo_id, año_lectivo, fecha_desde, fecha_hasta, estado, activo)
                VALUES (?, ?, ?, ?, ?, NULL, 'cursando', 1)
            ")->execute([
                $alumnoId,
                $divFinal,
                $cicloDestinoId,
                (int)$ciclo['año'],
                $fecha,
            ]);

            $pdo->prepare("DELETE FROM alumnos_division WHERE alumno_id = ?")->execute([$alumnoId]);
            $pdo->prepare("INSERT INTO alumnos_division (alumno_id, division_id) VALUES (?, ?)")
                ->execute([$alumnoId, $divFinal]);

            $procesados++;
        }

        return ['ok' => true, 'procesados' => $procesados];
    }
}