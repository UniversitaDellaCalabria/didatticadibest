<?php
// push_persona_ajax.php - Avvisi sul dispositivo per i compiti urgenti («Da fare per te»): iscrizione di questo browser dal profilo, disiscrizione e prova.
ini_set('display_errors', 0);

require_once __DIR__ . '/middleware.php';

while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$rispondi = function (array $dati, int $codice = 200) {
    http_response_code($codice);
    echo json_encode($dati, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};

$push = \App\Core\App::per($conn)->get(\App\Assistenza\Push\ServizioPush::class);
$uid = (int)$u_id;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    session_write_close();
    $endpoint = (string)($_GET['endpoint'] ?? '');
    $rispondi(['ok' => true, 'attivo' => $push->attivo(), 'chiave' => $push->chiavePubblica(), 'iscritto' => $endpoint !== '' && $push->iscrittoPersona($endpoint, $uid)]);
}

if (!(new \App\Auth\Csrf(new \App\Auth\SessioneNativa()))->valido($_POST['csrf_token'] ?? '')) $rispondi(['ok' => false, 'errore' => 'Sessione scaduta: ricarica la pagina.'], 403);
session_write_close();

$azione = (string)($_POST['azione'] ?? '');
if ($azione === 'iscrivi') {
    $err = $push->iscriviPersona($uid, (array)json_decode((string)($_POST['sottoscrizione'] ?? ''), true), (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $rispondi($err === null ? ['ok' => true] : ['ok' => false, 'errore' => $err]);
}
if ($azione === 'disiscrivi') {
    $push->disiscrivi((string)($_POST['endpoint'] ?? ''), $uid);
    $rispondi(['ok' => true]);
}
if ($azione === 'prova') {
    $n = $push->avvisaPersona($uid, 'Avvisi attivi', 'Funziona: ti avviseremo quando qualcosa sta per scadere.', url_base_sito() . '/area_personale.php');
    $rispondi($n > 0 ? ['ok' => true, 'inviate' => $n] : ['ok' => false, 'errore' => 'Non è partita nessuna notifica: controlla di averle attivate su questo dispositivo.']);
}
$rispondi(['ok' => false, 'errore' => 'Azione non valida.'], 400);
