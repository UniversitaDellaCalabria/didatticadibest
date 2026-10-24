<?php
// comunicazione_file.php - Gli allegati delle comunicazioni (?c=ID&i=posizione). I file stanno in uploads/comunicazioni/, chiusa al web: si scaricano solo da qui,
// e solo se la comunicazione è visibile ai visitatori (o, per l'anteprima, all'amministratore).
define('PAGINA_SENZA_SSO', true);
require_once 'config.php';
require_once 'functions.php';

while (ob_get_level()) ob_end_clean();
sync_sso_user($conn);
session_write_close();

const TIPI_COMUNICAZIONI = [
    'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'pdf' => 'application/pdf',
    'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'odt' => 'application/vnd.oasis.opendocument.text', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet', 'txt' => 'text/plain; charset=utf-8',
];
$non_trovato = function () { http_response_code(404); exit('File non trovato.'); };
$app = \App\Core\App::per($conn);
$rep = $app->get(\App\Portale\ComunicazioniRepository::class);
$id = (int)($_GET['c'] ?? 0);
$c = $rep->visibilePerId($id);
if (!$c && !empty($_GET['anteprima']) && !empty($_SESSION['utente_id'])) {
    $u = $app->get(\App\Auth\UtenteRepository::class)->perId((int)$_SESSION['utente_id']);
    if ($u && ((int)$u['ruolo_id'] === 1 || in_array('1', array_filter(explode(',', (string)($u['ruoli_secondari'] ?? ''))), true))) { $c = $rep->perId($id); }
}
if (!$c) $non_trovato();
$a = \App\Assistenza\Didacticus\VociRepository::allegati($c)[(int)($_GET['i'] ?? -1)] ?? null;
$percorso = __DIR__ . '/uploads/comunicazioni/' . ($a['f'] ?? '');
if (!$a || !is_file($percorso)) $non_trovato();
$ext = strtolower(pathinfo($a['f'], PATHINFO_EXTENSION));
if (!isset(TIPI_COMUNICAZIONI[$ext])) $non_trovato();
$nome = $a['n'] . (str_ends_with(strtolower($a['n']), '.' . $ext) ? '' : '.' . $ext);
header('Content-Type: ' . TIPI_COMUNICAZIONI[$ext]);
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($percorso));
header('Cache-Control: private, no-store');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
header('Content-Disposition: ' . (in_array($ext, ['png', 'jpg', 'jpeg', 'pdf'], true) ? 'inline' : 'attachment') . '; filename="' . preg_replace('/[^A-Za-z0-9._ -]/', '_', $nome) . '"');
readfile($percorso);
