<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Helpers.php';
require_once __DIR__ . '/../models/UserModel.php';

header('Content-Type: application/json; charset=utf-8');
Auth::checkRole(['madre']);

$institucionId = (int)($_GET['id'] ?? 0);
if (!$institucionId) jsonResponse(['ok' => false, 'mensaje' => 'Institución requerida'], 400);

$stmt = $pdo->prepare("SELECT * FROM instituciones WHERE id = ?");
$stmt->execute([$institucionId]);
if (!$stmt->fetch()) jsonResponse(['ok' => false, 'mensaje' => 'Institución no existe'], 404);

$action = $_GET['action'] ?? '';

function esMateriaCompatible(PDO $pdo, int $materiaId, int $divisionId): bool {
    $stmt = $pdo->prepare("SELECT orientacion_id FROM materias WHERE id = ?");
    $stmt->execute([$materiaId]);
    $mat = $stmt->fetch();
    if (!$mat) return false;

    $stmt = $pdo->prepare("SELECT orientacion_id, asignacion_id FROM divisiones WHERE id = ?");
    $stmt->execute([$divisionId]);
    $div = $stmt->fetch();
    if (!$div) return false;

    $matOrient = (int)($mat['orientacion_id'] ?? 0);
    $divOrient = (int)($div['orientacion_id'] ?? 0);
    $divAsig   = (int)($div['asignacion_id'] ?? 0);

    if ($matOrient > 0 && $matOrient !== $divOrient) return false;

    $stmt = $pdo->prepare("SELECT asignacion_id FROM materias_asignaciones WHERE materia_id = ?");
    $stmt->execute([$materiaId]);
    $cursosPermitidos = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    if (!empty($cursosPermitidos) && !in_array($divAsig, $cursosPermitidos, true)) return false;

    return true;
}

