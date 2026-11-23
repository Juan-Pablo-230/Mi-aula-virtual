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
    <link rel="stylesheet" href="/assets/css/aulaviva.css">
    <script src="/assets/js/aulaviva.js"></script>
    <script src="/assets/js/dni-numerico.js"></script>
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
                    <strong><?= h($currentUser['apellido'] . ', ' . $currentUser['nombre']) ?></strong>
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
                <a href="/plataforma/logout.php">🚪 Cerrar sesión</a>
            </div>
        </div>
    </div>
</header>