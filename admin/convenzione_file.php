<?php
// convenzione_file.php - Scarica la convenzione firmata o l'Allegato A di una scuola (pannello Formazione Scuola Lavoro).
// I file stanno in uploads/convenzioni/, bloccata al web: si leggono solo da qui, dal pannello.
require_once 'admin_header.php';

if (!$puo_fsl_convenzioni) nega_accesso(); // amministratori e abilitati alla FSL o alle convenzioni

// ?c=ID: convenzione firmata caricata dalla scuola (compilazione online), prima della presa in carico
if (isset($_GET['c'])) {
    $ff = \App\Core\App::per($conn)->get(\App\Fsl\ServizioConvenzioneFirmata::class)->fileDaScaricare((int)$_GET['c'], ['dir' => 'direttore', 'allegato' => 'allegato'][$_GET['f'] ?? ''] ?? 'scuola');
    while (ob_get_level() > 0) ob_end_clean();
    if (!$ff) { http_response_code(404); exit('File non trovato.'); }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $ff['nome'] . '"');
    header('Content-Length: ' . filesize($ff['percorso']));
    header('X-Content-Type-Options: nosniff');
    readfile($ff['percorso']);
    exit;
}

$id = (int)($_GET['id'] ?? 0);
$col = ($_GET['f'] ?? '') === 'all' ? 'file_allegato' : 'file_convenzione';
$file = \App\Core\App::per($conn)->get(\App\Fsl\ServizioRegistroConvenzioni::class)->fileDaScaricare($id, $col);
if (!$file) {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(404);
    exit('File non trovato.');
}
$percorso = $file['percorso']; $ext = $file['ext']; $nome = $file['nome'];
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: ' . ($ext === 'pdf' ? 'application/pdf' : 'application/pkcs7-mime'));
header('Content-Disposition: ' . ($ext === 'pdf' ? 'inline' : 'attachment') . '; filename="' . $nome . '"');
header('Content-Length: ' . filesize($percorso));
header('X-Content-Type-Options: nosniff');
readfile($percorso);
exit;
