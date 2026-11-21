// ============================================================
// MENSAJES
// ============================================================
// Variables opcionales en window:
//   window.MENSAJES_API  (string, /plataforma/api/mensajes_ajax.php)

window.MENSAJES_API = window.MENSAJES_API || '/plataforma/api/mensajes_ajax.php';

// ============================================================
// MODAL "NUEVO MENSAJE"
// ============================================================
function abrirNuevoMensaje() {
    const m = document.getElementById('modalNuevoMensaje');
    if (!m) return;
    const inp = document.getElementById('buscarUsuario');
    if (inp) { inp.value = ''; inp.focus(); }
    m.classList.remove('oculto');
    filtrarUsuarios();
}

function filtrarUsuarios() {
    const inp = document.getElementById('buscarUsuario');
    if (!inp) return;
    const q = inp.value.toLowerCase().trim();
    document.querySelectorAll('.usuario-item').forEach(el => {
        const nombre = el.dataset.nombre || '';
        el.style.display = (!q || nombre.includes(q)) ? '' : 'none';
    });
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const m = document.getElementById('modalNuevoMensaje');
        if (m && !m.classList.contains('oculto')) cerrarModal('modalNuevoMensaje');
    }
});

// ============================================================
// AUTO-SCROLL + FEEDBACK DEL BOTÓN ENVIAR
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    const cont = document.getElementById('chatMensajes');
    if (cont) cont.scrollTop = cont.scrollHeight;

    const formChat = document.getElementById('formChat');
    const btnEnviar = document.getElementById('btnEnviarMensaje');
    if (!formChat || !btnEnviar) return;

    formChat.addEventListener('submit', function() {
        btnEnviar.disabled = true;
        btnEnviar.textContent = 'Enviando...';
        btnEnviar.classList.add('btn-cargando');
        setTimeout(() => {
            if (btnEnviar.disabled) {
                btnEnviar.disabled = false;
                btnEnviar.textContent = 'Enviar';
                btnEnviar.classList.remove('btn-cargando');
            }
        }, 30000);
    });
});

// ============================================================
// MENÚ CONTEXTUAL
// ============================================================
const mc = document.getElementById('menuContextual');
let msgActual = null;

if (mc) {
    document.addEventListener('contextmenu', function(e) {
        const burbuja = e.target.closest('.burbuja');
        if (!burbuja) return;
        e.preventDefault();

        msgActual = burbuja;
        const puedeEditar = burbuja.dataset.puedeEditar === '1';
        const puedeEliminarMi = burbuja.dataset.puedeEliminarMi === '1';
        const puedeEliminarTodos = burbuja.dataset.puedeEliminarTodos === '1';

        document.getElementById('mcEditar').style.display = puedeEditar ? 'flex' : 'none';
        document.getElementById('mcEliminarMi').style.display = puedeEliminarMi ? 'flex' : 'none';
        document.getElementById('mcEliminarTodos').style.display = puedeEliminarTodos ? 'flex' : 'none';

        mc.classList.remove('oculto');
        const rect = mc.getBoundingClientRect();
        let x = e.clientX;
        let y = e.clientY;
        if (x + rect.width > window.innerWidth - 8) x = window.innerWidth - rect.width - 8;
        if (y + rect.height > window.innerHeight - 8) y = window.innerHeight - rect.height - 8;
        mc.style.left = x + 'px';
        mc.style.top = y + 'px';
    });

    document.addEventListener('click', function(e) {
        if (!mc.classList.contains('oculto') && !mc.contains(e.target)) {
            mc.classList.add('oculto');
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') mc.classList.add('oculto');
    });

    mc.querySelectorAll('.menu-contextual-item').forEach(btn => {
        btn.addEventListener('click', async function() {
            if (!msgActual) return;
            const accion = this.dataset.accion;
            const msgId = msgActual.dataset.msgId;
            mc.classList.add('oculto');

            if (accion === 'editar') {
                document.getElementById('editarMsgId').value = msgId;
                document.getElementById('editarTexto').value = msgActual.dataset.texto || '';
                document.getElementById('modalEditarMensaje').classList.remove('oculto');
                document.getElementById('editarTexto').focus();
                return;
            }

            if (accion === 'eliminar_para_mi') {
                if (!confirm('¿Eliminar este mensaje solo para vos?\nEl otro usuario lo seguirá viendo.')) return;
                const fd = new FormData();
                fd.append('msg_id', msgId);
                const r = await fetchConSesion(window.MENSAJES_API + '?action=eliminar_para_mi', { method: 'POST', body: fd });
                const d = await r.json();
                if (d.ok) { mostrarToast('Mensaje eliminado para vos', 'ok'); setTimeout(() => location.reload(), 700); }
                else mostrarToast(d.mensaje || 'Error', 'error');
                return;
            }

            if (accion === 'eliminar_para_todos') {
                if (!confirm('¿Eliminar este mensaje para TODOS?\nEsta acción no se puede deshacer.')) return;
                const fd = new FormData();
                fd.append('msg_id', msgId);
                const r = await fetchConSesion(window.MENSAJES_API + '?action=eliminar_para_todos', { method: 'POST', body: fd });
                const d = await r.json();
                if (d.ok) { mostrarToast('Mensaje eliminado para todos', 'ok'); setTimeout(() => location.reload(), 700); }
                else mostrarToast(d.mensaje || 'Error', 'error');
            }
        });
    });
}

// ============================================================
// MODAL DE EDICIÓN
// ============================================================
document.getElementById('formEditarMensaje')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('btnGuardarEdicion');
    btn.disabled = true;
    btn.textContent = 'Guardando...';
    try {
        const r = await fetchConSesion(window.MENSAJES_API + '?action=editar', { method: 'POST', body: new FormData(e.target) });
        const d = await r.json();
        if (d.ok) { mostrarToast('Mensaje editado', 'ok'); setTimeout(() => location.reload(), 700); }
        else { mostrarToast(d.mensaje || 'Error', 'error'); btn.disabled = false; btn.textContent = 'Guardar'; }
    } catch (err) {
        if (err.message === 'Sesión expirada') return;
        mostrarToast('Error de red: ' + err.message, 'error');
        btn.disabled = false;
        btn.textContent = 'Guardar';
    }
});