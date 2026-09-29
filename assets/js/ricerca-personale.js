// Pannello "Cerca nell'anagrafe di Ateneo" (html_ricerca_personale in functions.php).
// Filtri e nome interrogano cerca_personale.php; il pulsante di ogni risultato lancia sul pannello
// l'evento "persona-scelta" (detail = dati della persona), gestito dalla pagina che lo usa.
(function () {
    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
    function avatar(p, base) {
        if (p.foto) return '<img src="' + esc(base + p.foto) + '" alt="" width="36" height="36" style="width:36px;height:36px;border-radius:50%;object-fit:cover;flex-shrink:0;">';
        return '<svg width="36" height="36" viewBox="0 0 48 48" aria-hidden="true" style="flex-shrink:0;"><circle cx="24" cy="24" r="24" fill="#e5e7eb"/><circle cx="24" cy="19" r="8.5" fill="#f8fafc"/><path d="M8.5 41.5c2.6-8 8.6-12 15.5-12s12.9 4 15.5 12A23.9 23.9 0 0 1 24 48a23.9 23.9 0 0 1-15.5-6.5z" fill="#f8fafc"/></svg>';
    }
    function attiva(box) {
        if (box.dataset.pronto) return;
        box.dataset.pronto = '1';
        var out = box.querySelector('.rp-risultati'), timer = null, ultimi = [], seq = 0;
        function cerca() {
            var par = new URLSearchParams({
                q: box.querySelector('.rp-q').value.trim(), gruppo: box.querySelector('.rp-gruppo').value,
                ruolo: box.querySelector('.rp-ruolo').value, struttura: box.querySelector('.rp-struttura').value
            });
            if (par.get('q').length < 2 && !par.get('gruppo') && !par.get('ruolo') && !par.get('struttura')) { out.innerHTML = ''; return; }
            var mio = ++seq;
            out.innerHTML = '<div class="small text-secondary">Ricerca…</div>';
            fetch(box.dataset.endpoint + '?' + par.toString(), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (mio !== seq) return;
                    ultimi = j.risultati || [];
                    if (!ultimi.length) { out.innerHTML = '<div class="small text-secondary">Nessuno trovato. Puoi sempre inserire la persona a mano.</div>'; return; }
                    out.innerHTML = '<div class="small text-secondary mb-1">' + ultimi.length + (ultimi.length === 25 ? '+' : '') + ' risultati</div><ul class="list-group" style="max-height:300px;overflow-y:auto;">' + ultimi.map(function (p, i) {
                        return '<li class="list-group-item d-flex align-items-center gap-2 py-1">' + avatar(p, box.dataset.base || '') +
                            '<div class="flex-grow-1" style="min-width:0;"><div class="fw-bold small">' + esc(p.nome) + (p.attivo ? '' : ' <span class="badge bg-secondary">non più in servizio</span>') + '</div>' +
                            '<div class="text-secondary text-truncate" style="font-size:.75rem;">' + esc([p.ruolo, p.ssd, p.struttura].filter(Boolean).join(' · ')) + '</div>' +
                            '<div style="font-size:.75rem;">' + esc(p.email || 'email non disponibile') + '</div></div>' +
                            '<button type="button" class="btn btn-sm btn-outline-primary fw-bold rp-scegli" data-i="' + i + '">' + esc(box.dataset.pulsante || 'Aggiungi') + '</button></li>';
                    }).join('') + '</ul>';
                })
                .catch(function () { if (mio === seq) out.innerHTML = '<div class="small text-danger">Ricerca non riuscita: riprova.</div>'; });
        }
        box.addEventListener('input', function (e) { if (e.target.classList.contains('rp-q')) { clearTimeout(timer); timer = setTimeout(cerca, 250); } });
        box.addEventListener('change', function (e) { if (e.target.tagName === 'SELECT') cerca(); });
        box.addEventListener('keydown', function (e) { if (e.key === 'Enter' && e.target.classList.contains('rp-q')) { e.preventDefault(); clearTimeout(timer); cerca(); } });
        box.addEventListener('click', function (e) {
            var b = e.target.closest('.rp-scegli'); if (!b) return;
            var p = ultimi[+b.dataset.i]; if (!p) return;
            box.dispatchEvent(new CustomEvent('persona-scelta', { detail: p, bubbles: true }));
            b.textContent = '✓'; b.disabled = true;
        });
    }
    // Referenti di eventi e progetti (data-righe = id del contenitore delle righe): la persona scelta riempie
    // la prima riga vuota o una riga nuova (pulsante "Aggiungi persona" della pagina)
    document.addEventListener('persona-scelta', function (e) {
        var box = e.target.closest('.ricerca-personale'), righe = box && box.dataset.righe && document.getElementById(box.dataset.righe);
        if (!righe) return;
        var p = e.detail, campo = function (riga, n) { return riga.querySelector('[name="' + n + '[]"]'); };
        var riga = Array.prototype.find.call(righe.children, function (r) { return !campo(r, 'ref_nome').value.trim() && !campo(r, 'ref_email').value.trim(); });
        if (!riga) {
            var add = document.querySelector('[data-pj-aggiungi="' + righe.id + '"]');
            if (!add) return;
            add.click();
            riga = righe.lastElementChild;
        }
        campo(riga, 'ref_nome').value = p.nome;
        campo(riga, 'ref_email').value = p.email || '';
        campo(riga, 'ref_tel').value = p.telefono || '';
        campo(riga, 'ref_link').value = p.link || '';
        campo(riga, 'ref_persona').value = p.id;
        var badge = riga.querySelector('.ref-anag'); if (badge) badge.hidden = false;
        var ruolo = campo(riga, 'ref_ruolo'); (ruolo.value ? campo(riga, 'ref_nome') : ruolo).focus();
        // Con l'email la persona può ricevere le prenotazioni: interruttore acceso se non lo era già stato scelto
        if (!p.email) { var sw = riga.querySelector('.pj-notif'); if (sw && sw.checked) sw.click(); }
    });
    // Nome cambiato a mano: non è più la persona dell'anagrafe
    document.addEventListener('input', function (e) {
        if (e.target.name !== 'ref_nome[]') return;
        var riga = e.target.closest('.pj-ref'), pid = riga && riga.querySelector('[name="ref_persona[]"]');
        if (pid && pid.value) { pid.value = ''; var b = riga.querySelector('.ref-anag'); if (b) b.hidden = true; }
    });

    function tutti() { document.querySelectorAll('.ricerca-personale').forEach(attiva); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', tutti); else tutti();
})();
