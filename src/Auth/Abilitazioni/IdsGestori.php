<?php

declare(strict_types=1);

namespace App\Auth\Abilitazioni;

/** Lettura degli ID dei gestori salvati su aree ed eventi (spostata da ids_gestori_da_campi() di inc/base.php). */
final class IdsGestori
{
    /**
     * Unisce gli ID gestore dai tre formati presenti nel DB: campo singolo, CSV legacy, JSON permessi (chiavi = ID utente).
     * admin/abilitazioni.php oggi salva SOLO nel JSON: leggere solo il CSV fa perdere i gestori.
     *
     * @return list<int>
     */
    public static function daCampi(mixed $singolo, mixed $csv, mixed $json): array
    {
        $ids = [];
        if ((int) $singolo > 0) {
            $ids[] = (int) $singolo;
        }
        foreach (explode(',', (string) $csv) as $v) {
            if ((int) trim($v) > 0) {
                $ids[] = (int) trim($v);
            }
        }
        $perm = json_decode((string) $json ?: '{}', true);
        if (is_array($perm)) {
            foreach (array_keys($perm) as $k) {
                if ((int) $k > 0) {
                    $ids[] = (int) $k;
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
