<?php

declare(strict_types=1);

namespace App\Tutorato;

/**
 * Compenso del tutor: 25 € lordi all'ora per l'Ente; nella lettera di incarico si scrive il compenso «al netto degli oneri a carico dell'ente»,
 * cioè il lordo diviso per 1,24. Se il tutor svolge meno ore il compenso si ricalcola sulle ore approvate.
 */
final class Compenso
{
    /** Costo orario lordo per l'Ente, in euro. */
    public const TARIFFA_ORARIA = 25.00;

    /** Il compenso in lettera è il lordo diviso per questo numero (oneri a carico dell'ente). */
    public const DIVISORE_ONERI = 1.24;

    /** Lordo per l'Ente: ore × tariffa. */
    public static function lordo(float $ore): float
    {
        return round($ore * self::TARIFFA_ORARIA, 2);
    }

    /** Compenso da scrivere nella lettera (al netto degli oneri): lordo ÷ 1,24. */
    public static function lettera(float $ore): float
    {
        return round(self::lordo($ore) / self::DIVISORE_ONERI, 2);
    }

    /** Un importo come si scrive in italiano: 1.000,00 */
    public static function testo(float $importo): string
    {
        return number_format($importo, 2, ',', '.');
    }

    /**
     * Le ore sono la stessa cosa a meno di un arrotondamento (le ore si scrivono al decimo).
     */
    public static function stesseOre(?float $a, ?float $b): bool
    {
        return $a !== null && $b !== null && abs($a - $b) < 0.05;
    }
}
