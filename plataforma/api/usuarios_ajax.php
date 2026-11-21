<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Helpers.php';
require_once __DIR__ . '/../models/UserModel.php';
require_once __DIR__ . '/../models/AcademicModel.php';

header('Content-Type: application/json; charset=utf-8');
Auth::checkRole(ROLES_ADMIN);

$action = $_GET['action'] ?? '';
$esMadre = esMadre();
$userId = (int)$_SESSION['user_id'];

$userActual = UserModel::findById($pdo, $userId);
$miInstitucionId = $userActual ? (int)($userActual['institucion_id'] ?? 0) : 0;

if (!$esMadre && !$miInstitucionId) {
    jsonResponse(['ok' => false, 'mensaje' => 'Tu usuario no tiene institución asignada'], 403);
}

function puedeGestionar(PDO $pdo, int $targetId, bool $esMadre, int $miInstitucionId): bool {
    if ($esMadre) return true;
    $stmt = $pdo->prepare("SELECT institucion_id FROM usuarios WHERE id = ?");
    $stmt->execute([$targetId]);
    $inst = $stmt->fetchColumn();
    return $inst !== false && (int)$inst === $miInstitucionId;
}

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
            $institucionId = $esMadre ? (int)($_POST['institucion_id'] ?? 0) : $miInstitucionId;

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

            if ($institucionId > 0) {
                $pdo->prepare("UPDATE usuarios SET institucion_id = ? WHERE id = ?")->execute([$institucionId, $id]);
            }

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
                            throw new Exception("El DNI $dniFamiliar ya está registrado como {$familiar['rol']}");
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
                            $apellido, $dniFamiliar,
                            Auth::hashPassword('Temporal123'),
                            $institucionId > 0 ? $institucionId : null,
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
            if (!puedeGestionar($pdo, $id, $esMadre, $miInstitucionId)) {
                jsonResponse(['ok' => false, 'mensaje' => 'No podés gestionar usuarios de otra institución'], 403);
            }
            if ($nombre === '' || $apellido === '' || $dni === '') {
                jsonResponse(['ok' => false, 'mensaje' => 'Nombre, apellido y DNI son obligatorios'], 400);
            }
            if (!in_array($rol, ['alumno','docente','secretario','directivo','familiar'])) {
                jsonResponse(['ok' => false, 'mensaje' => 'Rol inválido'], 400);
            }
            $existente = UserModel::findByDni($pdo, $dni);
            if ($existente && (int)$existente['id'] !== $id) {
                jsonResponse(['ok' => false, 'mensaje' => 'Ese DNI ya está en uso'], 400);
            }

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
            if ($id === $userId) jsonResponse(['ok' => false, 'mensaje' => 'No podés eliminarte a vos mismo'], 400);
            if (!puedeGestionar($pdo, $id, $esMadre, $miInstitucionId)) {
                jsonResponse(['ok' => false, 'mensaje' => 'No podés eliminar usuarios de otra institución'], 403);
            }
            UserModel::delete($pdo, $id);
            jsonResponse(['ok' => true]);
            break;

        case 'transferir_alumnos':
            $alumnosIds = $_POST['alumnos_ids'] ?? [];
            $motivo = $_POST['motivo'] ?? 'promocion';
            $divisionDestinoId = (int)($_POST['division_destino_id'] ?? 0);
            $cicloDestinoId = (int)($_POST['ciclo_destino_id'] ?? 0);
            $fecha = $_POST['fecha'] ?? date('Y-m-d');

            if (!is_array($alumnosIds) || empty($alumnosIds)) {
                jsonResponse(['ok' => false, 'mensaje' => 'Seleccioná al menos un alumno'], 400);
            }
            if (!in_array($motivo, ['promocion', 'repite', 'transferencia'])) {
                jsonResponse(['ok' => false, 'mensaje' => 'Motivo inválido'], 400);
            }
            if (!$cicloDestinoId) jsonResponse(['ok' => false, 'mensaje' => 'Ciclo destino requerido'], 400);

            $ids = array_map('intval', $alumnosIds);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            if ($esMadre) {
                $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE id IN ($ph) AND rol = 'alumno'");
                $stmt->execute($ids);
            } else {
                $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE id IN ($ph) AND rol = 'alumno' AND institucion_id = ?");
                $stmt->execute(array_merge($ids, [$miInstitucionId]));
            }
            $validos = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            if (empty($validos)) jsonResponse(['ok' => false, 'mensaje' => 'Ningún alumno válido'], 400);

            $pdo->beginTransaction();
            try {
                $r = AcademicModel::transferirAlumnos($pdo, $validos, $motivo, $divisionDestinoId, $cicloDestinoId, $fecha);
                if (!$r['ok']) {
                    $pdo->rollBack();
                    jsonResponse(['ok' => false, 'mensaje' => $r['mensaje']], 400);
                }
                $pdo->commit();
                jsonResponse(['ok' => true, 'procesados' => $r['procesados']]);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        default:
            jsonResponse(['ok' => false, 'mensaje' => 'Acción desconocida'], 400);
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('usuarios_ajax ERROR: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'mensaje' => $e->getMessage()], 500);
}