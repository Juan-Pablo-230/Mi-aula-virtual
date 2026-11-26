// ============================================================
// ADMIN: INSTITUCIÓN → USUARIOS + TRANSFERENCIA MASIVA
// ============================================================
// Variables esperadas:
//   window.INST_USER_API
//   window.DIVISIONES
//   window.MATERIAS
//   window.INST_TRANSFER_API  (para transferir_alumnos)

let asignacionesActuales = [];
let divisionActual = 0;
let cursadasActuales = [];

function materiasCompatiblesConDivision(divisionId) {
    const div = (window.DIVISIONES || []).find(d => d.id == divisionId);
    if (!div) return [];
    const divOrient = parseInt(div.orientacion_id || 0, 10);
    const divAsig = parseInt(div.asignacion_id || 0, 10);
    return (window.MATERIAS || []).filter(m => {
        const matOrient = parseInt(m.orientacion_id || 0, 10);
        const cursosIds = (m.cursos_ids || []).map(Number);
        if (matOrient > 0 && matOrient !== divOrient) return false;
        if (cursosIds.length > 0 && !cursosIds.includes(divAsig)) return false;
        return true;
    });
}

// ============================================================
// SELECCIÓN MASIVA
// ============================================================
function marcarTodosAlumnos(marcar) {
    document.querySelectorAll('.chk-alumno').forEach(chk => {
        const fila = chk.closest('tr');
        if (fila && fila.style.display !== 'none') chk.checked = marcar;
    });
    actualizarContador();
}

function actualizarContador() {
    const n = document.querySelectorAll('.chk-alumno:checked').length;
    const el = document.getElementById('contadorSeleccionados');
    if (el) el.textContent = n;
    const btn = document.getElementById('btnTransferir');
    if (btn) btn.disabled = n === 0;
}

function filtrarAlumnos() {
    const q = (document.getElementById('buscarAlumno')?.value || '').toLowerCase().trim();
    document.querySelectorAll('#tablaUsuarios tbody tr').forEach(tr => {
        const nombre = tr.dataset.nombre || '';
        tr.style.display = (!q || nombre.includes(q)) ? '' : 'none';
    });
    actualizarContador();
}

// ============================================================
// MODAL TRANSFERIR
// ============================================================
function abrirModalTransferir() {
    const seleccionados = Array.from(document.querySelectorAll('.chk-alumno:checked')).map(c => c.value);
    if (seleccionados.length === 0) {
        mostrarToast('Seleccioná al menos un alumno', 'error');
        return;
    }
    document.getElementById('cantTransferir').textContent = seleccionados.length;
    document.getElementById('transError').style.display = 'none';
    document.getElementById('btnConfirmarTransferir').disabled = false;
    document.getElementById('btnConfirmarTransferir').textContent = 'Confirmar transferencia';
    actualizarMotivoTransferir();
    document.getElementById('modalTransferir').classList.remove('oculto');
}

function actualizarMotivoTransferir() {
    const motivo = document.getElementById('transMotivo').value;
    const lblDiv = document.getElementById('labelDivisionDestino');
    const selDiv = document.getElementById('transDivisionDestino');
    if (motivo === 'repite') {
        lblDiv.style.display = 'none';
        selDiv.removeAttribute('required');
    } else {
        lblDiv.style.display = 'block';
        selDiv.setAttribute('required', 'required');
    }
}

