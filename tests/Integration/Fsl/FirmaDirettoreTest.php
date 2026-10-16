<?php

declare(strict_types=1);

namespace Tests\Integration\Fsl;

use App\Core\Sito;
use App\Fsl\Costanti;
use App\Fsl\ServizioConvenzioneFirmata;
use App\Fsl\ServizioFirmaDirettore;
use App\Infrastructure\Pdf\VerificaFirme;
use App\Tutorato\FirmaRemota;
use Tests\Doppi\FirmaRemotaFinta;
use Tests\Doppi\FirmeFinte;

/** Firma del Direttore sulla convenzione: invio del link, firma remota o PDF caricato, controlli sul file e protocollo finale. */
final class FirmaDirettoreTest extends FslBase
{
    private string $radiceProva;
    private FirmaRemotaFinta $aruba;

    protected function setUp(): void
    {
        parent::setUp();
        $this->radiceProva = sys_get_temp_dir() . '/fsl_firmadir_' . bin2hex(random_bytes(4));
        mkdir($this->radiceProva . '/uploads/convenzioni_compilate/firmate', 0755, true);
        $this->c->istanza(Sito::class, new Sito($this->radiceProva, 'https://portale.test/eventi'));
        $this->c->istanza(VerificaFirme::class, new FirmeFinte());
        $this->aruba = new FirmaRemotaFinta();
        $this->c->istanza(FirmaRemota::class, $this->aruba);
        $this->db->esegui("INSERT INTO impostazioni_sistema (id) VALUES (1)");
        $this->db->esegui("INSERT INTO utenti (id, email, ruolo_id) VALUES (1, 'admin@x.it', 1), (2, 'conv@x.it', 3)");
        $this->db->esegui("INSERT INTO abilitazioni_ambito (utente_id, tipo) VALUES (2, 'fsl_convenzioni')");
    }

