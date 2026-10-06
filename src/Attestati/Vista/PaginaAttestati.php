<?php

declare(strict_types=1);

namespace App\Attestati\Vista;

use App\Attestati\NomeFile;

/**
 * Documento HTML stampabile con uno o più attestati (uno per pagina A4 orizzontale).
 * Ogni attestato riporta il codice di verifica e il QR che apre verifica_attestato.php.
 */
final class PaginaAttestati
{
    /**
     * @param list<array<string, mixed>> $lista dati di ogni attestato (ServizioAttestati::dati())
     * @param string $urlBase indirizzo del portale senza "/" finale
     */
    public static function html(array $lista, string $titoloDoc, string $urlBase): string
    {
        $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        ob_start(); ?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $h($titoloDoc); ?></title>
    <link href="<?php echo Librerie::urlVendor($urlBase, 'jsdelivr/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo Librerie::urlVendor($urlBase, 'cdnjs/ajax/libs/font-awesome/6.4.0/css/all.min.css'); ?>">
    <link href="<?php echo Librerie::urlVendor($urlBase, 'fonts/dancing-script.css'); ?>" rel="stylesheet">
    <style>
        body, html { margin: 0; padding: 0; background-color: #e2e8f0; font-family: 'Georgia', 'Times New Roman', serif; color: #1e293b; box-sizing: border-box; }
        @page { size: A4 landscape; margin: 0; }
        @media print {
            body { background-color: white; -webkit-print-color-adjust: exact; print-color-adjust: exact; margin: 0; padding: 0; }
            .no-print { display: none !important; }
            .cert-container { box-shadow: none !important; margin: 0 !important; width: 297mm !important; height: 209mm !important; padding: 12mm !important; page-break-after: always; break-after: page; page-break-inside: avoid; }
            .cert-container:last-of-type { page-break-after: auto; break-after: auto; }
        }
        .cert-container { width: 297mm; height: 210mm; margin: 20px auto; background: #ffffff; box-shadow: 0 10px 30px rgba(0,0,0,0.15); padding: 10mm; position: relative; box-sizing: border-box; overflow: hidden; }
        .cert-border-outer { border: 4px solid #B30000; padding: 5px; height: 100%; border-radius: 4px; box-sizing: border-box; }
        .cert-border-inner { border: 2px solid #0056b3; height: 100%; padding: 20px 40px; text-align: center; position: relative; border-radius: 2px; box-sizing: border-box; display: flex; flex-direction: column; justify-content: space-between; }
        .cert-header { display: flex; justify-content: center; align-items: center; gap: 20px; }
        .cert-logo { max-height: 80px; object-fit: contain; padding: 5px; border-radius: 6px; }
        .cert-main-content { display: flex; flex-direction: column; justify-content: center; flex-grow: 1; }
        .cert-title { font-size: 3.2rem; font-weight: bold; color: #B30000; letter-spacing: 2px; margin: 0 0 10px 0; text-transform: uppercase; line-height: 1.1; }
        .cert-subtitle { font-size: 1.4rem; color: #64748b; font-style: italic; margin-bottom: 20px; }
        .cert-body { font-size: 1.3rem; line-height: 1.5; }
        .cert-name { font-size: 2.3rem; font-weight: bold; color: #1e293b; border-bottom: 1px solid #cbd5e1; display: inline-block; padding: 0 40px; margin: 10px 0; }
        .cert-event { font-size: 1.6rem; font-weight: bold; color: #0056b3; margin: 10px 0; display: block; line-height: 1.2; }
        .cert-footer { display: flex; justify-content: space-between; align-items: flex-end; padding: 0 20px; gap: 20px; }
        .cert-verifica { display: flex; align-items: flex-end; gap: 12px; text-align: left; font-size: 1.05rem; padding-bottom: 6px; }
        .cert-qr { width: 84px; height: 84px; flex-shrink: 0; font-size: .6rem; color: #94a3b8; }
        .cert-qr svg { width: 100%; height: 100%; display: block; }
        .cert-signature { width: 300px; text-align: center; font-size: 1.1rem; }
        .signature-text { font-family: 'Dancing Script', cursive; font-size: 2.6rem; color: #1e293b; line-height: 0.6; margin-bottom: 10px; transform: rotate(-3deg); white-space: nowrap; }
        .cert-stamp { position: absolute; bottom: 50%; left: 50%; transform: translate(-50%, 50%); opacity: 0.05; font-size: 15rem; color: #B30000; pointer-events: none; }
        .cert-id { position: absolute; bottom: 5px; left: 10px; font-size: 0.75rem; color: #94a3b8; font-family: monospace; }
    </style>
</head>
<body>
    <div class="text-center my-3 no-print">
        <?php $nAtt = count($lista);
        $zipNome = 'attestati_' . (NomeFile::slug((string) preg_replace('/^Attestati? - /', '', $titoloDoc)) ?: 'partecipazione'); ?>
        <div class="d-flex flex-wrap justify-content-center gap-2">
            <button type="button" onclick="window.print()" class="btn btn-danger fw-bold px-4 py-2 shadow-sm fs-5" style="background:#B30000; border:none;"><i class="fa fa-print me-2"></i>Stampa / PDF unico<?php echo $nAtt > 1 ? " ($nAtt attestati)" : ''; ?></button>
            <button type="button" id="btnScaricaAtt" data-zip="<?php echo $h($zipNome); ?>" class="btn btn-success fw-bold px-4 py-2 shadow-sm fs-5"><i class="fa <?php echo $nAtt > 1 ? 'fa-file-zipper' : 'fa-file-pdf'; ?> me-2"></i><?php echo $nAtt > 1 ? "Scarica ZIP ($nAtt PDF separati)" : 'Scarica PDF'; ?></button>
            <button type="button" onclick="window.close()" class="btn btn-outline-secondary py-2 px-4 fw-bold fs-5">Chiudi</button>
        </div>
        <p class="text-muted mt-2 small mb-0"><i class="fa fa-info-circle me-1"></i> <strong>Stampa / PDF unico:</strong> seleziona <strong>Orizzontale</strong>, margini <strong>Nessuno</strong> e <strong>Grafica in background</strong>.<?php if ($nAtt > 1): ?> <strong>Scarica ZIP:</strong> un PDF per studente (attestato_cognome_nome.pdf), comodo da inviare per email.<?php endif; ?></p>
        <div id="attBarra" class="mx-auto mt-3" style="max-width: 520px; display: none;">
            <div class="progress" style="height: 22px;" role="progressbar" aria-label="Preparazione dei PDF" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                <div class="progress-bar progress-bar-striped progress-bar-animated bg-success fw-bold" style="width: 0%;">0%</div>
            </div>
        </div>
        <p id="attAvanzamento" class="fw-semibold mt-2 mb-0" aria-live="polite"></p>
    </div>
    <?php foreach ($lista as $a): $urlVer = $urlBase . '/verifica_attestato.php?c=' . urlencode($a['codice']); ?>
    <div class="cert-container" data-file="<?php echo $h(($a['file'] ?? '') !== '' ? $a['file'] : 'attestato_' . NomeFile::slug($a['nome'])); ?>">
        <div class="cert-border-outer">
            <div class="cert-border-inner">
                <i class="fa fa-award cert-stamp" aria-hidden="true"></i>
                <div class="cert-header">
                    <?php if (!empty($a['logo'])): ?><img src="<?php echo $h($a['logo']); ?>" class="cert-logo" alt="Logo"><?php endif; ?>
                    <div>
                        <h4 class="fw-bold m-0" style="color: #334155;"><?php echo $h($a['portale']); ?></h4>
                        <span style="font-size: 1.1rem; color: #64748b;"><?php echo $h($a['sottotitolo']); ?></span>
                    </div>
                </div>
                <div class="cert-main-content">
                    <h1 class="cert-title">Attestato di Partecipazione</h1>
                    <div class="cert-subtitle">Si attesta che</div>
                    <div class="cert-body">
                        <span class="cert-name"><?php echo $h(mb_strtoupper($a['nome'])); ?></span><br>
                        <?php if ($a['matricola'] !== ''): ?><span style="font-size: 1.1rem; color: #64748b;">(Matricola: <?php echo $h($a['matricola']); ?>)</span><br><?php endif; ?>
                        <span class="mt-3 d-block"><?php echo $h($a['formula']); ?></span>
                        <span class="cert-event">"<?php echo $h($a['evento']); ?>"</span>
                        <span class="d-block mt-2">
                            <?php echo $a['quando'] !== '' ? $h($a['quando']) . ',' : 'Svoltasi'; ?> presso <?php echo $h($a['luogo'] ?: 'le nostre strutture'); ?><?php if ($a['ore'] !== ''): ?>
                            <strong>per un numero di ore pari a <?php echo $h($a['ore']); ?></strong><?php endif; ?>.
                        </span>
                    </div>
                </div>
                <div class="cert-footer">
                    <div class="cert-verifica">
                        <div class="cert-qr" data-qr="<?php echo $h($urlVer); ?>" role="img" aria-label="QR per verificare l'attestato"></div>
                        <div>
                            <strong>Data di rilascio:</strong> <?php echo date('d/m/Y'); ?><br>
                            <?php if ($a['area'] !== ''): ?><strong>Rif. Iniziativa:</strong> <?php echo $h($a['area']); ?><br><?php endif; ?>
                            <span style="font-size: .9rem; color: #475569;">Verifica: inquadra il QR o inserisci il codice <strong style="font-family: monospace;"><?php echo $h($a['codice']); ?></strong> su <?php echo $h(preg_replace('#^https?://#', '', $urlBase)); ?>/verifica_attestato.php</span>
                        </div>
                    </div>
                    <div class="cert-signature">
                        <div class="signature-text"><?php echo $h($a['firma_nome']); ?></div>
                        <div class="border-top border-dark pt-1 mt-1">
                            <span class="fw-bold d-block">Prof. <?php echo $h($a['firma_nome']); ?></span>
                            <small style="color: #64748b;"><?php echo $h($a['firma_titolo']); ?></small>
                        </div>
                    </div>
                </div>
                <div class="cert-id">Codice Verifica Autenticità: <?php echo $h($a['codice']); ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <!-- QR generati nella pagina: nessun servizio esterno riceve l'indirizzo di verifica -->
    <?php echo Librerie::scriptLibreria($urlBase, 'qrcode'); ?>
    <script>
    document.querySelectorAll('.cert-qr').forEach(function (el) {
        if (typeof qrcode !== 'function') { el.textContent = 'QR non disponibile: usa il codice'; return; }
        var q = qrcode(0, 'M'); q.addData(el.dataset.qr); q.make();
        el.innerHTML = q.createSvgTag({ cellSize: 3, margin: 0, scalable: true });
    });

    // Scarica: ogni attestato diventa un PDF A4 orizzontale (immagine ad alta risoluzione della pagina);
    // con più attestati i PDF vanno in un unico ZIP. Tutto nel browser: librerie caricate solo al clic.
    (function () {
        var btn = document.getElementById('btnScaricaAtt'), stato = document.getElementById('attAvanzamento');
        if (!btn) return;
        var barra = document.getElementById('attBarra'), pb = barra.querySelector('.progress'), pbi = barra.querySelector('.progress-bar');
        var percentuale = function (p, finito) {
            barra.style.display = '';
            pbi.style.width = p + '%'; pbi.textContent = p + '%'; pb.setAttribute('aria-valuenow', p);
            pbi.classList.toggle('progress-bar-animated', !finito);
        };
        // Impronte SRI: il browser rifiuta le librerie se il CDN le servisse modificate
        var sri = {
            'html2canvas/1.4.1/html2canvas.min.js': 'sha384-ZZ1pncU3bQe8y31yfZdMFdSpttDoPmOZg2wguVK9almUodir1PghgT0eY7Mrty8H',
            'jspdf/2.5.1/jspdf.umd.min.js': 'sha384-JcnsjUPPylna1s1fvi1u12X5qjY5OL56iySh75FdtrwhO/SWXgMjoVqcKyIIWOLk',
            'jszip/3.10.1/jszip.min.js': 'sha384-+mbV2IY1Zk/X1p/nWllGySJSUN8uMs+gUAN10Or95UBH0fpj6GfKgPmgC5EXieXG'
        };
        var carica = function (src) {
            return new Promise(function (ok, ko) {
                var s = document.createElement('script'); s.src = src; s.crossOrigin = 'anonymous';
                var chiave = src.split('/ajax/libs/')[1]; if (sri[chiave]) s.integrity = sri[chiave];
                s.onload = ok; s.onerror = ko; document.head.appendChild(s);
            });
        };
        var salva = function (blob, nome) {
            var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = nome;
            document.body.appendChild(a); a.click(); a.remove();
            setTimeout(function () { URL.revokeObjectURL(a.href); }, 10000);
        };
        btn.addEventListener('click', async function () {
            var fogli = Array.prototype.slice.call(document.querySelectorAll('.cert-container'));
            var testo = btn.innerHTML; btn.disabled = true;
            try {
                stato.textContent = 'Preparazione in corso…'; percentuale(0);
                var lib = '<?php echo Librerie::urlVendor($urlBase, 'cdnjs/ajax/libs/'); ?>';
                await carica(lib + 'html2canvas/1.4.1/html2canvas.min.js');
                await carica(lib + 'jspdf/2.5.1/jspdf.umd.min.js');
                if (fogli.length > 1) await carica(lib + 'jszip/3.10.1/jszip.min.js');
                if (document.fonts && document.fonts.ready) await document.fonts.ready;
                var zip = fogli.length > 1 ? new JSZip() : null, usati = {};
                for (var i = 0; i < fogli.length; i++) {
                    stato.textContent = 'Creazione del PDF ' + (i + 1) + ' di ' + fogli.length + '…';
                    var canvas = await html2canvas(fogli[i], { scale: 2, backgroundColor: '#ffffff', useCORS: true, windowWidth: 1400 });
                    var pdf = new jspdf.jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4', compress: true });
                    pdf.addImage(canvas.toDataURL('image/jpeg', 0.92), 'JPEG', 0, 0, 297, 210);
                    // Nomi uguali (omonimi): attestato_rossi_mario_2.pdf
                    var base = fogli[i].dataset.file || ('attestato_' + (i + 1));
                    usati[base] = (usati[base] || 0) + 1;
                    var nome = base + (usati[base] > 1 ? '_' + usati[base] : '') + '.pdf';
                    if (zip) zip.file(nome, pdf.output('blob')); else salva(pdf.output('blob'), nome);
                    percentuale(Math.round((i + 1) / fogli.length * (zip ? 90 : 100)));
                }
                if (zip) {
                    stato.textContent = 'Creazione dello ZIP…';
                    salva(await zip.generateAsync({ type: 'blob' }, function (m) { percentuale(90 + Math.round(m.percent / 10)); }), btn.dataset.zip + '.zip');
                }
                percentuale(100, true);
                stato.textContent = 'Download completato: trovi il file nella cartella Download.';
            } catch (e) {
                console.error(e);
                barra.style.display = 'none';
                stato.textContent = 'Download non riuscito: riprova, oppure usa "Stampa / PDF unico".';
            }
            btn.disabled = false; btn.innerHTML = testo;
        });
    })();
    </script>
</body>
</html>
<?php
        return (string) ob_get_clean();
    }
}
