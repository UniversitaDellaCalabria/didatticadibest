<?php

declare(strict_types=1);

namespace App\Firme;

/** Un documento che aspetta la firma di una persona, qualunque sia il modulo da cui arriva (pratica, convenzione, lettera di tutorato, verbale). */
final class DocumentoDaFirmare
{
    public function __construct(
        public readonly string $tipo,
        public readonly int $id,
        public readonly string $titolo,
        public readonly string $dettaglio,
        public readonly int $giorni,
        /** Pagina di firma già esistente, con il link personale: lì si firma o si carica il PDF firmato. */
        public readonly string $linkFirma,
        /** Percorso del PDF da firmare sul server, null se manca. */
        public readonly ?string $percorso,
        /** Nome univoco del file per lo scarico in blocco e l'abbinamento alla ricarica: <tipo>_<id>_<codice>.pdf */
        public readonly string $nomeFile,
        /** Registra il PDF firmato caricato (stessa logica della pagina di firma del modulo): torna il messaggio d'errore, null se la firma è registrata. */
        public readonly ?\Closure $registra = null,
    ) {
    }

    /** Chiave per abbinare un file al documento: la parte del nome fino al numero («tutorato-lettera_5»), qualunque cosa segua (codice, «_signed»…). */
    public static function chiaveDaNome(string $nomeFile): ?string
    {
        $base = (string) preg_replace('/\.pdf$/i', '', basename(str_replace('\\', '/', $nomeFile)));

        return preg_match('/^([a-z]+(?:_[a-z]+)*(?:-[a-z]+)?_\d+)(?:[_\- .].*)?$/i', $base, $m) ? strtolower($m[1]) : null;
    }

    public function chiave(): string
    {
        return (string) self::chiaveDaNome($this->nomeFile);
    }

    /** Anteprima del PDF: la stessa pagina di firma con ?pdf=1. */
    public function linkPdf(): string
    {
        return $this->linkFirma . '&pdf=1';
    }

    /** Nome file sicuro: solo lettere, cifre, trattini e sottolineature. */
    public static function nomeSicuro(string $tipo, int $id, string $codice): string
    {
        $c = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '', $codice));

        return $tipo . '_' . $id . ($c !== '' ? '_' . $c : '') . '.pdf';
    }
}
