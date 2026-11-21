function abrirModalBib() {
    const m = document.getElementById('modalBib');
    if (m) {
        const e = document.getElementById('bibError');
        if (e) e.style.display = 'none';
        m.classList.remove('oculto');
    }
}

function actualizarDivisionesBib() {
    const sel = document.getElementById('bibMateria');
    const div = document.getElementById('bibDivision');
    if (!sel || !div) return;

    div.innerHTML = '<option value="">— Elegí una división —</option>';
    const opt = sel.options[sel.selectedIndex];
    let arr = [];
    try { arr = JSON.parse(opt.dataset.divs || '[]'); } catch (e) {}

    if (arr.length === 0) {
        div.innerHTML = '<option value="">— La materia no tiene divisiones —</option>';
        return;
    }
    arr.forEach(d => {
        const o = document.createElement('option');
        o.value = d.id;
        o.textContent = d.label;
        div.appendChild(o);
    });
}

function eliminarBib(id, nombre) {
    if (!confirm('¿Eliminar "' + nombre + '" de la bibliografía?')) return;
    const fd = new FormData();
    fd.append('bib_id', id);
    fetchConSesion('/plataforma/api/publicaciones_ajax.php?action=eliminar_bibliografia', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.ok) { mostrarToast('Documento eliminado', 'ok'); setTimeout(() => location.reload(), 700); }
            else mostrarToast(d.mensaje || 'Error', 'error');
        })
        .catch(e => { if (e.message !== 'Sesión expirada') mostrarToast('Error de red: ' + e.message, 'error'); });
}

document.addEventListener('DOMContentLoaded', function() {
    const formBib = document.getElementById('formBib');
    if (!formBib) return;

    actualizarDivisionesBib();

    formBib.addEventListener('submit', async function(e) {
        e.preventDefault();
        const btn = document.getElementById('btnSubirBib');
        const err = document.getElementById('bibError');
        if (btn) { btn.disabled = true; btn.textContent = 'Subiendo y escaneando...'; }
        if (err) err.style.display = 'none';

        try {
            const r = await fetchConSesion('/plataforma/api/publicaciones_ajax.php?action=bibliografia', {
                method: 'POST',
                body: new FormData(formBib)
            });
            const d = await r.json();
            if (d.ok) {
                mostrarToast('Documento subido', 'ok');
                setTimeout(() => location.reload(), 700);
            } else {
                if (err) { err.textContent = d.mensaje || 'Error al subir'; err.style.display = 'block'; }
                if (btn) { btn.disabled = false; btn.textContent = 'Subir'; }
            }
        } catch (e2) {
            if (e2.message === 'Sesión expirada') return;
            if (err) { err.textContent = 'Error de red: ' + e2.message; err.style.display = 'block'; }
            if (btn) { btn.disabled = false; btn.textContent = 'Subir'; }
        }
    });
});