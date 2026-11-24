<?php
/**
 * login.php — Página / fragmento de inicio de sesión.
 *
 * Si se accede directamente (GET sin header X-Requested-With), muestra
 * la página completa con el header de la landing.
 * Si se accede por fetch (X-Requested-With: fetch), devuelve solo el
 * fragmento HTML del formulario (sin <html>, <body>, etc.).
 */
// Liviano a propósito: este archivo lo ve cualquier visitante anónimo, así que NO se conecta a
// MariaDB/Mongo ni se crea una sesión salvo que el navegador ya traiga una cookie de sesión
// (para redirigir a quien ya inició sesión). Evita que abrir el modal consuma recursos del servidor.
require_once __DIR__ . '/plataforma/core/Security.php';
aplicarCabecerasSeguridad();

$esFragmento = (
    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'fetch')
    || (isset($_GET['fragmento']) && $_GET['fragmento'] === '1')
);

// Si ya está logueado, redirigir (solo si hay cookie de sesión previa)
if (isset($_COOKIE[session_name()])) {
    require_once __DIR__ . '/plataforma/config/database.php'; // inicia la sesión
    if (isset($_SESSION['user_id'])) {
        if ($esFragmento) {
            // Pedido por fetch desde el modal: NO redirigir (el navegador seguiría la redirección y se
            // pegaría la página entera de la plataforma dentro del modal). Se avisa y el JS navega.
            header('Content-Type: text/html; charset=utf-8');
            echo '<div id="yaLogueado" data-destino="/plataforma/publicaciones.php" style="text-align:center;">'
               . '<h3>Ya iniciaste sesión</h3>'
               . '<p style="margin:0.75rem 0 1.25rem;">Te estamos llevando a la plataforma…</p>'
               . '<a href="/plataforma/publicaciones.php" class="btn-primario" style="display:inline-block;">Ir a la plataforma</a>'
               . '</div>';
            exit;
        }
        header('Location: /plataforma/publicaciones.php');
        exit;
    }
}

$expirada = isset($_GET['expirada']) && $_GET['expirada'] === '1';

if ($esFragmento) {
    // ---- FRAGMENTO ----
    ?>
    <div class="login-fragmento">
        <h3 id="tituloModalLogin">Iniciar sesión</h3>
        <?php if ($expirada): ?>
            <div class="alerta-sesion" style="margin:0 0 1rem;">⏱️ Tu sesión expiró. Iniciá sesión de nuevo.</div>
        <?php endif; ?>
        <form id="formLogin">
            <label>DNI
                <input type="text" name="dni" placeholder="Ingresá tu DNI" autocomplete="username" inputmode="numeric" pattern="[0-9]*" maxlength="12" required>
            </label>
            <label>Contraseña
                <input type="password" name="password" placeholder="Mínimo 8 caracteres" maxlength="128" autocomplete="current-password" required>
            </label>
            <div id="loginError" class="alerta danger" style="display:none;"></div>
            <div class="modal-acciones">
                <button type="button" class="btn-secundario" onclick="cerrarLoginModal()">Cancelar</button>
                <button type="submit" class="btn-primario">Ingresar</button>
            </div>
        </form>
    </div>
    <?php
    exit;
}

// ---- PÁGINA COMPLETA ----
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar sesión — Mi Aula Virtual</title>
    <link rel="stylesheet" href="/assets/css/landing.css">
    <link rel="shortcut icon" href="/assets/img/icon.png" type="image/x-icon">
</head>
<body>
<header class="landing-header">
    <a href="/index.html" class="brand">🎓 Mi Aula Virtual</a>
    <nav>
        <a href="/index.html">Inicio</a>
        <a href="/servicios.html">Servicios</a>
        <a href="/contacto.html">Contacto</a>
    </nav>
</header>

<main class="landing-main" style="display:flex; align-items:center; justify-content:center;">
    <div class="modal-caja" style="margin:2rem auto;">
        <h3>Iniciar sesión</h3>
        <?php if ($expirada): ?>
            <div class="alerta-sesion" style="margin:0 0 1rem;">⏱️ Tu sesión expiró. Iniciá sesión de nuevo.</div>
        <?php endif; ?>
        <form id="formLogin">
            <label>DNI
                <input type="text" name="dni" placeholder="Ingresá tu DNI" autocomplete="username" inputmode="numeric" pattern="[0-9]*" maxlength="12" required>
            </label>
            <label>Contraseña
                <input type="password" name="password" placeholder="Mínimo 8 caracteres" maxlength="128" autocomplete="current-password" required>
            </label>
            <div id="loginError" class="alerta danger" style="display:none;"></div>
            <div class="modal-acciones">
                <a href="/index.html" class="btn-secundario">Cancelar</a>
                <button type="submit" class="btn-primario">Ingresar</button>
            </div>
        </form>
    </div>
</main>

<script src="/assets/js/dni-numerico.js"></script>
<script src="/assets/js/password-ojo.js"></script>
<script>
document.getElementById('formLogin').addEventListener('submit', async (e) => {
    e.preventDefault();
    const err = document.getElementById('loginError');
    err.style.display = 'none';
    const fd = new FormData(e.target);
    try {
        const r = await fetch('/plataforma/api/auth_ajax.php?action=login', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.ok) window.location.href = d.redirect;
        else { err.textContent = d.mensaje || 'Error'; err.style.display = 'block'; }
    } catch (ex) {
        err.textContent = 'Error de red: ' + ex.message;
        err.style.display = 'block';
    }
});
</script>
</body>
</html>