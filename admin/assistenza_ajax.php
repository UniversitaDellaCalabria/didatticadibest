<?php
// admin/assistenza_ajax.php - Risposte JSON per la casella e le chat degli operatori dell'assistenza (la pagina admin/assistenza.php le chiede ogni pochi secondi)
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
if (!$utente) $rispondi(['ok' => false, 'errore' => 'Sessione scaduta: ricarica la pagina.', 'auth' => false], 401);
if (!$app->get(\App\Assistenza\OperatoriAssistenza::class)->eOperatore($utente)) $rispondi(['ok' => false, 'errore' => 'Accesso negato.'], 403);

$operatore = ['id' => (int)$utente['id'], 'nome' => trim(trim((string)$utente['nome']) . ' ' . trim((string)$utente['cognome'])) ?: (string)$utente['email'],
    'uffici' => $app->get(\App\Assistenza\OperatoriAssistenza::class)->ufficiVisibili($utente)]; // le chat passate a un ufficio le vedono solo le sue persone
$api = $app->get(\App\Assistenza\ChatApi::class);
$azione = (string)($_REQUEST['azione'] ?? 'elenco');
$id = (int)($_REQUEST['c'] ?? 0);

// Le letture non modificano nulla (a parte «letto»): la sessione si libera subito per non fare aspettare le altre richieste della stessa persona
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    session_write_close();
    if ($azione === 'chat') $rispondi($api->chatOperatore($id, (int)($_GET['dopo'] ?? 0), $operatore['uffici']));
    $rispondi($api->elenco($operatore, (string)($_GET['vista'] ?? 'da_prendere')));
}

if (!(new \App\Auth\Csrf(new \App\Auth\SessioneNativa()))->valido($_POST['csrf_token'] ?? '')) $rispondi(['ok' => false, 'errore' => 'Sessione scaduta: ricarica la pagina.'], 403);
session_write_close();
$esito = $api->azioneOperatore($azione, $id, $operatore, $_POST);
if (!empty($esito['ok']) && in_array($azione, ['invia', 'prendi', 'passa', 'chiudi', 'riapri'], true)) {
    // dopo l'azione si rimanda subito lo stato aggiornato della chat
    $esito += $api->chatOperatore($id, (int)($_POST['dopo'] ?? 0), $operatore['uffici']);
}
$rispondi($esito);
