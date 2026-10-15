// pannello.js - Piccoli aiuti comuni alle pagine del pannello di gestione (admin/).
// Schede lunghe a sezioni (evento, progetto): indice con i nomi delle sezioni che segue lo scorrimento
// e pulsante «Salva» sempre in vista in fondo allo schermo. Le pagine senza sezioni (.pj-sez) non cambiano.
(function () {
    var sezioni = Array.prototype.slice.call(document.querySelectorAll('.pj-sez'));
    if (sezioni.length < 3) return;
    var prima = sezioni[0], riga = prima.closest('.row');
    if (!riga || !riga.parentNode) return;
    var indice = document.createElement('nav');
    indice.className = 'pn-indice';
    indice.setAttribute('aria-label', 'Sezioni della scheda');
    indice.innerHTML = '<span>Vai a</span>';
    var voci = [];
    sezioni.forEach(function (s, i) {
        var h2 = s.querySelector('h2');
        if (!h2) return;
        if (!s.id) s.id = 'pn-sez-' + (i + 1);
        var a = document.createElement('a');
        a.href = '#' + s.id;
        // Nome breve: il testo del titolo senza le parentesi
        a.textContent = h2.textContent.replace(/\s*\(.*\)\s*/, '').trim();
        indice.appendChild(a); voci.push({ a: a, s: s });
    });
    riga.parentNode.insertBefore(indice, riga);
    if ('IntersectionObserver' in window) {
        var visibili = {};
        var obs = new IntersectionObserver(function (es) {
            es.forEach(function (e) { visibili[e.target.id] = e.isIntersecting; });
            var primo = voci.filter(function (v) { return visibili[v.s.id]; })[0];
            voci.forEach(function (v) { v.a.classList.toggle('attiva', !!primo && v === primo); });
        }, { rootMargin: '-120px 0px -55% 0px' });
        voci.forEach(function (v) { obs.observe(v.s); });
    }
    // «Salva» sempre in vista
    var salva = document.querySelector('form .d-grid.gap-2.mb-4');
    if (salva) salva.classList.add('pn-salva-fisso');
})();

// Guida «?»: la frase «a cosa serve» della pagina e qualche suggerimento, sempre a portata di mano
(function () {
    var aiuto = document.querySelector('.pn-aiuto');
    if (!aiuto) return;
    var btn = document.createElement('button');
    btn.type = 'button'; btn.className = 'pn-guida-btn'; btn.setAttribute('aria-expanded', 'false'); btn.setAttribute('aria-controls', 'pnGuida');
    btn.setAttribute('aria-label', 'Come si usa questa pagina'); btn.innerHTML = '<span aria-hidden="true">?</span>';
    var box = document.createElement('div');
    box.id = 'pnGuida'; box.className = 'pn-guida'; box.hidden = true; box.setAttribute('role', 'dialog'); box.setAttribute('aria-label', 'Come si usa questa pagina');
    var t = document.querySelector('.pn-titolo');
    box.innerHTML = '<strong>' + (t ? t.textContent.trim() : 'Questa pagina') + '</strong><p></p>'
        + '<ul><li>Il pulsante colorato in alto è l\u2019azione principale della pagina.</li>'
        + '<li>Il pulsante <b>La mia scrivania</b> nel menu laterale riporta all\u2019elenco delle cose da fare.</li>'
        + '<li>Se ti perdi, torna alla scrivania: non perdi nulla di ciò che hai salvato.</li>'
        + '<li><a href="' + (location.pathname.indexOf('/admin/') >= 0 ? '../' : '') + 'guida.php">Guide per ruolo («Come si fa»)</a></li></ul>';
    box.querySelector('p').textContent = aiuto.textContent.trim();
    document.body.appendChild(box); document.body.appendChild(btn);
    function chiudi() { box.hidden = true; btn.setAttribute('aria-expanded', 'false'); }
    btn.addEventListener('click', function () { box.hidden = !box.hidden; btn.setAttribute('aria-expanded', String(!box.hidden)); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { chiudi(); btn.focus(); } });
    document.addEventListener('click', function (e) { if (!box.hidden && !box.contains(e.target) && !btn.contains(e.target)) chiudi(); });
})();

// «Vai al contenuto» per chi usa la tastiera
(function () {
    var c = document.getElementById('page-content-wrapper');
    if (!c) return;
    if (!c.id) return;
    var a = document.createElement('a'); a.href = '#page-content-wrapper'; a.className = 'pn-salta'; a.textContent = 'Vai al contenuto';
    document.body.insertBefore(a, document.body.firstChild);
})();
