<?php
// sostituzioni.php - Sostituzioni degli operatori (Didattica → Sostituzioni): chi sostituisce un operatore assente, dal… al…, così le pratiche non restano ferme.
// Nel periodo le email sull'operatore assente (messaggi degli studenti, promemoria) arrivano anche al sostituto, e le pratiche dell'assente compaiono
// nel suo «da fare» e in «in carico a me». Ognuno indica la propria assenza; chi smista le pratiche (e gli amministratori) la indica per chiunque.
require_once 'admin_header.php';

if (!$puo_didattica_tutto) nega_accesso();

function admin_redirect($url) { echo "<script>window.location.replace(" . json_encode($url) . ");</script>"; exit; }

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$app_s = \App\Core\App::per($conn);
$svc_s = $app_s->get(\App\Didattica\SostituzioniOperatori::class);
$uff_s = $app_s->get(\App\Didattica\ServizioUffici::class);
$io = $uff_s->operatore(0, $utente_admin);
$smistano = array_keys(array_filter($app_s->get(\App\Didattica\UfficioRepository::class)->tutti(), fn($u) => (int)$u['smista'] === 1));
$per_tutti = $is_full_admin || ha_modulo($conn, $uid_rbac, 'didattica') || ($io && array_intersect($io['_uffici'], $smistano));
$operatori = $uff_s->operatori();
$url = "sostituzioni.php?p_id=" . (int)$filtro_p;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    if (isset($_POST['aggiungi'])) {
        $assente = $per_tutti ? (int)($_POST['assente'] ?? 0) : (int)($io['id'] ?? 0);
        $err = $assente ? $svc_s->aggiungi($assente, (int)($_POST['sostituto'] ?? 0), (string)($_POST['dal'] ?? ''), (string)($_POST['al'] ?? ''), (string)($_POST['motivo'] ?? ''), (int)$u_id_curr) : 'Non risulti tra gli operatori degli uffici.';
        if ($err === null) {
            registra_log_audit($conn, 'Sostituzione di un operatore', ['Assente' => $assente, 'Sostituto' => (int)$_POST['sostituto'], 'Dal' => $_POST['dal'], 'Al' => $_POST['al']]);
            flash_set('Sostituzione registrata: nel periodo le email e il «da fare» passano anche al sostituto.', 'success');
        } else flash_set($err, 'danger');
    } elseif (isset($_POST['elimina'])) {
        $id = (int)$_POST['elimina'];
        foreach ($svc_s->elenco() as $s) {
            if ((int)$s['id'] === $id && ($per_tutti || (int)$s['op_id'] === (int)($io['id'] ?? -1))) {
                $svc_s->elimina($id);
                registra_log_audit($conn, 'Sostituzione di un operatore eliminata', ['Assente' => $s['assente'], 'Sostituto' => $s['sostituto']]);
                flash_set('Sostituzione eliminata.', 'success');
            }
        }
    }
    admin_redirect($url);
}

$elenco = $svc_s->elenco();
$mie = array_filter($elenco, fn($s) => $per_tutti || (int)$s['op_id'] === (int)($io['id'] ?? -1) || (int)$s['sostituto_op_id'] === (int)($io['id'] ?? -1));
?>
<?php echo pn_testata('Sostituzioni degli operatori', 'fa-people-arrows', 'Indica chi sostituisce un operatore assente: nel periodo le email e il «da fare» arrivano anche al sostituto. La pratica resta assegnata all\'assente: al suo ritorno non c\'è nulla da riassegnare.'); ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm"><div class="card-header bg-white fw-bold">Sostituzioni in corso e previste</div>
        <?php if (!$mie): ?><div class="card-body text-secondary small">Nessuna sostituzione. Quando un operatore è in ferie o assente, indicala qui accanto.</div>
        <?php else: ?><ul class="list-group list-group-flush">
            <?php foreach ($mie as $s): ?>
                <li class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <strong><?php echo $h($s['assente']); ?></strong> <i class="fa fa-arrow-right text-secondary mx-1" aria-hidden="true"></i> <strong><?php echo $h($s['sostituto']); ?></strong>
                        <?php echo $s['in_corso'] ? '<span class="badge bg-success ms-1">in corso</span>' : '<span class="badge bg-secondary ms-1">prevista</span>'; ?>
                        <div class="small text-secondary">dal <?php echo date('d/m/Y', strtotime($s['dal'])); ?> al <?php echo date('d/m/Y', strtotime($s['al'])); ?><?php echo $s['motivo'] !== '' ? ' · ' . $h($s['motivo']) : ''; ?></div>
                    </div>
                    <?php if ($per_tutti || (int)$s['op_id'] === (int)($io['id'] ?? -1)): ?>
                        <form method="POST" class="m-0"><?php csrf_field(); ?><button class="btn btn-sm btn-outline-danger" name="elimina" value="<?php echo (int)$s['id']; ?>" data-confirm="Eliminare questa sostituzione?"><i class="fa fa-trash" aria-hidden="true"></i><span class="visually-hidden">Elimina</span></button></form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul><?php endif; ?></div>
    </div>
    <div class="col-lg-5">
        <form method="POST" class="card border-0 shadow-sm"><div class="card-body">
            <?php csrf_field(); ?>
            <h2 class="h6 fw-bold mb-3"><i class="fa fa-plus me-1" aria-hidden="true"></i>Indica una assenza</h2>
            <?php if ($per_tutti): ?>
                <label class="form-label small fw-bold mb-0" for="sAss">Chi è assente</label>
                <select class="form-select mb-2" id="sAss" name="assente" required><option value="">— scegli —</option>
                    <?php foreach ($operatori as $o): ?><option value="<?php echo (int)$o['id']; ?>"<?php echo $io && (int)$io['id'] === (int)$o['id'] ? ' selected' : ''; ?>><?php echo $h($o['nominativo']); ?></option><?php endforeach; ?>
                </select>
            <?php elseif (!$io): ?>
                <div class="alert alert-warning small">Non risulti tra le persone degli uffici: chiedi a chi gestisce la Didattica di indicare la tua assenza.</div>
            <?php else: ?>
                <p class="small mb-2">Assente: <strong><?php echo $h($io['nominativo']); ?></strong></p>
            <?php endif; ?>
            <label class="form-label small fw-bold mb-0" for="sSos">Chi lo sostituisce</label>
            <select class="form-select mb-2" id="sSos" name="sostituto" required><option value="">— scegli —</option>
                <?php foreach ($operatori as $o): ?><option value="<?php echo (int)$o['id']; ?>"><?php echo $h($o['nominativo']); ?></option><?php endforeach; ?>
            </select>
            <div class="row g-2 mb-2">
                <div class="col-6"><label class="form-label small fw-bold mb-0" for="sDal">Dal</label><input type="date" class="form-control" id="sDal" name="dal" value="<?php echo date('Y-m-d'); ?>" required></div>
                <div class="col-6"><label class="form-label small fw-bold mb-0" for="sAl">Al (compreso)</label><input type="date" class="form-control" id="sAl" name="al" required></div>
            </div>
            <label class="form-label small fw-bold mb-0" for="sMot">Motivo (facoltativo)</label>
            <input class="form-control mb-3" id="sMot" name="motivo" maxlength="200" placeholder="es. ferie, congresso">
            <button class="btn btn-primary fw-bold" name="aggiungi" value="1"<?php echo !$per_tutti && !$io ? ' disabled' : ''; ?>><i class="fa fa-save me-1" aria-hidden="true"></i>Registra</button>
        </div></form>
    </div>
</div>

<?php require_once 'admin_footer.php'; ?>
