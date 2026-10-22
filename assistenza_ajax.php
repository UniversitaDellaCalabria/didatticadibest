<?php
// assistenza_ajax.php - Risposte JSON per la chat di chi chiede aiuto (assistenza.php e il pannello «Serve aiuto?»): messaggi nuovi, invio, chiusura, valutazione.
// La conversazione è quella di chi ha il login oppure, per gli esterni, quella del loro link personale (t=…). Mai quella di altri.
ini_set('display_errors', 0);
define('PAGINA_SENZA_SSO', true);

require_once 'config.php';
require_once 'functions.php';

while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$rispondi = function (array $dati, int $codice = 200) {
    http_response_code($codice);
    echo json_encode($dati, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};

sync_sso_user($conn);
$app = \App\Core\App::per($conn);
$srv = $app->get(\App\Assistenza\ServizioAssistenza::class);
$api = $app->get(\App\Assistenza\ChatApi::class);

// La conversazione di chi chiama
$token = strtolower(trim((string)($_REQUEST['t'] ?? '')));
$conv = null;
if ($token !== '') {
    $c = $srv->perToken($token);
    $conv = $c && $c['canale'] === \App\Assistenza\Costanti::CANALE_ESTERNO ? $c : null;
} elseif (!empty($_SESSION['utente_id'])) {
    $mie = $app->get(\App\Assistenza\ConversazioniRepository::class)->diUtente((int)$_SESSION['utente_id'], 1);
    $conv = $mie[0] ?? null;
}
$azione = (string)($_REQUEST['azione'] ?? 'chat');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    session_write_close();
    if (!$conv) $rispondi(['ok' => true, 'attiva' => false, 'non_letti' => 0, 'messaggi' => [], 'conv' => null]);
    if ($azione === 'push') {
        // Il browser chiede se può offrire le notifiche e con quale chiave iscriversi
        $pu = $app->get(\App\Assistenza\Push\ServizioPush::class);
        $rispondi(['ok' => true, 'attivo' => $pu->attivo(), 'chiave' => $pu->chiavePubblica(), 'iscritto' => $pu->iscrittoUtente($conv, (string)($_GET['endpoint'] ?? ''))]);
    }
    if ($azione === 'stato') {
        // Solo i numeri, senza segnare nulla come letto: il pannello chiuso li usa per il pallino «hai una risposta»
        $repo = $app->get(\App\Assistenza\MessaggiAssistenzaRepository::class);
        $rispondi(['ok' => true, 'attiva' => $conv['stato'] !== \App\Assistenza\Costanti::STATO_CHIUSA, 'non_letti' => $repo->nonLetti((int)$conv['id'], \App\Assistenza\Costanti::DA_UTENTE)]);
    }
    $rispondi($api->chatUtente($conv, (int)($_GET['dopo'] ?? 0)));
}

if (!(new \App\Auth\Csrf(new \App\Auth\SessioneNativa()))->valido($_POST['csrf_token'] ?? '')) $rispondi(['ok' => false, 'errore' => 'Sessione scaduta: ricarica la pagina.'], 403);
session_write_close();
if (!$conv) $rispondi(['ok' => false, 'errore' => 'Non hai conversazioni aperte.'], 404);
// Notifiche a finestrella di chi ha chiesto aiuto: iscrizione e rinuncia di questo browser (legate alla sua conversazione)
if ($azione === 'push_iscrivi' || $azione === 'push_disiscrivi') {
    $pu = $app->get(\App\Assistenza\Push\ServizioPush::class);
    if ($azione === 'push_disiscrivi') {
        $pu->disiscriviUtente($conv, (string)($_POST['endpoint'] ?? ''));
        $rispondi(['ok' => true]);
    }
    $err = $pu->iscriviUtente($conv, (array)json_decode((string)($_POST['sottoscrizione'] ?? ''), true), (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $rispondi($err === null ? ['ok' => true] : ['ok' => false, 'errore' => $err]);
}
$esito = $api->azioneUtente($azione, $conv, $_POST);
if (!empty($esito['ok'])) $esito += $api->chatUtente($srv->perId((int)$conv['id']) ?? $conv, (int)($_POST['dopo'] ?? 0));
$rispondi($esito);