    protected function tearDown(): void
    {
        $this->rimuovi($this->radiceProva);
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

    private function firma(): ServizioFirmaDirettore
    {
        return $this->servizio(ServizioFirmaDirettore::class);
    }

    /** Convenzione già firmata dalla scuola e presa in carico dall'ufficio (file reale nella cartella delle firmate). @return array<string, mixed> */
    private function inCarico(): array
    {
        $this->db->esegui("INSERT IGNORE INTO utenti (id, email, codice_fiscale) VALUES (50, 'direttore@unical.it', NULL)");
        $this->impostaDirettore('Prof. Mario Bianchi', 'Direttore@Unical.it', 'bnchmr60a01h501x');
        $pdf = "%PDF-1.7\n<< /Type /Pages /Count 3 /Kids [] >>\n%%FIRMA\n%%EOF";
        $nome = 'scuola.pdf';
        file_put_contents($this->radiceProva . '/' . Costanti::DIR_FIRMATE . $nome, $pdf);
        $id = $this->db->inserisci("INSERT INTO convenzioni_compilate (token, scuola_codice, prenotazione_id, email, dati_json, file_firmato, firmatario, firmata_il, stato_iter) VALUES (?, 'CSPS00001A', 1, 'scuola@x.it', ?, ?, 'Maria Rossi', NOW(), 'in_carico')",
            ['tok1', '{"scuola":{"denominazione":"Liceo Galilei","codice":"CSIS00001A","email":"dirigente@scuola.it"}}', Costanti::DIR_FIRMATE . $nome]);

        return $this->riga('convenzioni_compilate', $id) ?? [];
    }

    public function testIlDirettoreVieneDallaDirezione(): void
    {
        $s = $this->firma();
        $this->assertFalse($s->configurato());
        $this->db->esegui("INSERT INTO utenti (id, email, codice_fiscale) VALUES (51, 'm@y.it', NULL), (52, 'vice@y.it', NULL)");
        $this->impostaDirettore('Mario Bianchi', 'M@Y.IT', 'bnchmr60a01h501x');
        $this->assertSame(['nome' => 'Mario Bianchi', 'email' => 'm@y.it', 'cf' => 'BNCHMR60A01H501X'], $s->direttore());
        $this->assertTrue($s->configurato());

        $this->assertTrue($s->puoFirmare(['id' => 5, 'email' => 'M@Y.it']));
        $this->assertFalse($s->puoFirmare(['id' => 5, 'email' => 'altra@y.it', 'codice_fiscale' => 'XXXXXXXXXXXXXXXX']));
        $this->assertFalse($s->puoFirmare(null));
        $this->assertFalse($s->puoFirmare(['email' => 'm@y.it']), 'senza utente collegato');

        // Direttore assente: firma il vice
        $this->impostaDirettore('Vera Vice', 'vice@y.it', '', 'Vice direttore');
        $this->db->esegui('INSERT IGNORE INTO impostazioni_sistema (id) VALUES (1)');
        $this->db->esegui('UPDATE impostazioni_sistema SET direttore_assente = 1 WHERE id = 1');
        $this->assertSame('vice@y.it', $s->direttore()['email']);
        $this->assertTrue($s->puoFirmare(['id' => 6, 'email' => 'vice@y.it']));
        $this->assertFalse($s->puoFirmare(['id' => 5, 'email' => 'm@y.it']), 'assente: non firma lui');
    }

    public function testLUfficioMandaLaConvenzioneAlDirettore(): void
    {
        $s = $this->firma();
        $cc = $this->inCarico();
        $this->mailer->inviate = [];
        $this->togliDirettore();
        $this->assertStringContainsString('Indica prima il Direttore', (string) $s->invia((int) $cc['id']));
        $this->impostaDirettore('Prof. Mario Bianchi', 'direttore@unical.it');
        $this->db->esegui("UPDATE convenzioni_compilate SET stato_iter = 'caricata_scuola'");
        $this->assertStringContainsString('prendi in carico', (string) $s->invia((int) $cc['id']), 'prima va presa in carico');
        $this->db->esegui("UPDATE convenzioni_compilate SET stato_iter = 'in_carico'");

        $this->assertNull($s->invia((int) $cc['id']));
        $r = $this->riga('convenzioni_compilate', (int) $cc['id']) ?? [];
        $this->assertSame('da_firmare', $r['stato_iter']);
        $this->assertSame(40, strlen((string) $r['token_direttore']));
        $this->assertSame(['direttore@unical.it'], array_column($this->mailer->inviate, 'a'));
        $this->assertStringContainsString('firma_convenzione.php?t=' . $r['token_direttore'], $this->mailer->inviate[0]['corpo']);
        $this->assertStringContainsString('Liceo Galilei', $this->mailer->inviate[0]['oggetto']);
        $this->assertNotNull($s->perToken((string) $r['token_direttore']));
        $this->assertNull($s->perToken('sbagliato'));
        $this->assertNull($s->perToken(''));

        $primo = $r['token_direttore'];
        $this->assertNull($s->invia((int) $cc['id']), 'si può rimandare: nuovo link');
        $this->assertNotSame($primo, ($this->riga('convenzioni_compilate', (int) $cc['id']) ?? [])['token_direttore']);
    }

    public function testFirmaRemotaArubaSulPortale(): void
    {
        $s = $this->firma();
        $cc = $this->inCarico();
        $this->assertStringContainsString('non è in attesa', (string) $s->firmaConAruba($cc, 'utente', 'pin', '123456'), 'prima va mandata al Direttore');
        $s->invia((int) $cc['id']);
        $cc = $this->riga('convenzioni_compilate', (int) $cc['id']) ?? [];
        $this->mailer->inviate = [];

        $this->assertStringContainsString('credenziali non valide', (string) $s->firmaConAruba($cc, 'utente', 'sbagliata', '123456'));
        $this->assertSame('da_firmare', ($this->riga('convenzioni_compilate', (int) $cc['id']) ?? [])['stato_iter'], 'firma non riuscita: niente cambia');

        $this->assertNull($s->firmaConAruba($cc, 'utente', 'pin', '123456'));
        $ultima = end($this->aruba->richieste);
        $this->assertSame(3, $ultima['aspetto']['pagina'], 'la firma visibile sta sull\'ultima pagina');
        $this->assertSame('Il Direttore del Dipartimento', $ultima['motivo']);

        $r = $this->riga('convenzioni_compilate', (int) $cc['id']) ?? [];
        $this->assertSame('firmata_direttore', $r['stato_iter']);
        $this->assertStringStartsWith('uploads/convenzioni/', (string) $r['file_definitivo']);
        $this->assertSame(2, substr_count((string) file_get_contents($this->radiceProva . '/' . $r['file_definitivo']), '%%FIRMA'), 'firma della scuola più quella del Direttore');
        $this->assertStringContainsString('Mario Bianchi', (string) $r['firmatario_direttore']);
        $a = array_column($this->mailer->inviate, 'a');
        sort($a);
        $this->assertSame(['admin@x.it', 'conv@x.it', Costanti::EMAIL_UFFICIO], $a, 'l\'ufficio sa che è da protocollare');
        $this->assertStringContainsString('da protocollare', $this->mailer->inviate[0]['oggetto']);
        $this->assertStringContainsString('non è in attesa', (string) $s->firmaConAruba($r, 'utente', 'pin', '123456'), 'non si firma due volte');
    }

    public function testPdfFirmatoCaricatoDalDirettore(): void
    {
        $s = $this->firma();
        $cc = $this->inCarico();
        $s->invia((int) $cc['id']);
        $cc = $this->riga('convenzioni_compilate', (int) $cc['id']) ?? [];
        $originale = (string) file_get_contents($this->radiceProva . '/' . $cc['file_firmato']);

        $this->assertStringContainsString('non corrisponde', (string) $s->caricaFirmato($cc, "%PDF-1.7\nun altro documento\n%%FIRMA\n%%FIRMA"), 'non è la convenzione della scuola');
        $this->assertStringContainsString('non è un PDF', (string) $s->caricaFirmato($cc, 'PK p7m'));
        $this->assertStringContainsString('nuova firma', (string) $s->caricaFirmato($cc, $originale . "\naggiunta senza firma"), 'serve una firma in più');
        $this->assertSame('da_firmare', ($this->riga('convenzioni_compilate', (int) $cc['id']) ?? [])['stato_iter']);

        $this->assertNull($s->caricaFirmato($cc, $originale . "\n%%FIRMA\n"));
        $this->assertSame('firmata_direttore', ($this->riga('convenzioni_compilate', (int) $cc['id']) ?? [])['stato_iter']);
    }

    public function testDopoLaFirmaLUfficioProtocollaSenzaRicaricareIlFile(): void
    {
        $s = $this->firma();
        $cc = $this->inCarico();
        $s->invia((int) $cc['id']);
        $s->firmaConAruba($this->riga('convenzioni_compilate', (int) $cc['id']) ?? [], 'utente', 'pin', '123456');
        $firmata = $this->riga('convenzioni_compilate', (int) $cc['id']) ?? [];
        $inAttesa = $this->pren(100, ['stato' => 'da_approvare', 'convenzione' => 'no', 'email' => 'anna@prova.it']);
        $this->mailer->inviate = [];
        $dati = ['protocollo' => '555', 'protocollo_data' => '2026-10-12', 'data_stipula' => '2026-10-12', 'scadenza' => '2027-10-11'];

        $esito = $this->servizio(ServizioConvenzioneFirmata::class)->completa((int) $cc['id'], '', $dati, 'admin@x.it');

        $this->assertNull($esito['errore']);
        $this->assertSame(1, $esito['prenotazioni']);
        $conv = $this->riga('convenzioni_scuole', $esito['convenzione_id']) ?? [];
        $this->assertSame($firmata['file_definitivo'], $conv['file_convenzione'], 'il registro usa il PDF firmato dal Direttore, senza copie');
        $this->assertSame('protocollata', ($this->riga('convenzioni_compilate', (int) $cc['id']) ?? [])['stato_iter']);
        $this->assertSame('confermata', $this->riga('prenotazioni', $inAttesa)['stato']);
        $this->assertContains('dirigente@scuola.it', array_column($this->mailer->inviate, 'a'));
        $this->assertFileExists($this->radiceProva . '/' . $conv['file_convenzione']);
    }

    public function testIlPdfConLaSegnaturaDiProtocolloSostituisceQuelloDelDirettore(): void
    {
        $s = $this->firma();
        $cc = $this->inCarico();
        $s->invia((int) $cc['id']);
        $s->firmaConAruba($this->riga('convenzioni_compilate', (int) $cc['id']) ?? [], 'utente', 'pin', '123456');
        $firmata = $this->riga('convenzioni_compilate', (int) $cc['id']) ?? [];
        $dati = ['protocollo' => '556', 'protocollo_data' => '2026-10-12', 'data_stipula' => '2026-10-12', 'scadenza' => '2027-10-11'];
        $servizio = $this->servizio(ServizioConvenzioneFirmata::class);

        $this->assertStringContainsString('PAdES', (string) $servizio->completa((int) $cc['id'], "%PDF-1.7\nprotocollo senza firma", $dati, 'admin@x.it')['errore'], 'il PDF protocollato deve avere ancora una firma');

        $esito = $servizio->completa((int) $cc['id'], "%PDF-1.7\nsegnatura di protocollo\n%%FIRMA\n", $dati, 'admin@x.it');
        $this->assertNull($esito['errore']);
        $conv = $this->riga('convenzioni_scuole', $esito['convenzione_id']) ?? [];
        $this->assertNotSame($firmata['file_definitivo'], $conv['file_convenzione'], 'nel registro c\'è il PDF con la segnatura');
        $this->assertStringContainsString('segnatura di protocollo', (string) file_get_contents($this->radiceProva . '/' . $conv['file_convenzione']));
        $this->assertFileExists($this->radiceProva . '/' . $firmata['file_definitivo'], 'il PDF firmato dal Direttore resta');
        $this->assertNotNull($servizio->fileDaScaricare((int) $cc['id'], 'direttore'));
    }

    public function testSenzaIlFileDelDirettoreSiRichiedeIlPdfDefinitivo(): void
    {
        $cc = $this->inCarico();
        $r = $this->servizio(ServizioConvenzioneFirmata::class)->completa((int) $cc['id'], '', ['protocollo' => '1', 'data_stipula' => '2026-10-12', 'scadenza' => '2027-10-11'], 'admin@x.it');
        $this->assertStringContainsString('Carica il PDF', (string) $r['errore']);
    }

    public function testIPdfDelDirettoreSiScaricanoSoloDallaCartellaProtetta(): void
    {
        $s = $this->servizio(ServizioConvenzioneFirmata::class);
        $cc = $this->inCarico();
        $this->assertNull($s->fileDaScaricare((int) $cc['id'], 'direttore'));
        file_put_contents($this->radiceProva . '/fuori.pdf', 'x');
        $this->db->esegui("UPDATE convenzioni_compilate SET file_definitivo = 'fuori.pdf' WHERE id = ?", [(int) $cc['id']]);
        $this->assertNull($s->fileDaScaricare((int) $cc['id'], 'direttore'), 'fuori da uploads/convenzioni');
        $this->assertNotNull($s->fileDaScaricare((int) $cc['id'], 'scuola'));
    }

    public function testConteggioDelleDiPagine(): void
    {
        $this->assertSame(7, ServizioFirmaDirettore::contaPagine("<< /Type /Pages /Kids [1 0 R] /Count 7 >>"));
        $this->assertSame(4, ServizioFirmaDirettore::contaPagine("<< /Count 4 /Kids [] /Type /Pages >>"));
        $this->assertSame(9, ServizioFirmaDirettore::contaPagine("<< /Type /Pages /Count 2 >> << /Type /Pages /Count 9 >>"), 'il dizionario più grande è la radice');
        $this->assertSame(0, ServizioFirmaDirettore::contaPagine('%PDF-1.7 flussi compressi'));
        $this->assertNull($this->firma()->riquadro('%PDF-1.7 flussi compressi'), 'senza pagine: firma invisibile');
    }
}
