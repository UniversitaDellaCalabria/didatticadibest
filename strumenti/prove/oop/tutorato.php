<?php
// Prove del modulo Tutorato (App\Tutorato): le funzioni di inc/tutorato.php e inc/tutorato_registro.php come facciate dei servizi, le cartelle
// protette, il registro delle attività e i collegamenti nel container. Chiamato da esegui.php: stesse variabili ($conn, $q, $EMAIL) e funzioni
// (prova, sezione). I dati di prova usano il bando 9970 e le lettere create qui, tutti ripuliti alla fine.
sezione("Modulo Tutorato: facciate di inc/, lettere, registro e cartelle protette");

use App\Core\App as AppTut;
use App\Tutorato\ServizioIncarichi;
use App\Tutorato\ServizioRegistroTutorato;

$c_tut = AppTut::per($conn);
$s_inc = $c_tut->get(ServizioIncarichi::class);
$s_reg = $c_tut->get(ServizioRegistroTutorato::class);

prova($s_inc instanceof ServizioIncarichi && $c_tut->get(\App\Tutorato\FirmaRemota::class) instanceof \App\Tutorato\FirmaRemotaAruba && $c_tut->get(\App\Core\IndirizzoClient::class) instanceof \App\Core\IndirizzoClientDaServer,
    "container: servizi di App\\Tutorato, firma remota Aruba e indirizzo del client");
prova(STATI_INCARICO === \App\Tutorato\Costanti::STATI_INCARICO && STATI_REGISTRO === \App\Tutorato\Costanti::STATI_REGISTRO && STATI_FINE_ATTIVITA === \App\Tutorato\Costanti::STATI_FINE_ATTIVITA && METODI_ACCESSO === \App\Tutorato\Costanti::METODI_ACCESSO,
    "costanti globali di prima, con i valori di App\\Tutorato\\Costanti");

// Funzioni pure: facciata e servizio danno la stessa risposta
prova(numero_italiano('1.234,50') === 1234.5 && numero_italiano('abc') === null && badge_stato_incarico('bozza') === \App\Tutorato\Vista\StatiIncarico::badge('bozza'), "numero_italiano() e badge degli stati");
prova(ore_testo(2.5) === '2,5' && ore_testo(3.0) === '3', "ore_testo(): virgola italiana, niente decimali inutili");
prova(ore_registro([['ore' => '2.5', 'stato' => 'inviata'], ['ore' => '4', 'stato' => 'approvata'], ['ore' => '1', 'stato' => 'respinta']]) === ServizioRegistroTutorato::ore([['ore' => '2.5', 'stato' => 'inviata'], ['ore' => '4', 'stato' => 'approvata'], ['ore' => '1', 'stato' => 'respinta']])
    && ore_registro([['ore' => '2.5', 'stato' => 'inviata']])['totale'] === 2.5, "ore_registro(): facciata e servizio");

// Dati di prova
foreach (["DELETE FROM tutorato_registro WHERE incarico_id IN (SELECT id FROM tutorato_incarichi WHERE bando_id = 9970)", "DELETE FROM tutorato_eventi WHERE incarico_id IN (SELECT id FROM tutorato_incarichi WHERE bando_id = 9970)",
          "DELETE FROM tutorato_incarichi WHERE bando_id = 9970", "DELETE FROM tutorato_bandi WHERE id = 9970"] as $sql) $q($sql);
