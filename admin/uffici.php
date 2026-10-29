<?php
// uffici.php - Uffici del Dipartimento e di Ateneo, in un solo posto (Gestione del portale → Uffici).
// L'amministratore crea gli uffici che servono (protocollo, ufficio di un corso di studio, pagamenti, direzione…) e vi inserisce le persone,
// SEMPRE scelte dall'anagrafe di Ateneo. Da qui pratiche degli studenti, tutorato, prenotazioni di risorse e convenzioni prendono i destinatari.
// La Direzione del Dipartimento (Direttore e vice) sta qui; «Direttore assente» passa firme e avvisi al vice. Logica in src/Uffici/.
// Gli amministratori gestiscono tutto; chi gestisce la Didattica gestisce gli uffici del Dipartimento (non la Direzione né gli uffici di Ateneo).
require_once 'admin_header.php';

$puo_uffici_tutti = $is_full_admin;
$puo_uffici_dip = $is_full_admin || $puo_didattica_tutto;
if (!$puo_uffici_dip) nega_accesso();

function admin_redirect($url) { echo "<script>window.location.replace(" . json_encode($url) . ");</script>"; exit; }

$app_u = \App\Core\App::per($conn);
$sv = $app_u->get(\App\Uffici\ServizioUffici::class);
$dz = $app_u->get(\App\Uffici\Direzione::class);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// Ufficio scelto: per id o per chiave (es. ?u=direzione); l'ambito segue l'ufficio
$sel = 0;
$u_param = (string)($_GET['u'] ?? '');
if ($u_param !== '') {
    $tmp = ctype_digit($u_param) ? $sv->ufficio((int)$u_param) : $sv->perChiave(preg_replace('/[^a-z_]/', '', $u_param));
    $sel = $tmp ? (int)$tmp['id'] : 0;
}
$ambito = (string)($_GET['a'] ?? '');
if ($sel && ($tmp = $sv->ufficio($sel))) $ambito = (string)$tmp['ambito'];
if (!in_array($ambito, ['dipartimento', 'ateneo'], true)) $ambito = 'dipartimento';
if ($ambito === 'ateneo' && !$puo_uffici_tutti) $ambito = 'dipartimento';

