// ============================================================
// ELIMINAR TAREA DESDE EL LISTADO
// ============================================================
async function eliminarDesdeListado(tareaId, titulo) {
    if (!confirm(`¿Eliminar la tarea "${titulo}" y todas sus entregas?\n\nEsta acción no se puede deshacer.`)) return;

    try {
        const fd = new FormData();
        fd.append('tarea_id', tareaId);

        const r = await fetchConSesion('/plataforma/api/tareas_ajax.php?action=eliminar', {
            method: 'POST',
            body: fd
        });
        const d = await r.json();

        if (d.ok) {
            if (typeof mostrarToast === 'function') {
                mostrarToast('Tarea eliminada', 'ok');
                setTimeout(() => location.reload(), 800);
            } else {
                location.reload();
            }
        } else {
            alert(d.mensaje || 'Error al eliminar');
        }
    } catch (err) {
        if (err.message === 'Sesión expirada') return;
        alert('Error de red: ' + err.message);
    }
}

// Exportar al scope global por si el navegador lo requiere
window.eliminarDesdeListado = eliminarDesdeListado;