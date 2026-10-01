<?php
// inc/sistema.php - Registro delle operazioni, CSRF, file .env, backup cifrati, controllo del sito, riepiloghi, cache della configurazione.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

// =======================================================================
// AUDIT LOG (Registrazione delle attività degli amministratori)
// =======================================================================
if (!function_exists('registra_log_audit')) {
    function registra_log_audit($conn, $azione, $dettagli_array = []) {
        if (empty($_SESSION['utente_id'])) return false;
        
        $u_id = (int)$_SESSION['utente_id'];
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Sconosciuto';
        $dettagli_json = !empty($dettagli_array) ? json_encode($dettagli_array, JSON_UNESCAPED_UNICODE) : null;
        $stmt = $conn->prepare("INSERT INTO log_attivita (utente_id, azione, dettagli_json, indirizzo_ip) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isss", $u_id, $azione, $dettagli_json, $ip);
        return $stmt->execute();
    }
}

// =======================================================================
// PROTEZIONE CSRF (Fase 1 - Messa in Sicurezza Silenziosa)
// Un token per sessione, valido per tutti i form della sessione corrente.
// =======================================================================

// Restituisce il token corrente, generandolo se non esiste ancora
if (!function_exists('csrf_token')) {
    function csrf_token() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

// Stampa il campo hidden pronto da inserire in un <form>
if (!function_exists('csrf_field')) {
    function csrf_field() {
        echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
    }
}

// Verifica il token ricevuto da un form POST (o GET per i link "azione" dell'admin).
// In caso di esito negativo interrompe l'esecuzione con errore 403.
if (!function_exists('csrf_verify')) {
    function csrf_verify($token_ricevuto) {
        if (empty($_SESSION['csrf_token']) || empty($token_ricevuto) || !hash_equals($_SESSION['csrf_token'], $token_ricevuto)) {
            http_response_code(403);
            die("Richiesta non valida o sessione scaduta. Torna indietro, ricarica la pagina e riprova.");
        }
        return true;
    }
}

if (!function_exists('env_valore')) {
    // Valore del file .env (config.php lo legge solo per il database e poi lo scarta): null se assente o vuoto.
    // Lettura "grezza": "no"/"yes"/"true" restano testo e le password possono contenere ! ; = senza rompere il file.
    function env_valore(string $chiave): ?string {
        static $env = null;
        if ($env === null) {
            // Righe con # scartate: nei file ini il commento è ; (vedi config.php)
            $env = @parse_ini_string(preg_replace('/^\s*#.*$/m', '', (string)@file_get_contents(defined('FILE_ENV') ? FILE_ENV : RADICE_SITO . '/.env')), false, INI_SCANNER_RAW) ?: [];
        }
        $v = trim((string)($env[$chiave] ?? ''));
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) $v = substr($v, 1, -1); // valore tra virgolette
        return $v === '' ? null : $v;
    }
}

// ── Cifratura dei backup ──────────────────────────────────────────────────────
if (!function_exists('cifra_zip_aes')) {
    // ZIP con il file cifrato AES-256 (si apre con 7-Zip/WinRAR). Ritorna false con il motivo in $errore:
    // alcuni PHP hanno ZipArchive::EM_AES_256 ma una libreria libzip compilata senza cifratura.
    function cifra_zip_aes(string $src, string $dest, string $password, ?string &$errore = null): bool {
        $errore = '';
        if (!extension_loaded('zip') || !defined('ZipArchive::EM_AES_256') || !method_exists('ZipArchive', 'setEncryptionName')) { $errore = 'estensione zip senza cifratura AES'; return false; }
        $z = new ZipArchive();
        if (($r = $z->open($dest, ZipArchive::CREATE | ZipArchive::OVERWRITE)) !== true) { $errore = "apertura non riuscita (codice $r)"; return false; }
        $nome = basename($src);
        if (!$z->addFile($src, $nome)) { $errore = 'aggiunta del file: ' . $z->getStatusString(); @$z->close(); return false; }
        if (!$z->setEncryptionName($nome, ZipArchive::EM_AES_256, $password)) { $errore = 'la libreria zip del server non supporta la cifratura (' . $z->getStatusString() . ')'; @$z->close(); return false; }
        if (!$z->close()) { $errore = 'chiusura: ' . $z->getStatusString(); return false; }
        return is_file($dest) && filesize($dest) > 0;
    }
}

