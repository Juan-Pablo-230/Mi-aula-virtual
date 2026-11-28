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
// Institución activa de la sesión (si trabaja en varias, la que eligió)
$miInstitucionId = instActivaId();

if (!$esMadre && !$miInstitucionId) {
    jsonResponse(['ok' => false, 'mensaje' => 'Tu usuario no tiene institución asignada'], 403);
}

function puedeGestionar(PDO $pdo, int $targetId, bool $esMadre, int $miInstitucionId): bool {
    if ($esMadre) return true;
    $stmt = $pdo->prepare("SELECT institucion_id FROM usuarios WHERE id = ?");
    $stmt->execute([$targetId]);
    $inst = $stmt->fetchColumn();
    if ($inst !== false && (int)$inst === $miInstitucionId) return true;
    // Personal de otra institución que también trabaja en la mía
    return $miInstitucionId > 0 && Membresias::esSecundaria($pdo, $targetId, $miInstitucionId);
}

/** Guarda las asignaciones docente (materia-división) del formulario, solo de la institución dada. */
function guardarAsignacionesDocenteForm(PDO $pdo, int $docenteId, int $instId): void {
    $asignaciones = $_POST['asignaciones'] ?? [];
    if (!is_array($asignaciones)) return;
    $ins = $pdo->prepare("INSERT IGNORE INTO asignaciones_docente (docente_id, materia_id, division_id) VALUES (?, ?, ?)");
    foreach ($asignaciones as $par) {
        [$materiaId, $divisionId] = array_map('intval', explode('-', (string)$par));
        if ($materiaId <= 0 || $divisionId <= 0) continue;
        if ($instId > 0 && !divisionEsDeInstitucion($pdo, $divisionId, $instId)) continue;
        if (!esMateriaCompatible($pdo, $materiaId, $divisionId)) continue;
        $ins->execute([$docenteId, $materiaId, $divisionId]);
    }
}

/** La división pertenece a la institución (0 = sin división, siempre válido). */
function divisionEsDeInstitucion(PDO $pdo, int $divisionId, int $instId): bool {
    if ($divisionId <= 0) return true;
    $st = $pdo->prepare("SELECT 1 FROM divisiones d JOIN asignaciones a ON a.id = d.asignacion_id WHERE d.id = ? AND a.institucion_id = ?");
    $st->execute([$divisionId, $instId]);
    return (bool)$st->fetchColumn();
}

