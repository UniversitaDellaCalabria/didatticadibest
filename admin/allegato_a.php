<?php
// allegato_a.php - Scarica l'Allegato A (PDF o Word) o la Convenzione (Word) già precompilati per una scuola, dal pannello Formazione Scuola Lavoro.
//   ?k=CODICE_SCUOLA&doc=allegato_pdf|allegato|convenzione   tutte le prenotazioni FSL in essere della scuola (pagina «Convenzioni da stipulare»)
//   ?pr=ID&doc=...                                            dalla riga di una prenotazione (Iscrizioni): include le altre della stessa scuola
// I dati vengono dall'anagrafe delle scuole e dalle prenotazioni (se la scuola ha compilato il modulo online, dai suoi dati); il logo si aggiunge a mano.
require_once 'admin_header.php';

if (!$puo_fsl_convenzioni) nega_accesso(); // amministratori e abilitati alla FSL o alle convenzioni

$doc = in_array($_GET['doc'] ?? '', \App\Fsl\ServizioConvenzioniScuole::DOCUMENTI, true) ? $_GET['doc'] : 'allegato_pdf';
$servizio = \App\Core\App::per($conn)->get(\App\Fsl\ServizioConvenzioniScuole::class);
$f = !empty($_GET['pr']) ? $servizio->documentoDellaPrenotazione($doc, (int)$_GET['pr']) : $servizio->documento($doc, (string)($_GET['k'] ?? ''));
if (!$f) {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(404);
    exit('Documento non disponibile: la scuola non ha prenotazioni FSL in corso oppure manca il modello Word.');
}
registra_log_audit($conn, "Documento FSL precompilato scaricato", ["Documento" => $doc, "Scuola" => (string)($_GET['k'] ?? ''), "Prenotazione" => (int)($_GET['pr'] ?? 0)]);

while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: ' . \App\Fsl\ServizioConvenzioniScuole::mime($f['nome']));
header('Content-Disposition: attachment; filename="' . preg_replace('/[^\w.\-]+/', '_', $f['nome']) . '"');
header('Content-Length: ' . filesize($f['file']));
header('X-Content-Type-Options: nosniff');
readfile($f['file']);
@unlink($f['file']);
exit;
