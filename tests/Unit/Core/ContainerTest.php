<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Container;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ContainerTest extends TestCase
{
    public function testRegistraECondivideLeIstanze(): void
    {
        $c = new Container();
        $c->set(Orologio::class, static fn (): Orologio => new Orologio('2026-01-01'));

        self::assertSame('2026-01-01', $c->get(Orologio::class)->oggi);
        self::assertSame($c->get(Orologio::class), $c->get(Orologio::class));
    }

    public function testAutowiringDeiCostruttori(): void
    {
        $c = new Container();
        $c->istanza(Orologio::class, new Orologio('2026-10-05'));

        $servizio = $c->get(Promemoria::class);

        self::assertInstanceOf(Promemoria::class, $servizio);
        self::assertSame('2026-10-05', $servizio->data());
    }

    public function testParametroScalareSenzaPredefinitoNonRisolvibile(): void
    {
        $this->expectException(RuntimeException::class);
        (new Container())->get(Orologio::class);
    }

    public function testDipendenzaCircolareRiconosciuta(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('circolare');
        (new Container())->get(CircolareA::class);
    }
}

final class Orologio
{
    public function __construct(public string $oggi)
    {
    }
}

final class Promemoria
{
    public function __construct(private Orologio $orologio, private int $giorni = 7)
    {
    }

    public function data(): string
    {
        return $this->orologio->oggi;
    }
}

final class CircolareA
{
    public function __construct(public CircolareB $b)
    {
    }
}

final class CircolareB
{
    public function __construct(public CircolareA $a)
    {
    }
}
