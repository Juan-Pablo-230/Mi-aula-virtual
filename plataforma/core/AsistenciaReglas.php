<?php
/**
 * Reglas de qué fechas admiten pasar lista. Se usa en el servidor (asistencia_ajax.php y
 * asistencia.php) para que nadie pueda saltearse las restricciones de la pantalla.
 *
 * Una fecha NO admite asistencia si:
 *  - no es una fecha válida (formato Y-m-d),
 *  - es futura,
 *  - es domingo,
 *  - es feriado nacional,
 *  - cae fuera de los ciclos lectivos de la institución (si la institución tiene ciclos cargados),
 *  - está dentro de un período bloqueado por dirección/secretaría.
 */
require_once __DIR__ . '/Feriados.php';
require_once __DIR__ . '/Acceso.php';

class AsistenciaReglas {

    /** Clave de materia con la que se guardan las asistencias "por día" (no pertenecen a ninguna materia). */
    const MATERIA_DIA = 0;

    // ------------------------------------------------------------
    // Modo de asistencia del curso: por materia (por defecto) o por día completo
    // ------------------------------------------------------------
    /** ¿Existe la columna asignaciones.asistencia_por_dia? (si no se ejecutó la migración, todo sigue "por materia") */
    public static function modoPorDiaDisponible(PDO $pdo): bool {
        static $existe = null;
        if ($existe === null) {
            try {
                $st = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
                                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'asignaciones' AND COLUMN_NAME = 'asistencia_por_dia'");
                $existe = ((int)$st->fetchColumn()) > 0;
            } catch (Throwable $e) { $existe = false; }
        }
        return $existe;
    }

    /** Fragmento SQL para excluir de las listas "por materia" a los alumnos de cursos que toman asistencia por día. $alias = alias de la tabla asignaciones. */
    public static function sqlSoloPorMateria(PDO $pdo, string $alias = 'asg'): string {
        return self::modoPorDiaDisponible($pdo) ? " AND COALESCE($alias.asistencia_por_dia, 0) = 0" : '';
    }

    /** ¿El curso (asignación) de esta división toma asistencia por día completo? */
    public static function divisionPorDia(PDO $pdo, int $divisionId): bool {
        if ($divisionId <= 0 || !self::modoPorDiaDisponible($pdo)) return false;
        $st = $pdo->prepare("SELECT a.asistencia_por_dia FROM divisiones d JOIN asignaciones a ON a.id = d.asignacion_id WHERE d.id = ?");
        $st->execute([$divisionId]);
        return (int)$st->fetchColumn() === 1;
    }

    public static function asignacionDeDivision(PDO $pdo, int $divisionId): int {
        $st = $pdo->prepare("SELECT asignacion_id FROM divisiones WHERE id = ?");
        $st->execute([$divisionId]);
        return (int)$st->fetchColumn();
    }

    /** Valores de falta permitidos en la asistencia por día completo: presente, ¼, ½, ¾ o falta entera. */
    const VALORES_FALTA = [0.0, 0.25, 0.5, 0.75, 1.0];

    /** Solo el secretario pasa la asistencia por día completo (dirección y la madre la ven). */
    public static function puedeCargarDiaCompleto(string $rol): bool { return $rol === 'secretario'; }

    /** Cantidad de faltas con coma decimal: 3 -> "3", 3.25 -> "3,25". */
    public static function fmtFaltas(float $f): string {
        $s = rtrim(rtrim(number_format($f, 2, ',', ''), '0'), ',');
        return $s === '' ? '0' : $s;
    }

    /** Etiqueta de una falta: 0.25 -> "¼", 0.5 -> "½", 0.75 -> "¾", 1 -> "1". */
    public static function etiquetaFalta(float $v): string {
        return ['0.25' => '¼', '0.5' => '½', '0.75' => '¾', '1' => '1'][(string)round($v, 2)] ?? self::fmtFaltas($v);
    }

    /** Ids de todas las divisiones cuyo curso toma asistencia por día completo. */
    public static function divisionesDiaIds(PDO $pdo): array {
        static $ids = null;
        if ($ids === null) {
            $ids = [];
            if (self::modoPorDiaDisponible($pdo)) {
                $ids = array_map('intval', $pdo->query("SELECT d.id FROM divisiones d JOIN asignaciones a ON a.id = d.asignacion_id WHERE a.asistencia_por_dia = 1")->fetchAll(PDO::FETCH_COLUMN));
            }
        }
        return $ids;
    }

    /**
     * Una materia de un curso por día completo puede llevar asistencia propia que SÍ cuenta para quedar libre
     * (regla de la materia). Si no, la asistencia por materia de esos cursos se guarda y se muestra, pero se ignora al contar.
     */
    public static function materiaCuentaEnCursoCompleto($mongoDB, int $materiaId): bool {
        if ($materiaId <= 0) return false;
        $r = $mongoDB->selectCollection('reglas_asistencia')->findOne(['materia_id' => $materiaId]);
        return $r && !empty($r['cuenta_en_curso_completo']);
    }

