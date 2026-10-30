<?php
// sitemap.php - Mappa del sito per i motori di ricerca (/sitemap.xml): solo pagine pubbliche, senza accesso e senza dati personali.
require_once 'config.php';
require_once 'functions.php';

$sito = \App\Core\App::per($conn)->get(\App\Core\Sito::class);
$base = rtrim($sito->urlBase(), '/');
$pagine = ['', 'agenda.php', 'orientamento.php', 'contatti.php', 'comunicazioni.php', 'scadenze.php', 'corsi.php', 'docenti.php', 'percorso_tesi.php', 'percorso_tirocini.php',
    'disponibilita_tesi.php', 'disponibilita_tirocini.php', 'aziende_convenzionate_tesi.php', 'guida.php', 'privacy.php', 'crediti.php'];
foreach (\App\Core\App::per($conn)->get(\App\Portale\FonteAree::class)->areeVisibili() as $area) {
    if ((int) ($area['visibile'] ?? 1) === 1 && preg_match('/^[a-z0-9_-]+$/i', (string) ($area['slug'] ?? ''))) {
        $pagine[] = $area['slug'] . '.php';
    }
}
$x = fn($s) => htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');

while (ob_get_level()) ob_end_clean();
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach (array_unique($pagine) as $p): ?>
<url><loc><?php echo $x($base . '/' . $p); ?></loc></url>
<?php endforeach; ?>
</urlset>
