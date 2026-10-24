<?php
// assistenza.php - Assistenza a studenti, personale ed esterni: si sceglie l'argomento, si scrive e si continua in chat con gli operatori.
// Chi ha il login ritrova la sua conversazione; gli esterni (nome ed email, controllo anti-robot) usano il link personale ricevuto per email (?t=…).
// Con ?pannello=1 la stessa pagina è la finestrella «Serve aiuto?» che compare in basso a destra su tutte le pagine (assets/js/assistenza-widget.js).
define('PAGINA_SENZA_SSO', true); // l'accesso lo controlliamo noi qui sotto, senza il giro dell'intestazione
require_once 'config.php';
require_once 'functions.php';

sync_sso_user($conn); // riconosce chi ha già fatto l'accesso (SSO): anche gli esterni possono scrivere, quindi niente reindirizzamento
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$app = \App\Core\App::per($conn);
$srv = $app->get(\App\Assistenza\ServizioAssistenza::class);
$repo = $app->get(\App\Assistenza\ConversazioniRepository::class);
$Cost = \App\Assistenza\Costanti::class;
$pannello = !empty($_GET['pannello']) || !empty($_POST['pannello']);
$qs_pannello = $pannello ? 'pannello=1' : '';
$did_attivo = $app->get(\App\Assistenza\Didacticus\ServizioDidacticus::class)->attivo(); // Didacticus, l'assistente virtuale, risponde per primo
$utente = !empty($_SESSION['utente_id']) ? $app->get(\App\Auth\UtenteRepository::class)->perId((int)$_SESSION['utente_id']) : null;

// La conversazione di chi apre la pagina: dal link personale (esterni) o la più recente di chi ha il login
$token = strtolower(trim((string)($_GET['t'] ?? $_POST['t'] ?? '')));
$conv = null;
if ($token !== '') {
    $c_tok = $srv->perToken($token);
    $conv = $c_tok && $c_tok['canale'] === $Cost::CANALE_ESTERNO ? $c_tok : null;
}
if (!$conv && $utente) { // senza link valido (o con un link vecchio ricordato dal browser) vale la chat di chi ha il login
    $conv = ($repo->diUtente((int)$utente['id'], 1)[0] ?? null);
    $token = '';
}
$url_pagina = function (array $extra = []) use ($qs_pannello): string {
    $q = array_filter(($qs_pannello ? ['pannello' => 1] : []) + $extra, fn($v) => $v !== '' && $v !== null);
    return 'assistenza.php' . ($q ? '?' . http_build_query($q) : '');
};

// Nuova richiesta (modulo): chi ha il login scrive subito; gli esterni indicano nome ed email e risolvono il controllo anti-robot
$errore = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['azione'] ?? '') === 'nuova') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $argomento = (string)($_POST['argomento'] ?? '');
    $testo = (string)($_POST['testo'] ?? '');
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (!empty($_POST['sito_web'])) {
        $errore = 'Richiesta non valida.'; // campo trappola compilato: è un programma automatico
    } elseif (!check_rate_limit($conn, 'assistenza_nuova', 12, 600)) {
        $errore = 'Troppi tentativi: riprova tra qualche minuto.';
    } elseif ($utente) {
        $contesto = $app->get(\App\Assistenza\Contesti::class)->pratica((int)($_POST['pratica'] ?? 0), (int)$utente['id'])
            ?? $app->get(\App\Assistenza\Contesti::class)->prenotazione((int)($_POST['prenotazione'] ?? 0), (int)$utente['id']);
        [$nuova, $errore] = $srv->apriDaUtente($utente, $argomento, $testo, $contesto, $ip, !empty($_POST['con_operatore']));
        if ($nuova) { header('Location: ' . $url_pagina()); exit; }
    } else {
        $errore = captcha_verifica((string)($_POST['captcha_id'] ?? ''), (string)($_POST['captcha_risposta'] ?? ''));
        if ($errore === null) {
            [$nuova, $errore] = $srv->apriDaEsterno((string)($_POST['nome'] ?? ''), (string)($_POST['email'] ?? ''), $argomento, $testo, $ip, null, !empty($_POST['con_operatore']));
            if ($nuova) { header('Location: ' . $url_pagina(['t' => $nuova['token'], 'nuova_ok' => 1])); exit; }
        }
    }
}

