<?php

declare(strict_types=1);

namespace Tests\Integration\Fsl;

use App\Core\Sito;
use App\Fsl\Costanti;
use App\Fsl\ServizioConvenzioneFirmata;
use App\Infrastructure\Pdf\VerificaFirme;
use App\Tutorato\FirmaRemota;
use Tests\Doppi\FirmaRemotaFinta;

/** Convenzione firmata caricata dalla scuola: controlli sul PDF, sostituzione, presa in carico, avvisi e download protetto. */
final class ConvenzioneFirmataTest extends FslBase
{
    private string $radiceProva;

    protected function setUp(): void
    {
        parent::setUp();
        $this->radiceProva = sys_get_temp_dir() . '/fsl_firmata_' . bin2hex(random_bytes(4));
        mkdir($this->radiceProva . '/uploads/convenzioni_compilate', 0755, true);
        $this->c->istanza(Sito::class, new Sito($this->radiceProva, 'https://portale.test/eventi'));
        $this->c->istanza(FirmaRemota::class, new FirmaRemotaFinta());
        // «%%FIRMA» = firma PAdES valida che copre tutto il file; «%%MODIFICATO» = firma con il file cambiato dopo; «%%ROTTA» = struttura non valida
        $this->c->istanza(VerificaFirme::class, new class () implements VerificaFirme {
            public function firmePades(string $pdf): array
            {
                if (str_contains($pdf, '%%ROTTA')) {
                    return [['subfilter' => 'ETSI.CAdES.detached', 'nome' => '', 'data' => '', 'valida_struttura' => false, 'copre_tutto' => true]];
                }
                $copre = !str_contains($pdf, '%%MODIFICATO');

                return str_contains($pdf, '%%FIRMA') || !$copre ? [['subfilter' => 'ETSI.CAdES.detached', 'nome' => 'Maria Rossi', 'data' => '', 'valida_struttura' => true, 'copre_tutto' => $copre]] : [];
            }
        });
        $this->db->esegui("INSERT INTO utenti (id, email, ruolo_id) VALUES (1, 'admin@x.it', 1), (2, 'conv@x.it', 3), (3, 'fsl@x.it', 3), (4, 'altro@x.it', 3), (5, 'ADMIN@x.it', 1)");
        $this->db->esegui("INSERT INTO abilitazioni_ambito (utente_id, tipo) VALUES (2, 'fsl_convenzioni'), (3, 'fsl'), (4, 'fsl_scuole')");
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

    /** @return array<string, mixed> */
    private function compilazione(string $stato = ''): array
    {
        $id = $this->db->inserisci('INSERT INTO convenzioni_compilate (token, scuola_codice, prenotazione_id, email, dati_json, stato_iter) VALUES (?, ?, ?, ?, ?, ?)',
            ['abc123', 'CSPS00001A', 1, 'scuola@x.it', '{"scuola":{"denominazione":"Liceo Galilei","codice":"CSIS00001A","email":"dirigente@scuola.it"}}', $stato]);

        return $this->riga('convenzioni_compilate', $id) ?? [];
    }

    private function allegato(): string
    {
        return "%PDF-1.7\nAllegato A\n%%EOF";
    }

    private function direttoreConfigurato(): void
    {
        $this->impostaDirettore('Prof. Mario Bianchi', 'direttore@unical.it');
    }

    private function pdf(string $marcatore = '%%FIRMA'): string
    {
        return "%PDF-1.7\n1 0 obj<<>>endobj\n$marcatore\n%%EOF";
    }

    public function testCaricaLaConvenzioneFirmataEAvvisaChiLaGestisce(): void
    {
        $s = $this->servizio(ServizioConvenzioneFirmata::class);
        $cc = $this->compilazione();

        $this->assertNull($s->carica($cc, $this->pdf(), $this->allegato()));

        $r = $this->riga('convenzioni_compilate', (int) $cc['id']);
        $this->assertSame(Costanti::ITER_CARICATA, $r['stato_iter']);
        $this->assertSame('Maria Rossi', $r['firmatario']);
        $this->assertNotNull($r['firmata_il']);
        $this->assertFileExists($this->radiceProva . '/' . $r['file_firmato']);
        $this->assertFileExists($this->radiceProva . '/uploads/convenzioni_compilate/firmate/.htaccess', 'cartella bloccata al web');

        $a = array_column($this->mailer->inviate, 'a');
        sort($a);
        $this->assertSame(['admin@x.it', 'conv@x.it', 'dirigente@scuola.it', 'fsl@x.it', Costanti::EMAIL_UFFICIO], $a, 'amministratori, abilitati FSL e convenzioni, ufficio fisso e ricevuta alla scuola; non chi ha solo le scuole; niente doppioni');
        $avviso = array_values(array_filter($this->mailer->inviate, static fn (array $m): bool => $m['a'] === 'conv@x.it'))[0];
        $this->assertStringContainsString('Liceo Galilei', $avviso['oggetto']);
        $this->assertStringContainsString('Maria Rossi', $avviso['corpo']);
        $this->assertStringContainsString('admin/fsl.php?tab=iter', $avviso['corpo']);
    }

    public function testRifiutaFileCheNonVannoBene(): void
    {
        $s = $this->servizio(ServizioConvenzioneFirmata::class);
        $cc = $this->compilazione();

        $this->assertStringContainsString('non è un PDF', (string) $s->carica($cc, 'PK qualcosa'));
        $this->assertStringContainsString('p7m', (string) $s->carica($cc, $this->pdf('senza firma')));
        $this->assertStringContainsString('modificato dopo la firma', (string) $s->carica($cc, $this->pdf('%%MODIFICATO')));
        $this->assertStringContainsString('non è integra', (string) $s->carica($cc, $this->pdf('%%ROTTA')));
        $this->assertSame('Il file è vuoto.', $s->carica($cc, ''));
        $this->assertStringContainsString('troppo grande', (string) $s->carica($cc, $this->pdf() . str_repeat('x', Costanti::MAX_FIRMATA)));

        $r = $this->riga('convenzioni_compilate', (int) $cc['id']);
        $this->assertSame('', $r['stato_iter'], 'nessun file accettato: lo stato non cambia');
        $this->assertNull($r['file_firmato']);
        $this->assertSame([], $this->mailer->inviate, 'nessun avviso per un file rifiutato');
    }

    public function testLaScuolaPuoSostituireIlFileFinoAllaPresaInCarico(): void
    {
        $s = $this->servizio(ServizioConvenzioneFirmata::class);
        $this->assertNull($s->carica($this->compilazione(), $this->pdf(), $this->allegato()));
        $cc = $this->riga('convenzioni_compilate', 1) ?? [];
        $primo = $this->radiceProva . '/' . $cc['file_firmato'];
        $this->mailer->inviate = [];

        $this->assertNull($s->carica($cc, $this->pdf("%%FIRMA\nseconda versione")));

        $r = $this->riga('convenzioni_compilate', 1) ?? [];
        $this->assertFileDoesNotExist($primo, 'il file sostituito si cancella');
        $this->assertFileExists($this->radiceProva . '/' . $r['file_firmato']);
        $avviso = array_values(array_filter($this->mailer->inviate, static fn (array $m): bool => $m['a'] === 'admin@x.it'))[0];
        $this->assertStringContainsString('nuova versione', $avviso['corpo']);

        $this->db->esegui("UPDATE convenzioni_compilate SET stato_iter = 'in_carico' WHERE id = 1");
        $presa = $this->riga('convenzioni_compilate', 1) ?? [];
        $this->assertFalse(ServizioConvenzioneFirmata::caricabile($presa));
        $this->assertStringContainsString('già stata presa in carico', (string) $s->carica($presa, $this->pdf()));
        $this->assertSame($r['file_firmato'], ($this->riga('convenzioni_compilate', 1) ?? [])['file_firmato'], 'dopo la presa in carico il file non cambia');
    }

    public function testIlDownloadSiFermaAlleCartelleProtette(): void
    {
        $s = $this->servizio(ServizioConvenzioneFirmata::class);
        $cc = $this->compilazione();
        $this->assertNull($s->fileDaScaricare((int) $cc['id']), 'ancora niente da scaricare');
        $s->carica($cc, $this->pdf(), $this->allegato());

        $f = $s->fileDaScaricare((int) $cc['id']);
        $this->assertNotNull($f);
        $this->assertSame('Convenzione_firmata_CSIS00001A.pdf', $f['nome']);
        $this->assertStringContainsString('%%FIRMA', (string) file_get_contents($f['percorso']));

        file_put_contents($this->radiceProva . '/uploads/fuori.pdf', 'segreto');
        $this->db->esegui("UPDATE convenzioni_compilate SET file_firmato = 'uploads/fuori.pdf' WHERE id = ?", [(int) $cc['id']]);
        $this->assertNull($s->fileDaScaricare((int) $cc['id']), 'un percorso fuori dalla cartella delle firmate non si serve');
        $this->db->esegui("UPDATE convenzioni_compilate SET file_firmato = 'uploads/convenzioni_compilate/firmate/../../fuori.pdf' WHERE id = ?", [(int) $cc['id']]);
        $this->assertNull($s->fileDaScaricare((int) $cc['id']), 'nemmeno con i «..»');
        $this->assertNull($s->fileDaScaricare(9999));
    }

    public function testUnaCaricataNonValidaOUnUploadMancanteDannoMessaggiChiari(): void
    {
        $s = $this->servizio(ServizioConvenzioneFirmata::class);
        $cc = $this->compilazione();
        $this->assertSame('Scegli il file della convenzione firmata.', $s->caricaDaUpload($cc, null));
        $this->assertSame('Scegli il file della convenzione firmata.', $s->caricaDaUpload($cc, ['error' => UPLOAD_ERR_NO_FILE]));
        $this->assertStringContainsString('troppo grande', (string) $s->caricaDaUpload($cc, ['error' => UPLOAD_ERR_INI_SIZE]));
        $this->assertStringContainsString('non è riuscito', (string) $s->caricaDaUpload($cc, ['error' => UPLOAD_ERR_OK, 'tmp_name' => '/non/caricato', 'size' => 10]), 'un file che non arriva da un caricamento non si accetta');
    }

    public function testIlPrimoCaricamentoRichiedeAncheLAllegatoA(): void
    {
        $s = $this->servizio(ServizioConvenzioneFirmata::class);
        $cc = $this->compilazione();

        $this->assertStringContainsString('Carica anche l\'Allegato A', (string) $s->carica($cc, $this->pdf()));
        $this->assertStringContainsString('Allegato A deve essere un PDF', (string) $s->carica($cc, $this->pdf(), 'non un pdf'));
        $this->assertNull($this->riga('convenzioni_compilate', (int) $cc['id'])['file_firmato'], 'senza Allegato A non si salva niente');

        $this->assertNull($s->carica($cc, $this->pdf(), $this->allegato()));
        $r = $this->riga('convenzioni_compilate', (int) $cc['id']) ?? [];
        $this->assertFileExists($this->radiceProva . '/' . $r['file_allegato_scuola']);
        $this->assertSame('%PDF-1.7' . "\nAllegato A\n%%EOF", file_get_contents($this->radiceProva . '/' . $r['file_allegato_scuola']), 'l\'Allegato A può non essere firmato');

        // Sostituendo solo la convenzione l'Allegato A resta; sostituendo anche quello il vecchio file si cancella
        $vecchioAll = $this->radiceProva . '/' . $r['file_allegato_scuola'];
        $this->assertNull($s->carica($r, $this->pdf("%%FIRMA\nv2")));
        $this->assertSame($r['file_allegato_scuola'], ($this->riga('convenzioni_compilate', (int) $cc['id']) ?? [])['file_allegato_scuola']);
        $r = $this->riga('convenzioni_compilate', (int) $cc['id']) ?? [];
        $this->assertNull($s->carica($r, $this->pdf("%%FIRMA\nv3"), "%PDF-1.7\nAllegato A nuovo"));
        $this->assertFileDoesNotExist($vecchioAll);
        $this->assertSame('Allegato_A_CSIS00001A.pdf', $s->fileDaScaricare((int) $cc['id'], 'allegato')['nome'] ?? '');
    }

    public function testIlProtocolloInEntrataPrendeInCaricoSenzaIlDirettore(): void
    {
        $s = $this->servizio(ServizioConvenzioneFirmata::class);
        $cc = $this->compilazione();
        $this->assertStringContainsString('Non c\'è nessuna', (string) $s->protocolloIngresso((int) $cc['id'], '1', '2026-10-12', 'admin@x.it')['errore'], 'senza file non c\'è niente da protocollare');
        $s->carica($cc, $this->pdf(), $this->allegato());
        $this->mailer->inviate = [];

        $this->assertStringContainsString('numero del protocollo in entrata', (string) $s->protocolloIngresso((int) $cc['id'], '  ', '2026-10-12', 'admin@x.it')['errore']);
        $r = $s->protocolloIngresso((int) $cc['id'], '1234', '2026-10-12', 'admin@x.it');

        $this->assertNull($r['errore']);
        $this->assertStringContainsString('Indica prima il Direttore', (string) $r['direttore'], 'il protocollo si registra anche se il Direttore non è indicato');
        $riga = $this->riga('convenzioni_compilate', (int) $cc['id']) ?? [];
        $this->assertSame(['in_carico', '1234', '2026-10-12', 'admin@x.it'], [$riga['stato_iter'], $riga['protocollo_ingresso'], $riga['protocollo_ingresso_data'], $riga['in_carico_da']]);
        $this->assertSame(['dirigente@scuola.it'], array_column($this->mailer->inviate, 'a'), 'la scuola sa che è stata protocollata; al Direttore non parte niente');
        $this->assertStringContainsString('1234 del 12/10/2026', $this->mailer->inviate[0]['corpo']);
        $this->assertStringContainsString('non è in attesa', (string) $s->protocolloIngresso((int) $cc['id'], '1235', '', 'admin@x.it')['errore'], 'non si protocolla due volte');
        $this->assertFalse(ServizioConvenzioneFirmata::caricabile($riga), 'la scuola non può più sostituire il file');
    }

    public function testDopoIlProtocolloInEntrataLaConvenzioneVaSubitoAlDirettore(): void
    {
        $this->direttoreConfigurato();
        $s = $this->servizio(ServizioConvenzioneFirmata::class);
        $cc = $this->compilazione();
        $s->carica($cc, $this->pdf(), $this->allegato());
        $this->mailer->inviate = [];

        $r = $s->protocolloIngresso((int) $cc['id'], '1234', '2026-10-12', 'admin@x.it');

        $this->assertSame(['errore' => null, 'direttore' => null], $r);
        $riga = $this->riga('convenzioni_compilate', (int) $cc['id']) ?? [];
        $this->assertSame('da_firmare', $riga['stato_iter']);
        $this->assertSame(40, strlen((string) $riga['token_direttore']));
        $a = array_column($this->mailer->inviate, 'a');
        sort($a);
        $this->assertSame(['direttore@unical.it', 'dirigente@scuola.it'], $a, 'la scuola e il Direttore, nello stesso momento');
        $mail = array_values(array_filter($this->mailer->inviate, static fn (array $m): bool => $m['a'] === 'direttore@unical.it'))[0];
        $this->assertStringContainsString('firma_convenzione.php?t=' . $riga['token_direttore'], $mail['corpo']);
    }

    public function testRifiutoConMotivoELaScuolaRicaricaDaCapo(): void
    {
        $s = $this->servizio(ServizioConvenzioneFirmata::class);
        $cc = $this->compilazione();
        $s->carica($cc, $this->pdf(), $this->allegato());
        $s->protocolloIngresso((int) $cc['id'], '1234', '2026-10-12', 'admin@x.it');
        $this->mailer->inviate = [];

        $this->assertStringContainsString('motivo', (string) $s->rifiuta((int) $cc['id'], '  '));
        $this->assertNull($s->rifiuta((int) $cc['id'], 'Manca la firma del Dirigente'));
        $r = $this->riga('convenzioni_compilate', (int) $cc['id']) ?? [];
        $this->assertSame('rifiutata', $r['stato_iter']);
        $this->assertSame('Manca la firma del Dirigente', $r['nota_ufficio']);
        $this->assertStringContainsString('Manca la firma del Dirigente', $this->mailer->inviate[0]['corpo']);
        $this->assertStringContainsString('Carica di nuovo', $this->mailer->inviate[0]['corpo']);

        // Dopo il rifiuto la scuola ricarica: torna «da protocollare», senza il vecchio motivo né il vecchio protocollo in entrata
        $this->assertTrue(ServizioConvenzioneFirmata::caricabile($r));
        $this->assertNull($s->carica($r, $this->pdf("%%FIRMA\nversione corretta")));
        $r = $this->riga('convenzioni_compilate', (int) $cc['id']) ?? [];
        $this->assertSame(['caricata_scuola', '', ''], [$r['stato_iter'], $r['nota_ufficio'], $r['protocollo_ingresso']]);
    }
    public function testRicaricoFinaleRegistraLaConvenzioneEConfermaLePrenotazioni(): void
    {
        $s = $this->servizio(ServizioConvenzioneFirmata::class);
        $cc = $this->compilazione();
        $s->carica($cc, $this->pdf(), $this->allegato());
        $s->protocolloIngresso((int) $cc['id'], '1234', '2026-10-12', 'admin@x.it');
        $inAttesa = $this->pren(100, ['stato' => 'da_approvare', 'convenzione' => 'no', 'email' => 'anna@prova.it']);
        $altra = $this->pren(100, ['stato' => 'da_approvare', 'convenzione' => 'no', 'scuola_codice' => 'CSPS00002B']);
        $this->mailer->inviate = [];
        $dati = ['protocollo' => '1234', 'protocollo_data' => '2026-10-10', 'data_stipula' => '2026-10-10', 'scadenza' => '2027-10-09'];

        $this->assertStringContainsString('Indica il numero di protocollo', (string) $s->completa((int) $cc['id'], $this->pdf(), ['protocollo' => ''] + $dati, 'admin@x.it')['errore']);
        $this->assertStringContainsString('date di validità', (string) $s->completa((int) $cc['id'], $this->pdf(), ['scadenza' => '2026-01-01'] + $dati, 'admin@x.it')['errore']);
        $this->assertStringContainsString('PDF', (string) $s->completa((int) $cc['id'], '', $dati, 'admin@x.it')['errore']);
        $this->assertStringContainsString('PAdES', (string) $s->completa((int) $cc['id'], $this->pdf('senza firma'), $dati, 'admin@x.it')['errore']);
        $this->assertSame(0, (int) $this->db->valore('SELECT COUNT(*) FROM convenzioni_scuole'), 'con un errore non si registra nulla');

        $esito = $s->completa((int) $cc['id'], $this->pdf("%%FIRMA\nfirmato dal direttore"), $dati, 'admin@x.it');
        $this->assertNull($esito['errore']);
        $this->assertSame(1, $esito['prenotazioni']);

        $conv = $this->riga('convenzioni_scuole', $esito['convenzione_id']) ?? [];
        $this->assertSame(['CSPS00001A', '2026-10-10', '2027-10-09', '1234 del 10/10/2026', 'admin@x.it'], [$conv['scuola_codice'], $conv['data_stipula'], $conv['scadenza'], $conv['protocollo'], $conv['registrata_da']]);
        $this->assertFileExists($this->radiceProva . '/' . $conv['file_convenzione']);
        $this->assertFileExists($this->radiceProva . '/uploads/convenzioni/.htaccess');
        $this->assertStringStartsWith('uploads/convenzioni/', (string) $conv['file_allegato'], 'l\'Allegato A della scuola è nel registro');
        $this->assertFileExists($this->radiceProva . '/' . $conv['file_allegato']);

        $r = $this->riga('convenzioni_compilate', (int) $cc['id']) ?? [];
        $this->assertSame(['protocollata', $esito['convenzione_id'], '1234 del 10/10/2026'], [$r['stato_iter'], (int) $r['convenzione_id'], $r['protocollo']]);
        $this->assertSame(['confermata', 'ricevuta'], [$this->riga('prenotazioni', $inAttesa)['stato'], $this->riga('prenotazioni', $inAttesa)['convenzione']], 'la prenotazione in attesa si conferma');
        $this->assertSame('no', $this->riga('prenotazioni', $altra)['convenzione'], 'un\'altra scuola non cambia');

        $a = array_column($this->mailer->inviate, 'a');
        $this->assertContains('anna@prova.it', $a, 'chi aspettava la convenzione è avvisato della conferma');
        $this->assertContains('dirigente@scuola.it', $a, 'la scuola sa che la convenzione è attiva');
        $attiva = array_values(array_filter($this->mailer->inviate, static fn (array $m): bool => str_starts_with($m['oggetto'], 'Convenzione attiva')))[0];
        $this->assertStringContainsString('1234 del 10/10/2026', $attiva['corpo']);
        $this->assertStringContainsString('1 prenotazione era', $attiva['corpo']);

        $this->assertStringContainsString('già stata protocollata', (string) $s->completa((int) $cc['id'], $this->pdf(), $dati, 'admin@x.it')['errore'], 'non si registra due volte');
    }

    public function testRicaricoFinaleRichiedeLaScuolaDellAnagrafe(): void
    {
        $id = $this->db->inserisci('INSERT INTO convenzioni_compilate (token, scuola_codice, prenotazione_id, email, dati_json) VALUES (?, NULL, 1, ?, ?)', ['zzz', 'x@x.it', '{}']);
        $r = $this->servizio(ServizioConvenzioneFirmata::class)->completa($id, $this->pdf(), ['protocollo' => '1', 'data_stipula' => '2026-10-10', 'scadenza' => '2027-10-09'], 'admin@x.it');
        $this->assertStringContainsString('anagrafe', (string) $r['errore']);
    }
}