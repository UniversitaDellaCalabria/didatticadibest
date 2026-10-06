<?php
// Prove via HTTP delle pagine del modulo Didattica (ambiente locale acceso): modulistica, moduli online, pratiche dello studente e pannello.
sezione("Modulo Didattica: pagine");
$senza_errori_did = fn(array $r) => !preg_match('/<b>(Fatal error|Parse error|Warning)<\/b>|Uncaught |Fatal error:/', $r['corpo']);

foreach (['modulistica.php' => 200, 'modulistica.php?q=convalida' => 200, 'modulo.php?id=99999' => 302, 'modulo.php' => 302, 'pratiche.php' => 302, 'allegato_pratica.php?p=1&r=0' => 302] as $pag => $atteso) {
    $r = $http("/eventi/$pag");
    prova($r['codice'] === $atteso && $senza_errori_did($r), "$pag senza accesso → $atteso", 'risposta ' . $r['codice']);
}
foreach (['modulistica.php', 'pratiche.php', 'pratiche.php?id=99999', 'allegato_pratica.php?p=99999&r=0', 'modulo.php?id=99999'] as $pag) {
    $r = $http("/eventi/$pag", null, $jar2);
    prova($senza_errori_did($r) && in_array($r['codice'], [200, 404], true), "$pag da docente: senza errori PHP", 'risposta ' . $r['codice']);
}
foreach (['didattica.php', 'didattica.php?tab=pratiche', 'didattica.php?tab=pratiche&stato=tutte', 'didattica.php?tab=pratiche&carico=smistare', 'didattica.php?tab=pratiche&id=99999', 'didattica.php?tab=moduli', 'didattica.php?tab=moduli&nuovo=1',
          'didattica.php?tab=moduli&modifica=99999', 'didattica.php?tab=ufficio', 'didattica.php?tab=statistiche', 'didattica.php?tab=statistiche&dal=2020-01-01&al=2020-02-01', 'didattica.php?tab=sedute'] as $pag) {
    $r = $http("/eventi/admin/$pag" . (str_contains($pag, '?') ? '&p_id=1' : '?p_id=1'), null, $jar);
    prova($r['codice'] === 200 && $senza_errori_did($r) && str_contains($r['corpo'], '</html>'), "admin/$pag da amministratore: 200 senza errori PHP", 'risposta ' . $r['codice']);
}
$r = $http('/eventi/admin/didattica.php?p_id=1', null, $jar2);
prova($r['codice'] === 403, "admin/didattica.php da un docente senza permessi: accesso negato", 'risposta ' . $r['codice']);
foreach (['salva_modulo' => '1', 'elimina_modulo' => '1', 'salva_ufficio' => '1', 'salva_operatore' => '1', 'stato_pratica' => 'accolta', 'assegna_pratica' => '1', 'registra_protocollo' => '1'] as $azione => $valore) {
    $r = $http('/eventi/admin/didattica.php?p_id=1&tab=pratiche', [$azione => $valore, 'csrf_token' => 'sbagliato'], $jar);
    prova($r['codice'] === 403, "admin/didattica.php: $azione con token CSRF sbagliato respinto", 'risposta ' . $r['codice']);
}