$mostra_modulo = !$conv || isset($_GET['nuova']) || $errore !== null;
$chiusa = $conv && $conv['stato'] === $Cost::STATO_CHIUSA;
$argomento_sel = $Cost::argomentoValido((string)($_POST['argomento'] ?? $_GET['argomento'] ?? ''));
$pratica_ctx = $utente ? $app->get(\App\Assistenza\Contesti::class)->pratica((int)($_GET['pratica'] ?? $_POST['pratica'] ?? 0), (int)$utente['id']) : null;
$pren_ctx = $utente && !$pratica_ctx ? $app->get(\App\Assistenza\Contesti::class)->prenotazione((int)($_GET['prenotazione'] ?? $_POST['prenotazione'] ?? 0), (int)$utente['id']) : null;
$cap = !$utente && $mostra_modulo ? captcha_prenotazione() : null;

$page_cfg['titolo'] = 'Assistenza';
if ($pannello) {
    // Finestrella: niente intestazione né piè di pagina del sito, solo la chat
    $css = fn($f) => '<link rel="stylesheet" href="' . $h(url_vendor($f)) . '">';
    echo '<!DOCTYPE html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Assistenza</title>'
        . $css('fonts/titillium-lora.css') . $css('jsdelivr/npm/bootstrap-italia@2.8.3/dist/css/bootstrap-italia.min.css') . $css('cdnjs/ajax/libs/font-awesome/6.4.0/css/all.min.css')
        . '<style>body{font-family:"Titillium Web",sans-serif;background:#fff;} html{font-size:15px;height:100%} body{height:100%;margin:0}'
        . ' .ac-pannello{height:100%;display:flex;flex-direction:column;padding:10px;overflow-y:auto} .ac-pannello #ac-app{flex:1;min-height:0;display:flex;flex-direction:column}'
        . ' .ac-pannello #ac-log{flex:1;height:auto !important;min-height:0 !important;padding:.6rem !important;font-size:1rem}'
        . ' .ac-pannello .card-header{padding:.4rem .6rem !important;flex-wrap:nowrap !important} .ac-pannello .card-header>div:first-child{min-width:0;flex:1 1 auto} .ac-pannello .card-header .d-flex{flex-shrink:0} .ac-pannello{padding-bottom:0 !important} .ac-pannello #ac-stato{font-size:.75rem;line-height:1.25}'
        . ' .ac-pannello #ac-intestazione img{width:28px;height:28px} .ac-pannello .btn-sm{padding:.15rem .5rem;font-size:.8rem}'
        . ' .ac-pannello #ac-push{padding:.35rem .6rem !important;font-size:.78rem;line-height:1.3} .ac-pannello #ac-valuta{padding:.35rem .6rem !important}'
        . ' .ac-pannello #ac-form{padding:.5rem .6rem} .ac-pannello #ac-form .form-text{font-size:.72rem;margin-top:.2rem} .ac-pannello #ac-testo{font-size:.95rem}'
        . ' .ac-pannello .ac-azioni .btn{font-size:.82rem;padding:.2rem .55rem} .ac-pannello .ac-azioni .small{font-size:.78rem}'
        . '</style></head><body><div class="ac-pannello">';
} else {
    require_once 'header.php';
    echo '<div class="container my-4" style="max-width: 760px;">';
}
?>
<?php echo flash_html(); ?>
<?php if (!$pannello): ?>
    <h1 class="h3 fw-bold mb-1"><i class="fa fa-headset text-danger me-2" aria-hidden="true"></i>Assistenza</h1>
    <p class="text-secondary">Scrivici per qualsiasi dubbio su tirocini, certificati, pratiche, tutorato, tesi, eventi e accesso al portale: <?php echo $did_attivo ? 'ti risponde subito <strong>Didacticus</strong>, l’assistente virtuale, e se serve una persona dell’ufficio didattico.' : 'ti risponde una persona dell’ufficio didattico.'; ?></p>
    <?php if ($mostra_modulo): ?>
    <section class="card border-0 shadow-sm mb-4" style="border-radius:14px;background:#f8fafc;" aria-labelledby="as-faq"><div class="card-body">
        <h2 class="h5 fw-bold mb-1" id="as-faq"><i class="fa fa-book-open text-danger me-2" aria-hidden="true"></i>Prima di scrivere, cerca la risposta</h2>
        <p class="small text-secondary mb-2">Molte domande hanno già una risposta nelle <a href="domande_frequenti.php">domande frequenti</a>: è più veloce.</p>
        <form method="GET" action="domande_frequenti.php" class="d-flex gap-2 mb-2" role="search">
            <label class="visually-hidden" for="as-cerca">Cerca nelle domande frequenti</label>
            <input class="form-control" id="as-cerca" name="q" placeholder="Per esempio: certificato, tasse, tirocinio, tesi" autocomplete="off">
            <button class="btn btn-danger fw-bold" type="submit" style="background-color:#B80000;border:none;"><i class="fa fa-search" aria-hidden="true"></i><span class="visually-hidden"> Cerca</span></button>
        </form>
        <div class="d-flex flex-wrap gap-2"><?php foreach ($Cost::argomenti() as $k_f => $a_f): ?><a class="btn btn-sm btn-outline-secondary" href="domande_frequenti.php?argomento=<?php echo $h($k_f); ?>"><i class="fa <?php echo $h($a_f['icona']); ?> me-1" aria-hidden="true"></i><?php echo $h($a_f['nome']); ?></a><?php endforeach; ?></div>
    </div></section>
    <?php endif; ?>
