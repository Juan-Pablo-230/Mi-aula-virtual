<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Helpers.php';
require_once __DIR__ . '/models/AcademicModel.php';

Auth::checkRole(ROLES_ALL);
$user = Auth::currentUser($pdo);
$userId = (int)$user['id'];

$tz = new DateTimeZone('America/Argentina/Buenos_Aires');

$chats = $mongoDB->selectCollection('mensajes')->aggregate([
    ['$match' => ['$or' => [['de' => $userId], ['para' => $userId]]]],
    ['$addFields' => ['otro' => ['$cond' => [['$eq' => ['$de', $userId]], '$para', '$de']]]],
    ['$sort' => ['fecha' => -1]],
    ['$group' => ['_id' => '$otro', 'ultimo_mensaje' => ['$first' => '$texto'], 'ultima_fecha' => ['$first' => '$fecha']]],
    ['$sort' => ['ultima_fecha' => -1]],
])->toArray();

$otrosIds = array_map(fn($c) => (int)$c['_id'], $chats);
$usuariosPorId = [];
if (!empty($otrosIds)) {
    $ph = implode(',', array_fill(0, count($otrosIds), '?'));
    $stmt = $pdo->prepare("SELECT id, nombre, apellido, rol, foto_perfil FROM usuarios WHERE id IN ($ph)");
    $stmt->execute($otrosIds);
    foreach ($stmt as $u) $usuariosPorId[(int)$u['id']] = $u;
}

$destinoId = (int)($_GET['con_id'] ?? 0);
$destino = null;
if ($destinoId) {
    $stmt = $pdo->prepare("SELECT id, nombre, apellido, rol, foto_perfil FROM usuarios WHERE id = ?");
    $stmt->execute([$destinoId]);
    $destino = $stmt->fetch();
}

