<?php

declare(strict_types=1);

namespace App\Risorse;

/** Periodo mostrato dalla vista a calendario (giorno, settimana o mese). */
final class CalendarioRisorse
{
    /**
     * Giorni da mostrare: vista 'giorno' | 'settimana' | 'mese' e data di riferimento → [primo giorno, ultimo giorno] (Y-m-d).
     *
     * @return array{0: string, 1: string}
     */
    public static function periodo(string $vista, string $data): array
    {
        $t = strtotime($data) ?: time();
        if ($vista === 'mese') {
            return [date('Y-m-01', $t), date('Y-m-t', $t)];
        }
        if ($vista === 'settimana') {
            $lun = strtotime('monday this week', $t);

            return [date('Y-m-d', $lun), date('Y-m-d', strtotime('+6 days', $lun))];
        }

        return [date('Y-m-d', $t), date('Y-m-d', $t)];
    }
}
