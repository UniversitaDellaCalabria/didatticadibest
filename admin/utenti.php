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
// BACKEND: ABILITAZIONI PER PERIMETRO
// Nell'area corrente: tutta l'area (anche le impostazioni) oppure una parte: tutti i progetti, tutti gli eventi
// e/o attività scelte. Formazione Scuola Lavoro (tutte le aree): tutto oppure solo convenzioni / anagrafe scuole.
// Dentro il perimetro si gestisce tutto (attività, iscritti, sondaggi, moduli, attestati, statistiche).
// ==============================================================================

// Perimetro scelto nel modulo (scheda dell'utente o "Abilita una persona")
function leggi_perimetro_post(): array {
    $area = in_array($_POST['area_perimetro'] ?? '', ['area', 'parziale'], true) ? $_POST['area_perimetro'] : 'nessuno';
    $tipi = $area === 'parziale' ? array_values(array_intersect((array)($_POST['area_tipi'] ?? []), ['progetti', 'eventi'])) : [];
    $att  = $area === 'parziale' ? array_values(array_unique(array_filter(array_map('intval', (array)($_POST['attivita'] ?? []))))) : [];
    if ($area === 'parziale' && !$tipi && !$att) $area = 'nessuno';
    $fsl   = in_array($_POST['fsl_perimetro'] ?? '', ['tutto', 'parziale'], true) ? $_POST['fsl_perimetro'] : 'nessuno';
    $parti = $fsl === 'parziale' ? array_values(array_intersect((array)($_POST['fsl_parti'] ?? []), ['fsl_convenzioni', 'fsl_scuole'])) : [];
    if ($fsl === 'parziale' && !$parti) $fsl = 'nessuno';
    return ['area' => $area, 'tipi' => $tipi, 'attivita' => $att, 'fsl' => $fsl, 'fsl_parti' => $parti];
}

// [[ambito, pagina_id, eventi_ids], …] corrispondenti al perimetro
function ambiti_da_perimetro(array $p, int $pagina_id): array {
    $out = [];
    if ($p['area'] === 'area') $out[] = ['area', $pagina_id, []];
    foreach ($p['tipi'] as $t) $out[] = [$t, $pagina_id, []];
    if ($p['attivita']) $out[] = ['attivita', $pagina_id, $p['attivita']];
    if ($p['fsl'] === 'tutto') $out[] = ['fsl', 0, []];
    foreach ($p['fsl_parti'] as $t) $out[] = [$t, 0, []];
    return $out;
}

// Sostituisce le abilitazioni dell'utente nell'area e quelle FSL con il perimetro scelto
function salva_perimetro($conn, int $uid, int $pagina_id, array $p, int $da): void {
    revoca_permessi_gestore($conn, $pagina_id, $uid);
    $conn->query("UPDATE pagine_eventi SET gestore_utente_id = 0 WHERE id = $pagina_id AND gestore_utente_id = $uid");
    revoca_ambito($conn, $uid, null, $pagina_id);
    revoca_ambito($conn, $uid, null, 0);
    foreach (ambiti_da_perimetro($p, $pagina_id) as [$amb, $pag, $ev]) applica_abilitazione($conn, $uid, $amb, $pag, $ev, $da);
}

// Descrizione breve per riepiloghi e messaggi
function testo_perimetro(array $p, array $titoli_att = []): string {
    $parti = [];
    if ($p['area'] === 'area') $parti[] = "tutta l'area";
    if (in_array('progetti', $p['tipi'], true)) $parti[] = 'tutti i progetti';
    if (in_array('eventi', $p['tipi'], true)) $parti[] = 'tutti gli eventi';
    if ($p['attivita']) $parti[] = count($p['attivita']) === 1 ? '1 attività' . (isset($titoli_att[$p['attivita'][0]]) ? ' (' . $titoli_att[$p['attivita'][0]] . ')' : '') : count($p['attivita']) . ' attività';
    if ($p['fsl'] === 'tutto') $parti[] = 'Formazione Scuola Lavoro';
    if (in_array('fsl_convenzioni', $p['fsl_parti'], true)) $parti[] = 'convenzioni FSL';
    if (in_array('fsl_scuole', $p['fsl_parti'], true)) $parti[] = 'anagrafe scuole';
    return $parti ? implode(', ', $parti) : 'nessuna abilitazione';
}

