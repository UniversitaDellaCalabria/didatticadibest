<?php
// ricevi_link_convenzione.php - «Ho perso il link»: la scuola scrive l'email con cui ha compilato la convenzione FSL e riceve di nuovo il link personale
// (convenzione_online.php). Nessuna registrazione. Anti-robot: domanda di controllo, campo trappola, limite per IP. A schermo la risposta è sempre la stessa,
// esista o no una convenzione per quell'email (non si rivela niente). Logica in App\Fsl\ServizioConvenzioneOnline::rimandaLink().
define('PAGINA_SENZA_SSO', true); // pagina per chi non ha le credenziali di Ateneo: niente accesso SSO (vedi verbali.php)
require_once 'config.php';
require_once 'functions.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$errore = null; $inviata = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    if (!empty($_POST['sito_web'])) { $inviata = true; }                                           // campo trappola: i programmi automatici lo riempiono
    elseif (!check_rate_limit($conn, 'ricevi_link_convenzione', 5, 3600)) { $errore = 'Troppe richieste da questo indirizzo: riprova tra un po\'.'; }
    elseif ($err_c = captcha_verifica((string)($_POST['captcha_id'] ?? ''), (string)($_POST['captcha_risposta'] ?? ''))) { $errore = $err_c; }
    elseif (!filter_var(trim((string)($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL)) { $errore = 'Scrivi un indirizzo email valido.'; }
    else {
        \App\Core\App::per($conn)->get(\App\Fsl\ServizioConvenzioneOnline::class)->rimandaLink((string)$_POST['email']);
        $inviata = true;
    }
}
$cap = captcha_prenotazione();
$page_cfg['titolo'] = 'Convenzione FSL: ho perso il link';
require_once 'header.php';
?>
<div class="container my-4" style="max-width: 760px;">
    <nav aria-label="Percorso" class="mb-3 small"><a href="index.php">Home</a> <span class="text-secondary mx-1">/</span> Convenzione Formazione Scuola Lavoro</nav>
    <h1 class="fw-bold mb-1">Ho perso il link della convenzione</h1>
    <p class="text-secondary">Scrivi l'<strong>email con cui la scuola ha compilato la convenzione</strong> (quella della prenotazione o indicata nel modulo): ti rimandiamo il link per riprendere dove eri rimasto. Se hai il <em>codice della prenotazione</em> ti basta quello: scrivilo qui sotto.</p>
    <form method="GET" action="convenzione_online.php" class="d-flex flex-wrap gap-2 mb-4">
        <label class="visually-hidden" for="code">Codice della prenotazione</label>
        <input class="form-control" style="max-width:240px;" id="code" name="code" maxlength="50" placeholder="Codice della prenotazione" autocomplete="off">
        <button type="submit" class="btn btn-outline-secondary fw-bold">Apri la convenzione</button>
    </form>
    <?php if ($inviata): ?>
        <div class="alert alert-success" role="alert"><i class="fa fa-envelope-circle-check me-1" aria-hidden="true"></i><strong>Fatto.</strong> Se per questo indirizzo c'è una convenzione, tra pochi minuti trovi nella posta il link (controlla anche lo spam). Non arriva niente? Prova con un altro indirizzo della scuola o scrivi all'ufficio.</div>
    <?php else: ?>
    <?php if ($errore): ?><div class="alert alert-danger" role="alert"><?php echo $h($errore); ?></div><?php endif; ?>
    <form method="POST" class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
        <?php csrf_field(); ?>
        <div class="mb-3"><label class="form-label fw-bold" for="email">Email della scuola *</label><input type="email" class="form-control" id="email" name="email" maxlength="255" required value="<?php echo $h($_POST['email'] ?? ''); ?>"></div>
        <div class="mb-3" style="position:absolute;left:-9999px;" aria-hidden="true"><label for="sito_web">Non compilare questo campo</label><input id="sito_web" name="sito_web" tabindex="-1" autocomplete="off"></div>
        <div class="mb-3"><label class="form-label fw-bold" for="captcha_risposta"><?php echo $h($cap['domanda']); ?></label><input class="form-control" style="max-width:140px;" id="captcha_risposta" name="captcha_risposta" inputmode="numeric" pattern="[0-9]*" required autocomplete="off"><input type="hidden" name="captcha_id" value="<?php echo $h($cap['id']); ?>"></div>
        <button type="submit" class="btn btn-danger fw-bold"><i class="fa fa-paper-plane me-1" aria-hidden="true"></i>Rimandami il link</button>
    </div></form>
    <?php endif; ?>
</div>
<?php require_once 'footer.php'; ?>
