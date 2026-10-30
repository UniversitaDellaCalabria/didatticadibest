<?php
// tirocini_motorie.php - Didattica › Tirocini › Scienze motorie: per ogni azienda convenzionata scelta, quanti tirocini contemporanei accetta (di partenza 2)
// e chi li sta facendo. Gli studenti vedono solo «libera / satura» e quando si libera un posto (pagina tirocini_motorie.php, dopo l'accesso, senza nomi).
// Compilano gli amministratori, chi lavora in Didattica e le persone dell'ufficio «Ufficio tirocini (Scienze motorie)» (Gestione del portale → Uffici).
// Dal report del TSP si importano i tirocini di Scienze motorie [0827]; gli altri si scrivono a mano. Logica in src/Tirocini/ServizioMotorie.php.
require_once 'admin_header.php';

if (!$puo_tir_motorie) nega_accesso();

function admin_redirect($url) { echo "<script>window.location.replace(" . json_encode($url) . ");</script>"; exit; }

$sm = \App\Core\App::per($conn)->get(\App\Tirocini\ServizioMotorie::class);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$data_it = fn(?string $d) => $d ? date('d/m/Y', strtotime($d)) : '';
$url = 'tirocini_motorie.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $az = (int)($_POST['azienda_id'] ?? 0);
    if (isset($_POST['aggiungi_azienda'])) {
        $err = $sm->aggiungiAzienda($az, (int)($_POST['posti'] ?? \App\Tirocini\ServizioMotorie::POSTI_DI_PARTENZA));
        if (!$err) registra_log_audit($conn, 'Tirocini Scienze motorie: azienda aggiunta', ['Azienda' => $az, 'Posti' => (int)($_POST['posti'] ?? 0)]);
        flash_set($err ?? 'Azienda aggiunta all\'elenco di Scienze motorie.', $err ? 'danger' : 'success');
    } elseif (isset($_POST['salva_posti'])) {
        $err = $sm->impostaPosti($az, (int)($_POST['posti'] ?? 0), (string)($_POST['note'] ?? ''));
        if (!$err) registra_log_audit($conn, 'Tirocini Scienze motorie: posti modificati', ['Azienda' => $az, 'Posti' => (int)$_POST['posti']]);
        flash_set($err ?? 'Posti salvati.', $err ? 'danger' : 'success');
    } elseif (isset($_POST['togli_azienda'])) {
        $sm->togliAzienda($az);
        registra_log_audit($conn, 'Tirocini Scienze motorie: azienda tolta', ['Azienda' => $az]);
        flash_set('Azienda tolta dall\'elenco di Scienze motorie (con i suoi tirocini).', 'warning');
    } elseif (isset($_POST['nuovo_periodo'])) {
        $err = $sm->aggiungiPeriodo($az, (string)($_POST['matricola'] ?? ''), (string)($_POST['studente'] ?? ''), (string)($_POST['inizio'] ?? ''), (string)($_POST['fine'] ?? ''));
        if (!$err) registra_log_audit($conn, 'Tirocini Scienze motorie: tirocinio inserito', ['Azienda' => $az, 'Matricola' => trim((string)($_POST['matricola'] ?? ''))]);
        flash_set($err ?? 'Tirocinio inserito.', $err ? 'danger' : 'success');
    } elseif (isset($_POST['concludi'])) {
        $sm->concludiPeriodo((int)$_POST['concludi']);
        registra_log_audit($conn, 'Tirocini Scienze motorie: tirocinio concluso oggi', ['Periodo' => (int)$_POST['concludi']]);
        flash_set('Tirocinio concluso: il posto si libera da domani.');
    } elseif (isset($_POST['annulla'])) {
        $sm->annullaPeriodo((int)$_POST['annulla']);
        registra_log_audit($conn, 'Tirocini Scienze motorie: tirocinio annullato', ['Periodo' => (int)$_POST['annulla']]);
        flash_set('Tirocinio annullato: il posto è subito libero.', 'warning');
    } elseif (isset($_POST['leggi_report'])) {
        $righe = \App\Tirocini\LettoreFoglio::righe($_FILES['file'] ?? []);
        if (is_string($righe)) {
            flash_set($righe, 'danger');
        } else {
            $esito = $sm->leggiReport($righe);
            $_SESSION['tir_motorie_report'] = $esito;
            flash_set(count($esito['righe']) . " tirocini di Scienze motorie nel file ({$esito['saltate']} di altri corsi e {$esito['interni']} interni all'Ateneo saltati): controlla e conferma.", $esito['righe'] ? 'info' : 'warning');
        }
    } elseif (isset($_POST['conferma_report'])) {
        $bozza = $_SESSION['tir_motorie_report']['righe'] ?? [];
        $scelte = $_POST['azienda'] ?? [];
        foreach ($bozza as $i => &$riga) { $riga['azienda_id'] = (int)($scelte[$i] ?? 0); }
        unset($riga);
        $r = $sm->applicaReport($bozza);
        unset($_SESSION['tir_motorie_report']);
        registra_log_audit($conn, 'Tirocini Scienze motorie: report del TSP caricato', $r);
        flash_set("Report caricato: {$r['nuovi']} tirocini inseriti" . ($r['doppioni'] ? ", {$r['doppioni']} già presenti" : '') . ($r['saltati'] ? ", {$r['saltati']} saltati" : '') . '.', $r['saltati'] ? 'warning' : 'success');
    } elseif (isset($_POST['scarta_report'])) {
        unset($_SESSION['tir_motorie_report']);
        flash_set('Caricamento annullato.', 'warning');
    }
    admin_redirect($url . '?r=' . time());
}

