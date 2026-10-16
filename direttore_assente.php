<?php
// direttore_assente.php - Interruttore «Direttore assente» (Area personale): finché è attivo, link di firma, avvisi e riepilogo passano al vice direttore.
// Lo possono attivare e spegnere il Direttore, il vice, la segreteria di direzione e gli amministratori (anche dalla scheda «Direzione» in Gestione del portale → Uffici).
// Il Direttore e il vice si scelgono dall'anagrafe di Ateneo nell'ufficio «Direzione del Dipartimento». Logica in src/Uffici/Direzione.php.
require_once __DIR__ . '/middleware.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$dz = \App\Core\App::per($conn)->get(\App\Uffici\Direzione::class);
$ok = $dz->puoImpostareAssenza($user_info ?? null);

if ($ok && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $attiva = ($_POST['assente'] ?? '') === '1';
    $chi = trim((string)($user_info['nome'] ?? '') . ' ' . (string)($user_info['cognome'] ?? '')) ?: (string)($user_info['email'] ?? '');
    $err = $dz->imposta($attiva, (string)($_POST['rientro'] ?? ''), $chi);
    if (!$err) registra_log_audit($conn, $attiva ? "Direttore assente: attivato" : "Direttore assente: disattivato", ["Rientro" => (string)($_POST['rientro'] ?? '')]);
    flash_set($err ?? ($attiva ? "«Direttore assente» attivo: firme, link e avvisi vanno al vice direttore." : "«Direttore assente» spento: firme e avvisi tornano al Direttore."), $err ? 'danger' : 'success');
    header('Location: direttore_assente.php?r=' . time()); exit;
}

$page_cfg['titolo'] = 'Direttore assente';
require_once 'header.php';
?>
<div class="container my-4" style="max-width: 760px;">
<?php if (!$ok): ?>
    <h1 class="fw-bold h3">Pagina non disponibile</h1><p>Questa pagina è per il Direttore, il vice direttore e la segreteria di direzione.</p>
    <a class="btn btn-outline-secondary" href="area_personale.php">Area personale</a>
<?php else:
    $ass = $dz->assenza(); $dir = $dz->direttore(); $vice = $dz->vice(); $ora = $dz->chiFirma(); ?>
    <nav aria-label="Percorso" class="mb-3 small"><a href="area_personale.php">Area personale</a> <span class="text-secondary mx-1">/</span> <span class="text-secondary">Direttore assente</span></nav>
    <?php echo flash_html(); ?>
    <h1 class="fw-bold h3 mb-3"><i class="fa fa-user-clock me-2" style="color:#b45309;" aria-hidden="true"></i>Direttore assente</h1>
    <div class="card border-0 shadow-sm mb-3" style="border-left:5px solid <?php echo $ass['attiva'] ? '#f59e0b' : '#15803d'; ?> !important;"><div class="card-body">
        <p class="mb-1"><strong>Firma ora:</strong> <?php echo $ora['email'] === '' ? '<span class="text-danger">nessuno (la Direzione non è completa)</span>' : $h($ora['nome']) . ' · ' . $h($ora['email']); ?>
            <?php echo $ass['attiva'] ? '<span class="badge bg-warning text-dark ms-1">Direttore assente</span>' : '<span class="badge bg-success ms-1">Direttore in sede</span>'; ?></p>
        <p class="small text-secondary mb-2">Direttore: <strong><?php echo $dir ? $h($dir['nominativo']) : 'non indicato'; ?></strong> · Vice direttore: <strong><?php echo $vice ? $h($vice['nominativo']) : 'non indicato'; ?></strong>.
            Con l'interruttore attivo i link di firma (convenzioni, certificati, attestati) e gli avvisi vanno al vice; spento, tornano al Direttore. Il vice ha comunque gli stessi permessi del Direttore.</p>
        <form method="POST" class="d-flex flex-wrap gap-2 align-items-end"><?php csrf_field(); ?>
            <?php if ($ass['attiva']): ?>
                <div class="small text-secondary">Attivo<?php echo $ass['da'] !== '' ? ' da ' . $h($ass['da']) : ''; ?><?php echo $ass['il'] ? ' il ' . $h(date('d/m/Y H:i', strtotime($ass['il']))) : ''; ?><?php echo $ass['fino'] ? ' · rientro il ' . $h(date('d/m/Y', strtotime($ass['fino']))) : ''; ?>.</div>
                <button type="submit" name="assente" value="0" class="btn btn-success fw-bold"><i class="fa fa-user-check me-1" aria-hidden="true"></i>Il Direttore è tornato</button>
            <?php else: ?>
                <div><label class="form-label small fw-bold mb-0" for="daRientro">Data di rientro (facoltativa)</label><input type="date" class="form-control form-control-sm" id="daRientro" name="rientro" min="<?php echo date('Y-m-d'); ?>"></div>
                <button type="submit" name="assente" value="1" class="btn btn-warning fw-bold"<?php echo $vice ? '' : ' disabled'; ?>><i class="fa fa-user-clock me-1" aria-hidden="true"></i>Il Direttore è assente: passa al vice</button>
                <?php if (!$vice): ?><span class="small text-danger">Manca il vice direttore: lo aggiunge l'amministratore nella Direzione (Gestione del portale → Uffici).</span><?php endif; ?>
            <?php endif; ?>
        </form>
    </div></div>
<?php endif; ?>
</div>
<?php require_once 'footer.php'; ?>
