<?php
// Prova locale di Didacticus con un modello linguistico aperto (Ollama o simili): per ogni domanda mostra cosa risponde il bot a regole di sempre e cosa risponde
// il modello, con i tempi. Legge le voci dal database locale (.env.locale: acceso con bash strumenti/locale/avvia.sh) e non scrive nulla.
//   php strumenti/locale/prova_didacticus_modello.php [--url=http://127.0.0.1:11434/v1] [--modello=qwen2.5:3b] [--modalita=sempre|incerto] [--timeout=90] [--domande=file.txt]
// Le domande del file sono una per riga; senza file si usano quelle sotto.
if (PHP_SAPI !== 'cli') {
    exit;
}
chdir(realpath(__DIR__ . '/../..'));
require 'src/autoload.php';

$opz = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z]+)=(.*)$/', $a, $m)) {
        $opz[$m[1]] = $m[2];
    }
}
$url = $opz['url'] ?? 'http://127.0.0.1:11434/v1';
$modello = $opz['modello'] ?? 'qwen2.5:3b';
$modalita = $opz['modalita'] ?? 'sempre';
$timeout = (int) ($opz['timeout'] ?? 90);
$domande = isset($opz['domande']) && is_file($opz['domande'])
    ? array_values(array_filter(array_map('trim', file($opz['domande']))))
    : [
        'come faccio a fare il tirocinio curriculare?',
        'a chi mi rivolgo per un certificato di iscrizione?',
        'devo consegnare la relazione finale del tirocinio, come si fa?',
        'quando posso chiedere la tesi e cosa devo fare?',
        'ho perso la password del portale',
        'voglio cambiare il piano di studi',
        'come mi iscrivo a un seminario del dipartimento?',
        'posso fare il tirocinio all\'estero?',
        'quanto costa la tassa di laurea?',
        'mi serve un attestato di partecipazione a un evento',
        'dove trovo gli orari delle lezioni di biologia molecolare?',
        'chi è il direttore del dipartimento?',
    ];

$e = parse_ini_file('.env.locale');
mysqli_report(MYSQLI_REPORT_OFF);
$conn = new mysqli($e['DB_HOST'], $e['DB_USER'], $e['DB_PASS'], $e['DB_NAME']);
$conn->set_charset('utf8mb4');
$app = \App\Core\App::per($conn);
$voci = $app->get(\App\Assistenza\Didacticus\VociRepository::class);
$imp = $app->get(\App\Assistenza\Didacticus\ImpostazioniDidacticus::class);
$regole = new \App\Assistenza\Didacticus\ServizioDidacticus($voci, $imp);
$modelloLing = new \App\Assistenza\Didacticus\ModelloLinguistico($url, $modello, $timeout, $modalita);
$ia = new \App\Assistenza\Didacticus\ServizioDidacticus($voci, $imp, $modelloLing);

echo "Modello: $modello ($url), modalità «{$modalita}», " . count($voci->attive()) . " voci attive\n";
$tot = 0.0;
$n = 0;
foreach ($domande as $d) {
    echo "\n──────────────────────────────────────────────\nDOMANDA: $d\n";
    $r = $regole->risposta($d, null, true);
    echo '  A REGOLE  [' . $r['tipo'] . '] ' . mb_strimwidth(str_replace("\n", ' ', $r['testo']), 0, 220, '…') . "\n";
    $t0 = microtime(true);
    $m = $ia->risposta($d, null, true);
    $s = microtime(true) - $t0;
    if ($m['tipo'] === 'generata') {
        $tot += $s;
        ++$n;
    }
    echo sprintf('  MODELLO   [%s] (%.1f s) ', $m['tipo'], $s) . mb_strimwidth(str_replace("\n", ' ', $m['testo']), 0, 400, '…') . "\n";
}
if ($n) {
    echo sprintf("\nRisposte generate: %d, tempo medio %.1f s\n", $n, $tot / $n);
} else {
    echo "\nNessuna risposta generata: il servizio del modello è acceso? (ollama serve, e il modello scaricato)\n";
}
