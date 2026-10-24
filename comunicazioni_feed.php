<?php
// comunicazioni_feed.php - Feed RSS delle ultime notizie visibili (?per=studenti|scuole|aziende|docenti per un solo pubblico).
require_once 'config.php';
require_once 'functions.php';

$Cost = \App\Assistenza\Costanti::class;
$per = isset($Cost::PUBBLICI[$_GET['per'] ?? '']) ? (string)$_GET['per'] : '';
$notizie = \App\Core\App::per($conn)->get(\App\Portale\ComunicazioniRepository::class)->notizie($per, '', 30);
$sito = \App\Core\App::per($conn)->get(\App\Core\Sito::class);
$base = rtrim($sito->urlBase(), '/');
$x = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');

while (ob_get_level()) ob_end_clean();
header('Content-Type: application/rss+xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0"><channel>
<title>Notizie del Dipartimento</title>
<link><?php echo $x($base . '/comunicazioni.php'); ?></link>
<description>Le ultime comunicazioni del Dipartimento</description>
<language>it</language>
<?php foreach ($notizie as $n): $u = $base . '/comunicazione.php?id=' . (int)$n['id']; ?>
<item><title><?php echo $x($n['titolo']); ?></title><link><?php echo $x($u); ?></link><guid isPermaLink="true"><?php echo $x($u); ?></guid>
<pubDate><?php echo $x(date(DATE_RSS, strtotime((string)($n['data_pubblicazione'] ?: $n['creato_il'])))); ?></pubDate>
<description><?php echo $x($n['sommario'] !== '' ? $n['sommario'] : \App\Portale\TestoSemplice::estratto((string)$n['testo'], 400)); ?></description></item>
<?php endforeach; ?>
</channel></rss>
