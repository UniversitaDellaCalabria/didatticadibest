<?php
// didacticus_file.php - I file di Didacticus: l'immagine di profilo (?avatar=1) e gli allegati delle sue risposte (?v=ID della voce&i=posizione dell'allegato).
// I file stanno in uploads/didacticus/, chiusa al web: si scaricano solo da qui, e solo se la voce è attiva (quelle riservate a chi accede, solo con il login).
define('PAGINA_SENZA_SSO', true);
require_once 'config.php';
require_once 'functions.php';

while (ob_get_level()) ob_end_clean();
sync_sso_user($conn);
session_write_close();

const TIPI_DIDACTICUS = [
    'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'pdf' => 'application/pdf',
    'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'odt' => 'application/vnd.oasis.opendocument.text', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet', 'txt' => 'text/plain; charset=utf-8',
];
$cartella = __DIR__ . '/uploads/didacticus/';
$app = \App\Core\App::per($conn);

$invia = function (string $percorso, string $tipo, string $nome, bool $inline, int $cache) {
    header('Content-Type: ' . $tipo);
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($percorso));
    header('Cache-Control: ' . ($cache > 0 ? "public, max-age=$cache" : 'private, no-store'));
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . preg_replace('/[^A-Za-z0-9._ -]/', '_', $nome) . '"');
    readfile($percorso);
    exit;
};
$non_trovato = function () {
    http_response_code(404);
    exit('File non trovato.');
};

if (isset($_GET['avatar'])) {
    $file = $app->get(\App\Assistenza\Didacticus\ImpostazioniDidacticus::class)->leggi()['avatar'];
    if ($file !== '' && preg_match('/^avatar_[a-f0-9]{16}\.(png|jpg|jpeg|webp)$/', $file) && is_file($cartella . $file)) {
        $invia($cartella . $file, TIPI_DIDACTICUS[pathinfo($file, PATHINFO_EXTENSION)], 'didacticus.' . pathinfo($file, PATHINFO_EXTENSION), true, 3600);
    }
    $invia(__DIR__ . '/assets/img/didacticus.svg', 'image/svg+xml', 'didacticus.svg', true, 3600);
}

$voce = $app->get(\App\Assistenza\Didacticus\VociRepository::class)->perId((int)($_GET['v'] ?? 0));
if (!$voce || (int)$voce['attiva'] !== 1) $non_trovato();
if ((int)$voce['solo_login'] === 1 && empty($_SESSION['utente_id'])) {
    http_response_code(403);
    exit('Per scaricare questo file devi accedere al portale.');
}
$allegati = \App\Assistenza\Didacticus\VociRepository::allegati($voce);
$a = $allegati[(int)($_GET['i'] ?? -1)] ?? null;
if (!$a || !is_file($cartella . $a['f'])) $non_trovato();
$ext = strtolower(pathinfo($a['f'], PATHINFO_EXTENSION));
if (!isset(TIPI_DIDACTICUS[$ext])) $non_trovato();
$invia($cartella . $a['f'], TIPI_DIDACTICUS[$ext], $a['n'] . (str_ends_with(strtolower($a['n']), '.' . $ext) ? '' : '.' . $ext), in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'pdf'], true), 0);
