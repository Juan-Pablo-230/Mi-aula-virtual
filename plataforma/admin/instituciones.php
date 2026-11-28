<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Helpers.php';

Auth::checkRole(['madre']);
?>
<?php include __DIR__ . '/../views/header.php'; ?>
<?php include __DIR__ . '/../views/menu.php'; ?>
<main class="contenido-principal">
    <h1>🏛️ Instituciones</h1>
    <?php include __DIR__ . '/../views/admin_nav.php'; ?>

    <div class="panel-vidrio">
        <h3>Crear institución</h3>
        <p style="color:#a5c3e8; font-size:0.85rem;">
            Ingresá el DNI del dueño. Si no existe, se crea como <strong>directivo</strong> con contraseña temporal <code>Temporal123</code> y deberá cambiarla al ingresar.
        </p>
        <form id="formInstitucion">
            <fieldset style="border:0.0625rem solid var(--borde-vidrio); border-radius:0.5rem; padding:0.75rem 1rem; margin-bottom:1rem;">
                <legend style="padding:0 0.5rem; color:#7dd3fc; font-weight:600;">Datos de la institución</legend>
                <label>Nombre <input type="text" name="nombre" required maxlength="200"></label>
                <label>País
                    <select name="pais" id="instPais" required><option value="">Cargando países…</option></select>
                </label>
                <label>Localidad
                    <select name="localidad" id="instLocalidad" required disabled><option value="">— Elegí primero el país —</option></select>
                </label>
                <label>Calle <input type="text" name="calle" id="instCalle" required maxlength="150"></label>
                <label>Altura <input type="text" name="altura" id="instAltura" required inputmode="numeric" pattern="[0-9]{1,6}" maxlength="6" placeholder="Solo números"></label>
                <label>Teléfono <input type="text" name="telefono" maxlength="50"></label>
                <label>Email institucional <input type="email" name="email" maxlength="200"></label>
            </fieldset>

            <fieldset style="border:0.0625rem solid var(--borde-vidrio); border-radius:0.5rem; padding:0.75rem 1rem;">
                <legend style="padding:0 0.5rem; color:#7dd3fc; font-weight:600;">Dueño de la institución</legend>
                <label>DNI del dueño <input type="text" name="duenio_dni" required inputmode="numeric" pattern="[0-9]{6,12}" maxlength="12"></label>
            </fieldset>

            <div id="instError" class="alerta danger" style="display:none; margin-top:1rem;"></div>
            <button class="btn-primario" type="submit" id="btnCrearInst" style="margin-top:1rem;">Crear institución</button>
        </form>
    </div>

</main>
<script src="/assets/js/instituciones.js"></script>
<?php include __DIR__ . '/../views/footer.php'; ?>