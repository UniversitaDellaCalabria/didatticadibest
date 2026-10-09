<?php
// programma_fsl.php - Il programma FSL della scuola: le attività di Formazione Scuola Lavoro scelte (da prenotare insieme),
// i dati di chi prenota e della scuola, la risposta sulla convenzione. Alla conferma il portale prenota tutte le attività e prepara
// l'Allegato A (PDF, con la scheda di ogni attività) e la Convenzione (Word) precompilati, da firmare in PAdES e inviare via PEC.
// Il programma non riserva posti: i posti si assegnano alla conferma (le attività piene vanno in lista d'attesa, dove prevista).
require_once 'config.php';
require_once 'functions.php';

sync_sso_user($conn);

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$programma = \App\Core\App::get(\App\Fsl\ServizioProgrammaFsl::class);
$utente_logged = !empty($_SESSION['utente_id']);

// Dati di chi è connesso (nome, cognome, email non modificabili)
$val_nome = $val_cognome = $val_email = '';
$scuola_predefinita = null;
if ($utente_logged) {
    $u = \App\Core\App::get(\App\Auth\UtenteRepository::class)->perId((int)$_SESSION['utente_id']);
    if ($u) {
        $val_nome = (string)$u['nome']; $val_cognome = (string)$u['cognome']; $val_email = (string)$u['email'];
        $scuola_predefinita = scuola_per_codice($conn, $u['scuola_codice'] ?? '');
    }
}

// ── Azioni ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    if (isset($_POST['togli'])) {
        $programma->togli((int)$_POST['togli']);
        flash_set('Attività tolta dal programma.');
        header('Location: programma_fsl.php'); exit;
    }
    if (isset($_POST['svuota'])) {
        $programma->svuota();
        flash_set('Il programma è vuoto.');
        header('Location: programma_fsl.php'); exit;
    }
    if (isset($_POST['conferma_programma'])) {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $base_dir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
        $esito = $programma->conferma(new \App\Fsl\RichiestaProgramma(
            $utente_logged ? $val_nome : trim((string)($_POST['nome'] ?? '')), $utente_logged ? $val_cognome : trim((string)($_POST['cognome'] ?? '')),
            $utente_logged ? $val_email : trim((string)($_POST['email'] ?? '')), $utente_logged ? (int)$_SESSION['utente_id'] : null,
            (int)($_SESSION['utente_ruolo_id'] ?? 5), isset($_SESSION['utente_ruoli_secondari']) ? explode(',', $_SESSION['utente_ruoli_secondari']) : [],
            $_POST, $_FILES['logo'] ?? null, (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), $proto . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base_dir, true
        ));
        if ($esito->errori) {
            $_SESSION['programma_errori'] = $esito->errori;
            // Quanto già scritto resta nel modulo (tranne password… che qui non ci sono, e il logo)
            $_SESSION['programma_modulo'] = array_diff_key($_POST, ['csrf_token' => 1, 'captcha_risposta' => 1, 'captcha_id' => 1]);
            header('Location: programma_fsl.php#conferma'); exit;
        }
        $_SESSION['programma_esito'] = ['voci' => $esito->voci, 'token' => $esito->token, 'convenzione' => $esito->convenzione];
        unset($_SESSION['programma_modulo']);
        header('Location: programma_fsl.php?esito=1'); exit;
    }
}

$page_cfg['titolo'] = 'Il programma FSL';

