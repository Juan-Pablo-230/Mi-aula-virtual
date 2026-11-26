<?php
/**
 * Índices de MongoDB que aceleran calificaciones y asistencia. Se crean una sola vez
 * (queda una marca en el directorio temporal; si se borra, se vuelven a pedir: es idempotente).
 */
class MongoIndices {
    const VERSION = 'v1';

    public static function asegurar($mongoDB): void {
        $marca = sys_get_temp_dir() . '/aula_mongo_indices_' . self::VERSION . '_' . md5(__DIR__) . '.ok';
        if (is_file($marca)) return;
        $indices = [
            'entregas'                   => [['alumno_id' => 1, 'tarea_id' => 1], ['tarea_id' => 1]],
            'tareas'                     => [['materia_id' => 1]],
            'calificaciones_extra'       => [['alumno_id' => 1, 'materia_id' => 1]],
            'promedios_confirmados'      => [['alumno_id' => 1, 'materia_id' => 1]],
            'asistencia'                 => [['materia_id' => 1, 'alumno_id' => 1, 'fecha' => 1], ['alumno_id' => 1, 'fecha' => -1], ['division_id' => 1, 'materia_id' => 1]],
            'alumno_libre'               => [['alumno_id' => 1, 'materia_id' => 1]],
        ];
        try {
            foreach ($indices as $col => $lista) {
                foreach ($lista as $clave) $mongoDB->selectCollection($col)->createIndex($clave);
            }
            @file_put_contents($marca, date('c'));
        } catch (Throwable $e) {
            error_log('MongoIndices: ' . $e->getMessage());
        }
    }
}
