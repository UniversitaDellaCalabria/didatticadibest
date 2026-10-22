/* assistenza-push.js - Iscrive questo browser alle notifiche a finestrella dell'assistenza (Web Push).
   Espone window.AssistenzaPush: supportato(), stato(), iscrivi(chiavePubblica), disiscrivi(). Non manda nulla da sola: la pagina decide cosa fare con la sottoscrizione. */
(function () {
    'use strict';

    function b64uInByte(testo) {
        var b = (testo + '==='.slice((testo.length + 3) % 4)).replace(/-/g, '+').replace(/_/g, '/');
        var raw = atob(b), out = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
        return out;
    }

    // Il service worker sta alla radice del portale (…/sw.js): da una pagina in una cartella (admin/) si risale
    function percorsoSw() { return new URL(document.currentScript ? document.currentScript.src : location.href).pathname.replace(/assets\/js\/[^/]*$/, '') + 'sw.js'; }
    var SW = percorsoSw(), SCOPE = SW.replace(/sw\.js$/, '');

    function registrazione() {
        return navigator.serviceWorker.getRegistration(SCOPE).then(function (r) {
            return r || navigator.serviceWorker.register(SW, { scope: SCOPE });
        }).then(function () { return navigator.serviceWorker.ready; });
    }

    window.AssistenzaPush = {
        /** Il browser sa fare notifiche push? (non su vecchi browser, né su iPhone senza l'app installata) */
        supportato: function () { return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window; },

        /** @returns Promise<{permesso: string, sottoscrizione: PushSubscription|null}> */
        stato: function () {
            if (!this.supportato()) return Promise.resolve({ permesso: 'non_supportato', sottoscrizione: null });
            return registrazione().then(function (reg) { return reg.pushManager.getSubscription(); }).then(function (s) {
                return { permesso: Notification.permission, sottoscrizione: s };
            }).catch(function () { return { permesso: Notification.permission, sottoscrizione: null }; });
        },

        /** Chiede il permesso (se serve) e si iscrive. @returns Promise<PushSubscription> */
        iscrivi: function (chiavePubblica) {
            if (!this.supportato()) return Promise.reject(new Error('Questo browser non supporta le notifiche.'));
            return Notification.requestPermission().then(function (p) {
                if (p !== 'granted') throw new Error(p === 'denied' ? 'Le notifiche sono bloccate per questo sito: abilitale dalle impostazioni del browser (icona del lucchetto).' : 'Permesso non concesso.');
                return registrazione();
            }).then(function (reg) {
                return reg.pushManager.getSubscription().then(function (s) {
                    return s || reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64uInByte(chiavePubblica) });
                });
            });
        },

        /** Toglie l'iscrizione di questo browser. @returns Promise<string> l'indirizzo che si è tolto ('' se non c'era) */
        disiscrivi: function () {
            return this.stato().then(function (st) {
                if (!st.sottoscrizione) return '';
                var e = st.sottoscrizione.endpoint;
                return st.sottoscrizione.unsubscribe().then(function () { return e; });
            });
        }
    };
})();
