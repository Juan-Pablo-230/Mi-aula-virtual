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
    /**
     * Condición SQL (empieza con AND): el alumno cursa esa materia en esa división.
     * Un alumno con cursada parcial (alumnos_division.solo_materias = 1) solo cursa las materias inscriptas.
     * Los parámetros son columnas o números fijos del código que llama, nunca entrada del usuario.
     */
    public static function sqlCursaMateria(string $alumnoCol, string $divisionCol, string $materiaCol): string {
        return " AND (NOT EXISTS (SELECT 1 FROM alumnos_division adp WHERE adp.alumno_id = $alumnoCol AND adp.division_id = $divisionCol AND adp.solo_materias = 1)
                  OR EXISTS (SELECT 1 FROM alumno_materia_inscripcion ami WHERE ami.alumno_id = $alumnoCol AND ami.division_id = $divisionCol AND ami.materia_id = $materiaCol)) ";
    }

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
            WHERE ad.alumno_id = ? AND ame.alumno_id IS NULL " . self::sqlCursaMateria('ad.alumno_id', 'ad.division_id', 'm.id') . " ORDER BY m.nombre
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
            WHERE ame.alumno_id IS NULL " . self::sqlCursaMateria('x.alumno_id', 'x.division_id', 'm.id') . "
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
            WHERE ad.alumno_id IN ($placeholders) AND ame.alumno_id IS NULL " . self::sqlCursaMateria('ad.alumno_id', 'ad.division_id', 'm.id') . " ORDER BY m.nombre
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
    /** Filtro SQL "la división pertenece a esa institución" (el id va casteado a entero). */
    public static function sqlDivisionesDeInst(int $instId): string {
        return " AND division_id IN (SELECT d.id FROM divisiones d JOIN asignaciones a ON a.id = d.asignacion_id WHERE a.institucion_id = " . (int)$instId . ")";
    }

    /**
     * Asigna (o quita, con $divisionId = 0) la división del alumno en UNA institución.
     * $instId = 0 con división > 0: se toma la institución de esa división.
     * $instId = 0 con división = 0: se quitan todas (uso histórico, alumno de una sola institución).
     */
    public static function asignarDivisionAlumno(PDO $pdo, int $alumnoId, int $divisionId, int $instId = 0): void {
        if ($divisionId > 0 && $instId <= 0) {
            $st = $pdo->prepare("SELECT a.institucion_id FROM divisiones d JOIN asignaciones a ON a.id = d.asignacion_id WHERE d.id = ?");
            $st->execute([$divisionId]);
            $instId = (int)$st->fetchColumn();
        }
        $alcance = $instId > 0 ? self::sqlDivisionesDeInst($instId) : '';

        $pdo->prepare("DELETE FROM alumnos_division WHERE alumno_id = ?" . $alcance)->execute([$alumnoId]);
        $pdo->prepare("DELETE FROM alumno_materia_inscripcion WHERE alumno_id = ?" . $alcance)->execute([$alumnoId]);
        if ($divisionId <= 0) {
            $pdo->prepare("UPDATE alumnos_division_historial SET activo = 0, fecha_hasta = COALESCE(fecha_hasta, CURDATE()) WHERE alumno_id = ? AND activo = 1" . $alcance)
                ->execute([$alumnoId]);
            return;
        }
        $pdo->prepare("INSERT INTO alumnos_division (alumno_id, division_id) VALUES (?, ?)")->execute([$alumnoId, $divisionId]);
        if (!$instId) return;
        $ciclo = self::cicloActivo($pdo, $instId);
        if (!$ciclo) return; // sin ciclo activo: se usa alumnos_division como respaldo

        $st = $pdo->prepare("SELECT id, ciclo_lectivo_id FROM alumnos_division_historial WHERE alumno_id = ? AND activo = 1" . $alcance . " LIMIT 1");
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

    /**
     * Pasa un alumno a otra institución. Quien llama debe abrir la transacción.
     * Cierra su división e historial en la institución de origen, borra sus exclusiones de materias
     * (son de la institución anterior), cancela solicitudes pendientes y mueve a sus familiares
     * que ya no tengan otros alumnos en la institución de origen.
     */
    /** Cursada parcial: el alumno queda en la división pero solo cursa las materias indicadas. */
    public static function asignarCursadaParcial(PDO $pdo, int $alumnoId, int $divisionId, array $materiaIds, int $instId = 0, bool $sumarNuevas = false): void {
        self::asignarDivisionAlumno($pdo, $alumnoId, $divisionId, $instId);
        if ($divisionId <= 0) return;
        $pdo->prepare("UPDATE alumnos_division SET solo_materias = 1, sumar_nuevas = ? WHERE alumno_id = ? AND division_id = ?")->execute([$sumarNuevas ? 1 : 0, $alumnoId, $divisionId]);
        $ins = $pdo->prepare("INSERT IGNORE INTO alumno_materia_inscripcion (alumno_id, materia_id, division_id) VALUES (?, ?, ?)");
        foreach ($materiaIds as $mid) {
            if ((int)$mid > 0) $ins->execute([$alumnoId, (int)$mid, $divisionId]);
        }
    }

    /** Quita la división, el historial activo y las exclusiones de materias del alumno en una institución. */
    public static function quitarCursadaEnInstitucion(PDO $pdo, int $alumnoId, int $instId): void {
        self::asignarDivisionAlumno($pdo, $alumnoId, 0, $instId);
        $pdo->prepare("
            DELETE ame FROM alumno_materia_exclusion ame
            JOIN materias m ON m.id = ame.materia_id
            WHERE ame.alumno_id = ? AND m.institucion_id = ?
        ")->execute([$alumnoId, $instId]);
    }

    /**
     * Cursadas de los alumnos en una institución: [alumno_id => [ ['division_id','nombre','solo','sumar','materias'=>[],'año'], ... ]]
     */
    public static function cursadasPorAlumno(PDO $pdo, int $instId): array {
        $st = $pdo->prepare("
            SELECT ad.alumno_id, ad.division_id, ad.solo_materias, ad.sumar_nuevas,
                   CONCAT(a.nombre, ' ', d.nombre) AS nombre, h.año_lectivo AS anio
            FROM alumnos_division ad
            JOIN divisiones d ON d.id = ad.division_id
            JOIN asignaciones a ON a.id = d.asignacion_id AND a.institucion_id = ?
            LEFT JOIN alumnos_division_historial h ON h.alumno_id = ad.alumno_id AND h.division_id = ad.division_id AND h.activo = 1
            ORDER BY a.nombre, d.nombre
        ");
        $st->execute([$instId]);
        $out = [];
        foreach ($st as $r) {
            $out[(int)$r['alumno_id']][(int)$r['division_id']] = [
                'division_id' => (int)$r['division_id'], 'nombre' => $r['nombre'],
                'solo' => (int)$r['solo_materias'], 'sumar' => (int)$r['sumar_nuevas'],
                'materias' => [], 'anio' => $r['anio'] !== null ? (int)$r['anio'] : null,
            ];
        }
        $st = $pdo->prepare("
            SELECT ami.alumno_id, ami.division_id, ami.materia_id
            FROM alumno_materia_inscripcion ami
            JOIN divisiones d ON d.id = ami.division_id
            JOIN asignaciones a ON a.id = d.asignacion_id AND a.institucion_id = ?
        ");
        $st->execute([$instId]);
        foreach ($st as $r) {
            if (isset($out[(int)$r['alumno_id']][(int)$r['division_id']])) {
                $out[(int)$r['alumno_id']][(int)$r['division_id']]['materias'][] = (int)$r['materia_id'];
            }
        }
        return array_map('array_values', $out);
    }

    /** ¿La materia se puede cursar en esa división? (orientación y cursos permitidos) */
    public static function materiaCompatibleConDivision(PDO $pdo, int $materiaId, int $divisionId): bool {
        $st = $pdo->prepare("SELECT orientacion_id FROM materias WHERE id = ?");
        $st->execute([$materiaId]);
        $mat = $st->fetch();
        if (!$mat) return false;
        $st = $pdo->prepare("SELECT orientacion_id, asignacion_id FROM divisiones WHERE id = ?");
        $st->execute([$divisionId]);
        $div = $st->fetch();
        if (!$div) return false;
        $matOrient = (int)($mat['orientacion_id'] ?? 0);
        if ($matOrient > 0 && $matOrient !== (int)($div['orientacion_id'] ?? 0)) return false;
        $st = $pdo->prepare("SELECT asignacion_id FROM materias_asignaciones WHERE materia_id = ?");
        $st->execute([$materiaId]);
        $permitidos = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        return empty($permitidos) || in_array((int)$div['asignacion_id'], $permitidos, true);
    }

    /**
     * Cursadas del alumno en UNA institución: puede estar en varias divisiones a la vez
     * (una completa como máximo; las demás parciales, con materias distintas).
     * Cada cursada: ['division_id' => int, 'solo' => bool, 'materias' => int[], 'sumar' => bool]
     * Reemplaza todo lo que el alumno tenía en esa institución. Valida y lanza MensajeUsuarioException.
     */
    public static function guardarCursadas(PDO $pdo, int $alumnoId, int $instId, array $cursadas): void {
        if ($instId <= 0) throw new MensajeUsuarioException('El alumno no tiene institución');
        if (count($cursadas) > 10) throw new MensajeUsuarioException('Demasiadas cursadas para un mismo alumno');

        $limpias = [];
        $completas = 0;
        $materiasUsadas = [];
        $chkDiv = $pdo->prepare("SELECT 1 FROM divisiones d JOIN asignaciones a ON a.id = d.asignacion_id WHERE d.id = ? AND a.institucion_id = ?");
        foreach ($cursadas as $c) {
            $divId = (int)($c['division_id'] ?? 0);
            if ($divId <= 0) continue;
            $chkDiv->execute([$divId, $instId]);
            if (!$chkDiv->fetchColumn()) throw new MensajeUsuarioException('Una de las divisiones elegidas no pertenece a la institución');
            if (isset($limpias[$divId])) throw new MensajeUsuarioException('La misma división está elegida dos veces');
            $solo = !empty($c['solo']);
            $mats = [];
            if ($solo) {
                $mats = array_values(array_unique(array_filter(array_map('intval', (array)($c['materias'] ?? [])))));
                if (!$mats) throw new MensajeUsuarioException('Elegí al menos una materia en cada cursada parcial');
                foreach ($mats as $mid) {
                    if (!self::materiaCompatibleConDivision($pdo, $mid, $divId)) {
                        throw new MensajeUsuarioException('Una de las materias elegidas no corresponde a esa división');
                    }
                    if (isset($materiasUsadas[$mid])) throw new MensajeUsuarioException('Una materia no puede cursarse en dos divisiones a la vez');
                    $materiasUsadas[$mid] = true;
                }
            } else {
                $completas++;
            }
            $limpias[$divId] = ['division_id' => $divId, 'solo' => $solo, 'materias' => $mats, 'sumar' => !empty($c['sumar'])];
        }
        if ($completas > 1) throw new MensajeUsuarioException('Solo puede haber una cursada completa; las demás tienen que ser parciales');
        // Una materia de la cursada completa no puede repetirse en una parcial
        foreach ($limpias as $c) {
            if ($c['solo']) continue;
            foreach ($limpias as $p) {
                if (!$p['solo']) continue;
                foreach ($p['materias'] as $mid) {
                    if (self::materiaCompatibleConDivision($pdo, $mid, $c['division_id'])) {
                        throw new MensajeUsuarioException('Una materia elegida ya se cursa en la división completa del alumno');
                    }
                }
            }
        }

        $alcance = self::sqlDivisionesDeInst($instId);
        $nuevosIds = array_keys($limpias);

        // Lo que ya no está en la lista se quita (con su historial activo)
        $st = $pdo->prepare("SELECT division_id FROM alumnos_division WHERE alumno_id = ?" . $alcance);
        $st->execute([$alumnoId]);
        $delAd = $pdo->prepare("DELETE FROM alumnos_division WHERE alumno_id = ? AND division_id = ?");
        $delIns = $pdo->prepare("DELETE FROM alumno_materia_inscripcion WHERE alumno_id = ? AND division_id = ?");
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) {
            if (in_array((int)$d, $nuevosIds, true)) continue;
            $delAd->execute([$alumnoId, (int)$d]);
            $delIns->execute([$alumnoId, (int)$d]);
        }
        $st = $pdo->prepare("SELECT id, division_id, ciclo_lectivo_id FROM alumnos_division_historial WHERE alumno_id = ? AND activo = 1" . $alcance);
        $st->execute([$alumnoId]);
        $activos = $st->fetchAll();
        $cierra = $pdo->prepare("UPDATE alumnos_division_historial SET activo = 0, fecha_hasta = COALESCE(fecha_hasta, CURDATE()) WHERE id = ?");
        foreach ($activos as $h) {
            if (!in_array((int)$h['division_id'], $nuevosIds, true)) $cierra->execute([(int)$h['id']]);
        }

        $ciclo = self::cicloActivo($pdo, $instId);
        $upAd = $pdo->prepare("
            INSERT INTO alumnos_division (alumno_id, division_id, solo_materias, sumar_nuevas) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE solo_materias = VALUES(solo_materias), sumar_nuevas = VALUES(sumar_nuevas)
        ");
        $insIns = $pdo->prepare("INSERT IGNORE INTO alumno_materia_inscripcion (alumno_id, materia_id, division_id) VALUES (?, ?, ?)");
        $insHist = $pdo->prepare("
            INSERT INTO alumnos_division_historial
            (alumno_id, division_id, ciclo_lectivo_id, año_lectivo, fecha_desde, fecha_hasta, estado, activo)
            VALUES (?, ?, ?, ?, CURDATE(), NULL, 'cursando', 1)
        ");
        foreach ($limpias as $c) {
            $upAd->execute([$alumnoId, $c['division_id'], $c['solo'] ? 1 : 0, ($c['solo'] && $c['sumar']) ? 1 : 0]);
            $delIns->execute([$alumnoId, $c['division_id']]);
            if ($c['solo']) foreach ($c['materias'] as $mid) $insIns->execute([$alumnoId, $mid, $c['division_id']]);

            if (!$ciclo) continue; // sin ciclo activo: alumnos_division sirve de respaldo
            $yaActivo = null;
            foreach ($activos as $h) if ((int)$h['division_id'] === $c['division_id']) { $yaActivo = $h; break; }
            if ($yaActivo && (int)$yaActivo['ciclo_lectivo_id'] === (int)$ciclo['id']) continue;
            if ($yaActivo) $cierra->execute([(int)$yaActivo['id']]);
            $insHist->execute([$alumnoId, $c['division_id'], (int)$ciclo['id'], (int)$ciclo['año']]);
        }
    }

    /** Borra las asignaciones como docente en una institución (0 = en todas). */
    public static function borrarAsignacionesDocente(PDO $pdo, int $docenteId, int $instId = 0): void {
        $pdo->prepare("DELETE FROM asignaciones_docente WHERE docente_id = ?" . ($instId > 0 ? self::sqlDivisionesDeInst($instId) : ''))
            ->execute([$docenteId]);
    }

    /** Cuando una materia se asigna a cursos, la suman las cursadas parciales que lo pidieron ("sumar las nuevas"). */
    public static function sumarMateriaNuevaAParciales(PDO $pdo, int $materiaId): void {
        $pdo->prepare("
            INSERT IGNORE INTO alumno_materia_inscripcion (alumno_id, materia_id, division_id)
            SELECT ad.alumno_id, m.id, ad.division_id
            FROM alumnos_division ad
            JOIN divisiones d ON d.id = ad.division_id
            JOIN materias_asignaciones ma ON ma.asignacion_id = d.asignacion_id AND ma.materia_id = ?
            JOIN materias m ON m.id = ma.materia_id
            WHERE ad.solo_materias = 1 AND ad.sumar_nuevas = 1
              AND (m.orientacion_id IS NULL OR d.orientacion_id IS NULL OR m.orientacion_id = d.orientacion_id)
        ")->execute([$materiaId]);
    }

    public static function moverAlumnoAInstitucion(PDO $pdo, int $alumnoId, int $destinoId): void {
        $st = $pdo->prepare("SELECT institucion_id FROM usuarios WHERE id = ? AND rol = 'alumno'");
        $st->execute([$alumnoId]);
        $origen = $st->fetchColumn();
        if ($origen === false) throw new MensajeUsuarioException('El alumno no existe');
        $origen = (int)$origen;

        if ($origen > 0) {
            $pdo->prepare("UPDATE alumnos_division_historial SET estado = 'transferido' WHERE alumno_id = ? AND activo = 1 AND estado = 'cursando'" . self::sqlDivisionesDeInst($origen))
                ->execute([$alumnoId]);
            self::quitarCursadaEnInstitucion($pdo, $alumnoId, $origen);
        }
        $pdo->prepare("UPDATE usuarios SET institucion_id = ? WHERE id = ?")->execute([$destinoId, $alumnoId]);

        $pdo->prepare("UPDATE solicitudes_transferencia SET estado = 'cancelada', resuelta_en = NOW() WHERE alumno_id = ? AND estado = 'pendiente'")
            ->execute([$alumnoId]);

        if ($origen > 0) {
            $fam = $pdo->prepare("SELECT familiar_id FROM familiares_alumnos WHERE alumno_id = ?");
            $fam->execute([$alumnoId]);
            $quedan = $pdo->prepare("
                SELECT COUNT(*) FROM familiares_alumnos fa JOIN usuarios al ON al.id = fa.alumno_id
                WHERE fa.familiar_id = ? AND al.institucion_id = ? AND al.id <> ?
            ");
            $mover = $pdo->prepare("UPDATE usuarios SET institucion_id = ? WHERE id = ? AND rol = 'familiar' AND institucion_id = ?");
            foreach ($fam->fetchAll(PDO::FETCH_COLUMN) as $famId) {
                $quedan->execute([(int)$famId, $origen, $alumnoId]);
                if ((int)$quedan->fetchColumn() === 0) $mover->execute([$destinoId, (int)$famId, $origen]);
            }
        }
    }

    public static function transferirAlumnos(
        PDO $pdo,
        array $alumnoIds,
        string $motivo,
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

            // Puede tener varias cursadas activas en la institución (una completa y otras parciales)
            $stmtActiva = $pdo->prepare("
                SELECT id, division_id, ciclo_lectivo_id
                FROM alumnos_division_historial
                WHERE alumno_id = ? AND activo = 1" . self::sqlDivisionesDeInst((int)$divDestino['institucion_id'])
            );
            $stmtActiva->execute([$alumnoId]);
            $activas = $stmtActiva->fetchAll();

            $cierraH = $pdo->prepare("UPDATE alumnos_division_historial SET fecha_hasta = ?, estado = ?, activo = 0 WHERE id = ?");
            foreach ($activas as $h) $cierraH->execute([$fecha, $estado, (int)$h['id']]);

            $insH = $pdo->prepare("
                INSERT INTO alumnos_division_historial
                (alumno_id, division_id, ciclo_lectivo_id, año_lectivo, fecha_desde, fecha_hasta, estado, activo)
                VALUES (?, ?, ?, ?, ?, NULL, 'cursando', 1)
            ");
            if ($motivo === 'repite' && $activas) {
                // Al repetir se mantienen todas sus cursadas (también las parciales) en el nuevo ciclo
                foreach ($activas as $h) {
                    $insH->execute([$alumnoId, (int)$h['division_id'], $cicloDestinoId, (int)$ciclo['año'], $fecha]);
                }
            } else {
                // Promoción / transferencia: pasa a la división destino y las demás cursadas del ciclo anterior se cierran
                $insH->execute([$alumnoId, $divisionDestinoId, $cicloDestinoId, (int)$ciclo['año'], $fecha]);
                $alcDest = self::sqlDivisionesDeInst((int)$divDestino['institucion_id']);
                $pdo->prepare("DELETE FROM alumnos_division WHERE alumno_id = ?" . $alcDest)->execute([$alumnoId]);
                $pdo->prepare("DELETE FROM alumno_materia_inscripcion WHERE alumno_id = ?" . $alcDest)->execute([$alumnoId]);
                $pdo->prepare("INSERT INTO alumnos_division (alumno_id, division_id) VALUES (?, ?)")
                    ->execute([$alumnoId, $divisionDestinoId]);
            }

            $procesados++;
        }

        return ['ok' => true, 'procesados' => $procesados];
    }

    /**
     * Alumnos activos que cursan una materia en las divisiones dadas, en un año lectivo.
     * @return array<int,array{id:int,nombre:string,apellido:string}> ordenados por apellido y nombre
     */
    public static function alumnosDeMateriaEnDivisiones(PDO $pdo, int $materiaId, array $divisionesIds, int $año): array {
        $divisionesIds = array_values(array_unique(array_filter(array_map('intval', $divisionesIds))));
        if ($materiaId <= 0 || !$divisionesIds) return [];
        $ph = implode(',', array_fill(0, count($divisionesIds), '?'));
        $stmt = $pdo->prepare("
            SELECT DISTINCT u.id, u.nombre, u.apellido
            FROM usuarios u
            JOIN alumnos_division_historial h ON h.alumno_id = u.id AND h.activo = 1 AND h.año_lectivo = ?
            WHERE h.division_id IN ($ph) AND u.rol = 'alumno' AND u.activo = 1
            " . self::sqlCursaMateria('u.id', 'h.division_id', (string)$materiaId) . "
            ORDER BY u.apellido, u.nombre
        ");
        $stmt->execute(array_merge([$año], $divisionesIds));
        return $stmt->fetchAll();
    }
}
