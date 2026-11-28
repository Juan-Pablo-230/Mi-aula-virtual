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

// Ciclos lectivos
document.getElementById('formCiclo')?.addEventListener('submit', (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    if (fd.get('fecha_fin') <= fd.get('fecha_inicio')) {
        mostrarToast('La fecha de fin debe ser posterior a la de inicio', 'error');
        return;
    }
    submitCon('crear_ciclo', fd, 'Ciclo lectivo creado');
});

function activarCiclo(id) {
    if (!confirm('¿Activar este ciclo lectivo? Pasa a ser el ciclo en curso de la institución.')) return;
    const fd = new FormData(); fd.append('id', id);
    submitCon('activar_ciclo', fd, 'Ciclo activado');
}

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
    const recargar = ec && ec.recargar;
    ec = null;
    cerrarModal('modalEditarCurso');
    if (recargar) location.reload();   // para que el resto de la pantalla muestre las divisiones nuevas
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

document.getElementById('formEcDivision')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!ec) return;
    const f = e.target;
    const nombre = f.nombre.value.trim().toUpperCase();
    if (!nombre) return;
    const err = document.getElementById('ecError');
    if (ec.divs.some(d => d.nombre.toUpperCase() === nombre)) {
        err.textContent = 'Ya existe la división ' + nombre + ' en este curso.'; err.style.display = 'block';
        return;
    }
    err.style.display = 'none';
    // La división se guarda en la base de datos en el momento
    const fd = new FormData();
    fd.append('asignacion_id', ec.id); fd.append('nombre', nombre);
    const orientacionId = f.orientacion_id.value ? Number(f.orientacion_id.value) : null;
    if (orientacionId) fd.append('orientacion_id', orientacionId);
    const btn = f.querySelector('button[type="submit"]');
    if (btn) btn.disabled = true;
    try {
        const r = await fetchConSesion(API + '?action=crear_division', { method: 'POST', body: fd });
        const d = await r.json();
        if (!d.ok) throw new Error(d.mensaje || 'No se pudo crear la división');
        ec.divs.push({ id: Number(d.id), nombre, orientacion_id: orientacionId });
        ec.recargar = true;
        const lista = ((window.CURSOS_DATA || {}).divisionesPorAsignacion ||= {});
        (lista[ec.id] ||= []).push({ id: Number(d.id), nombre, orientacion_id: orientacionId });
        mostrarToast('División ' + nombre + ' creada', 'ok');
        f.reset();
        ecRenderDivisiones();
    } catch (ex) {
        if (ex.message === 'Sesión expirada') return;
        err.textContent = ex.message; err.style.display = 'block';
    } finally {
        if (btn) btn.disabled = false;
    }
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
        ec.sucio = false; ec.recargar = false;
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
    const propia = String(m.asistencia_propia) === '1';
    document.getElementById('editMatPropia').checked = propia;
    document.getElementById('editMatPeso').value = m.valor_falta_propia || 1;
    document.getElementById('editMatPeso').disabled = !propia;

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

// ============================================================
// TRANSFERIR ALUMNOS: asistente en pasos
//   (pestañas por ciclo) → 1. ¿de qué división? → 2. ¿qué alumnos? → 3. ¿a dónde van?
// ============================================================
let ac = { asignacionId: 0, nombre: '', datos: null, año: null, divisionId: null, paso: 1, destinoId: null };
const MOTIVOS_TRANSF = {
    transferencia: { icono: '🔀', titulo: 'Cambio de curso', ayuda: 'Pasan a otra cursada.' },
    promocion: { icono: '🎓', titulo: 'Pasa de año', ayuda: 'Promocionan al curso siguiente.' },
    repite: { icono: '🔁', titulo: 'Repite', ayuda: 'Mantienen su curso en el nuevo ciclo.' },
};