// L'utente può agire su questo ufficio?
$puo_su = function (?array $u) use ($puo_uffici_tutti, $puo_uffici_dip): bool {
    if (!$u) return $puo_uffici_dip;
    if ($puo_uffici_tutti) return true;
    return $puo_uffici_dip && $u['ambito'] === 'dipartimento' && !\App\Uffici\ServizioUffici::fisso($u);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $id_u = (int)($_POST['ufficio_id'] ?? 0);
    $u_post = $id_u ? $sv->ufficio($id_u) : null;
    $amb_post = in_array($_POST['ambito'] ?? '', ['dipartimento', 'ateneo'], true) ? $_POST['ambito'] : 'dipartimento';

    // Direttore assente: lo possono impostare Direttore, vice, segreteria di direzione e amministratori
    if (isset($_POST['direttore_assente'])) {
        if (!$dz->puoImpostareAssenza($utente_admin)) { flash_set("Non puoi cambiare «Direttore assente».", 'danger'); admin_redirect("uffici.php?u=direzione"); }
        $attiva = $_POST['direttore_assente'] === '1';
        $err = $dz->imposta($attiva, (string)($_POST['rientro'] ?? ''), trim((string)($utente_admin['nome'] ?? '') . ' ' . (string)($utente_admin['cognome'] ?? '')) ?: (string)($utente_admin['email'] ?? ''));
        if (!$err) registra_log_audit($conn, $attiva ? "Direttore assente: attivato" : "Direttore assente: disattivato", ["Rientro" => (string)($_POST['rientro'] ?? '')]);
        flash_set($err ?? ($attiva ? "«Direttore assente» attivo: firme, link e avvisi vanno al vice direttore." : "«Direttore assente» spento: tornano al Direttore."), $err ? 'danger' : 'success');
        admin_redirect("uffici.php?u=direzione&r=" . time());
    }
    if (isset($_POST['salva_ufficio'])) {
        if (($id_u && !$puo_su($u_post)) || (!$id_u && !($puo_uffici_tutti || $amb_post === 'dipartimento'))) nega_accesso();
        [$nuovo_id, $err] = $sv->salva($id_u, $amb_post, (string)($_POST['nome'] ?? ''), (string)($_POST['descrizione'] ?? ''), !empty($_POST['attivo']), (int)($_POST['ordine'] ?? 0),
            (string)($_POST['tipo'] ?? ''), (int)($_POST['consiglio_id'] ?? 0) ?: null, !empty($_POST['smista']), !empty($_POST['segue_corsi']));
        if (!$err) registra_log_audit($conn, $id_u ? "Ufficio modificato" : "Ufficio creato", ["Ufficio" => (string)($_POST['nome'] ?? ''), "Ambito" => $amb_post]);
        flash_set($err ?? ($id_u ? "Ufficio salvato." : "Ufficio creato: ora aggiungi le persone dall'anagrafe."), $err ? 'danger' : 'success');
        admin_redirect("uffici.php?u=" . (int)$nuovo_id . "&r=" . time());
    }
    if (!$puo_su($u_post)) nega_accesso();
    if (isset($_POST['aggiungi_anagrafe'])) {
        $err = $sv->aggiungiDaAnagrafe($id_u, (string)($_POST['persona_id'] ?? ''), (string)($_POST['ruolo'] ?? ''), isset($_POST['compiti_inviati']) ? (array)($_POST['compiti'] ?? []) : null, (array)($_POST['corsi'] ?? []));
        if (!$err) registra_log_audit($conn, "Ufficio: persona aggiunta", ["Ufficio" => $id_u, "Persona" => (string)($_POST['persona_id'] ?? '')]);
        flash_set($err ?? "Persona aggiunta: entra con le sue credenziali Unical.", $err ? 'danger' : 'success');
        admin_redirect("uffici.php?u=$id_u&r=" . time());
    }
    if (isset($_POST['salva_persona'])) {
        $err = $sv->aggiornaPersona((int)$_POST['salva_persona'], (string)($_POST['ruolo'] ?? ''), (array)($_POST['compiti'] ?? []), (array)($_POST['corsi'] ?? []));
        if (!$err) registra_log_audit($conn, "Ufficio: persona modificata", ["Ufficio" => $id_u]);
        flash_set($err ?? "Persona salvata.", $err ? 'danger' : 'success');
        admin_redirect("uffici.php?u=$id_u&r=" . time());
    }
    if (isset($_POST['togli_persona'])) {
        $sv->togliPersona((int)$_POST['togli_persona']);
        registra_log_audit($conn, "Ufficio: persona tolta", ["Ufficio" => $id_u]);
        flash_set("Persona tolta dall'ufficio.", 'warning');
        admin_redirect("uffici.php?u=$id_u&r=" . time());
    }
    if (isset($_POST['elimina_ufficio'])) {
        $err = $sv->elimina($id_u);
        if (!$err) registra_log_audit($conn, "Ufficio eliminato", ["Ufficio" => $id_u]);
        flash_set($err ?? "Ufficio eliminato.", $err ? 'danger' : 'warning');
        admin_redirect($err ? "uffici.php?u=$id_u&r=" . time() : "uffici.php?a=" . ($u_post['ambito'] ?? 'dipartimento') . "&r=" . time());
    }
}

$elenco = $sv->elenco($ambito);
$nuovo = isset($_GET['nuovo']) || (!$sel && !$elenco);
if (!$sel && !$nuovo && $elenco) $sel = (int)$elenco[0]['id'];
$uff = $sel && !isset($_GET['nuovo']) ? $sv->ufficio($sel) : null;
if ($uff && $uff['ambito'] !== $ambito) { $uff = null; }
$persone = $uff ? $sv->persone((int)$uff['id']) : [];
$fisso = $uff ? \App\Uffici\ServizioUffici::fisso($uff) : false;
$e_direzione = $uff && ($uff['chiave'] ?? '') === 'direzione';
$modificabile = $puo_su($uff);
$flusso = $uff && $uff['ambito'] === 'dipartimento' && !$fisso;
$uso = $uff ? $sv->uso((int)$uff['id']) : ['moduli' => 0, 'pratiche' => 0, 'bandi' => 0, 'risorse' => 0];
$compiti_pr = $flusso ? \App\Uffici\ServizioUffici::compitiPredefiniti($uff) : [];
$e_assistenza = $uff && ($uff['chiave'] ?? '') === 'assistenza'; // le persone dell'Assistenza indicano i corsi che seguono: Didacticus smista le chat in base al corso di laurea
$corsi_sc = ($flusso && (int)($uff['segue_corsi'] ?? 0)) || $e_assistenza ? scelte_anagrafe_didattica($conn, 'corso_studio') : [];
$cons_tutti = $flusso ? consigli_didattica($conn) : [];
$ass = $dz->assenza();

