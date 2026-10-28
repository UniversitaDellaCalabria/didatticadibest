<?php
// Prove via HTTP (ambiente locale acceso): pagine pubbliche della disponibilità dei docenti per tesi e tirocini e pagina del docente.
sezione("Disponibilità dei docenti: pagine");
foreach (['disponibilita_tesi.php' => 'Disponibilità dei docenti per la tesi', 'disponibilita_tirocini.php' => 'Disponibilità dei docenti per i tirocini'] as $pag => $titolo) {
    $r = $http("/eventi/$pag");
    prova($r['codice'] === 200 && str_contains($r['corpo'], $titolo) && !str_contains($r['corpo'], 'Fatal error') && !str_contains($r['corpo'], 'Warning:'), "$pag: pagina pubblica, senza bisogno di accedere");
}
$r = $http('/eventi/disponibilita_tesi.php?q=zzzz&corso=ABC&disponibili=1');
prova($r['codice'] === 200 && !str_contains($r['corpo'], 'Fatal error'), "disponibilita_tesi.php: i filtri non rompono la pagina");
prova($http('/eventi/la_mia_disponibilita.php')['codice'] === 302, "la_mia_disponibilita.php senza accesso: rimando al login");
$r = $http('/eventi/la_mia_disponibilita.php', null, $jar);
prova($r['codice'] === 200 && str_contains($r['corpo'], 'La mia disponibilità') && str_contains($r['corpo'], 'name="posti"') && str_contains($r['corpo'], 'name="corsi[]"') && !str_contains($r['corpo'], 'Fatal error'), "la_mia_disponibilita.php: il form per tesi e tirocinio (amministratore)");
