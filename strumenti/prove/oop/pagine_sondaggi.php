<?php
// Prove via HTTP delle pagine del modulo Sondaggi (ambiente locale acceso): questionario pubblico e gestione dell'admin.
sezione("Modulo Sondaggi: pagine");
$senza_errori = fn(array $r) => !str_contains($r['corpo'], 'Fatal error') && !str_contains($r['corpo'], 'Warning:') && !str_contains($r['corpo'], 'Uncaught');

foreach ([['sondaggio.php', 'Token di accesso mancante'], ['sondaggio.php?token=nonesiste', 'Link non valido o scaduto.']] as [$pag, $testo]) {
    $r = $http("/eventi/$pag");
    prova($r['codice'] === 200 && $senza_errori($r) && str_contains($r['corpo'], $testo), "$pag: messaggio \"$testo\"");
}
$r = $http('/eventi/sondaggio.php?token=nonesiste', ['submit_sondaggio' => '1', 'sondaggio_id' => '1', 'risposta' => ['1' => '5']]);
prova($r['codice'] === 200 && str_contains($r['corpo'], 'Link non valido o scaduto.') && !str_contains($r['corpo'], 'Sondaggio Completato'), "sondaggio.php: invio con token sconosciuto");

foreach (['sondaggi.php?p_id=1', 'sondaggi.php?p_id=1&f_sond_ev=10', 'sondaggi.php?p_id=1&archivio=1', 'sondaggi.php?p_id=2&f_sond_ev=20', 'sondaggi.php?p_id=24&f_sond_ev=21'] as $pag) {
    $r = $http("/eventi/admin/$pag", null, $jar);
    prova($r['codice'] === 200 && $senza_errori($r) && str_contains($r['corpo'], 'Customer Satisfaction'), "admin/$pag da amministratore: 200 senza errori PHP");
}
$r = $http('/eventi/admin/sondaggi.php?p_id=1&f_sond_ev=10', null, $jar);
prova(str_contains($r['corpo'], 'Seleziona l\'Evento') && (str_contains($r['corpo'], 'Crea Sondaggio') || str_contains($r['corpo'], 'Aggiungi Domanda')), "admin/sondaggi.php: scelta dell'evento e creazione del sondaggio");
prova($http('/eventi/admin/sondaggi.php?p_id=1')['codice'] !== 200, "admin/sondaggi.php senza accesso: non si vede");
prova($http('/eventi/admin/sondaggi.php?p_id=1', null, $jar2)['codice'] !== 500, "admin/sondaggi.php: il docente non provoca errori");
foreach ([['add_sondaggio' => '1', 'evento_id' => '10', 'titolo_sondaggio' => 'X'], ['export_sondaggio_xls' => '1', 'sondaggio_id_export' => '1'], ['invia_mail_sondaggi' => '1', 'evento_id' => '10'], ['ajax_salva_ordine_dom' => '1', 'ids' => ['1']]] as $dati) {
    prova($http('/eventi/admin/sondaggi.php?p_id=1', $dati + ['csrf_token' => 'sbagliato'], $jar)['codice'] === 403, "admin/sondaggi.php: token CSRF sbagliato respinto (" . array_key_first($dati) . ")");
}
