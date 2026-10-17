<?php
// richiedi_convenzione_tesi.php - Pagina pubblica per enti e aziende che vogliono la convenzione per la tesi: si scrive il nome dell'ente e l'email,
// si riceve per posta il link personale per compilarla (convenzione_tesi.php). Nessuna registrazione. Anti-robot: domanda di controllo, campo trappola, limite per IP.
// Alla stessa email si risponde sempre allo stesso modo (non si rivela se esiste già una convenzione). Logica in src/ConvenzioniTesi/.
require_once 'config.php';
require_once 'functions.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$svc = \App\Core\App::per($conn)->get(\App\ConvenzioniTesi\ServizioConvenzioniTesi::class);
$errore = null; $inviata = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    if (!empty($_POST['sito_web'])) { $inviata = true; }                                           // campo trappola: i programmi automatici lo riempiono
    elseif (!check_rate_limit($conn, 'richiesta_convenzione_tesi', 5, 3600)) { $errore = 'Troppe richieste da questo indirizzo: riprova tra un po\'.'; }
    elseif ($err_c = captcha_verifica((string)($_POST['captcha_id'] ?? ''), (string)($_POST['captcha_risposta'] ?? ''))) { $errore = $err_c; }
    else {
        [$err] = $svc->avvia((string)($_POST['email'] ?? ''), (string)($_POST['azienda'] ?? ''), \App\ConvenzioniTesi\Costanti::ORIGINE_PUBBLICA);
        if ($err) $errore = $err; else $inviata = true;
    }
}
$cap = captcha_prenotazione();
$page_cfg['titolo'] = 'Convenzione per la tesi: richiedila';
require_once 'header.php';
?>
<div class="container my-4" style="max-width: 760px;">
    <nav aria-label="Percorso" class="mb-3 small"><a href="index.php">Home</a> <span class="text-secondary mx-1">/</span> Convenzione per la tesi</nav>
    <h1 class="fw-bold mb-1">Convenzione per la tesi con il Dipartimento DiBEST</h1>
    <p class="text-secondary">Se il tuo ente o la tua azienda accoglie studenti che preparano la tesi, serve una convenzione con il Dipartimento. <strong>Si fa online in pochi minuti, senza registrarsi</strong>:</p>
    <ol class="mb-4">
        <li>scrivi qui il nome dell'ente e un'email: ti mandiamo un <strong>link personale</strong>;</li>
        <li>dal link inserisci i dati dell'azienda e del rappresentante legale (e, se vuoi, proponi modifiche agli articoli);</li>
        <li>scarichi la convenzione già compilata, la <strong>firmi digitalmente</strong> (PAdES) e la ricarichi;</li>
        <li>al resto pensiamo noi: protocollo, firma del Direttore e archivio. Ti scriviamo appena è stipulata.</li>
    </ol>
    <?php if ($inviata): ?>
        <div class="alert alert-success" role="alert"><i class="fa fa-envelope-circle-check me-1" aria-hidden="true"></i><strong>Fatto.</strong> Se l'indirizzo è corretto, tra pochi minuti trovi nella posta il link per compilare la convenzione (controlla anche lo spam).</div>
    <?php else: ?>
    <?php if ($errore): ?><div class="alert alert-danger" role="alert"><?php echo $h($errore); ?></div><?php endif; ?>
    <form method="POST" class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
        <?php csrf_field(); ?>
        <div class="mb-3"><label class="form-label fw-bold" for="azienda">Nome dell'ente o dell'azienda *</label><input class="form-control" id="azienda" name="azienda" maxlength="200" required value="<?php echo $h($_POST['azienda'] ?? ''); ?>"></div>
        <div class="mb-3"><label class="form-label fw-bold" for="email">Email a cui mandare il link *</label><input type="email" class="form-control" id="email" name="email" maxlength="255" required value="<?php echo $h($_POST['email'] ?? ''); ?>"><div class="form-text">Meglio un indirizzo dell'ufficio che segue la convenzione: il link è personale.</div></div>
        <div class="mb-3" style="position:absolute;left:-9999px;" aria-hidden="true"><label for="sito_web">Non compilare questo campo</label><input id="sito_web" name="sito_web" tabindex="-1" autocomplete="off"></div>
        <div class="mb-3"><label class="form-label fw-bold" for="captcha_risposta"><?php echo $h($cap['domanda']); ?></label><input class="form-control" style="max-width:140px;" id="captcha_risposta" name="captcha_risposta" inputmode="numeric" pattern="[0-9]*" required autocomplete="off"><input type="hidden" name="captcha_id" value="<?php echo $h($cap['id']); ?>"></div>
        <button type="submit" class="btn btn-danger fw-bold"><i class="fa fa-paper-plane me-1" aria-hidden="true"></i>Inviami il link</button>
    </div></form>
    <?php endif; ?>
    <p class="small text-secondary mt-3">Vuoi sapere se la tua azienda è già convenzionata? Guarda l'<a href="aziende_convenzionate_tesi.php">elenco delle aziende convenzionate</a>.</p>
</div>
<?php require_once 'footer.php'; ?>
