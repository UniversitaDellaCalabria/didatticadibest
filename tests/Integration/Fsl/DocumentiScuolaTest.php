<?php

declare(strict_types=1);

namespace Tests\Integration\Fsl;

use App\Core\Sito;
use App\Fsl\ServizioDocumentiScuola;

/** Convenzioni e Allegato A nell'Area personale: chi è collegato a una scuola, storico con gli stati e download protetto. */
final class DocumentiScuolaTest extends FslBase
{
    private string $radiceProva;

    protected function setUp(): void
    {
        parent::setUp();
        $this->radiceProva = sys_get_temp_dir() . '/fsl_docscuola_' . bin2hex(random_bytes(4));
        mkdir($this->radiceProva . '/uploads/convenzioni', 0755, true);
        $this->c->istanza(Sito::class, new Sito($this->radiceProva, 'https://portale.test/eventi'));
        $this->db->esegui("INSERT INTO utenti (id, email, ruolo_id, scuola_codice) VALUES (1, 'anna@prova.it', 5, NULL), (2, 'bea@prova.it', 5, 'CSPS00001A'), (3, 'carlo@prova.it', 5, NULL), (4, 'dora@prova.it', 5, NULL)");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->radiceProva . '/uploads/convenzioni/{,.}*', GLOB_BRACE) ?: [] as $f) {
            is_file($f) && @unlink($f);
        }
        @rmdir($this->radiceProva . '/uploads/convenzioni');
        @rmdir($this->radiceProva . '/uploads');
        @rmdir($this->radiceProva);
    }

    private function servizioDocumenti(): ServizioDocumentiScuola
    {
        return $this->servizio(ServizioDocumentiScuola::class);
    }

    public function testChiHaUnaPrenotazioneFslOLaScuolaNelProfiloELaVede(): void
    {
        $this->pren(200, ['utente_id' => 1, 'email' => 'anna@prova.it']);
        $this->pren(200, ['utente_id' => null, 'email' => 'CARLO@prova.it']);
        $this->pren(200, ['utente_id' => 4, 'email' => 'dora@prova.it', 'stato' => 'annullata']);
        $s = $this->servizioDocumenti();

        $this->assertSame(['CSPS00001A'], $s->codici(1, 'anna@prova.it'), 'con una prenotazione FSL dell\'utente');
        $this->assertSame(['CSPS00001A'], $s->codici(2, 'bea@prova.it'), 'con la scuola nel profilo');
        $this->assertSame(['CSPS00001A'], $s->codici(3, 'carlo@prova.it'), 'una prenotazione fatta con la stessa email, senza accesso');
        $this->assertSame([], $s->codici(4, 'dora@prova.it'), 'una prenotazione annullata non collega');
        $this->assertSame([], $s->codici(99, ''));
        $this->assertTrue($s->collegata(1, 'anna@prova.it', ' csps00001a '));
        $this->assertFalse($s->collegata(1, 'anna@prova.it', 'CSPS00002B'));
        $this->assertFalse($s->collegata(1, 'anna@prova.it', 'x'));
    }

    public function testLoStoricoDelleConvenzioniConGliStati(): void
    {
        $this->pren(200, ['utente_id' => 1, 'email' => 'anna@prova.it']);
        $vecchia = $this->convenzione('CSPS00001A', '2024-01-01', '2025-01-01');
        $valida = $this->convenzione('CSPS00001A', '2026-09-01', '2027-08-31', ['protocollo' => '77 del 01/09/2026']);
        $scadenza = $this->convenzione('CSPS00001A', '2026-06-01', '2026-11-15');
        $futura = $this->convenzione('CSPS00001A', '2027-01-01', '2027-12-31');
        $this->convenzione('CSPS00002B', '2026-01-01', '2027-01-01');
        $this->db->esegui("UPDATE convenzioni_scuole SET file_convenzione = 'uploads/convenzioni/a.pdf', file_allegato = 'uploads/convenzioni/b.pdf' WHERE id = ?", [$valida]);

        $scuole = $this->servizioDocumenti()->perUtente(1, 'anna@prova.it');

        $this->assertCount(1, $scuole, 'solo la scuola della persona');
        $this->assertSame('CSPS00001A', $scuole[0]['codice']);
        $this->assertTrue($scuole[0]['allegato'], 'c\'è un\'attività FSL in essere: l\'Allegato A si genera');
        $stati = array_column($scuole[0]['convenzioni'], 'stato', 'id');
        $this->assertSame([$futura => 'futura', $valida => 'valida', $scadenza => 'in_scadenza', $vecchia => 'scaduta'], $stati);
        $this->assertCount(4, $scuole[0]['convenzioni'], 'anche le scadute');
        $this->assertSame('futura', $scuole[0]['convenzioni'][0]['stato'], 'dalla più lontana nella scadenza');
        $v = array_values(array_filter($scuole[0]['convenzioni'], static fn (array $c): bool => $c['id'] === $valida))[0];
        $this->assertSame(['77 del 01/09/2026', true, true], [$v['protocollo'], $v['file'], $v['allegato']]);
        $this->assertSame('dal 01/09/2026 al 31/08/2027', $v['validita']);
    }

    public function testNonVedeNienteUnaScuolaSenzaConvenzioniNeAttivita(): void
    {
        $this->db->esegui("UPDATE utenti SET scuola_codice = 'CSTF00003C' WHERE id = 3");
        $this->assertSame([], $this->servizioDocumenti()->perUtente(3, 'carlo@prova.it'));
    }

    public function testIFileSiScaricanoSoloSeSeCollegatiEDallaCartellaProtetta(): void
    {
        $this->pren(200, ['utente_id' => 1, 'email' => 'anna@prova.it']);
        $id = $this->convenzione('CSPS00001A', '2026-09-01', '2027-08-31');
        $altra = $this->convenzione('CSPS00002B', '2026-09-01', '2027-08-31');
        file_put_contents($this->radiceProva . '/uploads/convenzioni/c.pdf', '%PDF convenzione');
        file_put_contents($this->radiceProva . '/uploads/convenzioni/all.pdf', '%PDF allegato');
        $this->db->esegui("UPDATE convenzioni_scuole SET file_convenzione = 'uploads/convenzioni/c.pdf', file_allegato = 'uploads/convenzioni/all.pdf' WHERE id IN (?, ?)", [$id, $altra]);
        $s = $this->servizioDocumenti();

        $f = $s->fileConvenzione(1, 'anna@prova.it', 'CSPS00001A', $id, 'file_convenzione');
        $this->assertNotNull($f);
        $this->assertSame('pdf', $f['ext']);
        $this->assertSame('%PDF convenzione', file_get_contents($f['percorso']));
        $this->assertSame('%PDF allegato', file_get_contents($s->fileConvenzione(1, 'anna@prova.it', 'CSPS00001A', $id, 'file_allegato')['percorso'] ?? ''));

        $this->assertNull($s->fileConvenzione(3, 'carlo@prova.it', 'CSPS00001A', $id, 'file_convenzione'), 'chi non è collegato alla scuola');
        $this->assertNull($s->fileConvenzione(1, 'anna@prova.it', 'CSPS00002B', $altra, 'file_convenzione'), 'la convenzione di un\'altra scuola');
        $this->assertNull($s->fileConvenzione(1, 'anna@prova.it', 'CSPS00001A', $altra, 'file_convenzione'), 'l\'id è di un\'altra scuola anche se il codice è il proprio');
        $this->assertNull($s->fileConvenzione(1, 'anna@prova.it', 'CSPS00001A', 9999, 'file_convenzione'));

        file_put_contents($this->radiceProva . '/fuori.pdf', 'x');
        $this->db->esegui("UPDATE convenzioni_scuole SET file_convenzione = '../fuori.pdf' WHERE id = ?", [$id]);
        $this->assertNull($s->fileConvenzione(1, 'anna@prova.it', 'CSPS00001A', $id, 'file_convenzione'), 'fuori dalla cartella delle convenzioni');
        @unlink($this->radiceProva . '/fuori.pdf');
    }

    public function testLAllegatoGeneratoSoloPerChiELaScuola(): void
    {
        $this->pren(200, ['utente_id' => 1, 'email' => 'anna@prova.it']);
        $s = $this->servizioDocumenti();

        $this->assertNull($s->allegatoGenerato(3, 'carlo@prova.it', 'CSPS00001A'), 'non collegato');
        $doc = $s->allegatoGenerato(1, 'anna@prova.it', 'CSPS00001A');
        $this->assertNotNull($doc);
        $this->assertStringStartsWith('%PDF-', (string) file_get_contents($doc['file']));
        $this->assertStringEndsWith('.pdf', $doc['nome']);
        @unlink($doc['file']);
    }
}
