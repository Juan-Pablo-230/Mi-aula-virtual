<?php $paginaActual = basename($_SERVER['PHP_SELF']); ?>
<aside class="menu-lateral">
    <nav>
        <a href="/plataforma/bibliografia.php" class="<?= $paginaActual === 'bibliografia.php' ? 'activo' : '' ?>">📖 Bibliografía</a>
        <a href="/plataforma/calendario.php" class="<?= $paginaActual === 'calendario.php' ? 'activo' : '' ?>">📅 Calendario</a>
        <a href="/plataforma/materias.php" class="<?= $paginaActual === 'materias.php' ? 'activo' : '' ?>">📚 Materias</a>
        <a href="/plataforma/publicaciones.php" class="<?= $paginaActual === 'publicaciones.php' ? 'activo' : '' ?>">📰 Publicaciones</a>
        <a href="/plataforma/tareas.php" class="<?= $paginaActual === 'tareas.php' ? 'activo' : '' ?>">📝 Tareas</a>
    </nav>
</aside>