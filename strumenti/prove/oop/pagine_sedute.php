<?php
// Prove via HTTP delle pagine del modulo Sedute (ambiente locale acceso): pannello consigli e sedute, giustificazione dal link e firma del verbale.
sezione("Modulo Sedute: pagine");
$senza_errori_sed = fn(array $r) => !preg_match('/<b>(Fatal error|Parse error|Warning)<\/b>|Uncaught |Fatal error:/', $r['corpo']);

foreach (['giustifica.php' => 200, 'giustifica.php?t=xyz' => 200, 'firma_verbale.php' => 302, 'firma_verbale.php?t=xyz' => 302] as $pag => $atteso) {
    $r = $http("/eventi/$pag");
    prova($r['codice'] === $atteso && $senza_errori_sed($r), "$pag con un link non valido: $atteso (la firma richiede l'accesso) senza errori PHP", 'risposta ' . $r['codice']);
}
foreach (['didattica.php?tab=sedute', 'didattica.php?tab=sedute&apri=99999', 'didattica.php?tab=sedute&consiglio=99999', 'didattica.php?tab=sedute&consiglio=0', 'didattica.php?tab=sedute&nuova=1'] as $pag) {
    $r = $http("/eventi/admin/$pag&p_id=1", null, $jar);
    prova($r['codice'] === 200 && $senza_errori_sed($r) && str_contains($r['corpo'], '</html>'), "admin/$pag da amministratore: 200 senza errori PHP", 'risposta ' . $r['codice']);
}
foreach (['salva_consiglio' => '1', 'aggiungi_persona_consiglio' => '1', 'togli_persona_consiglio' => '1', 'importa_componenti' => '1', 'salva_qualifiche' => '1', 'salva_seduta' => '1', 'elimina_seduta' => '1', 'invia_convocazione' => '1', 'applica_esiti' => '1', 'salva_decisioni' => '1'] as $azione => $valore) {
    $r = $http('/eventi/admin/didattica.php?p_id=1&tab=sedute', [$azione => $valore, 'csrf_token' => 'sbagliato'], $jar);
    prova($r['codice'] === 403, "admin/didattica.php (sedute): $azione con token CSRF sbagliato respinto", 'risposta ' . $r['codice']);
}
