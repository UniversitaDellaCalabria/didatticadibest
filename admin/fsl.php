<?php
// fsl.php - Formazione Scuola Lavoro (solo amministratori): pannello unico con quattro schede
//   Riepilogo    anno scolastico di tutte le attività con "Attività di Formazione Scuola Lavoro" (qualunque area)
//   Da stipulare scuole con prenotazioni in essere e convenzione da stipulare; Allegato A e Convenzione già precompilati da scaricare
//   Convenzioni  registro per scuola: file firmati, validità, docenti dell'Allegato A; registrazione e modifica
//   Verifica     iscrizioni (anche confermate) senza una convenzione che copre il periodo dell'attività
//   Valutazioni  schede di valutazione della struttura ospitante compilate dai docenti
// Tabelle esportabili in CSV.
require_once 'admin_header.php';

// Amministratori e abilitati alla FSL (tutto) o alle sole convenzioni
if (!$puo_fsl_convenzioni) nega_accesso();

function admin_redirect($url) { echo "<script>window.location.replace(" . json_encode($url) . ");</script>"; exit; }

$SCHEDE = ['riepilogo' => ['Riepilogo', 'fa-chart-column'], 'stipulare' => ['Da stipulare', 'fa-file-circle-exclamation'], 'convenzioni' => ['Convenzioni', 'fa-file-signature'],
           'verifica' => ['Verifica iscrizioni', 'fa-list-check'], 'valutazioni' => ['Valutazioni', 'fa-star']];
// Abilitati alle sole convenzioni: solo quella scheda
if (!$puo_fsl) $SCHEDE = array_intersect_key($SCHEDE, ['stipulare' => 1, 'convenzioni' => 1]);
$tab = isset($SCHEDE[$_GET['tab'] ?? '']) ? $_GET['tab'] : array_key_first($SCHEDE);

// ── Azioni: registro delle convenzioni e verifica delle iscrizioni ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $registro_fsl = \App\Core\App::per($conn)->get(\App\Fsl\ServizioRegistroConvenzioni::class);
    // Protocollo di una convenzione compilata online dalla scuola: compare nei documenti Word generati
    if (isset($_POST['conv_online_prot'])) {
        \App\Core\App::per($conn)->get(\App\Fsl\ServizioConvenzioneOnline::class)->salvaProtocollo((int)$_POST['conv_online_prot'], (string)($_POST['protocollo'] ?? ''), (string)($_POST['protocollo_data'] ?? ''));
        flash_set("Protocollo salvato: compare nei documenti della convenzione.");
        admin_redirect("fsl.php?p_id=$filtro_p&tab=convenzioni&r=" . time());
    }
    // Registro delle convenzioni: registrazione o modifica (con i file firmati e i docenti dell'Allegato A)
    if (isset($_POST['conv_salva'])) {
        $esito_cv = $registro_fsl->salvaDalPannello($_POST, $_FILES, (string)($_SESSION['utente_email'] ?? ''));
        if (!$esito_cv->salvata) {
            flash_set("Convenzione non salvata: scegli la scuola dall'elenco dell'anagrafe e controlla le date (\"valida fino al\" non può precedere \"valida dal\").", 'danger');
            admin_redirect("fsl.php?p_id=$filtro_p&tab=convenzioni" . ($esito_cv->id > 0 ? "&conv_mod={$esito_cv->id}" : '') . "&r=" . time() . "#convForm");
        }
        $cod_cv = $esito_cv->codiceScuola; $n_cv = $esito_cv->prenotazioniAggiornate; $errori_file = $esito_cv->fileNonCaricati;
        $s_cv = scuola_per_codice($conn, $cod_cv);
        registra_log_audit($conn, $esito_cv->modifica ? "Convenzione modificata" : "Convenzione registrata", ["Scuola" => $cod_cv, "Valida dal" => $_POST['data_stipula'] ?? '', "Valida fino al" => $_POST['scadenza'] ?? '', "Prenotazioni aggiornate" => $n_cv]);
        flash_set("Convenzione " . ($esito_cv->modifica ? "aggiornata" : "registrata") . " per " . etichetta_scuola($s_cv) . "."
                  . ($n_cv ? " $n_cv prenotazioni della scuola coperte dalla convenzione (chi era in attesa è stato avvisato per email)." : '')
                  . ($errori_file ? " File non caricati (serve un PDF; la convenzione deve essere firmata digitalmente in PAdES, i .p7m CAdES non sono accettati):" . implode(', ', $errori_file) . "." : ''), $errori_file ? 'warning' : 'success');
    }
    if (isset($_POST['conv_elimina'])) {
        $id_cv = (int)$_POST['conv_elimina'];
        $vecchia = $registro_fsl->elimina($id_cv);
        if ($vecchia) {
            registra_log_audit($conn, "Convenzione eliminata dal registro", ["ID" => $id_cv, "Scuola" => $vecchia['scuola_codice']]);
            flash_set("Convenzione eliminata dal registro con i suoi file. Premi \"Verifica ora\" per aggiornare le iscrizioni della scuola.", 'warning');
        }
    }
    if (isset($_POST['conv_verifica']) && $puo_fsl) {
        $v = \App\Core\App::per($conn)->get(\App\Fsl\ServizioConvenzioni::class)->verifica();
        registra_log_audit($conn, "Verifica convenzioni FSL", $v);
        flash_set("Verifica completata: {$v['coperte']} iscrizioni coperte da una convenzione, {$v['da_stipulare']} senza convenzione valida per il periodo"
                  . ($v['nuove_da_stipulare'] ? " ({$v['nuove_da_stipulare']} segnate ora come \"da stipulare\")" : '')
                  . ($v['senza_codice'] ? ", {$v['senza_codice']} con la scuola scritta a mano (abbinala all'anagrafe per verificarla)" : '') . ".",
                  $v['da_stipulare'] || $v['senza_codice'] ? 'warning' : 'success');
    }
    admin_redirect("fsl.php?p_id=$filtro_p&tab=" . (isset($_POST['conv_verifica']) ? 'verifica' : 'convenzioni') . (($_GET['vista'] ?? '') === 'archivio' ? '&vista=archivio' : '') . "&r=" . time());
}

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$d = fn($x) => $x ? date('d/m/Y', strtotime($x)) : '—';

$pannello_fsl = \App\Core\App::per($conn)->get(\App\Fsl\ServizioPannelloFsl::class);
// Scuole con prenotazioni FSL in essere (scheda «Da stipulare»): convenzione da stipulare e documenti precompilati
$scuole_fsl = \App\Core\App::per($conn)->get(\App\Fsl\ServizioConvenzioniScuole::class)->scuole();
$n_scuole_da_stipulare = count(array_filter($scuole_fsl, fn($g) => $g['da_stipulare']));

// Anno scolastico: dal 1° settembre al 31 agosto (default: quello in corso)
$anno_corr = $pannello_fsl->annoCorrente();
$anno = (int)($_GET['anno'] ?? $anno_corr);
if ($anno < 2015 || $anno > $anno_corr + 1) $anno = $anno_corr;
$dal_a = "$anno-09-01"; $al_a = ($anno + 1) . "-08-31";

