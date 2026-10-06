<?php
// =========================================================================
// strumenti/inventario_oop.php - Avanzamento della migrazione a classi (MIGRATION_PLAN.md, Step 0):
// per ogni file di inc/ quante funzioni restano procedurali e quante sono già facciate delle classi di src/
// (il corpo chiama \App\...), quante query dirette ci sono ancora nelle pagine e quante classi ci sono in src/.
//   php strumenti/inventario_oop.php          tabella
//   php strumenti/inventario_oop.php --csv    stesso contenuto in CSV
// Controlla anche la versione di PHP e le estensioni richieste da composer.json.
// =========================================================================
if (PHP_SAPI !== 'cli') exit;
$SITO = realpath(__DIR__ . '/..');
$csv = in_array('--csv', $argv, true);

// Funzioni di un file con il loro corpo (token di PHP: niente regex fragili)
function funzioni_file(string $file): array {
    $t = token_get_all((string)file_get_contents($file));
    $out = []; $n = count($t);
    for ($i = 0; $i < $n; $i++) {
        if (!is_array($t[$i]) || $t[$i][0] !== T_FUNCTION) continue;
        $j = $i + 1;
        while ($j < $n && is_array($t[$j]) && $t[$j][0] === T_WHITESPACE) $j++;
        if (!is_array($t[$j]) || $t[$j][0] !== T_STRING) continue; // funzioni anonime
        $nome = $t[$j][1];
        while ($j < $n && $t[$j] !== '{' && $t[$j] !== ';') $j++;
        if ($j >= $n || $t[$j] === ';') continue;
        $liv = 0; $corpo = '';
        for (; $j < $n; $j++) {
            $x = is_array($t[$j]) ? $t[$j][1] : $t[$j];
            if ($x === '{' || (is_array($t[$j]) && in_array($t[$j][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) $liv++;
            if ($x === '}') $liv--;
            $corpo .= $x;
            if ($liv === 0) break;
        }
        $out[$nome] = $corpo;
    }
    return $out;
}

$righe = []; $tot = ['funzioni' => 0, 'facciate' => 0];
foreach (glob("$SITO/inc/*.php") as $f) {
    $fun = funzioni_file($f);
    $fac = count(array_filter($fun, fn($c) => preg_match('/\\\\?App\\\\/', $c)));
    $righe[] = [basename($f), count($fun), $fac, count($fun) - $fac];
    $tot['funzioni'] += count($fun); $tot['facciate'] += $fac;
}
usort($righe, fn($a, $b) => $b[3] <=> $a[3]);

$pagine = array_merge(glob("$SITO/*.php"), glob("$SITO/admin/*.php"));
$query_pagine = 0;
foreach ($pagine as $p) $query_pagine += preg_match_all('/->(query|prepare)\(/', (string)file_get_contents($p));
$classi = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$SITO/src", FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) if ($f->getExtension() === 'php' && preg_match('/^\s*(final\s+|abstract\s+)?(class|interface|trait|enum)\s/m', (string)file_get_contents($f->getPathname()))) $classi++;

if ($csv) {
    echo "file,funzioni,facciate,procedurali\n";
    foreach ($righe as $r) echo implode(',', $r), "\n";
    exit;
}
printf("PHP %s · estensioni: %s\n\n", PHP_VERSION, implode(', ', array_map(fn($e) => $e . (extension_loaded($e) ? ' ok' : ' MANCANTE'), ['mysqli', 'mbstring', 'json', 'zip', 'curl', 'openssl'])));
printf("%-24s %9s %9s %12s\n", 'File di inc/', 'funzioni', 'facciate', 'procedurali');
foreach ($righe as $r) printf("%-24s %9d %9d %12d\n", ...$r);
printf("%-24s %9d %9d %12d\n\n", 'TOTALE', $tot['funzioni'], $tot['facciate'], $tot['funzioni'] - $tot['facciate']);
printf("Query dirette (->query / ->prepare) nelle %d pagine: %d\n", count($pagine), $query_pagine);
printf("Classi in src/: %d · avanzamento funzioni: %.1f%%\n", $classi, $tot['funzioni'] ? 100 * $tot['facciate'] / $tot['funzioni'] : 0);
