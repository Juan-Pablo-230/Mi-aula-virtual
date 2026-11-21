// ============================================================
// CALENDARIO
// ============================================================
// Variable esperada:
//   window.CALENDARIO_DATA  (objeto indexado por Y-m-d)

const MESES_ES = ['enero','febrero','marzo','abril','mayo','junio',
                  'julio','agosto','septiembre','octubre','noviembre','diciembre'];
const DIAS_ES  = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];

function formatearFechaDia(ymd) {
    const [y, m, d] = ymd.split('-').map(Number);
    const fecha = new Date(y, m - 1, d);
    return `${DIAS_ES[fecha.getDay()]} ${d} de ${MESES_ES[m - 1]} de ${y}`;
}

function iconoTipo(tipo) {
    return tipo === 'tarea' ? '📝' : (tipo === 'feriado' ? '🎉' : '📅');
}

function escapeHtml(s) {
    return String(s || '').replace(/[&<>"']/g, c => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
}

function abrirDia(ymd) {
    const items = (window.CALENDARIO_DATA || {})[ymd] || [];
    document.getElementById('diaTitulo').textContent = '📅 ' + formatearFechaDia(ymd);
    const cont = document.getElementById('diaContenido');

    if (items.length === 0) {
        cont.innerHTML = '<p class="alerta info" style="margin:0;">No hay eventos para esta fecha.</p>';
    } else {
        cont.innerHTML = items.map((it, idx) => {
            const horaTxt = it.hora ? `<strong>${it.hora}</strong> · ` : (it.todo_el_dia ? '<em>Todo el día</em> · ' : '');
            return `<button type="button" class="dia-evento-item dia-evento-${it.tipo}" onclick="abrirDetalle('${ymd}', ${idx})">
                <span class="dia-evento-icono">${iconoTipo(it.tipo)}</span>
                <span class="dia-evento-texto">${horaTxt}${escapeHtml(it.titulo)}</span>
                <span class="dia-evento-flecha">→</span>
            </button>`;
        }).join('');
    }
    document.getElementById('modalDia').classList.remove('oculto');
}

function abrirDetalle(ymd, idx) {
    const items = (window.CALENDARIO_DATA || {})[ymd] || [];
    const it = items[idx];
    if (!it) return;

    const tipoLabel = it.tipo === 'tarea' ? 'Tarea' : (it.tipo === 'feriado' ? 'Feriado' : 'Evento');

    let html = `
        <div class="detalle-linea"><span class="detalle-label">Tipo:</span> <span class="detalle-valor">${iconoTipo(it.tipo)} ${tipoLabel}</span></div>
        <div class="detalle-linea"><span class="detalle-label">Fecha:</span> <span class="detalle-valor">${escapeHtml(it.fecha_completa)}</span></div>
    `;
    if (it.fecha_fin) {
        html += `<div class="detalle-linea"><span class="detalle-label">Fin:</span> <span class="detalle-valor">${escapeHtml(it.fecha_fin)}</span></div>`;
    }
    if (it.materia) {
        html += `<div class="detalle-linea"><span class="detalle-label">Materia:</span> <span class="detalle-valor">${escapeHtml(it.materia)}</span></div>`;
    }
    if (it.autor) {
        html += `<div class="detalle-linea"><span class="detalle-label">Publicado por:</span> <span class="detalle-valor">${escapeHtml(it.autor)}</span></div>`;
    }
    if (it.tipo_entrega) {
        const tEnt = it.tipo_entrega === 'multimedia' ? 'Multimedia' : 'Documento';
        html += `<div class="detalle-linea"><span class="detalle-label">Tipo de entrega:</span> <span class="detalle-valor">${tEnt}</span></div>`;
    }
    if (it.descripcion) {
        html += `<div class="detalle-descripcion">${escapeHtml(it.descripcion).replace(/\n/g, '<br>')}</div>`;
    }
    if (it.archivo_id) {
        html += `<div class="detalle-linea"><span class="detalle-label">Adjunto:</span>
                    <a class="btn-secundario" href="/plataforma/descargar.php?id=${encodeURIComponent(it.archivo_id)}" target="_blank">📎 ${escapeHtml(it.archivo_nombre || 'Adjunto')}</a>
                 </div>`;
    }
    if (it.link) {
        html += `<div class="modal-acciones" style="justify-content:flex-start; margin-top:1rem;">
                    <a class="btn-primario" href="${escapeHtml(it.link)}">Abrir en la plataforma</a>
                 </div>`;
    }

    document.getElementById('detalleTitulo').textContent = '📄 ' + it.titulo;
    document.getElementById('detalleContenido').innerHTML = html;
    document.getElementById('modalDetalle').classList.remove('oculto');
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.getElementById('modalDetalle')?.classList.add('oculto');
        document.getElementById('modalDia')?.classList.add('oculto');
    }
});