<?php
// =========================================================================
// Da fare per te: l'elenco completo di ciò che aspetta la persona, con filtro per gruppo. In area personale se ne vedono solo le prime righe.
// Logica in App\Portale\DaFarePersona; qui solo il disegno. Si arriva da area_personale.php e dal pulsante nella barra.
// =========================================================================
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/middleware.php';

$gruppi_df = [];
try {
    $gruppi_df = \App\Core\App::per($conn)->get(\App\Portale\DaFarePersona::class)->gruppi($user_info ?? null, 200);
} catch (\Throwable $e_df) { error_log('[Da fare] non disponibile: ' . $e_df->getMessage()); }
foreach ($gruppi_df as &$g_df) {
    usort($g_df['righe'], static fn (array $a, array $b): int => $b['giorni'] <=> $a['giorni']); // prima ciò che aspetta da più tempo
}
unset($g_df);
$filtro_df = (string)($_GET['g'] ?? '');
$visibili_df = $filtro_df === '' ? $gruppi_df : array_values(array_filter($gruppi_df, fn($g) => $g['chiave'] === $filtro_df));
$totale_df = \App\Portale\DaFarePersona::totale($gruppi_df);

$page_cfg['titolo'] = 'Da fare per te';
require_once 'header.php';
echo ui_tema_script();
?>

<div class="container my-4" style="max-width: 900px;">
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
        <?php echo ui_intestazione('Da fare per te', 'area_personale.php', 'Area personale', $totale_df > 0 ? $totale_df . ($totale_df === 1 ? ' cosa aspetta te' : ' cose aspettano te') : ''); ?>
        <div class="d-print-none"><?php echo ui_tema_pulsante(); ?></div>
    </div>
    <?php if (!$gruppi_df): ?>
        <?php echo ui_vuoto('fa-circle-check', 'Non hai niente da fare', 'Quando qualcosa aspetta te (una firma, una conferma, un documento da caricare) compare qui, con il link per farla.', 'area_personale.php', 'Torna all\'area personale', '#0f766e'); ?>
    <?php else: ?>
        <div class="d-flex flex-wrap gap-2 mb-3 d-print-none" aria-label="Filtra per gruppo">
            <a href="da_fare.php" class="btn btn-sm <?php echo $filtro_df === '' ? 'btn-danger' : 'btn-outline-danger'; ?> fw-bold">Tutto <span class="ui-badge-rosso ms-1"><?php echo $totale_df; ?></span></a>
            <?php foreach ($gruppi_df as $g_df): ?>
                <a href="da_fare.php?g=<?php echo urlencode((string)$g_df['chiave']); ?>" class="btn btn-sm <?php echo $filtro_df === $g_df['chiave'] ? 'btn-danger' : 'btn-outline-danger'; ?> fw-bold"><?php echo htmlspecialchars($g_df['titolo']); ?> <span class="ui-badge-rosso ms-1"><?php echo (int)$g_df['n']; ?></span></a>
            <?php endforeach; ?>
        </div>
        <?php foreach ($visibili_df as $g_df): ?>
            <div class="card border-0 shadow-sm mb-3" id="df-<?php echo htmlspecialchars((string)$g_df['chiave']); ?>" style="border-radius: 12px; border-left: 5px solid <?php echo htmlspecialchars($g_df['colore']); ?> !important;"><div class="card-body">
                <h2 class="h6 fw-bold mb-2"><i class="fa <?php echo htmlspecialchars($g_df['icona']); ?> me-1" style="color:<?php echo htmlspecialchars($g_df['colore']); ?>;" aria-hidden="true"></i><?php echo htmlspecialchars($g_df['titolo']); ?> <span class="badge text-bg-secondary"><?php echo (int)$g_df['n']; ?></span></h2>
                <?php foreach ($g_df['righe'] as $r_df): ?>
                    <div class="d-flex align-items-center justify-content-between gap-2 py-2 border-top">
                        <div>
                            <span class="fw-semibold"><?php echo htmlspecialchars($r_df['testo']); ?></span><?php if ($r_df['giorni'] >= \App\Portale\DaFarePersona::GIORNI_FERMA): ?> <span class="badge text-bg-danger"><?php echo (int)$r_df['giorni']; ?> giorni</span><?php endif; ?>
                            <span class="d-block small text-secondary"><?php echo htmlspecialchars($r_df['dettaglio']); ?></span>
                        </div>
                        <a class="btn btn-sm btn-outline-secondary text-nowrap" href="<?php echo htmlspecialchars($r_df['link']); ?>">Apri</a>
                    </div>
                <?php endforeach; ?>
                <?php if ((int)$g_df['n'] > count($g_df['righe'])): ?><div class="small text-secondary pt-2 border-top">Sono mostrate le prime <?php echo count($g_df['righe']); ?> su <?php echo (int)$g_df['n']; ?>: chiudi queste per vedere le altre.</div><?php endif; ?>
            </div></div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>
