// ============================================================
// ADMIN: INSTITUCIÓN → IMPORTACIÓN
// ============================================================
// Variable esperada:
//   window.INST_IMPORT_URL  (string, /plataforma/api/institucion_import.php?id=X)

document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('formImport');
    if (!form) return;

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const res = document.getElementById('resultImport');
        if (res) res.style.display = 'none';
        try {
            const r = await fetchConSesion(window.INST_IMPORT_URL, { method: 'POST', body: new FormData(form) });
            const d = await r.json();
            if (d.ok && d.resultado) {
                res.innerHTML = '<div class="panel-vidrio"><h3>Resultado</h3><pre style="background:rgba(0,0,0,0.3);padding:1rem;border-radius:8px;max-height:300px;overflow:auto;color:#cfe2ff;">' + d.resultado.map(l => l.replace(/</g,'&lt;')).join('\n') + '</pre></div>';
                res.style.display = 'block';
            } else {
                alert(d.mensaje || 'Error al importar');
            }
        } catch (err) {
            if (err.message !== 'Sesión expirada') alert('Error de red: ' + err.message);
        }
    });
});