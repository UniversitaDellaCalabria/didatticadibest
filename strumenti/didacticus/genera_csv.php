<?php
// =========================================================================
// Trasforma strumenti/didacticus/voci_didattica.txt (le voci scritte in forma leggibile) nel CSV da importare in Didattica → Didacticus → Voci → «Importa da CSV»:
//   php strumenti/didacticus/genera_csv.php
// Scrive documentazione/didacticus_voci_didattica.csv (UTF-8 con BOM, separatore «;»). Le voci con lo stesso titolo si aggiornano, le altre si aggiungono.
// La stessa lettura è usata dalle prove (strumenti/prove/oop/assistenza_didacticus.php) per controllare che il file sia ben fatto.
// =========================================================================

/**
 * Legge il file delle voci.
 *
 * @return list<array{argomento: string, titolo: string, op: bool, login: bool, domande: list<string>, parole: string, link: list<array{e: string, u: string}>, risposta: string}>
 */
function leggi_voci_didattica(string $file): array
{
    $voci = [];
    $v = null;
    $in_risposta = false;
    foreach (preg_split('/\R/u', (string) file_get_contents($file)) ?: [] as $riga) {
        if (preg_match('/^\[([a-z]+)\]\s+(.+?)((?:\s+\{(?:op|login)\})*)\s*$/u', $riga, $m)) {
            if ($v !== null) {
                $voci[] = $v;
            }
            $v = ['argomento' => $m[1], 'titolo' => trim($m[2]), 'op' => str_contains($m[3], '{op}'), 'login' => str_contains($m[3], '{login}'), 'domande' => [], 'parole' => '', 'link' => [], 'risposta' => ''];
            $in_risposta = false;
            continue;
        }
        if ($v === null || ($riga !== '' && $riga[0] === '#' && !$in_risposta)) {
            continue;
        }
        if (str_starts_with($riga, '@ ')) {
            [$e, $u] = array_pad(explode('|', substr($riga, 2), 2), 2, '');
            $v['link'][] = ['e' => trim($e), 'u' => trim($u)];
        } elseif (!$in_risposta && str_starts_with($riga, '? ')) {
            $v['domande'][] = trim(substr($riga, 2));
        } elseif (!$in_risposta && str_starts_with($riga, '= ')) {
            $v['parole'] = trim(substr($riga, 2));
        } elseif (trim($riga) === '---' && !$in_risposta) {
            $in_risposta = true;
        } elseif ($in_risposta) {
            $v['risposta'] .= $riga . "\n";
        }
    }
    if ($v !== null) {
        $voci[] = $v;
    }
    foreach ($voci as &$x) {
        $x['risposta'] = trim($x['risposta']);
    }

    return $voci;
}

/** Il testo del CSV (UTF-8 con BOM, separatore «;») con le voci lette dal file. @param list<array<string, mixed>> $voci */
function csv_da_voci(array $voci): string
{
    $nomi = ['tirocini' => 'Tirocini e Formazione Scuola Lavoro', 'certificati' => 'Certificati e attestati', 'pratiche' => 'Pratiche e moduli', 'tutorato' => 'Tutorato', 'tesi' => 'Tesi e laurea',
        'eventi' => 'Eventi e prenotazioni', 'accesso' => 'Accesso e account', 'iscrizione' => 'Iscrizione, tasse e orientamento', 'esami' => 'Esami e piano di studi', 'altro' => 'Altro'];
    $f = fopen('php://temp', 'r+');
    fputcsv($f, ['Argomento', 'Titolo', 'Domande', 'Parole chiave', 'Risposta', 'Link (etichetta|indirizzo)', 'Attiva', 'Usi', 'Utile', 'Non utile', 'Propone operatore', 'Solo con accesso'], ';', '"', '');
    foreach ($voci as $v) {
        fputcsv($f, [$nomi[$v['argomento']] ?? $v['argomento'], $v['titolo'], implode("\n", $v['domande']), $v['parole'], $v['risposta'],
            implode("\n", array_map(fn($l) => $l['e'] . '|' . $l['u'], $v['link'])), 'sì', 0, 0, 0, $v['op'] ? 'sì' : 'no', $v['login'] ? 'sì' : 'no'], ';', '"', '');
    }
    rewind($f);
    $testo = "\xEF\xBB\xBF" . stream_get_contents($f);
    fclose($f);

    return $testo;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $voci = leggi_voci_didattica(__DIR__ . '/voci_didattica.txt');
    file_put_contents(dirname(__DIR__, 2) . '/documentazione/didacticus_voci_didattica.csv', csv_da_voci($voci));
    echo count($voci) . " voci scritte in documentazione/didacticus_voci_didattica.csv\n";
}
