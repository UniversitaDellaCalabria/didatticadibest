<?php

declare(strict_types=1);

namespace Tests\Unit\Risorse;

use App\Risorse\CalendarioRisorse;
use App\Risorse\Costanti;
use App\Risorse\Prenotazioni;
use PHPUnit\Framework\TestCase;

final class RisorseUnitTest extends TestCase
{
    public function testPeriodoDelCalendario(): void
    {
        self::assertSame(['2026-10-14', '2026-10-14'], CalendarioRisorse::periodo('giorno', '2026-10-14'));
        self::assertSame(['2026-10-12', '2026-10-18'], CalendarioRisorse::periodo('settimana', '2026-10-14'));
        self::assertSame(['2026-10-12', '2026-10-18'], CalendarioRisorse::periodo('settimana', '2026-10-12'), 'il lunedì è il primo giorno');
        self::assertSame(['2026-10-12', '2026-10-18'], CalendarioRisorse::periodo('settimana', '2026-10-18'), 'la domenica è l\'ultimo');
        self::assertSame(['2026-02-01', '2026-02-28'], CalendarioRisorse::periodo('mese', '2026-02-10'));
        self::assertSame(['2028-02-01', '2028-02-29'], CalendarioRisorse::periodo('mese', '2028-02-10'));
        self::assertSame([date('Y-m-d'), date('Y-m-d')], CalendarioRisorse::periodo('giorno', 'non una data'));
    }

    public function testQuandoEFileIcs(): void
    {
        $p = ['inizio' => '2026-11-10 09:00:00', 'fine' => '2026-11-10 11:00:00', 'codice' => 'RS-ABCD1234', 'risorsa_nome' => 'Aula; 1, bis', 'luogo' => 'Cubo 4B', 'motivo' => 'Lezione', 'stato' => 'confermata'];
        self::assertSame('Martedì 10/11/2026, 09:00–11:00', Prenotazioni::quando($p));
        $ics = Prenotazioni::ics($p);
        self::assertStringStartsWith("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n", $ics);
        self::assertStringContainsString('UID:RS-ABCD1234@didattica-dibest', $ics);
        self::assertStringContainsString('SUMMARY:Aula\; 1\\, bis', $ics);
        self::assertStringContainsString('LOCATION:Cubo 4B', $ics);
        self::assertStringContainsString('DESCRIPTION:Prenotazione RS-ABCD1234 – Lezione', $ics);
        self::assertStringContainsString('STATUS:CONFIRMED', $ics);
        self::assertStringContainsString('STATUS:TENTATIVE', Prenotazioni::ics(['stato' => 'da_approvare', 'luogo' => '', 'motivo' => ''] + $p));
        self::assertStringNotContainsString('LOCATION', Prenotazioni::ics(['luogo' => ''] + $p));
        // le ore sono in UTC: 09:00 a Roma d'inverno sono le 08:00
        self::assertSame(gmdate('Ymd\THis\Z', strtotime('2026-11-10 09:00:00')), substr(explode("DTSTART:", $ics)[1], 0, 16));
    }

    public function testCostantiDelleRisorse(): void
    {
        self::assertSame(['aula', 'laboratorio', 'sportello', 'altro'], array_keys(Costanti::TIPI));
        self::assertSame('Domenica', Costanti::GIORNI[7]);
        self::assertSame(['libero', 'chiuso', 'occupato', 'mia', 'sospesa', 'passato'], array_keys(Costanti::LEGENDA_CALENDARIO));
        self::assertArrayHasKey('personale', Costanti::ACCESSI);
    }
}
