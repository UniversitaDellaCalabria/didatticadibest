<?php
// admin/risorse.php - Calendari e risorse: aule, laboratori e sportelli dell'area (tipo "calendario") con
// orari settimanali (fino a due fasce al giorno), regole di prenotazione e chiusure (della risorsa o di tutta l'area).
require_once 'admin_header.php';

if (!$is_area_manager || tipo_area($page_cfg) !== 'calendario') {
    echo "<div class='alert alert-warning fw-bold shadow-sm m-4'><i class='fa fa-ban me-2'></i>" . (tipo_area($page_cfg) !== 'calendario' ? "Quest'area non è di tipo Calendari e risorse." : "Non gestisci quest'area.") . "</div>";
    require_once 'admin_footer.php'; exit;
}
$torna = fn(string $qs = '') => print("<script>window.location.replace(" . json_encode("risorse.php?p_id=$filtro_p&r=" . time() . $qs) . ");</script>"); // &r=: con un'ancora la pagina deve ricaricarsi
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$ora_ok = fn($t) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$t) ? $t . ':00' : null;

// ── Salvataggio della risorsa ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salva_risorsa'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $id = (int)($_POST['risorsa_id'] ?? 0);
    if ($id && !($r_old = risorsa($conn, $id)) || ($id && (int)$r_old['pagina_id'] !== (int)$filtro_p)) { flash_set("Risorsa non trovata.", 'danger'); $torna(); exit; }
    $nome = mb_substr(trim((string)($_POST['nome'] ?? '')), 0, 150);
    if ($nome === '') { flash_set("Il nome è obbligatorio.", 'danger'); $torna($id ? "&modifica=$id" : '&nuova=1'); exit; }
    $tipo = isset(TIPI_RISORSA[$_POST['tipo'] ?? '']) ? $_POST['tipo'] : 'aula';
    $accesso = isset(ACCESSI_RISORSA[$_POST['accesso'] ?? '']) ? $_POST['accesso'] : 'tutti';
    $descr = mb_substr(trim(strip_tags((string)($_POST['descrizione'] ?? ''))), 0, 3000);
    $luogo = mb_substr(trim((string)($_POST['luogo'] ?? '')), 0, 255);
    $capienza = ($_POST['capienza'] ?? '') !== '' ? max(0, (int)$_POST['capienza']) : null;
    $referente = mb_substr(trim((string)($_POST['referente'] ?? '')), 0, 150);
    $emails = implode(', ', array_filter(array_map('trim', preg_split('/[,;\s]+/', (string)($_POST['email_notifiche'] ?? ''))), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
    $durata = in_array((int)($_POST['durata_slot'] ?? 60), [10, 15, 20, 30, 45, 60, 90, 120, 180, 240], true) ? (int)$_POST['durata_slot'] : 60;
    $max_slot = max(1, min(12, (int)($_POST['max_slot'] ?? 1)));
    $anticipo = max(0, min(720, (int)($_POST['anticipo_ore'] ?? 0)));
    $max_giorni = max(1, min(365, (int)($_POST['max_giorni'] ?? 60)));
    $appr = isset($_POST['approvazione']) ? 1 : 0; $rip = isset($_POST['ripetizione']) ? 1 : 0;
    $motivo = isset($_POST['chiede_motivo']) ? 1 : 0; $attiva = isset($_POST['attiva']) ? 1 : 0;
    if ($id) {
        $st = $conn->prepare("UPDATE risorse SET nome=?, tipo=?, descrizione=?, luogo=?, capienza=?, referente=?, email_notifiche=?, durata_slot=?, max_slot=?, anticipo_ore=?, max_giorni=?, accesso=?, approvazione=?, ripetizione=?, chiede_motivo=?, attiva=? WHERE id=? AND pagina_id=?");
        $st->bind_param("ssssissiiiisiiiiii", $nome, $tipo, $descr, $luogo, $capienza, $referente, $emails, $durata, $max_slot, $anticipo, $max_giorni, $accesso, $appr, $rip, $motivo, $attiva, $id, $filtro_p);
        $st->execute();
    } else {
        $st = $conn->prepare("INSERT INTO risorse (pagina_id, nome, tipo, descrizione, luogo, capienza, referente, email_notifiche, durata_slot, max_slot, anticipo_ore, max_giorni, accesso, approvazione, ripetizione, chiede_motivo, attiva, ordine)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $ordine = (int)$conn->query("SELECT IFNULL(MAX(ordine), 0) + 1 n FROM risorse WHERE pagina_id = " . (int)$filtro_p)->fetch_assoc()['n'];
        $st->bind_param("issssissiiiisiiiii", $filtro_p, $nome, $tipo, $descr, $luogo, $capienza, $referente, $emails, $durata, $max_slot, $anticipo, $max_giorni, $accesso, $appr, $rip, $motivo, $attiva, $ordine);
        $st->execute();
        $id = (int)$conn->insert_id;
    }
    // Colore della riga nella vista a calendario (vuoto = grigio)
    $colore_r = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($_POST['colore'] ?? '')) && empty($_POST['colore_nessuno']) ? strtoupper($_POST['colore']) : null;
    $st_c = $conn->prepare("UPDATE risorse SET colore = ? WHERE id = ?");
    $st_c->bind_param("si", $colore_r, $id); $st_c->execute();
    // Sportello di ricevimento di un docente dell'anagrafe: il docente ne gestisce orari e appuntamenti da ricevimento.php
    $pers_r = persona_ateneo($conn, (string)($_POST['persona_id'] ?? ''));
    $pid_r = $pers_r['id'] ?? null;
    $st_p = $conn->prepare("UPDATE risorse SET persona_id = ? WHERE id = ?");
    $st_p->bind_param("si", $pid_r, $id); $st_p->execute();
    if ($pers_r) {
        // Notifiche al docente e referente, se non indicati
        if ($emails === '' && !empty($pers_r['email'])) { $st_e = $conn->prepare("UPDATE risorse SET email_notifiche = ? WHERE id = ?"); $st_e->bind_param("si", $pers_r['email'], $id); $st_e->execute(); }
        if ($referente === '') { $nome_d = nome_persona($pers_r); $st_e = $conn->prepare("UPDATE risorse SET referente = ? WHERE id = ?"); $st_e->bind_param("si", $nome_d, $id); $st_e->execute(); }
    }
    // Orari: fino a due fasce per giorno
    $conn->query("DELETE FROM risorse_orari WHERE risorsa_id = $id");
    $ins = $conn->prepare("INSERT INTO risorse_orari (risorsa_id, giorno, dalle, alle) VALUES (?, ?, ?, ?)");
    $avvisi = [];
    foreach (GIORNI_SETTIMANA as $g => $nome_g) {
        foreach ([1, 2] as $f) {
            $da = $ora_ok($_POST["dalle_{$g}_{$f}"] ?? ''); $a = $ora_ok($_POST["alle_{$g}_{$f}"] ?? '');
            if (!$da && !$a) continue;
            if (!$da || !$a || $a <= $da) { $avvisi[] = "$nome_g, fascia $f"; continue; }
            $ins->bind_param("iiss", $id, $g, $da, $a); $ins->execute();
        }
    }
    registra_log_audit($conn, "Risorsa salvata", ["Area" => $page_cfg['titolo'], "Risorsa" => $nome]);
    flash_set("Risorsa \"" . $nome . "\" salvata." . ($avvisi ? " Orari ignorati perché incompleti o al contrario: " . implode('; ', $avvisi) . "." : ''), $avvisi ? 'warning' : 'success');
    $torna(); exit;
}

// ── Eliminazione (solo senza prenotazioni future; altrimenti si disattiva) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['elimina_risorsa'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $id = (int)$_POST['elimina_risorsa'];
    $r = risorsa($conn, $id);
    if ($r && (int)$r['pagina_id'] === (int)$filtro_p) {
        $fut = (int)$conn->query("SELECT COUNT(*) n FROM prenotazioni_risorse WHERE risorsa_id = $id AND stato IN ('confermata', 'da_approvare') AND fine >= NOW()")->fetch_assoc()['n'];
        if ($fut > 0) {
            $conn->query("UPDATE risorse SET attiva = 0 WHERE id = $id");
            flash_set("\"" . $r['nome'] . "\" ha $fut prenotazioni future: non l'ho eliminata ma resa non prenotabile. Annulla prima le prenotazioni se vuoi eliminarla.", 'warning');
        } else {
            $conn->query("DELETE FROM risorse_orari WHERE risorsa_id = $id");
            $conn->query("DELETE FROM risorse_chiusure WHERE risorsa_id = $id");
            $conn->query("DELETE FROM prenotazioni_risorse WHERE risorsa_id = $id");
            $conn->query("DELETE FROM risorse WHERE id = $id");
            registra_log_audit($conn, "Risorsa eliminata", ["Area" => $page_cfg['titolo'], "Risorsa" => $r['nome']]);
            flash_set("Risorsa \"" . $r['nome'] . "\" eliminata.", 'warning');
        }
    }
    $torna(); exit;
}

