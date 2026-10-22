/* assistenza-operatori.js - Casella e chat degli operatori dell'assistenza (admin/assistenza.php).
   Tutto il testo passa da textContent: i messaggi sono testo semplice e non diventano mai HTML. */
(function () {
    'use strict';
    var app = document.getElementById('assistenza-app');
    if (!app) return;

    var URL = app.dataset.url, CSRF = app.dataset.csrf;
    var el = function (id) { return document.getElementById(id); };
    var stato = { vista: app.dataset.vista || 'da_prendere', conv: 0, ultimoId: 0, elenco: [], inCorso: false, sceltaPrimaVolta: true, titolo: document.title, nuoviPrima: null };

    function crea(tag, classe, testo) {
        var e = document.createElement(tag);
        if (classe) e.className = classe;
        if (testo !== undefined) e.textContent = testo;
        return e;
    }

    function data(s) { return new Date(String(s).replace(' ', 'T')); }
    function due(n) { return (n < 10 ? '0' : '') + n; }
    function quando(s) {
        var d = data(s), o = new Date();
        var ora = due(d.getHours()) + ':' + due(d.getMinutes());
        if (d.toDateString() === o.toDateString()) return ora;
        return due(d.getDate()) + '/' + due(d.getMonth() + 1) + ' ' + ora;
    }

    function chiama(parametri, post) {
        var opzioni = { credentials: 'same-origin', cache: 'no-store' };
        var url = URL;
        if (post) {
            var fd = new FormData();
            Object.keys(parametri).forEach(function (k) { fd.append(k, parametri[k]); });
            fd.append('csrf_token', CSRF);
            opzioni.method = 'POST';
            opzioni.body = fd;
        } else {
            url += '?' + new URLSearchParams(parametri).toString();
        }
        return fetch(url, opzioni).then(function (r) { return r.json(); }).catch(function () { return { ok: false, errore: 'Collegamento perso: riprovo tra poco.' }; });
    }

    function avviso(testo) {
        var a = el('as-avviso');
        a.textContent = testo || '';
        a.classList.toggle('d-none', !testo);
    }

    // ----- Casella -----
    function disegnaElenco() {
        var box = el('as-elenco');
        box.textContent = '';
        if (!stato.elenco.length) {
            box.appendChild(crea('div', 'text-center text-muted py-4', stato.vista === 'chiuse' ? 'Nessuna conversazione chiusa.' : 'Nessuna conversazione qui.'));
            return;
        }
        stato.elenco.forEach(function (c) {
            var b = crea('button', 'list-group-item list-group-item-action' + (c.id === stato.conv ? ' active' : '') + (c.da_leggere > 0 ? ' fw-bold' : ''));
            b.type = 'button';
            b.setAttribute('aria-current', c.id === stato.conv ? 'true' : 'false');
            var riga = crea('div', 'd-flex justify-content-between align-items-start gap-2');
            riga.appendChild(crea('span', 'text-truncate', c.nome));
            var dx = crea('span', 'text-nowrap small');
            if (c.da_leggere > 0) dx.appendChild(crea('span', 'badge bg-danger me-1', c.da_leggere + (c.da_leggere === 1 ? ' nuovo' : ' nuovi')));
            dx.appendChild(document.createTextNode(quando(c.ultimo)));
            riga.appendChild(dx);
            b.appendChild(riga);
            var sotto = crea('div', 'small ' + (c.id === stato.conv ? '' : 'text-secondary'));
            sotto.textContent = c.argomento_nome + (c.canale === 'esterno' ? ' · utente esterno' : '') + (c.stato === 'assegnata' && c.operatore_nome ? ' · ' + c.operatore_nome : '') + (c.stato === 'nuova' ? ' · da prendere' : '');
            b.appendChild(sotto);
            b.addEventListener('click', function () { apriChat(c.id); });
            box.appendChild(b);
        });
    }

    function disegnaContatori(c) {
        ['da_prendere', 'mie', 'tutte', 'bot'].forEach(function (k) {
            var b = el('as-n-' + k);
            if (b) { b.textContent = c[k]; b.classList.toggle('bg-danger', k === 'da_prendere' && c[k] > 0); b.classList.toggle('bg-secondary', !(k === 'da_prendere' && c[k] > 0)); }
        });
        document.title = (c.da_prendere > 0 ? '(' + c.da_prendere + ') ' : '') + stato.titolo;
        if (stato.nuoviPrima !== null && c.da_prendere > stato.nuoviPrima) avviso('C\'è una nuova richiesta da prendere in carico.');
        stato.nuoviPrima = c.da_prendere;
    }

    function caricaElenco() {
        return chiama({ azione: 'elenco', vista: stato.vista }).then(function (r) {
            if (!r.ok) { avviso(r.errore); return; }
            stato.elenco = r.elenco;
            disegnaContatori(r.contatori);
            disegnaElenco();
        });
    }

    function scegliVista(v) {
        stato.vista = v;
        document.querySelectorAll('#as-schede [data-vista]').forEach(function (b) {
            var on = b.dataset.vista === v;
            b.classList.toggle('active', on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        caricaElenco();
    }

    // ----- Chat -----
    function aggiungiMessaggi(lista) {
        var log = el('as-log');
        var inFondo = log.scrollHeight - log.scrollTop - log.clientHeight < 80;
        lista.forEach(function (m) {
            if (m.id <= stato.ultimoId) return;
            stato.ultimoId = Math.max(stato.ultimoId, m.id);
            var mio = m.da === 'operatore';
            var sistema = m.da === 'sistema';
            var bot = m.da === 'bot';
            var riga = crea('div', 'd-flex mb-2 ' + (sistema ? 'justify-content-center' : (mio ? 'justify-content-end' : 'justify-content-start')));
            var blocco = crea('div', sistema ? 'text-center small text-secondary' : '');
            blocco.style.maxWidth = sistema ? '90%' : '80%';
            if (!sistema) blocco.appendChild(crea('div', 'small text-muted mb-1' + (mio ? ' text-end' : ''), (bot ? 'Didacticus (assistente virtuale)' : (m.autore || (mio ? 'Operatore' : 'Utente'))) + ' · ' + quando(m.quando) + (m.tipo === 'nota' ? ' · nota interna' : '')));
            var fumetto = crea('div', sistema ? 'fst-italic' : 'p-2 rounded-3 shadow-sm ' + (m.tipo === 'nota' ? 'bg-warning-subtle border border-warning' : (mio ? 'text-white' : (bot ? 'bg-info-subtle border border-info' : 'bg-white border'))), m.testo);
            fumetto.style.whiteSpace = 'pre-wrap';
            fumetto.style.overflowWrap = 'anywhere';
            if (mio && m.tipo !== 'nota') fumetto.style.backgroundColor = '#B80000';
            blocco.appendChild(fumetto);
            riga.appendChild(blocco);
            log.appendChild(riga);
        });
        if (lista.length && inFondo) log.scrollTop = log.scrollHeight;
    }

    function disegnaIntestazione(c, colleghi) {
        el('as-nome').textContent = c.nome;
        el('as-dettagli').textContent = [c.email, c.argomento_nome, c.contesto_testo, c.canale === 'esterno' ? 'utente esterno (senza accesso)' : 'con accesso al portale'].filter(Boolean).join(' · ');
        var st = el('as-stato');
        st.textContent = c.stato_nome + (c.stato === 'assegnata' && c.operatore_nome ? ': ' + c.operatore_nome : '');
        st.className = 'badge ' + (c.stato === 'nuova' ? 'bg-danger' : (c.stato === 'chiusa' ? 'bg-secondary' : 'bg-success'));
        var mia = c.operatore_id === parseInt(app.dataset.io, 10);
        el('as-prendi').classList.toggle('d-none', c.stato === 'chiusa' || (c.stato === 'assegnata' && mia));
        el('as-chiudi').classList.toggle('d-none', c.stato === 'chiusa');
        el('as-riapri').classList.toggle('d-none', c.stato !== 'chiusa');
        el('as-passa-gruppo').classList.toggle('d-none', c.stato === 'chiusa' || !colleghi.length);
        var sel = el('as-passa-a');
        if (colleghi.length && sel.options.length !== colleghi.length + 1) {
            sel.textContent = '';
            var o0 = crea('option', '', 'Passa a…'); o0.value = '';
            sel.appendChild(o0);
            colleghi.forEach(function (k) { var o = crea('option', '', k.nome); o.value = k.id; sel.appendChild(o); });
        }
        el('as-testo').disabled = c.stato === 'chiusa';
        el('as-invia').disabled = c.stato === 'chiusa';
        el('as-valutazione').textContent = c.stato === 'chiusa' && c.valutazione !== null ? (c.valutazione ? 'La persona ha trovato utile l\'assistenza.' : 'La persona non ha trovato utile l\'assistenza.') : '';
    }

    function applicaChat(r) {
        if (!r.ok) { avviso(r.errore); return; }
        disegnaIntestazione(r.conv, r.colleghi || []);
        aggiungiMessaggi(r.messaggi);
    }

    function apriChat(id) {
        stato.conv = id;
        stato.ultimoId = 0;
        el('as-log').textContent = '';
        el('as-vuoto').classList.add('d-none');
        el('as-chat').classList.remove('d-none');
        avviso('');
        history.replaceState(null, '', '?vista=' + stato.vista + '&c=' + id);
        disegnaElenco();
        chiama({ azione: 'chat', c: id, dopo: 0 }).then(function (r) { applicaChat(r); var log = el('as-log'); log.scrollTop = log.scrollHeight; caricaElenco(); });
    }

    function azione(nome, extra) {
        var dati = Object.assign({ azione: nome, c: stato.conv, dopo: stato.ultimoId }, extra || {});
        return chiama(dati, true).then(function (r) {
            if (!r.ok) { avviso(r.errore); return r; }
            avviso('');
            applicaChat(r);
            caricaElenco();
            return r;
        });
    }

    el('as-schede').addEventListener('click', function (e) {
        var b = e.target.closest('[data-vista]');
        if (b) scegliVista(b.dataset.vista);
    });
    el('as-form').addEventListener('submit', function (e) {
        e.preventDefault();
        var t = el('as-testo');
        var testo = t.value.trim();
        if (!testo || !stato.conv) return;
        el('as-invia').disabled = true;
        azione('invia', { testo: testo, nota: el('as-nota').checked ? 1 : 0 }).then(function (r) {
            el('as-invia').disabled = false;
            if (r && r.ok) { t.value = ''; el('as-nota').checked = false; var log = el('as-log'); log.scrollTop = log.scrollHeight; t.focus(); }
        });
    });
    el('as-testo').addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); el('as-form').requestSubmit(); }
    });
    el('as-prendi').addEventListener('click', function () { azione('prendi'); });
    el('as-chiudi').addEventListener('click', function () { azione('chiudi'); });
    el('as-riapri').addEventListener('click', function () { azione('riapri'); });
    el('as-passa-a').addEventListener('change', function () {
        var v = this.value;
        if (v) azione('passa', { a: v }).then(function () { el('as-passa-a').value = ''; });
    });

    // Aggiornamento: la chat aperta ogni 4 secondi, la casella ogni 8; con la scheda nascosta molto più di rado
    var giro = 0;
    setInterval(function () {
        giro++;
        var lento = document.hidden;
        if (stato.conv && (!lento || giro % 5 === 0)) chiama({ azione: 'chat', c: stato.conv, dopo: stato.ultimoId }).then(applicaChat);
        if (giro % 2 === 0 && (!lento || giro % 10 === 0)) caricaElenco();
    }, 4000);

    scegliVista(stato.vista);
    var iniziale = parseInt(app.dataset.c, 10);
    if (iniziale > 0) apriChat(iniziale);
})();
