// Controllo nel browser del PDF firmato, prima dell'invio: estensione (.pdf, non .p7m) e dimensione.
// Si attiva sui campi file con data-pdf-firmato="firmato" (PDF firmato PAdES) o data-pdf-firmato="semplice" (PDF anche senza firma);
// data-max-mb cambia il limite (10 MB). I testi sono gli stessi del server (src/Infrastructure/Pdf/MessaggiFirma.php): il server controlla comunque
// anche la firma, qui si evita solo di aspettare l'invio per scoprire un file sbagliato.
(function () {
    'use strict';

    function messaggio(file, tipo, maxMb) {
        var nome = (file.name || '').toLowerCase();
        if (/\.p7m$/.test(nome) || /\.p7m\./.test(nome)) {
            return tipo === 'firmato'
                ? 'Hai scelto un file .p7m: è una firma in formato CAdES e il portale non la accetta. Cosa fare: firma di nuovo il PDF scegliendo il formato PAdES (nel programma di firma l\'opzione «PDF firmato») e carica il file .pdf che ne esce.'
                : 'Hai scelto un file .p7m: qui serve il PDF. Cosa fare: carica il file che termina con .pdf.';
        }
        if (!/\.pdf$/.test(nome)) {
            return 'Il file non è un PDF. Cosa fare: carica il PDF' + (tipo === 'firmato' ? ' firmato' : '') + ' (il file termina con .pdf), non un documento Word o un\'immagine.';
        }
        if (file.size === 0) {
            return 'Il file è vuoto. Cosa fare: scegli di nuovo il PDF' + (tipo === 'firmato' ? ' firmato' : '') + ' e riprova.';
        }
        if (file.size > maxMb * 1048576) {
            return 'Il file è troppo grande: il limite è ' + maxMb + ' MB. Cosa fare: riduci le immagini del documento' + (tipo === 'firmato' ? ' prima della firma, poi firma e carica di nuovo.' : ' e carica di nuovo.');
        }
        return '';
    }

    function avviso(campo) {
        var el = campo.parentNode.querySelector('.controllo-pdf-avviso');
        if (!el) {
            el = document.createElement('div');
            el.className = 'controllo-pdf-avviso alert alert-warning small py-2 mt-1 mb-2';
            el.setAttribute('role', 'alert');
            campo.insertAdjacentElement('afterend', el);
        }
        return el;
    }

    function controlla(campo) {
        var tipo = campo.getAttribute('data-pdf-firmato') === 'semplice' ? 'semplice' : 'firmato';
        var maxMb = parseInt(campo.getAttribute('data-max-mb') || '10', 10) || 10;
        var el = avviso(campo);
        var file = campo.files && campo.files[0];
        var testo = file ? messaggio(file, tipo, maxMb) : '';
        if (testo !== '') {
            campo.value = '';
            el.textContent = testo;
            el.hidden = false;
        } else {
            el.textContent = '';
            el.hidden = true;
        }
    }

    function avvia() {
        document.querySelectorAll('input[type="file"][data-pdf-firmato]').forEach(function (campo) {
            campo.addEventListener('change', function () { controlla(campo); });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', avvia);
    } else {
        avvia();
    }
})();
