<?php
// riepilogo_persone.php - Riepilogo settimanale «Da fare per te»: dal link in fondo all'email si smette di riceverlo (o si riattiva), senza accedere.
// Il link è personale (?t=…). Logica in src/Portale/RiepilogoPersone.php.
require_once 'config.php';
require_once 'functions.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$svc = \App\Core\App::get(\App\Portale\RiepilogoPersone::class);
$tok = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ''));
$r = $svc->perToken($tok);
if ($r && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $ricevi = isset($_POST['riattiva']);
    $svc->imposta($tok, $ricevi);
    flash_set($ricevi ? 'Riepilogo riattivato: lo riceverai ogni settimana quando c\'è qualcosa da fare.' : 'Fatto: non riceverai più il riepilogo settimanale.', 'success');
    header('Location: riepilogo_persone.php?t=' . urlencode($tok)); exit;
}

$page_cfg['titolo'] = 'Riepilogo settimanale';
require_once 'header.php';
?>
<div class="container my-4" style="max-width: 640px;">
    <?php echo flash_html(); ?>
    <h1 class="fw-bold h3 mb-2">Riepilogo settimanale «Da fare per te»</h1>
    <?php if (!$r): ?>
        <div class="alert alert-warning">Il link non è valido. Usa quello in fondo all'ultima email che hai ricevuto.</div>
    <?php else: $escluso = (int)$r['escluso'] === 1; ?>
        <p class="text-secondary">Una volta a settimana, solo quando c'è qualcosa che ti aspetta (conferme di attestati, firme, registri del tutorato, schede, verbali, pratiche…), ti mandiamo un'email con l'elenco e i link per farlo.</p>
        <form method="POST" class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
            <?php csrf_field(); ?>
            <p class="mb-3"><i class="fa fa-envelope me-1 text-primary" aria-hidden="true"></i><strong><?php echo $h($r['email']); ?></strong>
                <?php echo $escluso ? '<span class="badge bg-secondary ms-1">non lo ricevi</span>' : '<span class="badge bg-success ms-1">lo ricevi</span>'; ?></p>
            <?php if ($escluso): ?>
                <button type="submit" name="riattiva" value="1" class="btn btn-primary fw-bold"><i class="fa fa-bell me-1" aria-hidden="true"></i>Riattiva il riepilogo</button>
            <?php else: ?>
                <button type="submit" name="disattiva" value="1" class="btn btn-outline-danger fw-bold"><i class="fa fa-bell-slash me-1" aria-hidden="true"></i>Non voglio più riceverlo</button>
            <?php endif; ?>
            <div class="form-text mt-2">Le cose da fare restano comunque nella tua <a href="area_personale.php">Area personale</a>, alla voce «Da fare per te».</div>
        </div></form>
    <?php endif; ?>
</div>
<?php require_once 'footer.php'; ?>
