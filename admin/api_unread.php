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

$p_id = isset($_GET['p_id']) ? (int)$_GET['p_id'] : 0;
if ($p_id <= 0) {
    echo json_encode(['unread' => 0]);
    exit;
}

$n = \App\Core\App::per($conn)->get(\App\Iscritti\ServizioMessaggi::class)->nonLetti($p_id);
echo json_encode(['unread' => $n, 'auth' => true]);
