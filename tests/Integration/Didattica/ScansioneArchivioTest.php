<?php

declare(strict_types=1);

namespace Tests\Integration\Didattica;

use App\Didattica\ArchivioVerbali;
use App\Didattica\ConsiglioRepository;
use App\Didattica\OrganiArchivio;
use App\Didattica\ScansioneArchivio;
use App\Didattica\SedutaRepository;
use App\Infrastructure\Pdf\TestoPdf;
use App\Core\Sito;

/** Cartella dell'archivio sul NAS: i PDF si leggono da soli, ogni cartella ha il suo organo, spostamenti e cancellazioni sono gestiti con prudenza. */
final class ScansioneArchivioTest extends DidatticaBase
{
    private string $radice;
    private ScansioneArchivio $scan;
    private int $cons;

    protected function setUp(): void
    {
        parent::setUp();
        $sito = $this->servizio(Sito::class);
        $this->scan = new ScansioneArchivio($this->servizio(SedutaRepository::class), $this->servizio(ConsiglioRepository::class), $this->servizio(OrganiArchivio::class), new TestoPdf(), $sito, $this->cartellaVerbali);
        $this->radice = $this->scan->radice();
        $this->scan->prepara();
        $this->cons = $this->db->inserisci("INSERT INTO didattica_consigli (nome, cartella_archivio) VALUES ('Consiglio di corso di studi in Biologia', 'Biologia')");
    }

    protected function tearDown(): void
    {
        $this->svuota($this->radice);
        parent::tearDown();
    }

