<?php
// Prove via HTTP delle pagine del modulo FSL (ambiente locale acceso): convenzione online, documenti precompilati, scheda di valutazione e pannello.
sezione("Modulo FSL: pagine");
$senza_errori = fn(array $r) => !str_contains($r['corpo'], 'Fatal error') && !str_contains($r['corpo'], 'Warning:') && !str_contains($r['corpo'], 'Uncaught');

foreach (['convenzione_online.php' => 404, 'convenzione_online.php?code=NONESISTE' => 404, 'convenzione_online.php?t=ffff' => 404, 'convenzione_precompilata.php?code=NONESISTE' => 404] as $pag => $atteso) {
    $r = $http("/eventi/$pag");
    prova($r['codice'] === $atteso && $senza_errori($r), "$pag → $atteso", 'risposta ' . $r['codice']);
}
prova($senza_errori($http('/eventi/valutazione_fsl.php?t=xyz')), "valutazione_fsl.php: link non valido senza errori PHP");
prova($senza_errori($http('/eventi/valutazione_fsl.php?t=' . str_repeat('a', 40))), "valutazione_fsl.php: link valido ma senza prenotazione, senza errori PHP");
foreach (['fsl.php', 'fsl.php?tab=riepilogo', 'fsl.php?tab=convenzioni', 'fsl.php?tab=convenzioni&vista=archivio', 'fsl.php?tab=convenzioni&conv_mod=999', 'fsl.php?tab=convenzioni&conv_nuova=CSPS00001A&dal=2026-10-13&al=2026-12-02',
          'fsl.php?tab=verifica', 'fsl.php?tab=valutazioni', 'fsl.php?tab=riepilogo&anno=2025'] as $pag) {
    $r = $http("/eventi/admin/$pag", null, $jar);
    prova($r['codice'] === 200 && $senza_errori($r) && str_contains($r['corpo'], '</html>'), "admin/$pag da amministratore: 200 senza errori PHP", 'risposta ' . $r['codice']);
}
$r = $http('/eventi/admin/fsl.php', null, $jar2);
prova($r['codice'] === 403, "admin/fsl.php da un docente senza permessi: accesso negato", 'risposta ' . $r['codice']);
foreach (['conv_verifica' => '1', 'conv_elimina' => '1', 'conv_online_prot' => '1', 'conv_salva' => '1'] as $azione => $valore) {
    $r = $http('/eventi/admin/fsl.php?tab=convenzioni', [$azione => $valore, 'csrf_token' => 'sbagliato'], $jar);
    prova($r['codice'] === 403, "admin/fsl.php: $azione con token CSRF sbagliato respinto", 'risposta ' . $r['codice']);
}
prova($http('/eventi/admin/convenzione_file.php?id=999', null, $jar)['codice'] === 404, "admin/convenzione_file.php: convenzione senza file, file non trovato");
prova($http('/eventi/admin/convenzione_file.php?id=1', null, $jar2)['codice'] === 403, "admin/convenzione_file.php da un docente senza permessi: accesso negato");
