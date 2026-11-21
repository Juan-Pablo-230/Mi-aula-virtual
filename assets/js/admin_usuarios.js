const API = '/plataforma/api/cursos_ajax.php';

const VISTAS_KEY = 'aulaviva_vistas';

function getVistas() {
    try { return JSON.parse(localStorage.getItem(VISTAS_KEY)) || {}; }
    catch (e) { return {}; }
}

function setVista(seccion, vista) {
    const vistas = getVistas();
    vistas[seccion] = vista;
    localStorage.setItem(VISTAS_KEY, JSON.stringify(vistas));
}

function aplicarVista(seccion, vista) {
    const cont = document.getElementById(seccion + '-vista');
    if (!cont) return;
    cont.className = vista === 'grid' ? 'vista-grid' : 'vista-lista';
    cont.querySelectorAll(':scope > .panel-vidrio').forEach(el => {
        el.classList.toggle('compacto', vista === 'grid');
    });
    const grupo = document.querySelector(`.toggle-vista [data-target="${seccion}"]`)?.parentElement;
    if (grupo) grupo.querySelectorAll('.btn-toggle').forEach(b => b.classList.toggle('activo', b.dataset.vista === vista));
}

function cambiarVista(seccion, vista, btn) {
    aplicarVista(seccion, vista);
    setVista(seccion, vista);
}

