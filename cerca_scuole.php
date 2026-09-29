<?php
// cerca_scuole.php - Ricerca nell'anagrafe delle scuole per il campo "Scuola" dei moduli (JSON).
// Dati pubblici (open data del Ministero); limite di richieste per indirizzo IP contro gli abusi.
require_once 'config.php';
require_once 'functions.php';

while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
if (mb_strlen($q) < 3) { echo '[]'; exit; }
if (!check_rate_limit($conn, 'cerca_scuole', 120, 300)) { http_response_code(429); echo '[]'; exit; }

echo json_encode(cerca_scuole($conn, $q), JSON_UNESCAPED_UNICODE);
