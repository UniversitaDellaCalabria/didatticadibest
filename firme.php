<?php
// =========================================================================
// Da firmare: la scrivania delle firme. Tutti i documenti che aspettano la firma della persona collegata (pratiche, convenzioni FSL e di tesi, lettere
// di tutorato, verbali) in un elenco solo, con l'anteprima del PDF a fianco. Le pagine di firma di ogni modulo restano: «Firma» porta lì.
// Logica in App\Firme\ScrivaniaFirme; qui solo il disegno. Si arriva da area_personale.php, dal Cruscotto del Direttore e dalla barra.
// =========================================================================
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/middleware.php';

$h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$scrivania_fi = null;
$documenti_fi = [];
try {
    $scrivania_fi = \App\Core\App::per($conn)->get(\App\Firme\ScrivaniaFirme::class);
    $documenti_fi = $scrivania_fi->documenti($user_info ?? null);
} catch (\Throwable $e_fi) { error_log('[Firme] non disponibili: ' . $e_fi->getMessage()); }

// Filtri per tipo di documento, con il loro conteggio
$etichette_fi = [];
$conteggi_fi = [];
foreach ($scrivania_fi ? $scrivania_fi->fonti() : [] as $f_fi) { $etichette_fi[$f_fi->tipo()] = $f_fi->etichetta(); }
foreach ($documenti_fi as $d_fi) { $conteggi_fi[$d_fi->tipo] = ($conteggi_fi[$d_fi->tipo] ?? 0) + 1; }
$filtro_fi = (string) ($_GET['tipo'] ?? '');
if (!isset($conteggi_fi[$filtro_fi])) $filtro_fi = '';
$visibili_fi = $filtro_fi === '' ? $documenti_fi : array_values(array_filter($documenti_fi, static fn ($d): bool => $d->tipo === $filtro_fi));
$totale_fi = count($documenti_fi);

