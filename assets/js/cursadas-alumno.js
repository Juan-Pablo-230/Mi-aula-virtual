// Editor de cursadas de un alumno dentro de una institución.
// Un alumno puede estar en varias divisiones a la vez: una completa como máximo y el resto
// parciales (solo algunas materias). Deja el resultado en un campo oculto "cursadas_json".
window.CursadasEditor = (function () {
    function el(tag, props, children) {
        const e = document.createElement(tag);
        Object.keys(props || {}).forEach(k => {
            if (k === 'text') e.textContent = props[k];
            else if (k === 'style') e.style.cssText = props[k];
            else if (k === 'className') e.className = props[k];
            else e[k] = props[k];
        });
        (children || []).forEach(c => e.appendChild(c));
        return e;
    }

    function montar(cont, opts) {
        const divisiones = opts.divisiones || [];
        const materiasDe = opts.materiasDe || (() => []);
        const estado = (opts.cursadas || []).map(c => ({
            division_id: Number(c.division_id) || 0,
            solo: !!Number(c.solo),
            materias: (c.materias || []).map(Number),
            sumar: !!Number(c.sumar)
        }));
        const oculto = el('input', { type: 'hidden', name: 'cursadas_json' });

        function sincronizar() {
            oculto.value = JSON.stringify(estado.filter(c => c.division_id > 0));
        }

        function dibujar() {
            cont.innerHTML = '';
            cont.appendChild(el('label', { text: 'Cursadas del alumno', style: 'display:block; margin-bottom:0.25rem;' }));
            cont.appendChild(el('small', { text: 'Puede cursar en más de una división a la vez: una cursada completa y otras donde solo hace algunas materias.', style: 'display:block; color:#a5c3e8; margin-bottom:0.5rem;' }));

            if (!estado.length) cont.appendChild(el('p', { text: 'Sin división asignada.', style: 'color:#a5c3e8; font-size:0.85rem;' }));

            estado.forEach((c, i) => {
                const tarjeta = el('div', { className: 'cursada-card', style: 'border:0.0625rem solid var(--borde-vidrio); border-radius:0.5rem; padding:0.6rem; margin-bottom:0.5rem;' });

                const sel = el('select', { style: 'width:100%;' });
                sel.appendChild(el('option', { value: '0', text: '— Elegí la división —' }));
                divisiones.forEach(d => {
                    const o = el('option', { value: String(d.id), text: d.asignacion + ' ' + d.nombre });
                    if (Number(d.id) === c.division_id) o.selected = true;
                    sel.appendChild(o);
                });
                sel.addEventListener('change', () => { c.division_id = Number(sel.value); c.materias = []; dibujar(); });
                tarjeta.appendChild(sel);

                const chkSolo = el('input', { type: 'checkbox', checked: c.solo });
                chkSolo.addEventListener('change', () => { c.solo = chkSolo.checked; if (!c.solo) c.materias = []; dibujar(); });
                tarjeta.appendChild(el('label', { className: 'label-checkbox', style: 'margin-top:0.4rem;' }, [chkSolo, el('span', { text: 'Cursa solo algunas materias de esta división' })]));

                if (c.solo && c.division_id > 0) {
                    const mats = materiasDe(c.division_id);
                    if (!mats.length) tarjeta.appendChild(el('small', { text: 'Esa división no tiene materias cargadas.', style: 'color:#fbbf24;' }));
                    mats.forEach(m => {
                        const chk = el('input', { type: 'checkbox', checked: c.materias.indexOf(Number(m.id)) !== -1 });
                        chk.addEventListener('change', () => {
                            const id = Number(m.id);
                            c.materias = c.materias.filter(x => x !== id);
                            if (chk.checked) c.materias.push(id);
                            sincronizar();
                        });
                        tarjeta.appendChild(el('label', { className: 'label-checkbox' }, [chk, el('span', { text: m.nombre })]));
                    });
                    const chkSumar = el('input', { type: 'checkbox', checked: c.sumar });
                    chkSumar.addEventListener('change', () => { c.sumar = chkSumar.checked; sincronizar(); });
                    tarjeta.appendChild(el('label', { className: 'label-checkbox', style: 'margin-top:0.3rem;' }, [chkSumar, el('span', { text: 'Sumar automáticamente las materias que se agreguen después a la división' })]));
                }

                const quitar = el('button', { type: 'button', className: 'btn-secundario', text: '🗑️ Quitar esta cursada', style: 'margin-top:0.4rem;' });
                quitar.addEventListener('click', () => { estado.splice(i, 1); dibujar(); });
                tarjeta.appendChild(quitar);
                cont.appendChild(tarjeta);
            });

            const agregar = el('button', { type: 'button', className: 'btn-secundario', text: '➕ Agregar otra cursada' });
            agregar.addEventListener('click', () => {
                // Si ya tiene una cursada completa, la nueva es parcial
                estado.push({ division_id: 0, solo: estado.some(c => !c.solo), materias: [], sumar: false });
                dibujar();
            });
            cont.appendChild(agregar);
            cont.appendChild(oculto);
            sincronizar();
        }
        dibujar();
    }

    return { montar: montar };
})();
