<?php
// Crea le chiavi VAPID per le notifiche push del portale (una volta sola, poi si tengono):
//   php strumenti/genera_chiavi_push.php assistenza@unical.it
// Stampa tre righe da incollare nel file .env del server (PUSH_VAPID_SUBJECT è l'indirizzo email di contatto per i servizi push).
// Se le chiavi cambiano, i browser già iscritti vanno iscritti di nuovo: non rigenerarle senza motivo.
if (PHP_SAPI !== 'cli') {
    exit;
}
require __DIR__ . '/../src/autoload.php';

$contatto = $argv[1] ?? '';
if (!filter_var($contatto, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Uso: php strumenti/genera_chiavi_push.php indirizzo.email@unical.it\n");
    exit(1);
}
$k = \App\Assistenza\Push\CurvaEc::genera();
echo "; Notifiche push (assistenza): chiavi VAPID. Non cambiarle: i browser già iscritti smetterebbero di ricevere gli avvisi\n";
echo 'PUSH_VAPID_PUBLIC=' . \App\Assistenza\Push\CurvaEc::b64uCodifica($k['pubblica']) . "\n";
echo 'PUSH_VAPID_PRIVATE=' . \App\Assistenza\Push\CurvaEc::b64uCodifica($k['privata']) . "\n";
echo "PUSH_VAPID_SUBJECT=$contatto\n";
