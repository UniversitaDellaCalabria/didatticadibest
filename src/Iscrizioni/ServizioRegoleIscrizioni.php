<?php

declare(strict_types=1);

namespace App\Iscrizioni;

use App\Eventi\RegoleIscrizioni;

/** Regole delle iscrizioni usate dagli eventi e dai progetti (modulo Eventi): posti occupati, limiti dei partecipanti, vincoli per area. */
final class ServizioRegoleIscrizioni implements RegoleIscrizioni
{
    public function __construct(private PrenotazioneRepository $prenotazioni, private ServizioVincoli $vincoli)
    {
    }

    public function postiOccupati(int $turnoId): int
    {
        return $this->prenotazioni->postiOccupati($turnoId);
    }

    public function limitiPartecipanti(?array $dettagli, ?array $turno = null): array
    {
        return LimitiPartecipanti::calcola($dettagli, $turno);
    }

    public function decadiAtteseVincolate(int $prenotazioneId): int
    {
        return $this->vincoli->decadiAttese($prenotazioneId);
    }
}
