<?php
// utenti.php - Gestione Unificata Utenti, Gruppi e Abilitazioni
require_once 'admin_header.php';

if (!$is_full_admin) {
    echo "<div class='alert alert-danger fw-bold shadow-sm m-4'><i class='fa fa-ban me-2'></i> Accesso negato. Riservato agli amministratori globali.</div>";
    require_once 'admin_footer.php';
    exit;
}

function admin_redirect($url) {
    echo "<script>window.location.replace('$url');</script>";
    exit;
}


// ==============================================================================
// BACKEND: UTENTI & GRUPPI
// ==============================================================================

if (isset($_POST['add_nuovo_ruolo'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $nome_ruolo = $conn->real_escape_string(trim($_POST['nome_ruolo'] ?? ''));
    if (!empty($nome_ruolo)) {
        $conn->query("INSERT INTO ruoli (nome) VALUES ('$nome_ruolo')");
        registra_log_audit($conn, "Creazione Gruppo", ["Nome" => $nome_ruolo]);
        flash_set("Gruppo creato!");
    }
    admin_redirect("utenti.php?p_id=$filtro_p");
}

if (isset($_POST['change_user_role'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $u_id_mod    = (int)$_POST['utente_id'];
    $r_id_mod    = (int)$_POST['ruolo_id'];
    $sec_roles   = isset($_POST['ruoli_secondari']) && is_array($_POST['ruoli_secondari'])
                   ? implode(',', array_map('intval', $_POST['ruoli_secondari'])) : '';
    $nome_mod    = $conn->real_escape_string(trim($_POST['nome']    ?? ''));
    $cognome_mod = $conn->real_escape_string(trim($_POST['cognome'] ?? ''));
    $email_mod   = $conn->real_escape_string(trim($_POST['email']   ?? ''));
    $extra = "";
    if (!empty($nome_mod))  $extra .= ", nome='$nome_mod', cognome='$cognome_mod'";
    if (!empty($email_mod)) $extra .= ", email='$email_mod', email_personalizzata=1";
    $conn->query("UPDATE utenti SET ruolo_id=$r_id_mod, ruoli_secondari='$sec_roles' $extra WHERE id=$u_id_mod");
    registra_log_audit($conn, "Modifica Utente", ["ID" => $u_id_mod, "Ruolo" => $r_id_mod]);
    flash_set("Utente aggiornato!");
    admin_redirect("utenti.php?p_id=$filtro_p");
}

if (isset($_POST['del_user'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $u_del_id = (int)$_POST['del_user'];
    if ($u_del_id !== (int)$_SESSION['utente_id']) {
        $conn->query("DELETE FROM prenotazioni WHERE utente_id=$u_del_id");
        $conn->query("DELETE FROM utenti WHERE id=$u_del_id");
        registra_log_audit($conn, "Eliminazione Utente", ["ID" => $u_del_id]);
        flash_set("Utente eliminato!");
    } else {
        flash_set("Non puoi eliminare il tuo account.", 'danger');
    }
    admin_redirect("utenti.php?p_id=$filtro_p");
}

// ==============================================================================
// BACKEND: ABILITAZIONI AREA
// ==============================================================================

if (isset($_POST['assegna_permessi'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $u_id       = (int)$_POST['utente_id'];
    $permessi   = $_POST['permessi'] ?? [];
    $ambito     = $_POST['ambito_eventi'] ?? 'tutti';
    $eventi_sel = $_POST['eventi_specifici'] ?? [];

    if ($u_id > 0 && !empty($permessi) && $filtro_p > 0) {
        // Pulizia totale per questo utente in questa area
        $res_p = $conn->query("SELECT permessi_gestori_json, gestori_utenti_ids FROM pagine_eventi WHERE id=$filtro_p LIMIT 1");
        if ($res_p && $p_row = $res_p->fetch_assoc()) {
            $p_json = json_decode($p_row['permessi_gestori_json'] ?: '{}', true) ?: [];
            if (isset($p_json[$u_id])) unset($p_json[$u_id]);
            $p_csv = array_diff(array_filter(array_map('trim', explode(',', $p_row['gestori_utenti_ids'] ?? ''))), [(string)$u_id]);
            $conn->query("UPDATE pagine_eventi SET permessi_gestori_json='".$conn->real_escape_string(json_encode($p_json))."', gestori_utenti_ids='".$conn->real_escape_string(implode(',', $p_csv))."' WHERE id=$filtro_p");
        }
        $res_ev = $conn->query("SELECT id, permessi_gestori_json, gestori_utenti_ids FROM eventi WHERE pagina_id=$filtro_p");
        if ($res_ev) while ($e_row = $res_ev->fetch_assoc()) {
            $e_id   = $e_row['id'];
            $e_json = json_decode($e_row['permessi_gestori_json'] ?: '{}', true) ?: [];
            $e_csv  = array_filter(array_map('trim', explode(',', $e_row['gestori_utenti_ids'] ?? '')));
            $ch = false;
            if (isset($e_json[$u_id])) { unset($e_json[$u_id]); $ch = true; }
            if (in_array((string)$u_id, $e_csv)) { $e_csv = array_diff($e_csv, [(string)$u_id]); $ch = true; }
            if ($ch) $conn->query("UPDATE eventi SET permessi_gestori_json='".$conn->real_escape_string(json_encode($e_json))."', gestori_utenti_ids='".$conn->real_escape_string(implode(',', $e_csv))."' WHERE id=$e_id");
        }
        // Nuova assegnazione
        if ($ambito === 'tutti') {
            $p_json[$u_id] = $permessi;
            $conn->query("UPDATE pagine_eventi SET permessi_gestori_json='".$conn->real_escape_string(json_encode($p_json))."' WHERE id=$filtro_p");
        } elseif ($ambito === 'specifici' && !empty($eventi_sel)) {
            foreach ($eventi_sel as $e_id_a) {
                $e_id_a = (int)$e_id_a;
                $r2 = $conn->query("SELECT permessi_gestori_json FROM eventi WHERE id=$e_id_a LIMIT 1");
                if ($r2 && $er2 = $r2->fetch_assoc()) {
                    $ej2 = json_decode($er2['permessi_gestori_json'] ?: '{}', true) ?: [];
                    $ej2[$u_id] = $permessi;
                    $conn->query("UPDATE eventi SET permessi_gestori_json='".$conn->real_escape_string(json_encode($ej2))."' WHERE id=$e_id_a");
                }
            }
        }
        registra_log_audit($conn, "Assegnati permessi", ["Utente" => $u_id, "Area" => $filtro_p, "Ambito" => $ambito]);
        flash_set("Abilitazioni salvate!");
    }
    admin_redirect("utenti.php?p_id=$filtro_p");
}

// Attiva / disattiva le email sulle prenotazioni per un gestore dell'area corrente
if (isset($_POST['toggle_notifiche'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $u_id   = (int)$_POST['utente_id'];
    $attiva = ($_POST['toggle_notifiche'] === '1');
    if ($u_id > 0 && $filtro_p > 0) {
        set_notifica_gestore($conn, $filtro_p, $u_id, $attiva);
        registra_log_audit($conn, $attiva ? "Attivate notifiche prenotazioni" : "Disattivate notifiche prenotazioni", ["Utente" => $u_id, "Area" => $filtro_p]);
        flash_set($attiva ? "Notifiche email sulle prenotazioni attivate." : "Notifiche email sulle prenotazioni disattivate.");
    }
    admin_redirect("utenti.php?p_id=$filtro_p");
}

if (isset($_POST['remove_user_all'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $u_id = (int)$_POST['utente_id'];
    if ($filtro_p > 0) {
        $res_p = $conn->query("SELECT permessi_gestori_json, gestori_utenti_ids FROM pagine_eventi WHERE id=$filtro_p LIMIT 1");
        if ($res_p && $p_row = $res_p->fetch_assoc()) {
            $p_json = json_decode($p_row['permessi_gestori_json'] ?: '{}', true) ?: [];
            if (isset($p_json[$u_id])) unset($p_json[$u_id]);
            $p_csv = array_diff(array_filter(array_map('trim', explode(',', $p_row['gestori_utenti_ids'] ?? ''))), [(string)$u_id]);
            $conn->query("UPDATE pagine_eventi SET permessi_gestori_json='".$conn->real_escape_string(json_encode($p_json))."', gestori_utenti_ids='".$conn->real_escape_string(implode(',', $p_csv))."' WHERE id=$filtro_p");
            $conn->query("UPDATE pagine_eventi SET gestore_utente_id=0 WHERE id=$filtro_p AND gestore_utente_id=$u_id");
        }
        $res_ev = $conn->query("SELECT id, permessi_gestori_json, gestori_utenti_ids FROM eventi WHERE pagina_id=$filtro_p");
        if ($res_ev) while ($e_row = $res_ev->fetch_assoc()) {
            $e_id   = $e_row['id'];
            $e_json = json_decode($e_row['permessi_gestori_json'] ?: '{}', true) ?: [];
            $e_csv  = array_filter(array_map('trim', explode(',', $e_row['gestori_utenti_ids'] ?? '')));
            $ch = false;
            if (isset($e_json[$u_id])) { unset($e_json[$u_id]); $ch = true; }
            if (in_array((string)$u_id, $e_csv)) { $e_csv = array_diff($e_csv, [(string)$u_id]); $ch = true; }
            if ($ch) $conn->query("UPDATE eventi SET permessi_gestori_json='".$conn->real_escape_string(json_encode($e_json))."', gestori_utenti_ids='".$conn->real_escape_string(implode(',', $e_csv))."' WHERE id=$e_id");
        }
        registra_log_audit($conn, "Revoca permessi area", ["Utente" => $u_id, "Area" => $filtro_p]);
        flash_set("Abilitazioni revocate per quest'area.");
    }
    admin_redirect("utenti.php?p_id=$filtro_p");
}

// ==============================================================================
// PREPARAZIONE DATI
// ==============================================================================

$ruoli = get_ruoli($conn);
$ruoli_map = [];
foreach ($ruoli as $r) $ruoli_map[(int)$r['id']] = $r['nome'];

$utenti = [];
$res_ut = $conn->query("SELECT * FROM utenti ORDER BY cognome ASC, nome ASC");
if ($res_ut) while ($row = $res_ut->fetch_assoc()) {
    $row['ruolo_nome'] = $ruoli_map[(int)($row['ruolo_id'] ?? 5)] ?? 'Ospiti';
    $utenti[] = $row;
}

$eventi_area   = [];
$mappa_gestori = [];
if ($filtro_p > 0) {
    $res_el = $conn->query("SELECT id, titolo FROM eventi WHERE pagina_id=$filtro_p ORDER BY ordine ASC, id DESC");
    if ($res_el) while ($e = $res_el->fetch_assoc()) $eventi_area[$e['id']] = $e['titolo'];

    $res_p2 = $conn->query("SELECT gestore_utente_id, gestori_utenti_ids, permessi_gestori_json FROM pagine_eventi WHERE id=$filtro_p LIMIT 1");
    if ($res_p2 && $pr = $res_p2->fetch_assoc()) {
        $pj = json_decode($pr['permessi_gestori_json'] ?: '{}', true) ?: [];
        foreach ($pj as $uid => $perms)
            $mappa_gestori[(int)$uid] = ['ambito' => 'tutti', 'permessi' => $perms, 'eventi_ids' => [], 'eventi' => []];
        // Gestore principale (campo legacy): riceve le notifiche, quindi deve comparire qui
        $uid_princ = (int)($pr['gestore_utente_id'] ?? 0);
        if ($uid_princ > 0 && !isset($mappa_gestori[$uid_princ]))
            $mappa_gestori[$uid_princ] = ['ambito' => 'tutti', 'permessi' => ['full'], 'eventi_ids' => [], 'eventi' => []];
        foreach (array_filter(array_map('trim', explode(',', $pr['gestori_utenti_ids'] ?? ''))) as $uid)
            if (!isset($mappa_gestori[(int)$uid]))
                $mappa_gestori[(int)$uid] = ['ambito' => 'tutti', 'permessi' => ['eventi','iscritti','sondaggi','form'], 'eventi_ids' => [], 'eventi' => []];
    }
    $res_es = $conn->query("SELECT id, titolo, gestori_utenti_ids, permessi_gestori_json FROM eventi WHERE pagina_id=$filtro_p");
    if ($res_es) while ($er = $res_es->fetch_assoc()) {
        $eid = $er['id']; $etit = $er['titolo'];
        $ej  = json_decode($er['permessi_gestori_json'] ?: '{}', true) ?: [];
        foreach ($ej as $uid => $perms) {
            $uid = (int)$uid;
            if (!isset($mappa_gestori[$uid])) $mappa_gestori[$uid] = ['ambito' => 'specifici', 'permessi' => [], 'eventi_ids' => [], 'eventi' => []];
            if ($mappa_gestori[$uid]['ambito'] !== 'tutti') {
                if (!in_array($eid, $mappa_gestori[$uid]['eventi_ids'])) { $mappa_gestori[$uid]['eventi_ids'][] = $eid; $mappa_gestori[$uid]['eventi'][] = $etit; }
                $mappa_gestori[$uid]['permessi'] = array_unique(array_merge($mappa_gestori[$uid]['permessi'], $perms));
            }
        }
        foreach (array_filter(array_map('trim', explode(',', $er['gestori_utenti_ids'] ?? ''))) as $uid) {
            $uid = (int)$uid;
            if (!isset($mappa_gestori[$uid])) $mappa_gestori[$uid] = ['ambito' => 'specifici', 'permessi' => ['eventi','iscritti'], 'eventi_ids' => [], 'eventi' => []];
            if ($mappa_gestori[$uid]['ambito'] !== 'tutti' && !in_array($eid, $mappa_gestori[$uid]['eventi_ids'])) {
                $mappa_gestori[$uid]['eventi_ids'][] = $eid; $mappa_gestori[$uid]['eventi'][] = $etit;
            }
        }
    }
    // Chi riceve le email sulle prenotazioni (null = mai configurato → tutti i gestori)
    $notifiche_attive = get_notifiche_gestori_attive($conn, $filtro_p);
}

// Riepilogo in cima: amministratori del portale e abilitati di ogni area (intera area o singoli eventi)
$utenti_per_id = [];
foreach ($utenti as $u_r) $utenti_per_id[(int)$u_r['id']] = $u_r;
$amministratori = array_filter($utenti, fn($x) => (int)($x['ruolo_id'] ?? 5) === 1 || in_array('1', array_map('trim', explode(',', $x['ruoli_secondari'] ?? '')), true));
$ids_amministratori = array_map('intval', array_column($amministratori, 'id'));
$panoramica_aree = [];
foreach ($pagine_disponibili as $pa) {
    $ids_intera = ids_gestori_da_campi($pa['gestore_utente_id'] ?? 0, $pa['gestori_utenti_ids'] ?? '', $pa['permessi_gestori_json'] ?? '');
    $ids_eventi = [];
    $r_ge = $conn->query("SELECT gestori_utenti_ids, permessi_gestori_json FROM eventi WHERE pagina_id = " . (int)$pa['id']);
    while ($r_ge && $ge = $r_ge->fetch_assoc()) {
        foreach (ids_gestori_da_campi(0, $ge['gestori_utenti_ids'], $ge['permessi_gestori_json']) as $id_g) if (!in_array($id_g, $ids_intera, true)) $ids_eventi[$id_g] = true;
    }
    $panoramica_aree[] = ['a' => $pa, 'intera' => $ids_intera, 'eventi' => array_keys($ids_eventi)];
}
$nome_utente = fn(int $id) => isset($utenti_per_id[$id]) ? trim(mb_strtoupper($utenti_per_id[$id]['cognome'] . ' ' . $utenti_per_id[$id]['nome'])) : "Utente #$id (non più presente)";
$etichette_perm = ['full' => 'Admin area', 'eventi' => 'Eventi', 'iscritti' => 'Iscritti', 'sondaggi' => 'Sondaggi', 'form' => 'Form'];
?>

<!-- ============================================================
     FRONT-END
     ============================================================ -->
<?php $col_u = '#1e293b'; ?>
<style>
.usr-card { border:1px solid #e2e8f0; border-radius:12px; background:#fff; box-shadow:0 1px 5px rgba(0,0,0,.05); margin-bottom:6px; overflow:hidden; transition:box-shadow .15s; }
.usr-card:hover { box-shadow:0 3px 12px rgba(0,0,0,.1); }
.usr-header { padding:12px 16px; cursor:pointer; display:flex; align-items:center; gap:12px; user-select:none; }
.usr-header:hover { background:#f8fafc; }
.usr-body { border-top:1px solid #f1f5f9; background:#fafbfc; }
.usr-section { padding:14px 18px; border-bottom:1px solid #f1f5f9; }
.usr-section:last-child { border-bottom:none; }
.usr-section-label { font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#94a3b8; margin-bottom:10px; display:flex; align-items:center; gap:6px; }
.perm-chip { display:inline-flex; align-items:center; gap:4px; padding:2px 9px; border-radius:20px; font-size:.72rem; font-weight:700; }
.chevron-icon { transition:transform .2s; flex-shrink:0; }
.usr-header[aria-expanded="true"] .chevron-icon { transform:rotate(180deg); }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="fw-bold text-dark mb-0"><i class="fa fa-user-shield me-2 text-danger"></i>Utenti, Gruppi & Abilitazioni</h4>
    <button class="btn btn-sm btn-outline-danger fw-bold" style="border-radius:8px;" data-bs-toggle="modal" data-bs-target="#modCreaGruppo">
        <i class="fa fa-plus me-1"></i>Crea Gruppo
    </button>
</div>

<!-- Barra cerca + info area -->
<div class="d-flex gap-2 mb-3 flex-wrap align-items-center">
    <div class="input-group input-group-sm" style="max-width:300px;">
        <span class="input-group-text bg-white"><i class="fa fa-search text-muted"></i></span>
        <input type="text" id="cercaUtente" class="form-control" placeholder="Cerca per nome, email, matricola..." oninput="filtraUtenti(this.value)">
    </div>
    <small class="text-muted"><?php echo count($utenti); ?> utenti totali</small>
    <?php if ($filtro_p > 0): ?>
        <label for="selAreaAbil" class="small fw-bold ms-md-auto mb-0" style="color:#5b21b6;"><i class="fa fa-key me-1" aria-hidden="true"></i>Abilitazioni sull'area</label>
        <select id="selAreaAbil" class="form-select form-select-sm fw-bold" style="max-width:280px;border-color:#c4b5fd;color:#5b21b6;" onchange="location.href='utenti.php?p_id=' + this.value">
            <?php foreach ($pagine_disponibili as $p_opt): ?>
                <option value="<?php echo (int)$p_opt['id']; ?>" <?php echo (int)$p_opt['id'] === (int)$filtro_p ? 'selected' : ''; ?>><?php echo htmlspecialchars($p_opt['titolo']); ?><?php echo (int)($p_opt['visibile'] ?? 1) === 0 ? ' (nascosta)' : ''; ?></option>
            <?php endforeach; ?>
        </select>
    <?php else: ?>
        <span class="badge rounded-pill bg-warning text-dark" style="font-size:.75rem;">
            <i class="fa fa-exclamation-triangle me-1"></i>Seleziona un'area per gestire le abilitazioni
        </span>
    <?php endif; ?>
</div>

<!-- Riepilogo: chi amministra il portale e chi è abilitato sull'area -->
<style>
.riep-card { border:1px solid #e2e8f0; border-radius:12px; background:#fff; box-shadow:0 1px 5px rgba(0,0,0,.05); height:100%; }
.riep-card h6 { font-size:.72rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:#64748b; margin:0; }
.riep-riga { display:flex; align-items:flex-start; gap:10px; padding:8px 0; border-top:1px dashed #e2e8f0; }
.riep-riga:first-child { border-top:0; }
.riep-nome { font-weight:700; color:#0f172a; background:none; border:0; padding:0; text-align:left; }
.riep-nome:hover { text-decoration:underline; }
</style>
<div class="row g-3 mb-3">
    <div class="col-lg-4">
        <div class="riep-card p-3">
            <h6 class="mb-2"><i class="fa fa-crown me-1 text-danger" aria-hidden="true"></i>Amministratori del portale (<?php echo count($amministratori); ?>)</h6>
            <?php foreach ($amministratori as $ad): ?>
                <div class="riep-riga">
                    <div style="min-width:0;">
                        <button type="button" class="riep-nome" data-apri="<?php echo (int)$ad['id']; ?>"><?php echo htmlspecialchars($nome_utente((int)$ad['id'])); ?></button>
                        <?php if ((int)$ad['id'] === (int)$_SESSION['utente_id']): ?><span class="badge bg-secondary-subtle text-secondary-emphasis ms-1">tu</span><?php endif; ?>
                        <?php if ((int)($ad['ruolo_id'] ?? 5) !== 1): ?><span class="badge bg-light text-dark border ms-1" title="Amministratore come gruppo secondario">secondario</span><?php endif; ?>
                        <div class="small text-muted text-truncate"><?php echo htmlspecialchars($ad['email'] ?? ''); ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
            <div class="small text-muted mt-2">Vedono e gestiscono tutte le aree.</div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="riep-card p-3">
            <h6 class="mb-2"><i class="fa fa-key me-1" style="color:#5b21b6;" aria-hidden="true"></i>Abilitati su <?php echo htmlspecialchars($page_cfg['titolo'] ?? ''); ?> (<?php echo count($mappa_gestori); ?>)</h6>
            <?php if (!$mappa_gestori): ?>
                <div class="text-muted small py-2">Nessuno: in quest'area lavorano solo gli amministratori. Per abilitare qualcuno apri la sua scheda qui sotto, sezione "Abilitazioni Area".</div>
            <?php endif; ?>
            <?php foreach ($mappa_gestori as $id_g => $g): $notif = $notifiche_attive === null || in_array((int)$id_g, (array)$notifiche_attive); ?>
                <div class="riep-riga flex-wrap">
                    <div style="min-width:200px;flex:1;">
                        <button type="button" class="riep-nome" data-apri="<?php echo (int)$id_g; ?>"><?php echo htmlspecialchars($nome_utente((int)$id_g)); ?></button>
                        <div class="small text-muted">
                            <?php if ($g['ambito'] === 'tutti'): ?><i class="fa fa-folder-open me-1" aria-hidden="true"></i>Tutta l'area
                            <?php else: ?><i class="fa fa-calendar-check me-1" aria-hidden="true"></i><?php echo count($g['eventi']) === 1 ? 'Solo l\'evento' : 'Solo ' . count($g['eventi']) . ' eventi'; ?>: <?php echo htmlspecialchars(implode(', ', $g['eventi'])); ?><?php endif; ?>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-1 align-items-center">
                        <?php foreach (array_unique((array)$g['permessi']) as $pm): ?><span class="perm-chip" style="<?php echo $pm === 'full' ? 'background:#fee2e2;color:#991b1b;' : 'background:#ede9fe;color:#5b21b6;'; ?>"><?php echo htmlspecialchars($etichette_perm[$pm] ?? $pm); ?></span><?php endforeach; ?>
                        <span class="perm-chip" style="<?php echo $notif ? 'background:#dcfce7;color:#166534;' : 'background:#f1f5f9;color:#64748b;'; ?>" title="<?php echo $notif ? 'Riceve le email delle prenotazioni' : 'Non riceve le email delle prenotazioni'; ?>"><i class="fa <?php echo $notif ? 'fa-bell' : 'fa-bell-slash'; ?>" aria-hidden="true"></i></span>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php $altre = array_filter($panoramica_aree, fn($x) => (int)$x['a']['id'] !== (int)$filtro_p); if ($altre): ?>
                <div class="mt-3 pt-2 border-top small">
                    <div class="fw-bold text-secondary mb-1">Nelle altre aree</div>
                    <?php foreach ($altre as $pa): $tot = count($pa['intera']) + count($pa['eventi']); ?>
                        <div class="mb-1">
                            <a href="utenti.php?p_id=<?php echo (int)$pa['a']['id']; ?>" class="fw-semibold text-decoration-none"><?php echo htmlspecialchars($pa['a']['titolo']); ?></a>:
                            <?php if ($tot === 0): ?><span class="text-muted">nessun abilitato</span>
                            <?php else:
                                $nomi = array_merge(array_map(fn($i) => $nome_utente($i), $pa['intera']), array_map(fn($i) => $nome_utente($i) . ' (eventi)', $pa['eventi']));
                                echo htmlspecialchars(implode(', ', $nomi));
                            endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="form-check form-switch mb-2">
    <input class="form-check-input" type="checkbox" id="soloAbilitati" onchange="filtraUtenti(document.getElementById('cercaUtente').value)">
    <label class="form-check-label small fw-bold" for="soloAbilitati">Mostra solo amministratori e abilitati su quest'area</label>
</div>

<!-- Lista utenti -->
<div id="listaUtenti">
<?php foreach ($utenti as $u):
    $uid       = (int)$u['id'];
    $u_sec_arr = array_filter(explode(',', $u['ruoli_secondari'] ?? ''));
    $is_me     = $uid === (int)$_SESSION['utente_id'];
    $is_admin  = (int)($u['ruolo_id'] ?? 5) === 1;
    $initials  = strtoupper(substr($u['nome'] ?? 'U', 0, 1) . substr($u['cognome'] ?? '', 0, 1));
    $av_bg     = $is_admin ? '#dc2626' : '#475569';

    // Dati abilitazioni per questo utente
    $mg = $mappa_gestori[$uid] ?? null;
?>
<div class="usr-card" id="card<?php echo $uid; ?>" data-abil="<?php echo ($mg || in_array($uid, $ids_amministratori, true)) ? '1' : '0'; ?>" data-search="<?php echo htmlspecialchars(strtolower(($u['nome']??'').' '.($u['cognome']??'').' '.($u['email']??'').' '.($u['matricola_studente']??'').' '.($u['matricola_dipendente']??''))); ?>">

    <!-- HEADER (click to expand) -->
    <div class="usr-header" data-bs-toggle="collapse" data-bs-target="#usr<?php echo $uid; ?>" aria-expanded="false">
        <!-- Avatar -->
        <div style="width:38px;height:38px;border-radius:50%;background:<?php echo $av_bg; ?>;display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:700;color:#fff;flex-shrink:0;"><?php echo $initials; ?></div>

        <!-- Nome + email -->
        <div style="flex:1;min-width:0;">
            <div class="fw-semibold text-dark" style="font-size:.88rem;"><?php echo htmlspecialchars(($u['cognome'] ?? '') . ' ' . ($u['nome'] ?? '')); ?>
                <?php if ($is_me): ?><span class="badge bg-light text-muted border ms-1" style="font-size:.62rem;">Tu</span><?php endif; ?>
            </div>
            <div class="text-muted" style="font-size:.72rem;"><?php echo htmlspecialchars($u['email'] ?? ''); ?></div>
        </div>

        <!-- Matricole -->
        <div class="d-none d-md-flex flex-column gap-1" style="min-width:90px;">
            <?php if (!empty($u['matricola_studente'])): ?>
                <span style="font-size:.65rem;background:#dbeafe;color:#1d4ed8;padding:1px 6px;border-radius:4px;"><?php echo htmlspecialchars($u['matricola_studente']); ?></span>
            <?php endif; ?>
            <?php if (!empty($u['matricola_dipendente'])): ?>
                <span style="font-size:.65rem;background:#dcfce7;color:#166534;padding:1px 6px;border-radius:4px;"><?php echo htmlspecialchars($u['matricola_dipendente']); ?></span>
            <?php endif; ?>
        </div>

        <!-- Ruolo badge -->
        <div class="d-none d-sm-block" style="min-width:100px;text-align:center;">
            <span class="badge" style="background:<?php echo $is_admin ? '#fee2e2' : '#f1f5f9'; ?>;color:<?php echo $is_admin ? '#991b1b' : '#475569'; ?>;font-size:.7rem;">
                <?php echo htmlspecialchars($u['ruolo_nome']); ?>
            </span>
        </div>

        <!-- Abilitazione area badge -->
        <?php if ($filtro_p > 0 && $mg): ?>
        <div class="d-none d-md-block">
            <?php if ($mg['ambito'] === 'tutti'): ?>
                <span class="perm-chip" style="background:#ede9fe;color:#5b21b6;"><i class="fa fa-folder-open"></i>Area</span>
            <?php else: ?>
                <span class="perm-chip" style="background:#dcfce7;color:#166534;"><i class="fa fa-crosshairs"></i><?php echo count($mg['eventi_ids']); ?> eventi</span>
            <?php endif; ?>
        </div>
        <?php $notif_on = ($notifiche_attive === null || in_array($uid, $notifiche_attive, true)); ?>
        <form method="POST" class="d-inline m-0" onclick="event.stopPropagation()">
            <?php csrf_field(); ?>
            <input type="hidden" name="utente_id" value="<?php echo $uid; ?>">
            <?php if ($notif_on): ?>
                <button type="submit" name="toggle_notifiche" value="0" class="btn btn-sm btn-success fw-bold py-0 px-2" style="font-size:.72rem;border-radius:20px;" title="Riceve un'email a ogni prenotazione/disdetta in quest'area. Clicca per disattivare."><i class="fa fa-bell me-1"></i>Notifiche</button>
            <?php else: ?>
                <button type="submit" name="toggle_notifiche" value="1" class="btn btn-sm btn-outline-secondary fw-bold py-0 px-2" style="font-size:.72rem;border-radius:20px;" title="Non riceve email sulle prenotazioni di quest'area. Clicca per attivare."><i class="fa fa-bell-slash me-1"></i>Notifiche</button>
            <?php endif; ?>
        </form>
        <?php if (empty($u['email'])): ?><span class="text-danger" style="font-size:.7rem;" title="Senza email non può ricevere notifiche"><i class="fa fa-exclamation-triangle"></i></span><?php endif; ?>
        <?php endif; ?>

        <!-- Ultimo accesso -->
        <div class="d-none d-lg-block text-muted" style="font-size:.68rem;min-width:80px;text-align:right;">
            <?php echo !empty($u['ultimo_accesso']) ? date('d/m/y H:i', strtotime($u['ultimo_accesso'])) : 'mai'; ?>
        </div>

        <!-- Delete -->
        <?php if (!$is_me): ?>
        <form method="POST" class="d-inline" onclick="event.stopPropagation()">
            <?php csrf_field(); ?>
            <input type="hidden" name="del_user" value="<?php echo $uid; ?>">
            <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
            <button type="submit" class="act-btn red" style="width:28px;height:28px;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;font-size:.75rem;border:1px solid #fecaca;background:#fff1f2;color:#dc2626;cursor:pointer;" data-confirm="Eliminare definitivamente questo utente?" title="Elimina"><i class="fa fa-trash"></i></button>
        </form>
        <?php endif; ?>

        <i class="fa fa-chevron-down chevron-icon text-muted" style="font-size:.75rem;"></i>
    </div>

    <!-- CORPO COLLASSABILE -->
    <div class="collapse" id="usr<?php echo $uid; ?>">
        <div class="usr-body">

            <!-- SEZIONE 1: ANAGRAFICA -->
            <div class="usr-section">
                <div class="usr-section-label"><i class="fa fa-id-card"></i>Anagrafica</div>
                <form method="POST" class="row g-2 align-items-end">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="utente_id" value="<?php echo $uid; ?>">
                    <input type="hidden" name="ruolo_id" value="<?php echo $u['ruolo_id']; ?>">
                    <?php foreach ($u_sec_arr as $sr): ?><input type="hidden" name="ruoli_secondari[]" value="<?php echo (int)$sr; ?>"><?php endforeach; ?>
                    <div class="col-sm-3">
                        <label class="form-label small fw-bold mb-1">Nome</label>
                        <input type="text" name="nome" class="form-control form-control-sm" value="<?php echo htmlspecialchars($u['nome'] ?? ''); ?>" required>
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label small fw-bold mb-1">Cognome</label>
                        <input type="text" name="cognome" class="form-control form-control-sm" value="<?php echo htmlspecialchars($u['cognome'] ?? ''); ?>">
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small fw-bold mb-1">Email</label>
                        <input type="email" name="email" class="form-control form-control-sm" value="<?php echo htmlspecialchars($u['email'] ?? ''); ?>">
                    </div>
                    <div class="col-sm-2">
                        <button type="submit" name="change_user_role" class="btn btn-sm btn-primary fw-bold w-100">Salva</button>
                    </div>
                </form>
                <?php if (!empty($u['codice_fiscale']) || !empty($u['matricola_studente']) || !empty($u['matricola_dipendente'])): ?>
                <div class="mt-2 d-flex gap-2 flex-wrap" style="font-size:.72rem;color:#64748b;">
                    <?php if (!empty($u['codice_fiscale'])): ?><span>CF: <code><?php echo htmlspecialchars($u['codice_fiscale']); ?></code></span><?php endif; ?>
                    <?php if (!empty($u['matricola_studente'])): ?><span>Matricola studente: <strong><?php echo htmlspecialchars($u['matricola_studente']); ?></strong></span><?php endif; ?>
                    <?php if (!empty($u['matricola_dipendente'])): ?><span>Matricola dipendente: <strong><?php echo htmlspecialchars($u['matricola_dipendente']); ?></strong></span><?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- SEZIONE 2: RUOLO & GRUPPI -->
            <div class="usr-section">
                <div class="usr-section-label"><i class="fa fa-user-tag"></i>Ruolo & Gruppi</div>
                <form method="POST" class="row g-2 align-items-end">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="utente_id" value="<?php echo $uid; ?>">
                    <input type="hidden" name="nome" value="">
                    <input type="hidden" name="cognome" value="">
                    <div class="col-sm-4">
                        <label class="form-label small fw-bold mb-1">Ruolo Principale</label>
                        <select name="ruolo_id" class="form-select form-select-sm select2-role" style="width:100%;">
                            <?php foreach ($ruoli as $r): ?>
                                <option value="<?php echo $r['id']; ?>" <?php echo ($u['ruolo_id'] == $r['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($r['nome']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label small fw-bold mb-1">Gruppi Secondari</label>
                        <select name="ruoli_secondari[]" multiple class="form-select form-select-sm select2-sec-role" style="width:100%;">
                            <?php foreach ($ruoli as $r): ?>
                                <option value="<?php echo $r['id']; ?>" <?php echo in_array((string)$r['id'], $u_sec_arr) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($r['nome']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-2">
                        <button type="submit" name="change_user_role" class="btn btn-sm btn-primary fw-bold w-100" style="height:38px;">Salva</button>
                    </div>
                </form>
            </div>

            <!-- SEZIONE 3: ABILITAZIONI AREA -->
            <div class="usr-section">
                <div class="usr-section-label"><i class="fa fa-key"></i>Abilitazioni Area
                    <?php if ($filtro_p > 0): ?>
                        <span style="font-size:.65rem;background:#f1f5f9;color:#64748b;padding:1px 7px;border-radius:10px;font-weight:600;"><?php echo htmlspecialchars($page_cfg['titolo'] ?? ''); ?></span>
                    <?php endif; ?>
                </div>

                <?php if ($filtro_p == 0): ?>
                    <p class="text-muted small mb-0"><i class="fa fa-info-circle me-1"></i>Seleziona un'area dal menu in alto per gestire le abilitazioni di questo utente.</p>

                <?php elseif ($is_admin): ?>
                    <span class="perm-chip" style="background:#fee2e2;color:#991b1b;font-size:.8rem;"><i class="fa fa-shield-alt me-1"></i>Admin Globale — accesso completo a tutte le aree</span>

                <?php else:
                    // Permessi correnti
                    $cur_ambito  = $mg ? $mg['ambito']     : 'tutti';
                    $cur_perms   = $mg ? $mg['permessi']   : [];
                    $cur_ev_ids  = $mg ? $mg['eventi_ids'] : [];
                ?>
                <form method="POST">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="utente_id" value="<?php echo $uid; ?>">

                    <?php if ($mg): ?>
                    <div class="mb-2 p-2 rounded d-flex align-items-center gap-2 flex-wrap" style="background:#f0fdf4;border:1px solid #bbf7d0;">
                        <span style="font-size:.75rem;font-weight:600;color:#166534;"><i class="fa fa-check-circle me-1"></i>Abilitazioni attive:</span>
                        <?php
                        if (in_array('full', $cur_perms)) echo '<span class="perm-chip" style="background:#fee2e2;color:#991b1b;">Admin Area</span>';
                        if (in_array('eventi',   $cur_perms)) echo '<span class="perm-chip" style="background:#dbeafe;color:#1d4ed8;">Eventi</span>';
                        if (in_array('iscritti', $cur_perms)) echo '<span class="perm-chip" style="background:#dcfce7;color:#166534;">Iscritti</span>';
                        if (in_array('sondaggi', $cur_perms)) echo '<span class="perm-chip" style="background:#fef3c7;color:#92400e;">Sondaggi</span>';
                        if (in_array('form',     $cur_perms)) echo '<span class="perm-chip" style="background:#e0f2fe;color:#0c4a6e;">Form</span>';
                        ?>
                        <?php if ($cur_ambito === 'tutti'): ?>
                            <span class="perm-chip" style="background:#ede9fe;color:#5b21b6;"><i class="fa fa-folder-open"></i>Intera area</span>
                        <?php else: ?>
                            <span class="perm-chip" style="background:#dcfce7;color:#166534;"><i class="fa fa-crosshairs"></i><?php echo count($cur_ev_ids); ?> eventi specifici</span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <div class="row g-3">
                        <!-- Permessi sezioni -->
                        <div class="col-md-5">
                            <label class="form-label small fw-bold">Sezioni abilitate</label>
                            <div class="d-flex flex-wrap gap-2 p-2 border rounded bg-white">
                                <?php
                                $perm_defs = [
                                    'eventi'   => ['label'=>'Eventi',   'col'=>'#1d4ed8'],
                                    'iscritti' => ['label'=>'Iscritti', 'col'=>'#166534'],
                                    'sondaggi' => ['label'=>'Sondaggi', 'col'=>'#92400e'],
                                    'form'     => ['label'=>'Form',     'col'=>'#0c4a6e'],
                                    'full'     => ['label'=>'Admin Area','col'=>'#991b1b'],
                                ];
                                foreach ($perm_defs as $pk => $pd):
                                    $chk = in_array($pk, $cur_perms) ? 'checked' : '';
                                    $is_full_perm = $pk === 'full';
                                ?>
                                <div class="form-check m-0">
                                    <input class="form-check-input <?php echo $is_full_perm ? 'perm-full-'.$uid : 'perm-sec-'.$uid; ?>"
                                        type="checkbox" name="permessi[]" value="<?php echo $pk; ?>"
                                        id="p_<?php echo $uid; ?>_<?php echo $pk; ?>" <?php echo $chk; ?>
                                        <?php echo $is_full_perm ? 'onchange="toggleFull'.$uid.'(this)"' : ''; ?>>
                                    <label class="form-check-label small fw-bold" for="p_<?php echo $uid; ?>_<?php echo $pk; ?>" style="color:<?php echo $pd['col']; ?>">
                                        <?php echo $pd['label']; ?>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Ambito: area o eventi specifici -->
                        <div class="col-md-7">
                            <label class="form-label small fw-bold">Ambito</label>
                            <div class="p-2 border rounded bg-white">
                                <div class="form-check mb-1">
                                    <input class="form-check-input" type="radio" name="ambito_eventi" id="amb_tutti_<?php echo $uid; ?>" value="tutti"
                                        <?php echo $cur_ambito === 'tutti' ? 'checked' : ''; ?>
                                        onchange="document.getElementById('boxEv<?php echo $uid; ?>').classList.add('d-none')">
                                    <label class="form-check-label small fw-bold" for="amb_tutti_<?php echo $uid; ?>"><i class="fa fa-folder-open text-primary me-1"></i>Intera area</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="ambito_eventi" id="amb_spec_<?php echo $uid; ?>" value="specifici"
                                        <?php echo $cur_ambito === 'specifici' ? 'checked' : ''; ?>
                                        onchange="document.getElementById('boxEv<?php echo $uid; ?>').classList.remove('d-none')">
                                    <label class="form-check-label small fw-bold" for="amb_spec_<?php echo $uid; ?>"><i class="fa fa-crosshairs text-success me-1"></i>Solo eventi specifici</label>
                                </div>
                                <div id="boxEv<?php echo $uid; ?>" class="mt-2 <?php echo $cur_ambito === 'specifici' ? '' : 'd-none'; ?>">
                                    <select name="eventi_specifici[]" class="form-select form-select-sm select2-multi-abil" multiple="multiple">
                                        <?php foreach ($eventi_area as $e_id => $e_titolo): ?>
                                            <option value="<?php echo $e_id; ?>" <?php echo in_array($e_id, $cur_ev_ids) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($e_titolo); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Bottoni -->
                    <div class="mt-2 d-flex gap-2">
                        <button type="submit" name="assegna_permessi" class="btn btn-sm btn-primary fw-bold"><i class="fa fa-save me-1"></i>Salva abilitazioni</button>
                        <?php if ($mg): ?>
                        <button type="submit" name="remove_user_all" class="btn btn-sm btn-outline-danger fw-bold"
                            data-confirm="Revocare tutte le abilitazioni di questo utente per quest'area?">
                            <i class="fa fa-trash-alt me-1"></i>Revoca tutto
                        </button>
                        <?php endif; ?>
                    </div>
                </form>

                <script>
                function toggleFull<?php echo $uid; ?>(el) {
                    document.querySelectorAll('.perm-sec-<?php echo $uid; ?>').forEach(function(c){ c.checked = el.checked; c.disabled = el.checked; });
                }
                // Init: se full è già checked, disabilita gli altri
                (function(){ var f = document.querySelector('.perm-full-<?php echo $uid; ?>'); if(f && f.checked) document.querySelectorAll('.perm-sec-<?php echo $uid; ?>').forEach(function(c){ c.disabled=true; }); })();
                </script>

                <?php endif; // end abilitazioni section ?>
            </div><!-- /sezione abilitazioni -->

        </div><!-- /usr-body -->
    </div><!-- /collapse -->
</div><!-- /usr-card -->
<?php endforeach; ?>
</div><!-- /listaUtenti -->

<!-- MODALE CREA GRUPPO -->
<div class="modal fade" id="modCreaGruppo" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <?php csrf_field(); ?>
                <div class="modal-header py-2">
                    <h6 class="modal-title fw-bold"><i class="fa fa-users-cog me-1"></i>Crea Nuovo Gruppo</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label small fw-bold">Nome Gruppo</label>
                    <input type="text" name="nome_ruolo" class="form-control form-control-sm" placeholder="Es. Tutors, Docenti, Personale..." required>
                </div>
                <div class="modal-footer py-2">
                    <button type="submit" name="add_nuovo_ruolo" class="btn btn-danger btn-sm fw-bold w-100"><i class="fa fa-plus me-1"></i>Crea</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Select2 per eventi specifici: inizializza all'apertura del collapse
    document.querySelectorAll('[data-bs-toggle="collapse"]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var target = document.querySelector(btn.getAttribute('data-bs-target'));
            if (!target) return;
            target.addEventListener('shown.bs.collapse', function() {
                if (typeof jQuery !== 'undefined' && $.fn.select2) {
                    $(target).find('.select2-multi-abil').each(function() {
                        if (!$(this).hasClass('select2-hidden-accessible'))
                            $(this).select2({ width: '100%', theme: 'bootstrap-5', placeholder: 'Cerca eventi...' });
                    });
                    $(target).find('.select2-role').each(function() {
                        if (!$(this).hasClass('select2-hidden-accessible'))
                            $(this).select2({ width: '100%', theme: 'bootstrap-5', placeholder: 'Cerca ruolo...' });
                    });
                    $(target).find('.select2-sec-role').each(function() {
                        if (!$(this).hasClass('select2-hidden-accessible'))
                            $(this).select2({ width: '100%', theme: 'bootstrap-5', placeholder: 'Seleziona gruppi...', allowClear: true });
                    });
                }
            }, { once: true });
        });
    });
});

// Clic su un nome del riepilogo: apre la scheda dell'utente nell'elenco
document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-apri]'); if (!b) return;
    var card = document.getElementById('card' + b.dataset.apri);
    if (!card) return;
    card.style.display = '';
    var corpo = document.getElementById('usr' + b.dataset.apri);
    if (corpo && window.bootstrap) bootstrap.Collapse.getOrCreateInstance(corpo, { toggle: false }).show();
    card.scrollIntoView({ behavior: 'smooth', block: 'start' });
});

function filtraUtenti(q) {
    q = q.toLowerCase().trim();
    document.querySelectorAll('#listaUtenti .usr-card').forEach(function(card) {
        var solo = document.getElementById('soloAbilitati').checked;
        card.style.display = ((!q || card.dataset.search.includes(q)) && (!solo || card.dataset.abil === '1')) ? '' : 'none';
    });
}
</script>

<?php require_once 'admin_footer.php'; ?>