    private function svuota(string $dir): void
    {
        foreach (glob(rtrim($dir, '/') . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (in_array(basename($f), ['.', '..'], true)) {
                continue;
            }
            is_dir($f) ? $this->svuota($f) : @unlink($f);
        }
        @rmdir($dir);
    }

    private function pdf(string $rel, string $contenuto = ''): void
    {
        $p = $this->radice . $rel;
        @mkdir(dirname($p), 0755, true);
        file_put_contents($p, "%PDF-1.4\n" . ($contenuto !== '' ? $contenuto : $rel) . "\n%%EOF");
    }

    /** @return list<array<string, mixed>> */
    private function verbali(): array
    {
        return $this->db->righe('SELECT * FROM didattica_sedute WHERE da_cartella = 1 ORDER BY verbale_pdf');
    }

    public function testImportaLeCartelleCollegateELasciaStareLeAltre(): void
    {
        $this->pdf('Biologia/2019/Verbale_08_2019-03-12.pdf');
        $this->pdf('Biologia/2020/Verbale_n3_2020-06-01.pdf');
        $this->pdf('Chimica/2021/Verbale_1_2021-02-02.pdf');
        $r = $this->scan->esegui();
        $this->assertSame([3, 2], [$r['trovati'], $r['nuovi']]);
        $this->assertSame(['Chimica' => 1], $r['non_collegati'], 'la cartella senza organo non si importa e si segnala (senza la sottocartella dell\'anno)');
        $v = $this->verbali();
        $this->assertCount(2, $v);
        $this->assertSame([$this->cons, null, '2019-03-12', '2018/2019', '8', 'importato', 1], [(int) $v[0]['consiglio_id'], $v[0]['organo_id'], $v[0]['data'], $v[0]['anno_accademico'], $v[0]['numero_verbale'], $v[0]['origine'], (int) $v[0]['da_cartella']]);
        $this->assertSame('Consiglio di corso di studi in Biologia', $v[0]['organo']);
        $this->assertSame($this->cartellaVerbali . 'archivio/Biologia/2019/Verbale_08_2019-03-12.pdf', $v[0]['verbale_pdf']);
        $this->assertSame(['Chimica' => 1], $this->scan->nonCollegate());
    }

    public function testSecondaScansioneNonCambiaNulla(): void
    {
        $this->pdf('Biologia/2019/Verbale_08_2019-03-12.pdf');
        $this->scan->esegui();
        $r = $this->scan->esegui();
        $this->assertSame([1, 0, 0, 0, 0], [$r['trovati'], $r['nuovi'], $r['aggiornati'], $r['spostati'], $r['rimossi']]);
        $this->assertCount(1, $this->verbali());
    }

    public function testOrganoPiuSpecificoVinceESottocartelleDiAnno(): void
    {
        $o = $this->servizio(OrganiArchivio::class);
        $this->assertSame(3, $o->creaStandard('Biologia', 'Biologia'));
        $this->assertSame(0, $o->creaStandard('Biologia', 'Biologia'), 'una seconda volta non ripete');
        $this->pdf('Biologia/2019/Verbale_1_2019-01-10.pdf');
        $this->pdf('Biologia/Commissione didattica/2019/Verbale_2_2019-02-11.pdf');
        $this->pdf('Biologia/Gruppo di riesame/2020-2021/Verbale_3_2020-11-11.pdf');
        $this->pdf('Biologia/Riunioni con gli studenti/Incontro_2021-04-01.pdf');
        $r = $this->scan->esegui();
        $this->assertSame(4, $r['nuovi']);
        $per = array_column($this->verbali(), null, 'nome_originale');
        $this->assertSame($this->cons, (int) $per['Verbale_1_2019-01-10.pdf']['consiglio_id']);
        $this->assertNull($per['Verbale_2_2019-02-11.pdf']['consiglio_id']);
        $this->assertSame('Commissione didattica – Biologia', $per['Verbale_2_2019-02-11.pdf']['organo']);
        $this->assertNotNull($per['Verbale_2_2019-02-11.pdf']['organo_id']);
        $this->assertSame('Gruppo di riesame – Assicurazione della Qualità – Biologia', $per['Verbale_3_2020-11-11.pdf']['organo']);
        $this->assertSame('2020/2021', $per['Verbale_3_2020-11-11.pdf']['anno_accademico']);
        $this->assertSame('Riunione con gli studenti – Biologia', $per['Incontro_2021-04-01.pdf']['organo']);
        $this->assertSame('', ScansioneArchivio::senzaAnni('2019/2020'));
        $this->assertSame('Biologia/Riesame', ScansioneArchivio::senzaAnni('Biologia/Riesame/2019/2020-2021'));
    }

    public function testFileCambiatoSpostatoERimosso(): void
    {
        $this->pdf('Biologia/2019/Verbale_08_2019-03-12.pdf', 'uno');
        $this->pdf('Biologia/2020/Verbale_09_2020-03-12.pdf', 'due');
        $this->scan->esegui();
        $id = (int) $this->db->valore("SELECT id FROM didattica_sedute WHERE nome_originale = 'Verbale_08_2019-03-12.pdf'");
        $this->db->esegui("UPDATE didattica_sedute SET numero_verbale = '77' WHERE id = ?", [$id]); // un dato corretto a mano

        // cambiato: nuovo contenuto
        $this->pdf('Biologia/2019/Verbale_08_2019-03-12.pdf', 'uno modificato');
        touch($this->radice . 'Biologia/2019/Verbale_08_2019-03-12.pdf', time() + 5);
        $this->assertSame(1, $this->scan->esegui()['aggiornati']);

        // spostato in un'altra cartella: lo stesso verbale, con i dati corretti, cambia solo il percorso
        @mkdir($this->radice . 'Biologia/Archivio vecchio', 0755, true);
        rename($this->radice . 'Biologia/2019/Verbale_08_2019-03-12.pdf', $this->radice . 'Biologia/Archivio vecchio/Verbale_08_2019-03-12.pdf');
        $r = $this->scan->esegui();
        $this->assertSame([1, 0, 0], [$r['spostati'], $r['nuovi'], $r['rimossi']]);
        $riga = $this->db->riga('SELECT * FROM didattica_sedute WHERE id = ?', [$id]);
        $this->assertSame('77', $riga['numero_verbale']);
        $this->assertStringContainsString('Archivio vecchio', (string) $riga['verbale_pdf']);

        // cancellato dal NAS: sparisce dall'archivio
        unlink($this->radice . 'Biologia/Archivio vecchio/Verbale_08_2019-03-12.pdf');
        $this->assertSame(1, $this->scan->esegui()['rimossi']);
        $this->assertCount(1, $this->verbali());
    }

    public function testContenutoIdenticoInDueCartelleENessunaPerditaSeIlNasNonCEra(): void
    {
        $this->pdf('Biologia/2019/a.pdf', 'stesso');
        $this->pdf('Biologia/2019/copia di a.pdf', 'stesso');
        $r = $this->scan->esegui();
        $this->assertSame([1, 1], [$r['nuovi'], $r['doppi']]);

        // NAS non montato: cartella vuota, il database non si svuota
        $this->svuota($this->radice);
        $r = $this->scan->esegui();
        $this->assertSame(0, $r['rimossi']);
        $this->assertCount(1, $this->verbali());
        $this->assertStringContainsString('vuota', implode(' ', $r['avvisi']));
    }

    public function testTroppiFileSparitiNonSiTolgono(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->pdf("Biologia/2019/v$i.pdf", "contenuto $i");
        }
        $this->scan->esegui(100);
        for ($i = 1; $i <= 10; $i++) {
            unlink($this->radice . "Biologia/2019/v$i.pdf");
        }
        $r = $this->scan->esegui();
        $this->assertSame(0, $r['rimossi']);
        $this->assertNotSame([], $r['errori']);
        $this->assertCount(12, $this->verbali());
    }

