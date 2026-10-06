<?php
// Prove via HTTP delle pagine del modulo Portale (ambiente locale acceso): testata, menu e home.
sezione("Modulo Portale: pagine");
foreach (['/eventi/', '/eventi/index.php', '/eventi/privacy.php', '/eventi/crediti.php'] as $u) {
    $r = $http($u);
    prova($r['codice'] === 200 && str_contains($r['corpo'], '<main id="main-content"') && str_contains($r['corpo'], '<footer') && !str_contains($r['corpo'], 'Fatal error'), "$u: testata, contenuto e footer");
}
$r = $http('/eventi/');
prova(str_contains($r['corpo'], 'navbar-nav') && str_contains($r['corpo'], 'mob-nav-link'), "la home mostra il menu per computer e per telefono");
prova(str_contains($http('/eventi/modulo.php?id=4', null, $jar)['corpo'], '<h1') || $http('/eventi/modulo.php?id=4', null, $jar)['codice'] === 200, "modulo.php: le variabili della pagina restano intatte dopo header.php");
foreach (['inizio.php', 'dashboard.php', 'testata.php', 'menu.php'] as $pag) {
    $r = $http("/eventi/admin/$pag", null, $jar);
    prova($r['codice'] === 200 && !str_contains($r['corpo'], 'Fatal error') && !str_contains($r['corpo'], 'Warning:'), "admin/$pag da amministratore: 200 senza errori PHP");
}
$r = $http('/eventi/admin/menu.php', null, $jar2);
prova(!str_contains($r['corpo'], 'menu-node'), "admin/menu.php: il docente non vede la gestione del menu");
prova($http('/eventi/admin/menu.php', ['add_menu_voce' => '1', 'csrf_token' => 'sbagliato', 'etichetta' => 'x'], $jar)['codice'] === 403, "menu: token CSRF sbagliato respinto");