$page_cfg['titolo'] = 'Da firmare';
require_once 'header.php';
echo ui_tema_script();
?>
<style>
    .fi-riga { border-top: 1px solid #e2e8f0; padding: .6rem 0; }
    .fi-riga.attiva { background: #f0fdfa; }
    .fi-anteprima { position: sticky; top: 1rem; }
    .fi-anteprima iframe { width: 100%; height: 78vh; border: 0; border-radius: 8px; background: #f1f5f9; }
    @media (max-width: 991.98px) { .fi-anteprima { display: none; } }
</style>
<div class="container my-4" style="max-width: 1200px;">
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
        <?php echo ui_intestazione('Da firmare', 'area_personale.php', 'Area personale', $totale_fi > 0 ? $totale_fi . ($totale_fi === 1 ? ' documento aspetta la tua firma' : ' documenti aspettano la tua firma') : ''); ?>
        <div class="d-print-none"><?php echo ui_tema_pulsante(); ?></div>
    </div>
    <?php if (!$documenti_fi): ?>
        <?php echo ui_vuoto('fa-circle-check', 'Non hai documenti da firmare', 'Quando un documento aspetta la tua firma (una pratica, una convenzione, una lettera di tutorato, un verbale) compare qui, con l\'anteprima e il pulsante per firmarlo.', 'area_personale.php', 'Torna all\'area personale', '#0f766e'); ?>
    <?php else: ?>
        <div class="d-flex flex-wrap gap-2 mb-3 d-print-none" aria-label="Filtra per tipo di documento">
            <a href="firme.php" class="btn btn-sm <?php echo $filtro_fi === '' ? 'btn-danger' : 'btn-outline-danger'; ?> fw-bold">Tutti <span class="ui-badge-rosso ms-1"><?php echo $totale_fi; ?></span></a>
            <?php foreach ($conteggi_fi as $tipo_fi => $n_fi): ?>
                <a href="firme.php?tipo=<?php echo urlencode($tipo_fi); ?>" class="btn btn-sm <?php echo $filtro_fi === $tipo_fi ? 'btn-danger' : 'btn-outline-danger'; ?> fw-bold"><?php echo $h($etichette_fi[$tipo_fi] ?? $tipo_fi); ?> <span class="ui-badge-rosso ms-1"><?php echo $n_fi; ?></span></a>
            <?php endforeach; ?>
        </div>
        <div class="row g-3">
            <div class="col-lg-6">
                <div class="card border-0 shadow-sm" style="border-radius: 12px;"><div class="card-body">
                    <?php foreach ($visibili_fi as $d_fi): ?>
                        <div class="fi-riga" id="fi-<?php echo $h($d_fi->tipo . '-' . $d_fi->id); ?>">
                            <div class="d-flex align-items-start justify-content-between gap-2">
                                <div>
                                    <span class="fw-semibold"><?php echo $h($d_fi->titolo); ?></span><?php if ($d_fi->giorni >= \App\Portale\DaFarePersona::GIORNI_FERMA): ?> <span class="badge text-bg-danger"><?php echo $d_fi->giorni; ?> giorni</span><?php elseif ($d_fi->giorni > 0): ?> <span class="badge text-bg-light border"><?php echo $d_fi->giorni; ?> g</span><?php endif; ?>
                                    <span class="d-block small text-secondary"><?php echo $h($d_fi->dettaglio); ?></span>
                                </div>
                                <div class="d-flex flex-wrap gap-1 justify-content-end text-nowrap">
                                    <?php if ($d_fi->percorso !== null): ?>
                                        <button type="button" class="btn btn-sm btn-outline-secondary fi-vedi d-none d-lg-inline-block" data-src="<?php echo $h($d_fi->linkPdf()); ?>" data-riga="fi-<?php echo $h($d_fi->tipo . '-' . $d_fi->id); ?>">Anteprima</button>
                                        <a class="btn btn-sm btn-outline-secondary d-lg-none" href="<?php echo $h($d_fi->linkPdf()); ?>" target="_blank" rel="noopener">PDF</a>
                                    <?php endif; ?>
                                    <a class="btn btn-sm btn-danger fw-bold" href="<?php echo $h($d_fi->linkFirma); ?>">Firma <i class="fa fa-arrow-right ms-1" aria-hidden="true"></i></a>
                                </div>
                            </div>
                            <?php if ($d_fi->percorso === null): ?><div class="small text-warning-emphasis mt-1"><i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i>Il PDF non è disponibile: apri la pagina di firma per i dettagli.</div><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div></div>
                <p class="small text-secondary mt-2 mb-0">«Firma» apre la pagina del documento, dove puoi firmare con la firma remota o scaricare il PDF, firmarlo sul tuo computer e ricaricarlo. I più fermi stanno in alto.</p>
            </div>
            <div class="col-lg-6">
                <div class="fi-anteprima card border-0 shadow-sm" style="border-radius: 12px;"><div class="card-body">
                    <div class="small fw-bold mb-2"><i class="fa fa-eye me-1" aria-hidden="true"></i>Anteprima</div>
                    <p id="fi-vuoto" class="small text-secondary mb-0">Scegli «Anteprima» su un documento per vederlo qui, senza uscire dall'elenco.</p>
                    <iframe id="fi-frame" title="Anteprima del documento" hidden></iframe>
                </div></div>
            </div>
        </div>
        <script>
        (function () {
            var frame = document.getElementById('fi-frame'), vuoto = document.getElementById('fi-vuoto');
            document.querySelectorAll('.fi-vedi').forEach(function (b) {
                b.addEventListener('click', function () {
                    document.querySelectorAll('.fi-riga.attiva').forEach(function (r) { r.classList.remove('attiva'); });
                    document.getElementById(b.getAttribute('data-riga')).classList.add('attiva');
                    frame.src = b.getAttribute('data-src'); frame.hidden = false; vuoto.hidden = true;
                });
            });
        })();
        </script>
    <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>
