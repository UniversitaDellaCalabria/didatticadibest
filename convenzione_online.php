<?php
// convenzione_online.php - Convenzione FSL e Allegato A compilati online dalla scuola con un modulo guidato:
// dati dell'istituto e del Dirigente, logo della scuola, attività dell'Allegato A (quelle già prenotate dalla scuola e,
// se vuole, altre attività FSL ancora da prenotare, con il promemoria di prenotarle). Al termine si scaricano i due
// documenti Word dai modelli del Dipartimento, da firmare digitalmente e inviare via PEC.
// Accesso con il codice della prenotazione (?code=, come la ricevuta) o con il link personale ricevuto per email (?t=).
require_once 'config.php';
require_once 'functions.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$nega = function (string $msg) use ($h) {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(404);
    exit('<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Convenzione non disponibile</title></head>'
       . '<body style="font-family:system-ui,sans-serif;max-width:640px;margin:3rem auto;padding:0 1rem;"><h1>Convenzione non disponibile</h1><p>' . $h($msg) . '</p><p><a href="ricevi_link_convenzione.php">Hai perso il link? Fattelo rimandare per email</a> · <a href="index.php">Torna al portale</a></p></body></html>');
};
if (!check_rate_limit($conn, 'convenzione_online', 120, 3600)) $nega("Troppe richieste: riprova tra poco.");

// ── Accesso: link personale (token) o codice della prenotazione ──
$conv_online = \App\Core\App::per($conn)->get(\App\Fsl\ServizioConvenzioneOnline::class);
$cc = null;
$tok = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ''));
if ($tok !== '') {
    $cc = $conv_online->perToken($tok);
    if (!$cc) $nega("Il link non è valido o è scaduto.");
} else {
    $codice = strtoupper(trim((string)($_GET['code'] ?? '')));
    // Senza link né codice: si va alla pagina «Ho perso il link»
    if ($codice === '') { header('Location: ricevi_link_convenzione.php'); exit; }
    // Una sola compilazione per prenotazione: se c'è già si riprende
    $tok = $conv_online->tokenDaCodice($codice);
    if ($tok === null) $nega("Controlla il codice della prenotazione: la convenzione si compila dal link della conferma o dell'email.");
    header('Location: convenzione_online.php?t=' . $tok); exit;
}

$ctx = $conv_online->contesto($cc);
if (!$ctx) $nega("La prenotazione collegata non esiste più.");
['p0' => $p0, 'cfg' => $cfg, 'prenotate' => $prenotate, 'prenotabili' => $prenotabili, 'dati' => $dati, 's' => $s] = $ctx;

// ── Documenti: genera e scarica ──
if (isset($_GET['scarica']) && $_GET['scarica'] !== 'firmata' && $dati) {
    $doc_pronto = $conv_online->scarica((string)$_GET['scarica'], $cc, $ctx);
    if ($doc_pronto) invia_file_scaricabile($doc_pronto['file'], $doc_pronto['nome'], str_ends_with($doc_pronto['nome'], '.pdf') ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
}

// ── Convenzione firmata: la scuola la scarica di nuovo, la carica o la sostituisce ──
$conv_firmata = \App\Core\App::per($conn)->get(\App\Fsl\ServizioConvenzioneFirmata::class);
if (($_GET['scarica'] ?? '') === 'firmata') {
    $ff = $conv_firmata->fileDaScaricare((int)$cc['id']);
    if (!$ff) $nega("Non c'è nessuna convenzione firmata caricata.");
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $ff['nome'] . '"');
    header('Content-Length: ' . filesize($ff['percorso']));
    header('X-Content-Type-Options: nosniff');
    readfile($ff['percorso']);
    exit;
}
$errore_firmata = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['carica_firmata'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $errore_firmata = $conv_firmata->caricaDaUpload($cc, $_FILES['convenzione_firmata'] ?? null, $_FILES['allegato_a'] ?? null);
    if ($errore_firmata === null) {
        flash_set("Convenzione firmata ricevuta: l'ufficio la prende in carico e ti scriviamo appena è pronta.");
        header('Location: convenzione_online.php?t=' . $cc['token'] . '&r=' . time() . '#firmata'); exit;
    }
    $cc = $conv_online->perToken($cc['token']) ?: $cc;
}

