// ============================================================
// ELIMINAR PUBLICACIÓN
// ============================================================
async function eliminarPublicacion(id) {
    if (!confirm('¿Eliminar esta publicación?')) return;
    const fd = new FormData();
    fd.append('pub_id', id);
    const r = await fetchConSesion('/plataforma/api/publicaciones_ajax.php?action=eliminar_publicacion', {
        method: 'POST',
        body: fd
    });
    const d = await r.json();
    if (d.ok) mostrarToast('Publicación eliminada', 'ok');
    else mostrarToast(d.mensaje || 'Error', 'error');
    setTimeout(() => location.reload(), 800);
}

// ============================================================
// ELIMINAR TAREA
// ============================================================
async function eliminarTarea(id) {
    if (!confirm('¿Eliminar esta tarea y todas sus entregas?')) return;
    const fd = new FormData();
    fd.append('tarea_id', id);
    const r = await fetchConSesion('/plataforma/api/tareas_ajax.php?action=eliminar', {
        method: 'POST',
        body: fd
    });
    const d = await r.json();
    if (d.ok) mostrarToast('Tarea eliminada', 'ok');
    else mostrarToast(d.mensaje || 'Error', 'error');
    setTimeout(() => location.reload(), 800);
}

// ============================================================
// ELIMINAR BIBLIOGRAFÍA
// ============================================================
async function eliminarBib(id) {
    if (!confirm('¿Eliminar este documento de la bibliografía?')) return;
    const fd = new FormData();
    fd.append('bib_id', id);
    const r = await fetchConSesion('/plataforma/api/publicaciones_ajax.php?action=eliminar_bibliografia', {
        method: 'POST',
        body: fd
    });
    const d = await r.json();
    if (d.ok) mostrarToast('Documento eliminado', 'ok');
    else mostrarToast(d.mensaje || 'Error', 'error');
    setTimeout(() => location.reload(), 800);
}

// ============================================================
// MODALES DE PUBLICACIÓN / TAREA / BIBLIOGRAFÍA
// ============================================================
function abrirModalPub(materiaId, divisionId, materiaNombre, divisionNombre) {
    document.getElementById('pubMateriaId').value = materiaId;
    document.getElementById('pubDivisionId').value = divisionId;
    document.getElementById('pubContexto').textContent = materiaNombre + ' · ' + divisionNombre;
    document.getElementById('pubError').style.display = 'none';
    document.getElementById('modalPubMateria').classList.remove('oculto');
}

function abrirModalTarea(materiaId, divisionId, materiaNombre, divisionNombre) {
    document.getElementById('tareaMateriaId').value = materiaId;
    document.getElementById('tareaDivisionId').value = divisionId;
    document.getElementById('tareaContexto').textContent = materiaNombre + ' · ' + divisionNombre;
    document.getElementById('tareaError').style.display = 'none';
    document.getElementById('modalTareaMateria').classList.remove('oculto');
}

function abrirModalBib(materiaId, divisionId, materiaNombre, divisionNombre) {
    document.getElementById('bibMateriaId').value = materiaId;
    document.getElementById('bibDivisionId').value = divisionId;
    document.getElementById('bibContexto').textContent = materiaNombre + ' · ' + divisionNombre;
    document.getElementById('bibErrorMat').style.display = 'none';
    document.getElementById('modalBibMateria').classList.remove('oculto');
}

// ============================================================
// SUBMITS DE LOS MODALES
// ============================================================
document.getElementById('formPubMateria')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const err = document.getElementById('pubError');
    err.style.display = 'none';
    const fd = new FormData(e.target);
    fd.set('materia_id', document.getElementById('pubMateriaId').value);
    fd.set('division_id', document.getElementById('pubDivisionId').value);
    const r = await fetchConSesion('/plataforma/api/publicaciones_ajax.php?action=crear', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.ok) location.reload();
    else { err.textContent = d.mensaje; err.style.display = 'block'; }
});

document.getElementById('formTareaMateria')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const err = document.getElementById('tareaError');
    err.style.display = 'none';
    const fd = new FormData(e.target);
    fd.set('materia_id', document.getElementById('tareaMateriaId').value);
    fd.set('division_id', document.getElementById('tareaDivisionId').value);
    const r = await fetchConSesion('/plataforma/api/tareas_ajax.php?action=crear', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.ok) location.reload();
    else { err.textContent = d.mensaje; err.style.display = 'block'; }
});

document.getElementById('formBibMateria')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const err = document.getElementById('bibErrorMat');
    err.style.display = 'none';
    const fd = new FormData(e.target);
    fd.set('materia_id', document.getElementById('bibMateriaId').value);
    fd.set('division_id', document.getElementById('bibDivisionId').value);
    const r = await fetchConSesion('/plataforma/api/publicaciones_ajax.php?action=bibliografia', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.ok) location.reload();
    else { err.textContent = d.mensaje; err.style.display = 'block'; }
});