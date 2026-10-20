<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Infrastructure\Documenti\TabellaEsportabile;
use PHPUnit\Framework\TestCase;

/** Le tabelle del pannello si scaricano in CSV, Excel e PDF dagli stessi dati. */
final class TabellaEsportabileTest extends TestCase
{
    private function tabella(int $colonne = 3): TabellaEsportabile
    {
        $int = array_slice(['Scuola', 'Valida fino al', 'Note', 'Docenti', 'Protocollo', 'Stato'], 0, $colonne);
        $riga = array_slice(['Liceo «G. Galilei»; Cosenza', '30/09/2029', "con \"virgolette\"\ne a capo", 'Rossi', 'n. 1', 'In vigore'], 0, $colonne);

        return new TabellaEsportabile('Convenzioni in vigore', $int, [$riga, array_fill(0, $colonne, 'à è ì ò ù')], 'Anno 2026/27');
    }

    public function testCsvConPuntoEVirgolaEBom(): void
    {
        $csv = $this->tabella()->csv();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $righe = array_map(fn ($r) => str_getcsv($r, ';', '"', '\\'), preg_split('/\R/', trim(substr($csv, 3))));
        $this->assertSame(['Scuola', 'Valida fino al', 'Note'], $righe[0]);
        $this->assertSame('Liceo «G. Galilei»; Cosenza', $righe[1][0]);
        $this->assertSame('con "virgolette" · e a capo', $righe[1][2]);
    }

    public function testExcelEUnZipConIlFoglio(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive non disponibile');
        }
        $f = $this->tabella()->xlsx();
        $this->assertNotNull($f);
        $z = new \ZipArchive();
        $this->assertTrue($z->open($f));
        $foglio = (string) $z->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringContainsString('G. Galilei', $foglio);
        $this->assertStringContainsString('Convenzioni in vigore', (string) $z->getFromName('xl/workbook.xml'));
        $z->close();
        unlink($f);
    }

    public function testPdfSiGenera(): void
    {
        foreach ([3, 6] as $colonne) {
            $pdf = $this->tabella($colonne)->pdf();
            $this->assertStringStartsWith('%PDF-', $pdf);
            $this->assertStringContainsString('%%EOF', $pdf);
        }
        // in orizzontale se le colonne sono più di cinque
        $this->assertMatchesRegularExpression('/MediaBox\s*\[\s*0\s+0\s+841/', $this->tabella(6)->pdf());
        $this->assertMatchesRegularExpression('/MediaBox\s*\[\s*0\s+0\s+595/', $this->tabella(3)->pdf());
    }

    public function testRigheConChiaviTestualiVannoBene(): void
    {
        $t = new TabellaEsportabile('Con chiavi', ['A', 'B'], ['CSTF00003C' => ['uno', 'due'], 'altra' => ['tre', 'quattro']]);
        $this->assertStringContainsString('tre;quattro', $t->csv());
        $this->assertStringStartsWith('%PDF-', $t->pdf());
        if (class_exists(\ZipArchive::class)) {
            $f = $t->xlsx();
            $this->assertNotNull($f);
            unlink($f);
        }
    }

    public function testElencoVuotoGeneraComunqueIFile(): void
    {
        $t = new TabellaEsportabile('Vuoto', ['A', 'B'], []);
        $this->assertStringContainsString('A;B', $t->csv());
        $this->assertStringStartsWith('%PDF-', $t->pdf());
    }
}