// ── Messaggi con il Dipartimento: la scuola risponde o scrive all'ufficio ──
$msg_serv = \App\Core\App::per($conn)->get(\App\Fsl\ServizioMessaggiConvenzione::class);
$msg_rep = \App\Core\App::per($conn)->get(\App\Fsl\MessaggiConvenzioneRepository::class);
$errore_msg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['scrivi_ufficio'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $errore_msg = $msg_serv->rispondi($cc, (string)($_POST['testo'] ?? ''));
    if ($errore_msg === null) {
        flash_set("Messaggio mandato: l'ufficio lo riceve per email e ti risponde appena può.");
        header('Location: convenzione_online.php?t=' . $cc['token'] . '&r=' . time() . '#messaggi'); exit;
    }
}

// ── Salvataggio del modulo ──
$errori = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salva_convenzione'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $esito = $conv_online->salva($cc, $ctx, $_POST, $_FILES['logo'] ?? null);
    $s = $esito['s'];
    $errori = $esito['errori'];
    if (!$errori) {
        flash_set("Dati salvati: scarica i documenti, falli firmare digitalmente al Dirigente e inviali via PEC.");
        header('Location: convenzione_online.php?t=' . $cc['token'] . '&r=' . time() . '#documenti'); exit;
    }
}

$scelte = [];
foreach ($dati['attivita'] ?? [] as $a) $scelte[!empty($a['pr']) ? 'pr' . $a['pr'] : 'ev' . ($a['ev'] ?? 0)] = $a;
if (!$dati) foreach ($prenotate as $id => $p) $scelte['pr' . $id] = ['studenti' => 0, 'tutor' => ''];
$non_prenotate = array_filter(array_keys($scelte), fn($k) => str_starts_with($k, 'ev'));