$page_cfg['titolo'] = 'Uffici';
echo pn_testata('Uffici', 'fa-sitemap', "Gli uffici del Dipartimento e di Ateneo, con le persone scelte dall'anagrafe di Ateneo. Si creano qui una volta sola e si richiamano dove servono: pratiche degli studenti, tutorato, prenotazioni di risorse, convenzioni.");
?>
<?php echo flash_html(); ?>
<?php if ($puo_uffici_tutti): ?>
<?php echo pn_schede([
    'dipartimento' => ['href' => 'uffici.php?a=dipartimento', 'testo' => 'Uffici del Dipartimento', 'icona' => 'fa-sitemap', 'numero' => count($sv->elenco('dipartimento'))],
    'ateneo' => ['href' => 'uffici.php?a=ateneo', 'testo' => 'Uffici di Ateneo', 'icona' => 'fa-building-columns', 'numero' => count($sv->elenco('ateneo'))],
], $ambito, 'Dipartimento o Ateneo'); ?>
<?php endif; ?>
<style>
.uf-p { border:1px solid #e2e8f0; border-radius:10px; padding:.6rem .8rem; margin-bottom:.5rem; background:#fff; }
.uf-ruolo-dir { background:#fefce8; border-color:#fde68a; }
</style>
<div class="row g-3">
    <div class="col-lg-3">
        <div class="list-group shadow-sm mb-3">
            <?php foreach ($elenco as $x): ?>
                <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center<?php echo $uff && (int)$uff['id'] === (int)$x['id'] ? ' active' : ''; ?>" href="uffici.php?u=<?php echo (int)$x['id']; ?>">
                    <span><?php echo $x['chiave'] === 'direzione' ? '<i class="fa fa-user-tie me-1" aria-hidden="true"></i>' : ''; ?><?php echo $h($x['nome']); ?><?php echo (int)$x['attivo'] ? '' : ' <span class="badge bg-secondary">non attivo</span>'; ?></span>
                    <span class="badge bg-light text-dark border"><?php echo (int)$x['n_persone']; ?></span></a>
            <?php endforeach; ?>
            <?php if (!$elenco): ?><div class="list-group-item text-secondary small">Nessun ufficio ancora.</div><?php endif; ?>
        </div>
        <a class="btn btn-outline-danger fw-bold w-100" href="uffici.php?a=<?php echo $h($ambito); ?>&amp;nuovo=1"><i class="fa fa-plus me-1" aria-hidden="true"></i>Nuovo ufficio</a>
    </div>
    <div class="col-lg-9">
        <?php if ($e_direzione): ?>
        <div class="card border-0 shadow-sm mb-3" style="border-left:5px solid <?php echo $ass['attiva'] ? '#f59e0b' : '#15803d'; ?> !important;"><div class="card-body">
            <h2 class="h5 fw-bold"><i class="fa fa-user-tie me-1" aria-hidden="true"></i>Chi firma ora</h2>
            <?php $firma_ora = $dz->chiFirma(); $dir_p = $dz->direttore(); $vice_p = $dz->vice(); ?>
            <p class="mb-2"><?php if ($firma_ora['email'] === ''): ?><strong class="text-danger">Nessun Direttore indicato:</strong> aggiungi qui sotto il Direttore (ruolo «Direttore») scegliendolo dall'anagrafe.
                <?php else: ?><strong><?php echo $h($firma_ora['nome']); ?></strong> (<?php echo $h($firma_ora['email']); ?>)
                <?php echo $ass['attiva'] ? '<span class="badge bg-warning text-dark ms-1">Direttore assente: firma il vice</span>' : '<span class="badge bg-success ms-1">Direttore</span>'; ?><?php endif; ?></p>
            <p class="small text-secondary mb-2">Il Direttore e il vice hanno gli stessi permessi (Cruscotto del Direttore, firme). I link di firma e gli avvisi vanno al Direttore; con <strong>Direttore assente</strong> attivo passano al vice, finché non si spegne (o finché passa la data di rientro, se indicata). Lo possono attivare il Direttore, il vice, la segreteria di direzione e gli amministratori.</p>
            <?php if ($dz->puoImpostareAssenza($utente_admin)): ?>
                <form method="POST" class="d-flex flex-wrap gap-2 align-items-end"><?php csrf_field(); ?>
                    <?php if ($ass['attiva']): ?>
                        <div class="small text-secondary">Attivo<?php echo $ass['da'] !== '' ? ' da ' . $h($ass['da']) : ''; ?><?php echo $ass['il'] ? ' il ' . $h(date('d/m/Y H:i', strtotime($ass['il']))) : ''; ?><?php echo $ass['fino'] ? ', rientro il ' . $h(date('d/m/Y', strtotime($ass['fino']))) : ''; ?>.</div>
                        <button type="submit" name="direttore_assente" value="0" class="btn btn-success fw-bold"><i class="fa fa-user-check me-1" aria-hidden="true"></i>Il Direttore è tornato</button>
                    <?php else: ?>
                        <div><label class="form-label small fw-bold mb-0" for="ufRientro">Data di rientro (facoltativa)</label><input type="date" class="form-control form-control-sm" id="ufRientro" name="rientro" min="<?php echo date('Y-m-d'); ?>"></div>
                        <button type="submit" name="direttore_assente" value="1" class="btn btn-warning fw-bold"<?php echo $vice_p ? '' : ' disabled'; ?> data-confirm="Passare firme, link e avvisi al vice direttore?"><i class="fa fa-user-clock me-1" aria-hidden="true"></i>Direttore assente: passa al vice</button>
                        <?php if (!$vice_p): ?><span class="small text-danger">Aggiungi prima il vice direttore.</span><?php endif; ?>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </div></div>
        <?php endif; ?>

        <?php if ($modificabile && !$fisso || ($uff && $fisso && $puo_uffici_tutti) || (!$uff)): ?>
        <div class="card border-0 shadow-sm mb-3"><div class="card-body">
            <h2 class="h5 fw-bold"><?php echo $uff ? 'Ufficio' : 'Nuovo ufficio ' . ($ambito === 'ateneo' ? 'di Ateneo' : 'del Dipartimento'); ?></h2>
            <form method="POST" class="row g-2">
                <?php csrf_field(); ?>
                <input type="hidden" name="ufficio_id" value="<?php echo $uff ? (int)$uff['id'] : 0; ?>"><input type="hidden" name="ambito" value="<?php echo $h($ambito); ?>">
                <div class="col-md-5"><label class="form-label small fw-bold" for="ufN">Nome dell'ufficio *</label><input class="form-control" id="ufN" name="nome" value="<?php echo $h($uff['nome'] ?? ''); ?>" maxlength="150" required placeholder="<?php echo $ambito === 'ateneo' ? 'Ufficio pagamenti tutorato' : 'Ufficio protocollo'; ?>"></div>
                <div class="col-md-5"><label class="form-label small fw-bold" for="ufD">A cosa serve</label><input class="form-control" id="ufD" name="descrizione" value="<?php echo $h($uff['descrizione'] ?? ''); ?>" maxlength="500" placeholder="Riceve le pratiche, paga i tutorati…"></div>
                <div class="col-md-2 d-flex align-items-end"><?php if (!$fisso): ?><label class="form-check"><input class="form-check-input" type="checkbox" name="attivo" value="1"<?php echo !$uff || (int)$uff['attivo'] ? ' checked' : ''; ?>> Attivo</label><?php else: ?><input type="hidden" name="attivo" value="1"><span class="badge bg-secondary">fisso</span><?php endif; ?></div>
                <?php if ($ambito === 'dipartimento' && !$fisso): ?>
                    <div class="col-md-5"><label class="form-label small fw-bold" for="ufT">Nel flusso delle pratiche</label>
                        <select class="form-select form-select-sm" id="ufT" name="tipo"><?php foreach (\App\Didattica\Costanti::TIPI_UFFICIO as $k => $n): ?><option value="<?php echo $h($k); ?>"<?php echo ($uff['tipo'] ?? '') === $k ? ' selected' : ''; ?>><?php echo $h(explode(' (', $n)[0]); ?></option><?php endforeach; ?></select>
                        <div class="form-text">Protocollo, ufficio di un corso di studio (con il suo consiglio) o segreteria studenti; «Ufficio didattico» negli altri casi.</div></div>
                    <div class="col-md-5" id="ufCons"<?php echo ($uff['tipo'] ?? '') === 'cdl' ? '' : ' hidden'; ?>><label class="form-label small fw-bold" for="ufC">Consiglio dei corsi seguiti</label>
                        <select class="form-select form-select-sm" id="ufC" name="consiglio_id"><option value="0">-- scegli il consiglio --</option><?php foreach (consigli_didattica($conn) as $c): ?><option value="<?php echo (int)$c['id']; ?>"<?php echo (int)($uff['consiglio_id'] ?? 0) === (int)$c['id'] ? ' selected' : ''; ?>><?php echo $h(mb_strimwidth($c['nome'], 0, 90, '…')); ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-4 d-flex flex-wrap gap-3 align-items-end small">
                        <label class="form-check"><input class="form-check-input" type="checkbox" name="smista" value="1"<?php echo (int)($uff['smista'] ?? 0) ? ' checked' : ''; ?>> Riceve le pratiche nuove e le smista</label>
                        <label class="form-check"><input class="form-check-input" type="checkbox" name="segue_corsi" value="1"<?php echo (int)($uff['segue_corsi'] ?? 0) ? ' checked' : ''; ?>> Le persone seguono corsi di studio</label></div>
                    <div class="col-md-2"><label class="form-label small fw-bold" for="ufO">Ordine</label><input type="number" class="form-control form-control-sm" id="ufO" name="ordine" value="<?php echo (int)($uff['ordine'] ?? 0); ?>"></div>
                <?php endif; ?>
                <div class="col-12"><button type="submit" name="salva_ufficio" value="1" class="btn btn-danger fw-bold"><i class="fa fa-save me-1" aria-hidden="true"></i><?php echo $uff ? 'Salva' : "Crea l'ufficio"; ?></button></div>
            </form>
        </div></div>
        <?php elseif ($uff): ?>
        <div class="card border-0 shadow-sm mb-3"><div class="card-body"><h2 class="h5 fw-bold mb-1"><?php echo $h($uff['nome']); ?></h2><p class="small text-secondary mb-0"><?php echo $h($uff['descrizione']); ?> · <em>Gli amministratori modificano la Direzione.</em></p></div></div>
        <?php endif; ?>

        <?php if ($uff): ?>
        <div class="card border-0 shadow-sm mb-3"><div class="card-body">
            <h2 class="h5 fw-bold">Persone dell'ufficio (<?php echo count($persone); ?>)</h2>
            <?php if ($uff['ambito'] === 'dipartimento' && !$fisso): ?><p class="small text-secondary">Le persone si scelgono dall'anagrafe di Ateneo e accedono con le loro credenziali Unical. I <strong>compiti</strong> decidono cosa fanno nella Didattica: senza compiti la persona è nell'ufficio (riceve gli avvisi) ma non apre il pannello Didattica.</p><?php endif; ?>
            <?php foreach ($persone as $p): $comp_p = array_filter(explode(',', (string)$p['compiti'])); $cs_p = json_decode((string)$p['corsi'], true) ?: [];
                  $ruolo_dir = $e_direzione && in_array($p['ruolo'], \App\Uffici\ServizioUffici::RUOLI_DIREZIONE, true); ?>
                <form method="POST" class="uf-p<?php echo $ruolo_dir ? ' uf-ruolo-dir' : ''; ?>"><?php csrf_field(); ?><input type="hidden" name="ufficio_id" value="<?php echo (int)$uff['id']; ?>">
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <div class="flex-grow-1"><strong><?php echo $h($p['nominativo']); ?></strong>
                            <span class="small text-secondary"><?php echo $h($p['email']); ?><?php echo $p['struttura'] ? ' · ' . $h($p['struttura']) : ''; ?></span>
                            <?php if (!$p['persona_id']): ?><span class="badge bg-warning text-dark" title="Inserita prima che le persone fossero scelte solo dall'anagrafe: toglila e aggiungila dall'anagrafe">non collegata all'anagrafe</span><?php endif; ?></div>
                        <?php if ($modificabile): ?>
                            <?php if ($e_direzione): ?>
                                <select class="form-select form-select-sm" style="max-width:190px;" name="ruolo" aria-label="Ruolo nella Direzione"><?php foreach (array_merge([''], \App\Uffici\ServizioUffici::RUOLI_DIREZIONE) as $r_d): ?><option value="<?php echo $h($r_d); ?>"<?php echo $p['ruolo'] === $r_d ? ' selected' : ''; ?>><?php echo $h($r_d ?: '— altro ruolo —'); ?></option><?php endforeach; ?></select>
                            <?php else: ?>
                                <input type="text" class="form-control form-control-sm" style="max-width:190px;" name="ruolo" value="<?php echo $h($p['ruolo']); ?>" placeholder="Ruolo (es. Responsabile)" aria-label="Ruolo" maxlength="100">
                            <?php endif; ?>
                        <?php else: ?><span class="badge bg-light text-dark border"><?php echo $h($p['ruolo']); ?></span><?php endif; ?>
                    </div>
                    <?php if ($flusso && $modificabile): ?>
                    <div class="d-flex flex-wrap gap-3 align-items-center mt-1 small">
                        <?php foreach (\App\Didattica\Costanti::COMPITI_UFFICIO as $k => $n): ?><label class="form-check m-0"><input class="form-check-input" type="checkbox" name="compiti[]" value="<?php echo $h($k); ?>"<?php echo in_array($k, $comp_p, true) ? ' checked' : ''; ?>> <?php echo $h($n); ?></label><?php endforeach; ?>
                    </div>
                    <?php if ($corsi_sc): ?><div class="mt-1"><label class="small fw-bold">Corsi di studio seguiti</label>
                        <select class="form-select form-select-sm" name="corsi[]" multiple size="4" aria-label="Corsi di studio seguiti"><?php foreach ($corsi_sc as $g => $cc): ?><optgroup label="<?php echo $h($g); ?>"><?php foreach ($cc as $c): ?><option<?php echo in_array($c, $cs_p, true) ? ' selected' : ''; ?>><?php echo $h($c); ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select></div>
                    <?php elseif ($cs_p): ?><div class="small text-secondary mt-1"><i class="fa fa-graduation-cap me-1" aria-hidden="true"></i><?php echo $h(implode(' · ', $cs_p)); ?></div><?php endif; ?>
                    <?php elseif ($comp_p): ?><div class="small text-secondary mt-1">Compiti: <?php echo $h(implode(', ', array_map(fn($k) => \App\Didattica\Costanti::COMPITI_UFFICIO[$k] ?? $k, $comp_p))); ?></div><?php endif; ?>
                    <?php if ($e_assistenza && $modificabile): ?>
                    <div class="mt-1"><label class="small fw-bold">Corsi di laurea seguiti <span class="fw-normal text-secondary">(Didacticus manda a questa persona le chat degli studenti di questi corsi; Ctrl+clic per sceglierne più d'uno)</span></label>
                        <select class="form-select form-select-sm" name="corsi[]" multiple size="5" aria-label="Corsi di laurea seguiti"><?php foreach ($corsi_sc as $g => $cc): ?><optgroup label="<?php echo $h($g); ?>"><?php foreach ($cc as $c): ?><option<?php echo in_array($c, $cs_p, true) ? ' selected' : ''; ?>><?php echo $h($c); ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select></div>
                    <?php elseif ($e_assistenza && $cs_p): ?><div class="small text-secondary mt-1"><i class="fa fa-graduation-cap me-1" aria-hidden="true"></i><?php echo $h(implode(' · ', $cs_p)); ?></div><?php endif; ?>
                    <?php if ($modificabile): ?><div class="mt-1 d-flex gap-1">
                        <button type="submit" name="salva_persona" value="<?php echo (int)$p['id']; ?>" class="btn btn-sm btn-outline-primary py-0">Salva</button>
                        <button type="submit" name="togli_persona" value="<?php echo (int)$p['id']; ?>" class="btn btn-sm btn-outline-danger py-0" data-confirm="Togliere <?php echo $h($p['nominativo']); ?> dall'ufficio?" aria-label="Togli"><i class="fa fa-user-minus" aria-hidden="true"></i> Togli</button></div><?php endif; ?>
                </form>
            <?php endforeach; ?>
            <?php if (!$persone): ?><p class="text-secondary small">Nessuna persona: aggiungila qui sotto.</p><?php endif; ?>

            <?php if ($modificabile): ?>
            <h3 class="h6 fw-bold mt-3">Aggiungi una persona dall'anagrafe di Ateneo</h3>
            <form method="POST" id="formUfficioAn" class="bg-light rounded p-2"><?php csrf_field(); ?>
                <input type="hidden" name="ufficio_id" value="<?php echo (int)$uff['id']; ?>"><input type="hidden" name="persona_id" id="ufPid"><input type="hidden" name="compiti_inviati" value="1">
                <div class="d-flex flex-wrap gap-3 align-items-center small mb-2">
                    <?php if ($e_direzione): ?>
                        <select class="form-select form-select-sm" style="max-width:200px;" name="ruolo" aria-label="Ruolo nella Direzione"><?php foreach (\App\Uffici\ServizioUffici::RUOLI_DIREZIONE as $r_d): ?><option><?php echo $h($r_d); ?></option><?php endforeach; ?><option value="">Altro ruolo (segreteria…)</option></select>
                    <?php else: ?>
                        <input type="text" class="form-control form-control-sm" style="max-width:200px;" name="ruolo" placeholder="Ruolo (facoltativo)" aria-label="Ruolo" maxlength="100">
                    <?php endif; ?>
                    <?php if ($flusso): foreach (\App\Didattica\Costanti::COMPITI_UFFICIO as $k => $n): ?><label class="form-check m-0"><input class="form-check-input" type="checkbox" name="compiti[]" value="<?php echo $h($k); ?>"<?php echo in_array($k, $compiti_pr, true) ? ' checked' : ''; ?>> <?php echo $h($n); ?></label><?php endforeach; endif; ?>
                </div>
                <?php if ($corsi_sc): ?><div class="mb-2"><label class="small fw-bold">Corsi di studio seguiti</label><select class="form-select form-select-sm" name="corsi[]" multiple size="4" aria-label="Corsi di studio seguiti"><?php foreach ($corsi_sc as $g => $cc): ?><optgroup label="<?php echo $h($g); ?>"><?php foreach ($cc as $c): ?><option><?php echo $h($c); ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select></div><?php endif; ?>
                <?php echo html_ricerca_personale($conn, 'Aggiungi'); ?>
                <button type="submit" name="aggiungi_anagrafe" value="1" id="ufInvia" hidden></button>
            </form>
            <p class="form-text">Cerca per cognome: scegliendo la persona viene aggiunta con il ruolo<?php echo $flusso ? ' e i compiti' : ''; ?> indicati sopra. Non ci sono inserimenti a mano: se manca, va aggiornata l'anagrafe di Ateneo. Una persona può stare in più uffici.</p>
            <?php endif; ?>
        </div></div>

        <?php if ($uso['moduli'] || $uso['pratiche'] || $uso['bandi'] || $uso['risorse']): ?>
        <div class="alert alert-light border small"><i class="fa fa-link me-1" aria-hidden="true"></i><strong>Dove è usato:</strong>
            <?php $pl = fn(int $n, string $uno, string $molti) => $n . ' ' . ($n === 1 ? $uno : $molti);
            echo implode(' · ', array_filter([$uso['moduli'] ? 'nell\'iter di ' . $pl($uso['moduli'], 'modulo', 'moduli') : '', $uso['pratiche'] ? $pl($uso['pratiche'], 'pratica', 'pratiche') . ' in corso' : '', $uso['bandi'] ? 'paga i tutor di ' . $pl($uso['bandi'], 'bando', 'bandi') : '', $uso['risorse'] ? 'responsabile di ' . $pl($uso['risorse'], 'risorsa', 'risorse') : ''])); ?>.</div>
        <?php endif; ?>
        <?php if ($modificabile && !$fisso): ?>
        <form method="POST" class="mb-4"><?php csrf_field(); ?><input type="hidden" name="ufficio_id" value="<?php echo (int)$uff['id']; ?>">
            <button class="btn btn-sm btn-outline-danger" name="elimina_ufficio" value="1" data-confirm="Eliminare l'ufficio «<?php echo $h($uff['nome']); ?>» con le sue persone?"><i class="fa fa-trash me-1" aria-hidden="true"></i>Elimina l'ufficio</button></form>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<script>
var fUf = document.getElementById('formUfficioAn');
if (fUf) fUf.addEventListener('persona-scelta', function (e) {
    document.getElementById('ufPid').value = e.detail.id;
    document.getElementById('ufInvia').click();
});
var tUf = document.getElementById('ufT');
if (tUf) tUf.addEventListener('change', function () { document.getElementById('ufCons').hidden = tUf.value !== 'cdl'; });
</script>
<?php require_once 'admin_footer.php'; ?>