$msgs = [];
$errorMsg = '';
if ($destinoId && $destino) {
    $msgs = $mongoDB->selectCollection('mensajes')->find(
        [
            '$or' => [['de' => $userId, 'para' => $destinoId], ['de' => $destinoId, 'para' => $userId]],
            'eliminado_para' => ['$ne' => $userId],
        ],
        ['sort' => ['fecha' => 1]]
    )->toArray();
    $mongoDB->selectCollection('mensajes')->updateMany(
        ['de' => $destinoId, 'para' => $userId, 'leido' => ['$ne' => true]],
        ['$set' => ['leido' => true, 'fecha_lectura' => new MongoDB\BSON\UTCDateTime()]]
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $destinoId && $destino) {
    $texto = trim($_POST['mensaje'] ?? '');
    $archivoId = null;
    $archivoNombre = null;
    $vtEstado = 'ok';

    if (!empty($_FILES['archivo']['tmp_name'])) {
        require_once __DIR__ . '/core/FileHandler.php';
        $fh = new FileHandler($mongoArchivos);
        $r = $fh->procesar($_FILES['archivo'], 'documento');
        if ($r['estado'] === 'malicioso') {
            $errorMsg = '⚠️ El archivo contiene malware y fue bloqueado.';
        } elseif ($r['estado'] === 'error') {
            $errorMsg = 'Adjunto: ' . $r['mensaje'];
        } else {
            $archivoId = new MongoDB\BSON\ObjectId($r['archivo_id']);
            $archivoNombre = $r['nombre'];
            $vtEstado = $r['estado'];
        }
    }

    if (!$errorMsg && ($texto !== '' || $archivoId)) {
        $mongoDB->selectCollection('mensajes')->insertOne([
            'de' => $userId,
            'para' => $destinoId,
            'texto' => $texto,
            'archivo_id' => $archivoId,
            'archivo_nombre' => $archivoNombre,
            'vt_estado' => $vtEstado,
            'leido' => false,
            'eliminado_para' => [],
            'fecha' => new MongoDB\BSON\UTCDateTime(),
        ]);
        redirect('/plataforma/mensajes.php?con_id=' . $destinoId);
    } elseif (!$errorMsg) {
        $errorMsg = 'Escribí un mensaje o adjuntá un archivo.';
    }
}

$usuarios = $pdo->query("SELECT id, nombre, apellido, rol, foto_perfil FROM usuarios WHERE id != $userId AND activo = 1 ORDER BY apellido, nombre")->fetchAll();
?>
<?php include __DIR__ . '/views/header.php'; ?>
<?php include __DIR__ . '/views/menu.php'; ?>
<main class="contenido-principal">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
        <h1>💬 Mensajes</h1>
        <button class="btn-primario" onclick="abrirNuevoMensaje()">➕ Nuevo mensaje</button>
    </div>
    <div class="layout-mensajes">
        <aside class="panel-vidrio" style="padding:1rem;">
            <h3>Conversaciones</h3>
            <?php if (empty($chats)): ?><p class="alerta info">Sin conversaciones.</p>
            <?php else: foreach ($chats as $c):
                $otro = $usuariosPorId[(int)$c['_id']] ?? null;
                if (!$otro) continue;
                $sinLeer = $mongoDB->selectCollection('mensajes')->countDocuments([
                    'de' => (int)$otro['id'], 'para' => $userId, 'leido' => ['$ne' => true]
                ]);
            ?>
                <a href="?con_id=<?= (int)$otro['id'] ?>" class="contacto contacto-con-avatar <?= (int)$otro['id'] === $destinoId ? 'activo' : '' ?>">
                    <img class="contacto-avatar" src="<?= h(fotoPerfilUrl($otro['foto_perfil'] ?? null)) ?>" alt="">
                    <div class="contacto-texto">
                        <strong><?= h($otro['apellido'] . ', ' . $otro['nombre']) ?></strong>
                        <small><?= h(mb_strimwidth($c['ultimo_mensaje'] ?? '', 0, 32, '…')) ?></small>
                    </div>
                    <?php if ($sinLeer > 0): ?>
                        <span class="badge-no-leido" title="<?= (int)$sinLeer ?> mensaje(s) sin leer"><?= $sinLeer > 99 ? '99+' : (int)$sinLeer ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; endif; ?>
        </aside>
        <section class="panel-vidrio chat">
            <?php if ($destino): ?>
                <h3><?= h($destino['apellido'] . ', ' . $destino['nombre']) ?></h3>
                <?php if (!empty($errorMsg)): ?><div class="alerta danger"><?= h($errorMsg) ?></div><?php endif; ?>
                <div class="chat-mensajes" id="chatMensajes">
                    <?php foreach ($msgs as $m):
                        $esMio = (int)$m['de'] === $userId;
                        $fechaMsg = $m['fecha']->toDateTime();
                        $fechaMsg->setTimezone($tz);
                        $hora = $fechaMsg->format('H:i');
                        $msgId = (string)$m['_id'];
                        $editado = !empty($m['editado']);
                        $minutosTranscurridos = (time() - $fechaMsg->getTimestamp()) / 60;
                        $dentroDe24h = $minutosTranscurridos <= (24 * 60);
                        $puedeEditar = $esMio && $dentroDe24h;
                        $puedeEliminarTodos = $esMio && $dentroDe24h;
                        $puedeEliminarMi = true;
                    ?>
                        <div class="burbuja <?= $esMio ? 'mia' : 'suya' ?> <?= $esMio ? 'burbuja-propia' : 'burbuja-ajena' ?>"
                             data-msg-id="<?= h($msgId) ?>"
                             data-es-mio="<?= $esMio ? '1' : '0' ?>"
                             data-puede-editar="<?= $puedeEditar ? '1' : '0' ?>"
                             data-puede-eliminar-mi="<?= $puedeEliminarMi ? '1' : '0' ?>"
                             data-puede-eliminar-todos="<?= $puedeEliminarTodos ? '1' : '0' ?>"
                             data-texto="<?= h($m['texto'] ?? '') ?>">
                            <?php if (!empty(trim($m['texto'] ?? ''))): ?>
                                <div class="burbuja-texto"><?= nl2br(h($m['texto'])) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($m['archivo_id'])): ?>
                                <div class="burbuja-adjunto">
                                    <?php if (($m['vt_estado'] ?? '') === 'sospechoso'): ?>
                                        <div class="alerta warn" style="padding:.25rem .5rem; font-size:.75rem; margin:.25rem 0;">⚠️ Archivo marcado como sospechoso</div>
                                    <?php endif; ?>
                                    <a class="chat-adjunto" href="/plataforma/descargar.php?id=<?= h((string)$m['archivo_id']) ?>" target="_blank">
                                        📎 <?= h($m['archivo_nombre'] ?? 'Adjunto') ?>
                                    </a>
                                </div>
                            <?php endif; ?>
                            <div class="burbuja-meta">
                                <?= h($fechaMsg->format('d/m/Y')) ?> - <?= h($hora) ?>
                                <?php if ($editado): ?>
                                    <span class="burbuja-editado">· Mensaje editado</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <form method="post" enctype="multipart/form-data" class="chat-form" id="formChat">
                    <input name="mensaje" placeholder="Escribí un mensaje...">
                    <label class="chat-adjunto-btn">
                        <input type="file" name="archivo" accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip,.rar,.7z" hidden
                               onchange="this.closest('.chat-form').querySelector('.chat-adjunto-nombre').textContent = this.files[0]?.name || '';">
                        📎 <span class="chat-adjunto-nombre"></span>
                    </label>
                    <button class="btn-primario" id="btnEnviarMensaje" type="submit">Enviar</button>
                </form>
            <?php else: ?><p class="alerta info">Elegí una conversación.</p><?php endif; ?>
        </section>
    </div>
