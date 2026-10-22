<?php
// admin/push_ajax.php - Notifiche a finestrella per gli operatori dell'assistenza: iscrizione di questo browser, preferenze (silenzia, solo le mie chat), notifica di prova.
ini_set('display_errors', 0);

require_once '../config.php';
require_once '../functions.php';

while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$rispondi = function (array $dati, int $codice = 200) {
    http_response_code($codice);
    echo json_encode($dati, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};

$app = \App\Core\App::per($conn);
$utente = !empty($_SESSION['utente_id']) ? $app->get(\App\Auth\UtenteRepository::class)->perId((int)$_SESSION['utente_id']) : null;
if (!$utente) $rispondi(['ok' => false, 'errore' => 'Sessione scaduta: ricarica la pagina.'], 401);
if (!$app->get(\App\Assistenza\OperatoriAssistenza::class)->eOperatore($utente)) $rispondi(['ok' => false, 'errore' => 'Accesso negato.'], 403);

$push = $app->get(\App\Assistenza\Push\ServizioPush::class);
$uid = (int)$utente['id'];
$sito = $app->get(\App\Core\Sito::class);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    session_write_close();
    $endpoint = (string)($_GET['endpoint'] ?? '');
    $pref = $push->leggiPreferenze($uid);
    $rispondi(['ok' => true, 'attivo' => $push->attivo(), 'chiave' => $push->chiavePubblica(), 'iscritto' => $endpoint !== '' && $push->iscrittoOperatore($endpoint, $uid)] + $pref);
}

if (!(new \App\Auth\Csrf(new \App\Auth\SessioneNativa()))->valido($_POST['csrf_token'] ?? '')) $rispondi(['ok' => false, 'errore' => 'Sessione scaduta: ricarica la pagina.'], 403);
session_write_close();

$azione = (string)($_POST['azione'] ?? '');
if ($azione === 'iscrivi') {
    $err = $push->iscriviOperatore($uid, (array)json_decode((string)($_POST['sottoscrizione'] ?? ''), true), (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $rispondi($err === null ? ['ok' => true] : ['ok' => false, 'errore' => $err]);
}
if ($azione === 'disiscrivi') {
    $push->disiscrivi((string)($_POST['endpoint'] ?? ''), $uid);
    $rispondi(['ok' => true]);
}
if ($azione === 'preferenze') {
    $rispondi(['ok' => true] + $push->preferenze($uid, max(0, (int)($_POST['silenzia'] ?? 0)), !empty($_POST['solo_mie'])));
}
if ($azione === 'prova') {
    $n = $push->prova($uid, $sito->urlBase() . '/admin/assistenza.php');
    $rispondi($n > 0 ? ['ok' => true, 'inviate' => $n] : ['ok' => false, 'errore' => 'Non è partita nessuna notifica: controlla di averle attivate su questo computer (e che il server riesca a raggiungere internet).']);
}
$rispondi(['ok' => false, 'errore' => 'Azione non valida.'], 400);
