<?php
// Prove delle piccole chiusure di sicurezza e della visibilità sui motori di ricerca (punti 5, 6 e 9 dell'analisi del 30/10/2026). Chiamato da esegui.php.
sezione("Sito: file chiusi, intestazioni, mappa del sito e anteprime");

$r = __DIR__ . '/../../../';
$ht = (string) file_get_contents($r . '.htaccess');
prova(preg_match('#<Files "install\.php">\s*Require all denied#', $ht) === 1, ".htaccess: install.php non è raggiungibile dal web");
prova(str_contains($ht, 'RewriteRule ^sitemap\.xml$ sitemap.php [L]') && str_contains($ht, 'RewriteRule ^robots\.txt$ robots.php [L]'), ".htaccess: sitemap.xml e robots.txt sono serviti dal portale");
prova(is_file($r . 'sitemap.php') && is_file($r . 'robots.php'), "sitemap.php e robots.php esistono");
$cfg = (string) file_get_contents($r . 'config.php');
prova(str_contains($cfg, 'Permissions-Policy') && str_contains($cfg, 'camera=(self)') && str_contains($cfg, 'geolocation=()'), "intestazioni: Permissions-Policy (la fotocamera resta per lo scanner)");
$badge = (string) file_get_contents($r . 'admin/stampa_badge.php');
prova(str_contains($badge, "csrf_verify(\$_POST['csrf_token'] ?? '')") && str_contains($badge, 'csrf_field()'), "stampa_badge.php: modulo con token CSRF e controllo prima di generare i badge");
$testa = (string) file_get_contents($r . 'header.php');
prova(str_contains($testa, 'name="description"') && str_contains($testa, 'property="og:title"') && str_contains($testa, 'rel="canonical"') && str_contains($testa, 'og:image'), "testata: descrizione, anteprima per le condivisioni e indirizzo canonico");
prova(!str_contains((string) file_get_contents($r . 'src/Portale/CatalogoPagineMenu.php'), "'Ricevimento dei docenti', 'ricevimento.php'"), "catalogo menu: il ricevimento per gli studenti non punta più alla pagina del docente");
