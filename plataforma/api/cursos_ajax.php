<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Helpers.php';
require_once __DIR__ . '/../models/AcademicModel.php';
require_once __DIR__ . '/../core/Feriados.php';
require_once __DIR__ . '/../core/Acceso.php';
require_once __DIR__ . '/../core/AsistenciaReglas.php';

header('Content-Type: application/json; charset=utf-8');
Auth::checkRole(ROLES_ALL);
if (($_SESSION['rol'] ?? '') === 'madre') {
    jsonResponse(['ok' => false, 'mensaje' => 'La cuenta madre no administra cursos desde acá'], 403);
}
Auth::checkRole(['directivo', 'secretario']);

$userActual = Auth::currentUser($pdo);
$miInst = (int)($userActual['institucion_id'] ?? 0);
if (!$miInst) jsonResponse(['ok' => false, 'mensaje' => 'Tu usuario no tiene institución asignada'], 403);

$action = $_GET['action'] ?? '';

function guardarAsignacionesMateria(PDO $pdo, int $materiaId, array $asignacionesIds, int $miInst): void {
    $pdo->prepare("DELETE FROM materias_asignaciones WHERE materia_id = ?")->execute([$materiaId]);
    if (empty($asignacionesIds)) return;

    $ids = array_values(array_unique(array_map('intval', $asignacionesIds)));
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id FROM asignaciones WHERE id IN ($ph) AND institucion_id = ?");
    $stmt->execute(array_merge($ids, [$miInst]));
    $validos = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $ins = $pdo->prepare("INSERT IGNORE INTO materias_asignaciones (materia_id, asignacion_id) VALUES (?, ?)");
    foreach ($validos as $aid) $ins->execute([$materiaId, (int)$aid]);
    AcademicModel::sumarMateriaNuevaAParciales($pdo, $materiaId);
}

/** Asistencia propia de la materia en cursos por día completo: [0|1, cuánto suma cada falta (0,25 a 10)] */
function datosAsistenciaPropia(): array {
    $propia = !empty($_POST['asistencia_propia']) ? 1 : 0;
    $v = round((float)str_replace(',', '.', (string)($_POST['valor_falta_propia'] ?? '1')), 2);
    if ($v < 0.25 || $v > 10) $v = 1.0;
    return [$propia, $v];
}

