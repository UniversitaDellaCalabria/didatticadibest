<?php
// Prove via HTTP delle pagine del modulo Auth, utenti e sistema (ambiente locale acceso).
sezione("Modulo Auth e sistema: pagine");
foreach (['utenti.php', 'log_accessi.php', 'audit_log.php', 'sistema.php'] as $pag) {
    $r = $http("/eventi/admin/$pag", null, $jar);
    prova($r['codice'] === 200 && !str_contains($r['corpo'], 'Fatal error') && !str_contains($r['corpo'], 'Warning:'), "admin/$pag da amministratore: 200 senza errori PHP");
}
prova($http('/eventi/admin/log_accessi.php?f_cerca=a&f_da=2020-01-01&f_fine=2099-01-01&page=3', null, $jar)['codice'] === 200, "log accessi con filtri e pagina oltre l'ultima");
$r = $http('/eventi/admin/utenti.php', null, $jar2);
prova(!str_contains($r['corpo'], 'usr-card'), "admin/utenti.php: il docente non vede l'elenco utenti");
$r = $http('/eventi/admin/audit_log.php', null, $jar2);
prova(!str_contains($r['corpo'], 'tabellaAudit'), "admin/audit_log.php: il docente non vede il registro");
$anon = $http('/eventi/admin/utenti.php');
prova($anon['codice'] === 302 && str_contains($anon['dove'], 'saml_login.php'), "senza accesso il pannello rimanda al login");
$r = $http('/eventi/profilo.php', null, $jar);
prova($r['codice'] === 302 && str_contains($r['dove'], 'area_personale.php'), "profilo.php rimanda all'area personale");
$r = $http('/eventi/admin/cron_backup.php');
prova($r['codice'] === 403, "cron_backup.php senza chiave né accesso: 403");
prova($http('/eventi/admin/sistema.php', ['save_system_settings' => '1', 'csrf_token' => 'sbagliato'], $jar)['codice'] === 403, "salvataggio impostazioni con token CSRF sbagliato: 403");
