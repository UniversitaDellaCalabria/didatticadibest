<?php

declare(strict_types=1);

namespace Tests\Unit\Attestati;

use App\Attestati\DatiAttestato;
use App\Attestati\Vista\PaginaAttestati;
use PHPUnit\Framework\TestCase;

/** L'attestato della Formazione Scuola Lavoro: porta la scuola dello studente e ha una sua impaginazione. */
final class AttestatoFslTest extends TestCase
{
    public function testNomeDellaScuolaDaMaiuscoloAdIniziali(): void
    {
        self::assertSame('Liceo Scientifico Galilei', DatiAttestato::nomeScuola('LICEO SCIENTIFICO GALILEI'));
        self::assertSame('Istituto Tecnico di Castrovillari', DatiAttestato::nomeScuola('ISTITUTO TECNICO DI CASTROVILLARI'));
        self::assertSame('Liceo "G. Galilei"', DatiAttestato::nomeScuola('Liceo "G. Galilei"'), 'un nome già misto resta com\'è');
        self::assertSame('', DatiAttestato::nomeScuola('  '));
    }

    /** @return array<string, mixed> */
    private function prenotazione(array $extra = []): array
    {
        return $extra + ['evento_tipo' => 'progetto', 'evento_titolo' => 'Laboratorio di zoologia', 'evento_luogo' => 'Cubo 15B', 'ore_totali' => 20, 'data_inizio' => '2026-03-02', 'data_fine' => '2026-03-06',
                         'fsl' => 1, 'scuola_nome' => 'LICEO SCIENTIFICO GALILEI', 'scuola_comune' => 'COSENZA', 'nome_portale' => 'DiBEST', 'sottotitolo_portale' => 'Dipartimento'];
    }

    public function testDatiConLaScuolaSoloPerLaFsl(): void
    {
        $d = DatiAttestato::crea($this->prenotazione(), 'Luca Rossi', 'COD1');
        self::assertTrue($d['fsl']);
        self::assertSame(['Liceo Scientifico Galilei', 'Cosenza'], [$d['scuola'], $d['scuola_comune']]);
        $altro = DatiAttestato::crea($this->prenotazione(['fsl' => 0]), 'Luca Rossi', 'COD1');
        self::assertFalse($altro['fsl']);
        self::assertSame(['', ''], [$altro['scuola'], $altro['scuola_comune']], 'gli attestati degli eventi non cambiano');
        // scuola scritta a mano nella prenotazione
        $a_mano = DatiAttestato::crea($this->prenotazione(['scuola_nome' => null, 'scuola_comune' => null, 'dati_custom_json' => '{"scuola":"IIS Fermi – Rende"}']), 'Luca Rossi', 'COD1');
        self::assertSame(['IIS Fermi – Rende', ''], [$a_mano['scuola'], $a_mano['scuola_comune']]);
    }

    public function testLaPaginaFslHaLaFasciaELaScuola(): void
    {
        $fsl = PaginaAttestati::html([DatiAttestato::crea($this->prenotazione(), 'Luca Rossi', 'COD1')], 'Attestato - Luca Rossi', 'https://portale.test/eventi');
        self::assertStringContainsString('Formazione Scuola Lavoro', $fsl);
        self::assertStringContainsString("studente/studentessa dell'Istituto <strong>Liceo Scientifico Galilei</strong> di Cosenza", $fsl);
        self::assertStringContainsString('"Formazione Scuola Lavoro: Laboratorio di zoologia"', $fsl, 'il titolo porta il prefisso Formazione Scuola Lavoro');
        $evento = PaginaAttestati::html([DatiAttestato::crea($this->prenotazione(['fsl' => 0]), 'Luca Rossi', 'COD1')], 'Attestato - Luca Rossi', 'https://portale.test/eventi');
        self::assertStringNotContainsString('cert-fsl-fascia">', $evento);
        self::assertStringNotContainsString('studente/studentessa', $evento);
        // il titolo che già comincia con «Formazione Scuola Lavoro» non si ripete
        $gia = PaginaAttestati::html([DatiAttestato::crea($this->prenotazione(['evento_titolo' => 'Formazione Scuola Lavoro: Botanica']), 'Luca Rossi', 'COD1')], 'Attestato', 'https://portale.test/eventi');
        self::assertSame(1, substr_count($gia, 'Formazione Scuola Lavoro: Botanica'));
        self::assertStringNotContainsString('Formazione Scuola Lavoro: Formazione', $gia);
    }
}
