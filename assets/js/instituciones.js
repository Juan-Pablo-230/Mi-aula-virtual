// ============================================================
// ADMIN: INSTITUCIONES
// ============================================================
const API_INST = '/plataforma/api/instituciones_ajax.php';

const API_GEO = '/plataforma/api/geo_ajax.php';

async function cargarPaises() {
    const sel = document.getElementById('instPais');
    if (!sel) return;
    try {
        const r = await fetchConSesion(API_GEO + '?action=paises');
        const d = await r.json();
        if (!d.ok) throw new Error(d.mensaje || 'Error');
        sel.innerHTML = '<option value="">— Elegí un país —</option>';
        d.paises.forEach(p => { const o = document.createElement('option'); o.value = p.valor; o.textContent = p.nombre; sel.appendChild(o); });
    } catch (ex) {
        if (ex.message === 'Sesión expirada') return;
        sel.innerHTML = '<option value="">No se pudo cargar la lista</option>';
        mostrarToast('No se pudo cargar los países: ' + ex.message, 'error');
    }
}

// Si no hay lista de localidades (API sin acceso), se escribe a mano
function localidadComoTexto() {
    const el = document.getElementById('instLocalidad');
    if (el && el.tagName === 'INPUT') { el.disabled = false; return; }
    const inp = document.createElement('input');
    inp.type = 'text'; inp.name = 'localidad'; inp.id = 'instLocalidad'; inp.required = true; inp.maxlength = 150;
    inp.placeholder = 'Escribí la localidad';
    el.replaceWith(inp);
}

function localidadComoSelect() {
    const el = document.getElementById('instLocalidad');
    if (el && el.tagName === 'SELECT') return el;
    const sel = document.createElement('select');
    sel.name = 'localidad'; sel.id = 'instLocalidad'; sel.required = true;
    el.replaceWith(sel);
    return sel;
}

async function cargarLocalidades(pais) {
    const sel = localidadComoSelect();
    sel.disabled = true;
    if (!pais) { sel.innerHTML = '<option value="">— Elegí primero el país —</option>'; return; }
    sel.innerHTML = '<option value="">Cargando localidades…</option>';
    try {
        const r = await fetchConSesion(API_GEO + '?action=localidades&pais=' + encodeURIComponent(pais));
        const d = await r.json();
        if (!d.ok) throw new Error(d.mensaje || 'Error');
        if (d.manual || !(d.localidades || []).length) { localidadComoTexto(); return; }
        sel.innerHTML = '<option value="">— Elegí una localidad —</option>';
        d.localidades.forEach(l => { const o = document.createElement('option'); o.value = l; o.textContent = l; sel.appendChild(o); });
        sel.disabled = false;
    } catch (ex) {
        if (ex.message === 'Sesión expirada') return;
        localidadComoTexto();
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('formInstitucion');
    if (!form) return;

    cargarPaises();
    document.getElementById('instPais').addEventListener('change', e => cargarLocalidades(e.target.value));
    // Altura: solo números
    document.getElementById('instAltura').addEventListener('input', e => { e.target.value = e.target.value.replace(/\D/g, ''); });

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