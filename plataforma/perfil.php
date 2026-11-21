<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Helpers.php';
Auth::checkRole(ROLES_ALL);
$user = Auth::currentUser($pdo);
?>
<?php include __DIR__ . '/views/header.php'; ?>
<?php include __DIR__ . '/views/menu.php'; ?>
<main class="contenido-principal">
    <h1>Mi perfil</h1>
    <div class="panel-vidrio">
        <p><strong>Apellido y Nombre:</strong> <?= h($user['apellido'] . ', ' . $user['nombre']) ?></p>
        <p><strong>Email:</strong> <?= h($user['email']) ?></p>
        <p><strong>Rol:</strong> <?= h(ucfirst($user['rol'])) ?></p>
        <button class="btn-primario" onclick="abrirModalPerfil()">Editar perfil</button>
    </div>
</main>
<?php include __DIR__ . '/views/footer.php'; ?>