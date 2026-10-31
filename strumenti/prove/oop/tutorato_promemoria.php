<?php
// Prove del promemoria automatico al tutor con il registro fermo (dopo 7 giorni). Chiamato da esegui.php. Dati di prova con bando 9896.
sezione("Tutorato: promemoria automatico al tutor dopo 7 giorni");

foreach (["DELETE FROM tutorato_registro WHERE incarico_id IN (SELECT id FROM tutorato_incarichi WHERE bando_id = 9896)", "DELETE FROM tutorato_eventi WHERE incarico_id IN (SELECT id FROM tutorato_incarichi WHERE bando_id = 9896)",
          "DELETE FROM tutorato_incarichi WHERE bando_id = 9896", "DELETE FROM tutorato_bandi WHERE id = 9896"] as $sql) $q($sql);
$q("INSERT INTO tutorato_bandi (id, titolo) VALUES (9896, 'Bando promemoria 9896')");
$q("INSERT INTO tutorato_incarichi (bando_id, codice, cognome, nome, codice_fiscale, email, docente_email, ore, stato, firmata_direttore_il, firmata_docente_il, confermata_il) VALUES
    (9896, 'TU-PR1', 'Fermo', 'Dieci', 'FRMDCI00A01D086X', 'fermo10-9896@example.org', '', 20, 'firmata', NOW() - INTERVAL 10 DAY, NOW() - INTERVAL 11 DAY, NOW() - INTERVAL 12 DAY),
    (9896, 'TU-PR2', 'Recente', 'Cinque', 'RCNCNQ00A01D086X', 'recente5-9896@example.org', '', 20, 'firmata', NOW() - INTERVAL 5 DAY, NOW() - INTERVAL 6 DAY, NOW() - INTERVAL 7 DAY),
    (9896, 'TU-PR3', 'Attivo', 'Tre', 'TTVTRE00A01D086X', 'attivo3-9896@example.org', '', 20, 'firmata', NOW() - INTERVAL 10 DAY, NOW() - INTERVAL 11 DAY, NOW() - INTERVAL 12 DAY)");
$id_att = (int) $conn->query("SELECT id FROM tutorato_incarichi WHERE codice = 'TU-PR3' AND bando_id = 9896")->fetch_row()[0];
$q("INSERT INTO tutorato_registro (incarico_id, data, ore, attivita, stato) VALUES ($id_att, '" . date('Y-m-d', strtotime('-3 days')) . "', 2, 'Esercitazione', 'inviata')");

$EMAIL = [];
\App\Core\App::per($conn)->get(\App\Tutorato\ServizioRegistroTutorato::class)->promemoria();
$a_pr = array_map(fn($m) => strtolower($m['a']), array_filter($EMAIL, fn($m) => str_contains((string) $m['a'], '9896@example.org')));
prova(\App\Tutorato\ServizioRegistroTutorato::GIORNI_REGISTRO_FERMO === 7, "promemoria registro: soglia a 7 giorni");
prova(in_array('fermo10-9896@example.org', $a_pr, true), "promemoria registro: firmata da 10 giorni e nessuna attività, il tutor riceve il promemoria", json_encode($a_pr));
prova(!in_array('recente5-9896@example.org', $a_pr, true), "promemoria registro: firmata da 5 giorni, ancora presto");
prova(!in_array('attivo3-9896@example.org', $a_pr, true), "promemoria registro: ha segnato attività 3 giorni fa, niente promemoria");
$EMAIL = [];
\App\Core\App::per($conn)->get(\App\Tutorato\ServizioRegistroTutorato::class)->promemoria();
prova(array_filter($EMAIL, fn($m) => str_contains((string) $m['a'], 'fermo10-9896@example.org')) === [], "promemoria registro: al massimo uno ogni 7 giorni");

foreach (["DELETE FROM tutorato_registro WHERE incarico_id = $id_att", "DELETE FROM tutorato_eventi WHERE incarico_id IN (SELECT id FROM tutorato_incarichi WHERE bando_id = 9896)", "DELETE FROM tutorato_incarichi WHERE bando_id = 9896", "DELETE FROM tutorato_bandi WHERE id = 9896"] as $sql) $q($sql);