// ── Esito della conferma ──
if (isset($_GET['esito']) && !empty($_SESSION['programma_esito'])) {
    $es = $_SESSION['programma_esito'];
    $cc = null; $pec = '';
    if (!empty($es['token'])) {
        $conv_online = \App\Core\App::per($conn)->get(\App\Fsl\ServizioConvenzioneOnline::class);
        $cc = $conv_online->perToken((string)$es['token']);
        $ctx = $cc ? $conv_online->contesto($cc) : null;
        $pec = $ctx['cfg']['pec'] ?? '';
    }
    $rimaste = $programma->elenco();
    require_once 'header.php';
    $etichette = [
        'confermata' => ['Confermata', 'success', 'fa-check-circle'], 'da_approvare' => ['In attesa di approvazione', 'info', 'fa-hourglass-half'],
        'attesa' => ["Lista d'attesa", 'warning', 'fa-clock'], 'convenzione' => ['In attesa della convenzione', 'info', 'fa-file-signature'],
        'errore' => ['Non prenotata', 'danger', 'fa-circle-exclamation'],
    ];
    $n_ok = count(array_filter($es['voci'], fn($v) => $v['esito'] !== 'errore'));
    $n_attesa = count(array_filter($es['voci'], fn($v) => $v['esito'] === 'attesa'));
    $n_err = count(array_filter($es['voci'], fn($v) => $v['esito'] === 'errore'));
    ?>
    <div class="container my-4" style="max-width: 960px;">
        <h1 class="fw-bold h2 mb-1"><i class="fa fa-clipboard-list me-2" style="color:#B30000;" aria-hidden="true"></i>Programma FSL: <?php echo $n_ok > 0 ? 'richiesta registrata' : 'nessuna attività prenotata'; ?></h1>
        <?php if ($n_ok > 0): ?><p class="text-secondary">Ti abbiamo mandato per email il riepilogo con il link ai documenti.</p><?php endif; ?>

        <div class="card border-0 shadow-sm mb-3"><div class="card-body">
            <h2 class="h5 fw-bold">Attività</h2>
            <ul class="list-group list-group-flush">
            <?php foreach ($es['voci'] as $v): [$et, $col, $ico] = $etichette[$v['esito']] ?? [$v['esito'], 'secondary', 'fa-circle']; ?>
                <li class="list-group-item px-0 d-flex flex-wrap justify-content-between gap-2">
                    <div><strong><?php echo $h($v['titolo']); ?></strong><?php echo $v['turno'] !== '' ? '<div class="small text-secondary">' . $h($v['turno']) . '</div>' : ''; ?>
                        <?php if (!empty($v['messaggio'])): ?><div class="small text-danger"><?php echo $h($v['messaggio']); ?></div><?php endif; ?></div>
                    <div class="text-end"><span class="badge bg-<?php echo $col; ?><?php echo $col === 'warning' ? ' text-dark' : ''; ?>"><i class="fa <?php echo $ico; ?> me-1" aria-hidden="true"></i><?php echo $h($et); ?></span>
                        <?php if (!empty($v['codice'])): ?><div class="small font-monospace mt-1"><?php echo $h($v['codice']); ?></div><?php endif; ?></div>
                </li>
            <?php endforeach; ?>
            </ul>
        </div></div>

        <?php if ($n_attesa > 0): ?>
            <div class="alert alert-warning" role="alert"><i class="fa fa-clock me-1" aria-hidden="true"></i><strong>Lista d'attesa.</strong> Per <?php echo $n_attesa === 1 ? "un'attività" : "$n_attesa attività"; ?> i posti erano esauriti: la richiesta è in lista d'attesa, in ordine di arrivo. Se un posto si libera ti scriviamo per confermarlo.</div>
        <?php endif; ?>
        <?php if ($n_err > 0 && $rimaste): ?>
            <div class="alert alert-danger" role="alert"><i class="fa fa-circle-exclamation me-1" aria-hidden="true"></i><strong>Non tutte le attività sono state prenotate.</strong> Quelle con il motivo indicato restano nel tuo programma: puoi toglierle o riprovare. <a class="fw-bold" href="programma_fsl.php">Torna al programma</a>.</div>
        <?php endif; ?>

        <?php if ($cc): $tk = $h($cc['token']); ?>
        <div class="card border-0 shadow-sm mb-3" style="border-left:5px solid #16a34a !important;"><div class="card-body">
            <h2 class="h5 fw-bold"><i class="fa fa-file-signature me-1 text-success" aria-hidden="true"></i>Documenti da firmare</h2>
            <p class="small text-secondary">Sono già compilati con i dati della scuola e le schede delle attività prenotate.</p>
            <div class="d-flex flex-wrap gap-2 my-3">
                <a class="btn btn-success fw-bold" href="convenzione_online.php?t=<?php echo $tk; ?>&amp;scarica=allegato_pdf"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>Scarica l'Allegato A (PDF)</a>
                <?php if ($es['convenzione'] !== 'si'): ?>
                    <a class="btn btn-success fw-bold" href="convenzione_online.php?t=<?php echo $tk; ?>&amp;scarica=convenzione_sola"><i class="fa fa-file-word me-1" aria-hidden="true"></i>Scarica la Convenzione (Word)</a>
                <?php endif; ?>
                <a class="btn btn-outline-secondary fw-bold" href="convenzione_online.php?t=<?php echo $tk; ?>"><i class="fa fa-pen me-1" aria-hidden="true"></i>Controlla o correggi i dati</a>
            </div>
            <ol class="mb-2">
                <?php if ($es['convenzione'] === 'rinnovo'): ?><li>La convenzione registrata dal Dipartimento <strong>non copre tutto il periodo</strong> delle attività: ne va stipulata una nuova (scarica anche la Convenzione).</li>
                <?php elseif ($es['convenzione'] === 'si'): ?><li>La scuola ha già la convenzione con il Dipartimento: serve solo l'<strong>Allegato A</strong>.
                    Se ti serve anche la Convenzione <a href="convenzione_online.php?t=<?php echo $tk; ?>&amp;scarica=convenzione_sola">scaricala qui</a>.</li><?php endif; ?>
                <li>Apri i file e controlla i dati (nella Convenzione in Word i dati non ancora indicati restano come puntini da completare a mano).</li>
                <li>Il <strong>Dirigente Scolastico</strong> firma <strong>digitalmente in formato PAdES</strong> (PDF firmato; il formato CAdES .p7m non è accettato). Il Word della Convenzione va prima salvato in PDF.</li>
                <li>La scuola invia i documenti firmati dalla propria PEC a <?php echo $pec !== '' ? '<a href="mailto:' . $h($pec) . '" class="fw-bold">' . $h($pec) . '</a>' : 'alla PEC del Dipartimento'; ?>.</li>
                <li>Il Dipartimento registra la convenzione e <strong>conferma le prenotazioni</strong>: riceverete un'email.</li>
            </ol>
        </div></div>
        <?php endif; ?>
        <div class="d-flex flex-wrap gap-2 mb-5">
            <a href="index.php" class="btn btn-outline-secondary fw-bold">Torna al portale</a>
            <a href="area_personale.php#programma-fsl" class="btn btn-outline-secondary fw-bold"><i class="fa fa-id-card me-1" aria-hidden="true"></i>Il mio programma FSL (Area personale)</a>
        </div>
    </div>
    <?php
    unset($_SESSION['programma_esito']);
    require_once 'footer.php';
    exit;
}

