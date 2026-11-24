// Bloquea el scroll de la página mientras haya una ventana emergente (.modal-fondo) abierta.
// Se actualiza solo cuando un modal se muestra u oculta (cambia su clase "oculto" o su style),
// o cuando se agrega/quita uno del DOM; no hace falta tocar el código de cada modal.
(function () {
    var anchoBarra = 0;

    function hayModalAbierto() {
        var modales = document.querySelectorAll('.modal-fondo');
        for (var i = 0; i < modales.length; i++) {
            var m = modales[i];
            if (!m.classList.contains('oculto') && getComputedStyle(m).display !== 'none') return true;
        }
        return false;
    }

    function actualizar() {
        var abierto = hayModalAbierto();
        var yaBloqueado = document.body.classList.contains('modal-abierto');
        if (abierto === yaBloqueado) return;

        if (abierto) {
            // Al ocultar la barra de scroll la página se ensancharía: se compensa para que no "salte"
            anchoBarra = window.innerWidth - document.documentElement.clientWidth;
            if (anchoBarra > 0) document.body.style.paddingRight = anchoBarra + 'px';
            document.body.classList.add('modal-abierto');
        } else {
            document.body.classList.remove('modal-abierto');
            document.body.style.paddingRight = '';
        }
    }

    function iniciar() {
        new MutationObserver(actualizar).observe(document.body, {
            subtree: true, childList: true, attributes: true, attributeFilter: ['class', 'style']
        });
        actualizar();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar);
    else iniciar();
})();