    public function testAPassiELeCoseDaIgnorare(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->pdf("Biologia/2019/v$i.pdf", "contenuto $i");
        }
        $this->pdf('Biologia/@eaDir/miniatura.pdf');
        $this->pdf('Biologia/2019/~$temp.pdf');
        file_put_contents($this->radice . 'Biologia/2019/appunti.docx', 'x');
        $r = $this->scan->esegui(3);
        $this->assertSame([5, 3, 2], [$r['trovati'], $r['nuovi'], $r['restanti']]);
        $r = $this->scan->esegui(3);
        $this->assertSame([2, 0], [$r['nuovi'], $r['restanti']]);
    }

    public function testOrganiNomiECartelle(): void
    {
        $o = $this->servizio(OrganiArchivio::class);
        $this->assertSame('A/B c/D', OrganiArchivio::cartella(' \\A//B c/../D/ '));
        $this->assertNull($o->salva(0, 'Commissione paritetica', 'altro', 'Biologia', 'Biologia/Paritetica'));
        $this->assertStringContainsString('già di', (string) $o->salva(0, 'Altra', 'altro', '', 'biologia'), 'la cartella del consiglio è già presa (maiuscole ignorate)');
        $this->assertStringContainsString('già di', (string) $o->salva(0, 'Altra', 'altro', '', 'Biologia/Paritetica'));
        $this->assertSame("Scrivi il nome dell'organo.", $o->salva(0, ' ', 'altro', '', ''));
        $id = (int) $o->tutti()[0]['id'];
        $this->pdf('Biologia/Paritetica/2019/v.pdf');
        $this->scan->esegui();
        $this->assertNull($o->salva($id, 'Commissione paritetica docenti-studenti', 'altro', 'Biologia', 'Biologia/Paritetica'));
        $this->assertSame('Commissione paritetica docenti-studenti', $this->db->valore('SELECT organo FROM didattica_sedute WHERE organo_id = ?', [$id]), 'il nuovo nome arriva ai verbali');
        $this->assertStringContainsString('ha dei verbali', (string) $o->elimina($id));
    }

    public function testArchivioNonEliminaIFileDelNas(): void
    {
        $this->pdf('Biologia/2019/v.pdf');
        $this->scan->esegui();
        $riga = $this->verbali()[0];
        $arch = $this->servizio(ArchivioVerbali::class);
        $this->assertFalse($arch->elimina($riga));
        $this->assertFileExists($this->radice . 'Biologia/2019/v.pdf');
        $this->assertStringContainsString('NAS', (string) $arch->sostituisciFile($riga, '/dev/null', 'x.pdf'));
        $this->assertNull($arch->salvaDati((int) $riga['id'], ['consiglio_id' => (string) $this->cons, 'data' => '2019-05-05', 'numero_verbale' => '9', 'odg' => ''], [$this->cons => ['nome' => 'Consiglio di corso di studi in Biologia']]), 'i dati si possono correggere');
        $this->assertSame('2019-05-05', $this->db->valore('SELECT data FROM didattica_sedute WHERE id = ?', [$riga['id']]));
    }
}
