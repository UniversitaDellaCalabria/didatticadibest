<?php
// ricerca.php - La ricerca unica del sito: eventi e progetti, notizie e pagine, guide «Come si fa», docenti, risposte di Didacticus, raggruppati per genere.
// Logica in src/Portale/RicercaGlobale.php.
require_once 'config.php';
require_once 'functions.php';

$page_cfg['titolo'] = "Ricerca";

require_once 'header.php';

$q_raw = trim($_GET['q'] ?? '');
$q_safe = htmlspecialchars($q_raw);
$gruppi = $q_raw !== '' ? \App\Core\App::per($conn)->get(\App\Portale\RicercaGlobale::class)->cerca($q_raw, !empty($_SESSION['utente_id'])) : [];
$totale = \App\Portale\RicercaGlobale::totale($gruppi);
$h = static fn ($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>

<div class="container my-5" style="max-width: 900px;">

    <div class="mb-4 border-bottom pb-4">
        <h1 class="fw-bold display-6" style="letter-spacing: -1px;"><i class="fa fa-search text-primary me-2" aria-hidden="true"></i>Cerca nel portale</h1>
        <form action="ricerca.php" method="GET" class="mt-3" role="search">
            <div class="input-group input-group-lg shadow-sm">
                <input type="search" name="q" class="form-control border-primary" placeholder="Eventi, notizie, guide, docenti, domande…" value="<?php echo $q_safe; ?>" aria-label="Cosa cerchi" required minlength="2">
                <button class="btn btn-primary fw-bold px-4" type="submit">Cerca</button>
            </div>
        </form>
        <?php if ($q_raw !== ''): ?>
            <p class="text-secondary mt-3 mb-0">Hai cercato <strong>«<?php echo $q_safe; ?>»</strong>: <strong><?php echo $totale; ?></strong> <?php echo $totale === 1 ? 'risultato' : 'risultati'; ?>.</p>
        <?php else: ?>
            <p class="text-secondary mt-3 mb-0">Una ricerca sola per gli eventi, le notizie, le guide, i docenti e le risposte di Didacticus.</p>
        <?php endif; ?>
    </div>

    <?php if ($q_raw !== '' && !$gruppi): ?>
        <?php echo ui_vuoto('fa-search-minus', 'Non abbiamo trovato niente', 'Nessun risultato per «' . $q_raw . '». Prova con parole più semplici o chiedi a Didacticus.', 'assistenza.php', 'Chiedi a Didacticus', '#0891b2'); ?>
    <?php elseif ($gruppi): ?>
        <div class="d-flex flex-wrap gap-2 mb-4" aria-label="Vai al gruppo">
            <?php foreach ($gruppi as $g): ?>
                <a href="#g-<?php echo $h($g['chiave']); ?>" class="ui-chip text-decoration-none"><i class="fa <?php echo $h($g['icona']); ?> me-1" style="color:<?php echo $h($g['colore']); ?>;" aria-hidden="true"></i><?php echo $h($g['titolo']); ?> (<?php echo count($g['voci']); ?>)</a>
            <?php endforeach; ?>
        </div>
        <?php foreach ($gruppi as $g): ?>
            <section class="mb-5" id="g-<?php echo $h($g['chiave']); ?>">
                <h2 class="h5 fw-bold mb-3"><i class="fa <?php echo $h($g['icona']); ?> me-2" style="color:<?php echo $h($g['colore']); ?>;" aria-hidden="true"></i><?php echo $h($g['titolo']); ?></h2>
                <div class="d-flex flex-column gap-2">
                    <?php foreach ($g['voci'] as $v): ?>
                        <div class="ui-tessera" style="--tc: <?php echo $h($g['colore']); ?>; height:auto;">
                            <div class="ui-tessera-corpo flex-grow-1">
                                <?php if ($v['risposta'] === ''): ?>
                                    <a href="<?php echo $h($v['link']); ?>" class="ui-tessera-titolo stretched-link"><?php echo $h($v['titolo']); ?></a>
                                <?php else: ?>
                                    <div class="ui-tessera-titolo"><?php echo $h($v['titolo']); ?></div>
                                <?php endif; ?>
                                <?php if ($v['meta'] !== ''): ?><div class="small text-secondary"><?php echo $h($v['meta']); ?></div><?php endif; ?>
                                <?php if ($v['testo'] !== ''): ?><div class="ui-tessera-testo"><?php echo $h($v['testo']); ?></div><?php endif; ?>
                                <?php if ($v['risposta'] !== ''): ?>
                                    <details class="mt-1" style="position:relative; z-index:2;"><summary class="small fw-bold" style="cursor:pointer;">Leggi la risposta</summary>
                                        <div class="small mt-2"><?php echo nl2br($h(\App\Portale\RicercaGlobale::estratto($v['risposta'], $q_raw, 700))); ?></div>
                                        <a href="assistenza.php" class="small fw-bold">Hai ancora un dubbio? Chiedi a Didacticus</a>
                                    </details>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>

</div>

<?php require_once 'footer.php'; ?>
