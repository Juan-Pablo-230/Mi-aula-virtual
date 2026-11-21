<footer class="pie-pagina">
    <small>© <?= date('Y') ?> Mi Aula Virtual — Todos los derechos reservados</small>
</footer>

<div class="modal-fondo oculto" id="modalPerfil">
    <div class="modal-caja" role="dialog" aria-modal="true">
        <h3>Editar perfil</h3>
        <form id="formPerfil" enctype="multipart/form-data">
            <label>Nombre
                <input type="text" name="nombre" value="<?= h($currentUser['nombre'] ?? '') ?>" required maxlength="100">
            </label>
            <label>Apellido
                <input type="text" name="apellido" value="<?= h($currentUser['apellido'] ?? '') ?>" required maxlength="100">
            </label>
            <label>DNI
                <input type="text" name="dni" value="<?= h($currentUser['dni'] ?? '') ?>" required pattern="[0-9A-Za-z]{6,20}" maxlength="20">
            </label>
            <label>Correo electrónico (obligatorio)
                <input type="email" name="email" value="<?= h($currentUser['email'] ?? '') ?>" required>
            </label>
            <label>Nueva contraseña (opcional, 8-20 caracteres)
                <input type="password" name="password" minlength="8" maxlength="20">
            </label>
            <label>Foto de perfil
                <input type="file" name="foto" accept="image/*">
            </label>
            <?php if (!empty($currentUser['foto_perfil'])): ?>
                <div style="margin-top:-0.5rem; margin-bottom:1rem;">
                    <button type="button" class="btn-peligro-sm" onclick="eliminarFotoPerfil()">🗑️ Eliminar foto actual</button>
                </div>
            <?php endif; ?>
            <div id="perfilError" class="alerta danger" style="display:none;"></div>
            <div class="modal-acciones">
                <button type="button" class="btn-secundario" onclick="cerrarModal('modalPerfil')">Cancelar</button>
                <button type="submit" class="btn-primario" id="btnGuardarPerfil">Guardar</button>
            </div>
        </form>
    </div>
</div>
</body>
</html>