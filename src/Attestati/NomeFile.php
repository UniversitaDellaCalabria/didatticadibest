<?php

declare(strict_types=1);

namespace App\Attestati;

/** Nomi di file sicuri (attestato_cognome_nome.pdf). */
final class NomeFile
{
    /** Testo adatto a un nome di file: minuscole, senza accenti né apostrofi, parole separate da _ */
    public static function slug(string $s): string
    {
        $s = strtr(mb_strtolower(trim($s)), ['à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n', 'ß' => 'ss', "'" => '', '’' => '']);

        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $s), '_');
    }
}
