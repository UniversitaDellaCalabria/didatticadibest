<?php
// convenzione_tesi.php - Convenzione per lo svolgimento della tesi presso un ente o un'azienda, compilata online dall'azienda con il link personale (?t=…),
// senza registrazione: dati dell'ente e del rappresentante, eventuali modifiche agli articoli (che il Dipartimento valuta prima della firma),
// download della convenzione compilata (Word e PDF), firma digitale PAdES e caricamento del PDF firmato. Logica in src/ConvenzioniTesi/.
define('PAGINA_SENZA_SSO', true); // pagina per chi non ha le credenziali di Ateneo: niente accesso SSO (vedi verbali.php)
require_once 'config.php';
require_once 'functions.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$nega = function (string $msg) use ($h) {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(404);
    exit('<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Convenzione non disponibile</title></head>'
       . '<body style="font-family:system-ui,sans-serif;max-width:640px;margin:3rem auto;padding:0 1rem;"><h1>Convenzione non disponibile</h1><p>' . $h($msg) . '</p><p><a href="richiedi_convenzione_tesi.php">Richiedi la convenzione</a></p></body></html>');
};
if (!check_rate_limit($conn, 'convenzione_tesi', 120, 3600)) $nega("Troppe richieste: riprova tra poco.");

$svc = \App\Core\App::per($conn)->get(\App\ConvenzioniTesi\ServizioConvenzioniTesi::class);
$tok = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ''));
$c = $svc->perToken($tok);
if (!$c) $nega("Il link non è valido o è scaduto.");
$id = (int)$c['id'];
$S = \App\ConvenzioniTesi\Costanti::class;

// ── Download: Word e PDF compilati, convenzione firmata caricata, convenzione stipulata ──
if (isset($_GET['scarica'])) {
    $q = (string)$_GET['scarica'];
    $nome_az = trim((string)preg_replace('/[^A-Za-z0-9]+/', '_', (string)$c['azienda']), '_') ?: 'azienda';
    if ($q === 'word' && ($w = $svc->word($c))) { invia_file_scaricabile($w, 'Convenzione_tesi_' . $nome_az . '.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'); }
    if ($q === 'pdf' && ($pdf = $svc->pdf($c))) {
        $tmp = tempnam(sys_get_temp_dir(), 'ct') . '.pdf'; file_put_contents($tmp, $pdf);
        invia_file_scaricabile($tmp, 'Convenzione_tesi_' . $nome_az . '.pdf', 'application/pdf');
    }
    $ff = in_array($q, ['firmata', 'finale'], true) ? $svc->fileDaScaricare($id, $q === 'firmata' ? 'azienda' : 'finale') : null;
    if ($ff && ($q === 'firmata' || $c['stato'] === $S::PROTOCOLLATA)) {
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename="' . $ff['nome'] . '"'); header('Content-Length: ' . filesize($ff['percorso'])); header('X-Content-Type-Options: nosniff');
        readfile($ff['percorso']); exit;
    }
    $nega("Il documento non è disponibile in questo momento.");
}

// ── Azioni ──
$errori = []; $errore_firmata = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    if (isset($_POST['salva_dati'])) {
        $errori = $svc->salvaDati($c, $_POST);
        if (!$errori) {
            $c = $svc->perToken($tok) ?: $c;
            flash_set($c['stato'] === $S::MODIFICHE ? "Dati salvati. Hai proposto modifiche agli articoli: il Dipartimento le valuta e ti scrive. Potrai firmare dopo la loro risposta."
                : ($svc->pronta($c) ? "Dati salvati: scarica la convenzione, firmala digitalmente e ricaricala qui sotto." : "Dati salvati. Controlla le risposte del Dipartimento agli articoli modificati."));
            header('Location: convenzione_tesi.php?t=' . $tok . '&r=' . time() . '#' . ($svc->pronta($c) ? 'documenti' : 'articoli')); exit;
        }
    } elseif (isset($_POST['carica_firmata'])) {
        $errore_firmata = $svc->caricaDaUpload($c, $_FILES['pdf_firmato'] ?? null);
        if ($errore_firmata === null) {
            flash_set("Convenzione firmata ricevuta: il Dipartimento la prende in carico e ti scrive appena è stipulata.");
            header('Location: convenzione_tesi.php?t=' . $tok . '&r=' . time() . '#stato'); exit;
        }
        $c = $svc->perToken($tok) ?: $c;
    } elseif (isset($_POST['rinnova'])) {
        [$err_r, $nuovo_tok] = $svc->rinnova($id);
        if (!$err_r && $nuovo_tok !== '') { flash_set("Rinnovo avviato: controlla i dati, poi scarica e firma la nuova convenzione."); header('Location: convenzione_tesi.php?t=' . urlencode($nuovo_tok)); exit; }
        $errori = [$err_r ?: 'Rinnovo non riuscito.'];
    }
}

