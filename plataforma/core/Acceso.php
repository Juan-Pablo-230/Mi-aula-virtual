<?php
/**
 * Acceso — controles de autorización centralizados para un sistema multi-institución.
 *
 * Reglas generales:
 *  - madre            : superadministrador, acceso a todo.
 *  - directivo/secretario : solo lo que pertenece a SU institución.
 *  - docente          : solo las materias/divisiones que tiene asignadas.
 *  - alumno / familiar: solo lo propio (o de sus hijos/as).
 *
 * Todas las funciones asumen que Auth::checkRole() ya validó la sesión y refrescó
 * $_SESSION['rol'] e ['institucion_id'] desde la base de datos en esta misma petición.
 * Ante cualquier duda, devuelven false (denegar por defecto).
 */
require_once __DIR__ . '/../models/AcademicModel.php';

class Acceso {

    // ------------------------------------------------------------
    // Usuario actual
    // ------------------------------------------------------------
    /** @return array{id:int, rol:string, inst:int} */
    public static function yo(): array {
        return [
            'id'   => (int)($_SESSION['user_id'] ?? 0),
            'rol'  => (string)($_SESSION['rol'] ?? ''),
            'inst' => (int)($_SESSION['institucion_id'] ?? 0),
        ];
    }

    public static function esMadre(array $u): bool { return $u['rol'] === 'madre'; }
    public static function esAdminInst(array $u): bool { return in_array($u['rol'], ['directivo', 'secretario'], true); }

    // ------------------------------------------------------------
    // Utilidades
    // ------------------------------------------------------------
    /** Convierte arrays/BSONArray de Mongo en una lista de enteros. */
    public static function ids($v): array {
        if (is_object($v) && method_exists($v, 'getArrayCopy')) $v = $v->getArrayCopy();
        if (!is_array($v)) return [];
        return array_values(array_unique(array_map('intval', $v)));
    }

    private static function valor(PDO $pdo, string $sql, array $params) {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchColumn();
    }

