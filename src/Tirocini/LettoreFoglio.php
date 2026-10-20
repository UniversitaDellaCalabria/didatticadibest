<?php

declare(strict_types=1);

namespace App\Tirocini;

use ZipArchive;

/**
 * Legge il primo foglio di un file Excel (.xlsx) o un CSV e lo restituisce come righe con le intestazioni come chiavi
 * (in minuscolo, senza spazi in più). Capisce sia le stringhe condivise sia quelle scritte nella cella, come nel report del TSP.
 */
final class LettoreFoglio
{
    /**
     * @param array{tmp_name?: string, name?: string, error?: int} $file elemento di $_FILES
     * @return list<array<string, string>>|string righe, o il messaggio dell'errore
     */
    public static function righe(array $file): array|string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '') && !is_file($file['tmp_name'] ?? '')) {
            return 'Scegli un file .xlsx o .csv.';
        }

        return self::daPercorso((string) $file['tmp_name'], (string) ($file['name'] ?? ''));
    }

    /** @return list<array<string, string>>|string */
    public static function daPercorso(string $percorso, string $nome = ''): array|string
    {
        $celle = str_ends_with(strtolower($nome), '.csv') ? self::csv($percorso) : self::xlsx($percorso);
        if (is_string($celle)) {
            return $celle;
        }
        if (count($celle) < 2) {
            return 'Il file non contiene righe da leggere.';
        }
        $intest = array_map(static fn (string $c): string => mb_strtolower(trim(preg_replace('/\s+/', ' ', $c) ?? $c)), array_shift($celle));
        $out = [];
        foreach ($celle as $riga) {
            $r = [];
            foreach ($intest as $i => $nomeCol) {
                if ($nomeCol !== '') {
                    $r[$nomeCol] = trim((string) ($riga[$i] ?? ''));
                }
            }
            if (array_filter($r, static fn (string $v): bool => $v !== '')) {
                $out[] = $r;
            }
        }

        return $out;
    }

    /** @return list<list<string>>|string */
    private static function xlsx(string $percorso): array|string
    {
        if (!class_exists(ZipArchive::class)) {
            return 'Su questo server non si possono leggere i file .xlsx: salva il file come CSV.';
        }
        $zip = new ZipArchive();
        if ($zip->open($percorso) !== true) {
            return 'Il file non è un .xlsx leggibile.';
        }
        $condivise = [];
        if (($ss = $zip->getFromName('xl/sharedStrings.xml')) !== false && ($xml = @simplexml_load_string($ss))) {
            foreach ($xml->si as $si) {
                $condivise[] = isset($si->t) ? (string) $si->t : implode('', array_map('strval', $si->xpath('.//*[local-name()="t"]') ?: []));
            }
        }
        $foglio = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        $xml = $foglio !== false ? @simplexml_load_string($foglio) : false;
        if (!$xml) {
            return 'Nel file non trovo il primo foglio.';
        }
        $righe = [];
        foreach ($xml->sheetData->row as $row) {
            $riga = [];
            foreach ($row->c as $c) {
                $indice = self::indiceColonna((string) $c['r']);
                $v = (string) ($c->v ?? '');
                $t = (string) $c['t'];
                if ($t === 's') {
                    $v = $condivise[(int) $v] ?? '';
                } elseif ($t === 'inlineStr') {
                    $v = isset($c->is->t) ? (string) $c->is->t : implode('', array_map('strval', $c->is->xpath('.//*[local-name()="t"]') ?: []));
                }
                $riga[$indice] = $v;
            }
            if ($riga) {
                $pieno = [];
                for ($i = 0, $max = max(array_keys($riga)); $i <= $max; $i++) {
                    $pieno[] = $riga[$i] ?? '';
                }
                $righe[] = $pieno;
            }
        }

        return $righe;
    }

    /** @return list<list<string>>|string */
    private static function csv(string $percorso): array|string
    {
        $testo = @file_get_contents($percorso);
        if ($testo === false || $testo === '') {
            return 'Il file è vuoto.';
        }
        $testo = preg_replace('/^\xEF\xBB\xBF/', '', $testo) ?? $testo;
        if (!mb_check_encoding($testo, 'UTF-8')) {
            $testo = mb_convert_encoding($testo, 'UTF-8', 'Windows-1252');
        }
        $sep = substr_count(strtok($testo, "\n") ?: '', ';') >= substr_count(strtok($testo, "\n") ?: '', ',') ? ';' : ',';
        $righe = [];
        $f = fopen('php://temp', 'r+');
        fwrite($f, $testo);
        rewind($f);
        while (($r = fgetcsv($f, 0, $sep, '"', '')) !== false) {
            $righe[] = array_map('strval', $r);
        }
        fclose($f);

        return $righe;
    }

    private static function indiceColonna(string $rif): int
    {
        $lettere = preg_replace('/\d+/', '', $rif) ?? '';
        $n = 0;
        foreach (str_split(strtoupper($lettere)) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return max(0, $n - 1);
    }
}
