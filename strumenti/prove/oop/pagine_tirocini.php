<?php
// Prove via HTTP (ambiente locale acceso): l'elenco delle aziende per la tesi e la pagina dei tirocini si vedono solo dopo l'accesso;
// chi non è entrato trova una pagina che lo dice e il pulsante per accedere (che poi riporta alla pagina voluta).
sezione("Tirocini e aziende per la tesi: pagine visibili solo dopo l'accesso");
foreach (['aziende_convenzionate_tesi.php', 'tirocini.php', 'tirocini.php?tipo=interni'] as $pag) {
    $r = $http('/eventi/' . $pag);
    prova($r['codice'] === 401 && str_contains($r['corpo'], 'Per visualizzare questa pagina devi prima fare l\'accesso') && str_contains($r['corpo'], 'saml_login.php?redirect=' . urlencode($pag)) && !str_contains($r['corpo'], 'Fatal error'),
        "$pag senza accesso: avviso «devi prima fare l'accesso» e pulsante Accedi");
    $r = $http('/eventi/' . $pag, null, $jar);
    prova($r['codice'] === 200 && !str_contains($r['corpo'], 'devi prima fare l\'accesso') && !str_contains($r['corpo'], 'Fatal error') && !str_contains($r['corpo'], 'Warning:'), "$pag dopo l'accesso: si apre");
}
$r = $http('/eventi/admin/tirocini.php', null, $jar);
prova($r['codice'] === 200 && str_contains($r['corpo'], 'Carica il report del TSP') && !str_contains($r['corpo'], 'Fatal error'), "admin/tirocini.php da amministratore: si apre");
$r = $http('/eventi/admin/tirocini.php');
prova($r['codice'] !== 200 || !str_contains($r['corpo'], 'Carica il report del TSP'), "admin/tirocini.php senza accesso: non si vede");
prova($http('/eventi/richiedi_convenzione_tesi.php')['codice'] === 200, "richiedi_convenzione_tesi.php resta aperta alle aziende senza accesso");
