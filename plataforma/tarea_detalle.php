<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Helpers.php';
require_once __DIR__ . '/models/AcademicModel.php';

Auth::checkRole(ROLES_ALL);
$user = Auth::currentUser($pdo);
$rol  = $user['rol'];
$miInst = (int)($user['institucion_id'] ?? 0);

$cicloActivo = $miInst ? AcademicModel::cicloActivo($pdo, $miInst) : null;
$añoActual = $cicloActivo ? (int)$cicloActivo['año'] : (int)date('Y');
$añosDisponibles = [$añoActual];
if ($miInst) {
    $stmt = $pdo->prepare("SELECT DISTINCT año FROM ciclos_lectivos WHERE institucion_id = ? ORDER BY año DESC");
    $stmt->execute([$miInst]);
    $añosDisponibles = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if (empty($añosDisponibles)) $añosDisponibles = [$añoActual];
}

$esNueva  = isset($_GET['nueva']);
$esEditar = isset($_GET['editar']);
$id       = $_GET['id'] ?? '';

if ($esNueva && esDocenteOAdmin()) {
    if ($rol === 'docente') {
        $stmt = $pdo->prepare("
            SELECT DISTINCT m.id AS materia_id, m.nombre AS materia, d.id AS division_id, d.nombre AS division, a.nombre AS asignacion
            FROM asignaciones_docente ad
            JOIN materias m ON m.id = ad.materia_id
            JOIN divisiones d ON d.id = ad.division_id
            JOIN asignaciones a ON a.id = d.asignacion_id
            WHERE ad.docente_id = ? ORDER BY m.nombre, a.nombre, d.nombre
        ");
        $stmt->execute([$user['id']]);
    } else {
        $stmt = $pdo->prepare("
            SELECT DISTINCT m.id AS materia_id, m.nombre AS materia, d.id AS division_id, d.nombre AS division, a.nombre AS asignacion
            FROM materias m CROSS JOIN divisiones d JOIN asignaciones a ON a.id = d.asignacion_id
            WHERE m.institucion_id = ? AND a.institucion_id = ?
              AND EXISTS (SELECT 1 FROM asignaciones_docente ad WHERE ad.materia_id = m.id AND ad.division_id = d.id)
            ORDER BY m.nombre, a.nombre, d.nombre
        ");
        $stmt->execute([$miInst, $miInst]);
    }
    $opciones = $stmt->fetchAll();

    // Cursos por materia
    $materiasCursos = [];
    if (!empty($opciones)) {
        $matIds = array_unique(array_map(fn($o) => (int)$o['materia_id'], $opciones));
        $ph = implode(',', array_fill(0, count($matIds), '?'));
        $stmt = $pdo->prepare("SELECT materia_id, asignacion_id FROM materias_asignaciones WHERE materia_id IN ($ph)");
        $stmt->execute($matIds);
        foreach ($stmt as $row) {
            $materiasCursos[(int)$row['materia_id']][] = (int)$row['asignacion_id'];
        }
    }

    // Divisiones por curso
    $divisionesPorCurso = [];
    $stmt = $pdo->query("
        SELECT d.id, d.nombre AS division, a.nombre AS curso, a.id AS asignacion_id
        FROM divisiones d JOIN asignaciones a ON a.id = d.asignacion_id
        ORDER BY a.nombre, d.nombre
    ");
    foreach ($stmt as $row) $divisionesPorCurso[] = $row;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $titulo = trim($_POST['titulo'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $materiaId = (int)($_POST['materia_id'] ?? 0);
        $divisionId = (int)($_POST['division_id'] ?? 0);
        $fechaVenc = $_POST['fecha_vencimiento'] ?? '';
        $tipoEntrega = $_POST['tipo_entrega'] ?? '';
        $aplicarTodas = !empty($_POST['aplicar_a_todas']);
        $añoLectivo = (int)($_POST['año_lectivo'] ?? $añoActual);

        $error = '';
        if ($titulo === '') $error = 'El título es obligatorio.';
        elseif (!$materiaId) $error = 'Seleccioná una materia.';
        elseif (!$divisionId && !$aplicarTodas) $error = 'Seleccioná una división o marcá "todas".';
        elseif ($fechaVenc === '') $error = 'La fecha es obligatoria.';
        elseif (!in_array($tipoEntrega, ['documento', 'multimedia'])) $error = 'Seleccioná el tipo de entrega.';

        if (!$error) {
            if ($rol === 'docente') {
                $stmt = $pdo->prepare("SELECT 1 FROM asignaciones_docente WHERE docente_id = ? AND materia_id = ?");
                $stmt->execute([$user['id'], $materiaId]);
                if (!$stmt->fetchColumn()) $error = 'No tenés asignada esa materia.';
            }
            if (!$error) {
                $yaExiste = $mongoDB->selectCollection('tareas')->findOne(['titulo' => $titulo, 'docente_id' => (int)$user['id'], 'materia_id' => $materiaId, 'divisiones_ids' => $divisionId, 'año_lectivo' => $añoLectivo]);
                if ($yaExiste) $error = 'Ya existe una tarea con ese título.';
            }
            if (!$error) {
                try {
                    $dt = new DateTime($fechaVenc, new DateTimeZone('America/Argentina/Buenos_Aires'));
                    $fechaMongo = new MongoDB\BSON\UTCDateTime($dt);
                } catch (Exception $e) { $error = 'Fecha inválida.'; }

                $archivoId = null; $archivoNombre = null; $vtEstado = 'ok';
                if (!$error && !empty($_FILES['archivo_tarea']['tmp_name'])) {
                    require_once __DIR__ . '/core/FileHandler.php';
                    $fh = new FileHandler($mongoArchivos);
                    $r = $fh->procesar($_FILES['archivo_tarea'], 'documento');
                    if ($r['estado'] === 'malicioso') $error = '⚠️ Malware detectado.';
                    elseif ($r['estado'] === 'error') $error = 'Adjunto: ' . $r['mensaje'];
                    else { $archivoId = new MongoDB\BSON\ObjectId($r['archivo_id']); $archivoNombre = $r['nombre']; $vtEstado = $r['estado']; }
                }

                $divisionesIds = [];
                if ($aplicarTodas && $rol === 'docente') {
                    $stmt = $pdo->prepare("SELECT DISTINCT division_id FROM asignaciones_docente WHERE docente_id = ? AND materia_id = ?");
                    $stmt->execute([$user['id'], $materiaId]);
                    $divisionesIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
                } else $divisionesIds = [$divisionId];

                if (empty($divisionesIds)) $error = 'No hay divisiones.';

                if (!$error) {
                    $mongoDB->selectCollection('tareas')->insertOne([
                        'titulo' => $titulo, 'descripcion' => $descripcion, 'docente_id' => (int)$user['id'],
                        'materia_id' => $materiaId, 'divisiones_ids' => $divisionesIds,
                        'año_lectivo' => $añoLectivo,
                        'fecha_vencimiento' => $fechaMongo,
                        'permite_entrega' => true, 'tipo_entrega' => $tipoEntrega, 'archivo_id' => $archivoId,
                        'archivo_nombre' => $archivoNombre, 'vt_estado' => $vtEstado, 'fecha_creacion' => new MongoDB\BSON\UTCDateTime(),
                    ]);
                    redirect('/plataforma/tareas.php');
                }
            }
        }
    }
    ?>
    <?php include __DIR__ . '/views/header.php'; ?>
    <?php include __DIR__ . '/views/menu.php'; ?>
    <main class="contenido-principal">
        <h1>➕ Nueva tarea</h1>
        <?php if (!empty($error)): ?><div class="alerta danger"><?= h($error) ?></div><?php endif; ?>
        <?php if (empty($opciones)): ?>
            <div class="alerta warn">No hay combinaciones disponibles.</div>
        <?php else: ?>
        <form method="post" enctype="multipart/form-data" class="panel-vidrio">
            <label>Título <input type="text" name="titulo" required maxlength="200"></label>
            <label>Descripción <textarea name="descripcion" rows="4"></textarea></label>
            <label>Materia
                <select name="materia_id" id="tareaMateria" required onchange="actualizarDivisionesTarea()">
                    <option value="">— Seleccione una materia —</option>
                    <?php $mats = []; foreach ($opciones as $o) { if (!isset($mats[$o['materia_id']])) { $mats[$o['materia_id']] = 1; echo '<option value="'.(int)$o['materia_id'].'" data-cursos="'.implode(',', $materiasCursos[(int)$o['materia_id']] ?? []).'">'.h($o['materia']).'</option>'; } } ?>
                </select>
            </label>
            <label>División
                <select name="division_id" id="tareaDivision" required>
                    <option value="">— Elegí una materia primero —</option>
                </select>
            </label>
            <?php if ($rol === 'docente' && count($opciones) > 1): ?>
                <label class="label-checkbox">
                    <input type="checkbox" name="aplicar_a_todas" value="1" id="chkAplicarTodasTarea" onchange="var sel=document.getElementById('tareaDivision');if(this.checked){sel.selectedIndex=0;sel.style.pointerEvents='none';sel.style.opacity='0.5';sel.removeAttribute('required');}else{sel.style.pointerEvents='';sel.style.opacity='';sel.setAttribute('required','required');}">
                    <span>Crear en <strong>todas las divisiones</strong></span>
                </label>
            <?php endif; ?>
            <label>Año lectivo
                <select name="año_lectivo" required>
                    <?php foreach ($añosDisponibles as $a): ?>
                        <option value="<?= $a ?>" <?= $a === $añoActual ? 'selected' : '' ?>>
                            <?= $a ?><?= $a === $añoActual ? ' (actual)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Fecha vencimiento <input type="datetime-local" name="fecha_vencimiento" required></label>
            <label>Tipo de entrega
                <select name="tipo_entrega" required>
                    <option value="">— Seleccione —</option>
                    <option value="documento">Documento</option>
                    <option value="multimedia">Multimedia (imagen, video o audio)</option>
                </select>
            </label>
            <label>Adjunto (opcional) <input type="file" name="archivo_tarea" id="archivoTarea" accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip,.rar,.7z"></label>
            <div class="form-acciones">
                <a class="btn-secundario" href="/plataforma/tareas.php">Cancelar</a>
                <button class="btn-primario" type="submit" id="btnCrearTarea">Crear tarea</button>
            </div>
        </form>
        <script>
        window.OPCIONES_TAREA = <?= json_encode($opciones) ?>;
        window.DIVISIONES_POR_CURSO = <?= json_encode($divisionesPorCurso) ?>;
        </script>
        <script src="/assets/js/tarea_detalle.js"></script>
        <?php endif; ?>
    </main>
    <?php include __DIR__ . '/views/footer.php'; ?>
    <?php exit;
}

if (!$id) redirect('/plataforma/tareas.php');
try { $tarea = $mongoDB->selectCollection('tareas')->findOne(['_id' => new MongoDB\BSON\ObjectId($id)]); } catch (Exception $e) { die('ID inválido'); }
if (!$tarea) die('Tarea no encontrada');

$soyDocente = ($rol === 'docente' && (int)$tarea['docente_id'] === (int)$user['id']);
$soyAdmin   = in_array($rol, ['directivo', 'secretario']);
$puedoEditar = $soyDocente || $soyAdmin;

$errorEdit = '';
if ($esEditar && $puedoEditar && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipoEdit = $_POST['tipo_entrega'] ?? '';
    $tituloEdit = trim($_POST['titulo'] ?? '');
    $fechaEdit = $_POST['fecha_vencimiento'] ?? '';
    if ($tituloEdit === '') $errorEdit = 'Título obligatorio.';
    elseif (!in_array($tipoEdit, ['documento', 'multimedia'])) $errorEdit = 'Tipo inválido.';
    elseif ($fechaEdit === '') $errorEdit = 'Fecha obligatoria.';
    if (!$errorEdit) {
        try { $dtEdit = new DateTime($fechaEdit, new DateTimeZone('America/Argentina/Buenos_Aires')); $fechaEditMongo = new MongoDB\BSON\UTCDateTime($dtEdit); } catch (Exception $e) { $errorEdit = 'Fecha inválida.'; }
    }
    if (!$errorEdit) {
        $mongoDB->selectCollection('tareas')->updateOne(['_id' => $tarea['_id']], ['$set' => ['titulo' => $tituloEdit, 'descripcion' => trim($_POST['descripcion'] ?? ''), 'fecha_vencimiento' => $fechaEditMongo, 'tipo_entrega' => $tipoEdit]]);
        redirect('/plataforma/tarea_detalle.php?id=' . $id);
    }
}

$miEntrega = null; $error = '';
if ($rol === 'alumno') {
    $miEntrega = $mongoDB->selectCollection('entregas')->findOne(['tarea_id' => $tarea['_id'], 'alumno_id' => (int)$user['id']]);
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['entregar'])) {
        $comentarioAlumno = trim($_POST['comentario_alumno'] ?? '');
        $archivoSubido = !empty($_FILES['archivo']['tmp_name']);
        if (!$archivoSubido && $comentarioAlumno === '') $error = 'Adjuntá archivo o comentario.';
        else {
            $archivoId = null; $archivoNombre = null; $vtEstado = 'ok';
            if ($archivoSubido) {
                require_once __DIR__ . '/core/FileHandler.php';
                $fh = new FileHandler($mongoArchivos);
                $tipo = $tarea['tipo_entrega'] === 'multimedia' ? 'multimedia' : 'documento';
                $r = $fh->procesar($_FILES['archivo'], $tipo);
                if ($r['estado'] === 'malicioso') $error = '⚠️ Malware.';
                elseif ($r['estado'] === 'error') $error = $r['mensaje'];
                else { $archivoId = new MongoDB\BSON\ObjectId($r['archivo_id']); $archivoNombre = $r['nombre']; $vtEstado = $r['estado']; }
            }
            if (!$error) {
                $doc = ['tarea_id' => $tarea['_id'], 'alumno_id' => (int)$user['id'], 'archivo_id' => $archivoId, 'archivo_nombre' => $archivoNombre, 'vt_estado' => $vtEstado, 'comentario_alumno' => $comentarioAlumno ?: null, 'estado' => 'entregada', 'calificacion' => null, 'nota_numerica' => null, 'comentario' => null, 'fecha_entrega' => new MongoDB\BSON\UTCDateTime()];
                if ($miEntrega) $mongoDB->selectCollection('entregas')->updateOne(['_id' => $miEntrega['_id']], ['$set' => $doc]);
                else $mongoDB->selectCollection('entregas')->insertOne($doc);
                redirect('/plataforma/tarea_detalle.php?id=' . $id);
            }
        }
    }
}

$entregas = [];
if ($puedoEditar) $entregas = $mongoDB->selectCollection('entregas')->find(['tarea_id' => $tarea['_id']], ['sort' => ['fecha_entrega' => -1]])->toArray();

$stmt = $pdo->prepare("SELECT nombre FROM materias WHERE id = ?");
$stmt->execute([$tarea['materia_id']]);
$materiaNombre = $stmt->fetchColumn() ?: 'Materia';
$divsNombres = []; $divsIds = $tarea['divisiones_ids'] ?? [];
if (is_object($divsIds)) $divsIds = (array)$divsIds;
$divsIds = array_map('intval', $divsIds);
if (!empty($divsIds)) {
    $ph = implode(',', array_fill(0, count($divsIds), '?'));
    $stmt = $pdo->prepare("SELECT d.nombre AS division, a.nombre AS curso FROM divisiones d JOIN asignaciones a ON a.id = d.asignacion_id WHERE d.id IN ($ph)");
    $stmt->execute($divsIds);
    foreach ($stmt as $d) $divsNombres[] = $d['curso'] . ' ' . $d['division'];
}
$fv = $tarea['fecha_vencimiento']->toDateTime(); $fv->setTimezone(new DateTimeZone('America/Argentina/Buenos_Aires'));
$añoTarea = isset($tarea['año_lectivo']) ? (int)$tarea['año_lectivo'] : (int)$fv->format('Y');
?>
<?php include __DIR__ . '/views/header.php'; ?>
<?php include __DIR__ . '/views/menu.php'; ?>
<main class="contenido-principal">
    <?php if ($esEditar && $puedoEditar): ?>
        <h1>✏️ Editar tarea</h1>
        <?php if (!empty($errorEdit)): ?><div class="alerta danger"><?= h($errorEdit) ?></div><?php endif; ?>
        <form method="post" class="panel-vidrio">
            <label>Título <input type="text" name="titulo" value="<?= h($tarea['titulo']) ?>" required></label>
            <label>Descripción <textarea name="descripcion" rows="4"><?= h($tarea['descripcion'] ?? '') ?></textarea></label>
            <label>Fecha <input type="datetime-local" name="fecha_vencimiento" value="<?= h($fv->format('Y-m-d\TH:i')) ?>" required></label>
            <label>Tipo
                <select name="tipo_entrega" required>
                    <option value="documento" <?= $tarea['tipo_entrega'] === 'documento' ? 'selected' : '' ?>>Documento</option>
                    <option value="multimedia" <?= $tarea['tipo_entrega'] === 'multimedia' ? 'selected' : '' ?>>Multimedia</option>
                </select>
            </label>
            <div class="form-acciones">
                <a class="btn-secundario" href="/plataforma/tarea_detalle.php?id=<?= $id ?>">Cancelar</a>
                <button class="btn-primario">Guardar cambios</button>
            </div>
        </form>
    <?php else: ?>
        <h1><?= h($tarea['titulo']) ?></h1>
        <?php if ($añoTarea !== $añoActual): ?>
            <div class="alerta info" style="margin-bottom:1rem;">📅 Tarea del año lectivo <strong><?= $añoTarea ?></strong></div>
        <?php endif; ?>
        <div class="panel-vidrio">
            <p><strong>Materia:</strong> <?= h($materiaNombre) ?></p>
            <p><strong>Divisiones:</strong> <?= h(implode(', ', $divsNombres)) ?></p>
            <p><strong>Vence:</strong> <?= h($fv->format('d/m/Y H:i')) ?></p>
            <p><strong>Tipo:</strong> <?= $tarea['tipo_entrega'] === 'multimedia' ? 'Multimedia' : 'Documento' ?></p>
            <p><?= nl2br(h($tarea['descripcion'] ?? '')) ?></p>
            <?php if (!empty($tarea['archivo_id'])): ?>
                <p><strong>Adjunto:</strong> <a class="btn-secundario" href="/plataforma/descargar.php?id=<?= h((string)$tarea['archivo_id']) ?>" target="_blank">📎 <?= h($tarea['archivo_nombre']) ?></a></p>
            <?php endif; ?>
            <?php if ($puedoEditar): ?>
                <div class="form-acciones">
                    <a class="btn-secundario" href="?id=<?= $id ?>&editar=1">✏️ Editar</a>
                    <button type="button" class="btn-peligro" onclick="abrirEliminarTarea()">🗑️ Eliminar tarea</button>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($rol === 'alumno'): ?>
            <div class="panel-vidrio">
                <h2>Mi entrega</h2>
                <?php if (!empty($error)): ?><div class="alerta danger"><?= h($error) ?></div><?php endif; ?>
                <?php if ($miEntrega): ?>
                    <p>Estado: <span class="badge"><?= h(ucfirst($miEntrega['estado'])) ?></span></p>
                    <?php if (!empty($miEntrega['comentario_alumno'])): ?><p><strong>Mi comentario:</strong> <?= nl2br(h($miEntrega['comentario_alumno'])) ?></p><?php endif; ?>
                    <?php if (!empty($miEntrega['archivo_id'])): ?><p><a class="btn-secundario" href="/plataforma/descargar.php?id=<?= h((string)$miEntrega['archivo_id']) ?>" target="_blank">📎 Mi archivo</a></p><?php endif; ?>
                    <?php if (!empty($miEntrega['calificacion'])): ?><p><strong>Calificación:</strong> <?= h($miEntrega['calificacion']) ?></p><?php endif; ?>
                    <?php if (!empty($miEntrega['comentario'])): ?><p><strong>Comentario del docente:</strong> <?= nl2br(h($miEntrega['comentario'])) ?></p><?php endif; ?>
                <?php endif; ?>
                <form method="post" enctype="multipart/form-data" style="margin-top:1rem;" onsubmit="return validarEntrega(this);">
                    <input type="hidden" name="entregar" value="1">
                    <label>Comentario <textarea name="comentario_alumno" rows="3"><?= h($miEntrega['comentario_alumno'] ?? '') ?></textarea></label>
                    <label>Archivo <input type="file" name="archivo" id="tareaArchivo" accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip,.rar,.7z"></label>
                    <div class="form-acciones"><button class="btn-primario" type="submit" id="btnEntregar">Enviar</button></div>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($puedoEditar): ?>
            <div class="panel-vidrio">
                <h2>Entregas (<?= count($entregas) ?>)</h2>
                <?php if (empty($entregas)): ?><p class="alerta info">Sin entregas.</p>
                <?php else: ?>
                    <table class="tabla-alumnos">
                        <thead><tr><th>Alumno</th><th>Fecha</th><th>Adjunto</th><th>Estado</th><th>Nota</th><th>Acción</th></tr></thead>
                        <tbody>
                            <?php foreach ($entregas as $e):
                                $fe = $e['fecha_entrega']->toDateTime(); $fe->setTimezone(new DateTimeZone('America/Argentina/Buenos_Aires'));
                                $stmt = $pdo->prepare("SELECT nombre, apellido FROM usuarios WHERE id = ?"); $stmt->execute([(int)$e['alumno_id']]); $al = $stmt->fetch(); $alNom = $al['apellido'].', '.$al['nombre'];
                            ?>
                                <tr>
                                    <td><?= h($alNom) ?></td>
                                    <td><?= h($fe->format('d/m/Y H:i')) ?></td>
                                    <td><?php if (!empty($e['archivo_id'])): ?><a href="/plataforma/descargar.php?id=<?= h((string)$e['archivo_id']) ?>" target="_blank">📎</a><?php else: ?>—<?php endif; ?></td>
                                    <td><span class="badge"><?= h(ucfirst($e['estado'])) ?></span></td>
                                    <td><?= h($e['calificacion'] ?? '—') ?></td>
                                    <td><button type="button" class="btn-secundario" onclick="abrirCalificar('<?= h((string)$e['_id']) ?>', '<?= h($alNom) ?>', '<?= h($e['calificacion'] ?? '') ?>', <?= $e['nota_numerica'] !== null ? $e['nota_numerica'] : 'null' ?>, '<?= h(addslashes($e['comentario'] ?? '')) ?>', '<?= h($e['estado'] ?? 'entregada') ?>')">Calificar</button></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            <div class="modal-fondo oculto" id="modalCalificar">
                <div class="modal-caja">
                    <h3>Calificar</h3>
                    <form id="formCalificar">
                        <input type="hidden" name="entrega_id" id="calEntregaId">
                        <p id="calAlumno"></p>
                        <label>Calificación
                            <select name="calificacion">
                                <option value="">—</option>
                                <?php foreach (CALIF_CUALITATIVAS as $c): ?><option value="<?= $c ?>"><?= $c ?></option><?php endforeach; ?>
                            </select>
                        </label>
                        <label>Nota (0-10) <input type="number" name="nota_numerica" min="0" max="10" step="0.01"></label>
                        <label>Comentario <textarea name="comentario" rows="3"></textarea></label>
                        <label>Estado
                            <select name="estado">
                                <option value="aprobada">Aprobada</option>
                                <option value="rechazada">Rechazada</option>
                                <option value="entregada">Entregada (sin corregir)</option>
                            </select>
                        </label>
                        <div class="modal-acciones">
                            <button type="button" class="btn-secundario" onclick="cerrarModal('modalCalificar')">Cancelar</button>
                            <button type="submit" class="btn-primario">Guardar</button>
                        </div>
                    </form>
                </div>
            </div>
            <div class="modal-fondo oculto" id="modalEliminarTarea">
                <div class="modal-caja">
                    <h3>⚠️ Eliminar</h3>
                    <p>¿Eliminar <strong><?= h($tarea['titulo']) ?></strong> y sus entregas?</p>
                    <div class="modal-acciones">
                        <button type="button" class="btn-secundario" onclick="cerrarModal('modalEliminarTarea')">Cancelar</button>
                        <button type="button" class="btn-peligro" id="btnConfirmarEliminarTarea" onclick="confirmarEliminarTarea('<?= h((string)$tarea['_id']) ?>')">Eliminar</button>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</main>
<script>
window.MAX_FILE_MB = <?= (float)UPLOAD_MAX_MB ?>;
window.MAX_FILE_LABEL = <?= json_encode(UPLOAD_MAX_LABEL) ?>;
</script>
<script src="/assets/js/tarea_detalle.js"></script>
<?php include __DIR__ . '/views/footer.php'; ?>