<?php
// Prove del modulo Iscritti (App\Iscritti): funzioni di inc/dati.php come facciate e servizi del pannello (iscritti, messaggi, scanner,
// badge, form builder, studenti delle classi, email massiva, promemoria e cron) nel container, con i servizi di Iscrizioni
// e Attestati (FSL: classi di App\Fsl). Chiamato da esegui.php: stesse variabili ($conn, $q, $EMAIL) e funzioni (prova, sezione).
// I dati di prova usano id 99xx.
sezione("Modulo Iscritti: facciate di inc/ e servizi del pannello");

// getPostiOccupati() sta in config.php, che queste prove non caricano: stessa query
if (!function_exists('getPostiOccupati')) {
    function getPostiOccupati($conn, $turno_id, $for_update = false) {
        $r = $conn->query("SELECT COALESCE(SUM(num_posti), 0) as totale FROM prenotazioni WHERE turno_id = " . (int)$turno_id . " AND IFNULL(stato, 'confermata') IN ('confermata', 'richiesta_conferma', 'da_approvare')");
        return (int)$r->fetch_assoc()['totale'];
    }
}

use App\Core\App as AppIsc;
use App\Iscritti\Operatore;

$area = (int)$conn->query("SELECT id FROM pagine_eventi WHERE visibile = 1 AND IFNULL(tipo_area, '') <> 'calendario' ORDER BY id LIMIT 1")->fetch_assoc()['id'];
$colore_area = (string)$conn->query("SELECT colore_primario FROM pagine_eventi WHERE id = $area")->fetch_assoc()['colore_primario'];
foreach (["DELETE FROM messaggi_prenotazioni WHERE prenotazione_id BETWEEN 9900 AND 9999", "DELETE FROM partecipanti_prenotazione WHERE prenotazione_id BETWEEN 9900 AND 9999",
          "DELETE FROM prenotazioni WHERE id BETWEEN 9900 AND 9999", "DELETE FROM turni WHERE id BETWEEN 99000 AND 99999", "DELETE FROM eventi WHERE id = 9901",
          "DELETE FROM campi_form WHERE nome_campo LIKE 'pr99%'", "DELETE FROM log_attivita WHERE azione IN ('Azione di massa iscritti', 'Modifica Presenza Check-in', 'Check-in annullato')"] as $sql) $q($sql);
