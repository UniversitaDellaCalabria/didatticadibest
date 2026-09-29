// campo-scuola.js - Campo "Scuola" dei moduli: ricerca nell'anagrafe del Ministero mentre si scrive
// (html_campo_scuola() in functions.php). Scegliendo dall'elenco si compila il codice meccanografico nascosto;
// se la scuola non c'è resta il testo scritto a mano. Funziona anche con i campi aggiunti dopo il caricamento.
(function () {
    var AIUTO = "Scegli la scuola dall'elenco che compare mentre scrivi. Se non la trovi, scrivi nome completo e comune.";

    function avvia(box) {
        if (box.dataset.pronto) return;
        box.dataset.pronto = '1';
        var inp = box.querySelector('.scuola-testo'), cod = box.querySelector('.scuola-codice'),
            lista = box.querySelector('.scuola-lista'), stato = box.querySelector('.scuola-stato');
        var timer = null, voci = [], attiva = -1, ultima = '';

        function chiudi() { lista.style.display = 'none'; inp.setAttribute('aria-expanded', 'false'); inp.removeAttribute('aria-activedescendant'); attiva = -1; }
        function segna(i) {
            [].forEach.call(lista.children, function (li, k) { li.classList.toggle('active', k === i); li.setAttribute('aria-selected', k === i ? 'true' : 'false'); });
            attiva = i;
            if (lista.children[i]) { lista.children[i].scrollIntoView({ block: 'nearest' }); inp.setAttribute('aria-activedescendant', lista.children[i].id); }
        }
        function scegli(s) {
            inp.value = s.nome; cod.value = s.codice; chiudi();
            stato.innerHTML = '';
            var ico = document.createElement('i'); ico.className = 'fa fa-circle-check text-success me-1'; ico.setAttribute('aria-hidden', 'true');
            var cd = document.createElement('span'); cd.className = 'font-monospace'; cd.textContent = s.codice;
            stato.appendChild(ico); stato.appendChild(document.createTextNode("Scuola dall'anagrafe del Ministero · ")); stato.appendChild(cd);
        }
        function mostra(r) {
            voci = r; lista.innerHTML = '';
            if (!r.length) {
                var v = document.createElement('li'); v.className = 'list-group-item small text-muted';
                v.textContent = 'Nessuna scuola trovata: prova con meno parole o con il comune, oppure scrivi il nome completo.';
                lista.appendChild(v);
            }
            r.forEach(function (s, i) {
                var li = document.createElement('li');
                li.className = 'list-group-item list-group-item-action py-2'; li.setAttribute('role', 'option'); li.id = lista.id + '_' + i; li.style.cursor = 'pointer';
                var n = document.createElement('div'); n.className = 'fw-semibold small'; n.textContent = s.nome;
                var d = document.createElement('div'); d.className = 'text-muted'; d.style.fontSize = '.75rem';
                d.textContent = [s.tipo, s.provincia ? 'provincia di ' + s.provincia : '', s.codice].filter(Boolean).join(' · ');
                li.appendChild(n); li.appendChild(d);
                li.addEventListener('mousedown', function (e) { e.preventDefault(); scegli(s); });
                lista.appendChild(li);
            });
            lista.style.display = ''; inp.setAttribute('aria-expanded', 'true'); attiva = -1;
        }

        inp.addEventListener('input', function () {
            if (cod.value) { cod.value = ''; stato.textContent = AIUTO; }
            var q = inp.value.trim();
            clearTimeout(timer);
            if (q.length < 3) { chiudi(); return; }
            timer = setTimeout(function () {
                ultima = q;
                fetch(box.dataset.endpoint + '?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
                    .then(function (r) { return r.ok ? r.json() : []; })
                    .then(function (r) { if (q === ultima && document.activeElement === inp) mostra(Array.isArray(r) ? r : []); })
                    .catch(function () {});
            }, 250);
        });
        inp.addEventListener('keydown', function (e) {
            if (lista.style.display === 'none' || !voci.length) return;
            if (e.key === 'ArrowDown') { e.preventDefault(); segna(Math.min(voci.length - 1, attiva + 1)); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); segna(Math.max(0, attiva - 1)); }
            else if (e.key === 'Enter' && attiva >= 0) { e.preventDefault(); scegli(voci[attiva]); }
            else if (e.key === 'Escape') chiudi();
        });
        inp.addEventListener('blur', function () { setTimeout(chiudi, 150); });
    }

    function tutti() { document.querySelectorAll('.scuola-campo:not([data-pronto])').forEach(avvia); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', tutti); else tutti();
    new MutationObserver(tutti).observe(document.documentElement, { childList: true, subtree: true });
})();
