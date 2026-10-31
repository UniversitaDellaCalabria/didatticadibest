<?php

declare(strict_types=1);

namespace App\Firme;

use App\Core\Database;
use App\Infrastructure\Audit\AuditLog;

/**
 * La ricarica dei PDF firmati sul proprio computer, uno o tanti (anche in uno ZIP). Ogni file si abbina al documento dal nome che aveva nello ZIP scaricato
 * (<tipo>_<id>_<codice>.pdf, anche con un suffisso come «_signed») e si registra con la stessa logica della pagina di firma del suo modulo, che verifica
 * che sia lo stesso PDF con una firma PAdES in più. Un file sbagliato non ferma gli altri: l'esito è file per file.
 */
final class RicaricaFirmati
{
    public const MAX_FILE = 30 * 1024 * 1024;
    public const MAX_FILE_TOTALI = 100;

    public function __construct(private Database $db)
    {
    }

    /**
     * Apre i file caricati: i PDF restano così, gli ZIP si aprono nei loro PDF.
     *
     * @param list<array{nome: string, percorso: string}> $caricati nome originale e file temporaneo
     * @return list<array{nome: string, dati: string|null, errore: string|null}>
     */
    public function espandi(array $caricati): array
    {
        $out = [];
        foreach ($caricati as $c) {
            $nome = basename(str_replace(chr(92), '/', $c['nome']));
            if (strtolower(pathinfo($nome, PATHINFO_EXTENSION)) === 'zip') {
                array_push($out, ...$this->apriZip($c['percorso']));
            } elseif (strtolower(pathinfo($nome, PATHINFO_EXTENSION)) === 'pdf') {
                $out[] = $this->leggi($nome, $c['percorso']);
            } else {
                $out[] = ['nome' => $nome, 'dati' => null, 'errore' => 'Formato non accettato: carica i PDF firmati in PAdES (anche in uno ZIP), non i file .p7m.'];
            }
        }

        return array_slice($out, 0, self::MAX_FILE_TOTALI);
    }

    /**
     * Registra i file firmati sui documenti che aspettano la firma della persona.
     *
     * @param list<DocumentoDaFirmare> $documenti l'elenco attuale della persona (la scrivania)
     * @param list<array{nome: string, dati: string|null, errore: string|null}> $file
     * @return list<array{file: string, ok: bool, titolo: string, messaggio: string}>
     */
    public function registra(array $documenti, array $file, int $utenteId): array
    {
        $perChiave = [];
        foreach ($documenti as $d) {
            $perChiave[$d->chiave()] = $d;
        }
        $fatti = [];
        $esiti = [];
        foreach ($file as $f) {
            if ($f['errore'] !== null || $f['dati'] === null) {
                $esiti[] = ['file' => $f['nome'], 'ok' => false, 'titolo' => '', 'messaggio' => (string) $f['errore']];
                continue;
            }
            $chiave = DocumentoDaFirmare::chiaveDaNome($f['nome']);
            $d = $chiave !== null ? ($perChiave[$chiave] ?? null) : null;
            if ($chiave === null) {
                $esiti[] = ['file' => $f['nome'], 'ok' => false, 'titolo' => '', 'messaggio' => 'Il nome del file non dice di quale documento si tratta: ricarica i file con i nomi dello ZIP scaricato (si può aggiungere un suffisso, ma non cambiare l\'inizio).'];
            } elseif ($d === null || $d->registra === null) {
                $esiti[] = ['file' => $f['nome'], 'ok' => false, 'titolo' => '', 'messaggio' => 'Non è tra i documenti che aspettano la tua firma (forse è già stato firmato).'];
            } elseif (isset($fatti[$chiave])) {
                $esiti[] = ['file' => $f['nome'], 'ok' => false, 'titolo' => $d->titolo, 'messaggio' => 'Per questo documento hai già caricato un altro file.'];
            } elseif (!str_starts_with($f['dati'], '%PDF')) {
                $esiti[] = ['file' => $f['nome'], 'ok' => false, 'titolo' => $d->titolo, 'messaggio' => 'Non è un PDF.'];
            } else {
                try {
                    $err = ($d->registra)($f['dati']);
                } catch (\Throwable $e) {
                    error_log('Ricarica dei firmati (' . $d->tipo . ' ' . $d->id . '): ' . $e->getMessage());
                    $err = 'Errore imprevisto: riprova dalla pagina di firma del documento.';
                }
                $fatti[$chiave] = true;
                if ($err === null) {
                    $this->audit($utenteId, $d);
                }
                $esiti[] = ['file' => $f['nome'], 'ok' => $err === null, 'titolo' => $d->titolo, 'messaggio' => $err ?? 'Firma verificata e registrata.'];
            }
        }

        return $esiti;
    }

    private function audit(int $utenteId, DocumentoDaFirmare $d): void
    {
        try {
            (new AuditLog($this->db))->registra($utenteId, 'Firma caricata dalla scrivania delle firme', ['Tipo' => $d->tipo, 'Documento' => $d->id, 'Titolo' => $d->titolo], (string) ($_SERVER['REMOTE_ADDR'] ?? 'Sconosciuto'));
        } catch (\Throwable $e) {
            error_log('Ricarica dei firmati (audit): ' . $e->getMessage());
        }
    }

    /** @return array{nome: string, dati: string|null, errore: string|null} */
    private function leggi(string $nome, string $percorso): array
    {
        if (!is_file($percorso) || filesize($percorso) > self::MAX_FILE) {
            return ['nome' => $nome, 'dati' => null, 'errore' => 'Il file è vuoto o troppo grande (massimo 30 MB).'];
        }

        return ['nome' => $nome, 'dati' => (string) file_get_contents($percorso), 'errore' => null];
    }

    /** @return list<array{nome: string, dati: string|null, errore: string|null}> */
    private function apriZip(string $percorso): array
    {
        $zip = new \ZipArchive();
        if (!class_exists(\ZipArchive::class) || $zip->open($percorso) !== true) {
            return [['nome' => 'ZIP', 'dati' => null, 'errore' => 'Lo ZIP non si apre.']];
        }
        $out = [];
        for ($i = 0; $i < $zip->numFiles && count($out) < self::MAX_FILE_TOTALI; $i++) {
            $st = $zip->statIndex($i);
            $nome = basename(str_replace(chr(92), '/', (string) ($st['name'] ?? '')));
            if (!$st || str_ends_with((string) $st['name'], '/') || $nome === '' || str_starts_with($nome, '.') || str_starts_with((string) $st['name'], '__MACOSX/')) {
                continue;
            }
            if (strtolower(pathinfo($nome, PATHINFO_EXTENSION)) !== 'pdf') {
                $out[] = ['nome' => $nome, 'dati' => null, 'errore' => 'Nello ZIP ci sono solo PDF firmati in PAdES: questo file viene ignorato.'];
            } elseif ((int) $st['size'] > self::MAX_FILE) {
                $out[] = ['nome' => $nome, 'dati' => null, 'errore' => 'Il file è troppo grande (massimo 30 MB).'];
            } else {
                $out[] = ['nome' => $nome, 'dati' => (string) $zip->getFromIndex($i), 'errore' => null];
            }
        }
        $zip->close();

        return $out;
    }
}
