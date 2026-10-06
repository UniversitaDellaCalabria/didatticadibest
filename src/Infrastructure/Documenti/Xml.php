<?php

declare(strict_types=1);

namespace App\Infrastructure\Documenti;

/** Testo sicuro dentro i file XML di Office (Word, Excel): toglie i caratteri di controllo non ammessi e fa l'escape. */
final class Xml
{
    public static function testo(mixed $s): string
    {
    $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', (string)$s) ?? '';
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}
}
