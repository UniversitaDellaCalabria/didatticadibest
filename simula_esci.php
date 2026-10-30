<?php
// simula_esci.php - «Torna a te»: finisce la simulazione di accesso e rimette l'identità vera dell'amministratore (App\Auth\SimulazioneAccesso).
// Solo POST con il token di sessione; la guardia della simulazione lascia passare questa pagina.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: index.php');
    exit;
}
csrf_verify($_POST['csrf_token'] ?? '');
\App\Core\App::per($conn)->get(\App\Auth\SimulazioneAccesso::class)->termina((string)($_SERVER['REMOTE_ADDR'] ?? ''));
flash_set('Sei tornato al tuo accesso.');
header('Location: admin/simula.php');
exit;
