<?php
function calificacionANumerico(?string $cal): ?float {
    if (!$cal) return null;
    return CALIF_A_NUMERICO[$cal] ?? null;
}

function numericoACalificacion(?float $num): ?string {
    if ($num === null) return null;
    $escala = CALIF_A_NUMERICO;
    arsort($escala);
    $mejor = null;
    $menorDif = PHP_FLOAT_MAX;
    foreach ($escala as $letra => $valor) {
        $dif = abs($num - $valor);
        if ($dif < $menorDif) {
            $menorDif = $dif;
            $mejor = $letra;
        }
    }
    return $mejor;
}

/**
 * Formato único de nota: número de 2 cifras + letra, p. ej. "09 - MB+" (con decimales: "08,50 - MB").
 */
function formatoNota(?float $n): string {
    if ($n === null) return '—';
    $letra = numericoACalificacion($n);
    $num = abs($n - round($n)) < 0.005
        ? sprintf('%02d', (int)round($n))
        : str_pad(number_format($n, 2, ',', ''), 5, '0', STR_PAD_LEFT);
    return $num . ($letra ? ' - ' . $letra : '');
}

/** Valor numérico de una calificación (tarea o extra): nota numérica si existe, si no el equivalente de la letra. */
function notaNumericaDe(array $c): ?float {
    if (isset($c['nota_numerica']) && $c['nota_numerica'] !== null && $c['nota_numerica'] !== '') return (float)$c['nota_numerica'];
    if (!empty($c['calificacion'])) return calificacionANumerico($c['calificacion']);
    return null;
}

function calcularPromedio(array $calificaciones): ?float {
    $valores = [];
    foreach ($calificaciones as $c) {
        if (isset($c['nota_numerica']) && $c['nota_numerica'] !== null && $c['nota_numerica'] !== '') {
            $valores[] = (float)$c['nota_numerica'];
        } elseif (!empty($c['calificacion'])) {
            $n = calificacionANumerico($c['calificacion']);
            if ($n !== null) $valores[] = $n;
        }
    }
    if (empty($valores)) return null;
    return round(array_sum($valores) / count($valores), 2);
}

function obtenerCalificacionesAlumno($mongoDB, int $alumnoId, int $materiaId): array {
    $entregas = $mongoDB->selectCollection('entregas')->find(['alumno_id' => $alumnoId])->toArray();
    $tareas = [];
    foreach ($entregas as $e) {
        if (!in_array($e['estado'], ['aprobada', 'rechazada'])) continue;
        if (empty($e['calificacion']) && ($e['nota_numerica'] ?? null) === null) continue;
        $tarea = $mongoDB->selectCollection('tareas')->findOne(['_id' => $e['tarea_id']]);
        if (!$tarea || (int)$tarea['materia_id'] !== $materiaId) continue;
        $tareas[] = [
            'id'            => (string)$e['_id'],
            'concepto'      => $tarea['titulo'],
            'origen'        => 'tarea',
            'calificacion'  => $e['calificacion'] ?? null,
            'nota_numerica' => $e['nota_numerica'] ?? null,
            'vista'         => $e['vista_calificacion'] ?? 'letras',
            'estado'        => $e['estado'],
            'año_lectivo'   => isset($tarea['año_lectivo']) ? (int)$tarea['año_lectivo'] : null,
            'fecha'         => $e['fecha_correccion'] ?? $e['fecha_entrega'] ?? null,
        ];
    }
    $extras = $mongoDB->selectCollection('calificaciones_extra')->find([
        'alumno_id' => $alumnoId, 'materia_id' => $materiaId,
    ], ['sort' => ['fecha' => -1]])->toArray();
    $resultado = $tareas;
    foreach ($extras as $x) {
        $resultado[] = [
            'id'            => (string)$x['_id'],
            'concepto'      => $x['concepto'],
            'origen'        => 'extra',
            'calificacion'  => null,
            'nota_numerica' => (float)$x['nota'],
            'estado'        => 'aprobada',
            'año_lectivo'   => isset($x['año_lectivo']) ? (int)$x['año_lectivo'] : null,
            'fecha'         => $x['fecha'] ?? null,
        ];
    }
    return $resultado;
}

function obtenerMateriasLibres(PDO $pdo, $mongoDB, int $alumnoId): array {
    $libres = $mongoDB->selectCollection('alumno_libre')->find(['alumno_id' => $alumnoId])->toArray();
    if (empty($libres)) return [];
    // materia_id 0 = asistencia por día completo (no es una materia real)
    $materiaIds = array_values(array_filter(array_map(fn($l) => (int)$l['materia_id'], $libres)));
    if (empty($materiaIds)) $materiaIds = [0];
    $placeholders = implode(',', array_fill(0, count($materiaIds), '?'));
    $stmt = $pdo->prepare("SELECT id, nombre FROM materias WHERE id IN ($placeholders)");
    $stmt->execute($materiaIds);
    $nombres = [];
    foreach ($stmt as $m) $nombres[(int)$m['id']] = $m['nombre'];
    $resultado = [];
    foreach ($libres as $l) {
        $mid = (int)$l['materia_id'];
        $resultado[] = ['id' => $mid, 'nombre' => $mid === 0 ? 'Asistencia general (por día)' : ($nombres[$mid] ?? ('Materia ' . $mid)), 'motivo' => $l['motivo'] ?? 'Exceso de inasistencias'];
    }
    return $resultado;
}

