<?php

declare(strict_types=1);

namespace App\Portale;

/** Colori delle aree (card, badge, email): validazione e colore del testo leggibile su uno sfondo. */
final class Colori
{
    public const PREDEFINITO = '#B30000';

    /** Colore #RRGGBB sicuro da stampare negli attributi style (anche da #RGB); altrimenti $predefinito. */
    public static function valido(mixed $hex, string $predefinito = self::PREDEFINITO): string
    {
        $hex = trim((string) $hex);
        if (preg_match('/^#[0-9a-f]{6}$/i', $hex)) {
            return strtoupper($hex);
        }
        if (preg_match('/^#[0-9a-f]{3}$/i', $hex)) {
            return strtoupper('#' . $hex[1] . $hex[1] . $hex[2] . $hex[2] . $hex[3] . $hex[3]);
        }

        return $predefinito;
    }

    /** Testo leggibile su uno sfondo (contrasto WCAG): bianco o grigio quasi nero, quello con il contrasto più alto. */
    public static function testoSu(mixed $sfondo): string
    {
        $h = ltrim(self::valido($sfondo), '#');
        $lin = static function (int $c): float {
            $c /= 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $l = 0.2126 * $lin((int) hexdec(substr($h, 0, 2))) + 0.7152 * $lin((int) hexdec(substr($h, 2, 2))) + 0.0722 * $lin((int) hexdec(substr($h, 4, 2)));
        $contrastoBianco = 1.05 / ($l + 0.05);
        $contrastoScuro = ($l + 0.05) / (0.0216 + 0.05); // 0.0216 = luminanza di #1F2937

        return $contrastoBianco >= $contrastoScuro ? '#FFFFFF' : '#1F2937';
    }
}
