<?php

declare(strict_types=1);

namespace App\Eventi;

/** Aiuti di presentazione di altri moduli usati dalle viste degli eventi (colori delle aree, testo dei posti liberi). */
interface PresentazioneComune
{
    /** Colore esadecimale valido (#RRGGBB) o $predefinito, come colore_valido(). */
    public function coloreValido(mixed $hex, string $predefinito = '#B30000'): string;

    /** "12 posti liberi", "Posti disponibili" (senza limite) o '' (nessun posto), come testo_posti_liberi(). */
    public function testoPostiLiberi(int $liberi): string;

    /** Colore primario dell'area di un turno, come colore_area_turno(). */
    public function coloreAreaTurno(int $turnoId): string;
}
