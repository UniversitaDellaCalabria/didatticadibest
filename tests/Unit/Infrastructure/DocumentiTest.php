<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Infrastructure\Documenti\Excel;
use App\Infrastructure\Documenti\Word;
use App\Infrastructure\Documenti\Xml;
use App\Infrastructure\Storage\Upload;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class DocumentiTest extends TestCase
{
    public function testTestoXmlSenzaCaratteriDiControllo(): void
    {
        self::assertSame('a &amp; b &lt;c&gt;', Xml::testo("a & b <c>\x01"));
    }

    public function testExcelConIntestazioneEFiltro(): void
    {
        $file = Excel::crea(['Elenco' => ['intestazioni' => ['Nome', 'CFU'], 'righe' => [['Ecologia', 6]]]]);
        $zip = new ZipArchive();

        self::assertNotNull($file);
        self::assertTrue($zip->open($file) === true);
        $foglio = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        self::assertStringContainsString('<autoFilter ref="A1:B2"/>', $foglio);
        self::assertStringContainsString('Ecologia', $foglio);
        $zip->close();
        unlink($file);
    }

    public function testWordConParagrafoInGrassettoETabella(): void
    {
        $corpo = Word::paragrafo('Il **Consiglio** approva', ['al' => 'both']) . Word::tabella(['Esame', 'CFU'], [['Zoologia', '9']]);
        $file = Word::crea($corpo, ['piede' => 'Verbale']);
        $zip = new ZipArchive();

        self::assertNotNull($file);
        self::assertTrue($zip->open($file) === true);
        $doc = (string) $zip->getFromName('word/document.xml');
        self::assertStringContainsString('<w:b/><w:bCs/></w:rPr><w:t xml:space="preserve">Consiglio</w:t>', $doc);
        self::assertStringContainsString('<w:jc w:val="both"/>', $doc);
        self::assertStringContainsString('Zoologia', $doc);
        $zip->close();
        unlink($file);
    }

    public function testUploadRifiutaFileNonCaricatiOEstensioniNonAmmesse(): void
    {
        $up = new Upload();

        self::assertNull($up->salva(['error' => UPLOAD_ERR_NO_FILE], sys_get_temp_dir() . '/', ['pdf'], ['application/pdf']));
        self::assertNull($up->salva(['error' => UPLOAD_ERR_OK, 'name' => 'x.php', 'tmp_name' => __FILE__], sys_get_temp_dir() . '/', ['pdf'], ['application/pdf']));
    }
}
