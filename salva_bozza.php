<?php
// salva_bozza.php - Salvataggio automatico della bozza dei moduli lunghi delle convenzioni (scuole e aziende). POST con codice di sicurezza, tipo e link personale;
// risponde in JSON. Non serve l'accesso: chi compila ha il suo link personale (il token deve esistere).
define('PAGINA_SENZA_SSO', true); // per chi compila con il link personale: niente accesso SSO (vedi convenzione_tesi.php)
require_once 'config.php';
require_once 'functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$esci = static function (int $codice, array $dati): never {
    http_response_code($codice);
    echo json_encode($dati);
    exit;
};
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $esci(405, ['ok' => false]);
}
if (!(new \App\Auth\Csrf(new \App\Auth\SessioneNativa()))->valido($_POST['csrf_token'] ?? '')) {
    $esci(403, ['ok' => false, 'errore' => 'sessione scaduta']);
}
$tipo = (string) ($_POST['tipo'] ?? '');
$token = (string) ($_POST['token'] ?? '');
$chiave = \App\Portale\BozzaModulo::chiave($tipo, $token);
if ($chiave === null) {
    $esci(400, ['ok' => false]);
}
$tabella = $tipo === 'tesi' ? 'convenzioni_tesi' : 'convenzioni_compilate';
if (\App\Core\App::per($conn)->get(\App\Core\Database::class)->valore("SELECT COUNT(*) FROM $tabella WHERE token = ?", [$token]) < 1) {
    $esci(404, ['ok' => false]);
}
$ok = \App\Core\App::per($conn)->get(\App\Portale\BozzaModulo::class)->salva($chiave, $_POST);
$esci($ok ? 200 : 422, ['ok' => $ok, 'ora' => date('H:i')]);
