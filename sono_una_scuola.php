<?php
// =========================================================================
// Sono una scuola: al primo accesso la persona sceglie la sua scuola dall'anagrafe e il suo profilo diventa «Scuola» (area personale con la Formazione Scuola
// Lavoro). Logica in App\Portale\ScegliScuola. Si arriva dalla tessera di benvenuto dell'area personale.
// =========================================================================
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/middleware.php';

$h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$errore_ss = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $cod_ss = (string) (((array) ($_POST['scuola_codice'] ?? []))['scuola'] ?? '');
    $errore_ss = (string) \App\Core\App::per($conn)->get(\App\Portale\ScegliScuola::class)->imposta((int) $u_id, $cod_ss);
    if ($errore_ss === '') {
        flash_set('Fatto: da ora la tua area personale è quella della scuola, con la Formazione Scuola Lavoro.');
        header('Location: area_personale.php'); exit;
    }
}

$page_cfg['titolo'] = 'Sono una scuola';
require_once 'header.php';
echo ui_tema_script();
?>
<div class="container my-4" style="max-width: 700px;">
    <?php echo ui_intestazione('Sono una scuola', 'area_personale.php', 'Area personale', 'Indica la tua scuola: la tua area personale mostrerà i percorsi della Formazione Scuola Lavoro.'); ?>
    <?php if ($errore_ss !== ''): ?><div class="alert alert-warning py-2"><?php echo $h($errore_ss); ?></div><?php endif; ?>
    <form method="post" action="sono_una_scuola.php" class="card border-0 shadow-sm" style="border-radius: 12px;"><div class="card-body">
        <?php csrf_field(); ?>
        <label class="form-label fw-bold" for="campo-scuola">La tua scuola</label>
        <?php echo html_campo_scuola('scuola', '', '', 'id="campo-scuola" required', 'form-control'); ?>
        <p class="small text-secondary mt-2 mb-3">Scrivi il nome o il comune e scegli la scuola dall'elenco. Non la trovi? Scrivi a un operatore con «Serve aiuto?».</p>
        <button type="submit" class="btn btn-primary fw-bold">Conferma la mia scuola</button>
    </div></form>
</div>
<?php require_once 'footer.php'; ?>
