<?php

declare(strict_types=1);

namespace Tests\Unit\Portale;

use App\Portale\Vista\Pannello;
use PHPUnit\Framework\TestCase;

/** Componenti del pannello guidato: «Cosa devo fare», menu «Altro», menu «Scarica», riga «torna a». */
final class PannelloGuidatoTest extends TestCase
{
    public function testCompitiConNumeroHannoIlPulsanteEQuelliVuotiNo(): void
    {
        $html = Pannello::compiti([
            ['numero' => 2, 'titolo' => 'Da prendere in carico', 'testo' => 'Devi protocollarle.', 'href' => 'fsl.php?tab=iter#fase-caricata', 'bottone' => 'Apri e gestisci', 'tono' => 'tuo'],
            ['numero' => 0, 'titolo' => 'Dal Direttore', 'testo' => 'Si aspetta.', 'href' => 'x.php', 'tono' => 'attesa', 'vuoto' => 'Niente da fare.'],
        ]);
        $this->assertStringContainsString('pn-compito-tuo', $html);
        $this->assertStringContainsString('href="fsl.php?tab=iter#fase-caricata"', $html);
        $this->assertStringContainsString('Apri e gestisci', $html);
        $this->assertStringContainsString('pn-compito-zero', $html);
        $this->assertStringContainsString('Niente da fare.', $html);
        $this->assertStringNotContainsString('x.php', $html, 'una scheda a zero non ha pulsante');
    }

    public function testITestiVengonoRipuliti(): void
    {
        $html = Pannello::compiti([['numero' => 1, 'titolo' => '<b>x</b>', 'testo' => '"a" & <i>b</i>', 'href' => 'a.php?x=1&y=2']]);
        $this->assertStringNotContainsString('<b>x</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
        $this->assertStringContainsString('a.php?x=1&amp;y=2', $html);
    }

    public function testScaricaOffreExcelPdfECsv(): void
    {
        $html = Pannello::scarica('fsl.php?p_id=1&tab=convenzioni');
        foreach (['xlsx', 'pdf', 'csv'] as $f) {
            $this->assertStringContainsString('&amp;scarica=' . $f, $html);
        }
        $this->assertStringContainsString('?scarica=csv', Pannello::scarica('lista.php'));
    }

    public function testAltroSenzaVociNonMostraNulla(): void
    {
        $this->assertSame('', Pannello::altro([]));
        $this->assertStringContainsString('dropdown-menu', Pannello::altro(['<a class="dropdown-item" href="#">Una</a>']));
    }

    public function testIndietro(): void
    {
        $this->assertStringContainsString('Torna all&#039;elenco', Pannello::indietro('a.php', "Torna all'elenco"));
    }
}
