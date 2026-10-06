<?php

declare(strict_types=1);

namespace App\Risorse;

/** Testi di una prenotazione di risorsa: quando e file .ics. */
final class Prenotazioni
{
    /** «Martedì 10/11/2026, 09:00–11:00». */
    public static function quando(array $p): string
    {
        $i = (int) strtotime((string) $p['inizio']);
        $f = (int) strtotime((string) $p['fine']);

        return Costanti::GIORNI[(int) date('N', $i)] . ' ' . date('d/m/Y', $i) . ', ' . date('H:i', $i) . '–' . date('H:i', $f);
    }

    /** File .ics della prenotazione. */
    public static function ics(array $p): string
    {
        $e = static fn ($s): string => str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\\,', '\\n'], (string) $s);
        $utc = static fn ($t): string => gmdate('Ymd\THis\Z', (int) strtotime((string) $t));

        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Didattica DiBEST//IT\r\nCALSCALE:GREGORIAN\r\nBEGIN:VEVENT\r\n"
             . 'UID:' . $e($p['codice']) . "@didattica-dibest\r\nDTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n"
             . 'DTSTART:' . $utc($p['inizio']) . "\r\nDTEND:" . $utc($p['fine']) . "\r\n"
             . 'SUMMARY:' . $e($p['risorsa_nome']) . "\r\n" . ($p['luogo'] !== '' ? 'LOCATION:' . $e($p['luogo']) . "\r\n" : '')
             . 'DESCRIPTION:' . $e('Prenotazione ' . $p['codice'] . ($p['motivo'] !== '' ? ' – ' . $p['motivo'] : '')) . "\r\n"
             . 'STATUS:' . ($p['stato'] === 'confermata' ? 'CONFIRMED' : 'TENTATIVE') . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    }
}
