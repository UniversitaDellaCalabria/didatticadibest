<?php
// =========================================================================
// I miei studenti: le richieste degli studenti in cui il docente è indicato come relatore (o altra persona che firma), in sola lettura, con lo stato della
// pratica e della sua firma. Utile per sapere se un tesista ha inviato la richiesta. Logica in App\Didattica\StudentiDelDocente. Si arriva da area_personale.php.
// =========================================================================
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/middleware.php';

$h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$pratiche_ms = [];
try {
    $pratiche_ms = \App\Core\App::per($conn)->get(\App\Didattica\StudentiDelDocente::class)->perUtente($user_info ?? null);
} catch (\Throwable $e_ms) { error_log('[I miei studenti] non disponibili: ' . $e_ms->getMessage()); }
$r_ms = \App\Didattica\StudentiDelDocente::riepilogo($pratiche_ms);
$firme_ms = ['attesa' => 'Aspetta la tua firma', 'firmata' => 'Firmata da te', 'rifiutata' => 'Rifiutata da te'];

$page_cfg['titolo'] = 'I miei studenti';
require_once 'header.php';
echo ui_tema_script();
?>
<style>
    .ms-riga { border-top: 1px solid #e2e8f0; padding: .6rem 0; }
</style>
<div class="container my-4" style="max-width: 900px;">
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
        <?php echo ui_intestazione('I miei studenti', 'area_personale.php', 'Area personale', $r_ms['testo']); ?>
        <div class="d-print-none"><?php echo ui_tema_pulsante(); ?></div>
    </div>
    <?php if (!$pratiche_ms): ?>
        <?php echo ui_vuoto('fa-user-graduate', 'Nessuna richiesta', 'Quando uno studente ti indica come relatore e invia la richiesta, la trovi qui con lo stato.', 'area_personale.php', 'Torna all\'area personale', '#7c3aed'); ?>
    <?php else: ?>
        <div class="card border-0 shadow-sm" style="border-radius: 12px;"><div class="card-body">
            <?php foreach ($pratiche_ms as $p_ms): $st_ms = \App\Didattica\Costanti::STATI_PRATICA[$p_ms['stato']] ?? [$p_ms['stato'], '#64748b', 'fa-circle']; ?>
                <div class="ms-riga">
                    <div class="d-flex flex-wrap justify-content-between gap-2">
                        <span class="fw-semibold"><?php echo $h($p_ms['studente']); ?> · <?php echo $h($p_ms['modulo']); ?></span>
                        <span class="badge" style="background: <?php echo $h($st_ms[1]); ?>;"><i class="fa <?php echo $h($st_ms[2]); ?> me-1" aria-hidden="true"></i><?php echo $h($st_ms[0]); ?></span>
                    </div>
                    <span class="d-block small text-secondary">pratica <?php echo $h($p_ms['codice']); ?> · <?php echo $h($firme_ms[$p_ms['firma']] ?? $p_ms['firma']); ?><?php echo $p_ms['dal'] !== '' ? ' · richiesta il ' . $h(date('d/m/Y', (int) strtotime($p_ms['dal']))) : ''; ?></span>
                    <?php if ($p_ms['firma'] === 'attesa' && in_array($p_ms['stato'], ['inviata', 'in_lavorazione'], true)): ?><a class="btn btn-sm btn-primary mt-1" href="firme.php">Firma ora</a><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div></div>
        <p class="small text-secondary mt-2">Elenco in sola lettura: mostra solo studente, tipo di richiesta e stato. Per i dettagli della richiesta usa il link ricevuto per email.</p>
    <?php endif; ?>
</div>
<?php require_once 'footer.php'; ?>
