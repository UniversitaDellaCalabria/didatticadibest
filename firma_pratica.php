<?php
// firma_pratica.php - Firma digitale PAdES su una pratica degli studenti (link personale dall'email): del relatore indicato nel modulo
// o del Direttore. Dopo l'accesso con le credenziali di Ateneo (la stessa email a cui è arrivato il link) si firma con la firma remota Aruba,
// se configurata, oppure si scarica il PDF, si firma in PAdES e si ricarica; si può anche rifiutare con un motivo (la pratica torna allo
// studente). Dopo la firma la pratica prosegue nell'iter. Logica in src/Didattica/FirmaPratica.php.
require_once __DIR__ . '/middleware.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$firma = \App\Core\App::per($conn)->get(\App\Didattica\FirmaPratica::class);
$tok = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ''));
$f = $firma->perToken($tok);
$p = $f ? pratica($conn, (int)$f['pratica_id']) : null;
$io = $f && $p && $firma->puoFirmare($f, $user_info);
$in_attesa = $f && $f['stato'] === 'attesa';
$ruolo = $f ? ($f['tipo'] === 'direttore' ? 'Direttore' : 'firmatario indicato nel modulo') : '';

// PDF da firmare (da vedere o scaricare)
if ($f && $io && isset($_GET['pdf']) && ($file = $firma->pdfDaFirmare($f))) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf'); header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . (isset($_GET['scarica']) ? 'attachment' : 'inline') . '; filename="Pratica_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$p['codice']) . '_da_firmare.pdf"');
    header('Content-Length: ' . filesize($file));
    readfile($file); exit;
}

