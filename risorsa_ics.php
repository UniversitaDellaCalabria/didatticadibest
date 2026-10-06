<?php
// risorsa_ics.php - File .ics di una prenotazione di aula, laboratorio o sportello (Calendari e risorse).
// Il codice della prenotazione (casuale, nell'email di conferma) basta per scaricarlo.
require_once 'config.php';
require_once 'functions.php';

$codice = strtoupper(trim((string)($_GET['code'] ?? '')));
$p = preg_match('/^RS-[0-9A-F]{8}$/', $codice) ? prenotazione_risorsa($conn, $codice) : null;
if (!$p || !in_array($p['stato'], ['confermata', 'da_approvare'], true)) { http_response_code(404); die("Prenotazione non trovata o annullata."); }

while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="prenotazione_' . $p['codice'] . '.ics"');
echo ics_prenotazione_risorsa($p);
