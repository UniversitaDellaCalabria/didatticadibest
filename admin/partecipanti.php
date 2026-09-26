<?php
// partecipanti.php - Studenti di un'iscrizione a un progetto per le scuole e relativi attestati:
// elenco (scritto, incollato o da file), studenti senza attestato (assenti), anteprima e invio degli attestati al docente.
require_once 'admin_header.php';

if (!$can_manage_iscritti) {
    echo "<div class='alert alert-danger fw-bold shadow-sm'><i class='fa fa-ban me-2'></i> Accesso negato.</div>";
    require_once 'admin_footer.php';
    exit;
}
function admin_redirect($url) { echo "<script>window.location.replace(" . json_encode($url) . ");</script>"; exit; }

$pr_id = (int)($_GET['pr'] ?? $_POST['pr'] ?? 0);
if (!$pr_id || !pren_autorizzata($conn, $pr_id, $filtro_p, $sql_filtro_eventi_rbac)) nega_accesso();
$p = prenotazione_per_attestati($conn, $pr_id);
if (!$p || ($p['evento_tipo'] ?? '') !== 'progetto' || (int)($p['per_scuole'] ?? 1) !== 1) {
    echo "<div class='alert alert-warning'>Questa iscrizione non riguarda un progetto per le scuole.</div>";
    require_once 'admin_footer.php';
    exit;
}
$dett = get_dettagli_progetti($conn, [(int)$p['evento_id']])[(int)$p['evento_id']] ?? null;
$max = max_partecipanti_prenotazione($p, $dett);
$url_self = "partecipanti.php?p_id=$filtro_p&pr=$pr_id";

