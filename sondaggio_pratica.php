<?php
// sondaggio_pratica.php - Sondaggio facoltativo a fine pratica (due domande): si apre dal link personale nell'email di conclusione o dalla pagina della pratica.
require_once 'config.php';
require_once 'functions.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$svc = \App\Core\App::per($conn)->get(\App\Didattica\SondaggioPratica::class);
$token = strtolower(trim((string)($_GET['t'] ?? $_POST['t'] ?? '')));
$dati = $svc->perToken($token);
$fatto = false;

if ($dati && !$dati['compilato'] && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    if (!check_rate_limit($conn, 'sondaggio_pratica', 20, 300)) {
        flash_set('Troppi tentativi: riprova tra qualche minuto.', 'warning');
    } else {
        $err = $svc->rispondi($token, (int)($_POST['voto_servizio'] ?? 0), (int)($_POST['voto_tempi'] ?? 0), (string)($_POST['commento'] ?? ''));
        if ($err === null) { $fatto = true; $dati['compilato'] = true; } else flash_set($err, 'warning');
    }
}

$page_cfg['titolo'] = 'Com\'è andata la tua pratica?';
require_once 'header.php';
$scale = ['1' => 'Per niente', '2' => 'Poco', '3' => 'Abbastanza', '4' => 'Bene', '5' => 'Molto bene'];
?>
<div class="container my-5" style="max-width: 640px;">
    <?php echo flash_html(); ?>
    <?php if (!$dati): ?>
        <div class="alert alert-danger"><i class="fa fa-circle-xmark me-1" aria-hidden="true"></i>Il link non è valido, oppure la pratica non è ancora conclusa.</div>
    <?php elseif ($dati['compilato']): ?>
        <div class="alert alert-success"><i class="fa fa-circle-check me-1" aria-hidden="true"></i><?php echo $fatto ? '<strong>Grazie!</strong> Le tue risposte ci aiutano a migliorare il servizio.' : 'Hai già risposto a questo sondaggio: grazie!'; ?></div>
        <a href="area_personale.php" class="btn btn-outline-secondary btn-sm">Torna alla tua area</a>
    <?php else: ?>
        <h1 class="h3 fw-bold">Com'è andata la tua pratica?</h1>
        <p class="text-secondary">Pratica «<?php echo $h($dati['modulo']); ?>» conclusa. Due domande, un minuto: <strong>è facoltativo</strong> e non lega le risposte al tuo nome.</p>
        <form method="POST" class="card border-0 shadow-sm"><div class="card-body">
            <?php csrf_field(); ?><input type="hidden" name="t" value="<?php echo $h($token); ?>">
            <?php foreach ([['voto_servizio', '1. Quanto sei soddisfatto del servizio nel complesso?'], ['voto_tempi', '2. Le informazioni erano chiare e i tempi ragionevoli?']] as [$n, $dom]): ?>
                <fieldset class="mb-4"><legend class="h6 fw-bold"><?php echo $h($dom); ?></legend>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($scale as $v => $et): ?>
                            <div><input class="btn-check" type="radio" name="<?php echo $n; ?>" id="<?php echo $n . $v; ?>" value="<?php echo $v; ?>" required>
                                <label class="btn btn-outline-primary" for="<?php echo $n . $v; ?>"><strong><?php echo $v; ?></strong> <span class="small"><?php echo $et; ?></span></label></div>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
            <?php endforeach; ?>
            <label class="form-label fw-bold" for="snCom">Vuoi aggiungere qualcosa? <span class="fw-normal text-secondary">(facoltativo)</span></label>
            <textarea class="form-control mb-3" id="snCom" name="commento" rows="3" maxlength="1000"></textarea>
            <button class="btn btn-primary fw-bold" type="submit"><i class="fa fa-paper-plane me-1" aria-hidden="true"></i>Invia</button>
        </div></form>
    <?php endif; ?>
</div>
<?php require_once 'footer.php'; ?>
