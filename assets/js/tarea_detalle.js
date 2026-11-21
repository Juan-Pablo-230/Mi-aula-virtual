// ============================================================
// ACTUALIZAR DIVISIONES FILTRADAS POR CURSOS DE LA MATERIA
// ============================================================
function actualizarDivisionesTarea() {
    const selMateria = document.getElementById('tareaMateria');
    const selDivision = document.getElementById('tareaDivision');
    if (!selMateria || !selDivision) return;

    const opt = selMateria.options[selMateria.selectedIndex];
    const materiaId = parseInt(opt?.value || '0', 10);
    const cursosStr = opt?.dataset.cursos || '';
    const cursosIds = cursosStr.split(',').map(n => parseInt(n, 10)).filter(n => !isNaN(n) && n > 0);

    if (!materiaId || cursosIds.length === 0) {
        selDivision.innerHTML = '<option value="">— Elegí una materia primero —</option>';
        return;
    }

    const divisionesPermitidas = new Set();
    (window.OPCIONES_TAREA || []).forEach(o => {
        if (parseInt(o.materia_id, 10) === materiaId) {
            divisionesPermitidas.add(parseInt(o.division_id, 10));
        }
    });

    const divisionesFiltradas = (window.DIVISIONES_POR_CURSO || []).filter(d => {
        const asigId = parseInt(d.asignacion_id, 10);
        const divId = parseInt(d.id, 10);
        return cursosIds.includes(asigId) && divisionesPermitidas.has(divId);
    });

    if (divisionesFiltradas.length === 0) {
        selDivision.innerHTML = '<option value="">— No hay divisiones disponibles —</option>';
        return;
    }

    selDivision.innerHTML = '<option value="">— Seleccione una división —</option>' +
        divisionesFiltradas.map(d =>
            `<option value="${d.id}">${escapeHtml(d.curso)} ${escapeHtml(d.division)}</option>`
        ).join('');

    if (divisionesFiltradas.length === 1) selDivision.selectedIndex = 1;
}

function escapeHtml(s) {
    return String(s || '').replace(/[&<>"']/g, c => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
}

// ============================================================
// VALIDAR ENTREGA
// ============================================================
function validarEntrega(form) {
    const input = form.querySelector('input[type=file]');
    const textarea = form.querySelector('textarea[name=comentario_alumno]');
    const comentario = textarea ? textarea.value.trim() : '';
    const tieneArchivo = input && input.files && input.files.length > 0;

    if (!tieneArchivo && comentario === '') {
        alert('⚠️ Adjuntá un archivo o escribí un comentario para entregar.');
        return false;
    }

    if (tieneArchivo) {
        const file = input.files[0];
        const maxBytes = (window.MAX_FILE_MB || 200) * 1024 * 1024;
        if (file.size > maxBytes) {
            const pesoMB = (file.size / 1024 / 1024).toFixed(2);
            alert('⚠️ El archivo pesa ' + pesoMB + ' MB y el límite es ' + (window.MAX_FILE_LABEL || '200M') + '.');
            input.value = '';
            return false;
        }
    }

    const btn = document.getElementById('btnEntregar');
    if (btn) { btn.disabled = true; btn.textContent = 'Enviando...'; }
    return true;
}

// ============================================================
// MODAL CALIFICAR
// ============================================================
function abrirCalificar(id, alumno, calificacion, nota, comentario, estado) {
    document.getElementById('calEntregaId').value = id;
    document.getElementById('calAlumno').textContent = alumno;
    const f = document.getElementById('formCalificar');
    f.calificacion.value = calificacion || '';
    f.nota_numerica.value = nota ?? '';
    f.comentario.value = comentario || '';
    f.estado.value = estado || 'aprobada';
    document.getElementById('modalCalificar').classList.remove('oculto');
}

document.getElementById('formCalificar')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = e.target.querySelector('button[type=submit]');
    if (btn) { btn.disabled = true; btn.textContent = 'Guardando...'; }
    try {
        const fd = new FormData(e.target);
        const r = await fetchConSesion('/plataforma/api/tareas_ajax.php?action=calificar', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.ok) { location.reload(); }
        else { alert(d.mensaje || 'Error'); if (btn) { btn.disabled = false; btn.textContent = 'Guardar'; } }
    } catch (err) {
        if (err.message === 'Sesión expirada') return;
        alert('Error de red: ' + err.message);
        if (btn) { btn.disabled = false; btn.textContent = 'Guardar'; }
    }
});

// ============================================================
// MODAL ELIMINAR TAREA
// ============================================================
function abrirEliminarTarea() {
    document.getElementById('modalEliminarTarea').classList.remove('oculto');
}

async function confirmarEliminarTarea(tareaId) {
    const btn = document.getElementById('btnConfirmarEliminarTarea');
    if (btn) { btn.disabled = true; btn.textContent = 'Eliminando...'; }
    try {
        const fd = new FormData();
        fd.append('tarea_id', tareaId);
        const r = await fetchConSesion('/plataforma/api/tareas_ajax.php?action=eliminar', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.ok) { window.location.href = '/plataforma/tareas.php'; }
        else { alert(d.mensaje || 'Error al eliminar'); if (btn) { btn.disabled = false; btn.textContent = 'Eliminar'; } }
    } catch (err) {
        if (err.message === 'Sesión expirada') return;
        alert('Error de red: ' + err.message);
        if (btn) { btn.disabled = false; btn.textContent = 'Eliminar'; }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    actualizarDivisionesTarea();

    const inputArchivo = document.getElementById('tareaArchivo');
    if (inputArchivo) {
        const maxBytes = (window.MAX_FILE_MB || 200) * 1024 * 1024;
        const maxLabel = window.MAX_FILE_LABEL || '200M';
        inputArchivo.addEventListener('change', function() {
            if (!this.files || this.files.length === 0) return;
            const avisoPrevio = document.getElementById('avisoTamano');
            if (avisoPrevio) avisoPrevio.remove();
            if (this.files[0].size > maxBytes) {
                const pesoMB = (this.files[0].size / 1024 / 1024).toFixed(2);
                const aviso = document.createElement('div');
                aviso.id = 'avisoTamano';
                aviso.className = 'alerta danger';
                aviso.style.marginTop = '0.5rem';
                aviso.textContent = '⚠️ El archivo pesa ' + pesoMB + ' MB y el límite es ' + maxLabel + '.';
                this.parentElement.appendChild(aviso);
                this.value = '';
            }
        });
    }
});