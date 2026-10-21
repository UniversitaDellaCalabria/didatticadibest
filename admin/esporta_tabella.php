<?php
// esporta_tabella.php - Scarica in Excel, PDF o CSV una tabella che la pagina ha già in mano (elenchi filtrati con JavaScript).
// Lo script assets/js/esporta_tabella.js manda qui intestazioni e righe visibili (POST con il token CSRF); qui si rifà il file con
// TabellaEsportabile, uguale a tutte le altre esportazioni del pannello. Solo per chi ha l'accesso al pannello.
ini_set('display_errors', 0);
require_once '../config.php';
require_once '../functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['utente_id'])) {
    http_response_code(403);
    exit('Accesso negato.');
}
csrf_verify($_POST['csrf_token'] ?? '');

$formato = (string)($_POST['formato'] ?? '');
$dati = json_decode((string)($_POST['dati'] ?? ''), true);
if (!in_array($formato, ['xlsx', 'pdf', 'csv'], true) || !is_array($dati) || !isset($dati['intestazioni'], $dati['righe']) || !is_array($dati['intestazioni']) || !is_array($dati['righe'])
    || strlen((string)($_POST['dati'] ?? '')) > 4_000_000 || count($dati['righe']) > 20000) {
    http_response_code(400);
    exit('Richiesta non valida.');
}
$testo = static fn($v) => mb_substr(trim((string)(is_scalar($v) ? $v : '')), 0, 2000);
$int = array_map($testo, array_slice(array_values($dati['intestazioni']), 0, 40));
$righe = [];
foreach ($dati['righe'] as $r) {
    if (is_array($r)) $righe[] = array_map($testo, array_slice(array_values($r), 0, count($int)));
}
$titolo = $testo($_POST['titolo'] ?? '') ?: 'Elenco';
while (ob_get_level() > 0) ob_end_clean();
(new \App\Infrastructure\Documenti\TabellaEsportabile($titolo, $int, $righe, 'Stampato il ' . date('d/m/Y')))
    ->scarica($formato, $testo($_POST['nome'] ?? '') ?: 'elenco');
http_response_code(400);
exit('Formato non disponibile.');
