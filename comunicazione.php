<?php
// comunicazione.php - Una notizia o una pagina informativa (?id=). Chi può gestire il portale vede anche le bozze (?id=…) con un avviso.
require_once 'config.php';
require_once 'functions.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$Cost = \App\Assistenza\Costanti::class;
$rep = \App\Core\App::per($conn)->get(\App\Portale\ComunicazioniRepository::class);
$id = (int)($_GET['id'] ?? 0);
$c = $rep->visibilePerId($id);
$anteprima = false;
if (!$c && !empty($_GET['anteprima']) && !empty($_SESSION['utente_id'])) {
    // Bozza o non ancora/più valida: la vede solo l'amministratore
    $u_an = \App\Core\App::per($conn)->get(\App\Auth\UtenteRepository::class)->perId((int)$_SESSION['utente_id']);
    if ($u_an && ((int)$u_an['ruolo_id'] === 1 || in_array('1', array_filter(explode(',', (string)($u_an['ruoli_secondari'] ?? ''))), true))) {
        $c = $rep->perId($id);
        $anteprima = (bool)$c;
    }
}
if (!$c) {
    http_response_code(404);
    $page_cfg['titolo'] = 'Pagina non trovata';
    require_once 'header.php';
    echo '<div class="container my-5" style="max-width:800px;"><h1 class="fw-bold">Pagina non trovata</h1><p>La comunicazione cercata non esiste o non è più valida.</p><p><a class="btn btn-outline-danger" href="comunicazioni.php">Tutte le notizie</a></p></div>';
    require_once 'footer.php';
    exit;
}
$link = \App\Assistenza\Didacticus\VociRepository::link($c);
$alleg = \App\Assistenza\Didacticus\VociRepository::allegati($c);

$page_cfg['titolo'] = (string)$c['titolo'];
require_once 'header.php';
?>
<style>.cm-testo { line-height: 1.7; font-size: 1.05rem; } .cm-testo p { margin-bottom: 1rem; } .cm-testo ul { margin-bottom: 1rem; }</style>
<div class="container my-4" style="max-width: 780px;">
    <nav aria-label="Percorso" class="mb-3 small"><a href="index.php">Home</a> <span class="text-secondary mx-1">/</span> <a href="comunicazioni.php">Notizie</a> <span class="text-secondary mx-1">/</span> <?php echo $h($c['titolo']); ?></nav>
    <?php if ($anteprima): ?><div class="alert alert-warning py-2"><i class="fa fa-eye me-1" aria-hidden="true"></i>Anteprima: i visitatori non vedono ancora questa comunicazione (non è pubblicata, o la data non è arrivata, o non è più valida).</div><?php endif; ?>
    <article>
        <p class="small text-secondary mb-1"><?php echo $c['tipo'] === 'notizia' && $c['data_pubblicazione'] ? $h(date('d/m/Y', strtotime((string)$c['data_pubblicazione']))) : 'Pagina informativa'; ?>
            <?php foreach ($Cost::pubblici((string)$c['pubblico']) as $p_b): ?><span class="badge bg-light text-dark border fw-normal"><?php echo $h($Cost::PUBBLICI[$p_b]); ?></span> <?php endforeach; ?></p>
        <h1 class="fw-bold mb-3"><?php echo $h($c['titolo']); ?></h1>
        <?php if ($c['data_utile'] && $c['data_utile'] >= date('Y-m-d')): ?><div class="alert alert-danger py-2 fw-bold"><i class="fa fa-clock me-1" aria-hidden="true"></i>Da ricordare: <?php echo $h(date('d/m/Y', strtotime((string)$c['data_utile']))); ?></div><?php endif; ?>
        <div class="cm-testo"><?php echo \App\Portale\TestoSemplice::html((string)$c['testo']); ?></div>
        <?php if ($link || $alleg): ?><div class="d-flex flex-wrap gap-2 mt-3">
            <?php foreach ($link as $l): $est = preg_match('#^https?://#i', $l['u']) === 1; ?><a class="btn btn-outline-danger" href="<?php echo $h($l['u']); ?>"<?php echo $est ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>><i class="fa <?php echo $est ? 'fa-arrow-up-right-from-square' : 'fa-arrow-right'; ?> me-1" aria-hidden="true"></i><?php echo $h($l['e']); ?></a><?php endforeach; ?>
            <?php foreach ($alleg as $i => $a): ?><a class="btn btn-outline-secondary" href="comunicazione_file.php?c=<?php echo (int)$c['id']; ?>&amp;i=<?php echo (int)$i; ?><?php echo $anteprima ? '&amp;anteprima=1' : ''; ?>"><i class="fa fa-file-arrow-down me-1" aria-hidden="true"></i><?php echo $h($a['n']); ?></a><?php endforeach; ?></div><?php endif; ?>
    </article>
    <p class="mt-4"><a href="comunicazioni.php"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i>Tutte le notizie</a></p>
</div>
<?php require_once 'footer.php'; ?>
