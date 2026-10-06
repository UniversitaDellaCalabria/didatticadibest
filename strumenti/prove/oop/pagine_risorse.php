<?php
// Prove via HTTP delle pagine del modulo Risorse (ambiente locale acceso): sportelli di ricevimento, pannello e file .ics.
sezione("Modulo Risorse: pagine");
$senza_errori = fn(array $r) => $r['codice'] === 200 && !str_contains($r['corpo'], 'Fatal error') && !str_contains($r['corpo'], 'Warning:') && !str_contains($r['corpo'], 'Uncaught');

prova($http('/eventi/risorsa_ics.php?code=XX')['codice'] === 404, "risorsa_ics.php: codice non valido, pagina non trovata");
prova($http('/eventi/risorsa_ics.php?code=RS-00000000')['codice'] === 404, "risorsa_ics.php: prenotazione inesistente, pagina non trovata");
prova($senza_errori($http('/eventi/ricevimento.php', null, $jar2)), "ricevimento.php da docente: 200 senza errori PHP");
prova($senza_errori($http('/eventi/ricevimento.php', null, $jar)), "ricevimento.php da amministratore: 200 senza errori PHP");
prova($http('/eventi/ricevimento.php', ['risorsa_id' => '1', 'salva_ricevimento' => '1', 'csrf_token' => 'sbagliato'], $jar2)['codice'] === 403, "ricevimento.php: token CSRF sbagliato respinto");
foreach (['risorse.php?p_id=1', 'prenotazioni_risorse.php?p_id=1', 'prenotazioni_risorse.php?p_id=1&modo=elenco', 'prenotazioni_risorse.php?p_id=1&csv=1'] as $pag) {
    $r = $http("/eventi/admin/$pag", null, $jar);
    prova($r['codice'] === 200 && !str_contains($r['corpo'], 'Fatal error') && !str_contains($r['corpo'], 'Warning:'), "admin/$pag da amministratore: 200 senza errori PHP");
}
foreach (['risorse.php' => ['salva_risorsa' => '1', 'nome' => 'X'], 'prenotazioni_risorse.php' => ['azione_pren' => 'annulla', 'pren_id' => '1']] as $pag => $dati) {
    $r = $http("/eventi/admin/$pag?p_id=1", $dati + ['csrf_token' => 'sbagliato'], $jar);
    prova($r['codice'] === 200 && str_contains($r['corpo'], 'non è di tipo Calendari e risorse'), "admin/$pag su un'area che non è di tipo calendario: richiesta rifiutata");
}
