<?php
// Prove della casella unica dei messaggi (App\Iscritti\CasellaMessaggi): prenotazioni di più aree e risposte delle scuole sulla convenzione FSL,
// con il modulo di provenienza e gli stessi permessi di prima. Chiamato da esegui.php. Dati di prova con id 988xx.
sezione("Messaggi: casella unica per tutte le aree e le convenzioni FSL");

use App\Core\App as AppCm;

foreach (["DELETE FROM convenzioni_messaggi WHERE compilazione_id = 98830", "DELETE FROM convenzioni_compilate WHERE id = 98830", "DELETE FROM messaggi_prenotazioni WHERE prenotazione_id IN (98813, 98823)",
          "DELETE FROM prenotazioni WHERE id IN (98813, 98823)", "DELETE FROM turni WHERE id IN (98812, 98822)", "DELETE FROM eventi WHERE id IN (98811, 98821)", "DELETE FROM pagine_eventi WHERE id IN (98810, 98820)"] as $sql) $q($sql);
$q("INSERT INTO pagine_eventi (id, titolo, slug, tipo_area, visibile, colore_primario, gestore_utente_id) VALUES (98810, 'Area orientamento 988', 'area_or_988', 'eventi', 1, '#112233', 98801),
    (98820, 'Area FSL 988', 'area_fsl_988', 'fsl', 1, '#445566', 98802)");
$q("INSERT INTO eventi (id, pagina_id, titolo, tipo, descrizione_breve) VALUES (98811, 98810, 'Evento orientamento 988', 'evento', 'x'), (98821, 98820, 'Attività FSL 988', 'evento', 'x')");
$q("INSERT INTO turni (id, evento_id, nome_turno, data_turno, max_posti) VALUES (98812, 98811, 'T1', '" . $giorni(20) . "', 40), (98822, 98821, 'T2', '" . $giorni(20) . "', 40)");
$q("INSERT INTO prenotazioni (id, turno_id, codice_prenotazione, stato, nome, cognome, email, data_prenotazione) VALUES
    (98813, 98812, 'CM-OR1', 'confermata', 'Anna', 'Orient', 'anna988@example.org', NOW()), (98823, 98822, 'CM-FS1', 'confermata', 'Carlo', 'Fsl', 'carlo988@example.org', NOW())");
$q("INSERT INTO messaggi_prenotazioni (prenotazione_id, mittente_tipo, mittente_id, messaggio, letto, data_invio) VALUES (98813, 'utente', NULL, 'Domanda orientamento', 0, NOW()), (98823, 'utente', NULL, 'Domanda FSL', 0, NOW())");
$q("INSERT INTO convenzioni_compilate (id, token, scuola_codice, email, dati_json) VALUES (98830, 'tokcasella988', 'ZZCM98800A', 'scuola988@example.org', '{\"scuola\":{\"denominazione\":\"LICEO CASELLA 988\"}}')");
$q("INSERT INTO convenzioni_messaggi (compilazione_id, da, tipo, testo) VALUES (98830, 'ufficio', 'promemoria', 'Promemoria'), (98830, 'scuola', 'messaggio', 'Abbiamo caricato il file')");

$cas_cm = AppCm::per($conn)->get(\App\Iscritti\CasellaMessaggi::class);
$tutte_cm = $cas_cm->conversazioni(1, true, true);
$mie_cm = array_values(array_filter($tutte_cm, fn($c) => in_array($c['id'], [98813, 98823, 98830], true)));
$per_cm = array_column($mie_cm, 'modulo', 'id');
prova(count($mie_cm) === 3 && ($per_cm[98813] ?? '') === 'orientamento' && ($per_cm[98823] ?? '') === 'fsl' && ($per_cm[98830] ?? '') === 'fsl', "amministratore: prenotazioni di due aree e convenzione FSL nella stessa casella, ognuna col suo modulo", json_encode($per_cm));
$conv_cm = array_values(array_filter($mie_cm, fn($c) => $c['tipo'] === 'convenzione'))[0] ?? [];
prova(($conv_cm['nome'] ?? '') === 'LICEO CASELLA 988' && ($conv_cm['da_leggere'] ?? 0) === 1 && ($conv_cm['totale'] ?? 0) === 2, "convenzione: nome della scuola, un messaggio da leggere (il promemoria da solo non conta)");
prova(count(array_filter($cas_cm->conversazioni(1, true, false), fn($c) => $c['id'] === 98830 && $c['tipo'] === 'convenzione')) === 0, "senza accesso alle convenzioni FSL le risposte delle scuole non si vedono");

$aree_cm = array_column(array_column($cas_cm->aree(98801, false), 'area'), 'id');
prova(in_array(98810, $aree_cm, true) && !in_array(98820, $aree_cm, true), "gestore di un'area: vede solo le conversazioni della sua area");
$mie_g = array_values(array_filter($cas_cm->conversazioni(98801, false, false), fn($c) => in_array($c['id'], [98813, 98823], true)));
prova(count($mie_g) === 1 && $mie_g[0]['id'] === 98813, "gestore di un'area: nella casella solo la sua prenotazione", json_encode(array_column($mie_g, 'id')));
prova($cas_cm->nonLetti(98801, false, false) === 1 && $cas_cm->nonLetti(98899, false, false) === 0, "contatore del pulsante: solo le conversazioni dell'utente");
prova($cas_cm->nonLetti(1, true, true) >= 3, "contatore dell'amministratore: somma di tutte le aree e delle convenzioni");

// Archivia: sparisce dalla casella e dal contatore, non si cancella, torna da sola se l'altra parte riscrive
$msg_cm = AppCm::per($conn)->get(\App\Iscritti\ServizioMessaggi::class);
$conv_rep_cm = AppCm::per($conn)->get(\App\Fsl\MessaggiConvenzioneRepository::class);
$stato_cm = fn() => array_column(array_filter($cas_cm->conversazioni(1, true, true), fn($c) => in_array($c['id'], [98813, 98830], true)), 'archiviata', 'id');
prova($stato_cm() === [98813 => false, 98830 => false], "prima di archiviare le due conversazioni sono in casella");
$da_leggere_prima = $cas_cm->nonLetti(1, true, true);
$msg_cm->archivia(98813, true);
$conv_rep_cm->archivia(98830, true);
prova($stato_cm() === [98813 => true, 98830 => true] && $cas_cm->nonLetti(1, true, true) === $da_leggere_prima - 2, "archiviate: segnate come lette, fuori dal contatore");
prova((int) $conn->query("SELECT COUNT(*) FROM messaggi_prenotazioni WHERE prenotazione_id = 98813")->fetch_row()[0] === 1 && count($conv_rep_cm->della(98830)) === 2, "archiviare non cancella i messaggi");
$q("UPDATE prenotazioni SET msg_archiviata_il = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id = 98813");
$q("INSERT INTO messaggi_prenotazioni (prenotazione_id, mittente_tipo, mittente_id, messaggio, letto, data_invio) VALUES (98813, 'utente', NULL, 'Nuova domanda', 0, NOW())");
$q("UPDATE convenzioni_compilate SET msg_archiviata_il = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id = 98830");
$q("INSERT INTO convenzioni_messaggi (compilazione_id, da, tipo, testo) VALUES (98830, 'scuola', 'messaggio', 'Altro messaggio')");
prova($stato_cm() === [98813 => false, 98830 => false], "un nuovo messaggio dell'altra parte rimette la conversazione in casella");
$msg_cm->archivia(98813, true);
$msg_cm->archivia(98813, false);
$q("INSERT INTO messaggi_prenotazioni (prenotazione_id, mittente_tipo, mittente_id, messaggio, letto, data_invio) VALUES (98813, 'admin', 1, 'Risposta nostra', 1, NOW())");
prova($stato_cm()[98813] === false, "ripristina: la conversazione torna in casella");
$msg_cm->archivia(98813, true);
$q("INSERT INTO messaggi_prenotazioni (prenotazione_id, mittente_tipo, mittente_id, messaggio, letto, data_invio) VALUES (98813, 'admin', 1, 'Altra risposta nostra', 1, NOW())");
prova($stato_cm()[98813] === true, "una risposta nostra non rimette in casella una conversazione archiviata");

foreach (["DELETE FROM convenzioni_messaggi WHERE compilazione_id = 98830", "DELETE FROM convenzioni_compilate WHERE id = 98830", "DELETE FROM messaggi_prenotazioni WHERE prenotazione_id IN (98813, 98823)",
          "DELETE FROM prenotazioni WHERE id IN (98813, 98823)", "DELETE FROM turni WHERE id IN (98812, 98822)", "DELETE FROM eventi WHERE id IN (98811, 98821)", "DELETE FROM pagine_eventi WHERE id IN (98810, 98820)"] as $sql) $q($sql);