// ── Chiusure ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aggiungi_chiusura'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $dal = (string)($_POST['dal'] ?? ''); $al = (string)($_POST['al'] ?? '') ?: $dal;
    $rid = (int)($_POST['risorsa_chiusura'] ?? 0);
    if ($rid && (!($rc = risorsa($conn, $rid)) || (int)$rc['pagina_id'] !== (int)$filtro_p)) $rid = 0;
    $motivo = mb_substr(trim((string)($_POST['motivo'] ?? '')), 0, 255);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dal) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $al) || $al < $dal) {
        flash_set("Date della chiusura non valide.", 'danger');
    } else {
        $rid_b = $rid ?: null;
        $st = $conn->prepare("INSERT INTO risorse_chiusure (pagina_id, risorsa_id, dal, al, motivo) VALUES (?, ?, ?, ?, ?)");
        $st->bind_param("iisss", $filtro_p, $rid_b, $dal, $al, $motivo); $st->execute();
        // Prenotazioni già presenti nei giorni chiusi: si segnalano (si annullano a mano, con l'avviso a chi ha prenotato)
        $q_c = "SELECT COUNT(*) n FROM prenotazioni_risorse pr JOIN risorse r ON r.id = pr.risorsa_id WHERE r.pagina_id = " . (int)$filtro_p
             . ($rid ? " AND r.id = $rid" : '') . " AND pr.stato IN ('confermata', 'da_approvare') AND DATE(pr.inizio) BETWEEN '" . $conn->real_escape_string($dal) . "' AND '" . $conn->real_escape_string($al) . "'";
        $n_c = (int)$conn->query($q_c)->fetch_assoc()['n'];
        flash_set("Chiusura aggiunta." . ($n_c ? " Attenzione: in quei giorni ci sono $n_c prenotazioni: annullale da Prenotazioni per avvisare chi le ha fatte." : ''), $n_c ? 'warning' : 'success');
    }
    $torna('#chiusure'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['elimina_chiusura'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $st = $conn->prepare("DELETE FROM risorse_chiusure WHERE id = ? AND pagina_id = ?");
    $cid = (int)$_POST['elimina_chiusura'];
    $st->bind_param("ii", $cid, $filtro_p); $st->execute();
    flash_set("Chiusura eliminata.");
    $torna('#chiusure'); exit;
}

$risorse = [];
$r_ris = $conn->query("SELECT r.*, (SELECT COUNT(*) FROM prenotazioni_risorse pr WHERE pr.risorsa_id = r.id AND pr.stato IN ('confermata', 'da_approvare') AND pr.fine >= NOW()) AS future
                       FROM risorse r WHERE r.pagina_id = " . (int)$filtro_p . " ORDER BY r.ordine, r.nome");
while ($r_ris && $x = $r_ris->fetch_assoc()) $risorse[] = $x;
$modifica = isset($_GET['modifica']) ? risorsa($conn, (int)$_GET['modifica']) : null;
if ($modifica && (int)$modifica['pagina_id'] !== (int)$filtro_p) $modifica = null;
$mostra_form = $modifica || isset($_GET['nuova']) || !$risorse;
$f = $modifica ?: ['id' => 0, 'nome' => '', 'tipo' => 'aula', 'descrizione' => '', 'luogo' => '', 'capienza' => null, 'referente' => '', 'email_notifiche' => '',
                   'durata_slot' => 60, 'max_slot' => 2, 'anticipo_ore' => 2, 'max_giorni' => 60, 'accesso' => 'tutti', 'approvazione' => 0, 'ripetizione' => 0, 'chiede_motivo' => 1, 'attiva' => 1];
$orari_f = $modifica ? orari_risorsa($conn, (int)$modifica['id']) : [1 => [['09:00:00', '13:00:00'], ['14:30:00', '17:30:00']], 2 => [['09:00:00', '13:00:00'], ['14:30:00', '17:30:00']], 3 => [['09:00:00', '13:00:00'], ['14:30:00', '17:30:00']], 4 => [['09:00:00', '13:00:00'], ['14:30:00', '17:30:00']], 5 => [['09:00:00', '13:00:00']]];
$chiusure = [];
$r_ch = $conn->query("SELECT c.*, r.nome AS risorsa_nome FROM risorse_chiusure c LEFT JOIN risorse r ON r.id = c.risorsa_id WHERE c.pagina_id = " . (int)$filtro_p . " AND c.al >= CURDATE() - INTERVAL 30 DAY ORDER BY c.dal");
while ($r_ch && $x = $r_ch->fetch_assoc()) $chiusure[] = $x;
$url_pub = '../' . $page_cfg['slug'] . '.php';
?>
<div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
    <h4 class="fw-bold text-dark mb-0"><i class="fa fa-door-open me-2 text-primary" aria-hidden="true"></i>Risorse, orari e chiusure</h4>
    <div class="d-flex gap-2">
        <a href="<?php echo $h($url_pub); ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm"><i class="fa fa-eye me-1" aria-hidden="true"></i>Pagina pubblica</a>
        <?php if (!$mostra_form): ?><a href="risorse.php?p_id=<?php echo $filtro_p; ?>&amp;nuova=1" class="btn btn-primary btn-sm fw-bold"><i class="fa fa-plus-circle me-1" aria-hidden="true"></i>Nuova risorsa</a><?php endif; ?>
    </div>
</div>
<p class="text-secondary small mb-3">Ogni risorsa (aula, laboratorio, sportello di un ufficio) ha i suoi orari settimanali divisi in slot: chi ha fatto l'accesso prenota dalla pagina pubblica dell'area uno o più slot consecutivi, senza sovrapposizioni.</p>

<?php if ($mostra_form): ?>
<form method="POST" class="card border-0 shadow-sm mb-4">
    <?php csrf_field(); ?>
    <input type="hidden" name="risorsa_id" value="<?php echo (int)$f['id']; ?>">
    <div class="card-header bg-white fw-bold"><?php echo $modifica ? 'Modifica: ' . $h($f['nome']) : 'Nuova risorsa'; ?></div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-5"><label class="form-label small fw-bold" for="rNome">Nome *</label><input type="text" class="form-control" id="rNome" name="nome" value="<?php echo $h($f['nome']); ?>" required maxlength="150" placeholder="es. Laboratorio di microscopia, Sportello didattica"></div>
            <div class="col-md-3"><label class="form-label small fw-bold" for="rTipo">Tipo</label>
                <select class="form-select" id="rTipo" name="tipo"><?php foreach (TIPI_RISORSA as $k => [$n]): ?><option value="<?php echo $k; ?>"<?php echo $f['tipo'] === $k ? ' selected' : ''; ?>><?php echo $h($n); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label small fw-bold" for="rLuogo">Luogo</label><input type="text" class="form-control" id="rLuogo" name="luogo" value="<?php echo $h($f['luogo']); ?>" maxlength="255" placeholder="es. Cubo 4B, piano terra"></div>
            <div class="col-md-8"><label class="form-label small fw-bold" for="rDescr">Descrizione</label><textarea class="form-control" id="rDescr" name="descrizione" rows="2" maxlength="3000" placeholder="Attrezzature, regole d'uso, cosa portare…"><?php echo $h($f['descrizione']); ?></textarea></div>
            <div class="col-md-2"><label class="form-label small fw-bold" for="rCap">Capienza</label><input type="number" min="0" class="form-control" id="rCap" name="capienza" value="<?php echo $h($f['capienza']); ?>"></div>
            <div class="col-md-2"><label class="form-label small fw-bold" for="rCol">Colore nel calendario</label>
                <input type="color" class="form-control form-control-color w-100" id="rCol" name="colore" oninput="document.getElementById('rColNo').checked = false;" value="<?php echo $h($f['colore'] ?? '') ?: '#e2e8f0'; ?>">
                <div class="form-check small mt-1"><input class="form-check-input" type="checkbox" name="colore_nessuno" value="1" id="rColNo" <?php echo empty($f['colore']) ? 'checked' : ''; ?>><label class="form-check-label" for="rColNo">nessun colore</label></div></div>
            <div class="col-md-2"><label class="form-label small fw-bold" for="rRef">Referente</label><input type="text" class="form-control" id="rRef" name="referente" value="<?php echo $h($f['referente']); ?>" maxlength="150"></div>
            <div class="col-md-4"><label class="form-label small fw-bold" for="rDoc">Docente del ricevimento <span class="fw-normal text-muted">(sportelli)</span></label>
                <select class="form-select" id="rDoc" name="persona_id"><option value="">Nessuno</option>
                    <?php $r_doc = @$conn->query("SELECT id, cognome, nome FROM personale_ateneo WHERE gruppo = 'docenti' AND attivo = 1 ORDER BY cognome, nome");
                    while ($r_doc && $d_doc = $r_doc->fetch_assoc()): ?><option value="<?php echo $h($d_doc['id']); ?>"<?php echo ($f['persona_id'] ?? '') === $d_doc['id'] ? ' selected' : ''; ?>><?php echo $h($d_doc['cognome'] . ' ' . $d_doc['nome']); ?></option><?php endwhile; ?>
                </select>
                <div class="form-text">Il docente gestisce giorni, orari e appuntamenti da "Il mio ricevimento" (Area personale); dalla sua pagina pubblica gli studenti prenotano.</div></div>
            <div class="col-12"><label class="form-label small fw-bold" for="rEmail">Email per le notifiche</label><input type="text" class="form-control" id="rEmail" name="email_notifiche" value="<?php echo $h($f['email_notifiche']); ?>" placeholder="separate da virgola; vuoto = gestori dell'area">
                <div class="form-text">Ricevono un'email a ogni nuova prenotazione, richiesta da approvare o annullamento.</div></div>
        </div>

        <h6 class="fw-bold mt-4 mb-2">Regole di prenotazione</h6>
        <div class="row g-3">
            <div class="col-6 col-md-2"><label class="form-label small fw-bold" for="rDur">Durata slot</label>
                <select class="form-select" id="rDur" name="durata_slot"><?php foreach ([10, 15, 20, 30, 45, 60, 90, 120, 180, 240] as $m): ?><option value="<?php echo $m; ?>"<?php echo (int)$f['durata_slot'] === $m ? ' selected' : ''; ?>><?php echo $m < 60 ? "$m min" : ($m / 60) . ' or' . ($m === 60 ? 'a' : 'e'); ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-2"><label class="form-label small fw-bold" for="rMax">Slot per prenotazione</label><input type="number" min="1" max="12" class="form-control" id="rMax" name="max_slot" value="<?php echo (int)$f['max_slot']; ?>"></div>
            <div class="col-6 col-md-2"><label class="form-label small fw-bold" for="rAnt">Preavviso (ore)</label><input type="number" min="0" max="720" class="form-control" id="rAnt" name="anticipo_ore" value="<?php echo (int)$f['anticipo_ore']; ?>"></div>
            <div class="col-6 col-md-2"><label class="form-label small fw-bold" for="rGg">Fino a (giorni)</label><input type="number" min="1" max="365" class="form-control" id="rGg" name="max_giorni" value="<?php echo (int)$f['max_giorni']; ?>"></div>
            <div class="col-md-4"><label class="form-label small fw-bold" for="rAcc">Chi può prenotare</label>
                <select class="form-select" id="rAcc" name="accesso"><?php foreach (ACCESSI_RISORSA as $k => $n): ?><option value="<?php echo $k; ?>"<?php echo $f['accesso'] === $k ? ' selected' : ''; ?>><?php echo $h($n); ?></option><?php endforeach; ?></select>
                <div class="form-text">I gestori dell'area possono sempre prenotare.</div></div>
            <div class="col-12 d-flex flex-wrap gap-4">
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="rAppr" name="approvazione"<?php echo (int)$f['approvazione'] ? ' checked' : ''; ?>><label class="form-check-label small" for="rAppr">Richiede approvazione dei gestori</label></div>
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="rRip" name="ripetizione"<?php echo (int)$f['ripetizione'] ? ' checked' : ''; ?>><label class="form-check-label small" for="rRip">Consenti la ripetizione settimanale (es. un corso per il semestre)</label></div>
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="rMot" name="chiede_motivo"<?php echo (int)$f['chiede_motivo'] ? ' checked' : ''; ?>><label class="form-check-label small" for="rMot">Chiedi il motivo (obbligatorio)</label></div>
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="rAtt" name="attiva"<?php echo (int)$f['attiva'] ? ' checked' : ''; ?>><label class="form-check-label small" for="rAtt">Prenotabile</label></div>
            </div>
        </div>

        <h6 class="fw-bold mt-4 mb-1">Orari settimanali</h6>
        <p class="small text-secondary mb-2">Fino a due fasce al giorno (es. mattina e pomeriggio); giorno vuoto = chiuso. Le fasce sono divise in slot della durata scelta.</p>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" style="max-width:640px;">
                <thead class="small table-light"><tr><th>Giorno</th><th>Dalle</th><th>Alle</th><th>Dalle</th><th>Alle</th></tr></thead>
                <tbody>
                <?php foreach (GIORNI_SETTIMANA as $g => $nome_g): $fasce = $orari_f[$g] ?? []; ?>
                    <tr><th class="small fw-bold"><?php echo $nome_g; ?></th>
                    <?php foreach ([1, 2] as $fx): $fa = $fasce[$fx - 1] ?? ['', '']; ?>
                        <td><input type="time" class="form-control form-control-sm" name="dalle_<?php echo $g . '_' . $fx; ?>" value="<?php echo $h(substr((string)$fa[0], 0, 5)); ?>" aria-label="<?php echo $nome_g; ?>, fascia <?php echo $fx; ?>, dalle"></td>
                        <td><input type="time" class="form-control form-control-sm" name="alle_<?php echo $g . '_' . $fx; ?>" value="<?php echo $h(substr((string)$fa[1], 0, 5)); ?>" aria-label="<?php echo $nome_g; ?>, fascia <?php echo $fx; ?>, alle"></td>
                    <?php endforeach; ?></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer bg-white d-flex gap-2">
        <button type="submit" name="salva_risorsa" value="1" class="btn btn-primary fw-bold"><i class="fa fa-save me-1" aria-hidden="true"></i>Salva</button>
        <?php if ($risorse): ?><a href="risorse.php?p_id=<?php echo $filtro_p; ?>" class="btn btn-outline-secondary">Annulla</a><?php endif; ?>
    </div>
</form>
<?php endif; ?>

<?php if ($risorse): ?>
<div class="row g-3 mb-4">
    <?php foreach ($risorse as $r): $orari = orari_risorsa($conn, (int)$r['id']); ?>
    <div class="col-md-6 col-xl-4">
        <div class="card border-0 shadow-sm h-100<?php echo (int)$r['attiva'] ? '' : ' opacity-75'; ?>">
            <div class="card-body">
                <div class="d-flex justify-content-between gap-2">
                    <h2 class="h6 fw-bold mb-1"><i class="fa <?php echo TIPI_RISORSA[$r['tipo']][1] ?? 'fa-cube'; ?> me-1 text-primary" aria-hidden="true"></i><?php echo $h($r['nome']); ?></h2>
                    <?php if (!(int)$r['attiva']): ?><span class="badge bg-secondary">Non prenotabile</span><?php endif; ?>
                </div>
                <div class="small text-secondary mb-2"><?php echo $h(implode(' · ', array_filter([TIPI_RISORSA[$r['tipo']][0] ?? '', $r['luogo'], $r['capienza'] ? $r['capienza'] . ' posti' : '']))); ?></div>
                <div class="small mb-2">
                    Slot da <strong><?php echo (int)$r['durata_slot']; ?> min</strong>, fino a <?php echo (int)$r['max_slot']; ?> di seguito · <?php echo $h(ACCESSI_RISORSA[$r['accesso']] ?? ''); ?>
                    <?php if ((int)$r['approvazione']): ?> · <span class="text-warning-emphasis fw-bold">con approvazione</span><?php endif; ?>
                    <?php if ((int)$r['ripetizione']): ?> · ripetibile ogni settimana<?php endif; ?>
                </div>
                <div class="small text-secondary">
                    <?php if (!$orari): ?><span class="text-danger">Nessun orario: non prenotabile.</span><?php endif; ?>
                    <?php foreach ($orari as $g => $fasce): ?><div><strong><?php echo mb_substr(GIORNI_SETTIMANA[$g], 0, 3); ?></strong> <?php echo $h(implode(', ', array_map(fn($x) => substr($x[0], 0, 5) . '–' . substr($x[1], 0, 5), $fasce))); ?></div><?php endforeach; ?>
                </div>
            </div>
            <div class="card-footer bg-white d-flex justify-content-between align-items-center">
                <a href="prenotazioni_risorse.php?p_id=<?php echo $filtro_p; ?>&amp;risorsa=<?php echo (int)$r['id']; ?>" class="small text-decoration-none"><strong><?php echo (int)$r['future']; ?></strong> prenotazioni future</a>
                <div class="d-flex gap-1">
                    <a href="risorse.php?p_id=<?php echo $filtro_p; ?>&amp;modifica=<?php echo (int)$r['id']; ?>" class="btn btn-sm btn-outline-primary" title="Modifica" aria-label="Modifica <?php echo $h($r['nome']); ?>"><i class="fa fa-pen" aria-hidden="true"></i></a>
                    <form method="POST" class="m-0"><?php csrf_field(); ?>
                        <button type="submit" name="elimina_risorsa" value="<?php echo (int)$r['id']; ?>" class="btn btn-sm btn-outline-danger" title="Elimina" aria-label="Elimina <?php echo $h($r['nome']); ?>"
                                data-confirm="Eliminare &quot;<?php echo $h($r['nome']); ?>&quot; con le sue prenotazioni passate? Se ha prenotazioni future verrà solo resa non prenotabile."><i class="fa fa-trash-alt" aria-hidden="true"></i></button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="card border-0 shadow-sm" id="chiusure">
    <div class="card-header bg-white fw-bold"><i class="fa fa-calendar-xmark me-1 text-danger" aria-hidden="true"></i>Chiusure (festività, ponti, manutenzione)</div>
    <div class="card-body">
        <form method="POST" class="row g-2 align-items-end mb-3">
            <?php csrf_field(); ?>
            <div class="col-6 col-md-2"><label class="form-label small fw-bold" for="chDal">Dal</label><input type="date" class="form-control form-control-sm" id="chDal" name="dal" required></div>
            <div class="col-6 col-md-2"><label class="form-label small fw-bold" for="chAl">Al</label><input type="date" class="form-control form-control-sm" id="chAl" name="al"></div>
            <div class="col-md-3"><label class="form-label small fw-bold" for="chRis">Cosa</label>
                <select class="form-select form-select-sm" id="chRis" name="risorsa_chiusura"><option value="0">Tutta l'area</option><?php foreach ($risorse as $r): ?><option value="<?php echo (int)$r['id']; ?>"><?php echo $h($r['nome']); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label small fw-bold" for="chMot">Motivo</label><input type="text" class="form-control form-control-sm" id="chMot" name="motivo" maxlength="255" placeholder="es. Vacanze di Natale"></div>
            <div class="col-md-2"><button type="submit" name="aggiungi_chiusura" value="1" class="btn btn-sm btn-outline-danger fw-bold w-100"><i class="fa fa-plus me-1" aria-hidden="true"></i>Aggiungi</button></div>
        </form>
        <?php if (!$chiusure): ?>
            <p class="small text-muted mb-0">Nessuna chiusura in programma.</p>
        <?php else: ?>
            <ul class="list-group list-group-flush small">
            <?php foreach ($chiusure as $c): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                    <span><strong><?php echo date('d/m/Y', strtotime($c['dal'])) . ($c['al'] !== $c['dal'] ? ' – ' . date('d/m/Y', strtotime($c['al'])) : ''); ?></strong>
                        · <?php echo $c['risorsa_id'] ? $h($c['risorsa_nome']) : "Tutta l'area"; ?><?php echo $c['motivo'] !== '' ? ' · ' . $h($c['motivo']) : ''; ?></span>
                    <form method="POST" class="m-0"><?php csrf_field(); ?><button type="submit" name="elimina_chiusura" value="<?php echo (int)$c['id']; ?>" class="btn btn-sm btn-link text-danger p-0" aria-label="Elimina la chiusura"><i class="fa fa-times" aria-hidden="true"></i></button></form>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php require_once 'admin_footer.php'; ?>