$page_cfg['titolo'] = 'Convenzione Formazione Scuola Lavoro';
require_once 'header.php';
?>
<style>
.cv-passo { display:inline-flex; width:28px; height:28px; border-radius:50%; background:#B30000; color:#fff; align-items:center; justify-content:center; font-weight:bold; margin-right:.4rem; }
.cv-att { border:1px solid #e5e7eb; border-radius:10px; padding:.7rem .9rem; margin-bottom:.5rem; }
.cv-att.scelta { border-color:#16a34a; background:#f0fdf4; }
</style>
<div class="container my-4" style="max-width: 960px;">
    <h1 class="fw-bold h2 mb-1"><i class="fa fa-file-signature me-2" style="color:#B30000;" aria-hidden="true"></i>Convenzione Formazione Scuola Lavoro</h1>
    <p class="text-secondary">Compila i dati: prepariamo noi la <strong>Convenzione</strong> e l'<strong>Allegato A</strong> sui modelli del Dipartimento. Poi li scarichi in Word, il Dirigente li firma digitalmente in PAdES (PDF firmato, non .p7m) e la scuola li invia via PEC.</p>
    <?php echo flash_html(); ?>
    <?php if ($errori): ?><div class="alert alert-danger" role="alert"><strong>Controlla i dati:</strong><ul class="mb-0"><?php foreach ($errori as $e): ?><li><?php echo $h($e); ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <?php if ($dati && !$errori): ?>
    <section id="documenti" class="card border-0 shadow-sm mb-4" style="border-left:5px solid #16a34a !important;"><div class="card-body">
        <h2 class="h5 fw-bold"><i class="fa fa-circle-check text-success me-1" aria-hidden="true"></i>I documenti sono pronti</h2>
        <div class="d-flex flex-wrap gap-2 my-3">
            <a class="btn btn-success fw-bold" href="convenzione_online.php?t=<?php echo $h($cc['token']); ?>&amp;scarica=allegato_pdf"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>Scarica l'Allegato A (PDF)</a>
            <a class="btn btn-success fw-bold" href="convenzione_online.php?t=<?php echo $h($cc['token']); ?>&amp;scarica=convenzione_sola"><i class="fa fa-file-word me-1" aria-hidden="true"></i>Scarica la Convenzione (Word)</a>
        </div>
        <p class="small text-secondary">Ti serve l'Allegato A da modificare? <a href="convenzione_online.php?t=<?php echo $h($cc['token']); ?>&amp;scarica=allegato">Scaricalo in Word</a>: ha lo stesso contenuto e lo stesso ordine del PDF.</p>
        <ol class="mb-2">
            <li>Apri i file e controlla i dati (nella Convenzione in Word i dati non ancora indicati restano come puntini da completare a mano).</li>
            <li>Il <strong>Dirigente Scolastico</strong> firma i documenti <strong>digitalmente in formato PAdES</strong> (PDF firmato; il formato CAdES .p7m non è accettato).</li>
            <li>La scuola <strong>carica qui sotto la convenzione firmata e l'Allegato A</strong> (la convenzione in PDF PAdES, l'Allegato A in PDF anche senza firma): l'ufficio riceve subito l'avviso. In alternativa si può inviare dalla PEC della scuola a <a href="mailto:<?php echo $h($cfg['pec']); ?>?subject=<?php echo rawurlencode('Convenzione Formazione Scuola Lavoro – ' . ($s['denominazione'] ?? '')); ?>" class="fw-bold"><?php echo $h($cfg['pec']); ?></a>.</li>
            <li>Il Dipartimento la protocolla in entrata, la fa firmare dal Direttore, la protocolla in via definitiva e <strong>conferma le prenotazioni</strong>: riceverete un'email a ogni passaggio importante.</li>
        </ol>
        <?php if ($non_prenotate): ?>
            <div class="alert alert-warning small mb-0"><i class="fa fa-bell me-1" aria-hidden="true"></i><strong>Ricordati di prenotare</strong> le attività che hai inserito nell'Allegato A ma che non hai ancora prenotato:
                <?php foreach ($non_prenotate as $k): $ev = $prenotabili[(int)substr($k, 2)] ?? null; if (!$ev) continue; ?>
                    <a class="d-block fw-bold" href="<?php echo $h($ev['slug'] . '.php?' . (($ev['tipo'] ?? '') === 'progetto' ? 'progetto' : 'evento') . '=' . (int)$ev['evento_id']); ?>"><?php echo $h($ev['evento_titolo']); ?> → prenota</a>
                <?php endforeach; ?></div>
        <?php endif; ?>
        <p class="small text-secondary mt-2 mb-0">Puoi tornare su questa pagina dal link che ti abbiamo inviato per email e correggere i dati qui sotto: i documenti si aggiornano.</p>
    </div></section>

    <?php if (($dati['convenzione'] ?? '') !== 'si' || !empty($cc['file_firmato'])): // la scuola che ha già una convenzione valida non ne carica un'altra ?>
    <?php $cv_caricabile = \App\Fsl\ServizioConvenzioneFirmata::caricabile($cc); ?>
    <section id="firmata" class="card border-0 shadow-sm mb-4" style="border-left:5px solid #B30000 !important;"><div class="card-body">
        <h2 class="h5 fw-bold"><i class="fa fa-file-circle-check me-1" style="color:#B30000;" aria-hidden="true"></i>Convenzione firmata</h2>
        <?php if ($errore_firmata): ?><div class="alert alert-danger" role="alert"><i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i><?php echo $h($errore_firmata); ?></div><?php endif; ?>
        <?php $it_sc = (string)($cc['stato_iter'] ?? ''); ?>
        <?php if ($it_sc === 'rifiutata'): ?>
            <div class="alert alert-warning mb-3" role="alert"><i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i><strong>Il Dipartimento non ha potuto accettare il file.</strong>
                <?php if (!empty($cc['nota_ufficio'])): ?><div class="mt-1">Motivo: <?php echo nl2br($h($cc['nota_ufficio'])); ?></div><?php endif; ?>
                <div class="mt-1">Correggi il documento e caricalo di nuovo qui sotto.</div></div>
        <?php elseif ($it_sc === 'protocollata'): ?>
            <div class="alert alert-success mb-3" role="status"><i class="fa fa-circle-check me-1" aria-hidden="true"></i><strong>La convenzione è attiva.</strong>
                Firmata dal Direttore e protocollata<?php echo !empty($cc['protocollo']) ? ' (' . $h($cc['protocollo']) . ')' : ''; ?>. La trovi nell'Area personale, scheda «Programma FSL».</div>
        <?php elseif (!empty($cc['file_firmato'])): ?>
            <div class="alert alert-<?php echo $it_sc !== 'caricata_scuola' ? 'primary' : 'success'; ?> mb-3" role="status"><i class="fa fa-<?php echo $it_sc !== 'caricata_scuola' ? 'hand' : 'circle-check'; ?> me-1" aria-hidden="true"></i>
                <strong>Convenzione firmata ricevuta</strong> il <?php echo $h(date('d/m/Y \a\l\l\e H:i', strtotime((string)$cc['firmata_il']))); ?><?php echo !empty($cc['firmatario']) ? ' (firma di ' . $h($cc['firmatario']) . ')' : ''; ?>.
                <?php echo in_array($it_sc, ['in_carico', 'da_firmare', 'firmata_direttore'], true) ? "Il Dipartimento l'ha presa in carico" . (!empty($cc['protocollo_ingresso']) ? ' (protocollo in entrata ' . $h($cc['protocollo_ingresso']) . (!empty($cc['protocollo_ingresso_data']) ? ' del ' . $h(date('d/m/Y', strtotime((string)$cc['protocollo_ingresso_data']))) : '') . ')' : '') . ": la sta facendo firmare dal Direttore e protocollare. Ti scriviamo appena è pronta." : "Il Dipartimento la prenderà in carico a breve."; ?>
                <a class="fw-bold ms-1" href="convenzione_online.php?t=<?php echo $h($cc['token']); ?>&amp;scarica=firmata">Scarica il file caricato</a></div>
        <?php endif; ?>
        <?php if ($cv_caricabile): ?>
            <form method="POST" enctype="multipart/form-data" class="row g-2 align-items-end">
                <?php csrf_field(); ?>
                <div class="col-md-8"><label class="form-label small fw-bold" for="cvFirmata"><?php echo empty($cc['file_firmato']) ? 'Carica la convenzione firmata digitalmente in PAdES' : 'Hai sbagliato file? Carica la versione corretta'; ?> (PDF fino a 10 MB)</label>
                    <input type="file" class="form-control" id="cvFirmata" name="convenzione_firmata" accept=".pdf,application/pdf" required data-pdf-firmato="firmato"><script src="assets/js/controllo-pdf-firmato.js" defer></script>
                    <div class="form-text">Deve essere il PDF firmato dal Dirigente Scolastico in formato <strong>PAdES</strong>, senza modifiche dopo la firma. I file .p7m non sono accettati. Se il file viene rifiutato ti diciamo il perché e cosa fare; <a href="<?php echo $h(\App\Infrastructure\Pdf\MessaggiFirma::GUIDA_SCUOLE); ?>#s4" target="_blank" rel="noopener">la guida spiega la firma passo per passo</a>.</div></div>
                <div class="col-md-8"><label class="form-label small fw-bold" for="cvAllegatoA"><?php echo empty($cc['file_allegato_scuola']) ? 'Carica l\'Allegato A' : 'Allegato A (già caricato: scegli un file solo per sostituirlo)'; ?> (PDF fino a 10 MB)</label>
                    <input type="file" class="form-control" id="cvAllegatoA" name="allegato_a" accept=".pdf,application/pdf" data-pdf-firmato="semplice"<?php echo empty($cc['file_allegato_scuola']) ? ' required' : ''; ?>>
                    <div class="form-text">Puoi usare l'Allegato A in PDF scaricato qui sopra: non serve la firma digitale.</div></div>
                <div class="col-md-4"><button type="submit" name="carica_firmata" value="1" class="btn btn-lg fw-bold text-white w-100" style="background:#B30000;"><i class="fa fa-upload me-1" aria-hidden="true"></i><?php echo empty($cc['file_firmato']) ? 'Carica la convenzione' : 'Sostituisci il file'; ?></button></div>
            </form>
        <?php elseif (empty($cc['file_firmato']) && $it_sc !== 'protocollata'): ?>
            <p class="mb-0 text-secondary">La convenzione di questa scuola è già in lavorazione presso il Dipartimento.</p>
        <?php endif; ?>
    </div></section>
    <?php endif; ?>
    <?php endif; ?>

    <?php $conv_msg = $msg_rep->della((int)$cc['id']); $msg_rep->segnaLetti((int)$cc['id'], 'scuola'); ?>
    <section id="messaggi" class="card border-0 shadow-sm mb-4" style="border-left:5px solid #0056b3 !important;"><div class="card-body">
        <h2 class="h5 fw-bold"><i class="fa fa-comments me-1" style="color:#0056b3;" aria-hidden="true"></i>Messaggi con il Dipartimento</h2>
        <?php if ($errore_msg): ?><div class="alert alert-danger" role="alert"><i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i><?php echo $h($errore_msg); ?></div><?php endif; ?>
        <?php if ($conv_msg): ?><div class="d-flex flex-column gap-2 my-3" style="max-height:320px;overflow:auto;">
            <?php foreach ($conv_msg as $m): $mia = $m['da'] === 'scuola'; ?>
                <div class="rounded-3 p-2 px-3 small" style="max-width:90%;<?php echo $mia ? 'align-self:flex-end;background:#eff6ff;' : 'align-self:flex-start;background:#fef2f2;'; ?>">
                    <div class="text-secondary"><?php echo $mia ? 'Tu' : 'Dipartimento'; ?> · <?php echo $h(date('d/m/Y H:i', strtotime((string)$m['creato_il']))); ?></div>
                    <div><?php echo nl2br($h($m['testo'])); ?></div></div>
            <?php endforeach; ?></div>
        <?php else: ?><p class="small text-secondary">Qui trovi i messaggi dell'ufficio e puoi scrivergli per dubbi o problemi con la convenzione.</p><?php endif; ?>
        <form method="POST" class="d-flex flex-column gap-2"><?php csrf_field(); ?>
            <label class="form-label small fw-bold mb-0" for="cvTesto">Scrivi all'ufficio</label>
            <textarea class="form-control" id="cvTesto" name="testo" rows="3" maxlength="1500" required placeholder="Es. Il Dirigente ha firmato ma il file non viene accettato…"></textarea>
            <div><button type="submit" name="scrivi_ufficio" value="1" class="btn fw-bold text-white" style="background:#0056b3;"><i class="fa fa-paper-plane me-1" aria-hidden="true"></i>Manda il messaggio</button></div>
        </form>
    </div></section>

    <form method="POST" enctype="multipart/form-data">
        <?php csrf_field(); ?>
        <section class="card border-0 shadow-sm mb-3"><div class="card-body">
            <h2 class="h5 fw-bold"><span class="cv-passo">1</span>Istituzione scolastica</h2>
            <div class="row g-2">
                <div class="col-md-8"><label class="form-label small fw-bold" for="cvDen">Denominazione <span class="text-danger">*</span></label><input class="form-control" id="cvDen" name="denominazione" value="<?php echo $h($s['denominazione']); ?>" required maxlength="255"></div>
                <div class="col-md-4"><label class="form-label small fw-bold" for="cvCod">Codice meccanografico</label><input class="form-control" id="cvCod" name="codice" value="<?php echo $h($s['codice']); ?>" maxlength="20"></div>
                <div class="col-md-4"><label class="form-label small fw-bold" for="cvCom">Comune (provincia)</label><input class="form-control" id="cvCom" name="comune" value="<?php echo $h($s['comune']); ?>" maxlength="255"></div>
                <div class="col-md-5"><label class="form-label small fw-bold" for="cvInd">Indirizzo</label><input class="form-control" id="cvInd" name="indirizzo" value="<?php echo $h($s['indirizzo']); ?>" maxlength="255"></div>
                <div class="col-md-3"><label class="form-label small fw-bold" for="cvCf">Codice fiscale dell'istituto</label><input class="form-control font-monospace" id="cvCf" name="cf" value="<?php echo $h($s['cf']); ?>" maxlength="16" placeholder="11 cifre"></div>
                <div class="col-md-6"><label class="form-label small fw-bold" for="cvPec">PEC della scuola</label><input type="email" class="form-control" id="cvPec" name="pec" value="<?php echo $h($s['pec']); ?>" maxlength="255"></div>
                <div class="col-md-6"><label class="form-label small fw-bold" for="cvEm">Email a cui mandare il link</label><input type="email" class="form-control" id="cvEm" name="email" value="<?php echo $h($s['email']); ?>" maxlength="255"></div>
                <div class="col-12"><label class="form-label small fw-bold" for="cvLogo">Logo della scuola (facoltativo, PNG o JPG fino a 2 MB)</label>
                    <input type="file" class="form-control" id="cvLogo" name="logo" accept=".png,.jpg,.jpeg">
                    <?php if ($cc['logo']): ?><label class="form-check small mt-1"><input class="form-check-input" type="checkbox" name="togli_logo" value="1"> Il logo è già caricato: spunta per toglierlo</label><?php endif; ?>
                    <div class="form-text">Compare in testa alla Convenzione e all'Allegato A.</div></div>
            </div>
        </div></section>

        <section class="card border-0 shadow-sm mb-3"><div class="card-body">
            <h2 class="h5 fw-bold"><span class="cv-passo">2</span>Dirigente Scolastico</h2>
            <div class="row g-2">
                <div class="col-md-6"><label class="form-label small fw-bold" for="cvDir">Nome e cognome</label><input class="form-control" id="cvDir" name="dirigente" value="<?php echo $h($s['dirigente']); ?>" maxlength="255" placeholder="Dott.ssa Maria Rossi"></div>
                <div class="col-md-6"><label class="form-label small fw-bold" for="cvDcf">Codice fiscale</label><input class="form-control font-monospace" id="cvDcf" name="dir_cf" value="<?php echo $h($s['dir_cf']); ?>" maxlength="16" style="text-transform:uppercase;"></div>
                <div class="col-md-6"><label class="form-label small fw-bold" for="cvLn">Luogo di nascita</label><input class="form-control" id="cvLn" name="luogo_nascita" value="<?php echo $h($s['luogo_nascita']); ?>" maxlength="255"></div>
                <div class="col-md-6"><label class="form-label small fw-bold" for="cvDn">Data di nascita</label><input type="date" class="form-control" id="cvDn" name="data_nascita" value="<?php echo $h($s['data_nascita']); ?>"></div>
            </div>
            <p class="small text-secondary mb-0 mt-2">I campi lasciati vuoti restano evidenziati nel documento: potete completarli a mano prima della firma.</p>
        </div></section>

        <section class="card border-0 shadow-sm mb-3"><div class="card-body">
            <h2 class="h5 fw-bold"><span class="cv-passo">3</span>Attività dell'Allegato A</h2>
            <p class="small text-secondary">Scegli le attività da inserire nell'Allegato A. Per ognuna puoi indicare il numero di studenti e il docente tutor della scuola.</p>
            <h3 class="h6 fw-bold mt-2">Attività già prenotate dalla scuola</h3>
            <?php foreach ($prenotate as $id => $p): $k = 'pr' . $id; $v = dati_convenzione_precompilata($conn, $p); $sel = isset($scelte[$k]); ?>
                <div class="cv-att<?php echo $sel ? ' scelta' : ''; ?>">
                    <label class="form-check fw-bold mb-1"><input class="form-check-input cv-sc" type="checkbox" name="att[<?php echo $k; ?>][scelta]" value="1"<?php echo $sel ? ' checked' : ''; ?>> <?php echo $h($v['TITOLO']); ?></label>
                    <div class="small text-secondary mb-1"><?php echo $h($v['PERIODO']); ?><?php echo $v['DURATA'] ? ' · ' . $h($v['DURATA']) : ''; ?> · prenotazione <?php echo $h($p['codice_prenotazione']); ?></div>
                    <div class="row g-2"><div class="col-sm-4"><input type="number" min="0" max="500" class="form-control form-control-sm" name="att[<?php echo $k; ?>][studenti]" value="<?php echo $h(($scelte[$k]['studenti'] ?? 0) ?: $v['STUDENTI']); ?>" placeholder="N. studenti" aria-label="Numero di studenti"></div>
                        <div class="col-sm-8"><input class="form-control form-control-sm" name="att[<?php echo $k; ?>][tutor]" value="<?php echo $h(($scelte[$k]['tutor'] ?? '') ?: $v['TUTOR_SCUOLA']); ?>" placeholder="Docente tutor della scuola" aria-label="Docente tutor della scuola" maxlength="150"></div></div>
                </div>
            <?php endforeach; ?>
            <?php if ($prenotabili): ?>
                <details class="mt-3"<?php echo $non_prenotate ? ' open' : ''; ?>><summary class="fw-bold">Altre attività di Formazione Scuola Lavoro da prenotare (<?php echo count($prenotabili); ?>)</summary>
                    <p class="small text-secondary mt-2">Se le inserisci nell'Allegato A, <strong>ricordati di prenotarle</strong>: la convenzione non sostituisce la prenotazione.</p>
                    <?php foreach ($prenotabili as $id => $ev): $k = 'ev' . $id; $v = dati_convenzione_precompilata($conn, $ev); $sel = isset($scelte[$k]); ?>
                        <div class="cv-att<?php echo $sel ? ' scelta' : ''; ?>">
                            <label class="form-check fw-bold mb-1"><input class="form-check-input cv-sc" type="checkbox" name="att[<?php echo $k; ?>][scelta]" value="1"<?php echo $sel ? ' checked' : ''; ?>> <?php echo $h($v['TITOLO']); ?></label>
                            <div class="small text-secondary mb-1"><?php echo $h($v['PERIODO']); ?><?php echo $v['DURATA'] ? ' · ' . $h($v['DURATA']) : ''; ?> · <a href="<?php echo $h($ev['slug'] . '.php?' . (($ev['tipo'] ?? '') === 'progetto' ? 'progetto' : 'evento') . '=' . $id); ?>" target="_blank" rel="noopener">vedi e prenota<span class="visually-hidden"> (si apre in una nuova scheda)</span></a></div>
                            <div class="cv-promemoria alert alert-warning small py-1 px-2 mb-1"<?php echo $sel ? '' : ' hidden'; ?>><i class="fa fa-bell me-1" aria-hidden="true"></i>Non l'hai ancora prenotata: ricordati di farlo.</div>
                            <div class="row g-2"><div class="col-sm-4"><input type="number" min="0" max="500" class="form-control form-control-sm" name="att[<?php echo $k; ?>][studenti]" value="<?php echo $h($scelte[$k]['studenti'] ?? ''); ?>" placeholder="N. studenti" aria-label="Numero di studenti"></div>
                                <div class="col-sm-8"><input class="form-control form-control-sm" name="att[<?php echo $k; ?>][tutor]" value="<?php echo $h($scelte[$k]['tutor'] ?? ''); ?>" placeholder="Docente tutor della scuola" aria-label="Docente tutor della scuola" maxlength="150"></div></div>
                        </div>
                    <?php endforeach; ?>
                </details>
            <?php endif; ?>
        </div></section>

        <button type="submit" name="salva_convenzione" value="1" class="btn btn-lg fw-bold text-white mb-2" style="background:#B30000;"><i class="fa fa-wand-magic-sparkles me-1" aria-hidden="true"></i><?php echo $dati ? 'Aggiorna i documenti' : 'Prepara i documenti'; ?></button>
        <p class="small text-secondary">Preferisci i modelli vuoti? <a href="<?php echo $h($cfg['modello']); ?>">Convenzione</a> · <a href="<?php echo $h($cfg['allegato']); ?>">Allegato A</a></p>
    </form>
</div>
<script>
document.addEventListener('change', function (e) {
    if (!e.target.classList.contains('cv-sc')) return;
    var box = e.target.closest('.cv-att'); box.classList.toggle('scelta', e.target.checked);
    var pm = box.querySelector('.cv-promemoria'); if (pm) pm.hidden = !e.target.checked;
});
</script>
<?php require_once 'footer.php'; ?>