document.getElementById('formTransferir')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const err = document.getElementById('transError');
    const btn = document.getElementById('btnConfirmarTransferir');
    if (err) err.style.display = 'none';
    if (btn) { btn.disabled = true; btn.textContent = 'Transfiriendo...'; }

    const seleccionados = Array.from(document.querySelectorAll('.chk-alumno:checked')).map(c => c.value);
    const formData = new FormData(e.target);
    formData.delete('alumnos_ids[]');
    seleccionados.forEach(id => formData.append('alumnos_ids[]', id));

    try {
        const r = await fetchConSesion(window.INST_TRANSFER_API, { method: 'POST', body: formData });
        const d = await r.json();
        if (d.ok) {
            mostrarToast(d.procesados + ' alumno(s) transferido(s) correctamente', 'ok');
            setTimeout(() => location.reload(), 900);
        } else {
            if (err) { err.textContent = d.mensaje || 'Error al transferir'; err.style.display = 'block'; }
            if (btn) { btn.disabled = false; btn.textContent = 'Confirmar transferencia'; }
        }
    } catch (ex) {
        if (ex.message === 'Sesión expirada') return;
        if (err) { err.textContent = 'Error de red: ' + ex.message; err.style.display = 'block'; }
        if (btn) { btn.disabled = false; btn.textContent = 'Confirmar transferencia'; }
    }
});