$EMAIL = [];
[$b70, $e70] = salva_bando_tutorato($conn, ['titolo' => 'Bando prova 70', 'decreto_bando' => '70/26', 'direttore_nome' => 'Dir Prova', 'direttore_email' => 'dir70@example.org', 'direttore_cf' => 'BNCMRA60A01D086X'], 1);
prova($b70 > 0 && $e70 === null && bando_tutorato($conn, $b70) === $s_inc->bando($b70) && in_array($b70, array_map('intval', array_column(bandi_tutorato($conn), 'id')), true), "bando: salvataggio e lettura dalla facciata e dal servizio");
$dati70 = ['bando_id' => $b70, 'cognome' => 'Prova', 'nome' => 'Settanta', 'genere' => 'F', 'luogo_nascita' => 'Cosenza', 'data_nascita' => '2000-01-31', 'comune_residenza' => 'Rende', 'indirizzo' => 'Via 70', 'civico' => '7',
    'codice_fiscale' => 'prvstt00a71d086x', 'email' => 'tut70@example.org', 'telefono' => '333', 'attivita' => 'Tutorato di prova', 'ore' => '10', 'periodo' => 'novembre', 'compenso' => '500',
    'docente_cognome' => 'Docente', 'docente_nome' => 'Prova', 'docente_email' => 'doc70@example.org', 'data_lettera' => '2026-10-03'];
prova(salva_incarico_tutorato($conn, ['codice_fiscale' => 'XYZ'] + $dati70)[1] !== null, "lettera: codice fiscale controllato");
[$i70, $ei70] = salva_incarico_tutorato($conn, $dati70);
$inc70 = incarico_tutorato($conn, $i70);
prova($i70 > 0 && $ei70 === null && $inc70['stato'] === 'bozza' && $inc70['codice_fiscale'] === 'PRVSTT00A71D086X' && $inc70 === $c_tut->get(\App\Tutorato\IncaricoRepository::class)->perId($i70)
    && count($s_inc->eventi($i70)) === 1 && $s_inc->eventi($i70)[0]['tipo'] === 'creata', "lettera: bozza salvata con l'evento «creata»");
prova(passi_incarico($inc70) === \App\Tutorato\Vista\StatiIncarico::passi($inc70) && impronta_dati_incarico($inc70) === impronta_dati_incarico(incarico_tutorato($conn, $i70)), "passi dell'iter e impronta dei dati");

// Il registro non è aperto finché la lettera non è firmata
prova(aggiungi_registro($conn, $i70, '2026-09-01', 2, 'x') === 'Il registro non è aperto per questo incarico.' && $s_reg->aggiungi($i70, '2026-09-01', 2, 'x') === 'Il registro non è aperto per questo incarico.' && registro_aperto($inc70) === false, "registro chiuso prima della firma");
$q("UPDATE tutorato_incarichi SET stato = 'firmata', firmata_direttore_il = NOW() WHERE id = $i70");
prova(registro_aperto(incarico_tutorato($conn, $i70)) === true && aggiungi_registro($conn, $i70, '2026-09-01', '2,5', ' Prima attività ') === null && $s_reg->aggiungi($i70, '2026-09-02', 3, 'Seconda') === null, "registro aperto: attività segnate dalla facciata e dal servizio");
$righe70 = registro_incarico($conn, $i70);
prova(count($righe70) === 2 && $righe70 == $s_reg->righe($i70) && ore_registro($righe70)['inviata'] === 5.5 && decidi_registro($conn, $i70, 'approvata') === 2 && togli_registro($conn, $i70, (int)$righe70[0]['id']) !== null, "registro: ore, approvazione e righe già decise non rimovibili");

// Cartelle protette: ci sono e non si leggono via web
$rel = $c_tut->get(\App\Tutorato\ArchivioIncarichi::class)->scrivi('TU-PROVA70', 'x', "%PDF-1.4\nprova");
$cart = RADICE_SITO . '/' . dirname((string)$rel);
prova($rel !== null && is_file(RADICE_SITO . '/' . $rel) && is_file($cart . '/.htaccess'), "cartella delle lettere protetta (.htaccess)");
if ($rel) @unlink(RADICE_SITO . '/' . $rel);

foreach (["DELETE FROM tutorato_registro WHERE incarico_id = $i70", "DELETE FROM tutorato_eventi WHERE incarico_id = $i70", "DELETE FROM tutorato_incarichi WHERE id = $i70", "DELETE FROM tutorato_bandi WHERE id = $b70"] as $sql) $q($sql);
