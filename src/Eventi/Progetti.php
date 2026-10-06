<?php

declare(strict_types=1);

namespace App\Eventi;

/** Periodo e stato di un progetto (un evento con tipo «progetto», scheda in progetti_dettagli, un turno per edizione). */
final class Progetti
{
    /** «Dal 13/10/2026 al 18/12/2026», «Dal 13/10/2026», «Entro il 18/12/2026» oppure «Date da definire». */
    public static function periodo(?array $d): string
    {
        $ini = !empty($d['data_inizio']) ? date('d/m/Y', (int) strtotime((string) $d['data_inizio'])) : '';
        $fin = !empty($d['data_fine']) ? date('d/m/Y', (int) strtotime((string) $d['data_fine'])) : '';
        if ($ini && $fin) {
            return $ini === $fin ? "Il $ini" : "Dal $ini al $fin";
        }
        if ($ini) {
            return "Dal $ini";
        }
        if ($fin) {
            return "Entro il $fin";
        }

        return 'Date da definire';
    }

    /**
     * Stato calcolato dalle date del progetto e dal turno di iscrizione. $occupati = posti occupati del turno (0 o 1).
     *
     * @return array{codice: string, etichetta: string, bg: string, fg: string, ordine: int}
     */
    public static function stato(?array $d, ?array $turno, int $occupati): array
    {
        $oggi = date('Y-m-d');
        $ora = date('Y-m-d H:i:s');
        $stati = [
            'aperte' => ['Iscrizioni aperte', '#DCFCE7', '#166534', 1],
            'attesa' => ["Assegnato · lista d'attesa aperta", '#FEF3C7', '#92400E', 2],
            'arrivo' => ['Iscrizioni in arrivo', '#DBEAFE', '#1E40AF', 3],
            'chiuse' => ['Iscrizioni chiuse', '#F1F5F9', '#334155', 4],
            'in_corso' => ['In corso', '#EDE9FE', '#5B21B6', 5],
            'concluso' => ['Concluso', '#E5E7EB', '#374151', 6],
        ];
        $iniziato = !empty($d['data_inizio']) && $d['data_inizio'] <= $oggi;
        if (!empty($d['data_fine']) && $d['data_fine'] < $oggi) {
            $c = 'concluso';
        } elseif (!$turno) {
            $c = $iniziato ? 'in_corso' : 'chiuse';
        } elseif (!empty($turno['data_apertura']) && $ora < $turno['data_apertura']) {
            $c = 'arrivo';
        } elseif (!empty($turno['data_chiusura']) && $ora > $turno['data_chiusura']) {
            $c = $iniziato ? 'in_corso' : 'chiuse';
        } elseif ($occupati < (int) $turno['max_posti']) {
            $c = 'aperte';
        } elseif (!empty($turno['abilita_lista_attesa'])) {
            $c = 'attesa';
        } else {
            $c = $iniziato ? 'in_corso' : 'chiuse';
        }
        [$et, $bg, $fg, $ord] = $stati[$c];

        return ['codice' => $c, 'etichetta' => $et, 'bg' => $bg, 'fg' => $fg, 'ordine' => $ord];
    }
}
