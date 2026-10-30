<?php
// =========================================================================
// Controllo delle email scelte nel profilo prima della verifica con il codice (30/10/2026, schema v107). Da riga di comando, sul server.
//
//   php strumenti/controllo_email_personalizzate.php
//        Elenca gli utenti con email personalizzata NON verificata che coincide con quella di un docente, un firmatario, un referente,
//        un ufficio o un'altra persona dell'anagrafe di Ateneo: sono i casi da guardare (qualcuno potrebbe aver ricevuto compiti di un altro).
//   php strumenti/controllo_email_personalizzate.php --tutte
//        Elenca tutte le email personalizzate non verificate.
//   php strumenti/controllo_email_personalizzate.php --azzera=12,34
//        Per gli utenti indicati toglie l'email scelta a mano: al prossimo accesso torna quella del login di Ateneo.
//   (--env=file per usare un file .env diverso da quello del portale, es. --env=.env.locale)
//
// Prima di usarlo il portale deve aver aggiornato lo schema alla v107 (basta aprire una pagina qualsiasi dopo il caricamento).
// =========================================================================
if (PHP_SAPI !== 'cli') {
    exit;
}
$radice = realpath(__DIR__ . '/..');
require_once $radice . '/src/autoload.php';

$file_env = $radice . '/.env';
$tutte = false;
$azzera = [];
foreach (array_slice($argv, 1) as $x) {
    if (str_starts_with($x, '--env=')) $file_env = substr($x, 6);
    elseif ($x === '--tutte') $tutte = true;
    elseif (str_starts_with($x, '--azzera=')) $azzera = array_map('intval', explode(',', substr($x, 9)));
}
$_env = @parse_ini_string(preg_replace('/^\s*#.*$/m', '', (string)@file_get_contents($file_env))) ?: [];
mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli($_env['DB_HOST'] ?? 'localhost', $_env['DB_USER'] ?? '', $_env['DB_PASS'] ?? '', $_env['DB_NAME'] ?? '');
if ($conn->connect_error) {
    fwrite(STDERR, "Database non raggiungibile: controlla il file $file_env.\n");
    exit(2);
}
$conn->set_charset('utf8mb4');
$db = \App\Core\Database::per($conn);
if ($db->valore("SHOW COLUMNS FROM utenti LIKE 'email_verificata_il'") === null) {
    fwrite(STDERR, "Lo schema non è ancora alla v107: apri una pagina del portale e riprova.\n");
    exit(2);
}
$controllo = new \App\Auth\ControlloEmailPersonalizzate($db);

if ($azzera) {
    echo 'Utenti cambiati: ' . $controllo->azzera($azzera) . "\n";
    exit(0);
}
$elenco = $controllo->elenco(!$tutte);
if (!$elenco) {
    echo $tutte ? "Nessuna email personalizzata non verificata.\n" : "Nessun caso sospetto: nessuna email scelta a mano coincide con quella di un docente, un firmatario o un'altra persona.\n";
    exit(0);
}
echo ($tutte ? 'Email personalizzate non verificate: ' : 'Casi da controllare: ') . count($elenco) . "\n\n";
foreach ($elenco as $u) {
    printf("#%d  %s %s  <%s>\n", $u['id'], $u['cognome'], $u['nome'], $u['email']);
    foreach ($u['dove'] as $d) echo "      - $d\n";
}
echo "\nSe un'email non è della persona, toglila con: php strumenti/controllo_email_personalizzate.php --azzera=ID[,ID]\n";
