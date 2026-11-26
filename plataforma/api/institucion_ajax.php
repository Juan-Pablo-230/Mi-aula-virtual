<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Helpers.php';
require_once __DIR__ . '/../models/UserModel.php';
require_once __DIR__ . '/../models/AcademicModel.php';

header('Content-Type: application/json; charset=utf-8');
Auth::checkRole(['madre']);

$institucionId = (int)($_GET['id'] ?? 0);
if (!$institucionId) jsonResponse(['ok' => false, 'mensaje' => 'Institución requerida'], 400);

$stmt = $pdo->prepare("SELECT * FROM instituciones WHERE id = ?");
$stmt->execute([$institucionId]);
if (!$stmt->fetch()) jsonResponse(['ok' => false, 'mensaje' => 'Institución no existe'], 404);

$action = $_GET['action'] ?? '';

/** Guarda las asignaciones docente (materia-división) del formulario, solo de esa institución. */
function guardarAsignacionesDocenteForm(PDO $pdo, int $docenteId, int $instId): void {
    $asignaciones = $_POST['asignaciones'] ?? [];
    if (!is_array($asignaciones)) return;
    $ins = $pdo->prepare("INSERT IGNORE INTO asignaciones_docente (docente_id, materia_id, division_id) VALUES (?, ?, ?)");
    foreach ($asignaciones as $par) {
        [$materiaId, $divisionId] = array_map('intval', explode('-', (string)$par));
        if ($materiaId <= 0 || $divisionId <= 0) continue;
        $st = $pdo->prepare("SELECT 1 FROM divisiones d JOIN asignaciones a ON a.id = d.asignacion_id WHERE d.id = ? AND a.institucion_id = ?");
        $st->execute([$divisionId, $instId]);
        if (!$st->fetchColumn()) continue;
        if (!esMateriaCompatible($pdo, $materiaId, $divisionId)) continue;
        $ins->execute([$docenteId, $materiaId, $divisionId]);
    }
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

/** Cursada del alumno en esta institución: división completa o solo las materias elegidas. */
function aplicarCursadaAlumno(PDO $pdo, int $alumnoId, int $divisionId, int $instId): void {
    // Formulario nuevo: lista de cursadas (varias divisiones a la vez, con cursadas completas y parciales)
    if (isset($_POST['cursadas_json'])) {
        $lista = json_decode((string)$_POST['cursadas_json'], true);
        if (!is_array($lista)) throw new MensajeUsuarioException('Las cursadas del alumno no son válidas');
        AcademicModel::guardarCursadas($pdo, $alumnoId, $instId, $lista);
        return;
    }
    $solo = !empty($_POST['solo_materias']) && $divisionId > 0;
    if (!$solo) {
        AcademicModel::asignarDivisionAlumno($pdo, $alumnoId, $divisionId, $instId);
        return;
    }
    $materias = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['materias_ids'] ?? [])))));
    if (!$materias) throw new MensajeUsuarioException('Elegí al menos una materia para la cursada parcial');
    foreach ($materias as $mid) {
        if (!esMateriaCompatible($pdo, $mid, $divisionId)) {
            throw new MensajeUsuarioException('Una de las materias elegidas no corresponde a esa división');
        }
    }
    AcademicModel::asignarCursadaParcial($pdo, $alumnoId, $divisionId, $materias, $instId, !empty($_POST['sumar_nuevas']));
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
            if (!dniValido($dni)) {
                jsonResponse(['ok' => false, 'mensaje' => 'DNI inválido'], 400);
            }
            if (!in_array($rol, ['alumno','docente','secretario','directivo','familiar'])) {
                jsonResponse(['ok' => false, 'mensaje' => 'Rol inválido'], 400);
            }
            $yaExiste = UserModel::findByDni($pdo, $dni);
            if ($yaExiste) {
                $msg = 'Ya existe un usuario con ese DNI';
                $instEx = (int)($yaExiste['institucion_id'] ?? 0);
                if ($yaExiste['rol'] === 'alumno' && $instEx > 0 && $instEx !== $institucionId) {
                    $st = $pdo->prepare("SELECT nombre FROM instituciones WHERE id = ?");
                    $st->execute([$instEx]);
                    $msg = 'Ese alumno ya está registrado en «' . $st->fetchColumn() . '». Usá «Pasar a otra institución» en la lista de esa institución.';
                }
                // Personal que ya trabaja en otra institución: se lo suma a esta con el rol elegido
                if (Membresias::esPersonal($yaExiste['rol']) && Membresias::esPersonal($rol) && $instEx > 0 && $instEx !== $institucionId) {
                    if (Membresias::rolEn($pdo, (int)$yaExiste['id'], $institucionId) !== null) {
                        jsonResponse(['ok' => false, 'mensaje' => 'Esa persona ya trabaja en esta institución'], 400);
                    }
                    $otras = array_values(array_filter(Membresias::contextos($pdo, (int)$yaExiste['id']), fn($c) => $c['institucion_id'] !== $institucionId));
                    if (($_POST['agregar_personal'] ?? '') !== '1') {
                        jsonResponse([
                            'ok' => false, 'codigo' => 'personal_otra_institucion',
                            'mensaje' => 'Ese DNI ya corresponde a personal de otra institución.',
                            'persona' => nombreCompleto($yaExiste),
                            'instituciones' => array_map(fn($c) => ['nombre' => $c['nombre'], 'rol' => $c['rol']], $otras),
                        ], 409);
                    }
                    $pdo->beginTransaction();
                    Membresias::agregar($pdo, (int)$yaExiste['id'], $institucionId, $rol);
                    if ($rol === 'docente') guardarAsignacionesDocenteForm($pdo, (int)$yaExiste['id'], $institucionId);
                    $pdo->commit();
                    jsonResponse(['ok' => true, 'id' => (int)$yaExiste['id'], 'agregado' => true]);
                }
                jsonResponse(['ok' => false, 'mensaje' => $msg], 400);
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
                aplicarCursadaAlumno($pdo, (int)$id, $divisionId, $institucionId);
                $dniFamiliar = trim($_POST['dni_familiar'] ?? '');
                if ($dniFamiliar !== '' && !dniValido($dniFamiliar)) {
                    throw new MensajeUsuarioException('DNI del familiar inválido: solo números (6 a 12 dígitos)');
                }
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
                            VALUES (NULL, ?, ?, NULL, ?, 'familiar', 1, ?)
                        ");
                        $stmt->execute([
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
            // El rol no se puede cambiar al editar: se toma siempre el que ya tiene el usuario
            $rolActual = (string)Membresias::rolEn($pdo, (int)$id, $institucionId); // rol en ESTA institución
            $esSecundario = Membresias::esSecundaria($pdo, (int)$id, $institucionId);
            if ($rolActual === '' || $rolActual === 'madre') jsonResponse(['ok' => false, 'mensaje' => 'Usuario inválido'], 400);
            $rol = $rolActual;

            if (!$id) jsonResponse(['ok' => false, 'mensaje' => 'ID inválido'], 400);
            if (($nombre === '' && $rol !== 'familiar') || $apellido === '' || $dni === '') jsonResponse(['ok' => false, 'mensaje' => 'Faltan datos'], 400);
            if (!dniValido($dni)) jsonResponse(['ok' => false, 'mensaje' => 'DNI inválido: solo números (6 a 12 dígitos)'], 400);
            if (!in_array($rol, ['alumno','docente','secretario','directivo','familiar'])) jsonResponse(['ok' => false, 'mensaje' => 'Rol inválido'], 400);

            $stmt = $pdo->prepare("SELECT institucion_id FROM usuarios WHERE id = ?");
            $stmt->execute([$id]);
            $uInst = $stmt->fetchColumn();
            if ((int)$uInst !== $institucionId && !$esSecundario) jsonResponse(['ok' => false, 'mensaje' => 'Usuario no pertenece a esta institución'], 403);

            $existente = UserModel::findByDni($pdo, $dni);
            if ($existente && (int)$existente['id'] !== $id) jsonResponse(['ok' => false, 'mensaje' => 'Ese DNI ya está en uso'], 400);

            $pdo->beginTransaction();

            // Los datos personales los gestiona su institución principal: desde otra solo se editan sus asignaciones
            if (!$esSecundario) {
                UserModel::update($pdo, $id, [
                    'nombre'   => $nombre !== '' ? $nombre : null,
                    'apellido' => $apellido,
                    'dni'      => $dni,
                    'email'    => $email !== '' ? $email : null,
                    'rol'      => $rol,
                    'activo'   => (int)$activo
                ]);
            }

            if ($rol !== 'alumno') AcademicModel::asignarDivisionAlumno($pdo, (int)$id, 0, $institucionId);
            AcademicModel::borrarAsignacionesDocente($pdo, (int)$id, $institucionId);
            $pdo->prepare("DELETE FROM familiares_alumnos WHERE alumno_id = ?")->execute([$id]);

            if ($rol === 'alumno') {
                $divisionId = (int)($_POST['division_id'] ?? 0);
                aplicarCursadaAlumno($pdo, (int)$id, $divisionId, $institucionId);
                $dniFamiliar = trim($_POST['dni_familiar'] ?? '');
                if ($dniFamiliar !== '' && !dniValido($dniFamiliar)) {
                    throw new MensajeUsuarioException('DNI del familiar inválido: solo números (6 a 12 dígitos)');
                }
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

        case 'transferir_institucion':
            // La madre pasa alumnos a otra institución sin pedir autorización
            $destinoId = (int)($_POST['destino_id'] ?? 0);
            $ids = array_values(array_filter(array_map('intval', (array)($_POST['alumnos_ids'] ?? []))));
            if (!$ids) jsonResponse(['ok' => false, 'mensaje' => 'Seleccioná al menos un alumno'], 400);
            $st = $pdo->prepare("SELECT COUNT(*) FROM instituciones WHERE id = ?");
            $st->execute([$destinoId]);
            if (!$st->fetchColumn()) jsonResponse(['ok' => false, 'mensaje' => 'Institución destino inválida'], 400);
            if ($destinoId === $institucionId) jsonResponse(['ok' => false, 'mensaje' => 'El destino es la misma institución'], 400);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT id FROM usuarios WHERE id IN ($ph) AND rol = 'alumno' AND institucion_id = ?");
            $st->execute(array_merge($ids, [$institucionId]));
            $validos = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            if (!$validos) jsonResponse(['ok' => false, 'mensaje' => 'Ningún alumno válido'], 400);
            $pdo->beginTransaction();
            foreach ($validos as $aid) AcademicModel::moverAlumnoAInstitucion($pdo, $aid, $destinoId);
            $pdo->commit();
            jsonResponse(['ok' => true, 'procesados' => count($validos)]);
            break;

        case 'eliminar':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) jsonResponse(['ok' => false, 'mensaje' => 'ID inválido'], 400);
            if ($id === (int)$_SESSION['user_id']) jsonResponse(['ok' => false, 'mensaje' => 'No podés eliminarte'], 400);

            $stmt = $pdo->prepare("SELECT institucion_id FROM usuarios WHERE id = ?");
            $stmt->execute([$id]);
            $uInst = $stmt->fetchColumn();
            if ((int)$uInst !== $institucionId) {
                if (Membresias::esSecundaria($pdo, $id, $institucionId)) {
                    Membresias::quitar($pdo, $id, $institucionId); // solo deja de trabajar acá
                    jsonResponse(['ok' => true, 'quitado' => true]);
                }
                jsonResponse(['ok' => false, 'mensaje' => 'Usuario no pertenece a esta institución'], 403);
            }
            if (Membresias::promoverSiTieneOtras($pdo, $id, $institucionId)) {
                jsonResponse(['ok' => true, 'quitado' => true]); // trabaja en otra institución: no se borra la cuenta
            }
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