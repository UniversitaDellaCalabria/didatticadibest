<?php

declare(strict_types=1);

namespace App\Iscrizioni;

use App\Iscritti\Iscrizioni;

/** Posti, liste d'attesa e validazioni dei progetti per il pannello degli iscritti. */
final class IscrizioniPerIscritti implements Iscrizioni
{
    public function __construct(private PrenotazioneRepository $prenotazioni, private ServizioVincoli $vincoli, private ServizioListaAttesa $listaAttesa)
    {
    }

    public function postiOccupati(int $turnoId): int
    {
        return $this->prenotazioni->postiOccupati($turnoId);
    }

    public function decadiAtteseVincolate(int $prenotazioneId): int
    {
        return $this->vincoli->decadiAttese($prenotazioneId);
    }

    public function promuoviListaAttesa(int $turnoId): void
    {
        $this->listaAttesa->promuovi($turnoId);
    }

    public function validaPartecipantiProgetto(array $custom, ?array $dettagli, ?array $turno = null): ?string
    {
        return LimitiPartecipanti::valida($custom, $dettagli, $turno);
    }
}
