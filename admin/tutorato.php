<?php
// tutorato.php - Modulo Didattica › Tutorato: bandi e lettere di incarico dei vincitori.
// L'operatore inserisce il bando (decreto del bando e della commissione, direttore dall'anagrafe, operatore che protocolla)
// e per ogni vincitore dati, attività, ore, periodo, compenso e docente responsabile; scarica il Word precompilato,
// vede l'anteprima del PDF e invia la lettera allo studente. Poi: conferma dello studente con SPID/CIE (incarico.php),
// firme PAdES del docente e del direttore (firma_incarico.php), protocollo. Dopo la firma: registro delle attività del tutor
// (registro_tutorato.php), dichiarazione di fine attività precompilata e firmata dal docente, protocollo della fine.
// Logica in inc/tutorato.php e inc/tutorato_registro.php.
require_once 'admin_header.php';

if (!$puo_tutorato) nega_accesso();
function admin_redirect($url) { echo "<script>window.location.replace(" . json_encode($url) . ");</script>"; exit; }
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$base = "tutorato.php?p_id=" . (int)$filtro_p;
$autore = trim(($utente_admin['nome'] ?? '') . ' ' . ($utente_admin['cognome'] ?? ''));

// ==============================================================================
// FILE: anteprima del PDF, PDF corrente (con le firme), Word precompilato
// ==============================================================================
if (isset($_GET['file'], $_GET['incarico'])) {
    $i = incarico_tutorato($conn, (int)$_GET['incarico']);
    if (!$i) nega_accesso();
    while (ob_get_level() > 0) ob_end_clean();
    if ($_GET['file'] === 'word' && ($tmp = docx_lettera_incarico($i))) {
        invia_file_scaricabile($tmp, str_replace('.pdf', '.docx', nome_file_incarico($i)), 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }
    if ($_GET['file'] === 'pdf' && ($f = pdf_corrente_incarico($i))) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . (isset($_GET['scarica']) ? 'attachment' : 'inline') . '; filename="' . nome_file_incarico($i, in_array($i['stato'], ['firmata', 'protocollata'], true) ? 'firmata' : '') . '"');
        header('Content-Length: ' . filesize($f)); header('X-Content-Type-Options: nosniff');
        readfile($f); exit;
    }
    if ($_GET['file'] === 'fine' && ($f = pdf_fine_corrente($i))) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . (isset($_GET['scarica']) ? 'attachment' : 'inline') . '; filename="FINE_ATTIVITA_' . str_replace('LETTERA_INCARICO_', '', nome_file_incarico($i)) . '"');
        header('Content-Length: ' . filesize($f)); header('X-Content-Type-Options: nosniff');
        readfile($f); exit;
    }
    if ($_GET['file'] === 'fine_anteprima') {
        // Anteprima della dichiarazione di fine attività con i dati attuali (ore approvate finora)
        $reg = registro_incarico($conn, (int)$i['id']);
        [$pdf] = pdf_fine_attivita($i, $reg, $i['ore_approvate'] !== null ? (float)$i['ore_approvate'] : (ore_registro($reg)['approvata'] ?: null));
        header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="anteprima_fine_attivita.pdf"');
        header('X-Content-Type-Options: nosniff');
        echo $pdf; exit;
    }
    if ($_GET['file'] === 'anteprima') {
        [$pdf] = pdf_lettera_incarico($i, json_decode((string)$i['studente_firma_json'], true) ?: null);
        header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="anteprima_' . nome_file_incarico($i) . '"');
        header('X-Content-Type-Options: nosniff');
        echo $pdf; exit;
    }
    http_response_code(404); exit('File non disponibile.');
}

// ==============================================================================
// AZIONI
// ==============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    // Decreti in PDF, estremi del decreto di approvazione atti e ufficio di Ateneo che paga i tutor del bando
    if (isset($_POST['salva_decreti'])) {
        $bid = (int)($_POST['bando_id'] ?? 0);
        $fsv = \App\Core\App::per($conn)->get(\App\Tutorato\FileTutorato::class);
        $bsv = \App\Core\App::per($conn)->get(\App\Tutorato\BandoRepository::class);
        $errd = [];
        if (!$bsv->perId($bid)) { flash_set('Bando non trovato.', 'danger'); admin_redirect($base); }
        foreach (['bando' => 'del bando', 'commissione' => 'della commissione', 'atti' => 'di approvazione atti'] as $t => $nome_t) {
            $fd = $_FILES['decreto_' . $t] ?? null;
            if ($fd && (int)($fd['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                [$ee, $pdf_d] = $fsv->leggiUpload($fd);
                $ee ??= $fsv->salvaDecreto($bid, $t, (string)$pdf_d);
                if ($ee) $errd[] = "Decreto $nome_t: $ee";
            }
        }
        $dt_a = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['decreto_atti_data'] ?? '')) ? $_POST['decreto_atti_data'] : null;
        $bsv->impostaDecretoAtti($bid, mb_substr(trim((string)($_POST['decreto_atti'] ?? '')), 0, 100), $dt_a);
        $bsv->impostaUfficio($bid, (int)($_POST['ufficio_ateneo_id'] ?? 0) ?: null);
        registra_log_audit($conn, "Tutorato: decreti e ufficio pagamenti del bando", ["Bando" => $bid]);
        flash_set($errd ? implode(' ', $errd) : "Decreti e ufficio pagamenti salvati.", $errd ? 'danger' : 'success');
        admin_redirect("$base&bando=$bid");
    }
    // UNILAV del tutor (si carica dopo il protocollo della lettera)
    if (isset($_POST['carica_unilav'])) {
        $iid_u = (int)($_POST['incarico_id'] ?? 0);
        $fsv = \App\Core\App::per($conn)->get(\App\Tutorato\FileTutorato::class);
        [$ee, $pdf_u] = $fsv->leggiUpload($_FILES['unilav_pdf'] ?? null);
        $ee ??= $fsv->salvaUnilav($iid_u, (string)$pdf_u);
        if (!$ee) registra_log_audit($conn, "Tutorato: UNILAV caricato", ["Lettera" => $iid_u]);
        flash_set($ee ?? "UNILAV caricato.", $ee ? 'danger' : 'success');
        admin_redirect("$base&incarico=$iid_u#pagamento");
    }
    // Il referente valida e passa le pratiche all'ufficio pagamenti (una, alcune o tutte)
    if (isset($_POST['passa_pagamenti'])) {
        $es = \App\Core\App::per($conn)->get(\App\Tutorato\ServizioPagamenti::class)->passa(array_map('intval', (array)($_POST['ids'] ?? [])), $autore);
        if ($es['passate']) registra_log_audit($conn, "Tutorato: pratiche passate all'ufficio pagamenti", ["Pratiche" => $es['passate']]);
        $msg = $es['passate'] ? $es['passate'] . ($es['passate'] === 1 ? " pratica passata" : " pratiche passate") . " all'ufficio pagamenti: ha ricevuto una notifica." : '';
        if ($es['errori']) {
            $nomi = [];
            foreach ($es['errori'] as $eid => $em) { $x = incarico_tutorato($conn, (int)$eid); $nomi[] = ($x ? trim($x['cognome'] . ' ' . $x['nome']) : "n. $eid") . ': ' . $em; }
            $msg .= ($msg !== '' ? ' ' : '') . 'Non passate: ' . implode(' · ', $nomi) . '.';
        }
        flash_set($msg !== '' ? $msg : 'Seleziona almeno una pratica.', $es['errori'] ? ($es['passate'] ? 'warning' : 'danger') : ($es['passate'] ? 'success' : 'warning'));
        admin_redirect("$base&vista=validare");
    }
    // Il referente rimanda la fine attività al docente con il motivo
    if (isset($_POST['rimanda_docente'])) {
        $err = \App\Core\App::per($conn)->get(\App\Tutorato\ServizioPagamenti::class)->rimandaAlDocente((int)($_POST['incarico_id'] ?? 0), (string)($_POST['motivo'] ?? ''), $autore);
        if (!$err) registra_log_audit($conn, "Tutorato: fine attività rimandata al docente", ["Lettera" => (int)($_POST['incarico_id'] ?? 0)]);
        flash_set($err ?? "Rimandata al docente: ha ricevuto l'email con il motivo.", $err ? 'danger' : 'warning');
        admin_redirect("$base&vista=validare");
    }
    // Import dei vincitori dal Word del decreto di approvazione atti: anteprima (resta nella pagina) e conferma
    if (isset($_POST['anteprima_graduatoria'])) {
        $bid_g = (int)($_POST['bando_id'] ?? 0);
        [$eg, $docx_g] = \App\Core\App::per($conn)->get(\App\Tutorato\FileTutorato::class)->leggiUpload($_FILES['graduatoria'] ?? null);
        $anteprima = $eg === null ? \App\Core\App::per($conn)->get(\App\Tutorato\ServizioImportBando::class)->anteprima($bid_g, (string)$docx_g) : ['errore' => str_replace('PDF', 'Word', $eg), 'righe' => [], 'decreti' => []];
        $_GET['bando'] = $bid_g; $_GET['importa'] = 1;
    } elseif (isset($_POST['importa_graduatoria'])) {
        $bid_g = (int)($_POST['bando_id'] ?? 0);
        $es_g = \App\Core\App::per($conn)->get(\App\Tutorato\ServizioImportBando::class)->importa($bid_g, (array)($_POST['r'] ?? []));
        if ($es_g['creati']) registra_log_audit($conn, "Tutorato: vincitori importati dalla graduatoria", ["Bando" => $bid_g, "Lettere" => $es_g['creati']]);
        $msg_g = $es_g['creati'] . ($es_g['creati'] === 1 ? ' lettera creata' : ' lettere create') . ' in bozza' . ($es_g['saltati'] ? ", {$es_g['saltati']} già presenti saltate" : '') . '.'
               . ($es_g['creati'] ? ' Completa codice fiscale ed email dei vincitori (le lettere con «dati da completare» non si possono ancora inviare).' : '')
               . ($es_g['errori'] ? ' ' . implode(' ', $es_g['errori']) : '');
        flash_set($msg_g, $es_g['errori'] ? ($es_g['creati'] ? 'warning' : 'danger') : 'success');
        admin_redirect("$base&bando=$bid_g");
    }
    if (isset($_POST['salva_bando'])) {
        [$bid, $err] = salva_bando_tutorato($conn, $_POST + ['id' => (int)($_POST['bando_id'] ?? 0)], (int)$u_id_curr);
        if (!$err) registra_log_audit($conn, "Tutorato: bando salvato", ["Bando" => $_POST['titolo'] ?? '']);
        flash_set($err ?? "Bando salvato: ora aggiungi i vincitori.", $err ? 'danger' : 'success');
        admin_redirect($err ? "$base&" . ((int)($_POST['bando_id'] ?? 0) ? 'bando=' . (int)$_POST['bando_id'] . '&modifica=1' : 'nuovo_bando=1') : "$base&bando=$bid");
    }
    if (isset($_POST['salva_incarico'])) {
        [$iid, $err] = salva_incarico_tutorato($conn, $_POST + ['id' => (int)($_POST['incarico_id'] ?? 0)]);
        if (!$err) registra_log_audit($conn, "Tutorato: lettera di incarico salvata", ["Vincitore" => trim(($_POST['cognome'] ?? '') . ' ' . ($_POST['nome'] ?? ''))]);
        if (!$err) salva_dati_fine_attivita($conn, (int)$iid, $_POST);
        if ($err) { $_SESSION['incarico_post'] = $_POST; flash_set($err, 'danger'); admin_redirect("$base&" . ($iid ? "incarico=$iid" : "nuovo_incarico=1&bando=" . (int)($_POST['bando_id'] ?? 0))); }
        flash_set("Lettera salvata: controlla l'anteprima e inviala allo studente.");
        admin_redirect("$base&incarico=$iid");
    }
    $iid = (int)($_POST['incarico_id'] ?? 0);
    if (isset($_POST['invia_studente'])) {
        $err = invia_incarico_studente($conn, $iid, $autore);
        if (!$err) registra_log_audit($conn, "Tutorato: lettera inviata allo studente", ["Lettera" => $iid]);
        flash_set($err ?? "Lettera inviata: lo studente riceve l'email con il link per controllare e confermare con SPID/CIE.", $err ? 'danger' : 'success');
        admin_redirect("$base&incarico=$iid");
    }
    if (isset($_POST['carica_firmato'])) {
        // L'operatore carica il PDF firmato ricevuto dal docente o dal direttore (es. firmato fuori dal portale)
        $i = incarico_tutorato($conn, $iid);
        $ruolo = $i && $i['stato'] === 'confermata' ? 'docente' : 'direttore';
        $f = $_FILES['pdf_firmato'] ?? null;
        $err = (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || $f['size'] > 20 * 1024 * 1024) ? "Scegli il PDF firmato (fino a 20 MB)." : registra_firma_incarico($conn, $iid, $ruolo, (string)file_get_contents($f['tmp_name']), 'caricata dall\'Ufficio: ' . $autore);
        if (!$err) registra_log_audit($conn, "Tutorato: firma caricata dall'ufficio", ["Lettera" => $iid, "Firma" => $ruolo]);
        flash_set($err ?? "Firma del " . $ruolo . " registrata: la lettera è passata al passo successivo.", $err ? 'danger' : 'success');
        admin_redirect("$base&incarico=$iid");
    }
    if (isset($_POST['protocolla'])) {
        $err = protocolla_incarico($conn, $iid, (string)($_POST['protocollo'] ?? ''), (string)($_POST['protocollo_data'] ?? ''), !empty($_POST['invia_copia']), $autore);
        if (!$err) registra_log_audit($conn, "Tutorato: lettera protocollata", ["Lettera" => $iid, "Protocollo" => $_POST['protocollo'] ?? '']);
        flash_set($err ?? "Protocollo registrato.", $err ? 'danger' : 'success');
        admin_redirect("$base&incarico=$iid");
    }
    if (isset($_POST['salva_dati_fine'])) {
        $err = salva_dati_fine_attivita($conn, $iid, $_POST);
        flash_set($err ?? "Dati della fine attività salvati.", $err ? 'danger' : 'success');
        admin_redirect("$base&incarico=$iid#fine");
    }
    if (isset($_POST['carica_fine_firmata'])) {
        // Dichiarazione di fine attività firmata dal docente fuori dal portale
        $f = $_FILES['pdf_firmato'] ?? null;
        $err = (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || $f['size'] > 20 * 1024 * 1024) ? "Scegli il PDF firmato (fino a 20 MB)." : registra_firma_fine($conn, $iid, (string)file_get_contents($f['tmp_name']), 'caricata dall\'Ufficio: ' . $autore);
        if (!$err) registra_log_audit($conn, "Tutorato: fine attività firmata caricata dall'ufficio", ["Lettera" => $iid]);
        flash_set($err ?? "Firma della dichiarazione di fine attività registrata.", $err ? 'danger' : 'success');
        admin_redirect("$base&incarico=$iid#fine");
    }
    if (isset($_POST['protocolla_fine'])) {
        $err = protocolla_fine_attivita($conn, $iid, (string)($_POST['fine_protocollo'] ?? ''), $autore);
        if (!$err) registra_log_audit($conn, "Tutorato: fine attività protocollata", ["Lettera" => $iid, "Protocollo" => $_POST['fine_protocollo'] ?? '']);
        flash_set($err ?? "Protocollo della fine attività registrato.", $err ? 'danger' : 'success');
        admin_redirect("$base&incarico=$iid#fine");
    }
    if (isset($_POST['annulla'])) {
        $err = annulla_incarico($conn, $iid, (string)($_POST['motivo'] ?? ''), $autore);
        if (!$err) registra_log_audit($conn, "Tutorato: lettera annullata", ["Lettera" => $iid]);
        flash_set($err ?? "Lettera annullata: i link inviati non funzionano più.", $err ? 'danger' : 'warning');
        admin_redirect("$base&incarico=$iid");
    }
    if (isset($_POST['duplica'])) {
        $nuovo = duplica_incarico($conn, $iid);
        flash_set($nuovo ? "Copia creata in bozza: correggi e invia." : "Copia non riuscita.", $nuovo ? 'success' : 'danger');
        admin_redirect($nuovo ? "$base&incarico=$nuovo" : "$base&incarico=$iid");
    }
}

