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

function calcularPromedio(array $calificaciones): ?float {
    $valores = [];
    foreach ($calificaciones as $c) {
        if (!empty($c['nota_numerica'])) {
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
    $materiaIds = array_map(fn($l) => (int)$l['materia_id'], $libres);
    $placeholders = implode(',', array_fill(0, count($materiaIds), '?'));
    $stmt = $pdo->prepare("SELECT id, nombre FROM materias WHERE id IN ($placeholders)");
    $stmt->execute($materiaIds);
    $nombres = [];
    foreach ($stmt as $m) $nombres[(int)$m['id']] = $m['nombre'];
    $resultado = [];
    foreach ($libres as $l) {
        $mid = (int)$l['materia_id'];
        $resultado[] = ['id' => $mid, 'nombre' => $nombres[$mid] ?? ('Materia ' . $mid), 'motivo' => $l['motivo'] ?? 'Exceso de inasistencias'];
    }
    return $resultado;
}

function invalidarPromedio($mongoDB, int $alumnoId, int $materiaId): void {
    $mongoDB->selectCollection('promedios_confirmados')->updateOne(
        ['alumno_id' => $alumnoId, 'materia_id' => $materiaId],
        ['$set' => ['confirmado' => false, 'fecha_confirmacion' => null]]
    );
}