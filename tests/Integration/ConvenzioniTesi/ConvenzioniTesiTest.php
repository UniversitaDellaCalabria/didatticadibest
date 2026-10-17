<?php

declare(strict_types=1);

namespace Tests\Integration\ConvenzioniTesi;

use App\Anagrafi\ClientApiAteneo;
use App\ConvenzioniTesi\Costanti;
use App\ConvenzioniTesi\ServizioConvenzioniTesi;
use App\Core\Container;
use App\Core\Database;
use App\Core\Orologio;
use App\Core\Schema\Definizioni;
use App\Core\Sito;
use App\Infrastructure\Mail\Mailer;
use App\Infrastructure\Pdf\VerificaFirme;
use App\Tutorato\FirmaRemota;
use Tests\Doppi\AnagrafiClientApiFinto;
use Tests\Doppi\FirmaRemotaFinta;
use Tests\Doppi\FirmeFinte;
use Tests\Doppi\MailerFinto;
use Tests\Doppi\OrologioFisso;
use Tests\Integration\ConDirezione;
use Tests\Integration\DatabaseDiProva;

/** Convenzioni di tesi: dall'invito all'azienda fino alla stipula, con modifiche agli articoli, firma del Direttore, archivio ed elenco pubblico. */
final class ConvenzioniTesiTest extends DatabaseDiProva
{
    use ConDirezione;

    private Container $c;
    private MailerFinto $mailer;
    private string $radice;

