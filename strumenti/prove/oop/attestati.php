<?php
// Prove del modulo Attestati (App\Attestati): le funzioni di inc/attestati.php come facciate (stessi risultati di prima).
// Chiamato da esegui.php: stesse variabili ($conn, $q, $EMAIL) e funzioni (prova, sezione). I dati di prova usano id 99xx.
sezione("Modulo Attestati: facciate di inc/");

$oggi = date('Y-m-d');
$area = (int)$conn->query("SELECT id FROM pagine_eventi ORDER BY id LIMIT 1")->fetch_assoc()['id'];
$q("DELETE FROM partecipanti_prenotazione WHERE prenotazione_id BETWEEN 9901 AND 9920");
$q("DELETE FROM prenotazioni WHERE id BETWEEN 9901 AND 9920");
$q("DELETE FROM turni WHERE id BETWEEN 99011 AND 99060");
$q("DELETE FROM progetti_dettagli WHERE evento_id BETWEEN 9901 AND 9910");
$q("DELETE FROM eventi WHERE id BETWEEN 9901 AND 9910");
$q("INSERT INTO eventi (id, pagina_id, titolo, luogo, tipo) VALUES (9901, $area, 'Evento prova attestati', 'Aula 99', 'evento'), (9902, $area, 'Progetto prova concluso', 'Lab 99', 'progetto'), (9903, $area, 'Progetto prova in corso', '', 'progetto'),
    (9904, $area, 'Progetto prova senza attestati', '', 'progetto'), (9905, $area, 'Progetto singolo prova', '', 'progetto'), (9906, $area, 'Evento di classe prova', 'Aula 6', 'evento')");
$q("INSERT INTO progetti_dettagli (evento_id, per_scuole, attestati, data_inizio, data_fine, ore_totali) VALUES (9902, 1, 1, '2026-01-10', '2026-03-01', 20), (9903, 1, 1, '2026-01-10', '" . date('Y-m-d', strtotime('+30 days')) . "', 10),
    (9904, 1, 0, '2026-01-10', '2026-03-01', 10), (9905, 0, 1, '2026-01-10', '2026-03-01', 15), (9906, 0, 1, NULL, NULL, NULL)");
$q("INSERT INTO turni (id, evento_id, nome_turno, data_turno, orario_inizio, orario_fine, max_posti) VALUES (99011, 9901, 'Turno uno', '2026-09-10', '10:00:00', '12:30:00', 50), (99012, 9901, 'Futuro', '2999-01-01', '10:00:00', '12:00:00', 50),
    (99021, 9902, 'Iscrizione scuole', NULL, NULL, NULL, 5), (99031, 9903, 'Edizione', NULL, NULL, NULL, 5), (99041, 9904, 'Edizione', NULL, NULL, NULL, 5), (99051, 9905, 'Edizione', NULL, NULL, NULL, 5),
    (99061, 9906, 'Mattina', '2026-09-01', '09:00:00', '11:00:00', 50)");
$q("INSERT INTO prenotazioni (id, turno_id, codice_prenotazione, stato, presente, num_posti, nome, cognome, email, matricola, dati_custom_json) VALUES
    (9901, 99011, 'ATTPROVA1', 'confermata', 1, 1, 'Anna', 'Verdi <b>', 'anna99@prova.it', '', NULL),
    (9902, 99021, 'ATTPROVA2', 'confermata', 1, 1, 'Maria', 'Docente', 'maria99@prova.it', '', '{\"numero_partecipanti\":\"4\"}'),
    (9903, 99012, 'ATTPROVA3', 'confermata', 0, 1, 'Assente', 'Uno', 'as99@prova.it', '', NULL),
    (9904, 99031, 'ATTPROVA4', 'confermata', 1, 1, 'In', 'Corso', 'ic99@prova.it', '', NULL),
    (9905, 99041, 'ATTPROVA5', 'confermata', 1, 1, 'Senza', 'Attestati', 'sa99@prova.it', '', NULL),
    (9906, 99051, 'ATTPROVA6', 'confermata', 1, 1, 'Luca', 'Singolo', 'ls99@prova.it', '', NULL),
    (9907, 99061, 'ATTPROVA7', 'confermata', 1, 1, 'Classe', 'Evento', 'ce99@prova.it', '', NULL)");

// Regola dell'attestato
prova(regola_attestato_evento($conn, 9901) === 'evento' && regola_attestato_evento($conn, 9902) === 'gruppo' && regola_attestato_evento($conn, 9903) === 'attendi' && regola_attestato_evento($conn, 9904) === 'no'
      && regola_attestato_evento($conn, 9905) === 'singolo' && regola_attestato_evento($conn, 9906) === 'gruppo' && regola_attestato_evento($conn, 99999) === 'evento', "regola_attestato_evento(): evento, gruppo, attendi, no, singolo");

// Elenco degli studenti
salva_elenco_partecipanti($conn, 9902, [['cognome' => 'Rossi', 'nome' => 'Mario'], ['cognome' => 'Bianchi', 'nome' => 'Anna']]);
$el = get_partecipanti_prenotazione($conn, 9902);
prova(count($el) === 2 && nome_partecipante($el[0]) === 'Rossi Mario' && $el[1]['ordine'] === '1' && is_string($el[0]['escluso']), "salva_elenco_partecipanti() e get_partecipanti_prenotazione(): ordine e tipi testuali");
salva_elenco_partecipanti($conn, 9902, [['cognome' => 'Neri', 'nome' => 'Ugo']]);
prova(count(get_partecipanti_prenotazione($conn, 9902)) === 1, "salva_elenco_partecipanti(): sostituisce l'elenco");
$lt = leggi_elenco_partecipanti("Cognome;Nome\nRossi\tMario\n  Bianchi , Anna \nrossi;mario\n\nSolo");
prova($lt === [['cognome' => 'Rossi', 'nome' => 'Mario'], ['cognome' => 'Bianchi', 'nome' => 'Anna'], ['cognome' => 'Solo', 'nome' => '']], "leggi_elenco_partecipanti()");
prova(leggi_elenco_da_campi(['De  Luca', 'de luca', ''], ['Anna', 'anna', '']) === [['cognome' => 'De Luca', 'nome' => 'Anna']], "leggi_elenco_da_campi()");
$tmp = tempnam(sys_get_temp_dir(), 'att');
file_put_contents($tmp, mb_convert_encoding("Cognome;Nome\nÈsposito;Ciro\n", 'Windows-1252', 'UTF-8'));
prova(testo_da_file_elenco(['error' => 0, 'size' => 10, 'name' => 'e.csv', 'tmp_name' => $tmp], $err_att) === "Cognome;Nome\nÈsposito;Ciro\n" && testo_da_file_elenco(['error' => 0, 'size' => 10, 'name' => 'e.doc', 'tmp_name' => $tmp], $err_att) === null && $err_att === 'Formato non supportato: carica un file .xlsx o .csv.', "testo_da_file_elenco(): CSV Windows-1252 e formato non supportato");
@unlink($tmp);
prova(max_partecipanti_prenotazione(['dati_custom_json' => '{"numero_partecipanti":"4"}'], null) === 4 && max_partecipanti_prenotazione([], ['max_studenti' => 25]) === 25 && max_partecipanti_prenotazione([], null) === 200, "max_partecipanti_prenotazione()");

// Codici, dati e pagina degli attestati
assegna_codici_partecipanti($conn, 9902);
$cod = get_partecipanti_prenotazione($conn, 9902)[0]['codice'];
prova(preg_match('/^AT-[A-HJ-NP-Z2-9]{10}$/', (string)$cod) === 1 && (assegna_codici_partecipanti($conn, 9902) ?? true) && get_partecipanti_prenotazione($conn, 9902)[0]['codice'] === $cod, "assegna_codici_partecipanti(): una volta sola");
prova(preg_match('/^AT-[A-HJ-NP-Z2-9]{10}$/', nuovo_codice_attestato()) === 1 && str_ends_with(url_verifica_attestato('AT-X Y'), '/verifica_attestato.php?c=AT-X+Y'), "nuovo_codice_attestato() e url_verifica_attestato()");
$p = prenotazione_per_attestati($conn, 9902);
prova($p && $p['evento_tipo'] === 'progetto' && $p['attestati'] === '1' && $p['ore_totali'] === '20' && prenotazione_per_attestati($conn, 99999) === null, "prenotazione_per_attestati(): campi come stringhe");
$d = dati_attestato($p, 'Mario Rossi', 'AT-1', '77');
prova($d['ore'] === '20' && $d['quando'] === 'Dal 10/01/2026 al 01/03/2026' && $d['evento'] === 'Progetto prova concluso' && $d['formula'] === 'ha partecipato al progetto dal titolo:' && $d['matricola'] === '77', "dati_attestato(): progetto");
$d2 = dati_attestato(prenotazione_per_attestati($conn, 9901), 'Anna Verdi', 'ATTPROVA1');
prova($d2['ore'] === '2.5' && $d2['quando'] === 'In data 10/09/2026' && str_starts_with($d2['formula'], "ha partecipato all'attività"), "dati_attestato(): evento con durata del turno");
$ga = get_attestato($conn, 'ATTPROVA1');
prova($ga && $ga['evento_titolo'] === 'Evento prova attestati' && array_key_exists('matricola_effettiva', $ga) && get_attestato($conn, 'NONESISTE') === null, "get_attestato()");
$html = pagina_attestati([$d2 + ['file' => 'attestato_verdi_anna']], 'Attestato - Anna Verdi');
prova(str_starts_with($html, '<!DOCTYPE html>') && str_contains($html, 'data-file="attestato_verdi_anna"') && str_contains($html, 'verifica_attestato.php?c=ATTPROVA1') && str_contains($html, 'ANNA VERDI') && str_ends_with($html, "</html>\n"), "pagina_attestati()");
prova(slug_file("D'Angelo  Pietà") === 'dangelo_pieta' && str_contains(url_vendor('jsdelivr/x.css'), '/assets/vendor/jsdelivr/x.css') && str_contains(script_libreria('qrcode'), 'qrcode-generator-1.4.4.min.js') && script_libreria('nonesiste') === '', "slug_file(), url_vendor(), script_libreria()");
$qr1 = qr_html('https://x.it', 'width:50px');
$qr2 = qr_html('altro');
prova(str_contains($qr1, 'data-qr="https://x.it"') && str_contains($qr1, '<script') && !str_contains($qr2, '<script'), "qr_html(): lo script una volta sola per pagina");

// Chi può vedere
$_SESSION = ['utente_id' => 7, 'utente_ruolo_id' => 5, 'utente_email' => 'ANNA99@prova.it'];
$pren = ['utente_id' => '7', 'email' => 'altra@x.it'];
prova(puo_vedere_prenotazione($pren) && puo_vedere_prenotazione(['utente_id' => null, 'email' => 'anna99@prova.it']) && !puo_vedere_prenotazione(['utente_id' => 8, 'email' => 'x@x.it']), "puo_vedere_prenotazione(): titolare per id o email");
$_SESSION = ['utente_id' => 9, 'utente_ruolo_id' => 5, 'utente_ruoli_secondari' => '3,2'];
prova(puo_vedere_prenotazione(['utente_id' => 8, 'email' => 'x@x.it']), "puo_vedere_prenotazione(): gestore come ruolo secondario");
$_SESSION = [];
prova(!puo_vedere_prenotazione($pren), "puo_vedere_prenotazione(): non collegato");

// Invio degli attestati di gruppo
$EMAIL = [];
prova(invia_attestati_gruppo($conn, 9901) === 'Questa attività non prevede attestati per gli studenti.' && invia_attestati_gruppo($conn, 99999) === 'Questa attività non prevede attestati per gli studenti.', "invia_attestati_gruppo(): attività senza attestati di classe");
prova(invia_attestati_gruppo($conn, 9904) === 'Il progetto non è ancora concluso.' && invia_attestati_gruppo($conn, 9907) === "L'elenco degli studenti è vuoto.", "invia_attestati_gruppo(): progetto non concluso, elenco vuoto");
salva_elenco_partecipanti($conn, 9907, [['cognome' => 'Gialli', 'nome' => 'Luca']]);
prova(invia_attestati_gruppo($conn, 9907) === true && count($EMAIL) === 1 && $EMAIL[0]['a'] === 'ce99@prova.it' && $EMAIL[0]['oggetto'] === 'Attestati degli studenti: Evento di classe prova'
      && str_contains($EMAIL[0]['corpo'], "all'attività <strong>Evento di classe prova</strong>") && str_contains($EMAIL[0]['corpo'], 'attestati_gruppo.php?code=ATTPROVA7')
      && (int)$conn->query("SELECT attestato_inviato FROM prenotazioni WHERE id = 9907")->fetch_assoc()['attestato_inviato'] === 1, "invia_attestati_gruppo(): email al docente e prenotazione segnata");
prova(invia_attestati_gruppo($conn, 9907) === 'Attestati già inviati.' && invia_attestati_gruppo($conn, 9907, true) === true && count($EMAIL) === 2, "invia_attestati_gruppo(): una volta sola, salvo invio forzato");

// Email dell'attestato personale
$EMAIL = [];
prova(invia_email_attestato_se_concluso($conn, 9903) === false && invia_email_attestato_se_concluso($conn, 9905) === false && invia_email_attestato_se_concluso($conn, 9904) === false && $EMAIL === [], "invia_email_attestato_se_concluso(): assente, senza attestati, progetto non concluso");
prova(invia_email_attestato_se_concluso($conn, 9906) === true && count($EMAIL) === 1 && $EMAIL[0]['oggetto'] === 'Il tuo Attestato è pronto: Progetto singolo prova' && str_contains($EMAIL[0]['corpo'], 'stampa_attestato.php?code=ATTPROVA6'), "invia_email_attestato_se_concluso(): progetto generico concluso");
prova(invia_email_attestato_se_concluso($conn, 9906) === false && invia_email_attestato_se_concluso($conn, 9901) === true && str_contains($EMAIL[1]['corpo'], '<strong>Anna Verdi &lt;b&gt;</strong>') && str_contains($EMAIL[1]['corpo'], "all'evento <strong>Evento prova attestati</strong> del 10/09/2026."), "invia_email_attestato_se_concluso(): una volta sola, evento con data");

// Pulizia
$q("DELETE FROM partecipanti_prenotazione WHERE prenotazione_id BETWEEN 9901 AND 9920");
$q("DELETE FROM prenotazioni WHERE id BETWEEN 9901 AND 9920");
$q("DELETE FROM turni WHERE id BETWEEN 99011 AND 99060");
$q("DELETE FROM progetti_dettagli WHERE evento_id BETWEEN 9901 AND 9910");
$q("DELETE FROM eventi WHERE id BETWEEN 9901 AND 9910");
prova(!$conn->query("SELECT 1 FROM eventi WHERE id BETWEEN 9901 AND 9910")->num_rows, "pulizia dei dati di prova del modulo Attestati");
