<?php
// didattica_pratiche.php - Pratiche degli studenti: elenco con filtri, dettaglio, iter, istruttoria, integrazioni, messaggi, Excel e Word.
// Scheda del pannello Didattica: si apre da didattica.php?tab=pratiche (stesso indirizzo di sempre), mai direttamente.
// $fase = 'azioni': gestisce i POST della scheda (ognuno risponde con un rimando e termina); $fase = 'vista': la pagina.
if (!defined('DIDATTICA_PANNELLO')) { http_response_code(403); exit('Accesso negato.'); }

if ($fase === 'azioni') {
    if (isset($_POST['richiedi_operatore'])) {
        $id = (int)$_POST['pratica_id'];
        $err = richiedi_a_operatore($conn, $id, (int)($_POST['operatore_prec'] ?? 0), (string)($_POST['testo_operatore'] ?? ''), $uid, $autore_nome);
        flash_set($err ?? "Richiesta inviata: l'operatore riceve un'email e integra la pratica.", $err ? 'danger' : 'success');
        admin_redirect("$base&tab=pratiche&id=$id&r=" . time());
    }

    // ── Pratiche: stato, integrazioni, messaggi, attività, iter, istruttoria, seduta ──
    if (isset($_POST['stato_pratica'])) {
        $id = (int)$_POST['pratica_id'];
        $ok = cambia_stato_pratica($conn, $id, (string)$_POST['stato_pratica'], mb_substr(trim((string)($_POST['nota'] ?? '')), 0, 2000), $uid, $autore_nome);
        if ($ok) registra_log_audit($conn, "Pratica: cambio di stato", ["Pratica" => $id, "Stato" => $_POST['stato_pratica']]);
        flash_set($ok ? (in_array($_POST['stato_pratica'], ['accolta', 'respinta', 'chiusa'], true) ? "Stato aggiornato: lo studente riceve un'email." : "Stato aggiornato.") : "Stato non valido.", $ok ? 'success' : 'danger');
        admin_redirect("$base&tab=pratiche&id=$id&r=" . time());
    }
    if (isset($_POST['richiedi_integrazione'])) {
        $id = (int)$_POST['pratica_id'];
        $testo_r = mb_substr(trim((string)($_POST['testo_richiesta'] ?? '')), 0, 3000);
        if ($testo_r === '') flash_set("Scrivi cosa deve integrare o dichiarare lo studente.", 'danger');
        else {
            cambia_stato_pratica($conn, $id, 'integrazione', '', $uid, $autore_nome, ['tipo' => (string)($_POST['tipo_richiesta'] ?? 'documenti'), 'testo' => $testo_r]);
            registra_log_audit($conn, "Pratica: integrazione richiesta", ["Pratica" => $id, "Tipo" => $_POST['tipo_richiesta'] ?? '']);
            flash_set("Richiesta inviata allo studente: la vede nella pratica e riceve un'email.");
        }
        admin_redirect("$base&tab=pratiche&id=$id&r=" . time());
    }
    if (isset($_POST['messaggio_pratica']) || isset($_POST['attivita_pratica'])) {
        $id = (int)$_POST['pratica_id'];
        $att = isset($_POST['attivita_pratica']);
        $interno = $att ? empty($_POST['visibile']) : !empty($_POST['interno']);
        $err = messaggio_pratica($conn, $id, 'ufficio', $uid, (string)($_POST['testo'] ?? ''), $_FILES['allegato'] ?? null, $interno, $att ? 'attivita' : 'messaggio', $autore_nome);
        flash_set($err ?? ($att ? "Attività aggiunta alla pratica." : ($interno ? "Nota interna salvata (lo studente non la vede)." : "Messaggio inviato allo studente.")), $err ? 'danger' : 'success');
        admin_redirect("$base&tab=pratiche&id=$id&r=" . time());
    }
    if (isset($_POST['assegna_pratica'])) {
        $id = (int)$_POST['pratica_id'];
        $err = assegna_pratica($conn, $id, (int)($_POST['operatore_id'] ?? 0), (int)($_POST['passo'] ?? 1), (string)($_POST['nota_passaggio'] ?? ''), $uid, $autore_nome);
        if (!$err) registra_log_audit($conn, "Pratica: assegnata", ["Pratica" => $id, "Operatore" => $_POST['operatore_id'] ?? '']);
        flash_set($err ?? "Pratica assegnata: l'operatore riceve un'email, lo studente vede il passaggio.", $err ? 'danger' : 'success');
        admin_redirect("$base&tab=pratiche&id=$id&r=" . time());
    }
    if (isset($_POST['salva_istruttoria'])) {
        $id = (int)$_POST['pratica_id'];
        $codice_p = \App\Core\App::per($conn)->get(\App\Didattica\ServizioPratiche::class)->salvaIstruttoria($id, $_POST, $_FILES);
        if ($codice_p !== null) {
            registra_log_audit($conn, "Pratica: istruttoria salvata", ["Pratica" => $codice_p]);
            flash_set("Istruttoria salvata.");
        }
        admin_redirect("$base&tab=pratiche&id=$id&r=" . time());
    }
    // ── Flusso con il protocollo: protocollo → ufficio del corso → convalide → segreteria studenti (inc/convalide.php) ──
    if (isset($_POST['registra_protocollo'])) {
        $id = (int)$_POST['pratica_id'];
        [$err, $msg] = registra_protocollo_pratica($conn, $id, (string)($_POST['protocollo'] ?? ''), (string)($_POST['protocollo_data'] ?? ''), $uid, $autore_nome, !empty($_POST['bollo']));
        if (!$err) registra_log_audit($conn, "Pratica: protocollo registrato", ["Pratica" => $id, "Protocollo" => $_POST['protocollo'] ?? '']);
        flash_set($err ?? $msg, $err ? 'danger' : (str_contains($msg, 'a mano') || str_contains($msg, 'bollo') ? 'warning' : 'success'));
        admin_redirect("$base&tab=pratiche&id=$id&r=" . time());
    }
    if (isset($_POST['rigenera_domanda'])) {
        $id = (int)$_POST['pratica_id'];
        $ok = genera_domanda_pratica($conn, $id, $uid, $autore_nome);
        flash_set($ok ? "Domanda in PDF/A generata di nuovo." : "Non è stato possibile generare la domanda.", $ok ? 'success' : 'danger');
        admin_redirect("$base&tab=pratiche&id=$id&r=" . time());
    }
    if (isset($_POST['prendi_pratica'])) {
        $id = (int)$_POST['pratica_id']; $p = pratica($conn, $id);
        $err = $p && $io_operatore ? assegna_pratica($conn, $id, (int)$io_operatore['id'], max(1, (int)$p['passo']), '', $uid, $autore_nome) : "Solo il personale dell'ufficio può prendere in carico la pratica.";
        flash_set($err ?? "Pratica presa in carico.", $err ? 'danger' : 'success');
        admin_redirect("$base&tab=pratiche&id=$id&r=" . time());
    }
    if (isset($_POST['salva_convalide'])) {
        $id = (int)$_POST['pratica_id']; $p = pratica($conn, $id);
        $m = $p ? modulo_didattica($conn, (int)$p['modulo_id']) : null; $tipo_d = decisione_modulo($m);
        if ($p && $tipo_d !== '') {
            salva_decisioni_seduta($conn, $id, (string)$p['esito_seduta'], leggi_decisioni_post($conn, $tipo_d, $_POST), null);
            registra_log_audit($conn, "Pratica: convalide istruite", ["Pratica" => $p['codice']]);
            flash_set($tipo_d === 'convalide' ? "Convalide salvate: vanno nel verbale della seduta (si possono ritoccare in seduta)." : "Decisioni salvate.");
        }
        admin_redirect("$base&tab=pratiche&id=$id&r=" . time() . "#convalide");
    }
    if (isset($_POST['invia_segreteria'])) {
        [$n, $escluse] = invia_pratiche_segreteria($conn, $ids_post(), (int)($_POST['segreteria_id'] ?? 0), $uid, $autore_nome);
        if ($n) registra_log_audit($conn, "Pratiche inviate alla segreteria studenti", ["Ufficio" => $_POST['segreteria_id'] ?? '', "Pratiche" => $n]);
        flash_set(($n ? "$n pratiche inviate alla segreteria studenti con l'estratto e il verbale della seduta." : "Nessuna pratica inviata.") . ($escluse ? ' Non inviate: ' . implode('; ', $escluse) . '.' : ''), $n ? ($escluse ? 'warning' : 'success') : 'danger');
        admin_redirect((string)($_POST['torna'] ?? "$base&tab=pratiche") . '&r=' . time());
    }
    if (isset($_POST['assegna_seduta'])) {
        $ids = $ids_post(); $sed = (int)($_POST['seduta_id'] ?? 0);
        if ($solo_ref) {
            // Referente: solo verso le sedute del suo consiglio e solo pratiche libere o già nelle sue sedute
            if ($sed && !$puo_seduta(seduta_didattica($conn, $sed))) nega_accesso();
            $mie_s = \App\Core\App::per($conn)->get(\App\Didattica\SedutaRepository::class)->idDeiConsigli($consigli_referente);
            $ids = array_values(array_filter($ids, function ($i) use ($conn, $mie_s, $sed) { $x = pratica($conn, $i); return $x && ($x['seduta_id'] === null ? $sed > 0 : in_array((int)$x['seduta_id'], array_map('intval', $mie_s), true)); }));
        }
        if ($ids && ($sed === 0 || seduta_didattica($conn, $sed))) {
            \App\Core\App::per($conn)->get(\App\Didattica\SedutaRepository::class)->portaPratiche($ids, $sed);
            flash_set(count($ids) . ($sed ? " pratiche portate alla seduta." : " pratiche tolte dalla seduta."));
        } else flash_set("Scegli le pratiche e la seduta.", 'warning');
        admin_redirect((string)($_POST['torna'] ?? "$base&tab=pratiche") . '&r=' . time());
    }

    return;
}
?>
<?php
    $id_sel = (int)($_GET['id'] ?? 0);
    $p = $id_sel ? pratica($conn, $id_sel) : null;
    if ($p):
        $risposte = json_decode((string)$p['risposte_json'], true) ?: [];
        $eventi = \App\Core\App::per($conn)->get(\App\Didattica\PraticaRepository::class)->eventi((int)$p['id'], false);
        $m_p = modulo_didattica($conn, (int)$p['modulo_id']);
        $c_uff = campi_ufficio(campi_modulo($m_p['campi_json'] ?? ''));
        $val_uff = [];
        foreach (json_decode((string)$p['ufficio_json'], true) ?: [] as $r) $val_uff[mb_strtolower($r['etichetta'])] = ($r['tipo'] ?? '') === 'tabella' ? ($r['righe'] ?? []) : (string)$r['valore'];
        $v_m = verbale_modulo($m_p ?: ['titolo' => $p['modulo_titolo']]);
        $passi = passi_pratica($m_p, $p);
        $uff_p = !empty($p['ufficio_id']) ? (uffici_didattica($conn)[(int)$p['ufficio_id']] ?? null) : null;
        $serve_bollo = config_domanda($m_p)['bollo'];
        $da_protocollare = $uff_p && ($uff_p['tipo'] ?? '') === 'protocollo' && ((string)$p['protocollo'] === '' || ($serve_bollo && empty($p['bollo_il'])));
        $ev_domanda = evento_domanda_pratica($conn, $p);
        $tipo_dec = decisione_modulo($m_p);
        $passo_dopo = min(count($passi) - 1, max(1, (int)$p['passo'] + 1));
        $o_carico = $p['assegnata_a'] ? operatore_ufficio($conn, (int)$p['assegnata_a']) : null;
        $conclusa = in_array($p['stato'], ['accolta', 'respinta', 'chiusa'], true);
        $richiesta = json_decode((string)($p['richiesta_json'] ?? ''), true) ?: null;
        $tipi_ev = ['richiesta' => ['fa-people-arrows', 'Richiesta a un operatore'], 'passaggio' => ['fa-route', 'Passaggio'], 'attivita' => ['fa-paperclip', 'Attività'], 'autodich' => ['fa-file-signature', 'Autodichiarazione'], 'messaggio' => ['fa-comment', ''], 'stato' => ['fa-flag', '']];
