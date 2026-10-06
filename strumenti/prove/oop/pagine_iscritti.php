<?php
// Prove via HTTP delle pagine del modulo Iscritti (ambiente locale acceso): pannello iscritti, partecipanti, scanner, messaggi, badge,
// stampa della lista, form builder, endpoint dei messaggi non letti e cron (accesso negato).
sezione("Modulo Iscritti: pagine");
$senza_errori = fn(array $r) => $r['codice'] === 200 && !str_contains($r['corpo'], 'Fatal error') && !str_contains($r['corpo'], 'Warning:') && !str_contains($r['corpo'], 'Uncaught');

foreach (['iscritti.php?p_id=1', 'iscritti.php?p_id=1&f_turno=100', 'iscritti.php?p_id=1&f_stato=confermata', 'iscritti.php?p_id=1&f_cerca=rossi&f_data_da=2020-01-01&f_data_fine=2999-12-31',
          'iscritti.php?p_id=1&archivio=1', 'iscritti.php?p_id=2', 'iscritti.php?p_id=1&page=99', 'partecipanti.php?p_id=1', 'partecipanti.php?p_id=2', 'scanner.php?p_id=1', 'scanner.php?p_id=1&turno=100',
          'messaggi.php?p_id=1', 'stampa_badge.php?p_id=1', 'stampa_lista_iscritti.php?p_id=1', 'stampa_lista_iscritti.php?p_id=1&f_stato=in_attesa', 'form_builder.php?p_id=1'] as $pag) {
    prova($senza_errori($http("/eventi/admin/$pag", null, $jar)), "admin/$pag da amministratore: 200 senza errori PHP");
}

// Endpoint JSON
$r = $http('/eventi/admin/api_unread.php?p_id=1', null, $jar);
$j = json_decode($r['corpo'], true);
prova($r['codice'] === 200 && is_array($j) && isset($j['unread']) && $j['auth'] === true, "api_unread.php: numero di conversazioni da leggere");
$j = json_decode($http('/eventi/admin/api_unread.php?p_id=1')['corpo'], true);
prova(($j['auth'] ?? null) === false && ($j['unread'] ?? null) === 0, "api_unread.php senza accesso: auth false");
prova(json_decode($http('/eventi/admin/api_unread.php', null, $jar)['corpo'], true) === ['unread' => 0], "api_unread.php senza area: zero");
$j = json_decode($http('/eventi/admin/scanner.php?p_id=1&turno=100&ajax=stato', null, $jar)['corpo'], true);
prova(($j['esito'] ?? '') === 'ok' && isset($j['presenti'], $j['totale'], $j['lista']), "scanner: stato del turno in JSON");
$j = json_decode($http('/eventi/admin/scanner.php?p_id=1&turno=1&ajax=stato', null, $jar)['corpo'], true);
prova(($j['titolo'] ?? '') === 'Turno non valido', "scanner: turno non valido");
$j = json_decode($http('/eventi/admin/scanner.php?p_id=1&turno=100&ajax=checkin', ['codice' => 'XXXX', 'csrf_token' => 'sbagliato'], $jar)['corpo'], true);
prova(($j['titolo'] ?? '') === 'Sessione scaduta', "scanner: check-in senza token CSRF valido respinto");
$r = $http('/eventi/admin/iscritti.php?p_id=1&ajax_cerca_utenti=1&q=a', null, $jar);
$j = json_decode($r['corpo'], true);
prova(is_array($j) && count($j) <= 20 && (!$j || isset($j[0]['id'], $j[0]['matricola'])), "iscritti: ricerca utenti per la prenotazione manuale");
prova($http('/eventi/admin/iscritti.php?p_id=1&ajax_cerca_utenti=1&q=a', null, $jar2)['corpo'] === '[]', "iscritti: il docente non cerca utenti");
$r = $http('/eventi/admin/iscritti.php?p_id=1&ajax_campi_turno=100', null, $jar);
prova($r['codice'] === 200 && !str_contains($r['corpo'], 'Fatal error') && !str_contains($r['corpo'], '<html'), "iscritti: campi del form del turno (frammento HTML)");
prova($http('/eventi/admin/iscritti.php?p_id=1&ajax_campi_turno=1', null, $jar)['codice'] === 403, "iscritti: campi del form di un turno non dell'area: 403");
$r = $http('/eventi/admin/iscritti.php?p_id=1', ['ajax_action' => 'start', 'p_id' => '1', 'csrf_token' => 'x'], $jar);
prova(json_decode($r['corpo'], true) === ['status' => 'error', 'msg' => 'Token CSRF non valido.'], "email massiva: senza il cookie CSRF doppio è respinta");

// Esportazioni
$pag = $http('/eventi/admin/iscritti.php?p_id=1', null, $jar)['corpo'];
$tok = preg_match('/csrf_token" value="([a-f0-9]+)"/', $pag, $m) ? $m[1] : '';
$r = $http('/eventi/admin/iscritti.php?p_id=1', ['csrf_token' => $tok, 'p_id' => '1', 'export_csv' => '1'], $jar);
prova($r['codice'] === 200 && str_starts_with($r['corpo'], 'Codice,Stato,Presenza,Posti,Nome,Cognome,Matricola,Email,Evento,Turno,Data,Ora'), "iscritti: esportazione CSV");
$r = $http('/eventi/admin/iscritti.php?p_id=1', ['csrf_token' => $tok, 'p_id' => '1', 'export_xls' => '1', 'f_stato_export' => 'confermata'], $jar);
prova($r['codice'] === 200 && str_starts_with($r['corpo'], '<html xmlns:o=') && str_contains($r['corpo'], '<th>Data Registrazione</th></tr>') && str_ends_with($r['corpo'], '</table></body></html>'), "iscritti: esportazione Excel");

// Permessi e protezioni
foreach (['iscritti.php?p_id=1' => ['del_pren' => '1'], 'iscritti.php?p_id=1 ' => ['bulk_azione' => 'annulla', 'bulk_ids' => ['1']], 'messaggi.php?p_id=1' => ['invia_risposta_inbox' => '1', 'prenotazione_id' => '1', 'corpo_messaggio' => 'x'],
          'form_builder.php?p_id=1' => ['add_campo_custom' => '1', 'etichetta' => 'x'], 'partecipanti.php?p_id=1&pr=1' => ['segna_presente' => '1', 'pr' => '1']] as $pag => $dati) {
    prova($http('/eventi/admin/' . trim($pag), $dati + ['csrf_token' => 'sbagliato'], $jar)['codice'] === 403, "admin/" . trim($pag) . ": token CSRF sbagliato respinto");
}
foreach (['iscritti.php?p_id=1', 'partecipanti.php?p_id=1', 'scanner.php?p_id=1', 'messaggi.php?p_id=1', 'stampa_badge.php?p_id=1', 'stampa_lista_iscritti.php?p_id=1', 'form_builder.php?p_id=1'] as $pag) {
    $r = $http("/eventi/admin/$pag", null, $jar2);
    prova($r['codice'] !== 500 && !str_contains($r['corpo'], 'Fatal error'), "admin/$pag: il docente non provoca errori");
}
foreach (['admin/cron_reminders.php', 'cron_background.php'] as $pag) {
    prova($http("/eventi/$pag")['codice'] === 403, "$pag senza accesso: 403");
}
prova($http('/eventi/admin/iscritti.php?p_id=1')['codice'] !== 200, "iscritti.php senza accesso: non si vede");
