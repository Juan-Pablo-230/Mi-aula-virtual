// ============================================================
// ABRIR MODAL
// ============================================================
function abrirModalPub() {
    const modal = document.getElementById('modalPub');
    if (modal) modal.classList.remove('oculto');

    const chk = document.getElementById('chkAplicarTodas');
    if (chk) chk.checked = false;

    const sel = document.getElementById('pubDivision');
    if (sel) {
        sel.style.pointerEvents = '';
        sel.style.opacity = '';
        sel.style.cursor = '';
        sel.setAttribute('required', 'required');
    }

    const chkInst = document.getElementById('chkInstitucional');
    if (chkInst) chkInst.checked = false;

    const chkEv = document.getElementById('chkAgregarEvento');
    if (chkEv) chkEv.checked = false;
    const chkTodoDia = document.getElementById('chkEventoTodoDia');
    if (chkTodoDia) chkTodoDia.checked = false;
    const evTit = document.getElementById('eventoTitulo');
    if (evTit) evTit.value = '';
    const evIni = document.getElementById('eventoInicio');
    if (evIni) evIni.value = '';
    const evFin = document.getElementById('eventoFin');
    if (evFin) evFin.value = '';
    const evFecha = document.getElementById('eventoFecha');
    if (evFecha) evFecha.value = '';

    toggleInstitucional();
    toggleEvento();
    toggleTodoElDia();
    actualizarDivisiones();
}

// ============================================================
// TOGGLE INSTITUCIONAL
// ============================================================
function toggleInstitucional() {
    const chk = document.getElementById('chkInstitucional');
    if (!chk) return;
    const sec = document.getElementById('seccionMateria');
    const secDestino = document.getElementById('seccionDestinoInstitucional');
    const selMateria = document.getElementById('pubMateria');
    const selDivision = document.getElementById('pubDivision');
    const chkAplicar = document.getElementById('chkAplicarTodas');

    if (chk.checked) {
        if (sec) sec.style.display = 'none';
        if (secDestino) secDestino.style.display = 'block';
        if (selMateria) selMateria.removeAttribute('required');
        if (selDivision) selDivision.removeAttribute('required');
        if (chkAplicar) chkAplicar.checked = false;
    } else {
        if (sec) sec.style.display = 'block';
        if (secDestino) secDestino.style.display = 'none';
        if (selMateria) selMateria.setAttribute('required', 'required');
        if (selDivision) selDivision.setAttribute('required', 'required');
    }
}

// ============================================================
// TOGGLE EVENTO
// ============================================================
function toggleEvento() {
    const chk = document.getElementById('chkAgregarEvento');
    const sec = document.getElementById('seccionEvento');
    if (!chk || !sec) return;
    if (chk.checked) {
        sec.style.display = 'block';
        const et = document.getElementById('eventoTitulo');
        if (et && !et.value) {
            const pubTit = document.querySelector('input[name="titulo"]');
            if (pubTit && pubTit.value) et.value = pubTit.value;
        }
    } else {
        sec.style.display = 'none';
    }
}

function toggleTodoElDia() {
    const chk = document.getElementById('chkEventoTodoDia');
    const conHora = document.getElementById('eventoConHora');
    const soloFecha = document.getElementById('eventoSoloFecha');
    if (!chk || !conHora || !soloFecha) return;
    if (chk.checked) {
        conHora.style.display = 'none';
        soloFecha.style.display = 'block';
    } else {
        conHora.style.display = 'block';
        soloFecha.style.display = 'none';
    }
}

// ============================================================
// ACTUALIZAR DIVISIONES FILTRADAS POR CURSOS DE LA MATERIA
// ============================================================
function actualizarDivisiones() {
    const selMateria = document.getElementById('pubMateria');
    const selDiv = document.getElementById('pubDivision');
    if (!selMateria || !selDiv) return;

    const opt = selMateria.options[selMateria.selectedIndex];
    if (!opt || opt.value === '') {
        selDiv.innerHTML = '<option value="">— Elegí una materia primero —</option>';
        return;
    }

    const cursosStr = opt?.dataset.cursos || '';
    const divsDocenteStr = opt?.dataset.divisionesDocente || '';

    const cursosIds = cursosStr.split(',').map(n => parseInt(n, 10)).filter(n => !isNaN(n) && n > 0);
    const divsDocenteIds = divsDocenteStr.split(',').map(n => parseInt(n, 10)).filter(n => !isNaN(n) && n > 0);

    if (cursosIds.length === 0) {
        selDiv.innerHTML = '<option value="">— La materia no tiene cursos asignados —</option>';
        return;
    }

    let divisionesFiltradas = (window.DIVISIONES_POR_CURSO || []).filter(d =>
        cursosIds.includes(parseInt(d.asignacion_id, 10))
    );

    if (divsDocenteIds.length > 0) {
        divisionesFiltradas = divisionesFiltradas.filter(d =>
            divsDocenteIds.includes(parseInt(d.id, 10))
        );
    }

    if (divisionesFiltradas.length === 0) {
        selDiv.innerHTML = '<option value="">— No hay divisiones disponibles —</option>';
        return;
    }

    selDiv.innerHTML = '<option value="">— Seleccione una división —</option>' +
        divisionesFiltradas.map(d =>
            `<option value="${d.id}">${escapeHtml(d.curso)} ${escapeHtml(d.division)}</option>`
        ).join('');

    if (divisionesFiltradas.length === 1) selDiv.selectedIndex = 1;
}

