/* decisioni-pratica.js - Tabelle delle decisioni sulle pratiche (convalide e piano di studi), nella pratica e in seduta
 * (inc/convalide.php, html_editor_decisioni): CFU dell'insegnamento scelto dall'anagrafe, CFU riconosciuti e da integrare,
 * righe aggiunte e tolte, insegnamento da eliminare dal piano. Il server ripete i calcoli (leggi_decisioni_post). */
(function () {
    'use strict';
    var dati = document.getElementById('cfuInsDip');
    var cfuIns = dati ? JSON.parse(dati.textContent || '{}') : {};
    function n(v) { v = String(v || '').replace(',', '.'); return v === '' || isNaN(v) ? null : parseFloat(v); }
    function ricalcola(tr) {
        var es = tr.querySelector('.d-esito'); if (!es) return;
        var ins = n(tr.querySelector('.d-ins-cfu').value), cfu = n(tr.querySelector('.d-cfu').value), ric = tr.querySelector('.d-ric'), int = tr.querySelector('.d-int');
        if (es.value === 'totale') { ric.value = ins !== null ? ins : (cfu !== null ? cfu : ''); int.value = '0'; }
        else if (es.value === 'no') { ric.value = '0'; int.value = ''; }
        else if (ins !== null && n(ric.value) !== null) int.value = Math.max(0, ins - n(ric.value));
    }
    document.querySelectorAll('.dd-dec').forEach(function (f) {
        f.addEventListener('change', function (e) {
            var tr = e.target.closest('tr'); if (!tr) return;
            if (e.target.classList.contains('d-ins')) { var c = cfuIns[e.target.value]; tr.querySelector('.d-ins-cfu').value = c === undefined || c === null ? '' : c; }
            if (e.target.classList.contains('d-ins') || e.target.classList.contains('d-esito') || e.target.classList.contains('d-ric')) ricalcola(tr);
            if (e.target.classList.contains('d-piano')) { var el = tr.querySelector('.d-elimina'); el.hidden = !e.target.value; if (!e.target.value) el.value = ''; }
        });
        f.addEventListener('click', function (e) {
            var b = e.target.closest('.d-aggiungi');
            if (b) {
                var tb = f.querySelector('tbody'), r = tb.lastElementChild.cloneNode(true);
                r.querySelectorAll('input').forEach(function (i) { i.value = ''; });
                r.querySelectorAll('select').forEach(function (s) { s.selectedIndex = 0; });
                r.querySelectorAll('.d-elimina').forEach(function (i) { i.hidden = true; });
                r.querySelectorAll('.text-secondary').forEach(function (d) { d.remove(); });
                tb.appendChild(r); return;
            }
            var t = e.target.closest('.d-togli');
            if (t) { var tr = t.closest('tr'); if (tr.parentNode.children.length > 1) tr.remove(); else tr.querySelectorAll('input').forEach(function (i) { i.value = ''; }); }
        });
    });
})();
