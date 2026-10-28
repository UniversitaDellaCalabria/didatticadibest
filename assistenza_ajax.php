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
$cid = (int)($_REQUEST['c'] ?? 0);
if ($token !== '') {
    $c = $srv->perToken($token);
    $conv = $c && $c['canale'] === \App\Assistenza\Costanti::CANALE_ESTERNO ? $c : null;
}
if (!$conv && !empty($_SESSION['utente_id'])) { // senza link valido (o con uno vecchio ricordato dal browser) vale la chat di chi ha il login
    $repo_c = $app->get(\App\Assistenza\ConversazioniRepository::class);
    if ($cid > 0) { // la chat indicata dalla pagina, solo se è sua
        $c = $repo_c->perId($cid);
        $conv = $c && (int)$c['utente_id'] === (int)$_SESSION['utente_id'] ? $c : null;
    } else { // senza indicazione (il pallino «hai una risposta» del pulsante) vale la più recente
        $conv = $repo_c->diUtente((int)$_SESSION['utente_id'], 1)[0] ?? null;
    }
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
        $da_leggere = $repo->nonLetti((int)$conv['id'], \App\Assistenza\Costanti::DA_UTENTE);
        if ($cid === 0 && !empty($_SESSION['utente_id']) && $conv['canale'] === \App\Assistenza\Costanti::CANALE_UTENTE) { // chi ha il login può avere più chat: il pallino le conta tutte (le ultime)
            $da_leggere = 0;
            foreach ($app->get(\App\Assistenza\ConversazioniRepository::class)->diUtente((int)$_SESSION['utente_id'], 5) as $c_r) {
                if ($c_r['stato'] !== \App\Assistenza\Costanti::STATO_CHIUSA) $da_leggere += $repo->nonLetti((int)$c_r['id'], \App\Assistenza\Costanti::DA_UTENTE);
            }
        }
        $rispondi(['ok' => true, 'attiva' => $conv['stato'] !== \App\Assistenza\Costanti::STATO_CHIUSA, 'non_letti' => $da_leggere]);
    }
    $rispondi($api->chatUtente($conv, (int)($_GET['dopo'] ?? 0)));
}

if (!(new \App\Auth\Csrf(new \App\Auth\SessioneNativa()))->valido($_POST['csrf_token'] ?? '')) $rispondi(['ok' => false, 'errore' => 'Sessione scaduta: ricarica la pagina.'], 403);
session_write_close();
// La chat parte da Didacticus: la conversazione nasce solo quando la persona sceglie un argomento o scrive (chi non ha l'accesso ha già indicato nome ed email in assistenza.php)
if ($azione === 'avvia') {
    if (!check_rate_limit($conn, 'assistenza_nuova', 12, 600)) $rispondi(['ok' => false, 'errore' => 'Troppi tentativi: riprova tra qualche minuto.'], 429);
    $avvio = ($_POST['scelta'] ?? '') === 'argomento' ? 'argomento' : 'testo';
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (!empty($_SESSION['utente_id'])) {
        $u = $app->get(\App\Auth\UtenteRepository::class)->perId((int)$_SESSION['utente_id']);
        [$nuova, $err] = $u ? $srv->apriDaUtente($u, (string)($_POST['argomento'] ?? ''), (string)($_POST['testo'] ?? ''), null, $ip, false, $avvio) : [null, 'Accedi di nuovo.'];
    } else {
        $o = $_SESSION['assistenza_ospite'] ?? null;
        [$nuova, $err] = is_array($o) ? $srv->apriDaEsterno((string)$o['nome'], (string)$o['email'], (string)($_POST['argomento'] ?? ''), (string)($_POST['testo'] ?? ''), $ip, null, false, $avvio) : [null, 'Indica prima nome ed email: ricarica la pagina.'];
    }
    if (!$nuova) $rispondi(['ok' => false, 'errore' => $err ?: 'Non sono riuscito ad aprire la chat.']);
    $esterno = $nuova['canale'] === \App\Assistenza\Costanti::CANALE_ESTERNO;
    $rispondi(['ok' => true, 'c' => $esterno ? '' : (int)$nuova['id'], 't' => $esterno ? (string)$nuova['token'] : ''] + $api->chatUtente($nuova, 0));
}
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
