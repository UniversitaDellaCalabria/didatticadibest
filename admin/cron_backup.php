<?php
// admin/cron_backup.php - Backup completo del portale
//  1. database esportato in backups/backup_DB_<data>.sql.gz (compresso, scritto a blocchi: niente limiti di memoria)
//  2. file del sito in backups/backup_SITO_<data>.zip (escluse le cartelle backups, cache, .git, .claude)
//  3. copia di entrambi nella cartella del NAS (BACKUP_NAS_PATH nel file .env), con pulizia dei più vecchi
//  4. copia del SOLO database, cifrata (ZIP AES-256, password BACKUP_PASSWORD), via email a BACKUP_EMAIL
//     con la frequenza BACKUP_EMAIL_FREQUENZA (settimanale = il lunedì, giornaliera, no)
//  5. esito in cache/backup_stato.json (letto dal pannello Sistema e dal riepilogo settimanale);
//     se qualcosa non va, avviso immediato per email agli amministratori.
// Avvio: crontab (php admin/cron_backup.php), URL con ?key=CRON_KEY, oppure dal pannello (admin). ?email=1 forza l'invio email.

set_time_limit(0);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
consenti_esecuzione_cron([1]);

$cli = PHP_SAPI === 'cli';
$root = realpath(__DIR__ . '/..');
$backup_dir = $root . '/backups/';
if (!is_dir($backup_dir)) { @mkdir($backup_dir, 0750, true); @file_put_contents($backup_dir . '.htaccess', "Require all denied\n"); }

$data = date('Y-m-d_H-i-s');
$file_db  = $backup_dir . "backup_DB_{$data}.sql.gz";
$file_zip = $backup_dir . "backup_SITO_{$data}.zip";
$righe = [];   // [esito (true/false/null = informazione), messaggio]
$problemi = [];
$nota = function (?bool $ok, string $msg) use (&$righe, &$problemi) { $righe[] = [$ok, $msg]; if ($ok === false) $problemi[] = $msg; };
$mb = fn($byte) => number_format($byte / 1048576, 1, ',', '.') . ' MB';

