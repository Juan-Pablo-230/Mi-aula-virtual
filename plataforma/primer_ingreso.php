<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Helpers.php';

Auth::checkRole(ROLES_ALL);

$user = Auth::currentUser($pdo);
if (!$user) { header('Location: /index.html'); exit; }

$stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt->execute([(int)$user['id']]);
$userFull = $stmt->fetch();
if (!$userFull) { header('Location: /index.html'); exit; }

if (!$userFull['primer_ingreso']) {
    header('Location: /plataforma/publicaciones.php');
    exit;
}

// ============================================================
// Detectar si es el dueño recién creado (puede editar nombre/apellido)
// Criterios: nombre exactamente 'Dueño' + apellido empieza con 'Institución '
//            O bien email NULL (todavía no completó nada)
// ============================================================
$esDuenioNuevo = (
    ($userFull['nombre'] === 'Dueño' && strpos((string)$userFull['apellido'], 'Institución ') === 0)
    || $userFull['email'] === null
);

$readonlyNombre = $esDuenioNuevo ? '' : 'readonly';
$hintNombre = $esDuenioNuevo
    ? 'Ingresá tu nombre real'
    : 'Este dato no se puede modificar';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Primer ingreso — Mi Aula Virtual</title>
    <meta name="csrf-token" content="<?= h(csrfToken()) ?>">
    <link rel="stylesheet" href="/assets/css/aulaviva.css">
    <script src="/assets/js/aulaviva.js"></script>
    <script src="/assets/js/password-ojo.js"></script>
</head>
<body>
<div class="login-wrapper">
    <div class="panel-vidrio">
        <h2>👋 ¡Bienvenido/a!</h2>
        <p style="color:#cfe2ff; font-size:0.9rem; margin-bottom:1rem;">
            Antes de empezar, completá tus datos y elegí una contraseña nueva.
        </p>

        <form id="formPrimerIngreso">
            <label>Nombre
                <input type="text" name="nombre" value="<?= h($userFull['nombre'] ?? '') ?>"
                       required maxlength="100" placeholder="Tu nombre" <?= $readonlyNombre ?>>
                <?php if (!$esDuenioNuevo): ?>
                    <small style="color:#a5c3e8; font-size:0.75rem; display:block;"><?= h($hintNombre) ?></small>
                <?php endif; ?>
            </label>
            <label>Apellido
                <input type="text" name="apellido" value="<?= h($userFull['apellido'] ?? '') ?>"
                       required maxlength="100" placeholder="Tu apellido" <?= $readonlyNombre ?>>
            </label>
            <label>DNI (solo lectura)
                <input type="text" value="<?= h($userFull['dni'] ?? '') ?>" readonly>
            </label>
            <label>Correo electrónico (obligatorio)
                <input type="email" name="email" value="<?= h($userFull['email'] ?? '') ?>"
                       required maxlength="255" placeholder="tu@email.com">
            </label>
            <label>Nueva contraseña
                <input type="password" name="password" required minlength="8" maxlength="128" autocomplete="new-password" placeholder="Mínimo 8 caracteres, con letras y números">
            </label>
            <label>Repetir contraseña
                <input type="password" name="password2" required minlength="8" maxlength="128" autocomplete="new-password" placeholder="Repetí la contraseña">
            </label>
            <div id="primerError" class="alerta danger" style="display:none;"></div>
            <div class="modal-acciones" style="justify-content:flex-start; margin-top:1rem;">
                <button type="submit" class="btn-primario" id="btnGuardarPrimer">Guardar y continuar</button>
            </div>
        </form>
    </div>
</div>
<script>
window.PRIMER_INGRESO_EDITA_NOMBRE = <?= $esDuenioNuevo ? 'true' : 'false' ?>;
</script>
<script src="/assets/js/primer_ingreso.js"></script>
</body>
</html>