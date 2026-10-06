<?php

declare(strict_types=1);

namespace App\Eventi;

/**
 * Le query spostate da $conn->query() (protocollo testuale di MySQL: ogni valore è una stringa, NULL resta null) passano
 * ora da prepared statement, che restituisce interi e decimali come numeri. Chi usava quei risultati (confronti, JSON,
 * array_search…) riceve con questi metodi gli stessi tipi di prima.
 */
final class Righe
{
    /**
     * @param list<array<string, mixed>> $righe
     * @return list<array<string, string|null>>
     */
    public static function testo(array $righe): array
    {
        return array_map(self::riga(...), $righe);
    }

    /**
     * @param array<string, mixed>|null $riga
     * @return ($riga is null ? null : array<string, string|null>)
     */
    public static function riga(?array $riga): ?array
    {
        if ($riga === null) {
            return null;
        }
        foreach ($riga as $k => $v) {
            if ($v !== null && !is_string($v)) {
                $riga[$k] = (string) $v;
            }
        }

        return $riga;
    }
}