<?php endif; ?>

<?php
$orari_srv = $app->get(\App\Assistenza\OrariAssistenza::class);
$push_pub = $app->get(\App\Assistenza\Push\ServizioPush::class);
$servizio_attivo = $orari_srv->attiva();
$descr_orari = $orari_srv->descrizione();
if (!$servizio_attivo): ?>
    <div class="alert alert-warning py-2" role="status"><i class="fa fa-pause-circle me-1" aria-hidden="true"></i>Il servizio di assistenza è <strong>temporaneamente sospeso</strong>. Riprova più tardi.</div>
<?php elseif (!$orari_srv->inOrario()): ?>
    <div class="alert alert-info py-2" role="status"><i class="fa fa-moon me-1" aria-hidden="true"></i>In questo momento non c'è nessuno in linea<?php echo $descr_orari !== '' ? ' (rispondiamo: ' . $h($descr_orari) . ')' : ''; ?>. Puoi scrivere lo stesso: ti rispondiamo appena possibile e, se non hai la pagina aperta, la risposta ti arriva per email.</div>
<?php elseif ($descr_orari !== '' && !$pannello): ?>
    <p class="small text-secondary mb-2"><i class="fa fa-clock me-1" aria-hidden="true"></i>Orari dell'assistenza: <?php echo $h($descr_orari); ?>.</p>
<?php endif; ?>
<?php if ($errore): ?><div class="alert alert-danger py-2" role="alert"><i class="fa fa-circle-xmark me-1" aria-hidden="true"></i><?php echo $h($errore); ?></div><?php endif; ?>
<?php if (!empty($_GET['nuova_ok']) && $conv): ?>
    <div class="alert alert-success py-2" role="status"><i class="fa fa-circle-check me-1" aria-hidden="true"></i>Richiesta inviata. Ti abbiamo scritto un'email con il <strong>link personale</strong> per riprendere questa chat in qualsiasi momento: non condividerlo.</div>
