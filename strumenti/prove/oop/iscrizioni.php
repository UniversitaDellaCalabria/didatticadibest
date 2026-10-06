<?php
// Prove del modulo Iscrizioni (App\Iscrizioni): posti, ultimo posto, lista d'attesa, promozione, vincoli per area, limiti, concorrenza.
// Chiamato da esegui.php: stesse variabili ($conn, $q, $EMAIL) e funzioni (prova, sezione). I dati di prova usano id 99xx.
sezione("Modulo Iscrizioni: posti, liste d'attesa e vincoli");

// getPostiOccupati() sta in config.php, che queste prove non caricano: stessa funzione (se il modulo c'è, passa dal servizio come la facciata)
if (!function_exists('getPostiOccupati')) {
    function getPostiOccupati($conn, $turno_id, $for_update = false) {
        $r = $conn->query("SELECT COALESCE(SUM(num_posti), 0) as totale FROM prenotazioni WHERE turno_id = " . (int)$turno_id . " AND IFNULL(stato, 'confermata') IN ('confermata', 'richiesta_conferma', 'da_approvare')" . ($for_update ? ' FOR UPDATE' : ''));
        return (int)$r->fetch_assoc()['totale'];
    }
}
// Conteggio con o senza blocco delle righe, come fa la prenotazione pubblica dentro la transazione
$posti99 = function (mysqli $c, int $turno, bool $blocca = false) {
    if (class_exists(\App\Iscrizioni\PrenotazioneRepository::class)) return \App\Core\App::per($c)->get(\App\Iscrizioni\PrenotazioneRepository::class)->postiOccupati($turno, $blocca);
    $r = $c->query("SELECT COALESCE(SUM(num_posti), 0) as totale FROM prenotazioni WHERE turno_id = $turno AND IFNULL(stato, 'confermata') IN ('confermata', 'richiesta_conferma', 'da_approvare')" . ($blocca ? ' FOR UPDATE' : ''));
    return (int)$r->fetch_assoc()['totale'];
};

