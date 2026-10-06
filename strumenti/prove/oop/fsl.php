<?php
// Prove del modulo FSL (App\Fsl): le funzioni di inc/fsl.php come facciate dei servizi, la convenzione online, il registro del pannello e
// i collegamenti con Attestati, Iscrizioni e Iscritti nel container. Chiamato da esegui.php: stesse variabili ($conn, $q, $EMAIL) e funzioni
// (prova, sezione). I dati di prova usano id 98xx e la scuola ZZFS98000X.
sezione("Modulo FSL: facciate di inc/, convenzione online e registro");

use App\Core\App as AppFsl;

$st98 = fn(int $id) => $conn->query("SELECT stato, convenzione, conv_promemoria FROM prenotazioni WHERE id = $id")->fetch_assoc();

foreach (["DELETE FROM convenzioni_compilate WHERE prenotazione_id = 9893", "DELETE FROM valutazioni_fsl WHERE prenotazione_id = 9893", "DELETE FROM prenotazioni WHERE id = 9893",
          "DELETE FROM turni WHERE id = 9892", "DELETE FROM progetti_dettagli WHERE evento_id = 9891", "DELETE FROM eventi WHERE id = 9891", "DELETE FROM pagine_eventi WHERE id = 9890",
          "DELETE FROM convenzioni_scuole WHERE scuola_codice = 'ZZFS98000X'", "DELETE FROM scuole WHERE codice = 'ZZFS98000X'"] as $sql) $q($sql);