// ==============================================================================
// PAGINA
// ==============================================================================
$operatori_b = operatori_ufficio($conn, 'bandi') ?: operatori_ufficio($conn);
$inc_sel = !empty($_GET['incarico']) ? incarico_tutorato($conn, (int)$_GET['incarico']) : null;
$bando_sel = !empty($_GET['bando']) ? bando_tutorato($conn, (int)$_GET['bando']) : ($inc_sel ? bando_tutorato($conn, (int)$inc_sel['bando_id']) : null);
$passi_html = function (array $i) use ($h) {
    $o = '<ol class="list-unstyled d-flex flex-wrap gap-1 mb-0 small" aria-label="Iter della lettera">';
    foreach (passi_incarico($i) as $k => [$n, $fatto, $ora]) {
        $st = $fatto ? 'background:#dcfce7;color:#166534;' : ($ora ? 'background:#047857;color:#fff;' : 'background:#f1f5f9;color:#64748b;');
        if ($k) $o .= '<li class="align-self-center text-secondary" aria-hidden="true">›</li>';
        $o .= '<li class="px-2 py-1 rounded fw-bold" style="' . $st . '"' . ($ora ? ' aria-current="step"' : '') . '><i class="fa ' . ($fatto ? 'fa-check' : ($ora ? 'fa-location-dot' : 'fa-circle')) . ' me-1" aria-hidden="true"></i>' . $h($n) . '</li>';
    }
    return $o . '</ol>';
};
?>
<?php
echo pn_testata('Tutorato · lettere di incarico', 'fa-user-graduate',
    'Per ogni bando: i vincitori, la lettera di incarico, la conferma dello studente, le firme e il protocollo.',
    !firma_remota_disponibile() ? '<span class="small text-secondary"><i class="fa fa-circle-info me-1" aria-hidden="true"></i>Firma remota Aruba non configurata (.env): docente e direttore caricano il PDF firmato in PAdES.</span>' : '',
    '<a href="didattica.php?p_id=' . (int)$filtro_p . '">Didattica</a> › Tutorato');
?>

<?php
$pag_sv = \App\Core\App::per($conn)->get(\App\Tutorato\ServizioPagamenti::class);
$da_validare = $pag_sv->daValidare();
$cifr_ok = \App\Core\App::per($conn)->get(\App\Tutorato\ServizioDichiarazione::class)->richiesta();
$vista_validare = ($_GET['vista'] ?? '') === 'validare';
?>
<ul class="nav nav-pills mb-3" aria-label="Sezioni del tutorato">
    <li class="nav-item"><a class="nav-link<?php echo !$vista_validare ? ' active' : ''; ?>" href="<?php echo $base; ?>"><i class="fa fa-user-graduate me-1" aria-hidden="true"></i>Bandi e lettere</a></li>
    <li class="nav-item"><a class="nav-link<?php echo $vista_validare ? ' active' : ''; ?>" href="<?php echo $base; ?>&amp;vista=validare"><i class="fa fa-clipboard-check me-1" aria-hidden="true"></i>Da validare e pagamenti<?php echo $da_validare ? ' <span class="badge bg-warning text-dark">' . count($da_validare) . '</span>' : ''; ?></a></li>
</ul>
<?php if (!$cifr_ok): ?><div class="alert alert-warning small"><i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i><strong>Dichiarazione individuale online non attiva.</strong> Per conservare i dati bancari dei tutor cifrati scrivi nel file <code>.env</code> la riga <code>CIFRATURA_CHIAVE=</code> seguita da una frase lunga e casuale (almeno 16 caratteri, da conservare anche fuori dal server). Finché manca, la dichiarazione non si richiede agli studenti.</div><?php endif; ?>

