<?php
$adminPaginaActual = basename($_SERVER['PHP_SELF']);
$rolAdmin = $_SESSION['rol'] ?? '';
$adminItems = [];

// Solo madre: Instituciones (y nada más a nivel global)
if ($rolAdmin === 'madre') {
    $adminItems['instituciones.php'] = ['url' => '/plataforma/admin/instituciones.php', 'label' => '🏛️ Instituciones'];
    $adminItems['__gestionar'] = ['url' => '#', 'label' => '⚙️ Gestionar institución'];
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
        <?php if ($archivo === '__gestionar'): ?>
            <a href="#" class="admin-nav-item <?= $adminPaginaActual === 'institucion.php' ? 'activo' : '' ?>" onclick="abrirElegirInstitucion(event)"><?= h($item['label']) ?></a>
        <?php else: ?>
        <a href="<?= h($item['url']) ?>" class="admin-nav-item <?= $adminPaginaActual === $archivo ? 'activo' : '' ?>">
            <?= h($item['label']) ?>
        </a>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>
<?php if ($rolAdmin === 'madre'):
    $instNav = $pdo->query("SELECT id, nombre, localidad FROM instituciones ORDER BY nombre")->fetchAll(); ?>
<div class="modal-fondo oculto" id="modalElegirInstitucion">
    <div class="modal-caja" style="min-width: 420px; max-width: 90vw;">
        <h3>⚙️ ¿Qué institución querés gestionar?</h3>
        <?php if (empty($instNav)): ?>
            <p class="alerta info">Todavía no hay instituciones. Creá una desde "Instituciones".</p>
        <?php else: ?>
            <input type="text" id="buscarInstNav" placeholder="🔍 Buscar..." oninput="filtrarInstNav()" style="width:100%; margin-bottom:0.75rem; padding:0.5rem;">
            <div id="listaInstNav" style="max-height:320px; overflow-y:auto;">
                <?php foreach ($instNav as $in): ?>
                    <div class="inst-nav-item" data-nombre="<?= h(mb_strtolower($in['nombre'])) ?>" style="display:flex; gap:0.5rem; align-items:center; margin:0.3rem 0;">
                        <a class="btn-secundario" style="flex:1; text-align:left;" href="/plataforma/admin/institucion.php?id=<?= (int)$in['id'] ?>">
                            🏛️ <?= h($in['nombre']) ?><?php if (!empty($in['localidad'])): ?> <small style="color:#a5c3e8;">· <?= h($in['localidad']) ?></small><?php endif; ?>
                        </a>
                        <button type="button" class="btn-peligro-sm" title="Eliminar institución" onclick='eliminarInstitucionNav(<?= (int)$in['id'] ?>, <?= jsonJs($in['nombre']) ?>)'>🗑️</button>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="modal-acciones"><button type="button" class="btn-secundario" onclick="cerrarModal('modalElegirInstitucion')">Cancelar</button></div>
    </div>
</div>
<script>
function abrirElegirInstitucion(e) { if (e) e.preventDefault(); document.getElementById('modalElegirInstitucion').classList.remove('oculto'); }
function filtrarInstNav() {
    const q = document.getElementById('buscarInstNav').value.trim().toLowerCase();
    document.querySelectorAll('#listaInstNav .inst-nav-item').forEach(el => { el.style.display = el.dataset.nombre.includes(q) ? 'flex' : 'none'; });
}
async function eliminarInstitucionNav(id, nombre) {
    if (!confirm('⚠️ ¿Eliminar la institución "' + nombre + '"?\n\nSe eliminarán TODOS sus usuarios y sus datos asociados.\n\nEsta acción no se puede deshacer.')) return;
    const fd = new FormData(); fd.append('id', id);
    try {
        const r = await fetchConSesion('/plataforma/api/instituciones_ajax.php?action=eliminar', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.ok) { mostrarToast('Institución eliminada con ' + (d.usuarios_eliminados || 0) + ' usuario(s)', 'ok'); setTimeout(() => { location.href = '/plataforma/admin/instituciones.php'; }, 800); }
        else mostrarToast(d.mensaje || 'Error', 'error');
    } catch (err) { if (err.message !== 'Sesión expirada') mostrarToast('Error de red: ' + err.message, 'error'); }
}
</script>
<?php endif; ?>