if (!function_exists('cifra_openssl_aes')) {
    // File cifrato AES-256-CBC nello stesso formato di "openssl enc -aes-256-cbc -pbkdf2 -iter 100000 -md sha256":
    // "Salted__" + sale di 8 byte + dati; chiave e IV dalla password con PBKDF2-SHA256. Si apre con
    // openssl enc -d -aes-256-cbc -pbkdf2 -iter 100000 -md sha256 -in FILE.enc -out FILE (openssl c'è in Git Bash).
    function cifra_openssl_aes(string $src, string $dest, string $password, ?string &$errore = null): bool {
        $errore = '';
        if (!function_exists('openssl_encrypt')) { $errore = 'estensione openssl non attiva'; return false; }
        $dati = @file_get_contents($src);
        if ($dati === false) { $errore = 'lettura del file non riuscita'; return false; }
        $sale = random_bytes(8);
        $km = hash_pbkdf2('sha256', $password, $sale, 100000, 48, true);
        $cif = openssl_encrypt($dati, 'aes-256-cbc', substr($km, 0, 32), OPENSSL_RAW_DATA, substr($km, 32, 16));
        if ($cif === false) { $errore = 'cifratura non riuscita: ' . (openssl_error_string() ?: 'errore sconosciuto'); return false; }
        if (@file_put_contents($dest, 'Salted__' . $sale . $cif) === false) { $errore = 'scrittura del file non riuscita'; return false; }
        return true;
    }
}

if (!function_exists('email_amministratori')) {
    // Email degli amministratori globali (ruolo principale o secondario 1)
    function email_amministratori($conn): array {
        $out = [];
        $r = $conn->query("SELECT DISTINCT email FROM utenti WHERE (ruolo_id = 1 OR FIND_IN_SET('1', ruoli_secondari) > 0) AND email IS NOT NULL AND email <> ''");
        while ($r && $row = $r->fetch_assoc()) if (filter_var($row['email'], FILTER_VALIDATE_EMAIL)) $out[] = strtolower($row['email']);
        return array_values(array_unique($out));
    }
}

if (!function_exists('controllo_sito')) {
    // Controllo automatico del portale (cron notturno e pulsante in Sistema): database, cartelle scrivibili,
    // spazio su disco, età dell'ultimo backup, email non partite e, via HTTP, pagine pubbliche e file riservati.
    // Ritorna [['ok' => true|false|null, 'voce' => testo], ...]  (null = solo informazione)
    function controllo_sito($conn): array {
        $esiti = [];
        $voce = function ($ok, string $t) use (&$esiti) { $esiti[] = ['ok' => $ok, 'voce' => $t]; };

        $voce((bool)@$conn->query("SELECT 1"), "Database raggiungibile");
        foreach (['cache', 'uploads', 'backups'] as $cart) {
            $p = RADICE_SITO . '/' . $cart;
            if ($cart === 'backups' && !is_dir($p)) continue;
            $voce(is_dir($p) && is_writable($p), "Cartella $cart/ scrivibile");
        }
        $libero = @disk_free_space(RADICE_SITO);
        if ($libero !== false) {
            $gb = $libero / 1073741824;
            $voce($gb >= 1, "Spazio libero sul disco del portale: " . number_format($gb, 1, ',', '.') . " GB" . ($gb < 1 ? " (meno di 1 GB)" : ''));
        }
        $bk = stato_backup();
        if (!$bk) $voce(null, "Backup: nessun backup registrato");
        else {
            $ore = (time() - strtotime($bk['data'])) / 3600;
            $voce(!empty($bk['ok']) && $ore <= 36, "Ultimo backup: " . date('d/m/Y H:i', strtotime($bk['data'])) . (!empty($bk['ok']) ? '' : ' (con problemi)') . ($ore > 36 ? ' – più di 36 ore fa' : ''));
        }
        $r_em = @$conn->query("SELECT SUM(esito = 0) AS ko, COUNT(*) AS tot FROM log_email WHERE created_at >= NOW() - INTERVAL 1 DAY");
        if ($r_em && ($x = $r_em->fetch_assoc()) && (int)$x['tot'] > 0) {
            $ko = (int)$x['ko'];
            $voce($ko <= max(3, (int)$x['tot'] / 10), "Email delle ultime 24 ore: " . (int)$x['tot'] . " inviate, $ko non partite");
        }

        // Controlli HTTP: in locale il server di sviluppo serve una richiesta alla volta, quindi si saltano
        if (defined('AMBIENTE_LOCALE') && AMBIENTE_LOCALE) { $voce(null, "Controlli delle pagine saltati nell'ambiente locale"); return $esiti; }
        $base = rtrim(url_base_sito(), '/');
        $prove = [
            ['index.php', [200], 'Home'], ['privacy.php', [200], 'Privacy'], ['verifica_attestato.php', [200], 'Verifica attestato'],
            ['admin/', [301, 302, 303, 401, 403], 'Pannello senza accesso (deve rimandare al login)'],
            ['.env', [403, 404], 'File .env bloccato'], ['config.php', [403, 404], 'config.php bloccato'],
            ['cache/', [403, 404], 'Cartella cache bloccata'], ['uploads/convenzioni/', [403, 404], 'Convenzioni firmate bloccate'],
            ['strumenti/verifica_sito.sh', [403, 404], 'Strumenti bloccati'], ['inc/base.php', [403, 404], 'Codice in inc/ bloccato'], ['modelli_documenti/convenzione_precompilabile.docx', [403, 404], 'Modelli interni bloccati'], ['cron_background.php', [403], 'Cron senza chiave rifiutato'],
        ];
        foreach ($prove as [$perc, $attesi, $nome]) {
            $codice = 0;
            if (function_exists('curl_init')) {
                $ch = curl_init($base . '/' . $perc);
                curl_setopt_array($ch, [CURLOPT_NOBODY => false, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15, CURLOPT_USERAGENT => 'DidatticaDiBEST-controllo']);
                $corpo = curl_exec($ch);
                $codice = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                // Pagina che risponde 200 ma con un errore PHP a schermo
                if ($codice === 200 && is_string($corpo) && preg_match('/<b>(Fatal error|Parse error)<\/b>|Uncaught (Error|Exception|TypeError)/', $corpo)) $codice = -1;
            }
            $voce(in_array($codice, $attesi, true), "$nome: " . ($codice === -1 ? 'errore PHP nella pagina' : ($codice ?: 'nessuna risposta')));
        }
        return $esiti;
    }
}

