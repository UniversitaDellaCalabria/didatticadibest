<?php
// inc/simulazione_guardia.php - Guardia della simulazione di accesso («Entra come…», App\Auth\SimulazioneAccesso). Caricata da config.php a ogni richiesta,
// prima di tutto il resto, e volutamente senza classi: usa solo $_SESSION e $_SERVER.
//  - simulazione scaduta (o rovinata): si torna all'identità vera dell'amministratore;
//  - simulazione in corso: SOLA LETTURA. Ogni richiesta che modifica qualcosa (POST e simili, o un indirizzo con un'azione) è fermata con un avviso.
//    Si lasciano passare solo l'uscita dalla simulazione (simula_esci.php) e il logout (esci.php).
if (!empty($_SESSION['simula']) && is_array($_SESSION['simula'])) {
    $__sim = $_SESSION['simula'];
    $__chiavi = ['utente_id', 'utente_cf', 'utente_nome', 'utente_email', 'utente_ruolo_id', 'utente_ruoli_secondari', 'auth_meta'];
    if (empty($__sim['origine']) || !is_array($__sim['origine'])) {
        // simulazione rovinata: si esce del tutto, meglio che restare con l'identità di un altro
        foreach ($__chiavi as $__k) { unset($_SESSION[$__k]); }
        unset($_SESSION['simula']);
    } elseif ((int)($__sim['fino'] ?? 0) < time()) {
        foreach ($__chiavi as $__k) {
            if (($__sim['origine'][$__k] ?? null) === null) { unset($_SESSION[$__k]); } else { $_SESSION[$__k] = $__sim['origine'][$__k]; }
        }
        unset($_SESSION['simula']);
    } else {
        $__script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        if (!in_array($__script, ['simula_esci.php', 'esci.php'], true)) {
            $__metodo = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            $__azione = false;
            foreach (array_keys($_GET) as $__k) {
                if (preg_match('/^(csrf|azione$|elimina|annulla|conferma|rifiuta|approva|cancella|firma|invia|disattiva|attiva|rigenera|promuovi|archivia)/i', (string)$__k)) { $__azione = true; }
            }
            if (!in_array($__metodo, ['GET', 'HEAD'], true) || $__azione) {
                $__msg = 'Stai vedendo il portale come ' . ($__sim['nome'] ?? 'un\'altra persona') . ': è solo una visione, non si può modificare niente. Premi «Torna a te» in fondo alla pagina per uscire.';
                http_response_code(403);
                $__ajax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== '') || stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'json') !== false;
                if ($__ajax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['ok' => false, 'errore' => $__msg], JSON_UNESCAPED_UNICODE);
                } else {
                    header('Content-Type: text/html; charset=utf-8');
                    echo '<!DOCTYPE html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Solo visione</title></head>'
                        . '<body style="font-family:sans-serif;background:#f8fafc;color:#1e293b;max-width:34rem;margin:12vh auto;padding:0 1rem;text-align:center;">'
                        . '<h1 style="font-size:1.4rem;">Questa azione è bloccata</h1><p>' . htmlspecialchars($__msg, ENT_QUOTES, 'UTF-8') . '</p>'
                        . '<form method="post" action="simula_esci.php"><input type="hidden" name="csrf_token" value="' . htmlspecialchars((string)($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') . '">'
                        . '<button type="submit" style="padding:.6rem 1.2rem;font-weight:bold;border:0;border-radius:6px;background:#B80000;color:#fff;cursor:pointer;">Torna a te</button></form></body></html>';
                }
                exit;
            }
        }
    }
    unset($__sim, $__chiavi, $__k, $__script, $__metodo, $__azione, $__msg, $__ajax);
}
