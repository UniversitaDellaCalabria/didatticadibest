<?php
// uffici_ateneo.php - Uffici di Ateneo (solo amministratori): l'amministratore crea l'ufficio (es. «Ufficio pagamenti tutorato») e associa le persone
// scegliendole dall'anagrafe di Ateneo. Chi è associato entra con le credenziali Unical e vede le pratiche che l'ufficio riceve
// (pagamenti del tutorato: pagamenti_tutorato.php). Una persona può far parte di più uffici. Logica in src/Ateneo/.
require_once 'admin_header.php';

if (!$is_full_admin) nega_accesso();

function admin_redirect($url) { echo "<script>window.location.replace(" . json_encode($url) . ");</script>"; exit; }

$uffici_sv = \App\Core\App::per($conn)->get(\App\Ateneo\ServizioUfficiAteneo::class);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $id_u = (int)($_POST['ufficio_id'] ?? 0);
    if (isset($_POST['salva_ufficio'])) {
        [$nuovo_id, $err] = $uffici_sv->salva($id_u, (string)($_POST['nome'] ?? ''), (string)($_POST['descrizione'] ?? ''), !empty($_POST['attivo']));
        if (!$err) registra_log_audit($conn, $id_u ? "Ufficio di Ateneo modificato" : "Ufficio di Ateneo creato", ["Ufficio" => (string)($_POST['nome'] ?? '')]);
        flash_set($err ?? ($id_u ? "Ufficio salvato." : "Ufficio creato: ora aggiungi le persone."), $err ? 'danger' : 'success');
        admin_redirect("uffici_ateneo.php?u=" . (int)$nuovo_id . "&r=" . time());
    }
    if (isset($_POST['aggiungi_anagrafe'])) {
        $err = $uffici_sv->aggiungiDaAnagrafe($id_u, (string)($_POST['persona_id'] ?? ''));
        if (!$err) registra_log_audit($conn, "Ufficio di Ateneo: persona aggiunta", ["Ufficio" => $id_u, "Persona" => (string)($_POST['persona_id'] ?? '')]);
        flash_set($err ?? "Persona aggiunta: entra con le sue credenziali Unical.", $err ? 'danger' : 'success');
        admin_redirect("uffici_ateneo.php?u=$id_u&r=" . time());
    }
    if (isset($_POST['aggiungi_mano'])) {
        $err = $uffici_sv->aggiungiAMano($id_u, (string)($_POST['nominativo'] ?? ''), (string)($_POST['email'] ?? ''));
        if (!$err) registra_log_audit($conn, "Ufficio di Ateneo: persona aggiunta a mano", ["Ufficio" => $id_u, "Email" => (string)($_POST['email'] ?? '')]);
        flash_set($err ?? "Persona aggiunta.", $err ? 'danger' : 'success');
        admin_redirect("uffici_ateneo.php?u=$id_u&r=" . time());
    }
    if (isset($_POST['togli_persona'])) {
        $uffici_sv->togliPersona((int)$_POST['togli_persona']);
        registra_log_audit($conn, "Ufficio di Ateneo: persona tolta", ["Ufficio" => $id_u]);
        flash_set("Persona tolta dall'ufficio.", 'warning');
        admin_redirect("uffici_ateneo.php?u=$id_u&r=" . time());
    }
    if (isset($_POST['elimina_ufficio'])) {
        $err = $uffici_sv->elimina($id_u);
        if (!$err) registra_log_audit($conn, "Ufficio di Ateneo eliminato", ["Ufficio" => $id_u]);
        flash_set($err ?? "Ufficio eliminato.", $err ? 'danger' : 'warning');
        admin_redirect("uffici_ateneo.php?r=" . time());
    }
}

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$elenco = $uffici_sv->elenco();
$sel = (int)($_GET['u'] ?? 0);
$nuovo = isset($_GET['nuovo']) || (!$sel && !$elenco);
$uff = $sel ? $uffici_sv->ufficio($sel) : null;
if (!$uff && !$nuovo && $elenco) { $uff = $uffici_sv->ufficio((int)$elenco[0]['id']); $sel = (int)$uff['id']; }
$persone = $uff ? $uffici_sv->persone((int)$uff['id']) : [];

