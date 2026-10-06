<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Pdf;

use App\Infrastructure\Pdf\FirmePades;
use PHPUnit\Framework\TestCase;

final class FirmePadesTest extends TestCase
{
    /** Un PDF finto con una firma: dizionario /Sig con /ByteRange [0 50 100 d]; con $coda = false il tratto firmato lascia fuori gli ultimi 40 byte. */
    private function pdfFirmato(bool $coda = true): string
    {
        $pdf = "%PDF-1.7\n1 0 obj\n<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /ETSI.CAdES.detached /Name (Mario Rossi) /M (D:20261006120000+02'00') /ByteRange [0 50 100 XXXX] >>\nendobj\n" . str_repeat('x', 60) . "\n%%EOF\n";

        return str_replace('XXXX', str_pad((string) (strlen($pdf) - 100 - ($coda ? 0 : 40)), 4, ' ', STR_PAD_LEFT), $pdf);
    }

    public function testNonUnPdfNonHaFirme(): void
    {
        $f = new FirmePades();
        $this->assertSame([], $f->firmePades('PK file zip'));
        $this->assertSame([], $f->firmePades(''));
        $this->assertSame([], $f->firmePades("%PDF-1.7\nsenza firme\n%%EOF"));
    }

    public function testLeggeLaFirma(): void
    {
        $firme = (new FirmePades())->firmePades($this->pdfFirmato());
        $this->assertCount(1, $firme);
        $this->assertSame(['ETSI.CAdES.detached', 'Mario Rossi', '20261006120000'], [$firme[0]['subfilter'], $firme[0]['nome'], $firme[0]['data']]);
        $this->assertTrue($firme[0]['valida_struttura']);
        $this->assertTrue($firme[0]['copre_tutto']);
    }

    public function testModificheDopoLaFirma(): void
    {
        $firme = (new FirmePades())->firmePades($this->pdfFirmato(false));
        $this->assertFalse($firme[0]['copre_tutto'], 'il file prosegue oltre il tratto firmato');
    }

    public function testCopreTuttoIgnoraGliSpaziInCoda(): void
    {
        $firme = (new FirmePades())->firmePades($this->pdfFirmato() . "\n\n");
        $this->assertTrue($firme[0]['copre_tutto']);
    }
}
