<?php
// Prove del modulo Sondaggi (App\Sondaggi): le funzioni di inc/dati.php come facciate e la gestione dei sondaggi.
// Chiamato da esegui.php: stesse variabili ($conn, $q, $EMAIL) e funzioni (prova, sezione). I dati di prova usano id 99xx.
sezione("Modulo Sondaggi: facciate di inc/ e servizio");

$area = (int)$conn->query("SELECT id FROM pagine_eventi ORDER BY id LIMIT 1")->fetch_assoc()['id'];
$q("DELETE FROM sondaggi_risposte WHERE sondaggio_id BETWEEN 9901 AND 9910");
$q("DELETE FROM sondaggi_domande WHERE sondaggio_id BETWEEN 9901 AND 9910");
$q("DELETE FROM sondaggi WHERE id BETWEEN 9901 AND 9910");
$q("DELETE FROM prenotazioni WHERE id BETWEEN 9951 AND 9960");
$q("DELETE FROM turni WHERE id BETWEEN 99511 AND 99520");
$q("DELETE FROM eventi WHERE id BETWEEN 9951 AND 9960");
$q("INSERT INTO eventi (id, pagina_id, titolo, luogo, tipo) VALUES (9951, $area, 'Seminario sondaggio prova', 'Aula 95', 'evento')");
$q("INSERT INTO turni (id, evento_id, nome_turno, data_turno, orario_inizio, orario_fine, max_posti) VALUES (99511, 9951, 'Turno', '2026-09-10', '10:00:00', '12:00:00', 50)");
$q("INSERT INTO prenotazioni (id, turno_id, codice_prenotazione, stato, presente, num_posti, nome, cognome, email, matricola, token_sondaggio, sondaggio_completato) VALUES
    (9951, 99511, 'SONDPROVA1', 'confermata', 1, 1, 'Anna', 'Verdi', 'anna95@prova.it', '123', 'tokprova9951', 0),
    (9952, 99511, 'SONDPROVA2', 'confermata', 1, 1, 'Paolo', 'Neri', 'paolo95@prova.it', '', NULL, 0),
    (9953, 99511, 'SONDPROVA3', 'confermata', 1, 1, 'Gia', 'Compilato', 'gc95@prova.it', '', NULL, 1),
    (9954, 99511, 'SONDPROVA4', 'confermata', 0, 1, 'Assente', 'Uno', 'as95@prova.it', '', NULL, 0)");

$sondaggi = \App\Core\App::get(\App\Sondaggi\ServizioSondaggi::class);
$sondaggi->crea(9951, 'Valutazione prova');
$sid = (int)$conn->query("SELECT id FROM sondaggi WHERE evento_id = 9951")->fetch_assoc()['id'];
prova(get_sondaggio_attivo($conn, 9951) === null, "get_sondaggio_attivo(): un sondaggio appena creato è spento");
$sondaggi->attiva($sid, true);
$att = get_sondaggio_attivo($conn, 9951);
prova($att && $att['id'] === $sid && $att['titolo'] === 'Valutazione prova' && is_int($att['attivo']), "get_sondaggio_attivo(): sondaggio attivo (tipi nativi)");
$sondaggi->aggiungiDomanda($sid, 'Stelle', 'rating', '', true, 0, '');
$sondaggi->aggiungiDomanda($sid, 'Scelta', 'radio', 'Sì,No', false, 0, '');
$sondaggi->aggiungiDomanda($sid, 'Perché?', 'text', '', true, 0, '');
$dom = get_domande_sondaggio($conn, $sid);
prova(count($dom) === 3 && $dom[1]['ordine'] === 10 && $dom[0]['obbligatorio'] === 1 && $dom[1]['opzioni'] === 'Sì,No' && get_domande_sondaggio($conn, 99999) === [], "get_domande_sondaggio(): in ordine, a passi di 10");
$d1 = (int)$dom[0]['id']; $d2 = (int)$dom[1]['id']; $d3 = (int)$dom[2]['id'];
$sondaggi->modificaDomanda($d3, 'Perché?', 'text', '', false, $d2, 'No');
$sondaggi->spostaDomanda($d3, true);
$m = $conn->query("SELECT condizione_json, ordine FROM sondaggi_domande WHERE id = $d3")->fetch_assoc();
prova($m['condizione_json'] === '{"se_id":' . $d2 . ',"se_val":"No"}' && $m['ordine'] === '5', "modifica con condizione e spostamento di una domanda");

// Link personale e compilazione
$pr = get_prenotazione_by_token_sondaggio($conn, 'tokprova9951');
prova($pr && $pr['id'] === 9951 && $pr['evento_id'] === 9951 && $pr['evento_titolo'] === 'Seminario sondaggio prova' && get_prenotazione_by_token_sondaggio($conn, 'nonesiste') === null, "get_prenotazione_by_token_sondaggio()");
prova(salva_risposte_sondaggio($conn, $sid, [$d2 => 'Sì'], 9951, $errore_son) === false && $errore_son === 'Rispondi a tutte le domande obbligatorie.' && (int)$conn->query("SELECT COUNT(*) AS n FROM sondaggi_risposte")->fetch_assoc()['n'] === 0, "salva_risposte_sondaggio(): domanda obbligatoria mancante");
prova(salva_risposte_sondaggio($conn, $sid, [$d1 => '4', $d2 => ['Sì'], $d3 => '  ', 99999 => 'estranea'], 9951, $errore_son) === true && $errore_son === null, "salva_risposte_sondaggio(): invio valido");
$ris = [];
$rr = $conn->query("SELECT domanda_id, risposta FROM sondaggi_risposte WHERE sondaggio_id = $sid ORDER BY id");
while ($x = $rr->fetch_assoc()) $ris[$x['domanda_id']] = $x['risposta'];
prova($ris === [(string)$d1 => '4', (string)$d2 => '["Sì"]'] && (int)$conn->query("SELECT sondaggio_completato FROM prenotazioni WHERE id = 9951")->fetch_assoc()['sondaggio_completato'] === 1, "risposte salvate (anonime) e prenotazione completata");
prova(salva_risposte_sondaggio($conn, $sid, [$d1 => '1'], 9951, $errore_son) === false && $errore_son === 'Hai già compilato questo questionario. Grazie per il tuo feedback!' && (int)$conn->query("SELECT COUNT(*) AS n FROM sondaggi_risposte")->fetch_assoc()['n'] === 2, "salva_risposte_sondaggio(): secondo invio respinto");

// Statistiche ed esportazione
$sc = $sondaggi->scheda(9951);
prova($sc && $sc['statistiche'][$d1]['somma_voti'] === 4 && $sc['statistiche'][$d2]['conteggi_opzioni'] === ['["Sì"]' => 1] && count($sc['domande']) === 3 && $sondaggi->scheda(99999) === null, "scheda(): domande e statistiche");
$esp = $sondaggi->esportazione($sid);
$xls = \App\Sondaggi\Vista\EsportaRisultati::html($esp['domande'], $esp['risposte']);
prova(str_contains($xls, '<th style="background-color:#198754; color:white;">Stelle</th>') && str_contains($xls, '<td>4</td>') && str_contains($xls, 'N/D'), "esportazione dei risultati in Excel (HTML)");

// Autorizzazioni dell'admin
prova($sondaggi->autorizzato('evento', 9951, $area, '') && $sondaggi->autorizzato('sondaggio', $sid, $area, '') && $sondaggi->autorizzato('domanda', $d1, $area, '') && !$sondaggi->autorizzato('domanda', $d1, $area + 9999, '') && !$sondaggi->autorizzato('evento', 9951, $area, ' AND e.id = -1 '), "autorizzato(): area e perimetro del gestore");
$ev = $sondaggi->eventiDellArea($area, false, '');
prova(in_array('9951', array_column($ev, 'id'), true) && !in_array('9951', array_column($sondaggi->eventiDellArea($area, true, ''), 'id'), true), "eventiDellArea(): attivi e archivio");

// Invio del link per email
$EMAIL = [];
$n = $sondaggi->inviaLink(9951, 'https://sito.test/eventi');
$dest = array_column($EMAIL, 'a');
sort($dest);
$tok2 = $conn->query("SELECT token_sondaggio FROM prenotazioni WHERE id = 9952")->fetch_assoc()['token_sondaggio'];
prova($n === 1 && $dest === ['paolo95@prova.it'] && preg_match('/^[0-9a-f]{32}$/', (string)$tok2) === 1 && str_contains($EMAIL[0]['corpo'], "sondaggio.php?token=$tok2") && str_contains($EMAIL[0]['oggetto'], 'Seminario sondaggio prova'), "inviaLink(): solo presenti che non hanno compilato, link creato se manca");
$conn->query("UPDATE impostazioni_sistema SET email_sondaggio_oggetto = 'Oggetto {NOME}', email_sondaggio_corpo = '<p>{COGNOME} {MATRICOLA} {DATA_TURNO} {ORARIO_TURNO} {LUOGO}</p>' WHERE id = 1");
$conn->query("UPDATE prenotazioni SET sondaggio_completato = 0 WHERE id = 9951");
$EMAIL = [];
$sondaggi->inviaLink(9951, 'https://sito.test/eventi');
$conn->query("UPDATE impostazioni_sistema SET email_sondaggio_oggetto = '', email_sondaggio_corpo = NULL WHERE id = 1");
$o = array_column($EMAIL, 'oggetto');
sort($o);
prova($o === ['Oggetto Anna', 'Oggetto Paolo'] && in_array('<p>Verdi 123 10/09/2026 10:00–12:00 Aula 95</p>', array_column($EMAIL, 'corpo'), true), "inviaLink(): testo personalizzato con i segnaposto");

// Eliminazione
$sondaggi->eliminaDomanda($d3);
prova(count(get_domande_sondaggio($conn, $sid)) === 2, "eliminaDomanda()");
$sondaggi->elimina($sid);
prova(!$conn->query("SELECT 1 FROM sondaggi WHERE id = $sid")->num_rows && !$conn->query("SELECT 1 FROM sondaggi_domande WHERE sondaggio_id = $sid")->num_rows && !$conn->query("SELECT 1 FROM sondaggi_risposte WHERE sondaggio_id = $sid")->num_rows, "elimina(): sondaggio, domande e risposte");

// Pulizia
$q("DELETE FROM prenotazioni WHERE id BETWEEN 9951 AND 9960");
$q("DELETE FROM turni WHERE id BETWEEN 99511 AND 99520");
$q("DELETE FROM eventi WHERE id BETWEEN 9951 AND 9960");
prova(!$conn->query("SELECT 1 FROM eventi WHERE id BETWEEN 9951 AND 9960")->num_rows, "pulizia dei dati di prova del modulo Sondaggi");