if ($f && $io && $in_attesa && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $err = null; $fatto = '';
    if (isset($_POST['rifiuta'])) {
        $err = $firma->rifiuta($f, (string)($_POST['motivo'] ?? ''));
        $fatto = "Hai rifiutato la firma: lo studente è stato avvisato.";
        if (!$err) registra_log_audit($conn, "Pratica: firma rifiutata", ["Pratica" => $p['codice'], "Tipo" => $f['tipo']]);
    } else {
        if (isset($_POST['firma_remota'])) {
            if (!check_rate_limit($conn, 'firma_remota', 10, 900)) $err = "Troppi tentativi: riprova tra qualche minuto.";
            else $err = $firma->firmaConAruba($f, (string)($_POST['utente'] ?? ''), (string)($_POST['password'] ?? ''), (string)($_POST['otp'] ?? ''));
        } elseif (isset($_POST['carica'])) {
            $up = $_FILES['pdf_firmato'] ?? null;
            if (!$up || ($up['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$up['tmp_name']) || $up['size'] > 20 * 1024 * 1024) $err = "Scegli il PDF firmato (fino a 20 MB).";
            else $err = $firma->caricaFirmato($f, (string)file_get_contents($up['tmp_name']));
        }
        $fatto = "Firma registrata: la pratica prosegue. Grazie.";
        if (!$err) {
            registra_log_audit($conn, "Pratica firmata", ["Pratica" => $p['codice'], "Tipo" => $f['tipo']]);
            // Passo successivo dell'iter (la firma è il passo «passo» dell'iter: il successivo ha indice «passo»)
            [$e2, $msg2] = \App\Core\App::per($conn)->get(\App\Didattica\ServizioFlussoConvalide::class)->trasmettiAlPasso((int)$p['id'], (int)$f['passo'], 0, (string)($f['nome'] ?: 'Firmatario'));
            if ($e2) \App\Core\App::per($conn)->get(\App\Didattica\StoricoPratica::class)->evento((int)$p['id'], 'messaggio', 'ufficio', 0, null, 'Dopo la firma: ' . $e2, null, null, true);
        }
    }
    unset($_POST['password'], $_POST['otp']);
    flash_set($err ?? $fatto, $err ? 'danger' : 'success');
    header('Location: firma_pratica.php?t=' . urlencode($tok) . '&r=' . time()); exit;
}

$page_cfg['titolo'] = 'Firma della pratica';
require_once 'header.php';
?>
<div class="container my-4" style="max-width: 1000px;">
    <?php echo flash_html() . ui_prossima_cosa(); ?>
    <?php if (!$f || !$p): ?>
        <?php echo ui_link_non_valido('Documento non disponibile', 'Il link non è valido o la richiesta di firma è stata annullata.'); ?>
    <?php elseif (!$io): ?>
        <h1 class="fw-bold h3">Firma della pratica</h1>
        <div class="alert alert-warning">Questa pagina è per <strong><?php echo $h($f['nome']); ?></strong>: accedi con le sue credenziali (Unical ID, SPID o CIE).</div>
        <a class="btn btn-outline-secondary" href="esci.php">Esci</a>
    <?php else: ?>
        <h1 class="fw-bold h3 mb-1"><?php echo $h($p['modulo_titolo']); ?> · <?php echo $h(trim($p['nome'] . ' ' . $p['cognome'])); ?></h1>
        <p class="text-secondary mb-3">Pratica <?php echo $h($p['codice']); ?><?php echo $p['matricola'] !== '' ? ' · matricola ' . $h($p['matricola']) : ''; ?> · firma richiesta come <strong><?php echo $h($ruolo); ?></strong>.</p>
        <div class="row g-3">
            <div class="col-lg-5">
                <?php if ($in_attesa): ?>
                    <div class="alert alert-info small"><i class="fa fa-circle-info me-1" aria-hidden="true"></i>Firma richiesta: <strong>firma digitale PAdES</strong> sul PDF qui a fianco, senza modificarlo.</div>
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
                    <form method="POST" enctype="multipart/form-data" class="card border-0 shadow-sm mb-3"><div class="card-body">
                        <?php csrf_field(); ?>
                        <h2 class="h6 fw-bold"><i class="fa fa-upload me-1" aria-hidden="true"></i><?php echo $firma->firmaRemotaDisponibile() ? 'Oppure: carica il PDF firmato' : 'Firma e carica il PDF'; ?></h2>
                        <ol class="small ps-3 mb-2"><li><a href="firma_pratica.php?t=<?php echo $h(urlencode($tok)); ?>&amp;pdf=1&amp;scarica=1">Scarica il PDF</a>.</li>
                            <li>Firmalo con il tuo strumento di firma (es. ArubaSign, Dike, Acrobat) scegliendo <strong>PAdES</strong> (PDF), non CAdES (.p7m).</li><li>Carica qui il PDF firmato, senza modificarlo.</li></ol>
                        <input type="file" name="pdf_firmato" class="form-control form-control-sm mb-2" accept=".pdf,application/pdf" required aria-label="PDF firmato in PAdES" data-pdf-firmato="firmato"><script src="assets/js/controllo-pdf-firmato.js" defer></script>
                        <button type="submit" name="carica" value="1" class="btn btn-primary fw-bold">Carica il PDF firmato</button>
                    </div></form>
                    <form method="POST" class="card border-0 shadow-sm"><div class="card-body">
                        <?php csrf_field(); ?>
                        <h2 class="h6 fw-bold"><i class="fa fa-ban me-1 text-danger" aria-hidden="true"></i>Non posso firmare</h2>
                        <label class="form-label small mb-1" for="rifM">Scrivi il motivo: lo studente lo riceve per email e può correggere la richiesta.</label>
                        <textarea class="form-control form-control-sm mb-2" id="rifM" name="motivo" rows="3" maxlength="500" required></textarea>
                        <button type="submit" name="rifiuta" value="1" class="btn btn-outline-danger btn-sm fw-bold" data-confirm="Rifiutare la firma? La pratica torna allo studente.">Rifiuta la firma</button>
                    </div></form>
                <?php elseif ($f['stato'] === 'firmata'): ?>
                    <div class="alert alert-success">Hai già firmato il documento<?php echo $f['firmata_il'] ? ' il ' . date('d/m/Y H:i', strtotime($f['firmata_il'])) : ''; ?>: grazie.</div>
                <?php elseif ($f['stato'] === 'rifiutata'): ?>
                    <div class="alert alert-secondary">Hai rifiutato la firma: <?php echo $h($f['motivo']); ?></div>
                <?php else: ?>
                    <div class="alert alert-secondary">Questa richiesta di firma è stata annullata dall'ufficio.</div>
                <?php endif; ?>
            </div>
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm"><div class="card-body p-1">
                    <iframe src="firma_pratica.php?t=<?php echo $h(urlencode($tok)); ?>&amp;pdf=1" title="Documento in PDF" style="width:100%;height:78vh;border:0;"></iframe>
                </div></div>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php require_once 'footer.php'; ?>
