<?php

declare(strict_types=1);

namespace App\Attestati;

use App\Iscritti\Attestati;

/** Gli attestati per il pannello degli iscritti: invio alla presenza e per la classe, regola dell'evento. */
final class AttestatiPerIscritti implements Attestati
{
    public function __construct(private ServizioAttestati $attestati)
    {
    }

    public function inviaSeConcluso(int $prenotazioneId): bool
    {
        return $this->attestati->inviaSeConcluso($prenotazioneId);
    }

    public function inviaGruppo(int $prenotazioneId, bool $forza = false): bool|string
    {
        return $this->attestati->inviaGruppo($prenotazioneId, $forza);
    }

    public function regolaEvento(int $eventoId): string
    {
        return $this->attestati->regola($eventoId)->value;
    }
}
