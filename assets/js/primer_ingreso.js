// ============================================================
// PRIMER INGRESO
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('formPrimerIngreso');
    if (!form) return;

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const err = document.getElementById('primerError');
        const btn = document.getElementById('btnGuardarPrimer');
        if (err) err.style.display = 'none';

        const fd = new FormData(form);
        const pass = fd.get('password');
        const pass2 = fd.get('password2');
        const email = (fd.get('email') || '').trim();

        // Validaciones cliente
        if (!email) {
            if (err) { err.textContent = 'El correo electrónico es obligatorio.'; err.style.display = 'block'; }
            return;
        }
        if (pass !== pass2) {
            if (err) { err.textContent = 'Las contraseñas no coinciden.'; err.style.display = 'block'; }
            return;
        }

        if (btn) { btn.disabled = true; btn.textContent = 'Guardando...'; }

        try {
            const r = await fetchConSesion('/plataforma/api/primer_ingreso_ajax.php', {
                method: 'POST',
                body: fd
            });
            const d = await r.json();
            if (d.ok) {
                mostrarToast('Datos guardados. Redirigiendo...', 'ok');
                setTimeout(() => window.location.href = '/plataforma/publicaciones.php', 800);
            } else {
                if (err) { err.textContent = d.mensaje || 'Error al guardar'; err.style.display = 'block'; }
                if (btn) { btn.disabled = false; btn.textContent = 'Guardar y continuar'; }
            }
        } catch (ex) {
            if (ex.message === 'Sesión expirada') return;
            if (err) { err.textContent = 'Error de red: ' + ex.message; err.style.display = 'block'; }
            if (btn) { btn.disabled = false; btn.textContent = 'Guardar y continuar'; }
        }
    });
});