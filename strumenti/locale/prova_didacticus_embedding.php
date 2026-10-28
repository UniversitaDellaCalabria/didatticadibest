<?php
// Prova locale: le voci si trovano meglio per significato (embedding con Ollama) che con le sole radici delle parole?
// Per ogni domanda parafrasata confronta la voce scelta dal bot a regole con quella scelta dagli embedding. Importa in transazione (poi annulla) il CSV indicato.
//   php strumenti/locale/prova_didacticus_embedding.php [--modello=bge-m3] [--url=http://127.0.0.1:11434] [--csv=file.csv]
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
$modello = $opz['modello'] ?? 'bge-m3';
$url = rtrim($opz['url'] ?? 'http://127.0.0.1:11434', '/');
$e = parse_ini_file('.env.locale');
mysqli_report(MYSQLI_REPORT_OFF);
$conn = new mysqli($e['DB_HOST'], $e['DB_USER'], $e['DB_PASS'], $e['DB_NAME']);
$conn->set_charset('utf8mb4');
$app = \App\Core\App::per($conn);
$repo = $app->get(\App\Assistenza\Didacticus\VociRepository::class);
$servizio = $app->get(\App\Assistenza\Didacticus\ServizioDidacticus::class);
$conn->begin_transaction();
if (isset($opz['csv'])) {
    $repo->importaCsv(file_get_contents($opz['csv']), date('Y-m-d H:i:s'));
}
function incorpora(string $url, string $modello, array $testi): array
{
    $ch = curl_init("$url/api/embed");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 300, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['model' => $modello, 'input' => array_values($testi)])]);
    $r = json_decode((string) curl_exec($ch), true);

    return $r['embeddings'] ?? [];
}
function coseno(array $a, array $b): float
{
    $p = $na = $nb = 0.0;
    foreach ($a as $i => $x) {
        $p += $x * $b[$i];
        $na += $x * $x;
        $nb += $b[$i] * $b[$i];
    }

    return $p / (sqrt($na * $nb) ?: 1);
}
$voci = array_values($repo->attive());
$t0 = microtime(true);
$ev = incorpora($url, $modello, array_map(fn ($v) => $v['titolo'] . "\n" . $v['domande'], $voci));
printf("%d voci incorporate in %.1f s\n", count($voci), microtime(true) - $t0);

// domanda => parte del titolo attesa
$prove = [
    'voglio fare lo stage fuori dall\'Italia' => 'estero',
    'devo laurearmi, chi mi segue il lavoro finale?' => 'relatore',
    'quali professori hanno ancora posto per seguire la mia tesi' => 'relatore',
    'il professore è segnato in rosso, che vuol dire' => 'semaforo',
    'ho un telefono android, posso avere il sito come applicazione' => 'Installare',
    'come porto gli appuntamenti del portale sul mio smartphone' => 'calendario',
    'ho scansionato il codice all\'ingresso del seminario' => 'QR',
    'il seminario era al completo ma ora mi hanno scritto che c\'è posto' => 'posto liberato',
    'non trovo il numero di telefono della segreteria' => 'Contatti',
    'entro quando devo consegnare i documenti' => 'Scadenze',
    'sono una prof, voglio dire quanti laureandi posso seguire' => 'disponibilità',
    'ho dimenticato le credenziali per entrare' => 'assword',
    'voglio il certificato che dimostra che sono iscritto' => 'ertificat',
    'mi serve cambiare gli esami scelti nel mio percorso' => 'iano di studi',
    'come faccio ad avere la tessera di tutor' => 'utor',
    'dove si trova l\'aula del corso di chimica' => 'ontatt',
    'azienda dove fare la tesi senza convenzione' => 'convenzionate',
    'vorrei sapere cosa fare per fare la tesi' => 'esi',
    'come mi segnalano nuove conferenze' => 'avviso',
    'posso cercare un professore per nome nel sito' => 'Cercare',
];
$ok = ['regole' => 0, 'embedding' => 0, 'misto' => 0];
$n = count($prove);
$qs = incorpora($url, $modello, array_keys($prove));
$i = 0;
foreach ($prove as $d => $atteso) {
    $cl = $servizio->prova($d, null);
    $r1 = $cl['classifica'][0] ?? null;
    $reg = $r1 && $r1['punteggio'] >= $cl['soglia'] ? $r1['titolo'] : '(nessuna)';
    $reg2 = $r1 ? $r1['titolo'] : '(nessuna)';
    $sc = [];
    foreach ($voci as $k => $v) {
        $sc[$k] = coseno($qs[$i], $ev[$k]);
    }
    arsort($sc);
    $k1 = array_key_first($sc);
    $emb = $voci[$k1]['titolo'];
    $ok['regole'] += mb_stripos($reg2, $atteso) !== false ? 1 : 0;
    $ok['embedding'] += mb_stripos($emb, $atteso) !== false ? 1 : 0;
    printf("\n%s\n  atteso «%s»\n  regole    %s %s\n  embedding %s (%.2f)\n", $d, $atteso, mb_stripos($reg2, $atteso) !== false ? '✓' : '✗', mb_strimwidth($reg2, 0, 60, '…'), mb_stripos($emb, $atteso) !== false ? '✓ ' . mb_strimwidth($emb, 0, 55, '…') : '✗ ' . mb_strimwidth($emb, 0, 55, '…'), $sc[$k1]);
    ++$i;
}
printf("\nCorrette su %d: regole %d, embedding %d\n", $n, $ok['regole'], $ok['embedding']);
$conn->rollback();