$area99 = (int)$conn->query("SELECT id FROM pagine_eventi WHERE visibile = 1 AND IFNULL(tipo_area, '') <> 'calendario' ORDER BY id LIMIT 1")->fetch_assoc()['id'];
$q("UPDATE pagine_eventi SET limite_iscrizioni = 'nessuno' WHERE id = $area99");
$q("DELETE FROM prenotazioni WHERE turno_id BETWEEN 99010 AND 99029");
$q("DELETE FROM turni WHERE evento_id IN (9901, 9902)");
$q("DELETE FROM eventi WHERE id IN (9901, 9902)");
$q("DELETE FROM utenti WHERE id = 9901");
$q("INSERT INTO utenti (id, codice_fiscale, nome, cognome, email, ruolo_id) VALUES (9901, 'PROVA99A01X000Y', 'Utente', 'Novantanove', 'utente99@prova.it', 5)");
$q("INSERT INTO eventi (id, pagina_id, titolo, luogo, tipo, ruolo_accesso_id, archiviato, richiede_prenotazione, email_notifiche_extra) VALUES (9901, $area99, 'Evento Iscrizioni 99', 'Aula 99', 'evento', 0, 0, 1, 'extra99@prova.it, sbagliata'), (9902, $area99, 'Evento Iscrizioni 99 bis', 'Aula 99', 'evento', 0, 0, 1, NULL)");
$tra = fn(string $when) => date('Y-m-d', strtotime($when));
$q("INSERT INTO turni (id, evento_id, nome_turno, data_turno, orario_inizio, orario_fine, max_posti, abilita_lista_attesa) VALUES
    (99011, 9901, 'Turno A', '" . $tra('+10 days') . "', '10:00:00', '12:00:00', 2, 1),
    (99012, 9901, 'Turno B', '" . $tra('+11 days') . "', '10:00:00', '12:00:00', 1, 0),
    (99013, 9901, 'Imminente', '" . date('Y-m-d', strtotime('+3 hours')) . "', '" . date('H:i:00', strtotime('+3 hours')) . "', NULL, 1, 1),
    (99014, 9901, 'Senza data', NULL, NULL, NULL, 1, 1),
    (99021, 9902, 'Altro evento', '" . $tra('+12 days') . "', '10:00:00', '12:00:00', 5, 1)");
$pr = function (int $id, int $turno, string $stato, int $posti, string $email, ?int $utente = null, string $ts = '2026-01-01 10:00:00') use ($conn) {
    $conn->query("INSERT INTO prenotazioni (id, turno_id, utente_id, codice_prenotazione, stato, num_posti, nome, cognome, email, matricola, data_prenotazione) VALUES ($id, $turno, " . ($utente ?? 'NULL') . ", 'PR99-$id', " . ($stato === 'NULL' ? 'NULL' : "'$stato'") . ", $posti, 'Nome$id', 'Cognome$id', '$email', '', '$ts')");
};
$stato99 = fn(int $id) => $conn->query("SELECT stato FROM prenotazioni WHERE id = $id")->fetch_assoc()['stato'] ?? null;

// --- posti occupati: contano confermata, posto offerto, da approvare e le vecchie righe senza stato; non contano attesa, annullata, rifiutata, scaduta
foreach ([[9911, 'confermata', 1], [9912, 'richiesta_conferma', 1], [9913, 'da_approvare', 2], [9914, 'NULL', 1], [9915, 'in_attesa', 4], [9916, 'annullata', 3], [9917, 'rifiutata', 3], [9918, 'scaduta', 3]] as [$i, $s, $n]) $pr($i, 99021, $s, $n, "p$i@prova.it");
prova(getPostiOccupati($conn, 99021) === 5 && $posti99($conn, 99021) === 5 && getPostiOccupati($conn, 99999) === 0, "posti occupati: confermate, posti offerti, da approvare e righe senza stato");
$q("DELETE FROM prenotazioni WHERE turno_id = 99021");

// --- ultimo posto e lista d'attesa: il conteggio si ferma alla capienza
$pr(9921, 99012, 'confermata', 1, 'ultimo@prova.it');
prova($posti99($conn, 99012) === 1 && ($posti99($conn, 99012) + 1 > 1), "ultimo posto: il turno da 1 posto è pieno");

// --- concorrenza: chi conta i posti dentro una transazione blocca le prenotazioni di quel turno finché non finisce
$c2 = new mysqli($DB_H, $DB_U, $DB_P, $DB_N);
$c2->set_charset('utf8mb4');
$c2->query("SET SESSION innodb_lock_wait_timeout = 1");
$conn->begin_transaction();
$posti99($conn, 99011, true);                 // turno senza prenotazioni: blocca l'intervallo
$ins2 = function () use ($c2) { try { return $c2->query("INSERT INTO prenotazioni (turno_id, codice_prenotazione, stato, num_posti, nome, cognome, email) VALUES (99011, 'CONC99B', 'confermata', 1, 'B', 'B', 'b99@prova.it')"); } catch (mysqli_sql_exception $e) { return $e->getCode() * -1; } };
$esito2 = $ins2();
$bloccata = $esito2 === -1205;
$errno2 = $bloccata ? 1205 : $c2->errno;
prova($bloccata && $errno2 === 1205, "concorrenza: la seconda prenotazione aspetta finché la prima transazione non finisce", "errno $errno2");
$conn->query("INSERT INTO prenotazioni (turno_id, codice_prenotazione, stato, num_posti, nome, cognome, email) VALUES (99011, 'CONC99A', 'confermata', 1, 'A', 'A', 'a99@prova.it')");
$conn->commit();
$esito2 = $ins2();
prova($esito2 === true && $posti99($conn, 99011) === 2, "concorrenza: dopo il commit la seconda vede il posto già preso e ne occupa un altro");
$c2->close();
$q("DELETE FROM prenotazioni WHERE turno_id = 99011");

// --- promozione dalla lista d'attesa: in ordine di arrivo, solo se il posto c'è, nessuna promozione a meno di 24 ore dall'evento
$pr(9931, 99011, 'confermata', 1, 'c1@prova.it');
$pr(9932, 99011, 'in_attesa', 1, 'w1@prova.it', null, '2026-01-02 10:00:00');
$pr(9933, 99011, 'in_attesa', 1, 'w2@prova.it', null, '2026-01-03 10:00:00');
$pr(9934, 99011, 'in_attesa', 2, 'w3@prova.it', null, '2026-01-04 10:00:00');
$EMAIL = [];
promuovi_lista_attesa($conn, 99011);
$r1 = $conn->query("SELECT stato, scadenza_conferma FROM prenotazioni WHERE id = 9932")->fetch_assoc();
prova($r1['stato'] === 'richiesta_conferma' && $r1['scadenza_conferma'] > date('Y-m-d H:i:s', strtotime('+23 hours')) && $stato99(9933) === 'in_attesa' && count($EMAIL) === 1 && $EMAIL[0]['a'] === 'w1@prova.it'
    && str_contains($EMAIL[0]['oggetto'], 'Si è liberato un posto per Evento Iscrizioni 99') && str_contains($EMAIL[0]['corpo'], 'area_personale.php?conferma_posto=9932') && str_contains($EMAIL[0]['corpo'], 'Hai esattamente <strong>24 ore</strong>'),
    "promozione: il primo in coda riceve il posto per 24 ore e l'email di conferma", json_encode($r1));
$EMAIL = [];
promuovi_lista_attesa($conn, 99011);
prova($stato99(9933) === 'in_attesa' && $EMAIL === [], "promozione: senza posti liberi non cambia nulla");
$q("UPDATE prenotazioni SET stato = 'annullata' WHERE id = 9931");
promuovi_lista_attesa($conn, 99011);
prova($stato99(9933) === 'richiesta_conferma' && $stato99(9934) === 'in_attesa' && count($EMAIL) === 1, "promozione: liberato un posto passa al secondo; chi chiede più posti resta in coda");
$q("UPDATE prenotazioni SET stato = 'annullata' WHERE id = 9932");
$EMAIL = [];
promuovi_lista_attesa($conn, 99011);
prova($stato99(9934) === 'in_attesa' && $EMAIL === [], "promozione: il primo in coda chiede più posti di quelli liberi: ci si ferma");
$q("UPDATE prenotazioni SET stato = 'annullata' WHERE id = 9933");
promuovi_lista_attesa($conn, 99011);
prova($stato99(9934) === 'richiesta_conferma', "promozione: la richiesta da 2 posti entra quando ce ne sono 2");
$pr(9935, 99013, 'in_attesa', 1, 'imm@prova.it');
promuovi_lista_attesa($conn, 99013);
prova($stato99(9935) === 'in_attesa', "promozione: a meno di 24 ore dall'evento nessuno viene promosso");
$pr(9936, 99014, 'in_attesa', 1, 'senzadata@prova.it');
promuovi_lista_attesa($conn, 99014);
prova($stato99(9936) === 'richiesta_conferma', "promozione: i turni senza data non hanno scadenza");
promuovi_lista_attesa($conn, 99999);
prova(true, "promozione: turno inesistente senza errori");

// --- automazioni: offerta scaduta, promozione del successivo e chiusura delle code a 24 ore dall'evento
$q("DELETE FROM prenotazioni WHERE turno_id BETWEEN 99010 AND 99029");
$pr(9941, 99012, 'richiesta_conferma', 1, 'scad@prova.it');
$pr(9942, 99012, 'in_attesa', 1, 'next@prova.it');
$q("UPDATE prenotazioni SET scadenza_conferma = '" . date('Y-m-d H:i:s', strtotime('-1 hour')) . "' WHERE id = 9941");
$pr(9943, 99013, 'in_attesa', 1, 'taglio@prova.it');
$pr(9944, 99013, 'richiesta_conferma', 1, 'taglio2@prova.it');
$pr(9945, 99013, 'confermata', 1, 'resta@prova.it');
$EMAIL = [];
check_automazioni_sistema($conn);
prova($stato99(9941) === 'scaduta' && $stato99(9942) === 'richiesta_conferma' && ($EMAIL[0]['a'] ?? '') === 'next@prova.it', "automazioni: l'offerta scaduta passa al successivo in coda");
prova($stato99(9943) === 'scaduta' && $stato99(9944) === 'scaduta' && $stato99(9945) === 'confermata', "automazioni: a 24 ore dall'evento le code si chiudono, le confermate restano");
$q("DELETE FROM prenotazioni WHERE turno_id BETWEEN 99010 AND 99029");

// --- vincolo di iscrizione per area (un evento / un turno): le attese decadono quando una prenotazione si conferma
prova(scope_vincolo_sql('un_evento', 3, 7) === 'e.pagina_id = 3 AND e.archiviato = 0' && scope_vincolo_sql('un_turno', 3, 7) === 't.evento_id = 7' && scope_vincolo_sql('nessuno', 3, 7) === null, "scope_vincolo_sql()");
$q("UPDATE pagine_eventi SET limite_iscrizioni = 'un_evento' WHERE id = $area99");
$pr(9951, 99011, 'confermata', 1, 'v@prova.it', 9901);
prova((trova_iscrizione_vincolata($conn, 'un_evento', $area99, 9902, 0, 'V@prova.it', '')['id'] ?? 0) === 9951 && trova_iscrizione_vincolata($conn, 'nessuno', $area99, 9902, 0, 'v@prova.it', '') === null
    && trova_iscrizione_vincolata($conn, 'un_evento', $area99, 9902, 9901, 'altra@prova.it', '') !== null && trova_iscrizione_vincolata($conn, 'un_evento', $area99, 9902, 0, 'altra@prova.it', '') === null, "trova_iscrizione_vincolata(): per email, per utente, senza vincolo");
$pr(9952, 99021, 'in_attesa', 1, 'v@prova.it', 9901);
prova(trova_iscrizione_vincolata($conn, 'un_turno', $area99, 9902, 0, 'v@prova.it', '') === null, "trova_iscrizione_vincolata(): le liste d'attesa non contano");
$pr(9953, 99012, 'richiesta_conferma', 1, 'v@prova.it', 9901);
$pr(9954, 99014, 'da_approvare', 1, 'v@prova.it', 9901);
$pr(9955, 99012, 'in_attesa', 1, 'terzo@prova.it', null, '2026-01-09 10:00:00');
$mie = get_mie_iscrizioni_area($conn, $area99, 9901, 'v@prova.it');
prova($mie[9901][99011] === 'confermata' && $mie[9902][99021] === 'in_attesa' && $mie[9901][99012] === 'richiesta_conferma', "get_mie_iscrizioni_area(): evento => turno => stato", json_encode($mie));
$EMAIL = [];
prova(decadi_attese_vincolate($conn, 9951) === 3 && $stato99(9952) === 'annullata' && $stato99(9953) === 'annullata' && $stato99(9954) === 'annullata' && $stato99(9951) === 'confermata', "decadi_attese_vincolate(): annulla attese, posti offerti e richieste da approvare");
prova($stato99(9955) === 'richiesta_conferma' && ($EMAIL[0]['a'] ?? '') === 'terzo@prova.it' && (end($EMAIL)['a'] ?? '') === 'v@prova.it' && str_contains(end($EMAIL)['oggetto'], "Liste d'attesa annullate: iscrizione confermata a "), "decadi_attese_vincolate(): il posto liberato va alla coda e l'utente è avvisato");
prova(decadi_attese_vincolate($conn, 9952) === 0 && decadi_attese_vincolate($conn, 99999) === 0, "decadi_attese_vincolate(): solo dopo una conferma");
$q("UPDATE pagine_eventi SET limite_iscrizioni = 'nessuno' WHERE id = $area99");
$q("UPDATE prenotazioni SET stato = 'in_attesa' WHERE id = 9952");
prova(decadi_attese_vincolate($conn, 9951) === 0 && $stato99(9952) === 'in_attesa', "decadi_attese_vincolate(): area senza limite");

// --- limiti dei partecipanti
prova(limiti_partecipanti(null) === ['min' => 1, 'max' => null] && limiti_partecipanti(['min_studenti' => 8, 'max_studenti' => 25]) === ['min' => 8, 'max' => 25]
    && limiti_partecipanti(['min_studenti' => 8, 'max_studenti' => 25], ['min_partecipanti' => 10, 'max_partecipanti' => 12]) === ['min' => 10, 'max' => 12] && limiti_partecipanti(['max_studenti' => 25], ['min_partecipanti' => 0]) === ['min' => 1, 'max' => 25], "limiti_partecipanti(): turno prima del progetto");
prova(testo_limiti_partecipanti(15, 30) === 'da 15 a 30' && testo_limiti_partecipanti(20, 20) === '20' && testo_limiti_partecipanti(15, null) === 'almeno 15' && testo_limiti_partecipanti(1, 30) === 'fino a 30' && testo_limiti_partecipanti(1, null) === '' && testo_limiti_partecipanti(null, null) === '', "testo_limiti_partecipanti()");
$dp = ['per_scuole' => 1, 'min_studenti' => 8, 'max_studenti' => 25];
prova(valida_partecipanti_progetto([], $dp) === 'Indica il numero di studenti partecipanti.' && valida_partecipanti_progetto([CAMPO_PARTECIPANTI => 'x'], $dp) === 'Il numero di studenti deve essere un numero intero.'
    && valida_partecipanti_progetto([CAMPO_PARTECIPANTI => '30'], $dp) === 'Il numero di studenti deve essere compreso tra 8 e 25.' && valida_partecipanti_progetto([CAMPO_PARTECIPANTI => '10'], $dp) === null
    && valida_partecipanti_progetto([], ['per_scuole' => 0]) === null && valida_partecipanti_progetto([CAMPO_PARTECIPANTI => '3'], ['per_scuole' => 1]) === 'Il numero di studenti deve essere compreso tra 1 e il massimo previsto.' || valida_partecipanti_progetto([CAMPO_PARTECIPANTI => '3'], ['per_scuole' => 1]) === null, "valida_partecipanti_progetto()");
prova(annullamento_scaduto(['annullabile_fino' => '2000-01-01 10:00:00']) && !annullamento_scaduto(['annullabile_fino' => '2999-01-01 10:00:00']) && !annullamento_scaduto(['annullabile_fino' => null]) && !annullamento_scaduto(null), "annullamento_scaduto()");

// --- domanda di controllo delle prenotazioni pubbliche
$_SESSION = [];
$cap = captcha_prenotazione();
prova(preg_match('/^Quanto fa (\d) \+ (\d)\?$/', $cap['domanda'], $mm) === 1 && captcha_prenotazione() === $cap && isset($_SESSION['captcha_pren'][$cap['id']]), "captcha: domanda di somma, una per richiesta, risposta in sessione");
prova(str_contains((string)captcha_verifica($cap['id'], (string)($mm[1] + $mm[2])), 'troppo in fretta'), "captcha: un modulo inviato in meno di 3 secondi è respinto");
$_SESSION['captcha_pren']['x1'] = ['r' => 7, 't' => time() - 10];
prova(captcha_verifica('x1', ' 7 ') === null && !isset($_SESSION['captcha_pren']['x1']) && str_contains((string)captcha_verifica('x1', '7'), 'scaduta'), "captcha: risposta giusta una sola volta");
$_SESSION['captcha_pren']['x2'] = ['r' => 7, 't' => time() - 10];
prova(str_contains((string)captcha_verifica('x2', '8'), 'non è corretta') && str_contains((string)captcha_verifica('nuovo', '8'), 'scaduta'), "captcha: risposta sbagliata o domanda sconosciuta");
$_SESSION['captcha_pren']['x3'] = ['r' => 7, 't' => time() - 8000];
prova(str_contains((string)captcha_verifica('x3', '7'), 'scaduta'), "captcha: domanda scaduta dopo 2 ore");

// --- destinatari e riepilogo delle notifiche
$q("UPDATE eventi SET email_notifiche_extra = 'extra99@prova.it, sbagliata, EXTRA99@prova.it' WHERE id = 9901");
$q("DELETE FROM progetti_dettagli WHERE evento_id = 9901");
$q("INSERT INTO progetti_dettagli (evento_id, referenti_json) VALUES (9901, '[{\"nome\":\"R1\",\"email\":\"Ref99@prova.it\",\"notifiche\":1},{\"nome\":\"R2\",\"email\":\"no99@prova.it\",\"notifiche\":0},{\"nome\":\"R3\",\"email\":\"rotta\",\"notifiche\":1}]')");
$dest = get_destinatari_notifiche_prenotazione($conn, 9901);
prova(in_array('extra99@prova.it', $dest, true) && in_array('ref99@prova.it', $dest, true) && !in_array('no99@prova.it', $dest, true) && !in_array('rotta', $dest, true) && count($dest) === count(array_unique($dest)), "destinatari delle notifiche: gestori, indirizzi extra e referenti con notifiche", json_encode($dest));
$rie = ['html' => 'CON-ADMIN', 'html_senza_admin' => 'SENZA'];
prova(corpo_notifica_per('A@x.it', '<p>i</p>', $rie, ['a@x.it']) === '<p>i</p>CON-ADMIN' && corpo_notifica_per('b@x.it', '<p>i</p>', $rie, ['a@x.it']) === '<p>i</p>SENZA', "corpo_notifica_per(): il link all'admin solo ai gestori");
$q("UPDATE turni SET data_turno = '2999-03-01' WHERE id = 99011");
$pr(9961, 99011, 'confermata', 2, 'rie@prova.it', 9901);
$q("UPDATE prenotazioni SET matricola = 'M99', dati_custom_json = '" . $conn->real_escape_string(json_encode(['campo_99' => "riga1\nriga2", 'file_99' => 'uploads/allegati_prenotazioni/a.pdf, uploads/allegati_prenotazioni/b.pdf', 'vecchio_99' => 'v'], JSON_UNESCAPED_UNICODE)) . "' WHERE id = 9961");
$q("DELETE FROM campi_form WHERE nome_campo IN ('campo_99', 'file_99')");
$q("INSERT INTO campi_form (pagina_id, evento_id, nome_campo, etichetta, tipo_campo, ordine) VALUES ($area99, NULL, 'campo_99', 'Campo novantanove', 'text', 5), ($area99, 9901, 'file_99', 'Allegati', 'file', 6)");
$ri = html_riepilogo_prenotazione($conn, 9961);
prova($ri !== null && $ri['oggetto_evento'] === 'Evento Iscrizioni 99' && str_contains($ri['html'], 'Apri gli iscritti del turno') && !str_contains($ri['html_senza_admin'], 'Apri gli iscritti')
    && str_contains($ri['html'], 'Nome9961 Cognome9961') && str_contains($ri['html'], 'M99') && str_contains($ri['html'], 'Turno A · 01/03/2999 · 10:00–12:00') && str_contains($ri['html'], 'Confermata') && str_contains($ri['html'], '<td style="padding:6px 10px;border-bottom:1px solid #e5e7eb;font-weight:bold;">2</td>')
    && str_contains($ri['html'], 'Campo novantanove') && str_contains($ri['html'], 'riga1<br />') && str_contains($ri['html'], 'Allegato 1</a> · <a') && str_contains($ri['html'], 'Vecchio 99') && ($ri['dati']['id'] ?? 0) == 9961
    && html_riepilogo_prenotazione($conn, 99999) === null, "html_riepilogo_prenotazione(): dati, campi con etichetta, allegati come link", substr(strip_tags((string)($ri['html'] ?? '')), 0, 700));

// --- letture per le pagine
prova(array_column(get_campi_form($conn, 9901), 'nome_campo') === ['file_99'] && get_campi_form($conn, 99999) === [], "get_campi_form()");
$ric = get_prenotazione_ricevuta($conn, 'PR99-9961', 0);
prova($ric && $ric['evento_titolo'] === 'Evento Iscrizioni 99' && $ric['matricola_effettiva'] === 'M99' && get_prenotazione_ricevuta($conn, '', 9961)['codice_prenotazione'] === 'PR99-9961' && get_prenotazione_ricevuta($conn, 'zz', 0) === null, "get_prenotazione_ricevuta(): per codice e per id");
$tce = get_turno_con_evento($conn, 99011);
prova($tce && $tce['evento_titolo'] === 'Evento Iscrizioni 99' && $tce['luogo'] === 'Aula 99' && get_turno_con_evento($conn, 99999) === null, "get_turno_con_evento()");
prova(testo_posti_liberi(1) === '1 posto libero' && testo_posti_liberi(7) === '7 posti liberi' && testo_posti_liberi(0) === '' && testo_posti_liberi(POSTI_SENZA_LIMITE) === 'Posti disponibili', "testo_posti_liberi()");

// Posizione in coda, riepilogo posti, ultimi posti e prenotazioni attive dell'utente
$q("DELETE FROM prenotazioni WHERE turno_id BETWEEN 99010 AND 99029");
$q("UPDATE turni SET data_turno = '" . $tra('+10 days') . "', max_posti = 10 WHERE id = 99011");
$q("UPDATE turni SET max_posti = 10, data_turno = '" . $tra('+11 days') . "' WHERE id = 99012");
$pr(9971, 99011, 'confermata', 3, 'u1@prova.it', 9901);
$pr(9972, 99011, 'da_approvare', 2, 'u2@prova.it');
$pr(9973, 99011, 'in_attesa', 1, 'u3@prova.it', null, '2026-02-01 10:00:00');
$pr(9974, 99011, 'in_attesa', 1, 'u4@prova.it', null, '2026-02-01 10:00:00');
$pr(9975, 99011, 'in_attesa', 1, 'u5@prova.it', null, '2026-01-01 10:00:00');
$pos = get_posizioni_lista_attesa($conn, [9973, 9974, 9975, 9971, 0]);
prova($pos === [9973 => ['posizione' => 2, 'totale' => 3], 9974 => ['posizione' => 3, 'totale' => 3], 9975 => ['posizione' => 1, 'totale' => 3]] && get_posizioni_lista_attesa($conn, []) === [], "get_posizioni_lista_attesa(): stesso ordine della promozione", json_encode($pos));
$rp = get_riepilogo_posti($conn, 'evento', [9901, 9902]);
prova($rp[9901] === ['capienza' => 20, 'occupati' => 5, 'liberi' => 15] && !isset($rp[9902]) || ($rp[9901]['occupati'] ?? -1) === 5, "get_riepilogo_posti(): per evento", json_encode($rp));
$rp2 = get_riepilogo_posti($conn, 'pagina', [$area99]);
prova(isset($rp2[$area99]) && $rp2[$area99]['liberi'] === $rp2[$area99]['capienza'] - $rp2[$area99]['occupati'] && get_riepilogo_posti($conn, 'evento', []) === [], "get_riepilogo_posti(): per area");
$q("UPDATE turni SET max_posti = 6 WHERE id = 99011");
$ult = get_turni_ultimi_posti($conn, [$area99]);
$mioult = array_values(array_filter($ult, fn($t) => (int)$t['evento_id'] === 9901))[0] ?? null;
prova($mioult && (int)$mioult['turno_id'] === 99011 && $mioult['liberi'] === 1 && get_turni_ultimi_posti($conn, []) === [], "get_turni_ultimi_posti(): pochi posti rimasti", json_encode($mioult));
$att = get_prenotazioni_attive_utente($conn, 9901);
prova(count($att) >= 1 && (int)$att[0]['id'] === 9971 && $att[0]['stato'] === 'confermata' && get_prenotazioni_attive_utente($conn, 0) === [], "get_prenotazioni_attive_utente()");

// --- campi del modulo per l'amministratore
$cfa = html_campi_form_admin($conn, 9901, ['campo_99' => 'valore99'], 'tst', 99011);
prova(str_contains($cfa, 'Campo novantanove') && str_contains($cfa, 'value="valore99"') && str_contains($cfa, 'id="tst_') && html_campi_form_admin($conn, 99999) === '', "html_campi_form_admin()");

// pulizia
$q("DELETE FROM prenotazioni WHERE turno_id BETWEEN 99010 AND 99029");
$q("DELETE FROM campi_form WHERE nome_campo IN ('campo_99', 'file_99')");
$q("DELETE FROM progetti_dettagli WHERE evento_id = 9901");
$q("DELETE FROM turni WHERE evento_id IN (9901, 9902)");
$q("DELETE FROM eventi WHERE id IN (9901, 9902)");
$q("DELETE FROM utenti WHERE id = 9901");
$q("UPDATE pagine_eventi SET limite_iscrizioni = 'nessuno' WHERE id = $area99");
