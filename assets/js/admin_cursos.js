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

// ============================================================
// EDITAR CURSO (nombre, modo de asistencia, divisiones y orientación)
// Todo se edita en el modal y se confirma junto con "Guardar cambios".
// ============================================================
let ec = null; // { id, divs: [{id|null, nombre, orientacion_id}], eliminadas: [ids], sucio: bool }

function ecOrientaciones() {
    const o = {};
    ((window.CURSOS_DATA || {}).orientaciones || []).forEach(x => o[x.id] = x.nombre);
    return o;
}

function ecRenderDivisiones() {
    const orient = ecOrientaciones();
    document.getElementById('ecDivisiones').innerHTML = ec.divs.length === 0
        ? '<small style="color:#a5c3e8;">Sin divisiones.</small>'
        : ec.divs.map((d, i) => `
            <div class="division-chip">
                <strong>${escapeHtml(d.nombre)}</strong>
                ${d.orientacion_id && orient[d.orientacion_id] ? `<small>(${escapeHtml(orient[d.orientacion_id])})</small>` : ''}
                ${d.id ? '' : '<small style="color:#fbbf24;">nueva</small>'}
                <button type="button" class="btn-peligro-sm" onclick="ecQuitarDivision(${i})">🗑️</button>
            </div>`).join('');
}

function abrirEditarCurso(id) {
    const data = window.CURSOS_DATA || {};
    const curso = (data.asignaciones || []).find(a => a.id == id);
    if (!curso) { mostrarToast('Curso no encontrado', 'error'); return; }

    ec = {
        id: Number(id),
        divs: ((data.divisionesPorAsignacion || {})[id] || []).map(d => ({ id: Number(d.id), nombre: d.nombre, orientacion_id: d.orientacion_id ? Number(d.orientacion_id) : null })),
        eliminadas: [],
        sucio: false,
    };
    document.getElementById('ecId').value = id;
    document.getElementById('ecNombre').value = curso.nombre;
    const chk = document.getElementById('ecPorDia');
    if (chk) { chk.checked = Number(curso.asistencia_por_dia) === 1; chk.dataset.original = chk.checked ? '1' : '0'; }
    document.getElementById('ecError').style.display = 'none';
    document.getElementById('formEcDivision').reset();
    ecRenderDivisiones();
    document.getElementById('modalEditarCurso').classList.remove('oculto');
}

function cerrarEditarCurso() {
    if (ec && (ec.sucio || ecHayCambiosFormulario()) && !confirm('Hay cambios sin guardar. ¿Cerrar y descartarlos?')) return;
    ec = null;
    cerrarModal('modalEditarCurso');
}

function ecHayCambiosFormulario() {
    const curso = ((window.CURSOS_DATA || {}).asignaciones || []).find(a => a.id == ec.id);
    const chk = document.getElementById('ecPorDia');
    return (curso && document.getElementById('ecNombre').value.trim() !== curso.nombre)
        || (chk && (chk.checked ? '1' : '0') !== chk.dataset.original);
}

function ecQuitarDivision(i) {
    const d = ec.divs[i];
    if (d.id) ec.eliminadas.push(d.id);
    ec.divs.splice(i, 1);
    ec.sucio = true;
    ecRenderDivisiones();
}

document.getElementById('formEcDivision')?.addEventListener('submit', (e) => {
    e.preventDefault();
    if (!ec) return;
    const f = e.target;
    const nombre = f.nombre.value.trim().toUpperCase();
    if (!nombre) return;
    if (ec.divs.some(d => d.nombre.toUpperCase() === nombre)) {
        const err = document.getElementById('ecError');
        err.textContent = 'Ya existe la división ' + nombre + ' en este curso.'; err.style.display = 'block';
        return;
    }
    document.getElementById('ecError').style.display = 'none';
    ec.divs.push({ id: null, nombre, orientacion_id: f.orientacion_id.value ? Number(f.orientacion_id.value) : null });
    ec.sucio = true;
    f.reset();
    ecRenderDivisiones();
});

async function ecLlamar(accion, fd) {
    const r = await fetchConSesion(API + '?action=' + accion, { method: 'POST', body: fd });
    const d = await r.json();
    if (!d.ok) throw new Error(d.mensaje || 'No se pudo guardar');
}

document.getElementById('formEditarCurso')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!ec) return;
    const form = e.target;
    const err = document.getElementById('ecError');
    err.style.display = 'none';

    const nuevas = ec.divs.filter(d => !d.id);
    const chk = document.getElementById('ecPorDia');
    const avisos = [];
    if (chk && (chk.checked ? '1' : '0') !== chk.dataset.original) {
        avisos.push(chk.checked
            ? 'Este curso pasará a tomar asistencia por DÍA COMPLETO (una sola lista por día para todas las materias). Las asistencias por materia ya cargadas no se borran, pero dejan de mostrarse al pasar lista en este curso.'
            : 'Este curso volverá a tomar asistencia POR MATERIA. Las asistencias por día ya cargadas no se borran.');
    }
    if (ec.eliminadas.length) {
        avisos.push('Se eliminarán ' + ec.eliminadas.length + ' división(es).');
    }
    if (avisos.length && !confirm(avisos.join('\n\n') + '\n\n¿Guardar los cambios?')) return;

    const btn = document.querySelector('button[form="formEditarCurso"]');
    if (btn) btn.disabled = true;
    try {
        await ecLlamar('editar_asignacion', new FormData(form));
        for (const id of ec.eliminadas) {
            const fd = new FormData(); fd.append('id', id);
            await ecLlamar('eliminar_division', fd);
        }
        for (const d of nuevas) {
            const fd = new FormData();
            fd.append('asignacion_id', ec.id); fd.append('nombre', d.nombre);
            if (d.orientacion_id) fd.append('orientacion_id', d.orientacion_id);
            await ecLlamar('crear_division', fd);
        }
        mostrarToast('Curso actualizado', 'ok');
        ec.sucio = false;
        setTimeout(() => location.reload(), 600);
    } catch (ex) {
        if (ex.message === 'Sesión expirada') return;
        err.textContent = ex.message + ' (los cambios anteriores a este error ya se guardaron; recargá la página para verlos)';
        err.style.display = 'block';
        if (btn) btn.disabled = false;
    }
});

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
    // La copia hereda el modo de asistencia del curso original (se puede cambiar acá)
    const dupPorDia = document.getElementById('dupPorDia');
    if (dupPorDia) dupPorDia.checked = Number(original.asistencia_por_dia) === 1;

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

    // Materias (desmarcadas por defecto)
    const mats = (data.materiasPorAsignacion || {})[id] || [];
    const contMat = document.getElementById('dupListaMaterias');
    if (mats.length === 0) {
        contMat.innerHTML = '<small style="color:#a5c3e8;">Este curso no tiene materias específicas.</small>';
    } else {
        contMat.innerHTML = mats.map(m => `
            <label class="label-checkbox" style="margin:0.15rem 0;">
                <input type="checkbox" name="materias_ids[]" value="${m.id}">
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