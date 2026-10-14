<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Core\Sito;
use App\Infrastructure\Pdf\TestoPdf;

/**
 * L'archivio dei verbali: i verbali vecchi (PDF) si caricano a decine, anche da una cartella; data, numero e anno accademico si ricavano dal
 * nome del file e dal percorso (o dal testo del PDF) e si correggono dopo. Il testo del PDF, quando è leggibile, serve alla ricerca.
 */
final class ArchivioVerbali
{
    public const MAX_BYTE = 30 * 1024 * 1024;
    /** Oltre questa dimensione il testo del PDF non si legge (il verbale si archivia lo stesso, senza ricerca nel testo). */
    public const MAX_BYTE_TESTO = 12 * 1024 * 1024;

    private const MESI = ['gennaio' => 1, 'febbraio' => 2, 'marzo' => 3, 'aprile' => 4, 'maggio' => 5, 'giugno' => 6, 'luglio' => 7, 'agosto' => 8, 'settembre' => 9, 'ottobre' => 10, 'novembre' => 11, 'dicembre' => 12,
        'gen' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'mag' => 5, 'giu' => 6, 'lug' => 7, 'ago' => 8, 'set' => 9, 'sett' => 9, 'ott' => 10, 'nov' => 11, 'dic' => 12];

    public function __construct(private SedutaRepository $sedute, private ConsiglioRepository $consigli, private Sito $sito, private TestoPdf $testo, private string $cartella = Costanti::DIR_VERBALI)
    {
    }

    /** Anno accademico («2025/2026») di una data «aaaa-mm-gg»: da settembre in poi è quello che inizia in quell'anno. */
    public static function annoAccademico(string $data): string
    {
        $t = strtotime($data);
        if (!$t) {
            return '';
        }
        $a = (int) date('Y', $t) - ((int) date('n', $t) < 9 ? 1 : 0);

        return $a . '/' . ($a + 1);
    }

    private static function dataValida(int $g, int $m, int $a): ?string
    {
        return $a >= 1990 && $a <= 2100 && checkdate($m, $g, $a) ? sprintf('%04d-%02d-%02d', $a, $m, $g) : null;
    }

    /** La data («aaaa-mm-gg») scritta in un testo (nome del file o inizio del verbale), null se non se ne trova una valida. */
    public static function dataDa(string $s): ?string
    {
        if (preg_match('/(?<!\d)(20\d{2})[-_.\/ ](\d{1,2})[-_.\/ ](\d{1,2})(?!\d)/', $s, $m) && ($d = self::dataValida((int) $m[3], (int) $m[2], (int) $m[1]))) {
            return $d;
        }
        if (preg_match('/(?<!\d)(\d{1,2})[-_.\/ ](\d{1,2})[-_.\/ ](20\d{2})(?!\d)/', $s, $m) && ($d = self::dataValida((int) $m[1], (int) $m[2], (int) $m[3]))) {
            return $d;
        }
        if (preg_match('/(?<!\d)(\d{1,2})[-_.\/](\d{1,2})[-_.\/](\d{2})(?!\d)/', $s, $m) && ($d = self::dataValida((int) $m[1], (int) $m[2], 2000 + (int) $m[3]))) {
            return $d;
        }
        if (preg_match('/(?<!\d)(\d{2})(\d{2})(20\d{2})(?!\d)/', $s, $m) && ($d = self::dataValida((int) $m[1], (int) $m[2], (int) $m[3]))) {
            return $d;
        }
        if (preg_match('/(?<!\d)(20\d{2})(\d{2})(\d{2})(?!\d)/', $s, $m) && ($d = self::dataValida((int) $m[3], (int) $m[2], (int) $m[1]))) {
            return $d;
        }
        if (preg_match('/(?<!\d)(\d{1,2})\s*[°º]?(?:\s+del\s+mese\s+di|[\s_\-.]*)\s*(' . implode('|', array_keys(self::MESI)) . ')\.?[\s_\-.]*(20\d{2})(?!\d)/iu', $s, $m)
            && ($d = self::dataValida((int) $m[1], self::MESI[mb_strtolower($m[2])], (int) $m[3]))) {
            return $d;
        }

        return null;
    }