$domani = date('Y-m-d', strtotime('+1 day'));
$q("INSERT INTO eventi (id, pagina_id, titolo, luogo, tipo, abilita_presenze, archiviato) VALUES (9901, $area, 'Prova Iscritti OOP', 'Aula 99', 'evento', 1, 0)");
$q("INSERT INTO turni (id, evento_id, nome_turno, data_turno, orario_inizio, orario_fine, max_posti, abilita_lista_attesa) VALUES
    (99011, 9901, 'Turno uno', '2999-03-01', '10:00:00', '12:00:00', 1, 1), (99012, 9901, 'Turno due', '2999-03-02', NULL, NULL, 50, 0), (99013, 9901, 'Domani', '$domani', '10:00:00', '12:00:00', 50, 0)");
$q("INSERT INTO prenotazioni (id, turno_id, codice_prenotazione, stato, presente, num_posti, nome, cognome, email, matricola, data_prenotazione) VALUES
    (9901, 99011, 'PRV99A', 'confermata', 0, 1, 'Anna', 'Rossi', 'anna99@prova.it', 'M99', '2026-10-01 10:00:00'),
    (9902, 99011, 'PRV99B', 'in_attesa', 0, 1, 'Bruno', 'Bianchi', 'bruno99@prova.it', '', '2026-10-01 10:05:00'),
    (9903, 99012, 'PRV99C', 'da_approvare', 0, 1, 'Carla', 'Verdi', 'carla99@prova.it', '', '2026-10-01 10:10:00'),
    (9904, 99012, 'PRV99D', 'annullata', 0, 1, 'Dario', 'Neri', 'dario99@prova.it', '', '2026-10-01 10:15:00'),
    (9905, 99013, 'PRV99E', 'confermata', 0, 1, 'Elena', 'Gialli', 'elena99@prova.it', '', '2026-10-01 10:20:00')");
$op = new Operatore(0, 'prova@example.org', '127.0.0.1');
$iscr = AppIsc::per($conn)->get(\App\Iscritti\ServizioIscritti::class);

// Funzioni di inc/dati.php (facciate)
$pr = get_prenotazione_con_turno_evento($conn, 9901);
prova($pr && $pr['evento_titolo'] === 'Prova Iscritti OOP' && $pr['turno_id'] === '99011' && $pr['nome_turno'] === 'Turno uno' && get_prenotazione_con_turno_evento($conn, 99999) === null, "get_prenotazione_con_turno_evento(): stringhe come prima");
$ta = get_turno_admin($conn, 99011);
prova($ta && $ta['evento_titolo'] === 'Prova Iscritti OOP' && !empty($ta['slug']) && $ta['max_posti'] === '1' && get_turno_admin($conn, 99999) === null, "get_turno_admin()");
$dest = get_destinatari_email_massiva($conn, $area, 99011);
prova(count($dest) === 1 && $dest[0]['email'] === 'anna99@prova.it' && count(get_destinatari_email_massiva($conn, $area)) >= 3, "get_destinatari_email_massiva(): solo confermate con email");
$q("INSERT INTO campi_form (pagina_id, evento_id, nome_campo, etichetta, tipo_campo, ordine) VALUES ($area, 9901, 'pr99_dieta', 'Dieta 99', 'text', 990)");
prova((get_campi_custom_export($conn, $area)['pr99_dieta'] ?? '') === 'Dieta 99', "get_campi_custom_export()");
$ck = get_prenotazione_per_checkin_admin($conn, 'PRV99A');
prova($ck && $ck['id'] === 9901 && $ck['evento_id'] === 9901 && get_prenotazione_per_checkin_admin($conn, 'NOPE') === null, "get_prenotazione_per_checkin_admin()");
$q("INSERT INTO messaggi_prenotazioni (id, prenotazione_id, mittente_tipo, mittente_id, messaggio, letto, data_invio) VALUES (99001, 9901, 'utente', NULL, 'Domanda 99', 0, '2026-10-02 08:00:00'), (99002, 9901, 'admin', 1, 'Risposta 99', 1, '2026-10-02 09:00:00')");
$mp = get_messaggi_per_prenotazioni($conn, [9901, 9902]);
prova(count($mp) === 1 && count($mp[9901]) === 2 && $mp[9901][0]['messaggio'] === 'Domanda 99' && get_messaggi_per_prenotazioni($conn, []) === [], "get_messaggi_per_prenotazioni()");
$inb = get_inbox_conversazioni($conn, $area);
$mia = array_values(array_filter($inb, fn($c) => $c['codice_prenotazione'] === 'PRV99A'))[0] ?? null;
prova($mia && $mia['messaggi_da_leggere'] === '1' && $mia['totale_messaggi'] === '2' && get_inbox_conversazioni($conn, $area, ' AND e.id = -1 ') === [], "get_inbox_conversazioni(): conteggi e filtro dei permessi");

// Presenza e azioni di massa
$EMAIL = [];
$iscr->segnaPresenza(9901, 1, $op);
prova((int)$conn->query("SELECT presente FROM prenotazioni WHERE id = 9901")->fetch_assoc()['presente'] === 1 && (int)$conn->query("SELECT COUNT(*) AS n FROM prenotazioni WHERE id = 9901 AND attestato_inviato = 1")->fetch_assoc()['n'] === 0, "segnaPresenza(): presenza registrata (turno non concluso: niente attestato)");
$r = $iscr->azioneDiMassa('promuovi', [9902, 9901], $area, '', $op);
prova($r->fatte === 1 && $r->saltate === 1 && $r->oltreCapienza === 1 && $conn->query("SELECT stato FROM prenotazioni WHERE id = 9902")->fetch_assoc()['stato'] === 'confermata', "azioneDiMassa('promuovi'): conferma, salta chi non è in attesa, avvisa oltre la capienza");
prova(str_contains($r->messaggio(), '1 prenotazioni promosse') && $r->tipo() === 'warning' && ($EMAIL[0]['a'] ?? '') === 'bruno99@prova.it' && str_contains($EMAIL[0]['oggetto'], 'Posto Confermato: Prova Iscritti OOP'), "azioneDiMassa(): messaggio ed email al promosso");
$r = $iscr->azioneDiMassa('approva', [9903], $area, '', $op);
prova($r->fatte === 1 && $conn->query("SELECT stato FROM prenotazioni WHERE id = 9903")->fetch_assoc()['stato'] === 'confermata', "azioneDiMassa('approva')");
$r = $iscr->azioneDiMassa('annulla', [9901, 9905], $area, ' AND e.id = -1 ', $op);
prova($r->fatte === 0 && $r->saltate === 2, "azioneDiMassa(): rispetta i permessi del gestore");
$EMAIL = [];
$r = $iscr->azioneDiMassa('annulla', [9901], $area, '', $op);
prova($r->fatte === 1 && $conn->query("SELECT stato FROM prenotazioni WHERE id = 9901")->fetch_assoc()['stato'] === 'annullata' && ($EMAIL[0]['oggetto'] ?? '') === 'Prenotazione Annullata: Prova Iscritti OOP', "azioneDiMassa('annulla'): annulla e avvisa");
prova(in_array($conn->query("SELECT stato FROM prenotazioni WHERE id = 9902")->fetch_assoc()['stato'], ['confermata', 'richiesta_conferma'], true), "annullamento: il turno liberato passa per promuovi_lista_attesa()");
$an = $iscr->annulla(9901);
prova($an['esito'] === 'errore' && $iscr->annulla(99999)['esito'] === 'non_trovata', "annulla(): già annullata → errore, inesistente → non trovata");
$iscr->rifiuta(9903);
prova($conn->query("SELECT stato FROM prenotazioni WHERE id = 9903")->fetch_assoc()['stato'] === 'rifiutata', "rifiuta()");
$iscr->elimina(9904);
prova(!$conn->query("SELECT 1 FROM prenotazioni WHERE id = 9904")->num_rows, "elimina(): prenotazione cancellata");

// Prenotazione manuale e modifica
$EMAIL = [];
$man = $iscr->prenotazioneManuale(99012, 'Mario', 'Prova', 'mario99@prova.it', 'M1', 2, ['custom_pr99_dieta' => 'Vegana', 'custom_vuoto' => ''], []);
$idm = (int)$conn->query("SELECT MAX(id) AS m FROM prenotazioni WHERE turno_id = 99012")->fetch_assoc()['m'];
$rm = $conn->query("SELECT * FROM prenotazioni WHERE id = $idm")->fetch_assoc();
prova($man && $man->tipo === 'success' && $rm['stato'] === 'confermata' && (int)$rm['num_posti'] === 2 && $rm['dati_custom_json'] === '{"pr99_dieta":"Vegana"}' && str_starts_with($rm['codice_prenotazione'], strtoupper(substr((string)$conn->query("SELECT slug FROM pagine_eventi WHERE id = $area")->fetch_assoc()['slug'], 0, 2)) . '-'), "prenotazioneManuale(): prenotazione confermata con i campi del modulo");
prova(($EMAIL[0]['a'] ?? '') === 'mario99@prova.it' && $EMAIL[0]['oggetto'] === 'Conferma Prenotazione: Prova Iscritti OOP', "prenotazioneManuale(): email di conferma all'indirizzo in minuscolo");
$iscr->modifica($idm, 99011, 'Marco', 'Prova', 'marco99@prova.it', '', ['custom_nuovo' => 'si'], []);
$rm = $conn->query("SELECT * FROM prenotazioni WHERE id = $idm")->fetch_assoc();
prova($rm['turno_id'] === '99011' && $rm['nome'] === 'Marco' && $rm['dati_custom_json'] === '{"pr99_dieta":"Vegana","nuovo":"si"}', "modifica(): sposta di turno e unisce i dati del modulo");

// Messaggi
$EMAIL = [];
$msg = AppIsc::per($conn)->get(\App\Iscritti\ServizioMessaggi::class);
$op1 = new Operatore(1, 'prova@example.org', '127.0.0.1');
prova($msg->rispondiDaInbox(9905, '<p>Ok <b>si</b><script>x()</script></p>', $op1) && !$msg->rispondiDaInbox(9905, ' ', $op1), "rispondiDaInbox(): invia, ignora il testo vuoto");
$mm = $conn->query("SELECT * FROM messaggi_prenotazioni WHERE prenotazione_id = 9905")->fetch_assoc();
prova($mm['messaggio'] === '<p>Ok <b>si</b>x()</p>' && (int)$mm['letto'] === 1 && ($EMAIL[0]['a'] ?? '') === 'elena99@prova.it' && str_contains($EMAIL[0]['oggetto'], 'Nuovo messaggio da ') && str_contains($EMAIL[0]['corpo'], "\n                <p>Hai ricevuto un nuovo messaggio da"), "rispondiDaInbox(): testo ripulito, già letto, email con lo stesso corpo di prima");
prova($msg->inviaDaIscritti(9905, 'Ciao', $op1) && (int)$conn->query("SELECT letto FROM messaggi_prenotazioni WHERE prenotazione_id = 9905 AND messaggio = 'Ciao'")->fetch_assoc()['letto'] === 0 && str_contains($EMAIL[1]['corpo'], "\n                    <p>Hai ricevuto un nuovo messaggio da"), "inviaDaIscritti(): messaggio da leggere, corpo con il rientro di iscritti.php");
$msg->segnaLetti(9901);
prova($msg->nonLetti($area) >= 0 && (int)$conn->query("SELECT COUNT(*) AS n FROM messaggi_prenotazioni WHERE prenotazione_id = 9901 AND letto = 0")->fetch_assoc()['n'] === 0, "segnaLetti() e nonLetti()");

// Scanner
$sc = AppIsc::per($conn)->get(\App\Iscritti\ServizioScanner::class);
$turni_sc = $sc->turni($area, '');
prova(isset($turni_sc[99011], $turni_sc[99012]) && $turni_sc[99011]['evento_titolo'] === 'Prova Iscritti OOP', "scanner: turni con check-in attivo dell'area");
$q("UPDATE prenotazioni SET stato = 'confermata', presente = 0, codice_prenotazione = 'PRV99A' WHERE id = 9901");
$ris = $sc->registra('checkin', 99011, $area, $turni_sc, ' prv99a ', 0, false, false, $op1);
prova($ris['esito'] === 'ok' && $ris['nome'] === 'Anna Rossi' && $ris['presenti'] >= 1 && $sc->registra('checkin', 99011, $area, $turni_sc, 'PRV99A', 0, false, false, $op1)['esito'] === 'gia', "scanner: ingresso consentito, poi già registrato");
prova($sc->registra('checkin', 99011, $area, $turni_sc, 'PRV99E', 0, false, false, $op1)['esito'] === 'turno' && $sc->registra('checkin', 99011, $area, $turni_sc, 'PRV99D', 0, false, false, $op1)['titolo'] === 'Biglietto non trovato' && $sc->registra('checkin', 99011, $area, $turni_sc, '!!', 0, false, false, $op1)['titolo'] === 'QR non riconosciuto', "scanner: turno diverso, biglietto non trovato, codice non valido");
prova($sc->registra('presenza', 99011, $area, $turni_sc, '', 9901, false, false, $op1)['titolo'] === 'Presenza annullata' && $conn->query("SELECT data_presenza FROM prenotazioni WHERE id = 9901")->fetch_assoc()['data_presenza'] === null, "scanner: correzione della presenza");
$st = $sc->stato(99011);
prova($st['totale'] >= 1 && $st['lista'][0]['nome'] !== '' && isset($st['posti_totali']), "scanner: stato del turno");

// Badge
$bd = AppIsc::per($conn)->get(\App\Iscritti\ServizioBadge::class);
$b = $bd->genera(99011, $area, true, true, ['Ospite'], ['Speciale'], ['vip']);
$ruoli = array_column($b['badge'], 'ruolo');
prova($b['info']['titolo'] === 'Prova Iscritti OOP' && in_array('STUDENTE - M99', $ruoli, true) && in_array('PARTECIPANTE', $ruoli, true) && end($ruoli) === 'VIP', "badge: iscritti e badge manuali (lo staff è provato nei test PHPUnit)");
prova($bd->genera(1, $area, true, true, [], [], []) === ['info' => null, 'badge' => []], "badge: turno inesistente");
prova(count(array_filter($bd->eventiConTurni($area, ''), fn($e) => $e['id'] === '9901' && count($e['turni']) === 3)) === 1, "badge: eventi con i loro turni");

// Form builder
$fb = AppIsc::per($conn)->get(\App\Iscritti\ServizioFormBuilder::class);
$fb->aggiungi($area, 9901, "Pr99 Dieta O'Brien", 'select', ' A, B ', true, 991, 0, '');
$cf = $conn->query("SELECT * FROM campi_form WHERE etichetta LIKE 'Pr99 Dieta%'")->fetch_assoc();
prova($cf && $cf['nome_campo'] === 'pr99_dieta_obrien' && $cf['opzioni_select'] === 'A, B' && (int)$cf['obbligatorio'] === 1 && (int)$cf['evento_id'] === 9901 && $cf['condizione_json'] === null, "form builder: aggiungi campo con nome tecnico dall'etichetta");
$fb->modifica((int)$cf['id'], 0, 'Pr99 Mod', 'radio', 'Si,No', false, (int)$cf['id'], 'Si');
$cf2 = $conn->query("SELECT * FROM campi_form WHERE id = {$cf['id']}")->fetch_assoc();
prova($cf2['tipo_campo'] === 'radio' && $cf2['evento_id'] === null && $cf2['condizione_json'] === '{"se_id":' . $cf['id'] . ',"se_val":"Si"}' && $cf2['nome_campo'] === 'pr99_dieta_obrien', "form builder: modifica (nome tecnico invariato)");
$fb->sposta((int)$cf['id'], true);
prova((int)$conn->query("SELECT ordine FROM campi_form WHERE id = {$cf['id']}")->fetch_assoc()['ordine'] === 976 && $fb->campoAutorizzato((int)$cf['id'], $area, true, []) && !$fb->campoAutorizzato((int)$cf['id'], $area, false, [9901]), "form builder: sposta e permessi");
$fb->elimina((int)$cf['id']);

// Studenti delle classi
$cl = AppIsc::per($conn)->get(\App\Iscritti\ServizioClassi::class);
$esito = $cl->aggiungi(9905, [['cognome' => 'Alfa', 'nome' => 'Aldo'], ['cognome' => 'ALFA', 'nome' => 'aldo']], []);
prova($esito['aggiunti'] === 2 && $esito['totale'] === 2, "classi: aggiunge in fondo all'elenco (i doppioni nello stesso inserimento restano, come prima)");
$esito = $cl->aggiungi(9905, [['cognome' => 'ALFA', 'nome' => 'ALDO'], ['cognome' => 'Beta', 'nome' => 'Bea']], get_partecipanti_prenotazione($conn, 9905));
prova($esito['aggiunti'] === 1 && $esito['totale'] === 3, "classi: chi c'è già non si aggiunge");
$sid = (int)$conn->query("SELECT id FROM partecipanti_prenotazione WHERE prenotazione_id = 9905 AND cognome = 'Beta'")->fetch_assoc()['id'];
$cl->escludi($sid, 9905, true);
$cl->elimina($sid, 99999);
prova((int)$conn->query("SELECT escluso FROM partecipanti_prenotazione WHERE id = $sid")->fetch_assoc()['escluso'] === 1, "classi: escludi, e non si tocca lo studente di un'altra prenotazione");
$cl->svuota(9905);
prova(!$conn->query("SELECT 1 FROM partecipanti_prenotazione WHERE prenotazione_id = 9905")->num_rows, "classi: svuota l'elenco");

// Email massiva
$EMAIL = [];
$mass = AppIsc::per($conn)->get(\App\Iscritti\ServizioEmailMassiva::class);
$coda = $mass->prepara($area, 99011, 'Oggetto 99', '<p>Testo 99</p>');
$tot = $coda['totale'];
$coda = $mass->invia($coda);
prova($tot >= 1 && $coda['inviate'] === min($tot, 10) && count($EMAIL) === $coda['inviate'] && $EMAIL[0]['oggetto'] === 'Oggetto 99', "email massiva: invia a gruppi di 10 agli iscritti confermati");

// Promemoria pre-evento (cron_reminders)
$EMAIL = [];
AppIsc::per($conn)->get(\App\Iscritti\ServizioPromemoria::class)->invia();
$pm = array_values(array_filter($EMAIL, fn($e) => $e['a'] === 'elena99@prova.it'));
prova(count($pm) === 1 && str_contains($pm[0]['corpo'], 'Prova Iscritti OOP') && (int)$conn->query("SELECT reminder_inviato FROM prenotazioni WHERE id = 9905")->fetch_assoc()['reminder_inviato'] === 1, "promemoria: email agli iscritti del turno di domani, segnata come inviata");
$EMAIL = [];
AppIsc::per($conn)->get(\App\Iscritti\ServizioPromemoria::class)->invia();
prova(!array_filter($EMAIL, fn($e) => $e['a'] === 'elena99@prova.it'), "promemoria: non si ripete");

// Cron: email dopo l'evento, auto-archiviazione, conservazione
$cron = AppIsc::per($conn)->get(\App\Iscritti\ServizioCron::class);
$sys0 = $conn->query("SELECT email_attestato_oggetto, email_attestato_corpo, email_sondaggio_oggetto, email_sondaggio_corpo FROM impostazioni_sistema WHERE id = 1")->fetch_assoc();
$q("UPDATE impostazioni_sistema SET email_attestato_oggetto = 'Att {TITOLO_EVENTO}', email_attestato_corpo = '<p>Att {NOME}</p>', email_sondaggio_oggetto = 'Son {TITOLO_EVENTO}', email_sondaggio_corpo = '<p>Son {NOME} {LINK_AREA_PERSONALE}</p>' WHERE id = 1");
$q("INSERT INTO turni (id, evento_id, nome_turno, data_turno, orario_fine, max_posti) VALUES (99014, 9901, 'Ieri', '2000-01-01', '12:00:00', 50)");
$q("INSERT INTO prenotazioni (id, turno_id, codice_prenotazione, stato, presente, nome, cognome, email, email_post_evento_inviata) VALUES (9950, 99014, 'PRV99F', 'confermata', 1, 'Vera', 'Ieri', 'vera99@prova.it', 0)");
$EMAIL = [];
$riga = $cron->emailPostEvento(date('Y-m-d H:i:s'), 'https://x.it/eventi');
$mie = array_values(array_filter($EMAIL, fn($e) => $e['a'] === 'vera99@prova.it'));
prova(str_contains($riga, 'Inviate ') && count($mie) === 2 && $mie[0]['oggetto'] === 'Att Prova Iscritti OOP' && str_contains($mie[1]['corpo'], "<a href='https://x.it/eventi/area_personale.php'") && (int)$conn->query("SELECT email_post_evento_inviata FROM prenotazioni WHERE id = 9950")->fetch_assoc()['email_post_evento_inviata'] === 1, "cron: email di attestato e sondaggio dopo l'evento, una volta sola");
$q("UPDATE impostazioni_sistema SET email_attestato_oggetto = " . ($sys0['email_attestato_oggetto'] === null ? 'NULL' : "'" . $conn->real_escape_string($sys0['email_attestato_oggetto']) . "'") . ", email_attestato_corpo = " . ($sys0['email_attestato_corpo'] === null ? 'NULL' : "'" . $conn->real_escape_string($sys0['email_attestato_corpo']) . "'") . ", email_sondaggio_oggetto = " . ($sys0['email_sondaggio_oggetto'] === null ? 'NULL' : "'" . $conn->real_escape_string($sys0['email_sondaggio_oggetto']) . "'") . ", email_sondaggio_corpo = " . ($sys0['email_sondaggio_corpo'] === null ? 'NULL' : "'" . $conn->real_escape_string($sys0['email_sondaggio_corpo']) . "'") . " WHERE id = 1");
prova(preg_match('/^- Auto-archiviati \d+ eventi scaduti\.\n$/', $cron->autoArchiviazione()) === 1, "cron: auto-archiviazione");
$q("UPDATE prenotazioni SET dati_custom_json = '{\"a\":\"b\"}' WHERE id = 9950");
prova(str_contains($cron->conservazionePrenotazioni(1), '- Conservazione prenotazioni: ') && $conn->query("SELECT nome, email, dati_custom_json FROM prenotazioni WHERE id = 9950")->fetch_assoc() === ['nome' => 'V.', 'email' => '', 'dati_custom_json' => null], "cron: conservazione, prenotazione anonimizzata");
prova(preg_match('/^- Conservazione registri: eliminati \d+ accessi e \d+ email più vecchi di 12 mesi, \d+ azioni più vecchie di 24 mesi\.\n$/', $cron->conservazioneRegistri(12, 24)) === 1, "cron: conservazione dei registri");

// Esportazione
$righe_e = AppIsc::per($conn)->get(\App\Iscritti\IscrittiRepository::class)->perEsportazione($area, 99011, '');
ob_start(); \App\Iscritti\Vista\EsportaIscritti::csv($righe_e, ['pr99_dieta' => 'Dieta 99'], false); $csv = ob_get_clean();
prova(str_starts_with($csv, 'Codice,Stato,Presenza,Posti,Nome,Cognome,Matricola,Email,Evento,Turno,Data,Ora,"Dieta 99","Data Registrazione"') && str_contains($csv, 'PRV99A'), "esportazione CSV degli iscritti");

// Pulizia
foreach (["DELETE FROM messaggi_prenotazioni WHERE prenotazione_id BETWEEN 9900 AND 9999", "DELETE FROM partecipanti_prenotazione WHERE prenotazione_id BETWEEN 9900 AND 9999",
          "DELETE FROM prenotazioni WHERE id BETWEEN 9900 AND 9999 OR turno_id BETWEEN 99000 AND 99999", "DELETE FROM turni WHERE id BETWEEN 99000 AND 99999", "DELETE FROM eventi WHERE id = 9901",
          "DELETE FROM campi_form WHERE nome_campo LIKE 'pr99%'"] as $sql) $q($sql);
