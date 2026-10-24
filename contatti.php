<?php
// contatti.php - «Chi contattare per cosa»: i recapiti istituzionali che il Dipartimento mostra agli studenti (si scrivono da admin/contatti.php).
require_once 'config.php';
require_once 'functions.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$contatti = \App\Core\App::per($conn)->get(\App\Portale\ContattiRepository::class)->visibili();
$page_cfg['titolo'] = 'Contatti';
require_once 'header.php';
?>
<style>
.ct-card { border: 1px solid #e2e8f0; border-radius: 14px; background: #fff; padding: 18px 20px; height: 100%; }
.ct-card h2 { font-size: 1.15rem; font-weight: 700; margin: 0 0 4px; }
.ct-riga { display: flex; gap: 10px; align-items: baseline; margin-top: 6px; font-size: .98rem; }
.ct-riga .fa { width: 18px; text-align: center; color: #990000; flex-shrink: 0; }
</style>
<div class="container my-4" style="max-width: 960px;">
    <nav aria-label="Percorso" class="mb-3 small"><a href="index.php">Home</a> <span class="text-secondary mx-1">/</span> Contatti</nav>
    <h1 class="fw-bold mb-1">Chi contattare per cosa</h1>
    <p class="text-secondary">Scegli l'ufficio giusto per la tua richiesta. Prima di scrivere, controlla se la risposta è già nelle <a href="domande_frequenti.php">domande frequenti</a>: è più veloce.</p>

    <?php if (!$contatti): ?>
        <div class="alert alert-light border">I contatti sono in preparazione. Nel frattempo puoi <a href="assistenza.php">scrivere all'assistenza</a>.</div>
    <?php else: ?>
    <div class="row g-3">
        <?php foreach ($contatti as $c): $esterno = preg_match('#^https?://#i', (string)$c['link_url']) === 1; ?>
        <div class="col-md-6"><div class="ct-card">
            <h2><?php echo $h($c['titolo']); ?></h2>
            <?php if ($c['per_cosa'] !== ''): ?><div class="text-secondary"><?php echo $h($c['per_cosa']); ?></div><?php endif; ?>
            <?php if ($c['luogo'] !== ''): ?><div class="ct-riga"><i class="fa fa-location-dot" aria-hidden="true"></i><span><?php echo $h($c['luogo']); ?></span></div><?php endif; ?>
            <?php if ($c['orari'] !== ''): ?><div class="ct-riga"><i class="fa fa-clock" aria-hidden="true"></i><span><?php echo $h($c['orari']); ?></span></div><?php endif; ?>
            <?php if ($c['telefono'] !== ''): ?><div class="ct-riga"><i class="fa fa-phone" aria-hidden="true"></i><a href="tel:<?php echo $h(preg_replace('/[^0-9+]/', '', (string)$c['telefono'])); ?>"><?php echo $h($c['telefono']); ?></a></div><?php endif; ?>
            <?php if ($c['email'] !== ''): ?><div class="ct-riga"><i class="fa fa-envelope" aria-hidden="true"></i><a href="mailto:<?php echo $h($c['email']); ?>"><?php echo $h($c['email']); ?></a></div><?php endif; ?>
            <?php if ($c['link_url'] !== ''): ?><div class="ct-riga"><i class="fa fa-arrow-right" aria-hidden="true"></i><a href="<?php echo $h($c['link_url']); ?>"<?php echo $esterno ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>><?php echo $h($c['link_etichetta'] !== '' ? $c['link_etichetta'] : $c['link_url']); ?></a></div><?php endif; ?>
        </div></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm mt-4" style="border-radius:14px;background:#f8fafc;"><div class="card-body d-flex flex-wrap align-items-center gap-3">
        <i class="fa fa-headset fs-3 text-danger" aria-hidden="true"></i>
        <div class="flex-grow-1"><strong>Preferisci scrivere subito?</strong><div class="small text-secondary">L'assistenza risponde in chat e, se serve, passa la richiesta alla persona giusta.</div></div>
        <a class="btn btn-danger fw-bold" href="assistenza.php">Chiedi all'assistenza</a>
    </div></div>
</div>
<?php require_once 'footer.php'; ?>