    private static function lista(PDO $pdo, string $sql, array $params): array {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    // ------------------------------------------------------------
    // A qué institución pertenece cada recurso (0 = no existe / sin institución)
    // ------------------------------------------------------------
    public static function instDeMateria(PDO $pdo, int $materiaId): int {
        return (int)self::valor($pdo, "SELECT institucion_id FROM materias WHERE id = ?", [$materiaId]);
    }

    public static function instDeDivision(PDO $pdo, int $divisionId): int {
        return (int)self::valor($pdo, "SELECT a.institucion_id FROM divisiones d JOIN asignaciones a ON a.id = d.asignacion_id WHERE d.id = ?", [$divisionId]);
    }

    public static function instDeUsuario(PDO $pdo, int $usuarioId): int {
        return (int)self::valor($pdo, "SELECT institucion_id FROM usuarios WHERE id = ?", [$usuarioId]);
    }

    public static function materiaEnMiInst(PDO $pdo, array $u, int $materiaId): bool {
        if (self::esMadre($u)) return true;
        return $u['inst'] > 0 && self::instDeMateria($pdo, $materiaId) === $u['inst'];
    }

    public static function divisionEnMiInst(PDO $pdo, array $u, int $divisionId): bool {
        if (self::esMadre($u)) return true;
        return $u['inst'] > 0 && self::instDeDivision($pdo, $divisionId) === $u['inst'];
    }

    // ------------------------------------------------------------
    // Docentes, materias y alumnos
    // ------------------------------------------------------------
    public static function docenteTieneMateria(PDO $pdo, int $docenteId, int $materiaId, int $divisionId = 0): bool {
        if ($divisionId > 0) {
            return (bool)self::valor($pdo, "SELECT 1 FROM asignaciones_docente WHERE docente_id = ? AND materia_id = ? AND division_id = ?", [$docenteId, $materiaId, $divisionId]);
        }
        return (bool)self::valor($pdo, "SELECT 1 FROM asignaciones_docente WHERE docente_id = ? AND materia_id = ?", [$docenteId, $materiaId]);
    }

    /** ¿Puede gestionar (publicar, tomar asistencia, configurar) esta materia? */
    public static function puedeGestionarMateria(PDO $pdo, array $u, int $materiaId): bool {
        if ($materiaId <= 0) return false;
        if (self::esMadre($u)) return true;
        if (self::esAdminInst($u)) return self::materiaEnMiInst($pdo, $u, $materiaId);
        if ($u['rol'] === 'docente') return self::docenteTieneMateria($pdo, $u['id'], $materiaId);
        return false;
    }

    /** ¿Puede gestionar (asistencia, notas) a ESTE alumno en ESTA materia? */
    public static function puedeGestionarAlumnoMateria(PDO $pdo, array $u, int $alumnoId, int $materiaId): bool {
        if ($alumnoId <= 0 || $materiaId <= 0) return false;
        if (self::esMadre($u)) return true;
        if (self::esAdminInst($u)) {
            return $u['inst'] > 0
                && self::instDeMateria($pdo, $materiaId) === $u['inst']
                && self::instDeUsuario($pdo, $alumnoId) === $u['inst'];
        }
        if ($u['rol'] === 'docente') {
            return (bool)self::valor($pdo, "
                SELECT 1 FROM asignaciones_docente ad
                JOIN alumnos_division_historial h ON h.division_id = ad.division_id AND h.alumno_id = ? AND h.activo = 1
                WHERE ad.docente_id = ? AND ad.materia_id = ?
                " . AcademicModel::sqlCursaMateria('h.alumno_id', 'h.division_id', 'ad.materia_id') . "
                LIMIT 1
            ", [$alumnoId, $u['id'], $materiaId]);
        }
        return false;
    }

    /** ¿Puede tomar asistencia por día en esta división? (docente que dicta alguna materia en ella, directivo/secretario de la institución o madre) */
    public static function puedeGestionarDivisionDia(PDO $pdo, array $u, int $divisionId): bool {
        if ($divisionId <= 0) return false;
        if (self::esMadre($u)) return true;
        if (self::esAdminInst($u)) return self::divisionEnMiInst($pdo, $u, $divisionId);
        if ($u['rol'] === 'docente') {
            return (bool)self::valor($pdo, "SELECT 1 FROM asignaciones_docente WHERE docente_id = ? AND division_id = ? LIMIT 1", [$u['id'], $divisionId]);
        }
        return false;
    }

    /** ¿Puede gestionar la asistencia por día de ESTE alumno en ESTA división? (el alumno debe cursar en ella) */
    public static function puedeGestionarAlumnoDia(PDO $pdo, array $u, int $alumnoId, int $divisionId): bool {
        if ($alumnoId <= 0 || !self::puedeGestionarDivisionDia($pdo, $u, $divisionId)) return false;
        return (bool)self::valor($pdo, "SELECT 1 FROM alumnos_division_historial WHERE alumno_id = ? AND division_id = ? AND activo = 1 LIMIT 1", [$alumnoId, $divisionId]);
    }

    /** ¿Puede ver los datos (boletín, etc.) de este alumno? */
    public static function puedeVerAlumno(PDO $pdo, array $u, int $alumnoId): bool {
        if ($alumnoId <= 0) return false;
        if (self::esMadre($u)) return true;
        if ($u['rol'] === 'alumno') return $u['id'] === $alumnoId;
        if ($u['rol'] === 'familiar') {
            return (bool)self::valor($pdo, "SELECT 1 FROM familiares_alumnos WHERE familiar_id = ? AND alumno_id = ?", [$u['id'], $alumnoId]);
        }
        if (self::esAdminInst($u) || $u['rol'] === 'docente') {
            return $u['inst'] > 0 && self::instDeUsuario($pdo, $alumnoId) === $u['inst'];
        }
        return false;
    }

    /** IDs de las materias que el usuario puede ver, según su rol. null = todas (madre). */
    public static function materiasVisibles(PDO $pdo, array $u): ?array {
        switch ($u['rol']) {
            case 'madre':
                return null;
            case 'alumno':
                return array_map('intval', array_column(AcademicModel::materiasAlumno($pdo, $u['id']), 'id'));
            case 'familiar':
                return array_map('intval', array_column(AcademicModel::materiasDeFamiliar($pdo, $u['id']), 'id'));
            case 'docente':
                return self::lista($pdo, "SELECT DISTINCT materia_id FROM asignaciones_docente WHERE docente_id = ?", [$u['id']]);
            case 'directivo':
            case 'secretario':
                return $u['inst'] > 0 ? self::lista($pdo, "SELECT id FROM materias WHERE institucion_id = ?", [$u['inst']]) : [];
        }
        return [];
    }

    // ------------------------------------------------------------
    // Tareas
    // ------------------------------------------------------------
    /** Dueño de la tarea, directivo de SU institución o madre. */
    public static function puedeAdministrarTarea(PDO $pdo, array $u, $tarea): bool {
        if (!$tarea) return false;
        if (self::esMadre($u)) return true;
        if ($u['rol'] === 'docente') return (int)($tarea['docente_id'] ?? 0) === $u['id'];
        if (self::esAdminInst($u)) return self::materiaEnMiInst($pdo, $u, (int)($tarea['materia_id'] ?? 0));
        return false;
    }

    /** ¿Puede ver la tarea? (administra, docente de la materia, o alumno/familiar de una de sus divisiones) */
    public static function puedeVerTarea(PDO $pdo, array $u, $tarea): bool {
        if (!$tarea) return false;
        if (self::puedeAdministrarTarea($pdo, $u, $tarea)) return true;

        $materiaId = (int)($tarea['materia_id'] ?? 0);
        if ($u['rol'] === 'docente') return self::docenteTieneMateria($pdo, $u['id'], $materiaId);

        if ($u['rol'] === 'alumno' || $u['rol'] === 'familiar') {
            $alumnos = $u['rol'] === 'alumno'
                ? [$u['id']]
                : self::lista($pdo, "SELECT alumno_id FROM familiares_alumnos WHERE familiar_id = ?", [$u['id']]);
            $divs = self::ids($tarea['divisiones_ids'] ?? []);
            if (empty($alumnos) || empty($divs)) return false;
            $phA = implode(',', array_fill(0, count($alumnos), '?'));
            $phD = implode(',', array_fill(0, count($divs), '?'));
            return (bool)self::valor($pdo,
                "SELECT 1 FROM alumnos_division_historial h WHERE h.alumno_id IN ($phA) AND h.division_id IN ($phD) "
                    . AcademicModel::sqlCursaMateria('h.alumno_id', 'h.division_id', (string)$materiaId) . " LIMIT 1",
                array_merge($alumnos, $divs));
        }
        return false;
    }

    /** ¿Puede crear una tarea/publicación/bibliografía en esta materia y estas divisiones? */
    public static function puedeCrearEn(PDO $pdo, array $u, int $materiaId, array $divisionesIds): bool {
        if ($materiaId <= 0) return false;
        if (self::esMadre($u)) return true;
        if ($u['rol'] === 'docente') {
            foreach ($divisionesIds as $d) {
                if (!self::docenteTieneMateria($pdo, $u['id'], $materiaId, (int)$d)) return false;
            }
            return !empty($divisionesIds);
        }
        if (self::esAdminInst($u)) {
            if (!self::materiaEnMiInst($pdo, $u, $materiaId)) return false;
            foreach ($divisionesIds as $d) {
                if (!self::divisionEnMiInst($pdo, $u, (int)$d)) return false;
            }
            return true;
        }
        return false;
    }

    // ------------------------------------------------------------
    // Publicaciones y bibliografía
    // ------------------------------------------------------------
    public static function puedeAdministrarPublicacion(PDO $pdo, array $u, $pub): bool {
        if (!$pub) return false;
        if (self::esMadre($u)) return true;
        if ((int)($pub['autor_id'] ?? 0) === $u['id']) return true;
        if (self::esAdminInst($u)) {
            $dest = (int)($pub['institucion_destino_id'] ?? 0);
            if ($dest > 0) return $dest === $u['inst'];
            $mat = (int)($pub['materia_id'] ?? 0);
            return $mat > 0 && self::materiaEnMiInst($pdo, $u, $mat);
        }
        return false;
    }

    public static function puedeAdministrarBibliografia(PDO $pdo, array $u, $bib): bool {
        if (!$bib) return false;
        if (self::esMadre($u)) return true;
        if ((int)($bib['subido_por'] ?? 0) === $u['id']) return true;
        return self::esAdminInst($u) && self::materiaEnMiInst($pdo, $u, (int)($bib['materia_id'] ?? 0));
    }

    public static function puedeVerPublicacion(PDO $pdo, array $u, $pub): bool {
        if (!$pub) return false;
        if (self::esMadre($u) || (int)($pub['autor_id'] ?? 0) === $u['id']) return true;

        $dest  = $pub['institucion_destino_id'] ?? null;
        if ($dest !== null && (int)$dest !== $u['inst']) return false;
        $roles = $pub['roles_destino'] ?? [];
        if (is_object($roles) && method_exists($roles, 'getArrayCopy')) $roles = $roles->getArrayCopy();
        if (!empty($roles) && is_array($roles) && !in_array($u['rol'], $roles, true)) return false;

        if (!empty($pub['institucional'])) return true;

        $mat = (int)($pub['materia_id'] ?? 0);
        $visibles = self::materiasVisibles($pdo, $u);
        return $mat > 0 && $visibles !== null && in_array($mat, $visibles, true);
    }

    // ------------------------------------------------------------
    // Archivos: solo quien tiene acceso al mensaje/tarea/entrega/publicación/bibliografía
    // que referencia el archivo (o quien lo subió) puede descargarlo.
    // ------------------------------------------------------------
    public static function puedeDescargarArchivo(PDO $pdo, $mongoDB, array $u, $archivo): bool {
        if (!$archivo) return false;
        if (self::esMadre($u)) return true;
        if ($u['id'] > 0 && (int)($archivo['subido_por'] ?? 0) === $u['id']) return true;

        $aid = $archivo['_id'];

        // Mensajes: solo emisor y receptor
        if ($mongoDB->selectCollection('mensajes')->findOne([
            'archivo_id' => $aid,
            '$or' => [['de' => $u['id']], ['para' => $u['id']]],
        ], ['projection' => ['_id' => 1]])) return true;

        // Adjuntos de tareas
        foreach ($mongoDB->selectCollection('tareas')->find(['archivo_id' => $aid], ['limit' => 20]) as $t) {
            if (self::puedeVerTarea($pdo, $u, $t)) return true;
        }

        // Entregas de alumnos: el propio alumno, su familia, o quien administra la tarea
        foreach ($mongoDB->selectCollection('entregas')->find(['archivo_id' => $aid], ['limit' => 20]) as $e) {
            $alumnoId = (int)($e['alumno_id'] ?? 0);
            if ($u['rol'] === 'alumno' && $alumnoId === $u['id']) return true;
            if ($u['rol'] === 'familiar' && self::puedeVerAlumno($pdo, $u, $alumnoId)) return true;
            $t = $mongoDB->selectCollection('tareas')->findOne(['_id' => $e['tarea_id'] ?? null]);
            if ($t && self::puedeAdministrarTarea($pdo, $u, $t)) return true;
        }

        // Publicaciones
        foreach ($mongoDB->selectCollection('publicaciones')->find(['archivo_id' => $aid], ['limit' => 20]) as $p) {
            if (self::puedeVerPublicacion($pdo, $u, $p)) return true;
        }

        // Bibliografía: quien ve la materia
        $visibles = self::materiasVisibles($pdo, $u);
        foreach ($mongoDB->selectCollection('bibliografia')->find(['archivo_id' => $aid], ['limit' => 20]) as $b) {
            if ($visibles !== null && in_array((int)($b['materia_id'] ?? 0), $visibles, true)) return true;
        }

        return false;
    }
}
