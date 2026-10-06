<?php
// Prove via HTTP delle pagine del modulo Anagrafi e catalogo di Ateneo (ambiente locale acceso).
sezione("Modulo Anagrafi: pagine");
foreach (['anagrafe_personale.php', 'anagrafe_personale.php?vista=docenti', 'anagrafe_personale.php?vista=pta', 'anagrafe_personale.php?vista=insegnamenti', 'anagrafe_personale.php?vista=corsi', 'anagrafe_personale.php?vista=strutture', 'anagrafe_docenti.php', 'scuole.php'] as $pag) {
    $r = $http("/eventi/admin/$pag", null, $jar);
    prova($r['codice'] === 200 && !str_contains($r['corpo'], 'Fatal error') && !str_contains($r['corpo'], 'Warning:'), "admin/$pag da amministratore: 200 senza errori PHP");
}
$r = $http('/eventi/admin/cerca_personale.php?q=ross', null, $jar);
prova($r['codice'] === 200 && isset(json_decode($r['corpo'], true)['risultati']), "admin/cerca_personale.php: JSON con i risultati");
prova($http('/eventi/admin/cerca_personale.php?q=ross')['codice'] === 403, "admin/cerca_personale.php senza accesso: 403");
prova($http('/eventi/admin/anagrafe_personale.php', null, $jar2)['codice'] !== 500, "anagrafe del personale: il docente non provoca errori");
$r = $http('/eventi/cerca_scuole.php?q=lic');
prova($r['codice'] === 200 && is_array(json_decode($r['corpo'], true)), "cerca_scuole.php: JSON");
prova($http('/eventi/cerca_scuole.php?elenco=regioni')['codice'] === 200, "cerca_scuole.php: elenco delle regioni");
prova($http('/eventi/cerca_insegnamenti.php?azione=tipi')['codice'] === 401, "cerca_insegnamenti.php senza accesso: 401");
$r = $http('/eventi/cerca_insegnamenti.php?azione=tipi', null, $jar);
prova($r['codice'] === 200 && is_array(json_decode($r['corpo'], true)), "cerca_insegnamenti.php: tipi di corso");
prova($http('/eventi/cerca_insegnamenti.php?azione=xx', null, $jar)['codice'] === 400, "cerca_insegnamenti.php: azione non valida");
prova($http('/eventi/persona.php?id=non.esiste')['codice'] === 404, "persona.php: persona sconosciuta, pagina non trovata");
prova($http('/eventi/admin/anagrafe_personale.php', ['sincronizza' => '1', 'csrf_token' => 'sbagliato'], $jar)['codice'] === 403, "anagrafe del personale: token CSRF sbagliato respinto");