$q("INSERT INTO scuole (codice, denominazione, istituto_codice, istituto_denominazione, tipo, comune, provincia, regione, indirizzo, cap) VALUES ('ZZFS98000X', 'LICEO PROVA 98', NULL, NULL, 'LICEO', 'RENDE', 'COSENZA', 'CALABRIA', 'VIA 98', '87036')");
$q("INSERT INTO pagine_eventi (id, titolo, slug, tipo_area, visibile, colore_primario) VALUES (9890, 'FSL prova 98', 'fsl_prova_98', 'fsl', 1, '#445566')");
$q("INSERT INTO eventi (id, pagina_id, titolo, tipo, descrizione_breve) VALUES (9891, 9890, 'Progetto FSL 98', 'progetto', 'Descrizione 98.')");
$q("INSERT INTO progetti_dettagli (evento_id, convenzione, per_scuole, attestati, data_inizio, data_fine, ore_totali) VALUES (9891, 1, 1, 1, '" . $giorni(10) . "', '" . $giorni(40) . "', 20)");
$q("INSERT INTO turni (id, evento_id, nome_turno, max_posti, richiede_approvazione) VALUES (9892, 9891, 'Edizione 98', 5, 0)");
$q("INSERT INTO prenotazioni (id, turno_id, codice_prenotazione, stato, nome, cognome, email, scuola_codice, convenzione, dati_custom_json)
    VALUES (9893, 9892, 'FS-98', 'da_approvare', 'Rosa', 'Docente', 'rosa98@example.org', 'ZZFS98000X', 'no', '{\"numero_partecipanti\":\"14\"}')");

// Container: le interfacce degli altri moduli sono servite dalle classi di App\Fsl 
$c_fsl = AppFsl::per($conn);
prova($c_fsl->get(\App\Attestati\RegoleClasse::class) instanceof \App\Fsl\RegoleClasse && $c_fsl->get(\App\Iscrizioni\RegoleFsl::class) instanceof \App\Fsl\RegoleIscrizioniFsl
    && $c_fsl->get(\App\Iscritti\Convenzioni::class) instanceof \App\Fsl\ConvenzioniIscritti, "Attestati, Iscrizioni e Iscritti usano le classi di App\\Fsl");
prova(defined('VALUTAZIONE_FSL_ASPETTI') && VALUTAZIONE_FSL_ASPETTI === \App\Fsl\Costanti::ASPETTI && CONV_SEGNAPOSTI === \App\Fsl\Costanti::SEGNAPOSTI && CONV_DURATA_ANNI === 1, "costanti globali di prima, con i valori di App\\Fsl\\Costanti");

// Facciate e servizi danno la stessa risposta
$s_conv = $c_fsl->get(\App\Fsl\ServizioConvenzioni::class);
prova(convenzione_valida($conn, 'ZZFS98000X', true) === null && $s_conv->valida('zzfs98000x') === null, "nessuna convenzione per la scuola");
$esito = salva_convenzione($conn, ['scuola_codice' => 'zzfs98000x', 'data_stipula' => $giorni(0), 'scadenza' => $giorni(100), 'protocollo' => ' 98/26 ', 'docenti' => [['nome' => 'Doc 98', 'email' => 'DOC98@EXAMPLE.ORG']]], 0, 'prova98');
prova($esito !== null && $esito[1] === 1 && $s_conv->valida('ZZFS98000X', true)['protocollo'] === '98/26' && convenzioni_della_scuola($conn, 'ZZFS98000X')[0]['id'] == $esito[0], "salva_convenzione(): registrata e la prenotazione in attesa è coperta");
prova(($st98(9893)['convenzione'] ?? '') === 'ricevuta' && ($st98(9893)['stato'] ?? '') === 'confermata', "la prenotazione diventa ricevuta e confermata");
prova(periodo_prenotazione(['pd_inizio' => $giorni(10), 'pd_fine' => $giorni(40)]) === [$giorni(10), $giorni(40)] && periodo_attivita(null, null, '2026-01-01') === ['2026-01-01', '2026-01-01'], "periodi dell'attività");

// Documenti Word: la facciata e il servizio generano lo stesso documento
$dati98 = dati_prenotazione_convenzione($conn, 9893);
$pre = dati_convenzione_precompilata($conn, $dati98);
prova($dati98['evento_titolo'] === 'Progetto FSL 98' && $pre['ISTITUTO'] === 'Liceo Prova 98 (codice meccanografico ZZFS98000X)' && $pre['STUDENTI'] === '14' && $pre['DURATA'] === '20 ore', "dati della convenzione precompilata");
if (class_exists('ZipArchive') && is_file(RADICE_SITO . '/modelli_documenti/convenzione_precompilabile.docx')) {
    $f1 = genera_convenzione_precompilata($conn, 9893, 'allegato');
    $f2 = $c_fsl->get(\App\Fsl\PrecompilazioneConvenzione::class)->genera(9893, 'allegato');
    $zip1 = new ZipArchive(); $zip1->open((string)$f1); $zip2 = new ZipArchive(); $zip2->open((string)$f2);
    prova($f1 && $f2 && $zip1->getFromName('word/document.xml') === $zip2->getFromName('word/document.xml') && str_contains((string)$zip1->getFromName('word/document.xml'), 'Progetto FSL 98'), "allegato precompilato: facciata e servizio generano lo stesso documento");
    @unlink((string)$f1); @unlink((string)$f2);
}

// Convenzione online: dal codice della prenotazione al link, salvataggio, protocollo e scarico
$online = $c_fsl->get(\App\Fsl\ServizioConvenzioneOnline::class);
$tok98 = $online->tokenDaCodice('FS-98');
prova(preg_match('/^[a-f0-9]{32}$/', (string)$tok98) && $online->tokenDaCodice('FS-98') === $tok98 && $online->tokenDaCodice('FS-NONESISTE') === null, "convenzione online: link personale dal codice, una sola compilazione");
$cc98 = $online->perToken((string)$tok98);
$ctx98 = $online->contesto($cc98);
$EMAIL = [];
$esito_on = $online->salva($cc98, $ctx98, ['denominazione' => 'Liceo Prova 98', 'dirigente' => 'Dott. Neri', 'email' => 'liceo98@example.org', 'att' => ['pr9893' => ['scelta' => 1, 'studenti' => '14', 'tutor' => 'Prof. Verdi']]], null);
prova($esito_on['errori'] === [] && count($EMAIL) === 1 && $EMAIL[0]['a'] === 'liceo98@example.org' && str_contains($EMAIL[0]['corpo'], 'convenzione_online.php?t=' . $tok98), "convenzione online: dati salvati e link per email");
prova($online->salva($cc98, $ctx98, ['denominazione' => ''], null)['errori'] !== [] && count($online->recenti()) >= 1, "convenzione online: errori del modulo e compilazioni recenti");
$online->salvaProtocollo((int)$cc98['id'], '99/26', '2026-10-07');
prova($conn->query("SELECT protocollo, protocollo_data FROM convenzioni_compilate WHERE id = " . (int)$cc98['id'])->fetch_assoc() == ['protocollo' => '99/26', 'protocollo_data' => '2026-10-07'], "protocollo della convenzione online");
if (class_exists('ZipArchive') && is_file(RADICE_SITO . '/modelli_documenti/convenzione_precompilabile.docx')) {
    $cc98 = $online->perToken((string)$tok98);
    $doc98 = $online->scarica('convenzione', $cc98, $online->contesto($cc98));
    prova($doc98 && is_file($doc98['file']) && str_starts_with($doc98['nome'], 'Convenzione_FSL_'), "convenzione online: documento Word scaricabile");
    if ($doc98) @unlink($doc98['file']);
}

// Verifica delle iscrizioni, scheda di valutazione e regole di classe dal container
$q("UPDATE prenotazioni SET convenzione = NULL, stato = 'da_approvare' WHERE id = 9893");
$q("DELETE FROM convenzioni_scuole WHERE scuola_codice = 'ZZFS98000X'");
convenzione_valida($conn, 'ZZFS98000X', true);   // il registro è cambiato fuori dal servizio: si rilegge
$v98 = verifica_convenzioni_fsl($conn);
prova($v98['nuove_da_stipulare'] >= 1 && $st98(9893)['convenzione'] === 'no' && (int)$st98(9893)['conv_promemoria'] === 3, "verifica_convenzioni_fsl(): senza convenzione, da stipulare");
$EMAIL = [];
prova(email_richiesta_convenzione($conn, 9893, 'promemoria') && str_starts_with($EMAIL[0]['oggetto'], 'Promemoria: convenzione da inviare - Progetto FSL 98'), "email di promemoria della convenzione");
prova(invia_invito_valutazione($conn, 9893) && prenotazione_da_valutazione($conn, (string)$conn->query("SELECT valutazione_token FROM prenotazioni WHERE id = 9893")->fetch_assoc()['valutazione_token'])['evento_titolo'] === 'Progetto FSL 98', "invito alla scheda di valutazione e link personale");
$classi = $c_fsl->get(\App\Fsl\RegoleClasse::class);
prova($classi->prenotazioneDiClasse(true, null) === prenotazione_di_classe(true, null) && $classi->attivitaConclusaClasse(['data_turno' => '2000-01-01']) === attivita_conclusa_classe(['data_turno' => '2000-01-01']), "regole di classe: facciate e servizio");

// Registro nel pannello: file solo PAdES e percorsi dentro uploads/convenzioni/
$reg98 = $c_fsl->get(\App\Fsl\ServizioRegistroConvenzioni::class);
$nuova98 = $reg98->salvaDalPannello(['scuola_codice' => ['conv' => 'zzfs98000x'], 'data_stipula' => $giorni(0), 'scadenza' => $giorni(200), 'doc_nome' => ['Doc'], 'doc_email' => ['d@example.org']],
                                    ['file_convenzione' => ['error' => UPLOAD_ERR_NO_FILE], 'file_allegato' => ['error' => UPLOAD_ERR_NO_FILE]], 'prova98');
prova($nuova98->salvata && !$nuova98->modifica && $nuova98->codiceScuola === 'ZZFS98000X' && $nuova98->prenotazioniAggiornate === 1, "registro del pannello: nuova convenzione che copre la prenotazione");
prova($reg98->fileDaScaricare($nuova98->id, 'file_convenzione') === null && $reg98->fileDaScaricare($nuova98->id, 'docenti_json') === null, "nessun file da scaricare senza PDF firmato");
prova($reg98->elimina($nuova98->id)['scuola_codice'] === 'ZZFS98000X' && $reg98->elimina($nuova98->id) === null, "registro del pannello: convenzione eliminata");

// Pannello: riepilogo dell'anno scolastico e registro
$pann98 = $c_fsl->get(\App\Fsl\ServizioPannelloFsl::class);
$anno98 = $pann98->annoCorrente();
$riep98 = $pann98->riepilogo("$anno98-09-01", ($anno98 + 1) . '-08-31');
prova(isset($riep98['per_att'][9891]) && $riep98['per_att'][9891]['iscrizioni'] === 1 && $riep98['n_conv_mancanti'] >= 1 && array_keys($pann98->registro()) === ['vigore', 'archivio'], "pannello FSL: riepilogo dell'anno e registro");

foreach (["DELETE FROM convenzioni_compilate WHERE prenotazione_id = 9893", "DELETE FROM valutazioni_fsl WHERE prenotazione_id = 9893", "DELETE FROM prenotazioni WHERE id = 9893",
          "DELETE FROM turni WHERE id = 9892", "DELETE FROM progetti_dettagli WHERE evento_id = 9891", "DELETE FROM eventi WHERE id = 9891", "DELETE FROM pagine_eventi WHERE id = 9890",
          "DELETE FROM convenzioni_scuole WHERE scuola_codice = 'ZZFS98000X'", "DELETE FROM scuole WHERE codice = 'ZZFS98000X'"] as $sql) $q($sql);
