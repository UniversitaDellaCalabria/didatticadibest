<?php
// calendario_ics.php - Il calendario personale in formato .ics: scaricato da chi ha fatto l'accesso, oppure letto dal telefono abbonato con il link
// segreto (?t=…, lo crea calendario.php). Logica in src/Portale/CalendarioPersonale.php.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

$cal = \App\Core\App::get(\App\Portale\CalendarioPersonale::class);
$utenti = \App\Core\App::get(\App\Auth\UtenteRepository::class);

if (isset($_GET['t'])) {
    $uid = $cal->utentePerToken((string)$_GET['t']);
    if ($uid === null) { http_response_code(404); die('Calendario non trovato: il link potrebbe essere stato sostituito.'); }
} else {
    sync_sso_user($conn);
    $uid = (int)($_SESSION['utente_id'] ?? 0);
    if ($uid <= 0) { header('Location: saml_login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'])); exit; }
}
$utente = $utenti->perId($uid);
if (!$utente) { http_response_code(404); die('Calendario non trovato.'); }

$voci = $cal->voci($utente, date('Y-m-d', strtotime('-7 days')), date('Y-m-d', strtotime('+180 days')));

while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: ' . (isset($_GET['t']) ? 'inline' : 'attachment') . '; filename="calendario_dibest.ics"');
header('Cache-Control: private, max-age=900');
echo $cal->ics($voci, 'DiBEST – il mio calendario');
