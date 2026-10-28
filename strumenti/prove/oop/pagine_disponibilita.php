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
$r = $http('/eventi/admin/menu.php', null, $jar);
prova($r['codice'] === 200 && str_contains($r['corpo'], 'data-nota="') && str_contains($r['corpo'], 'value="disponibilita_tesi.php"') && str_contains($r['corpo'], 'value="ricerca.php"') && !str_contains($r['corpo'], 'Fatal error'), "admin/menu.php: il menu a tendina elenca tutte le pagine del sito, con le note");
$r = $http('/eventi/area_personale.php', null, $jar);
prova($r['codice'] === 200 && str_contains($r['corpo'], 'class="ui-icona') && str_contains($r['corpo'], 'class="ui-riga') && !str_contains($r['corpo'], 'Fatal error'), "area_personale.php: icone di servizio in alto e righe di lavoro per chi gestisce");
$r = $http('/eventi/admin/didacticus.php?scheda=pagine', null, $jar);
prova($r['codice'] === 200 && str_contains($r['corpo'], 'Pagine del sito di Ateneo') && str_contains($r['corpo'], 'pagine_aggiungi') && !str_contains($r['corpo'], 'Fatal error'), "admin/didacticus.php: la scheda «Pagine di Ateneo» c'è");
$r = $http('/eventi/admin/didacticus.php?scheda=pagine', ['azione' => 'pagine_aggiungi', 'indirizzi' => "https://sito-non-ammesso.example.org/x", 'csrf_token' => 'sbagliato'], $jar);
prova($r['codice'] === 403, "admin/didacticus.php: aggiungere pagine con token CSRF sbagliato è respinto");

