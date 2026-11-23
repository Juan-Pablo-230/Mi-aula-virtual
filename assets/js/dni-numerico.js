/* Campos de DNI: solo dígitos (también al pegar o autocompletar). */
(function () {
    if (window.__dniNumerico) return;
    window.__dniNumerico = true;
    var SEL = 'input[name="dni"],input[name="dni_familiar"],input[name="duenio_dni"],input[data-dni]';
    function limpiar(el) {
        var max = parseInt(el.getAttribute('maxlength'), 10) || 12;
        var v = (el.value || '').replace(/\D+/g, '').slice(0, max);
        if (v !== el.value) el.value = v;
    }
    function preparar(el) {
        if (el.readOnly || el.type === 'hidden') return;
        el.setAttribute('inputmode', 'numeric');
        if (!el.hasAttribute('maxlength')) el.setAttribute('maxlength', '12');
        el.setAttribute('pattern', '[0-9]{6,12}');
        if (el.getAttribute('autocomplete') === null) el.setAttribute('autocomplete', 'off');
        limpiar(el);
    }
    document.addEventListener('input', function (e) {
        if (e.target && e.target.matches && e.target.matches(SEL) && !e.target.readOnly) limpiar(e.target);
    }, true);
    document.addEventListener('keypress', function (e) {
        if (e.target && e.target.matches && e.target.matches(SEL) && e.key && e.key.length === 1 && !/\d/.test(e.key) && !e.ctrlKey && !e.metaKey) e.preventDefault();
    }, true);
    function init() { document.querySelectorAll(SEL).forEach(preparar); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
    new MutationObserver(init).observe(document.documentElement, { childList: true, subtree: true });
})();
