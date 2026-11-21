// ============================================================
// ADMIN: INSTITUCIONES
// ============================================================
const API_INST = '/plataforma/api/instituciones_ajax.php';

document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('formInstitucion');
    if (!form) return;

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const err = document.getElementById('instError');
        const btn = document.getElementById('btnCrearInst');
        if (err) err.style.display = 'none';
        if (btn) { btn.disabled = true; btn.textContent = 'Creando...'; }

        try {
            const r = await fetchConSesion(API_INST + '?action=crear_con_duenio', {
                method: 'POST',
                body: new FormData(form)
            });
            const d = await r.json();
            if (d.ok) {
                let msg = 'Institución creada';
                if (d.duenio) {
                    msg += '\n\nDueño: ' + d.duenio.nombre + ' ' + d.duenio.apellido
                        + '\nDNI: ' + d.duenio.dni
                        + '\nContraseña: ' + d.duenio.password
                        + (d.duenio.creado ? '\n\n(Se creó un usuario nuevo)' : '\n\n(Se vinculó un usuario existente)');
                }
                alert(msg);
                location.reload();
            } else {
                if (err) { err.textContent = d.mensaje || 'Error al crear'; err.style.display = 'block'; }
                if (btn) { btn.disabled = false; btn.textContent = 'Crear institución'; }
            }
        } catch (ex) {
            if (ex.message === 'Sesión expirada') return;
            if (err) { err.textContent = 'Error de red: ' + ex.message; err.style.display = 'block'; }
            if (btn) { btn.disabled = false; btn.textContent = 'Crear institución'; }
        }
    });
});

async function eliminarInstitucion(id, nombre) {
    if (!confirm('⚠️ ¿Eliminar la institución "' + nombre + '"?\n\nSe eliminarán TODOS sus usuarios y sus datos asociados.\n\nEsta acción no se puede deshacer.')) return;

    const fd = new FormData();
    fd.append('id', id);

    try {
        const r = await fetchConSesion(API_INST + '?action=eliminar', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.ok) {
            mostrarToast('Institución eliminada con ' + (d.usuarios_eliminados || 0) + ' usuario(s)', 'ok');
            setTimeout(() => location.reload(), 800);
        } else {
            mostrarToast(d.mensaje || 'Error', 'error');
        }
    } catch (err) {
        if (err.message !== 'Sesión expirada') mostrarToast('Error de red: ' + err.message, 'error');
    }
}