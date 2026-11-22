<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Helpers.php';
require_once __DIR__ . '/../models/UserModel.php';

header('Content-Type: application/json; charset=utf-8');
Auth::checkRole(['madre']);

$action = $_GET['action'] ?? '';

try {
    switch ($action) {

        case 'crear_con_duenio':
            $nombre    = trim($_POST['nombre'] ?? '');
            $direccion = trim($_POST['direccion'] ?? '');
            $telefono  = trim($_POST['telefono'] ?? '');
            $emailInst = trim($_POST['email'] ?? '');
            $duenioDni = trim($_POST['duenio_dni'] ?? '');

            if ($nombre === '') jsonResponse(['ok' => false, 'mensaje' => 'El nombre de la institución es obligatorio'], 400);
            $stmt = $pdo->prepare("SELECT id FROM instituciones WHERE nombre = ?");
            $stmt->execute([$nombre]);
            if ($stmt->fetchColumn()) jsonResponse(['ok' => false, 'mensaje' => 'Ya existe una institución con ese nombre'], 400);
            if ($emailInst !== '' && !filter_var($emailInst, FILTER_VALIDATE_EMAIL)) {
                jsonResponse(['ok' => false, 'mensaje' => 'Email institucional inválido'], 400);
            }
            if ($duenioDni === '' || !preg_match('/^[0-9A-Za-z]{6,20}$/', $duenioDni)) {
                jsonResponse(['ok' => false, 'mensaje' => 'DNI del dueño inválido'], 400);
            }

            $pdo->beginTransaction();

            $pdo->prepare("INSERT INTO instituciones (nombre, direccion, telefono, email) VALUES (?, ?, ?, ?)")
                ->execute([
                    $nombre,
                    $direccion !== '' ? $direccion : null,
                    $telefono !== '' ? $telefono : null,
                    $emailInst !== '' ? $emailInst : null,
                ]);
            $institucionId = (int)$pdo->lastInsertId();

            $duenio = UserModel::findByDni($pdo, $duenioDni);
            if ($duenio) {
                $duenioId = (int)$duenio['id'];
                $pdo->prepare("UPDATE usuarios SET institucion_id = ?, activo = 1 WHERE id = ?")
                    ->execute([$institucionId, $duenioId]);
                $duenioData = [
                    'id' => $duenioId,
                    'nombre' => $duenio['nombre'],
                    'apellido' => $duenio['apellido'],
                    'dni' => $duenio['dni'],
                    'password' => null,
                    'creado' => false,
                ];
            } else {
                $passwordTemporal = 'Temporal123';
                $stmt = $pdo->prepare("
                    INSERT INTO usuarios (nombre, apellido, dni, email, password_hash, rol, institucion_id, primer_ingreso, activo)
                    VALUES (?, ?, ?, NULL, ?, 'directivo', ?, 1, 1)
                ");
                $stmt->execute([
                    'Dueño',
                    'Institución "' . $nombre . '"',
                    $duenioDni,
                    Auth::hashPassword($passwordTemporal),
                    $institucionId,
                ]);
                $duenioId = (int)$pdo->lastInsertId();
                $duenioData = [
                    'id' => $duenioId,
                    'nombre' => 'Dueño',
                    'apellido' => 'Institución "' . $nombre . '"',
                    'dni' => $duenioDni,
                    'password' => $passwordTemporal,
                    'creado' => true,
                ];
            }

            $pdo->commit();
            jsonResponse(['ok' => true, 'id' => $institucionId, 'duenio' => $duenioData]);
            break;

        case 'crear':
            $nombre = trim($_POST['nombre'] ?? '');
            if ($nombre === '') jsonResponse(['ok' => false, 'mensaje' => 'Nombre requerido'], 400);
            $stmt = $pdo->prepare("SELECT id FROM instituciones WHERE nombre = ?");
            $stmt->execute([$nombre]);
            if ($stmt->fetchColumn()) jsonResponse(['ok' => false, 'mensaje' => 'Ya existe una institución con ese nombre'], 400);
            $pdo->prepare("INSERT INTO instituciones (nombre, direccion, telefono, email) VALUES (?, ?, ?, ?)")
                ->execute([
                    $nombre,
                    trim($_POST['direccion'] ?? '') ?: null,
                    trim($_POST['telefono'] ?? '') ?: null,
                    trim($_POST['email'] ?? '') ?: null,
                ]);
            jsonResponse(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
            break;

        case 'editar':
            $id = (int)($_POST['id'] ?? 0);
            $nombre = trim($_POST['nombre'] ?? '');
            if (!$id || $nombre === '') jsonResponse(['ok' => false, 'mensaje' => 'Datos inválidos'], 400);
            $pdo->prepare("UPDATE instituciones SET nombre = ?, direccion = ?, telefono = ?, email = ? WHERE id = ?")
                ->execute([
                    $nombre,
                    trim($_POST['direccion'] ?? '') ?: null,
                    trim($_POST['telefono'] ?? '') ?: null,
                    trim($_POST['email'] ?? '') ?: null,
                    $id,
                ]);
            jsonResponse(['ok' => true]);
            break;

        case 'eliminar':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) jsonResponse(['ok' => false, 'mensaje' => 'ID requerido'], 400);

            $stmt = $pdo->prepare("SELECT id, nombre FROM instituciones WHERE id = ?");
            $stmt->execute([$id]);
            $inst = $stmt->fetch();
            if (!$inst) jsonResponse(['ok' => false, 'mensaje' => 'Institución no encontrada'], 404);

            $pdo->beginTransaction();

            // ============================================================
            // 1) Usuarios y sus dependencias
            // ============================================================
            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE institucion_id = ?");
            $stmt->execute([$id]);
            $userIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

            if (!empty($userIds)) {
                $ph = implode(',', array_fill(0, count($userIds), '?'));
                $pdo->prepare("DELETE FROM familiares_alumnos WHERE familiar_id IN ($ph) OR alumno_id IN ($ph)")
                    ->execute(array_merge($userIds, $userIds));
                $pdo->prepare("DELETE FROM alumnos_division WHERE alumno_id IN ($ph)")->execute($userIds);
                $pdo->prepare("DELETE FROM asignaciones_docente WHERE docente_id IN ($ph)")->execute($userIds);
                $pdo->prepare("DELETE FROM alumno_materia_exclusion WHERE alumno_id IN ($ph)")->execute($userIds);
                $pdo->prepare("DELETE FROM usuarios WHERE id IN ($ph)")->execute($userIds);
            }

            // ============================================================
            // 2) Cursos y divisiones
            // ============================================================
            $stmt = $pdo->prepare("SELECT id FROM asignaciones WHERE institucion_id = ?");
            $stmt->execute([$id]);
            $asigIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

            if (!empty($asigIds)) {
                $phA = implode(',', array_fill(0, count($asigIds), '?'));
                $pdo->prepare("DELETE FROM divisiones WHERE asignacion_id IN ($phA)")->execute($asigIds);
                $pdo->prepare("DELETE FROM asignaciones WHERE id IN ($phA)")->execute($asigIds);
            }

            // ============================================================
            // 3) Materias y sus dependencias
            // ============================================================
            $stmt = $pdo->prepare("SELECT id FROM materias WHERE institucion_id = ?");
            $stmt->execute([$id]);
            $matIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

            if (!empty($matIds)) {
                $phM = implode(',', array_fill(0, count($matIds), '?'));
                $pdo->prepare("DELETE FROM alumno_materia_exclusion WHERE materia_id IN ($phM)")->execute($matIds);
                $pdo->prepare("DELETE FROM asignaciones_docente WHERE materia_id IN ($phM)")->execute($matIds);
                $pdo->prepare("DELETE FROM materias WHERE id IN ($phM)")->execute($matIds);
            }

            // ============================================================
            // 4) La institución
            // ============================================================
            $pdo->prepare("DELETE FROM instituciones WHERE id = ?")->execute([$id]);

            $pdo->commit();

            jsonResponse([
                'ok' => true,
                'usuarios_eliminados' => count($userIds),
                'asignaciones_eliminadas' => count($asigIds),
                'materias_eliminadas' => count($matIds),
                'institucion' => $inst['nombre'],
            ]);
            break;

        default:
            jsonResponse(['ok' => false, 'mensaje' => 'Acción desconocida'], 400);
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('instituciones_ajax ERROR: ' . $e->getMessage());
    respuestaErrorInterno($e, 'instituciones_ajax');
}