<?php
if (!isset($pdo)) require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Helpers.php';
require_once __DIR__ . '/../core/GradeHelpers.php';
require_once __DIR__ . '/../models/AcademicModel.php';

$currentUser = Auth::currentUser($pdo);
$rolActual   = $currentUser['rol'] ?? '';

// ============================================================
// Contador de mensajes sin leer (para el badge del header)
// ============================================================
$mensajesSinLeer = 0;
if (isset($_SESSION['user_id']) && isset($mongoDB)) {
    try {
        $mensajesSinLeer = $mongoDB->selectCollection('mensajes')->countDocuments([
            'para' => (int)$_SESSION['user_id'],
            'leido' => ['$ne' => true],
        ]);
    } catch (Exception $e) {
        $mensajesSinLeer = 0;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Mi Aula Virtual</title>
    <meta name="csrf-token" content="<?= h(csrfToken()) ?>">
    <link rel="shortcut icon" href="/assets/img/icon.png" type="image/x-icon">
    <?php
    // Versión por fecha de modificación: el navegador no usa una copia vieja del CSS/JS tras actualizar
    $verAsset = function (string $ruta): string {
        $f = __DIR__ . '/../..' . $ruta;
        return $ruta . (is_file($f) ? '?v=' . filemtime($f) : '');
    };
    ?>
    <link rel="stylesheet" href="<?= h($verAsset('/assets/css/aulaviva.css')) ?>">
    <style>
        /* Botón "Cerrar sesión" del menú del perfil: mismo aspecto que los demás ítems del menú */
        .dropdown-menu .form-logout { margin: 0; padding: 0; }
        .dropdown-menu .form-logout button {
            display: block; width: 100%; text-align: left; margin: 0;
            padding: 0.7rem 1rem; background: transparent; border: none; border-radius: 0;
            color: #fff; font: inherit; font-size: 0.9rem; cursor: pointer; box-shadow: none;
        }
        .dropdown-menu .form-logout button:hover { background: rgba(255, 255, 255, 0.1); }
    </style>
    <script src="<?= h($verAsset('/assets/js/aulaviva.js')) ?>"></script>
    <script src="<?= h($verAsset('/assets/js/dni-numerico.js')) ?>"></script>
    <script src="<?= h($verAsset('/assets/js/password-ojo.js')) ?>"></script>
    <script src="<?= h($verAsset('/assets/js/modal-scroll.js')) ?>"></script>
</head>
<body>

<?php if ($rolActual === 'alumno'): ?>
    <?php
    $libres = obtenerMateriasLibres($pdo, $mongoDB, (int)$_SESSION['user_id']);
    if (!empty($libres)) {
        echo '<div class="alerta-libre-global">⚠️ Quedaste LIBRE en: <strong>' . h(implode(' · ', array_column($libres, 'nombre'))) . '</strong></div>';
    }
    ?>
<?php elseif ($rolActual === 'familiar'): ?>
    <?php
    $alumnosFam = AcademicModel::alumnosDeFamiliar($pdo, (int)$_SESSION['user_id']);
    foreach ($alumnosFam as $aid) {
        $libresFam = obtenerMateriasLibres($pdo, $mongoDB, $aid);
        if (!empty($libresFam)) {
            $stmt = $pdo->prepare("SELECT nombre, apellido FROM usuarios WHERE id = ?");
            $stmt->execute([$aid]);
            $al = $stmt->fetch();
            echo '<div class="alerta-libre-global">⚠️ ' . h($al['nombre'] . ' ' . $al['apellido'])
                . ' quedó LIBRE en: <strong>' . h(implode(' · ', array_column($libresFam, 'nombre'))) . '</strong></div>';
        }
    }
    ?>
<?php endif; ?>

<header class="top-bar">
    <div class="top-left">
        <a href="/plataforma/publicaciones.php" class="brand">🎓 Mi Aula Virtual</a>
    </div>
    <div class="top-right">
        <a href="/plataforma/asistencia.php" class="btn-top">🗓️ Asistencias</a>
        <a href="/plataforma/boletines.php" class="btn-top">📄 Boletines</a>
        <a href="/plataforma/calificaciones.php" class="btn-top">📊 Calificaciones</a>
        <a href="/plataforma/mensajes.php" class="btn-top btn-top-mensajes">
            💬 Mensajes
            <?php if ($mensajesSinLeer > 0): ?>
                <span class="badge-no-leido-header" title="<?= (int)$mensajesSinLeer ?> mensaje(s) sin leer">
                    <?= $mensajesSinLeer > 99 ? '99+' : (int)$mensajesSinLeer ?>
                </span>
            <?php endif; ?>
        </a>

        <div class="perfil-dropdown" id="perfilDropdown">
            <button class="perfil-btn" onclick="togglePerfil()">
                <div class="perfil-texto">
                    <strong><?= h(nombreCompleto($currentUser)) ?></strong>
                    <small><?= h(ucfirst($rolActual)) ?> · DNI <?= h($currentUser['dni']) ?></small>
                </div>
                <img src="<?= h(fotoPerfilUrl($currentUser['foto_perfil'] ?? null)) ?>" alt="" class="avatar">
            </button>
            <div class="dropdown-menu oculto" id="dropdownMenu">
                <a href="#" onclick="abrirModalPerfil();return false;">✏️ Editar perfil</a>
                <?php if ($rolActual === 'madre'): ?>
                    <a href="/plataforma/admin/instituciones.php">🏛️ Administrar plataforma</a>
                <?php elseif (in_array($rolActual, ['directivo', 'secretario'])): ?>
                    <a href="/plataforma/admin/cursos.php">🏫 Administrar institución</a>
                <?php endif; ?>
                <form method="post" action="/plataforma/logout.php" class="form-logout">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <button type="submit">🚪 Cerrar sesión</button>
                </form>
            </div>
        </div>
    </div>
</header>