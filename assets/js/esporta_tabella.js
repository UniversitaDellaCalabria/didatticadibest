/* esporta_tabella.js - «Scarica» per le tabelle già nella pagina (Excel, PDF, CSV).
   Uso: <button type="button" data-esporta="xlsx|pdf|csv" data-tabella="idTabella" data-titolo="…" data-nome="nome_file">
   Si esportano solo le righe visibili (dopo la ricerca). Opzioni sulla tabella: data-colonne-escluse="0,5" (indici da saltare).
   Il file lo prepara admin/esporta_tabella.php. Il token CSRF sta nel <meta name="csrf-esporta"> o nel primo campo csrf_token della pagina. */
(function () {
    function testo(c) { return (c.textContent || '').replace(/\s+/g, ' ').trim(); }
    function token() {
        var m = document.querySelector('meta[name="csrf-esporta"]');
        if (m) return m.content;
        var i = document.querySelector('input[name="csrf_token"]');
        return i ? i.value : '';
    }
    function raccogli(tab) {
        var esc = (tab.dataset.colonneEscluse || '').split(',').filter(Boolean).map(Number);
        var int = [], righe = [];
        tab.querySelectorAll('thead tr').forEach(function (tr, k) {
            if (k === 0) Array.prototype.forEach.call(tr.children, function (c, i) { if (esc.indexOf(i) < 0) int.push(testo(c)); });
        });
        tab.querySelectorAll('tbody tr').forEach(function (tr) {
            if (tr.style.display === 'none' || tr.hidden) return;
            var r = [];
            Array.prototype.forEach.call(tr.children, function (c, i) { if (esc.indexOf(i) < 0) r.push(testo(c)); });
            if (r.length && r.some(function (v) { return v !== ''; })) righe.push(r);
        });
        return { intestazioni: int, righe: righe };
    }
    document.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-esporta]') : null;
        if (!b || !b.dataset.tabella) return;
        var tab = document.getElementById(b.dataset.tabella);
        if (!tab) return;
        e.preventDefault();
        var f = document.createElement('form');
        f.method = 'POST'; f.action = (b.dataset.azione || 'esporta_tabella.php'); f.style.display = 'none';
        [['csrf_token', token()], ['formato', b.dataset.esporta], ['titolo', b.dataset.titolo || ''], ['nome', b.dataset.nome || 'elenco'], ['dati', JSON.stringify(raccogli(tab))]].forEach(function (p) {
            var i = document.createElement('input'); i.type = 'hidden'; i.name = p[0]; i.value = p[1]; f.appendChild(i);
        });
        document.body.appendChild(f); f.submit(); f.remove();
    });
})();