    /** Il numero del verbale scritto in un testo («Verbale n. 8», «verbale_8», «n°8»), null se non c'è. */
    public static function numeroDa(string $s): ?string
    {
        $fine = '(?![\d]|[\-_.\/]\d{1,2}[\-_.\/]\d)';
        foreach (['/verbale[\s_.\-]*(?:n(?:\.|°|º|r\.?)?[\s_.\-]*)?(\d{1,3})' . $fine . '/iu', '/(?:^|[\s_\-])n(?:\.|°|º)?[\s_.\-]*(\d{1,3})' . $fine . '/iu', '/^(\d{1,3})(?=[\s_\-.])' . $fine . '/u'] as $re) {
            if (preg_match($re, $s, $m)) {
                return (string) (int) $m[1];
            }
        }

        return null;
    }

    /**
     * Data, numero e anno accademico dal percorso del file (anche le cartelle: «2024-2025/Verbale_08_2025-01-20.pdf»).
     *
     * @return array{data: ?string, numero: ?string, anno_accademico: ?string}
     */
    public static function indovina(string $percorso): array
    {
        $percorso = str_replace('\\', '/', $percorso);
        $nome = pathinfo($percorso, PATHINFO_FILENAME);
        $aa = null;
        if (preg_match('#(?<!\d)(20\d{2})\s*[-_/]\s*(20\d{2}|\d{2})(?!\d)#', $percorso, $m)) {
            $fine = strlen($m[2]) === 2 ? substr($m[1], 0, 2) . $m[2] : $m[2];
            if ((int) $fine === (int) $m[1] + 1) {
                $aa = $m[1] . '/' . $fine;
            }
        }

        return ['data' => self::dataDa($nome), 'numero' => self::numeroDa($nome), 'anno_accademico' => $aa];
    }

    /**
     * Importa un PDF come verbale vecchio. Esiti: «ok» (creato; «completo» dice se data e organo ci sono), «doppio» (stesso file già in archivio), «errore».
     *
     * @return array{esito: string, id?: int, completo?: bool, messaggio: string, nome: string}
     */
    public function importa(string $tmp, string $nomeOriginale, string $percorso, ?int $consiglioId, string $organo, string $annoForzato = ''): array
    {
        $nome = $nomeOriginale !== '' ? $nomeOriginale : basename($percorso);
        $err = fn (string $m) => ['esito' => 'errore', 'messaggio' => $m, 'nome' => $nome];
        if (!is_file($tmp) || ($dim = filesize($tmp)) === false || $dim <= 0) {
            return $err('file vuoto o non arrivato');
        }
        if ($dim > self::MAX_BYTE) {
            return $err('più grande di 30 MB');
        }
        $pdf = (string) file_get_contents($tmp);
        if (!str_starts_with(ltrim(substr($pdf, 0, 16)), '%PDF')) {
            return $err('non è un PDF');
        }
        $hash = sha1($pdf);
        if ($gia = $this->sedute->perImpronta($hash)) {
            return ['esito' => 'doppio', 'id' => (int) $gia['id'], 'messaggio' => 'già in archivio', 'nome' => $nome];
        }
        $testo = $dim <= self::MAX_BYTE_TESTO ? $this->testo->estrai($pdf) : '';
        $g = self::indovina($percorso !== '' ? $percorso : $nome);
        $data = $g['data'] ?? self::dataDa(mb_substr($testo, 0, 6000));
        $numero = $g['numero'] ?? self::numeroDa(mb_substr($testo, 0, 3000));
        $aa = $annoForzato !== '' ? $annoForzato : ($g['anno_accademico'] ?? ($data ? self::annoAccademico($data) : ''));
        if ($consiglioId && ($c = $this->consigli->perId($consiglioId))) {
            $organo = (string) $c['nome'];
        } else {
            $consiglioId = null;
            $organo = mb_substr(trim($organo), 0, 500);
        }
        $dir = $this->sito->radice() . '/' . $this->cartella;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_file($dir . '.htaccess')) {
            @file_put_contents($dir . '.htaccess', "# Verbali: si scaricano solo dal pannello o con il link personale\nRequire all denied\n");
        }
        $file = 'archivio_' . ($data ? str_replace('-', '', $data) : 'senzadata') . '_' . bin2hex(random_bytes(6)) . '.pdf';
        if (@file_put_contents($dir . $file, $pdf) === false) {
            return $err('salvataggio non riuscito');
        }
        $id = $this->sedute->inserisciImportato([
            'organo' => $organo, 'anno_accademico' => $aa, 'data' => $data, 'consiglio_id' => $consiglioId, 'numero_verbale' => (string) $numero,
            'verbale_pdf' => $this->cartella . $file, 'nome_originale' => mb_substr($nome, 0, 255), 'file_hash' => $hash, 'testo_ricerca' => $testo,
        ]);

