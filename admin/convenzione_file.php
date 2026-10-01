<?php
// convenzione_file.php - Scarica la convenzione firmata o l'Allegato A di una scuola (pannello Formazione Scuola Lavoro).
// I file stanno in uploads/convenzioni/, bloccata al web: si leggono solo da qui, dal pannello.
require_once 'admin_header.php';

if (!$puo_fsl_convenzioni) nega_accesso(); // amministratori e abilitati alla FSL o alle convenzioni

$id = (int)($_GET['id'] ?? 0);
$col = ($_GET['f'] ?? '') === 'all' ? 'file_allegato' : 'file_convenzione';
$c = $conn->query("SELECT scuola_codice, $col AS file FROM convenzioni_scuole WHERE id = $id")->fetch_assoc();
$base = realpath(dirname(__DIR__) . '/uploads/convenzioni');
$percorso = $c && !empty($c['file']) ? realpath(dirname(__DIR__) . '/' . $c['file']) : false;
if (!$percorso || !$base || strpos($percorso, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($percorso)) {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(404);
    exit('File non trovato.');
}
$ext = strtolower(pathinfo($percorso, PATHINFO_EXTENSION));
$nome = ($col === 'file_allegato' ? 'Allegato_A_' : 'Convenzione_') . preg_replace('/[^A-Z0-9]/', '', (string)$c['scuola_codice']) . '.' . $ext;
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: ' . ($ext === 'pdf' ? 'application/pdf' : 'application/pkcs7-mime'));
header('Content-Disposition: ' . ($ext === 'pdf' ? 'inline' : 'attachment') . '; filename="' . $nome . '"');
header('Content-Length: ' . filesize($percorso));
header('X-Content-Type-Options: nosniff');
readfile($percorso);
exit;
