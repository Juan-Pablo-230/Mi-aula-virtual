// Agrega un "ojito" a TODOS los campos de contraseña para mostrarla / ocultarla.
// Funciona también con campos que se insertan después (modales, el formulario de login que se carga por fetch).
(function () {
    function envolver(input) {
        if (input.dataset.ojo === '1') return;
        input.dataset.ojo = '1';

        var wrap = document.createElement('span');
        wrap.className = 'pw-wrap';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'pw-ojo';
        btn.setAttribute('aria-label', 'Mostrar contraseña');
        btn.setAttribute('title', 'Mostrar contraseña');
        btn.setAttribute('tabindex', '-1');
        btn.textContent = '👁️';
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            var visible = input.type === 'text';
            input.type = visible ? 'password' : 'text';
            btn.textContent = visible ? '👁️' : '🙈';
            var txt = visible ? 'Mostrar contraseña' : 'Ocultar contraseña';
            btn.setAttribute('aria-label', txt);
            btn.setAttribute('title', txt);
            input.focus();
        });
        wrap.appendChild(btn);

        // Si el formulario se envía o se limpia, el campo vuelve a quedar oculto
        if (input.form) {
            input.form.addEventListener('reset', function () { input.type = 'password'; btn.textContent = '👁️'; });
        }
    }

    function procesar(raiz) {
        if (!raiz || !raiz.querySelectorAll) return;
        if (raiz.matches && raiz.matches('input[type="password"]')) envolver(raiz);
        raiz.querySelectorAll('input[type="password"]').forEach(envolver);
    }

    function iniciar() {
        procesar(document);
        new MutationObserver(function (muts) {
            muts.forEach(function (m) { m.addedNodes.forEach(procesar); });
        }).observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar);
    else iniciar();
})();
