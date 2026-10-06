<?php

declare(strict_types=1);

namespace App\Eventi;

/** Etichette, orari e finestre di prenotazione dei turni (nome, data e orari sono tutti facoltativi: almeno nome o data). */
final class Turni
{
    /** «09:30–11:00», «09:30» o '' se il turno non ha orario. */
    public static function orario(array $t): string
    {
        if (empty($t['orario_inizio'])) {
            return '';
        }

        return substr((string) $t['orario_inizio'], 0, 5) . (!empty($t['orario_fine']) ? '–' . substr((string) $t['orario_fine'], 0, 5) : '');
    }

    /** Testo semplice (da passare a htmlspecialchars): «Gruppo 1 · 22/09/2026 · 09:30–11:00». */
    public static function etichetta(array $t): string
    {
        $parti = [];
        if (!empty($t['nome_turno'])) {
            $parti[] = $t['nome_turno'];
        }
        if (!empty($t['data_turno'])) {
            $parti[] = date('d/m/Y', (int) strtotime((string) $t['data_turno']));
        }
        $ora = self::orario($t);
        if ($ora !== '') {
            $parti[] = $ora;
        }

        return $parti ? implode(' · ', $parti) : 'Turno';
    }

    /** Un turno senza data non scade mai. */
    public static function concluso(array $t): bool
    {
        if (empty($t['data_turno'])) {
            return false;
        }
        $fine = $t['data_turno'] . ' ' . (!empty($t['orario_fine']) ? substr((string) $t['orario_fine'], 0, 8) : '23:59:59');

        return date('Y-m-d H:i:s') > $fine;
    }

    /**
     * Scadenza/apertura delle prenotazioni di un turno, o di un insieme di turni (card dell'evento):
     * tra i turni non conclusi, se qualcuno è prenotabile ora -> la chiusura più vicina («Prenota entro…»),
     * altrimenti l'apertura più vicina («Prenotazioni dal…»), altrimenti «Prenotazioni chiuse».
     *
     * @return array{testo: string, icona: string, bg: string, fg: string}|null null se non c'è nulla da dire (nessuna data impostata)
     */
    public static function finestraPrenotazione(array $turni): ?array
    {
        $ora = date('Y-m-d H:i:s');
        $aperti = 0;
        $chiusura = null;
        $apertura = null;
        $chiusi = 0;
        $attivi = 0;
        foreach ($turni as $t) {
            if (self::concluso($t)) {
                continue;
            }
            ++$attivi;
            if (!empty($t['data_apertura']) && $ora < $t['data_apertura']) {
                if ($apertura === null || $t['data_apertura'] < $apertura) {
                    $apertura = $t['data_apertura'];
                }
                continue;
            }
            if (!empty($t['data_chiusura']) && $ora > $t['data_chiusura']) {
                ++$chiusi;
                continue;
            }
            ++$aperti;
            if (!empty($t['data_chiusura']) && ($chiusura === null || $t['data_chiusura'] < $chiusura)) {
                $chiusura = $t['data_chiusura'];
            }
        }
        $fmt = static fn ($d): string => date('d/m/Y', (int) strtotime((string) $d)) . ' alle ' . date('H:i', (int) strtotime((string) $d));
        if ($aperti > 0 && $chiusura !== null) {
            $urgente = strtotime((string) $chiusura) - time() < 48 * 3600;

            return ['testo' => 'Prenota entro il ' . $fmt($chiusura), 'icona' => 'fa-hourglass-half', 'bg' => $urgente ? '#FEE2E2' : '#FEF3C7', 'fg' => $urgente ? '#991B1B' : '#92400E'];
        }
        if ($aperti > 0) {
            return null;
        }
        if ($apertura !== null) {
            return ['testo' => 'Prenotazioni dal ' . $fmt($apertura), 'icona' => 'fa-door-open', 'bg' => '#DBEAFE', 'fg' => '#1E40AF'];
        }
        if ($attivi > 0 && $chiusi === $attivi) {
            return ['testo' => 'Prenotazioni chiuse', 'icona' => 'fa-lock', 'bg' => '#F1F5F9', 'fg' => '#334155'];
        }

        return null;
    }

    /** Indirizzo «Aggiungi a Google Calendar» di un turno. */
    public static function urlGoogleCalendar(mixed $titolo, mixed $dataTurno, mixed $oraInizio, mixed $oraFine, mixed $luogo, mixed $dettagli): string
    {
        $st = date('Ymd\THis', (int) strtotime($dataTurno . ' ' . $oraInizio));
        $et = date('Ymd\THis', (int) strtotime($dataTurno . ' ' . $oraFine));

        return 'https://calendar.google.com/calendar/render?action=TEMPLATE&text=' . urlencode((string) $titolo) . '&dates=' . $st . '/' . $et . '&details=' . urlencode((string) $dettagli) . '&location=' . urlencode((string) $luogo);
    }
}
