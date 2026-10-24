<?php
// guida_studente.php - vecchio indirizzo: la guida ora è la pagina «Domande frequenti» dell'assistenza.
$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: domande_frequenti.php' . ($qs !== '' ? '?' . $qs : ''), true, 301);
exit;
