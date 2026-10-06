<?php

declare(strict_types=1);

namespace App\Attestati;

use ZipArchive;

/**
 * Lettura dell'elenco degli studenti da testo incollato, campi del modulo o file caricato (CSV, TXT, XLSX).
 * Ogni voce è ['cognome' => ..., 'nome' => ...], senza righe vuote né doppioni.
 */
final class ElencoStudenti
{
    /**
     * Righe incollate da Excel o da un file CSV: "Cognome<TAB>Nome", "Cognome;Nome", "Cognome,Nome".
     * Una riga senza separatori è tenuta intera (es. "Rossi Mario"). L'intestazione "Cognome/Nome" è ignorata.
     *
     * @return list<array{cognome: string, nome: string}>
     */
    public static function daTesto(string $testo, int $max = 500): array
    {
        $testo = (string) preg_replace('/^\xEF\xBB\xBF/', '', $testo); // BOM dei CSV di Excel
        $out = [];
        $visti = [];
        foreach ((array) preg_split('/\r\n|\r|\n/', $testo) as $riga) {
            $riga = trim((string) $riga);
            if ($riga === '') {
                continue;
            }
            $parti = (array) preg_split('/\t|;|,/', $riga);
            $parti = array_values(array_filter(array_map(static fn ($x) => trim((string) $x, " \t\"'"), $parti), static fn ($x) => $x !== ''));
            if (!$parti) {
                continue;
            }
            $cognome = mb_substr($parti[0], 0, 100);
            $nome = mb_substr(implode(' ', array_slice($parti, 1)), 0, 100);
            if (preg_match('/^cognome$/i', $cognome) && ($nome === '' || preg_match('/^nome$/i', $nome))) {
                continue;
            }
            $chiave = mb_strtolower($cognome . '|' . $nome);
            if (isset($visti[$chiave])) {
                continue;
            }
            $visti[$chiave] = true;
            $out[] = ['cognome' => $cognome, 'nome' => $nome];
            if (count($out) >= $max) {
                break;
            }
        }

        return $out;
    }

    /**
     * Elenco dai campi separati Cognome[] e Nome[] del modulo: righe vuote ignorate, doppioni tolti.
     *
     * @param array<int|string, mixed> $cognomi
     * @param array<int|string, mixed> $nomi
     * @return list<array{cognome: string, nome: string}>
     */
    public static function daCampi(array $cognomi, array $nomi, int $max = 500): array
    {
        $out = [];
        $visti = [];
        $nomi = array_values($nomi);
        foreach (array_values($cognomi) as $i => $c) {
            $cognome = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $c)), 0, 100);
            $nome = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) ($nomi[$i] ?? ''))), 0, 100);
            if ($cognome === '' && $nome === '') {
                continue;
            }
            $chiave = mb_strtolower($cognome . '|' . $nome);
            if (isset($visti[$chiave])) {
                continue;
            }
            $visti[$chiave] = true;
            $out[] = ['cognome' => $cognome, 'nome' => $nome];
            if (count($out) >= $max) {
                break;
            }
        }

        return $out;
    }

    /**
     * Testo "Cognome;Nome" da un file caricato: CSV/TXT (anche salvato da Excel) oppure XLSX (prime due colonne
     * del primo foglio; serve l'estensione zip di PHP). Ritorna il testo oppure null con $errore valorizzato.
     *
     * @param array<string, mixed> $file voce di $_FILES
     */
    public static function testoDaFile(array $file, ?string &$errore = null): ?string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errore = 'Caricamento del file non riuscito.';

            return null;
        }
        if ((int) $file['size'] > 2 * 1024 * 1024) {
            $errore = 'Il file supera i 2 MB.';

            return null;
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['csv', 'txt'], true)) {
            $t = (string) file_get_contents((string) $file['tmp_name']);
            if (!mb_check_encoding($t, 'UTF-8')) {
                $t = mb_convert_encoding($t, 'UTF-8', 'Windows-1252'); // CSV di Excel in italiano
            }

            return $t;
        }
        if ($ext === 'xlsx') {
            return self::testoDaXlsx((string) $file['tmp_name'], $errore);
        }
        $errore = 'Formato non supportato: carica un file .xlsx o .csv.';

        return null;
    }

    /** Prime due colonne del primo foglio di un .xlsx come righe "A<TAB>B". */
    private static function testoDaXlsx(string $percorso, ?string &$errore): ?string
    {
        if (!class_exists(ZipArchive::class)) {
            $errore = 'Su questo server non si possono leggere i file .xlsx: salva il file come CSV oppure copia e incolla i nomi.';

            return null;
        }
        $zip = new ZipArchive();
        if ($zip->open($percorso) !== true) {
            $errore = 'Il file .xlsx non è leggibile.';

            return null;
        }
        $condivise = [];
        if (($ss = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $xml = @simplexml_load_string($ss);
            if ($xml) {
                foreach ($xml->si as $si) {
                    $condivise[] = isset($si->t) ? (string) $si->t : implode('', array_map('strval', $si->xpath('.//*[local-name()="t"]') ?: []));
                }
            }
        }
        $foglio = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        $xml = $foglio !== false ? @simplexml_load_string($foglio) : false;
        if (!$xml) {
            $errore = 'Nel file .xlsx non trovo il primo foglio.';

            return null;
        }
        $righe = [];
        foreach ($xml->sheetData->row as $row) {
            $celle = [];
            foreach ($row->c as $c) {
                $col = preg_replace('/\d+/', '', (string) $c['r']);
                if (!in_array($col, ['A', 'B'], true)) {
                    continue;
                }
                $v = (string) ($c->v ?? '');
                if ((string) $c['t'] === 's') {
                    $v = $condivise[(int) $v] ?? '';
                } elseif ((string) $c['t'] === 'inlineStr') {
                    $v = (string) ($c->is->t ?? '');
                }
                $celle[$col] = str_replace(["\t", ';'], ' ', trim($v));
            }
            if ($celle) {
                $righe[] = ($celle['A'] ?? '') . "\t" . ($celle['B'] ?? '');
            }
        }

        return implode("\n", $righe);
    }
}
