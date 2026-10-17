<?php
// aziende_convenzionate_tesi.php - Elenco pubblico degli enti e delle aziende convenzionati con il Dipartimento per lo svolgimento delle tesi: solo convenzioni
// stipulate, valide oggi e con il consenso dell'azienda a comparire. Ricerca per nome, comune o settore. Logica in src/ConvenzioniTesi/.
require_once 'config.php';
require_once 'functions.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$svc = \App\Core\App::per($conn)->get(\App\ConvenzioniTesi\ServizioConvenzioniTesi::class);
$cerca = trim((string)($_GET['q'] ?? ''));
$tutte = array_map(function (array $c) use ($svc) { $d = $svc->dati($c); return ['nome' => (string)$c['azienda'], 'comune' => (string)($d['comune'] ?? ''), 'prov' => (string)($d['provincia'] ?? ''), 'settore' => (string)($d['settore'] ?? ''), 'sito' => (string)($d['sito'] ?? ''), 'al' => (string)$c['valida_al']]; }, $svc->elencoPubblico());
$elenco = $cerca === '' ? $tutte : array_values(array_filter($tutte, fn($a) => mb_stripos($a['nome'] . ' ' . $a['comune'] . ' ' . $a['settore'], $cerca) !== false));
$page_cfg['titolo'] = 'Aziende convenzionate per la tesi';
require_once 'header.php';
?>
<div class="container my-4" style="max-width: 960px;">
    <nav aria-label="Percorso" class="mb-3 small"><a href="index.php">Home</a> <span class="text-secondary mx-1">/</span> Aziende convenzionate per la tesi</nav>
    <h1 class="fw-bold mb-1">Aziende convenzionate per la tesi</h1>
    <p class="text-secondary">Enti e aziende che hanno una convenzione con il Dipartimento di Biologia, Ecologia e Scienze della Terra per accogliere studenti che preparano la tesi. Per svolgere la tesi in un'altra azienda serve prima la convenzione: <a href="richiedi_convenzione_tesi.php">può richiederla online</a>.</p>
    <form method="GET" class="d-flex gap-2 mb-3" role="search"><label class="visually-hidden" for="q">Cerca</label><input class="form-control" id="q" name="q" value="<?php echo $h($cerca); ?>" placeholder="Cerca per nome, comune o settore"><button class="btn btn-danger" type="submit"><i class="fa fa-search" aria-hidden="true"></i><span class="visually-hidden"> Cerca</span></button></form>
    <?php if (!$elenco): ?>
        <p class="text-muted"><?php echo $cerca !== '' ? 'Nessuna azienda corrisponde alla ricerca.' : 'L\'elenco è in preparazione.'; ?></p>
    <?php else: ?>
    <p class="small text-secondary"><?php echo count($elenco); ?> <?php echo count($elenco) === 1 ? 'azienda' : 'aziende'; ?></p>
    <div class="row g-3">
        <?php foreach ($elenco as $a): ?>
        <div class="col-md-6"><div class="card border-0 shadow-sm h-100" style="border-radius:12px;"><div class="card-body">
            <h2 class="h6 fw-bold mb-1"><?php echo $h($a['nome']); ?></h2>
            <?php if ($a['comune'] !== ''): ?><div class="small"><i class="fa fa-location-dot me-1 text-secondary" aria-hidden="true"></i><?php echo $h($a['comune'] . ($a['prov'] !== '' ? ' (' . $a['prov'] . ')' : '')); ?></div><?php endif; ?>
            <?php if ($a['settore'] !== ''): ?><div class="small"><i class="fa fa-flask me-1 text-secondary" aria-hidden="true"></i><?php echo $h($a['settore']); ?></div><?php endif; ?>
            <?php if ($a['sito'] !== ''): ?><div class="small"><i class="fa fa-globe me-1 text-secondary" aria-hidden="true"></i><a href="<?php echo $h($a['sito']); ?>" target="_blank" rel="noopener noreferrer"><?php echo $h(preg_replace('#^https?://(www\.)?#', '', rtrim($a['sito'], '/'))); ?></a></div><?php endif; ?>
            <div class="small text-secondary mt-2">Convenzione valida fino al <?php echo $h(date('d/m/Y', strtotime($a['al']))); ?></div>
        </div></div></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php require_once 'footer.php'; ?>
