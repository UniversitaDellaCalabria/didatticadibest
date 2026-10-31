<?php
// =========================================================================
// Scenario per profilo (roadmap 7.1): un utente di prova per ogni profilo entra dall'accesso di prova e si controlla che l'area personale e «Da fare per te»
// si aprano senza errori e che ogni link della pagina (tessere e compiti) porti a una pagina che esiste (niente 404 né errori di PHP).
//   bash strumenti/locale/avvia.sh   (in un'altra finestra)
//   php strumenti/locale/scenario_profili.php [http://127.0.0.1:8080]
// Crea (se mancano) gli utenti «profilo.<ruolo>@example.org». Non scrive altro: esce con codice 1 se qualcosa non va.
// =========================================================================
if (PHP_SAPI !== 'cli') {
    exit;
}
chdir(realpath(__DIR__ . '/../..'));
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8080', '/');
$e = parse_ini_file('.env.locale');
mysqli_report(MYSQLI_REPORT_OFF);
$conn = new mysqli($e['DB_HOST'], $e['DB_USER'], $e['DB_PASS'], $e['DB_NAME']);
$conn->set_charset('utf8mb4');

$profili = [3 => 'Studente', 4 => 'Docente/Dipendente', 5 => 'Esterno', 6 => 'Docenti', 7 => 'Personale TA', 8 => 'Altro personale', 9 => 'Scuole', 10 => 'Aziende'];
$errori = 0;
$prendi = function (string $url, string $jar) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 30]);
    $corpo = (string) curl_exec($ch);
    $codice = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$codice, $corpo];
};

foreach ($profili as $ruolo => $nome) {
    $email = "profilo.$ruolo@example.org";
    if (!$conn->query("SELECT id FROM utenti WHERE email = '$email'")->fetch_row()) {
        $conn->query("INSERT INTO utenti (nome, cognome, email, ruolo_id, ruoli_secondari) VALUES ('Prova', '" . $conn->real_escape_string($nome) . "', '$email', $ruolo, '')");
    }
    $id = (int) $conn->query("SELECT id FROM utenti WHERE email = '$email'")->fetch_row()[0];
    $jar = tempnam(sys_get_temp_dir(), 'prof');
    $prendi("$base/__accesso?u=$id", $jar);
    echo "\n$nome (id $id)\n";
    $link = [];
    foreach (['area_personale.php', 'da_fare.php'] as $pagina) {
        [$codice, $corpo] = $prendi("$base/eventi/$pagina", $jar);
        $guasto = $codice !== 200 || preg_match('/(Fatal error|Warning:|Notice:|Deprecated:|Stack trace)/', $corpo);
        echo ($guasto ? '  KO ' : '  OK ') . "$pagina ($codice)\n";
        $errori += $guasto ? 1 : 0;
        if (preg_match_all('/href="([^"#]+\.php[^"]*)"/', $corpo, $m)) {
            foreach ($m[1] as $h) {
                if (!preg_match('#^(https?:|mailto:|//)#', $h) && !str_starts_with($h, '/__') && !str_contains($h, 'logout') && !str_contains($h, 'esci')) {
                    $link[html_entity_decode($h)] = true;
                }
            }
        }
    }
    foreach (array_keys($link) as $h) {
        [$codice, $corpo] = $prendi("$base/eventi/" . ltrim($h, '/'), $jar);
        $guasto = $codice >= 400 || preg_match('/(Fatal error|Warning:|Notice:|Stack trace)/', $corpo);
        if ($guasto) {
            echo "  KO link $h ($codice)\n";
            $errori++;
        }
    }
    echo '  ' . count($link) . " link controllati\n";
    @unlink($jar);
}
echo $errori ? "\n$errori problemi.\n" : "\nTutto a posto.\n";
exit($errori ? 1 : 0);
