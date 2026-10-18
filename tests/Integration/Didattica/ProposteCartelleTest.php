<?php

declare(strict_types=1);

namespace Tests\Integration\Didattica;

use App\Core\Sito;
use App\Didattica\ConsiglioRepository;
use App\Didattica\OrganiArchivio;
use App\Didattica\ProposteCartelle;
use App\Didattica\ScansioneArchivio;
use App\Didattica\SedutaRepository;
use App\Infrastructure\Pdf\TestoPdf;

/** Collegamento automatico delle cartelle «Corso/Organo/anno» (struttura reale del Dipartimento) ai consigli e agli organi. */
final class ProposteCartelleTest extends DidatticaBase
{
    private const CONSIGLI = [
        'Consiglio del Corso di Laurea in Scienze e Tecnologie per le Attività Motorie e Sportive',
        'Consiglio di Coordinamento del Corso di Laurea in Biologia, del Corso di Laurea Magistrale in Biologia, del Corso di Laurea in Scienze e Tecnologie Biologiche e del Corso di Laurea Magistrale in Health Biotechnology',
        'Consiglio di Coordinamento del Corso di Laurea Magistrale a Ciclo Unico in Conservazione e Restauro dei Beni Culturali',
        'Consiglio Unificato del Corso di Laurea in Scienze Geologiche e del Corso di Laurea Magistrale in Scienze Geologiche per la Gestione dei Rischi Ambientali e le Georisorse',
        'Consiglio Unificato del Corso di Laurea in Scienze Naturali e Ambientali e del Corso di Laurea Magistrale in Biodiversità e Conservazione dei Sistemi Naturali',
    ];
    private const CORSI = ['VERBALI_BIOLOGIA_BIOTECNOLOGIE', 'VERBALI_GEOLOGIA', 'VERBALI_MOTORIE', 'VERBALI_NATURALI', 'VERBALI_RESTAURO'];
    private const ORGANI = ['COMMISSIONE_DIDATTICA', 'CONSIGLIO_CORSO_DI_STUDIO', 'GRUPPO_RIESAME_AQ', 'RIUNIONE_STUDENTI_WELCOME_DAY'];

