<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Helpers.php';

Auth::checkRole(['madre']);
$instituciones = $pdo->query("
    SELECT i.*,
           (SELECT COUNT(*) FROM usuarios u WHERE u.institucion_id = i.id) AS cant_usuarios,
           (SELECT CONCAT(u.apellido, ', ', u.nombre) FROM usuarios u
              WHERE u.institucion_id = i.id AND u.rol IN ('directivo','secretario')
              ORDER BY u.id LIMIT 1) AS duenio
    FROM instituciones i ORDER BY i.nombre
")->fetchAll();
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
                <label>Dirección <input type="text" name="direccion" maxlength="300"></label>
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

    <div class="panel-vidrio">
        <h3>Existentes</h3>
        <?php if (empty($instituciones)): ?>
            <p class="alerta info">Sin instituciones creadas.</p>
        <?php else: ?>
            <table class="tabla-alumnos">
                <thead>
                    <tr><th>Nombre</th><th>Dueño</th><th>Dirección</th><th>Teléfono</th><th>Email</th><th>Usuarios</th><th>Acciones</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($instituciones as $i): ?>
                        <tr>
                            <td><?= h($i['nombre']) ?></td>
                            <td><?= $i['duenio'] ? h($i['duenio']) : '<em style="color:#a5c3e8;">—</em>' ?></td>
                            <td><?= h($i['direccion'] ?? '—') ?></td>
                            <td><?= h($i['telefono'] ?? '—') ?></td>
                            <td><?= h($i['email'] ?? '—') ?></td>
                            <td><?= (int)$i['cant_usuarios'] ?></td>
                            <td style="white-space:nowrap;">
                                <a class="btn-secundario" href="/plataforma/admin/institucion.php?id=<?= (int)$i['id'] ?>">⚙️ Gestionar</a>
                                <button type="button" class="btn-peligro-sm" onclick="eliminarInstitucion(<?= (int)$i['id'] ?>, '<?= h(addslashes($i['nombre'])) ?>')">🗑️</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</main>
<script src="/assets/js/instituciones.js"></script>
<?php include __DIR__ . '/../views/footer.php'; ?>