?>
    <nav class="small mb-2"><a href="<?php echo $base; ?>&amp;tab=pratiche" class="text-decoration-none"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i>Tutte le pratiche</a></nav>

    <div class="card border-0 shadow-sm mb-3" style="border-left:4px solid #0056B3 !important;"><div class="card-body">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
            <h6 class="fw-bold mb-0"><i class="fa fa-route me-1 text-primary" aria-hidden="true"></i>Iter della pratica</h6>
            <span class="small text-secondary"><?php echo $o_carico ? 'In carico a <strong>' . $h(etichetta_operatore($o_carico)) . '</strong>' . ($io_operatore && (int)$io_operatore['id'] === (int)$o_carico['id'] ? ' <span class="badge bg-success">a te</span>' : '') : ($conclusa ? 'Conclusa' : ($uff_p ? 'All\'ufficio <strong>' . $h($uff_p['nome']) . '</strong> <span class="badge bg-warning text-dark">da prendere in carico</span>' : '<span class="badge bg-warning text-dark">da smistare</span>')); ?>
                <?php if ($io_operatore && (int)$io_operatore['id'] !== (int)$p['assegnata_a'] && in_array((int)$io_operatore['id'], operatori_pratica($conn, (int)$p['id']), true)): ?> · <span class="badge bg-light text-dark border" title="Puoi aggiungere documenti e note: chi l'ha in carico viene avvisato"><i class="fa fa-clock-rotate-left me-1" aria-hidden="true"></i>l'hai avuta in carico</span><?php endif; ?></span>
        </div>
        <?php echo html_iter_pratica($conn, $p, $m_p); ?>
        <?php if (!$conclusa && $io_operatore && (int)$p['assegnata_a'] !== (int)$io_operatore['id'] && $uff_p && (int)$io_operatore['ufficio_id'] === (int)$uff_p['id']): ?>
            <form method="POST" class="mt-2"><?php csrf_field(); ?><input type="hidden" name="pratica_id" value="<?php echo (int)$p['id']; ?>">
                <button type="submit" name="prendi_pratica" value="1" class="btn btn-sm btn-success fw-bold"><i class="fa fa-hand me-1" aria-hidden="true"></i>Prendi in carico</button></form>
        <?php endif; ?>
        <?php if (!$conclusa): $sugg = operatori_suggeriti($conn, $p, $m_p, $passo_dopo); ?>
        <form method="POST" class="row g-2 align-items-end mt-2">
            <?php csrf_field(); ?><input type="hidden" name="pratica_id" value="<?php echo (int)$p['id']; ?>">
            <div class="col-md-3"><label class="form-label small fw-bold mb-0" for="itPasso"><?php echo $p['assegnata_a'] ? 'Passa al passo' : 'Smista al passo'; ?></label>
                <select class="form-select form-select-sm" id="itPasso" name="passo"><?php foreach ($passi as $i => $n): if ($i === 0) continue; ?><option value="<?php echo $i; ?>"<?php echo $i === $passo_dopo ? ' selected' : ''; ?>><?php echo $i . '. ' . $h($n); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label small fw-bold mb-0" for="itOp">Operatore</label>
                <select class="form-select form-select-sm" id="itOp" name="operatore_id" required><option value="">--</option>
                    <?php foreach ($sugg as $o): ?><option value="<?php echo (int)$o['id']; ?>"><?php echo ($o['_consigliato'] ? '★ ' : '') . $h(etichetta_operatore($o)); ?></option><?php endforeach; ?></select>
                <?php if (!$operatori): ?><div class="form-text text-danger">Aggiungi prima gli operatori in «Ufficio e ricevimento».</div><?php endif; ?></div>
            <div class="col-md-3"><label class="form-label small fw-bold mb-0" for="itNota">Nota per l'operatore (interna)</label><input type="text" class="form-control form-control-sm" id="itNota" name="nota_passaggio" maxlength="3000" placeholder="facoltativa"></div>
            <div class="col-md-2"><button type="submit" name="assegna_pratica" value="1" class="btn btn-sm btn-primary fw-bold w-100"><i class="fa fa-share me-1" aria-hidden="true"></i><?php echo $p['assegnata_a'] ? 'Passa' : 'Smista'; ?></button></div>
            <div class="col-12 small text-secondary">★ = persona dell'ufficio previsto per quel passo (e che segue il corso della pratica). Chi riceve la pratica ha un'email; lo studente vede il passaggio, non la nota.</div>
        </form>
        <?php endif; ?>
    </div></div>

    <?php if ($da_protocollare): ?>
    <form method="POST" class="card border-0 shadow-sm mb-3" style="border-left:4px solid #b45309 !important;"><div class="card-body">
        <?php csrf_field(); ?><input type="hidden" name="pratica_id" value="<?php echo (int)$p['id']; ?>">
        <h6 class="fw-bold mb-1"><i class="fa fa-stamp me-1" style="color:#b45309;" aria-hidden="true"></i><?php echo (string)$p['protocollo'] === '' ? 'Da protocollare' : 'Protocollata: in attesa della marca da bollo'; ?></h6>
        <p class="small text-secondary mb-2">1. Scarica la domanda in PDF/A e gli allegati · 2. protocollala nel sistema di Ateneo · 3. registra qui numero e data<?php echo $serve_bollo ? ' e spunta la marca da bollo quando risulta pagata' : ''; ?>: la pratica passa da sola all'ufficio del corso di studio<?php $c_pr = corso_pratica($p); $u_c = $c_pr !== '' ? ufficio_del_corso($conn, $c_pr) : null; echo $c_pr !== '' ? ' (<strong>' . $h($c_pr) . '</strong> → ' . ($u_c ? $h(uffici_didattica($conn)[$u_c]['nome']) : '<span class="text-danger">nessun ufficio ha questo corso: indica i corsi nel consiglio</span>') . ')' : ''; ?>.</p>
        <div class="row g-2 align-items-end">
            <div class="col-md-4"><?php if ($ev_domanda): ?><a class="btn btn-sm btn-outline-danger fw-bold w-100" href="../allegato_pratica.php?p=<?php echo (int)$p['id']; ?>&amp;e=<?php echo $ev_domanda; ?>"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>Scarica la domanda (PDF/A)</a><?php else: ?><button type="submit" name="rigenera_domanda" value="1" class="btn btn-sm btn-outline-secondary w-100">Genera la domanda in PDF/A</button><?php endif; ?></div>
            <div class="col-md-3"><label class="form-label small fw-bold mb-0" for="prNum">Numero di protocollo</label><input class="form-control form-control-sm" id="prNum" name="protocollo" value="<?php echo $h($p['protocollo']); ?>" maxlength="100" placeholder="es. 12345" required></div>
            <div class="col-md-2"><label class="form-label small fw-bold mb-0" for="prData">Data</label><input type="date" class="form-control form-control-sm" id="prData" name="protocollo_data" value="<?php echo $h($p['protocollo_data'] ?: date('Y-m-d')); ?>" max="<?php echo date('Y-m-d'); ?>" required></div>
            <div class="col-md-3"><button type="submit" name="registra_protocollo" value="1" class="btn btn-sm fw-bold text-white w-100" style="background:#b45309;"><i class="fa fa-share me-1" aria-hidden="true"></i><?php echo $serve_bollo ? 'Registra' : 'Registra e trasmetti'; ?></button></div>
            <?php if ($serve_bollo): ?><div class="col-12"><label class="form-check small fw-bold m-0"><input class="form-check-input" type="checkbox" name="bollo" value="1"> Marca da bollo pagata dallo studente (verificata su Esse3 / PagoPA): con il protocollo la pratica viene trasmessa</label>
                <div class="form-text">Senza la spunta la pratica resta qui, protocollata, finché il bollo non risulta pagato.</div></div><?php endif; ?>
        </div>
    </div></form>
    <?php endif; ?>

    <?php if ($tipo_dec !== ''): $dec_p = decisioni_pratica($p, $tipo_dec, campi_modulo($m_p['campi_json'] ?? '')); $ins_dip = insegnamenti_dipartimento_scelta($conn); ?>
    <form method="POST" class="card border-0 shadow-sm mb-3 dd-dec" id="convalide" data-tipo="<?php echo $h($tipo_dec); ?>" style="border-left:4px solid #7c3aed !important;"><div class="card-body">
        <?php csrf_field(); ?><input type="hidden" name="pratica_id" value="<?php echo (int)$p['id']; ?>">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-1">
            <h6 class="fw-bold mb-0"><i class="fa fa-right-left me-1" style="color:#7c3aed;" aria-hidden="true"></i><?php echo $tipo_dec === 'convalide' ? 'Convalide: richiesto dallo studente → convalidato con' : 'Piano di studi: insegnamenti richiesti'; ?></h6>
            <?php echo !empty($dec_p['_proposte']) && $dec_p['righe'] ? '<span class="badge bg-warning text-dark">proposte dalla domanda, da completare</span>' : ($dec_p['righe'] ? '<span class="badge bg-success">salvate</span>' : ''); ?>
        </div>
        <p class="small text-secondary mb-2">Per ogni esame scegli l'insegnamento dell'anagrafe del Dipartimento con cui si convalida: la tabella va nel verbale della seduta (dove si può ritoccare) e nell'estratto per lo studente<?php echo $tipo_dec === 'convalide' ? ', con le richieste sul piano di studi' : ''; ?>.</p>
        <?php echo html_editor_decisioni($tipo_dec, $dec_p, $ins_dip); ?>
        <?php foreach (frasi_piano_decisioni($dec_p) as $fr): ?><div class="small"><i class="fa fa-list-check me-1 text-secondary" aria-hidden="true"></i><?php echo $h($fr); ?></div><?php endforeach; ?>
        <button type="submit" name="salva_convalide" value="1" class="btn btn-sm fw-bold text-white mt-2" style="background:#7c3aed;"><i class="fa fa-save me-1" aria-hidden="true"></i>Salva le convalide</button>
    </div></form>
    <?php echo html_supporto_decisioni($ins_dip); ?>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm mb-3"><div class="card-body">
                <div class="d-flex justify-content-between flex-wrap gap-2 mb-2">
                    <div><h5 class="fw-bold mb-0"><?php echo $h($p['modulo_titolo']); ?></h5><div class="small text-secondary font-monospace"><?php echo $h($p['codice']); ?> · inviata il <?php echo date('d/m/Y H:i', strtotime($p['creata_il'])); ?><?php echo ($p['protocollo'] ?? '') !== '' ? ' · prot. ' . $h($p['protocollo']) . (!empty($p['protocollo_data']) ? ' del ' . date('d/m/Y', strtotime($p['protocollo_data'])) : '') : ''; ?></div></div>
                    <div class="d-flex gap-1 align-items-start"><?php echo badge_stato_pratica($p['stato']); ?>
                        <?php if ($ev_domanda): ?><a class="btn btn-sm btn-outline-danger py-0" href="../allegato_pratica.php?p=<?php echo (int)$p['id']; ?>&amp;e=<?php echo $ev_domanda; ?>" title="Domanda in PDF/A<?php echo $p['domanda_sha'] ? ' – SHA-256 ' . $h($p['domanda_sha']) : ''; ?>"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>Domanda</a><?php endif; ?>
                        <a class="btn btn-sm btn-outline-success py-0" href="<?php echo $base; ?>&amp;tab=pratiche&amp;esporta=xlsx&amp;ids=<?php echo (int)$p['id']; ?>" title="Excel"><i class="fa fa-file-excel" aria-hidden="true"></i><span class="visually-hidden">Excel</span></a>
                        <a class="btn btn-sm btn-outline-primary py-0" href="<?php echo $base; ?>&amp;tab=pratiche&amp;esporta=docx&amp;ids=<?php echo (int)$p['id']; ?>" title="Word"><i class="fa fa-file-word" aria-hidden="true"></i><span class="visually-hidden">Word</span></a></div>
                </div>
                <p class="mb-3"><strong><?php echo $h(trim($p['cognome'] . ' ' . $p['nome'])); ?></strong> · <a href="mailto:<?php echo $h($p['email']); ?>"><?php echo $h($p['email']); ?></a><?php echo $p['matricola'] !== '' ? ' · matricola ' . $h($p['matricola']) : ''; ?></p>
                <dl class="row small mb-0">
                    <?php foreach ($risposte as $i => $r): if (!empty($r['nascosto'])) continue; ?>
                        <dt class="col-sm-4"><?php echo $h($r['etichetta']); ?></dt>
                        <dd class="col-sm-8"><?php if (!empty($r['file'])): ?><a href="../allegato_pratica.php?p=<?php echo (int)$p['id']; ?>&amp;r=<?php echo $i; ?>"><i class="fa fa-paperclip me-1" aria-hidden="true"></i><?php echo $h($r['nome_file']); ?></a><?php else: echo html_risposta_pratica($r); endif; ?></dd>
                    <?php endforeach; ?>
                </dl>
            </div></div>

            <form method="POST" class="card border-0 shadow-sm mb-3" style="border-left:4px solid #047857 !important;"><div class="card-body">
                <?php csrf_field(); ?><input type="hidden" name="pratica_id" value="<?php echo (int)$p['id']; ?>">
                <h6 class="fw-bold"><i class="fa fa-scale-balanced me-1 text-success" aria-hidden="true"></i>Istruttoria e verbale <span class="small text-secondary fw-normal">(non visibile allo studente)</span></h6>
                <?php echo html_datalist_didattica($conn, $c_uff); foreach ($c_uff as $c): $c['obbligatorio'] = false; echo html_campo_pratica($c, $val_uff[mb_strtolower($c['etichetta'])] ?? '', $conn); endforeach; ?>
                <div class="row g-2">
                    <div class="col-md-7"><label class="form-label small fw-bold" for="ddProt">Numero di protocollo</label><input class="form-control form-control-sm" id="ddProt" name="protocollo" value="<?php echo $h($p['protocollo'] ?? ''); ?>" maxlength="100" placeholder="es. 1234/2026"></div>
                    <div class="col-md-5"><label class="form-label small fw-bold" for="ddProtD">Data del protocollo</label><input type="date" class="form-control form-control-sm" id="ddProtD" name="protocollo_data" value="<?php echo $h($p['protocollo_data'] ?? ''); ?>"></div>
                    <div class="col-md-5"><label class="form-label small fw-bold" for="ddSed">Seduta del Consiglio</label>
                        <select class="form-select form-select-sm" id="ddSed" name="seduta_id"><option value="0">Nessuna</option>
                            <?php foreach ($sedute as $s): if (!in_array($s, $sedute_future, true) && (int)$s['id'] !== (int)$p['seduta_id']) continue; ?><option value="<?php echo (int)$s['id']; ?>"<?php echo (int)$p['seduta_id'] === (int)$s['id'] ? ' selected' : ''; ?>><?php echo $h(etichetta_seduta($s)); ?></option><?php endforeach; ?></select>
                        <div class="form-text"><a href="<?php echo $base; ?>&amp;tab=sedute&amp;nuova=1">Nuova seduta</a></div></div>
                    <div class="col-md-7"><label class="form-label small fw-bold" for="ddDel">Delibera nel verbale</label>
                        <textarea class="form-control form-control-sm" id="ddDel" name="delibera" rows="3" maxlength="5000" placeholder="<?php echo $h($v_m['delibera'] ?: 'Testo della delibera'); ?>"><?php echo $h($p['delibera']); ?></textarea>
                        <div class="form-text">Vuoto = testo predefinito del modulo. Puoi usare {STUDENTE}, {MATRICOLA} e le domande tra graffe.</div></div>
                </div>
                <button type="submit" name="salva_istruttoria" value="1" class="btn btn-sm btn-success fw-bold mt-2"><i class="fa fa-save me-1" aria-hidden="true"></i>Salva l'istruttoria</button>
            </div></form>
            <?php echo js_tabelle_pratica(); ?>

            <div class="card border-0 shadow-sm"><div class="card-body">
                <h6 class="fw-bold">Storico, attività e messaggi</h6>
                <?php foreach ($eventi as $e): [$ico_e, $lab_e] = $tipi_ev[$e['tipo']] ?? ['fa-circle', '']; ?>
                    <div class="dd-ev <?php echo $e['autore'] === 'ufficio' ? 'uff' : 'stu'; ?>"<?php echo (int)$e['interno'] ? ' style="background:#fffbeb;"' : ''; ?>>
                        <div class="small text-secondary"><?php echo date('d/m/Y H:i', strtotime($e['creato_il'])); ?> · <?php echo $e['autore'] === 'ufficio' ? $h($e['autore_nome'] ?: 'Ufficio') : 'Studente'; ?>
                            <?php if ($lab_e): ?> · <span class="badge bg-light text-dark border"><i class="fa <?php echo $ico_e; ?> me-1" aria-hidden="true"></i><?php echo $lab_e; ?></span><?php endif; ?>
                            <?php if ((int)$e['interno']): ?> · <span class="badge bg-warning text-dark"><i class="fa fa-lock me-1" aria-hidden="true"></i>interna</span><?php endif; ?>
                            <?php if ($e['stato']): ?> · <?php echo badge_stato_pratica((string)$e['stato']); ?><?php endif; ?></div>
                        <?php if ((string)$e['testo'] !== ''): ?><div class="small"><?php echo nl2br($h($e['testo'])); ?></div><?php endif; ?>
                        <?php if ($e['allegato']): ?><div class="small"><a href="../allegato_pratica.php?p=<?php echo (int)$p['id']; ?>&amp;e=<?php echo (int)$e['id']; ?>"><i class="fa fa-paperclip me-1" aria-hidden="true"></i><?php echo $h($e['nome_allegato']); ?></a></div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <form method="POST" enctype="multipart/form-data" class="mt-3 border-top pt-3">
                    <?php csrf_field(); ?><input type="hidden" name="pratica_id" value="<?php echo (int)$p['id']; ?>">
                    <label class="form-label small fw-bold" for="ddMsg">Messaggio allo studente o nota tra i referenti</label>
                    <textarea class="form-control form-control-sm mb-2" id="ddMsg" name="testo" rows="3" maxlength="5000"></textarea>
                    <div class="d-flex gap-2 flex-wrap align-items-center"><input type="file" name="allegato" class="form-control form-control-sm" style="max-width:280px;" accept=".pdf,.jpg,.jpeg,.png,.p7m" aria-label="Allegato (facoltativo)">
                        <label class="form-check small m-0"><input class="form-check-input" type="checkbox" name="interno" value="1"> <i class="fa fa-lock" aria-hidden="true"></i> nota interna (solo referenti)</label>
                        <button type="submit" name="messaggio_pratica" value="1" class="btn btn-sm btn-primary fw-bold"><i class="fa fa-paper-plane me-1" aria-hidden="true"></i>Invia</button></div>
                </form>
            </div></div>
        </div>
        <div class="col-lg-5">
            <form method="POST" enctype="multipart/form-data" class="card border-0 shadow-sm mb-3"><div class="card-body">
                <?php csrf_field(); ?><input type="hidden" name="pratica_id" value="<?php echo (int)$p['id']; ?>">
                <h6 class="fw-bold"><i class="fa fa-paperclip me-1" aria-hidden="true"></i>Aggiungi un'attività</h6>
                <p class="small text-secondary mb-2">Es. verbale del corso di studio, parere, documento istruttorio: resta nello storico della pratica.</p>
                <textarea class="form-control form-control-sm mb-2" name="testo" rows="2" maxlength="5000" placeholder="Cosa è stato fatto (es. Verbale del CdL del 15/10/2026)" aria-label="Descrizione dell'attività"></textarea>
                <input type="file" name="allegato" class="form-control form-control-sm mb-2" accept=".pdf,.jpg,.jpeg,.png,.p7m" aria-label="File dell'attività">
                <div class="d-flex flex-wrap gap-2 align-items-center"><label class="form-check small m-0"><input class="form-check-input" type="checkbox" name="visibile" value="1" checked> visibile allo studente</label>
                    <button type="submit" name="attivita_pratica" value="1" class="btn btn-sm btn-outline-primary fw-bold ms-auto"><i class="fa fa-plus me-1" aria-hidden="true"></i>Aggiungi</button></div>
            </div></form>

            <form method="POST" class="card border-0 shadow-sm mb-3" style="border-left:4px solid #b45309 !important;"><div class="card-body">
                <?php csrf_field(); ?><input type="hidden" name="pratica_id" value="<?php echo (int)$p['id']; ?>">
                <h6 class="fw-bold"><i class="fa fa-circle-exclamation me-1" style="color:#b45309;" aria-hidden="true"></i>Chiedi un'integrazione allo studente</h6>
                <?php if ($richiesta): ?><div class="alert alert-warning small py-1 px-2">In attesa: <?php echo $richiesta['tipo'] === 'autodichiarazione' ? 'autodichiarazione' : 'documenti'; ?> – <?php echo $h($richiesta['testo']); ?></div><?php endif; ?>
                <div class="d-flex gap-3 small mb-1">
                    <label class="form-check m-0"><input class="form-check-input" type="radio" name="tipo_richiesta" value="documenti" checked> Documenti</label>
                    <label class="form-check m-0"><input class="form-check-input" type="radio" name="tipo_richiesta" value="autodichiarazione"> Autodichiarazione</label>
                </div>
                <textarea class="form-control form-control-sm mb-2" name="testo_richiesta" rows="3" maxlength="3000" placeholder="Documenti: cosa allegare. Autodichiarazione: il testo che lo studente dichiara (es. di aver sostenuto l'esame di … il …)" aria-label="Testo della richiesta"></textarea>
                <button type="submit" name="richiedi_integrazione" value="1" class="btn btn-sm fw-bold text-white" style="background:#b45309;">Invia la richiesta</button>
                <p class="small text-secondary mt-2 mb-0">Lo studente riceve un'email e risponde dalla pratica allegando i file o rendendo l'autodichiarazione (D.P.R. 445/2000); poi la pratica torna «In lavorazione».</p>
            </div></form>

            <?php $prec = array_values(array_filter(array_map(fn($oid) => operatore_ufficio($conn, $oid), operatori_pratica($conn, (int)$p['id'])), fn($o) => $o && (int)$o['id'] !== (int)$p['assegnata_a']));
            if ($prec): ?>
            <form method="POST" class="card border-0 shadow-sm mb-3" style="border-left:4px solid #7c3aed !important;"><div class="card-body">
                <?php csrf_field(); ?><input type="hidden" name="pratica_id" value="<?php echo (int)$p['id']; ?>">
                <h6 class="fw-bold"><i class="fa fa-people-arrows me-1" style="color:#7c3aed;" aria-hidden="true"></i>Chiedi un'integrazione a chi l'ha avuta prima</h6>
                <p class="small text-secondary mb-2">Chi ha avuto la pratica nei passi precedenti continua a vederla: riceve un'email e aggiunge documenti o note; chi l'ha in carico viene avvisato.</p>
                <select class="form-select form-select-sm mb-2" name="operatore_prec" aria-label="Operatore"><?php foreach ($prec as $o): ?><option value="<?php echo (int)$o['id']; ?>"><?php echo $h(etichetta_operatore($o)); ?></option><?php endforeach; ?></select>
                <textarea class="form-control form-control-sm mb-2" name="testo_operatore" rows="2" maxlength="3000" placeholder="Cosa serve (es. il verbale del CdL firmato)" aria-label="Cosa serve"></textarea>
                <button type="submit" name="richiedi_operatore" value="1" class="btn btn-sm fw-bold text-white" style="background:#7c3aed;">Invia la richiesta</button>
            </div></form>
            <?php endif; ?>

            <div class="card border-0 shadow-sm"><div class="card-body">
                <h6 class="fw-bold">Cambia stato</h6>
                <form method="POST">
                    <?php csrf_field(); ?><input type="hidden" name="pratica_id" value="<?php echo (int)$p['id']; ?>">
                    <label class="form-label small fw-bold" for="ddNota">Nota per lo studente (facoltativa)</label>
                    <textarea class="form-control form-control-sm mb-2" id="ddNota" name="nota" rows="3" maxlength="2000" placeholder="Es. esito, motivo, prossimi passi"></textarea>
                    <div class="d-grid gap-1">
                        <?php foreach (STATI_PRATICA as $k => [$n, $col, $ico]): if ($k === $p['stato'] || in_array($k, ['inviata', 'integrazione'], true)) continue; ?>
                            <button type="submit" name="stato_pratica" value="<?php echo $k; ?>" class="btn btn-sm fw-bold text-white text-start" style="background:<?php echo $col; ?>;"><i class="fa <?php echo $ico; ?> me-1" aria-hidden="true"></i><?php echo $n; ?></button>
                        <?php endforeach; ?>
                    </div>
                    <p class="small text-secondary mt-2 mb-0">Lo studente riceve un'email per gli esiti (accolta, respinta, chiusa) e per le richieste di integrazione; i passaggi tra uffici gli arrivano quando la pratica passa di mano.</p>
                </form>
            </div></div>
        </div>
    </div>
    <?php else:
        $elenco = $elenco_pratiche();
        $qs_filtri = http_build_query(['stato' => $f_stato, 'modulo' => $f_mod ?: null, 'q' => $f_q ?: null, 'seduta' => $f_sed ?: null, 'dal' => $f_dal ?: null, 'al' => $f_al ?: null, 'carico' => $f_car ?: null]);
    ?>
    <div class="d-flex flex-wrap gap-1 mb-2">
        <?php foreach (['' => ['fa-inbox', 'Tutte'], 'smistare' => ['fa-shuffle', 'Da smistare'], 'ufficio' => ['fa-building-user', 'Del mio ufficio'], 'me' => ['fa-user-check', 'Assegnate a me'], 'seguite' => ['fa-clock-rotate-left', 'Passate ad altri uffici']] as $k_c => [$ico_c, $txt_c]): if (in_array($k_c, ['me', 'seguite', 'ufficio'], true) && !$io_operatore) continue; ?>
            <a class="btn btn-sm <?php echo $f_car === $k_c ? 'btn-dark' : 'btn-outline-dark'; ?>" href="<?php echo $base; ?>&amp;tab=pratiche&amp;carico=<?php echo $k_c; ?>"><i class="fa <?php echo $ico_c; ?> me-1" aria-hidden="true"></i><?php echo $txt_c; ?></a>
        <?php endforeach; ?>
    </div>
    <form method="GET" class="card border-0 shadow-sm mb-3"><div class="card-body row g-2 align-items-end">
        <input type="hidden" name="p_id" value="<?php echo (int)$filtro_p; ?>"><input type="hidden" name="tab" value="pratiche"><input type="hidden" name="carico" value="<?php echo $h($f_car); ?>">
        <div class="col-md-2"><label class="form-label small fw-bold mb-0" for="fSt">Stato</label><select class="form-select form-select-sm" id="fSt" name="stato">
            <option value="aperte"<?php echo $f_stato === 'aperte' ? ' selected' : ''; ?>>Aperte</option>
            <?php foreach (STATI_PRATICA as $k => [$n]): ?><option value="<?php echo $k; ?>"<?php echo $f_stato === $k ? ' selected' : ''; ?>><?php echo $n; ?></option><?php endforeach; ?>
            <option value="tutte"<?php echo $f_stato === 'tutte' ? ' selected' : ''; ?>>Tutte</option></select></div>
        <div class="col-md-3"><label class="form-label small fw-bold mb-0" for="fMod">Modulo</label><select class="form-select form-select-sm" id="fMod" name="modulo"><option value="0">Tutti</option>
            <?php foreach ($moduli as $m): if ($m['tipo'] !== 'online') continue; ?><option value="<?php echo (int)$m['id']; ?>"<?php echo $f_mod === (int)$m['id'] ? ' selected' : ''; ?>><?php echo $h($m['titolo']); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label small fw-bold mb-0" for="fSed">Seduta</label><select class="form-select form-select-sm" id="fSed" name="seduta"><option value="">Qualsiasi</option><option value="nessuna"<?php echo $f_sed === 'nessuna' ? ' selected' : ''; ?>>Senza seduta</option>
            <?php foreach ($sedute as $s): ?><option value="<?php echo (int)$s['id']; ?>"<?php echo $f_sed === (string)$s['id'] ? ' selected' : ''; ?>><?php echo $h(etichetta_seduta($s)); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-1"><label class="form-label small fw-bold mb-0" for="fDal">Dal</label><input type="date" class="form-control form-control-sm" id="fDal" name="dal" value="<?php echo $h($f_dal); ?>"></div>
        <div class="col-md-1"><label class="form-label small fw-bold mb-0" for="fAl">Al</label><input type="date" class="form-control form-control-sm" id="fAl" name="al" value="<?php echo $h($f_al); ?>"></div>
        <div class="col-md-2"><label class="form-label small fw-bold mb-0" for="fQ">Cerca</label><input type="search" class="form-control form-control-sm" id="fQ" name="q" value="<?php echo $h($f_q); ?>" placeholder="Nome, matricola, codice"></div>
        <div class="col-md-1"><button class="btn btn-sm btn-primary fw-bold w-100" aria-label="Filtra"><i class="fa fa-filter" aria-hidden="true"></i></button></div>
    </div></form>
    <form method="POST" id="ddElenco">
        <?php csrf_field(); ?><input type="hidden" name="torna" value="<?php echo $h("$base&tab=pratiche&$qs_filtri"); ?>">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
            <span class="small text-secondary"><?php echo count($elenco); ?> pratiche</span>
            <a class="btn btn-sm btn-outline-success fw-bold dd-esp" data-formato="xlsx" href="<?php echo $base; ?>&amp;tab=pratiche&amp;<?php echo $h($qs_filtri); ?>&amp;esporta=xlsx"><i class="fa fa-file-excel me-1" aria-hidden="true"></i>Excel</a>
            <a class="btn btn-sm btn-outline-primary fw-bold dd-esp" data-formato="docx" href="<?php echo $base; ?>&amp;tab=pratiche&amp;<?php echo $h($qs_filtri); ?>&amp;esporta=docx"><i class="fa fa-file-word me-1" aria-hidden="true"></i>Word (per il verbale)</a>
            <span class="small text-secondary">Esporta le pratiche filtrate o, se ne selezioni alcune, solo quelle.</span>
            <span class="ms-auto d-flex gap-1 align-items-center">
                <label class="small fw-bold" for="ddAss">Porta le selezionate alla seduta</label>
                <select class="form-select form-select-sm" id="ddAss" name="seduta_id" style="max-width:260px;"><option value="0">Nessuna (togli)</option>
                    <?php foreach ($sedute_future as $s): ?><option value="<?php echo (int)$s['id']; ?>"><?php echo $h(etichetta_seduta($s)); ?></option><?php endforeach; ?></select>
                <button type="submit" name="assegna_seduta" value="1" class="btn btn-sm btn-dark fw-bold">Assegna</button>
            </span>
        </div>
        <?php $segreterie = uffici_di_tipo($conn, 'segreteria'); ?>
        <div class="d-flex flex-wrap gap-1 align-items-center justify-content-end mb-2">
            <label class="small fw-bold" for="ddSeg"><i class="fa fa-paper-plane me-1" aria-hidden="true"></i>Invia le selezionate (esaminate in seduta) alla</label>
            <select class="form-select form-select-sm" id="ddSeg" name="segreteria_id" style="max-width:300px;"><?php if (!$segreterie): ?><option value="0">nessuna segreteria studenti: creala in «Ufficio e ricevimento»</option><?php endif; ?>
                <?php foreach ($segreterie as $k_s => $u_s): ?><option value="<?php echo (int)$k_s; ?>"><?php echo $h($u_s['nome']); ?></option><?php endforeach; ?></select>
            <button type="submit" name="invia_segreteria" value="1" class="btn btn-sm btn-success fw-bold"<?php echo $segreterie ? '' : ' disabled'; ?> data-confirm="Inviare le pratiche selezionate alla segreteria studenti? Ricevono l'estratto e il verbale della seduta; le persone della segreteria ricevono un'email di riepilogo.">Invia alla segreteria</button>
        </div>
        <div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-sm align-middle small mb-0">
            <thead class="table-light"><tr><th style="width:28px;"><input type="checkbox" class="form-check-input" id="ddTutte" aria-label="Seleziona tutte"></th><th>Pratica</th><th>Studente</th><th>Stato</th><th>In carico a</th><th>Seduta</th><th>Ultimo aggiornamento</th></tr></thead>
            <tbody>
            <?php if (!$elenco): ?><tr><td colspan="7" class="text-center text-muted py-4">Nessuna pratica con questi filtri.<?php echo !$moduli ? ' Crea prima un modulo online nella scheda "Moduli e documenti".' : ''; ?></td></tr><?php endif; ?>
            <?php foreach ($elenco as $x): ?>
                <tr>
                    <td><input type="checkbox" class="form-check-input dd-sel" name="ids[]" value="<?php echo (int)$x['id']; ?>" aria-label="Seleziona <?php echo $h($x['codice']); ?>"></td>
                    <td><a class="fw-bold text-decoration-none" href="<?php echo $base; ?>&amp;tab=pratiche&amp;id=<?php echo (int)$x['id']; ?>"><?php echo $h($x['modulo_titolo']); ?></a><div class="font-monospace text-secondary" style="font-size:.7rem;"><?php echo $h($x['codice']); ?></div></td>
                    <td><?php echo $h(trim($x['cognome'] . ' ' . $x['nome'])); ?><div class="text-secondary"><?php echo $h($x['email']); ?><?php echo $x['matricola'] !== '' ? ' · ' . $h($x['matricola']) : ''; ?></div></td>
                    <td><?php echo badge_stato_pratica($x['stato']); ?></td>
                    <td><?php $o_x = $x['assegnata_a'] ? operatore_ufficio($conn, (int)$x['assegnata_a']) : null; $u_x = !$o_x && $x['ufficio_id'] ? (uffici_didattica($conn)[(int)$x['ufficio_id']] ?? null) : null;
                        echo $o_x ? $h($o_x['nominativo']) . '<div class="text-secondary">' . $h(nome_ufficio_operatore($conn, $o_x)) . '</div>' : ($u_x ? $h($u_x['nome']) . '<div><span class="badge bg-warning text-dark">' . (($u_x['tipo'] ?? '') === 'protocollo' ? ((string)$x['protocollo'] === '' ? 'da protocollare' : 'attesa marca da bollo') : 'da prendere in carico') . '</span></div>' : (in_array($x['stato'], ['accolta', 'respinta', 'chiusa'], true) ? '—' : '<span class="badge bg-warning text-dark">da smistare</span>')); ?></td>
                    <td class="text-nowrap"><?php echo $x['seduta_data'] ? '<a href="' . $base . '&amp;tab=sedute&amp;id=' . (int)$x['seduta_id'] . '">' . date('d/m/Y', strtotime($x['seduta_data'])) . '</a>' : ($x['seduta_id'] ? 'sì' : '—'); ?></td>
                    <td class="text-nowrap"><?php echo date('d/m/Y H:i', strtotime($x['aggiornata_il'] ?: $x['creata_il'])); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div></div>
    </form>
    <script>
    (function () {
        var tutte = document.getElementById('ddTutte');
        if (tutte) tutte.addEventListener('change', function () { document.querySelectorAll('.dd-sel').forEach(function (c) { c.checked = tutte.checked; }); });
        document.querySelectorAll('.dd-esp').forEach(function (a) {
            a.addEventListener('click', function () {
                var ids = Array.prototype.map.call(document.querySelectorAll('.dd-sel:checked'), function (c) { return c.value; });
                a.href = a.href.replace(/&ids=[^&]*/, '') + (ids.length ? '&ids=' + ids.join(',') : '');
            });
        });
    })();
    </script>
    <?php endif; ?>

