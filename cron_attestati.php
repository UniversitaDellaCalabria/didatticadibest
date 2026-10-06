<?php
// cron_attestati.php - Motore invio email automatiche per attestati
require_once 'config.php';
require_once 'functions.php';
consenti_esecuzione_cron([1, 2]); // anche i gestori: pulsante "Attestati" in Iscritti

// FASE 3: MUTEX LOCK PER PREVENIRE ESECUZIONI SOVRAPPOSTE E INVIO DOPPIO
$cache_dir = __DIR__ . '/cache';
if (!is_dir($cache_dir)) { @mkdir($cache_dir, 0755, true); }

// Se la cartella cache/ non esiste e non è stato possibile crearla (permessi), è un problema
// di configurazione del server, non un "processo già in esecuzione": lo segnaliamo in modo chiaro.
if (!is_dir($cache_dir) || !is_writable($cache_dir)) {
    die("ERRORE DI CONFIGURAZIONE: la cartella 'cache/' non esiste o non è scrivibile dal server web (" . htmlspecialchars($cache_dir) . "). Crearla manualmente con permessi 755 e riprovare.\n");
}

$lock_file = $cache_dir . '/cron_attestati.lock';

// Anti lock-orfano: se il file di lock esiste da più di 10 minuti, lo consideriamo
// residuo di un'esecuzione precedente interrotta in modo anomalo e lo rimuoviamo.
if (file_exists($lock_file) && (time() - filemtime($lock_file)) > 600) {
    @unlink($lock_file);
}

$lock_handle = fopen($lock_file, 'w+');

// LOCK_EX = Lock esclusivo | LOCK_NB = Non bloccante (se è già in uso, fallisce subito invece di accodarsi)
if (!$lock_handle || !flock($lock_handle, LOCK_EX | LOCK_NB)) {
    die("PROCESSO IN ESECUZIONE: Lo script cron_attestati.php è già in esecuzione in un altro processo (avviato meno di 10 minuti fa). Se il problema persiste oltre 10 minuti, il lock verrà rilasciato automaticamente al prossimo tentativo.\n");
}


// Chi deve ricevere l'email (evento finito, presente, mai inviata prima): App\Attestati\ServizioCronAttestati
$domain = "https://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
$email_inviate = \App\Core\App::per($conn)->get(\App\Attestati\ServizioCronAttestati::class)->invia($domain);

// Rilascio del lock (viene eseguito anche dal sistema alla chiusura del file)
flock($lock_handle, LOCK_UN);
fclose($lock_handle);

// Se l'admin ha cliccato il bottone, lo rimandiamo indietro con un messaggio
if (isset($_SERVER['HTTP_REFERER']) && strpos($_SERVER['HTTP_REFERER'], 'admin') !== false) {
    flash_set("Missione compiuta! Sono state inviate $email_inviate nuove email di attestato agli studenti.");
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit;
}

// Se invece viene avviato dal server in automatico, stampa a video
echo "Elaborazione completata. Email inviate: $email_inviate\n";
?>
