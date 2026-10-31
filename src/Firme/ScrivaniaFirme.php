<?php

declare(strict_types=1);

namespace App\Firme;

/**
 * La scrivania delle firme: tutti i documenti che aspettano la firma di una persona, da tutti i moduli, in un solo elenco (i più fermi in cima).
 * Non scrive niente e non duplica le regole dei moduli: ogni fonte decide da sola chi può firmare. Una fonte che non risponde (tabella mancante,
 * installazione non aggiornata) lascia il suo gruppo vuoto e l'errore va nel log.
 */
final class ScrivaniaFirme
{
    /** @var list<FonteFirme> */
    private array $fonti;

    public function __construct(
        FirmePratiche $pratiche,
        FirmeConvenzioniFsl $fsl,
        FirmeConvenzioniTesi $tesi,
        FirmeTutorato $tutorato,
        FirmeVerbali $verbali
    ) {
        $this->fonti = [$pratiche, $fsl, $tesi, $tutorato, $verbali];
    }

    /** @return list<FonteFirme> */
    public function fonti(): array
    {
        return $this->fonti;
    }

    /**
     * @param array<string, mixed>|null $utente
     * @return list<DocumentoDaFirmare>
     */
    public function documenti(?array $utente): array
    {
        if (!$utente || empty($utente['id'])) {
            return [];
        }
        $out = [];
        foreach ($this->fonti as $f) {
            try {
                array_push($out, ...$f->documenti($utente));
            } catch (\Throwable $e) {
                error_log('Scrivania delle firme (' . $f->tipo() . '): ' . $e->getMessage());
            }
        }
        usort($out, static fn (DocumentoDaFirmare $a, DocumentoDaFirmare $b): int => $b->giorni <=> $a->giorni);

        return $out;
    }

    /**
     * Un documento dell'elenco, riconosciuto da tipo e numero (per lo scarico in blocco e la ricarica dei firmati).
     *
     * @param list<DocumentoDaFirmare> $documenti
     */
    public static function trova(array $documenti, string $tipo, int $id): ?DocumentoDaFirmare
    {
        foreach ($documenti as $d) {
            if ($d->tipo === $tipo && $d->id === $id) {
                return $d;
            }
        }

        return null;
    }
}
