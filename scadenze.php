<?php
// scadenze.php - «Scadenze e date utili»: le date da ricordare delle comunicazioni (campo «Data da ricordare») e le scadenze dei moduli online della didattica.
require_once 'config.php';
require_once 'functions.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$Cost = \App\Assistenza\Costanti::class;
$app = \App\Core\App::per($conn);
$per = isset($Cost::PUBBLICI[$_GET['per'] ?? '']) ? (string)$_GET['per'] : '';
$rep = $app->get(\App\Portale\ComunicazioniRepository::class);

$voci = [];
foreach ($rep->scadenze() as $c) {
    if ($rep->perPubblico($c, $per)) { $voci[] = ['data' => $c['data_utile'], 'titolo' => $c['titolo'], 'nota' => $c['sommario'] !== '' ? $c['sommario'] : '', 'url' => 'comunicazione.php?id=' . (int)$c['id'], 'tipo' => 'Notizia']; }
}
if ($per === '' || $per === 'studenti') {
    foreach ($app->get(\App\Core\Database::class)->righe("SELECT id, titolo, categoria, aperto_al FROM didattica_moduli WHERE attivo = 1 AND tipo = 'online' AND aperto_al IS NOT NULL AND aperto_al >= CURDATE() AND (aperto_dal IS NULL OR aperto_dal <= CURDATE()) ORDER BY aperto_al, titolo") as $m) {
        $voci[] = ['data' => $m['aperto_al'], 'titolo' => $m['titolo'], 'nota' => 'Il modulo online chiude questo giorno', 'url' => 'modulo.php?id=' . (int)$m['id'], 'tipo' => (string)$m['categoria']];
    }
}
usort($voci, fn($a, $b) => strcmp((string)$a['data'], (string)$b['data']) ?: strcmp($a['titolo'], $b['titolo']));

$page_cfg['titolo'] = 'Scadenze e date utili';
require_once 'header.php';
?>
<div class="container my-4" style="max-width: 860px;">
    <nav aria-label="Percorso" class="mb-3 small"><a href="index.php">Home</a> <span class="text-secondary mx-1">/</span> Scadenze e date utili</nav>
    <h1 class="fw-bold mb-1">Scadenze e date utili</h1>
    <p class="text-secondary">Le date da non perdere, dalla più vicina. Si aggiornano da sole: una scadenza passata sparisce.</p>
    <nav class="d-flex flex-wrap gap-2 mb-4" aria-label="A chi ti rivolgi"><span class="small text-secondary align-self-center me-1">Per:</span>
        <?php foreach (['' => 'Tutti'] + $Cost::PUBBLICI as $k_p => $n_p): ?><a class="btn btn-sm <?php echo $per === $k_p ? 'btn-danger' : 'btn-outline-secondary'; ?>" href="<?php echo $h('scadenze.php' . ($k_p !== '' ? '?per=' . $k_p : '')); ?>"<?php echo $per === $k_p ? ' aria-current="true"' : ''; ?>><?php echo $h($n_p); ?></a><?php endforeach; ?></nav>
    <?php if (!$voci): ?><div class="alert alert-light border">Non ci sono scadenze in programma.</div><?php endif; ?>
    <ul class="list-unstyled m-0">
    <?php foreach ($voci as $v): $gg = (int)floor((strtotime((string)$v['data']) - strtotime(date('Y-m-d'))) / 86400); ?>
        <li class="d-flex gap-3 align-items-start p-3 mb-2 bg-white border rounded-3" style="border-left:4px solid <?php echo $gg <= 7 ? '#dc2626' : '#047857'; ?> !important;">
            <div class="text-center flex-shrink-0" style="min-width:64px;"><div class="fw-bold fs-4 lh-1"><?php echo $h(date('d', strtotime((string)$v['data']))); ?></div><div class="small text-uppercase text-secondary"><?php echo $h(['01'=>'gen','02'=>'feb','03'=>'mar','04'=>'apr','05'=>'mag','06'=>'giu','07'=>'lug','08'=>'ago','09'=>'set','10'=>'ott','11'=>'nov','12'=>'dic'][date('m', strtotime((string)$v['data']))]); ?> <?php echo $h(date('Y', strtotime((string)$v['data']))); ?></div></div>
            <div><a class="fw-bold" href="<?php echo $h($v['url']); ?>"><?php echo $h($v['titolo']); ?></a>
                <div class="small text-secondary"><?php echo $h($v['tipo']); ?><?php echo $v['nota'] !== '' ? ' · ' . $h($v['nota']) : ''; ?></div>
                <div class="small <?php echo $gg <= 7 ? 'text-danger fw-bold' : 'text-secondary'; ?>"><?php echo $gg === 0 ? 'Oggi' : ($gg === 1 ? 'Domani' : 'Tra ' . $gg . ' giorni'); ?></div></div>
        </li>
    <?php endforeach; ?>
    </ul>
</div>
<?php require_once 'footer.php'; ?>