// ── Il programma e il modulo di conferma ──
$voci = $programma->elenco();
$errori = $_SESSION['programma_errori'] ?? [];
$mod = $_SESSION['programma_modulo'] ?? [];
unset($_SESSION['programma_errori'], $_SESSION['programma_modulo']);
$val = fn(string $k, string $def = '') => $h($mod[$k] ?? $def);
$dal_min = $al_max = '';
foreach ($voci as $v) {
    if ($v['dal'] !== '' && ($dal_min === '' || $v['dal'] < $dal_min)) $dal_min = $v['dal'];
    if ($v['al'] !== '' && ($al_max === '' || $v['al'] > $al_max)) $al_max = $v['al'];
}
$n_attesa = count(array_filter($voci, fn($v) => $v['stato'] === 'attesa'));
$n_bloccate = count(array_filter($voci, fn($v) => in_array($v['stato'], ['conclusa', 'chiusa', 'piena', 'assente'], true)));
$n_prenotabili = count($voci) - $n_bloccate;

require_once 'header.php';
?>
<div class="container my-4" style="max-width: 960px;">
    <h1 class="fw-bold h2 mb-1"><i class="fa fa-clipboard-list me-2" style="color:#B30000;" aria-hidden="true"></i>Il programma FSL della scuola</h1>
    <p class="text-secondary">Le attività di Formazione Scuola Lavoro che hai scelto. Controllale, indica i dati della scuola e conferma: <strong>le prenotiamo tutte insieme</strong> e prepariamo l'<strong>Allegato A</strong> e la <strong>Convenzione</strong> già compilati.</p>
    <?php echo flash_html(); ?>
    <?php if ($errori): ?><div class="alert alert-danger" role="alert" id="errori"><strong>Controlla i dati:</strong><ul class="mb-0"><?php foreach ($errori as $e): ?><li><?php echo $h($e); ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <?php if (!$voci): ?>
        <div class="card border-0 shadow-sm"><div class="card-body text-center py-5">
            <i class="fa fa-clipboard-list fs-1 text-secondary mb-3" aria-hidden="true"></i>
            <p class="fw-bold mb-1">Il programma è vuoto.</p>
            <p class="text-secondary">Scegli le attività dalle aree del portale e usa «Aggiungi al programma».</p>
            <a href="index.php" class="btn btn-primary fw-bold"><i class="fa fa-magnifying-glass me-1" aria-hidden="true"></i>Scegli le attività</a>
        </div></div>
    <?php else: ?>