if (isset($_POST['salva_abilitazioni'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $u_id = (int)$_POST['utente_id'];
    if ($u_id > 0 && $filtro_p > 0) {
        $p_sc = leggi_perimetro_post();
        salva_perimetro($conn, $u_id, $filtro_p, $p_sc, (int)$_SESSION['utente_id']);
        registra_log_audit($conn, "Abilitazioni salvate", ["Utente" => $u_id, "Area" => $filtro_p, "Perimetro" => testo_perimetro($p_sc)]);
        flash_set("Abilitazioni salvate: " . testo_perimetro($p_sc) . ".");
    }
    admin_redirect("utenti.php?p_id=$filtro_p");
}

// Abilitazione di una persona scelta dall'anagrafe di Ateneo (o indicata per email): se ha già fatto accesso
// vale subito, altrimenti resta in attesa e si attiva al suo primo login con quell'email
if (isset($_POST['abilita_da_anagrafe'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $email_ab = strtolower(trim((string)($_POST['email'] ?? '')));
    $pid_ab   = trim((string)($_POST['persona_id'] ?? ''));
    $pers_ab  = persona_ateneo($conn, $pid_ab);
    $nome_ab  = $pers_ab ? nome_persona($pers_ab) : mb_substr(trim((string)($_POST['nominativo'] ?? '')), 0, 200);
    $p_ab = leggi_perimetro_post();
    $ambiti_ab = ambiti_da_perimetro($p_ab, $filtro_p);
    if ($filtro_p <= 0 || !filter_var($email_ab, FILTER_VALIDATE_EMAIL) || !$ambiti_ab) {
        flash_set("Abilitazione non salvata: servono un'email valida e almeno una cosa da abilitare.", 'danger');
        admin_redirect("utenti.php?p_id=$filtro_p");
    }
    $st = $conn->prepare("SELECT id FROM utenti WHERE LOWER(email) = ? OR (? <> '' AND persona_id = ?) ORDER BY ultimo_accesso DESC LIMIT 1");
    $st->bind_param("sss", $email_ab, $pid_ab, $pid_ab); $st->execute();
    $u_ab = $st->get_result()->fetch_assoc();
    if ($u_ab) {
        salva_perimetro($conn, (int)$u_ab['id'], $filtro_p, $p_ab, (int)$_SESSION['utente_id']);
        registra_log_audit($conn, "Abilitazioni salvate", ["Utente" => (int)$u_ab['id'], "Area" => $filtro_p, "Perimetro" => testo_perimetro($p_ab), "Da anagrafe" => $nome_ab]);
        flash_set(($nome_ab ?: $email_ab) . " è abilitato/a: " . testo_perimetro($p_ab) . ".");
    } else {
        $st = $conn->prepare("DELETE FROM abilitazioni_attesa WHERE email = ? AND pagina_id IN (?, 0)");
        $st->bind_param("si", $email_ab, $filtro_p); $st->execute();
        $pid_s = $pers_ab['id'] ?? null; $da = (int)$_SESSION['utente_id'];
        $st = $conn->prepare("INSERT INTO abilitazioni_attesa (email, persona_id, nominativo, pagina_id, permessi, eventi_ids, creata_da, ambito) VALUES (?, ?, ?, ?, 'full', ?, ?, ?)");
        foreach ($ambiti_ab as [$amb, $pag, $ev]) {
            $ev_s = implode(',', $ev);
            $st->bind_param("sssisis", $email_ab, $pid_s, $nome_ab, $pag, $ev_s, $da, $amb); $st->execute();
        }
        registra_log_audit($conn, "Abilitazione in attesa del primo accesso", ["Email" => $email_ab, "Area" => $filtro_p, "Perimetro" => testo_perimetro($p_ab)]);
        flash_set(($nome_ab ?: $email_ab) . " non ha ancora fatto accesso al portale: l'abilitazione (" . testo_perimetro($p_ab) . ") si attiverà al suo primo accesso con $email_ab.");
    }
    admin_redirect("utenti.php?p_id=$filtro_p");
}

if (isset($_POST['annulla_attesa'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $id_att = (int)$_POST['annulla_attesa'];
    $r_att = $conn->query("SELECT email FROM abilitazioni_attesa WHERE id = $id_att AND pagina_id IN (" . (int)$filtro_p . ", 0)");
    if ($r_att && $x_att = $r_att->fetch_assoc()) {
        // Tutte le abilitazioni in attesa di quella persona (area corrente e FSL)
        $st = $conn->prepare("DELETE FROM abilitazioni_attesa WHERE email = ? AND pagina_id IN (?, 0)");
        $st->bind_param("si", $x_att['email'], $filtro_p); $st->execute();
        registra_log_audit($conn, "Annullata abilitazione in attesa", ["Email" => $x_att['email'], "Area" => $filtro_p]);
        flash_set("Abilitazione in attesa annullata.");
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
        salva_perimetro($conn, $u_id, $filtro_p, ['area' => 'nessuno', 'tipi' => [], 'attivita' => [], 'fsl' => 'nessuno', 'fsl_parti' => []], (int)$_SESSION['utente_id']);
        registra_log_audit($conn, "Revoca abilitazioni", ["Utente" => $u_id, "Area" => $filtro_p]);
        flash_set("Abilitazioni revocate (quest'area e Formazione Scuola Lavoro).");
    }
    admin_redirect("utenti.php?p_id=$filtro_p");
}

// ==============================================================================
// PREPARAZIONE DATI
// ==============================================================================

$in_attesa = [];
if ($filtro_p > 0) {
    $r_att = $conn->query("SELECT * FROM abilitazioni_attesa WHERE pagina_id IN (" . (int)$filtro_p . ", 0) ORDER BY created_at DESC");
    if ($r_att) $in_attesa = $r_att->fetch_all(MYSQLI_ASSOC);
}
$ruoli = get_ruoli($conn);
$ruoli_map = [];
foreach ($ruoli as $r) $ruoli_map[(int)$r['id']] = $r['nome'];
// Gruppi di ciascun utente (ruolo principale + gruppi secondari), per il filtro per gruppo dell'elenco
$gruppi_utente = fn(array $x) => array_values(array_unique(array_filter(array_map('intval', array_merge([(int)($x['ruolo_id'] ?? 5)], explode(',', (string)($x['ruoli_secondari'] ?? '')))))));

$utenti = [];
// Ruolo in Ateneo di chi è collegato all'anagrafe (letto a parte: le due tabelle possono avere collation diverse)
$pers_ut = [];
$r_pu = $conn->query("SELECT id, ruolo, struttura, gruppo, attivo FROM personale_ateneo");
while ($r_pu && $x = $r_pu->fetch_assoc()) $pers_ut[$x['id']] = $x;
$res_ut = $conn->query("SELECT * FROM utenti ORDER BY cognome ASC, nome ASC");
if ($res_ut) while ($row = $res_ut->fetch_assoc()) {
    $row['ruolo_nome'] = $ruoli_map[(int)($row['ruolo_id'] ?? 5)] ?? 'Ospiti';
    if (!empty($row['persona_id']) && ($pu = $pers_ut[$row['persona_id']] ?? null)) {
        $row['ateneo_ruolo'] = $pu['ruolo']; $row['ateneo_struttura'] = $pu['struttura']; $row['ateneo_gruppo'] = $pu['gruppo']; $row['ateneo_attivo'] = $pu['attivo'];
    }
    $utenti[] = $row;
}

$eventi_area   = []; $tipo_att = []; $arch_att = [];
$mappa_gestori = [];
if ($filtro_p > 0) {
    $res_el = $conn->query("SELECT id, titolo, IFNULL(tipo, 'evento') AS tipo, archiviato FROM eventi WHERE pagina_id=$filtro_p ORDER BY archiviato ASC, ordine ASC, id DESC");
    if ($res_el) while ($e = $res_el->fetch_assoc()) { $eventi_area[$e['id']] = $e['titolo']; $tipo_att[$e['id']] = $e['tipo'] === 'progetto' ? 'progetto' : 'evento'; $arch_att[$e['id']] = (int)$e['archiviato']; }

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

// Perimetro di ciascun utente nell'area corrente e nella FSL (per i riepiloghi e i moduli)
$PERIMETRO_VUOTO = ['area' => 'nessuno', 'tipi' => [], 'attivita' => [], 'fsl' => 'nessuno', 'fsl_parti' => []];
$perimetri = [];
foreach ($mappa_gestori as $uid_m => $g_m) {
    $p_m = $PERIMETRO_VUOTO;
    if ($g_m['ambito'] === 'tutti') $p_m['area'] = 'area';
    else { $p_m['area'] = 'parziale'; $p_m['attivita'] = array_map('intval', $g_m['eventi_ids']); }
    $perimetri[(int)$uid_m] = $p_m;
}
$r_amb = @$conn->query("SELECT utente_id, tipo, pagina_id FROM abilitazioni_ambito WHERE pagina_id IN (" . (int)$filtro_p . ", 0)");
while ($r_amb && $x_amb = $r_amb->fetch_assoc()) {
    $uid_m = (int)$x_amb['utente_id'];
    $p_m = $perimetri[$uid_m] ?? $PERIMETRO_VUOTO;
    if (in_array($x_amb['tipo'], ['progetti', 'eventi'], true) && (int)$x_amb['pagina_id'] === (int)$filtro_p && $p_m['area'] !== 'area') { $p_m['area'] = 'parziale'; $p_m['tipi'][] = $x_amb['tipo']; }
    if ($x_amb['tipo'] === 'fsl') { $p_m['fsl'] = 'tutto'; $p_m['fsl_parti'] = []; }
    if (in_array($x_amb['tipo'], ['fsl_convenzioni', 'fsl_scuole'], true) && $p_m['fsl'] !== 'tutto') { $p_m['fsl'] = 'parziale'; $p_m['fsl_parti'][] = $x_amb['tipo']; }
    $perimetri[$uid_m] = $p_m;
}
// Abilitati alla FSL (valgono in tutte le aree)
$abilitati_fsl = array_filter($perimetri, fn($p) => $p['fsl'] !== 'nessuno');
// Abilitati su qualcosa dell'area corrente
$abilitati_area = array_filter($perimetri, fn($p) => $p['area'] !== 'nessuno');

// Controlli del perimetro (scheda utente e "Abilita una persona"); $pref rende unici gli id
function html_perimetro(string $pref, array $p, string $titolo_area, array $eventi_area, array $tipo_att, array $arch_att): string {
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $chk = fn($c) => $c ? ' checked' : '';
    $o = '<div class="row g-3 perimetro" data-pref="' . $h($pref) . '">';
    // Area corrente
    $o .= '<div class="col-lg-7"><fieldset class="p-2 border rounded bg-white h-100"><legend class="form-label small fw-bold mb-1 float-none w-auto px-1">In quest\'area: ' . $h($titolo_area) . '</legend>';
    foreach (['nessuno' => 'Nessuna abilitazione', 'area' => "Tutta l'area (attività, iscritti, sondaggi, moduli, statistiche e impostazioni)", 'parziale' => 'Solo una parte:'] as $v => $l) {
        $o .= '<div class="form-check"><input class="form-check-input per-area" type="radio" name="area_perimetro" id="' . $pref . 'a_' . $v . '" value="' . $v . '"' . $chk($p['area'] === $v) . '><label class="form-check-label small' . ($v === 'area' ? ' fw-bold' : '') . '" for="' . $pref . 'a_' . $v . '">' . $h($l) . '</label></div>';
    }
    $o .= '<div class="per-parziale ms-4 mt-1"' . ($p['area'] === 'parziale' ? '' : ' hidden') . '>'
        . '<div class="form-check"><input class="form-check-input" type="checkbox" name="area_tipi[]" value="progetti" id="' . $pref . 't_pr"' . $chk(in_array('progetti', $p['tipi'], true)) . '><label class="form-check-label small" for="' . $pref . 't_pr">Tutti i progetti <span class="text-muted">(anche quelli creati dopo)</span></label></div>'
        . '<div class="form-check"><input class="form-check-input" type="checkbox" name="area_tipi[]" value="eventi" id="' . $pref . 't_ev"' . $chk(in_array('eventi', $p['tipi'], true)) . '><label class="form-check-label small" for="' . $pref . 't_ev">Tutti gli eventi <span class="text-muted">(anche quelli creati dopo)</span></label></div>'
        . '<label class="form-label small mt-2 mb-1" for="' . $pref . 'att">Attività scelte</label><select name="attivita[]" id="' . $pref . 'att" class="form-select form-select-sm per-att" multiple size="5">';
    foreach (['progetto' => 'Progetti', 'evento' => 'Eventi'] as $tp => $lbl) {
        $grp = array_filter($eventi_area, fn($id) => ($tipo_att[$id] ?? 'evento') === $tp, ARRAY_FILTER_USE_KEY);
        if (!$grp) continue;
        $o .= '<optgroup label="' . $lbl . '">';
        foreach ($grp as $id => $tit) $o .= '<option value="' . (int)$id . '"' . (in_array((int)$id, $p['attivita'], true) ? ' selected' : '') . '>' . $h($tit) . (!empty($arch_att[$id]) ? ' (archiviato)' : '') . '</option>';
        $o .= '</optgroup>';
    }
    $o .= '</select></div></fieldset></div>';
    // Formazione Scuola Lavoro
    $o .= '<div class="col-lg-5"><fieldset class="p-2 border rounded bg-white h-100"><legend class="form-label small fw-bold mb-1 float-none w-auto px-1">Formazione Scuola Lavoro (tutte le aree)</legend>';
    foreach (['nessuno' => 'Nessuna abilitazione', 'tutto' => 'Tutto: pannello FSL e attività FSL di tutte le aree, con iscritti, sondaggi e attestati', 'parziale' => 'Solo:'] as $v => $l) {
        $o .= '<div class="form-check"><input class="form-check-input per-fsl" type="radio" name="fsl_perimetro" id="' . $pref . 'f_' . $v . '" value="' . $v . '"' . $chk($p['fsl'] === $v) . '><label class="form-check-label small' . ($v === 'tutto' ? ' fw-bold' : '') . '" for="' . $pref . 'f_' . $v . '">' . $h($l) . '</label></div>';
    }
    $o .= '<div class="per-fsl-parti ms-4 mt-1"' . ($p['fsl'] === 'parziale' ? '' : ' hidden') . '>'
        . '<div class="form-check"><input class="form-check-input" type="checkbox" name="fsl_parti[]" value="fsl_convenzioni" id="' . $pref . 'fc"' . $chk(in_array('fsl_convenzioni', $p['fsl_parti'], true)) . '><label class="form-check-label small" for="' . $pref . 'fc">Convenzioni</label></div>'
        . '<div class="form-check"><input class="form-check-input" type="checkbox" name="fsl_parti[]" value="fsl_scuole" id="' . $pref . 'fs"' . $chk(in_array('fsl_scuole', $p['fsl_parti'], true)) . '><label class="form-check-label small" for="' . $pref . 'fs">Anagrafe scuole</label></div>'
        . '</div></fieldset></div></div>';
    return $o;
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
    $r_ga = @$conn->query("SELECT DISTINCT utente_id FROM abilitazioni_ambito WHERE pagina_id = " . (int)$pa['id']);
    while ($r_ga && $ga = $r_ga->fetch_assoc()) if (!in_array((int)$ga['utente_id'], $ids_intera, true)) $ids_eventi[(int)$ga['utente_id']] = true;
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
    <h4 class="fw-bold text-dark mb-0"><i class="fa fa-user-shield me-2 text-danger"></i>Utenti, gruppi e abilitazioni</h4>
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
    <?php $conta_gruppi = []; foreach ($utenti as $x_g) foreach ($gruppi_utente($x_g) as $g_id) $conta_gruppi[$g_id] = ($conta_gruppi[$g_id] ?? 0) + 1; ?>
    <label for="filtroGruppo" class="visually-hidden">Filtra per gruppo</label>
    <select id="filtroGruppo" class="form-select form-select-sm" style="max-width:260px;" onchange="filtraUtenti(document.getElementById('cercaUtente').value)">
        <option value="">Tutti i gruppi</option>
        <?php foreach ($ruoli as $r): ?>
            <option value="<?php echo (int)$r['id']; ?>"><?php echo htmlspecialchars($r['nome']); ?> (<?php echo (int)($conta_gruppi[(int)$r['id']] ?? 0); ?>)</option>
        <?php endforeach; ?>
    </select>
    <small class="text-muted"><span id="contaUtenti"><?php echo count($utenti); ?></span> di <?php echo count($utenti); ?> utenti</small>
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
            <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap mb-2">
                <h6 class="mb-0"><i class="fa fa-key me-1" style="color:#5b21b6;" aria-hidden="true"></i>Abilitati su <?php echo htmlspecialchars($page_cfg['titolo'] ?? ''); ?> (<?php echo count($abilitati_area); ?>)</h6>
                <?php if ($filtro_p > 0): ?><button type="button" class="btn btn-sm fw-bold" style="background:#ede9fe;color:#5b21b6;" data-bs-toggle="modal" data-bs-target="#modAbilitaAnagrafe"><i class="fa fa-user-plus me-1" aria-hidden="true"></i>Abilita una persona</button><?php endif; ?>
            </div>
            <?php if (!$abilitati_area && !$in_attesa): ?>
                <div class="text-muted small py-2">Nessuno: in quest'area lavorano solo gli amministratori. Per abilitare qualcuno usa "Abilita una persona" o apri la sua scheda qui sotto.</div>
            <?php endif; ?>
            <?php foreach ($abilitati_area as $id_g => $p_g): $notif = $notifiche_attive === null || in_array((int)$id_g, (array)$notifiche_attive); ?>
                <div class="riep-riga flex-wrap">
                    <div style="min-width:200px;flex:1;">
                        <button type="button" class="riep-nome" data-apri="<?php echo (int)$id_g; ?>"><?php echo htmlspecialchars($nome_utente((int)$id_g)); ?></button>
                        <div class="small text-muted">
                            <i class="fa <?php echo $p_g['area'] === 'area' ? 'fa-folder-open' : 'fa-crosshairs'; ?> me-1" aria-hidden="true"></i><?php echo htmlspecialchars(ucfirst(testo_perimetro(['fsl' => 'nessuno', 'fsl_parti' => []] + $p_g, $eventi_area))); ?>
                            <?php if ($p_g['attivita'] && count($p_g['attivita']) > 1): ?><span title="<?php echo htmlspecialchars(implode(', ', array_map(fn($i) => $eventi_area[$i] ?? "#$i", $p_g['attivita']))); ?>"> <i class="fa fa-circle-info" aria-hidden="true"></i><span class="visually-hidden">: <?php echo htmlspecialchars(implode(', ', array_map(fn($i) => $eventi_area[$i] ?? "#$i", $p_g['attivita']))); ?></span></span><?php endif; ?>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-1 align-items-center">
                        <?php if ($p_g['area'] === 'area'): ?><span class="perm-chip" style="background:#fee2e2;color:#991b1b;">Tutta l'area</span><?php endif; ?>
                        <span class="perm-chip" style="<?php echo $notif ? 'background:#dcfce7;color:#166534;' : 'background:#f1f5f9;color:#64748b;'; ?>" title="<?php echo $notif ? 'Riceve le email delle prenotazioni' : 'Non riceve le email delle prenotazioni'; ?>"><i class="fa <?php echo $notif ? 'fa-bell' : 'fa-bell-slash'; ?>" aria-hidden="true"></i></span>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php
            // Abilitazioni in attesa del primo accesso: una riga per persona
            $attesa_per_email = [];
            foreach ($in_attesa as $att) $attesa_per_email[$att['email']][] = $att;
            foreach ($attesa_per_email as $em_att => $righe_att):
                $p_att = $PERIMETRO_VUOTO;
                foreach ($righe_att as $att) {
                    $amb_att = (string)($att['ambito'] ?? 'area');
                    if ($amb_att === 'area' && $att['eventi_ids'] !== '') $amb_att = 'attivita';
                    if ($amb_att === 'area') $p_att['area'] = 'area';
                    elseif ($amb_att === 'attivita') { $p_att['area'] = $p_att['area'] === 'area' ? 'area' : 'parziale'; $p_att['attivita'] = array_map('intval', explode(',', $att['eventi_ids'])); }
                    elseif (in_array($amb_att, ['progetti', 'eventi'], true)) { $p_att['area'] = $p_att['area'] === 'area' ? 'area' : 'parziale'; $p_att['tipi'][] = $amb_att; }
                    elseif ($amb_att === 'fsl') $p_att['fsl'] = 'tutto';
                    else { $p_att['fsl'] = $p_att['fsl'] === 'tutto' ? 'tutto' : 'parziale'; $p_att['fsl_parti'][] = $amb_att; }
                }
                $att = $righe_att[0]; ?>
                <div class="riep-riga flex-wrap">
                    <div style="min-width:200px;flex:1;">
                        <span class="fw-bold"><?php echo htmlspecialchars($att['nominativo'] ?: $em_att); ?></span>
                        <span class="badge ms-1" style="background:#fef3c7;color:#92400e;" title="Si attiva al primo accesso con questa email"><i class="fa fa-hourglass-half me-1" aria-hidden="true"></i>in attesa del primo accesso</span>
                        <div class="small text-muted"><?php echo htmlspecialchars($em_att); ?> · <?php echo htmlspecialchars(testo_perimetro($p_att, $eventi_area)); ?></div>
                    </div>
                    <form method="POST" class="m-0"><?php csrf_field(); ?><button type="submit" name="annulla_attesa" value="<?php echo (int)$att['id']; ?>" class="btn btn-sm btn-link text-danger p-0 ms-1" data-confirm="Annullare l'abilitazione in attesa di <?php echo htmlspecialchars($att['nominativo'] ?: $em_att); ?>?" title="Annulla" aria-label="Annulla l'abilitazione in attesa"><i class="fa fa-xmark" aria-hidden="true"></i></button></form>
                </div>
            <?php endforeach; ?>

            <div class="mt-3 pt-2 border-top small">
                <div class="fw-bold text-secondary mb-1"><i class="fa fa-briefcase me-1" aria-hidden="true"></i>Formazione Scuola Lavoro (tutte le aree)</div>
                <?php if (!$abilitati_fsl): ?><span class="text-muted">Solo gli amministratori.</span><?php endif; ?>
                <?php foreach ($abilitati_fsl as $id_f => $p_f): ?>
                    <div class="mb-1"><button type="button" class="riep-nome" data-apri="<?php echo (int)$id_f; ?>"><?php echo htmlspecialchars($nome_utente((int)$id_f)); ?></button>
                        <span class="text-muted">· <?php echo htmlspecialchars(testo_perimetro(['area' => 'nessuno', 'tipi' => [], 'attivita' => []] + $p_f)); ?></span></div>
                <?php endforeach; ?>
            </div>

            <?php $altre = array_filter($panoramica_aree, fn($x) => (int)$x['a']['id'] !== (int)$filtro_p); if ($altre): ?>
                <div class="mt-3 pt-2 border-top small">
                    <div class="fw-bold text-secondary mb-1">Nelle altre aree</div>
                    <?php foreach ($altre as $pa): $tot = count($pa['intera']) + count($pa['eventi']); ?>
                        <div class="mb-1">
                            <a href="utenti.php?p_id=<?php echo (int)$pa['a']['id']; ?>" class="fw-semibold text-decoration-none"><?php echo htmlspecialchars($pa['a']['titolo']); ?></a>:
                            <?php if ($tot === 0): ?><span class="text-muted">nessun abilitato</span>
                            <?php else:
                                $nomi = array_merge(array_map(fn($i) => $nome_utente($i), $pa['intera']), array_map(fn($i) => $nome_utente($i) . ' (parte dell\'area)', $pa['eventi']));
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
    $pu = $perimetri[$uid] ?? null;   // perimetro nell'area corrente e nella FSL
?>
<div class="usr-card" id="card<?php echo $uid; ?>" data-gruppi=",<?php echo implode(',', $gruppi_utente($u)); ?>," data-abil="<?php echo ($pu || in_array($uid, $ids_amministratori, true)) ? '1' : '0'; ?>" data-search="<?php echo htmlspecialchars(strtolower(($u['nome']??'').' '.($u['cognome']??'').' '.($u['email']??'').' '.($u['matricola_studente']??'').' '.($u['matricola_dipendente']??''))); ?>">

    <!-- HEADER (click to expand) -->
    <div class="usr-header" data-bs-toggle="collapse" data-bs-target="#usr<?php echo $uid; ?>" aria-expanded="false">
        <!-- Avatar -->
        <div style="width:38px;height:38px;border-radius:50%;background:<?php echo $av_bg; ?>;display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:700;color:#fff;flex-shrink:0;"><?php echo $initials; ?></div>

        <!-- Nome + email -->
        <div style="flex:1;min-width:0;">
            <div class="fw-semibold text-dark" style="font-size:.88rem;"><?php echo htmlspecialchars(($u['cognome'] ?? '') . ' ' . ($u['nome'] ?? '')); ?>
                <?php if ($is_me): ?><span class="badge bg-light text-muted border ms-1" style="font-size:.62rem;">Tu</span><?php endif; ?>
                <?php if (!empty($u['ateneo_gruppo'])): ?><span class="badge ms-1" style="font-size:.62rem;background:#ccfbf1;color:#115e59;" title="<?php echo htmlspecialchars(($u['ateneo_ruolo'] ?? '') . ' · ' . ($u['ateneo_struttura'] ?? '')); ?>"><i class="fa fa-address-book me-1" aria-hidden="true"></i><?php echo htmlspecialchars(GRUPPI_PERSONALE[$u['ateneo_gruppo']] ?? ''); ?><?php echo empty($u['ateneo_attivo']) ? ' · non più nel portale' : ''; ?></span><?php endif; ?>
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
        <?php if ($filtro_p > 0 && $pu): ?>
        <div class="d-none d-md-block">
            <?php if ($pu['area'] === 'area'): ?><span class="perm-chip" style="background:#ede9fe;color:#5b21b6;"><i class="fa fa-folder-open" aria-hidden="true"></i>Area</span>
            <?php elseif ($pu['area'] === 'parziale'): ?><span class="perm-chip" style="background:#dcfce7;color:#166534;" title="<?php echo htmlspecialchars(testo_perimetro(['fsl' => 'nessuno', 'fsl_parti' => []] + $pu, $eventi_area)); ?>"><i class="fa fa-crosshairs" aria-hidden="true"></i>Parte dell'area</span><?php endif; ?>
            <?php if ($pu['fsl'] !== 'nessuno'): ?><span class="perm-chip" style="background:#ffedd5;color:#9a3412;"><i class="fa fa-briefcase" aria-hidden="true"></i>FSL</span><?php endif; ?>
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

            <!-- SEZIONE 3: ABILITAZIONI (area corrente e Formazione Scuola Lavoro) -->
            <div class="usr-section">
                <div class="usr-section-label"><i class="fa fa-key" aria-hidden="true"></i>Abilitazioni
                    <?php if ($filtro_p > 0): ?>
                        <span style="font-size:.65rem;background:#f1f5f9;color:#64748b;padding:1px 7px;border-radius:10px;font-weight:600;"><?php echo htmlspecialchars($page_cfg['titolo'] ?? ''); ?> e FSL</span>
                    <?php endif; ?>
                </div>

                <?php if ($filtro_p == 0): ?>
                    <p class="text-muted small mb-0"><i class="fa fa-info-circle me-1" aria-hidden="true"></i>Seleziona un'area dal menu in alto per gestire le abilitazioni di questo utente.</p>

                <?php elseif ($is_admin): ?>
                    <span class="perm-chip" style="background:#fee2e2;color:#991b1b;font-size:.8rem;"><i class="fa fa-shield-alt me-1" aria-hidden="true"></i>Admin Globale — accesso completo a tutte le aree</span>

                <?php else: $p_cur = $pu ?? $PERIMETRO_VUOTO; ?>
                <form method="POST">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="utente_id" value="<?php echo $uid; ?>">
                    <p class="small text-secondary mb-2">Dentro ciò che abiliti la persona gestisce tutto: attività, iscritti e check-in, sondaggi, moduli, attestati e statistiche.</p>
                    <?php echo html_perimetro('u' . $uid . '_', $p_cur, (string)($page_cfg['titolo'] ?? ''), $eventi_area, $tipo_att, $arch_att); ?>
                    <div class="mt-2 d-flex gap-2">
                        <button type="submit" name="salva_abilitazioni" value="1" class="btn btn-sm btn-primary fw-bold"><i class="fa fa-save me-1" aria-hidden="true"></i>Salva abilitazioni</button>
                        <?php if ($pu): ?>
                        <button type="submit" name="remove_user_all" value="1" class="btn btn-sm btn-outline-danger fw-bold" data-confirm="Togliere tutte le abilitazioni di questa persona su quest'area e sulla Formazione Scuola Lavoro?"><i class="fa fa-trash-alt me-1" aria-hidden="true"></i>Revoca tutto</button>
                        <?php endif; ?>
                    </div>
                </form>
                <?php endif; ?>
            </div><!-- /sezione abilitazioni -->

        </div><!-- /usr-body -->
    </div><!-- /collapse -->
</div><!-- /usr-card -->
<?php endforeach; ?>
</div><!-- /listaUtenti -->

<?php if ($filtro_p > 0): ?>
<!-- MODALE ABILITA UNA PERSONA (anagrafe di Ateneo o email) -->
<div class="modal fade" id="modAbilitaAnagrafe" tabindex="-1" aria-labelledby="titAbAn">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" id="formAbAn">
                <?php csrf_field(); ?>
                <div class="modal-header py-2">
                    <h6 class="modal-title fw-bold" id="titAbAn"><i class="fa fa-user-plus me-1" aria-hidden="true"></i>Abilita una persona su <?php echo htmlspecialchars($page_cfg['titolo'] ?? ''); ?></h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-secondary">Cerca la persona nell'anagrafe di Ateneo oppure scrivi nome ed email. Se non ha ancora fatto accesso al portale, l'abilitazione si attiva al suo primo accesso con quell'email.</p>
                    <?php echo html_ricerca_personale($conn, 'Scegli'); ?>
                    <input type="hidden" name="persona_id" id="abAnPid">
                    <div class="row g-2 mt-1">
                        <div class="col-md-6"><label class="form-label small fw-bold" for="abAnNome">Nome e cognome</label><input type="text" name="nominativo" id="abAnNome" class="form-control form-control-sm" maxlength="200"></div>
                        <div class="col-md-6"><label class="form-label small fw-bold" for="abAnEmail">Email <span class="text-danger">*</span></label><input type="email" name="email" id="abAnEmail" class="form-control form-control-sm" required placeholder="nome.cognome@unical.it"></div>
                    </div>
                    <div id="abAnScelta" class="small mt-1" style="color:#0f766e;" hidden><i class="fa fa-address-book me-1" aria-hidden="true"></i><span></span></div>
                    <div class="mt-2"><?php echo html_perimetro('ab_', $PERIMETRO_VUOTO, (string)($page_cfg['titolo'] ?? ''), $eventi_area, $tipo_att, $arch_att); ?></div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" name="abilita_da_anagrafe" value="1" class="btn btn-sm btn-primary fw-bold"><i class="fa fa-save me-1" aria-hidden="true"></i>Abilita</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
// Persona scelta dall'anagrafe: nome, email e collegamento. Nome o email cambiati a mano: non è più quella persona
document.getElementById('formAbAn').addEventListener('persona-scelta', function (e) {
    var p = e.detail;
    document.getElementById('abAnPid').value = p.id;
    document.getElementById('abAnNome').value = p.nome;
    document.getElementById('abAnEmail').value = p.email || '';
    var s = document.getElementById('abAnScelta'); s.hidden = false;
    s.querySelector('span').textContent = 'Dall\'anagrafe: ' + [p.ruolo, p.struttura].filter(Boolean).join(' · ') + (p.email ? '' : ' — email non pubblicata: scrivila a mano');
    (p.email ? document.querySelector('#formAbAn button[name=abilita_da_anagrafe]') : document.getElementById('abAnEmail')).focus();
});
['abAnNome', 'abAnEmail'].forEach(function (id) {
    document.getElementById(id).addEventListener('input', function () { document.getElementById('abAnPid').value = ''; document.getElementById('abAnScelta').hidden = true; });
});
</script>
<?php endif; ?>

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

// Abilitazioni: "Solo una parte" mostra progetti/eventi/attività, "Solo" della FSL mostra le sue parti
document.addEventListener('change', function (e) {
    var box = e.target.closest('.perimetro'); if (!box) return;
    if (e.target.classList.contains('per-area')) box.querySelector('.per-parziale').hidden = e.target.value !== 'parziale';
    if (e.target.classList.contains('per-fsl')) box.querySelector('.per-fsl-parti').hidden = e.target.value !== 'parziale';
});

// Ricerca per testo, per gruppo (ruolo principale o secondario) e solo abilitati, combinabili
function filtraUtenti(q) {
    q = q.toLowerCase().trim();
    var solo = document.getElementById('soloAbilitati').checked;
    var gruppo = document.getElementById('filtroGruppo').value, n = 0;
    document.querySelectorAll('#listaUtenti .usr-card').forEach(function(card) {
        var ok = (!q || card.dataset.search.includes(q)) && (!solo || card.dataset.abil === '1')
              && (!gruppo || card.dataset.gruppi.indexOf(',' + gruppo + ',') !== -1);
        card.style.display = ok ? '' : 'none';
        if (ok) n++;
    });
    document.getElementById('contaUtenti').textContent = n;
}
</script>

<?php require_once 'admin_footer.php'; ?>
