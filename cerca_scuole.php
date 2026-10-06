<?php
// cerca_scuole.php - Ricerca nell'anagrafe delle scuole per il campo "Scuola" dei moduli (JSON).
// Dati pubblici (open data del Ministero); limite di richieste per indirizzo IP contro gli abusi.
require_once 'config.php';
require_once 'functions.php';

while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

// ?elenco=regioni | province&regione=… | comuni&regione=…&provincia=…  → luoghi per le tendine della finestra guidata
// ?q=…[&regione=…&provincia=…&comune=…]                                  → scuole (con comune o provincia basta anche q vuota)
if (!check_rate_limit($conn, 'cerca_scuole', 400, 300)) { http_response_code(429); echo '[]'; exit; }
$par = fn(string $k) => mb_substr(trim((string)($_GET[$k] ?? '')), 0, 80);

if (isset($_GET['elenco'])) {
    echo json_encode(luoghi_scuole($conn, $par('elenco'), $par('regione'), $par('provincia')), JSON_UNESCAPED_UNICODE);
    exit;
}

$q = $par('q');
$filtri = ['regione' => $par('regione'), 'provincia' => $par('provincia'), 'comune' => $par('comune')];
if (mb_strlen($q) < 3 && $filtri['comune'] === '' && $filtri['provincia'] === '') { echo '[]'; exit; }

echo json_encode(cerca_scuole($conn, $q, $filtri['comune'] !== '' ? 200 : ($filtri['provincia'] !== '' ? 80 : 20), $filtri), JSON_UNESCAPED_UNICODE);
