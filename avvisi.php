<?php
// avvisi.php - Avvisi per email dei nuovi eventi: iscrizione scegliendo gli argomenti (ambiti e attività per le scuole),
// conferma dal link (?conferma=…), gestione e cancellazione dal link in fondo a ogni email (?t=…). Logica in inc/avvisi.php.
require_once 'config.php';
require_once 'functions.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$utente = !empty($_SESSION['utente_id']) ? db_riga($conn, "SELECT * FROM utenti WHERE id = ?", [(int)$_SESSION['utente_id']]) : null;
$tok = (string)($_GET['t'] ?? '');
if (!empty($_GET['conferma'])) {
    $ok = conferma_avvisi($conn, (string)$_GET['conferma']);
    flash_set($ok ? "Iscrizione confermata: riceverai un'email quando escono nuovi eventi dei tuoi argomenti." : "Il link non è valido o l'iscrizione è stata cancellata.", $ok ? 'success' : 'warning');
    header('Location: avvisi.php' . ($ok ? '?t=' . urlencode((string)$_GET['conferma']) : '')); exit;
}
$isc = $tok !== '' ? iscrizione_avvisi_per_token($conn, $tok) : null;
if (!$isc && $utente && !empty($utente['email'])) $isc = db_riga($conn, "SELECT * FROM avvisi_iscrizioni WHERE email = ?", [strtolower(trim($utente['email']))]);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    if (isset($_POST['cancella']) && $isc) {
        cancella_avvisi($conn, $isc['token']);
        flash_set("Iscrizione cancellata: non riceverai più avvisi.", 'success');
        header('Location: avvisi.php'); exit;
    }
    if (!check_rate_limit($conn, 'avvisi', 10, 3600)) { flash_set("Troppe richieste: riprova più tardi.", 'danger'); header('Location: avvisi.php'); exit; }
    $email = $isc ? $isc['email'] : (string)($_POST['email'] ?? '');
    [$msg, $err] = iscrivi_avvisi($conn, $email, (array)($_POST['ambiti'] ?? []), !empty($_POST['scuole']), $utente);
    flash_set($err ?? $msg, $err ? 'danger' : 'success');
    header('Location: avvisi.php' . ($isc ? '?t=' . urlencode($isc['token']) : '')); exit;
}
$scelti = $isc ? array_filter(explode(',', $isc['ambiti'])) : array_filter([isset(AMBITI_EVENTO[$_GET['ambito'] ?? '']) ? $_GET['ambito'] : '']);
$scuole = $isc ? (bool)$isc['scuole'] : !empty($_GET['scuole']);

$page_cfg['titolo'] = 'Avvisi dei nuovi eventi';
require_once 'header.php';
?>
<div class="container my-4" style="max-width: 760px;">
    <nav aria-label="Percorso" class="mb-3 small"><a href="index.php">Home</a> <span class="text-secondary mx-1">/</span> <a href="agenda.php">Eventi e seminari</a> <span class="text-secondary mx-1">/</span> <span class="text-secondary">Avvisi per email</span></nav>
    <?php echo flash_html(); ?>
    <h1 class="fw-bold mb-1">Avvisi dei nuovi eventi</h1>
    <p class="text-secondary">Scegli cosa ti interessa: quando il Dipartimento pubblica un nuovo evento su quegli argomenti ricevi un'email con il riepilogo. Al massimo un'email per volta con tutte le novità, e ti cancelli quando vuoi dal link in fondo.</p>
    <form method="POST" class="card border-0 shadow-sm" style="border-radius:14px;"><div class="card-body p-4">
        <?php csrf_field(); ?>
        <?php if ($isc): ?>
            <p class="mb-3"><i class="fa fa-envelope me-1 text-primary" aria-hidden="true"></i><strong><?php echo $h($isc['email']); ?></strong>
                <?php echo $isc['confermata_il'] ? '<span class="badge bg-success ms-1">iscrizione attiva</span>' : '<span class="badge bg-warning text-dark ms-1">da confermare: controlla la tua email</span>'; ?></p>
        <?php else: ?>
            <label class="form-label fw-bold" for="avEmail">La tua email</label>
            <input type="email" class="form-control mb-3" id="avEmail" name="email" required maxlength="150" value="<?php echo $h($utente['email'] ?? ''); ?>" autocomplete="email">
        <?php endif; ?>
        <fieldset class="mb-3"><legend class="form-label fw-bold fs-6">Argomenti</legend>
            <?php foreach (AMBITI_EVENTO as $k => $a): ?>
                <label class="form-check d-flex gap-2 align-items-start py-1"><input class="form-check-input mt-1" type="checkbox" name="ambiti[]" value="<?php echo $k; ?>"<?php echo in_array($k, $scelti, true) ? ' checked' : ''; ?>>
                    <span><i class="fa <?php echo $a['icona']; ?> me-1" style="color:<?php echo $a['colore']; ?>;" aria-hidden="true"></i><strong><?php echo $h($a['nome']); ?></strong><span class="d-block small text-secondary"><?php echo $h($a['descr']); ?></span></span></label>
            <?php endforeach; ?>
            <label class="form-check d-flex gap-2 align-items-start py-1"><input class="form-check-input mt-1" type="checkbox" name="scuole" value="1"<?php echo $scuole ? ' checked' : ''; ?>>
                <span><i class="fa fa-school me-1" aria-hidden="true"></i><strong>Attività per le scuole</strong><span class="d-block small text-secondary">Per docenti delle scuole superiori: laboratori e percorsi di Formazione Scuola Lavoro</span></span></label>
        </fieldset>
        <p class="small text-secondary">Usiamo l'email solo per questi avvisi (vedi <a href="privacy.php">privacy</a>). Le iscrizioni non confermate si cancellano dopo 30 giorni.</p>
        <div class="d-flex flex-wrap gap-2">
            <button type="submit" name="salva" value="1" class="btn btn-primary fw-bold"><i class="fa fa-bell me-1" aria-hidden="true"></i><?php echo $isc ? 'Salva le preferenze' : 'Iscriviti agli avvisi'; ?></button>
            <?php if ($isc): ?><button type="submit" name="cancella" value="1" class="btn btn-outline-danger" formnovalidate>Cancellami</button><?php endif; ?>
        </div>
    </div></form>
</div>
<?php require_once 'footer.php'; ?>
