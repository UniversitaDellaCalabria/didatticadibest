<?php
// admin/api_unread.php - Endpoint JSON per il badge messaggi non letti (polling AJAX)
ini_set('display_errors', 0);

require_once '../config.php';
require_once '../src/bootstrap.php'; // solo le classi: questo endpoint leggero non carica functions.php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['utente_id'])) {
    echo json_encode(['unread' => 0, 'auth' => false]);
    exit;
}

// Come il numero del pulsante «Messaggi»: tutte le aree dell'utente e le risposte delle scuole sulle convenzioni FSL
$app = \App\Core\App::per($conn);
$utente = $app->get(\App\Auth\UtenteRepository::class)->perId((int)$_SESSION['utente_id']);
if (!$utente) {
    echo json_encode(['unread' => 0, 'auth' => false]);
    exit;
}
$casella = $app->get(\App\Iscritti\CasellaMessaggi::class);
$n = $casella->nonLetti((int)$utente['id'], \App\Iscritti\CasellaMessaggi::eAmministratore($utente), $casella->puoConvenzioniFsl($utente));
echo json_encode(['unread' => $n, 'auth' => true]);