<?php if ($vista_validare): ?>
    <p class="small text-secondary">Pratiche con la lettera protocollata e la <strong>fine attività firmata dal docente titolare</strong> (non serve il protocollo). Controlla ore e documenti, poi passale all'ufficio pagamenti: una sola notifica per ufficio con l'elenco.</p>
    <?php if (!$da_validare): ?><div class="card border-0 shadow-sm"><div class="card-body"><?php echo pn_vuoto("Niente da validare: quando un docente firma la fine attività la pratica compare qui.", "fa-clipboard-check"); ?></div></div>
    <?php else: ?>
    <form method="POST" id="formValida"><?php csrf_field(); ?>
    <div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-sm align-middle small mb-0">
        <thead class="table-light"><tr><th style="width:34px;"><input type="checkbox" class="form-check-input" id="selTutte" aria-label="Seleziona tutte le pratiche pronte"></th><th>Tutor</th><th class="text-center">Ore</th><th>Compenso</th><th>Controlli</th><th></th></tr></thead><tbody>
        <?php foreach ($da_validare as $x): $pronta = !$x['_controlli']; $c = $x['_compenso']; ?>
            <tr><td><input type="checkbox" class="form-check-input pratica-pronta" name="ids[]" value="<?php echo (int)$x['id']; ?>"<?php echo $pronta ? '' : ' disabled'; ?> aria-label="Seleziona <?php echo $h(trim($x['cognome'] . ' ' . $x['nome'])); ?>"></td>
                <td><a class="fw-bold text-decoration-none" href="<?php echo $base; ?>&amp;incarico=<?php echo (int)$x['id']; ?>#pagamento"><?php echo $h(trim($x['cognome'] . ' ' . $x['nome'])); ?></a><div class="text-secondary"><?php echo $h($x['bando_titolo']); ?></div>
                    <?php if ($x['pagamento_stato'] === 'rimandata'): ?><div class="mt-1"><span class="badge bg-warning text-dark">Rimandata dall'ufficio</span> <span class="text-secondary"><?php echo $h($x['pagamento_nota']); ?></span></div><?php endif; ?></td>
                <td class="text-center"><strong<?php echo $c['cambiato'] ? ' class="text-warning-emphasis"' : ''; ?>><?php echo $h(ore_testo($c['ore'])); ?></strong> / <?php echo $h(ore_testo($x['ore'])); ?></td>
                <td class="text-nowrap">€ <?php echo number_format($c['lettera'], 2, ',', '.'); ?><div class="text-secondary" style="font-size:.72rem;">lordo ente € <?php echo number_format($c['lordo'], 2, ',', '.'); ?><?php echo $c['cambiato'] ? ' · in lettera era € ' . number_format($c['lettera_originale'], 2, ',', '.') : ''; ?></div></td>
                <td><?php if ($pronta): ?><span class="badge bg-success">tutto presente</span><?php else: ?><ul class="mb-0 ps-3 text-danger" style="max-width:340px;"><?php foreach ($x['_controlli'] as $m): ?><li><?php echo $h($m); ?></li><?php endforeach; ?></ul><?php endif; ?></td>
                <td class="text-end"><a class="btn btn-sm btn-outline-secondary py-0" href="<?php echo $base; ?>&amp;incarico=<?php echo (int)$x['id']; ?>#pagamento">Apri</a></td></tr>
        <?php endforeach; ?>
        </tbody></table></div></div>
    <p class="mt-3 d-flex flex-wrap gap-2 align-items-center"><button type="submit" name="passa_pagamenti" value="1" class="btn btn-success fw-bold" data-confirm="Passare le pratiche selezionate all'ufficio pagamenti?"><i class="fa fa-paper-plane me-1" aria-hidden="true"></i>Passa le selezionate all'ufficio pagamenti</button>
        <span class="small text-secondary">Una pratica con documenti mancanti non si può passare: la colonna «Controlli» dice cosa manca.</span></p>
    </form>
    <script>document.getElementById('selTutte').addEventListener('change', function (e) { document.querySelectorAll('.pratica-pronta:not(:disabled)').forEach(function (c) { c.checked = e.target.checked; }); });</script>
    <details class="mt-3"><summary class="fw-bold small">Rimandare una pratica al docente (fine attività da rivedere)</summary>
        <form method="POST" class="row g-2 mt-1"><?php csrf_field(); ?>
            <div class="col-md-4"><select name="incarico_id" class="form-select form-select-sm" required aria-label="Pratica"><option value="">Scegli la pratica…</option><?php foreach ($da_validare as $x): ?><option value="<?php echo (int)$x['id']; ?>"><?php echo $h(trim($x['cognome'] . ' ' . $x['nome'])); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><input class="form-control form-control-sm" name="motivo" maxlength="500" required placeholder="Perché la rimandi (il docente lo riceve per email)" aria-label="Motivo"></div>
            <div class="col-md-2"><button type="submit" name="rimanda_docente" value="1" class="btn btn-sm btn-outline-danger w-100">Rimanda al docente</button></div></form></details>
    <?php endif; ?>

<?php elseif (!empty($_GET['nuovo_bando']) || ($bando_sel && !empty($_GET['modifica']))):
    $b = $bando_sel ?: ['id' => 0, 'titolo' => '', 'anno_accademico' => anno_accademico_corrente() . '/' . (anno_accademico_corrente() + 1), 'decreto_bando' => '', 'decreto_bando_data' => '', 'decreto_commissione' => '', 'decreto_commissione_data' => '',
                         'direttore_persona_id' => '', 'direttore_nome' => '', 'direttore_email' => '', 'direttore_cf' => '', 'operatore_id' => operatore_ufficio($conn, 0, $utente_admin)['id'] ?? null, 'luogo' => 'Rende'];
    // Direttore proposto: quello dell'ultimo bando
    if (!$b['id'] && ($ult = bandi_tutorato($conn)[0] ?? null)) foreach (['direttore_persona_id', 'direttore_nome', 'direttore_email', 'direttore_cf', 'operatore_id', 'luogo'] as $k) $b[$k] = $ult[$k];
?>
    <nav class="small mb-2"><a href="<?php echo $base; ?>" class="text-decoration-none"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i>Bandi</a></nav>
    <form method="POST" class="card border-0 shadow-sm" id="formBando"><div class="card-body">
        <?php csrf_field(); ?><input type="hidden" name="bando_id" value="<?php echo (int)$b['id']; ?>">
        <h5 class="fw-bold mb-3"><?php echo $b['id'] ? 'Modifica bando' : 'Nuovo bando di tutorato'; ?></h5>
        <div class="row g-2">
            <div class="col-md-9"><label class="form-label small fw-bold" for="bTit">Titolo <span class="text-danger">*</span></label><input class="form-control" id="bTit" name="titolo" value="<?php echo $h($b['titolo']); ?>" required maxlength="255" placeholder="es. Tutorato e attività didattico-integrative – I semestre"></div>
            <div class="col-md-3"><label class="form-label small fw-bold" for="bAa">Anno accademico</label><select class="form-select" id="bAa" name="anno_accademico"><?php foreach (anni_accademici_scelta() as $a): ?><option<?php echo $a === $b['anno_accademico'] ? ' selected' : ''; ?>><?php echo $a; ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label small fw-bold" for="bDb">Decreto del bando (D.D. n.) <span class="text-danger">*</span></label><input class="form-control" id="bDb" name="decreto_bando" value="<?php echo $h($b['decreto_bando']); ?>" required maxlength="100" placeholder="es. 123/2026"></div>
            <div class="col-md-3"><label class="form-label small fw-bold" for="bDbd">del</label><input type="date" class="form-control" id="bDbd" name="decreto_bando_data" value="<?php echo $h($b['decreto_bando_data']); ?>"></div>
            <div class="col-md-3"><label class="form-label small fw-bold" for="bDc">Decreto della commissione / approvazione atti</label><input class="form-control" id="bDc" name="decreto_commissione" value="<?php echo $h($b['decreto_commissione']); ?>" maxlength="100" placeholder="es. 150/2026"></div>
            <div class="col-md-3"><label class="form-label small fw-bold" for="bDcd">del</label><input type="date" class="form-control" id="bDcd" name="decreto_commissione_data" value="<?php echo $h($b['decreto_commissione_data']); ?>"></div>
            <div class="col-12"><hr class="my-2"><h6 class="fw-bold mb-1">Direttore del Dipartimento</h6><p class="small text-secondary mb-1">Firma per ultimo, in PAdES. Sceglilo dall'anagrafe (nome ed email si compilano da soli) o scrivilo a mano.</p></div>
            <div class="col-md-12"><?php echo html_ricerca_personale($conn, 'Direttore'); ?></div>
            <input type="hidden" name="direttore_persona_id" id="bDirPid" value="<?php echo $h($b['direttore_persona_id']); ?>">
            <div class="col-md-4"><label class="form-label small fw-bold" for="bDirN">Nome e cognome</label><input class="form-control form-control-sm" id="bDirN" name="direttore_nome" value="<?php echo $h($b['direttore_nome']); ?>" maxlength="200"></div>
            <div class="col-md-4"><label class="form-label small fw-bold" for="bDirE">Email</label><input type="email" class="form-control form-control-sm" id="bDirE" name="direttore_email" value="<?php echo $h($b['direttore_email']); ?>" maxlength="150"></div>
            <div class="col-md-4"><label class="form-label small fw-bold" for="bDirC">Codice fiscale (facoltativo)</label><input class="form-control form-control-sm text-uppercase" id="bDirC" name="direttore_cf" value="<?php echo $h($b['direttore_cf']); ?>" maxlength="16"><div class="form-text">Se indicato, la firma deve essere del suo certificato.</div></div>
            <div class="col-12"><hr class="my-2"></div>
            <div class="col-md-6"><label class="form-label small fw-bold" for="bOp">Operatore che riceve le lettere firmate e le protocolla</label>
                <select class="form-select form-select-sm" id="bOp" name="operatore_id"><option value="0">Chi ha il compito «Bandi» nell'Ufficio didattico</option>
                    <?php foreach ($operatori_b as $o): ?><option value="<?php echo (int)$o['id']; ?>"<?php echo (int)$b['operatore_id'] === (int)$o['id'] ? ' selected' : ''; ?>><?php echo $h(etichetta_operatore($o)); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label small fw-bold" for="bLuogo">Luogo della lettera</label><input class="form-control form-control-sm" id="bLuogo" name="luogo" value="<?php echo $h($b['luogo']); ?>" maxlength="100"></div>
        </div>
        <div class="mt-3 d-flex gap-2"><button type="submit" name="salva_bando" value="1" class="btn btn-primary fw-bold"><i class="fa fa-save me-1" aria-hidden="true"></i>Salva</button>
            <a href="<?php echo $bando_sel ? "$base&amp;bando=" . (int)$bando_sel['id'] : $base; ?>" class="btn btn-outline-secondary">Annulla</a></div>
    </div></form>
    <script>
    document.getElementById('formBando').addEventListener('persona-scelta', function (e) {
        var p = e.detail;
        document.getElementById('bDirPid').value = p.id; document.getElementById('bDirN').value = p.nome; document.getElementById('bDirE').value = p.email || '';
    });
    ['bDirN', 'bDirE'].forEach(function (k) { document.getElementById(k).addEventListener('input', function () { document.getElementById('bDirPid').value = ''; }); });
    </script>

