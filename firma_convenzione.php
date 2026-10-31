<?php
// firma_convenzione.php - Firma PAdES del Direttore sulla convenzione FSL già firmata dalla scuola (link personale dall'email).
// Si firma con la firma remota Aruba direttamente dal portale (utente, password/PIN e OTP; non si salvano) oppure scaricando il PDF,
// firmandolo in PAdES (es. ArubaSign, Dike, Acrobat) e caricandolo. Il portale accetta solo il PDF della scuola con una firma in più,
// senza altre modifiche (i .p7m CAdES sono rifiutati). Logica in src/Fsl/ServizioFirmaDirettore.php.
require_once __DIR__ . '/middleware.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$firma = \App\Core\App::per($conn)->get(\App\Fsl\ServizioFirmaDirettore::class);
$tok = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ''));
$cc = $firma->perToken($tok);
$io = $cc && $firma->puoFirmare($user_info);
$in_attesa = $cc && ($cc['stato_iter'] ?? '') === \App\Fsl\Costanti::ITER_DA_FIRMARE;
$dati = $cc ? (json_decode((string)$cc['dati_json'], true) ?: []) : [];
$scuola = (string)(($dati['scuola']['denominazione'] ?? '') ?: 'Scuola');

// PDF della scuola da vedere o scaricare
if ($cc && $io && isset($_GET['pdf']) && ($p = $firma->pdfDaFirmare($cc))) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf'); header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . (isset($_GET['scarica']) ? 'attachment' : 'inline') . '; filename="Convenzione_FSL_da_firmare.pdf"');
    header('Content-Length: ' . filesize($p));
    readfile($p); exit;
}