</main>
<div class="modal-fondo oculto" id="modalNuevoMensaje">
    <div class="modal-caja">
        <h3>Nuevo mensaje</h3>
        <input type="text" id="buscarUsuario" placeholder="Buscar..." oninput="filtrarUsuarios()" style="width:100%; margin-bottom:1rem; padding:0.5rem;">
        <div id="listaUsuarios" style="max-height:300px; overflow-y:auto;">
            <?php foreach ($usuarios as $u): ?>
                <a href="?con_id=<?= (int)$u['id'] ?>" class="contacto contacto-con-avatar usuario-item" data-nombre="<?= h(mb_strtolower($u['nombre'] . ' ' . $u['apellido'])) ?>">
                    <img class="contacto-avatar" src="<?= h(fotoPerfilUrl($u['foto_perfil'] ?? null)) ?>" alt="">
                    <div class="contacto-texto">
                        <strong><?= h($u['apellido'] . ', ' . $u['nombre']) ?></strong>
                        <small><?= h(ucfirst($u['rol'])) ?></small>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="modal-acciones"><button type="button" class="btn-secundario" onclick="cerrarModal('modalNuevoMensaje')">Cancelar</button></div>
    </div>
</div>

<div class="menu-contextual oculto" id="menuContextual">
    <button type="button" class="menu-contextual-item" data-accion="editar" id="mcEditar">✏️ Editar mensaje</button>
    <button type="button" class="menu-contextual-item" data-accion="eliminar_para_mi" id="mcEliminarMi">🗑️ Eliminar para mí</button>
    <button type="button" class="menu-contextual-item peligro" data-accion="eliminar_para_todos" id="mcEliminarTodos">🗑️ Eliminar para todos</button>
</div>

<div class="modal-fondo oculto" id="modalEditarMensaje">
    <div class="modal-caja">
        <h3>✏️ Editar mensaje</h3>
        <form id="formEditarMensaje">
            <input type="hidden" name="msg_id" id="editarMsgId">
            <label>Mensaje
                <textarea name="texto" id="editarTexto" rows="4" required></textarea>
            </label>
            <small style="color:#a5c3e8; font-size:0.75rem; display:block; margin-top:0.25rem;">
                El adjunto del mensaje no se modifica al editar el texto.
            </small>
            <div class="modal-acciones">
                <button type="button" class="btn-secundario" onclick="cerrarModal('modalEditarMensaje')">Cancelar</button>
                <button type="submit" class="btn-primario" id="btnGuardarEdicion">Guardar</button>
            </div>
        </form>
    </div>
</div>

<script src="/assets/js/mensajes.js"></script>
<?php include __DIR__ . '/views/footer.php'; ?>