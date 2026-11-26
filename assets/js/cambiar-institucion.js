// Cambio de institución para quien trabaja en más de una (con un rol distinto en cada una)
const API_CAMBIO_INST = '/plataforma/api/cambiar_institucion.php';

async function abrirCambioInstitucion() {
    const lista = document.getElementById('listaCambioInst');
    lista.innerHTML = '<p>Cargando…</p>';
    document.getElementById('modalCambioInst').classList.remove('oculto');
    try {
        const r = await fetchConSesion(API_CAMBIO_INST + '?action=listar');
        const d = await r.json();
        if (!d.ok) throw new Error(d.mensaje || 'Error');
        lista.innerHTML = '';
        d.instituciones.forEach(i => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'opcion-institucion' + (i.institucion_id === d.actual ? ' actual' : '');
            const n = document.createElement('strong'); n.textContent = i.nombre;
            const s = document.createElement('small');
            s.textContent = i.rol.charAt(0).toUpperCase() + i.rol.slice(1) + (i.institucion_id === d.actual ? ' · estás acá' : '');
            b.append(n, s);
            if (i.institucion_id !== d.actual) b.addEventListener('click', () => cambiarInstitucion(i.institucion_id));
            else b.disabled = true;
            lista.appendChild(b);
        });
    } catch (err) {
        if (err.message === 'Sesión expirada') return;
        lista.textContent = 'No se pudo cargar: ' + err.message;
    }
}

async function cambiarInstitucion(id) {
    const fd = new FormData(); fd.append('institucion_id', id);
    try {
        const r = await fetchConSesion(API_CAMBIO_INST + '?action=cambiar', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.ok) { location.href = '/plataforma/publicaciones.php'; }
        else mostrarToast(d.mensaje || 'Error', 'error');
    } catch (err) {
        if (err.message !== 'Sesión expirada') mostrarToast('Error de red: ' + err.message, 'error');
    }
}
