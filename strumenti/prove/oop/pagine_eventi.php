<?php
// Prove via HTTP delle pagine del modulo Eventi (ambiente locale acceso): aree, eventi, progetti, agenda, report, statistiche.
sezione("Modulo Eventi: pagine");
$senza_errori = fn(array $r) => $r['codice'] === 200 && !str_contains($r['corpo'], 'Fatal error') && !str_contains($r['corpo'], 'Warning:') && !str_contains($r['corpo'], 'Uncaught');

foreach (['agenda.php', 'agenda.php?ambito=orientamento', 'agenda.php?scuole=1', 'agenda.php?q=geo', 'orientamento.php', 'ricerca.php?q=geo', 'openlab.php', 'seminari.php', 'openlab_archivio.php'] as $pag) {
    prova($senza_errori($http("/eventi/$pag")), "$pag senza accesso: 200 senza errori PHP");
}
$r = $http('/eventi/agenda_ics.php');
prova($r['codice'] === 200 && str_starts_with($r['corpo'], 'BEGIN:VCALENDAR') && str_contains($r['corpo'], 'END:VCALENDAR'), "agenda_ics.php: calendario .ics");
prova(str_starts_with($http('/eventi/genera_ics.php?t_id=100')['corpo'], 'BEGIN:VCALENDAR'), "genera_ics.php: calendario del turno");
foreach (['aree.php', 'nuova_area.php', 'report_ambiti.php', 'report_ambiti.php?anno=2025', 'eventi.php?p_id=1', 'eventi.php?p_id=1&azione=nuovo', 'eventi.php?p_id=1&id=10', 'progetti.php?p_id=2', 'progetti.php?p_id=2&azione=nuovo', 'progetti.php?p_id=2&id=20',
          'archivio.php?p_id=1', 'statistiche.php?p_id=1', 'impostazioni_area.php?p_id=1'] as $pag) {
    prova($senza_errori($http("/eventi/admin/$pag", null, $jar)), "admin/$pag da amministratore: 200 senza errori PHP");
}
foreach (['report_ambiti.php?anno=2026&excel=1', 'statistiche.php?p_id=1&export_csv=1', 'statistiche.php?p_id=1&export_excel=1'] as $pag) {
    $r = $http("/eventi/admin/$pag", null, $jar);
    prova($r['codice'] === 200 && !str_contains($r['corpo'], 'Fatal error'), "admin/$pag: esportazione");
}
prova($http('/eventi/admin/report_ambiti.php')['codice'] !== 200, "admin/report_ambiti.php senza accesso: non si vede");
prova($http('/eventi/admin/nuova_area.php', null, $jar2)['codice'] === 403, "admin/nuova_area.php: il docente non può creare aree");
foreach (['eventi.php?p_id=1', 'progetti.php?p_id=2', 'aree.php', 'archivio.php?p_id=1', 'impostazioni_area.php?p_id=1'] as $pag) {
    prova($http("/eventi/admin/$pag", null, $jar2)['codice'] !== 500, "admin/$pag: il docente non provoca errori");
}
foreach (['eventi.php?p_id=1' => ['del_ev' => '10'], 'progetti.php?p_id=2' => ['elimina_progetto' => '20'], 'aree.php' => ['tipo_area_pagina' => '1', 'tipo_area' => 'eventi'], 'archivio.php?p_id=1' => ['del_ev' => '1', 'evento_id' => '10']] as $pag => $dati) {
    prova($http("/eventi/admin/$pag", $dati + ['csrf_token' => 'sbagliato'], $jar)['codice'] === 403, "admin/$pag: token CSRF sbagliato respinto");
}