try {
    switch ($action) {

        case 'crear_asignacion':
            $nombre = trim($_POST['nombre'] ?? '');
            $nivelId = (int)($_POST['nivel_id'] ?? 0);
            if ($nombre === '' || !$nivelId) jsonResponse(['ok' => false, 'mensaje' => 'Faltan datos'], 400);
            $porDia = !empty($_POST['asistencia_por_dia']) && AsistenciaReglas::modoPorDiaDisponible($pdo) ? 1 : 0;
            if ($porDia) {
                $pdo->prepare("INSERT INTO asignaciones (nombre, nivel_id, institucion_id, asistencia_por_dia) VALUES (?, ?, ?, 1)")
                    ->execute([$nombre, $nivelId, $miInst]);
            } else {
                $pdo->prepare("INSERT INTO asignaciones (nombre, nivel_id, institucion_id) VALUES (?, ?, ?)")
                    ->execute([$nombre, $nivelId, $miInst]);
            }
            jsonResponse(['ok' => true]);
            break;

        case 'editar_asignacion':
            $id = (int)($_POST['id'] ?? 0);
            $nombre = trim($_POST['nombre'] ?? '');
            if (!$id || $nombre === '') jsonResponse(['ok' => false, 'mensaje' => 'El nombre es obligatorio'], 400);
            $existe = $pdo->prepare("SELECT 1 FROM asignaciones WHERE id = ? AND institucion_id = ?");
            $existe->execute([$id, $miInst]);
            if (!$existe->fetchColumn()) jsonResponse(['ok' => false, 'mensaje' => 'Curso no encontrado'], 404);

            // No repetir el nombre de otro curso de la institución
            $dup = $pdo->prepare("SELECT 1 FROM asignaciones WHERE institucion_id = ? AND id <> ? AND LOWER(TRIM(nombre)) = LOWER(?)");
            $dup->execute([$miInst, $id, $nombre]);
            if ($dup->fetchColumn()) jsonResponse(['ok' => false, 'mensaje' => 'Ya existe otro curso con ese nombre'], 400);

            $pdo->prepare("UPDATE asignaciones SET nombre = ? WHERE id = ? AND institucion_id = ?")->execute([$nombre, $id, $miInst]);
            // El checkbox desmarcado no se envía: "modo_enviado" indica que el formulario trae ese campo
            if (isset($_POST['modo_enviado']) && AsistenciaReglas::modoPorDiaDisponible($pdo)) {
                $porDia = !empty($_POST['asistencia_por_dia']) ? 1 : 0;
                $pdo->prepare("UPDATE asignaciones SET asistencia_por_dia = ? WHERE id = ? AND institucion_id = ?")->execute([$porDia, $id, $miInst]);
            }
            jsonResponse(['ok' => true]);
            break;

        case 'guardar_modo_asistencia':
            $id = (int)($_POST['id'] ?? 0);
            $porDia = !empty($_POST['por_dia']) ? 1 : 0;
            if (!AsistenciaReglas::modoPorDiaDisponible($pdo)) {
                jsonResponse(['ok' => false, 'mensaje' => 'Falta ejecutar la migración sql/migracion_asistencia_por_dia.sql'], 400);
            }
            // Solo cursos de la propia institución
            $stmt = $pdo->prepare("UPDATE asignaciones SET asistencia_por_dia = ? WHERE id = ? AND institucion_id = ?");
            $stmt->execute([$porDia, $id, $miInst]);
            $existe = $pdo->prepare("SELECT 1 FROM asignaciones WHERE id = ? AND institucion_id = ?");
            $existe->execute([$id, $miInst]);
            if (!$existe->fetchColumn()) jsonResponse(['ok' => false, 'mensaje' => 'Curso no encontrado'], 404);
            jsonResponse(['ok' => true]);
            break;

        case 'crear_orientacion':
            $nombre = trim($_POST['nombre'] ?? '');
            if ($nombre === '') jsonResponse(['ok' => false, 'mensaje' => 'Nombre requerido'], 400);
            try {
                $pdo->prepare("INSERT INTO orientaciones (nombre) VALUES (?)")->execute([$nombre]);
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) jsonResponse(['ok' => false, 'mensaje' => 'Ya existe esa orientación'], 400);
                throw $e;
            }
            jsonResponse(['ok' => true]);
            break;

        case 'crear_materia':
            $nombre = trim($_POST['nombre'] ?? '');
            $orientacionId = !empty($_POST['orientacion_id']) ? (int)$_POST['orientacion_id'] : null;
            $asignacionesIds = $_POST['asignaciones_ids'] ?? [];
            if (!is_array($asignacionesIds)) $asignacionesIds = [];
            if ($nombre === '') jsonResponse(['ok' => false, 'mensaje' => 'Nombre requerido'], 400);
            if (empty($asignacionesIds)) jsonResponse(['ok' => false, 'mensaje' => 'Seleccioná al menos un curso para la materia'], 400);

            $stmt = $pdo->prepare("
                SELECT id, nombre FROM materias
                WHERE institucion_id = ? AND LOWER(TRIM(nombre)) = LOWER(?)
                LIMIT 1
            ");
            $stmt->execute([$miInst, trim($nombre)]);
            $existente = $stmt->fetch();
            if ($existente) {
                jsonResponse([
                    'ok' => false,
                    'mensaje' => 'Ya existe una materia llamada "' . $existente['nombre'] . '". En lugar de crear una nueva, editá esa materia y asociala al curso deseado.'
                ], 400);
            }

            $pdo->beginTransaction();
            [$propia, $valorPropia] = datosAsistenciaPropia();
            $pdo->prepare("INSERT INTO materias (nombre, orientacion_id, institucion_id, asistencia_propia, valor_falta_propia) VALUES (?, ?, ?, ?, ?)")
                ->execute([$nombre, $orientacionId, $miInst, $propia, $valorPropia]);
            $mid = (int)$pdo->lastInsertId();
            guardarAsignacionesMateria($pdo, $mid, $asignacionesIds, $miInst);
            $pdo->commit();
            jsonResponse(['ok' => true, 'id' => $mid]);
            break;

        case 'editar_materia':
            $id = (int)($_POST['id'] ?? 0);
            $nombre = trim($_POST['nombre'] ?? '');
            $orientacionId = !empty($_POST['orientacion_id']) ? (int)$_POST['orientacion_id'] : null;
            $asignacionesIds = $_POST['asignaciones_ids'] ?? [];
            if (!is_array($asignacionesIds)) $asignacionesIds = [];

            if (!$id || $nombre === '') jsonResponse(['ok' => false, 'mensaje' => 'Faltan datos'], 400);
            if (empty($asignacionesIds)) jsonResponse(['ok' => false, 'mensaje' => 'Seleccioná al menos un curso para la materia'], 400);

            $stmt = $pdo->prepare("SELECT institucion_id FROM materias WHERE id = ?");
            $stmt->execute([$id]);
            $matInst = $stmt->fetchColumn();
            if ($matInst === false) jsonResponse(['ok' => false, 'mensaje' => 'Materia no encontrada'], 404);
            if ((int)$matInst !== $miInst) jsonResponse(['ok' => false, 'mensaje' => 'Esa materia no pertenece a tu institución'], 403);

            $stmt = $pdo->prepare("
                SELECT id, nombre FROM materias
                WHERE institucion_id = ? AND LOWER(TRIM(nombre)) = LOWER(?) AND id != ?
                LIMIT 1
            ");
            $stmt->execute([$miInst, trim($nombre), $id]);
            $existente = $stmt->fetch();
            if ($existente) {
                jsonResponse([
                    'ok' => false,
                    'mensaje' => 'Ya existe otra materia llamada "' . $existente['nombre'] . '". Elegí un nombre distinto.'
                ], 400);
            }

            $pdo->beginTransaction();

            [$propia, $valorPropia] = datosAsistenciaPropia();
            $pdo->prepare("UPDATE materias SET nombre = ?, orientacion_id = ?, asistencia_propia = ?, valor_falta_propia = ? WHERE id = ?")
                ->execute([$nombre, $orientacionId, $propia, $valorPropia, $id]);
            guardarAsignacionesMateria($pdo, $id, $asignacionesIds, $miInst);

            $stmt = $pdo->prepare("
                SELECT ad.id, d.asignacion_id AS div_asig, d.orientacion_id AS div_orient,
                       m.orientacion_id AS mat_orient
                FROM asignaciones_docente ad
                JOIN divisiones d ON d.id = ad.division_id
                JOIN materias m ON m.id = ad.materia_id
                WHERE ad.materia_id = ?
            ");
            $stmt->execute([$id]);

            $matAsigs = $pdo->prepare("SELECT asignacion_id FROM materias_asignaciones WHERE materia_id = ?");
            $matAsigs->execute([$id]);
            $cursosPermitidos = array_map('intval', $matAsigs->fetchAll(PDO::FETCH_COLUMN));

            $incompatibles = [];
            foreach ($stmt->fetchAll() as $row) {
                $divAsig   = (int)($row['div_asig'] ?? 0);
                $matOrient = (int)($row['mat_orient'] ?? 0);
                $divOrient = (int)($row['div_orient'] ?? 0);

                if ($matOrient > 0 && $matOrient !== $divOrient) { $incompatibles[] = (int)$row['id']; continue; }
                if (!empty($cursosPermitidos) && !in_array($divAsig, $cursosPermitidos, true)) { $incompatibles[] = (int)$row['id']; continue; }
            }
            if (!empty($incompatibles)) {
                $ph = implode(',', array_fill(0, count($incompatibles), '?'));
                $pdo->prepare("DELETE FROM asignaciones_docente WHERE id IN ($ph)")->execute($incompatibles);
            }

            $pdo->commit();
            jsonResponse(['ok' => true, 'asignaciones_eliminadas' => count($incompatibles)]);
            break;

        case 'crear_division':
            $asignacionId = (int)($_POST['asignacion_id'] ?? 0);
            $nombre = trim($_POST['nombre'] ?? '');
            $orientacionId = !empty($_POST['orientacion_id']) ? (int)$_POST['orientacion_id'] : null;
            if (!$asignacionId || $nombre === '') jsonResponse(['ok' => false, 'mensaje' => 'Faltan datos'], 400);

            $stmt = $pdo->prepare("SELECT institucion_id FROM asignaciones WHERE id = ?");
            $stmt->execute([$asignacionId]);
            $asigInst = (int)$stmt->fetchColumn();
            if ($asigInst !== $miInst) jsonResponse(['ok' => false, 'mensaje' => 'Ese curso no pertenece a tu institución'], 403);

            try {
                $pdo->prepare("INSERT INTO divisiones (asignacion_id, nombre, orientacion_id) VALUES (?, ?, ?)")
                    ->execute([$asignacionId, $nombre, $orientacionId]);
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) jsonResponse(['ok' => false, 'mensaje' => 'Ya existe esa división en este curso'], 400);
                throw $e;
            }
            jsonResponse(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
            break;

        case 'eliminar_asignacion':
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT institucion_id FROM asignaciones WHERE id = ?");
            $stmt->execute([$id]);
            $asigInst = (int)$stmt->fetchColumn();
            if ($asigInst !== $miInst) jsonResponse(['ok' => false, 'mensaje' => 'Sin permiso'], 403);
            $pdo->prepare("DELETE FROM asignaciones WHERE id = ?")->execute([$id]);
            jsonResponse(['ok' => true]);
            break;

        case 'eliminar_division':
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("
                SELECT a.institucion_id FROM divisiones d
                JOIN asignaciones a ON a.id = d.asignacion_id
                WHERE d.id = ?
            ");
            $stmt->execute([$id]);
            $divInst = (int)$stmt->fetchColumn();
            if ($divInst !== $miInst) jsonResponse(['ok' => false, 'mensaje' => 'Sin permiso'], 403);
            $pdo->prepare("DELETE FROM divisiones WHERE id = ?")->execute([$id]);
            jsonResponse(['ok' => true]);
            break;

        case 'eliminar_materia':
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT institucion_id FROM materias WHERE id = ?");
            $stmt->execute([$id]);
            $matInst = (int)$stmt->fetchColumn();
            if ($matInst !== $miInst) jsonResponse(['ok' => false, 'mensaje' => 'Sin permiso'], 403);
            $pdo->prepare("DELETE FROM materias WHERE id = ?")->execute([$id]);
            jsonResponse(['ok' => true]);
            break;

        case 'crear_ciclo':
            $año = (int)($_POST['año'] ?? 0);
            $inicio = $_POST['fecha_inicio'] ?? '';
            $fin = $_POST['fecha_fin'] ?? '';
            $activar = !empty($_POST['activar']);
            if (!$año || !$inicio || !$fin) jsonResponse(['ok' => false, 'mensaje' => 'Faltan datos'], 400);
            if ($año < 2000 || $año > 2100 || !strtotime($inicio) || !strtotime($fin) || $fin <= $inicio) {
                jsonResponse(['ok' => false, 'mensaje' => 'Revisá las fechas: el fin debe ser posterior al inicio'], 400);
            }
            $stmt = $pdo->prepare("SELECT id FROM ciclos_lectivos WHERE institucion_id = ? AND año = ?");
            $stmt->execute([$miInst, $año]);
            if ($stmt->fetchColumn()) jsonResponse(['ok' => false, 'mensaje' => 'Ya existe un ciclo para ese año'], 400);
            AcademicModel::crearCiclo($pdo, $miInst, $año, $inicio, $fin, $activar);
            jsonResponse(['ok' => true]);
            break;

        // ============================================================
        // ALUMNOS DE UN CURSO por año lectivo + transferencia a otra cursada
        // ============================================================
        case 'alumnos_curso':
            $asigId = (int)($_GET['asignacion_id'] ?? 0);
            $st = $pdo->prepare("SELECT id FROM asignaciones WHERE id = ? AND institucion_id = ?");
            $st->execute([$asigId, $miInst]);
            if (!$st->fetchColumn()) jsonResponse(['ok' => false, 'mensaje' => 'Curso no encontrado'], 404);

            $st = $pdo->prepare("
                SELECT h.alumno_id, h.año_lectivo, h.activo, h.estado, d.id AS division_id, d.nombre AS division,
                       u.nombre, u.apellido, u.dni
                FROM alumnos_division_historial h
                JOIN divisiones d ON d.id = h.division_id
                JOIN usuarios u ON u.id = h.alumno_id
                WHERE d.asignacion_id = ?
                ORDER BY h.año_lectivo DESC, u.apellido, u.nombre
            ");
            $st->execute([$asigId]);
            $alumnos = $st->fetchAll();

            // Solo ciclos abiertos: los años cerrados (ciclo no activo y ya finalizado) no aparecen
            $ciclos = array_values(array_filter(AcademicModel::ciclosLectivos($pdo, $miInst),
                fn($c) => (int)$c['activo'] === 1 || $c['fecha_fin'] >= date('Y-m-d')));
            $añosAbiertos = array_map(fn($c) => (int)$c['año'], $ciclos);
            $alumnos = array_values(array_filter($alumnos, fn($a) => in_array((int)$a['año_lectivo'], $añosAbiertos, true)));
            $años = array_values(array_unique($añosAbiertos));
            rsort($años);

            $st = $pdo->prepare("
                SELECT d.id, d.nombre AS division, a.id AS asignacion_id, a.nombre AS curso, CONCAT(a.nombre, ' — ', d.nombre) AS nombre
                FROM divisiones d JOIN asignaciones a ON a.id = d.asignacion_id
                WHERE a.institucion_id = ? ORDER BY a.nombre, d.nombre
            ");
            $st->execute([$miInst]);
            jsonResponse([
                'ok' => true,
                'años' => $años,
                'alumnos' => $alumnos,
                'destinos' => $st->fetchAll(),
                'ciclos' => array_map(fn($c) => ['id' => (int)$c['id'], 'año' => (int)$c['año'], 'activo' => (int)$c['activo']], $ciclos),
            ]);
            break;

        case 'transferir_alumnos_curso':
            $asigId = (int)($_POST['asignacion_id'] ?? 0);
            $motivo = $_POST['motivo'] ?? 'transferencia';
            $divDestino = (int)($_POST['division_destino_id'] ?? 0);
            $cicloDestino = (int)($_POST['ciclo_destino_id'] ?? 0);
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['alumnos_ids'] ?? [])))));
            if (!$ids) jsonResponse(['ok' => false, 'mensaje' => 'Seleccioná al menos un alumno'], 400);
            if (!in_array($motivo, ['promocion', 'repite', 'transferencia'], true)) jsonResponse(['ok' => false, 'mensaje' => 'Motivo inválido'], 400);

            $st = $pdo->prepare("SELECT id FROM asignaciones WHERE id = ? AND institucion_id = ?");
            $st->execute([$asigId, $miInst]);
            if (!$st->fetchColumn()) jsonResponse(['ok' => false, 'mensaje' => 'Curso no encontrado'], 404);
            $st = $pdo->prepare("SELECT 1 FROM ciclos_lectivos WHERE id = ? AND institucion_id = ?");
            $st->execute([$cicloDestino, $miInst]);
            if (!$st->fetchColumn()) jsonResponse(['ok' => false, 'mensaje' => 'Elegí el ciclo lectivo de destino'], 400);
            $st = $pdo->prepare("SELECT 1 FROM divisiones d JOIN asignaciones a ON a.id = d.asignacion_id WHERE d.id = ? AND a.institucion_id = ?");
            $st->execute([$divDestino, $miInst]);
            if (!$st->fetchColumn() && $motivo !== 'repite') jsonResponse(['ok' => false, 'mensaje' => 'Elegí el curso de destino'], 400);
            if ($motivo === 'repite' && !$divDestino) {
                $st = $pdo->prepare("SELECT id FROM divisiones WHERE asignacion_id = ? ORDER BY id LIMIT 1");
                $st->execute([$asigId]);
                $divDestino = (int)$st->fetchColumn();   // en 'repite' se mantienen sus cursadas actuales
            }

            // Solo alumnos que hoy cursan en ese curso
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("
                SELECT DISTINCT h.alumno_id FROM alumnos_division_historial h
                JOIN divisiones d ON d.id = h.division_id
                WHERE d.asignacion_id = ? AND h.activo = 1 AND h.alumno_id IN ($ph)
            ");
            $st->execute(array_merge([$asigId], $ids));
            $validos = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            if (!$validos) jsonResponse(['ok' => false, 'mensaje' => 'Ninguno de los alumnos cursa actualmente en este curso'], 400);

            $pdo->beginTransaction();
            try {
                // El historial anterior se conserva: el alumno sigue viendo lo que veía antes
                $r = AcademicModel::transferirAlumnos($pdo, $validos, $motivo, $divDestino, $cicloDestino, date('Y-m-d'));
                if (!$r['ok']) { $pdo->rollBack(); jsonResponse(['ok' => false, 'mensaje' => $r['mensaje']], 400); }
                $pdo->commit();
                jsonResponse(['ok' => true, 'procesados' => $r['procesados']]);
            } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
            break;

        case 'activar_ciclo':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) jsonResponse(['ok' => false, 'mensaje' => 'ID requerido'], 400);
            AcademicModel::activarCiclo($pdo, $miInst, $id);
            jsonResponse(['ok' => true]);
            break;

        case 'duplicar_asignacion':
            $id = (int)($_POST['id'] ?? 0);
            $nombreNuevo = trim($_POST['nombre'] ?? '');
            if (!$id || $nombreNuevo === '') jsonResponse(['ok' => false, 'mensaje' => 'Faltan datos'], 400);

            $stmt = $pdo->prepare("SELECT *  FROM asignaciones WHERE id = ?");
            $stmt->execute([$id]);
            $original = $stmt->fetch();
            if (!$original) throw new MensajeUsuarioException('Curso original no existe');
            if ((int)$original['institucion_id'] !== $miInst) jsonResponse(['ok' => false, 'mensaje' => 'Sin permiso'], 403);

            $copiarDivisiones = !empty($_POST['copiar_divisiones']);
            $copiarMaterias   = !empty($_POST['copiar_materias']);
            $divisionesIds    = $_POST['divisiones_ids'] ?? [];
            $materiasIds      = $_POST['materias_ids'] ?? [];
            if (!is_array($divisionesIds)) $divisionesIds = [];
            if (!is_array($materiasIds)) $materiasIds = [];

            $pdo->beginTransaction();

            $pdo->prepare("INSERT INTO asignaciones (nombre, nivel_id, institucion_id) VALUES (?, ?, ?)")
                ->execute([$nombreNuevo, $original['nivel_id'], $miInst]);
            $nuevoId = (int)$pdo->lastInsertId();
            // Asistencia única (por día): lo que se eligió en el formulario; si no vino el campo, igual que el original
            if (AsistenciaReglas::modoPorDiaDisponible($pdo)) {
                $porDiaCopia = isset($_POST['modo_enviado'])
                    ? !empty($_POST['asistencia_por_dia'])
                    : !empty($original['asistencia_por_dia']);
                if ($porDiaCopia) {
                    $pdo->prepare("UPDATE asignaciones SET asistencia_por_dia = 1 WHERE id = ?")->execute([$nuevoId]);
                }
            }

            if ($copiarDivisiones && !empty($divisionesIds)) {
                $idsDiv = array_map('intval', $divisionesIds);
                $ph = implode(',', array_fill(0, count($idsDiv), '?'));
                $params = array_merge($idsDiv, [$id]);
                $stmt = $pdo->prepare("
                    SELECT id, nombre, orientacion_id FROM divisiones
                    WHERE id IN ($ph) AND asignacion_id = ?
                ");
                $stmt->execute($params);
                foreach ($stmt->fetchAll() as $d) {
                    $pdo->prepare("INSERT INTO divisiones (asignacion_id, nombre, orientacion_id) VALUES (?, ?, ?)")
                        ->execute([$nuevoId, $d['nombre'], $d['orientacion_id']]);
                }
            }

            if ($copiarMaterias && !empty($materiasIds)) {
                $idsMat = array_map('intval', $materiasIds);
                $ph = implode(',', array_fill(0, count($idsMat), '?'));
                $params = array_merge($idsMat, [$id, $miInst]);
                $stmt = $pdo->prepare("
                    SELECT DISTINCT m.id FROM materias m
                    JOIN materias_asignaciones ma ON ma.materia_id = m.id
                    WHERE m.id IN ($ph) AND ma.asignacion_id = ? AND m.institucion_id = ?
                ");
                $stmt->execute($params);
                foreach ($stmt->fetchAll() as $m) {
                    $pdo->prepare("INSERT IGNORE INTO materias_asignaciones (materia_id, asignacion_id) VALUES (?, ?)")
                        ->execute([(int)$m['id'], $nuevoId]);
                }
            }

            $pdo->commit();
            jsonResponse(['ok' => true, 'nuevo_id' => $nuevoId]);
            break;

        default:
            jsonResponse(['ok' => false, 'mensaje' => 'Acción desconocida'], 400);
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    respuestaErrorInterno($e, 'cursos_ajax');
}