<?php
// =========================================================================
// Verifica e ripristino dei backup del portale (da riga di comando, sul server o sul proprio computer).
//
//   php strumenti/ripristina_backup.php verifica  backup_DB_<data>.sql.gz [backup_SITO_<data>.zip]
//        Controlla che i file si aprano e siano completi. Non tocca nulla.
//   php strumenti/ripristina_backup.php prova     backup_DB_<data>.sql.gz [nome_database_di_prova]
//        Importa il database in un database di PROVA (il nome deve contenere «prova» o «ripristino») e confronta tabelle e righe.
//        Il database del portale non viene toccato. Serve un utente MySQL che possa creare database.
//   php strumenti/ripristina_backup.php file      backup_SITO_<data>.zip  cartella_di_destinazione
//        Estrae i file in una cartella nuova (senza sovrascrivere il sito): poi si copiano a mano quelli che servono (es. uploads/).
//   php strumenti/ripristina_backup.php database  backup_DB_<data>.sql.gz  --sovrascrivi-il-database-del-portale
//   (per prova e database: --env=file per usare un file .env diverso da quello del portale, es. --env=.env.locale)
//        RIPRISTINO VERO: cancella e ricrea le tabelle del database del portale con quelle del backup. Serve l'opzione scritta per esteso.
//
// Le istruzioni complete (quando e come ripristinare) sono nella guida «Backup e ripristino» (documentazione/Guide_per_ruolo).
// =========================================================================
if (PHP_SAPI !== 'cli') {
    exit;
}
$radice = realpath(__DIR__ . '/..');
chdir($radice);
require_once $radice . '/src/Sistema/Backup/VerificaBackup.php';

use App\Sistema\Backup\VerificaBackup;

// Le credenziali del database si leggono dal file .env del portale (o da quello indicato con --env=file), senza avviare il portale
$file_env = $radice . '/.env';
foreach ($argv as $x) {
    if (str_starts_with($x, '--env=')) {
        $file_env = substr($x, 6);
    }
}
$_env = @parse_ini_string(preg_replace('/^\s*#.*$/m', '', (string)@file_get_contents($file_env))) ?: [];
$db_host = $_env['DB_HOST'] ?? 'localhost';
$db_user = $_env['DB_USER'] ?? '';
$db_pass = $_env['DB_PASS'] ?? '';
$db_name = $_env['DB_NAME'] ?? '';

$args = array_values(array_filter(array_slice($argv, 1), static fn($x) => !str_starts_with($x, '--')));
$cmd = $args[0] ?? '';
$a = $args[1] ?? '';
$b = $args[2] ?? '';
$uso = fn(string $m) => exit($m . "\nVedi l'intestazione di questo file per i comandi.\n");
$mb = fn($byte) => number_format($byte / 1048576, 1, ',', '.') . ' MB';

/** Importa un'esportazione SQL compressa (come scrive cron_backup.php) nella connessione data. Ritorna [istruzioni eseguite, errori]. */
function importa_sql_gz(mysqli $c, string $file): array {
    $gz = gzopen($file, 'rb');
    $n = 0;
    $errori = [];
    $istr = '';
    while (($riga = gzgets($gz)) !== false) {
        if ($istr === '' && (trim($riga) === '' || str_starts_with($riga, '--'))) {
            continue;
        }
        $istr .= $riga;
        if (!str_ends_with(rtrim($riga), ';')) {
            continue;
        }
        // Una istruzione per volta: le INSERT stanno su una riga sola, le CREATE TABLE finiscono con «;»
        if ($c->query($istr) === false) {
            $errori[] = mb_substr(trim($istr), 0, 80) . ' → ' . $c->error;
            if (count($errori) >= 10) {
                break;
            }
        }
        ++$n;
        $istr = '';
    }
    gzclose($gz);

    return [$n, $errori];
}

if ($cmd === 'verifica') {
    if ($a === '') $uso('Indica il file del database.');
    $v = new VerificaBackup();
    $db = $v->database($a);
    echo ($db['ok'] ? '[OK] ' : '[ERRORE] ') . "Database: " . ($db['ok'] ? "completo, {$db['tabelle']} tabelle e {$db['righe']} righe (" . $mb(filesize($a)) . ")" : $db['errore']) . "\n";
    $ok = $db['ok'];
    if ($b !== '') {
        $e = $v->zip($b);
        echo ($e === null ? '[OK] ' : '[ERRORE] ') . "File del sito: " . ($e === null ? 'lo ZIP si apre e contiene config.php (' . $mb(filesize($b)) . ')' : $e) . "\n";
        $ok = $ok && $e === null;
    }
    exit($ok ? 0 : 1);
}