    /**
     * Faltas acumuladas por alumno (con fracciones en el modo por día) que cuentan para el tope.
     * @return array<int,float> alumno_id => faltas
     */
    public static function faltasPorAlumno(PDO $pdo, $mongoDB, array $alumnoIds, int $claveMateria): array {
        $alumnoIds = array_values(array_unique(array_map('intval', $alumnoIds)));
        if (!$alumnoIds) return [];
        $match = ['materia_id' => $claveMateria, 'alumno_id' => ['$in' => $alumnoIds], 'estado' => 'ausente'];
        if ($claveMateria > 0 && !self::materiaCuentaEnCursoCompleto($mongoDB, $claveMateria)) {
            $dia = self::divisionesDiaIds($pdo);
            if ($dia) $match['division_id'] = ['$nin' => $dia];   // curso por día completo: la materia no computa
        }
        $out = [];
        $rows = $mongoDB->selectCollection('asistencia')->aggregate([
            ['$match' => $match],
            ['$group' => ['_id' => '$alumno_id', 'total' => ['$sum' => ['$ifNull' => ['$valor_falta', 1]]]]],
        ]);
        foreach ($rows as $r) $out[(int)$r['_id']] = (float)$r['total'];
        return $out;
    }

    /** Divisiones en modo "por día" que el usuario puede ver/gestionar, con el nombre del curso. $instId: para la madre, la institución elegida. */
    public static function divisionesPorDia(PDO $pdo, array $u, int $instId = 0): array {
        if (!self::modoPorDiaDisponible($pdo)) return [];
        $base = "SELECT DISTINCT d.id, d.nombre AS division, asg.nombre AS curso
                 FROM divisiones d JOIN asignaciones asg ON asg.id = d.asignacion_id";
        $orden = " ORDER BY asg.nombre, d.nombre";
        if ($u['rol'] === 'docente') {
            return []; // el docente solo carga la asistencia de su materia
        } elseif ($u['rol'] === 'madre') {
            if ($instId <= 0) return [];
            $st = $pdo->prepare("$base WHERE asg.asistencia_por_dia = 1 AND asg.institucion_id = ?" . $orden);
            $st->execute([$instId]);
        } else {
            $st = $pdo->prepare("$base WHERE asg.asistencia_por_dia = 1 AND asg.institucion_id = ?" . $orden);
            $st->execute([$u['inst']]);
        }
        return $st->fetchAll();
    }

    public static function formatoValido(string $fecha): bool {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fecha, $m)) return false;
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    }

    public static function fmt(string $ymd): string {
        return implode('/', array_reverse(explode('-', $ymd)));
    }

    /** Períodos bloqueados de una institución, ordenados por fecha: [['id','desde','hasta','motivo'], ...] */
    public static function periodos($mongoDB, int $institucionId): array {
        if ($institucionId <= 0) return [];
        $docs = $mongoDB->selectCollection('periodos_sin_asistencia')
            ->find(['institucion_id' => $institucionId], ['sort' => ['desde' => 1]])->toArray();
        $out = [];
        foreach ($docs as $d) {
            $out[] = [
                'id'     => (string)$d['_id'],
                'desde'  => (string)$d['desde'],
                'hasta'  => (string)$d['hasta'],
                'motivo' => (string)($d['motivo'] ?? ''),
            ];
        }
        return $out;
    }

    public static function periodoQueContiene(array $periodos, string $fecha): ?array {
        foreach ($periodos as $p) {
            if ($fecha >= $p['desde'] && $fecha <= $p['hasta']) return $p;
        }
        return null;
    }

    /**
     * @return string|null mensaje de error para mostrar, o null si la fecha admite asistencia
     */
    public static function validarFecha(PDO $pdo, $mongoDB, int $materiaId, string $fecha, int $divisionId = 0): ?string {
        if (!self::formatoValido($fecha)) return 'Fecha inválida.';
        if ($fecha > date('Y-m-d')) return 'No se puede tomar asistencia en fechas futuras.';
        if ((int)date('w', strtotime($fecha)) === 0) return 'No se puede tomar asistencia los domingos.';

        if (in_array($fecha, obtenerFeriados((int)substr($fecha, 0, 4)), true)) {
            return 'No se puede tomar asistencia en un feriado nacional.';
        }

        // Asistencia por día: la institución se toma de la división; por materia, de la materia
        $instId = $divisionId > 0 ? Acceso::instDeDivision($pdo, $divisionId) : Acceso::instDeMateria($pdo, $materiaId);
        if ($instId > 0) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM ciclos_lectivos WHERE institucion_id = ?");
            $st->execute([$instId]);
            if ((int)$st->fetchColumn() > 0) {
                $st = $pdo->prepare("SELECT 1 FROM ciclos_lectivos WHERE institucion_id = ? AND ? BETWEEN fecha_inicio AND fecha_fin LIMIT 1");
                $st->execute([$instId, $fecha]);
                if (!$st->fetchColumn()) return 'La fecha está fuera de los ciclos lectivos de la institución.';
            }
            $p = self::periodoQueContiene(self::periodos($mongoDB, $instId), $fecha);
            if ($p) {
                return 'No se puede tomar asistencia entre el ' . self::fmt($p['desde']) . ' y el ' . self::fmt($p['hasta'])
                    . ($p['motivo'] !== '' ? ' (' . $p['motivo'] . ')' : '') . '.';
            }
        }
        return null;
    }
}