$page_cfg['titolo'] = 'Uffici di Ateneo';
echo pn_testata('Uffici di Ateneo', 'fa-building-columns', "Uffici che non sono del Dipartimento (pagamenti, personale…): ricevono pratiche da lavorare nel portale. L'amministratore li crea e associa le persone dall'anagrafe di Ateneo.");
?>
<?php echo flash_html(); ?>
<div class="row g-3">
    <div class="col-lg-3">
        <div class="list-group shadow-sm mb-3">
            <?php foreach ($elenco as $x): ?>
                <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center<?php echo $uff && (int)$uff['id'] === (int)$x['id'] ? ' active' : ''; ?>" href="uffici_ateneo.php?u=<?php echo (int)$x['id']; ?>">
                    <span><?php echo $h($x['nome']); ?><?php echo (int)$x['attivo'] ? '' : ' <span class="badge bg-secondary">non attivo</span>'; ?></span>
                    <span class="badge bg-light text-dark border"><?php echo (int)$x['n_persone']; ?></span></a>
            <?php endforeach; ?>
            <?php if (!$elenco): ?><div class="list-group-item text-secondary small">Nessun ufficio ancora.</div><?php endif; ?>
        </div>
        <a class="btn btn-outline-danger fw-bold w-100" href="uffici_ateneo.php?nuovo=1"><i class="fa fa-plus me-1" aria-hidden="true"></i>Nuovo ufficio</a>
    </div>
    <div class="col-lg-9">
        <div class="card border-0 shadow-sm mb-3"><div class="card-body">
            <h2 class="h5 fw-bold"><?php echo $uff ? 'Ufficio' : 'Nuovo ufficio'; ?></h2>
            <form method="POST" class="row g-2">
                <?php csrf_field(); ?>
                <input type="hidden" name="ufficio_id" value="<?php echo $uff ? (int)$uff['id'] : 0; ?>">
                <div class="col-md-5"><label class="form-label small fw-bold" for="ufN">Nome dell'ufficio *</label><input class="form-control" id="ufN" name="nome" value="<?php echo $h($uff['nome'] ?? ''); ?>" maxlength="150" required placeholder="Ufficio pagamenti tutorato"></div>
                <div class="col-md-5"><label class="form-label small fw-bold" for="ufD">A cosa serve</label><input class="form-control" id="ufD" name="descrizione" value="<?php echo $h($uff['descrizione'] ?? ''); ?>" maxlength="500" placeholder="Paga i tutorati dopo la validazione del referente"></div>
                <div class="col-md-2 d-flex align-items-end"><label class="form-check"><input class="form-check-input" type="checkbox" name="attivo" value="1"<?php echo !$uff || (int)$uff['attivo'] ? ' checked' : ''; ?>> Attivo</label></div>
                <div class="col-12"><button type="submit" name="salva_ufficio" value="1" class="btn btn-danger fw-bold"><i class="fa fa-save me-1" aria-hidden="true"></i><?php echo $uff ? 'Salva' : 'Crea l\'ufficio'; ?></button></div>
            </form>
        </div></div>

        <?php if ($uff): ?>
        <div class="card border-0 shadow-sm mb-3"><div class="card-body">
            <h2 class="h5 fw-bold">Persone dell'ufficio (<?php echo count($persone); ?>)</h2>
            <?php if ($persone): ?>
            <div class="table-responsive"><table class="table table-sm align-middle">
                <thead><tr><th>Nome</th><th>Struttura (anagrafe di Ateneo)</th><th>Email</th><th></th></tr></thead><tbody>
                <?php foreach ($persone as $p): ?>
                    <tr><td class="fw-bold"><?php echo $h($p['nominativo']); ?></td><td class="small"><?php echo $h($p['struttura'] ?: ($p['persona_id'] ? '' : 'inserita a mano')); ?></td><td class="small"><?php echo $h($p['email']); ?></td>
                        <td class="text-end"><form method="POST" class="d-inline"><?php csrf_field(); ?><input type="hidden" name="ufficio_id" value="<?php echo (int)$uff['id']; ?>">
                            <button class="btn btn-sm btn-outline-secondary" name="togli_persona" value="<?php echo (int)$p['id']; ?>" data-confirm="Togliere <?php echo $h($p['nominativo']); ?> dall'ufficio?">Togli</button></form></td></tr>
                <?php endforeach; ?></tbody></table></div>
            <?php else: ?><p class="text-secondary small">Nessuna persona: aggiungila qui sotto.</p><?php endif; ?>

            <h3 class="h6 fw-bold mt-3">Aggiungi una persona dall'anagrafe di Ateneo</h3>
            <form method="POST" id="formUfficioAn"><?php csrf_field(); ?>
                <input type="hidden" name="ufficio_id" value="<?php echo (int)$uff['id']; ?>"><input type="hidden" name="persona_id" id="ufPid">
                <?php echo html_ricerca_personale($conn, 'Aggiungi'); ?>
                <button type="submit" name="aggiungi_anagrafe" value="1" id="ufInvia" hidden></button>
            </form>
            <p class="form-text">Accede con le sue credenziali Unical: non serve creare un account. Può far parte di più uffici.</p>

            <details class="mt-2"><summary class="small fw-bold">La persona non c'è nell'anagrafe? Scrivi nome ed email</summary>
                <form method="POST" class="row g-2 mt-1"><?php csrf_field(); ?><input type="hidden" name="ufficio_id" value="<?php echo (int)$uff['id']; ?>">
                    <div class="col-md-5"><input class="form-control form-control-sm" name="nominativo" placeholder="Cognome e nome" maxlength="200" aria-label="Cognome e nome"></div>
                    <div class="col-md-5"><input type="email" class="form-control form-control-sm" name="email" placeholder="Email Unical" aria-label="Email"></div>
                    <div class="col-md-2"><button class="btn btn-sm btn-outline-primary w-100" name="aggiungi_mano" value="1">Aggiungi</button></div></form></details>
        </div></div>

        <form method="POST" class="mb-4"><?php csrf_field(); ?><input type="hidden" name="ufficio_id" value="<?php echo (int)$uff['id']; ?>">
            <button class="btn btn-sm btn-outline-danger" name="elimina_ufficio" value="1" data-confirm="Eliminare l'ufficio «<?php echo $h($uff['nome']); ?>» con le sue persone?"><i class="fa fa-trash me-1" aria-hidden="true"></i>Elimina l'ufficio</button></form>
        <?php endif; ?>
    </div>
</div>
<script>
// Persona scelta dall'anagrafe: si aggiunge subito all'ufficio
var fUf = document.getElementById('formUfficioAn');
if (fUf) fUf.addEventListener('persona-scelta', function (e) {
    document.getElementById('ufPid').value = e.detail.id;
    document.getElementById('ufInvia').click();
});
</script>
<?php require_once 'admin_footer.php'; ?>