function escapeHtml(s) {
    return String(s || '').replace(/[&<>"']/g, c => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
}

async function submitCon(accion, formData, mensajeOk) {
    try {
        const r = await fetchConSesion(API + '?action=' + accion, { method: 'POST', body: formData });
        const d = await r.json();
        if (d.ok) {
            mostrarToast(mensajeOk, 'ok');
            setTimeout(() => location.reload(), 700);
        } else {
            mostrarToast(d.mensaje || 'Error al procesar', 'error');
        }
    } catch (err) {
        if (err.message === 'Sesión expirada') return;
        mostrarToast('Error de red: ' + err.message, 'error');
    }
}

// ============================================================
// CHECKBOX DINÁMICO DE CURSOS
// ============================================================
function filtrarCursosCheckbox(input, target) {
    const q = input.value.toLowerCase().trim();
    const lista = document.getElementById(target === 'crear' ? 'crearCursosLista' : 'editarCursosLista');
    if (!lista) return;
    lista.querySelectorAll('.checkbox-cursos-item').forEach(el => {
        const nombre = el.dataset.nombre || '';
        el.style.display = (!q || nombre.includes(q)) ? '' : 'none';
    });
}

function marcarTodosCursos(target, marcar) {
    const lista = document.getElementById(target === 'crear' ? 'crearCursosLista' : 'editarCursosLista');
    if (!lista) return;
    lista.querySelectorAll('.checkbox-cursos-item').forEach(el => {
        if (el.style.display === 'none') return;
        const chk = el.querySelector('input[type="checkbox"]');
        if (chk) chk.checked = marcar;
    });
}

// ============================================================
// FORMULARIOS
// ============================================================
document.getElementById('formAsignacion')?.addEventListener('submit', (e) => {
    e.preventDefault();
    submitCon('crear_asignacion', new FormData(e.target), 'Curso creado correctamente');
});

document.getElementById('formOrientacion')?.addEventListener('submit', (e) => {
    e.preventDefault();
    submitCon('crear_orientacion', new FormData(e.target), 'Orientación creada correctamente');
});

document.getElementById('formMateria')?.addEventListener('submit', (e) => {
    e.preventDefault();
    const form = e.target;
    const marcados = form.querySelectorAll('input[name="asignaciones_ids[]"]:checked');
    if (marcados.length === 0) {
        mostrarToast('Seleccioná al menos un curso para la materia', 'error');
        return;
    }
    submitCon('crear_materia', new FormData(form), 'Materia creada correctamente');
});

document.querySelectorAll('.form-division').forEach(f => {
    f.addEventListener('submit', (e) => {
        e.preventDefault();
        const fd = new FormData(f);
        fd.append('asignacion_id', f.dataset.asignacion);
        submitCon('crear_division', fd, 'División creada correctamente');
    });
});

// ============================================================
// ACCIONES
// ============================================================
async function eliminarAsignacion(id) {
    if (!confirm('¿Eliminar este curso con TODAS sus divisiones?')) return;
    const fd = new FormData(); fd.append('id', id);
    submitCon('eliminar_asignacion', fd, 'Curso eliminado');
}

async function eliminarDivision(id) {
    if (!confirm('¿Eliminar esta división?')) return;
    const fd = new FormData(); fd.append('id', id);
    submitCon('eliminar_division', fd, 'División eliminada');
}

async function eliminarMateria(id) {
    if (!confirm('¿Eliminar esta materia?')) return;
    const fd = new FormData(); fd.append('id', id);
    submitCon('eliminar_materia', fd, 'Materia eliminada');
}

// ============================================================
// EDITAR MATERIA
// ============================================================
function abrirEditarMateria(payload) {
    const m = payload.materia || payload;
    const cursosIds = (payload.cursos_ids || []).map(Number);

    document.getElementById('editMatId').value = m.id;
    document.getElementById('editMatNombre').value = m.nombre || '';
    document.getElementById('editMatOrientacion').value = m.orientacion_id ? String(m.orientacion_id) : '';

    const lista = document.getElementById('editarCursosLista');
    if (lista) {
        lista.querySelectorAll('input[type="checkbox"][name="asignaciones_ids[]"]').forEach(chk => {
            chk.checked = cursosIds.includes(parseInt(chk.value, 10));
        });
        const searchInput = document.querySelector('.checkbox-cursos[data-target="editar"] .checkbox-cursos-buscar');
        if (searchInput) {
            searchInput.value = '';
            filtrarCursosCheckbox(searchInput, 'editar');
        }
    }

    document.getElementById('editMatError').style.display = 'none';
    document.getElementById('btnGuardarMateria').disabled = false;
    document.getElementById('btnGuardarMateria').textContent = 'Guardar cambios';
    document.getElementById('modalEditarMateria').classList.remove('oculto');
}

// ============================================================
// DUPLICAR CURSO
// ============================================================
function duplicarAsignacion(id) {
    const data = window.CURSOS_DATA || {};
    const asignaciones = data.asignaciones || [];
    const original = asignaciones.find(a => a.id == id);
    if (!original) { mostrarToast('Curso no encontrado', 'error'); return; }

    document.getElementById('dupId').value = id;
    document.getElementById('dupNombre').value = original.nombre + ' (copia)';
    document.getElementById('dupCopiarDivisiones').checked = true;
    document.getElementById('dupCopiarMaterias').checked = true;

    // Divisiones
    const divs = (data.divisionesPorAsignacion || {})[id] || [];
    const contDiv = document.getElementById('dupListaDivisiones');
    if (divs.length === 0) {
        contDiv.innerHTML = '<small style="color:#a5c3e8;">Este curso no tiene divisiones.</small>';
    } else {
        contDiv.innerHTML = divs.map(d => `
            <label class="label-checkbox" style="margin:0.15rem 0;">
                <input type="checkbox" name="divisiones_ids[]" value="${d.id}" checked>
                <span>${escapeHtml(d.nombre)}</span>
            </label>
        `).join('');
    }

    // Materias (solo se reutilizan)
    const mats = (data.materiasPorAsignacion || {})[id] || [];
    const contMat = document.getElementById('dupListaMaterias');
    if (mats.length === 0) {
        contMat.innerHTML = '<small style="color:#a5c3e8;">Este curso no tiene materias específicas.</small>';
    } else {
        contMat.innerHTML = mats.map(m => `
            <label class="label-checkbox" style="margin:0.15rem 0;">
                <input type="checkbox" name="materias_ids[]" value="${m.id}" checked>
                <span>${escapeHtml(m.nombre)}</span>
            </label>
        `).join('');
    }

    toggleSeccionDuplicar('divisiones');
    toggleSeccionDuplicar('materias');

    document.getElementById('dupError').style.display = 'none';
    document.getElementById('btnDuplicar').disabled = false;
    document.getElementById('btnDuplicar').textContent = 'Duplicar';
    document.getElementById('modalDuplicar').classList.remove('oculto');
}

function toggleSeccionDuplicar(seccion) {
    if (seccion === 'divisiones') {
        const chk = document.getElementById('dupCopiarDivisiones');
        const sec = document.getElementById('dupSeccionDivisiones');
        if (chk && sec) sec.style.display = chk.checked ? 'block' : 'none';
    } else if (seccion === 'materias') {
        const chk = document.getElementById('dupCopiarMaterias');
        const sec = document.getElementById('dupSeccionMaterias');
        if (chk && sec) sec.style.display = chk.checked ? 'block' : 'none';
    }
}

// ============================================================
// INICIALIZACIÓN
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    const vistas = getVistas();
    ['cursos', 'materias'].forEach(seccion => aplicarVista(seccion, vistas[seccion] || 'lista'));

    // Submit del modal de editar materia
    const formEdit = document.getElementById('formEditarMateria');
    if (formEdit) {
        formEdit.addEventListener('submit', async (e) => {
            e.preventDefault();
            const err = document.getElementById('editMatError');
            const btn = document.getElementById('btnGuardarMateria');
            if (err) err.style.display = 'none';

            const marcados = formEdit.querySelectorAll('input[name="asignaciones_ids[]"]:checked');
            if (marcados.length === 0) {
                if (err) { err.textContent = 'Seleccioná al menos un curso para la materia.'; err.style.display = 'block'; }
                return;
            }

            if (btn) { btn.disabled = true; btn.textContent = 'Guardando...'; }

            try {
                const fd = new FormData(formEdit);
                const r = await fetchConSesion(API + '?action=editar_materia', { method: 'POST', body: fd });
                const d = await r.json();
                if (d.ok) {
                    let msg = 'Materia actualizada';
                    if (d.asignaciones_eliminadas && d.asignaciones_eliminadas > 0) {
                        msg += '. Se quitaron ' + d.asignaciones_eliminadas + ' asignación(es) docente(s) incompatibles.';
                    }
                    mostrarToast(msg, 'ok');
                    setTimeout(() => location.reload(), 900);
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
    }

    // Submit del modal de duplicar curso
    const formDup = document.getElementById('formDuplicar');
    if (formDup) {
        formDup.addEventListener('submit', async (e) => {
            e.preventDefault();
            const err = document.getElementById('dupError');
            const btn = document.getElementById('btnDuplicar');
            if (err) err.style.display = 'none';
            if (btn) { btn.disabled = true; btn.textContent = 'Duplicando...'; }

            const fd = new FormData(formDup);
            fd.delete('divisiones_ids[]');
            fd.delete('materias_ids[]');
            if (document.getElementById('dupCopiarDivisiones').checked) {
                formDup.querySelectorAll('input[name="divisiones_ids[]"]:checked').forEach(chk => fd.append('divisiones_ids[]', chk.value));
            }
            if (document.getElementById('dupCopiarMaterias').checked) {
                formDup.querySelectorAll('input[name="materias_ids[]"]:checked').forEach(chk => {
                    fd.append('materias_ids[]', chk.value);
                });
            }

            try {
                const r = await fetchConSesion(API + '?action=duplicar_asignacion', { method: 'POST', body: fd });
                const d = await r.json();
                if (d.ok) {
                    mostrarToast('Curso duplicado correctamente', 'ok');
                    setTimeout(() => location.reload(), 800);
                } else {
                    if (err) { err.textContent = d.mensaje || 'Error al duplicar'; err.style.display = 'block'; }
                    if (btn) { btn.disabled = false; btn.textContent = 'Duplicar'; }
                }
            } catch (ex) {
                if (ex.message === 'Sesión expirada') return;
                if (err) { err.textContent = 'Error de red: ' + ex.message; err.style.display = 'block'; }
                if (btn) { btn.disabled = false; btn.textContent = 'Duplicar'; }
            }
        });
    }
});