// ============================================================
// HELPER GLOBAL: fetch con manejo de sesión expirada
// ============================================================
async function fetchConSesion(url, options = {}) {
    options.credentials = 'include';

    try {
        const r = await fetch(url, options);

        if (r.status === 401 || r.status === 403) {
            let mensaje = 'Tu sesión expiró. Redirigiendo al login...';
            try {
                const data = await r.clone().json();
                if (data.mensaje) mensaje = data.mensaje;
            } catch (e) { /* no es JSON */ }

            alert(mensaje);
            window.location.href = '/index.html?expirada=1';
            throw new Error('Sesión expirada');
        }

        return r;
    } catch (err) {
        if (err.message === 'Sesión expirada') throw err;
        throw new Error('Error de red: ' + err.message);
    }
}

// ============================================================
// CHEQUEO PERIÓDICO DE SESIÓN
// ============================================================
async function verificarSesion() {
    try {
        const r = await fetch('/plataforma/api/session_check.php', {
            credentials: 'include'
        });
        if (r.status === 401) {
            window.location.href = '/index.html?expirada=1';
        }
    } catch (e) { /* error de red, no hacer nada */ }
}

if (window.location.pathname.includes('/plataforma/')) {
    setTimeout(verificarSesion, 1000);
    setInterval(verificarSesion, 5 * 60 * 1000);
}

// ============================================================
// TOAST DE NOTIFICACIÓN
// ============================================================
let _toastTimer = null;
function mostrarToast(mensaje, tipo = 'ok') {
    let toast = document.getElementById('toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'toast';
        toast.innerHTML = '<span id="toastIcono"></span><span id="toastMensaje"></span>';
        document.body.appendChild(toast);
    }

    const icono = document.getElementById('toastIcono');
    const texto = document.getElementById('toastMensaje');

    icono.textContent = tipo === 'ok' ? '✅' : '⚠️';
    texto.textContent = mensaje;

    toast.className = 'toast ' + (tipo === 'ok' ? 'toast-ok' : 'toast-error');

        if (_toastTimer) clearTimeout(_toastTimer);
    const duracion = tipo === 'error' ? 6000 : 4000;
    _toastTimer = setTimeout(() => {
        toast.classList.add('oculto');
        _toastTimer = null;
    }, duracion);
}

// ============================================================
// PERFIL: dropdown, modal, eliminar foto
// ============================================================
function togglePerfil() {
    document.getElementById('dropdownMenu')?.classList.toggle('oculto');
}

function abrirModalPerfil() {
    document.getElementById('modalPerfil')?.classList.remove('oculto');
}

function cerrarModal(id) {
    document.getElementById(id)?.classList.add('oculto');
}

// ============================================================
// PERFIL: guardar, eliminar foto, validar tamaño
// ============================================================
document.addEventListener('DOMContentLoaded', function() {

    // Guardar perfil
    const formPerfil = document.getElementById('formPerfil');
    if (formPerfil) {
        formPerfil.addEventListener('submit', async (e) => {
            e.preventDefault();
            const err = document.getElementById('perfilError');
            const btn = document.getElementById('btnGuardarPerfil');
            if (err) err.style.display = 'none';
            if (btn) { btn.disabled = true; btn.textContent = 'Guardando...'; }

            try {
                const fd = new FormData(e.target);
                const r = await fetchConSesion('/plataforma/api/perfil_ajax.php?action=guardar', {
                    method: 'POST',
                    body: fd
                });
                const d = await r.json();
                if (d.ok) {
                    mostrarToast('Datos guardados', 'ok');
                    setTimeout(() => location.reload(), 800);
                } else {
                    if (err) { err.textContent = d.mensaje || 'Error al guardar'; err.style.display = 'block'; }
                    if (btn) { btn.disabled = false; btn.textContent = 'Guardar'; }
                }
            } catch (ex) {
                if (ex.message === 'Sesión expirada') return;
                if (err) { err.textContent = ex.message; err.style.display = 'block'; }
                if (btn) { btn.disabled = false; btn.textContent = 'Guardar'; }
            }
        });

        // Validar tamaño de la foto
        const inputFoto = formPerfil.querySelector('input[name="foto"]');
        if (inputFoto) {
            inputFoto.addEventListener('change', function() {
                const file = this.files[0];
                if (file && file.size > 5 * 1024 * 1024) {
                    alert('La imagen supera los 5 MB');
                    this.value = '';
                }
            });
        }
    }
});

document.addEventListener('click', (e) => {
    const dd = document.getElementById('perfilDropdown');
    if (dd && !dd.contains(e.target)) {
        document.getElementById('dropdownMenu')?.classList.add('oculto');
    }
});

async function eliminarFotoPerfil() {
    if (!confirm('¿Eliminar tu foto de perfil? Se volverá a mostrar el avatar por defecto.')) return;

    try {
        const r = await fetchConSesion('/plataforma/api/perfil_ajax.php?action=eliminar_foto', {
            method: 'POST'
        });
        const d = await r.json();
        if (d.ok) {
            mostrarToast('Foto eliminada', 'ok');
            setTimeout(() => location.reload(), 1000);
        } else {
            mostrarToast(d.mensaje || 'Error al eliminar la foto', 'error');
        }
    } catch (err) {
        if (err.message === 'Sesión expirada') return;
        mostrarToast(err.message, 'error');
    }
}

// ============================================================
// VALIDACIÓN DE TAMAÑO DE ARCHIVO (helper genérico)
// ============================================================
function validarTamanoArchivo(file, maxBytes, maxLabel) {
    if (!file) return true;
    if (file.size > maxBytes) {
        const pesoMB = (file.size / 1024 / 1024).toFixed(2);
        alert('⚠️ El archivo pesa ' + pesoMB + ' MB y el límite es ' + maxLabel + '.');
        return false;
    }
    return true;
}