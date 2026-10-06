<?php
// convenzione_precompilata.php - Scarica la convenzione FSL (.docx) già compilata con i dati della prenotazione:
// istituto e sede dall'anagrafe del Ministero, attività, studenti, periodo, durata e tutor. Restano da completare
// codice fiscale e dati del Dirigente scolastico (evidenziati in giallo). Con ?doc=allegato scarica l'Allegato A
// compilato (attività, studenti, periodo, durata, tutor). Accesso con il codice della prenotazione, come la ricevuta.
require_once 'config.php';
require_once 'functions.php';

$codice = strtoupper(trim((string)($_GET['code'] ?? '')));
$pr = preg_match('/^[A-Z0-9-]{4,50}$/', $codice) ? \App\Core\App::per($conn)->get(\App\Fsl\PrenotazioneFslRepository::class)->perCodiceConvenzione($codice) : null;
if (!$pr || !check_rate_limit($conn, 'convenzione_precompilata', 30, 3600)) {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(404);
    exit('Documento non disponibile: controlla il codice della prenotazione.');
}
$doc = ($_GET['doc'] ?? '') === 'allegato' ? 'allegato' : 'convenzione';
$file = genera_convenzione_precompilata($conn, (int)$pr['id'], $doc);
if (!$file) { while (ob_get_level() > 0) ob_end_clean(); http_response_code(500); exit('Non è stato possibile preparare il documento: scarica il modello vuoto dalla pagina dell\'attività.'); }

while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . ($doc === 'allegato' ? 'Allegato_A_FSL_' : 'Convenzione_FSL_') . preg_replace('/[^A-Z0-9-]/', '', $codice) . '.docx"');
header('Content-Length: ' . filesize($file));
header('X-Content-Type-Options: nosniff');
readfile($file);
@unlink($file);
exit;
