/* campi-pratica.js - Moduli online della Didattica (modulo.php e istruttoria nel pannello):
 * - tabelle a righe: aggiungi / togli riga;
 * - logica dei campi: "mostra solo se" (data-cond) e "compila in automatico se" (data-auto) sul valore di un altro campo;
 * - scelta di un insegnamento dal catalogo di Ateneo (tipo di corso → a.a. di offerta → corso di studio offerto quell'anno → insegnamento)
 *   con CFU e S.S.D. compilati da soli nella stessa riga della tabella; se non c'è si scrive a mano;
 * - modulo a pagine (modulo.php, .pagine-modulo): una pagina per sezione, Avanti / Indietro con il controllo dei campi;
 * - colonna «piano di studi»: spuntando «a scelta» si chiede se nel piano c'è un insegnamento da eliminare (Sì → si sceglie
 *   dal catalogo o si scrive); il valore va nel campo nascosto della cella ("A scelta" o "A scelta; elimina: …").
 * Il server ripete gli stessi controlli (inc/didattica.php, leggi_risposte_modulo). */
(function () {
    'use strict';
    var script = document.currentScript;
    var BASE = (script && script.dataset.base) || '';
    var URL_CAT = BASE + '/cerca_insegnamenti.php';

    // ── Tabelle a righe ──────────────────────────────────────────────────────
    document.addEventListener('click', function (e) {
        var a = e.target.closest('.tab-aggiungi');
        if (a) {
            var tb = a.closest('fieldset').querySelector('tbody'), r = tb.lastElementChild.cloneNode(true);
            r.querySelectorAll('input, select').forEach(function (i) { if (i.tagName === 'SELECT') i.selectedIndex = 0; else if (i.type === 'checkbox') i.checked = false; else i.value = ''; });
            r.querySelectorAll('.piano-elimina').forEach(function (d) { d.hidden = true; d.querySelector('span').textContent = ''; });
            tb.appendChild(r);
            var f = r.querySelector('input, select'); if (f) f.focus();
            return;
        }
        var t = e.target.closest('.tab-togli');
        if (t) {
            var tr = t.closest('tr'), tb2 = tr.parentNode;
            if (tb2.children.length > 1) tr.remove();
            else {
                tr.querySelectorAll('input, select').forEach(function (i) { if (i.tagName === 'SELECT') i.selectedIndex = 0; else if (i.type === 'checkbox') i.checked = false; else i.value = ''; });
                tr.querySelectorAll('.piano-elimina').forEach(function (d) { d.hidden = true; });
            }
        }
    });

    // ── Logica dei campi ─────────────────────────────────────────────────────
    function valoreCampo(form, nome) {
        var box = form.querySelector('.campo-pratica[data-nome="' + nome + '"]');
        if (!box) return null; // campo non in questa pagina: la condizione non si applica
        if (box.hidden) return '';
        var tipo = box.dataset.tipo, el;
        if (tipo === 'checkbox' || tipo === 'dichiarazione') { el = box.querySelector('input[type=checkbox]'); return el && el.checked ? 'Sì' : ''; }
        if (tipo === 'radio') { el = box.querySelector('input[type=radio]:checked'); return el ? el.value : ''; }
        if (tipo === 'multicheck') return Array.prototype.map.call(box.querySelectorAll('input[type=checkbox]:checked'), function (i) { return i.value; }).join(', ');
        if (tipo === 'tabella') return Array.prototype.some.call(box.querySelectorAll('tbody input:not([type=checkbox]):not(.piano-val), tbody select'), function (i) { return i.value.trim() !== ''; }) ? 'righe' : '';
        el = box.querySelector('[name="campo_' + nome + '"]');
        return el ? String(el.value || '').trim() : '';
    }
    function vera(c, v) {
        v = String(v).toLowerCase().trim();
        var att = String(c.valore || '').toLowerCase().trim(), parti = v.split(',').map(function (x) { return x.trim(); });
        switch (c.op) {
            case 'uguale': return v === att || parti.indexOf(att) !== -1;
            case 'diverso': return !(v === att || parti.indexOf(att) !== -1);
            case 'contiene': return att !== '' && v.indexOf(att) !== -1;
            case 'compilato': return v !== '';
            case 'vuoto': return v === '';
        }
        return true;
    }
    function mostra(box, si) {
        if (box.hidden === !si) return;
        box.hidden = !si;
        box.querySelectorAll('input, select, textarea').forEach(function (i) {
            if (!si && i.required) { i.dataset.req = '1'; i.required = false; }
            if (si && i.dataset.req === '1') i.required = true;
        });
    }
    function imposta(box, testo) {
        var tipo = box.dataset.tipo;
        if (tipo === 'checkbox' || tipo === 'dichiarazione') { var c = box.querySelector('input[type=checkbox]'); if (c) c.checked = testo !== ''; return; }
        if (tipo === 'radio' || tipo === 'multicheck') {
            box.querySelectorAll('input').forEach(function (i) { i.checked = testo.split(',').map(function (x) { return x.trim(); }).indexOf(i.value) !== -1; });
            return;
        }
        var el = box.querySelector('[name="campo_' + box.dataset.nome + '"]');
        if (el && el.value !== testo) { el.value = testo; el.dispatchEvent(new Event('change', { bubbles: true })); }
    }
    function aggiornaLogica(form) {
        // Due passaggi: un campo può dipendere da uno che a sua volta dipende da un altro
        for (var giro = 0; giro < 2; giro++) {
            form.querySelectorAll('.campo-pratica[data-cond]').forEach(function (box) {
                var c = JSON.parse(box.dataset.cond), v = valoreCampo(form, c.nome);
                mostra(box, v === null || vera(c, v));
            });
        }
        form.querySelectorAll('.campo-pratica[data-auto]').forEach(function (box) {
            var a = JSON.parse(box.dataset.auto), v = valoreCampo(form, a.nome), si = !box.hidden && v !== null && vera(a, v);
            box.querySelectorAll('input:not([type=hidden]), select, textarea').forEach(function (i) { i.classList.toggle('bg-light', si); if (i.tagName !== 'SELECT' && i.type !== 'checkbox' && i.type !== 'radio') i.readOnly = si; });
            if (si) imposta(box, a.imposta || '');
            box.dataset.autoAttivo = si ? '1' : '';
        });
    }
    function prepara(form) {
        if (form.dataset.logica) return;
        form.dataset.logica = '1';
        form.addEventListener('input', function () { aggiornaLogica(form); });
        form.addEventListener('change', function () { aggiornaLogica(form); });
        form.addEventListener('submit', function (e) {
            // Scelte multiple obbligatorie: almeno una casella (solo se il campo è visibile)
            var manca = Array.prototype.find.call(form.querySelectorAll('fieldset[data-almeno-uno]'), function (fs) {
                var box = fs.closest('.campo-pratica');
                return !(box && box.hidden) && !fs.querySelector('input:checked');
            });
            if (manca) { e.preventDefault(); manca.scrollIntoView({ block: 'center' }); alert('Scegli almeno una opzione in: ' + manca.querySelector('legend').textContent.replace('*', '').trim()); }
        });
        aggiornaLogica(form);
    }
    function avvia() {
        document.querySelectorAll('.campo-pratica').forEach(function (b) { var f = b.closest('form'); if (f) prepara(f); });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', avvia); else avvia();

    // ── Scelta dell'insegnamento dal catalogo di Ateneo ──────────────────────
    var cache = {}, dlg = null, bersaglio = null;
    function json(q) {
        if (cache[q]) return Promise.resolve(cache[q]);
        return fetch(URL_CAT + '?' + q, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
            .then(function (j) { cache[q] = j; return j; });
    }
    function opt(sel, valore, testo, dati) {
        var o = document.createElement('option'); o.value = valore; o.textContent = testo;
        if (dati) Object.keys(dati).forEach(function (k) { o.dataset[k] = dati[k]; });
        sel.appendChild(o); return o;
    }
    function svuota(sel, testo) { sel.innerHTML = ''; opt(sel, '', testo); sel.disabled = true; }
    function aa(anno) { anno = parseInt(anno, 10); return anno + '/' + (anno + 1); }
    function cfuTesto(c) { return c === null || c === undefined ? '' : String(c).replace('.', ',').replace(/,0$/, ''); }
    function creaDialog() {
        dlg = document.createElement('dialog');
        dlg.className = 'border-0 rounded-3 shadow p-0';
        dlg.style.maxWidth = '640px'; dlg.style.width = '96%';
        dlg.setAttribute('aria-labelledby', 'insDlgTit');
        dlg.innerHTML = '<form method="dialog" class="p-3">'
            + '<h2 class="h5 fw-bold mb-1" id="insDlgTit">Scegli l\'insegnamento</h2>'
            + '<p class="small text-secondary mb-3">Dal catalogo dei corsi di studio dell\'Università della Calabria. Se non lo trovi chiudi e scrivilo a mano.</p>'
            + '<div class="row g-2">'
            + '<div class="col-md-6"><label class="form-label small fw-bold mb-0" for="insTipo">1. Tipo di corso</label><select class="form-select form-select-sm" id="insTipo"></select></div>'
            + '<div class="col-md-6"><label class="form-label small fw-bold mb-0" for="insAa">2. Anno accademico di offerta</label><select class="form-select form-select-sm" id="insAa"></select></div>'
            + '<div class="col-12"><label class="form-label small fw-bold mb-0" for="insCorso">3. Corso di studio</label><select class="form-select form-select-sm" id="insCorso"></select></div>'
            + '<div class="col-12"><label class="form-label small fw-bold mb-0" for="insCerca">4. Insegnamento</label><input type="search" class="form-control form-control-sm mb-1" id="insCerca" placeholder="Filtra per nome" disabled>'
            + '<select class="form-select form-select-sm" id="insIns" size="8"></select><div class="small text-secondary mt-1" id="insStato" aria-live="polite"></div></div>'
            + '</div><div class="d-flex gap-2 justify-content-end mt-3">'
            + '<button type="button" class="btn btn-sm btn-outline-secondary" id="insAnnulla">Annulla</button>'
            + '<button type="button" class="btn btn-sm btn-primary fw-bold" id="insUsa" disabled>Usa questo insegnamento</button></div></form>';
        document.body.appendChild(dlg);
        var tipo = dlg.querySelector('#insTipo'), corso = dlg.querySelector('#insCorso'), anno = dlg.querySelector('#insAa'),
            ins = dlg.querySelector('#insIns'), cerca = dlg.querySelector('#insCerca'), stato = dlg.querySelector('#insStato'), usa = dlg.querySelector('#insUsa');
        function azzeraIns(t) { svuota(ins, t || '—'); cerca.disabled = true; cerca.value = ''; usa.disabled = true; stato.textContent = ''; }
        dlg.querySelector('#insAnnulla').addEventListener('click', function () { dlg.close(); });
        // Ordine: tipo → anno di offerta → corsi offerti quell'anno (uno per nome) → insegnamenti
        tipo.addEventListener('change', function () {
            svuota(anno, 'Caricamento…'); svuota(corso, '—'); azzeraIns();
            if (!tipo.value) { svuota(anno, '—'); return; }
            json('azione=anni&tipo=' + encodeURIComponent(tipo.value)).then(function (el) {
                svuota(anno, el.length ? '-- anno di offerta --' : 'Nessun anno'); anno.disabled = !el.length;
                el.forEach(function (a) { opt(anno, a, aa(a)); });
            }).catch(function () { svuota(anno, 'Catalogo non disponibile'); });
        });
        anno.addEventListener('change', function () {
            svuota(corso, 'Caricamento…'); azzeraIns();
            if (!anno.value) { svuota(corso, '—'); return; }
            json('azione=corsi&tipo=' + encodeURIComponent(tipo.value) + '&aa=' + encodeURIComponent(anno.value)).then(function (el) {
                svuota(corso, el.length ? '-- scegli il corso --' : 'Nessun corso offerto in quell\'anno'); corso.disabled = !el.length;
                el.forEach(function (c) { opt(corso, c.codice, c.nome + (c.dipartimento ? ' – ' + c.dipartimento : ''), { nome: c.nome }); });
            }).catch(function () { svuota(corso, 'Catalogo non disponibile'); });
        });
        corso.addEventListener('change', function () {
            azzeraIns('Caricamento…');
            if (!corso.value) { azzeraIns(); return; }
            json('azione=insegnamenti&cds=' + encodeURIComponent(corso.value) + '&aa=' + encodeURIComponent(anno.value)).then(function (el) {
                ins.innerHTML = ''; ins.disabled = !el.length; cerca.disabled = !el.length; cerca.value = '';
                stato.textContent = el.length ? el.length + ' insegnamenti' : 'Nessun insegnamento per questo anno: prova un altro anno o scrivilo a mano.';
                el.forEach(function (i) {
                    opt(ins, i.id, i.nome + (i.anno ? ' · ' + i.anno + '° anno' : '') + (i.cfu !== null ? ' · ' + cfuTesto(i.cfu) + ' CFU' : '') + (i.ssd ? ' · ' + i.ssd : ''),
                        { nome: i.nome, cfu: i.cfu === null ? '' : i.cfu, ssd: i.ssd || '' });
                });
            }).catch(function () { svuota(ins, 'Catalogo non disponibile'); });
        });
        cerca.addEventListener('input', function () {
            var q = cerca.value.toLowerCase();
            Array.prototype.forEach.call(ins.options, function (o) { o.hidden = q && o.textContent.toLowerCase().indexOf(q) === -1; });
        });
        ins.addEventListener('change', function () { usa.disabled = !ins.value; });
        ins.addEventListener('dblclick', function () { if (ins.value) usa.click(); });
        usa.addEventListener('click', function () {
            var o = ins.selectedOptions[0]; if (!o) return;
            var co = corso.selectedOptions[0], dati = { id: parseInt(o.value, 10), nome: o.dataset.nome, corso: co ? co.dataset.nome : '', aa: anno.value ? aa(anno.value) : '',
                cfu: o.dataset.cfu === '' ? null : parseFloat(o.dataset.cfu), ssd: o.dataset.ssd };
            applica(dati); dlg.close();
        });
        json('azione=tipi').then(function (el) {
            svuota(tipo, '-- tipo di corso --'); tipo.disabled = false;
            el.forEach(function (t) { opt(tipo, t.tipo, t.nome); });
        }).catch(function () { svuota(tipo, 'Catalogo non disponibile'); });
        svuota(anno, '—'); svuota(corso, '—'); svuota(ins, '—');
    }
    function scrivi(el, v) { if (el) { el.value = v; el.dispatchEvent(new Event('change', { bubbles: true })); } }
    function applica(d) {
        if (!bersaglio) return;
        if (bersaglio.dataset.ritorno === 'piano') { var t = document.getElementById('pianoTesto'); if (t) t.value = d.nome + (d.corso ? ' – ' + d.corso : ''); return; }
        var tr = bersaglio.closest('tr');
        if (tr) {
            // Riga di una tabella: nome nella cella dell'insegnamento, CFU e S.S.D. nelle colonne di quel tipo
            scrivi(bersaglio.closest('td').querySelector('input[type=text]'), d.nome + (d.corso ? ' – ' + d.corso : ''));
            if (d.cfu !== null) tr.querySelectorAll('[data-col="cfu"]').forEach(function (i) { if (!i.value) scrivi(i, d.cfu); });
            if (d.ssd) tr.querySelectorAll('[data-col="ssd"]').forEach(function (i) { if (!i.value) scrivi(i, d.ssd); });
            return;
        }
        var box = bersaglio.closest('.campo-pratica') || bersaglio.parentNode;
        scrivi(box.querySelector('.ins-testo'), d.nome + (d.corso ? ' – ' + d.corso : '') + (d.aa ? ' (a.a. ' + d.aa + ')' : '') + (d.cfu !== null ? ' · ' + cfuTesto(d.cfu) + ' CFU' : '') + (d.ssd ? ' · ' + d.ssd : ''));
        var m = box.querySelector('.ins-meta'); if (m) m.value = JSON.stringify(d);
    }
    document.addEventListener('click', function (e) {
        var b = e.target.closest('.ins-scegli');
        if (!b) return;
        e.preventDefault();
        bersaglio = b;
        if (!dlg) creaDialog();
        if (typeof dlg.showModal === 'function') dlg.showModal(); else dlg.setAttribute('open', '');
    });
    // Scritto a mano: il collegamento al catalogo non vale più
    document.addEventListener('input', function (e) {
        if (e.target.classList && e.target.classList.contains('ins-testo')) {
            var m = e.target.closest('.campo-pratica'); m = m && m.querySelector('.ins-meta'); if (m) m.value = '';
        }
    });

    // ── Piano di studi: «a scelta» e insegnamento da eliminare ───────────────
    var dlgP = null, cellaP = null;
    function scriviPiano(cella, elimina) {
        var val = cella.querySelector('.piano-val'), box = cella.querySelector('.piano-elimina');
        val.value = 'A scelta' + (elimina ? '; elimina: ' + elimina : '');
        box.querySelector('span').textContent = elimina || '';
        box.hidden = !elimina;
    }
    function creaDialogPiano() {
        dlgP = document.createElement('dialog');
        dlgP.className = 'border-0 rounded-3 shadow p-0';
        dlgP.style.maxWidth = '520px'; dlgP.style.width = '94%';
        dlgP.setAttribute('aria-labelledby', 'pianoTit');
        dlgP.innerHTML = '<form method="dialog" class="p-3">'
            + '<h2 class="h6 fw-bold mb-2" id="pianoTit">Insegnamento a scelta nel piano di studi</h2>'
            + '<div id="pianoPasso1"><p class="mb-3">Sono presenti attualmente insegnamenti del piano di studi da eliminare?</p>'
            + '<div class="d-flex gap-2 justify-content-end"><button type="button" class="btn btn-sm btn-outline-secondary fw-bold" id="pianoNo">No</button>'
            + '<button type="button" class="btn btn-sm btn-primary fw-bold" id="pianoSi">Sì</button></div></div>'
            + '<div id="pianoPasso2" hidden><label class="form-label small fw-bold mb-1" for="pianoTesto">Insegnamento del piano da eliminare</label>'
            + '<div class="input-group input-group-sm mb-1"><input type="text" class="form-control" id="pianoTesto" maxlength="250" autocomplete="off" placeholder="Scegli dal catalogo o scrivilo">'
            + '<button type="button" class="btn btn-outline-primary ins-scegli" data-ritorno="piano"><i class="fa fa-magnifying-glass me-1" aria-hidden="true"></i>Catalogo</button></div>'
            + '<div class="form-text mb-3">Scegli il corso di studio a cui sei iscritto e l\'anno di offerta del tuo piano.</div>'
            + '<div class="d-flex gap-2 justify-content-end"><button type="button" class="btn btn-sm btn-outline-secondary" id="pianoIndietro">Indietro</button>'
            + '<button type="button" class="btn btn-sm btn-primary fw-bold" id="pianoOk">Conferma</button></div></div></form>';
        document.body.appendChild(dlgP);
        var p1 = dlgP.querySelector('#pianoPasso1'), p2 = dlgP.querySelector('#pianoPasso2'), testo = dlgP.querySelector('#pianoTesto');
        dlgP.querySelector('#pianoNo').addEventListener('click', function () { scriviPiano(cellaP, ''); dlgP.close(); });
        dlgP.querySelector('#pianoSi').addEventListener('click', function () { p1.hidden = true; p2.hidden = false; testo.focus(); });
        dlgP.querySelector('#pianoIndietro').addEventListener('click', function () { p1.hidden = false; p2.hidden = true; });
        dlgP.querySelector('#pianoOk').addEventListener('click', function () {
            if (!testo.value.trim()) { testo.focus(); return; }
            scriviPiano(cellaP, testo.value.trim()); dlgP.close();
        });
        // Chiusa con Esc senza scegliere: «a scelta» senza insegnamenti da eliminare
        dlgP.addEventListener('cancel', function () { scriviPiano(cellaP, ''); });
    }
    document.addEventListener('change', function (e) {
        if (!e.target.classList || !e.target.classList.contains('piano-check')) return;
        var cella = e.target.closest('.piano-cella');
        if (!e.target.checked) { cella.querySelector('.piano-val').value = ''; cella.querySelector('.piano-elimina').hidden = true; return; }
        cellaP = cella;
        if (!dlgP) creaDialogPiano();
        dlgP.querySelector('#pianoPasso1').hidden = false; dlgP.querySelector('#pianoPasso2').hidden = true;
        dlgP.querySelector('#pianoTesto').value = '';
        scriviPiano(cella, '');
        if (typeof dlgP.showModal === 'function') dlgP.showModal(); else dlgP.setAttribute('open', '');
    });

    // ── Modulo a pagine: una pagina per sezione (titolo), con Avanti / Indietro ──
    // Si controllano i campi della pagina prima di andare avanti; all'invio si torna alla prima pagina con un campo da completare.
    function visibileCampo(el) { var b = el.closest('.campo-pratica'); return !(b && b.hidden); }
    function primoNonValido(radice) {
        var el = Array.prototype.find.call(radice.querySelectorAll('input, select, textarea'), function (i) { return i.willValidate && visibileCampo(i) && !i.checkValidity(); });
        if (el) return el;
        return Array.prototype.find.call(radice.querySelectorAll('fieldset[data-almeno-uno]'), function (fs) { return visibileCampo(fs) && !fs.querySelector('input:checked'); }) || null;
    }
    function segnala(el) {
        if (el.tagName === 'FIELDSET') { el.scrollIntoView({ block: 'center' }); alert('Scegli almeno una opzione in: ' + el.querySelector('legend').textContent.replace('*', '').trim()); return; }
        el.reportValidity();
    }
    function impagina(cont) {
        var riga = cont.querySelector(':scope > .row'), form = cont.closest('form');
        if (!riga || !form) return;
        var campi = Array.prototype.slice.call(riga.children), titoli = campi.filter(function (c) { return c.dataset.tipo === 'titolo'; });
        if (titoli.length < 2) return;
        var pagine = [], att = null;
        campi.forEach(function (c) {
            if (!att || (c.dataset.tipo === 'titolo' && att.querySelector('.campo-pratica[data-tipo="titolo"]'))) {
                att = document.createElement('div'); att.className = 'row gx-4 pagina-modulo'; pagine.push(att);
            }
            att.appendChild(c);
        });
        riga.remove();
        var passi = document.createElement('ol'); passi.className = 'list-unstyled d-flex flex-wrap gap-1 small mb-3'; passi.setAttribute('aria-label', 'Sezioni del modulo');
        pagine.forEach(function (p, i) {
            var t = p.querySelector('.campo-pratica[data-tipo="titolo"] h2'), li = document.createElement('li');
            li.className = 'px-2 py-1 rounded fw-bold'; li.textContent = (i + 1) + '. ' + (t ? t.textContent : 'Inizio'); passi.appendChild(li);
            cont.appendChild(p);
        });
        cont.insertBefore(passi, cont.firstChild);
        var nav = document.createElement('div'); nav.className = 'd-flex gap-2 align-items-center mt-2';
        nav.innerHTML = '<button type="button" class="btn btn-outline-secondary fw-bold pag-indietro"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i>Indietro</button>'
            + '<span class="small text-secondary pag-stato" aria-live="polite"></span>'
            + '<button type="button" class="btn btn-primary fw-bold ms-auto pag-avanti">Avanti<i class="fa fa-arrow-right ms-1" aria-hidden="true"></i></button>';
        cont.appendChild(nav);
        var invia = form.querySelector('.invia-modulo'), corrente = 0;
        if (invia) nav.appendChild(invia);
        form.noValidate = true;
        function mostraPagina(n) {
            corrente = Math.max(0, Math.min(pagine.length - 1, n));
            pagine.forEach(function (p, i) { p.hidden = i !== corrente; });
            Array.prototype.forEach.call(passi.children, function (li, i) {
                li.style.cssText = i < corrente ? 'background:#dcfce7;color:#166534;' : (i === corrente ? 'background:#0056B3;color:#fff;' : 'background:#f1f5f9;color:#64748b;');
                if (i === corrente) li.setAttribute('aria-current', 'step'); else li.removeAttribute('aria-current');
            });
            nav.querySelector('.pag-indietro').hidden = corrente === 0;
            nav.querySelector('.pag-avanti').hidden = corrente === pagine.length - 1;
            if (invia) invia.hidden = corrente !== pagine.length - 1;
            nav.querySelector('.pag-stato').textContent = 'Pagina ' + (corrente + 1) + ' di ' + pagine.length;
        }
        nav.querySelector('.pag-indietro').addEventListener('click', function () { mostraPagina(corrente - 1); cont.scrollIntoView({ block: 'start' }); });
        nav.querySelector('.pag-avanti').addEventListener('click', function () {
            var el = primoNonValido(pagine[corrente]);
            if (el) { segnala(el); return; }
            mostraPagina(corrente + 1); cont.scrollIntoView({ block: 'start' });
        });
        form.addEventListener('submit', function (e) {
            for (var i = 0; i < pagine.length; i++) {
                var el = primoNonValido(pagine[i]);
                if (el) { e.preventDefault(); e.stopImmediatePropagation(); mostraPagina(i); segnala(el); return; }
            }
        }, true);
        mostraPagina(0);
    }
    function avviaPagine() { document.querySelectorAll('.pagine-modulo').forEach(impagina); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', avviaPagine); else avviaPagine();
})();
