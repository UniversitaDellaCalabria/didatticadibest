<?php

declare(strict_types=1);

namespace App\Fsl;

/** Risultato della conferma del programma FSL: errori che hanno impedito tutto, esito di ogni attività e link ai documenti. */
final readonly class EsitoProgramma
{
    /**
     * @param list<string> $errori motivi per cui non è stato prenotato nulla (dati mancanti o non validi)
     * @param list<array{evento_id: int, titolo: string, turno: string, esito: string, codice: string|null, messaggio: string|null}> $voci
     *        esito: 'confermata', 'da_approvare', 'attesa' (lista d'attesa), 'convenzione' (in attesa della convenzione) o 'errore'
     * @param string|null $token link personale della convenzione online con l'Allegato A
     * @param string $convenzione 'si', 'no' o 'rinnovo'
     */
    public function __construct(
        public array $errori = [],
        public array $voci = [],
        public ?string $token = null,
        public string $convenzione = 'si',
    ) {
    }

    /** Almeno un'attività è stata prenotata (anche in lista d'attesa). */
    public function prenotato(): bool
    {
        foreach ($this->voci as $v) {
            if ($v['esito'] !== 'errore') {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{evento_id: int, titolo: string, turno: string, esito: string, codice: string|null, messaggio: string|null}> */
    public function inErrore(): array
    {
        return array_values(array_filter($this->voci, static fn (array $v): bool => $v['esito'] === 'errore'));
    }

    public function inListaAttesa(): int
    {
        return count(array_filter($this->voci, static fn (array $v): bool => $v['esito'] === 'attesa'));
    }
}
