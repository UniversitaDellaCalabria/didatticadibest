<?php
// Prove via HTTP delle pagine del modulo Tutorato (ambiente locale acceso): lettera dello studente, firma del docente/direttore, registro e pannello.
sezione("Modulo Tutorato: pagine");
$senza_errori_tut = fn(array $r) => !preg_match('/<b>(Fatal error|Parse error|Warning)<\/b>|Uncaught |Fatal error:/', $r['corpo']);

foreach (['incarico.php', 'incarico.php?t=' . str_repeat('e', 40), 'firma_incarico.php', 'firma_incarico.php?t=' . str_repeat('e', 40), 'registro_tutorato.php'] as $pag) {
    $r = $http("/eventi/$pag");
    prova(in_array($r['codice'], [302, 303, 403, 404], true) && $senza_errori_tut($r), "$pag senza accesso: rimando al login o rifiuto, senza errori PHP", 'risposta ' . $r['codice']);
}
foreach (['registro_tutorato.php', 'registro_tutorato.php?i=999', 'incarico.php?t=' . str_repeat('e', 40), 'firma_incarico.php?t=' . str_repeat('e', 40)] as $pag) {
    $r = $http("/eventi/$pag", null, $jar2);
    prova($senza_errori_tut($r) && in_array($r['codice'], [200, 302, 303, 403, 404], true), "$pag da docente: senza errori PHP", 'risposta ' . $r['codice']);
}
foreach (['tutorato.php', 'tutorato.php?nuovo_bando=1', 'tutorato.php?bando=999', 'tutorato.php?incarico=999', 'tutorato.php?nuovo_incarico=1&bando=999'] as $pag) {
    $r = $http("/eventi/admin/$pag", null, $jar);
    prova($r['codice'] === 200 && $senza_errori_tut($r) && str_contains($r['corpo'], '</html>'), "admin/$pag da amministratore: 200 senza errori PHP", 'risposta ' . $r['codice']);
}
$r = $http('/eventi/admin/tutorato.php', null, $jar2);
prova($r['codice'] === 403, "admin/tutorato.php da un docente senza permessi: accesso negato", 'risposta ' . $r['codice']);
foreach (['salva_bando' => '1', 'salva_incarico' => '1', 'invia_studente' => '1', 'carica_firmato' => '1'] as $azione => $valore) {
    $r = $http('/eventi/admin/tutorato.php', [$azione => $valore, 'csrf_token' => 'sbagliato'], $jar);
    prova($r['codice'] === 403, "admin/tutorato.php: $azione con token CSRF sbagliato respinto", 'risposta ' . $r['codice']);
}