async function abrirAlumnosCurso(asignacionId, nombre) {
    ac = { asignacionId, nombre, datos: null, año: null, divisionId: null, paso: 1, destinoId: null };
    document.getElementById('alumnosCursoTitulo').textContent = '🔀 Transferir alumnos — ' + nombre;
    document.getElementById('modalAlumnosCurso').classList.remove('oculto');
    document.getElementById('acCuerpo').innerHTML = '<p>Cargando…</p>';
    try {
        const r = await fetchConSesion(API + '?action=alumnos_curso&asignacion_id=' + asignacionId);
        const d = await r.json();
        if (!d.ok) { mostrarToast(d.mensaje || 'Error', 'error'); return; }
        ac.datos = d;
        const activo = (d.ciclos.find(c => c.activo) || {}).año;
        ac.año = d.años.includes(activo) ? activo : (d.años[0] || null);
        acIniciarPaso1();
    } catch (err) {
        if (err.message !== 'Sesión expirada') mostrarToast('Error de red: ' + err.message, 'error');
    }
}

function acActuales() {   // alumnos que hoy cursan en el año elegido
    return ac.datos.alumnos.filter(a => Number(a.año_lectivo) === ac.año && Number(a.activo) === 1);
}
function acDivisiones() {
    const m = new Map();
    acActuales().forEach(a => { const k = Number(a.division_id); if (!m.has(k)) m.set(k, { id: k, nombre: a.division, n: 0 }); m.get(k).n++; });
    return [...m.values()].sort((x, y) => x.nombre.localeCompare(y.nombre));
}

function acPintarCabecera() {
    const d = ac.datos;
    document.getElementById('acTabs').innerHTML = d.años.map(a =>
        `<button type="button" class="${a === ac.año ? 'btn-primario' : 'btn-secundario'}" onclick="acElegirAño(${a})">Alumnos año ${a}</button>`).join('')
        || '<em style="color:#a5c3e8;">No hay ciclos lectivos abiertos.</em>';
    const pasos = ['División', 'Alumnos', 'Destino'];
    document.getElementById('acPasos').innerHTML = pasos.map((t, i) =>
        `<span style="padding:0.25rem 0.75rem; border-radius:999px; font-size:0.8rem; ${ac.paso === i + 1 ? 'background:#38bdf8; color:#04202e; font-weight:700;' : (ac.paso > i + 1 ? 'background:rgba(56,189,248,0.25);' : 'background:rgba(255,255,255,0.08);')}">${i + 1}. ${t}</span>`).join('');
}

function acElegirAño(a) { ac.año = a; acIniciarPaso1(); }

function acIniciarPaso1() {
    ac.divisionId = null; ac.destinoId = null;
    const divs = acDivisiones();
    if (divs.length <= 1) {            // una sola división (o ninguna): no hace falta preguntar
        ac.divisionId = divs[0] ? divs[0].id : null;
        acPaso2();
        return;
    }
    ac.paso = 1;
    acPintarCabecera();
    document.getElementById('acCuerpo').innerHTML = `
        <h4>¿De qué división querés transferir alumnos?</h4>
        <div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:0.6rem; margin-top:0.5rem;">
            ${divs.map(d => `<button type="button" class="btn-secundario" style="padding:1rem; text-align:center;" onclick="acElegirDivision(${d.id})">
                <div style="font-size:1.6rem; font-weight:700;">${escapeHtml(d.nombre)}</div><small>${d.n} alumno(s)</small></button>`).join('')}
            <button type="button" class="btn-secundario" style="padding:1rem; text-align:center;" onclick="acElegirDivision(0)">
                <div style="font-size:1.6rem;">👥</div><small>Todas (${acActuales().length})</small></button>
        </div>`;
    document.getElementById('acAcciones').innerHTML = '';
}

function acElegirDivision(id) { ac.divisionId = id || null; acPaso2(); }