<?php endif; ?>

<?php if ($conv && !$mostra_modulo): ?>
    <!-- CHAT -->
    <div class="card border-0 shadow-sm" id="ac-app" data-url="assistenza_ajax.php" data-csrf="<?php echo $h(csrf_token()); ?>" data-t="<?php echo $conv['canale'] === $Cost::CANALE_ESTERNO ? $h($token) : ''; ?>" data-chiusa="<?php echo $chiusa ? 1 : 0; ?>" data-chiave="<?php echo $h($push_pub->chiavePubblica()); ?>" data-avatar="didacticus_file.php?avatar=1" data-argomenti="<?php echo $h(json_encode(array_map(fn($k, $a) => ['k' => $k, 'n' => $a['nome'], 'i' => $a['icona']], array_keys($Cost::argomenti()), $Cost::argomenti()), JSON_UNESCAPED_UNICODE)); ?>">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2 py-2">
            <div>
                <div id="ac-intestazione" class="d-none"><span class="d-flex align-items-center gap-2 mb-1"><img src="didacticus_file.php?avatar=1" alt="" width="34" height="34" class="rounded-circle" style="object-fit:cover;"><strong>Didacticus</strong><span class="badge bg-light text-dark border">assistente virtuale</span></span></div>
                <div class="fw-bold"><?php echo $h($Cost::nomeArgomento((string)$conv['argomento'])); ?></div>
                <div class="small text-secondary" id="ac-stato" role="status"></div>
            </div>
            <div class="d-flex gap-1">
                <a class="btn btn-outline-secondary btn-sm" href="<?php echo $h($url_pagina(['t' => $token, 'nuova' => 1])); ?>" title="Inizia una nuova richiesta su un altro argomento"><i class="fa fa-plus <?php echo $pannello ? '' : 'me-1'; ?>" aria-hidden="true"></i><span class="<?php echo $pannello ? 'visually-hidden' : ''; ?>">Nuova richiesta</span></a>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="ac-chiudi" title="Chiudi la conversazione"><i class="fa fa-check <?php echo $pannello ? '' : 'me-1'; ?>" aria-hidden="true"></i><span class="<?php echo $pannello ? 'visually-hidden' : ''; ?>">Ho risolto</span></button>
            </div>
        </div>
        <div class="card-body" id="ac-log" role="log" aria-live="polite" aria-label="Messaggi della conversazione" style="height: <?php echo $pannello ? '48vh' : '50vh'; ?>; min-height: 260px; overflow-y: auto; background: #f8fafc;"></div>
        <div id="ac-errore" class="alert alert-warning m-2 py-2 d-none" role="alert"></div>
        <div id="ac-valuta" class="px-3 py-2 border-top bg-white d-none">
            <span class="fw-bold small">Ti è stato utile?</span>
            <button type="button" class="btn btn-outline-success btn-sm ms-2" data-utile="1"><i class="fa fa-thumbs-up me-1" aria-hidden="true"></i>Sì</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-utile="0"><i class="fa fa-thumbs-down me-1" aria-hidden="true"></i>No</button>
        </div>
        <?php if ($push_pub->attivo()): ?>
        <div id="ac-push" class="px-3 py-2 border-top bg-white d-none small">
            <i class="fa fa-bell text-danger me-1" aria-hidden="true"></i>Vuoi un avviso quando l'assistenza risponde?
            <button type="button" class="btn btn-outline-danger btn-sm ms-1" id="ac-push-attiva">Avvisami</button>
            <span id="ac-push-msg" class="text-secondary ms-1" role="status"></span>
        </div>
        <?php endif; ?>
        <form id="ac-form" class="card-footer bg-white" autocomplete="off">
            <label class="form-label small fw-bold mb-1" for="ac-testo">Scrivi un messaggio</label>
            <div class="d-flex gap-2 align-items-end">
                <textarea id="ac-testo" class="form-control" rows="2" maxlength="<?php echo $Cost::MAX_CARATTERI; ?>" placeholder="Invio per mandare, Maiusc+Invio per andare a capo"></textarea>
                <button type="submit" class="btn btn-danger fw-bold" id="ac-invia" style="background-color:#B80000;border:none;"><i class="fa fa-paper-plane" aria-hidden="true"></i><span class="visually-hidden">Invia</span></button>
            </div>
            <div class="form-text">Non scrivere password, codice fiscale o dati bancari. <a href="privacy.php" target="_top">Informativa privacy</a>.</div>
        </form>
        <noscript><div class="alert alert-warning m-2">Per la chat serve JavaScript. Puoi comunque aspettare la risposta: ti arriva per email.</div></noscript>
    </div>
    <?php if ($chiusa && !$pannello): ?><p class="small text-secondary mt-2">La conversazione è chiusa: se scrivi di nuovo si riapre, oppure inizia una <a href="<?php echo $h($url_pagina(['t' => $token, 'nuova' => 1])); ?>">nuova richiesta</a>.</p><?php endif; ?>
    <?php if ($push_pub->attivo()): ?><script src="assets/js/assistenza-push.js?v=1"></script><?php endif; ?>
    <script src="assets/js/assistenza-chat.js?v=4"></script>
    <?php if ($conv['canale'] === $Cost::CANALE_ESTERNO): ?>
        <script>try { localStorage.setItem('assistenza_t', <?php echo json_encode($token, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>); } catch (e) {}</script>
    <?php endif; ?>

<?php else: ?>
    <!-- NUOVA RICHIESTA -->
    <?php if (!$utente): ?>
        <div class="alert alert-info py-2 d-flex flex-wrap align-items-center gap-2">
            <span><i class="fa fa-id-card me-1" aria-hidden="true"></i><strong>Studente o personale Unical?</strong> Accedi: ritroverai le tue conversazioni e le pratiche.</span>
            <a class="btn btn-primary btn-sm fw-bold ms-md-auto" target="_top" href="saml_login.php?redirect=<?php echo urlencode('assistenza.php'); ?>"><i class="fa fa-right-to-bracket me-1" aria-hidden="true"></i>Accedi</a>
        </div>
    <?php endif; ?>
    <form method="POST" action="<?php echo $h($url_pagina()); ?>" class="card border-0 shadow-sm"><div class="card-body">
        <?php csrf_field(); ?>
        <input type="hidden" name="azione" value="nuova">
        <?php if ($pannello): ?><input type="hidden" name="pannello" value="1"><?php endif; ?>
        <?php if ($pratica_ctx): ?><input type="hidden" name="pratica" value="<?php echo (int)$pratica_ctx['id']; ?>"><div class="alert alert-light border py-2 small"><i class="fa fa-link me-1" aria-hidden="true"></i>Scrivi per <?php echo $h($pratica_ctx['testo']); ?>.</div><?php endif; ?>
        <?php if ($pren_ctx): ?><input type="hidden" name="prenotazione" value="<?php echo (int)$pren_ctx['id']; ?>"><div class="alert alert-light border py-2 small"><i class="fa fa-link me-1" aria-hidden="true"></i>Scrivi per <?php echo $h($pren_ctx['testo']); ?>.</div><?php endif; ?>
        <?php if (!$utente): ?>
            <div class="row g-2 mb-3">
                <div class="col-md-6"><label class="form-label fw-bold small" for="as-nome">Nome e cognome</label><input class="form-control" id="as-nome" name="nome" maxlength="150" autocomplete="name" required value="<?php echo $h($_POST['nome'] ?? ''); ?>"></div>
                <div class="col-md-6"><label class="form-label fw-bold small" for="as-email">Email (ti rispondiamo qui)</label><input class="form-control" type="email" id="as-email" name="email" maxlength="190" autocomplete="email" required value="<?php echo $h($_POST['email'] ?? ''); ?>"></div>
            </div>
        <?php endif; ?>
        <fieldset class="mb-3"><legend class="form-label fw-bold small">Di cosa hai bisogno?</legend>
            <div class="row g-2">
                <?php foreach ($Cost::argomenti() as $k_a => $a_a): ?>
                    <div class="col-6 col-md-4">
                        <input class="btn-check" type="radio" name="argomento" id="arg-<?php echo $k_a; ?>" value="<?php echo $k_a; ?>" <?php echo $k_a === $argomento_sel ? 'checked' : ''; ?> required>
                        <label class="btn btn-outline-secondary w-100 text-start h-100 py-2" for="arg-<?php echo $k_a; ?>" title="<?php echo $h($a_a['aiuto']); ?>"><i class="fa <?php echo $a_a['icona']; ?> me-1" aria-hidden="true"></i><span class="small fw-bold"><?php echo $h($a_a['nome']); ?></span></label>
                    </div>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <label class="form-label fw-bold small" for="as-testo">Scrivi la tua domanda</label>
        <textarea class="form-control mb-1" id="as-testo" name="testo" rows="4" maxlength="<?php echo $Cost::MAX_CARATTERI; ?>" required><?php echo $h($_POST['testo'] ?? ''); ?></textarea>
        <?php if ($did_attivo): ?>
            <div class="d-flex align-items-start gap-2 p-2 rounded border bg-light mb-2">
                <img src="didacticus_file.php?avatar=1" alt="" width="40" height="40" class="rounded-circle flex-shrink-0" style="object-fit:cover;">
                <div class="small"><strong>Ti risponde prima Didacticus</strong>, l'assistente virtuale del Dipartimento: conosce le domande più comuni e ti risponde subito. Se non basta, ti mette in contatto con una persona dell'ufficio.
                    <div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="con_operatore" value="1" id="as-conop" <?php echo !empty($_POST['con_operatore']) ? 'checked' : ''; ?>><label class="form-check-label" for="as-conop">Preferisco scrivere subito a un operatore</label></div></div>
            </div>
        <?php endif; ?>
        <div class="form-text mb-3">Non scrivere password, codice fiscale o dati bancari. La conversazione si conserva per 12 mesi: <a href="privacy.php" target="_top">informativa privacy</a>.</div>
        <?php if ($cap): ?>
            <div class="p-3 rounded border bg-light mb-3">
                <label for="as-captcha" class="form-label small fw-bold mb-1"><i class="fa fa-shield-halved me-1" aria-hidden="true"></i>Controllo anti-robot: <?php echo $h($cap['domanda']); ?></label>
                <input type="text" name="captcha_risposta" id="as-captcha" class="form-control form-control-sm" style="max-width:140px;" inputmode="numeric" pattern="[0-9]*" maxlength="3" autocomplete="off" required>
                <input type="hidden" name="captcha_id" value="<?php echo $h($cap['id']); ?>">
            </div>
            <div style="position:absolute; left:-10000px; width:1px; height:1px; overflow:hidden;" aria-hidden="true"><label>Sito web <input type="text" name="sito_web" tabindex="-1" autocomplete="off"></label></div>
        <?php endif; ?>
        <button class="btn btn-danger fw-bold" type="submit" style="background-color:#B80000;border:none;"><i class="fa fa-paper-plane me-1" aria-hidden="true"></i>Invia la richiesta</button>
        <?php if ($conv): ?><a class="btn btn-link" href="<?php echo $h($url_pagina(['t' => $token])); ?>">Torna alla chat</a><?php endif; ?>
    </div></form>
<?php endif; ?>

<?php
if ($pannello) {
    echo '</div></body></html>';
} else {
    echo '</div>';
    require_once 'footer.php';
}
