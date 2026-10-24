<?php
// admin/editor_upload.php - Immagini inserite dall'editor di testo (TinyMCE): salvate in uploads/editor/ e restituite come indirizzo da inserire nel testo.
// Solo per gli amministratori; la cartella uploads/ non esegue script.
ini_set('display_errors', 0);

require_once '../config.php';
require_once '../functions.php';

while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$rispondi = function (array $dati, int $codice = 200) {
    http_response_code($codice);
    echo json_encode($dati, JSON_UNESCAPED_UNICODE);
    exit;
};

$app = \App\Core\App::per($conn);
$u = !empty($_SESSION['utente_id']) ? $app->get(\App\Auth\UtenteRepository::class)->perId((int)$_SESSION['utente_id']) : null;
if (!$u) $rispondi(['errore' => 'Sessione scaduta: ricarica la pagina.'], 401);
if (!((int)$u['ruolo_id'] === 1 || in_array('1', array_filter(explode(',', (string)($u['ruoli_secondari'] ?? ''))), true))) $rispondi(['errore' => 'Accesso negato.'], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') $rispondi(['errore' => 'Richiesta non valida.'], 405);
if (!(new \App\Auth\Csrf(new \App\Auth\SessioneNativa()))->valido($_POST['csrf_token'] ?? '')) $rispondi(['errore' => 'Sessione scaduta: ricarica la pagina.'], 403);
session_write_close();

$nome = $app->get(\App\Infrastructure\Storage\Upload::class)->salva($_FILES['file'] ?? [], dirname(__DIR__) . '/uploads/editor/', ['png', 'jpg', 'jpeg', 'gif', 'webp'], ['image/png', 'image/jpeg', 'image/gif', 'image/webp']);
if ($nome === null) $rispondi(['errore' => 'Immagine non valida: usa un file PNG, JPG, GIF o WebP.'], 422);

$rispondi(['location' => url_base_sito() . '/uploads/editor/' . $nome]);
