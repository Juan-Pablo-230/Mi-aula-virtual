<?php
$adminPaginaActual = basename($_SERVER['PHP_SELF']);
$rolAdmin = $_SESSION['rol'] ?? '';
$adminItems = [];

// Solo madre: Instituciones (y nada más a nivel global)
if ($rolAdmin === 'madre') {
    $adminItems['instituciones.php'] = ['url' => '/plataforma/admin/instituciones.php', 'label' => '🏛️ Instituciones'];
    $adminItems['institucion.php']   = ['url' => '/plataforma/admin/institucion.php',   'label' => '⚙️ Gestionar institución'];
} else {
    // Directivo / secretario: las 4 pestañas de su institución
    $adminItems['cursos.php']       = ['url' => '/plataforma/admin/cursos.php',       'label' => '🏫 Cursos y Materias'];
    $adminItems['usuarios.php']     = ['url' => '/plataforma/admin/usuarios.php',     'label' => '👥 Usuarios'];
    $adminItems['importacion.php']  = ['url' => '/plataforma/admin/importacion.php',  'label' => '📥 Importación / Exportación'];
    $adminItems['estadisticas.php'] = ['url' => '/plataforma/admin/estadisticas.php', 'label' => '📈 Estadísticas'];
}
?>
<nav class="admin-nav">
    <?php foreach ($adminItems as $archivo => $item): ?>
        <a href="<?= h($item['url']) ?>" class="admin-nav-item <?= $adminPaginaActual === $archivo ? 'activo' : '' ?>">
            <?= h($item['label']) ?>
        </a>
    <?php endforeach; ?>
</nav>