function invalidarPromedio($mongoDB, int $alumnoId, int $materiaId): void {
    $mongoDB->selectCollection('promedios_confirmados')->updateOne(
        ['alumno_id' => $alumnoId, 'materia_id' => $materiaId],
        ['$set' => ['confirmado' => false, 'fecha_confirmacion' => null]]
    );
}

/**
 * Calificaciones de VARIOS alumnos en una materia con pocas consultas (en vez de 2 consultas por alumno y por tarea).
 * @return array<int,array> alumno_id => lista de calificaciones (mismo formato que obtenerCalificacionesAlumno)
 */
function obtenerCalificacionesLote($mongoDB, array $alumnoIds, int $materiaId, ?int $año = null): array {
    $alumnoIds = array_values(array_unique(array_map('intval', $alumnoIds)));
    $out = [];
    foreach ($alumnoIds as $id) $out[$id] = [];
    if (!$alumnoIds) return $out;

    $tareas = [];
    foreach ($mongoDB->selectCollection('tareas')->find(['materia_id' => $materiaId], ['projection' => ['titulo' => 1, 'año_lectivo' => 1]]) as $t) {
        $tareas[(string)$t['_id']] = $t;
    }
    if ($tareas) {
        $entregas = $mongoDB->selectCollection('entregas')->find([
            'alumno_id' => ['$in' => $alumnoIds],
            'tarea_id'  => ['$in' => array_map(fn($t) => $t['_id'], array_values($tareas))],
            'estado'    => ['$in' => ['aprobada', 'rechazada']],
        ]);
        foreach ($entregas as $e) {
            if (empty($e['calificacion']) && ($e['nota_numerica'] ?? null) === null) continue;
            $tarea = $tareas[(string)$e['tarea_id']] ?? null;
            if (!$tarea) continue;
            $añoT = isset($tarea['año_lectivo']) ? (int)$tarea['año_lectivo'] : null;
            if ($año !== null && $añoT !== null && $añoT !== $año) continue;
            $out[(int)$e['alumno_id']][] = [
                'id' => (string)$e['_id'], 'concepto' => $tarea['titulo'], 'origen' => 'tarea',
                'calificacion' => $e['calificacion'] ?? null, 'nota_numerica' => $e['nota_numerica'] ?? null,
                'vista' => $e['vista_calificacion'] ?? 'letras', 'estado' => $e['estado'],
                'año_lectivo' => $añoT, 'fecha' => $e['fecha_correccion'] ?? $e['fecha_entrega'] ?? null,
            ];
        }
    }
    $extras = $mongoDB->selectCollection('calificaciones_extra')->find(
        ['alumno_id' => ['$in' => $alumnoIds], 'materia_id' => $materiaId], ['sort' => ['fecha' => -1]]);
    foreach ($extras as $x) {
        $añoX = isset($x['año_lectivo']) ? (int)$x['año_lectivo'] : null;
        if ($año !== null && $añoX !== null && $añoX !== $año) continue;
        $out[(int)$x['alumno_id']][] = [
            'id' => (string)$x['_id'], 'concepto' => $x['concepto'], 'origen' => 'extra',
            'calificacion' => null, 'nota_numerica' => (float)$x['nota'], 'estado' => 'aprobada',
            'año_lectivo' => $añoX, 'fecha' => $x['fecha'] ?? null,
            'global_id' => $x['global_id'] ?? null,
        ];
    }
    return $out;
}

/**
 * Promedios confirmados de varios alumnos en una materia y año (una sola consulta).
 * Los registros viejos sin año_lectivo se consideran válidos para cualquier año.
 * @return array<int,array> alumno_id => documento
 */
function obtenerPromediosConfirmadosLote($mongoDB, array $alumnoIds, int $materiaId, int $año): array {
    $alumnoIds = array_values(array_unique(array_map('intval', $alumnoIds)));
    if (!$alumnoIds) return [];
    $res = [];
    $docs = $mongoDB->selectCollection('promedios_confirmados')->find(['alumno_id' => ['$in' => $alumnoIds], 'materia_id' => $materiaId]);
    foreach ($docs as $d) {
        $aid = (int)$d['alumno_id'];
        $añoD = isset($d['año_lectivo']) ? (int)$d['año_lectivo'] : null;
        if ($añoD !== null && $añoD !== $año) continue;
        if (!isset($res[$aid]) || ($añoD !== null)) $res[$aid] = $d; // el que tiene año exacto gana
    }
    return $res;
}