function escapeHtml(s) {
    return String(s || '').replace(/[&<>"']/g, c => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
}

// ============================================================
// SUBMIT CREAR
// ============================================================
document.getElementById('formPub')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;
    const chkInst = document.getElementById('chkInstitucional');
    const esInstitucional = chkInst && chkInst.checked;
    const chk = document.getElementById('chkAplicarTodas');
    const selDiv = document.getElementById('pubDivision');
    const err = document.getElementById('pubError');
    const btn = form.querySelector('button[type=submit]');

    if (err) err.style.display = 'none';

    if (!esInstitucional && selDiv) {
        const aplicarTodas = chk && chk.checked;
        if (!aplicarTodas && selDiv.value === '') {
            if (err) { err.textContent = 'Seleccioná una división o marcá "Publicar en todas las divisiones".'; err.style.display = 'block'; }
            return;
        }
    }

    const chkEv = document.getElementById('chkAgregarEvento');
    if (chkEv && chkEv.checked) {
        const evTit = document.getElementById('eventoTitulo').value.trim();
        const chkTodoDia = document.getElementById('chkEventoTodoDia');
        const todoDia = chkTodoDia && chkTodoDia.checked;
        if (!evTit) { if (err) { err.textContent = 'El evento debe tener un título.'; err.style.display = 'block'; } return; }
        if (todoDia) {
            const fecha = document.getElementById('eventoFecha').value;
            if (!fecha) { if (err) { err.textContent = 'Seleccioná la fecha del evento.'; err.style.display = 'block'; } return; }
        } else {
            const ini = document.getElementById('eventoInicio').value;
            const fin = document.getElementById('eventoFin').value;
            if (!ini || !fin) { if (err) { err.textContent = 'Completá inicio y fin del evento.'; err.style.display = 'block'; } return; }
            if (new Date(fin) <= new Date(ini)) { if (err) { err.textContent = 'El fin del evento debe ser posterior al inicio.'; err.style.display = 'block'; } return; }
        }
    }

    btn.disabled = true;
    btn.textContent = 'Subiendo...';

    try {
        const fd = new FormData(form);
        const r = await fetchConSesion('/plataforma/api/publicaciones_ajax.php?action=crear', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.ok) location.reload();
        else {
            if (err) { err.textContent = d.mensaje || 'Error al publicar'; err.style.display = 'block'; }
            btn.disabled = false;
            btn.textContent = 'Publicar';
        }
    } catch (err2) {
        if (err2.message === 'Sesión expirada') return;
        alert('Error de red: ' + err2.message);
        btn.disabled = false;
        btn.textContent = 'Publicar';
    }
});

// ============================================================
// EDITAR PUBLICACIÓN
// ============================================================
function abrirEditarPub(pub) {
    document.getElementById('editPubId').value = pub.id;
    document.getElementById('editPubTitulo').value = pub.titulo || '';
    document.getElementById('editPubContenido').value = pub.contenido || '';
    document.getElementById('editPubAño').value = pub.año_lectivo || new Date().getFullYear();
    document.getElementById('editPubError').style.display = 'none';
    document.getElementById('btnGuardarEditPub').disabled = false;
    document.getElementById('btnGuardarEditPub').textContent = 'Guardar cambios';
    document.getElementById('modalEditarPub').classList.remove('oculto');
}

document.getElementById('formEditarPub')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const err = document.getElementById('editPubError');
    const btn = document.getElementById('btnGuardarEditPub');
    if (err) err.style.display = 'none';
    if (btn) { btn.disabled = true; btn.textContent = 'Guardando...'; }
    try {
        const fd = new FormData(e.target);
        const r = await fetchConSesion('/plataforma/api/publicaciones_ajax.php?action=editar', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.ok) {
            mostrarToast('Publicación actualizada', 'ok');
            setTimeout(() => location.reload(), 700);
        } else {
            if (err) { err.textContent = d.mensaje || 'Error al guardar'; err.style.display = 'block'; }
            if (btn) { btn.disabled = false; btn.textContent = 'Guardar cambios'; }
        }
    } catch (ex) {
        if (ex.message === 'Sesión expirada') return;
        if (err) { err.textContent = 'Error de red: ' + ex.message; err.style.display = 'block'; }
        if (btn) { btn.disabled = false; btn.textContent = 'Guardar cambios'; }
    }
});

// ============================================================
// ELIMINAR PUBLICACIÓN
// ============================================================
async function eliminarPublicacion(id) {
    if (!confirm('¿Eliminar esta publicación? Esta acción no se puede deshacer.')) return;
    const fd = new FormData();
    fd.append('pub_id', id);
    try {
        const r = await fetchConSesion('/plataforma/api/publicaciones_ajax.php?action=eliminar_publicacion', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.ok) {
            mostrarToast('Publicación eliminada', 'ok');
            setTimeout(() => location.reload(), 700);
        } else {
            mostrarToast(d.mensaje || 'Error al eliminar', 'error');
        }
    } catch (ex) {
        if (ex.message === 'Sesión expirada') return;
        mostrarToast('Error de red: ' + ex.message, 'error');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    actualizarDivisiones();
});