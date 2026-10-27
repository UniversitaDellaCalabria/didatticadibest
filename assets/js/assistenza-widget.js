/* assistenza-widget.js - Il pulsante «Serve aiuto?» in basso a destra su tutte le pagine pubbliche: apre la chat di assistenza in una finestrella
   (la pagina assistenza.php?pannello=1 dentro un iframe della stessa origine). Se c'è una risposta da leggere compare un pallino rosso.
   Gli esterni ritrovano la loro chat grazie al link personale ricordato dal browser (localStorage, facoltativo). */
(function () {
    'use strict';
    var script = document.currentScript;
    if (!script || document.getElementById('assistenza-widget')) return;
    var BASE = script.src.replace(/assets\/js\/assistenza-widget\.js.*$/, '');

    function leggiToken() { try { return localStorage.getItem('assistenza_t') || ''; } catch (e) { return ''; } }
    // Chi ha il login parte sempre da una chat nuova; durante la stessa visita (scheda del browser) riprende solo quella che ha appena iniziato
    function leggiChat() { try { return (sessionStorage.getItem('assistenza_c') || '').replace(/\D/g, ''); } catch (e) { return ''; } }
    function indirizzo() {
        var t = leggiToken(), c = leggiChat();
        return BASE + 'assistenza.php?pannello=1' + (t ? '&t=' + encodeURIComponent(t) : '') + (c ? '&c=' + c : '');
    }

    var stile = document.createElement('style');
    stile.textContent =
        '#assistenza-widget{position:fixed;right:16px;bottom:16px;z-index:1090;font-family:inherit}' +
        '#assistenza-widget .aw-btn{display:flex;align-items:center;gap:8px;border:0;border-radius:28px;background:#B30000;color:#fff;font-weight:700;padding:12px 18px;box-shadow:0 4px 14px rgba(0,0,0,.3);cursor:pointer;font-size:1rem}' +
        '#assistenza-widget .aw-btn:hover,#assistenza-widget .aw-btn:focus{background:#8a0000;outline:3px solid #ffbf47;outline-offset:2px}' +
        '#assistenza-widget .aw-pallino{position:absolute;top:-4px;right:-2px;min-width:20px;height:20px;border-radius:10px;background:#ffbf47;color:#000;border:2px solid #fff;font-size:.7rem;line-height:16px;text-align:center;font-weight:700;padding:0 4px}' +
        '#assistenza-widget .aw-pannello{position:absolute;right:0;bottom:64px;width:400px;max-width:calc(100vw - 32px);height:640px;max-height:calc(100vh - 100px);background:#fff;border-radius:12px;box-shadow:0 8px 30px rgba(0,0,0,.35);overflow:hidden;display:none;flex-direction:column;border:1px solid #cbd5e1}' +
        '#assistenza-widget.aperto .aw-pannello{display:flex}' +
        '#assistenza-widget.grande .aw-pannello{width:760px;max-width:calc(100vw - 32px);height:calc(100vh - 100px);max-height:none}' +
        '#assistenza-widget .aw-azioni{display:flex;align-items:center;gap:2px}' +
        '#assistenza-widget .aw-testa{background:#333;color:#fff;padding:10px 14px;display:flex;align-items:center;justify-content:space-between;font-weight:700}' +
        '#assistenza-widget .aw-chiudi,#assistenza-widget .aw-icona{background:transparent;border:0;color:#fff;font-size:1.3rem;line-height:1;cursor:pointer;padding:4px 7px;border-radius:6px}' +
        '#assistenza-widget .aw-icona{font-size:1rem}#assistenza-widget .aw-chiudi:hover,#assistenza-widget .aw-icona:hover,#assistenza-widget .aw-chiudi:focus,#assistenza-widget .aw-icona:focus{background:rgba(255,255,255,.2);outline:2px solid #ffbf47}' +
        '#assistenza-widget iframe{border:0;width:100%;flex:1}' +
        '@media (max-width:575px){#assistenza-widget .aw-pannello{position:fixed;right:8px;left:8px;bottom:76px;width:auto;max-width:none;height:calc(100vh - 100px)}#assistenza-widget .aw-btn span.aw-testo{display:none}#assistenza-widget .aw-btn{padding:14px}}' +
        '@media (prefers-reduced-motion:no-preference){#assistenza-widget .aw-pannello{animation:aw-su .15s ease-out}@keyframes aw-su{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}}';
    document.head.appendChild(stile);

    var w = document.createElement('div');
    w.id = 'assistenza-widget';
    w.innerHTML =
        '<div class="aw-pannello" id="aw-pannello" role="dialog" aria-label="Assistenza"><div class="aw-testa"><span><i class="fa fa-headset me-1" aria-hidden="true"></i> Assistenza</span>' +
        '<span class="aw-azioni"><button type="button" class="aw-icona aw-grande" aria-label="Ingrandisci la finestra" title="Ingrandisci"><i class="fa fa-expand" aria-hidden="true"></i></button>' +
        '<button type="button" class="aw-icona aw-popup" aria-label="Apri la chat in una finestra separata" title="Apri in una finestra"><i class="fa fa-up-right-from-square" aria-hidden="true"></i></button>' +
        '<button type="button" class="aw-chiudi" aria-label="Chiudi la finestra dell\'assistenza">&times;</button></span></div></div>' +
        '<button type="button" class="aw-btn" aria-expanded="false" aria-controls="aw-pannello"><i class="fa fa-headset" aria-hidden="true"></i><span class="aw-testa-testo aw-testo">Serve aiuto?</span><span class="aw-pallino" hidden></span></button>';
    document.body.appendChild(w);

    var btn = w.querySelector('.aw-btn'), pannello = w.querySelector('.aw-pannello'), pallino = w.querySelector('.aw-pallino'), iframe = null;

    function apri(on) {
        w.classList.toggle('aperto', on);
        btn.setAttribute('aria-expanded', on ? 'true' : 'false');
        if (on) {
            if (!iframe) {
                iframe = document.createElement('iframe');
                iframe.title = 'Chat di assistenza';
                iframe.src = indirizzo();
                pannello.appendChild(iframe);
            }
            pallino.hidden = true;
        } else {
            btn.focus();
        }
    }
    btn.addEventListener('click', function () { apri(!w.classList.contains('aperto')); });
    w.querySelector('.aw-chiudi').addEventListener('click', function () { apri(false); });

    // Ingrandisci: la finestrella occupa quasi tutta l'altezza e diventa larga; la scelta si ricorda (facoltativo)
    var bGrande = w.querySelector('.aw-grande');
    function grande(on) {
        w.classList.toggle('grande', on);
        bGrande.setAttribute('aria-label', on ? 'Riduci la finestra' : 'Ingrandisci la finestra');
        bGrande.title = on ? 'Riduci' : 'Ingrandisci';
        bGrande.firstChild.className = 'fa ' + (on ? 'fa-compress' : 'fa-expand');
        try { localStorage.setItem('assistenza_grande', on ? '1' : '0'); } catch (e) {}
    }
    try { if (localStorage.getItem('assistenza_grande') === '1') grande(true); } catch (e) {}
    bGrande.addEventListener('click', function () { grande(!w.classList.contains('grande')); });

    // Apri in una finestra separata (popup): la stessa chat, con tutto lo spazio che serve, anche su un secondo schermo
    w.querySelector('.aw-popup').addEventListener('click', function () {
        var f = window.open(indirizzo(), 'assistenza_didacticus', 'popup=yes,width=560,height=780,resizable=yes,scrollbars=yes');
        if (f) { f.focus(); apri(false); } else { grande(true); }   // popup bloccato dal browser: si ingrandisce la finestrella
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && w.classList.contains('aperto')) apri(false); });

    // Pallino «hai una risposta»: ogni 30 secondi, solo a finestrella chiusa e scheda visibile, e solo se c'è una chat (login o link personale)
    function controlla() {
        if (w.classList.contains('aperto') || document.hidden) return;
        var t = leggiToken();
        fetch(BASE + 'assistenza_ajax.php?azione=stato' + (t ? '&t=' + encodeURIComponent(t) : ''), { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (r) { if (r && r.ok && r.non_letti > 0) { pallino.textContent = r.non_letti; pallino.hidden = false; } })
            .catch(function () {});
    }
    // Con il banner dei cookie in basso il pulsante sale sopra di lui, così non resta nascosto
    function posizione() {
        var b = document.getElementById('cookieBanner');
        w.style.bottom = (b && getComputedStyle(b).display !== 'none' ? b.offsetHeight + 12 : 16) + 'px';
    }
    var banner = document.getElementById('cookieBanner');
    if (banner && window.MutationObserver) new MutationObserver(posizione).observe(banner, { attributes: true, attributeFilter: ['style'] });
    window.addEventListener('resize', posizione);
    posizione();
    setTimeout(posizione, 600);
    setTimeout(controlla, 3000);
    setInterval(controlla, 30000);
})();
