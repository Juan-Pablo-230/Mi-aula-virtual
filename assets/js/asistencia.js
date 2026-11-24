const API_ASIST = '/plataforma/api/asistencia_ajax.php';

const cambios = {};

// ============================================================
// VALIDACIÓN DE FECHA: bloquear domingos y feriados (sábado OK)
// ============================================================
const MESES_ES = ['enero','febrero','marzo','abril','mayo','junio',
                  'julio','agosto','septiembre','octubre','noviembre','diciembre'];
const DIAS_ES  = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];

function formatearFechaLegible(ymd) {
    const [y, m, d] = ymd.split('-').map(Number);
    const fecha = new Date(y, m - 1, d);
    return `${DIAS_ES[fecha.getDay()]} ${d} de ${MESES_ES[m - 1]} de ${y}`;
}

function esFechaValida(ymd) {
    if (!ymd || !/^\d{4}-\d{2}-\d{2}$/.test(ymd)) {
        return { ok: false, motivo: 'Fecha inválida.' };
    }
    const [y, m, d] = ymd.split('-').map(Number);
    const fecha = new Date(y, m - 1, d);
    const dia = fecha.getDay(); // 0=Domingo, 6=Sábado

    if (dia === 0) {
        return { ok: false, motivo: 'No se puede tomar asistencia los domingos.' };
    }
    if ((window.FERIADOS || []).includes(ymd)) {
        return { ok: false, motivo: 'No se puede tomar asistencia en un feriado nacional.' };
    }
    return { ok: true, motivo: '' };
}

function mostrarErrorFecha(motivo) {
    const err = document.getElementById('fechaError');
    if (!err) return;
    err.textContent = '⚠️ ' + motivo;
    err.style.display = 'block';
    setTimeout(() => {
        err.style.display = 'none';
    }, 5000);
}

document.addEventListener('DOMContentLoaded', function() {
    // ============================================================
    // Limpieza de residuos de flatpickr (por si quedó algo cacheado)
    // ============================================================
    document.querySelectorAll('body > .flatpickr-calendar').forEach(el => el.remove());
    document.querySelectorAll('body > svg[viewBox="0 0 17 17"]').forEach(el => el.remove());
    document.querySelectorAll('body > .flatpickr-innerContainer').forEach(el => el.remove());
    document.querySelectorAll('body > .flatpickr-rContainer').forEach(el => el.remove());
    document.querySelectorAll('body > .flatpickr-days').forEach(el => el.remove());
    document.querySelectorAll('body > .flatpickr-weekdays').forEach(el => el.remove());

    // ============================================================
    // Validar fecha al cambiar el input
    // ============================================================
    const inputFecha = document.getElementById('fechaAsistencia');
    if (inputFecha) {
        const fechaOriginal = inputFecha.value;

        inputFecha.addEventListener('change', function() {
            const ymd = inputFecha.value;

            if (!ymd) {
                inputFecha.value = fechaOriginal;
                mostrarErrorFecha('Seleccioná una fecha.');
                return;
            }

            const check = esFechaValida(ymd);
            if (!check.ok) {
                mostrarErrorFecha(check.motivo);
                inputFecha.value = fechaOriginal;
                return;
            }

            if (Object.keys(cambios).length > 0) {
                if (!confirm('Tenés cambios sin guardar. ¿Querés descartarlos y cambiar de fecha?')) {
                    inputFecha.value = fechaOriginal;
                    return;
                }
            }
            inputFecha.closest('form').submit();
        });
    }

    // ============================================================
    // Resumen de días con asistencia / feriados
    // ============================================================
    const diasConAsist = window.DIAS_CON_ASISTENCIA || [];
    const feriados = window.FERIADOS || [];
    if ((diasConAsist.length > 0 || feriados.length > 0) && !document.getElementById('infoFechas')) {
        const form = document.querySelector('main form');
        if (form) {
            const info = document.createElement('div');
            info.id = 'infoFechas';
            info.style.cssText = 'font-size:0.78rem; color:#a5c3e8; margin-top:0.5rem; flex-basis:100%;';
            const partes = [];
            if (feriados.length > 0) {
                partes.push(`🎉 ${feriados.length} feriado(s) este año`);
            }
            if (diasConAsist.length > 0) {
                partes.push(`📋 ${diasConAsist.length} día(s) con asistencia tomada`);
            }
            info.textContent = partes.join(' · ');
            form.appendChild(info);
        }
    }
});

// ============================================================
// TOGGLE (solo visual)
// ============================================================
function toggleAsistencia(el, alumnoId) {
    if (el.dataset.libre === '1') {
        if (!window.ES_DIRECTIVO) {
            mostrarToast('Este alumno está LIBRE. Solo dirección puede quitar ese estado.', 'error');
            return;
        }
        if (!confirm('Este alumno está LIBRE. ¿Querés quitarle el estado libre?')) return;
        quitarLibre(alumnoId);
        return;
    }

    const estadoActual = el.dataset.estado;
    const nuevoEstado = estadoActual === 'presente' ? 'ausente' : 'presente';

    el.dataset.estado = nuevoEstado;
    el.classList.toggle('ausente', nuevoEstado === 'ausente');
    el.classList.toggle('presente', nuevoEstado === 'presente');

    const original = el.dataset.original;
    if (nuevoEstado !== original) {
        cambios[alumnoId] = nuevoEstado;
    } else {
        delete cambios[alumnoId];
    }

    actualizarAviso();
}

