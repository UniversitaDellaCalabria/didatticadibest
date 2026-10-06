<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Auth\Csrf;
use App\Auth\Flash;
use PHPUnit\Framework\TestCase;
use Tests\Doppi\AuthSessioneInMemoria;

final class CsrfFlashTest extends TestCase
{
    public function testIlTokenSiGeneraUnaVoltaEResta(): void
    {
        $s = new AuthSessioneInMemoria();
        $csrf = new Csrf($s);
        $t = $csrf->token();
        self::assertSame(64, strlen((string) $t));
        self::assertSame($t, $csrf->token());
        self::assertStringContainsString('name="csrf_token" value="' . $t . '"', $csrf->campo());
    }

    public function testValidoSoloConIlTokenDellaSessione(): void
    {
        $csrf = new Csrf(new AuthSessioneInMemoria());
        self::assertFalse($csrf->valido('x'), 'senza token in sessione non è valido');
        $t = (string) $csrf->token();
        self::assertTrue($csrf->valido($t));
        self::assertFalse($csrf->valido('altro'));
        self::assertFalse($csrf->valido(''));
        self::assertFalse($csrf->valido(null));
    }

    public function testFlashSiLeggeUnaVoltaSola(): void
    {
        $f = new Flash(new AuthSessioneInMemoria());
        self::assertNull($f->prendi());
        $f->imposta('Fatto');
        self::assertSame(['msg' => 'Fatto', 'type' => 'success'], $f->prendi());
        self::assertNull($f->prendi());
    }

    public function testFlashHtmlPerTipo(): void
    {
        $f = new Flash(new AuthSessioneInMemoria());
        self::assertSame('', $f->html());
        $f->imposta('<b>No</b>', 'danger');
        $html = $f->html();
        self::assertStringContainsString('alert-danger', $html);
        self::assertStringContainsString('fa-times-circle', $html);
        self::assertStringContainsString('&lt;b&gt;No&lt;/b&gt;', $html);
        $f->imposta('Attenzione', 'warning');
        self::assertStringContainsString('fa-exclamation-triangle', $f->html());
        $f->imposta('Info', 'info');
        self::assertStringContainsString('fa-info-circle', $f->html());
        $f->imposta('Ok', 'qualunque');
        self::assertStringContainsString('alert-success', $f->html());
    }
}
