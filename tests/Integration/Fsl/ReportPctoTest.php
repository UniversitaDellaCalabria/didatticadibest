<?php

declare(strict_types=1);

namespace Tests\Integration\Fsl;

use App\Fsl\ReportPcto;

/** Report PCTO: ore e studenti per scuola, per attività e per studente nell'anno scolastico. */
final class ReportPctoTest extends FslBase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->db->esegui('ALTER TABLE prenotazioni ADD COLUMN attestato_inviato TINYINT DEFAULT 0');
        $this->db->esegui('ALTER TABLE partecipanti_prenotazione ADD COLUMN cognome VARCHAR(100) NULL, ADD COLUMN nome VARCHAR(100) NULL, ADD COLUMN escluso TINYINT DEFAULT 0');
    }

    private function report(): array
    {
        return $this->servizio(ReportPcto::class)->dati('2026-09-01', '2027-08-31');
    }

    public function testNumeriPerScuolaEAttivita(): void
    {
        // progetto da 40 ore: classe di 12 (dal campo del modulo) presente; evento di 3,5 ore: classe con elenco di 2 nomi presente + classe assente
        $this->pren(200, ['presente' => 1]);
        $e1 = $this->pren(100, ['presente' => 1, 'scuola_codice' => 'CSPS00002B', 'attestato_inviato' => 1]);
        $this->db->esegui("INSERT INTO partecipanti_prenotazione (prenotazione_id, cognome, nome) VALUES (?, 'Rossi', 'Luca'), (?, 'Bianchi', 'Anna'), (?, 'Verdi', 'Gino')", [$e1, $e1, $e1]);
        $this->db->esegui('UPDATE partecipanti_prenotazione SET escluso = 1 WHERE cognome = \'Verdi\'');
        $this->pren(100, ['presente' => 0, 'scuola_codice' => 'CSTF00003C']);
        $this->pren(100, ['stato' => 'annullata']);

        $d = $this->report();
        $this->assertSame(3, $d['totali']['scuole']);
        $this->assertSame(2, $d['totali']['attivita']);
        $this->assertSame(3, $d['totali']['classi'], 'le annullate non contano');
        $this->assertSame(12 + 2 + 12, $d['totali']['studenti']);
        $this->assertSame(12 + 2, $d['totali']['presenti']);
        $this->assertSame(12 * 40 + 2 * 3.5, $d['totali']['ore_studente']);
        $nomi = array_column($d['scuole'], 'nome');
        $sorted = $nomi;
        sort($sorted, SORT_FLAG_CASE | SORT_STRING);
        $this->assertSame($sorted, $nomi, 'in ordine alfabetico');
        $this->assertCount(2, $d['studenti'], 'le ore per studente solo con l\'elenco dei nomi, senza gli esclusi');
        $this->assertSame(['Bianchi', 'Rossi'], array_column($d['studenti'], 'cognome'));
        $this->assertSame(3.5, $d['studenti'][0]['ore']);
        $this->assertSame('Sì', $d['studenti'][0]['attestato']);
    }

    public function testFuoriAnnoScolasticoENonFsl(): void
    {
        $this->pren(100, ['presente' => 1]);
        $this->assertSame(0, $this->servizio(ReportPcto::class)->dati('2025-09-01', '2026-08-31')['totali']['classi']);
        $this->db->esegui('UPDATE progetti_dettagli SET convenzione = 0 WHERE evento_id = 10');
        $this->assertSame(0, $this->report()['totali']['classi'], 'senza convenzione non è FSL');
    }

    public function testExcel(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive non disponibile');
        }
        $this->pren(200, ['presente' => 1]);
        $r = $this->servizio(ReportPcto::class);
        $f = $r->esporta($r->dati('2026-09-01', '2027-08-31'), '2026/2027');
        $this->assertNotNull($f);
        $this->assertFileExists($f);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($f) === true);
        $this->assertNotFalse($zip->locateName('xl/worksheets/sheet4.xml'), 'quattro fogli');
        $zip->close();
        @unlink($f);
    }
}
