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
    // ── Passo «attesa del pagamento»: marche da bollo caricate in Esse3, pagamento verificato + certificato da firmare ──
    if (isset($_POST['marche_caricate']) || isset($_POST['pagamento_registrato'])) {
        $id = (int)$_POST['pratica_id'];
        $marche = \App\Core\App::per($conn)->get(\App\Didattica\ServizioMarcheBollo::class);
        if (isset($_POST['marche_caricate'])) {
            $err = $marche->avvisaMarche($id, $uid, $autore_nome);
            if (!$err) registra_log_audit($conn, "Pratica: marche da bollo caricate", ["Pratica" => $id]);
            flash_set($err ?? "Lo studente è stato avvisato: può procedere al pagamento delle marche da bollo.", $err ? 'danger' : 'success');
        } else {
            [$err, $msg] = $marche->registraPagamento($id, $_FILES['certificato'] ?? null, !empty($_POST['pagato']), $uid, $autore_nome);
            if (!$err) registra_log_audit($conn, "Pratica: pagamento delle marche da bollo registrato", ["Pratica" => $id]);
            flash_set($err ?? $msg, $err ? 'danger' : 'success');
        }
        admin_redirect("$base&tab=pratiche&id=$id&r=" . time());
    }
    // ── Attestati: conferma dei docenti (rimanda i link), attestato controllato e mandato al Direttore, protocollo in uscita ──
    if (isset($_POST['rimanda_conferme']) || isset($_POST['attestato_al_direttore']) || isset($_POST['protocollo_uscita'])) {
        $id = (int)$_POST['pratica_id'];
        $att = \App\Core\App::per($conn)->get(\App\Didattica\ServizioAttestati::class);
        if (isset($_POST['rimanda_conferme'])) {
            $n_c = \App\Core\App::per($conn)->get(\App\Didattica\ServizioConfermeDocenti::class)->reinvia($id);
            flash_set($n_c ? "Email con il link di conferma rimandata ai docenti che non hanno ancora risposto ($n_c)." : 'Nessun docente in attesa.', $n_c ? 'success' : 'warning');
        } elseif (isset($_POST['attestato_al_direttore'])) {
            [$err, $msg] = $att->mandaAlDirettore($id, $uid, $autore_nome);
            if (!$err) registra_log_audit($conn, "Pratica: attestato mandato al Direttore", ["Pratica" => $id]);
            flash_set($err ?? $msg, $err ? 'danger' : 'success');
        } else {
            [$err, $msg] = $att->protocolloUscita($id, (string)($_POST['protocollo'] ?? ''), (string)($_POST['protocollo_data'] ?? ''), $_FILES['pdf_protocollato'] ?? null, $uid, $autore_nome);
            if (!$err) registra_log_audit($conn, "Pratica: protocollo in uscita registrato", ["Pratica" => $id, "Protocollo" => $_POST['protocollo'] ?? '']);
            flash_set($err ?? $msg, $err ? 'danger' : 'success');
        }
        admin_redirect("$base&tab=pratiche&id=$id&r=" . time());
    }
    // ── Passo «documento da firmare» (certificati senza marca da bollo): l'ufficio carica il PDF e la pratica passa al Direttore ──
    if (isset($_POST['documento_caricato'])) {
        $id = (int)$_POST['pratica_id'];
        [$err, $msg] = \App\Core\App::per($conn)->get(\App\Didattica\ServizioMarcheBollo::class)->caricaDocumento($id, $_FILES['documento'] ?? null, $uid, $autore_nome);
        if (!$err) registra_log_audit($conn, "Pratica: documento caricato e mandato alla firma", ["Pratica" => $id]);
        flash_set($err ?? $msg, $err ? 'danger' : 'success');
        admin_redirect("$base&tab=pratiche&id=$id&r=" . time());
    }
    // ── Iter: passo successivo e firme (persona indicata nel modulo, Direttore) ──
    if (isset($_POST['avanza_pratica']) || isset($_POST['invia_firma']) || isset($_POST['rimanda_firma'])) {
        $id = (int)$_POST['pratica_id']; $p = pratica($conn, $id);
        if (!$p) nega_accesso();
        if (isset($_POST['avanza_pratica'])) {
            [$err, $msg] = \App\Core\App::per($conn)->get(\App\Didattica\ServizioFlussoConvalide::class)->trasmettiAlPasso($id, (int)$p['passo'], $uid, $autore_nome);
            if (!$err) registra_log_audit($conn, "Pratica: passata al passo successivo", ["Pratica" => $p['codice']]);
        } elseif (isset($_POST['invia_firma'])) {
            $err = \App\Core\App::per($conn)->get(\App\Didattica\FirmaPratica::class)->avvia($id, max(1, (int)$p['passo'])); $msg = 'La pratica è stata mandata alla firma: chi deve firmare ha ricevuto l\'email.';
            if (!$err) registra_log_audit($conn, "Pratica: mandata alla firma", ["Pratica" => $p['codice']]);
        } else {
            $err = \App\Core\App::per($conn)->get(\App\Didattica\FirmaPratica::class)->reinvia($id); $msg = 'Email con il link di firma rimandata.';
        }
        flash_set($err ?? $msg, $err ? 'danger' : 'success');
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
        $svc_marche = \App\Core\App::per($conn)->get(\App\Didattica\ServizioMarcheBollo::class);
        $in_pagamento = !$conclusa && $svc_marche->inAttesa($p);
        $in_documento = !$conclusa && !$in_pagamento && $svc_marche->inAttesaDocumento($p);
        $svc_att = \App\Core\App::per($conn)->get(\App\Didattica\ServizioAttestati::class);
        $svc_conf = \App\Core\App::per($conn)->get(\App\Didattica\ServizioConfermeDocenti::class);
        $in_genera = !$conclusa && $svc_att->inGenera($p);
        $in_uscita = !$conclusa && $svc_att->inUscita($p);
        $conf_p = $svc_conf->dellaPratica((int)$p['id']);
        $richiesta = json_decode((string)($p['richiesta_json'] ?? ''), true) ?: null;
        $tipi_ev = ['richiesta' => ['fa-people-arrows', 'Richiesta a un operatore'], 'passaggio' => ['fa-route', 'Passaggio'], 'attivita' => ['fa-paperclip', 'Attività'], 'autodich' => ['fa-file-signature', 'Autodichiarazione'], 'messaggio' => ['fa-comment', ''], 'stato' => ['fa-flag', '']];
?>
<?php
// Intestazione della pratica: dove si trova adesso e, sotto, che cosa fare
$fase_p = \App\Didattica\FasePratica::di($p, (string)($uff_p['tipo'] ?? ''));
[$et_p, $tono_p, , $ico_p] = \App\Didattica\FasePratica::FASI[$fase_p];
echo pn_testata($p['modulo_titolo'], 'fa-inbox', trim($p['cognome'] . ' ' . $p['nome']) . ' · ' . $p['codice'] . ' · inviata il ' . date('d/m/Y', strtotime($p['creata_il'])),
    pn_stato($et_p, $tono_p, $ico_p) . ' ' . badge_stato_pratica($p['stato']),
    '<a href="' . $base . '&amp;tab=pratiche" class="text-decoration-none"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i>Tutte le pratiche</a>');
$sed_url_p = !empty($p['seduta_id']) ? $base . '&tab=sedute&id=' . (int)$p['seduta_id'] : '';
$btn_p = fn(string $href, string $t) => '<a href="' . $h($href) . '" class="btn fw-bold" style="background:var(--pn-mod);border-color:var(--pn-mod);color:var(--pn-mod-testo);">' . $t . ' <i class="fa fa-arrow-down ms-1" aria-hidden="true"></i></a>';
$btn_sed = fn(string $t) => $sed_url_p ? '<a href="' . $h($sed_url_p) . '" class="btn fw-bold" style="background:var(--pn-mod);border-color:var(--pn-mod);color:var(--pn-mod-testo);">' . $t . ' <i class="fa fa-arrow-right ms-1" aria-hidden="true"></i></a>' : '';
$mio_ufficio_p = $io_operatore && $uff_p && in_array((int)$uff_p['id'], $io_operatore['_uffici'] ?? [], true);
$btn_prendi = $mio_ufficio_p ? '<form method="POST" class="m-0">' . (new \App\Auth\Csrf(new \App\Auth\SessioneNativa()))->campo() . '<input type="hidden" name="pratica_id" value="' . (int)$p['id'] . '"><button type="submit" name="prendi_pratica" value="1" class="btn fw-bold" style="background:var(--pn-mod);border-color:var(--pn-mod);color:var(--pn-mod-testo);"><i class="fa fa-hand me-1" aria-hidden="true"></i>Prendi in carico</button></form>' : $btn_p('#assegna', 'Assegna');
$prossima_p = match ($fase_p) {
    'protocollare' => (string)$p['protocollo'] === ''
        ? ['Protocolla la pratica', 'Scarica la domanda, protocollala nel sistema di Ateneo e registra qui numero e data.', $da_protocollare ? $btn_p('#protocollo', 'Vai al protocollo') : '', 'attesa']
        : ['Si aspetta la marca da bollo', 'La pratica è protocollata e resta qui finché il bollo non risulta pagato.', $da_protocollare ? $btn_p('#protocollo', 'Registra il bollo') : '', 'attesa'],
    'smistare'     => ['Assegna la pratica a una persona', 'Scegli il passo e l\'operatore: riceve un\'email e lo studente vede il passaggio.', $btn_p('#assegna', 'Assegna'), ''],
    'prendere'     => ['La pratica è del tuo ufficio', 'Prendila in carico per lavorarla, oppure passala a un\'altra persona.', $btn_prendi, ''],
    'lavorazione'  => ['Completa l\'istruttoria e porta la pratica in consiglio', 'Compila le informazioni per il verbale e scegli la seduta; se serve, passa la pratica a un altro ufficio.', $btn_p('#istruttoria', 'Vai all\'istruttoria'), ''],
    'integrazione' => ['Si aspetta lo studente', 'Hai chiesto un\'integrazione: la pratica torna «In lavorazione» quando lo studente risponde.', '', 'attesa'],
    'consiglio'    => ['La pratica è in seduta', 'Registra la decisione del consiglio nella pagina della seduta.', $btn_sed('Vai alla seduta'), 'ok'],
    'decisa'       => ['Comunica l\'esito allo studente', 'Il consiglio ha deciso: applica l\'esito dalla pagina della seduta.', $btn_sed('Vai alla seduta'), 'ok'],
    'firma'        => ['Si aspetta una firma', 'Chi deve firmare ha ricevuto il link per email: la pratica prosegue da sola appena firma (se rifiuta, torna allo studente). Puoi rimandare il link dal riquadro dell\'iter.', '', 'attesa'],
    'segreteria'   => ['La pratica è alla segreteria studenti', 'Ha ricevuto l\'estratto e il verbale: qui non c\'è altro da fare.', '', 'attesa'],
    default        => ['Pratica conclusa', 'Stato finale: «' . (STATI_PRATICA[$p['stato']][0] ?? $p['stato']) . '». Lo studente è stato avvisato.', '', 'ok'],
};
if ($in_pagamento) {
    $prossima_p = empty($p['marche_il'])
        ? ['Carica le marche da bollo in Esse3', 'Carica le 2 marche da bollo su Esse3 (Segreteria > Situazione tasse) e avvisa lo studente dal riquadro qui sotto: può procedere al pagamento.', $btn_p('#marche', 'Vai alle marche da bollo'), 'attesa']
        : (empty($p['bollo_il'])
            ? ['Si aspetta il pagamento', 'Lo studente è stato avvisato. Quando il pagamento risulta, spuntalo e carica il certificato di Esse3: la pratica passa alla firma del Direttore.', $btn_p('#marche', 'Registra il pagamento'), 'attesa']
            : ['Pagamento registrato', 'Il pagamento risulta ma la pratica non è ancora passata alla firma: usa «Passa al passo successivo».', '', 'attesa']);
}
if ($in_genera) {
    $prossima_p = ['Controlla l\'attestato e mandalo al Direttore', 'Tutti i docenti hanno confermato. Guarda l\'anteprima dell\'attestato e, se è giusto, mandalo al Direttore per la firma.', $btn_p('#attestato', 'Vai all\'attestato'), 'attesa'];
} elseif ($in_uscita) {
    $prossima_p = ['Registra il protocollo in uscita', 'Il Direttore ha firmato: protocolla l\'attestato, mandalo all\'azienda e carica qui il PDF firmato e protocollato. Lo studente lo scarica da qui.', $btn_p('#uscita', 'Protocollo in uscita'), 'attesa'];
} elseif ($conf_p && array_filter($conf_p, fn($c) => $c['stato'] === 'attesa')) {
    $prossima_p = ['Si aspetta la conferma dei docenti', 'Ogni docente ha ricevuto il suo link: la pratica prosegue appena tutti confermano (se uno non conferma, torna allo studente). Puoi rimandare le email dal riquadro dell\'iter.', '', 'attesa'];
}
if ($in_documento) {
    $prossima_p = ['Carica il documento da firmare', 'Carica il PDF generato da Esse3 (o preparato dall\'ufficio) dal riquadro qui sotto: la pratica passa al Direttore per la firma.', $btn_p('#documento', 'Carica il documento'), 'attesa'];
}
echo pn_prossima($prossima_p[0], $prossima_p[1], $prossima_p[2], $prossima_p[3]);
?>

    <div class="card border-0 shadow-sm mb-3" style="border-left:4px solid #0056B3 !important;"><div class="card-body">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
            <h6 class="fw-bold mb-0"><i class="fa fa-route me-1 text-primary" aria-hidden="true"></i>Iter della pratica</h6>
            <span class="small text-secondary"><?php echo $o_carico ? 'In carico a <strong>' . $h(etichetta_operatore($o_carico)) . '</strong>' . ($io_operatore && (int)$io_operatore['id'] === (int)$o_carico['id'] ? ' <span class="badge bg-success">a te</span>' : '') : ($conclusa ? 'Conclusa' : ($uff_p ? 'All\'ufficio <strong>' . $h($uff_p['nome']) . '</strong> <span class="badge bg-warning text-dark">da prendere in carico</span>' : '<span class="badge bg-warning text-dark">da smistare</span>')); ?>
                <?php if ($io_operatore && (int)$io_operatore['id'] !== (int)$p['assegnata_a'] && in_array((int)$io_operatore['id'], operatori_pratica($conn, (int)$p['id']), true)): ?> · <span class="badge bg-light text-dark border" title="Puoi aggiungere documenti e note: chi l'ha in carico viene avvisato"><i class="fa fa-clock-rotate-left me-1" aria-hidden="true"></i>l'hai avuta in carico</span><?php endif; ?></span>
        </div>
        <?php echo pn_iter(\App\Core\App::per($conn)->get(\App\Didattica\ServizioIter::class)->passiIter($p, $m_p), 'Iter della pratica'); ?>
        <?php
        $fp_svc = \App\Core\App::per($conn)->get(\App\Didattica\FirmaPratica::class);
        $fa_p = $fp_svc->inAttesa((int)$p['id']);
        $it_p = iter_modulo($m_p, $p);
        $passo_firma = in_array($it_p[(int)$p['passo'] - 1] ?? 0, [ITER_PERSONA, ITER_DIRETTORE], true);
        $ultima_firma = $fp_svc->storico((int)$p['id'])[0] ?? null;
        if ($fa_p): ?>
        <div class="alert alert-warning small mt-2 mb-0 d-flex flex-wrap gap-2 align-items-center"><span><i class="fa fa-signature me-1" aria-hidden="true"></i>In attesa della firma di <strong><?php echo $h($fa_p['nome']); ?></strong> (<?php echo $h($fa_p['email']); ?>) dal <?php echo date('d/m/Y', strtotime($fa_p['richiesta_il'])); ?><?php echo (int)$fa_p['solleciti'] ? ' · ' . (int)$fa_p['solleciti'] . ' solleciti' : ''; ?>.</span>
            <form method="POST" class="ms-auto m-0"><?php csrf_field(); ?><input type="hidden" name="pratica_id" value="<?php echo (int)$p['id']; ?>"><button type="submit" name="rimanda_firma" value="1" class="btn btn-sm btn-outline-secondary py-0"><i class="fa fa-envelope me-1" aria-hidden="true"></i>Rimanda il link</button></form></div>
        <?php elseif (!$conclusa && $passo_firma): ?>
        <div class="alert alert-light border small mt-2 mb-0 d-flex flex-wrap gap-2 align-items-center"><span><i class="fa fa-signature me-1" aria-hidden="true"></i>Questo passo è una firma<?php echo $ultima_firma && $ultima_firma['stato'] === 'rifiutata' ? ': l\'ultima richiesta è stata rifiutata («' . $h($ultima_firma['motivo']) . '»)' : ''; ?>.</span>
            <form method="POST" class="ms-auto m-0"><?php csrf_field(); ?><input type="hidden" name="pratica_id" value="<?php echo (int)$p['id']; ?>"><button type="submit" name="invia_firma" value="1" class="btn btn-sm btn-primary fw-bold py-0"><i class="fa fa-paper-plane me-1" aria-hidden="true"></i>Manda alla firma</button></form></div>
        <?php endif; ?>
        <?php if (!$conclusa && !$fa_p && !$passo_firma && isset($passi[(int)$p['passo'] + 1]) && (int)$p['passo'] >= 1): ?>
        <form method="POST" class="mt-2 mb-0"><?php csrf_field(); ?><input type="hidden" name="pratica_id" value="<?php echo (int)$p['id']; ?>">
            <button type="submit" name="avanza_pratica" value="1" class="btn btn-sm btn-outline-success fw-bold" data-confirm="Passare la pratica al passo successivo?"><i class="fa fa-forward-step me-1" aria-hidden="true"></i>Passa al passo successivo: <?php echo $h($passi[(int)$p['passo'] + 1]); ?></button></form>
        <?php endif; ?>
        <?php if (!$conclusa && $io_operatore && (int)$p['assegnata_a'] !== (int)$io_operatore['id'] && $uff_p && in_array((int)$uff_p['id'], $io_operatore['_uffici'] ?? [], true)): ?>
            <form method="POST" class="mt-2"><?php csrf_field(); ?><input type="hidden" name="pratica_id" value="<?php echo (int)$p['id']; ?>">
                <button type="submit" name="prendi_pratica" value="1" class="btn btn-sm btn-success fw-bold"><i class="fa fa-hand me-1" aria-hidden="true"></i>Prendi in carico</button></form>
        <?php endif; ?>
        <?php if (!$conclusa): $sugg = operatori_suggeriti($conn, $p, $m_p, $passo_dopo); ?>
        <form method="POST" id="assegna" class="row g-2 align-items-end mt-2">
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

    <?php if ($conf_p): ?>
    <div class="card border-0 shadow-sm mb-3" style="border-left:4px solid #0056B3 !important;"><div class="card-body">
        <h6 class="fw-bold mb-2"><i class="fa fa-user-check me-1 text-primary" aria-hidden="true"></i>Conferma dei docenti</h6>
        <ul class="list-unstyled small mb-2">
        <?php foreach ($conf_p as $c_x): ?><li class="mb-1"><?php echo $c_x['stato'] === 'confermata' ? '<i class="fa fa-circle-check text-success me-1" aria-hidden="true"></i>' : ($c_x['stato'] === 'rifiutata' ? '<i class="fa fa-circle-xmark text-danger me-1" aria-hidden="true"></i>' : '<i class="fa fa-hourglass-half text-warning me-1" aria-hidden="true"></i>'); ?>
            <strong><?php echo $h($svc_conf->nomeVisibile((string)$c_x['nome'])); ?></strong> (<?php echo $h($c_x['email']); ?>) · <?php echo $c_x['stato'] === 'confermata' ? 'confermato il ' . date('d/m/Y H:i', strtotime($c_x['esito_il'])) : ($c_x['stato'] === 'rifiutata' ? 'non conferma: ' . $h($c_x['motivo']) : 'in attesa dal ' . date('d/m/Y', strtotime($c_x['richiesta_il']))); ?></li><?php endforeach; ?>
        </ul>
        <?php if (array_filter($conf_p, fn($c) => $c['stato'] === 'attesa')): ?><form method="POST" class="m-0"><?php csrf_field(); ?><input type="hidden" name="pratica_id" value="<?php echo (int)$p['id']; ?>"><button type="submit" name="rimanda_conferme" value="1" class="btn btn-sm btn-outline-secondary"><i class="fa fa-envelope me-1" aria-hidden="true"></i>Rimanda l'email ai docenti in attesa</button></form><?php endif; ?>
    </div></div>
    <?php endif; ?>

    <?php if ($in_genera): ?>
    <form method="POST" id="attestato" class="card border-0 shadow-sm mb-3" style="border-left:4px solid #b45309 !important;"><div class="card-body">
        <?php csrf_field(); ?><input type="hidden" name="pratica_id" value="<?php echo (int)$p['id']; ?>">
        <h6 class="fw-bold mb-1"><i class="fa fa-file-circle-check me-1" style="color:#b45309;" aria-hidden="true"></i>Attestato da controllare</h6>
        <p class="small text-secondary mb-2">Il portale ha preparato l'attestato con i dati dello studente, le righe e le conferme dei docenti. Controllalo e mandalo al Direttore: riceve l'email con il link per firmarlo in PAdES.</p>
        <div class="d-flex flex-wrap gap-2 align-items-center"><a class="btn btn-sm btn-outline-danger fw-bold" href="../allegato_pratica.php?p=<?php echo (int)$p['id']; ?>&amp;attestato=1" target="_blank" rel="noopener"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>Anteprima dell'attestato (PDF)</a>
            <button type="submit" name="attestato_al_direttore" value="1" class="btn btn-sm btn-success fw-bold" data-confirm="L'attestato è giusto? Va al Direttore per la firma."><i class="fa fa-signature me-1" aria-hidden="true"></i>Controllato: manda al Direttore</button></div>
        <div class="form-text mt-2">Se c'è un errore, scrivi allo studente dalla scheda «Storico e messaggi»: puoi chiedergli un'integrazione e le conferme ripartono.</div>
    </div></form>
    <?php endif; ?>

    <?php if ($in_uscita): ?>
    <form method="POST" enctype="multipart/form-data" id="uscita" class="card border-0 shadow-sm mb-3" style="border-left:4px solid #15803d !important;"><div class="card-body">
        <?php csrf_field(); ?><input type="hidden" name="pratica_id" value="<?php echo (int)$p['id']; ?>">
        <h6 class="fw-bold mb-1"><i class="fa fa-paper-plane me-1" style="color:#15803d;" aria-hidden="true"></i>Protocollo in uscita</h6>
        <p class="small text-secondary mb-2">Il Direttore ha firmato. 1. Scarica l'attestato firmato dalla scheda «Storico e messaggi» · 2. protocollalo in uscita e mandalo all'azienda<?php $pec_a = $svc_att->destinatario($p); echo $pec_a !== '' ? ' (<strong>' . $h($pec_a) . '</strong>)' : ''; ?> · 3. registra qui numero e data e carica il PDF firmato e protocollato: lo studente lo scarica e riceve l'email.</p>
        <div class="row g-2 align-items-end">
            <div class="col-md-3"><label class="form-label small fw-bold mb-0" for="ucNum">Numero di protocollo</label><input class="form-control form-control-sm" id="ucNum" name="protocollo" maxlength="100" required></div>
            <div class="col-md-2"><label class="form-label small fw-bold mb-0" for="ucData">Data</label><input type="date" class="form-control form-control-sm" id="ucData" name="protocollo_data" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required></div>
            <div class="col-md-5"><label class="form-label small fw-bold mb-0" for="ucPdf">PDF firmato e protocollato</label><input type="file" class="form-control form-control-sm" id="ucPdf" name="pdf_protocollato" accept=".pdf,application/pdf" required></div>
            <div class="col-md-2"><button type="submit" name="protocollo_uscita" value="1" class="btn btn-sm btn-success fw-bold w-100"><i class="fa fa-check me-1" aria-hidden="true"></i>Concludi</button></div>
        </div>
    </div></form>
    <?php endif; ?>

    <?php if ($in_documento): ?>
    <form method="POST" enctype="multipart/form-data" id="documento" class="card border-0 shadow-sm mb-3" style="border-left:4px solid #b45309 !important;"><div class="card-body">
        <?php csrf_field(); ?><input type="hidden" name="pratica_id" value="<?php echo (int)$p['id']; ?>">
        <h6 class="fw-bold mb-1"><i class="fa fa-file-arrow-up me-1" style="color:#b45309;" aria-hidden="true"></i>Documento da firmare</h6>
        <p class="small text-secondary mb-2">Non è dovuta la marca da bollo. Carica il documento in PDF (generato da Esse3 o preparato dall'ufficio): il Direttore riceve l'email con il link per firmarlo in PAdES; quando firma e ricarica, lo studente può scaricarlo e stamparlo e tu ricevi l'avviso.</p>
        <div class="row g-2 align-items-end"><div class="col-md-8"><label class="form-label small fw-bold mb-0" for="docPdf">Documento (PDF)</label><input type="file" class="form-control form-control-sm" id="docPdf" name="documento" accept=".pdf,application/pdf" required></div>
            <div class="col-md-4"><button type="submit" name="documento_caricato" value="1" class="btn btn-sm btn-success fw-bold w-100"><i class="fa fa-signature me-1" aria-hidden="true"></i>Al Direttore</button></div></div>
    </div></form>
    <?php endif; ?>

    <?php if ($in_pagamento): ?>
    <div id="marche" class="card border-0 shadow-sm mb-3" style="border-left:4px solid #b45309 !important;"><div class="card-body">
        <h6 class="fw-bold mb-1"><i class="fa fa-receipt me-1" style="color:#b45309;" aria-hidden="true"></i>Marche da bollo</h6>
        <p class="small text-secondary mb-2">1. Carica le 2 marche da bollo su Esse3 · 2. avvisa lo studente · 3. quando il pagamento risulta, spuntalo e carica il certificato generato da Esse3: passa al Direttore per la firma.</p>
        <form method="POST" class="mb-3"><?php csrf_field(); ?><input type="hidden" name="pratica_id" value="<?php echo (int)$p['id']; ?>">
            <?php if (!empty($p['marche_il'])): ?><div class="small text-success fw-bold"><i class="fa fa-circle-check me-1" aria-hidden="true"></i>Marche caricate e studente avvisato il <?php echo date('d/m/Y H:i', strtotime($p['marche_il'])); ?><?php echo $p['marche_da'] !== '' ? ' da ' . $h($p['marche_da']) : ''; ?></div>
            <?php else: ?><button type="submit" name="marche_caricate" value="1" class="btn btn-sm fw-bold text-white" style="background:#b45309;" data-confirm="Hai caricato le 2 marche da bollo su Esse3? Lo studente riceve un'email per procedere al pagamento."><i class="fa fa-bell me-1" aria-hidden="true"></i>Marche caricate su Esse3: avvisa lo studente</button><?php endif; ?>
        </form>
        <?php if (!empty($p['marche_il']) && empty($p['bollo_il'])): ?>
        <form method="POST" enctype="multipart/form-data" class="row g-2 align-items-end"><?php csrf_field(); ?><input type="hidden" name="pratica_id" value="<?php echo (int)$p['id']; ?>">
            <div class="col-md-5"><label class="form-label small fw-bold mb-0" for="mbCert">Certificato generato da Esse3 (PDF)</label><input type="file" class="form-control form-control-sm" id="mbCert" name="certificato" accept=".pdf,application/pdf" required></div>
            <div class="col-md-5"><label class="form-check small fw-bold m-0"><input class="form-check-input" type="checkbox" name="pagato" value="1" required> Il pagamento risulta: <?php echo $h(\App\Didattica\ServizioMarcheBollo::DICITURA); ?></label></div>
            <div class="col-md-2"><button type="submit" name="pagamento_registrato" value="1" class="btn btn-sm btn-success fw-bold w-100"><i class="fa fa-signature me-1" aria-hidden="true"></i>Al Direttore</button></div>
            <div class="col-12 form-text">Il Direttore riceve l'email con il link per firmare in PAdES il certificato; quando firma e ricarica, lo studente può scaricarlo e stamparlo e tu ricevi l'avviso.</div>
        </form>
        <?php elseif (!empty($p['bollo_il'])): ?><div class="small text-success fw-bold"><i class="fa fa-circle-check me-1" aria-hidden="true"></i>Pagamento verificato il <?php echo date('d/m/Y H:i', strtotime($p['bollo_il'])); ?><?php echo $p['bollo_da'] !== '' ? ' da ' . $h($p['bollo_da']) : ''; ?></div><?php endif; ?>
    </div></div>
    <?php endif; ?>

    <?php if ($da_protocollare): ?>
    <form method="POST" id="protocollo" class="card border-0 shadow-sm mb-3" style="border-left:4px solid #b45309 !important;"><div class="card-body">
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


    <ul class="nav nav-tabs" id="schedePratica" role="tablist">
        <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tpDomanda" type="button" role="tab"><i class="fa fa-file-lines me-1" aria-hidden="true"></i>Domanda</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tpIstruttoria" type="button" role="tab"><i class="fa fa-scale-balanced me-1" aria-hidden="true"></i>Istruttoria e verbale</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tpStorico" type="button" role="tab"><i class="fa fa-comments me-1" aria-hidden="true"></i>Storico e messaggi <span class="badge bg-light text-dark border ms-1"><?php echo count($eventi); ?></span></button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tpAltre" type="button" role="tab"><i class="fa fa-ellipsis me-1" aria-hidden="true"></i>Altre azioni</button></li>
    </ul>
    <div class="tab-content">
    <div class="tab-pane fade show active" id="tpDomanda" role="tabpanel">
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
    </div><div class="tab-pane fade" id="tpIstruttoria" role="tabpanel">
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

            <form method="POST" id="istruttoria" class="card border-0 shadow-sm mb-3" style="border-left:4px solid #047857 !important;"><div class="card-body">
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
    </div><div class="tab-pane fade" id="tpStorico" role="tabpanel">

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
    <div class="tab-pane fade" id="tpAltre" role="tabpanel"><div class="row"><div class="col-lg-7">
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
                        <?php foreach (STATI_PRATICA as $k => [$n, $col, $ico]): if ($k === $p['stato'] || in_array($k, ['bozza', 'inviata', 'integrazione'], true)) continue; ?>
                            <button type="submit" name="stato_pratica" value="<?php echo $k; ?>" class="btn btn-sm fw-bold text-white text-start" style="background:<?php echo $col; ?>;"><i class="fa <?php echo $ico; ?> me-1" aria-hidden="true"></i><?php echo $n; ?></button>
                        <?php endforeach; ?>
                    </div>
                    <p class="small text-secondary mt-2 mb-0">Lo studente riceve un'email per gli esiti (accolta, respinta, chiusa) e per le richieste di integrazione; i passaggi tra uffici gli arrivano quando la pratica passa di mano.</p>
                </form>
            </div></div>
    </div></div></div>
    </div>
    <script>
    // I collegamenti interni (#istruttoria, #convalide…) aprono la scheda che li contiene
    document.addEventListener('DOMContentLoaded', function () {
        function apri() {
            var h = location.hash; if (!h || h.length < 2) return;
            var el; try { el = document.querySelector(h); } catch (e) { return; }
            var pane = el && el.closest('.tab-pane'); if (!pane) return;
            var b = document.querySelector('[data-bs-target="#' + pane.id + '"]');
            if (b && window.bootstrap) bootstrap.Tab.getOrCreateInstance(b).show();
        }
        apri(); window.addEventListener('hashchange', apri);
    });
    </script>
    <?php else:
        // ── Elenco a righe: fasi cliccabili in alto, filtri, e per ogni pratica dove si trova e che cosa fare ──
        $repo_pr = \App\Core\App::per($conn)->get(\App\Didattica\PraticaRepository::class);
        $uffici_p = uffici_didattica($conn);
        $tipo_uff = fn(array $x) => !empty($x['ufficio_id']) ? (string)($uffici_p[(int)$x['ufficio_id']]['tipo'] ?? '') : '';
        // Numeri per fase: sempre su tutte le pratiche aperte, indipendenti dagli altri filtri
        $conta_fasi = \App\Didattica\FasePratica::conta($repo_pr->elenco('aperte', 0, '', '', '', '', '', $io_operatore), $tipo_uff);
        $f_fase = isset(\App\Didattica\FasePratica::FASI[$_GET['fase'] ?? '']) ? (string)$_GET['fase'] : '';
        $stato_q = $f_fase === 'conclusa' && $f_stato === 'aperte' ? 'tutte' : $f_stato;
        $elenco = $repo_pr->elenco($stato_q, $f_mod, $f_sed, $f_car, $f_dal, $f_al, $f_q, $io_operatore);
        if ($f_fase !== '') $elenco = array_values(array_filter($elenco, fn($x) => \App\Didattica\FasePratica::di($x, $tipo_uff($x)) === $f_fase));
        $filtri_url = fn(array $cambia = []) => array_filter(array_merge(['stato' => $f_stato !== 'aperte' ? $f_stato : null, 'modulo' => $f_mod ?: null, 'q' => $f_q ?: null, 'seduta' => $f_sed ?: null,
            'dal' => $f_dal ?: null, 'al' => $f_al ?: null, 'carico' => $f_car ?: null, 'fase' => $f_fase ?: null], $cambia), fn($v) => $v !== null && $v !== '');
        $url_elenco = fn(array $cambia = []) => $base . '&tab=pratiche&' . http_build_query($filtri_url($cambia));
        $qs_filtri = http_build_query(['stato' => $f_stato, 'modulo' => $f_mod ?: null, 'q' => $f_q ?: null, 'seduta' => $f_sed ?: null, 'dal' => $f_dal ?: null, 'al' => $f_al ?: null, 'carico' => $f_car ?: null]);
        $fasi_bar = ['' => ['href' => $url_elenco(['fase' => '']), 'testo' => 'Tutte le aperte', 'numero' => array_sum($conta_fasi), 'colore' => '#334155']];
        foreach (\App\Didattica\FasePratica::FASI as $k_f => [$et_f, , $col_f]) {
            if ($k_f === 'conclusa') { $fasi_bar[$k_f] = ['href' => $url_elenco(['fase' => $k_f]), 'testo' => $et_f, 'colore' => $col_f]; continue; }
            if (!empty($conta_fasi[$k_f]) || $f_fase === $k_f) $fasi_bar[$k_f] = ['href' => $url_elenco(['fase' => $k_f]), 'testo' => $et_f, 'numero' => $conta_fasi[$k_f] ?? 0, 'colore' => $col_f];
        }
        $altri_filtri = $f_sed !== '' || $f_dal !== '' || $f_al !== '' || $f_car !== '';
    ?>
    <?php echo pn_fasi($fasi_bar, $f_fase, 'Pratiche per fase'); ?>

    <form method="GET" class="card border-0 shadow-sm mb-3"><div class="card-body">
        <input type="hidden" name="p_id" value="<?php echo (int)$filtro_p; ?>"><input type="hidden" name="tab" value="pratiche"><input type="hidden" name="fase" value="<?php echo $h($f_fase); ?>">
        <div class="row g-2 align-items-end">
            <div class="col-md-4"><label class="form-label small fw-bold mb-0" for="fMod">Tipo di pratica</label><select class="form-select" id="fMod" name="modulo"><option value="0">Tutti i tipi</option>
                <?php foreach ($moduli as $m): if ($m['tipo'] !== 'online') continue; ?><option value="<?php echo (int)$m['id']; ?>"<?php echo $f_mod === (int)$m['id'] ? ' selected' : ''; ?>><?php echo $h($m['titolo']); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label small fw-bold mb-0" for="fSt">Stato</label><select class="form-select" id="fSt" name="stato">
                <option value="aperte"<?php echo $f_stato === 'aperte' ? ' selected' : ''; ?>>Aperte</option>
                <?php foreach (STATI_PRATICA as $k => [$n]): if ($k === 'bozza') continue; ?><option value="<?php echo $k; ?>"<?php echo $f_stato === $k ? ' selected' : ''; ?>><?php echo $n; ?></option><?php endforeach; ?>
                <option value="tutte"<?php echo $f_stato === 'tutte' ? ' selected' : ''; ?>>Tutte</option></select></div>
            <div class="col-md-4"><label class="form-label small fw-bold mb-0" for="fQ">Cerca</label><input type="search" class="form-control" id="fQ" name="q" value="<?php echo $h($f_q); ?>" placeholder="Nome, matricola o codice della pratica"></div>
            <div class="col-md-2"><button class="btn btn-primary fw-bold w-100" style="background:var(--pn-mod);border-color:var(--pn-mod);color:var(--pn-mod-testo);"><i class="fa fa-filter me-1" aria-hidden="true"></i>Filtra</button></div>
        </div>
        <details class="mt-2"<?php echo $altri_filtri ? ' open' : ''; ?>>
            <summary class="small fw-bold" style="cursor:pointer;">Altri filtri: seduta, date, di chi è la pratica</summary>
            <div class="row g-2 align-items-end mt-1">
                <div class="col-md-3"><label class="form-label small fw-bold mb-0" for="fSed">Seduta del consiglio</label><select class="form-select form-select-sm" id="fSed" name="seduta"><option value="">Qualsiasi</option><option value="nessuna"<?php echo $f_sed === 'nessuna' ? ' selected' : ''; ?>>Senza seduta</option>
                    <?php foreach ($sedute as $s): ?><option value="<?php echo (int)$s['id']; ?>"<?php echo $f_sed === (string)$s['id'] ? ' selected' : ''; ?>><?php echo $h(etichetta_seduta($s)); ?></option><?php endforeach; ?></select></div>
                <div class="col-6 col-md-2"><label class="form-label small fw-bold mb-0" for="fDal">Arrivate dal</label><input type="date" class="form-control form-control-sm" id="fDal" name="dal" value="<?php echo $h($f_dal); ?>"></div>
                <div class="col-6 col-md-2"><label class="form-label small fw-bold mb-0" for="fAl">fino al</label><input type="date" class="form-control form-control-sm" id="fAl" name="al" value="<?php echo $h($f_al); ?>"></div>
                <div class="col-md-3"><label class="form-label small fw-bold mb-0" for="fCar">Di chi è</label><select class="form-select form-select-sm" id="fCar" name="carico">
                    <option value="">Di tutti</option><option value="smistare"<?php echo $f_car === 'smistare' ? ' selected' : ''; ?>>Da assegnare a qualcuno</option>
                    <?php if ($io_operatore): foreach (['ufficio' => 'Del mio ufficio', 'me' => 'Assegnate a me', 'seguite' => 'Passate ad altri uffici'] as $k_c => $t_c): ?><option value="<?php echo $k_c; ?>"<?php echo $f_car === $k_c ? ' selected' : ''; ?>><?php echo $t_c; ?></option><?php endforeach; endif; ?></select></div>
                <div class="col-md-2"><a class="btn btn-sm btn-outline-secondary w-100" href="<?php echo $base; ?>&amp;tab=pratiche">Azzera i filtri</a></div>
            </div>
        </details>
    </div></form>

    <form method="POST" id="ddElenco">
        <?php csrf_field(); ?><input type="hidden" name="torna" value="<?php echo $h($url_elenco()); ?>">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
            <span class="small text-secondary"><strong><?php echo count($elenco); ?></strong> <?php echo count($elenco) === 1 ? 'pratica' : 'pratiche'; ?></span>
            <a class="btn btn-sm btn-outline-success fw-bold dd-esp" data-formato="xlsx" href="<?php echo $base; ?>&amp;tab=pratiche&amp;<?php echo $h($qs_filtri); ?>&amp;esporta=xlsx"><i class="fa fa-file-excel me-1" aria-hidden="true"></i>Scarica in Excel</a>
            <a class="btn btn-sm btn-outline-primary fw-bold dd-esp" data-formato="docx" href="<?php echo $base; ?>&amp;tab=pratiche&amp;<?php echo $h($qs_filtri); ?>&amp;esporta=docx"><i class="fa fa-file-word me-1" aria-hidden="true"></i>Scarica in Word (per il verbale)</a>
            <span class="small text-secondary">Scarica le pratiche dell'elenco o, se ne spunti alcune, solo quelle.</span>
        </div>

        <?php $segreterie = uffici_di_tipo($conn, 'segreteria'); ?>
        <div class="pn-barra" id="ddBarra" hidden>
            <strong class="me-2"><span id="ddNum">0</span> selezionate</strong>
            <span class="d-flex gap-1 align-items-center flex-wrap">
                <label class="small fw-bold" for="ddAss">Porta alla seduta</label>
                <select class="form-select form-select-sm" id="ddAss" name="seduta_id" style="max-width:240px;"><option value="0">Nessuna (togli dalla seduta)</option>
                    <?php foreach ($sedute_future as $s): ?><option value="<?php echo (int)$s['id']; ?>"><?php echo $h(etichetta_seduta($s)); ?></option><?php endforeach; ?></select>
                <button type="submit" name="assegna_seduta" value="1" class="btn btn-sm btn-dark fw-bold">Assegna</button>
            </span>
            <span class="d-flex gap-1 align-items-center flex-wrap">
                <label class="small fw-bold" for="ddSeg">Invia alla segreteria studenti</label>
                <select class="form-select form-select-sm" id="ddSeg" name="segreteria_id" style="max-width:260px;"><?php if (!$segreterie): ?><option value="0">nessuna segreteria: creala in «Ufficio e ricevimento»</option><?php endif; ?>
                    <?php foreach ($segreterie as $k_s => $u_s): ?><option value="<?php echo (int)$k_s; ?>"><?php echo $h($u_s['nome']); ?></option><?php endforeach; ?></select>
                <button type="submit" name="invia_segreteria" value="1" class="btn btn-sm btn-success fw-bold"<?php echo $segreterie ? '' : ' disabled'; ?> data-confirm="Inviare le pratiche selezionate alla segreteria studenti? Ricevono l'estratto e il verbale della seduta; le persone della segreteria ricevono un'email di riepilogo.">Invia</button>
            </span>
        </div>

        <div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table align-middle small mb-0">
            <thead class="table-light"><tr><th style="width:28px;"><input type="checkbox" class="form-check-input" id="ddTutte" aria-label="Seleziona tutte"></th><th>Pratica</th><th>Studente</th><th>Dove si trova</th><th>Cosa fare</th><th>Aggiornata</th></tr></thead>
            <tbody>
            <?php if (!$elenco): ?><tr><td colspan="6"><?php echo pn_vuoto($moduli ? 'Nessuna pratica con questi filtri.' : 'Non c\'è ancora nessun modulo online: crealo nella scheda «Moduli e documenti» e gli studenti potranno inviare le pratiche.', 'fa-inbox'); ?></td></tr><?php endif; ?>
            <?php foreach ($elenco as $x):
                $fase_x = \App\Didattica\FasePratica::di($x, $tipo_uff($x)); [$et_x, $tono_x, , $ico_x] = \App\Didattica\FasePratica::FASI[$fase_x];
                $cf = \App\Didattica\FasePratica::cosaFare($fase_x, $x);
                $o_x = $x['assegnata_a'] ? operatore_ufficio($conn, (int)$x['assegnata_a']) : null; $u_x = !$o_x && $x['ufficio_id'] ? ($uffici_p[(int)$x['ufficio_id']] ?? null) : null;
                $dove_x = $o_x ? $h($o_x['nominativo']) . '<div class="text-secondary">' . $h(nome_ufficio_operatore($conn, $o_x)) . '</div>' : ($u_x ? $h($u_x['nome']) : '');
                $url_x = $base . '&tab=pratiche&id=' . (int)$x['id'];
                $url_cf = $cf['dove'] === 'seduta' && $x['seduta_id'] ? $base . '&tab=sedute&id=' . (int)$x['seduta_id'] : $url_x; ?>
                <tr>
                    <td><input type="checkbox" class="form-check-input dd-sel" name="ids[]" value="<?php echo (int)$x['id']; ?>" aria-label="Seleziona <?php echo $h($x['codice']); ?>"></td>
                    <td><a class="fw-bold text-decoration-none" href="<?php echo $url_x; ?>"><?php echo $h($x['modulo_titolo']); ?></a><div class="font-monospace text-secondary" style="font-size:.75rem;"><?php echo $h($x['codice']); ?><?php echo $x['seduta_data'] ? ' · seduta ' . date('d/m/Y', strtotime($x['seduta_data'])) : ''; ?></div></td>
                    <td><?php echo $h(trim($x['cognome'] . ' ' . $x['nome'])); ?><div class="text-secondary"><?php echo $h($x['email']); ?><?php echo $x['matricola'] !== '' ? ' · ' . $h($x['matricola']) : ''; ?></div></td>
                    <td><?php echo pn_stato($et_x, $tono_x, $ico_x); ?><?php echo $dove_x !== '' ? '<div class="mt-1">' . $dove_x . '</div>' : ''; ?></td>
                    <td><?php if ($cf['tipo'] === 'azione'): ?><a class="btn btn-sm fw-bold" style="background:var(--pn-mod);border-color:var(--pn-mod);color:var(--pn-mod-testo);" href="<?php echo $h($url_cf); ?>"><?php echo $h($cf['testo']); ?> <i class="fa fa-arrow-right ms-1" aria-hidden="true"></i></a>
                        <?php elseif ($cf['tipo'] === 'attesa'): ?><span class="text-secondary"><i class="fa fa-hourglass-half me-1" aria-hidden="true"></i><?php echo $h($cf['testo']); ?></span> <a href="<?php echo $url_x; ?>" class="ms-1">Apri</a>
                        <?php else: ?><a href="<?php echo $url_x; ?>">Apri</a><?php endif; ?></td>
                    <td class="text-nowrap"><?php echo date('d/m/Y H:i', strtotime($x['aggiornata_il'] ?: $x['creata_il'])); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div></div>
    </form>
    <script>
    (function () {
        var tutte = document.getElementById('ddTutte'), barra = document.getElementById('ddBarra'), num = document.getElementById('ddNum');
        function aggiorna() {
            var n = document.querySelectorAll('.dd-sel:checked').length;
            if (num) num.textContent = n;
            if (barra) barra.hidden = n === 0;
        }
        if (tutte) tutte.addEventListener('change', function () { document.querySelectorAll('.dd-sel').forEach(function (c) { c.checked = tutte.checked; }); aggiorna(); });
        document.querySelectorAll('.dd-sel').forEach(function (c) { c.addEventListener('change', aggiorna); });
        document.querySelectorAll('.dd-esp').forEach(function (a) {
            a.addEventListener('click', function () {
                var ids = Array.prototype.map.call(document.querySelectorAll('.dd-sel:checked'), function (c) { return c.value; });
                a.href = a.href.replace(/&ids=[^&]*/, '') + (ids.length ? '&ids=' + ids.join(',') : '');
            });
        });
        aggiorna();
    })();
    </script>
    <?php endif; ?>