<style>
/* Moduli del programma: campi con bordo ben visibile (lo stile base li mostra solo con una linea in basso) */
#formProgramma .form-control, #formProgramma .form-select { border: 1px solid #6c757d; border-radius: 6px; padding: .5rem .75rem; background: #fff; }
#formProgramma .form-control:focus { border-color: #0d6efd; box-shadow: 0 0 0 .2rem rgba(13,110,253,.2); }
#formProgramma .form-control[readonly] { background: #f1f3f5; }
#formProgramma .form-label { font-weight: 700; font-size: .85rem; margin-bottom: .25rem; }
.pg-docente { border: 2px solid #0d6efd; border-left-width: 6px; background: #f3f8ff; border-radius: 8px; padding: .8rem 1rem; }
.pg-docente-tit { display: block; font-weight: 700; color: #0a4aa8; margin-bottom: .4rem; font-size: 1rem; }
.pg-gruppo { border: 1px solid #ced4da; border-radius: 8px; padding: 1rem; margin-bottom: 1rem; background: #fafbfc; }
.pg-gruppo-tit { font-size: 1rem; font-weight: 800; color: #1f2937; margin: 0 0 .75rem; padding-bottom: .4rem; border-bottom: 2px solid #B30000; }
.pg-logo { display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; border: 2px dashed #6c757d; border-radius: 8px; padding: .9rem 1rem; background: #fff; cursor: pointer; margin: 0; }
.pg-logo:hover, .pg-file:focus + .pg-logo { border-color: #0d6efd; background: #f3f8ff; }
.pg-logo-btn { background: #0d6efd; color: #fff; font-weight: 700; padding: .5rem 1rem; border-radius: 6px; white-space: nowrap; }
.pg-logo-testo { flex: 1 1 200px; font-weight: 600; }
.pg-file { position: absolute; opacity: 0; width: 1px; height: 1px; }
</style>
    <form method="POST" enctype="multipart/form-data" id="formProgramma">
        <?php csrf_field(); ?>
        <?php // Il tasto Invio in un campo deve confermare il programma, non togliere la prima attività (il primo pulsante del modulo) ?>
        <button type="submit" name="conferma_programma" value="1" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;" tabindex="-1" aria-hidden="true">Conferma</button>
        <section class="card border-0 shadow-sm mb-3" aria-labelledby="titProgramma"><div class="card-body">
            <h2 class="h5 fw-bold" id="titProgramma">Attività scelte (<?php echo count($voci); ?>)</h2>
            <?php foreach ($voci as $v): $bloccata = in_array($v['stato'], ['conclusa', 'chiusa', 'piena', 'assente'], true); ?>
                <div class="border rounded p-3 mb-2 <?php echo $bloccata ? 'border-danger bg-light' : ($v['stato'] === 'attesa' ? 'border-warning' : ''); ?>">
                    <div class="d-flex flex-wrap justify-content-between gap-2">
                        <div>
                            <div class="fw-bold"><?php echo $h($v['titolo']); ?></div>
                            <div class="small text-secondary">
                                <?php echo $v['turno'] !== '' ? '<i class="fa fa-calendar-day me-1" aria-hidden="true"></i>' . $h($v['turno']) . ' · ' : ''; ?><?php echo $v['periodo'] !== '' ? $h($v['periodo']) : ''; ?>
                                <?php echo $v['sede'] !== '' ? ' · <i class="fa fa-location-dot me-1" aria-hidden="true"></i>' . $h($v['sede']) : ''; ?>
                                <?php echo $v['studenti'] > 0 ? ' · <i class="fa fa-users me-1" aria-hidden="true"></i>' . (int)$v['studenti'] . ' studenti' : ''; ?>
                            </div>
                            <?php if ($v['nota'] !== ''): ?><div class="small mt-1 <?php echo $bloccata ? 'text-danger fw-bold' : 'text-warning-emphasis fw-bold'; ?>"><i class="fa <?php echo $bloccata ? 'fa-circle-exclamation' : 'fa-clock'; ?> me-1" aria-hidden="true"></i><?php echo $h($v['nota']); ?></div><?php endif; ?>
                        </div>
                        <div class="d-flex gap-2 align-items-start">
                            <?php if ($v['slug'] !== ''): ?><a class="btn btn-sm btn-outline-secondary" href="<?php echo $h($v['slug'] . '.php?' . ($v['tipo'] === 'progetto' ? 'progetto' : 'evento') . '=' . (int)$v['evento_id']); ?>"><i class="fa fa-pen me-1" aria-hidden="true"></i>Modifica</a><?php endif; ?>
                            <button type="submit" name="togli" value="<?php echo (int)$v['evento_id']; ?>" class="btn btn-sm btn-outline-danger" formnovalidate aria-label="Togli dal programma: <?php echo $h($v['titolo']); ?>"><i class="fa fa-trash me-1" aria-hidden="true"></i>Togli</button>
                        </div>
                    </div>
                    <?php if (!$bloccata): ?>
                    <div class="pg-docente mt-3">
                        <label class="pg-docente-tit" for="doc<?php echo (int)$v['evento_id']; ?>"><i class="fa fa-user-tie me-1" aria-hidden="true"></i>Docente referente della scuola per questa attività</label>
                        <input class="form-control" id="doc<?php echo (int)$v['evento_id']; ?>" name="docente[<?php echo (int)$v['evento_id']; ?>]" maxlength="150" placeholder="es. Prof.ssa Maria Verdi"
                               value="<?php echo $h($mod['docente'][$v['evento_id']] ?? $v['docente']); ?>">
                        <div class="form-text">È il docente che accompagna gli studenti e compare nell'Allegato A. Se lasci vuoto, è chi sta prenotando.</div>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if ($n_attesa > 0): ?><div class="alert alert-warning small mt-3 mb-0"><i class="fa fa-clock me-1" aria-hidden="true"></i><strong>Lista d'attesa.</strong> Per <?php echo $n_attesa === 1 ? "un'attività" : "$n_attesa attività"; ?> i posti sono esauriti: alla conferma la richiesta entra in lista d'attesa e, se si libera un posto, ti scriviamo.</div><?php endif; ?>
            <?php if ($n_bloccate > 0): ?><div class="alert alert-danger small mt-3 mb-0"><i class="fa fa-circle-exclamation me-1" aria-hidden="true"></i><?php echo $n_bloccate === 1 ? "Un'attività non si può prenotare" : "$n_bloccate attività non si possono prenotare"; ?>: toglile dal programma prima di confermare.</div><?php endif; ?>
            <div class="small text-secondary mt-3"><i class="fa fa-circle-info me-1" aria-hidden="true"></i>I posti non sono riservati finché non confermi: si assegnano alla conferma, in ordine di arrivo.</div>
        </div></section>

        <section class="card border-0 shadow-sm mb-3" id="conferma"><div class="card-body">
            <h2 class="h5 fw-bold">Chi prenota</h2>
            <div class="row g-2">
                <div class="col-md-6"><label class="form-label small fw-bold" for="pgNome">Nome <span class="text-danger">*</span></label><input class="form-control" id="pgNome" name="nome" value="<?php echo $utente_logged ? $h($val_nome) : $val('nome'); ?>" required <?php echo $utente_logged && $val_nome !== '' ? 'readonly' : ''; ?> maxlength="100"></div>
                <div class="col-md-6"><label class="form-label small fw-bold" for="pgCognome">Cognome <span class="text-danger">*</span></label><input class="form-control" id="pgCognome" name="cognome" value="<?php echo $utente_logged ? $h($val_cognome) : $val('cognome'); ?>" required <?php echo $utente_logged && $val_cognome !== '' ? 'readonly' : ''; ?> maxlength="100"></div>
                <div class="col-md-6"><label class="form-label small fw-bold" for="pgEmail">Email <span class="text-danger">*</span></label><input type="email" class="form-control" id="pgEmail" name="email" autocomplete="email" value="<?php echo $utente_logged ? $h($val_email) : $val('email'); ?>" required <?php echo $utente_logged && $val_email !== '' ? 'readonly' : ''; ?> maxlength="255"></div>
                <?php if (!$utente_logged): ?><div class="col-md-6"><label class="form-label small fw-bold" for="pgEmail2">Ripeti l'email <span class="text-danger">*</span></label><input type="email" class="form-control" id="pgEmail2" name="email_conferma" autocomplete="off" required maxlength="255"></div><?php endif; ?>
                <div class="col-12 form-text">Qui riceverai il riepilogo, le conferme e il link ai documenti.</div>
            </div>
        </div></section>

        <section class="card border-0 shadow-sm mb-3"><div class="card-body">
            <h2 class="h5 fw-bold">La scuola</h2>
            <div class="mb-2"><div class="form-label small fw-bold">Scuola <span class="text-danger">*</span></div>
                <?php echo html_campo_scuola('scuola', $mod['custom_scuola'] ?? ($scuola_predefinita ? etichetta_scuola($scuola_predefinita) : ''), $mod['scuola_codice']['scuola'] ?? ($scuola_predefinita['codice'] ?? ''), 'required', 'form-control'); ?></div>

            <fieldset class="mt-3 p-3 rounded border conv-box" data-dal="<?php echo $h($dal_min); ?>" data-al="<?php echo $h($al_max); ?>">
                <legend class="form-label small fw-bold mb-2 float-start w-100 p-0" style="font-size:.95rem; white-space:normal; line-height:1.4;"><i class="fa fa-file-signature me-1" aria-hidden="true"></i>La scuola ha già stipulato la convenzione con il Dipartimento per la Formazione Scuola Lavoro? <span class="text-danger">*</span></legend>
                <div class="clearfix"></div>
                <div class="form-check form-check-inline"><input class="form-check-input conv-radio" type="radio" name="convenzione" id="pgConvSi" value="si" required<?php echo ($mod['convenzione'] ?? '') === 'si' ? ' checked' : ''; ?>><label class="form-check-label" for="pgConvSi">Sì, è già stipulata</label></div>
                <div class="form-check form-check-inline"><input class="form-check-input conv-radio" type="radio" name="convenzione" id="pgConvNo" value="no" required<?php echo ($mod['convenzione'] ?? '') === 'no' ? ' checked' : ''; ?>><label class="form-check-label" for="pgConvNo">No, non ancora</label></div>
                <div class="conv-scad alert alert-danger small mt-2 mb-0" hidden><i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i>La convenzione della scuola registrata al Dipartimento non copre tutto il periodo delle attività: <strong>va stipulata una nuova convenzione</strong>.</div>
                <div class="conv-reg alert alert-success small mt-2 mb-0" hidden><i class="fa fa-circle-check me-1" aria-hidden="true"></i>La scuola ha già una convenzione con il Dipartimento valida per il periodo delle attività (<span class="conv-reg-sc"></span>): serve solo l'Allegato A.</div>
                <div class="conv-no alert alert-warning small mt-2 mb-0" hidden><i class="fa fa-circle-info me-1" aria-hidden="true"></i>Le prenotazioni restano in attesa finché il Dipartimento non riceve la convenzione firmata. Qui sotto puoi far preparare la convenzione già compilata.</div>
                <div id="notaConvSi" class="small text-secondary mt-2" hidden><i class="fa fa-circle-info me-1" aria-hidden="true"></i>Per l'Allegato A useremo i dati dell'anagrafe delle scuole. Dopo la conferma potrai correggere i dati dalla pagina della convenzione.</div>
            </fieldset>

            <fieldset id="datiConvenzione" class="pg-dati mt-3" hidden disabled>
                <legend class="h6 fw-bold mb-1">Dati per la convenzione <span class="badge bg-secondary fw-normal">facoltativi</span></legend>
                <p class="small text-secondary">Se li conosci, scrivili: ti restituiamo la <strong>Convenzione già compilata</strong>. Se non li conosci lasciali vuoti: avrai la convenzione da completare a mano. Nessun campo è obbligatorio.</p>

                <div class="pg-gruppo">
                    <h3 class="pg-gruppo-tit"><i class="fa fa-school me-1" aria-hidden="true"></i>Istituto</h3>
                    <div id="pgDenInfo" class="small text-success mb-2" hidden><i class="fa fa-circle-check me-1" aria-hidden="true"></i>Dati compilati dall'anagrafe delle scuole: puoi correggerli.</div>
                    <div class="row g-3">
                        <div class="col-md-8"><label class="form-label" for="pgDen">Denominazione dell'istituto</label><input class="form-control" id="pgDen" readonly placeholder="Compilata dalla scuola scelta sopra" aria-describedby="pgDenInfo"></div>
                        <div class="col-md-4"><label class="form-label" for="pgCodice">Codice meccanografico</label><input class="form-control font-monospace" id="pgCodice" name="codice" value="<?php echo $val('codice'); ?>" maxlength="20"></div>
                        <div class="col-md-4"><label class="form-label" for="pgCf">Codice fiscale dell'istituto</label><input class="form-control font-monospace" id="pgCf" name="cf" value="<?php echo $val('cf'); ?>" maxlength="16" placeholder="11 cifre"></div>
                        <div class="col-md-4"><label class="form-label" for="pgCom">Comune (provincia)</label><input class="form-control" id="pgCom" name="comune" value="<?php echo $val('comune'); ?>" maxlength="255"></div>
                        <div class="col-md-4"><label class="form-label" for="pgInd">Indirizzo</label><input class="form-control" id="pgInd" name="indirizzo" value="<?php echo $val('indirizzo'); ?>" maxlength="255"></div>
                        <div class="col-md-6"><label class="form-label" for="pgPec">PEC della scuola</label><input type="email" class="form-control" id="pgPec" name="pec" value="<?php echo $val('pec'); ?>" maxlength="255"></div>
                    </div>
                </div>

                <div class="pg-gruppo">
                    <h3 class="pg-gruppo-tit"><i class="fa fa-user-tie me-1" aria-hidden="true"></i>Dirigente Scolastico</h3>
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label" for="pgDir">Nome e cognome</label><input class="form-control" id="pgDir" name="dirigente" value="<?php echo $val('dirigente'); ?>" maxlength="255" placeholder="es. Dott.ssa Maria Rossi"></div>
                        <div class="col-md-6"><label class="form-label" for="pgDcf">Codice fiscale</label><input class="form-control font-monospace" id="pgDcf" name="dir_cf" value="<?php echo $val('dir_cf'); ?>" maxlength="16" style="text-transform:uppercase;"></div>
                        <div class="col-md-6"><label class="form-label" for="pgLn">Luogo di nascita</label><input class="form-control" id="pgLn" name="luogo_nascita" value="<?php echo $val('luogo_nascita'); ?>" maxlength="255"></div>
                        <div class="col-md-6"><label class="form-label" for="pgDn">Data di nascita</label><input type="date" class="form-control" id="pgDn" name="data_nascita" value="<?php echo $val('data_nascita'); ?>"></div>
                    </div>
                </div>

            </fieldset>

            <div class="pg-gruppo mt-3">
                <h3 class="pg-gruppo-tit"><i class="fa fa-image me-1" aria-hidden="true"></i>Logo della scuola <span class="text-danger" title="obbligatorio">*</span></h3>
                <p class="small text-secondary mb-2">Serve per l'<strong>Allegato A</strong> (e per la Convenzione): compare in testa ai documenti.</p>
                <input type="file" class="pg-file" id="pgLogo" name="logo" accept=".png,.jpg,.jpeg" required>
                <label for="pgLogo" class="pg-logo">
                    <span class="pg-logo-btn"><i class="fa fa-upload me-1" aria-hidden="true"></i>Scegli il file del logo</span>
                    <span class="pg-logo-testo"><span id="pgLogoNome">Nessun file scelto</span><small class="d-block text-secondary">PNG o JPG, fino a 2 MB.</small></span>
                    <img id="pgLogoImg" alt="Anteprima del logo scelto" hidden style="max-height:56px;max-width:140px;">
                </label>
            </div>
        </div></section>

        <section class="card border-0 shadow-sm mb-3"><div class="card-body">
            <?php if (!$utente_logged): $cap = captcha_prenotazione(); ?>
            <div class="p-3 rounded border bg-light mb-3">
                <label for="pgCaptcha" class="form-label small fw-bold mb-1"><i class="fa fa-shield-halved me-1" aria-hidden="true"></i>Controllo anti-robot: <?php echo $h($cap['domanda']); ?> <span class="text-danger">*</span></label>
                <input type="text" name="captcha_risposta" id="pgCaptcha" class="form-control form-control-sm" style="max-width:140px;" inputmode="numeric" pattern="[0-9]*" maxlength="3" autocomplete="off" required>
                <input type="hidden" name="captcha_id" value="<?php echo $h($cap['id']); ?>">
                <div class="form-text">Con l'accesso SPID, CIE o Unical questa domanda non viene chiesta.</div>
            </div>
            <div style="position:absolute; left:-10000px; width:1px; height:1px; overflow:hidden;" aria-hidden="true"><label for="pgSito">Sito web (lascia vuoto)</label><input type="text" name="sito_web" id="pgSito" value="" tabindex="-1" autocomplete="off"></div>
            <?php endif; ?>
            <div class="form-check p-3 bg-light rounded border border-secondary mb-3">
                <input class="form-check-input border-secondary" type="checkbox" name="accetta_privacy" id="pgPrivacy" required>
                <label class="form-check-label text-dark" for="pgPrivacy" style="font-size:.85rem; line-height:1.4;">Ho letto l'<a href="privacy.php" target="_blank" rel="noopener" class="fw-bold">informativa sul trattamento dei dati personali</a>.</label>
            </div>
            <button type="submit" name="conferma_programma" value="1" class="btn btn-lg fw-bold text-white" style="background:#B30000;" <?php echo $n_prenotabili < 1 ? 'disabled' : ''; ?>><i class="fa fa-check me-1" aria-hidden="true"></i>Conferma e prenota <?php echo $n_prenotabili === 1 ? "l'attività" : "le $n_prenotabili attività"; ?></button>
            <button type="submit" name="svuota" value="1" class="btn btn-lg btn-outline-secondary fw-bold ms-2" formnovalidate onclick="return confirm('Vuoi svuotare il programma?');">Svuota il programma</button>

        </div></section>
    </form>
    <script>
    (function () {
        var form = document.getElementById('formProgramma'); if (!form) return;
        var box = form.querySelector('.conv-box'), dati = document.getElementById('datiConvenzione'), nota = document.getElementById('notaConvSi');
        function aggiorna() {
            var r = form.querySelector('.conv-radio:checked'), no = r && r.value === 'no';
            box.querySelector('.conv-no').hidden = !no;
            nota.hidden = !(r && r.value === 'si');
            dati.hidden = !no;
            dati.disabled = !no;   // i campi nascosti non partono con il modulo
        }
        form.addEventListener('change', function (e) { if (e.target.classList && e.target.classList.contains('conv-radio')) aggiorna(); });

        // Scuola scelta dall'anagrafe: se nel registro c'è una convenzione che copre tutto il periodo delle attività si risponde «Sì» da soli;
        // se ce n'è una che non lo copre, «No» (ne va stipulata una nuova). In ogni caso si precompilano i dati dell'istituto.
        var auto = ['pgDen', 'pgCodice', 'pgCom', 'pgInd'];
        function riempi(d) {
            var m = { pgDen: d && d.denominazione, pgCodice: d && d.codice, pgCom: d && d.comune, pgInd: d && d.indirizzo };
            auto.forEach(function (id) {
                var c = document.getElementById(id); if (!c) return;
                if (d) { c.value = m[id] || ''; c.dataset.auto = '1'; c.readOnly = (id === 'pgDen'); }
                else if (c.dataset.auto === '1') { c.value = ''; c.dataset.auto = ''; c.readOnly = false; }
            });
            document.getElementById('pgDenInfo').hidden = !d;
        }
        document.addEventListener('scuola-scelta', function (e) {
            var s = e.detail, reg = box.querySelector('.conv-reg'), scad = box.querySelector('.conv-scad');
            reg.hidden = true; scad.hidden = true;
            if (!s) { riempi(null); return; }
            var ep = e.target.closest('.scuola-campo').dataset.endpoint;
            fetch(ep + '?dettaglio=' + encodeURIComponent(s.codice), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(riempi).catch(function () {});
            if (!Array.isArray(s.conv) || !s.conv.length) return;
            var dal = box.dataset.dal, al = box.dataset.al;
            var fmt = function (d) { return d.split('-').reverse().join('/'); };
            var ok = s.conv.filter(function (p) { return (!p[0] || !dal || p[0] <= dal) && (!p[1] || !al || p[1] >= al); })[0];
            form.querySelector('.conv-radio[value="' + (ok ? 'si' : 'no') + '"]').checked = true;
            if (ok) { box.querySelector('.conv-reg-sc').textContent = ok[1] ? (ok[0] ? 'dal ' + fmt(ok[0]) + ' al ' : 'fino al ') + fmt(ok[1]) : 'senza scadenza'; reg.hidden = false; }
            else scad.hidden = false;
            aggiorna();
        });

        // Logo: nome del file scelto e anteprima
        var logo = document.getElementById('pgLogo'), nomeLogo = document.getElementById('pgLogoNome'), anteprima = document.getElementById('pgLogoImg');
        if (logo) logo.addEventListener('change', function () {
            var f = logo.files && logo.files[0];
            nomeLogo.textContent = f ? f.name : 'Nessun file scelto';
            anteprima.hidden = !f; if (f) anteprima.src = URL.createObjectURL(f);
        });
        aggiorna();
    })();
    </script>
    <?php endif; ?>
</div>
<?php require_once 'footer.php'; ?>
