/* assistenza-chat.js - La chat di chi chiede aiuto (assistenza.php, anche dentro la finestrella «Serve aiuto?»), con Didacticus.
   I messaggi sono testo: passano sempre da textContent e non diventano mai HTML. Le risposte di Didacticus hanno un formato minimo (paragrafi, elenchi con «- »,
   **grassetto**, indirizzi web cliccabili) costruito pezzo per pezzo con il DOM. */
(function () {
    'use strict';
    var app = document.getElementById('ac-app');
    if (!app) return;

    var URL = app.dataset.url, CSRF = app.dataset.csrf, TOKEN = app.dataset.t || '';
    var el = function (id) { return document.getElementById(id); };
    var stato = { ultimoId: 0, chiusa: app.dataset.chiusa === '1', valutata: false, bot: false, titolo: document.title, nuovi: 0, ultimoBot: null };
    var AVATAR = app.dataset.avatar || '', ARGOMENTI = [];
    try { ARGOMENTI = JSON.parse(app.dataset.argomenti || '[]'); } catch (e) { ARGOMENTI = []; }

    function crea(tag, classe, testo) {
        var e = document.createElement(tag);
        if (classe) e.className = classe;
        if (testo !== undefined) e.textContent = testo;
        return e;
    }
    function due(n) { return (n < 10 ? '0' : '') + n; }
    function quando(s) {
        var d = new Date(String(s).replace(' ', 'T')), o = new Date();
        var ora = due(d.getHours()) + ':' + due(d.getMinutes());
        return d.toDateString() === o.toDateString() ? ora : due(d.getDate()) + '/' + due(d.getMonth() + 1) + ' ' + ora;
    }

    function chiama(parametri, post) {
        var opzioni = { credentials: 'same-origin', cache: 'no-store' };
        var url = URL;
        if (TOKEN) parametri.t = TOKEN;
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

    function errore(testo) {
        var e = el('ac-errore');
        e.textContent = testo || '';
        e.classList.toggle('d-none', !testo);
    }

    // Testo di Didacticus: paragrafi, elenchi, **grassetto** e indirizzi web
    function inLinea(padre, testo) {
        testo.split(/(\*\*[^*]+\*\*|https?:\/\/[^\s<]+)/).forEach(function (pezzo) {
            if (!pezzo) return;
            if (/^\*\*[^*]+\*\*$/.test(pezzo)) padre.appendChild(crea('strong', '', pezzo.slice(2, -2)));
            else if (/^https?:\/\//.test(pezzo)) { var a = crea('a', '', pezzo); a.href = pezzo; a.target = '_blank'; a.rel = 'noopener'; padre.appendChild(a); }
            else padre.appendChild(document.createTextNode(pezzo));
        });
    }
    function formatta(padre, testo) {
        var ul = null;
        testo.split(/\n\s*\n/).forEach(function (blocco) {
            var righe = blocco.split('\n'), tutteElenco = righe.every(function (x) { return /^\s*[-•]\s+/.test(x); });
            if (tutteElenco) {
                ul = crea('ul', 'mb-2 ps-3');
                righe.forEach(function (x) { var li = crea('li'); inLinea(li, x.replace(/^\s*[-•]\s+/, '')); ul.appendChild(li); });
                padre.appendChild(ul);
            } else {
                var p = crea('p', 'mb-2');
                righe.forEach(function (x, i) { if (i) p.appendChild(document.createElement('br')); inLinea(p, x); });
                padre.appendChild(p);
            }
        });
        if (padre.lastChild) padre.lastChild.classList.remove('mb-2');
    }
    function pulsante(testo, icona, classe, azione) {
        var b = crea('button', 'btn btn-sm ' + classe + ' me-1 mb-1');
        b.type = 'button';
        if (icona) { var i = crea('i', 'fa ' + icona + ' me-1'); i.setAttribute('aria-hidden', 'true'); b.appendChild(i); }
        b.appendChild(document.createTextNode(testo));
        b.addEventListener('click', function () {
            document.querySelectorAll('#ac-log .ac-azioni button').forEach(function (x) { x.disabled = true; });
            azione();
        });
        return b;
    }
    // I pulsanti stanno solo sotto l'ultima risposta di Didacticus, e solo finché la chat è con lui
    function azioniBot(extra) {
        var box = crea('div', 'ac-azioni mt-2');
        (extra.link || []).forEach(function (l) {
            var a = crea('a', 'btn btn-sm btn-primary me-1 mb-1', l.e);
            a.href = l.u; a.target = '_top';
            if (/^https?:/i.test(l.u)) a.rel = 'noopener';
            box.appendChild(a);
        });
        (extra.file || []).forEach(function (f) {
            var a = crea('a', 'btn btn-sm btn-outline-primary me-1 mb-1');
            a.href = 'didacticus_file.php?v=' + f.v + '&i=' + f.i;
            var i = crea('i', 'fa fa-file-arrow-down me-1'); i.setAttribute('aria-hidden', 'true');
            a.appendChild(i); a.appendChild(document.createTextNode(f.n));
            box.appendChild(a);
        });
        if (extra.suggerimenti && extra.suggerimenti.length) {
            box.appendChild(crea('div', 'small fw-bold text-secondary mt-1 mb-1', extra.suggerimenti_titolo || 'Scegli:'));
            extra.suggerimenti.forEach(function (sg) {
                box.appendChild(pulsante(sg.t, '', 'btn-outline-secondary text-start', function () { agisci('bot_voce', { valore: sg.v }); }));
            });
        }
        if (extra.argomenti) {
            box.appendChild(crea('div', 'small fw-bold text-secondary mt-1 mb-1', 'Oppure scegli un argomento:'));
            ARGOMENTI.forEach(function (a) { box.appendChild(pulsante(a.n, a.i, 'btn-outline-secondary', function () { agisci('bot_argomento', { valore: a.k }); })); });
        }
        if (extra.feedback) {
            box.appendChild(crea('div', 'small fw-bold text-secondary mt-2 mb-1', 'Ti è stato utile?'));
            box.appendChild(pulsante('Sì, grazie', 'fa-thumbs-up', 'btn-outline-success', function () { agisci('bot_utile'); }));
            box.appendChild(pulsante('Non mi basta', 'fa-thumbs-down', 'btn-outline-secondary', function () { agisci('bot_non_utile'); }));
        }
        if (extra.operatore || extra.feedback || extra.argomenti || extra.suggerimenti) {
            box.appendChild(pulsante('Parla con un operatore', 'fa-headset', 'btn-outline-danger', function () { agisci('operatore'); }));
        }
        return box;
    }
    function pulisciAzioni() {
        var tutte = document.querySelectorAll('#ac-log .ac-azioni');
        for (var i = 0; i < tutte.length; i++) {
            if (!stato.bot || tutte[i].parentNode !== stato.ultimoBot) tutte[i].remove();
        }
    }

    function disegnaStato(c) {
        var s = el('ac-stato');
        stato.bot = !!c.bot;
        var intest = el('ac-intestazione');
        if (intest) intest.classList.toggle('d-none', !c.bot);
        if (c.chiusa) s.textContent = 'Conversazione chiusa';
        else if (c.bot) s.textContent = 'Ti risponde Didacticus, l\'assistente virtuale. Quando vuoi puoi parlare con un operatore.';
        else if (c.stato === 'assegnata' && c.operatore_nome) s.textContent = 'Ti risponde ' + c.operatore_nome;
        else s.textContent = 'In attesa di un operatore: ti risponderemo il prima possibile';
        stato.chiusa = c.chiusa;
        stato.valutata = c.valutata;
        el('ac-chiudi').classList.toggle('d-none', c.chiusa);
        el('ac-valuta').classList.toggle('d-none', !(c.chiusa && !c.valutata));
    }

    function aggiungi(lista) {
        var log = el('ac-log');
        var inFondo = log.scrollHeight - log.scrollTop - log.clientHeight < 80;
        lista.forEach(function (m) {
            if (m.id <= stato.ultimoId) return;
            stato.ultimoId = Math.max(stato.ultimoId, m.id);
            var mio = m.da === 'utente', sistema = m.da === 'sistema', bot = m.da === 'bot';
            var riga = crea('div', 'd-flex mb-2 ' + (sistema ? 'justify-content-center' : (mio ? 'justify-content-end' : 'justify-content-start')));
            if (bot) {
                var av = crea('img', 'rounded-circle me-2 flex-shrink-0');
                av.src = AVATAR; av.alt = ''; av.width = 34; av.height = 34; av.style.objectFit = 'cover'; av.style.marginTop = '18px';
                riga.appendChild(av);
            }
            var blocco = crea('div', sistema ? 'text-center small text-secondary' : '');
            blocco.style.maxWidth = sistema ? '95%' : '82%';
            if (!sistema) blocco.appendChild(crea('div', 'small text-muted mb-1' + (mio ? ' text-end' : ''), (mio ? 'Tu' : (m.autore || 'Assistenza')) + ' · ' + quando(m.quando)));
            var f = crea('div', sistema ? 'fst-italic' : 'p-2 rounded-3 shadow-sm ' + (mio ? 'text-white' : 'bg-white border'));
            if (bot) formatta(f, m.testo); else f.textContent = m.testo;
            f.style.whiteSpace = bot ? 'normal' : 'pre-wrap';
            f.style.overflowWrap = 'anywhere';
            if (mio) f.style.backgroundColor = '#0056B3';
            blocco.appendChild(f);
            if (bot && m.extra) { blocco.appendChild(azioniBot(m.extra)); stato.ultimoBot = blocco; }
            riga.appendChild(blocco);
            log.appendChild(riga);
            if (!mio && !sistema && document.hidden) { stato.nuovi++; document.title = '(' + stato.nuovi + ') ' + stato.titolo; }
        });
        pulisciAzioni();
        if (lista.length && inFondo) log.scrollTop = log.scrollHeight;
    }

    function applica(r, primaVolta) {
        if (!r.ok) { errore(r.errore); return; }
        errore('');
        if (r.conv) disegnaStato(r.conv);
        aggiungi(r.messaggi || []);
        if (primaVolta) el('ac-log').scrollTop = el('ac-log').scrollHeight;
    }

    function agisci(azione, extra) {
        var dati = Object.assign({ azione: azione, dopo: stato.ultimoId }, extra || {});
        return chiama(dati, true).then(function (r) { applica(r, false); var l = el('ac-log'); l.scrollTop = l.scrollHeight; return r; });
    }

    el('ac-form').addEventListener('submit', function (e) {
        e.preventDefault();
        var t = el('ac-testo'), testo = t.value.trim();
        if (!testo) return;
        el('ac-invia').disabled = true;
        agisci('invia', { testo: testo }).then(function (r) {
            el('ac-invia').disabled = false;
            if (r && r.ok) { t.value = ''; t.focus(); }
        });
    });
    el('ac-testo').addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); el('ac-form').requestSubmit(); }
    });
    el('ac-chiudi').addEventListener('click', function () {
        if (window.confirm('Chiudere la conversazione? Se ti serve ancora aiuto potrai riscrivere.')) agisci('chiudi');
    });
    el('ac-valuta').addEventListener('click', function (e) {
        var b = e.target.closest('[data-utile]');
        if (b) agisci('valuta', { utile: b.dataset.utile });
    });
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) { stato.nuovi = 0; document.title = stato.titolo; }
    });

    // Notifiche a finestrella (facoltative): compare l'invito solo se il browser le sa fare, il permesso non è bloccato e questa chat non è già collegata
    var chiavePush = app.dataset.chiave || '';
    if (chiavePush && window.AssistenzaPush && el('ac-push')) {
        var msgPush = function (t, err) { var m = el('ac-push-msg'); m.textContent = t || ''; m.className = (err ? 'text-danger' : 'text-secondary') + ' ms-1'; };
        window.AssistenzaPush.stato().then(function (st) {
            if (st.permesso === 'non_supportato' || st.permesso === 'denied') return;
            var ep = st.sottoscrizione ? st.sottoscrizione.endpoint : '';
            return chiama(ep ? { azione: 'push', endpoint: ep } : { azione: 'push' }).then(function (r) {
                if (r && r.ok && r.attivo && !r.iscritto) el('ac-push').classList.remove('d-none');
            });
        });
        el('ac-push-attiva').addEventListener('click', function () {
            msgPush('Un momento…');
            window.AssistenzaPush.iscrivi(chiavePush).then(function (s) {
                return chiama({ azione: 'push_iscrivi', sottoscrizione: JSON.stringify(s.toJSON()) }, true);
            }).then(function (r) {
                if (!r.ok) { msgPush(r.errore, true); return; }
                el('ac-push').classList.add('d-none');
            }).catch(function (e) { msgPush(e.message, true); });
        });
    }

    var giro = 0;
    setInterval(function () {
        giro++;
        if (document.hidden && giro % 5 !== 0) return;
        chiama({ azione: 'chat', dopo: stato.ultimoId }).then(function (r) { applica(r, false); });
    }, 4000);

    chiama({ azione: 'chat', dopo: 0 }).then(function (r) { applica(r, true); });
})();
