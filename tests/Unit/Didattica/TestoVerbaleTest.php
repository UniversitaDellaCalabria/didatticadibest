<?php

declare(strict_types=1);

namespace Tests\Unit\Didattica;

use App\Didattica\ServizioPuntiSeduta;
use App\Didattica\TestoVerbale;
use PHPUnit\Framework\TestCase;

/** Il testo scritto per ogni punto del verbale (pulizia e conversione in Word) e i punti ricavati dall'ordine del giorno. */
final class TestoVerbaleTest extends TestCase
{
    public function testPuliziaTogliTagEAttributiNonAmmessi(): void
    {
        $html = TestoVerbale::pulisci('<p style="color:red" onclick="x()">Testo <strong>forte</strong></p><script>alert(1)</script><a href="http://x">link</a>');
        $this->assertSame('<p>Testo <strong>forte</strong></p>alert(1)link', $html);
        $this->assertSame('', TestoVerbale::pulisci('<p>&nbsp;</p><p><br></p>'));
        $this->assertSame('', TestoVerbale::pulisci('   '));
    }

    public function testInTesto(): void
    {
        $this->assertSame("Uno\nDue", TestoVerbale::inTesto('<p>Uno</p><p>Due</p>'));
    }

    public function testParagrafiEElenchiInWord(): void
    {
        $xml = TestoVerbale::inWord('<p>Il <strong>Coordinatore</strong> &amp; il <em>Segretario</em></p><ul><li>Primo</li></ul><ol><li>Uno</li><li>Due</li></ol>');
        $this->assertNotFalse(simplexml_load_string('<w:d xmlns:w="w">' . $xml . '</w:d>'));
        $this->assertSame(4, substr_count($xml, '<w:p>'));
        $this->assertStringContainsString('<w:b/>', $xml);
        $this->assertStringContainsString('<w:i/>', $xml);
        $this->assertStringContainsString('&amp;', $xml);
        $this->assertStringContainsString('•', $xml);
        $this->assertStringContainsString('2.', $xml);
        $this->assertSame('', TestoVerbale::inWord('<p></p>'));
    }

    public function testTitoliDallOdg(): void
    {
        $this->assertSame(['Comunicazioni', 'Pratiche studenti', 'Varie'], ServizioPuntiSeduta::titoli("1. Comunicazioni\n\n2) Pratiche studenti\n  Varie  "));
        $this->assertSame('pratiche', ServizioPuntiSeduta::tipoPerTitolo('Pratiche studenti'));
        $this->assertSame('testo', ServizioPuntiSeduta::tipoPerTitolo('Orario lezioni'));
    }

    public function testPraticheDeiPunti(): void
    {
        $punti = [['id' => 1, 'tipo' => 'testo'], ['id' => 2, 'tipo' => 'pratiche'], ['id' => 3, 'tipo' => 'pratiche']];
        $r = ServizioPuntiSeduta::praticheDeiPunti($punti, [['id' => 10, 'punto_id' => null], ['id' => 11, 'punto_id' => 3], ['id' => 12, 'punto_id' => 1]]);
        $this->assertSame([10, 12], array_column($r[2], 'id'), 'senza punto (o con un punto non valido) vanno al primo punto delle pratiche');
        $this->assertSame([11], array_column($r[3], 'id'));
        $r = ServizioPuntiSeduta::praticheDeiPunti([['id' => 1, 'tipo' => 'testo']], [['id' => 10]]);
        $this->assertSame([10], array_column($r[0], 'id'), 'senza punti delle pratiche restano a parte');
    }
}
