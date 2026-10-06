<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Auth\Abilitazioni\IdsGestori;
use App\Auth\Abilitazioni\Perimetri;
use PHPUnit\Framework\TestCase;

final class PerimetriTest extends TestCase
{
    public function testDaModuloIgnoraValoriNonAmmessi(): void
    {
        $p = Perimetri::daModulo(['area_perimetro' => 'qualcosa', 'fsl_perimetro' => 'tutto', 'moduli' => ['didattica', 'hack']]);
        self::assertSame('nessuno', $p['area']);
        self::assertSame('tutto', $p['fsl']);
        self::assertSame(['didattica'], $p['moduli']);
    }

    public function testParzialeSenzaNullaSiAzzera(): void
    {
        self::assertSame('nessuno', Perimetri::daModulo(['area_perimetro' => 'parziale'])['area']);
        self::assertSame('nessuno', Perimetri::daModulo(['fsl_perimetro' => 'parziale'])['fsl']);
        $p = Perimetri::daModulo(['area_perimetro' => 'parziale', 'area_tipi' => ['eventi', 'x'], 'attivita' => ['3', '0', '3', '7']]);
        self::assertSame(['eventi'], $p['tipi']);
        self::assertSame([3, 7], $p['attivita']);
    }

    public function testAmbitiETesto(): void
    {
        $p = ['area' => 'area', 'tipi' => [], 'attivita' => [5], 'fsl' => 'parziale', 'fsl_parti' => ['fsl_scuole'], 'moduli' => ['calendari']];
        self::assertSame([['area', 9, []], ['attivita', 9, [5]], ['fsl_scuole', 0, []], ['modulo_calendari', 0, []]], Perimetri::ambiti($p, 9));
        self::assertSame("tutta l'area, 1 attività (Open day), anagrafe scuole, modulo Prenotazioni", Perimetri::testo($p, [5 => 'Open day'], ['calendari' => ['nome' => 'Prenotazioni']]));
        self::assertSame('nessuna abilitazione', Perimetri::testo(Perimetri::VUOTO));
    }

    public function testIdsGestoriUniscePiuFormati(): void
    {
        self::assertSame([4, 7, 9, 12], IdsGestori::daCampi(4, '7, 9,x', '{"9":["full"],"12":["eventi"]}'));
        self::assertSame([], IdsGestori::daCampi(0, '', ''));
    }
}
