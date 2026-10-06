<?php

declare(strict_types=1);

namespace App\Sistema;

/** Elenchi di indirizzi email scritti a mano (impostazioni, .env): spostato da normalizza_lista_email() di inc/base.php. */
final class ListaEmail
{
    /**
     * Testo libero (separatori: virgola, punto e virgola, spazi, a capo) -> indirizzi validi, minuscoli, senza doppioni.
     * $scartati riceve gli indirizzi non validi, per poterli segnalare all'admin.
     *
     * @param list<string>|null $scartati
     * @param-out list<string> $scartati
     * @return list<string>
     */
    public static function normalizza(?string $testo, int $max = 10, ?array &$scartati = null): array
    {
        $scartati = [];
        $validi = [];
        foreach (preg_split('/[\s,;]+/', (string) $testo, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $e) {
            $e = strtolower(trim($e));
            if (filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $validi[$e] = true;
            } else {
                $scartati[] = $e;
            }
        }

        return array_slice(array_map('strval', array_keys($validi)), 0, $max);
    }
}