$aziende = $sm->stato();
$libere = $sm->convenzionateLibere();
$report = $_SESSION['tir_motorie_report']['righe'] ?? null;
$convenzionate = $report !== null ? \App\Core\App::per($conn)->get(\App\Core\Database::class)->righe('SELECT id, nome FROM tirocini_aziende WHERE convenzione = 1 ORDER BY nome') : [];
$etichetta = ['libera' => ['success', 'Libera'], 'ultimo' => ['warning', 'Ultimo posto'], 'satura' => ['danger', 'Satura']];
?>
<style>
.tm-az { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:12px 14px; margin-bottom:10px; }
.tm-az h3 { font-size:1rem; margin:0; }
.tm-az .sub { font-size:.82rem; color:#64748b; }
</style>
<?php
echo pn_testata('Tirocini · Scienze motorie', 'fa-person-running', "Per ogni azienda convenzionata scelta, quanti tirocini contemporanei accetta (di partenza 2). Un tirocinio occupa il posto dalla richiesta approvata fino alla data di fine. Gli studenti vedono solo se l'azienda è libera o satura e quando si libera un posto, mai i nomi.",
    '<a class="btn btn-outline-secondary btn-sm" href="../tirocini_motorie.php" target="_blank" rel="noopener"><i class="fa fa-eye me-1" aria-hidden="true"></i>Pagina per gli studenti</a>');
?>
<?php if ($report !== null): ?>
<section class="scu-sez border rounded p-3 bg-white mb-3">
    <h2 class="h6 fw-bold"><i class="fa fa-file-excel me-1 text-success" aria-hidden="true"></i>Controlla il report del TSP</h2>
    <p class="small text-secondary">Per ogni riga scegli l'azienda convenzionata (già scelta se il nome coincide); «— salta —» non la inserisce. Le aziende che non erano ancora nell'elenco di Scienze motorie vi entrano con 2 posti.</p>
    <form method="POST"><?php csrf_field(); ?>
        <div class="table-responsive"><table class="table table-sm align-middle">
            <thead><tr><th>Studente</th><th>Matricola</th><th>Azienda nel report</th><th>Periodo</th><th>Azienda convenzionata</th></tr></thead>
            <tbody>
            <?php foreach ($report as $i => $r): ?>
                <tr>
                    <td><?php echo $h($r['studente']); ?></td><td><?php echo $h($r['matricola']); ?></td><td><?php echo $h($r['azienda_nome']); ?></td>
                    <td><?php echo $r['inizio'] && $r['fine'] ? $h($data_it($r['inizio']) . ' – ' . $data_it($r['fine'])) : '<span class="text-danger">date mancanti</span>'; ?></td>
                    <td><select class="form-select form-select-sm" name="azienda[<?php echo (int)$i; ?>]" aria-label="Azienda per <?php echo $h($r['azienda_nome']); ?>">
                        <option value="0">— salta —</option>
                        <?php foreach ($convenzionate as $c): ?><option value="<?php echo (int)$c['id']; ?>"<?php echo (int)$c['id'] === (int)$r['azienda_id'] ? ' selected' : ''; ?>><?php echo $h($c['nome']); ?></option><?php endforeach; ?>
                    </select></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <button class="btn btn-success btn-sm" name="conferma_report" value="1" type="submit">Conferma e inserisci</button>
        <button class="btn btn-outline-secondary btn-sm" name="scarta_report" value="1" type="submit">Annulla</button>
    </form>
</section>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-lg-6"><form method="POST" class="border rounded p-3 bg-white h-100"><?php csrf_field(); ?>
        <h2 class="h6 fw-bold"><i class="fa fa-building-circle-check me-1 text-success" aria-hidden="true"></i>Aggiungi un'azienda</h2>
        <p class="small text-secondary mb-2">Scegli tra le aziende convenzionate della piattaforma e imposta il numero di posti.</p>
        <div class="row g-2">
            <div class="col-md-8"><label class="form-label small" for="tm_az">Azienda</label><select class="form-select" id="tm_az" name="azienda_id" required>
                <option value="">Scegli…</option><?php foreach ($libere as $l): ?><option value="<?php echo (int)$l['id']; ?>"><?php echo $h($l['nome'] . ($l['comune'] !== '' ? ' (' . $l['comune'] . ')' : '')); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label small" for="tm_posti">Posti</label><input class="form-control" id="tm_posti" type="number" name="posti" min="1" max="50" value="<?php echo \App\Tirocini\ServizioMotorie::POSTI_DI_PARTENZA; ?>"></div>
        </div>
        <button class="btn btn-success btn-sm mt-2" name="aggiungi_azienda" value="1" type="submit">Aggiungi</button>
    </form></div>
    <div class="col-lg-6"><form method="POST" enctype="multipart/form-data" class="border rounded p-3 bg-white h-100"><?php csrf_field(); ?>
        <h2 class="h6 fw-bold"><i class="fa fa-file-excel me-1 text-success" aria-hidden="true"></i>Carica il report del TSP</h2>
        <p class="small text-secondary mb-2">Il report dei tirocini curriculari (.xlsx): si leggono solo le righe di Scienze motorie [0827], senza i tirocini interni all'Ateneo. Prima di salvare vedi l'elenco.</p>
        <input class="form-control form-control-sm" type="file" name="file" accept=".xlsx,.csv" aria-label="Report del TSP" required>
        <button class="btn btn-outline-success btn-sm mt-2" name="leggi_report" value="1" type="submit">Leggi il file</button>
    </form></div>
</div>

<?php if (!$aziende): ?>
    <p class="text-muted">Nessuna azienda nell'elenco: aggiungine una, oppure carica il report del TSP.</p>
<?php endif; ?>
<?php foreach ($aziende as $a): [$cls, $txt] = $etichetta[$a['stato']]; ?>
<div class="tm-az" id="a<?php echo (int)$a['id']; ?>">
    <div class="d-flex flex-wrap gap-2 align-items-start justify-content-between">
        <div><h3><?php echo $h($a['nome']); ?> <span class="badge text-bg-<?php echo $cls; ?> fw-normal align-middle"><?php echo $txt; ?></span></h3>
            <div class="sub"><?php echo (int)$a['occupati']; ?> su <?php echo (int)$a['posti']; ?> posti occupati<?php echo $a['libera_dal'] ? ' · un posto si libera dal ' . $h($data_it($a['libera_dal'])) : ''; ?><?php echo $a['occupati'] > $a['posti'] ? ' · <strong class="text-danger">oltre il limite</strong>' : ''; ?></div></div>
        <form method="POST" class="d-flex gap-2 align-items-end"><?php csrf_field(); ?><input type="hidden" name="azienda_id" value="<?php echo (int)$a['id']; ?>">
            <div><label class="form-label small mb-0" for="p<?php echo (int)$a['id']; ?>">Posti</label><input class="form-control form-control-sm" style="width:5rem" id="p<?php echo (int)$a['id']; ?>" type="number" name="posti" min="1" max="50" value="<?php echo (int)$a['posti']; ?>"></div>
            <div><label class="form-label small mb-0" for="n<?php echo (int)$a['id']; ?>">Nota (non pubblica)</label><input class="form-control form-control-sm" id="n<?php echo (int)$a['id']; ?>" name="note" maxlength="300" value="<?php echo $h($a['note']); ?>"></div>
            <button class="btn btn-outline-primary btn-sm" name="salva_posti" value="1" type="submit">Salva</button>
            <button class="btn btn-outline-danger btn-sm" name="togli_azienda" value="1" type="submit" data-confirm="Togliere l'azienda dall'elenco di Scienze motorie, con i suoi tirocini?">Togli</button>
        </form>
    </div>
    <?php if ($a['periodi']): ?>
    <div class="table-responsive mt-2"><table class="table table-sm mb-2">
        <thead><tr><th>Studente</th><th>Matricola</th><th>Dal</th><th>Al</th><th></th></tr></thead>
        <tbody><?php foreach ($a['periodi'] as $p): ?>
            <tr><td><?php echo $h($p['studente']); ?></td><td><?php echo $h($p['matricola']); ?></td><td><?php echo $h($data_it($p['inizio'])); ?><?php echo $p['inizio'] > date('Y-m-d') ? ' <span class="badge text-bg-secondary fw-normal">non iniziato</span>' : ''; ?></td><td><?php echo $h($data_it($p['fine'])); ?></td>
                <td class="text-end"><form method="POST" class="d-inline"><?php csrf_field(); ?>
                    <button class="btn btn-outline-secondary btn-sm" name="concludi" value="<?php echo (int)$p['id']; ?>" type="submit" data-confirm="Il tirocinio finisce oggi e il posto si libera da domani?">Concluso oggi</button>
                    <button class="btn btn-outline-danger btn-sm" name="annulla" value="<?php echo (int)$p['id']; ?>" type="submit" data-confirm="Annullare questo tirocinio? Il posto torna libero subito.">Annulla</button></form></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
    <form method="POST" class="row g-2 align-items-end"><?php csrf_field(); ?><input type="hidden" name="azienda_id" value="<?php echo (int)$a['id']; ?>">
        <div class="col-md-3"><label class="form-label small mb-0">Studente</label><input class="form-control form-control-sm" name="studente" maxlength="150" placeholder="Cognome Nome"></div>
        <div class="col-md-2"><label class="form-label small mb-0">Matricola</label><input class="form-control form-control-sm" name="matricola" maxlength="20"></div>
        <div class="col-md-2"><label class="form-label small mb-0">Dal</label><input class="form-control form-control-sm" type="date" name="inizio" required></div>
        <div class="col-md-2"><label class="form-label small mb-0">Al</label><input class="form-control form-control-sm" type="date" name="fine" required></div>
        <div class="col-md-3"><button class="btn btn-success btn-sm" name="nuovo_periodo" value="1" type="submit"><i class="fa fa-plus me-1" aria-hidden="true"></i>Aggiungi tirocinio</button></div>
    </form>
</div>
<?php endforeach; ?>
<?php require_once 'admin_footer.php'; ?>
