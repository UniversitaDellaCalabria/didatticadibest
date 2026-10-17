<?php

declare(strict_types=1);

namespace Tests\Unit\ConvenzioniTesi;

use App\ConvenzioniTesi\ModelloConvenzione;
use App\Core\Sito;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/** Il modello Word della convenzione per la tesi: parte dell'azienda, articoli modificati, PDF con lo stesso testo. */
final class ModelloConvenzioneTest extends TestCase
{
    private const AZIENDA = ['ragione_sociale' => 'BioLab Calabria S.r.l.', 'comune' => 'Rende', 'provincia' => 'cs', 'indirizzo' => 'Via Pietro Bucci 12', 'piva' => '01234567890',
        'cf' => '01234567890', 'pec' => 'biolab@pec.example.it', 'rappr_titolo' => 'Dott.ssa', 'rappr_nome' => 'Maria Rossi', 'rappr_qualifica' => 'Amministratore Unico'];

    private function modello(): ModelloConvenzione
    {
        return new ModelloConvenzione(new Sito(dirname(__DIR__, 3), 'https://portale.test/eventi'));
    }

    /** @return string il testo del document.xml del Word */
    private function testoWord(string $docx): string
    {
        $z = new ZipArchive();
        self::assertTrue($z->open($docx) === true);
        $xml = (string) $z->getFromName('word/document.xml');
        $z->close();

        return trim((string) preg_replace('/\s+/u', ' ', strip_tags(str_replace('</w:p>', "\n", $xml))));
    }

    public function testGliArticoliDelModello(): void
    {
        $a = $this->modello()->articoli();
        self::assertSame(range(1, 13), array_keys($a), 'tredici articoli');
        self::assertSame('Finalità', $a[1]['titolo']);
        self::assertStringContainsString('accogliere presso le sue strutture', $a[1]['testo']);
        self::assertSame('Norme finali', $a[13]['titolo']);
        self::assertStringContainsString('durata di 5 anni', $a[10]['testo']);
        self::assertStringContainsString('7.1-', $a[7]['testo']);
        self::assertStringContainsString("\n\n", $a[3]['testo'], 'i paragrafi sono separati da una riga vuota');
    }

    public function testParteDellAzienda(): void
    {
        $w = $this->modello()->word(self::AZIENDA);
        self::assertNotNull($w);
        $t = $this->testoWord($w);
        @unlink($w);
        self::assertStringContainsString('BioLab Calabria S.r.l., con sede legale in Rende (CS), via Via Pietro Bucci 12, P. IVA 01234567890 – C.F. 01234567890', $t);
        self::assertStringContainsString('(PEC) biolab@pec.example.it, rappresentato/a dal/la Dott.ssa Maria Rossi, in qualità di Amministratore Unico', $t);
        self::assertStringContainsString('Firma del soggetto ospitante (BioLab Calabria S.r.l.)', $t);
        self::assertStringNotContainsString('……', $t, 'nessun campo lasciato in bianco');
        self::assertStringContainsString('Art. 13', $t);
    }

    public function testCampiVuotiRestanoPuntini(): void
    {
        $w = $this->modello()->word(['ragione_sociale' => 'Solo Nome']);
        $t = $this->testoWord((string) $w);
        @unlink((string) $w);
        self::assertStringContainsString('Solo Nome, con sede legale in ……………………', $t);
    }

    public function testArticoloModificato(): void
    {
        $m = $this->modello();
        $standard = $m->word(self::AZIENDA);
        $nuovo = "I diritti sui risultati spettano all'ente ospitante.\n\nResta salvo il diritto del tesista di essere citato come autore.";
        $w = $m->word(self::AZIENDA, [7 => $nuovo]);
        $t = $this->testoWord((string) $w);
        $ts = $this->testoWord((string) $standard);
        @unlink((string) $w);
        @unlink((string) $standard);
        self::assertStringContainsString("Articolo 7 – Gestione della proprietà intellettuale I diritti sui risultati spettano all'ente ospitante. Resta salvo il diritto del tesista", $t);
        self::assertStringNotContainsString('Background', $t, 'il testo standard dell\'articolo è sostituito');
        self::assertStringContainsString('Background', $ts);
        // gli altri articoli non cambiano
        self::assertStringContainsString('Art. 8 – Oneri', $t);
        self::assertStringContainsString('Art. 6 – Obblighi del soggetto ospitante', $t);
        // e le modifiche cambiano solo l'articolo scelto: tolto l'articolo 7 il resto del testo è uguale
        $senza7 = static fn (string $x): string => (string) preg_replace('/Articolo 7 .*?(?=Art\. 8)/su', '', $x);
        self::assertSame($senza7($ts), $senza7($t));
    }

    public function testIlPdfHaLoStessoTestoDelWord(): void
    {
        $m = $this->modello();
        $pdf = $m->pdf(self::AZIENDA, [10 => 'Il presente Accordo ha durata di 3 anni.']);
        self::assertNotNull($pdf);
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertGreaterThan(5000, strlen($pdf));
        $testo = (new \App\Infrastructure\Pdf\TestoPdf())->estrai($pdf);
        self::assertStringContainsString('BioLab Calabria', $testo);
        self::assertStringContainsString('ha durata di 3 anni', $testo);
        self::assertStringNotContainsString('durata di 5 anni', $testo);
        self::assertStringContainsString('Art. 13', $testo);
    }
}
