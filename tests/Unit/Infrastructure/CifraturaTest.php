<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Infrastructure\Sicurezza\Cifratura;
use PHPUnit\Framework\TestCase;

/** Cifratura AES-256-GCM dei dati bancari: va e viene, non si legge senza chiave e si accorge delle alterazioni. */
final class CifraturaTest extends TestCase
{
    public function testCifraEDecifra(): void
    {
        $c = new Cifratura('una-frase-lunga-e-segreta');
        $this->assertTrue($c->disponibile());
        $x = $c->cifra('IT60X0542811101000000123456');
        $this->assertStringStartsWith('v1:', (string) $x);
        $this->assertStringNotContainsString('0542811', (string) $x);
        $this->assertNotSame($x, $c->cifra('IT60X0542811101000000123456'), 'ogni cifratura ha un vettore diverso');
        $this->assertSame('IT60X0542811101000000123456', $c->decifra((string) $x));
        $this->assertSame('', $c->decifra((string) $c->cifra('')));
    }

    public function testSenzaChiaveONonLaGiustaNonSiLegge(): void
    {
        $x = (string) (new Cifratura('una-frase-lunga-e-segreta'))->cifra('segreto');
        $this->assertFalse((new Cifratura(''))->disponibile());
        $this->assertFalse((new Cifratura('corta'))->disponibile());
        $this->assertNull((new Cifratura(''))->cifra('x'));
        $this->assertNull((new Cifratura('un-altra-frase-lunga-e-diversa'))->decifra($x));
        $this->assertNull((new Cifratura(null))->decifra($x));
    }

    public function testUnDatoAlteratoNonSiDecifra(): void
    {
        $c = new Cifratura('una-frase-lunga-e-segreta');
        $x = (string) $c->cifra('segreto');
        $raw = (string) base64_decode(substr($x, 3));
        $alterato = 'v1:' . base64_encode(substr($raw, 0, -1) . chr(ord($raw[strlen($raw) - 1]) ^ 1));
        $this->assertNull($c->decifra($alterato));
        $this->assertNull($c->decifra('v1:non-base64!!'));
        $this->assertNull($c->decifra('v1:'));
        $this->assertNull($c->decifra('testo in chiaro'));
    }
}