function actualizarAviso() {
    const aviso = document.getElementById('avisoCambios');
    if (!aviso) return;
    if (Object.keys(cambios).length > 0) {
        aviso.style.display = 'inline';
        aviso.textContent = '⚠️ ' + Object.keys(cambios).length + ' cambio(s) sin guardar';
    } else {
        aviso.style.display = 'none';
    }
}

// ============================================================
// GUARDAR
// ============================================================
async function guardarAsistencia() {
    if (Object.keys(cambios).length === 0) {
        mostrarToast('No hay cambios para guardar', 'error');
        return;
    }

    const btn = document.getElementById('btnGuardarAsistencia');
    if (btn) { btn.disabled = true; btn.textContent = 'Guardando...'; }

    try {
        const fd = new FormData();
        fd.append('materia_id', window.MATERIA_ID);
        fd.append('fecha', window.FECHA);
        fd.append('cambios', JSON.stringify(cambios));

        const r = await fetchConSesion(API_ASIST + '?action=guardar_lote', { method: 'POST', body: fd });
        const d = await r.json();

        if (!d.ok) {
            mostrarToast(d.mensaje || 'Error al guardar', 'error');
            if (btn) { btn.disabled = false; btn.textContent = '💾 Guardar asistencia'; }
            return;
        }

        document.querySelectorAll('.alumno-card').forEach(el => {
            el.dataset.original = el.dataset.estado;
        });

        Object.keys(cambios).forEach(k => delete cambios[k]);
        actualizarAviso();

        mostrarToast('Asistencia guardada correctamente', 'ok');

        if (d.libres && d.libres.length > 0) {
            setTimeout(() => {
                mostrarToast('Quedaron LIBRES: ' + d.libres.join(', '), 'error');
            }, 1000);
        }

        setTimeout(() => location.reload(), 1500);
    } catch (err) {
        if (err.message === 'Sesión expirada') return;
        mostrarToast('Error de red: ' + err.message, 'error');
        if (btn) { btn.disabled = false; btn.textContent = '💾 Guardar asistencia'; }
    }
}

// ============================================================
// TOPES
// ============================================================
async function guardarTopeMateria() {
    const v = document.getElementById('topeMateria').value;
    const fd = new FormData();
    fd.append('materia_id', window.MATERIA_ID);
    fd.append('faltas_maximas', v);
    const r = await fetchConSesion(API_ASIST + '?action=guardar_regla', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.ok) {
        mostrarToast('Tope general guardado', 'ok');
        setTimeout(() => location.reload(), 1000);
    } else {
        mostrarToast(d.mensaje, 'error');
    }
}

let topeAlumnoId = null;

function cambiarTope(alumnoId, topeActual, nombre) {
    topeAlumnoId = alumnoId;
    document.getElementById('modalTopeAlumno').textContent = nombre || '';
    document.getElementById('topeAlumnoInput').value = topeActual;
    document.getElementById('topeError').style.display = 'none';
    document.getElementById('modalTope').classList.remove('oculto');
    setTimeout(() => document.getElementById('topeAlumnoInput').select(), 50);
}

function cerrarModalTope() {
    document.getElementById('modalTope').classList.add('oculto');
    topeAlumnoId = null;
}

document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('formTope');
    if (!form) return;
    document.getElementById('modalTope').addEventListener('click', e => {
        if (e.target.id === 'modalTope') cerrarModalTope();
    });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') cerrarModalTope(); });
    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        const err = document.getElementById('topeError');
        const n = parseInt(document.getElementById('topeAlumnoInput').value, 10);
        if (isNaN(n) || n < 1) {
            err.textContent = 'Ingresá un número válido mayor a 0.';
            err.style.display = 'block';
            return;
        }
        const fd = new FormData();
        fd.append('materia_id', window.MATERIA_ID);
        fd.append('alumno_id', topeAlumnoId);
        fd.append('tope', n);
        try {
            const r = await fetchConSesion(API_ASIST + '?action=guardar_tope_alumno', { method: 'POST', body: fd });
            const d = await r.json();
            if (d.ok) location.reload();
            else { err.textContent = d.mensaje || 'Error al guardar'; err.style.display = 'block'; }
        } catch (ex) {
            if (ex.message === 'Sesión expirada') return;
            err.textContent = 'Error de red: ' + ex.message; err.style.display = 'block';
        }
    });
});

async function quitarLibre(alumnoId) {
    if (!confirm('¿Quitar el estado LIBRE a este alumno?')) return;
    const fd = new FormData();
    fd.append('materia_id', window.MATERIA_ID);
    fd.append('alumno_id', alumnoId);
    const r = await fetchConSesion(API_ASIST + '?action=quitar_libre', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.ok) {
        mostrarToast('Estado LIBRE quitado', 'ok');
        setTimeout(() => location.reload(), 1000);
    } else {
        mostrarToast(d.mensaje, 'error');
    }
}