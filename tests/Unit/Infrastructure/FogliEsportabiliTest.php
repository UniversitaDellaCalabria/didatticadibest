<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Infrastructure\Documenti\Excel;
use App\Infrastructure\Documenti\FogliEsportabili;
use App\Infrastructure\Documenti\TabellaEsportabile;
use PHPUnit\Framework\TestCase;

/** I report a più fogli si scaricano in Excel, PDF e CSV dagli stessi dati. */
final class FogliEsportabiliTest extends TestCase
{
    private function fogli(): FogliEsportabili
    {
        return new FogliEsportabili('Report di prova', [
            new TabellaEsportabile('Riepilogo', ['Indicatore', 'Valore'], [['Scuole', 12], ['Studenti', 340]]),
            new TabellaEsportabile('Per scuola', ['Scuola', 'Codice', 'Comune', 'Provincia', 'Attività', 'Classi', 'Studenti'], [['Liceo «Galilei»', 'CSPS00001A', 'Cosenza', 'CS', 3, 5, 120]]),
        ]);
    }

    public function testCsvConLeTabelleSeparate(): void
    {
        $csv = $this->fogli()->csv();
        $this->assertStringContainsString("Riepilogo\nIndicatore;Valore\nScuole;12", str_replace("\r", '', $csv));
        $this->assertStringContainsString('Per scuola', $csv);
    }

    public function testExcelHaUnFoglioPerTabella(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive non disponibile');
        }
        $f = $this->fogli()->xlsx();
        $z = new \ZipArchive();
        $this->assertTrue($z->open((string) $f));
        $this->assertNotFalse($z->getFromName('xl/worksheets/sheet2.xml'));
        $wb = (string) $z->getFromName('xl/workbook.xml');
        $this->assertStringContainsString('Per scuola', $wb);
        $z->close();
        unlink((string) $f);
    }

    public function testPdfConPiuTabelle(): void
    {
        $pdf = $this->fogli()->pdf();
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertMatchesRegularExpression('/MediaBox\s*\[\s*0\s+0\s+841/', $pdf, 'in orizzontale: una tabella ha più di cinque colonne');
    }

    public function testExcelRicordaGliUltimiFogli(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive non disponibile');
        }
        $f = Excel::crea(['Uno' => ['intestazioni' => ['A'], 'righe' => [['x']]]]);
        $this->assertSame(['Uno'], array_keys(Excel::ultimiFogli()));
        unlink((string) $f);
    }
}
