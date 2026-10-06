<?php
// inc/sistema.php - Registro delle operazioni, CSRF, file .env, backup cifrati, controllo del sito, riepiloghi, cache della configurazione.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

// =======================================================================
// AUDIT LOG (Registrazione delle attività degli amministratori)
// =======================================================================
if (!function_exists('registra_log_audit')) {
    // Facciata di App\Infrastructure\Audit\AuditLog: chi (dalla sessione) e da dove (IP) fa l'operazione
    function registra_log_audit($conn, $azione, $dettagli_array = []) {
        if (empty($_SESSION['utente_id'])) return false;
        return (new \App\Infrastructure\Audit\AuditLog(\App\Core\Database::per($conn)))
            ->registra((int)$_SESSION['utente_id'], (string)$azione, (array)$dettagli_array, (string)($_SERVER['REMOTE_ADDR'] ?? 'Sconosciuto'));
    }
}

// =======================================================================
// PROTEZIONE CSRF: facciate di App\Auth\Csrf (un token per sessione, valido per tutti i form della sessione)
// =======================================================================
if (!function_exists('csrf_token')) {
    // Restituisce il token corrente, generandolo se non esiste ancora
    function csrf_token() {
        return (new \App\Auth\Csrf(new \App\Auth\SessioneNativa()))->token();
    }
}

// Stampa il campo hidden pronto da inserire in un <form>
if (!function_exists('csrf_field')) {
    function csrf_field() {
        echo (new \App\Auth\Csrf(new \App\Auth\SessioneNativa()))->campo();
    }
}

// Verifica il token ricevuto da un form POST (o GET per i link "azione" dell'admin).
// In caso di esito negativo interrompe l'esecuzione con errore 403.
if (!function_exists('csrf_verify')) {
    function csrf_verify($token_ricevuto) {
        if (!(new \App\Auth\Csrf(new \App\Auth\SessioneNativa()))->valido($token_ricevuto)) \App\Auth\RispostaHttp::csrfNonValido();
        return true;
    }
}

if (!function_exists('env_valore')) {
    // Valore del file .env (null se assente o vuoto): facciata di App\Sistema\FileEnv (il file si legge una sola volta)
    function env_valore(string $chiave): ?string {
        static $env = null;
        $env ??= new \App\Sistema\FileEnv(defined('FILE_ENV') ? FILE_ENV : RADICE_SITO . '/.env');
        return $env->valore($chiave);
    }
}

// ── Cifratura dei backup (facciate di App\Sistema\Backup\Cifratura) ─────────────
if (!function_exists('cifra_zip_aes')) {
    function cifra_zip_aes(string $src, string $dest, string $password, ?string &$errore = null): bool {
        return (new \App\Sistema\Backup\Cifratura())->zipAes($src, $dest, $password, $errore);
    }
}

if (!function_exists('cifra_openssl_aes')) {
    function cifra_openssl_aes(string $src, string $dest, string $password, ?string &$errore = null): bool {
        return (new \App\Sistema\Backup\Cifratura())->opensslAes($src, $dest, $password, $errore);
    }
}

if (!function_exists('email_amministratori')) {
    // Email degli amministratori globali (ruolo principale o secondario 1): App\Auth\ServizioUtenti
    function email_amministratori($conn): array {
        return \App\Core\App::per($conn)->get(\App\Auth\ServizioUtenti::class)->emailAmministratori();
    }
}

if (!function_exists('controllo_sito')) {
    // Controllo automatico del portale (cron notturno e pulsante in Sistema): App\Sistema\ControlloSito
    // Ritorna [['ok' => true|false|null, 'voce' => testo], ...]  (null = solo informazione)
    function controllo_sito($conn): array {
        return \App\Core\App::per($conn)->get(\App\Sistema\ControlloSito::class)->esiti();
    }
}

if (!function_exists('esegui_controllo_sito')) {
    // Esegue il controllo, lo salva in cache/controllo_sito.json e avvisa gli amministratori per email
    function esegui_controllo_sito($conn, bool $avvisa = true): array {
        return \App\Core\App::per($conn)->get(\App\Sistema\ControlloSito::class)->esegui($avvisa);
    }
}

if (!function_exists('stato_backup')) {
    // Esito dell'ultimo backup (scritto da admin/cron_backup.php), [] se non è mai stato eseguito
    function stato_backup(): array {
        return (new \App\Sistema\Backup\StatoBackup(new \App\Core\Sito(RADICE_SITO)))->leggi();
    }
}

if (!function_exists('invia_report_email_settimanale')) {
    // Riepilogo settimanale delle email di sistema agli amministratori ($forza = invio immediato). Ritorna true o il motivo.
    function invia_report_email_settimanale($conn, bool $forza = false) {
        return \App\Core\App::per($conn)->get(\App\Sistema\ReportEmailSettimanale::class)->invia($forza);
    }
}

if (!function_exists('consenti_esecuzione_cron')) {
    // Gli script cron partono SOLO: da riga di comando, con la chiave CRON_KEY del .env, oppure da un utente loggato
    // con uno dei ruoli ammessi (pulsanti del pannello admin). Altrimenti 403 (regole in App\Sistema\AccessoCron).
    function consenti_esecuzione_cron(array $ruoli_ammessi = [1]): void {
        if (PHP_SAPI === 'cli') return;
        if (\App\Sistema\AccessoCron::chiaveValida((string)(env_valore('CRON_KEY') ?? ''), (string)($_GET['key'] ?? ''))) return;
        if (session_status() === PHP_SESSION_NONE) @session_start();
        if (!empty($_SESSION['utente_id'])) {
            $ruoli = array_merge([(int)($_SESSION['utente_ruolo_id'] ?? 0)],
                                 array_map('intval', explode(',', (string)($_SESSION['utente_ruoli_secondari'] ?? ''))));
            if (\App\Sistema\AccessoCron::ruoloAmmesso($ruoli, $ruoli_ammessi)) return;
        }
        error_log('[cron] accesso negato a ' . basename($_SERVER['SCRIPT_NAME'] ?? '?') . ' da ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
        http_response_code(403);
        exit("Accesso negato.\n");
    }
}

if (!function_exists('secure_upload')) {
    // Facciata di App\Infrastructure\Storage\Upload::salva (estensione e tipo MIME ammessi, nome casuale)
    function secure_upload(array $file, string $upload_dir, array $allowed_exts, array $allowed_mimes): ?string {
        return (new \App\Infrastructure\Storage\Upload())->salva($file, $upload_dir, $allowed_exts, $allowed_mimes);
    }
}

// =======================================================================
// CACHE CONFIGURAZIONE PORTALE (Fase 3 - Ottimizzazione Prestazioni)
// header.php e footer.php interrogavano configurazione_portale ad OGNI
// caricamento pagina. Qui salviamo il risultato in un file JSON locale
// (cache/configurazione_portale.json), valido finché un admin non salva
// nuove impostazioni da testata.php (invalidazione esplicita) o comunque
// non oltre 5 minuti (rete di sicurezza, in caso di modifiche dirette a DB).
// Se la cartella cache/ non è scrivibile, la funzione ricade in modo
// trasparente sulla query diretta: nessun malfunzionamento, solo niente cache.
// =======================================================================
if (!function_exists('get_configurazione_portale')) {
    // Configurazione del portale con cache su file (App\Portale\CacheConfigurazione)
    function get_configurazione_portale($conn) {
        return \App\Core\App::per($conn)->get(\App\Portale\CacheConfigurazione::class)->leggi();
    }
}