if ($cc && $io && $in_attesa && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $err = null;
    if (isset($_POST['firma_remota'])) {
        if (!check_rate_limit($conn, 'firma_remota', 10, 900)) $err = "Troppi tentativi: riprova tra qualche minuto.";
        else $err = $firma->firmaConAruba($cc, (string)($_POST['utente'] ?? ''), (string)($_POST['password'] ?? ''), (string)($_POST['otp'] ?? ''));
    } elseif (isset($_POST['carica'])) {
        $f = $_FILES['pdf_firmato'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name']) || $f['size'] > 20 * 1024 * 1024) $err = "Scegli il PDF firmato (fino a 20 MB).";
        else $err = $firma->caricaFirmato($cc, (string)file_get_contents($f['tmp_name']));
    }
    unset($_POST['password'], $_POST['otp']);
    if (!$err) registra_log_audit($conn, "Convenzione FSL firmata dal Direttore", ["Compilazione" => (int)$cc['id'], "Scuola" => $scuola]);
    flash_set($err ?? "Firma registrata: la convenzione passa all'Ufficio per il protocollo. Grazie.", $err ? 'danger' : 'success');
    header('Location: firma_convenzione.php?t=' . urlencode($tok) . '&r=' . time()); exit;
}

$page_cfg['titolo'] = 'Firma della convenzione FSL';
require_once 'header.php';
?>
<div class="container my-4" style="max-width: 1000px;">
    <?php echo flash_html() . ui_prossima_cosa(); ?>
    <?php if (!$cc || ($cc['stato_iter'] ?? '') === 'rifiutata'): ?>
        <?php echo ui_link_non_valido('Documento non disponibile', "Il link non è valido o la convenzione è stata ritirata dall'ufficio."); ?>
    <?php elseif (!$io): $d = $firma->direttore(); ?>
        <h1 class="fw-bold h3">Firma della convenzione</h1>
        <div class="alert alert-warning">Questa pagina è per <strong><?php echo $h($d['nome'] ?: 'il Direttore del Dipartimento'); ?></strong>: accedi con le sue credenziali (Unical ID, SPID o CIE).</div>
        <a class="btn btn-outline-secondary" href="esci.php">Esci</a>
    <?php else: ?>
        <h1 class="fw-bold h3 mb-1">Convenzione Formazione Scuola Lavoro · <?php echo $h($scuola); ?></h1>
        <p class="text-secondary mb-3">Già firmata dal Dirigente Scolastico<?php echo !empty($cc['firmatario']) ? ' (' . $h($cc['firmatario']) . ')' : ''; ?>.</p>
        <div class="row g-3">
            <div class="col-lg-5">
                <?php if ($in_attesa): ?>
                    <div class="alert alert-info small"><i class="fa fa-circle-info me-1" aria-hidden="true"></i>Firma richiesta: <strong>firma digitale PAdES del Direttore</strong> sul PDF qui a fianco, senza modificarlo.</div>
                    <?php if ($firma->firmaRemotaDisponibile()): ?>
                    <form method="POST" class="card border-0 shadow-sm mb-3" style="border-left:5px solid #047857 !important;" autocomplete="off"><div class="card-body">
                        <?php csrf_field(); ?>
                        <h2 class="h6 fw-bold"><i class="fa fa-signature me-1 text-success" aria-hidden="true"></i>Firma remota Aruba</h2>
                        <p class="small text-secondary">Con le credenziali della firma remota. Le credenziali non vengono salvate.</p>
                        <label class="form-label small fw-bold mb-0" for="frU">Utente della firma remota</label><input class="form-control form-control-sm mb-2" id="frU" name="utente" required autocomplete="off">
                        <label class="form-label small fw-bold mb-0" for="frP">Password / PIN</label><input type="password" class="form-control form-control-sm mb-2" id="frP" name="password" required autocomplete="off">
                        <label class="form-label small fw-bold mb-0" for="frO">Codice OTP</label><input class="form-control form-control-sm mb-2" id="frO" name="otp" required inputmode="numeric" autocomplete="one-time-code">
                        <button type="submit" name="firma_remota" value="1" class="btn btn-success fw-bold"><i class="fa fa-pen-nib me-1" aria-hidden="true"></i>Firma in PAdES</button>
                    </div></form>
                    <?php endif; ?>
                    <form method="POST" enctype="multipart/form-data" class="card border-0 shadow-sm"><div class="card-body">
                        <?php csrf_field(); ?>
                        <h2 class="h6 fw-bold"><i class="fa fa-upload me-1" aria-hidden="true"></i><?php echo $firma->firmaRemotaDisponibile() ? 'Oppure: carica il PDF firmato' : 'Firma e carica il PDF'; ?></h2>
                        <ol class="small ps-3 mb-2"><li><a href="firma_convenzione.php?t=<?php echo $h(urlencode($tok)); ?>&amp;pdf=1&amp;scarica=1">Scarica il PDF</a> della convenzione.</li>
                            <li>Firmalo con il tuo strumento di firma (es. ArubaSign, Dike, Acrobat) scegliendo <strong>PAdES</strong> (PDF), non CAdES (.p7m).</li><li>Carica qui il PDF firmato, senza modificarlo.</li></ol>
                        <input type="file" name="pdf_firmato" class="form-control form-control-sm mb-2" accept=".pdf,application/pdf" required aria-label="PDF firmato in PAdES" data-pdf-firmato="firmato"><script src="assets/js/controllo-pdf-firmato.js" defer></script>
                        <button type="submit" name="carica" value="1" class="btn btn-primary fw-bold">Carica il PDF firmato</button>
                    </div></form>
                <?php elseif (in_array($cc['stato_iter'], [\App\Fsl\Costanti::ITER_FIRMATA, \App\Fsl\Costanti::ITER_PROTOCOLLATA], true)): ?>
                    <div class="alert alert-success">Hai già firmato la convenzione: grazie. Ora è all'Ufficio per il protocollo.</div>
                <?php else: ?>
                    <div class="alert alert-secondary">La convenzione non è ancora pronta per la tua firma: riceverai un'email.</div>
                <?php endif; ?>
            </div>
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm"><div class="card-body p-1">
                    <iframe src="firma_convenzione.php?t=<?php echo $h(urlencode($tok)); ?>&amp;pdf=1" title="Convenzione in PDF" style="width:100%;height:78vh;border:0;"></iframe>
                </div></div>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php require_once 'footer.php'; ?>
