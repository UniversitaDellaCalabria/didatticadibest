<?php
// documento_scuola.php - Scarica la convenzione firmata o l'Allegato A di una scuola dall'Area personale.
//   ?k=CODICE_SCUOLA&c=ID&f=conv   convenzione firmata e protocollata del registro
//   ?k=CODICE_SCUOLA&c=ID&f=all    Allegato A caricato nel registro (PDF)
//   ?k=CODICE_SCUOLA&f=allegato    Allegato A in PDF precompilato con le attività FSL in essere della scuola
// Solo per chi ha fatto l'accesso ed è collegato alla scuola (prenotazione FSL o scuola nel profilo): i file stanno in cartelle bloccate al web.
require_once __DIR__ . '/middleware.php';

$h404 = function (string $msg): never {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(404);
    exit($msg);
};
$servizio = \App\Core\App::per($conn)->get(\App\Fsl\ServizioDocumentiScuola::class);
$codice = (string)($_GET['k'] ?? '');
$f = (string)($_GET['f'] ?? '');
$email = (string)($user_info['email'] ?? '');

if ($f === 'allegato') {
    $doc = $servizio->allegatoGenerato((int)$u_id, $email, $codice);
    if (!$doc) $h404('Documento non disponibile.');
    error_log('[Documenti scuola] utente ' . (int)$u_id . ' scarica l\'Allegato A di ' . $codice);
    \App\Infrastructure\Documenti\Download::invia($doc['file'], $doc['nome'], 'application/pdf');
}

$file = $servizio->fileConvenzione((int)$u_id, $email, $codice, (int)($_GET['c'] ?? 0), $f === 'all' ? 'file_allegato' : 'file_convenzione');
if (!$file) $h404('Documento non disponibile.');
error_log('[Documenti scuola] utente ' . (int)$u_id . ' scarica ' . ($f === 'all' ? 'l\'Allegato A' : 'la convenzione') . ' ' . (int)($_GET['c'] ?? 0) . ' di ' . $codice);
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: ' . ($file['ext'] === 'pdf' ? 'application/pdf' : 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . preg_replace('/[^\w.\-]+/', '_', $file['nome']) . '"');
header('Content-Length: ' . filesize($file['percorso']));
header('X-Content-Type-Options: nosniff');
readfile($file['percorso']);
exit;