function acPaso2() {
    ac.paso = 2;
    acPintarCabecera();
    const filas = acActuales().filter(a => !ac.divisionId || Number(a.division_id) === ac.divisionId);
    const hayVarias = acDivisiones().length > 1;
    document.getElementById('acCuerpo').innerHTML = `
        <h4>¿Qué alumnos querés transferir?</h4>
        <input type="text" id="acBuscar" placeholder="🔍 Buscar por nombre o DNI" oninput="acFiltrar()" style="width:100%; margin:0.4rem 0; padding:0.5rem;">
        <div style="max-height:40vh; overflow:auto;">
        <table class="tabla-alumnos" style="width:100%;">
            <thead><tr><th style="width:2.5rem;"><input type="checkbox" id="acTodos" onchange="acMarcar(this.checked)" title="Marcar todos los visibles"></th>
                <th>Apellido, Nombre</th><th>DNI</th>${hayVarias ? '<th>División</th>' : ''}</tr></thead>
            <tbody>${filas.length ? filas.map(a => `<tr class="ac-fila" data-txt="${escapeHtml((a.apellido + ' ' + a.nombre + ' ' + a.dni).toLowerCase())}">
                <td><input type="checkbox" class="chk-alumno-curso" value="${a.alumno_id}" onchange="acContar()"></td>
                <td>${escapeHtml(a.apellido + ', ' + (a.nombre || ''))}</td><td>${escapeHtml(a.dni || '')}</td>
                ${hayVarias ? `<td>${escapeHtml(a.division)}</td>` : ''}</tr>`).join('')
                : '<tr><td colspan="4"><em style="color:#a5c3e8;">No hay alumnos cursando en este año.</em></td></tr>'}</tbody>
        </table></div>`;
    document.getElementById('acAcciones').innerHTML = `
        ${hayVarias ? '<button type="button" class="btn-secundario" onclick="acIniciarPaso1()">← Cambiar división</button>' : ''}
        <span id="acContador" style="margin-right:auto; color:#a5c3e8;"></span>
        <button type="button" class="btn-secundario" onclick="cerrarModal('modalAlumnosCurso')">Cerrar</button>
        <button type="button" class="btn-primario" id="acSiguiente" onclick="acPaso3()" disabled>Siguiente →</button>`;
    acContar();
}

function acFiltrar() {
    const q = document.getElementById('acBuscar').value.trim().toLowerCase();
    document.querySelectorAll('.ac-fila').forEach(tr => { tr.style.display = tr.dataset.txt.includes(q) ? '' : 'none'; });
}
function acMarcar(m) {
    document.querySelectorAll('.ac-fila').forEach(tr => { if (tr.style.display !== 'none') tr.querySelector('input').checked = m; });
    acContar();
}
function acContar() {
    const n = document.querySelectorAll('.chk-alumno-curso:checked').length;
    const c = document.getElementById('acContador'); if (c) c.textContent = n + ' seleccionado(s)';
    const b = document.getElementById('acSiguiente'); if (b) b.disabled = n === 0;
}

function acPaso3() {
    ac.ids = [...document.querySelectorAll('.chk-alumno-curso:checked')].map(c => c.value);
    if (!ac.ids.length) return;
    ac.paso = 3; ac.motivo = ac.motivo || 'transferencia';
    acPintarCabecera();
    const d = ac.datos;
    const grupos = {};
    d.destinos.forEach(x => (grupos[x.curso] ||= []).push(x));
    document.getElementById('acCuerpo').innerHTML = `
        <h4>¿A dónde van los ${ac.ids.length} alumno(s)?</h4>
        <div style="display:flex; gap:0.5rem; flex-wrap:wrap; margin:0.5rem 0;">
            ${Object.entries(MOTIVOS_TRANSF).map(([k, m]) => `<button type="button" id="mot-${k}" class="${ac.motivo === k ? 'btn-primario' : 'btn-secundario'}" onclick="acMotivo('${k}')" title="${m.ayuda}">${m.icono} ${m.titulo}</button>`).join('')}
        </div>
        <div id="acDestinos" style="max-height:34vh; overflow:auto;"></div>
        <label style="margin-top:0.5rem;">Ciclo lectivo de destino
            <select id="acCiclo" onchange="acResumen()">${d.ciclos.map(c => `<option value="${c.id}" ${c.activo ? 'selected' : ''}>${c.año}${c.activo ? ' (activo)' : ''}</option>`).join('')}</select>
        </label>
        <div id="acResumen" class="alerta info" style="margin-top:0.5rem;"></div>
        <small style="color:#a5c3e8; display:block;">El historial anterior se conserva: los alumnos y sus familiares siguen viendo todo lo que veían antes.</small>`;
    document.getElementById('acAcciones').innerHTML = `
        <button type="button" class="btn-secundario" onclick="acPaso2()">← Volver</button>
        <button type="button" class="btn-primario" id="btnTransfCurso" onclick="confirmarTransferenciaCurso()">Transferir</button>`;
    acPintarDestinos();
}

