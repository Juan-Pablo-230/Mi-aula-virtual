<?php
if (!isset($pdo)) require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Helpers.php';
require_once __DIR__ . '/../core/GradeHelpers.php';
require_once __DIR__ . '/../models/AcademicModel.php';

$currentUser = Auth::currentUser($pdo);
$rolActual   = $currentUser['rol'] ?? '';

// Institución activa (nombre completo en la tarjeta del perfil) y lista de instituciones donde trabaja
$nombreInstActual = '';
$contextosInst = [];
if ($currentUser && $rolActual !== 'madre' && !empty($currentUser['institucion_id'])) {
    $stI = $pdo->prepare("SELECT nombre FROM instituciones WHERE id = ?");
    $stI->execute([(int)$currentUser['institucion_id']]);
    $nombreInstActual = (string)$stI->fetchColumn();
    if (Membresias::esPersonal($rolActual)) $contextosInst = Membresias::contextos($pdo, (int)$currentUser['id']);
}

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

<?php
// Aviso de LIBRE: ventana emergente (una vez por sesión de navegador para cada combinación), no un banner fijo
$avisosLibre = [];
if ($rolActual === 'alumno') {
    $libres = obtenerMateriasLibres($pdo, $mongoDB, (int)$_SESSION['user_id']);
    if (!empty($libres)) $avisosLibre[] = 'Quedaste LIBRE en: ' . implode(' · ', array_column($libres, 'nombre'));
} elseif ($rolActual === 'familiar') {
    foreach (AcademicModel::alumnosDeFamiliar($pdo, (int)$_SESSION['user_id']) as $aid) {
        $libresFam = obtenerMateriasLibres($pdo, $mongoDB, $aid);
        if (empty($libresFam)) continue;
        $stmt = $pdo->prepare("SELECT nombre, apellido FROM usuarios WHERE id = ?");
        $stmt->execute([$aid]);
        $al = $stmt->fetch();
        if ($al) $avisosLibre[] = trim($al['nombre'] . ' ' . $al['apellido']) . ' quedó LIBRE en: ' . implode(' · ', array_column($libresFam, 'nombre'));
    }
}
?>
<?php if (!empty($avisosLibre)): ?>
<div class="modal-fondo oculto" id="modalAvisoLibre">
    <div class="modal-caja">
        <h3>⚠️ Aviso de alumno libre</h3>
        <?php foreach ($avisosLibre as $av): ?><p><?= h($av) ?></p><?php endforeach; ?>
        <div class="modal-acciones">
            <button type="button" class="btn-primario" onclick="document.getElementById('modalAvisoLibre').classList.add('oculto')">Entendido</button>
        </div>
    </div>
</div>
<script>
(function () {
    var clave = 'avisoLibre:' + <?= json_encode(md5(implode('|', $avisosLibre))) ?>, ya = false;
    try { ya = sessionStorage.getItem(clave) === '1'; sessionStorage.setItem(clave, '1'); } catch (e) {}
    if (!ya) document.getElementById('modalAvisoLibre').classList.remove('oculto');
})();
</script>
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
                    <?php if ($nombreInstActual !== ''): ?><small class="perfil-inst"><?= h($nombreInstActual) ?></small><?php endif; ?>
                </div>
                <img src="<?= h(fotoPerfilUrl($currentUser['foto_perfil'] ?? null)) ?>" alt="" class="avatar">
            </button>
            <div class="dropdown-menu oculto" id="dropdownMenu">
                <a href="#" onclick="abrirModalPerfil();return false;">✏️ Editar perfil</a>
                <?php if (count($contextosInst) > 1): ?>
                    <a href="#" onclick="abrirCambioInstitucion();return false;">🔄 Cambiar institución</a>
                <?php endif; ?>
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
<?php if (count($contextosInst) > 1): ?>
<div class="modal-fondo oculto" id="modalCambioInst">
    <div class="modal-caja" style="min-width: 340px; max-width: 90vw;">
        <h3>🔄 ¿A qué institución querés ir?</h3>
        <div id="listaCambioInst" class="lista-cambio-inst"></div>
        <div class="modal-acciones">
            <button type="button" class="btn-secundario" onclick="cerrarModal('modalCambioInst')">Cancelar</button>
        </div>
    </div>
</div>
<script src="<?= h($verAsset('/assets/js/cambiar-institucion.js')) ?>"></script>
<?php endif; ?>
