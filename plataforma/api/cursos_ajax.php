<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Helpers.php';
require_once __DIR__ . '/../models/AcademicModel.php';

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
}

try {
    switch ($action) {

        case 'crear_asignacion':
            $nombre = trim($_POST['nombre'] ?? '');
            $nivelId = (int)($_POST['nivel_id'] ?? 0);
            if ($nombre === '' || !$nivelId) jsonResponse(['ok' => false, 'mensaje' => 'Faltan datos'], 400);
            $pdo->prepare("INSERT INTO asignaciones (nombre, nivel_id, institucion_id) VALUES (?, ?, ?)")
                ->execute([$nombre, $nivelId, $miInst]);
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
            $pdo->prepare("INSERT INTO materias (nombre, orientacion_id, institucion_id) VALUES (?, ?, ?)")
                ->execute([$nombre, $orientacionId, $miInst]);
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

            $pdo->prepare("UPDATE materias SET nombre = ?, orientacion_id = ? WHERE id = ?")
                ->execute([$nombre, $orientacionId, $id]);
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
            jsonResponse(['ok' => true]);
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
            $stmt = $pdo->prepare("SELECT id FROM ciclos_lectivos WHERE institucion_id = ? AND año = ?");
            $stmt->execute([$miInst, $año]);
            if ($stmt->fetchColumn()) jsonResponse(['ok' => false, 'mensaje' => 'Ya existe un ciclo para ese año'], 400);
            AcademicModel::crearCiclo($pdo, $miInst, $año, $inicio, $fin, $activar);
            jsonResponse(['ok' => true]);
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

            $stmt = $pdo->prepare("SELECT nivel_id, institucion_id FROM asignaciones WHERE id = ?");
            $stmt->execute([$id]);
            $original = $stmt->fetch();
            if (!$original) throw new Exception('Curso original no existe');
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
    jsonResponse(['ok' => false, 'mensaje' => $e->getMessage()], 500);
}