// ============================================================
// MODAL CREAR/EDITAR (igual que antes)
// ============================================================
// Escapa texto antes de insertarlo con innerHTML (evita XSS por nombres de curso/división/materia)
function escHtmlIU(v) {
    return String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function actualizarAsignacionForm() {
    const rol = document.getElementById('userRol').value;
    const seccion = document.getElementById('seccionAsignacion');
    const cont = document.getElementById('contenidoAsignacion');
    const campoFamiliar = document.getElementById('campoFamiliar');
    if (campoFamiliar) campoFamiliar.style.display = (rol === 'alumno') ? 'block' : 'none';

    if (rol === 'alumno') {
        seccion.style.display = 'block';
        cont.innerHTML = '<div id="cursadasAlumno"></div>';
        CursadasEditor.montar(document.getElementById('cursadasAlumno'), { divisiones: window.DIVISIONES || [], materiasDe: materiasCompatiblesConDivision, cursadas: cursadasActuales });
    } else if (rol === 'docente') {
        seccion.style.display = 'block';
        const asignadas = new Set((asignacionesActuales || []).map(a => `${a.materia_id}-${a.division_id}`));
        const materiasPorDivision = {};
        (window.DIVISIONES || []).forEach(d => materiasPorDivision[d.id] = materiasCompatiblesConDivision(d.id));
        let html = `<div class="tabla-asignaciones"><table style="width:100%; border-collapse:collapse; font-size:0.82rem;"><thead><tr><th style="text-align:left; padding:0.4rem;">División \\ Materia</th>`;
        (window.MATERIAS || []).forEach(m => { html += `<th style="padding:0.4rem; font-size:0.72rem;">${escHtmlIU(m.nombre)}</th>`; });
        html += `</tr></thead><tbody>`;
        (window.DIVISIONES || []).forEach(d => {
            const compatibles = materiasPorDivision[d.id] || [];
            html += `<tr><td style="padding:0.4rem; white-space:nowrap;">${escHtmlIU(d.asignacion)} ${escHtmlIU(d.nombre)}</td>`;
            (window.MATERIAS || []).forEach(m => {
                const key = `${m.id}-${d.id}`;
                const checked = asignadas.has(key) ? 'checked' : '';
                const ok = compatibles.some(x => x.id == m.id);
                if (!ok) html += `<td style="text-align:center; padding:0.4rem; opacity:0.25;"><input type="checkbox" disabled></td>`;
                else html += `<td style="text-align:center; padding:0.4rem;"><input type="checkbox" name="asignaciones[]" value="${escHtmlIU(key)}" ${checked}></td>`;
            });
            html += `</tr>`;
        });
        html += `</tbody></table></div>`;
        cont.innerHTML = html;
    } else {
        seccion.style.display = 'none';
        cont.innerHTML = '';
    }
}

// El rol solo se elige al crear; al editar queda de solo lectura.
function configurarRolSelect(rol, esEdicion) {
    const sel = document.getElementById('userRol');
    sel.value = rol;
    sel.disabled = esEdicion;
    sel.title = esEdicion ? 'El rol no se puede cambiar' : '';
}

function abrirModalCrear() {
    document.getElementById('modalUserTitulo').textContent = 'Crear usuario';
    document.getElementById('formUser').reset();
    document.getElementById('userId').value = '';
    document.getElementById('userNombre').required = true;
    configurarRolSelect('', false);
    document.getElementById('campoPasswordInfo').style.display = 'block';
    document.getElementById('campoActivo').style.display = 'none';
    document.getElementById('campoEmail').style.display = 'none';
    document.getElementById('btnGuardarUser').textContent = 'Crear';
    document.getElementById('seccionAsignacion').style.display = 'none';
    document.getElementById('campoFamiliar').style.display = 'none';
    document.getElementById('contenidoAsignacion').innerHTML = '';
    asignacionesActuales = [];
    divisionActual = 0;
    cursadasActuales = [];
    document.getElementById('modalUser').classList.remove('oculto');
}

function abrirEditar(u, asigs, divId) {
    document.getElementById('modalUserTitulo').textContent = 'Editar usuario';
    document.getElementById('userId').value = u.id;
    document.getElementById('userNombre').value = u.nombre;
    document.getElementById('userApellido').value = u.apellido;
    document.getElementById('userDni').value = u.dni;
    document.getElementById('userEmail').value = u.email || '';
    configurarRolSelect(u.rol, true);
    document.getElementById('userNombre').required = u.rol !== 'familiar';
    document.getElementById('campoPasswordInfo').style.display = 'none';
    document.getElementById('campoActivo').style.display = 'block';
    document.getElementById('userActivo').value = u.activo;
    document.getElementById('campoEmail').style.display = 'block';
    document.getElementById('btnGuardarUser').textContent = 'Guardar cambios';
    asignacionesActuales = asigs || [];
    divisionActual = divId || 0;
    cursadasActuales = u.cursadas || [];
    actualizarAsignacionForm();
    document.getElementById('modalUser').classList.remove('oculto');
}

async function eliminarUsuario(id) {
    if (!confirm('¿Eliminar este usuario?')) return;
    const fd = new FormData(); fd.append('id', id);
    try {
        const r = await fetchConSesion(window.INST_USER_API + '&action=eliminar', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.ok) { mostrarToast('Usuario eliminado', 'ok'); setTimeout(() => location.reload(), 700); }
        else mostrarToast(d.mensaje || 'Error', 'error');
    } catch (err) {
        if (err.message === 'Sesión expirada') return;
        mostrarToast('Error de red: ' + err.message, 'error');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    actualizarContador();

    const form = document.getElementById('formUser');
    if (!form) return;

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const isEdit = document.getElementById('userId').value !== '';
        const url = window.INST_USER_API + (isEdit ? '&action=editar' : '&action=crear');
        const fd = new FormData(form);
        if (!fd.has('rol')) fd.set('rol', form.rol.value); // select bloqueado al editar
        const rol = form.rol.value;
        if (rol === 'alumno') {
            const selectDiv = form.querySelector('select[name="division_id"]');
            if (selectDiv) fd.set('division_id', selectDiv.value || '0');
        } else if (rol === 'docente') {
            fd.delete('asignaciones[]');
            form.querySelectorAll('input[name="asignaciones[]"]:checked').forEach(chk => fd.append('asignaciones[]', chk.value));
        }
        const btn = document.getElementById('btnGuardarUser');
        btn.disabled = true;
        btn.textContent = 'Guardando...';
        try {
            const r = await fetchConSesion(url, { method: 'POST', body: fd });
            const d = await r.json();
            if (d.ok) { mostrarToast(isEdit ? 'Usuario actualizado' : 'Usuario creado', 'ok'); setTimeout(() => location.reload(), 700); }
            else { mostrarToast(d.mensaje || 'Error', 'error'); btn.disabled = false; btn.textContent = isEdit ? 'Guardar cambios' : 'Crear'; }
        } catch (err) {
            if (err.message === 'Sesión expirada') return;
            mostrarToast('Error de red: ' + err.message, 'error');
            btn.disabled = false;
            btn.textContent = isEdit ? 'Guardar cambios' : 'Crear';
        }
    });
});