    protected function tabelle(): array
    {
        return $this->tabelleUffici() + [
            'utenti' => 'CREATE TABLE utenti (id INT PRIMARY KEY, email VARCHAR(150) NULL, ruolo_id INT DEFAULT 5, codice_fiscale VARCHAR(16) NULL)',
            'impostazioni_sistema' => 'CREATE TABLE impostazioni_sistema (id INT PRIMARY KEY, ' . self::COLONNE_ASSENZA . ')',
            'convenzioni_tesi' => Definizioni::tabelle()['convenzioni_tesi'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->radice = sys_get_temp_dir() . '/tesi_' . bin2hex(random_bytes(4));
        mkdir($this->radice . '/modelli_documenti', 0755, true);
        copy(dirname(__DIR__, 3) . '/modelli_documenti/convenzione_tesi.docx', $this->radice . '/modelli_documenti/convenzione_tesi.docx');
        mkdir($this->radice . '/assets/modelli', 0755, true);
        copy(dirname(__DIR__, 3) . '/assets/modelli/logo_lettera_incarico.jpg', $this->radice . '/assets/modelli/logo_lettera_incarico.jpg');
        $this->mailer = new MailerFinto();
        $c = new Container();
        $c->istanza(Container::class, $c);
        $c->istanza(Database::class, $this->db);
        $c->istanza(Mailer::class, $this->mailer);
        $c->istanza(Orologio::class, new OrologioFisso('2026-10-19 09:00:00'));
        $c->istanza(Sito::class, new Sito($this->radice, 'https://portale.test/eventi'));
        $c->istanza(VerificaFirme::class, new FirmeFinte());
        $c->istanza(FirmaRemota::class, new FirmaRemotaFinta());
        $c->istanza(ClientApiAteneo::class, new AnagrafiClientApiFinto());
        $this->c = $c;
        $this->db->esegui('INSERT INTO impostazioni_sistema (id) VALUES (1)');
        $this->db->esegui("INSERT INTO utenti (id, email, ruolo_id) VALUES (1, 'admin@x.it', 1), (2, 'direttore@unical.it', 4)");
        $this->db->esegui("INSERT INTO uffici (nome, chiave) VALUES ('Ufficio convenzioni di tesi', 'convenzioni_tesi')");
        $this->db->esegui("INSERT INTO uffici_persone (ufficio_id, email, nominativo) VALUES (1, 'Protocollo@Unical.it', 'Anna Protocollo')");
        $this->impostaDirettore('Mario Bianchi', 'direttore@unical.it');
    }

    protected function tearDown(): void
    {
        $this->rimuovi($this->radice);
    }

    private function rimuovi(string $d): void
    {
        foreach (glob($d . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (basename($f) === '.' || basename($f) === '..') {
                continue;
            }
            is_dir($f) ? $this->rimuovi($f) : @unlink($f);
        }
        @rmdir($d);
    }

    private function s(): ServizioConvenzioniTesi
    {
        return $this->c->get(ServizioConvenzioniTesi::class);
    }

    /** @return array<string, string|null> */
    private function riga(int $id): array
    {
        return $this->s()->perId($id) ?? [];
    }

    private const POST = ['ragione_sociale' => 'BioLab Calabria S.r.l.', 'forma_giuridica' => 'S.r.l.', 'piva' => '01234567890', 'cf' => '01234567890', 'comune' => 'Rende', 'provincia' => 'cs',
        'indirizzo' => 'Via Pietro Bucci 12', 'pec' => 'BioLab@pec.example.it', 'rappr_titolo' => 'Dott.ssa', 'rappr_nome' => 'Maria Rossi', 'rappr_qualifica' => 'Amministratore Unico',
        'settore' => 'Biotecnologie', 'sito' => 'www.biolab.example.it', 'consenso_elenco' => '1'];

    /** Un'azienda invitata che ha compilato i dati (senza modifiche). */
    private function compilata(string $email = 'info@biolab.it'): int
    {
        [$e, $id] = $this->s()->avvia($email, 'BioLab', Costanti::ORIGINE_INVITO, 'Anna');
        $this->assertNull($e);
        $this->assertSame([], $this->s()->salvaDati($this->riga($id), self::POST));

        return $id;
    }

    /** Un PDF «firmato» (i test contano i %%FIRMA). */
    private function pdfFirmato(string $titolo = 'azienda', int $firme = 1): string
    {
        return "%PDF-1.7\n% $titolo\n" . str_repeat("%%FIRMA\n", $firme) . '%%EOF';
    }

    /** La convenzione fino al punto in cui il Direttore ha già firmato. */
    private function firmataDalDirettore(string $email = 'info@biolab.it'): int
    {
        $id = $this->compilata($email);
        $this->assertNull($this->s()->carica($this->riga($id), $this->pdfFirmato()));
        $es = $this->s()->protocolloIngresso($id, '1234', '2026-10-20', 'Anna');
        $this->assertNull($es['errore']);
        $this->assertNull($es['direttore']);
        $c = $this->riga($id);
        $this->assertNull($this->s()->caricaFirmatoDirettore($c, (string) file_get_contents($this->radice . '/' . $c['file_firmato']) . "%%FIRMA\n"));

        return $id;
    }

    public function testInvitoERichiestaRipetuta(): void
    {
        [$e] = $this->s()->avvia('non-una-email', 'X', Costanti::ORIGINE_INVITO);
        $this->assertStringContainsString('email valido', (string) $e);
        [$e] = $this->s()->avvia('info@x.it', '', Costanti::ORIGINE_PUBBLICA);
        $this->assertStringContainsString('nome dell\'ente', (string) $e);

        [$e, $id] = $this->s()->avvia('Info@BioLab.it', 'BioLab', Costanti::ORIGINE_INVITO, 'Anna Protocollo');
        $this->assertNull($e);
        $r = $this->riga($id);
        $this->assertSame(['invitata', 'info@biolab.it', 'invito'], [$r['stato'], $r['email'], $r['origine']]);
        $this->assertSame(40, strlen((string) $r['token']));
        $this->assertSame(['info@biolab.it'], array_column($this->mailer->inviate, 'a'));
        $this->assertStringContainsString('convenzione_tesi.php?t=' . $r['token'], $this->mailer->inviate[0]['corpo']);

        // la stessa azienda che richiede di nuovo non apre una seconda convenzione: riceve il link di quella aperta
        [$e, $id2] = $this->s()->avvia('info@biolab.it', 'BioLab', Costanti::ORIGINE_PUBBLICA);
        $this->assertSame($id, $id2);
        $this->assertCount(2, $this->mailer->inviate);
        $this->assertSame(1, (int) $this->db->valore('SELECT COUNT(*) FROM convenzioni_tesi'));
    }

    public function testControlloDeiDati(): void
    {
        [, $err] = $this->s()->validaDati(['ragione_sociale' => 'X', 'piva' => '123', 'pec' => 'no', 'provincia' => 'Cosenza', 'referente_email' => 'xx']);
        $testo = implode(' | ', $err);
        foreach (['il comune', 'l\'indirizzo', 'rappresentante legale', 'qualifica', 'partita IVA ha 11', 'PEC', 'sigla', 'referente'] as $atteso) {
            $this->assertStringContainsString($atteso, $testo);
        }
        [$d, $err] = $this->s()->validaDati(self::POST);
        $this->assertSame([], $err);
        $this->assertSame(['CS', 'biolab@pec.example.it', 'https://www.biolab.example.it'], [$d['provincia'], $d['pec'], $d['sito']]);
    }

    public function testCompilataSenzaModificheScaricaIDocumenti(): void
    {
        $id = $this->compilata();
        $c = $this->riga($id);
        $this->assertSame('compilata', $c['stato']);
        $this->assertSame('BioLab Calabria S.r.l.', $c['azienda']);
        $this->assertSame('1', $c['consenso_elenco']);
        $this->assertTrue($this->s()->pronta($c));
        $word = $this->s()->word($c);
        $this->assertNotNull($word);
        $this->assertFileExists($word);
        @unlink($word);
        $this->assertStringStartsWith('%PDF-', (string) $this->s()->pdf($c));
    }

    public function testModificheAgliArticoliProposteEValutate(): void
    {
        $id = $this->compilata();
        $standard = $this->s()->articoliStandard();
        $this->mailer->inviate = [];
        $art = array_map(static fn (array $a): string => $a['testo'], $standard);
        $art[8] = "Il soggetto ospitante offre ai tesisti una borsa di studio.\n\nResta ferma l'assenza di rapporto di lavoro.";
        $art[10] = $standard[10]['testo'] . ' ';   // solo uno spazio in più: non è una modifica
        $this->assertSame([], $this->s()->salvaDati($this->riga($id), self::POST + ['art' => $art]));
        $c = $this->riga($id);
        $this->assertSame('modifiche', $c['stato']);
        $this->assertSame([8], array_keys($this->s()->modifiche($c)), 'solo l\'articolo cambiato davvero');
        $this->assertFalse($this->s()->pronta($c), 'con modifiche da valutare non si firma');
        $this->assertNull($this->s()->pdf($c));
        $this->assertStringContainsString('decise le modifiche', (string) $this->s()->carica($c, $this->pdfFirmato()));
        $this->assertContains('protocollo@unical.it', array_column($this->mailer->inviate, 'a'), 'l\'ufficio è avvisato');
        $this->assertContains('admin@x.it', array_column($this->mailer->inviate, 'a'));

        // l'ufficio deve scegliere per ogni articolo; per rifiutare serve il motivo
        $this->assertStringContainsString('accettare, rifiutare', (string) $this->s()->rispondiModifiche($c, []));
        $this->assertStringContainsString('perché', (string) $this->s()->rispondiModifiche($c, [8 => ['azione' => 'rifiuta']]));
        $this->assertStringContainsString('testo che proponi', (string) $this->s()->rispondiModifiche($c, [8 => ['azione' => 'controproposta']]));

        // rifiuto: l'azienda vede la risposta e può ritirare la modifica
        $this->mailer->inviate = [];
        $this->assertNull($this->s()->rispondiModifiche($c, [8 => ['azione' => 'rifiuta', 'risposta' => 'È una clausola standard di Ateneo.']]));
        $c = $this->riga($id);
        $this->assertSame('risposta', $c['stato']);
        $this->assertSame('rifiutata', $this->s()->modifiche($c)[8]['stato']);
        $this->assertSame(['info@biolab.it'], array_column($this->mailer->inviate, 'a'));
        $this->assertFalse($this->s()->pronta($c));
        $this->assertSame([], $this->s()->salvaDati($c, self::POST + ['art' => $art, 'ritira' => [8 => '1']]));
        $c = $this->riga($id);
        $this->assertSame(['compilata', []], [$c['stato'], $this->s()->modifiche($c)]);
        $this->assertTrue($this->s()->pronta($c));
    }

    public function testContropropostaAccettataEAccettazioneEntranoNelDocumento(): void
    {
        $id = $this->compilata();
        $art = array_map(static fn (array $a): string => $a['testo'], $this->s()->articoliStandard());
        $art[8] = 'Il soggetto ospitante può offrire borse di studio ai tesisti.';
        $art[11] = 'Le spese di bollo sono a carico del soggetto ospitante.';
        $this->s()->salvaDati($this->riga($id), self::POST + ['art' => $art]);
        $this->assertNull($this->s()->rispondiModifiche($this->riga($id), [
            8 => ['azione' => 'controproposta', 'testo' => 'Il soggetto ospitante comunica per iscritto le eventuali borse di studio.', 'risposta' => 'Meglio così'],
            11 => ['azione' => 'accetta'],
        ]));
        $c = $this->riga($id);
        $this->assertSame('risposta', $c['stato']);
        $this->assertSame(['controproposta', 'accettata'], array_column($this->s()->modifiche($c), 'stato'));
        // l'azienda accetta la controproposta dell'ufficio
        $this->assertSame([], $this->s()->salvaDati($c, self::POST + ['art' => $art, 'accetta' => [8 => '1']]));
        $c = $this->riga($id);
        $this->assertSame('compilata', $c['stato']);
        $this->assertSame([8 => 'Il soggetto ospitante comunica per iscritto le eventuali borse di studio.', 11 => 'Le spese di bollo sono a carico del soggetto ospitante.'], $this->s()->concordati($c));
        $w = (string) $this->s()->word($c);
        $zip = new \ZipArchive();
        $zip->open($w);
        $xml = strip_tags((string) $zip->getFromName('word/document.xml'));
        $zip->close();
        @unlink($w);
        $this->assertStringContainsString('comunica per iscritto le eventuali borse di studio', $xml);
        $this->assertStringContainsString('spese di bollo sono a carico del soggetto ospitante', $xml);
        $this->assertStringNotContainsString('imposta di bollo assolta', $xml);
    }

    public function testCaricaIlPdfFirmatoDaLAzienda(): void
    {
        $id = $this->compilata();
        $this->mailer->inviate = [];
        $this->assertStringContainsString('non è un PDF', (string) $this->s()->carica($this->riga($id), 'testo'));
        $this->assertStringContainsString('firma digitale PAdES', (string) $this->s()->carica($this->riga($id), "%PDF-1.7\nnessuna firma\n%%EOF"));
        $this->assertNull($this->s()->carica($this->riga($id), $this->pdfFirmato()));
        $c = $this->riga($id);
        $this->assertSame('caricata', $c['stato']);
        $this->assertFileExists($this->radice . '/' . $c['file_firmato']);
        $a = array_column($this->mailer->inviate, 'a');
        $this->assertContains('protocollo@unical.it', $a);
        $this->assertContains('info@biolab.it', $a, 'ricevuta all\'azienda');
        // si può sostituire finché l'ufficio non la prende in carico: il vecchio file sparisce
        $vecchio = $c['file_firmato'];
        $this->assertNull($this->s()->carica($c, $this->pdfFirmato('nuova')));
        $this->assertFileDoesNotExist($this->radice . '/' . $vecchio);
        $this->assertNotNull($this->s()->fileDaScaricare($id, 'azienda'));
        $this->assertNull($this->s()->fileDaScaricare($id, 'direttore'));
    }

    public function testProtocolloInEntrataEFirmaDelDirettore(): void
    {
        $id = $this->compilata();
        $this->s()->carica($this->riga($id), $this->pdfFirmato());
        $this->mailer->inviate = [];
        $this->assertStringContainsString('numero del protocollo', (string) $this->s()->protocolloIngresso($id, ' ', '2026-10-20', 'Anna')['errore']);
        $es = $this->s()->protocolloIngresso($id, '1234', '2026-10-20', 'Anna');
        $this->assertSame([null, null], [$es['errore'], $es['direttore']]);
        $c = $this->riga($id);
        $this->assertSame(['da_firmare', '1234'], [$c['stato'], $c['protocollo_ingresso']]);
        $this->assertSame(['info@biolab.it', 'direttore@unical.it'], array_column($this->mailer->inviate, 'a'));
        $this->assertStringContainsString('firma_convenzione_tesi.php?t=' . $c['token_direttore'], $this->mailer->inviate[1]['corpo']);
        $this->assertStringContainsString('non è in attesa del protocollo', (string) $this->s()->protocolloIngresso($id, '999', '', 'x')['errore']);
        $this->assertTrue($this->s()->puoFirmare(['id' => 2, 'email' => 'Direttore@Unical.it']));
        $this->assertFalse($this->s()->puoFirmare(['id' => 3, 'email' => 'altro@unical.it']));

        // il PDF firmato deve essere quello dell'azienda con una firma in più
        $this->assertStringContainsString('non corrisponde', (string) $this->s()->caricaFirmatoDirettore($c, $this->pdfFirmato('altro documento', 2)));
        $this->assertStringContainsString('nuova firma', (string) $this->s()->caricaFirmatoDirettore($c, (string) file_get_contents($this->radice . '/' . $c['file_firmato']) . "
"));
        $this->mailer->inviate = [];
        $this->assertNull($this->s()->caricaFirmatoDirettore($c, (string) file_get_contents($this->radice . '/' . $c['file_firmato']) . "%%FIRMA\n"));
        $c = $this->riga($id);
        $this->assertSame('firmata_direttore', $c['stato']);
        $this->assertContains('protocollo@unical.it', array_column($this->mailer->inviate, 'a'));
        $this->assertNotNull($this->s()->fileDaScaricare($id, 'direttore'));
        $this->assertStringContainsString('non è in attesa', (string) $this->s()->caricaFirmatoDirettore($c, 'x'));
    }

    public function testSenzaDirettoreIlLinkNonPartePoiSiRimanda(): void
    {
        $id = $this->compilata();
        $this->s()->carica($this->riga($id), $this->pdfFirmato());
        $this->togliDirettore();
        $this->mailer->inviate = [];
        $es = $this->s()->protocolloIngresso($id, '77', '', 'Anna');
        $this->assertNull($es['errore']);
        $this->assertStringContainsString('Indica prima il Direttore', (string) $es['direttore']);
        $this->assertSame('in_carico', $this->riga($id)['stato']);
        $this->impostaDirettore('Mario Bianchi', 'direttore@unical.it');
        $this->assertNull($this->s()->inviaDirettore($id));
        $this->assertSame('da_firmare', $this->riga($id)['stato']);
        // con «Direttore assente» il link va al vice
        $this->impostaDirettore('Vera Vice', 'vice@unical.it', '', 'Vice direttore');
        $this->db->esegui('UPDATE impostazioni_sistema SET direttore_assente = 1 WHERE id = 1');
        $this->mailer->inviate = [];
        $this->assertNull($this->s()->inviaDirettore($id));
        $this->assertSame(['vice@unical.it'], array_column($this->mailer->inviate, 'a'));
    }

    public function testRifiutoConMotivo(): void
    {
        $id = $this->compilata();
        $this->s()->carica($this->riga($id), $this->pdfFirmato());
        $this->mailer->inviate = [];
        $this->assertStringContainsString('motivo', (string) $this->s()->rifiuta($id, ' '));
        $this->assertNull($this->s()->rifiuta($id, 'Firma del legale rappresentante mancante'));
        $c = $this->riga($id);
        $this->assertSame(['rifiutata', 'Firma del legale rappresentante mancante'], [$c['stato'], $c['nota_ufficio']]);
        $this->assertStringContainsString('Firma del legale', $this->mailer->inviate[0]['corpo']);
        $this->assertTrue(ServizioConvenzioniTesi::caricabile($c), 'l\'azienda ricarica');
        $this->assertNull($this->s()->carica($c, $this->pdfFirmato('corretta')));
        $this->assertSame('caricata', $this->riga($id)['stato']);
        $this->assertStringContainsString('non si può rifiutare', (string) $this->s()->rifiuta($this->compilata('altra@x.it'), 'no'));
    }

    public function testProtocolloDefinitivoEStipula(): void
    {
        $id = $this->firmataDalDirettore();
        $ok = ['protocollo' => '2201', 'protocollo_data' => '2026-10-25', 'valida_dal' => '2026-10-25', 'valida_al' => '2031-10-24'];
        $this->assertStringContainsString('numero di protocollo', (string) $this->s()->completa($id, ['protocollo' => ''] + $ok));
        $this->assertStringContainsString('date di validità', (string) $this->s()->completa($id, ['valida_al' => '2020-01-01'] + $ok));
        $this->assertStringContainsString('deve essere un PDF', (string) $this->s()->completa($id, $ok, 'non è un pdf'));
        $this->assertSame('firmata_direttore', $this->riga($id)['stato']);
        $this->mailer->inviate = [];
        $this->assertNull($this->s()->completa($id, $ok));
        $c = $this->riga($id);
        $this->assertSame(['protocollata', '2201', '2031-10-24'], [$c['stato'], $c['protocollo'], $c['valida_al']]);
        $this->assertSame(['info@biolab.it'], array_column($this->mailer->inviate, 'a'));
        $this->assertStringContainsString('2201', $this->mailer->inviate[0]['corpo']);
        $this->assertStringContainsString('già stata protocollata', (string) $this->s()->completa($id, $ok));
        // senza PDF con la segnatura si scarica quello firmato dal Direttore; con la segnatura, quello
        $this->assertStringContainsString('firmata_dal_Direttore', (string) $this->s()->fileDaScaricare($id, 'finale')['nome']);
        $id2 = $this->firmataDalDirettore('seconda@x.it');
        $this->assertNull($this->s()->completa($id2, $ok, "%PDF-1.7\nsegnatura 2202\n%%EOF"));
        $this->assertStringContainsString('protocollata', (string) $this->s()->fileDaScaricare($id2, 'finale')['nome']);
        // l'elenco pubblico: stipulata, valida oggi, con il consenso
        $this->assertSame(['BioLab Calabria S.r.l.', 'BioLab Calabria S.r.l.'], array_column($this->s()->elencoPubblico(), 'azienda'));
    }

    public function testArchivioConConvenzioniGiaInEssereEElencoPubblico(): void
    {
        $post = ['ragione_sociale' => 'Geo Studio S.n.c.', 'comune' => 'Cosenza', 'provincia' => 'CS', 'settore' => 'Geologia', 'sito' => 'geostudio.example.it', 'protocollo' => '55/2024',
            'protocollo_data' => '2024-03-01', 'valida_dal' => '2024-03-01', 'valida_al' => '2029-02-28', 'consenso_elenco' => '1', 'email' => 'geo@x.it'];
        [$err] = $this->s()->archivia(['ragione_sociale' => ''] + $post, '', 'Anna');
        $this->assertCount(1, $err);
        [$err] = $this->s()->archivia(['valida_al' => '2020-01-01'] + $post, '', 'Anna');
        $this->assertStringContainsString('date di validità', implode(' ', $err));
        [$err, $id] = $this->s()->archivia($post, "%PDF-1.7\nvecchia convenzione\n%%EOF", 'Anna');
        $this->assertSame([], $err);
        $c = $this->riga($id);
        $this->assertSame(['protocollata', 'archivio'], [$c['stato'], $c['origine']]);
        $this->assertNotNull($this->s()->fileDaScaricare($id, 'finale'));
        // altre due: una scaduta e una senza consenso a comparire
        $this->s()->archivia(['ragione_sociale' => 'Vecchia S.p.A.', 'protocollo' => '1', 'valida_dal' => '2015-01-01', 'valida_al' => '2020-01-01', 'consenso_elenco' => '1'], '', 'Anna');
        $this->s()->archivia(['ragione_sociale' => 'Riservata S.r.l.', 'protocollo' => '2', 'valida_dal' => '2026-01-01', 'valida_al' => '2031-01-01'], '', 'Anna');
        $this->assertSame(['Geo Studio S.n.c.'], array_column($this->s()->elencoPubblico(), 'azienda'), 'solo valida e con consenso');
        $this->assertCount(3, $this->s()->archivio());
        $this->assertSame(['Geo Studio S.n.c.', 'Riservata S.r.l.'], array_column($this->s()->archivio('', 'valide'), 'azienda'));
        $this->assertSame(['Vecchia S.p.A.'], array_column($this->s()->archivio('', 'scadute'), 'azienda'));
        $this->assertSame(['Geo Studio S.n.c.'], array_column($this->s()->archivio('Geo'), 'azienda'));
        // modifica di una voce dell'archivio
        [, $id2] = $this->s()->archivia(['valida_al' => '2032-02-02'] + $post, '', 'Anna', $id);
        $this->assertSame([$id, '2032-02-02'], [$id2, $this->riga($id)['valida_al']]);
        $this->s()->elimina($id);
        $this->assertNull($this->s()->perId($id));
    }

    public function testCronPromemoriaEScadenze(): void
    {
        [, $fermo] = $this->s()->avvia('ferma@x.it', 'Ferma', Costanti::ORIGINE_INVITO);
        $this->db->esegui("UPDATE convenzioni_tesi SET aggiornata_il = '2026-10-01 10:00:00', creata_il = '2026-09-20 10:00:00' WHERE id = ?", [$fermo]);
        [, $recente] = $this->s()->avvia('recente@x.it', 'Recente', Costanti::ORIGINE_INVITO);
        $this->db->esegui("UPDATE convenzioni_tesi SET aggiornata_il = '2026-10-18 10:00:00' WHERE id = ?", [$recente]);
        [, $antica] = $this->s()->avvia('antica@x.it', 'Antica', Costanti::ORIGINE_INVITO);
        $this->db->esegui("UPDATE convenzioni_tesi SET aggiornata_il = '2026-01-01 10:00:00', creata_il = '2026-01-01 10:00:00' WHERE id = ?", [$antica]);
        // una stipulata che scade tra un mese
        $this->s()->archivia(['ragione_sociale' => 'In Scadenza S.r.l.', 'email' => 'scade@x.it', 'protocollo' => '9', 'valida_dal' => '2021-11-18', 'valida_al' => '2026-11-18', 'consenso_elenco' => '1'], '', 'Anna');
        $this->mailer->inviate = [];
        $r = $this->s()->cron();
        $this->assertStringContainsString('1 promemoria, 1 in scadenza', $r);
        $a = array_column($this->mailer->inviate, 'a');
        $this->assertContains('ferma@x.it', $a);
        $this->assertContains('scade@x.it', $a, 'l\'azienda sa che scade');
        $this->assertContains('protocollo@unical.it', $a, 'l\'ufficio sa che scade');
        $this->assertNotContains('recente@x.it', $a);
        $this->assertNotContains('antica@x.it', $a, 'oltre i 120 giorni non si insiste');
        $this->mailer->inviate = [];
        $this->assertSame('', $this->s()->cron(), 'una volta sola');
        $this->assertSame([], $this->mailer->inviate);
    }

    public function testRinnovo(): void
    {
        [, $id] = $this->s()->archivia(['ragione_sociale' => 'In Scadenza S.r.l.', 'email' => 'scade@x.it', 'piva' => '01234567890', 'protocollo' => '9', 'valida_dal' => '2021-11-18', 'valida_al' => '2027-11-18'], '', 'Anna');
        $this->assertStringContainsString('si può rinnovare negli ultimi', (string) $this->s()->rinnova($id)[0]);
        $this->db->esegui("UPDATE convenzioni_tesi SET valida_al = '2026-12-01' WHERE id = ?", [$id]);
        $this->mailer->inviate = [];
        [$e, $token] = $this->s()->rinnova($id);
        $this->assertNull($e);
        $nuova = $this->s()->perToken($token) ?? [];
        $this->assertSame(['compilata', 'In Scadenza S.r.l.', '01234567890'], [$nuova['stato'], $nuova['azienda'], $this->s()->dati($nuova)['piva']]);
        $this->assertSame(['scade@x.it'], array_column($this->mailer->inviate, 'a'));
        [, $stesso] = $this->s()->rinnova($id);
        $this->assertSame($token, $stesso, 'non se ne apre una seconda');
    }
}
