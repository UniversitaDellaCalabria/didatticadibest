<?php

declare(strict_types=1);

namespace App\Fsl;

/** Esito della registrazione o modifica di una convenzione dal pannello. */
final class EsitoRegistro
{
    /**
     * @param int $id id della convenzione (se non salvata: quella che si voleva modificare, -1 se non esiste)
     * @param int $prenotazioniAggiornate prenotazioni della scuola coperte dalla convenzione
     * @param bool $modifica true se era una modifica di una convenzione esistente
     * @param list<string> $fileNonCaricati documenti scartati perché non PDF firmati in PAdES
     */
    public function __construct(
        public readonly bool $salvata,
        public readonly int $id,
        public readonly int $prenotazioniAggiornate,
        public readonly bool $modifica,
        public readonly array $fileNonCaricati,
        public readonly string $codiceScuola
    ) {
    }
}
