<?php

declare(strict_types=1);

namespace Tests\Unit\Sondaggi;

use App\Sondaggi\ServizioSondaggi;
use App\Sondaggi\StatisticheSondaggio;
use App\Sondaggi\Vista\EsportaRisultati;
use PHPUnit\Framework\TestCase;

final class SondaggiUnitTest extends TestCase
{
    public function testCondizioneDellaDomanda(): void
    {
        self::assertSame('{"se_id":12,"se_val":"S\u00ec"}', ServizioSondaggi::condizioneJson(12, 'Sì'), 'come prima: json_encode senza JSON_UNESCAPED_UNICODE');
        self::assertSame('', ServizioSondaggi::condizioneJson(0, 'Sì'));
        self::assertSame('', ServizioSondaggi::condizioneJson(12, ''));
        self::assertSame('', ServizioSondaggi::condizioneJson(-1, 'x'));
    }

    public function testStatisticheVuote(): void
    {
        self::assertSame([], StatisticheSondaggio::calcola([]));
    }

    public function testStatistichePerTipoDiDomanda(): void
    {
        $r = static fn (int $d, string $tipo, ?string $risposta) => ['domanda_id' => (string) $d, 'tipo' => $tipo, 'testo_domanda' => "D$d", 'risposta' => $risposta];
        $s = StatisticheSondaggio::calcola([
            $r(1, 'rating', '5'), $r(1, 'rating', '2'),
            $r(2, 'nps', '11'), $r(2, 'nps', '0'), $r(2, 'nps', '7'),
            $r(3, 'matrice', '{"A":"5","B":"1"}'), $r(3, 'matrice', '{"A":"3"}'), $r(3, 'matrice', null), $r(3, 'matrice', '[]'),
            $r(4, 'checkboxes', '["x"]'), $r(4, 'checkboxes', '["x"]'), $r(5, 'select', ' a '), $r(6, 'textarea', 'ciao'), $r(6, 'date', null),
        ]);
        self::assertSame(['testo' => 'D1', 'tipo' => 'rating', 'totale_voti' => 2, 'somma_voti' => 7], array_intersect_key($s[1], array_flip(['testo', 'tipo', 'totale_voti', 'somma_voti'])));
        self::assertSame([3, 17], [$s[2]['totale_voti'], $s[2]['somma_voti']]);
        self::assertSame(1, $s[2]['distribuzione_nps'][10]);
        self::assertSame(1, $s[2]['distribuzione_nps'][0]);
        self::assertSame(1, $s[2]['distribuzione_nps'][7]);
        self::assertSame(['A' => ['somma' => 8, 'tot' => 2], 'B' => ['somma' => 1, 'tot' => 1]], $s[3]['conteggi_matrice']);
        self::assertSame(3, $s[3]['totale_voti'], 'conta anche il JSON vuoto (array)');
        self::assertSame(['["x"]' => 2], $s[4]['conteggi_opzioni']);
        self::assertSame(['a' => 1], $s[5]['conteggi_opzioni']);
        self::assertSame(['ciao', null], $s[6]['risposte']);
        self::assertSame(11, count($s[1]['distribuzione_nps']));
    }

    public function testEsportazioneHtmlPerExcel(): void
    {
        $domande = [10 => ['id' => '10', 'testo_domanda' => 'Stelle <1>', 'tipo' => 'rating'], 11 => ['id' => '11', 'testo_domanda' => 'Matrice', 'tipo' => 'matrice']];
        $html = EsportaRisultati::html($domande, [
            '2026-09-12 10:00:00' => [10 => '5', 11 => '{"Org":"4","Doc":"3"}'],
            '2026-09-11 10:00:00' => [11 => 'testo & non json'],
            '2026-09-10 10:00:00' => [10 => null],
        ]);
        self::assertStringStartsWith('<html xmlns:o="urn:schemas-microsoft-com:office:office"', $html);
        self::assertStringContainsString('<tr><th style="background-color:#198754; color:white;">Data Compilazione</th><th style="background-color:#198754; color:white;">Stelle &lt;1&gt;</th>', $html);
        self::assertStringContainsString('<tr><td>2026-09-12 10:00:00</td><td>5</td><td>Org: 4/5 | Doc: 3/5</td></tr>', $html);
        self::assertStringContainsString('<tr><td>2026-09-11 10:00:00</td><td>N/D</td><td>testo &amp; non json</td></tr>', $html);
        self::assertStringContainsString('<tr><td>2026-09-10 10:00:00</td><td>N/D</td><td>N/D</td></tr>', $html);
        self::assertStringEndsWith('</table></body></html>', $html);
        self::assertSame('<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40"><head><meta charset="utf-8"></head><body><table border="1"><tr><th style="background-color:#198754; color:white;">Data Compilazione</th></tr></table></body></html>', EsportaRisultati::html([], []));
    }
}
