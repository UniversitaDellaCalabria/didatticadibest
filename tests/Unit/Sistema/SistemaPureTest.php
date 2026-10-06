<?php

declare(strict_types=1);

namespace Tests\Unit\Sistema;

use App\Sistema\AccessoCron;
use App\Sistema\FileEnv;
use App\Sistema\Html;
use App\Sistema\ListaEmail;
use PHPUnit\Framework\TestCase;

final class SistemaPureTest extends TestCase
{
    public function testListaEmail(): void
    {
        $scartati = null;
        self::assertSame(['a@x.it', 'b@y.it'], ListaEmail::normalizza("A@x.it; b@y.it,a@x.it\nrotto", 10, $scartati));
        self::assertSame(['rotto'], $scartati);
        self::assertSame(['a@x.it'], ListaEmail::normalizza('a@x.it b@y.it', 1));
    }

    public function testHtmlEscapeENull(): void
    {
        self::assertSame('&lt;a href=&quot;x&quot;&gt;l&#039;uno&lt;/a&gt;', Html::h('<a href="x">l\'uno</a>'));
        self::assertSame('', Html::h(null));
    }

    public function testAccessoCron(): void
    {
        self::assertTrue(AccessoCron::chiaveValida(str_repeat('k', 16), str_repeat('k', 16)));
        self::assertFalse(AccessoCron::chiaveValida('corta', 'corta'), 'una chiave sotto i 16 caratteri non apre nulla');
        self::assertFalse(AccessoCron::chiaveValida(str_repeat('k', 16), 'altra'));
        self::assertTrue(AccessoCron::ruoloAmmesso([5, 1], [1]));
        self::assertFalse(AccessoCron::ruoloAmmesso([5, 2], [1]));
    }

    public function testFileEnv(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($f, "# commento\nA=uno\nB=\"con spazi!\"\nC=\nD='x!y=z'\n");
        $env = new FileEnv($f);
        self::assertSame('uno', $env->valore('A'));
        self::assertSame('con spazi!', $env->valore('B'));
        self::assertNull($env->valore('C'));
        self::assertSame('x!y=z', $env->valore('D'));
        self::assertNull($env->valore('Z'));
        unlink($f);
        self::assertNull((new FileEnv('/non/esiste'))->valore('A'));
    }
}