    private ScansioneArchivio $scan;
    private string $radice;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::CONSIGLI as $n) {
            $this->db->esegui('INSERT INTO didattica_consigli (nome) VALUES (?)', [$n]);
        }
        $this->scan = new ScansioneArchivio($this->servizio(SedutaRepository::class), $this->servizio(ConsiglioRepository::class), $this->servizio(OrganiArchivio::class), new TestoPdf(), $this->servizio(Sito::class), $this->cartellaVerbali);
        $this->c->istanza(ScansioneArchivio::class, $this->scan); // anche i servizi che la usano guardano la cartella di prova
        $this->scan->prepara();
        $this->scan->pausa(false);
        @unlink(dirname(__DIR__, 3) . '/cache/archivio_scansione.json');
        $this->radice = $this->scan->radice();
        foreach (self::CORSI as $c) {
            foreach (self::ORGANI as $o) {
                $this->pdf("$c/$o/2019/Verbale_1_2019-01-10.pdf", "$c$o");
            }
        }
        // un verbale con la sua cartella di allegati (PDF, Excel…), come nel 2025 del Consiglio di Geologia
        $cv = 'VERBALI_GEOLOGIA/CONSIGLIO_CORSO_DI_STUDIO/2025/Verb. CCL_02-10-2025/';
        $this->pdf($cv . 'Verb. CCL_02-10-2025.pdf', 'verbale 2025');
        $this->pdf($cv . 'ALLEGATO_BOZZA Rapporto.pdf', 'allegato pdf');
        file_put_contents($this->radice . $cv . 'ALLEGATO_OFA sett 2025.xlsx', 'xlsx');
        // e un PDF qualunque nella cartella dell'anno (RRC…): resta un verbale da completare
        $this->pdf('VERBALI_GEOLOGIA/CONSIGLIO_CORSO_DI_STUDIO/2019/RRC_L-34.pdf', 'rapporto');
    }

    protected function tearDown(): void
    {
        $this->scan->pausa(false);
        @unlink(dirname(__DIR__, 3) . '/cache/archivio_scansione.json');
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

    private function pdf(string $rel, string $contenuto): void
    {
        $p = $this->radice . $rel;
        @mkdir(dirname($p), 0755, true);
        file_put_contents($p, "%PDF-1.4\n$contenuto\n%%EOF");
    }

    private function proposta(array $proposte, string $cartella): array
    {
        return array_column($proposte, null, 'cartella')[$cartella];
    }

    public function testAbbinaIlConsiglioDalNomeDellaCartella(): void
    {
        $c = (new ConsiglioRepository($this->db))->tutti();
        $nome = static fn (?int $id): string => $id ? mb_substr((string) $c[$id]['nome'], 0, 40) : '-';
        $this->assertStringContainsString('Biologia', $nome(ProposteCartelle::abbina('VERBALI_BIOLOGIA_BIOTECNOLOGIE', $c)) . $c[ProposteCartelle::abbina('VERBALI_BIOLOGIA_BIOTECNOLOGIE', $c)]['nome']);
        $this->assertStringContainsString('Geologiche', (string) $c[ProposteCartelle::abbina('VERBALI_GEOLOGIA', $c)]['nome']);
        $this->assertStringContainsString('Motorie', (string) $c[ProposteCartelle::abbina('VERBALI_MOTORIE', $c)]['nome']);
        $this->assertStringContainsString('Naturali e Ambientali', (string) $c[ProposteCartelle::abbina('VERBALI_NATURALI', $c)]['nome']);
        $this->assertStringContainsString('Restauro', (string) $c[ProposteCartelle::abbina('VERBALI_RESTAURO', $c)]['nome']);
        $this->assertNull(ProposteCartelle::abbina('VERBALI_ASTRONOMIA', $c), 'nessun consiglio somiglia');
        $this->assertSame('Biologia biotecnologie', ProposteCartelle::nomeCorso('VERBALI_BIOLOGIA_BIOTECNOLOGIE'));
    }

    public function testProponeUnCollegamentoPerOgniOrganoDiOgniCorso(): void
    {
        $p = $this->servizio(ProposteCartelle::class)->proponi();
        $this->assertCount(20, $p, 'cinque corsi per quattro organi; la cartella degli allegati fa parte del suo organo');

        $cons = $this->proposta($p, 'VERBALI_GEOLOGIA/CONSIGLIO_CORSO_DI_STUDIO');
        $this->assertSame('consiglio', $cons['azione']);
        $this->assertStringContainsString('Scienze Geologiche', $cons['nome']);
        $this->assertSame(4, $cons['n_pdf'], '1 verbale del 2019, il RRC, il verbale 2025 e il suo allegato PDF');

        $com = $this->proposta($p, 'VERBALI_GEOLOGIA/COMMISSIONE_DIDATTICA');
        $this->assertSame(['nuovo', 'commissione'], [$com['azione'], $com['tipo']]);
        $this->assertStringContainsString('Scienze Geologiche', $com['corso'], 'il corso è quello del consiglio');
        $this->assertSame('riesame', $this->proposta($p, 'VERBALI_MOTORIE/GRUPPO_RIESAME_AQ')['tipo']);
        $this->assertSame('studenti', $this->proposta($p, 'VERBALI_NATURALI/RIUNIONE_STUDENTI_WELCOME_DAY')['tipo']);
        $this->assertSame(5, count(array_filter($p, static fn (array $x): bool => $x['azione'] === 'consiglio')), 'ogni consiglio una volta');
    }

    public function testApplicaEPoiLaScansioneAssegnaOgniPdfAlSuoOrgano(): void
    {
        $r = $this->servizio(ProposteCartelle::class)->applica();
        $this->assertSame([20, []], [$r['collegate'], $r['problemi']]);
        $this->assertSame([], $this->servizio(ProposteCartelle::class)->proponi(), 'nulla resta da collegare');
        $this->assertSame(5, (int) $this->db->valore("SELECT COUNT(*) FROM didattica_consigli WHERE cartella_archivio <> ''"));
        $this->assertSame(15, (int) $this->db->valore('SELECT COUNT(*) FROM archivio_organi'));

        $r = $this->scan->esegui(100);
        $this->assertSame([[], []], [$r['allegati'], $r['senza_verbale']]);
        $per = $this->db->righe('SELECT organo, COUNT(*) n FROM didattica_sedute WHERE da_cartella = 1 GROUP BY organo');
        $this->assertSame(22, array_sum(array_column($per, 'n')), '20 verbali del 2019 + il RRC + il verbale 2025; gli allegati non diventano verbali');
        $v = $this->db->riga("SELECT * FROM didattica_sedute WHERE nome_originale = 'Verb. CCL_02-10-2025.pdf'");
        $this->assertSame(['2025-10-02', '2025/2026'], [$v['data'], $v['anno_accademico']]);
        $all = json_decode((string) $v['allegati_json'], true);
        $this->assertSame(['ALLEGATO_BOZZA Rapporto.pdf', 'ALLEGATO_OFA sett 2025.xlsx'], array_column($all, 'n'), 'gli allegati del verbale, anche Excel');
        $this->assertStringStartsWith($this->cartellaVerbali . 'archivio/VERBALI_GEOLOGIA/', $all[0]['p']);
        $this->assertSame(1, (int) $this->db->valore("SELECT COUNT(*) FROM didattica_sedute WHERE nome_originale = 'RRC_L-34.pdf'"));
        // un allegato nuovo nella cartella del verbale: la scansione successiva lo aggiunge
        file_put_contents($this->radice . 'VERBALI_GEOLOGIA/CONSIGLIO_CORSO_DI_STUDIO/2025/Verb. CCL_02-10-2025/ALLEGATO_Tirocini.xlsx', 'x');
        $r = $this->scan->esegui(100);
        $this->assertSame(1, $r['allegati_aggiornati']);
        $this->assertCount(3, json_decode((string) $this->db->valore("SELECT allegati_json FROM didattica_sedute WHERE nome_originale = 'Verb. CCL_02-10-2025.pdf'"), true));
        // il verbale di Geologia: i dati del consiglio, non di un organo nuovo
        $this->assertSame(3, (int) $this->db->valore("SELECT COUNT(*) FROM didattica_sedute s JOIN didattica_consigli c ON c.id = s.consiglio_id WHERE c.nome LIKE '%Scienze Geologiche%' AND s.da_cartella = 1"), 'verbale 2019, RRC e verbale 2025');
    }

    public function testRegoleDelleCartelleDeiVerbali(): void
    {
        $this->assertNull(ScansioneArchivio::cartellaVerbale('Biologia/2019/Verbale_1.pdf', 'Biologia'));
        $this->assertNull(ScansioneArchivio::cartellaVerbale('Biologia/Verbale_1.pdf', 'Biologia'));
        $this->assertNull(ScansioneArchivio::cartellaVerbale('Biologia/2019-2020/Verbale_1.pdf', 'Biologia'));
        $this->assertSame('Biologia/2025/Verb. CCL_02-10-2025', ScansioneArchivio::cartellaVerbale('Biologia/2025/Verb. CCL_02-10-2025/Verb. CCL_02-10-2025.pdf', 'Biologia'));
        $this->assertSame('Biologia/2025/Verb. CCL_02-10-2025', ScansioneArchivio::cartellaVerbale('Biologia/2025/Verb. CCL_02-10-2025/Altri/doc.pdf', 'Biologia'), 'le sottocartelle sono ancora allegati dello stesso verbale');
        $this->assertSame('Biologia/Verbale 3', ScansioneArchivio::cartellaVerbale('Biologia/Verbale 3/v.pdf', 'Biologia'), 'cartella del verbale subito sotto l\'organo');

        $this->assertSame('Verb. CCL_02-10-2025.pdf', ScansioneArchivio::scegliVerbale(['ALLEGATO_a.pdf', 'Verb. CCL_02-10-2025.pdf'], 'Verb. CCL_02-10-2025'));
        $this->assertSame('02_verbale_x.pdf', ScansioneArchivio::scegliVerbale(['ALLEGATO_a.pdf', 'tabella.pdf', '02_verbale_x.pdf'], 'Cartella'), 'quello che si chiama verbale');
        $this->assertSame('solo.pdf', ScansioneArchivio::scegliVerbale(['solo.pdf', 'Allegato 1.pdf'], 'Qualcosa'));
        $this->assertNull(ScansioneArchivio::scegliVerbale(['ALLEGATO_a.pdf', 'allegato_b.pdf'], 'Cartella'), 'solo allegati: nessun verbale');
        $this->assertTrue(ScansioneArchivio::eAnno('A.A. 2019/20'));
        $this->assertFalse(ScansioneArchivio::eAnno('Verbale 2019'));
    }

    public function testCartellaSenzaVerbaleEAllegatoSciolto(): void
    {
        $this->servizio(ProposteCartelle::class)->applica();
        $base = 'VERBALI_MOTORIE/CONSIGLIO_CORSO_DI_STUDIO/2021/';
        $this->pdf($base . 'Solo allegati/ALLEGATO_1.pdf', 'a');
        $this->pdf($base . 'ALLEGATO_sciolto.pdf', 'b');
        $r = $this->scan->esegui(100);
        $this->assertSame(['VERBALI_MOTORIE/CONSIGLIO_CORSO_DI_STUDIO/2021/Solo allegati'], $r['senza_verbale']);
        $this->assertSame(['VERBALI_MOTORIE/CONSIGLIO_CORSO_DI_STUDIO' => 1], $r['allegati']);
        $this->assertSame(0, (int) $this->db->valore("SELECT COUNT(*) FROM didattica_sedute WHERE nome_originale LIKE 'ALLEGATO%'"));
    }
    public function testPausaDellaScansioneAutomatica(): void
    {
        $this->servizio(ProposteCartelle::class)->applica();
        $this->assertFalse($this->scan->inPausa());
        $this->scan->pausa(true);
        $this->assertTrue($this->scan->inPausa());
        $this->assertSame('', $this->scan->cron(), 'in pausa il cron non legge nulla');
        $this->assertSame(0, (int) $this->db->valore('SELECT COUNT(*) FROM didattica_sedute WHERE da_cartella = 1'));
        $this->scan->pausa(false);
        $this->assertStringContainsString('nuovi', $this->scan->cron());
        $this->assertGreaterThan(0, (int) $this->db->valore('SELECT COUNT(*) FROM didattica_sedute WHERE da_cartella = 1'));
    }
}
