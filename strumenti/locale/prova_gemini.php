<?php
// Prova di connessione a Gemini (nessuna modifica al portale). La chiave si legge da .env.locale (riga GEMINI_API_KEY=...), mai da riga di comando né stampata.
//   php -d extension=curl strumenti/locale/prova_gemini.php
// Invia solo un testo inventato. Mostra: modello usato, tempo e risposta.
if (PHP_SAPI !== 'cli') {
    exit;
}
chdir(realpath(__DIR__ . '/../..'));
$env = is_file('.env.locale') ? (parse_ini_file('.env.locale') ?: []) : [];
$chiave = trim((string) ($env['GEMINI_API_KEY'] ?? ''));
if ($chiave === '') {
    exit("Manca GEMINI_API_KEY in .env.locale (aggiungi una riga: GEMINI_API_KEY=la_tua_chiave).\n");
}
function chiama(string $metodo, string $url, string $chiave, ?array $corpo = null): array
{
    $c = curl_init($url);
    $opz = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => ['x-goog-api-key: ' . $chiave, 'Content-Type: application/json']];
    if ($corpo !== null) {
        $opz[CURLOPT_POST] = true;
        $opz[CURLOPT_POSTFIELDS] = json_encode($corpo, JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($c, $opz);
    $r = (string) curl_exec($c);
    $codice = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
    $errore = curl_error($c);
    curl_close($c);

    return ['codice' => $codice, 'dati' => json_decode($r, true) ?: [], 'errore' => $errore];
}

$base = 'https://generativelanguage.googleapis.com/v1beta';
$elenco = chiama('GET', "$base/models?pageSize=100", $chiave);
if ($elenco['codice'] !== 200) {
    exit('Connessione non riuscita (HTTP ' . $elenco['codice'] . '): ' . ($elenco['dati']['error']['message'] ?? $elenco['errore'] ?: 'nessuna risposta') . "\n");
}
$modelli = [];
foreach ($elenco['dati']['models'] ?? [] as $m) {
    if (in_array('generateContent', $m['supportedGenerationMethods'] ?? [], true)) {
        $modelli[] = preg_replace('#^models/#', '', (string) $m['name']);
    }
}
echo "Chiave accettata. Modelli di testo disponibili: " . count($modelli) . "\n";
$scelto = in_array('gemini-flash-latest', $modelli, true) ? 'gemini-flash-latest' : '';
foreach ($scelto === '' ? ['flash', 'pro'] : [] as $parte) {
    foreach ($modelli as $m) {
        if (str_contains($m, $parte) && !preg_match('/(preview|exp|thinking|image|tts|live|lite|vision|embed|audio)/', $m)) {
            $scelto = $m;
            break 2;
        }
    }
}
$scelto = $scelto ?: ($modelli[0] ?? '');
echo "Modello provato: $scelto\n";

$testo = "Il docente Rossi propone di attivare un laboratorio di biologia marina per il prossimo anno accademico, con 20 posti e 3 CFU. "
    . "La docente Bianchi ricorda che servono fondi per le uscite in barca e propone di chiedere un contributo al Dipartimento. Il Consiglio discute e rinvia la decisione alla prossima seduta.";
$t0 = microtime(true);
$r = chiama('POST', "$base/models/$scelto:generateContent", $chiave, ['contents' => [['parts' => [['text' => "Scrivi in italiano formale, come in un verbale di consiglio di dipartimento, un paragrafo che riassume il punto seguente. Non inventare nulla e segnala tra [parentesi] i numeri da verificare.\n\n" . $testo]]]]]);
$sec = round(microtime(true) - $t0, 1);
if ($r['codice'] !== 200) {
    exit('Richiesta non riuscita (HTTP ' . $r['codice'] . '): ' . ($r['dati']['error']['message'] ?? $r['errore']) . "\n");
}
echo "Risposta in {$sec} s:\n\n" . trim((string) ($r['dati']['candidates'][0]['content']['parts'][0]['text'] ?? '(vuota)')) . "\n";
