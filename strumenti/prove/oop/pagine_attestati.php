<?php
// Prove via HTTP delle pagine del modulo Attestati (ambiente locale acceso): verifica, attestati, elenco degli studenti, cron.
sezione("Modulo Attestati: pagine");
$senza_errori = fn(array $r) => !str_contains($r['corpo'], 'Fatal error') && !str_contains($r['corpo'], 'Warning:') && !str_contains($r['corpo'], 'Uncaught');

// Verifica pubblica
$r = $http('/eventi/verifica_attestato.php');
prova($r['codice'] === 200 && $senza_errori($r) && str_contains($r['corpo'], 'Verifica attestato') && !str_contains($r['corpo'], 'Attestato valido'), "verifica_attestato.php: maschera di ricerca");
$r = $http('/eventi/verifica_attestato.php?c=AT-NONESISTE9');
prova($r['codice'] === 200 && $senza_errori($r) && str_contains($r['corpo'], 'Codice non riconosciuto'), "verifica_attestato.php: codice sconosciuto");
$r = $http('/eventi/verifica_attestato.php?c=' . urlencode('a$b'));
prova($r['codice'] === 200 && str_contains($r['corpo'], 'Codice non riconosciuto'), "verifica_attestato.php: codice con caratteri non validi");

// Attestato personale: serve l'accesso, la presenza e il titolare
$r = $http('/eventi/stampa_attestato.php');
prova(str_contains($r['corpo'], 'Codice di sicurezza non valido.'), "stampa_attestato.php: senza codice");
$r = $http('/eventi/stampa_attestato.php?code=NONESISTE');
prova(str_contains($r['corpo'], 'Nessun dato trovato per questo codice.'), "stampa_attestato.php: codice sconosciuto");
$r = $http('/eventi/stampa_attestato.php?code=OP-PROVA001', null, $jar);
prova($r['codice'] === 200 && str_contains($r['corpo'], 'Attestato non disponibile') && $senza_errori($r), "stampa_attestato.php: senza presenza non si stampa");
$r = $http('/eventi/stampa_attestato.php?code=D1');
prova(str_contains($r['corpo'], 'Accesso negato'), "stampa_attestato.php: anonimo non vede l'attestato di altri");
$r = $http('/eventi/stampa_attestato.php?code=D1', null, $jar);
prova($r['codice'] === 200 && str_starts_with(ltrim($r['corpo']), '<!DOCTYPE html>') && str_contains($r['corpo'], 'cert-container') && str_contains($r['corpo'], 'verifica_attestato.php?c=D1') && $senza_errori($r), "stampa_attestato.php: l'amministratore stampa l'attestato (codice e QR di verifica)");
$r = $http('/eventi/verifica_attestato.php?c=OP-PROVA001');
prova(str_contains($r['corpo'], 'Codice non riconosciuto'), "verifica_attestato.php: la prenotazione senza presenza non ha attestato valido");

// Attestati di gruppo ed elenco degli studenti
foreach (['attestati_gruppo.php?code=OP-PROVA001', 'elenco_studenti.php?code=OP-PROVA001'] as $pag) {
    $r = $http("/eventi/$pag");
    prova($r['codice'] === 302 && str_contains($r['dove'], 'saml_login.php'), "$pag senza accesso: rimando al login");
}
foreach (['attestati_gruppo.php?code=a', 'elenco_studenti.php'] as $pag) {
    prova($http("/eventi/$pag")['codice'] === 400, "$pag: codice non valido (400)");
}
foreach (['attestati_gruppo.php?code=NONESISTE', 'elenco_studenti.php?code=NONESISTE'] as $pag) {
    prova($http("/eventi/$pag", null, $jar)['codice'] === 403, "$pag: prenotazione sconosciuta (403)");
}
$r = $http('/eventi/elenco_studenti.php?code=OP-PROVA001', null, $jar);
prova($r['codice'] === 200 && $senza_errori($r) && str_contains($r['corpo'], 'Elenco degli studenti per gli attestati') && str_contains($r['corpo'], 'name="stud_cognome[]"'), "elenco_studenti.php: modulo dell'elenco");
$r = $http('/eventi/elenco_studenti.php?code=OP-PROVA001&modello=1', null, $jar);
prova($r['codice'] === 200 && (str_starts_with($r['corpo'], 'PK') || str_contains($r['corpo'], 'Cognome;Nome')), "elenco_studenti.php: modello da scaricare");
prova($http('/eventi/elenco_studenti.php?code=OP-PROVA001', ['csrf_token' => 'sbagliato', 'salva_elenco' => '1'], $jar)['codice'] === 403, "elenco_studenti.php: token CSRF sbagliato respinto");
$r = $http('/eventi/attestati_gruppo.php?code=OP-PROVA001', null, $jar);
prova($r['codice'] === 200 && $senza_errori($r), "attestati_gruppo.php: prenotazione senza presenza");

// Cron
prova($http('/eventi/cron_attestati.php')['codice'] === 403 && $http('/eventi/cron_attestati.php', null, $jar2)['codice'] === 403, "cron_attestati.php: senza accesso o da docente non parte");