$dati = $svc->dati($c);
$mod = $svc->modifiche($c);
$stato = (string)$c['stato'];
$articoli = $svc->articoliStandard();
$modificabile = \App\ConvenzioniTesi\ServizioConvenzioniTesi::modificabile($c);
$pronta = $svc->pronta($c);
$v = fn(string $k) => $h($errori && isset($_POST[$k]) ? $_POST[$k] : ($dati[$k] ?? ''));
$passo = match ($stato) { $S::INVITATA => 1, $S::COMPILATA, $S::MODIFICHE, $S::RISPOSTA, $S::RIFIUTATA => ($pronta || $stato === $S::RIFIUTATA ? 2 : 1), $S::CARICATA, $S::IN_CARICO, $S::DA_FIRMARE, $S::FIRMATA => 3, default => 4 };
$scade_presto = $stato === $S::PROTOCOLLATA && !empty($c['valida_al']) && $c['valida_al'] <= date('Y-m-d', strtotime('+' . \App\ConvenzioniTesi\ServizioConvenzioniTesi::GIORNI_AVVISO_SCADENZA . ' days'));

$page_cfg['titolo'] = 'Convenzione per la tesi';
require_once 'header.php';
?>
<style>
.ct-passi { display:grid; grid-template-columns:repeat(4, 1fr); gap:8px; list-style:none; padding:0; margin:0 0 1.25rem; counter-reset:p; }
.ct-passi li { counter-increment:p; border:1px solid #e2e8f0; border-top:4px solid #cbd5e1; border-radius:10px; padding:8px 10px; font-size:.85rem; background:#fff; }
.ct-passi li::before { content:counter(p); display:inline-grid; place-items:center; width:22px; height:22px; border-radius:50%; background:#cbd5e1; color:#fff; font-weight:800; font-size:.75rem; margin-right:6px; }
.ct-passi li.fatto { border-top-color:#16a34a; } .ct-passi li.fatto::before { background:#16a34a; }
.ct-passi li.ora { border-top-color:#B30000; font-weight:700; } .ct-passi li.ora::before { background:#B30000; }
.ct-art { border:1px solid #e2e8f0; border-radius:10px; margin-bottom:.5rem; background:#fff; }
.ct-art > summary { cursor:pointer; padding:.6rem .9rem; font-weight:600; }
.ct-art .corpo { padding:0 .9rem .9rem; font-size:.9rem; }
.ct-art .corpo p { margin:0 0 .5rem; }
.ct-art.mod { border-color:#f59e0b; background:#fffbeb; }
@media (max-width: 767px) { .ct-passi { grid-template-columns:1fr 1fr; } }
</style>
<div class="container my-4" style="max-width: 960px;">
    <h1 class="fw-bold h2 mb-1"><i class="fa fa-file-signature me-2" style="color:#B30000;" aria-hidden="true"></i>Convenzione per la tesi</h1>
    <p class="text-secondary">Accordo tra il <strong>Dipartimento di Biologia, Ecologia e Scienze della Terra</strong> dell'Università della Calabria e <strong><?php echo $h($c['azienda'] ?: $c['email']); ?></strong> per accogliere studenti che preparano la tesi.
        Si fa tutto da questa pagina, senza registrarsi: puoi chiuderla e riprendere dal link ricevuto per email.</p>
    <ol class="ct-passi" aria-label="Avanzamento">
        <?php foreach ([1 => 'Dati dell\'azienda', 2 => 'Scarica, firma e ricarica', 3 => 'Il Dipartimento la protocolla e la firma', 4 => 'Convenzione stipulata'] as $n => $t): ?>
            <li class="<?php echo $n < $passo ? 'fatto' : ($n === $passo ? 'ora' : ''); ?>"><?php echo $h($t); ?></li>
        <?php endforeach; ?>
    </ol>
    <?php echo flash_html(); ?>
    <?php if ($errori): ?><div class="alert alert-danger" role="alert"><strong>Controlla i dati:</strong><ul class="mb-0"><?php foreach ($errori as $e): ?><li><?php echo $h($e); ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <?php if ($stato === $S::RIFIUTATA): ?>
        <div class="alert alert-warning" role="alert"><i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i><strong>Il file che hai caricato non è stato accettato:</strong> <?php echo $h($c['nota_ufficio']); ?>
            <div class="small mt-1">Correggi il documento e caricalo di nuovo nella sezione «Firma e ricarica».</div></div>
    <?php endif; ?>

    <?php if ($stato === $S::PROTOCOLLATA): ?>
    <section id="stato" class="card border-0 shadow-sm mb-4" style="border-left:5px solid #16a34a !important;"><div class="card-body">
        <h2 class="h5 fw-bold"><i class="fa fa-circle-check text-success me-1" aria-hidden="true"></i>Convenzione stipulata</h2>
        <p class="mb-2">Protocollo <strong><?php echo $h($c['protocollo']); ?></strong><?php echo $c['protocollo_data'] ? ' del ' . $h(date('d/m/Y', strtotime($c['protocollo_data']))) : ''; ?> · valida dal <strong><?php echo $h(date('d/m/Y', strtotime((string)$c['valida_dal']))); ?></strong> al <strong><?php echo $h(date('d/m/Y', strtotime((string)$c['valida_al']))); ?></strong>.</p>
        <?php if ($svc->fileDaScaricare($id, 'finale')): ?><a class="btn btn-success fw-bold" href="convenzione_tesi.php?t=<?php echo $h($tok); ?>&amp;scarica=finale"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>Scarica la convenzione firmata</a><?php endif; ?>
        <?php if ($c['consenso_elenco']): ?><p class="small text-secondary mt-3 mb-0">L'ente compare nell'<a href="aziende_convenzionate_tesi.php">elenco delle aziende convenzionate</a>.</p><?php endif; ?>
        <?php if ($scade_presto): ?>
            <form method="POST" class="mt-3"><?php csrf_field(); ?><div class="alert alert-warning small mb-2">La convenzione scade il <?php echo $h(date('d/m/Y', strtotime((string)$c['valida_al']))); ?>. Per continuare ad accogliere i nostri studenti puoi rinnovarla: i dati sono già inseriti.</div>
                <button type="submit" name="rinnova" value="1" class="btn btn-danger fw-bold"><i class="fa fa-rotate me-1" aria-hidden="true"></i>Rinnova la convenzione</button></form>
        <?php endif; ?>
    </div></section>
    <?php elseif (in_array($stato, [$S::CARICATA, $S::IN_CARICO, $S::DA_FIRMARE, $S::FIRMATA], true)): ?>
    <section id="stato" class="card border-0 shadow-sm mb-4" style="border-left:5px solid #0056b3 !important;"><div class="card-body">
        <h2 class="h5 fw-bold"><i class="fa fa-hourglass-half me-1 text-primary" aria-hidden="true"></i>Il Dipartimento sta completando la convenzione</h2>
        <ul class="mb-2">
            <li>Convenzione firmata ricevuta il <?php echo $h(date('d/m/Y', strtotime((string)$c['firmata_il']))); ?>.</li>
            <?php if ($c['protocollo_ingresso']): ?><li>Presa in carico: protocollo in entrata <strong><?php echo $h($c['protocollo_ingresso']); ?></strong>.</li><?php endif; ?>
            <?php if (in_array($stato, [$S::DA_FIRMARE, $S::IN_CARICO], true)): ?><li>È alla firma del Direttore.</li><?php elseif ($stato === $S::FIRMATA): ?><li>Firmata dal Direttore: manca il protocollo definitivo.</li><?php endif; ?>
        </ul>
        <p class="small text-secondary mb-2">Ti scriviamo appena la convenzione è stipulata: potrai scaricarla da questa pagina.</p>
        <?php if ($svc->fileDaScaricare($id, 'azienda')): ?><a class="btn btn-sm btn-outline-secondary" href="convenzione_tesi.php?t=<?php echo $h($tok); ?>&amp;scarica=firmata"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>Il file che hai caricato</a><?php endif; ?>
    </div></section>
    <?php endif; ?>

    <?php if ($modificabile): ?>
    <form method="POST" id="formDati">
        <?php csrf_field(); ?>
        <section class="card border-0 shadow-sm mb-4"><div class="card-body">
            <h2 class="h5 fw-bold"><span class="badge bg-danger me-1">1</span>Dati dell'ente o dell'azienda</h2>
            <div class="row g-2">
                <div class="col-md-8"><label class="form-label small fw-bold mb-0" for="ragione_sociale">Ragione sociale *</label><input class="form-control" id="ragione_sociale" name="ragione_sociale" value="<?php echo $v('ragione_sociale'); ?>" maxlength="200" required></div>
                <div class="col-md-4"><label class="form-label small fw-bold mb-0" for="forma_giuridica">Forma giuridica</label><input class="form-control" id="forma_giuridica" name="forma_giuridica" value="<?php echo $v('forma_giuridica'); ?>" maxlength="80" placeholder="es. S.r.l., ente pubblico"></div>
                <div class="col-md-6"><label class="form-label small fw-bold mb-0" for="piva">Partita IVA</label><input class="form-control" id="piva" name="piva" value="<?php echo $v('piva'); ?>" maxlength="20" inputmode="numeric"></div>
                <div class="col-md-6"><label class="form-label small fw-bold mb-0" for="cf">Codice fiscale</label><input class="form-control" id="cf" name="cf" value="<?php echo $v('cf'); ?>" maxlength="20" style="text-transform:uppercase;"><div class="form-text">Serve almeno uno tra partita IVA e codice fiscale.</div></div>
                <div class="col-md-5"><label class="form-label small fw-bold mb-0" for="comune">Comune della sede legale *</label><input class="form-control" id="comune" name="comune" value="<?php echo $v('comune'); ?>" maxlength="100" required></div>
                <div class="col-4 col-md-2"><label class="form-label small fw-bold mb-0" for="provincia">Prov.</label><input class="form-control" id="provincia" name="provincia" value="<?php echo $v('provincia'); ?>" maxlength="40" placeholder="CS" style="text-transform:uppercase;"></div>
                <div class="col-8 col-md-5"><label class="form-label small fw-bold mb-0" for="indirizzo">Indirizzo della sede legale *</label><input class="form-control" id="indirizzo" name="indirizzo" value="<?php echo $v('indirizzo'); ?>" maxlength="200" required placeholder="Via, numero"></div>
                <div class="col-md-6"><label class="form-label small fw-bold mb-0" for="pec">PEC *</label><input type="email" class="form-control" id="pec" name="pec" value="<?php echo $v('pec'); ?>" maxlength="150" required></div>
                <div class="col-md-6"><label class="form-label small fw-bold mb-0" for="telefono">Telefono</label><input class="form-control" id="telefono" name="telefono" value="<?php echo $v('telefono'); ?>" maxlength="40"></div>
                <div class="col-md-6"><label class="form-label small fw-bold mb-0" for="settore">Settore di attività</label><input class="form-control" id="settore" name="settore" value="<?php echo $v('settore'); ?>" maxlength="150" placeholder="es. biotecnologie, geologia, ambiente"></div>
                <div class="col-md-6"><label class="form-label small fw-bold mb-0" for="sito">Sito web</label><input class="form-control" id="sito" name="sito" value="<?php echo $v('sito'); ?>" maxlength="200" placeholder="www.esempio.it"></div>
            </div>
            <h3 class="h6 fw-bold mt-4">Chi firma per l'ente (rappresentante legale)</h3>
            <div class="row g-2">
                <div class="col-4 col-md-2"><label class="form-label small fw-bold mb-0" for="rappr_titolo">Titolo</label>
                    <select class="form-select" id="rappr_titolo" name="rappr_titolo"><option value=""></option><?php foreach (\App\ConvenzioniTesi\Costanti::TITOLI as $t): ?><option<?php echo (($errori && isset($_POST['rappr_titolo']) ? $_POST['rappr_titolo'] : ($dati['rappr_titolo'] ?? '')) === $t) ? ' selected' : ''; ?>><?php echo $h($t); ?></option><?php endforeach; ?></select></div>
                <div class="col-8 col-md-5"><label class="form-label small fw-bold mb-0" for="rappr_nome">Nome e cognome *</label><input class="form-control" id="rappr_nome" name="rappr_nome" value="<?php echo $v('rappr_nome'); ?>" maxlength="150" required></div>
                <div class="col-md-5"><label class="form-label small fw-bold mb-0" for="rappr_qualifica">In qualità di *</label><input class="form-control" id="rappr_qualifica" name="rappr_qualifica" value="<?php echo $v('rappr_qualifica'); ?>" maxlength="150" required placeholder="es. Amministratore Unico, Direttore"></div>
            </div>
            <h3 class="h6 fw-bold mt-4">Referente per gli studenti (facoltativo)</h3>
            <div class="row g-2">
                <div class="col-md-4"><label class="form-label small fw-bold mb-0" for="referente_nome">Nome e cognome</label><input class="form-control" id="referente_nome" name="referente_nome" value="<?php echo $v('referente_nome'); ?>" maxlength="150"></div>
                <div class="col-md-4"><label class="form-label small fw-bold mb-0" for="referente_email">Email</label><input type="email" class="form-control" id="referente_email" name="referente_email" value="<?php echo $v('referente_email'); ?>" maxlength="150"></div>
                <div class="col-md-4"><label class="form-label small fw-bold mb-0" for="referente_telefono">Telefono</label><input class="form-control" id="referente_telefono" name="referente_telefono" value="<?php echo $v('referente_telefono'); ?>" maxlength="40"></div>
            </div>
            <div class="form-check mt-3"><input class="form-check-input" type="checkbox" id="consenso_elenco" name="consenso_elenco" value="1"<?php echo ($errori ? !empty($_POST['consenso_elenco']) : (int)$c['consenso_elenco'] === 1) ? ' checked' : ''; ?>>
                <label class="form-check-label" for="consenso_elenco">Acconsento a comparire nell'<strong>elenco pubblico delle aziende convenzionate</strong> del Dipartimento (nome, comune, settore e sito).</label></div>
        </div></section>

        <section id="articoli" class="card border-0 shadow-sm mb-4"><div class="card-body">
            <h2 class="h5 fw-bold"><span class="badge bg-danger me-1">2</span>Articoli della convenzione</h2>
            <p class="small text-secondary">Il testo è quello standard del Dipartimento. <strong>Se vuoi cambiare un articolo</strong> aprilo, scegli «Proponi una modifica» e scrivi il testo che desideri: il Dipartimento lo valuta prima della firma e ti risponde per email. Se non vuoi cambiare nulla, vai avanti.</p>
            <?php foreach ($articoli as $n => $a):
                $m = $mod[$n] ?? null;
                $testo_campo = $errori && isset($_POST['art'][$n]) ? (string)$_POST['art'][$n] : ($m['testo'] ?? $a['testo']);
                $badge = $m ? ['proposta' => ['attesa', 'Modifica proposta: in attesa del Dipartimento'], 'accettata' => ['ok', 'Modifica accettata'], 'rifiutata' => ['azione', 'Modifica rifiutata'], 'controproposta' => ['info', 'Il Dipartimento propone un altro testo']][$m['stato']] ?? null : null; ?>
            <details class="ct-art<?php echo $m ? ' mod' : ''; ?>"<?php echo $m && $m['stato'] !== 'accettata' ? ' open' : ''; ?>>
                <summary>Art. <?php echo (int)$n; ?> – <?php echo $h($a['titolo']); ?><?php echo $badge ? ' ' . ui_stato($badge[0], $badge[1]) : ''; ?></summary>
                <div class="corpo">
                    <?php foreach (explode("\n\n", $a['testo']) as $par): ?><p class="text-secondary"><?php echo $h($par); ?></p><?php endforeach; ?>
                    <?php if ($m && $m['risposta'] !== ''): ?><div class="alert alert-light border small"><strong>Risposta del Dipartimento:</strong> <?php echo nl2br($h($m['risposta'])); ?></div><?php endif; ?>
                    <?php if ($m && $m['stato'] === 'controproposta'): ?>
                        <div class="alert alert-info small"><strong>Testo proposto dal Dipartimento:</strong><br><?php echo nl2br($h($m['testo_ufficio'])); ?>
                            <div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="accetta<?php echo (int)$n; ?>" name="accetta[<?php echo (int)$n; ?>]" value="1"><label class="form-check-label fw-bold" for="accetta<?php echo (int)$n; ?>">Accetto il testo proposto dal Dipartimento</label></div></div>
                    <?php endif; ?>
                    <details class="mt-1"<?php echo $m ? ' open' : ''; ?>><summary class="small fw-bold" style="cursor:pointer;color:#B30000;"><?php echo $m ? 'La tua modifica' : 'Proponi una modifica a questo articolo'; ?></summary>
                        <label class="form-label small mb-0 mt-2" for="art<?php echo (int)$n; ?>">Testo dell'articolo come lo vorresti (separa i paragrafi con una riga vuota)</label>
                        <textarea class="form-control" id="art<?php echo (int)$n; ?>" name="art[<?php echo (int)$n; ?>]" rows="<?php echo min(14, max(4, substr_count($testo_campo, "\n") + 3)); ?>"><?php echo $h($testo_campo); ?></textarea>
                        <?php if ($m && in_array($m['stato'], ['rifiutata', 'proposta', 'controproposta'], true)): ?>
                            <div class="form-check mt-1"><input class="form-check-input" type="checkbox" id="ritira<?php echo (int)$n; ?>" name="ritira[<?php echo (int)$n; ?>]" value="1"><label class="form-check-label small" for="ritira<?php echo (int)$n; ?>">Ritira la mia modifica e torna al testo standard</label></div>
                        <?php endif; ?>
                    </details>
                </div>
            </details>
            <?php endforeach; ?>
            <label class="form-label small fw-bold mt-3 mb-0" for="nota">Note per il Dipartimento (facoltativo)</label>
            <textarea class="form-control" id="nota" name="nota" rows="2" maxlength="1000"><?php echo $h($errori && isset($_POST['nota']) ? $_POST['nota'] : ($c['nota_azienda'] ?? '')); ?></textarea>
        </div></section>
        <div class="d-flex flex-wrap gap-2 mb-4 align-items-center">
            <button type="submit" name="salva_dati" value="1" class="btn btn-danger fw-bold btn-lg"><i class="fa fa-floppy-disk me-1" aria-hidden="true"></i>Salva<?php echo $stato === $S::INVITATA ? ' e continua' : ''; ?></button>
            <span class="small text-secondary">Dopo il salvataggio puoi scaricare la convenzione compilata (se non hai chiesto modifiche, subito; altrimenti dopo la risposta del Dipartimento).</span>
        </div>
    </form>
    <?php endif; ?>

    <?php if ($stato === $S::MODIFICHE): ?>
        <div class="alert alert-info"><i class="fa fa-hourglass-half me-1" aria-hidden="true"></i><strong>Modifiche in valutazione.</strong> Il Dipartimento sta valutando le modifiche proposte: ti scriviamo appena risponde. Fino ad allora non serve fare altro.</div>
    <?php elseif ($stato === $S::RISPOSTA): ?>
        <div class="alert alert-warning"><i class="fa fa-comments me-1" aria-hidden="true"></i><strong>Il Dipartimento ha risposto.</strong> Leggi le risposte agli articoli qui sopra: accetta il testo proposto, ritira la modifica o scrivine una nuova, poi salva.</div>
    <?php endif; ?>

    <?php if ($pronta): ?>
    <section id="documenti" class="card border-0 shadow-sm mb-4" style="border-left:5px solid #16a34a !important;"><div class="card-body">
        <h2 class="h5 fw-bold"><span class="badge bg-danger me-1">3</span>Scarica la convenzione e firmala</h2>
        <div class="d-flex flex-wrap gap-2 my-3">
            <a class="btn btn-success fw-bold" href="convenzione_tesi.php?t=<?php echo $h($tok); ?>&amp;scarica=pdf"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>Scarica il PDF da firmare</a>
            <a class="btn btn-outline-success" href="convenzione_tesi.php?t=<?php echo $h($tok); ?>&amp;scarica=word"><i class="fa fa-file-word me-1" aria-hidden="true"></i>Scarica anche il Word</a>
        </div>
        <ol class="mb-0">
            <li>Controlla i dati nel PDF. Se qualcosa non va, correggilo qui sopra e riscarica.</li>
            <li>Il <strong>legale rappresentante</strong> firma il <strong>PDF</strong> con la <strong>firma digitale in formato PAdES</strong> (con il tuo software di firma: è il formato «PDF firmato», non il file .p7m).</li>
            <li>Carica qui sotto il PDF firmato: <strong>non modificarlo né salvarlo di nuovo</strong> dopo la firma.</li>
        </ol>
    </div></section>

    <section id="firmata" class="card border-0 shadow-sm mb-4" style="border-left:5px solid #B30000 !important;"><div class="card-body">
        <h2 class="h5 fw-bold"><span class="badge bg-danger me-1">4</span>Firma e ricarica</h2>
        <?php if ($errore_firmata): ?><div class="alert alert-danger" role="alert"><i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i><?php echo $h($errore_firmata); ?></div><?php endif; ?>
        <?php if (!empty($c['file_firmato']) && $stato === $S::CARICATA): ?><div class="alert alert-success small"><i class="fa fa-circle-check me-1" aria-hidden="true"></i>Hai già caricato il PDF firmato (<?php echo $h($c['firmatario'] ?: 'firmatario non indicato'); ?>, <?php echo $h(date('d/m/Y H:i', strtotime((string)$c['firmata_il']))); ?>). Puoi sostituirlo fino alla presa in carico.</div><?php endif; ?>
        <form method="POST" enctype="multipart/form-data" class="d-flex flex-wrap gap-2 align-items-end"><?php csrf_field(); ?>
            <div class="flex-grow-1"><label class="form-label small fw-bold mb-0" for="pdf_firmato">PDF firmato digitalmente (PAdES)</label><input type="file" class="form-control" id="pdf_firmato" name="pdf_firmato" accept=".pdf,application/pdf" required data-pdf-firmato="firmato"><script src="assets/js/controllo-pdf-firmato.js" defer></script></div>
            <button type="submit" name="carica_firmata" value="1" class="btn btn-danger fw-bold"><i class="fa fa-upload me-1" aria-hidden="true"></i>Carica il PDF firmato</button>
        </form>
    </div></section>
    <?php endif; ?>

    <p class="small text-secondary">Hai bisogno di aiuto? Scrivi al Dipartimento (PEC): <a href="mailto:<?php echo $h(\App\Fsl\Costanti::PEC); ?>"><?php echo $h(\App\Fsl\Costanti::PEC); ?></a>.
        I dati inseriti sono usati solo per la convenzione (<a href="privacy.php">informativa</a>).</p>
</div>
<?php require_once 'footer.php'; ?>
