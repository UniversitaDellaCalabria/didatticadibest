// campo-scuola.js - Campo "Scuola" dei moduli (html_campo_scuola() in inc/anagrafi.php): cliccando nel campo si apre
// una finestra guidata con regione, provincia e comune (tendine), una ricerca per nome o codice e l'elenco delle scuole
// dell'anagrafe del Ministero. Scegliendo una scuola si compila il codice meccanografico nascosto e parte l'evento
// 'scuola-scelta' (detail = scuola, null se tolta); con "La scuola non è in elenco" il campo diventa a testo libero.
// Funziona anche con i campi aggiunti dopo il caricamento (es. finestre di prenotazione).
(function () {
    var AIUTO = "Clicca nel campo: scegli regione, provincia e comune, poi la scuola. Se non è in elenco potrai scriverla a mano.";
    var cacheLuoghi = {};

    function el(tag, cls, testo) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (testo != null) e.textContent = testo;
        return e;
    }
    function json(url) {
        if (cacheLuoghi[url]) return Promise.resolve(cacheLuoghi[url]);
        return fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : []; })
            .then(function (r) { r = Array.isArray(r) ? r : []; if (url.indexOf('elenco=') > -1 && r.length) cacheLuoghi[url] = r; return r; })
            .catch(function () { return []; });
    }

    function avvia(box) {
        if (box.dataset.pronto) return;
        box.dataset.pronto = '1';
        var inp = box.querySelector('.scuola-testo'), cod = box.querySelector('.scuola-codice'),
            stato = box.querySelector('.scuola-stato'), vecchiaLista = box.querySelector('.scuola-lista');
        if (vecchiaLista) vecchiaLista.remove();
        var ep = box.dataset.endpoint, uid = inp.id || ('scu' + Math.random().toString(36).slice(2));
        var manuale = inp.value.trim() !== '' && !cod.value;
        var voci = [], attiva = -1, timer = null, richiesta = 0, aperto = false;
        var senzaRegioni = false;   // l'anagrafe non ha le regioni: province e comuni si elencano comunque, senza passare dalla regione

        // ── Finestra guidata ──
        // Finestra sovrapposta (fixed) con sfondo: dentro la finestra di prenotazione si aggancia al .modal,
        // così il focus resta nella finestra di Bootstrap e la posizione è rispetto allo schermo.
        var velo = el('div', 'scuola-velo');
        velo.style.cssText = 'display:none;position:fixed;inset:0;z-index:2000;background:rgba(15,23,42,.45);align-items:flex-start;justify-content:center;padding:6vh 16px;overflow-y:auto;';
        var pan = el('div', 'scuola-pannello card shadow-lg');
        pan.setAttribute('role', 'dialog'); pan.setAttribute('aria-modal', 'true'); pan.setAttribute('aria-labelledby', uid + '_tit');
        pan.style.cssText = 'width:100%;max-width:640px;border-radius:10px;';
        velo.appendChild(pan);
        var corpo = el('div', 'card-body p-3');
        var testa = el('div', 'd-flex justify-content-between align-items-center mb-2');
        var tit = el('h2', 'h6 fw-bold mb-0', 'Scegli la scuola'); tit.id = uid + '_tit';
        testa.appendChild(tit);
        var chiudiBtn = el('button', 'btn-close btn-sm'); chiudiBtn.type = 'button'; chiudiBtn.setAttribute('aria-label', 'Chiudi');
        testa.appendChild(chiudiBtn);
        corpo.appendChild(testa);

        var riga = el('div', 'row g-2 mb-2');
        function tendina(etichetta, id) {
            var c = el('div', 'col-sm-4');
            var l = el('label', 'form-label small mb-0 text-secondary', etichetta); l.htmlFor = id;
            var s = el('select', 'form-select form-select-sm'); s.id = id;
            c.appendChild(l); c.appendChild(s); riga.appendChild(c);
            return s;
        }
        var selReg = tendina('Regione', uid + '_reg'), selPro = tendina('Provincia', uid + '_pro'), selCom = tendina('Comune', uid + '_com');
        corpo.appendChild(riga);

        var lc = el('label', 'form-label small mb-0 text-secondary', 'Cerca per nome o codice meccanografico'); lc.htmlFor = uid + '_cerca';
        var cerca = el('input', 'form-control form-control-sm'); cerca.type = 'search'; cerca.id = uid + '_cerca'; cerca.autocomplete = 'off';
        cerca.placeholder = 'es. Liceo Fermi, CSPS…';
        cerca.setAttribute('role', 'combobox'); cerca.setAttribute('aria-controls', uid + '_ris'); cerca.setAttribute('aria-expanded', 'true'); cerca.setAttribute('aria-autocomplete', 'list');
        corpo.appendChild(lc); corpo.appendChild(cerca);

        var info = el('div', 'small text-secondary mt-2'); info.setAttribute('aria-live', 'polite');
        var lista = el('ul', 'list-group mt-1'); lista.id = uid + '_ris'; lista.setAttribute('role', 'listbox');
        lista.style.cssText = 'max-height:min(320px,40vh);overflow-y:auto;';
        corpo.appendChild(info); corpo.appendChild(lista);

        var piede = el('div', 'd-flex justify-content-between align-items-center mt-2 pt-2 border-top flex-wrap gap-2');
        var nonC = el('button', 'btn btn-link btn-sm p-0 fw-bold text-decoration-none', 'La scuola non è in elenco');
        nonC.type = 'button';
        piede.appendChild(nonC);
        piede.appendChild(el('span', 'small text-muted', "Anagrafe delle scuole del Ministero"));
        corpo.appendChild(piede);
        pan.appendChild(corpo);
        (box.closest('.modal') || document.body).appendChild(velo);

        function riempi(sel, voci, vuota, valore) {
            sel.innerHTML = '';
            var o = el('option', '', vuota); o.value = ''; sel.appendChild(o);
            voci.forEach(function (v) {
                var x = el('option', '', v.nome + ' (' + v.n + ')'); x.value = v.valore;
                if (v.valore === valore) x.selected = true;
                sel.appendChild(x);
            });
            sel.disabled = !voci.length;
        }
        function caricaRegioni() {
            return json(ep + '?elenco=regioni').then(function (r) {
                senzaRegioni = !r.length;
                var pre = selReg.value || (r.some(function (x) { return x.valore === 'CALABRIA'; }) ? 'CALABRIA' : '');
                riempi(selReg, r, senzaRegioni ? 'Regione non indicata' : 'Tutte le regioni', pre);
                return caricaProvince();
            });
        }
        function caricaProvince() {
            riempi(selCom, [], 'Scegli prima la provincia', '');
            if (!selReg.value && !senzaRegioni) { riempi(selPro, [], 'Scegli prima la regione', ''); return Promise.resolve(); }
            return json(ep + '?elenco=province&regione=' + encodeURIComponent(selReg.value)).then(function (r) {
                riempi(selPro, r, 'Tutte le province', '');
            });
        }
        function caricaComuni() {
            if (!selPro.value) { riempi(selCom, [], 'Scegli prima la provincia', ''); return Promise.resolve(); }
            return json(ep + '?elenco=comuni&regione=' + encodeURIComponent(selReg.value) + '&provincia=' + encodeURIComponent(selPro.value)).then(function (r) {
                riempi(selCom, r, 'Tutti i comuni', '');
            });
        }

        function segna(i) {
            [].forEach.call(lista.children, function (li, k) { li.classList.toggle('active', k === i); li.setAttribute('aria-selected', k === i ? 'true' : 'false'); });
            attiva = i;
            if (lista.children[i]) { lista.children[i].scrollIntoView({ block: 'nearest' }); cerca.setAttribute('aria-activedescendant', lista.children[i].id); }
        }
        function mostra(r, troncato) {
            voci = r; lista.innerHTML = ''; attiva = -1;
            if (!r.length) { info.textContent = 'Nessuna scuola trovata: cambia comune o ricerca, oppure usa "La scuola non è in elenco".'; return; }
            info.textContent = r.length === 1 ? '1 scuola' : r.length + ' scuole' + (troncato ? ' (le prime: restringi con comune o ricerca)' : '');
            r.forEach(function (s, i) {
                var li = el('li', 'list-group-item list-group-item-action py-2');
                li.setAttribute('role', 'option'); li.id = lista.id + '_' + i; li.style.cursor = 'pointer';
                li.appendChild(el('div', 'fw-semibold small', s.nome));
                var d = el('div', 'text-muted', [s.tipo, s.istituto, s.codice].filter(Boolean).join(' · ')); d.style.fontSize = '.75rem';
                li.appendChild(d);
                li.addEventListener('mousedown', function (e) { e.preventDefault(); scegli(s); });
                lista.appendChild(li);
            });
        }
        function aggiorna() {
            var q = cerca.value.trim();
            clearTimeout(timer);
            if (q.length < 3 && !selPro.value && !selCom.value) {
                voci = []; lista.innerHTML = '';
                info.textContent = selReg.value ? 'Scegli la provincia (e il comune) oppure scrivi almeno 3 lettere del nome.' : 'Scegli regione e provincia oppure scrivi almeno 3 lettere del nome.';
                return;
            }
            info.textContent = 'Cerco…';
            timer = setTimeout(function () {
                var n = ++richiesta;
                var url = ep + '?q=' + encodeURIComponent(q) + '&regione=' + encodeURIComponent(selReg.value)
                        + '&provincia=' + encodeURIComponent(selPro.value) + '&comune=' + encodeURIComponent(selCom.value);
                json(url).then(function (r) {
                    if (n !== richiesta) return;
                    mostra(r, r.length >= (selCom.value ? 200 : (selPro.value ? 80 : 20)));
                });
            }, 200);
        }

        function apri(testo) {
            if (manuale) return;
            if (!aperto) {
                aperto = true; velo.style.display = 'flex'; inp.setAttribute('aria-expanded', 'true');
                (selReg.options.length ? Promise.resolve() : caricaRegioni()).then(aggiorna);
            }
            if (testo != null) { cerca.value = testo; aggiorna(); }
            cerca.focus();
        }
        function chiudi(rimettiFuoco) {
            if (!aperto) return;
            aperto = false; velo.style.display = 'none'; inp.setAttribute('aria-expanded', 'false');
            if (rimettiFuoco) { inp.dataset.noApri = '1'; inp.focus(); setTimeout(function () { delete inp.dataset.noApri; }, 0); }
        }
        function segnaStato(s) {
            stato.innerHTML = '';
            if (s) {
                var ico = el('i', 'fa fa-circle-check text-success me-1'); ico.setAttribute('aria-hidden', 'true');
                stato.appendChild(ico);
                stato.appendChild(document.createTextNode("Scuola dall'anagrafe del Ministero · "));
                stato.appendChild(el('span', 'font-monospace', s.codice));
                stato.appendChild(document.createTextNode(' · '));
                var cambia = el('a', '', 'cambia'); cambia.href = '#';
                cambia.addEventListener('click', function (e) { e.preventDefault(); apri(''); });
                stato.appendChild(cambia);
            } else if (manuale) {
                stato.appendChild(document.createTextNode('Scuola scritta a mano: indica nome completo e comune. '));
                var torna = el('a', '', "Torna all'elenco"); torna.href = '#';
                torna.addEventListener('click', function (e) { e.preventDefault(); impostaManuale(false); inp.value = ''; apri(''); });
                stato.appendChild(torna);
            } else stato.textContent = AIUTO;
        }
        function scegli(s) {
            manuale = false;
            inp.value = s.nome; cod.value = s.codice;
            segnaStato(s); chiudi(true);
            inp.dispatchEvent(new Event('change', { bubbles: true }));
            box.dispatchEvent(new CustomEvent('scuola-scelta', { bubbles: true, detail: s }));
        }
        function impostaManuale(si) {
            manuale = si;
            inp.placeholder = si ? 'Nome completo e comune della scuola' : 'Clicca per scegliere la scuola';
            segnaStato(null);
        }

        // ── Collegamenti ──
        inp.addEventListener('focus', function () { if (!inp.dataset.noApri && !cod.value) apri(); });
        inp.addEventListener('click', function () { apri(); });
        inp.addEventListener('keydown', function (e) {
            if (manuale) return;
            if (e.key === 'Enter' || e.key === 'ArrowDown') { e.preventDefault(); apri(); }
        });
        inp.addEventListener('input', function () {
            if (manuale) return;
            // Si scrive nel campo: il testo passa alla ricerca della finestra, il campo resta quello scelto
            var t = inp.value;
            if (cod.value) {
                cod.value = '';
                box.dispatchEvent(new CustomEvent('scuola-scelta', { bubbles: true, detail: null }));
                segnaStato(null);
            }
            inp.value = '';
            apri(t);
        });
        cerca.addEventListener('input', aggiorna);
        cerca.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); chiudi(true); return; }
            if (!voci.length) { if (e.key === 'Enter') e.preventDefault(); return; }
            if (e.key === 'ArrowDown') { e.preventDefault(); segna(Math.min(voci.length - 1, attiva + 1)); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); segna(Math.max(0, attiva - 1)); }
            else if (e.key === 'Enter') { e.preventDefault(); if (attiva >= 0) scegli(voci[attiva]); else if (voci.length === 1) scegli(voci[0]); }
        });
        selReg.addEventListener('change', function () { caricaProvince().then(aggiorna); });
        selPro.addEventListener('change', function () { caricaComuni().then(aggiorna); });
        selCom.addEventListener('change', aggiorna);
        velo.addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); chiudi(true); } });
        chiudiBtn.addEventListener('click', function () { chiudi(true); });
        nonC.addEventListener('click', function () {
            var t = cerca.value.trim();
            cod.value = '';
            impostaManuale(true); chiudi(false);
            inp.value = t; inp.focus();
            box.dispatchEvent(new CustomEvent('scuola-scelta', { bubbles: true, detail: null }));
        });
        velo.addEventListener('mousedown', function (e) { if (e.target === velo) chiudi(true); });

        if (manuale) impostaManuale(true);
        else if (cod.value) segnaStato({ codice: cod.value });
    }

    function tutti() { document.querySelectorAll('.scuola-campo:not([data-pronto])').forEach(avvia); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', tutti); else tutti();
    new MutationObserver(tutti).observe(document.documentElement, { childList: true, subtree: true });
})();