/**
 * Guarda la cursada del alumno en una institución según el formulario:
 * división completa, o (solo_materias) solo las materias tildadas dentro de esa división.
 */
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
            if (!dniValido($dni)) {
                jsonResponse(['ok' => false, 'mensaje' => 'DNI inválido: solo números (6 a 12 dígitos)'], 400);
            }
            if (!in_array($rol, ['alumno','docente','secretario','directivo','familiar'])) {
                jsonResponse(['ok' => false, 'mensaje' => 'Rol inválido'], 400);
            }
            $yaExiste = UserModel::findByDni($pdo, $dni);
            if ($yaExiste) {
                $instExistente = (int)($yaExiste['institucion_id'] ?? 0);
                if ($yaExiste['rol'] === 'alumno' && $instExistente > 0 && $instExistente !== $miInstitucionId) {
                    $st = $pdo->prepare("SELECT nombre FROM instituciones WHERE id = ?");
                    $st->execute([$instExistente]);
                    $nombreInst = (string)$st->fetchColumn();
                    if ($esMadre) {
                        jsonResponse(['ok' => false, 'mensaje' => "Ya existe un alumno con ese DNI, registrado en «$nombreInst». Podés pasarlo de institución desde la lista de usuarios de esa institución."], 400);
                    }
                    $st = $pdo->prepare("SELECT COUNT(*) FROM solicitudes_transferencia WHERE alumno_id = ? AND institucion_destino_id = ? AND estado = 'pendiente'");
                    $st->execute([(int)$yaExiste['id'], $miInstitucionId]);
                    jsonResponse([
                        'ok' => false,
                        'codigo' => 'otra_institucion',
                        'mensaje' => "Ese DNI ya está registrado como alumno en «$nombreInst».",
                        'alumno_id' => (int)$yaExiste['id'],
                        'alumno' => nombreCompleto($yaExiste),
                        'institucion' => $nombreInst,
                        'solicitud_pendiente' => (int)$st->fetchColumn() > 0,
                    ], 409);
                }
                // Personal que ya trabaja en otra institución: se lo puede sumar a esta con el rol elegido
                if (Membresias::esPersonal($yaExiste['rol']) && Membresias::esPersonal($rol) && $instExistente > 0) {
                    if ($esMadre) jsonResponse(['ok' => false, 'mensaje' => 'Ya existe personal con ese DNI. Se lo suma a una institución desde la sesión de esa institución.'], 400);
                    if (Membresias::rolEn($pdo, (int)$yaExiste['id'], $miInstitucionId) !== null) {
                        jsonResponse(['ok' => false, 'mensaje' => 'Esa persona ya trabaja en tu institución'], 400);
                    }
                    $otras = array_values(array_filter(Membresias::contextos($pdo, (int)$yaExiste['id']), fn($c) => $c['institucion_id'] !== $miInstitucionId));
                    if (($_POST['agregar_personal'] ?? '') !== '1') {
                        jsonResponse([
                            'ok' => false, 'codigo' => 'personal_otra_institucion',
                            'mensaje' => 'Ese DNI ya corresponde a personal de otra institución.',
                            'persona' => nombreCompleto($yaExiste),
                            'instituciones' => array_map(fn($c) => ['nombre' => $c['nombre'], 'rol' => $c['rol']], $otras),
                        ], 409);
                    }
                    $pdo->beginTransaction();
                    Membresias::agregar($pdo, (int)$yaExiste['id'], $miInstitucionId, $rol);
                    if ($rol === 'docente') guardarAsignacionesDocenteForm($pdo, (int)$yaExiste['id'], $miInstitucionId);
                    $pdo->commit();
                    jsonResponse(['ok' => true, 'id' => (int)$yaExiste['id'], 'agregado' => true]);
                }
                if ($instExistente > 0 && $instExistente !== $miInstitucionId) {
                    jsonResponse(['ok' => false, 'mensaje' => 'Ya existe un usuario con ese DNI en otra institución'], 400);
                }
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
                if ($institucionId > 0 && !divisionEsDeInstitucion($pdo, $divisionId, $institucionId)) {
                    throw new MensajeUsuarioException('La división elegida no pertenece a la institución');
                }
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
                        if ($miInstitucionId > 0 && !divisionEsDeInstitucion($pdo, $divisionId, $miInstitucionId)) continue;
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
            // Solo el directivo puede cambiar nombre, apellido y DNI: para el resto se conservan los datos guardados
            if ((Auth::currentUser($pdo)['rol'] ?? '') !== 'directivo') {
                $stIdent = $pdo->prepare("SELECT nombre, apellido, dni FROM usuarios WHERE id = ?");
                $stIdent->execute([$id]);
                $ident = $stIdent->fetch();
                if (!$ident) jsonResponse(['ok' => false, 'mensaje' => 'Usuario no encontrado'], 404);
                $nombre = (string)($ident['nombre'] ?? ''); $apellido = (string)$ident['apellido']; $dni = (string)$ident['dni'];
            }
            // El rol no se puede cambiar al editar: se toma siempre el que ya tiene el usuario
            if ($esMadre) {
                $stRol = $pdo->prepare("SELECT rol FROM usuarios WHERE id = ?");
                $stRol->execute([(int)$id]);
                $rolActual = (string)$stRol->fetchColumn();
            } else {
                $rolActual = (string)Membresias::rolEn($pdo, (int)$id, $miInstitucionId); // rol en ESTA institución
            }
            $esSecundario = !$esMadre && Membresias::esSecundaria($pdo, (int)$id, $miInstitucionId);
            if ($rolActual === '' || $rolActual === 'madre') jsonResponse(['ok' => false, 'mensaje' => 'Usuario inválido'], 400);
            $rol = $rolActual;

            if (!$id) jsonResponse(['ok' => false, 'mensaje' => 'ID inválido'], 400);
            if (!puedeGestionar($pdo, $id, $esMadre, $miInstitucionId)) {
                jsonResponse(['ok' => false, 'mensaje' => 'No podés gestionar usuarios de otra institución'], 403);
            }
            if (($nombre === '' && $rol !== 'familiar') || $apellido === '' || $dni === '') {
                jsonResponse(['ok' => false, 'mensaje' => 'Nombre, apellido y DNI son obligatorios'], 400);
            }
            if (!dniValido($dni)) {
                jsonResponse(['ok' => false, 'mensaje' => 'DNI inválido: solo números (6 a 12 dígitos)'], 400);
            }
            if (!in_array($rol, ['alumno','docente','secretario','directivo','familiar'])) {
                jsonResponse(['ok' => false, 'mensaje' => 'Rol inválido'], 400);
            }
            $existente = UserModel::findByDni($pdo, $dni);
            if ($existente && (int)$existente['id'] !== $id) {
                jsonResponse(['ok' => false, 'mensaje' => 'Ese DNI ya está en uso'], 400);
            }

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

            $instDelUsuario = $esSecundario ? $miInstitucionId : (int)($pdo->query("SELECT institucion_id FROM usuarios WHERE id = " . (int)$id)->fetchColumn());
            if ($rol !== 'alumno') AcademicModel::asignarDivisionAlumno($pdo, (int)$id, 0, $instDelUsuario);
            AcademicModel::borrarAsignacionesDocente($pdo, (int)$id, $esMadre ? 0 : $miInstitucionId);
            $pdo->prepare("DELETE FROM familiares_alumnos WHERE alumno_id = ?")->execute([$id]);

            if ($rol === 'alumno') {
                $divisionId = (int)($_POST['division_id'] ?? 0);
                if ($instDelUsuario > 0 && !divisionEsDeInstitucion($pdo, $divisionId, $instDelUsuario)) {
                    throw new MensajeUsuarioException('La división elegida no pertenece a la institución del alumno');
                }
                aplicarCursadaAlumno($pdo, (int)$id, $divisionId, $instDelUsuario);
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
                        if ($miInstitucionId > 0 && !divisionEsDeInstitucion($pdo, $divisionId, $miInstitucionId)) continue;
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
            if (!$esMadre && Membresias::esSecundaria($pdo, $id, $miInstitucionId)) {
                Membresias::quitar($pdo, $id, $miInstitucionId); // solo deja de trabajar acá
                jsonResponse(['ok' => true, 'quitado' => true]);
            }
            if (!$esMadre && Membresias::promoverSiTieneOtras($pdo, $id, $miInstitucionId)) {
                jsonResponse(['ok' => true, 'quitado' => true]); // trabaja en otra institución: no se borra la cuenta
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

        case 'solicitar_transferencia':
            if ($esMadre) jsonResponse(['ok' => false, 'mensaje' => 'La madre transfiere directamente, sin solicitud'], 400);
            $alumnoId = (int)($_POST['alumno_id'] ?? 0);
            $nota = mb_substr(trim($_POST['nota'] ?? ''), 0, 300);
            $st = $pdo->prepare("SELECT institucion_id FROM usuarios WHERE id = ? AND rol = 'alumno'");
            $st->execute([$alumnoId]);
            $origenId = $st->fetchColumn();
            if ($origenId === false) jsonResponse(['ok' => false, 'mensaje' => 'Alumno no encontrado'], 404);
            $origenId = (int)$origenId;
            if ($origenId <= 0) jsonResponse(['ok' => false, 'mensaje' => 'El alumno no pertenece a ninguna institución'], 400);
            if ($origenId === $miInstitucionId) jsonResponse(['ok' => false, 'mensaje' => 'El alumno ya pertenece a tu institución'], 400);
            $st = $pdo->prepare("SELECT COUNT(*) FROM solicitudes_transferencia WHERE alumno_id = ? AND institucion_destino_id = ? AND estado = 'pendiente'");
            $st->execute([$alumnoId, $miInstitucionId]);
            if ((int)$st->fetchColumn() > 0) jsonResponse(['ok' => false, 'mensaje' => 'Ya enviaste una solicitud pendiente para este alumno'], 400);
            $pdo->prepare("INSERT INTO solicitudes_transferencia (alumno_id, institucion_origen_id, institucion_destino_id, solicitada_por, nota) VALUES (?, ?, ?, ?, ?)")
                ->execute([$alumnoId, $origenId, $miInstitucionId, $userId, $nota !== '' ? $nota : null]);
            jsonResponse(['ok' => true]);
            break;

        case 'resolver_solicitud':
        case 'cancelar_solicitud':
            if ($esMadre) jsonResponse(['ok' => false, 'mensaje' => 'Acción no disponible para este rol'], 403);
            $solId = (int)($_POST['id'] ?? 0);
            $decision = $_POST['decision'] ?? '';
            $esCancelar = $action === 'cancelar_solicitud';
            if (!$esCancelar && !in_array($decision, ['aprobar', 'denegar'], true)) {
                jsonResponse(['ok' => false, 'mensaje' => 'Decisión inválida'], 400);
            }
            $pdo->beginTransaction();
            $st = $pdo->prepare("SELECT * FROM solicitudes_transferencia WHERE id = ? FOR UPDATE");
            $st->execute([$solId]);
            $sol = $st->fetch();
            if (!$sol || $sol['estado'] !== 'pendiente') {
                $pdo->rollBack();
                jsonResponse(['ok' => false, 'mensaje' => 'La solicitud no existe o ya fue resuelta'], 400);
            }
            if ($esCancelar) {
                if ((int)$sol['institucion_destino_id'] !== $miInstitucionId) { $pdo->rollBack(); jsonResponse(['ok' => false, 'mensaje' => 'Sin permiso'], 403); }
                $pdo->prepare("UPDATE solicitudes_transferencia SET estado = 'cancelada', resuelta_por = ?, resuelta_en = NOW() WHERE id = ?")->execute([$userId, $solId]);
                $pdo->commit();
                jsonResponse(['ok' => true]);
            }
            // Solo decide la institución donde está hoy el alumno
            $st = $pdo->prepare("SELECT institucion_id FROM usuarios WHERE id = ?");
            $st->execute([(int)$sol['alumno_id']]);
            $instActual = (int)$st->fetchColumn();
            if ($instActual !== $miInstitucionId) {
                $pdo->rollBack();
                jsonResponse(['ok' => false, 'mensaje' => 'El alumno ya no pertenece a tu institución'], 403);
            }
            if ($decision === 'denegar') {
                $pdo->prepare("UPDATE solicitudes_transferencia SET estado = 'denegada', resuelta_por = ?, resuelta_en = NOW() WHERE id = ?")->execute([$userId, $solId]);
                $pdo->commit();
                jsonResponse(['ok' => true]);
            }
            $pdo->prepare("UPDATE solicitudes_transferencia SET estado = 'aprobada', resuelta_por = ?, resuelta_en = NOW() WHERE id = ?")->execute([$userId, $solId]);
            AcademicModel::moverAlumnoAInstitucion($pdo, (int)$sol['alumno_id'], (int)$sol['institucion_destino_id']);
            $pdo->commit();
            jsonResponse(['ok' => true]);
            break;

        default:
            jsonResponse(['ok' => false, 'mensaje' => 'Acción desconocida'], 400);
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('usuarios_ajax ERROR: ' . $e->getMessage());
    respuestaErrorInterno($e, 'usuarios_ajax');
}