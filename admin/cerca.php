<?php
// cerca.php - «Cerca ovunque»: una sola casella (nella barra in alto del pannello) per trovare pratiche, persone, scuole, verbali,
// aziende con convenzione di tesi e risorse. Ogni gruppo compare solo se si ha il permesso per quella parte del pannello.
require_once 'admin_header.php';

$h = static fn ($t): string => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$servizio_cerca = \App\Core\App::per($conn)->get(\App\Portale\RicercaPannello::class);
$permessi_cerca = [
    'pratiche'         => $puo_didattica_tutto,
    'persone'          => $is_full_admin || $puo_didattica || $puo_fsl,
    'verbali'          => $puo_didattica_tutto,
    'convenzioni_tesi' => $puo_conv_tesi,
    'scuole'           => $puo_fsl_scuole,
    'risorse'          => $is_full_admin ? true : array_map('intval', array_column($pagine_disponibili, 'id')),
];
$parole_cerca = \App\Portale\RicercaPannello::parole($q);
$gruppi_cerca = $parole_cerca ? $servizio_cerca->cerca($q, $permessi_cerca, (int)$filtro_p) : [];
?>
<?php echo pn_testata('Cerca ovunque', 'fa-magnifying-glass', 'Una sola ricerca per pratiche, persone, scuole, verbali, aziende e risorse: vedi solo ciò che il tuo ruolo può aprire.'); ?>

<form method="GET" action="cerca.php" class="d-flex gap-2 mb-4" role="search">
    <input type="hidden" name="p_id" value="<?php echo (int)$filtro_p; ?>">
    <input class="form-control" type="search" name="q" value="<?php echo $h($q); ?>" maxlength="100" placeholder="Nome, cognome, codice pratica, matricola, azienda, scuola…" aria-label="Cerca" autofocus>
    <button class="btn btn-primary fw-bold" type="submit"><i class="fa fa-magnifying-glass me-1" aria-hidden="true"></i>Cerca</button>
</form>

<?php if ($q === '' || !$parole_cerca): ?>
    <p class="text-secondary"><?php echo $q === '' ? 'Scrivi una o più parole (almeno due lettere ciascuna): tutte devono comparire nel risultato.' : 'Scrivi almeno due lettere.'; ?></p>
<?php elseif (!$gruppi_cerca): ?>
    <div class="alert alert-light border"><i class="fa fa-circle-info me-1" aria-hidden="true"></i>Nessun risultato per «<strong><?php echo $h($q); ?></strong>» in ciò che puoi consultare.</div>
<?php else: foreach ($gruppi_cerca as $g): ?>
    <section class="card border-0 shadow-sm mb-3" aria-labelledby="gr_<?php echo $h($g['id']); ?>">
        <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
            <span id="gr_<?php echo $h($g['id']); ?>"><i class="fa <?php echo $h($g['icona']); ?> me-2 text-secondary" aria-hidden="true"></i><?php echo $h($g['titolo']); ?></span>
            <span class="badge bg-secondary"><?php echo (int)$g['totale']; ?></span>
        </div>
        <div class="list-group list-group-flush">
            <?php foreach ($g['voci'] as $v): ?>
                <a class="list-group-item list-group-item-action py-2" href="<?php echo $h($v['url']); ?>">
                    <div class="fw-bold"><?php echo $h($v['titolo']); ?></div>
                    <?php if ($v['sotto'] !== ''): ?><div class="small text-secondary"><?php echo $h($v['sotto']); ?></div><?php endif; ?>
                </a>
            <?php endforeach; ?>
            <?php if ((int)$g['totale'] > count($g['voci'])): ?>
                <div class="list-group-item small text-secondary">Altri <?php echo (int)$g['totale'] - count($g['voci']); ?> risultati: restringi la ricerca aggiungendo una parola.</div>
            <?php endif; ?>
        </div>
    </section>
<?php endforeach; endif; ?>

<?php require_once 'admin_footer.php'; ?>