function acMotivo(k) {
    ac.motivo = k;
    Object.keys(MOTIVOS_TRANSF).forEach(x => { document.getElementById('mot-' + x).className = x === k ? 'btn-primario' : 'btn-secundario'; });
    acPintarDestinos();
}

function acPintarDestinos() {
    const caja = document.getElementById('acDestinos');
    if (ac.motivo === 'repite') { caja.innerHTML = '<p style="color:#a5c3e8;">Se mantienen sus cursadas actuales en el nuevo ciclo.</p>'; acResumen(); return; }
    const grupos = {};
    ac.datos.destinos.forEach(x => (grupos[x.curso] ||= []).push(x));
    caja.innerHTML = Object.entries(grupos).map(([curso, divs]) => `
        <div style="margin:0.4rem 0;"><strong>${escapeHtml(curso)}</strong>
            <div style="display:flex; gap:0.4rem; flex-wrap:wrap; margin-top:0.25rem;">
                ${divs.map(x => `<button type="button" class="${ac.destinoId === Number(x.id) ? 'btn-primario' : 'btn-secundario'}" onclick="acElegirDestino(${x.id})">${escapeHtml(x.division)}</button>`).join('')}
            </div></div>`).join('') || '<em>No hay cursos con divisiones.</em>';
    acResumen();
}

function acElegirDestino(id) { ac.destinoId = Number(id); acPintarDestinos(); }

function acResumen() {
    const r = document.getElementById('acResumen'); if (!r) return;
    const dest = ac.datos.destinos.find(x => Number(x.id) === ac.destinoId);
    const ciclo = document.getElementById('acCiclo');
    const año = ciclo ? ciclo.options[ciclo.selectedIndex].text : '';
    r.textContent = ac.motivo === 'repite'
        ? `${ac.ids.length} alumno(s) repiten y siguen en su curso — ciclo ${año}`
        : (dest ? `${ac.ids.length} alumno(s) → ${dest.nombre} — ciclo ${año}` : 'Elegí el curso de destino');
}

async function confirmarTransferenciaCurso() {
    if (ac.motivo !== 'repite' && !ac.destinoId) { mostrarToast('Elegí el curso de destino', 'error'); return; }
    const fd = new FormData();
    fd.append('asignacion_id', ac.asignacionId);
    fd.append('motivo', ac.motivo);
    fd.append('division_destino_id', ac.motivo === 'repite' ? '' : ac.destinoId);
    fd.append('ciclo_destino_id', document.getElementById('acCiclo').value);
    ac.ids.forEach(i => fd.append('alumnos_ids[]', i));
    if (!confirm(document.getElementById('acResumen').textContent + '\n\n¿Confirmar la transferencia?')) return;
    const btn = document.getElementById('btnTransfCurso');
    btn.disabled = true;
    try {
        const r = await fetchConSesion(API + '?action=transferir_alumnos_curso', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.ok) { mostrarToast(d.procesados + ' alumno(s) transferido(s)', 'ok'); setTimeout(() => location.reload(), 800); }
        else { mostrarToast(d.mensaje || 'Error', 'error'); btn.disabled = false; }
    } catch (err) {
        if (err.message !== 'Sesión expirada') mostrarToast('Error de red: ' + err.message, 'error');
        btn.disabled = false;
    }
}