try {
    switch ($action) {

        case 'crear':
            $nombre   = trim($_POST['nombre'] ?? '');
            $apellido = trim($_POST['apellido'] ?? '');
            $dni      = trim($_POST['dni'] ?? '');
            $email    = trim($_POST['email'] ?? '');
            $rol      = $_POST['rol'] ?? '';

            if ($nombre === '' || $apellido === '' || $dni === '') {
                jsonResponse(['ok' => false, 'mensaje' => 'Nombre, apellido y DNI son obligatorios'], 400);
            }
            if (!preg_match('/^[0-9A-Za-z]{6,20}$/', $dni)) {
                jsonResponse(['ok' => false, 'mensaje' => 'DNI inválido'], 400);
            }
            if (!in_array($rol, ['alumno','docente','secretario','directivo','familiar'])) {
                jsonResponse(['ok' => false, 'mensaje' => 'Rol inválido'], 400);
            }
            if (UserModel::findByDni($pdo, $dni)) {
                jsonResponse(['ok' => false, 'mensaje' => 'Ya existe un usuario con ese DNI'], 400);
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                jsonResponse(['ok' => false, 'mensaje' => 'Email inválido'], 400);
            }

            $pdo->beginTransaction();

            $id = UserModel::create($pdo, [
                'nombre'   => $nombre,
                'apellido' => $apellido,
                'dni'      => $dni,
                'email'    => $email !== '' ? $email : null,
                'password' => 'Temporal123',
                'rol'      => $rol
            ]);

            $pdo->prepare("UPDATE usuarios SET institucion_id = ? WHERE id = ?")->execute([$institucionId, $id]);

            if ($rol === 'alumno') {
                $divisionId = (int)($_POST['division_id'] ?? 0);
                if ($divisionId > 0) {
                    $pdo->prepare("INSERT INTO alumnos_division (alumno_id, division_id) VALUES (?, ?)")->execute([$id, $divisionId]);
                }
                $dniFamiliar = trim($_POST['dni_familiar'] ?? '');
                $nombreFamiliar = trim($_POST['nombre_familiar'] ?? '');
                if ($dniFamiliar !== '') {
                    $stmt = $pdo->prepare("SELECT id, rol FROM usuarios WHERE dni = ?");
                    $stmt->execute([$dniFamiliar]);
                    $familiar = $stmt->fetch();
                    if ($familiar) {
                        if (!in_array($familiar['rol'], ['familiar', 'alumno'])) {
                            throw new MensajeUsuarioException("El DNI $dniFamiliar ya está registrado como {$familiar['rol']}");
                        }
                        $familiarId = (int)$familiar['id'];
                        if ($familiar['rol'] === 'alumno') {
                            $pdo->prepare("UPDATE usuarios SET rol = 'familiar' WHERE id = ?")->execute([$familiarId]);
                        }
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO usuarios (nombre, apellido, dni, email, password_hash, rol, primer_ingreso, institucion_id)
                            VALUES (?, ?, ?, NULL, ?, 'familiar', 1, ?)
                        ");
                        $stmt->execute([
                            $nombreFamiliar ?: 'Familiar de ' . $apellido,
                            $apellido,
                            $dniFamiliar,
                            Auth::hashPassword('Temporal123'),
                            $institucionId,
                        ]);
                        $familiarId = (int)$pdo->lastInsertId();
                    }
                    $pdo->prepare("INSERT IGNORE INTO familiares_alumnos (familiar_id, alumno_id) VALUES (?, ?)")->execute([$familiarId, $id]);
                }
            }

            if ($rol === 'docente') {
                $asignaciones = $_POST['asignaciones'] ?? [];
                if (is_array($asignaciones)) {
                    $insAsig = $pdo->prepare("INSERT IGNORE INTO asignaciones_docente (docente_id, materia_id, division_id) VALUES (?, ?, ?)");
                    foreach ($asignaciones as $par) {
                        [$materiaId, $divisionId] = array_map('intval', explode('-', $par));
                        if ($materiaId <= 0 || $divisionId <= 0) continue;
                        if (!esMateriaCompatible($pdo, $materiaId, $divisionId)) continue;
                        $insAsig->execute([$id, $materiaId, $divisionId]);
                    }
                }
            }

            $pdo->commit();
            jsonResponse(['ok' => true, 'id' => $id]);
            break;

        case 'editar':
            $id       = (int)($_POST['id'] ?? 0);
            $nombre   = trim($_POST['nombre'] ?? '');
            $apellido = trim($_POST['apellido'] ?? '');
            $dni      = trim($_POST['dni'] ?? '');
            $email    = trim($_POST['email'] ?? '');
            $rol      = $_POST['rol'] ?? '';
            $activo   = $_POST['activo'] ?? '1';

            if (!$id) jsonResponse(['ok' => false, 'mensaje' => 'ID inválido'], 400);
            if ($nombre === '' || $apellido === '' || $dni === '') jsonResponse(['ok' => false, 'mensaje' => 'Faltan datos'], 400);
            if (!in_array($rol, ['alumno','docente','secretario','directivo','familiar'])) jsonResponse(['ok' => false, 'mensaje' => 'Rol inválido'], 400);

            $stmt = $pdo->prepare("SELECT institucion_id FROM usuarios WHERE id = ?");
            $stmt->execute([$id]);
            $uInst = $stmt->fetchColumn();
            if ((int)$uInst !== $institucionId) jsonResponse(['ok' => false, 'mensaje' => 'Usuario no pertenece a esta institución'], 403);

            $existente = UserModel::findByDni($pdo, $dni);
            if ($existente && (int)$existente['id'] !== $id) jsonResponse(['ok' => false, 'mensaje' => 'Ese DNI ya está en uso'], 400);

            $pdo->beginTransaction();

            UserModel::update($pdo, $id, [
                'nombre'   => $nombre,
                'apellido' => $apellido,
                'dni'      => $dni,
                'email'    => $email !== '' ? $email : null,
                'rol'      => $rol,
                'activo'   => (int)$activo
            ]);

            $pdo->prepare("DELETE FROM alumnos_division WHERE alumno_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM asignaciones_docente WHERE docente_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM familiares_alumnos WHERE alumno_id = ?")->execute([$id]);

            if ($rol === 'alumno') {
                $divisionId = (int)($_POST['division_id'] ?? 0);
                if ($divisionId > 0) {
                    $pdo->prepare("INSERT INTO alumnos_division (alumno_id, division_id) VALUES (?, ?)")->execute([$id, $divisionId]);
                }
                $dniFamiliar = trim($_POST['dni_familiar'] ?? '');
                if ($dniFamiliar !== '') {
                    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE dni = ?");
                    $stmt->execute([$dniFamiliar]);
                    $famId = (int)$stmt->fetchColumn();
                    if ($famId) {
                        $pdo->prepare("INSERT IGNORE INTO familiares_alumnos (familiar_id, alumno_id) VALUES (?, ?)")->execute([$famId, $id]);
                    }
                }
            } elseif ($rol === 'docente') {
                $asignaciones = $_POST['asignaciones'] ?? [];
                if (is_array($asignaciones)) {
                    $insAsig = $pdo->prepare("INSERT IGNORE INTO asignaciones_docente (docente_id, materia_id, division_id) VALUES (?, ?, ?)");
                    foreach ($asignaciones as $par) {
                        [$materiaId, $divisionId] = array_map('intval', explode('-', $par));
                        if ($materiaId <= 0 || $divisionId <= 0) continue;
                        if (!esMateriaCompatible($pdo, $materiaId, $divisionId)) continue;
                        $insAsig->execute([$id, $materiaId, $divisionId]);
                    }
                }
            }

            $pdo->commit();
            jsonResponse(['ok' => true]);
            break;

        case 'eliminar':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) jsonResponse(['ok' => false, 'mensaje' => 'ID inválido'], 400);
            if ($id === (int)$_SESSION['user_id']) jsonResponse(['ok' => false, 'mensaje' => 'No podés eliminarte'], 400);

            $stmt = $pdo->prepare("SELECT institucion_id FROM usuarios WHERE id = ?");
            $stmt->execute([$id]);
            $uInst = $stmt->fetchColumn();
            if ((int)$uInst !== $institucionId) jsonResponse(['ok' => false, 'mensaje' => 'Usuario no pertenece a esta institución'], 403);

            UserModel::delete($pdo, $id);
            jsonResponse(['ok' => true]);
            break;

        default:
            jsonResponse(['ok' => false, 'mensaje' => 'Acción desconocida'], 400);
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('institucion_ajax ERROR: ' . $e->getMessage());
    respuestaErrorInterno($e, 'institucion_ajax');
}