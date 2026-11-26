const API_CAL = '/plataforma/api/calificaciones_ajax.php';

function abrirAgregarExtra(alumnoId) {
    document.getElementById('extraAlumnoId').value = alumnoId;
    document.getElementById('formExtra').reset();
    document.getElementById('extraAlumnoId').value = alumnoId;
    document.getElementById('modalExtra').classList.remove('oculto');
}

function abrirConfirmarPromedio(alumnoId, promedio, formateado) {
    document.getElementById('confAlumnoId').value = alumnoId;
    document.getElementById('confPromedio').textContent = formateado || promedio.toFixed(2);
    document.getElementById('modalConfirmar').classList.remove('oculto');
}

document.getElementById('formExtra')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const r = await fetchConSesion(API_CAL + '?action=agregar_extra', {
        method: 'POST',
        body: new FormData(e.target)
    });
    const d = await r.json();
    if (d.ok) { mostrarToast('Nota cargada', 'ok'); setTimeout(() => location.reload(), 700); }
    else alert(d.mensaje || 'Error');
});

document.getElementById('formConfirmar')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const r = await fetchConSesion(API_CAL + '?action=confirmar_promedio', {
        method: 'POST',
        body: new FormData(e.target)
    });
    const d = await r.json();
    if (d.ok) { mostrarToast('Promedio confirmado', 'ok'); setTimeout(() => location.reload(), 700); }
    else alert(d.mensaje || 'Error');
});

async function eliminarExtra(id) {
    if (!confirm('¿Eliminar esta nota extra?')) return;
    const fd = new FormData();
    fd.append('extra_id', id);
    const r = await fetchConSesion(API_CAL + '?action=eliminar_extra', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.ok) { mostrarToast('Nota eliminada', 'ok'); setTimeout(() => location.reload(), 700); }
    else alert(d.mensaje || 'Error');
}

// ============================================================
// CALIFICACIÓN GLOBAL DE LA MATERIA (p. ej. "Conducta"): una nota para todos los alumnos
// ============================================================
function abrirNuevaGlobal() {
    document.getElementById('formGlobal').reset();
    document.getElementById('modalGlobal').classList.remove('oculto');
}

document.getElementById('formGlobal')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const notas = {};
    e.target.querySelectorAll('.nota-global').forEach(i => { if (i.value.trim() !== '') notas[i.dataset.alumno] = i.value.trim(); });
    if (!Object.keys(notas).length) { mostrarToast('Cargá la nota de al menos un alumno', 'error'); return; }
    fd.append('notas', JSON.stringify(notas));
    const r = await fetchConSesion(API_CAL + '?action=crear_global', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.ok) { mostrarToast('Calificación global cargada', 'ok'); setTimeout(() => location.reload(), 600); }
    else mostrarToast(d.mensaje || 'Error', 'error');
});

async function eliminarGlobal(id, nombre) {
    if (!confirm('¿Eliminar la calificación global "' + nombre + '" de TODOS los alumnos?')) return;
    const fd = new FormData(); fd.append('global_id', id);
    const r = await fetchConSesion(API_CAL + '?action=eliminar_global', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.ok) { mostrarToast('Eliminada', 'ok'); setTimeout(() => location.reload(), 600); }
    else mostrarToast(d.mensaje || 'Error', 'error');
}