<?php elseif (!empty($_GET['nuovo_incarico']) || ($inc_sel && $inc_sel['stato'] === 'bozza')):
    // Form della lettera (nuova o in bozza); dopo un errore si ripropongono i dati scritti
    $i = $inc_sel ?: ['id' => 0, 'bando_id' => (int)($_GET['bando'] ?? 0), 'genere' => 'M', 'cognome' => '', 'nome' => '', 'luogo_nascita' => '', 'data_nascita' => '', 'comune_residenza' => '', 'indirizzo' => '', 'civico' => '',
                      'codice_fiscale' => '', 'email' => '', 'telefono' => '', 'attivita' => '', 'ore' => '', 'periodo' => '', 'compenso' => '', 'docente_persona_id' => '', 'docente_nome' => '', 'docente_cognome' => '', 'docente_email' => '', 'docente_cf' => '', 'data_lettera' => date('Y-m-d'),
                      'docente_titolo' => 'Prof.', 'insegnamento_docente' => '', 'corso_laurea' => '', 'data_inizio' => '', 'data_fine' => ''];
    if (!empty($_SESSION['incarico_post'])) { foreach ($_SESSION['incarico_post'] as $k => $v) if (array_key_exists($k, $i) && $k !== 'id') $i[$k] = $v; unset($_SESSION['incarico_post']); }
    $b = bando_tutorato($conn, (int)$i['bando_id']);
    if (!$b): ?><div class="alert alert-warning">Scegli prima il bando.</div><?php else: ?>
    <nav class="small mb-2"><a href="<?php echo $base; ?>&amp;bando=<?php echo (int)$b['id']; ?>" class="text-decoration-none"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i><?php echo $h($b['titolo']); ?></a></nav>
    <form method="POST" class="card border-0 shadow-sm" id="formInc"><div class="card-body">
        <?php csrf_field(); ?><input type="hidden" name="incarico_id" value="<?php echo (int)$i['id']; ?>"><input type="hidden" name="bando_id" value="<?php echo (int)$b['id']; ?>">
        <h5 class="fw-bold mb-1"><?php echo $i['id'] ? 'Lettera di incarico (bozza)' : 'Nuova lettera di incarico'; ?></h5>
        <p class="small text-secondary">Bando: <strong><?php echo $h($b['titolo']); ?></strong> · D.D. n. <?php echo $h(decreto_testo($b['decreto_bando'], $b['decreto_bando_data'])); ?><?php echo $b['decreto_commissione'] !== '' ? ' · atti approvati con D.D. n. ' . $h(decreto_testo($b['decreto_commissione'], $b['decreto_commissione_data'])) : ''; ?> · Direttore: <?php echo $h($b['direttore_nome']); ?></p>
        <h6 class="fw-bold mt-2">Vincitore</h6>
        <div class="row g-2">
            <div class="col-md-2"><label class="form-label small fw-bold" for="iGen">Titolo</label><select class="form-select" id="iGen" name="genere"><option value="M"<?php echo $i['genere'] !== 'F' ? ' selected' : ''; ?>>Dott.</option><option value="F"<?php echo $i['genere'] === 'F' ? ' selected' : ''; ?>>Dott.ssa</option></select></div>
            <div class="col-md-3"><label class="form-label small fw-bold" for="iCog">Cognome <span class="text-danger">*</span></label><input class="form-control" id="iCog" name="cognome" value="<?php echo $h($i['cognome']); ?>" required maxlength="100"></div>
            <div class="col-md-3"><label class="form-label small fw-bold" for="iNom">Nome <span class="text-danger">*</span></label><input class="form-control" id="iNom" name="nome" value="<?php echo $h($i['nome']); ?>" required maxlength="100"></div>
            <div class="col-md-4"><label class="form-label small fw-bold" for="iCf">Codice fiscale <span class="text-danger">*</span></label><input class="form-control text-uppercase font-monospace" id="iCf" name="codice_fiscale" value="<?php echo $h($i['codice_fiscale']); ?>" required maxlength="16" pattern="[A-Za-z0-9]{16}"><div class="form-text">Lo studente potrà confermare solo entrando con lo SPID/CIE di questo codice fiscale.</div></div>
            <div class="col-md-4"><label class="form-label small fw-bold" for="iLn">Luogo di nascita</label><input class="form-control" id="iLn" name="luogo_nascita" value="<?php echo $h($i['luogo_nascita']); ?>" maxlength="150"></div>
            <div class="col-md-3"><label class="form-label small fw-bold" for="iDn">Data di nascita</label><input type="date" class="form-control" id="iDn" name="data_nascita" value="<?php echo $h($i['data_nascita']); ?>"></div>
            <div class="col-md-5"><label class="form-label small fw-bold" for="iEm">Email <span class="text-danger">*</span></label><input type="email" class="form-control" id="iEm" name="email" value="<?php echo $h($i['email']); ?>" required maxlength="255"></div>
            <div class="col-md-4"><label class="form-label small fw-bold" for="iCr">Comune di residenza</label><input class="form-control" id="iCr" name="comune_residenza" value="<?php echo $h($i['comune_residenza']); ?>" maxlength="150"></div>
            <div class="col-md-5"><label class="form-label small fw-bold" for="iInd">Indirizzo</label><input class="form-control" id="iInd" name="indirizzo" value="<?php echo $h($i['indirizzo']); ?>" maxlength="255" placeholder="es. Via Roma"></div>
            <div class="col-md-1"><label class="form-label small fw-bold" for="iCiv">N°</label><input class="form-control" id="iCiv" name="civico" value="<?php echo $h($i['civico']); ?>" maxlength="20"></div>
            <div class="col-md-2"><label class="form-label small fw-bold" for="iTel">Telefono</label><input class="form-control" id="iTel" name="telefono" value="<?php echo $h($i['telefono']); ?>" maxlength="40"></div>
        </div>
        <h6 class="fw-bold mt-3">Incarico</h6>
        <div class="row g-2">
            <div class="col-md-6"><label class="form-label small fw-bold" for="iAtt">Attività da svolgere <span class="text-danger">*</span></label><textarea class="form-control" id="iAtt" name="attivita" rows="3" required maxlength="3000" placeholder="es. Tutorato di Chimica generale per gli studenti del primo anno del CdL in Biologia"><?php echo $h($i['attivita']); ?></textarea></div>
            <div class="col-md-2"><label class="form-label small fw-bold" for="iOre">N. ore <span class="text-danger">*</span></label><input class="form-control" id="iOre" name="ore" value="<?php echo $h($i['ore'] !== '' && $i['ore'] !== null ? rtrim(rtrim((string)$i['ore'], '0'), '.') : ''); ?>" required inputmode="decimal"></div>
            <div class="col-md-4"><label class="form-label small fw-bold" for="iPer">Periodo <span class="text-danger">*</span></label><input class="form-control" id="iPer" name="periodo" value="<?php echo $h($i['periodo']); ?>" required maxlength="255" placeholder="es. dal 01/11/2026 al 28/02/2027"></div>
            <div class="col-md-3"><label class="form-label small fw-bold" for="iComp">Compenso (€, al netto degli oneri dell'Ente) <span class="text-danger">*</span></label><input class="form-control" id="iComp" name="compenso" value="<?php echo $h($i['compenso'] !== '' && $i['compenso'] !== null && is_numeric($i['compenso']) ? number_format((float)$i['compenso'], 2, ',', '.') : $i['compenso']); ?>" required inputmode="decimal" placeholder="es. 1.200,00"></div>
            <div class="col-md-3"><label class="form-label small fw-bold" for="iDl">Data della lettera</label><input type="date" class="form-control" id="iDl" name="data_lettera" value="<?php echo $h($i['data_lettera']); ?>"></div>
            <div class="col-md-3"><label class="form-label small fw-bold" for="iDi">Inizio delle attività</label><input type="date" class="form-control" id="iDi" name="data_inizio" value="<?php echo $h($i['data_inizio']); ?>"></div>
            <div class="col-md-3"><label class="form-label small fw-bold" for="iDf">Fine delle attività</label><input type="date" class="form-control" id="iDf" name="data_fine" value="<?php echo $h($i['data_fine']); ?>"><div class="form-text">Serve per il registro e per i promemoria al tutor verso la fine.</div></div>
        </div>
        <h6 class="fw-bold mt-3">Docente responsabile dell'attività <span class="small fw-normal text-secondary">(firma la presa visione in PAdES)</span></h6>
        <?php echo html_ricerca_personale($conn, 'Responsabile'); ?>
        <input type="hidden" name="docente_persona_id" id="iDocPid" value="<?php echo $h($i['docente_persona_id']); ?>">
        <div class="small mb-1" id="iDocAn"<?php echo $i['docente_persona_id'] ? '' : ' hidden'; ?>><span class="badge bg-success"><i class="fa fa-address-book me-1" aria-hidden="true"></i>dall'anagrafe</span></div>
        <div class="row g-2">
            <div class="col-md-2"><label class="form-label small fw-bold" for="iDtit">Titolo</label><select class="form-select form-select-sm" id="iDtit" name="docente_titolo"><?php foreach (['Prof.', 'Prof.ssa', 'Dott.', 'Dott.ssa'] as $t): ?><option<?php echo ($i['docente_titolo'] ?: 'Prof.') === $t ? ' selected' : ''; ?>><?php echo $t; ?></option><?php endforeach; ?></select></div>
            <div class="col-md-5"><label class="form-label small fw-bold" for="iIns">Insegnamento di cui è titolare</label><input class="form-control form-control-sm" id="iIns" name="insegnamento_docente" value="<?php echo $h($i['insegnamento_docente']); ?>" maxlength="255" placeholder="es. Chimica generale"></div>
            <div class="col-md-5"><label class="form-label small fw-bold" for="iCdl">Corso di laurea</label><input class="form-control form-control-sm" id="iCdl" name="corso_laurea" value="<?php echo $h($i['corso_laurea']); ?>" maxlength="255" placeholder="es. Scienze Biologiche"><div class="form-text">Per la dichiarazione di fine attività, che si compila da sola.</div></div>
            <div class="col-md-3"><label class="form-label small fw-bold" for="iDn2">Nome</label><input class="form-control form-control-sm i-doc" id="iDn2" name="docente_nome" value="<?php echo $h($i['docente_nome']); ?>" maxlength="100"></div>
            <div class="col-md-3"><label class="form-label small fw-bold" for="iDc">Cognome</label><input class="form-control form-control-sm i-doc" id="iDc" name="docente_cognome" value="<?php echo $h($i['docente_cognome']); ?>" maxlength="100"></div>
            <div class="col-md-3"><label class="form-label small fw-bold" for="iDe">Email</label><input type="email" class="form-control form-control-sm i-doc" id="iDe" name="docente_email" value="<?php echo $h($i['docente_email']); ?>" maxlength="150"></div>
            <div class="col-md-3"><label class="form-label small fw-bold" for="iDcf">Codice fiscale</label><input class="form-control form-control-sm text-uppercase i-doc" id="iDcf" name="docente_cf" value="<?php echo $h($i['docente_cf']); ?>" maxlength="16"><div class="form-text">Se non è nell'anagrafe: serve per riconoscerlo all'accesso e nella firma.</div></div>
        </div>
        <div class="mt-3 d-flex gap-2"><button type="submit" name="salva_incarico" value="1" class="btn btn-primary fw-bold"><i class="fa fa-save me-1" aria-hidden="true"></i>Salva</button>
            <a href="<?php echo $base; ?>&amp;bando=<?php echo (int)$b['id']; ?>" class="btn btn-outline-secondary">Annulla</a></div>
    </div></form>
    <script>
    document.getElementById('formInc').addEventListener('persona-scelta', function (e) {
        var p = e.detail;
        document.getElementById('iDocPid').value = p.id; document.getElementById('iDocAn').hidden = false;
        document.getElementById('iDc').value = p.cognome || ''; document.getElementById('iDn2').value = (p.nome || '').replace(new RegExp('\\s*' + (p.cognome || '') + '$', 'i'), '').trim();
        document.getElementById('iDe').value = p.email || '';
    });
    document.querySelectorAll('.i-doc').forEach(function (x) { x.addEventListener('input', function () { document.getElementById('iDocPid').value = ''; document.getElementById('iDocAn').hidden = true; }); });
    </script>
    <?php endif; ?>
    <?php if ($inc_sel): ?>
        <div class="card border-0 shadow-sm mt-3"><div class="card-body d-flex flex-wrap gap-2 align-items-center">
            <a class="btn btn-sm btn-outline-secondary" href="<?php echo $base; ?>&amp;incarico=<?php echo (int)$inc_sel['id']; ?>&amp;file=anteprima" target="_blank" rel="noopener"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>Anteprima del PDF</a>
            <a class="btn btn-sm btn-outline-primary" href="<?php echo $base; ?>&amp;incarico=<?php echo (int)$inc_sel['id']; ?>&amp;file=word"><i class="fa fa-file-word me-1" aria-hidden="true"></i>Word precompilato</a>
            <form method="POST" class="m-0 ms-auto"><?php csrf_field(); ?><input type="hidden" name="incarico_id" value="<?php echo (int)$inc_sel['id']; ?>">
                <button type="submit" name="invia_studente" value="1" class="btn btn-success fw-bold" data-confirm="Inviare la lettera a <?php echo $h($inc_sel['email']); ?>? Dopo l'invio i dati non si modificano più."><i class="fa fa-paper-plane me-1" aria-hidden="true"></i>Invia email allo studente per la conferma</button></form>
        </div></div>
    <?php endif; ?>

<?php elseif ($inc_sel):
    $i = $inc_sel;
    $firma_s = json_decode((string)$i['studente_firma_json'], true) ?: null;
    $eventi = \App\Core\App::per($conn)->get(\App\Tutorato\ServizioIncarichi::class)->eventi((int)$i['id']);
    $d = dati_lettera_incarico($i);
?>
    <nav class="small mb-2"><a href="<?php echo $base; ?>&amp;bando=<?php echo (int)$i['bando_id']; ?>" class="text-decoration-none"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i><?php echo $h($i['bando_titolo']); ?></a></nav>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <div class="d-flex flex-wrap gap-2 align-items-start mb-2">
            <div class="flex-grow-1"><h5 class="fw-bold mb-0"><?php echo $h($d['TITOLO'] . ' ' . $d['NOMINATIVO']); ?></h5><div class="small text-secondary font-monospace"><?php echo $h($i['codice']); ?> · <?php echo $h($i['codice_fiscale']); ?> · <?php echo $h($i['email']); ?></div></div>
            <?php echo badge_stato_incarico($i['stato']); ?><?php echo !empty($i['anonimizzata']) ? ' <span class="badge bg-secondary" title="Dati personali, PDF e registro cancellati per la conservazione dei dati">dati cancellati</span>' : ''; ?>
        </div>
        <?php if ($i['stato'] !== 'annullata') echo $passi_html($i); ?>
        <?php if ($i['nota_studente'] && $i['stato'] === 'inviata'): ?><div class="alert alert-warning small mt-2 mb-0"><strong>Segnalazione dello studente:</strong> <?php echo nl2br($h($i['nota_studente'])); ?> — annulla la lettera e crea una copia corretta.</div><?php endif; ?>
    </div></div>
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm mb-3"><div class="card-body">
                <h6 class="fw-bold">Dati della lettera</h6>
                <dl class="row small mb-0">
                    <dt class="col-sm-4">Nascita e residenza</dt><dd class="col-sm-8"><?php echo $h($d['NATO'] . ' a ' . $d['LUOGO_NASCITA'] . ' il ' . $d['DATA_NASCITA'] . ' – residente a ' . $d['COMUNE_RESIDENZA'] . ', ' . $d['INDIRIZZO']); ?></dd>
                    <dt class="col-sm-4">Attività</dt><dd class="col-sm-8"><?php echo nl2br($h($d['ATTIVITA'])); ?></dd>
                    <dt class="col-sm-4">Ore · periodo · compenso</dt><dd class="col-sm-8"><?php echo $h($d['ORE'] . ' ore · ' . $d['PERIODO'] . ' · ' . $d['COMPENSO']); ?></dd>
                    <dt class="col-sm-4">Decreti</dt><dd class="col-sm-8">Bando D.D. n. <?php echo $h($d['DECRETO_BANDO']); ?> · atti D.D. n. <?php echo $h($d['DECRETO_COMMISSIONE']); ?></dd>
                    <dt class="col-sm-4">Responsabile dell'attività</dt><dd class="col-sm-8"><?php echo $h($d['FIRMA_DOCENTE']); ?> · <?php echo $h($i['docente_email']); ?><?php echo $i['docente_persona_id'] ? ' <span class="badge bg-light text-dark border">anagrafe</span>' : ''; ?></dd>
                    <dt class="col-sm-4">Direttore</dt><dd class="col-sm-8"><?php echo $h($d['FIRMA_DIRETTORE']); ?> · <?php echo $h($i['direttore_email']); ?></dd>
                    <?php if ($firma_s): ?><dt class="col-sm-4">Accettazione</dt><dd class="col-sm-8">con <?php echo $h(METODI_ACCESSO[$firma_s['metodo']] ?? $firma_s['metodo']); ?><?php echo $firma_s['livello'] ? ' livello ' . (int)$firma_s['livello'] : ''; ?> il <?php echo date('d/m/Y H:i', strtotime($firma_s['confermata_il'])); ?><?php echo $firma_s['spid_code'] ? ' · spidCode ' . $h($firma_s['spid_code']) : ''; ?></dd><?php endif; ?>
                    <?php if ($i['protocollo'] !== ''): ?><dt class="col-sm-4">Protocollo</dt><dd class="col-sm-8"><?php echo $h($i['protocollo'] . ' del ' . date('d/m/Y', strtotime($i['protocollo_data']))); ?></dd><?php endif; ?>
                </dl>
                <div class="d-flex flex-wrap gap-2 mt-3">
                    <?php if ($i['file_pdf']): ?>
                        <a class="btn btn-sm btn-primary fw-bold" href="<?php echo $base; ?>&amp;incarico=<?php echo (int)$i['id']; ?>&amp;file=pdf&amp;scarica=1"><i class="fa fa-download me-1" aria-hidden="true"></i><?php echo in_array($i['stato'], ['firmata', 'protocollata'], true) ? 'Scarica il PDF con tutte le firme' : 'Scarica il PDF attuale'; ?></a>
                        <a class="btn btn-sm btn-outline-secondary" href="<?php echo $base; ?>&amp;incarico=<?php echo (int)$i['id']; ?>&amp;file=pdf" target="_blank" rel="noopener">Apri</a>
                    <?php else: ?>
                        <a class="btn btn-sm btn-outline-secondary" href="<?php echo $base; ?>&amp;incarico=<?php echo (int)$i['id']; ?>&amp;file=anteprima" target="_blank" rel="noopener"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>Anteprima del PDF</a>
                    <?php endif; ?>
                    <a class="btn btn-sm btn-outline-primary" href="<?php echo $base; ?>&amp;incarico=<?php echo (int)$i['id']; ?>&amp;file=word"><i class="fa fa-file-word me-1" aria-hidden="true"></i>Word precompilato</a>
                </div>
            </div></div>
            <div class="card border-0 shadow-sm"><div class="card-body">
                <h6 class="fw-bold">Storico</h6>
                <?php foreach ($eventi as $e): ?>
                    <div class="small border-start border-3 ps-2 mb-2"><span class="text-secondary"><?php echo date('d/m/Y H:i', strtotime($e['creato_il'])); ?><?php echo $e['autore'] !== '' ? ' · ' . $h($e['autore']) : ''; ?><?php echo $e['ip'] !== '' ? ' · IP ' . $h($e['ip']) : ''; ?></span><div><?php echo nl2br($h($e['testo'])); ?></div></div>
                <?php endforeach; ?>
            </div></div>
        </div>
        <div class="col-lg-5">
            <?php if ($i['stato'] === 'inviata'): ?>
                <form method="POST" class="card border-0 shadow-sm mb-3"><div class="card-body"><?php csrf_field(); ?><input type="hidden" name="incarico_id" value="<?php echo (int)$i['id']; ?>">
                    <h6 class="fw-bold">In attesa della conferma dello studente</h6>
                    <p class="small text-secondary">Inviata il <?php echo date('d/m/Y H:i', strtotime($i['inviata_il'])); ?> a <?php echo $h($i['email']); ?>.</p>
                    <button type="submit" name="invia_studente" value="1" class="btn btn-sm btn-outline-primary"><i class="fa fa-rotate-right me-1" aria-hidden="true"></i>Invia di nuovo l'email</button>
                </div></form>
            <?php endif; ?>
            <?php if (in_array($i['stato'], ['confermata', 'firmata_docente'], true)): $chi = $i['stato'] === 'confermata' ? 'del docente' : 'del direttore'; ?>
                <form method="POST" enctype="multipart/form-data" class="card border-0 shadow-sm mb-3"><div class="card-body"><?php csrf_field(); ?><input type="hidden" name="incarico_id" value="<?php echo (int)$i['id']; ?>">
                    <h6 class="fw-bold">In attesa della firma <?php echo $chi; ?></h6>
                    <p class="small text-secondary">Ha ricevuto l'email con il link per firmare. Se ti invia il PDF firmato (PAdES), caricalo qui: deve essere la lettera scaricata dal portale con la sua firma aggiunta.</p>
                    <input type="file" name="pdf_firmato" class="form-control form-control-sm mb-2" accept=".pdf,application/pdf" required aria-label="PDF firmato">
                    <button type="submit" name="carica_firmato" value="1" class="btn btn-sm btn-primary fw-bold"><i class="fa fa-upload me-1" aria-hidden="true"></i>Carica la firma <?php echo $chi; ?></button>
                </div></form>
            <?php endif; ?>
            <?php if (in_array($i['stato'], ['firmata', 'protocollata'], true)): ?>
                <form method="POST" class="card border-0 shadow-sm mb-3" style="border-left:4px solid #15803d !important;"><div class="card-body"><?php csrf_field(); ?><input type="hidden" name="incarico_id" value="<?php echo (int)$i['id']; ?>">
                    <h6 class="fw-bold"><i class="fa fa-stamp me-1 text-success" aria-hidden="true"></i>Protocollo</h6>
                    <p class="small text-secondary">Scarica il PDF con tutte le firme, protocollalo e registra qui il numero.</p>
                    <div class="row g-2"><div class="col-7"><input class="form-control form-control-sm" name="protocollo" value="<?php echo $h($i['protocollo']); ?>" placeholder="Numero di protocollo" aria-label="Numero di protocollo" required></div>
                        <div class="col-5"><input type="date" class="form-control form-control-sm" name="protocollo_data" value="<?php echo $h($i['protocollo_data'] ?: date('Y-m-d')); ?>" aria-label="Data del protocollo"></div></div>
                    <label class="form-check small mt-2"><input class="form-check-input" type="checkbox" name="invia_copia" value="1"<?php echo $i['stato'] === 'firmata' ? ' checked' : ''; ?>> invia allo studente la lettera firmata</label>
                    <button type="submit" name="protocolla" value="1" class="btn btn-sm btn-success fw-bold mt-1">Registra il protocollo</button>
                </div></form>
            <?php endif; ?>
            <?php if (in_array($i['stato'], ['firmata', 'protocollata'], true)):
                $reg = registro_incarico($conn, (int)$i['id']); $o = ore_registro($reg); $fs = (string)$i['fine_stato']; $sf = STATI_FINE_ATTIVITA[$fs] ?? STATI_FINE_ATTIVITA[''];
                $perc = $i['ore'] > 0 ? min(100, round($o['approvata'] / (float)$i['ore'] * 100)) : 0; ?>
                <div class="card border-0 shadow-sm mb-3" id="fine" style="border-left:4px solid <?php echo $sf[1]; ?> !important;"><div class="card-body">
                    <h6 class="fw-bold"><i class="fa fa-clipboard-list me-1" aria-hidden="true"></i>Registro e fine attività</h6>
                    <span class="badge mb-2" style="background:<?php echo $sf[1]; ?>;white-space:normal;text-align:left;"><?php echo $h($sf[0]); ?></span>
                    <div class="small mb-1">Ore approvate <strong><?php echo ore_testo($o['approvata']); ?></strong> su <?php echo ore_testo($i['ore']); ?><?php echo $o['inviata'] > 0 ? ' · <span class="text-warning-emphasis">' . ore_testo($o['inviata']) . ' da approvare</span>' : ''; ?><?php echo $o['respinta'] > 0 ? ' · ' . ore_testo($o['respinta']) . ' respinte' : ''; ?></div>
                    <div class="progress mb-2" style="height:8px;" role="progressbar" aria-label="Ore approvate" aria-valuenow="<?php echo $perc; ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar bg-success" style="width:<?php echo $perc; ?>%"></div></div>
                    <?php if ($i['data_inizio'] || $i['data_fine']): ?><div class="small text-secondary mb-2">Periodo: <?php echo $i['data_inizio'] ? date('d/m/Y', strtotime($i['data_inizio'])) : '…'; ?> – <?php echo $i['data_fine'] ? date('d/m/Y', strtotime($i['data_fine'])) : '…'; ?></div><?php endif; ?>
                    <?php if ($reg): ?>
                        <details class="small mb-2"><summary class="fw-bold">Registro (<?php echo count($reg); ?> giorni)</summary>
                            <table class="table table-sm small mb-0 mt-1"><thead><tr><th>Data</th><th class="text-center">Ore</th><th>Attività</th><th>Stato</th></tr></thead><tbody>
                            <?php foreach ($reg as $r): ?><tr><td class="text-nowrap"><?php echo date('d/m/Y', strtotime($r['data'])); ?></td><td class="text-center"><?php echo ore_testo($r['ore']); ?></td><td><?php echo $h($r['attivita']); ?><?php echo $r['nota_docente'] !== '' && $r['nota_docente'] !== null ? '<div class="text-secondary fst-italic">' . $h($r['nota_docente']) . '</div>' : ''; ?></td>
                                <td><span class="badge" style="background:<?php echo STATI_REGISTRO[$r['stato']][1]; ?>;"><?php echo $h(STATI_REGISTRO[$r['stato']][0]); ?></span></td></tr><?php endforeach; ?>
                            </tbody></table></details>
                    <?php else: ?><p class="small text-secondary">Il tutor non ha ancora segnato attività nel registro.</p><?php endif; ?>
                    <div class="d-flex flex-wrap gap-2 mb-2">
                        <?php if (in_array($fs, ['da_firmare', 'firmata', 'protocollata'], true)): ?>
                            <a class="btn btn-sm btn-primary fw-bold" href="<?php echo $base; ?>&amp;incarico=<?php echo (int)$i['id']; ?>&amp;file=fine&amp;scarica=1"><i class="fa fa-download me-1" aria-hidden="true"></i>Dichiarazione di fine attività<?php echo $fs === 'da_firmare' ? ' (da firmare)' : ' firmata'; ?></a>
                        <?php else: ?>
                            <a class="btn btn-sm btn-outline-secondary" href="<?php echo $base; ?>&amp;incarico=<?php echo (int)$i['id']; ?>&amp;file=fine_anteprima" target="_blank" rel="noopener"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>Anteprima della dichiarazione</a>
                        <?php endif; ?>
                    </div>
                    <?php if (in_array($fs, ['', 'richiesta'], true)): ?>
                        <form method="POST" class="border-top pt-2"><?php csrf_field(); ?><input type="hidden" name="incarico_id" value="<?php echo (int)$i['id']; ?>">
                            <div class="small fw-bold mb-1">Dati per la dichiarazione (si compila da sola)</div>
                            <div class="row g-1">
                                <div class="col-4"><select class="form-select form-select-sm" name="docente_titolo" aria-label="Titolo del docente"><?php foreach (['Prof.', 'Prof.ssa', 'Dott.', 'Dott.ssa'] as $t): ?><option<?php echo ($i['docente_titolo'] ?: 'Prof.') === $t ? ' selected' : ''; ?>><?php echo $t; ?></option><?php endforeach; ?></select></div>
                                <div class="col-8"><input class="form-control form-control-sm" name="insegnamento_docente" value="<?php echo $h($i['insegnamento_docente']); ?>" placeholder="Insegnamento del docente" aria-label="Insegnamento del docente" maxlength="255"></div>
                                <div class="col-12"><input class="form-control form-control-sm" name="corso_laurea" value="<?php echo $h($i['corso_laurea']); ?>" placeholder="Corso di laurea" aria-label="Corso di laurea" maxlength="255"></div>
                                <div class="col-6"><input type="date" class="form-control form-control-sm" name="data_inizio" value="<?php echo $h($i['data_inizio']); ?>" aria-label="Inizio delle attività" title="Inizio delle attività"></div>
                                <div class="col-6"><input type="date" class="form-control form-control-sm" name="data_fine" value="<?php echo $h($i['data_fine']); ?>" aria-label="Fine delle attività" title="Fine delle attività"></div>
                            </div>
                            <button type="submit" name="salva_dati_fine" value="1" class="btn btn-sm btn-outline-primary mt-2">Salva</button>
                        </form>
                    <?php elseif ($fs === 'da_firmare'): ?>
                        <form method="POST" enctype="multipart/form-data" class="border-top pt-2"><?php csrf_field(); ?><input type="hidden" name="incarico_id" value="<?php echo (int)$i['id']; ?>">
                            <p class="small text-secondary mb-1">Il docente ha il link per firmare. Se ti manda il PDF firmato in PAdES, caricalo qui.</p>
                            <input type="file" name="pdf_firmato" class="form-control form-control-sm mb-2" accept=".pdf,application/pdf" required aria-label="Dichiarazione firmata">
                            <button type="submit" name="carica_fine_firmata" value="1" class="btn btn-sm btn-primary fw-bold"><i class="fa fa-upload me-1" aria-hidden="true"></i>Carica la dichiarazione firmata</button>
                        </form>
                    <?php elseif ($fs === 'firmata' || ($fs === 'protocollata' && $i['fine_protocollo'] === '')): ?>
                        <p class="small border-top pt-2 mb-0"><i class="fa fa-circle-check text-success me-1" aria-hidden="true"></i>Firmata dal docente titolare: <strong>non serve il protocollo</strong>. Controlla ore e documenti e passa la pratica ai pagamenti dalla vista <a href="<?php echo $base; ?>&amp;vista=validare">«Da validare»</a>.</p>
                    <?php else: ?>
                        <form method="POST" class="border-top pt-2"><?php csrf_field(); ?><input type="hidden" name="incarico_id" value="<?php echo (int)$i['id']; ?>">
                            <label class="small fw-bold mb-1" for="fProt">Protocollo della fine attività</label>
                            <div class="d-flex gap-1"><input class="form-control form-control-sm" id="fProt" name="fine_protocollo" value="<?php echo $h($i['fine_protocollo']); ?>" required placeholder="Numero di protocollo">
                            <button type="submit" name="protocolla_fine" value="1" class="btn btn-sm btn-success fw-bold">Registra</button></div>
                        </form>
                    <?php endif; ?>
                </div></div>
            <?php endif; ?>
            <?php if ($i['stato'] === 'protocollata'):
                $comp_i = $pag_sv->compenso($i); $contr_i = $pag_sv->controlli($i); $ps_i = (string)$i['pagamento_stato']; ?>
                <div class="card border-0 shadow-sm mb-3" id="pagamento" style="border-left:4px solid #15803d !important;"><div class="card-body">
                    <h6 class="fw-bold"><i class="fa fa-euro-sign me-1" aria-hidden="true"></i>UNILAV, compenso e pagamento</h6>
                    <div class="row g-2 small mb-2">
                        <div class="col-6"><div class="text-secondary">Lordo ente · <?php echo $h(ore_testo($comp_i['ore'])); ?> ore × € 25,00</div><div class="fs-6 fw-bold">€ <?php echo number_format($comp_i['lordo'], 2, ',', '.'); ?></div></div>
                        <div class="col-6"><div class="text-secondary">Compenso in lettera (÷ 1,24)</div><div class="fs-6 fw-bold">€ <?php echo number_format($comp_i['lettera'], 2, ',', '.'); ?></div></div>
                    </div>
                    <?php if ($comp_i['cambiato']): ?><div class="alert alert-warning small py-1">Ore approvate <?php echo $h(ore_testo($comp_i['ore'])); ?> su <?php echo $h(ore_testo($i['ore'])); ?>: il compenso si è <strong>ricalcolato</strong> (nella lettera c'era € <?php echo number_format($comp_i['lettera_originale'], 2, ',', '.'); ?>). Carica l'UNILAV aggiornato con le nuove ore.</div><?php endif; ?>
                    <div class="small fw-bold mb-1">UNILAV</div>
                    <?php if (!empty($i['unilav_file'])): ?><div class="small mb-1"><i class="fa fa-circle-check text-success" aria-hidden="true"></i> Caricato il <?php echo date('d/m/Y', strtotime($i['unilav_il'])); ?> · riporta <?php echo $h(ore_testo($i['unilav_ore'])); ?> ore · <a href="documento_tutorato.php?i=<?php echo (int)$i['id']; ?>&amp;f=unilav" target="_blank" rel="noopener">apri</a></div><?php endif; ?>
                    <?php if (!in_array($ps_i, ['in_ufficio', 'pagata'], true)): ?>
                        <form method="POST" enctype="multipart/form-data" class="d-flex gap-1 mb-2"><?php csrf_field(); ?><input type="hidden" name="incarico_id" value="<?php echo (int)$i['id']; ?>">
                            <input type="file" name="unilav_pdf" class="form-control form-control-sm" accept=".pdf,application/pdf" required aria-label="UNILAV in PDF">
                            <button type="submit" name="carica_unilav" value="1" class="btn btn-sm btn-primary fw-bold text-nowrap"><i class="fa fa-upload me-1" aria-hidden="true"></i><?php echo !empty($i['unilav_file']) ? 'Sostituisci' : 'Carica'; ?></button></form>
                    <?php else: ?><p class="small text-secondary">La pratica è all'ufficio pagamenti: l'UNILAV non si cambia più.</p><?php endif; ?>
                    <div class="small fw-bold mb-1">Stato</div>
                    <?php if ($ps_i === 'pagata'): ?><span class="badge bg-success">Pagato il <?php echo date('d/m/Y', strtotime($i['pagamento_data'])); ?></span> <span class="small text-secondary"><?php echo $h($i['pagamento_riferimento']); ?> · <?php echo $h($i['pagato_da']); ?></span>
                    <?php elseif ($ps_i === 'in_ufficio'): ?><span class="badge bg-primary">All'ufficio pagamenti</span> <span class="small text-secondary">dal <?php echo date('d/m/Y', strtotime($i['pagamento_passato_il'])); ?> · <?php echo $h($i['pagamento_passato_da']); ?></span>
                    <?php elseif ($contr_i): ?><span class="badge bg-warning text-dark">Non ancora pronta</span><ul class="small text-danger ps-3 mt-1 mb-0"><?php foreach ($contr_i as $m): ?><li><?php echo $h($m); ?></li><?php endforeach; ?></ul>
                    <?php else: ?><span class="badge bg-success">Pronta</span> <a class="small fw-bold" href="<?php echo $base; ?>&amp;vista=validare">Passala ai pagamenti dalla vista «Da validare»</a><?php endif; ?>
                    <?php if ($ps_i === 'rimandata' && $i['pagamento_nota'] !== ''): ?><div class="alert alert-warning small mt-2 mb-0"><strong>Rimandata dall'ufficio pagamenti:</strong> <?php echo nl2br($h($i['pagamento_nota'])); ?></div><?php endif; ?>
                </div></div>
            <?php endif; ?>
            <div class="card border-0 shadow-sm"><div class="card-body d-flex flex-wrap gap-2">
                <form method="POST" class="m-0"><?php csrf_field(); ?><input type="hidden" name="incarico_id" value="<?php echo (int)$i['id']; ?>"><button type="submit" name="duplica" value="1" class="btn btn-sm btn-outline-secondary"><i class="fa fa-copy me-1" aria-hidden="true"></i>Crea una copia in bozza</button></form>
                <?php if (!in_array($i['stato'], ['protocollata', 'annullata'], true)): ?>
                    <form method="POST" class="m-0 d-flex gap-1"><?php csrf_field(); ?><input type="hidden" name="incarico_id" value="<?php echo (int)$i['id']; ?>">
                        <input class="form-control form-control-sm" name="motivo" placeholder="Motivo (facoltativo)" aria-label="Motivo dell'annullamento">
                        <button type="submit" name="annulla" value="1" class="btn btn-sm btn-outline-danger" data-confirm="Annullare la lettera? I link inviati smettono di funzionare.">Annulla</button></form>
                <?php endif; ?>
            </div></div>
        </div>
    </div>

<?php elseif ($bando_sel && !empty($_GET['importa'])):
    $b = $bando_sel; $an = $anteprima ?? null; ?>
    <nav class="small mb-2"><a href="<?php echo $base; ?>&amp;bando=<?php echo (int)$b['id']; ?>" class="text-decoration-none"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i><?php echo $h($b['titolo']); ?></a></nav>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <h5 class="fw-bold">Importa i vincitori dal decreto di approvazione atti</h5>
        <p class="small text-secondary mb-2">Carica il Word (.docx) del decreto: il portale legge la tabella dei vincitori dell'<strong>ART. 2</strong> (cognome, nome, corso di laurea, insegnamento, ore, periodo, compenso), abbina ogni riga a un <strong>insegnamento dell'anagrafe di Ateneo</strong> (con codice, corso e <strong>docente titolare</strong>) e crea le lettere in <strong>bozza</strong>. Nel decreto non ci sono codice fiscale ed email: si completano qui sotto o dopo, nella lettera.</p>
        <form method="POST" enctype="multipart/form-data" class="d-flex flex-wrap gap-2 align-items-end"><?php csrf_field(); ?><input type="hidden" name="bando_id" value="<?php echo (int)$b['id']; ?>">
            <div><label class="form-label small fw-bold mb-0" for="gradFile">Decreto in Word (.docx)</label><input type="file" class="form-control form-control-sm" id="gradFile" name="graduatoria" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required></div>
            <button type="submit" name="anteprima_graduatoria" value="1" class="btn btn-primary fw-bold"><i class="fa fa-magnifying-glass me-1" aria-hidden="true"></i>Leggi e mostra l'anteprima</button></form>
    </div></div>
    <?php if ($an && $an['errore']): ?><div class="alert alert-danger"><?php echo $h($an['errore']); ?></div>
    <?php elseif ($an && $an['righe']): ?>
        <?php if ($an['decreti']): ?><p class="small text-secondary">Dal decreto: bando D.D. n. <?php echo $h($an['decreti']['bando'] ?? ''); ?><?php echo !empty($an['decreti']['commissione']) ? ' · commissione D.D. n. ' . $h($an['decreti']['commissione']) : ''; ?> (nel bando: D.D. n. <?php echo $h($b['decreto_bando']); ?>).</p><?php endif; ?>
        <form method="POST"><?php csrf_field(); ?><input type="hidden" name="bando_id" value="<?php echo (int)$b['id']; ?>">
        <div class="alert alert-info small py-2"><?php echo count($an['righe']); ?> vincitori letti. Controlla l'<strong>insegnamento</strong> abbinato (cambiandolo cambia anche il docente titolare, che puoi correggere), il genere (M/F) e completa codice fiscale ed email se li hai.</div>
        <div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-sm align-middle small mb-0" style="min-width:1250px;">
            <thead class="table-light"><tr><th></th><th>Vincitore</th><th>Corso e insegnamento (anagrafe di Ateneo)</th><th>Ore · periodo · compenso</th><th>Docente titolare</th></tr></thead><tbody>
            <?php foreach ($an['righe'] as $n => $r): $p = "r[$n]"; ?>
                <tr<?php echo $r['duplicato'] ? ' class="table-secondary"' : ''; ?>>
                    <td><input type="checkbox" class="form-check-input" name="<?php echo $p; ?>[includi]" value="1"<?php echo $r['duplicato'] ? '' : ' checked'; ?> aria-label="Importa la riga <?php echo $n + 1; ?>"><?php echo $r['duplicato'] ? '<div class="text-secondary">già presente</div>' : ''; ?></td>
                    <td style="min-width:260px;"><div class="d-flex gap-1 mb-1"><input class="form-control form-control-sm" name="<?php echo $p; ?>[cognome]" value="<?php echo $h($r['cognome']); ?>" placeholder="Cognome" aria-label="Cognome" maxlength="100"><input class="form-control form-control-sm" name="<?php echo $p; ?>[nome]" value="<?php echo $h($r['nome']); ?>" placeholder="Nome" aria-label="Nome" maxlength="100">
                            <select class="form-select form-select-sm" style="width:68px;" name="<?php echo $p; ?>[genere]" aria-label="Genere"><option>M</option><option>F</option></select></div>
                        <div class="d-flex gap-1"><input class="form-control form-control-sm text-uppercase" name="<?php echo $p; ?>[codice_fiscale]" placeholder="Codice fiscale" aria-label="Codice fiscale" maxlength="16"><input type="email" class="form-control form-control-sm" name="<?php echo $p; ?>[email]" placeholder="Email" aria-label="Email"></div>
                        <div class="text-secondary mt-1"><?php echo $h($r['ambito']); ?></div></td>
                    <td style="min-width:330px;"><div class="text-secondary mb-1">Nel decreto: <?php echo $h($r['corso'] . ' – ' . $r['insegnamento']); ?></div>
                        <input type="hidden" name="<?php echo $p; ?>[ambito]" value="<?php echo $h($r['ambito']); ?>"><input type="hidden" name="<?php echo $p; ?>[corso]" value="<?php echo $h($r['corso']); ?>"><input type="hidden" name="<?php echo $p; ?>[insegnamento]" value="<?php echo $h($r['insegnamento']); ?>">
                        <select class="form-select form-select-sm ins-sel" name="<?php echo $p; ?>[insegnamento_id]" aria-label="Insegnamento dell'anagrafe">
                            <?php foreach ($r['candidati'] as $c): $pe = $c['persona'] ?? null; ?>
                                <option value="<?php echo (int)$c['id']; ?>"<?php echo (int)$c['id'] === (int)$r['insegnamento_id'] ? ' selected' : ''; ?> data-pid="<?php echo $h($pe['persona_id'] ?? ''); ?>" data-dn="<?php echo $h($pe['nome'] ?? ''); ?>" data-dc="<?php echo $h($pe['cognome'] ?? $c['docente']); ?>" data-de="<?php echo $h($pe['email'] ?? ''); ?>"><?php echo $h($c['codice'] . ' · ' . $c['nome'] . ' · ' . $c['cds_nome'] . ($c['docente'] !== '' ? ' · ' . $c['docente'] : '')); ?></option>
                            <?php endforeach; ?>
                            <option value="0"<?php echo $r['candidati'] ? '' : ' selected'; ?> data-pid="" data-dn="" data-dc="" data-de="">Nessuno: uso il testo del decreto</option>
                        </select><?php if (!$r['candidati']): ?><div class="text-danger mt-1">Nessun insegnamento simile in anagrafe: resta il testo del decreto.</div><?php endif; ?></td>
                    <td style="min-width:250px;"><div class="d-flex gap-1 mb-1"><input class="form-control form-control-sm" style="width:70px;" name="<?php echo $p; ?>[ore]" value="<?php echo $h($r['ore']); ?>" aria-label="Ore"><input class="form-control form-control-sm" style="width:110px;" name="<?php echo $p; ?>[compenso]" value="<?php echo $h($r['compenso_decreto'] !== null ? number_format((float)$r['compenso_decreto'], 2, ',', '') : ($r['compenso_calcolato'] !== null ? number_format($r['compenso_calcolato'], 2, ',', '') : '')); ?>" aria-label="Compenso in lettera"></div>
                        <input class="form-control form-control-sm" name="<?php echo $p; ?>[periodo]" value="<?php echo $h($r['periodo']); ?>" aria-label="Periodo"><input type="hidden" name="<?php echo $p; ?>[data_fine]" value="<?php echo $h($r['data_fine']); ?>">
                        <?php if ($r['compenso_diverso']): ?><div class="text-warning-emphasis mt-1"><i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i>Il decreto scrive € <?php echo number_format((float)$r['compenso_decreto'], 2, ',', '.'); ?>; ore × 25 € ÷ 1,24 = € <?php echo number_format($r['compenso_calcolato'], 2, ',', '.'); ?>. Scegli quale tenere.</div><?php endif; ?></td>
                    <td style="min-width:230px;"><input type="hidden" name="<?php echo $p; ?>[docente_persona_id]" class="d-pid" value="<?php echo $h($r['docente']['persona_id'] ?? ''); ?>">
                        <div class="d-flex gap-1 mb-1"><input class="form-control form-control-sm d-cog" name="<?php echo $p; ?>[docente_cognome]" value="<?php echo $h($r['docente']['cognome'] ?? $r['docente_testo']); ?>" placeholder="Cognome" aria-label="Cognome del docente"><input class="form-control form-control-sm d-nom" name="<?php echo $p; ?>[docente_nome]" value="<?php echo $h($r['docente']['nome'] ?? ''); ?>" placeholder="Nome" aria-label="Nome del docente"></div>
                        <input type="email" class="form-control form-control-sm d-ema" name="<?php echo $p; ?>[docente_email]" value="<?php echo $h($r['docente']['email'] ?? ''); ?>" placeholder="Email del docente" aria-label="Email del docente">
                        <?php if (!$r['docente'] && $r['docente_testo'] !== ''): ?><div class="text-secondary mt-1">Nell'anagrafe degli insegnamenti: <?php echo $h($r['docente_testo']); ?>. Completa nome ed email.</div><?php endif; ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div></div>
        <p class="mt-3"><button type="submit" name="importa_graduatoria" value="1" class="btn btn-success fw-bold" data-confirm="Creare le lettere in bozza per le righe selezionate?"><i class="fa fa-file-import me-1" aria-hidden="true"></i>Crea le lettere in bozza</button>
            <a class="btn btn-outline-secondary" href="<?php echo $base; ?>&amp;bando=<?php echo (int)$b['id']; ?>">Annulla</a></p>
        </form>
        <script>
        // Cambiando l'insegnamento si propone il docente titolare di quell'insegnamento (resta modificabile)
        document.querySelectorAll('.ins-sel').forEach(function (s) {
            s.addEventListener('change', function () {
                var o = s.options[s.selectedIndex], tr = s.closest('tr');
                tr.querySelector('.d-pid').value = o.dataset.pid || ''; tr.querySelector('.d-cog').value = o.dataset.dc || '';
                tr.querySelector('.d-nom').value = o.dataset.dn || ''; tr.querySelector('.d-ema').value = o.dataset.de || '';
            });
        });
        </script>
    <?php endif; ?>

<?php elseif ($bando_sel):
    $b = $bando_sel;
    $inc =\App\Core\App::per($conn)->get(\App\Tutorato\ServizioIncarichi::class)->delBando((int)$b['id']);
?>
    <nav class="small mb-2"><a href="<?php echo $base; ?>" class="text-decoration-none"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i>Bandi</a></nav>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body d-flex flex-wrap gap-2 align-items-start">
        <div class="flex-grow-1"><h5 class="fw-bold mb-0"><?php echo $h($b['titolo']); ?></h5>
            <div class="small text-secondary">a.a. <?php echo $h($b['anno_accademico']); ?> · bando D.D. n. <?php echo $h(decreto_testo($b['decreto_bando'], $b['decreto_bando_data'])); ?><?php echo $b['decreto_commissione'] !== '' ? ' · atti D.D. n. ' . $h(decreto_testo($b['decreto_commissione'], $b['decreto_commissione_data'])) : ''; ?> · Direttore <?php echo $h($b['direttore_nome']); ?></div></div>
        <a class="btn btn-sm btn-outline-secondary" href="<?php echo $base; ?>&amp;bando=<?php echo (int)$b['id']; ?>&amp;modifica=1"><i class="fa fa-pen me-1" aria-hidden="true"></i>Modifica il bando</a>
        <a class="btn btn-sm btn-outline-primary fw-bold" href="<?php echo $base; ?>&amp;bando=<?php echo (int)$b['id']; ?>&amp;importa=1"><i class="fa fa-file-import me-1" aria-hidden="true"></i>Importa dalla graduatoria (Word)</a>
        <a class="btn btn-sm btn-primary fw-bold" href="<?php echo $base; ?>&amp;nuovo_incarico=1&amp;bando=<?php echo (int)$b['id']; ?>"><i class="fa fa-user-plus me-1" aria-hidden="true"></i>Nuovo vincitore</a>
    </div></div>
    <?php $uffici_ateneo_b = \App\Core\App::per($conn)->get(\App\Ateneo\ServizioUfficiAteneo::class)->elenco(true);
          $dec_b = [['bando', 'decreto_bando_file', 'Decreto del bando', decreto_testo($b['decreto_bando'], $b['decreto_bando_data'])], ['commissione', 'decreto_commissione_file', 'Decreto della commissione', decreto_testo($b['decreto_commissione'], $b['decreto_commissione_data'])],
                    ['atti', 'decreto_atti_file', 'Decreto di approvazione atti', decreto_testo($b['decreto_atti'] ?? '', $b['decreto_atti_data'] ?? null)]]; ?>
    <details class="card border-0 shadow-sm mb-3"<?php echo (empty($b['decreto_atti_file']) || empty($b['ufficio_ateneo_id'])) ? ' open' : ''; ?>><summary class="card-body fw-bold py-2" style="cursor:pointer;"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>Decreti in PDF e ufficio pagamenti
        <?php if (empty($b['decreto_bando_file']) || empty($b['decreto_commissione_file']) || empty($b['decreto_atti_file']) || empty($b['ufficio_ateneo_id'])): ?><span class="badge bg-warning text-dark ms-1">da completare</span><?php else: ?><span class="badge bg-success ms-1">completo</span><?php endif; ?></summary>
        <div class="card-body pt-0">
        <form method="POST" enctype="multipart/form-data" class="row g-2"><?php csrf_field(); ?><input type="hidden" name="bando_id" value="<?php echo (int)$b['id']; ?>">
            <?php foreach ($dec_b as [$t_d, $col_d, $nome_d, $est_d]): ?>
                <div class="col-md-4"><label class="form-label small fw-bold mb-0" for="dec<?php echo $t_d; ?>"><?php echo $h($nome_d); ?><?php echo $est_d !== '' ? ' <span class="text-secondary fw-normal">· ' . $h($est_d) . '</span>' : ''; ?></label>
                    <input type="file" class="form-control form-control-sm" id="dec<?php echo $t_d; ?>" name="decreto_<?php echo $t_d; ?>" accept=".pdf,application/pdf">
                    <div class="form-text"><?php if (!empty($b[$col_d])): ?><span class="text-success"><i class="fa fa-circle-check" aria-hidden="true"></i> PDF caricato</span> · <a href="documento_tutorato.php?b=<?php echo (int)$b['id']; ?>&amp;f=decreto_<?php echo $t_d; ?>" target="_blank" rel="noopener">apri</a> (carica solo per sostituirlo)<?php else: ?><span class="text-danger">manca il PDF</span><?php endif; ?></div></div>
            <?php endforeach; ?>
            <div class="col-md-3"><label class="form-label small fw-bold mb-0" for="decAttiN">Approvazione atti: D.D. n.</label><input class="form-control form-control-sm" id="decAttiN" name="decreto_atti" value="<?php echo $h($b['decreto_atti'] ?? ''); ?>" maxlength="100" placeholder="es. 200/2026"></div>
            <div class="col-md-3"><label class="form-label small fw-bold mb-0" for="decAttiD">del</label><input type="date" class="form-control form-control-sm" id="decAttiD" name="decreto_atti_data" value="<?php echo $h($b['decreto_atti_data'] ?? ''); ?>"></div>
            <div class="col-md-6"><label class="form-label small fw-bold mb-0" for="bUff">Ufficio di Ateneo che paga i tutor di questo bando</label>
                <select class="form-select form-select-sm" id="bUff" name="ufficio_ateneo_id"><option value="0">Nessuno (da indicare prima di passare le pratiche)</option>
                    <?php foreach ($uffici_ateneo_b as $ua): ?><option value="<?php echo (int)$ua['id']; ?>"<?php echo (int)($b['ufficio_ateneo_id'] ?? 0) === (int)$ua['id'] ? ' selected' : ''; ?>><?php echo $h($ua['nome']); ?></option><?php endforeach; ?></select>
                <?php if (!$uffici_ateneo_b): ?><div class="form-text text-danger">Nessun ufficio: l'amministratore lo crea in Gestione del portale › Uffici di Ateneo.</div><?php endif; ?></div>
            <div class="col-12"><button type="submit" name="salva_decreti" value="1" class="btn btn-sm btn-primary fw-bold"><i class="fa fa-save me-1" aria-hidden="true"></i>Salva decreti e ufficio</button></div>
        </form></div></details>
    <div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-sm align-middle small mb-0">
        <thead class="table-light"><tr><th>Vincitore</th><th>Attività</th><th>Responsabile</th><th>Stato</th><th>Aggiornata</th></tr></thead><tbody>
        <?php if (!$inc): ?><tr><td colspan="5"><?php echo pn_vuoto("Nessuna lettera: aggiungi i vincitori con «Nuovo vincitore».", "fa-envelope-open-text"); ?></td></tr><?php endif; ?>
        <?php foreach ($inc as $x): ?>
            <tr><td><a class="fw-bold text-decoration-none" href="<?php echo $base; ?>&amp;incarico=<?php echo (int)$x['id']; ?>"><?php echo $h(trim($x['cognome'] . ' ' . $x['nome'])); ?></a><div class="text-secondary font-monospace" style="font-size:.7rem;"><?php echo $h($x['codice']); ?></div></td>
                <td><?php echo $h(mb_strimwidth((string)$x['attivita'], 0, 80, '…')); ?><div class="text-secondary"><?php echo $h(rtrim(rtrim((string)$x['ore'], '0'), '.') . ' ore · € ' . number_format((float)$x['compenso'], 2, ',', '.')); ?></div></td>
                <td><?php echo $h(trim($x['docente_nome'] . ' ' . $x['docente_cognome'])); ?></td>
                <td><?php echo badge_stato_incarico($x['stato']); ?><?php if ($x['stato'] === 'bozza' && ($mx = \App\Tutorato\ServizioIncarichi::datiMancanti($x))) echo ' <span class="badge bg-warning text-dark" title="' . $h('Mancano: ' . implode(', ', $mx)) . '">dati da completare</span>'; ?><?php echo ($x['pagamento_stato'] ?? '') === 'pagata' ? ' <span class="badge bg-success">pagato</span>' : (($x['pagamento_stato'] ?? '') === 'in_ufficio' ? ' <span class="badge bg-primary">all\'ufficio pagamenti</span>' : (($x['pagamento_stato'] ?? '') === 'rimandata' ? ' <span class="badge bg-warning text-dark">rimandata dall\'ufficio</span>' : '')); ?><?php echo ($x['fine_stato'] ?? '') !== '' ? ' <span class="badge" style="background:' . STATI_FINE_ATTIVITA[$x['fine_stato']][1] . ';">' . ($x['fine_stato'] === 'protocollata' ? 'fine protocollata' : ($x['fine_stato'] === 'firmata' ? 'attività completate' : 'fine attività')) . '</span>' : ''; ?><?php echo $x['nota_studente'] && $x['stato'] === 'inviata' ? ' <span class="badge bg-warning text-dark">segnalazione</span>' : ''; ?></td>
                <td class="text-nowrap"><?php echo date('d/m/Y H:i', strtotime($x['aggiornata_il'] ?: $x['creata_il'])); ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div></div>

<?php else:
    $bandi = bandi_tutorato($conn);
?>
    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <a href="<?php echo $base; ?>&amp;nuovo_bando=1" class="btn btn-primary btn-sm fw-bold"><i class="fa fa-plus-circle me-1" aria-hidden="true"></i>Nuovo bando</a>
        <span class="small text-secondary">Per ogni bando: vincitori con i dati della lettera → conferma dello studente con SPID/CIE → firma PAdES del docente responsabile → firma PAdES del direttore → protocollo.</span>
    </div>
    <div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-sm align-middle small mb-0">
        <thead class="table-light"><tr><th>Bando</th><th>a.a.</th><th>Decreto</th><th class="text-center">Lettere</th><th class="text-center">Firmate</th></tr></thead><tbody>
        <?php if (!$bandi): ?><tr><td colspan="5"><?php echo pn_vuoto("Nessun bando: creane uno per inserire i vincitori e preparare le lettere.", "fa-user-graduate"); ?></td></tr><?php endif; ?>
        <?php foreach ($bandi as $b): ?>
            <tr><td><a class="fw-bold text-decoration-none" href="<?php echo $base; ?>&amp;bando=<?php echo (int)$b['id']; ?>"><?php echo $h($b['titolo']); ?></a></td><td><?php echo $h($b['anno_accademico']); ?></td>
                <td><?php echo $h(decreto_testo($b['decreto_bando'], $b['decreto_bando_data'])); ?></td><td class="text-center"><?php echo (int)$b['n_incarichi']; ?></td><td class="text-center"><?php echo (int)$b['n_firmate']; ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div></div>
<?php endif; ?>

<?php require_once 'admin_footer.php'; ?>
