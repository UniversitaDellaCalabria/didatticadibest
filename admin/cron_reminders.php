<?php
// Script per l'invio massivo dei solleciti (Cron Job o Trigger Manuale)
require_once __DIR__ . '/../config.php';
if (!function_exists('flash_set')) { require_once __DIR__ . '/../functions.php'; }
consenti_esecuzione_cron([1]); // solo crontab, chiave CRON_KEY o admin (pulsante in Sistema)

// Promemoria agli iscritti degli eventi nelle prossime 72 ore (con la funzione unica di invio email: verifica le risposte SMTP e scrive
// log_email), poi quelli delle risorse e degli altri moduli: App\Iscritti\ServizioPromemoria
$inviati = \App\Core\App::get(\App\Iscritti\ServizioPromemoria::class)->invia();

// Redirect e Output
if (isset($_GET['manual'])) {
    flash_set(" Elaborazione Reminder completata! Sono stati inviati <strong>$inviati</strong> promemoria.");
    header("Location: index.php#tab-sistema");
    exit;
} else {
    // Se eseguito via server cron
    echo "Cron Eseguito: Inviati $inviati promemoria.\n";
}