// Iscrizioni alle attività FSL che iniziano nell'anno scolastico (esclusi annullamenti, rifiuti, scadute), numeri per scuola e per attività, schede di valutazione
['righe' => $righe, 'conf' => $conf, 'per_scuola' => $per_scuola, 'per_att' => $per_att, 'n_studenti' => $n_studenti, 'n_conv_mancanti' => $n_conv_mancanti,
 'valutazioni' => $valutazioni, 'somme' => $somme, 'rip' => $rip, 'n_inviti' => $n_inviti] = $pannello_fsl->riepilogo($dal_a, $al_a);
$media = fn($arr) => $arr ? number_format(array_sum($arr) / count($arr), 1, ',', '') : '—';
$anni = range($anno_corr + 1, max(2020, $anno_corr - 5));

// Registro delle convenzioni raggruppato per scuola (la più recente per prima), diviso in due viste:
// in vigore (valide, in scadenza, non ancora valide) e archivio (scadute: ci passano da sole il giorno dopo la scadenza)
$vista_cv = ($_GET['vista'] ?? '') === 'archivio' ? 'archivio' : 'vigore';
['vigore' => $conv_vigore, 'archivio' => $conv_archivio] = $pannello_fsl->registro();
$conv_per_scuola = $vista_cv === 'archivio' ? $conv_archivio : $conv_vigore;
// Iscrizioni senza convenzione valida: da stipulare (attività FSL o richiesta dai gestori) e scuole scritte a mano nelle attività FSL
[$iscr_da_stipulare, $iscr_ora_coperte] = $pannello_fsl->daStipulare();
// Modifica di una convenzione, oppure nuova con scuola e periodo proposti (pulsante "Registra" della verifica)
$conv_mod = !empty($_GET['conv_mod']) ? $pannello_fsl->convenzione((int)$_GET['conv_mod']) : null;
$conv_nuova_s = !$conv_mod && !empty($_GET['conv_nuova']) ? scuola_per_codice($conn, (string)$_GET['conv_nuova']) : null;
$conv_rinnova = !$conv_mod && !empty($_GET['conv_rinnova']) ? $pannello_fsl->convenzione((int)$_GET['conv_rinnova']) : null;
if ($conv_rinnova) $conv_nuova_s = scuola_per_codice($conn, $conv_rinnova['scuola_codice']);
$data_get = fn($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET[$k] ?? '')) ? $_GET[$k] : null;
[$conv_def_dal, $conv_def_al] = periodo_nuova_convenzione($data_get('dal'), $data_get('al'));
$oggi_cv = date('Y-m-d'); $tra60_cv = date('Y-m-d', strtotime('+60 days'));
?>
<style>
.fsl-sez { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:1.25rem; margin-bottom:1rem; box-shadow:0 1px 4px rgba(0,0,0,.04); }
.fsl-sez h2 { font-size:.8rem; text-transform:uppercase; letter-spacing:.06em; font-weight:800; color:#1e293b; margin-bottom:1rem; }
.fsl-num { font-size:1.6rem; font-weight:800; color:#0f172a; line-height:1; }
.fsl-barra { height:10px; background:#e2e8f0; border-radius:6px; overflow:hidden; min-width:120px; }
.fsl-barra > span { display:block; height:100%; background:#b30000; }
.scu-sez { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:1.25rem; margin-bottom:1rem; box-shadow:0 1px 4px rgba(0,0,0,.04); scroll-margin-top: 80px; }
.scu-sez h2 { font-size:.8rem; text-transform:uppercase; letter-spacing:.06em; font-weight:800; color:#1e293b; margin-bottom:1rem; }
.fsl-tabs .nav-link { font-weight:700; color:#475569; }
.fsl-tabs .nav-link.active { color:#b30000; border-bottom:3px solid #b30000; }
</style>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <h4 class="fw-bold text-dark mb-0"><i class="fa fa-briefcase me-2" style="color:#b30000;" aria-hidden="true"></i>Formazione Scuola Lavoro</h4>
    <?php if (in_array($tab, ['riepilogo', 'valutazioni'], true)): ?>
    <form method="GET" class="d-flex gap-2 align-items-center">
        <input type="hidden" name="p_id" value="<?php echo (int)$filtro_p; ?>">
        <input type="hidden" name="tab" value="<?php echo $h($tab); ?>">
        <label for="fslAnno" class="small fw-bold">Anno scolastico</label>
        <select name="anno" id="fslAnno" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
            <?php foreach ($anni as $a): ?><option value="<?php echo $a; ?>" <?php echo $a === $anno ? 'selected' : ''; ?>><?php echo $a . '/' . ($a + 1); ?></option><?php endforeach; ?>
        </select>
    </form>
    <?php endif; ?>
</div>

<ul class="nav nav-tabs fsl-tabs mb-3">
    <?php foreach ($SCHEDE as $k_t => [$lbl_t, $ico_t]): ?>
        <li class="nav-item"><a class="nav-link <?php echo $tab === $k_t ? 'active' : ''; ?>" <?php echo $tab === $k_t ? 'aria-current="page"' : ''; ?> href="fsl.php?p_id=<?php echo (int)$filtro_p; ?>&tab=<?php echo $k_t; ?><?php echo in_array($k_t, ['riepilogo', 'valutazioni'], true) ? '&anno=' . $anno : ''; ?>">
            <i class="fa <?php echo $ico_t; ?> me-1" aria-hidden="true"></i><?php echo $lbl_t; ?>
            <?php if ($k_t === 'stipulare' && $n_scuole_da_stipulare): ?><span class="badge bg-danger ms-1" title="Scuole con una convenzione da stipulare"><?php echo $n_scuole_da_stipulare; ?></span><?php endif; ?>
            <?php if ($k_t === 'verifica' && $iscr_da_stipulare): ?><span class="badge bg-danger ms-1"><?php echo count($iscr_da_stipulare); ?></span><?php endif; ?>
            <?php if ($k_t === 'convenzioni'): ?><span class="badge bg-light text-dark border ms-1" title="Scuole con convenzione in vigore"><?php echo count($conv_vigore); ?></span><?php endif; ?>
        </a></li>
    <?php endforeach; ?>
</ul>

<?php if ($tab === 'riepilogo'): ?>
<p class="text-secondary small">Tutte le attività con <strong>Attività di Formazione Scuola Lavoro</strong> attivo, di ogni area, che iniziano tra il <?php echo $d($dal_a); ?> e il <?php echo $d($al_a); ?>. Studenti = numero dichiarato nelle iscrizioni confermate. Ogni tabella si scarica in CSV (separatore ;, per Excel).</p>

<section class="fsl-sez">
    <div class="row g-3 text-center text-md-start">
        <div class="col-6 col-md-2"><div class="fsl-num"><?php echo count($per_att); ?></div><div class="small text-secondary">attività</div></div>
        <div class="col-6 col-md-2"><div class="fsl-num"><?php echo count($per_scuola); ?></div><div class="small text-secondary">scuole</div></div>
        <div class="col-6 col-md-2"><div class="fsl-num"><?php echo count($conf); ?></div><div class="small text-secondary">iscrizioni confermate<?php echo count($righe) > count($conf) ? ' (+' . (count($righe) - count($conf)) . ' in attesa)' : ''; ?></div></div>
        <div class="col-6 col-md-2"><div class="fsl-num"><?php echo $n_studenti; ?></div><div class="small text-secondary">studenti</div></div>
        <div class="col-6 col-md-2"><div class="fsl-num <?php echo $n_conv_mancanti ? 'text-danger' : ''; ?>"><?php echo $n_conv_mancanti; ?></div><div class="small text-secondary">iscrizioni senza convenzione valida<?php if ($n_conv_mancanti): ?> · <a href="fsl.php?p_id=<?php echo (int)$filtro_p; ?>&tab=verifica">verifica</a><?php endif; ?></div></div>
        <div class="col-6 col-md-2"><div class="fsl-num"><?php echo count($valutazioni); ?><?php echo $n_inviti ? '<span class="fs-6 text-secondary">/' . $n_inviti . '</span>' : ''; ?></div><div class="small text-secondary">schede di valutazione<?php echo $valutazioni ? ' · media ' . $media(array_map(fn($x) => (float)$x['media'], $valutazioni)) : ''; ?></div></div>
    </div>
</section>

<section class="fsl-sez">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
        <h2 class="mb-0"><i class="fa fa-list me-1" aria-hidden="true"></i>Attività (<?php echo count($per_att); ?>)</h2>
        <button type="button" class="btn btn-sm btn-outline-secondary fw-bold fsl-csv" data-tab="tabAtt" data-nome="fsl_attivita_<?php echo $anno; ?>.csv"><i class="fa fa-file-csv me-1" aria-hidden="true"></i>CSV</button>
    </div>
    <?php if (!$per_att): ?><p class="text-muted small mb-0">Nessuna attività FSL con iscrizioni in questo anno scolastico.</p><?php else: ?>
    <div class="table-responsive"><table class="table table-sm align-middle small" id="tabAtt">
        <thead class="table-light"><tr><th>Attività</th><th>Area</th><th>Periodo</th><th class="text-center">Scuole</th><th class="text-center">Confermate</th><th class="text-center">Studenti</th><th class="text-center">Presenze</th><th class="text-center">Senza convenzione</th><th class="text-center">Valutazioni</th><th class="text-center">Media</th></tr></thead>
        <tbody>
        <?php foreach ($per_att as $a): ?>
            <tr>
                <td class="fw-semibold"><?php echo $h($a['titolo']); ?></td><td><?php echo $h($a['area']); ?></td>
                <td class="text-nowrap"><?php echo $d($a['dal']) . ($a['al'] !== $a['dal'] ? ' – ' . $d($a['al']) : ''); ?></td>
                <td class="text-center"><?php echo count($a['scuole']); ?></td><td class="text-center"><?php echo (int)($a['confermate'] ?? 0); ?></td>
                <td class="text-center"><?php echo (int)($a['studenti'] ?? 0); ?></td><td class="text-center"><?php echo (int)($a['presenze'] ?? 0); ?></td>
                <td class="text-center <?php echo !empty($a['conv_mancanti']) ? 'text-danger fw-bold' : ''; ?>"><?php echo (int)($a['conv_mancanti'] ?? 0); ?></td>
                <td class="text-center"><?php echo count($a['voti'] ?? []) . '/' . (int)($a['val_inviate'] ?? 0); ?></td><td class="text-center"><?php echo $media($a['voti'] ?? []); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</section>

<section class="fsl-sez">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
        <h2 class="mb-0"><i class="fa fa-school me-1" aria-hidden="true"></i>Scuole (<?php echo count($per_scuola); ?>)</h2>
        <button type="button" class="btn btn-sm btn-outline-secondary fw-bold fsl-csv" data-tab="tabScu" data-nome="fsl_scuole_<?php echo $anno; ?>.csv"><i class="fa fa-file-csv me-1" aria-hidden="true"></i>CSV</button>
    </div>
    <?php if (!$per_scuola): ?><p class="text-muted small mb-0">Nessuna scuola in questo anno scolastico.</p><?php else: ?>
    <div class="table-responsive"><table class="table table-sm align-middle small" id="tabScu">
        <thead class="table-light"><tr><th>Scuola</th><th>Codice</th><th>Attività</th><th class="text-center">Iscrizioni</th><th class="text-center">Studenti</th><th class="text-center">Presenze</th><th>Convenzione in registro</th><th class="text-center">Valutazione</th></tr></thead>
        <tbody>
        <?php foreach ($per_scuola as $s): $ult = !empty($s['codice']) ? (convenzioni_della_scuola($conn, $s['codice'])[0] ?? null) : null; ?>
            <tr>
                <td class="fw-semibold"><?php echo $h($s['nome']); ?></td><td class="font-monospace"><?php echo $h($s['codice'] ?: '—'); ?></td>
                <td><?php echo $h(implode(', ', array_keys($s['attivita']))); ?></td>
                <td class="text-center"><?php echo (int)$s['iscrizioni']; ?></td><td class="text-center"><?php echo (int)($s['studenti'] ?? 0); ?></td><td class="text-center"><?php echo (int)($s['presenze'] ?? 0); ?></td>
                <td><?php echo $ult ? $h(testo_validita_convenzione($ult)) : '<span class="text-secondary">nessuna</span>'; ?><?php if (!empty($s['conv_mancanti'])): ?> <span class="badge" style="background:#fee2e2;color:#991b1b;">da stipulare</span><?php endif; ?></td>
                <td class="text-center"><?php echo $media($s['voti'] ?? []); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</section>

<?php elseif ($tab === 'convenzioni'): ?>
<section class="scu-sez" id="convenzioni">
    <p class="small text-secondary">Registro delle convenzioni per la Formazione Scuola Lavoro, con i file firmati (convenzione e Allegato A), il periodo di validità e i docenti di riferimento. Nelle attività con l'interruttore <strong>Attività di Formazione Scuola Lavoro</strong> la convenzione deve coprire <strong>tutto il periodo</strong> del progetto (Dal/Al) o il giorno del turno dell'evento: se lo copre, la scuola non deve inviare nulla; se non lo copre, ne va stipulata una nuova. Registrando o modificando una convenzione, le prenotazioni della scuola in attesa si confermano da sole (se il turno non chiede anche l'approvazione) e la scuola riceve l'email. Gli amministratori ricevono un avviso 60 giorni prima della scadenza.</p>

    <?php // Convenzioni compilate online dalle scuole (convenzione_online.php): si aspetta la PEC con i documenti firmati
    $cv_online = \App\Core\App::per($conn)->get(\App\Fsl\ServizioConvenzioneOnline::class)->recenti();
    if ($cv_online): ?>
    <details class="border rounded p-2 mb-3" style="background:#fffbeb;"<?php echo count($cv_online) <= 5 ? ' open' : ''; ?>>
        <summary class="fw-bold small"><i class="fa fa-wand-magic-sparkles me-1" aria-hidden="true"></i>Compilate online dalle scuole (<?php echo count($cv_online); ?>): in attesa della PEC con i documenti firmati</summary>
        <div class="table-responsive mt-2"><table class="table table-sm small align-middle mb-0">
            <thead><tr><th>Scuola</th><th>Dirigente</th><th>Attività nell'Allegato A</th><th>Compilata</th><th>Protocollo</th><th></th></tr></thead><tbody>
            <?php foreach ($cv_online as $co): $dco = json_decode((string)$co['dati_json'], true) ?: []; $sco = $dco['scuola'] ?? []; $reg = convenzione_valida($conn, $co['scuola_codice']); ?>
                <tr><td><strong><?php echo $h($sco['denominazione'] ?? ''); ?></strong><div class="text-secondary"><?php echo $h($co['scuola_codice'] ?: 'scuola non in anagrafe'); ?><?php echo !empty($sco['pec']) ? ' · ' . $h($sco['pec']) : ''; ?></div></td>
                    <td><?php echo $h($sco['dirigente'] ?? ''); ?></td>
                    <td><?php echo count($dco['attivita'] ?? []); ?><?php $np = count(array_filter($dco['attivita'] ?? [], fn($a) => empty($a['pr']))); echo $np ? " <span class='badge bg-warning text-dark'>$np da prenotare</span>" : ''; ?></td>
                    <td class="text-nowrap"><?php echo date('d/m/Y', strtotime($co['aggiornata_il'])); ?><?php echo $reg ? ' <span class="badge bg-success">registrata</span>' : ''; ?></td>
                    <td><form method="POST" class="d-flex gap-1"><?php csrf_field(); ?><input class="form-control form-control-sm" style="width:110px;" name="protocollo" value="<?php echo $h($co['protocollo'] ?? ''); ?>" placeholder="n." aria-label="Numero di protocollo"><input type="date" class="form-control form-control-sm" style="width:130px;" name="protocollo_data" value="<?php echo $h($co['protocollo_data'] ?? ''); ?>" aria-label="Data del protocollo"><button class="btn btn-sm btn-outline-secondary py-0" name="conv_online_prot" value="<?php echo (int)$co['id']; ?>" aria-label="Salva il protocollo"><i class="fa fa-check" aria-hidden="true"></i></button></form></td>
                    <td class="text-nowrap"><a class="btn btn-sm btn-outline-primary py-0" href="../convenzione_online.php?t=<?php echo $h($co['token']); ?>&amp;scarica=convenzione"><i class="fa fa-file-word me-1" aria-hidden="true"></i>Word</a>
                        <?php if (!$reg && $co['scuola_codice']): ?><a class="btn btn-sm btn-outline-success py-0" href="fsl.php?p_id=<?php echo (int)$filtro_p; ?>&amp;tab=convenzioni&amp;conv_nuova=<?php echo $h($co['scuola_codice']); ?>#convForm">Registra</a><?php endif; ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
    </details>
    <?php endif; ?>

    <!-- REGISTRA / MODIFICA -->
    <h3 class="h6 fw-bold mt-3" id="convForm"><?php echo $conv_mod ? 'Modifica la convenzione' : ($conv_rinnova ? 'Rinnova la convenzione' : 'Registra una convenzione'); ?></h3>
    <?php if ($conv_rinnova): ?><p class="small text-secondary mb-2">Nuova convenzione per la stessa scuola, con i docenti di riferimento della precedente (<?php echo $h(testo_validita_convenzione($conv_rinnova)); ?><?php echo $conv_rinnova['protocollo'] !== '' ? (preg_match('/^prot/i', $conv_rinnova['protocollo']) ? ', ' : ', protocollo ') . $h($conv_rinnova['protocollo']) : ''; ?>): controlla le date e carica i nuovi file firmati. La convenzione scaduta resta in archivio.</p><?php endif; ?>
    <?php $cm = $conv_mod ?: []; $cm_doc = json_decode((string)($cm['docenti_json'] ?? ($conv_rinnova['docenti_json'] ?? '')), true) ?: [['nome' => '', 'email' => '']];
          $cm_s = !empty($cm['scuola_codice']) ? scuola_per_codice($conn, $cm['scuola_codice']) : ($conv_nuova_s ?? null); ?>
    <form method="POST" enctype="multipart/form-data" class="row g-2 align-items-end mb-3 border rounded p-2">
        <?php csrf_field(); ?>
        <input type="hidden" name="conv_id" value="<?php echo (int)($cm['id'] ?? 0); ?>">
        <div class="col-lg-6"><label class="form-label small fw-bold mb-1">Scuola *</label><?php echo html_campo_scuola('conv', $cm_s ? etichetta_scuola($cm_s) : '', $cm_s['codice'] ?? '', 'required'); ?></div>
        <div class="col-6 col-lg-3"><label class="form-label small fw-bold mb-1" for="cvStip">Valida dal *</label><input type="date" name="data_stipula" id="cvStip" class="form-control form-control-sm" required value="<?php echo $h($cm['data_stipula'] ?? $conv_def_dal); ?>" onchange="var s=document.getElementById('cvScad'); if (this.value && !s.dataset.toccata) { var d=new Date(this.value); d.setFullYear(d.getFullYear()+<?php echo CONV_DURATA_ANNI; ?>); d.setDate(d.getDate()-1); s.value=d.toISOString().slice(0,10); }"></div>
        <div class="col-6 col-lg-3"><label class="form-label small fw-bold mb-1" for="cvScad">Valida fino al *</label><input type="date" name="scadenza" id="cvScad" class="form-control form-control-sm" required value="<?php echo $h($cm['scadenza'] ?? $conv_def_al); ?>" oninput="this.dataset.toccata='1'"></div>
        <div class="col-md-6">
            <label class="form-label small fw-bold mb-1" for="cvFileC">Convenzione firmata (PDF PAdES)<?php echo empty($cm['file_convenzione']) ? '' : ' – carica solo per sostituirla'; ?></label>
            <input type="file" name="file_convenzione" id="cvFileC" class="form-control form-control-sm" accept=".pdf,application/pdf">
            <?php if (!empty($cm['file_convenzione'])): ?><div class="form-text"><a href="convenzione_file.php?id=<?php echo (int)$cm['id']; ?>&f=conv" target="_blank"><i class="fa fa-file-pdf me-1"></i>File attuale</a></div><?php endif; ?>
        </div>
        <div class="col-md-6">
            <label class="form-label small fw-bold mb-1" for="cvFileA">Allegato A (PDF, anche senza firma PAdES)<?php echo empty($cm['file_allegato']) ? '' : ' – carica solo per sostituirlo'; ?></label>
            <input type="file" name="file_allegato" id="cvFileA" class="form-control form-control-sm" accept=".pdf,application/pdf">
            <?php if (!empty($cm['file_allegato'])): ?><div class="form-text"><a href="convenzione_file.php?id=<?php echo (int)$cm['id']; ?>&f=all" target="_blank"><i class="fa fa-file-pdf me-1"></i>File attuale</a></div><?php endif; ?>
        </div>
        <div class="col-12">
            <label class="form-label small fw-bold mb-1">Docenti di riferimento (Allegato A)</label>
            <div id="cvDocenti">
                <?php foreach ($cm_doc as $d): ?>
                    <div class="d-flex gap-2 mb-1 cv-doc">
                        <input type="text" name="doc_nome[]" class="form-control form-control-sm" placeholder="Nome e cognome" value="<?php echo $h($d['nome'] ?? ''); ?>" maxlength="150" aria-label="Nome e cognome del docente">
                        <input type="email" name="doc_email[]" class="form-control form-control-sm" placeholder="Email" value="<?php echo $h($d['email'] ?? ''); ?>" aria-label="Email del docente">
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="var r=this.closest('.cv-doc'); if (document.querySelectorAll('#cvDocenti .cv-doc').length > 1) r.remove(); else r.querySelectorAll('input').forEach(function(i){i.value='';});" aria-label="Togli il docente">×</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary fw-bold py-0" onclick="var c=document.querySelector('#cvDocenti .cv-doc').cloneNode(true); c.querySelectorAll('input').forEach(function(i){i.value='';}); document.getElementById('cvDocenti').appendChild(c);"><i class="fa fa-plus me-1" aria-hidden="true"></i>Aggiungi docente</button>
        </div>
        <div class="col-6 col-lg-3"><label class="form-label small fw-bold mb-1" for="cvProt">Protocollo</label><input type="text" name="protocollo" id="cvProt" class="form-control form-control-sm" maxlength="100" value="<?php echo $h($cm['protocollo'] ?? ''); ?>" placeholder="es. n. 1234 del 01/10/2026"></div>
        <div class="col-6 col-lg-6"><label class="form-label small fw-bold mb-1" for="cvNote">Note</label><input type="text" name="note" id="cvNote" class="form-control form-control-sm" maxlength="500" value="<?php echo $h($cm['note'] ?? ''); ?>"></div>
        <div class="col-12 col-lg-3 d-flex gap-2">
            <button type="submit" name="conv_salva" value="1" class="btn btn-sm btn-primary fw-bold flex-grow-1"><i class="fa fa-save me-1" aria-hidden="true"></i><?php echo $conv_mod ? 'Salva le modifiche' : 'Registra'; ?></button>
            <?php if ($conv_mod || !empty($conv_nuova_s)): ?><a href="fsl.php?p_id=<?php echo $filtro_p; ?>&tab=convenzioni<?php echo $vista_cv === 'archivio' ? '&vista=archivio' : ''; ?>" class="btn btn-sm btn-outline-secondary fw-bold">Annulla</a><?php endif; ?>
        </div>
    </form>

    <!-- REGISTRO PER SCUOLA: in vigore / archivio delle scadute -->
    <div class="d-flex flex-wrap gap-2 align-items-center mb-2" id="registro">
        <div class="btn-group btn-group-sm" role="group" aria-label="Vista del registro">
            <a href="fsl.php?p_id=<?php echo $filtro_p; ?>&tab=convenzioni#registro" class="btn <?php echo $vista_cv === 'vigore' ? 'btn-success' : 'btn-outline-success'; ?> fw-bold" <?php echo $vista_cv === 'vigore' ? 'aria-current="page"' : ''; ?>><i class="fa fa-file-circle-check me-1" aria-hidden="true"></i>In vigore (<?php echo count($conv_vigore); ?>)</a>
            <a href="fsl.php?p_id=<?php echo $filtro_p; ?>&tab=convenzioni&vista=archivio#registro" class="btn <?php echo $vista_cv === 'archivio' ? 'btn-secondary' : 'btn-outline-secondary'; ?> fw-bold" <?php echo $vista_cv === 'archivio' ? 'aria-current="page"' : ''; ?>><i class="fa fa-box-archive me-1" aria-hidden="true"></i>Archivio scadute (<?php echo count($conv_archivio); ?>)</a>
        </div>
        <?php if ($conv_per_scuola): ?><input type="search" id="cercaConv" class="form-control form-control-sm" style="max-width:320px;" placeholder="Cerca scuola, docente o protocollo" aria-label="Cerca nel registro delle convenzioni"><?php endif; ?>
    </div>
    <p class="small text-secondary mb-2"><?php echo $vista_cv === 'archivio'
        ? "Convenzioni scadute: ci finiscono da sole il giorno dopo la scadenza e restano consultabili con i loro file. <strong>Rinnova</strong> prepara una nuova convenzione per la stessa scuola; l'eliminazione cancella anche i file."
        : "Convenzioni valide, in scadenza (entro 60 giorni) o non ancora valide. Il numero sulla scheda conta le scuole."; ?></p>
    <?php if ($conv_per_scuola): ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle small" id="tabConv">
                <thead class="table-light"><tr><th>Scuola</th><th>Validità</th><th>Docenti di riferimento</th><th>File</th><th>Protocollo e note</th><th></th></tr></thead>
                <?php foreach ($conv_per_scuola as $cod => $lista): $s_c = scuola_per_codice($conn, $cod); ?>
                    <tbody class="conv-scuola border-top">
                    <?php foreach ($lista as $i => $c):
                        $dal_c = $c['data_stipula']; $al_c = $c['scadenza'];
                        [$bg_c, $fg_c, $lbl_c] = ($al_c !== null && $al_c < $oggi_cv) ? ['#fee2e2', '#991b1b', 'Scaduta']
                            : (($dal_c !== null && $dal_c > $oggi_cv) ? ['#e0f2fe', '#0c4a6e', 'Non ancora valida']
                            : (($al_c !== null && $al_c <= $tra60_cv) ? ['#fef3c7', '#92400e', 'In scadenza'] : ['#f0fdf4', '#166534', 'Valida']));
                        $docs = json_decode((string)($c['docenti_json'] ?? ''), true) ?: []; ?>
                        <tr>
                            <?php if ($i === 0): ?><td rowspan="<?php echo count($lista); ?>" class="align-top"><div class="fw-semibold"><?php echo $h($s_c ? etichetta_scuola($s_c) : $cod); ?></div><div class="text-secondary font-monospace"><?php echo $h($cod); ?></div><div class="text-secondary"><?php echo (int)$c['n_iscr']; ?> iscrizioni</div></td><?php endif; ?>
                            <td class="text-nowrap"><?php echo $h(testo_validita_convenzione($c)); ?> <span class="badge" style="background:<?php echo $bg_c; ?>;color:<?php echo $fg_c; ?>;"><?php echo $lbl_c; ?></span></td>
                            <td><?php foreach ($docs as $d): ?><div><?php echo $h($d['nome']); ?><?php if (!empty($d['email'])): ?> · <a href="mailto:<?php echo $h($d['email']); ?>"><?php echo $h($d['email']); ?></a><?php endif; ?></div><?php endforeach; ?><?php if (!$docs): ?><span class="text-secondary">—</span><?php endif; ?></td>
                            <td class="text-nowrap">
                                <?php if (!empty($c['file_convenzione'])): ?><a href="convenzione_file.php?id=<?php echo (int)$c['id']; ?>&f=conv" target="_blank" class="me-2"><i class="fa fa-file-pdf me-1"></i>Convenzione</a><?php else: ?><span class="badge bg-light text-secondary border me-1">convenzione mancante</span><?php endif; ?>
                                <?php if (!empty($c['file_allegato'])): ?><a href="convenzione_file.php?id=<?php echo (int)$c['id']; ?>&f=all" target="_blank"><i class="fa fa-file-pdf me-1"></i>Allegato A</a><?php else: ?><span class="badge bg-light text-secondary border">Allegato A mancante</span><?php endif; ?>
                            </td>
                            <td><?php echo $h($c['protocollo']); ?><?php if ($c['note'] !== ''): ?><div class="text-secondary"><?php echo $h($c['note']); ?></div><?php endif; ?></td>
                            <td class="text-nowrap">
                                <?php if ($vista_cv === 'archivio' && $i === 0 && empty($conv_vigore[$cod])): ?>
                                    <a href="fsl.php?p_id=<?php echo $filtro_p; ?>&tab=convenzioni&vista=archivio&conv_rinnova=<?php echo (int)$c['id']; ?>#convForm" class="btn btn-sm btn-success fw-bold py-0" title="Rinnova: nuova convenzione per questa scuola"><i class="fa fa-rotate me-1" aria-hidden="true"></i>Rinnova</a>
                                <?php elseif ($vista_cv === 'archivio' && $i === 0): ?>
                                    <span class="badge bg-light text-success border" title="La scuola ha già una convenzione in vigore">rinnovata</span>
                                <?php endif; ?>
                                <a href="fsl.php?p_id=<?php echo $filtro_p; ?>&tab=convenzioni<?php echo $vista_cv === 'archivio' ? '&vista=archivio' : ''; ?>&conv_mod=<?php echo (int)$c['id']; ?>#convForm" class="btn btn-sm btn-outline-primary py-0" title="Modifica" aria-label="Modifica la convenzione"><i class="fa fa-pen"></i></a>
                                <form method="POST" class="d-inline">
                                    <?php csrf_field(); ?>
                                    <button type="submit" name="conv_elimina" value="<?php echo (int)$c['id']; ?>" class="btn btn-sm btn-outline-danger py-0" data-confirm="Eliminare questa convenzione dal registro, con i suoi file?" title="Elimina" aria-label="Elimina la convenzione"><i class="fa fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                <?php endforeach; ?>
            </table>
        </div>
        <script>
        document.getElementById('cercaConv').addEventListener('input', function () {
            var q = this.value.toLowerCase().trim();
            document.querySelectorAll('#tabConv tbody.conv-scuola').forEach(function (tb) { tb.style.display = !q || tb.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none'; });
        });
        </script>
    <?php else: ?>
        <p class="text-muted small mb-0"><?php echo $vista_cv === 'archivio' ? 'Nessuna convenzione scaduta.' : 'Nessuna convenzione in vigore.'; ?></p>
    <?php endif; ?>
</section>

<?php elseif ($tab === 'stipulare'): ?>
<?php
$mostra_st = ($_GET['mostra'] ?? '') === 'tutte' ? 'tutte' : 'da_stipulare';
$elenco_st = $mostra_st === 'tutte' ? $scuole_fsl : array_values(array_filter($scuole_fsl, fn($g) => $g['da_stipulare']));
$n_pren_st = array_sum(array_map(fn($g) => $g['n_da_stipulare'], $scuole_fsl));
$stati_pren_st = ['confermata' => ['Confermata', '#166534', '#f0fdf4'], 'da_approvare' => ['In attesa', '#9a3412', '#fff7ed'], 'in_attesa' => ["Lista d'attesa", '#854d0e', '#fefce8'], 'richiesta_conferma' => ['Posto offerto', '#1e40af', '#eff6ff']];
$stati_conv_st = ['da_stipulare' => ['Da stipulare', '#991b1b', '#fee2e2'], 'coperta' => ['Convenzione valida', '#166534', '#f0fdf4'], 'ricevuta' => ['Convenzione ricevuta', '#166534', '#f0fdf4'], 'dichiarata' => ['Dichiarata dalla scuola', '#475569', '#f1f5f9']];
?>
<style>
.st-card { background:#fff; border:1px solid #e2e8f0; border-left:6px solid #16a34a; border-radius:12px; margin-bottom:1rem; box-shadow:0 1px 4px rgba(0,0,0,.05); }
.st-card.da-stipulare { border-left-color:#dc2626; }
.st-card .st-testa { padding:1rem 1.25rem .5rem; }
.st-card .st-nome { font-size:1.15rem; font-weight:800; color:#0f172a; line-height:1.25; }
.st-card .st-corpo { padding:0 1.25rem; }
.st-card .st-piede { padding:.85rem 1.25rem; background:#f8fafc; border-top:1px solid #e2e8f0; border-radius:0 0 12px 12px; }
.st-chip { display:inline-block; font-size:.72rem; font-weight:700; padding:.15rem .55rem; border-radius:999px; white-space:nowrap; }
</style>
<section class="scu-sez">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <h3 class="h5 fw-bold mb-0"><i class="fa fa-file-circle-exclamation me-1 text-danger" aria-hidden="true"></i>Convenzioni da stipulare</h3>
        <div class="btn-group ms-auto" role="group" aria-label="Quali scuole mostrare">
            <a href="fsl.php?p_id=<?php echo (int)$filtro_p; ?>&tab=stipulare" class="btn btn-sm <?php echo $mostra_st === 'da_stipulare' ? 'btn-danger' : 'btn-outline-danger'; ?> fw-bold">Da stipulare (<?php echo $n_scuole_da_stipulare; ?>)</a>
            <a href="fsl.php?p_id=<?php echo (int)$filtro_p; ?>&tab=stipulare&mostra=tutte" class="btn btn-sm <?php echo $mostra_st === 'tutte' ? 'btn-dark' : 'btn-outline-dark'; ?> fw-bold">Tutte le scuole con prenotazioni (<?php echo count($scuole_fsl); ?>)</a>
        </div>
    </div>
    <p class="small text-secondary mb-3">Scuole con prenotazioni in corso alle attività FSL. Per ognuna scarichi già <strong>precompilati</strong> l'<strong>Allegato A</strong> (PDF con la scheda completa di ogni attività, o Word) e la <strong>Convenzione</strong> (Word), con i dati dell'anagrafe e delle prenotazioni. Vale anche per le prenotazioni <strong>già confermate</strong> e anche se la scuola non ha usato il modulo online. Il <strong>logo</strong> della scuola non è incluso, a meno che la scuola lo abbia caricato: lo aggiungi tu al documento. Restano da completare a mano i dati del Dirigente non ancora indicati.</p>
    <div class="row g-2 mb-3 text-center">
        <div class="col-4"><div class="fsl-sez py-2 mb-0"><div class="fsl-num <?php echo $n_scuole_da_stipulare ? 'text-danger' : 'text-success'; ?>"><?php echo $n_scuole_da_stipulare; ?></div><div class="small text-secondary"><?php echo $n_scuole_da_stipulare === 1 ? 'scuola con convenzione da stipulare' : 'scuole con convenzione da stipulare'; ?></div></div></div>
        <div class="col-4"><div class="fsl-sez py-2 mb-0"><div class="fsl-num <?php echo $n_pren_st ? 'text-danger' : ''; ?>"><?php echo $n_pren_st; ?></div><div class="small text-secondary"><?php echo $n_pren_st === 1 ? 'prenotazione in attesa di convenzione' : 'prenotazioni in attesa di convenzione'; ?></div></div></div>
        <div class="col-4"><div class="fsl-sez py-2 mb-0"><div class="fsl-num"><?php echo count($scuole_fsl) - $n_scuole_da_stipulare; ?></div><div class="small text-secondary"><?php echo count($scuole_fsl) - $n_scuole_da_stipulare === 1 ? 'scuola già a posto' : 'scuole già a posto'; ?></div></div></div>
    </div>

    <?php if (!$elenco_st): ?>
        <div class="alert alert-success mb-0"><i class="fa fa-circle-check me-1" aria-hidden="true"></i><?php echo $mostra_st === 'tutte' ? 'Nessuna scuola ha prenotazioni in corso alle attività FSL.' : 'Nessuna convenzione da stipulare: tutte le scuole con prenotazioni in corso sono a posto.'; ?></div>
    <?php endif; ?>

    <?php foreach ($elenco_st as $g): $k_g = urlencode($g['chiave']); $url_doc = fn($tipo) => 'allegato_a.php?k=' . $k_g . '&doc=' . $tipo; ?>
    <article class="st-card <?php echo $g['da_stipulare'] ? 'da-stipulare' : ''; ?>">
        <div class="st-testa d-flex flex-wrap align-items-start gap-2">
            <div class="flex-grow-1">
                <div class="st-nome"><?php echo $h($g['nome']); ?></div>
                <div class="small text-secondary"><?php echo $h($g['comune']); ?><?php echo $g['codice'] !== '' ? ($g['comune'] !== '' ? ' · ' : '') . '<span class="font-monospace">' . $h($g['codice']) . '</span>' : ' · scuola scritta a mano nel modulo: abbinala all\'anagrafe per verificarne la convenzione'; ?> · <?php echo count($g['prenotazioni']); ?> <?php echo count($g['prenotazioni']) === 1 ? 'prenotazione' : 'prenotazioni'; ?><?php echo $g['n_studenti'] ? ' · ' . (int)$g['n_studenti'] . ' studenti' : ''; ?></div>
            </div>
            <?php if ($g['da_stipulare']): ?>
                <span class="st-chip" style="background:#fee2e2;color:#991b1b;font-size:.85rem;"><i class="fa fa-file-circle-exclamation me-1" aria-hidden="true"></i>Convenzione da stipulare (<?php echo (int)$g['n_da_stipulare']; ?>)</span>
            <?php else: ?>
                <span class="st-chip" style="background:#dcfce7;color:#166534;font-size:.85rem;"><i class="fa fa-circle-check me-1" aria-hidden="true"></i>Convenzione a posto<?php echo $g['validita'] ? ' fino al ' . $d($g['validita']) : ''; ?></span>
            <?php endif; ?>
        </div>
        <div class="st-corpo">
            <div class="table-responsive">
                <table class="table table-sm align-middle small mb-2">
                    <thead class="table-light"><tr><th>Attività</th><th>Periodo</th><th class="text-center">Studenti</th><th>Docente</th><th>Prenotazione</th><th>Convenzione</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($g['prenotazioni'] as $p_g): [$lp, $cp, $bp] = $stati_pren_st[$p_g['stato']] ?? [$p_g['stato'], '#475569', '#f1f5f9']; [$lc, $cc_, $bc] = $stati_conv_st[$p_g['conv']]; ?>
                        <tr>
                            <td><strong><?php echo $h($p_g['titolo']); ?></strong><div class="text-secondary"><?php echo $h($p_g['area']); ?><?php echo $p_g['turno'] !== '' ? ' · ' . $h($p_g['turno']) : ''; ?> · <span class="font-monospace"><?php echo $h($p_g['codice']); ?></span></div></td>
                            <td class="text-nowrap"><?php echo $h($p_g['periodo']); ?></td>
                            <td class="text-center"><?php echo $p_g['studenti'] ?: '—'; ?></td>
                            <td><?php echo $h($p_g['docente']); ?><div class="text-secondary"><?php echo $h($p_g['email']); ?></div></td>
                            <td><span class="st-chip" style="background:<?php echo $bp; ?>;color:<?php echo $cp; ?>;"><?php echo $h($lp); ?></span></td>
                            <td><span class="st-chip" style="background:<?php echo $bc; ?>;color:<?php echo $cc_; ?>;"><?php echo $h($lc); ?></span></td>
                            <td class="text-nowrap"><a href="iscritti.php?p_id=<?php echo (int)$p_g['pagina_id']; ?>&f_cerca=<?php echo urlencode($p_g['codice']); ?>" class="btn btn-sm btn-outline-secondary fw-bold py-0">Iscrizioni</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="st-piede d-flex flex-wrap align-items-center gap-2">
            <a href="<?php echo $h($url_doc('allegato_pdf')); ?>" class="btn btn-danger fw-bold"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>Allegato A precompilato (PDF)</a>
            <a href="<?php echo $h($url_doc('allegato')); ?>" class="btn btn-outline-primary fw-bold"><i class="fa fa-file-word me-1" aria-hidden="true"></i>Allegato A (Word)</a>
            <a href="<?php echo $h($url_doc('convenzione')); ?>" class="btn btn-outline-primary fw-bold"><i class="fa fa-file-word me-1" aria-hidden="true"></i>Convenzione (Word)</a>
            <?php if ($g['codice'] !== '' && preg_match('/^[A-Z0-9]{10}$/', $g['codice'])): ?>
                <a href="fsl.php?p_id=<?php echo (int)$filtro_p; ?>&tab=convenzioni&conv_nuova=<?php echo urlencode($g['codice']); ?>&dal=<?php echo $h($g['dal']); ?>&al=<?php echo $h($g['al']); ?>#convForm" class="btn btn-success fw-bold ms-auto"><i class="fa fa-file-signature me-1" aria-hidden="true"></i>Registra la convenzione ricevuta</a>
            <?php endif; ?>
        </div>
    </article>
    <?php endforeach; ?>
</section>

<?php elseif ($tab === 'verifica'): ?>
<section class="scu-sez">
    <!-- VERIFICA SULLE ISCRIZIONI DELLE ATTIVITÀ FSL (anche già confermate) -->
    <div id="verifica">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <h3 class="h6 fw-bold mb-0"><i class="fa fa-list-check me-1" aria-hidden="true"></i>Verifica delle iscrizioni alle attività FSL</h3>
            <form method="POST" class="ms-auto m-0">
                <?php csrf_field(); ?>
                <button type="submit" name="conv_verifica" value="1" class="btn btn-sm btn-outline-dark fw-bold" data-attesa="Verifica in corso…"><i class="fa fa-rotate me-1" aria-hidden="true"></i>Verifica ora</button>
            </form>
        </div>
        <p class="small text-secondary mb-2">Controlla tutte le iscrizioni (anche confermate) alle attività FSL non ancora concluse, per OpenLab, Formazione Scuola Lavoro e ogni altra area: dove la convenzione copre il periodo l'iscrizione risulta <strong>Convenzione ricevuta</strong>; dove non lo copre diventa <strong>Convenzione da stipulare</strong>, senza cambiare lo stato della prenotazione e senza email automatiche. La richiesta alla scuola la invii da Iscrizioni ("Chiedi la convenzione per email"); da lì partono anche i promemoria. La verifica si ripete ogni giorno da sola.</p>
        <?php if ($iscr_ora_coperte): ?>
            <div class="alert alert-info small py-2"><i class="fa fa-circle-info me-1" aria-hidden="true"></i><?php echo $iscr_ora_coperte === 1 ? "Un'iscrizione segnata «da stipulare» ora è coperta" : "$iscr_ora_coperte iscrizioni segnate «da stipulare» ora sono coperte"; ?> da una convenzione del registro: premi <strong>Verifica ora</strong> per segnarle come ricevute (chi era in attesa viene confermato e avvisato per email).</div>
        <?php endif; ?>
        <?php if ($iscr_da_stipulare): ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle small mb-0">
                    <thead class="table-light"><tr><th>Scuola</th><th>Attività</th><th>Periodo</th><th>Convenzione in registro</th><th>Docente</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($iscr_da_stipulare as $x): $s_x = !empty($x['scuola_codice']) ? scuola_per_codice($conn, $x['scuola_codice']) : null;
                        [$pdal, $pal] = periodo_prenotazione($x); $ult = $s_x ? (convenzioni_della_scuola($conn, $x['scuola_codice'])[0] ?? null) : null; ?>
                        <tr>
                            <td><?php echo $h($s_x ? etichetta_scuola($s_x) : (nome_scuola_prenotazione($x) ?: '—')); ?><div class="text-secondary <?php echo $s_x ? 'font-monospace' : ''; ?>"><?php echo $s_x ? $h($x['scuola_codice']) : 'scritta a mano: abbinala all\'anagrafe'; ?></div></td>
                            <td><?php echo $h($x['titolo']); ?><div class="text-secondary"><?php echo $h($x['area']); ?> · <span class="font-monospace"><?php echo $h($x['codice_prenotazione']); ?></span> · <?php echo $h($x['stato'] ?: 'confermata'); ?></div></td>
                            <td class="text-nowrap"><?php echo date('d/m/Y', strtotime($pdal)) . ($pal !== $pdal ? ' – ' . date('d/m/Y', strtotime($pal)) : ''); ?></td>
                            <td><?php if ($ult): ?><span class="badge" style="background:#fee2e2;color:#991b1b;">non copre il periodo</span><div class="text-secondary"><?php echo $h(testo_validita_convenzione($ult)); ?></div><?php elseif ($s_x): ?><span class="badge bg-light text-dark border">nessuna</span><?php else: ?>—<?php endif; ?></td>
                            <td><?php echo $h(trim($x['nome'] . ' ' . $x['cognome'])); ?><div class="text-secondary"><?php echo $h($x['email']); ?></div></td>
                            <td class="text-nowrap"><a href="iscritti.php?p_id=<?php echo (int)$x['pagina_id']; ?>&f_cerca=<?php echo urlencode($x['codice_prenotazione']); ?>" class="btn btn-sm btn-outline-secondary fw-bold py-0">Iscrizioni</a>
                                <?php if ($s_x): ?><a href="fsl.php?p_id=<?php echo $filtro_p; ?>&tab=convenzioni&conv_nuova=<?php echo urlencode($x['scuola_codice']); ?>&dal=<?php echo $pdal; ?>&al=<?php echo $pal; ?>#convForm" class="btn btn-sm btn-success fw-bold py-0">Registra</a><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="small text-success mb-0"><i class="fa fa-circle-check me-1" aria-hidden="true"></i>Nessuna iscrizione alle attività FSL in corso senza convenzione.</p>
        <?php endif; ?>
    </div>
</section>

<?php else: ?>
<p class="text-secondary small">Tutte le attività con <strong>Attività di Formazione Scuola Lavoro</strong> attivo, di ogni area, che iniziano tra il <?php echo $d($dal_a); ?> e il <?php echo $d($al_a); ?>. Studenti = numero dichiarato nelle iscrizioni confermate. Ogni tabella si scarica in CSV (separatore ;, per Excel).</p>

<section class="fsl-sez" id="valutazioni">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
        <h2 class="mb-0"><i class="fa fa-star me-1" aria-hidden="true"></i>Schede di valutazione della struttura ospitante (<?php echo count($valutazioni); ?>)</h2>
        <?php if ($valutazioni): ?><button type="button" class="btn btn-sm btn-outline-secondary fw-bold fsl-csv" data-tab="tabVal" data-nome="fsl_valutazioni_<?php echo $anno; ?>.csv"><i class="fa fa-file-csv me-1" aria-hidden="true"></i>CSV</button><?php endif; ?>
    </div>
    <p class="small text-secondary">A fine attività (presenza registrata) il docente riceve per email il link alla scheda, con un promemoria dopo 7 giorni. Voti da 1 a 5.</p>
    <?php if (!$valutazioni): ?><p class="text-muted small mb-0">Nessuna scheda compilata in questo anno scolastico.</p><?php else: ?>
    <div class="row g-4 mb-3">
        <div class="col-lg-7">
            <table class="table table-sm small mb-0">
                <?php foreach (VALUTAZIONE_FSL_ASPETTI as $k => $etichetta): $m_k = !empty($somme[$k]) ? array_sum($somme[$k]) / count($somme[$k]) : 0; ?>
                    <tr><td><?php echo $h($etichetta); ?></td><td style="width:40%;"><div class="fsl-barra" role="img" aria-label="Media <?php echo number_format($m_k, 1, ',', ''); ?> su 5"><span style="width:<?php echo round($m_k / 5 * 100); ?>%"></span></div></td><td class="fw-bold text-end"><?php echo number_format($m_k, 1, ',', ''); ?></td></tr>
                <?php endforeach; ?>
            </table>
        </div>
        <div class="col-lg-5">
            <div class="small fw-bold mb-1">Riproporrebbero l'attività</div>
            <?php foreach (['si' => 'Sì', 'forse' => 'Forse', 'no' => 'No'] as $k => $t): ?><div class="small"><?php echo $t; ?>: <strong><?php echo $rip[$k]; ?></strong></div><?php endforeach; ?>
        </div>
    </div>
    <div class="table-responsive"><table class="table table-sm align-top small" id="tabVal">
        <thead class="table-light"><tr><th>Data</th><th>Attività</th><th>Scuola</th><th>Compilata da</th><th class="text-center">Media</th><th>Riproporrebbe</th>
            <?php foreach (VALUTAZIONE_FSL_ASPETTI as $etichetta): ?><th class="d-none"><?php echo $h($etichetta); ?></th><?php endforeach; ?>
            <?php foreach (VALUTAZIONE_FSL_APERTE as $etichetta): ?><th><?php echo $h($etichetta); ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($valutazioni as $v): $s_v = !empty($v['scuola_codice']) ? scuola_per_codice($conn, $v['scuola_codice']) : null; ?>
            <tr>
                <td class="text-nowrap"><?php echo $d($v['created_at']); ?></td><td><?php echo $h($v['titolo']); ?></td>
                <td><?php echo $h($s_v ? etichetta_scuola($s_v) : '—'); ?></td><td><?php echo $h($v['compilata_da']); ?></td>
                <td class="text-center fw-bold"><?php echo number_format((float)$v['media'], 1, ',', ''); ?></td><td><?php echo $h(['si' => 'Sì', 'forse' => 'Forse', 'no' => 'No'][$v['ripeterebbe']] ?? ''); ?></td>
                <?php foreach (array_keys(VALUTAZIONE_FSL_ASPETTI) as $k): ?><td class="d-none"><?php echo (int)($v['risposte']['voti'][$k] ?? 0); ?></td><?php endforeach; ?>
                <?php foreach (array_keys(VALUTAZIONE_FSL_APERTE) as $k): ?><td style="max-width:260px;"><?php echo nl2br($h($v['risposte']['testi'][$k] ?? '')); ?></td><?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</section>
<?php endif; ?>

<script>
// CSV di una tabella (anche le colonne nascoste, separatore ; per Excel in italiano)
document.querySelectorAll('.fsl-csv').forEach(function (b) {
    b.addEventListener('click', function () {
        var tab = document.getElementById(b.dataset.tab), righe = [];
        tab.querySelectorAll('tr').forEach(function (tr) { righe.push(Array.prototype.map.call(tr.children, function (c) { return c.textContent.replace(/\s+/g, ' ').trim(); })); });
        var csv = '﻿' + righe.map(function (r) { return r.map(function (v) { return '"' + v.replace(/"/g, '""') + '"'; }).join(';'); }).join('\r\n');
        var a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
        a.download = b.dataset.nome; document.body.appendChild(a); a.click(); a.remove();
    });
});
</script>
<?php require_once 'admin_footer.php'; ?>
