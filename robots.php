<?php
// robots.php - Indicazioni per i motori di ricerca (/robots.txt): il pannello e i file caricati non vanno indicizzati.
require_once 'config.php';
require_once 'functions.php';

$base = rtrim(\App\Core\App::per($conn)->get(\App\Core\Sito::class)->urlBase(), '/');
while (ob_get_level()) ob_end_clean();
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo "User-agent: *\nDisallow: " . parse_url($base, PHP_URL_PATH) . "/admin/\nDisallow: " . parse_url($base, PHP_URL_PATH) . "/uploads/\n\nSitemap: $base/sitemap.xml\n";