if (isset($_GET['modello'])) {
    invia_modello_elenco(array_map(fn($x) => ['cognome' => $x['cognome'], 'nome' => $x['nome']], get_partecipanti_prenotazione($conn, $pr_id)), 'studenti_' . $p['codice_prenotazione']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $errore = null;
    // Aggiunge nomi in fondo all'elenco (non tocca i codici già assegnati)
    if (isset($_POST['aggiungi']) || isset($_POST['carica_file'])) {
        $testo = isset($_POST['carica_file']) ? testo_da_file_elenco($_FILES['file_elenco'] ?? [], $errore) : (string)($_POST['elenco'] ?? '');
        if ($testo !== null) {
            $nuovi = leggi_elenco_partecipanti($testo);
            $esistenti = get_partecipanti_prenotazione($conn, $pr_id);
            $chiavi = array_flip(array_map(fn($s) => mb_strtolower($s['cognome'] . '|' . $s['nome']), $esistenti));
            $ord = count($esistenti);
            $ins = $conn->prepare("INSERT INTO partecipanti_prenotazione (prenotazione_id, cognome, nome, ordine) VALUES (?, ?, ?, ?)");
            $n_add = 0;
            foreach ($nuovi as $r) {
                if (isset($chiavi[mb_strtolower($r['cognome'] . '|' . $r['nome'])])) continue;
                $ins->bind_param("issi", $pr_id, $r['cognome'], $r['nome'], $ord); $ins->execute(); $ord++; $n_add++;
            }
            flash_set("Aggiunti $n_add studenti." . ($ord > $max ? " Attenzione: ora sono $ord, più dei $max dichiarati nell'iscrizione." : ''), $ord > $max ? 'warning' : 'success');
        }
    }
    if (isset($_POST['escludi'])) {
        $s_id = (int)$_POST['escludi']; $val = (int)($_POST['val'] ?? 0) === 1 ? 1 : 0;
        $conn->query("UPDATE partecipanti_prenotazione SET escluso = $val WHERE id = $s_id AND prenotazione_id = $pr_id");
        flash_set($val ? "Lo studente non riceverà l'attestato." : "Lo studente riceverà l'attestato.");
    }
    if (isset($_POST['elimina'])) {
        $s_id = (int)$_POST['elimina'];
        $conn->query("DELETE FROM partecipanti_prenotazione WHERE id = $s_id AND prenotazione_id = $pr_id");
        flash_set("Studente tolto dall'elenco.");
    }
    if (isset($_POST['svuota']) && empty($p['attestato_inviato'])) {
        $conn->query("DELETE FROM partecipanti_prenotazione WHERE prenotazione_id = $pr_id");
        flash_set("Elenco svuotato.");
    }
    if (isset($_POST['segna_presente'])) {
        $conn->query("UPDATE prenotazioni SET presente = 1, data_presenza = COALESCE(data_presenza, NOW()) WHERE id = $pr_id");
        flash_set("Presenza della scuola registrata.");
    }
    if (isset($_POST['invia'])) {
        $esito = invia_attestati_gruppo($conn, $pr_id, true);
        if ($esito === true) { registra_log_audit($conn, "Invio attestati studenti", ["Prenotazione ID" => $pr_id]); flash_set("Attestati inviati al docente (" . $p['email'] . ")."); }
        else flash_set("Attestati non inviati: " . $esito, 'danger');
    }
    if ($errore) flash_set($errore, 'danger');
    admin_redirect($url_self);
}

$studenti = get_partecipanti_prenotazione($conn, $pr_id);
$n_validi = count(array_filter($studenti, fn($s) => empty($s['escluso'])));
$scuola = nome_scuola_prenotazione($p);
$col_area = colore_valido($page_cfg['colore_primario'] ?? '', '#0056B3');
?>
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <h4 class="fw-bold text-dark mb-0"><i class="fa fa-graduation-cap me-2" style="color:<?php echo h($col_area); ?>" aria-hidden="true"></i>Studenti e attestati</h4>
    <a href="progetti.php?p_id=<?php echo $filtro_p; ?>" class="btn btn-outline-secondary btn-sm fw-bold"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i>Progetti</a>
</div>

<div class="card border-0 shadow-sm mb-3" style="border-left:5px solid <?php echo h($col_area); ?> !important;">
    <div class="card-body">
        <div class="fw-bold fs-5"><?php echo h($p['evento_titolo']); ?><?php if (!empty($p['nome_turno']) && !in_array($p['nome_turno'], ['Iscrizione scuole', 'Iscrizioni'], true)): ?> <span class="badge bg-secondary"><?php echo h($p['nome_turno']); ?></span><?php endif; ?></div>
        <div class="small text-secondary d-flex flex-wrap gap-3 mt-1">
            <?php if ($scuola !== ''): ?><span><i class="fa fa-school me-1"></i><?php echo h($scuola); ?></span><?php endif; ?>
            <span><i class="fa fa-user-tie me-1"></i><?php echo h($p['nome'] . ' ' . $p['cognome']); ?> · <a href="mailto:<?php echo h($p['email']); ?>"><?php echo h($p['email']); ?></a></span>
            <span><i class="fa fa-calendar-days me-1"></i><?php echo h(periodo_progetto($p)); ?></span>
            <span><i class="fa fa-hashtag me-1"></i><?php echo h($p['codice_prenotazione']); ?></span>
        </div>
        <div class="d-flex flex-wrap gap-2 mt-3 align-items-center">
            <span class="badge fs-6 <?php echo count($studenti) > $max ? 'bg-danger' : 'bg-light text-dark border'; ?>"><?php echo count($studenti); ?> / <?php echo $max; ?> studenti</span>
            <?php if ((int)$p['presente'] === 1): ?><span class="badge bg-success fs-6"><i class="fa fa-check me-1"></i>Presenza registrata</span>
            <?php else: ?>
                <form method="POST" class="m-0"><?php csrf_field(); ?><input type="hidden" name="pr" value="<?php echo $pr_id; ?>"><button type="submit" name="segna_presente" value="1" class="btn btn-sm btn-outline-success fw-bold" data-confirm="Registrare la presenza della scuola? Senza presenza gli attestati non partono."><i class="fa fa-user-check me-1"></i>Segna la scuola presente</button></form>
            <?php endif; ?>
            <?php if (!empty($p['attestato_inviato'])): ?><span class="badge bg-success-subtle text-success-emphasis fs-6"><i class="fa fa-envelope-circle-check me-1"></i>Attestati inviati</span><?php endif; ?>
            <?php if ((int)($p['attestati'] ?? 0) !== 1): ?><span class="badge bg-warning text-dark">Il progetto non prevede attestati: attiva l'opzione nella scheda del progetto</span><?php endif; ?>
            <div class="ms-auto d-flex gap-2 flex-wrap">
                <?php if ($n_validi > 0 && (int)$p['presente'] === 1): ?><a href="../attestati_gruppo.php?code=<?php echo urlencode($p['codice_prenotazione']); ?>" target="_blank" class="btn btn-sm btn-outline-dark fw-bold"><i class="fa fa-eye me-1"></i>Anteprima attestati (<?php echo $n_validi; ?>)</a><?php endif; ?>
                <form method="POST" class="m-0"><?php csrf_field(); ?><input type="hidden" name="pr" value="<?php echo $pr_id; ?>">
                    <button type="submit" name="invia" value="1" class="btn btn-sm btn-success fw-bold" data-confirm="<?php echo !empty($p['attestato_inviato']) ? 'Gli attestati sono già stati inviati: inviare di nuovo l\'email al docente?' : 'Inviare ora al docente gli attestati di ' . $n_validi . ' studenti?'; ?>" <?php echo ($n_validi === 0 || (int)$p['presente'] !== 1) ? 'disabled' : ''; ?>><i class="fa fa-paper-plane me-1"></i><?php echo !empty($p['attestato_inviato']) ? 'Invia di nuovo' : 'Invia attestati ora'; ?></button>
                </form>
            </div>
        </div>
        <div class="small text-muted mt-2">In automatico gli attestati partono il giorno dopo la fine del progetto, se la presenza è registrata e l'elenco non è vuoto.</div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h5 class="fw-bold">Elenco</h5>
                <?php if (!$studenti): ?>
                    <div class="text-muted small py-3">Il docente non ha ancora inserito l'elenco. Puoi aggiungerlo tu qui accanto.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>#</th><th>Studente</th><th>Codice attestato</th><th class="text-end">Azioni</th></tr></thead>
                            <tbody>
                            <?php foreach ($studenti as $i => $s): ?>
                                <tr class="<?php echo !empty($s['escluso']) ? 'text-muted' : ''; ?>">
                                    <td><?php echo $i + 1; ?></td>
                                    <td class="fw-semibold"><?php echo h(nome_partecipante($s)); ?><?php if (!empty($s['escluso'])): ?> <span class="badge bg-secondary">senza attestato</span><?php endif; ?></td>
                                    <td class="font-monospace small"><?php if (!empty($s['codice'])): ?><a href="../verifica_attestato.php?c=<?php echo urlencode($s['codice']); ?>" target="_blank"><?php echo h($s['codice']); ?></a><?php else: ?>—<?php endif; ?></td>
                                    <td class="text-end text-nowrap">
                                        <?php if (!empty($s['codice']) && empty($s['escluso']) && (int)$p['presente'] === 1): ?><a href="../attestati_gruppo.php?code=<?php echo urlencode($p['codice_prenotazione']); ?>&solo=<?php echo urlencode($s['codice']); ?>" target="_blank" class="btn btn-sm btn-outline-secondary py-0" title="Attestato di questo studente"><i class="fa fa-file-pdf"></i></a><?php endif; ?>
                                        <form method="POST" class="d-inline m-0"><?php csrf_field(); ?><input type="hidden" name="pr" value="<?php echo $pr_id; ?>"><input type="hidden" name="escludi" value="<?php echo (int)$s['id']; ?>"><input type="hidden" name="val" value="<?php echo empty($s['escluso']) ? 1 : 0; ?>">
                                            <button type="submit" class="btn btn-sm py-0 <?php echo empty($s['escluso']) ? 'btn-outline-warning text-dark' : 'btn-outline-success'; ?>" title="<?php echo empty($s['escluso']) ? 'Assente: niente attestato' : 'Rimetti l\'attestato'; ?>"><?php echo empty($s['escluso']) ? 'Assente' : 'Presente'; ?></button></form>
                                        <form method="POST" class="d-inline m-0"><?php csrf_field(); ?><input type="hidden" name="pr" value="<?php echo $pr_id; ?>"><input type="hidden" name="elimina" value="<?php echo (int)$s['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger py-0" data-confirm="<?php echo !empty($s['codice']) ? 'Togliere lo studente? Il suo attestato non risulterà più valido.' : 'Togliere lo studente dall\'elenco?'; ?>" title="Togli dall'elenco"><i class="fa fa-times"></i></button></form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (empty($p['attestato_inviato'])): ?>
                        <form method="POST" class="mt-2"><?php csrf_field(); ?><input type="hidden" name="pr" value="<?php echo $pr_id; ?>"><button type="submit" name="svuota" value="1" class="btn btn-sm btn-link text-danger p-0" data-confirm="Svuotare tutto l'elenco?">Svuota l'elenco</button></form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h5 class="fw-bold">Aggiungi studenti</h5>
                <form method="POST"><?php csrf_field(); ?><input type="hidden" name="pr" value="<?php echo $pr_id; ?>">
                    <textarea name="elenco" class="form-control font-monospace form-control-sm" rows="6" placeholder="Cognome;Nome (uno per riga, anche incollato da Excel)"></textarea>
                    <button type="submit" name="aggiungi" value="1" class="btn btn-sm btn-primary fw-bold mt-2 w-100"><i class="fa fa-plus me-1"></i>Aggiungi all'elenco</button>
                </form>
                <hr>
                <form method="POST" enctype="multipart/form-data"><?php csrf_field(); ?><input type="hidden" name="pr" value="<?php echo $pr_id; ?>">
                    <label class="form-label small fw-bold" for="fileEl">Da file (.xlsx o .csv)</label>
                    <input type="file" name="file_elenco" id="fileEl" class="form-control form-control-sm mb-2" accept=".xlsx,.csv,.txt" required>
                    <button type="submit" name="carica_file" value="1" class="btn btn-sm btn-outline-primary fw-bold w-100"><i class="fa fa-upload me-1"></i>Aggiungi dal file</button>
                </form>
                <a href="<?php echo h($url_self); ?>&modello=1" class="btn btn-sm btn-outline-success fw-bold w-100 mt-2"><i class="fa fa-file-excel me-1"></i>Scarica elenco / modello Excel</a>
            </div>
        </div>
    </div>
</div>
<?php require_once 'admin_footer.php'; ?>
