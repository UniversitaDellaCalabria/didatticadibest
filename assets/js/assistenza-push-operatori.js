/* assistenza-push-operatori.js - La barra «Notifiche sul computer» della pagina Assistenza: attiva o toglie le notifiche a finestrella di questo browser,
   le silenzia per un po', sceglie «solo le mie chat» e manda una notifica di prova. Usa AssistenzaPush (assistenza-push.js). */
(function () {
    'use strict';
    var barra = document.getElementById('as-push');
    if (!barra || !window.AssistenzaPush) return;

    var URL = barra.dataset.url, CSRF = barra.dataset.csrf, CHIAVE = barra.dataset.chiave;
    var el = function (id) { return document.getElementById(id); };
    var endpoint = '';

    function chiama(parametri, post) {
        var o = { credentials: 'same-origin', cache: 'no-store' }, url = URL;
        if (post) {
            var fd = new FormData();
            Object.keys(parametri).forEach(function (k) { fd.append(k, parametri[k]); });
            fd.append('csrf_token', CSRF);
            o.method = 'POST';
            o.body = fd;
        } else {
            url += '?' + new URLSearchParams(parametri).toString();
        }
        return fetch(url, o).then(function (r) { return r.json(); }).catch(function () { return { ok: false, errore: 'Collegamento perso: riprova.' }; });
    }

    function messaggio(testo, errore, avviso) {
        var m = el('as-push-msg');
        m.textContent = testo || '';
        m.className = 'small ' + (errore && !avviso ? 'text-danger' : 'text-secondary');
    }

    function mostra(attive, dettaglio) {
        el('as-push-attiva').classList.toggle('d-none', attive);
        el('as-push-attive').classList.toggle('d-none', !attive);
        if (dettaglio) {
            el('as-push-silenzia').value = dettaglio.silenzia_fino ? 'si' : '0';
            el('as-push-solomie').checked = !!dettaglio.solo_mie;
            el('as-push-stato').textContent = dettaglio.silenzia_fino ? 'Silenziate fino alle ' + dettaglio.silenzia_fino.slice(11, 16) : 'Attive su questo computer';
        }
    }

    function aggiorna() {
        window.AssistenzaPush.stato().then(function (st) {
            barra.classList.remove('d-none');
            if (st.permesso === 'non_supportato') { el('as-push-attiva').classList.add('d-none'); messaggio('Questo browser non supporta le notifiche a finestrella: usa Chrome o Edge.', true, true); return; }
            if (st.permesso === 'denied') { el('as-push-attiva').classList.add('d-none'); messaggio('Le notifiche a finestrella sono disattivate in questo browser. Per averle: clicca sul lucchetto accanto all\'indirizzo e consenti le notifiche. Le richieste restano comunque visibili qui sotto.', false, true); }
            endpoint = st.sottoscrizione ? st.sottoscrizione.endpoint : '';
            chiama({ endpoint: endpoint }).then(function (r) {
                if (!r.ok) { messaggio(r.errore, true); return; }
                mostra(!!(endpoint && r.iscritto), r);
            });
        });
    }

    el('as-push-attiva').addEventListener('click', function () {
        messaggio('Un momento…');
        window.AssistenzaPush.iscrivi(CHIAVE).then(function (s) {
            return chiama({ azione: 'iscrivi', sottoscrizione: JSON.stringify(s.toJSON()) }, true);
        }).then(function (r) {
            if (!r.ok) { messaggio(r.errore, true); return; }
            messaggio('Fatto: da ora le nuove richieste compaiono come finestrella, anche a sito chiuso (basta che il browser sia aperto).');
            aggiorna();
        }).catch(function (e) { messaggio(e.message, true); });
    });

    el('as-push-disattiva').addEventListener('click', function () {
        window.AssistenzaPush.disiscrivi().then(function (e) { return chiama({ azione: 'disiscrivi', endpoint: e || endpoint }, true); }).then(function () {
            messaggio('Notifiche disattivate su questo computer.');
            aggiorna();
        });
    });

    el('as-push-prova').addEventListener('click', function () {
        chiama({ azione: 'prova' }, true).then(function (r) { messaggio(r.ok ? 'Notifica di prova inviata: dovrebbe comparire tra un attimo.' : r.errore, !r.ok); });
    });

    function salvaPreferenze() {
        var v = el('as-push-silenzia').value;
        chiama({ azione: 'preferenze', silenzia: v === 'si' ? 0 : parseInt(v, 10), solo_mie: el('as-push-solomie').checked ? 1 : 0 }, true).then(function (r) {
            if (r.ok) { mostra(true, r); messaggio(r.silenzia_fino ? 'Notifiche silenziate.' : 'Preferenze salvate.'); }
        });
    }
    el('as-push-silenzia').addEventListener('change', salvaPreferenze);
    el('as-push-solomie').addEventListener('change', salvaPreferenze);

    aggiorna();
})();
