const API_CAL = '/plataforma/api/calificaciones_ajax.php';

function abrirAgregarExtra(alumnoId) {
    document.getElementById('extraAlumnoId').value = alumnoId;
    document.getElementById('formExtra').reset();
    document.getElementById('extraAlumnoId').value = alumnoId;
    document.getElementById('modalExtra').classList.remove('oculto');
}

function abrirConfirmarPromedio(alumnoId, promedio, letra) {
    document.getElementById('confAlumnoId').value = alumnoId;
    document.getElementById('confPromedio').textContent = promedio.toFixed(2);
    document.getElementById('confLetra').textContent = letra ? '(' + letra + ')' : '';
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