// ---------------------------------------------------------------------
// 1. DATABASE
// ---------------------------------------------------------------------
$db_ok = false;
$gz = @gzopen($file_db, 'wb6');
if (!$gz) {
    $nota(false, "Database: impossibile creare il file nella cartella backups (permessi?)");
} else {
    gzwrite($gz, "-- Backup del database Eventi DiBEST\n-- Generato il " . date('Y-m-d H:i:s') . "\n\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
    $tabelle = [];
    $r = $conn->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
    while ($r && $t = $r->fetch_row()) $tabelle[] = $t[0];
    $n_righe = 0;
    foreach ($tabelle as $tab) {
        $crea = $conn->query("SHOW CREATE TABLE `$tab`")->fetch_row()[1] ?? '';
        gzwrite($gz, "DROP TABLE IF EXISTS `$tab`;\n$crea;\n\n");
        // Lettura a flusso: le tabelle grandi non vengono caricate tutte in memoria
        $res = $conn->query("SELECT * FROM `$tab`", MYSQLI_USE_RESULT);
        if (!$res) continue;
        while ($row = $res->fetch_row()) {
            $valori = array_map(fn($v) => $v === null ? 'NULL' : "'" . $conn->real_escape_string((string)$v) . "'", $row);
            gzwrite($gz, "INSERT INTO `$tab` VALUES(" . implode(',', $valori) . ");\n");
            $n_righe++;
        }
        $res->free();
        gzwrite($gz, "\n");
    }
    gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
    gzclose($gz);
    $db_ok = is_file($file_db) && filesize($file_db) > 0;
    $nota($db_ok, $db_ok ? "Database esportato: " . count($tabelle) . " tabelle, $n_righe righe (" . $mb(filesize($file_db)) . ")" : "Database: esportazione non riuscita");
}

// ---------------------------------------------------------------------
// 2. FILE DEL SITO
// ---------------------------------------------------------------------
$zip_ok = false;
if (!extension_loaded('zip')) {
    $nota(false, "File del sito: estensione PHP zip non attiva sul server");
} else {
    $zip = new ZipArchive();
    if ($zip->open($file_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        $nota(false, "File del sito: impossibile creare lo ZIP (permessi?)");
    } else {
        $esclusi = ['backups/', 'cache/', '.git/', '.claude/'];
        $n_file = 0;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($it as $f) {
            if ($f->isDir() || !$f->isReadable()) continue;
            $rel = str_replace('\\', '/', substr($f->getRealPath(), strlen($root) + 1));
            foreach ($esclusi as $e) { if (strpos($rel, $e) === 0) continue 2; }
            $zip->addFile($f->getRealPath(), $rel);
            $n_file++;
        }
        $zip_ok = $zip->close();
        $nota($zip_ok, $zip_ok ? "File del sito compressi: $n_file file (" . $mb(filesize($file_zip)) . ")" : "File del sito: chiusura dello ZIP non riuscita");
    }
}

// Pulizia della cartella locale: ultimi 7 giorni (la copia lunga sta sul NAS)
$tolti = 0;
foreach (glob($backup_dir . 'backup_*') ?: [] as $f) {
    if (is_file($f) && time() - filemtime($f) >= 7 * 86400) { @unlink($f); $tolti++; }
}
if ($tolti) $nota(null, "Cartella locale: eliminati $tolti backup più vecchi di 7 giorni");

// ---------------------------------------------------------------------
// 3. COPIA SUL NAS
// ---------------------------------------------------------------------
$nas_base = env_valore('BACKUP_NAS_PATH');
$stato_nas = ['configurato' => $nas_base !== null, 'ok' => null, 'messaggio' => '', 'cartella' => ''];
if ($nas_base === null) {
    $stato_nas['messaggio'] = "NAS non configurato (BACKUP_NAS_PATH nel file .env)";
    $nota(false, $stato_nas['messaggio']);
} else {
    $nas_dir = rtrim($nas_base, '/\\') . '/eventi_dibest';
    $stato_nas['cartella'] = $nas_dir;
    if (!is_dir($nas_base)) {
        $stato_nas['ok'] = false; $stato_nas['messaggio'] = "Cartella del NAS non raggiungibile: $nas_base (NAS spento o non montato?)";
    } elseif (!is_dir($nas_dir) && !@mkdir($nas_dir, 0750, true)) {
        $stato_nas['ok'] = false; $stato_nas['messaggio'] = "Impossibile creare la cartella $nas_dir sul NAS (permessi?)";
    } elseif (!is_writable($nas_dir)) {
        $stato_nas['ok'] = false; $stato_nas['messaggio'] = "La cartella $nas_dir sul NAS non è scrivibile dal server web";
    } else {
        $copiati = []; $errori_nas = [];
        foreach (array_filter([$db_ok ? $file_db : null, $zip_ok ? $file_zip : null]) as $f) {
            $dest = $nas_dir . '/' . basename($f);
            if (@copy($f, $dest) && filesize($dest) === filesize($f)) $copiati[] = basename($f);
            else $errori_nas[] = basename($f);
        }
        $giorni_nas = max(1, (int)(env_valore('BACKUP_NAS_GIORNI') ?? 30));
        $tolti_nas = 0;
        foreach (glob($nas_dir . '/backup_*') ?: [] as $f) {
            if (is_file($f) && time() - filemtime($f) >= $giorni_nas * 86400) { @unlink($f); $tolti_nas++; }
        }
        $libero = @disk_free_space($nas_dir);
        $stato_nas['ok'] = !$errori_nas && $copiati;
        $stato_nas['messaggio'] = $errori_nas ? "Copia sul NAS non riuscita per: " . implode(', ', $errori_nas)
            : "Copiati sul NAS " . count($copiati) . " file in $nas_dir" . ($tolti_nas ? ", eliminati $tolti_nas più vecchi di $giorni_nas giorni" : '')
              . ($libero !== false ? " (spazio libero: " . $mb($libero) . ")" : '');
        if ($libero !== false && $libero < 1073741824) $nota(false, "Spazio quasi esaurito sul NAS: " . $mb($libero) . " liberi");
    }
    $nota($stato_nas['ok'], $stato_nas['messaggio']);
}

// ---------------------------------------------------------------------
// 4. COPIA CIFRATA DEL DATABASE VIA EMAIL
// ---------------------------------------------------------------------
$freq = strtolower(env_valore('BACKUP_EMAIL_FREQUENZA') ?? 'settimanale');
$dest_email = normalizza_lista_email(env_valore('BACKUP_EMAIL') ?? '', 5);
$forza_email = !$cli && (string)($_GET['email'] ?? '') === '1';
$dovuta = $forza_email || $freq === 'giornaliera' || ($freq === 'settimanale' && date('N') === '1');
$stato_email = ['configurato' => (bool)$dest_email, 'ok' => null, 'messaggio' => '', 'frequenza' => $freq];
if (!$dest_email || $freq === 'no') {
    $stato_email['messaggio'] = !$dest_email ? "Invio via email non configurato (BACKUP_EMAIL nel file .env)" : "Invio via email disattivato (BACKUP_EMAIL_FREQUENZA=no)";
    $nota(null, $stato_email['messaggio']);
} elseif (!$dovuta) {
    $stato_email['messaggio'] = "Invio via email: oggi non previsto (frequenza $freq, parte il lunedì)";
    $nota(null, $stato_email['messaggio']);
} elseif (!$db_ok) {
    $stato_email['ok'] = false; $stato_email['messaggio'] = "Invio via email saltato: il database non è stato esportato";
    $nota(false, $stato_email['messaggio']);
} else {
    $pwd = env_valore('BACKUP_PASSWORD');
    $aes = extension_loaded('zip') && method_exists('ZipArchive', 'setEncryptionName') && defined('ZipArchive::EM_AES_256');
    if ($pwd === null || strlen($pwd) < 12) {
        // Il database contiene dati personali: mai in chiaro per email
        $stato_email['ok'] = false; $stato_email['messaggio'] = "Invio via email bloccato: manca BACKUP_PASSWORD (almeno 12 caratteri) nel file .env. Il database non viene mai inviato in chiaro.";
    } elseif (!$aes) {
        $stato_email['ok'] = false; $stato_email['messaggio'] = "Invio via email bloccato: il PHP del server non supporta gli ZIP cifrati AES-256";
    } else {
        $file_cif = $backup_dir . "backup_DB_{$data}_cifrato.zip";
        $zc = new ZipArchive();
        $nome_int = basename($file_db);
        $cif_ok = $zc->open($file_cif, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true
               && $zc->addFile($file_db, $nome_int) && $zc->setEncryptionName($nome_int, ZipArchive::EM_AES_256, $pwd) && $zc->close();
        $dim = $cif_ok ? filesize($file_cif) : 0;
        $max = 15 * 1048576;
        if (!$cif_ok) {
            $stato_email['ok'] = false; $stato_email['messaggio'] = "Invio via email: creazione dello ZIP cifrato non riuscita";
        } else {
            $sopra = $dim > $max;
            $corpo = "<p>Backup del database del portale Eventi DiBEST del <strong>" . date('d/m/Y H:i') . "</strong>.</p>"
                   . ($sopra
                       ? "<p><strong>Il file cifrato pesa " . $mb($dim) . ", troppo per un'email</strong>: non è allegato. La copia completa è sul NAS" . ($stato_nas['ok'] ? " ({$stato_nas['cartella']})" : '') . ".</p>"
                       : "<p>In allegato il database compresso, dentro uno <strong>ZIP cifrato AES-256</strong> (" . $mb($dim) . "). Si apre con <strong>7-Zip</strong> o WinRAR usando la password <code>BACKUP_PASSWORD</code> del file <code>.env</code> del server (non è scritta in questa email).</p>")
                   . "<p style='color:#64748b;font-size:13px;'>Esito del backup di oggi:<br>" . implode('<br>', array_map(fn($x) => ($x[0] === false ? '❌ ' : ($x[0] ? '✅ ' : 'ℹ️ ')) . htmlspecialchars($x[1]), $righe)) . "</p>"
                   . "<p style='color:#64748b;font-size:13px;'>Conserva questa email in una casella sicura: contiene dati personali (cifrati).</p>";
            $inviati = 0;
            foreach ($dest_email as $em) {
                if (inviaNotificaEmail($em, "Backup database Eventi DiBEST - " . date('d/m/Y'), $corpo, $conn, null, $sopra ? [] : [['path' => $file_cif, 'nome' => basename($file_cif)]])) $inviati++;
            }
            @unlink($file_cif);
            $stato_email['ok'] = $inviati === count($dest_email) && !$sopra;
            $stato_email['messaggio'] = $sopra ? "Email inviata senza allegato: il database cifrato pesa " . $mb($dim) . " (limite 15 MB)"
                : ($inviati ? "Database cifrato (" . $mb($dim) . ") inviato a " . implode(', ', $dest_email) . ($inviati < count($dest_email) ? " (alcuni invii falliti: vedi il registro email)" : '') : "Invio email non riuscito: vedi il registro in Sistema Email");
        }
    }
    $stato_email['data'] = date('Y-m-d H:i:s');
    $nota($stato_email['ok'], $stato_email['messaggio']);
}

// ---------------------------------------------------------------------
// 5. ESITO: file di stato, avviso agli amministratori se qualcosa non va
// ---------------------------------------------------------------------
$stato = [
    'data' => date('Y-m-d H:i:s'), 'ok' => !$problemi,
    'db' => $db_ok ? ['file' => basename($file_db), 'byte' => filesize($file_db)] : null,
    'sito' => $zip_ok ? ['file' => basename($file_zip), 'byte' => filesize($file_zip)] : null,
    'nas' => $stato_nas, 'email' => $stato_email,
    'righe' => $righe,
];
// L'ultimo invio email riuscito resta memorizzato anche nei giorni in cui l'email non parte
$prec = stato_backup();
$stato['ultima_email'] = ($stato_email['ok'] === true) ? $stato['data'] : ($prec['ultima_email'] ?? null);
if (!is_dir($root . '/cache')) @mkdir($root . '/cache', 0755, true);
@file_put_contents($root . '/cache/backup_stato.json', json_encode($stato, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
registra_log_audit($conn, "Backup " . ($problemi ? "con problemi" : "completato"), ["Problemi" => count($problemi)]);

if ($problemi) {
    $corpo_av = "<p>Il backup del portale Eventi DiBEST del <strong>" . date('d/m/Y H:i') . "</strong> ha avuto dei problemi:</p><ul>"
              . implode('', array_map(fn($m) => "<li>" . htmlspecialchars($m) . "</li>", $problemi)) . "</ul>"
              . "<p>Dettagli e stato nel pannello: <a href='" . htmlspecialchars(url_base_sito() . '/admin/sistema.php#backup') . "'>Sistema Email &amp; Backup</a>.</p>";
    foreach (email_amministratori($conn) as $em) inviaNotificaEmail($em, "⚠️ Backup Eventi DiBEST: problemi da controllare", $corpo_av, $conn);
}

// ---------------------------------------------------------------------
// OUTPUT
// ---------------------------------------------------------------------
if ($cli) {
    foreach ($righe as [$ok, $msg]) echo ($ok === false ? '[ERRORE] ' : ($ok ? '[OK] ' : '[INFO] ')) . $msg . "\n";
    echo $problemi ? "Backup concluso con " . count($problemi) . " problemi.\n" : "Backup concluso.\n";
    exit($problemi ? 1 : 0);
}
?><!DOCTYPE html>
<html lang="it"><head><meta charset="utf-8"><title>Backup</title></head>
<body style="font-family: sans-serif; background: #f8f9fa;">
<div style="background: #fff; padding: 20px 24px; border-radius: 10px; max-width: 820px; margin: 30px auto; border: 1px solid #dee2e6;">
    <h2 style="margin-top: 0; color: <?php echo $problemi ? '#b45309' : '#198754'; ?>;"><?php echo $problemi ? '⚠️ Backup concluso con problemi' : '✅ Backup completato'; ?></h2>
    <ul style="line-height: 1.7; padding-left: 18px;">
        <?php foreach ($righe as [$ok, $msg]): ?>
            <li style="color: <?php echo $ok === false ? '#dc3545' : ($ok ? '#198754' : '#6c757d'); ?>;"><?php echo htmlspecialchars($msg); ?></li>
        <?php endforeach; ?>
    </ul>
    <p><a href="sistema.php#backup">Torna al pannello Sistema</a></p>
</div>
</body></html>
