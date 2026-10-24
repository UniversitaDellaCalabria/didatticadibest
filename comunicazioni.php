<?php
// comunicazioni.php - Le notizie del Dipartimento (si scrivono da admin/comunicazioni.php): elenco con filtro per pubblico e ricerca; sotto, le pagine informative.
require_once 'config.php';
require_once 'functions.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$Cost = \App\Assistenza\Costanti::class;
$rep = \App\Core\App::per($conn)->get(\App\Portale\ComunicazioniRepository::class);
$per = isset($Cost::PUBBLICI[$_GET['per'] ?? '']) ? (string)$_GET['per'] : '';
$cerca = trim((string)($_GET['q'] ?? ''));
$notizie = $rep->notizie($per, $cerca);
$pagine = $cerca === '' ? array_values(array_filter($rep->pagine(), fn($p) => $rep->perPubblico($p, $per))) : [];

$page_cfg['titolo'] = 'Notizie e comunicazioni';
require_once 'header.php';
?>
<style>
.nz-card { display: block; border: 1px solid #e2e8f0; border-radius: 14px; background: #fff; padding: 16px 20px; margin-bottom: 12px; color: inherit; text-decoration: none; transition: box-shadow .15s, border-color .15s; }
.nz-card:hover, .nz-card:focus-visible { border-color: #990000; box-shadow: 0 6px 18px rgba(0,0,0,.08); color: inherit; }
.nz-card h2 { font-size: 1.2rem; font-weight: 700; margin: 2px 0 4px; color: #1f2937; }
.nz-data { font-size: .85rem; color: #546072; }
</style>
<div class="container my-4" style="max-width: 860px;">
    <nav aria-label="Percorso" class="mb-3 small"><a href="index.php">Home</a> <span class="text-secondary mx-1">/</span> Notizie</nav>
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-2 mb-1"><h1 class="fw-bold mb-0">Notizie e comunicazioni</h1>
        <span class="small"><a href="scadenze.php"><i class="fa fa-hourglass-half me-1" aria-hidden="true"></i>Scadenze e date utili</a> · <a href="comunicazioni_feed.php"><i class="fa fa-rss me-1" aria-hidden="true"></i>Feed RSS</a></span></div>
    <p class="text-secondary">Le comunicazioni del Dipartimento per studenti, scuole, aziende e docenti.</p>
    <nav class="d-flex flex-wrap gap-2 mb-3" aria-label="A chi ti rivolgi"><span class="small text-secondary align-self-center me-1">Per:</span>
        <?php foreach (['' => 'Tutti'] + $Cost::PUBBLICI as $k_p => $n_p): ?><a class="btn btn-sm <?php echo $per === $k_p ? 'btn-danger' : 'btn-outline-secondary'; ?>" href="<?php echo $h('comunicazioni.php' . ($k_p !== '' ? '?per=' . $k_p : '')); ?>"<?php echo $per === $k_p ? ' aria-current="true"' : ''; ?>><?php echo $h($n_p); ?></a><?php endforeach; ?></nav>
    <form method="GET" class="d-flex gap-2 mb-4" role="search"><?php if ($per !== ''): ?><input type="hidden" name="per" value="<?php echo $h($per); ?>"><?php endif; ?>
        <label class="visually-hidden" for="nq">Cerca nelle notizie</label><input class="form-control" id="nq" name="q" value="<?php echo $h($cerca); ?>" placeholder="Cerca nelle notizie" autocomplete="off">
        <button class="btn btn-danger px-4" type="submit"><i class="fa fa-search" aria-hidden="true"></i><span class="visually-hidden"> Cerca</span></button></form>

    <?php if (!$notizie): ?>
        <div class="alert alert-light border"><?php echo $cerca !== '' ? 'Nessuna notizia corrisponde alla ricerca.' : 'Non ci sono notizie da mostrare.'; ?></div>
    <?php endif; foreach ($notizie as $n): ?>
        <a class="nz-card" href="comunicazione.php?id=<?php echo (int)$n['id']; ?>">
            <div class="nz-data"><?php echo $n['data_pubblicazione'] ? $h(date('d/m/Y', strtotime((string)$n['data_pubblicazione']))) : ''; ?>
                <?php if ((int)$n['in_evidenza']): ?><span class="badge bg-danger ms-1">In evidenza</span><?php endif; ?>
                <?php foreach ($Cost::pubblici((string)$n['pubblico']) as $p_b): ?><span class="badge bg-light text-dark border fw-normal"><?php echo $h($Cost::PUBBLICI[$p_b]); ?></span> <?php endforeach; ?></div>
            <h2><?php echo $h($n['titolo']); ?></h2>
            <div class="text-secondary"><?php echo $h($n['sommario'] !== '' ? $n['sommario'] : \App\Portale\TestoSemplice::estratto((string)$n['testo'])); ?></div>
            <?php if ($n['data_utile'] && $n['data_utile'] >= date('Y-m-d')): ?><div class="small mt-1 fw-bold text-danger"><i class="fa fa-clock me-1" aria-hidden="true"></i>Da ricordare: <?php echo $h(date('d/m/Y', strtotime((string)$n['data_utile']))); ?></div><?php endif; ?>
        </a>
    <?php endforeach; ?>

    <?php if ($pagine): ?>
        <h2 class="h5 fw-bold mt-4 mb-2">Pagine informative</h2>
        <ul class="list-unstyled"><?php foreach ($pagine as $p): ?><li class="mb-1"><a href="comunicazione.php?id=<?php echo (int)$p['id']; ?>"><i class="fa fa-file-lines me-1" aria-hidden="true"></i><?php echo $h($p['titolo']); ?></a></li><?php endforeach; ?></ul>
    <?php endif; ?>
</div>
<?php require_once 'footer.php'; ?>