if (!function_exists('esegui_controllo_sito')) {
    // Esegue il controllo, lo salva in cache/controllo_sito.json e avvisa gli amministratori per email se qualcosa
    // non va (al massimo una volta al giorno per gli stessi problemi) e quando torna tutto a posto.
    function esegui_controllo_sito($conn, bool $avvisa = true): array {
        $file = RADICE_SITO . '/cache/controllo_sito.json';
        $prec = is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
        $esiti = controllo_sito($conn);
        $problemi = array_values(array_map(fn($e) => $e['voce'], array_filter($esiti, fn($e) => $e['ok'] === false)));
        $firma = md5(implode('|', $problemi));
        $stato = ['data' => date('Y-m-d H:i:s'), 'esiti' => $esiti, 'problemi' => count($problemi), 'firma' => $firma,
                  'ultimo_avviso' => $prec['ultimo_avviso'] ?? null, 'firma_avviso' => $prec['firma_avviso'] ?? ''];
        if ($avvisa) {
            $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
            $link = $h(url_base_sito() . '/admin/sistema.php#controllo');
            if ($problemi && ($firma !== $stato['firma_avviso'] || strtotime((string)$stato['ultimo_avviso']) < time() - 86400)) {
                $corpo = "<p>Il controllo automatico del portale ha trovato <strong>" . count($problemi) . " problemi</strong>:</p><ul><li>" . implode('</li><li>', array_map($h, $problemi)) . "</li></ul>"
                       . "<p>Dettagli e nuovo controllo: <a href='$link'>Sistema → Controllo del sito</a>.</p>";
                foreach (email_amministratori($conn) as $em) inviaNotificaEmail($em, "Portale Didattica DiBEST: " . count($problemi) . " problemi rilevati", $corpo, $conn);
                $stato['ultimo_avviso'] = date('Y-m-d H:i:s'); $stato['firma_avviso'] = $firma;
            } elseif (!$problemi && ($prec['problemi'] ?? 0) > 0) {
                foreach (email_amministratori($conn) as $em) inviaNotificaEmail($em, "Portale Didattica DiBEST: tutto di nuovo a posto", "<p>Il controllo automatico non trova più problemi. Dettagli: <a href='$link'>Sistema → Controllo del sito</a>.</p>", $conn);
                $stato['firma_avviso'] = '';
            }
        }
        @file_put_contents($file, json_encode($stato, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $stato;
    }
}

if (!function_exists('stato_backup')) {
    // Esito dell'ultimo backup (scritto da admin/cron_backup.php), [] se non è mai stato eseguito
    function stato_backup(): array {
        $f = RADICE_SITO . '/cache/backup_stato.json';
        return is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    }
}

if (!function_exists('invia_report_email_settimanale')) {
    // Riepilogo delle email di sistema degli ultimi 7 giorni agli amministratori: inviate, fallite (raggruppate per
    // errore), invii ripiegati su mail() e stato del backup. Una volta a settimana (primo cron del lunedì o dopo);
    // $forza = invio immediato dal pannello Sistema. Ritorna true o il motivo per cui non è partito.
    function invia_report_email_settimanale($conn, bool $forza = false) {
        $cartella = RADICE_SITO . '/cache';
        $marker = $cartella . '/report_email_' . date('o-W') . '.ok';
        if (!$forza && is_file($marker)) return "già inviato questa settimana";
        $dest = email_amministratori($conn);
        if (!$dest) return "nessun amministratore con email";
        $tot = ['ok' => 0, 'ko' => 0, 'mail' => 0];
        $r = @$conn->query("SELECT SUM(esito = 1) AS ok, SUM(esito = 0) AS ko, SUM(esito = 1 AND canale = 'mail()') AS via_mail FROM log_email WHERE created_at >= NOW() - INTERVAL 7 DAY");
        if ($r && $row = $r->fetch_assoc()) $tot = ['ok' => (int)$row['ok'], 'ko' => (int)$row['ko'], 'mail' => (int)$row['via_mail']];
        $errori = [];
        $r = @$conn->query("SELECT errore, COUNT(*) AS n, MAX(created_at) AS ultimo, GROUP_CONCAT(DISTINCT destinatario ORDER BY destinatario SEPARATOR ', ') AS chi
                            FROM log_email WHERE esito = 0 AND created_at >= NOW() - INTERVAL 7 DAY GROUP BY errore ORDER BY n DESC LIMIT 15");
        while ($r && $row = $r->fetch_assoc()) $errori[] = $row;
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $corpo = "<p>Riepilogo delle email inviate dal portale negli ultimi 7 giorni (fino al " . date('d/m/Y H:i') . ").</p>"
               . "<table style='border-collapse:collapse;margin:10px 0;'>"
               . "<tr><td style='padding:4px 14px 4px 0;'>✅ Accettate dal server</td><td><strong>{$tot['ok']}</strong></td></tr>"
               . "<tr><td style='padding:4px 14px 4px 0;'>❌ Fallite</td><td><strong style='color:" . ($tot['ko'] ? '#b91c1c' : 'inherit') . ";'>{$tot['ko']}</strong></td></tr>"
               . ($tot['mail'] ? "<tr><td style='padding:4px 14px 4px 0;'>⚠️ Inviate con il ripiego mail() (SMTP non raggiungibile)</td><td><strong>{$tot['mail']}</strong></td></tr>" : '')
               . "</table>";
        if ($errori) {
            $corpo .= "<p><strong>Errori della settimana</strong> (raggruppati):</p><ul>";
            foreach ($errori as $e) {
                $chi = mb_strimwidth((string)$e['chi'], 0, 200, '…');
                $corpo .= "<li><strong>{$e['n']}×</strong> " . $h($e['errore'] ?: 'errore sconosciuto') . "<br><small style='color:#64748b;'>ultimo il " . date('d/m H:i', strtotime($e['ultimo'])) . " · " . $h($chi) . "</small></li>";
            }
            $corpo .= "</ul><p style='font-size:13px;color:#475569;'>\"Destinatario rifiutato\" di solito indica un indirizzo sbagliato; errori di autenticazione o di connessione riguardano la configurazione SMTP.</p>";
        } else {
            $corpo .= "<p>Nessun invio fallito. 👍</p>";
        }
        $b = stato_backup();
        if ($b) {
            $corpo .= "<p><strong>Backup</strong>: ultimo il " . date('d/m/Y H:i', strtotime($b['data'])) . " — " . (!empty($b['ok']) ? "✅ completato" : "⚠️ con problemi") . ". "
                    . "NAS: " . $h($b['nas']['messaggio'] ?? '-') . ". "
                    . (!empty($b['ultima_email']) ? "Ultima copia via email: " . date('d/m/Y', strtotime($b['ultima_email'])) . "." : "Nessuna copia via email.") . "</p>";
            if (strtotime($b['data']) < time() - 2 * 86400) $corpo .= "<p style='color:#b91c1c;'><strong>Attenzione: l'ultimo backup ha più di 2 giorni.</strong> Controlla che il cron di admin/cron_backup.php sia attivo.</p>";
        } else {
            $corpo .= "<p style='color:#b91c1c;'><strong>Backup: nessuna esecuzione registrata.</strong> Controlla che il cron di admin/cron_backup.php sia attivo.</p>";
        }
        $corpo .= "<p><a href='" . $h(url_base_sito() . '/admin/sistema.php#log-email') . "'>Apri il registro completo nel pannello</a></p>";
        $oggetto = ($tot['ko'] || ($b && empty($b['ok'])) || !$b ? "⚠️ " : "✅ ") . "Riepilogo settimanale email Didattica DiBEST: {$tot['ok']} inviate, {$tot['ko']} fallite";
        $inviati = 0;
        foreach ($dest as $em) if (inviaNotificaEmail($em, $oggetto, $corpo, $conn)) $inviati++;
        if (!$inviati) return "invio non riuscito: " . ($GLOBALS['ultimo_errore_email'] ?? 'errore sconosciuto');
        if (!is_dir($cartella)) @mkdir($cartella, 0755, true);
        @file_put_contents($marker, date('c'));
        foreach (glob($cartella . '/report_email_*.ok') ?: [] as $f) if ($f !== $marker && filemtime($f) < time() - 60 * 86400) @unlink($f);
        return true;
    }
}

if (!function_exists('consenti_esecuzione_cron')) {
    // Gli script cron partono SOLO: da riga di comando (crontab con "php script.php"), con la chiave
    // CRON_KEY del file .env (crontab con wget/curl: script.php?key=...), oppure da un utente loggato
    // con uno dei ruoli ammessi (pulsanti del pannello admin). In tutti gli altri casi: 403.
    function consenti_esecuzione_cron(array $ruoli_ammessi = [1]): void {
        if (PHP_SAPI === 'cli') return;
        $chiave = (string)(env_valore('CRON_KEY') ?? '');
        if (strlen($chiave) >= 16 && hash_equals($chiave, (string)($_GET['key'] ?? ''))) return;
        if (session_status() === PHP_SESSION_NONE) @session_start();
        if (!empty($_SESSION['utente_id'])) {
            $ruoli = array_merge([(int)($_SESSION['utente_ruolo_id'] ?? 0)],
                                 array_map('intval', explode(',', (string)($_SESSION['utente_ruoli_secondari'] ?? ''))));
            if (array_intersect($ruoli, $ruoli_ammessi)) return;
        }
        error_log('[cron] accesso negato a ' . basename($_SERVER['SCRIPT_NAME'] ?? '?') . ' da ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
        http_response_code(403);
        exit("Accesso negato.\n");
    }
}

if (!function_exists('secure_upload')) {
    function secure_upload(array $file, string $upload_dir, array $allowed_exts, array $allowed_mimes): ?string {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_exts, true)) return null;
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!in_array($mime, $allowed_mimes, true)) return null;
        if (!file_exists($upload_dir)) mkdir($upload_dir, 0755, true);
        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        return move_uploaded_file($file['tmp_name'], $upload_dir . $filename) ? $filename : null;
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
    function get_configurazione_portale($conn) {
        // Memoizzazione per-request: evita anche solo di riaprire il file
        // se header.php e footer.php vengono eseguiti nella stessa request.
        static $cfg_memo = null;
        if ($cfg_memo !== null) {
            return $cfg_memo;
        }

        $cache_file = RADICE_SITO . '/cache/configurazione_portale.json';
        $ttl_secondi = 300;

        if (is_file($cache_file) && (time() - filemtime($cache_file)) < $ttl_secondi) {
            $json = @file_get_contents($cache_file);
            $decoded = ($json !== false) ? json_decode($json, true) : null;
            if (is_array($decoded)) {
                $cfg_memo = $decoded;
                return $cfg_memo;
            }
        }

        // Cache assente, scaduta o corrotta: rileggi dal database
        $cfg = [];
        if ($conn instanceof mysqli) {
            $res = @$conn->query("SELECT * FROM configurazione_portale WHERE id = 1");
            if ($res && $res->num_rows > 0) {
                $cfg = $res->fetch_assoc();
            }
        }

        // Riscrittura cache best-effort: se cache/ manca o non è scrivibile,
        // l'app continua a funzionare interrogando il DB ad ogni richiesta.
        $cache_dir = dirname($cache_file);
        if (!is_dir($cache_dir)) { @mkdir($cache_dir, 0755, true); }
        if (is_dir($cache_dir) && is_writable($cache_dir)) {
            @file_put_contents($cache_file, json_encode($cfg, JSON_UNESCAPED_UNICODE), LOCK_EX);
        }

        $cfg_memo = $cfg;
        return $cfg_memo;
    }
}
