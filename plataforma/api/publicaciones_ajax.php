<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Helpers.php';
require_once __DIR__ . '/../core/FileHandler.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

header('Content-Type: application/json; charset=utf-8');
Auth::checkRole(ROLES_DOCENTES);

$action = $_GET['action'] ?? '';

try {
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if (empty($_POST) && $contentLength > 0) {
        jsonResponse(['ok' => false, 'mensaje' => "⚠️ El archivo es demasiado grande (límite: " . UPLOAD_MAX_LABEL . ")."], 413);
    }

    switch ($action) {

        case 'crear':
            $materiaId    = (int)($_POST['materia_id'] ?? 0);
            $titulo       = trim($_POST['titulo'] ?? '');
            $contenido    = trim($_POST['contenido'] ?? '');
            $divisionId   = (int)($_POST['division_id'] ?? 0);
            $aplicarTodas = !empty($_POST['aplicar_a_todas']);
            $añoLectivo   = (int)($_POST['año_lectivo'] ?? date('Y'));
            $esRolInstitucional = in_array($_SESSION['rol'], ['directivo', 'secretario', 'madre']);
            $esInstitucional = !empty($_POST['institucional']) && $esRolInstitucional;
            $miInstSesion = (int)($_SESSION['institucion_id'] ?? 0);

            if ($titulo === '') {
                jsonResponse(['ok' => false, 'mensaje' => 'El título es obligatorio'], 400);
            }

            // Destino institucional
            $institucionDestino = null;
            $rolesDestino = [];

            if ($esInstitucional && $_SESSION['rol'] === 'madre') {
                $instDest = $_POST['institucion_destino_id'] ?? '';
                if ($instDest !== '' && $instDest !== '0' && $instDest !== 'todas') {
                    $institucionDestino = (int)$instDest;
                }
                $rolesPost = $_POST['roles_destino'] ?? [];
                if (is_array($rolesPost)) {
                    $rolesPermitidos = ['directivo', 'secretario', 'docente', 'alumno', 'familiar'];
                    foreach ($rolesPost as $r) {
                        if (in_array($r, $rolesPermitidos)) $rolesDestino[] = $r;
                    }
                }
            } elseif ($esInstitucional) {
                $institucionDestino = $miInstSesion;
                $rolesDestino = [];
            }

            if ($esInstitucional) {
                $materiaId = null;
                $divisionesIds = [];
            } else {
                if (!$materiaId) jsonResponse(['ok' => false, 'mensaje' => 'Seleccioná una materia'], 400);
                if ($divisionId <= 0 && !$aplicarTodas) jsonResponse(['ok' => false, 'mensaje' => 'Seleccioná una división o marcá "Publicar en todas las divisiones".'], 400);

                if ($aplicarTodas) {
                    if ($_SESSION['rol'] === 'docente') {
                        $stmt = $pdo->prepare("SELECT DISTINCT division_id FROM asignaciones_docente WHERE docente_id = ? AND materia_id = ?");
                        $stmt->execute([$_SESSION['user_id'], $materiaId]);
                    } else {
                        $stmt = $pdo->prepare("SELECT DISTINCT division_id FROM asignaciones_docente WHERE materia_id = ?");
                        $stmt->execute([$materiaId]);
                    }
                    $divisionesIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
                } elseif ($divisionId > 0) {
                    $divisionesIds = [$divisionId];
                }
                if (empty($divisionesIds)) jsonResponse(['ok' => false, 'mensaje' => 'No hay divisiones disponibles para esta materia'], 400);
            }

            $stmtAutor = $pdo->prepare("SELECT nombre, apellido FROM usuarios WHERE id = ?");
            $stmtAutor->execute([(int)$_SESSION['user_id']]);
            $autorData = $stmtAutor->fetch();
            $autorNombre = $autorData ? ($autorData['apellido'] . ', ' . $autorData['nombre']) : 'Docente';

            $archivoId = null;
            $archivoNombre = null;
            $vtEstado = 'ok';

            if (!empty($_FILES['archivo']['tmp_name'])) {
                $fh = new FileHandler($mongoArchivos);
                $r = $fh->procesar($_FILES['archivo'], 'documento');
                if ($r['estado'] === 'malicioso') jsonResponse(['ok' => false, 'mensaje' => '⚠️ Malware detectado.'], 400);
                if ($r['estado'] === 'error') jsonResponse(['ok' => false, 'mensaje' => $r['mensaje']], 400);
                $archivoId = new ObjectId($r['archivo_id']);
                $archivoNombre = $r['nombre'];
                $vtEstado = $r['estado'];
            }

            $mongoPublicaciones->insertOne([
                'titulo'          => $titulo,
                'contenido'       => $contenido,
                'institucional'   => $esInstitucional,
                'año_lectivo'     => $añoLectivo,
                'materia_id'      => $materiaId,
                'divisiones_ids'  => $divisionesIds,
                'institucion_destino_id' => $institucionDestino,
                'roles_destino'   => $rolesDestino,
                'autor_id'        => (int)$_SESSION['user_id'],
                'autor_nombre'    => $autorNombre,
                'archivo_id'      => $archivoId,
                'archivo_nombre'  => $archivoNombre,
                'vt_estado'       => $vtEstado,
                'fecha'           => new UTCDateTime(),
            ]);

            jsonResponse(['ok' => true]);
            break;

        case 'editar':
            $pubId      = $_POST['pub_id'] ?? '';
            $titulo     = trim($_POST['titulo'] ?? '');
            $contenido  = trim($_POST['contenido'] ?? '');
            $añoLectivo = (int)($_POST['año_lectivo'] ?? date('Y'));

            if (!$pubId) jsonResponse(['ok' => false, 'mensaje' => 'ID requerido'], 400);
            if ($titulo === '') jsonResponse(['ok' => false, 'mensaje' => 'El título es obligatorio'], 400);

            try { $pub = $mongoPublicaciones->findOne(['_id' => new ObjectId($pubId)]); }
            catch (Exception $e) { jsonResponse(['ok' => false, 'mensaje' => 'ID inválido'], 400); }
            if (!$pub) jsonResponse(['ok' => false, 'mensaje' => 'Publicación no encontrada'], 404);

            $esAdmin = in_array($_SESSION['rol'], ['directivo', 'secretario', 'madre']);
            $esDueno = (int)$pub['autor_id'] === (int)$_SESSION['user_id'];
            if (!$esAdmin && !$esDueno) jsonResponse(['ok' => false, 'mensaje' => 'Sin permiso'], 403);

            $mongoPublicaciones->updateOne(
                ['_id' => $pub['_id']],
                ['$set' => [
                    'titulo'        => $titulo,
                    'contenido'     => $contenido,
                    'año_lectivo'   => $añoLectivo,
                    'editado'       => true,
                    'fecha_edicion' => new UTCDateTime(),
                ]]
            );
            jsonResponse(['ok' => true]);
            break;

        case 'bibliografia':
            $materiaId  = (int)($_POST['materia_id'] ?? 0);
            $divisionId = (int)($_POST['division_id'] ?? 0);
            if (!$materiaId)  jsonResponse(['ok' => false, 'mensaje' => 'Seleccioná una materia'], 400);
            if (!$divisionId) jsonResponse(['ok' => false, 'mensaje' => 'Seleccioná una división'], 400);
            if (empty($_FILES['archivo']['tmp_name'])) jsonResponse(['ok' => false, 'mensaje' => 'Seleccioná un archivo'], 400);

            if ($_SESSION['rol'] === 'docente') {
                $stmt = $pdo->prepare("SELECT 1 FROM asignaciones_docente WHERE docente_id = ? AND materia_id = ?");
                $stmt->execute([$_SESSION['user_id'], $materiaId]);
                if (!$stmt->fetchColumn()) jsonResponse(['ok' => false, 'mensaje' => 'Sin acceso a la materia'], 403);
            }

            $fh = new FileHandler($mongoArchivos);
            $r = $fh->procesar($_FILES['archivo'], 'documento');
            if ($r['estado'] === 'malicioso') jsonResponse(['ok' => false, 'mensaje' => '⚠️ Malware.'], 400);
            if ($r['estado'] === 'error') jsonResponse(['ok' => false, 'mensaje' => $r['mensaje']], 400);

            $mongoBibliografia->insertOne([
                'materia_id'      => $materiaId,
                'division_id'     => $divisionId,
                'archivo_id'      => new ObjectId($r['archivo_id']),
                'nombre_original' => $r['nombre'],
                'vt_estado'       => $r['estado'],
                'subido_por'      => (int)$_SESSION['user_id'],
                'fecha'           => new UTCDateTime(),
            ]);

            jsonResponse(['ok' => true, 'vt_estado' => $r['estado']]);
            break;

        case 'eliminar_bibliografia':
            $bibId = $_POST['bib_id'] ?? '';
            if (!$bibId) jsonResponse(['ok' => false, 'mensaje' => 'ID requerido'], 400);

            try { $bib = $mongoBibliografia->findOne(['_id' => new ObjectId($bibId)]); } catch (Exception $e) { jsonResponse(['ok' => false, 'mensaje' => 'ID inválido'], 400); }
            if (!$bib) jsonResponse(['ok' => false, 'mensaje' => 'No encontrado'], 404);

            $esAdmin = in_array($_SESSION['rol'], ['directivo', 'secretario', 'madre']);
            $esDueno = (int)$bib['subido_por'] === (int)$_SESSION['user_id'];
            if (!$esAdmin && !$esDueno) jsonResponse(['ok' => false, 'mensaje' => 'Sin permiso'], 403);

            $mongoBibliografia->deleteOne(['_id' => $bib['_id']]);
            jsonResponse(['ok' => true]);
            break;

        case 'eliminar_publicacion':
            $pubId = $_POST['pub_id'] ?? '';
            if (!$pubId) jsonResponse(['ok' => false, 'mensaje' => 'ID requerido'], 400);

            try { $pub = $mongoPublicaciones->findOne(['_id' => new ObjectId($pubId)]); } catch (Exception $e) { jsonResponse(['ok' => false, 'mensaje' => 'ID inválido'], 400); }
            if (!$pub) jsonResponse(['ok' => false, 'mensaje' => 'No encontrada'], 404);

            $esAdmin = in_array($_SESSION['rol'], ['directivo', 'secretario', 'madre']);
            $esDueno = (int)$pub['autor_id'] === (int)$_SESSION['user_id'];
            if (!$esAdmin && !$esDueno) jsonResponse(['ok' => false, 'mensaje' => 'Sin permiso'], 403);

            $mongoPublicaciones->deleteOne(['_id' => $pub['_id']]);
            jsonResponse(['ok' => true]);
            break;

        default:
            jsonResponse(['ok' => false, 'mensaje' => 'Acción desconocida'], 400);
    }
} catch (Throwable $e) {
    error_log('publicaciones_ajax ERROR: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'mensaje' => 'Error del servidor'], 500);
}