if ($cmd === 'prova') {
    if ($a === '' || !is_file($a)) $uso('Indica il file del database.');
    $nome = $b !== '' ? $b : 'eventi_ripristino_prova';
    if (!preg_match('/^[A-Za-z0-9_]+$/', $nome) || !preg_match('/prova|ripristino/i', $nome)) $uso('Il nome del database di prova deve contenere «prova» o «ripristino».');
    if ($nome === $db_name) $uso('Il database di prova non può essere quello del portale.');
    $v = (new VerificaBackup())->database($a);
    if (!$v['ok']) exit("[ERRORE] Il backup non è integro: {$v['errore']}\n");
    $c = new mysqli($db_host, $db_user, $db_pass);
    $c->set_charset('utf8mb4');
    if (!$c->query("DROP DATABASE IF EXISTS `$nome`") || !$c->query("CREATE DATABASE `$nome` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci")) {
        exit("[ERRORE] Non riesco a creare il database di prova «{$nome}»: {$c->error}\nServe un utente MySQL con il permesso CREATE; altrimenti crea il database a mano e importa il file con 7-Zip + mysql.\n");
    }
    $c->select_db($nome);
    [$n, $errori] = importa_sql_gz($c, $a);
    $tabelle = (int)$c->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$nome' AND table_type = 'BASE TABLE'")->fetch_row()[0];
    echo "Importate $n istruzioni nel database «{$nome}»: $tabelle tabelle (nel backup: {$v['tabelle']}).\n";
    foreach ($errori as $e) echo "[ERRORE] $e\n";
    $ok = !$errori && $tabelle === $v['tabelle'];
    if ($ok) {
        $righe = 0;
        foreach ($c->query("SELECT table_name FROM information_schema.tables WHERE table_schema = '$nome' AND table_type = 'BASE TABLE'")->fetch_all() as [$t]) {
            $righe += (int)$c->query("SELECT COUNT(*) FROM `$t`")->fetch_row()[0];
        }
        $ok = $righe === $v['righe'];
        echo ($ok ? '[OK] ' : '[ERRORE] ') . "Righe ripristinate: $righe (nel backup: {$v['righe']}).\n";
    }
    echo $ok ? "Il backup si ripristina correttamente. Il database di prova «{$nome}» si può cancellare: DROP DATABASE `$nome`;\n" : "Il ripristino di prova NON è riuscito.\n";
    exit($ok ? 0 : 1);
}

if ($cmd === 'file') {
    if ($a === '' || $b === '') $uso('Indica lo ZIP e la cartella di destinazione.');
    if (is_dir($b) && count(scandir($b)) > 2) $uso("La cartella «{$b}» non è vuota: indicane una nuova, così il sito non viene sovrascritto.");
    $err = (new VerificaBackup())->zip($a);
    if ($err !== null) exit("[ERRORE] $err\n");
    $z = new ZipArchive();
    $z->open($a);
    @mkdir($b, 0750, true);
    $ok = $z->extractTo($b);
    $n = $z->numFiles;
    $z->close();
    echo $ok ? "[OK] Estratti $n file in $b\nCopia a mano nel sito quelli che servono (di solito la cartella uploads/).\n" : "[ERRORE] Estrazione non riuscita (permessi o spazio?)\n";
    exit($ok ? 0 : 1);
}

if ($cmd === 'database') {
    if ($a === '' || !is_file($a)) $uso('Indica il file del database.');
    if (!in_array('--sovrascrivi-il-database-del-portale', $argv, true)) {
        $uso("Questo comando CANCELLA le tabelle del database «" . $db_name . "» e le ricrea dal backup.\nSe sei sicuro aggiungi l'opzione --sovrascrivi-il-database-del-portale (prima prova il backup con il comando «prova»).");
    }
    $v = (new VerificaBackup())->database($a);
    if (!$v['ok']) exit("[ERRORE] Il backup non è integro: {$v['errore']}. Non tocco il database.\n");
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($conn->connect_error) exit('[ERRORE] Connessione al database non riuscita: ' . $conn->connect_error . "
");
    $conn->set_charset('utf8mb4');
    echo "Ripristino nel database «" . $db_name . "»: {$v['tabelle']} tabelle, {$v['righe']} righe…\n";
    [$n, $errori] = importa_sql_gz($conn, $a);
    foreach ($errori as $e) echo "[ERRORE] $e\n";
    echo $errori ? "Ripristino concluso con errori: controlla l'elenco qui sopra.\n" : "[OK] Ripristino concluso: $n istruzioni eseguite.\nSvuota la cartella cache/ (cancella i file schema_v*.ok) e apri il portale: lo schema si aggiorna da solo se il backup è di una versione precedente.\n";
    exit($errori ? 1 : 0);
}

$uso('Comando non riconosciuto.');