        return ['esito' => 'ok', 'id' => $id, 'completo' => $data !== null && $organo !== '', 'messaggio' => $testo === '' ? 'archiviato (testo del PDF non leggibile: la ricerca userà solo i dati)' : 'archiviato', 'nome' => $nome];
    }

    /** Sostituisce il PDF di un verbale importato (e ne rilegge il testo). Ritorna l'errore, null se va bene. */
    public function sostituisciFile(array $seduta, string $tmp, string $nomeOriginale): ?string
    {
        if (($seduta['origine'] ?? '') !== 'importato') {
            return 'Si può sostituire solo il PDF di un verbale importato.';
        }
        $pdf = (string) @file_get_contents($tmp);
        if (!str_starts_with(ltrim(substr($pdf, 0, 16)), '%PDF') || strlen($pdf) > self::MAX_BYTE) {
            return 'Scegli un PDF (fino a 30 MB).';
        }
        $hash = sha1($pdf);
        $gia = $this->sedute->perImpronta($hash);
        if ($gia && (int) $gia['id'] !== (int) $seduta['id']) {
            return 'Questo PDF è già in archivio (verbale del ' . ($gia['data'] ? date('d/m/Y', strtotime((string) $gia['data'])) : 'senza data') . ').';
        }
        $dir = $this->sito->radice() . '/' . $this->cartella;
        $file = 'archivio_' . ($seduta['data'] ? date('Ymd', strtotime((string) $seduta['data'])) : 'senzadata') . '_' . bin2hex(random_bytes(6)) . '.pdf';
        if (@file_put_contents($dir . $file, $pdf) === false) {
            return 'Salvataggio non riuscito.';
        }
        $vecchio = $this->percorsoFile($seduta);
        $this->sedute->sostituisciFileImportato((int) $seduta['id'], $this->cartella . $file, mb_substr($nomeOriginale, 0, 255), $hash, strlen($pdf) <= self::MAX_BYTE_TESTO ? $this->testo->estrai($pdf) : '');
        if ($vecchio) {
            @unlink($vecchio);
        }

        return null;
    }

    /** Dati (organo/consiglio, data, anno accademico, numero, o.d.g. sintetico) di un verbale importato, ripuliti. Ritorna l'errore, null se va bene. */
    public function salvaDati(int $id, array $post, array $consigli): ?string
    {
        $cons = (int) ($post['consiglio_id'] ?? 0);
        $organo = mb_substr(trim((string) ($post['organo'] ?? '')), 0, 500);
        if ($cons && isset($consigli[$cons])) {
            $organo = (string) $consigli[$cons]['nome'];
        } else {
            $cons = 0;
        }
        $data = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($post['data'] ?? '')) ? (string) $post['data'] : null;
        $aa = preg_match('#^\d{4}/\d{4}$#', (string) ($post['anno_accademico'] ?? '')) ? (string) $post['anno_accademico'] : ($data ? self::annoAccademico($data) : '');
        if ($organo === '') {
            return "Scegli il consiglio o scrivi l'organo.";
        }
        $this->sedute->aggiornaImportato($id, [
            'organo' => $organo, 'anno_accademico' => $aa, 'data' => $data, 'consiglio_id' => $cons ?: null,
            'numero_verbale' => mb_substr(trim((string) ($post['numero_verbale'] ?? '')), 0, 10), 'odg' => mb_substr(trim((string) ($post['odg'] ?? '')), 0, 5000),
        ]);

        return null;
    }

    /** Toglie un verbale importato dall'archivio (anche il file). */
    public function elimina(array $seduta): void
    {
        if (($seduta['origine'] ?? '') !== 'importato') {
            return;
        }
        $f = $this->percorsoFile($seduta);
        $this->sedute->eliminaImportato((int) $seduta['id']);
        if ($f) {
            @unlink($f);
        }
    }

    /** Percorso reale del PDF (solo dentro la cartella dei verbali). */
    public function percorsoFile(array $s): ?string
    {
        if (empty($s['verbale_pdf'])) {
            return null;
        }
        $base = realpath($this->sito->radice() . '/' . $this->cartella);
        $p = realpath($this->sito->radice() . '/' . $s['verbale_pdf']);

        return ($base && $p && strpos($p, $base . DIRECTORY_SEPARATOR) === 0 && is_file($p)) ? $p : null;
    }
}
