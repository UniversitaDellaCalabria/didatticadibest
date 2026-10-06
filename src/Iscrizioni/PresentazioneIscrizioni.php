<?php

declare(strict_types=1);

namespace App\Iscrizioni;

use App\Eventi\PresentazioneComune;
use App\Portale\Colori;
use App\Portale\ColoriAree;

/** Colori e testi usati dalle viste degli eventi: i colori sono del modulo Portale, il testo dei posti liberi è delle iscrizioni. */
final class PresentazioneIscrizioni implements PresentazioneComune
{
    public function __construct(private ColoriAree $colori)
    {
    }

    public function coloreValido(mixed $hex, string $predefinito = '#B30000'): string
    {
        return Colori::valido($hex, $predefinito);
    }

    public function testoPostiLiberi(int $liberi): string
    {
        return ServizioDisponibilita::testoPostiLiberi($liberi);
    }

    public function coloreAreaTurno(int $turnoId): string
    {
        return $this->colori->delTurno($turnoId);
    }
}
