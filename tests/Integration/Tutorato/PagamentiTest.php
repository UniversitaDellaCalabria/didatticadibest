<?php

declare(strict_types=1);

namespace Tests\Integration\Tutorato;

use App\Ateneo\ServizioUfficiAteneo;
use App\Core\Sito;
use App\Infrastructure\Storage\Upload;
use App\Tutorato\ArchivioIncarichi;
use App\Tutorato\BandoRepository;
use App\Tutorato\FileTutorato;
use App\Tutorato\IncaricoRepository;
use App\Tutorato\ServizioDichiarazione;
use App\Tutorato\ServizioIncarichi;
use App\Tutorato\ServizioPagamenti;
use App\Tutorato\ServizioRegistroTutorato;

/** Decreti e UNILAV, dichiarazione individuale cifrata, validazione del referente, ufficio pagamenti e avviso al tutor. */
final class PagamentiTest extends TutoratoBase
{
    private const IBAN = 'IT60X0542811101000000123456';
    private const UFFICIO = ['id' => 9, 'email' => 'Ufficio@X.it', 'nome' => 'Rossana', 'cognome' => 'Zicarelli'];

    protected function tabelle(): array
    {
        return parent::tabelle() + [
            'uffici' => "CREATE TABLE uffici (id INT AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(150) NOT NULL DEFAULT '', descrizione VARCHAR(500) DEFAULT '', chiave VARCHAR(30) NULL, ambito VARCHAR(12) NOT NULL DEFAULT 'dipartimento', smista TINYINT(1) NOT NULL DEFAULT 0, segue_corsi TINYINT(1) NOT NULL DEFAULT 0, ordine INT NOT NULL DEFAULT 0, tipo VARCHAR(20) NOT NULL DEFAULT '', consiglio_id INT NULL, attivo TINYINT(1) NOT NULL DEFAULT 1, creato_il DATETIME DEFAULT CURRENT_TIMESTAMP)",
            'uffici_persone' => "CREATE TABLE uffici_persone (id INT AUTO_INCREMENT PRIMARY KEY, ufficio_id INT NOT NULL, persona_id VARCHAR(80) NULL, email VARCHAR(150) NOT NULL DEFAULT '', nominativo VARCHAR(200) NOT NULL DEFAULT '', struttura VARCHAR(255) DEFAULT '', ruolo VARCHAR(100) DEFAULT '', compiti VARCHAR(100) NOT NULL DEFAULT '', corsi TEXT NULL, op_id INT NULL, creato_il DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq (ufficio_id, email))",
        ];
    }

    protected function tearDown(): void
    {
        foreach (['decreti', 'unilav'] as $sotto) {
            $dir = dirname(__DIR__, 3) . '/' . $this->cartella . $sotto;
            foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
                is_file($f) && @unlink($f);
            }
            @rmdir($dir);
        }
        parent::tearDown();
    }

    private function abilitaCifratura(): void
    {
        file_put_contents($this->envFile, "CIFRATURA_CHIAVE=una-frase-segreta-di-prova-2026\n");
        $this->c->istanza(FileTutorato::class, new FileTutorato($this->servizio(BandoRepository::class), $this->servizio(IncaricoRepository::class), new Upload(), $this->servizio(Sito::class), $this->cartella));
    }

    private function pagamenti(): ServizioPagamenti
    {
        return $this->servizio(ServizioPagamenti::class);
    }

    private function files(): FileTutorato
    {
        return $this->servizio(FileTutorato::class);
    }

    /** @return array<string, mixed> */
    private function post2(array $v = []): array
    {
        return $v + ['telefono' => '347 1234567', 'email' => 'luca@x.it', 'residenza_invariata' => '1', 'non_dipendente' => '1', 'irpef' => '23', 'iban' => self::IBAN, 'banca' => 'Banca di prova'];
    }

    /** @return array<string, mixed> */
    private function post3(array $v = []): array
    {
        return $v + ['inps' => 'gs35', 'imponibile' => '', 'inail' => ['didattica']];
    }

    /** Ufficio «Pagamenti» con una persona e un bando di prova che lo usa (id 1). */
    private function ufficio(): int
    {
        $s = $this->servizio(ServizioUfficiAteneo::class);
        [$id] = $s->salva(0, 'Ufficio pagamenti tutorato', '');
        $this->db->esegui("INSERT INTO uffici_persone (ufficio_id, email, nominativo) VALUES (?, 'ufficio@x.it', 'Rossana Zicarelli')", [$id]);
        $this->db->esegui('UPDATE tutorato_bandi SET ufficio_ateneo_id = ? WHERE id = 1', [$id]);

        return $id;
    }

    /**
     * Pratica arrivata alla fine dell'iter: lettera firmata e protocollata, dichiarazione compilata, registro approvato e fine attività
     * firmata dal docente. Decreti e UNILAV si caricano a parte. Ritorna l'id.
     */
    private function pronta(int $oreRegistro = 10): int
    {
        $this->abilitaCifratura();
        $this->bando();
        $s = $this->servizio(ServizioIncarichi::class);
        $dich = $this->servizio(ServizioDichiarazione::class);
        [$id] = $s->salva($this->campi());
        $s->invia($id);
        $this->assertSame([[], []], [$dich->salvaPasso($id, 2, $this->post2(), $this->lettera($id))[0], $dich->salvaPasso($id, 3, $this->post3(), $this->lettera($id))[0]]);
        $this->assertNull($s->conferma($id, ['id' => 7, 'codice_fiscale' => 'RSSLCU00A31D086X'], ['metodo' => 'spid']));
        foreach (['docente', 'direttore'] as $ruolo) {
            $pdf = (string) file_get_contents((string) $this->servizio(ArchivioIncarichi::class)->pdfCorrente($this->lettera($id)));
            $this->assertNull($s->registraFirma($id, $ruolo, $this->firmato($pdf)));
        }
        $this->assertNull($s->protocolla($id, '1881', '2026-10-01', false, 'admin'));
        $r = $this->servizio(ServizioRegistroTutorato::class);
        $r->salvaDatiFine($id, ['docente_titolo' => 'Prof.ssa', 'insegnamento_docente' => 'Chimica', 'corso_laurea' => 'Biologia', 'data_inizio' => '2026-08-24', 'data_fine' => '2026-10-04']);
        $meta = $oreRegistro / 2;
        $r->aggiungi($id, '2026-09-01', $meta, 'Esercitazioni');
        $r->aggiungi($id, '2026-09-02', $meta, 'Laboratorio');
        $r->decidi($id, 'approvata');
        $this->assertNull($r->richiediFine($id));
        [$tok, $err] = $r->confermaFine($id, 'Maria Verdi');
        $this->assertNull($err);
        $pdf = (string) file_get_contents((string) $this->servizio(ArchivioIncarichi::class)->fineCorrente($this->lettera($id)));
        $this->assertNull($r->registraFirmaFine($id, $this->firmato($pdf)));
        $this->mailer->inviate = [];

        return $id;
    }

    private function completaDocumenti(int $id): void
    {
        $pdf = "%PDF-1.4\nuno";
        foreach (['bando', 'commissione', 'atti'] as $t) {
            $this->assertNull($this->files()->salvaDecreto(1, $t, $pdf . $t));
        }
        $this->db->esegui("UPDATE tutorato_bandi SET decreto_atti = '200/2026', decreto_atti_data = '2026-10-01' WHERE id = 1");
        $this->assertNull($this->files()->salvaUnilav($id, $pdf . 'unilav'));
    }

    // ------------------------------------------------------------------ compenso e IBAN

    public function testCompensoLordoEInLettera(): void
    {
        $this->assertSame(1000.0, \App\Tutorato\Compenso::lordo(40.0));
        $this->assertSame(806.45, \App\Tutorato\Compenso::lettera(40.0));
        $this->assertSame(766.13, \App\Tutorato\Compenso::lettera(38.0));
        $this->assertSame('1.000,00', \App\Tutorato\Compenso::testo(1000.0));
        $this->assertTrue(\App\Tutorato\Compenso::stesseOre(8.0, 8.04));
        $this->assertFalse(\App\Tutorato\Compenso::stesseOre(8.0, 10.0));
        $this->assertFalse(\App\Tutorato\Compenso::stesseOre(null, 10.0));
    }

    public function testCompensoLasciatoVuotoSiCalcolaDalleOre(): void
    {
        $this->bando();
        [$id, $err] = $this->servizio(ServizioIncarichi::class)->salva($this->campi(['ore' => '40', 'compenso' => '']));
        $this->assertNull($err);
        $this->assertSame('806.45', $this->lettera($id)['compenso'], '40 ore × 25 € ÷ 1,24');
        [$id2] = $this->servizio(ServizioIncarichi::class)->salva($this->campi(['ore' => '40', 'compenso' => '900,00', 'codice_fiscale' => 'bncmra60a01d086x', 'email' => 'altro@x.it']));
        $this->assertSame('900.00', $this->lettera($id2)['compenso'], 'se lo scrive il referente resta quello');
    }

    public function testIbanEMascheramento(): void
    {
        $d = new \App\Tutorato\DichiarazioneIndividuale(new Sito(dirname(__DIR__, 3), 'https://x'));
        $this->assertTrue($d::ibanValido(' it60 x054 2811 1010 0000 0123 456 '));
        $this->assertFalse($d::ibanValido('IT60X0542811101000000123457'), 'cifra di controllo sbagliata');
        $this->assertFalse($d::ibanValido('IT60X05428111010000001234'), 'un IBAN italiano ha 27 caratteri');
        $this->assertFalse($d::ibanValido('XX'));
        $this->assertTrue($d::ibanValido('DE89370400440532013000'), 'IBAN estero');
        $this->assertSame('IT60 X054 2811 1010 0000 0123 456', $d::ibanLeggibile(self::IBAN));
        $this->assertStringStartsWith('IT60 X054', $d::ibanMascherato(self::IBAN));
        $this->assertStringEndsWith('456', $d::ibanMascherato(self::IBAN));
        $this->assertStringNotContainsString('2811', $d::ibanMascherato(self::IBAN));
    }

    // ------------------------------------------------------------------ dichiarazione individuale

    public function testSenzaChiaveLaDichiarazioneNonSiRichiedeNeSiSalva(): void
    {
        $this->bando();
        $dich = $this->servizio(ServizioDichiarazione::class);
        $this->assertFalse($dich->richiesta());
        $this->assertTrue($dich->soddisfatta(1), 'i dati bancari non si conservano in chiaro: finché non c\'è la chiave non si richiede');
        [$s] = $this->servizio(ServizioIncarichi::class)->salva($this->campi());
        $this->assertStringContainsString('cifratura', $dich->salvaPasso($s, 2, $this->post2(), $this->lettera($s))[0][0]);
    }

    public function testDichiarazioneCifrataERegistroDegliAccessi(): void
    {
        $this->abilitaCifratura();
        $this->bando();
        $s = $this->servizio(ServizioIncarichi::class);
        $dich = $this->servizio(ServizioDichiarazione::class);
        [$id] = $s->salva($this->campi());
        $l = $this->lettera($id);
        $this->assertTrue($dich->richiesta());

        [$err] = $dich->salvaPasso($id, 2, $this->post2(['iban' => 'IT60X0542811101000000123457', 'irpef' => 'molta', 'telefono' => 'x', 'non_dipendente' => '']), $l);
        $this->assertStringContainsString('IBAN', implode('|', $err));
        $this->assertStringContainsString('IRPEF', implode('|', $err));
        $this->assertStringContainsString('telefono', implode('|', $err));
        $this->assertStringContainsString('dipendente UNICAL', implode('|', $err));
        $this->assertSame(0, (int) $this->db->valore('SELECT COUNT(*) FROM tutorato_dichiarazioni'), 'con errori non si salva nulla');

        $this->assertSame([[], 'Via Roma'], [$dich->salvaPasso($id, 2, $this->post2(['iban' => 'it60 x054 2811 1010 0000 0123 456']), $l)[0], $dich->datiPasso($id, 2)['indirizzo']], 'residenza invariata: quella della lettera');
        $this->assertSame(['passo2' => true, 'passo3' => false, 'completa' => false, 'iban_finale' => '3456'], $dich->stato($id));
        $this->assertFalse($dich->soddisfatta($id));

        // nel database c'è solo testo cifrato: né l'IBAN né il nome della banca si leggono
        $grezzo = (string) $this->db->valore('SELECT dati_cifrati FROM tutorato_dichiarazioni WHERE incarico_id = ?', [$id]);
        $this->assertStringStartsWith('v1:', $grezzo);
        $this->assertStringNotContainsString('0542811', $grezzo);
        $this->assertStringNotContainsString('Banca di prova', $grezzo);
        $this->assertStringNotContainsString('0542811', (string) json_encode($this->db->righe('SELECT * FROM tutorato_dichiarazioni')));

        $this->assertSame([[], true], [$dich->salvaPasso($id, 3, $this->post3(['inps' => 'gs24', 'cassa_ente' => 'INARCASSA', 'cassa_dal' => '2020-01-01']), $l)[0], $dich->stato($id)['completa']]);
        $this->assertStringContainsString('INPS', implode('|', $dich->salvaPasso($id, 3, $this->post3(['inps' => '']), $l)[0]));
        $this->assertStringContainsString('cassa', implode('|', $dich->salvaPasso($id, 3, $this->post3(['inps' => 'gs24', 'cassa_ente' => '']), $l)[0]));
        $this->assertStringContainsString('INAIL', implode('|', $dich->salvaPasso($id, 3, $this->post3(['inail' => []]), $l)[0]));
        $this->assertTrue($dich->stato($id)['completa'], 'un passo respinto non rovina quello salvato');

        // aprire i dati lascia traccia di chi li ha aperti
        $this->assertSame([], $dich->accessi($id));
        $d = $dich->apri($id, ['id' => 9, 'email' => 'ufficio@x.it'], 'iban');
        $this->assertSame(self::IBAN, $d['passo2']['iban']);
        $acc = $dich->accessi($id);
        $this->assertSame(['ufficio@x.it', 'iban'], [$acc[0]['utente_email'], $acc[0]['azione']]);
        $pdf = $dich->pdf($l, ['id' => 9, 'email' => 'ufficio@x.it']);
        $this->assertStringStartsWith('%PDF-', (string) $pdf);
        $this->assertCount(2, $dich->accessi($id), 'anche scaricare il PDF si registra');
    }

    public function testLaLetteraSiAccettaSoloConLaDichiarazioneCompleta(): void
    {
        $this->abilitaCifratura();
        $this->bando();
        $s = $this->servizio(ServizioIncarichi::class);
        $dich = $this->servizio(ServizioDichiarazione::class);
        [$id] = $s->salva($this->campi());
        $s->invia($id);
        $utente = ['id' => 7, 'codice_fiscale' => 'RSSLCU00A31D086X'];

        $this->assertStringContainsString('compila la dichiarazione individuale', (string) $s->conferma($id, $utente, ['metodo' => 'spid']));
        $dich->salvaPasso($id, 2, $this->post2(), $this->lettera($id));
        $this->assertStringContainsString('compila la dichiarazione', (string) $s->conferma($id, $utente, ['metodo' => 'spid']), 'manca il passo 3');
        $dich->salvaPasso($id, 3, $this->post3(), $this->lettera($id));
        $this->assertNull($s->conferma($id, $utente, ['metodo' => 'spid']));
    }

    // ------------------------------------------------------------------ decreti e UNILAV

    public function testDecretiEUnilavInPdfDaCartellaProtetta(): void
    {
        $id = $this->pronta();
        $f = $this->files();

        $this->assertSame('Il file non è un PDF.', $f->salvaDecreto(1, 'bando', 'PK'));
        $this->assertSame('Il file è vuoto.', $f->salvaDecreto(1, 'bando', ''));
        $this->assertSame('Bando o decreto non trovato.', $f->salvaDecreto(1, 'altro', '%PDF-1'));
        $this->assertSame('Bando o decreto non trovato.', $f->salvaDecreto(99, 'bando', '%PDF-1'));
        $this->assertNull($f->salvaDecreto(1, 'bando', "%PDF-1.4\nprimo"));
        $b = $this->servizio(BandoRepository::class)->perId(1);
        $primo = $f->percorsoDecreto($b, 'bando');
        $this->assertNotNull($primo);
        $this->assertNull($f->percorsoDecreto($b, 'atti'), 'non ancora caricato');
        $this->assertNull($f->salvaDecreto(1, 'bando', "%PDF-1.4\nsecondo"));
        $this->assertFileDoesNotExist((string) $primo, 'il decreto sostituito si cancella');
        $this->assertFileExists(dirname(__DIR__, 3) . '/' . $this->cartella . 'decreti/.htaccess', 'cartella bloccata al web');

        $this->assertNull($f->salvaUnilav($id, "%PDF-1.4\nunilav"));
        $i = $this->lettera($id);
        $this->assertSame('10.0', $i['unilav_ore'], 'ore approvate, quelle a cui si riferisce');
        $this->assertNotNull($f->percorsoUnilav($i));
        $this->db->esegui("UPDATE tutorato_incarichi SET unilav_file = '../../config.php' WHERE id = ?", [$id]);
        $this->assertNull($f->percorsoUnilav($this->lettera($id)), 'solo dentro la cartella del tutorato');
    }

    public function testLUnilavSiCaricaDopoIlProtocolloEFinoAiPagamenti(): void
    {
        $this->abilitaCifratura();
        $this->bando();
        [$id] = $this->servizio(ServizioIncarichi::class)->salva($this->campi());
        $this->assertStringContainsString('dopo il protocollo', (string) $this->files()->salvaUnilav($id, "%PDF-1.4\nx"));
        $this->assertStringContainsString('non trovata', (string) $this->files()->salvaUnilav(999, "%PDF-1.4\nx"));

        $id = $this->pronta();
        $this->completaDocumenti($id);
        $this->ufficio();
        $this->assertSame(1, $this->pagamenti()->passa([$id], 'Referente')['passate']);
        $this->assertStringContainsString('già all\'ufficio pagamenti', (string) $this->files()->salvaUnilav($id, "%PDF-1.4\nnuovo"));
    }

    // ------------------------------------------------------------------ referente → ufficio pagamenti

    public function testControlliCosaManca(): void
    {
        $id = $this->pronta();
        $m = implode('|', $this->pagamenti()->controlli($this->lettera($id)));
        $this->assertStringContainsString('decreto del bando', $m);
        $this->assertStringContainsString('decreto della commissione', $m);
        $this->assertStringContainsString('decreto di approvazione atti', $m);
        $this->assertStringContainsString("manca l'UNILAV", $m);
        $this->assertStringContainsString("nel bando manca l'ufficio di Ateneo", $m);
        $this->assertStringNotContainsString('dichiarazione individuale', $m, 'la dichiarazione è compilata');
        $this->assertStringNotContainsString('protocollata', $m);
        $this->assertStringNotContainsString('firmata dal docente', $m);

        $this->completaDocumenti($id);
        $this->ufficio();
        $this->assertSame([], $this->pagamenti()->controlli($this->lettera($id)));
    }

    public function testPassaUnaPraticaAiPagamentiConUnaSolaEmailPerUfficio(): void
    {
        $id = $this->pronta();
        $this->completaDocumenti($id);
        $this->ufficio();
        $p = $this->pagamenti();

        $this->assertCount(1, $p->daValidare());
        $this->assertSame([], $p->daValidare()[0]['_controlli']);
        $r = $p->passa([$id, 999, $id], 'Referente Tutorato');

        $this->assertSame(1, $r['passate']);
        $this->assertSame([999 => 'la pratica non è da passare ai pagamenti'], $r['errori']);
        $l = $this->lettera($id);
        $this->assertSame(['in_ufficio', 'Referente Tutorato', '201.61'], [$l['pagamento_stato'], $l['pagamento_passato_da'], $l['compenso_finale']]);
        $this->assertNotNull($l['fine_validata_il']);
        $this->assertSame(['ufficio@x.it'], array_column($this->mailer->inviate, 'a'));
        $this->assertStringContainsString('una pratica da pagare', $this->mailer->inviate[0]['oggetto']);
        $this->assertStringContainsString('pagamenti_tutorato.php', $this->mailer->inviate[0]['corpo']);
        $this->assertSame([], $p->daValidare(), 'non è più da validare');
        $this->assertSame(1, $p->passa([$id], 'x')['passate'] + 1, 'non si passa due volte');
        $this->assertCount(1, $this->mailer->inviate);
    }

    public function testPassaPiuPraticheInsiemeEUnaNonPronta(): void
    {
        $a = $this->pronta();
        $this->completaDocumenti($a);
        $this->ufficio();
        // seconda pratica dello stesso bando senza UNILAV
        $s = $this->servizio(ServizioIncarichi::class);
        $dich = $this->servizio(ServizioDichiarazione::class);
        [$b] = $s->salva($this->campi(['codice_fiscale' => 'bncmra60a01d086x', 'email' => 'maria@x.it']));
        $s->invia($b);
        $dich->salvaPasso($b, 2, $this->post2(), $this->lettera($b));
        $dich->salvaPasso($b, 3, $this->post3(), $this->lettera($b));
        $this->assertNull($s->conferma($b, ['id' => 8, 'codice_fiscale' => 'BNCMRA60A01D086X'], ['metodo' => 'spid']));
        $this->mailer->inviate = [];

        $r = $this->pagamenti()->passa([$a, $b], 'Referente');
        $this->assertSame(1, $r['passate']);
        $this->assertArrayHasKey($b, $r['errori']);
        $this->assertStringContainsString('non è ancora protocollata', $r['errori'][$b]);
        $this->assertCount(1, $this->mailer->inviate, 'una sola email con le pratiche passate');
    }

    public function testMenoOreRicalcolanoIlCompensoEChiedonoUnilavAggiornato(): void
    {
        $id = $this->pronta(8);
        $this->completaDocumenti($id);   // l'UNILAV riporta le 8 ore approvate
        $this->ufficio();
        $c = $this->pagamenti()->compenso($this->lettera($id));
        $this->assertSame([8.0, 200.0, 161.29, true], [$c['ore'], $c['lordo'], $c['lettera'], $c['cambiato']], '8 ore × 25 € = 200 € lordi; ÷ 1,24 = 161,29 €');

        // UNILAV vecchio (10 ore): da aggiornare
        $this->db->esegui('UPDATE tutorato_incarichi SET unilav_ore = 10 WHERE id = ?', [$id]);
        $m = implode('|', $this->pagamenti()->controlli($this->lettera($id)));
        $this->assertStringContainsString("UNILAV è da aggiornare: riporta 10 ore, quelle approvate sono 8", $m);
        $this->assertSame(0, $this->pagamenti()->passa([$id], 'R')['passate']);

        $this->assertNull($this->files()->salvaUnilav($id, "%PDF-1.4\naggiornato"));
        $this->assertSame('8.0', $this->lettera($id)['unilav_ore']);
        $this->assertSame(1, $this->pagamenti()->passa([$id], 'R')['passate']);
        $this->assertSame('161.29', $this->lettera($id)['compenso_finale']);
    }

    public function testIlReferenteRimandaAlDocenteConIlMotivo(): void
    {
        $id = $this->pronta();
        $p = $this->pagamenti();
        $this->assertStringContainsString('Scrivi il motivo', (string) $p->rimandaAlDocente($id, '  ', 'Referente'));
        $this->assertNull($p->rimandaAlDocente($id, 'Le ore del 02/09 non tornano', 'Referente'));
        $l = $this->lettera($id);
        $this->assertSame(['richiesta', null], [$l['fine_stato'], $l['fine_firmata_il']]);
        $m = $this->mailer->inviate[0];
        $this->assertSame('maria.verdi@x.it', $m['a']);
        $this->assertStringContainsString('Le ore del 02/09 non tornano', $m['corpo']);
        $this->assertStringContainsString('registro_tutorato.php?id=' . $id, $m['corpo']);
        $this->assertSame([], $p->daValidare(), 'finché il docente non rifirma non è da validare');
        $this->assertStringContainsString('non si può rimandare', (string) $p->rimandaAlDocente($id, 'altro', 'R'), 'non più firmata');
    }

    // ------------------------------------------------------------------ ufficio pagamenti

    private function inUfficio(): int
    {
        $id = $this->pronta();
        $this->completaDocumenti($id);
        $this->ufficio();
        $this->assertSame(1, $this->pagamenti()->passa([$id], 'Referente')['passate']);
        $this->mailer->inviate = [];

        return $id;
    }

    public function testLUfficioPagaESegnaLaData(): void
    {
        $id = $this->inUfficio();
        $p = $this->pagamenti();
        $altro = ['id' => 5, 'email' => 'estraneo@x.it'];

        $this->assertSame(1, $p->daPagare(self::UFFICIO));
        $this->assertSame(0, $p->daPagare($altro));
        $this->assertSame('Non puoi lavorare questa pratica.', $p->pagata($id, '2026-10-05', 'M1', $altro));
        $this->assertSame('Indica la data del pagamento.', $p->pagata($id, 'ieri', 'M1', self::UFFICIO));
        $this->assertSame('La data del pagamento non può essere nel futuro.', $p->pagata($id, '2026-10-06', 'M1', self::UFFICIO));
        $this->assertStringContainsString('riferimento', (string) $p->pagata($id, '2026-10-05', ' ', self::UFFICIO));

        $this->assertNull($p->pagata($id, '2026-10-05', 'Mandato 2026/00412', self::UFFICIO));
        $l = $this->lettera($id);
        $this->assertSame(['pagata', '2026-10-05', 'Mandato 2026/00412', 'Rossana Zicarelli'], [$l['pagamento_stato'], $l['pagamento_data'], $l['pagamento_riferimento'], $l['pagato_da']]);
        $m = $this->mailer->inviate[0];
        $this->assertSame('luca@x.it', $m['a'], 'lo studente è avvisato a pagamento disposto');
        $this->assertSame('Tutorato: pagamento disposto', $m['oggetto']);
        $this->assertStringContainsString('05/10/2026', $m['corpo']);
        $this->assertStringContainsString('Mandato 2026/00412', $m['corpo']);
        $this->assertSame('La pratica non è da pagare.', $p->pagata($id, '2026-10-05', 'M2', self::UFFICIO));
        $this->assertSame(0, $p->daPagare(self::UFFICIO));
        $this->assertCount(1, $p->dellUfficio(1, ['pagata']));
        $this->assertCount(0, $p->dellUfficio(1, ['in_ufficio']));
    }

    public function testLUfficioRimandaAlReferenteConUnaNotaEIlReferenteLaRipassa(): void
    {
        $id = $this->inUfficio();
        $p = $this->pagamenti();

        $this->assertStringContainsString('Scrivi perché', (string) $p->rimandaAlReferente($id, '', self::UFFICIO));
        $this->assertSame('Non puoi lavorare questa pratica.', $p->rimandaAlReferente($id, 'x', ['id' => 5, 'email' => 'estraneo@x.it']));
        $this->assertNull($p->rimandaAlReferente($id, 'IBAN intestato a un\'altra persona', self::UFFICIO));
        $l = $this->lettera($id);
        $this->assertSame(['rimandata', "IBAN intestato a un'altra persona", null], [$l['pagamento_stato'], $l['pagamento_nota'], $l['fine_validata_il']]);
        $this->assertSame(['admin@x.it'], array_column($this->mailer->inviate, 'a'), 'il referente riceve l\'email con il motivo');
        $this->assertStringContainsString("IBAN intestato a un&#039;altra persona", $this->mailer->inviate[0]['corpo']);
        $this->assertSame(0, $p->daPagare(self::UFFICIO));
        $this->assertSame('rimandata', $p->daValidare()[0]['pagamento_stato'], 'torna tra le pratiche da validare, in testa');

        $this->mailer->inviate = [];
        $this->assertSame(1, $p->passa([$id], 'Referente')['passate'], 'il referente corregge e la ripassa');
        $this->assertSame(['in_ufficio', ''], [$this->lettera($id)['pagamento_stato'], $this->lettera($id)['pagamento_nota']]);
        $this->assertSame(1, $p->daPagare(self::UFFICIO));
    }

    public function testLaConservazioneCancellaDichiarazioneEUnilavDopoIlPagamento(): void
    {
        $id = $this->inUfficio();
        $p = $this->pagamenti();
        $dich = $this->servizio(ServizioDichiarazione::class);
        $dich->apri($id, self::UFFICIO, 'scheda');
        $unilav = $this->files()->percorsoUnilav($this->lettera($id));
        $this->assertFileExists((string) $unilav);
        $this->db->esegui("UPDATE tutorato_incarichi SET aggiornata_il = '2023-01-01 00:00:00' WHERE id = ?", [$id]);
        $this->assertSame(0, $this->servizio(ServizioRegistroTutorato::class)->conserva(12), 'ancora all\x27ufficio pagamenti: non si cancella niente');
        $this->assertTrue($dich->stato($id)['completa']);

        $this->assertNull($p->pagata($id, '2026-10-05', 'M1', self::UFFICIO));
        $this->db->esegui("UPDATE tutorato_incarichi SET aggiornata_il = '2023-01-01 00:00:00' WHERE id = ?", [$id]);
        $this->assertSame(1, $this->servizio(ServizioRegistroTutorato::class)->conserva(12));
        $this->assertFalse($dich->stato($id)['completa'], 'la dichiarazione con l\x27IBAN è cancellata');
        $this->assertSame(0, (int) $this->db->valore('SELECT COUNT(*) FROM tutorato_dichiarazioni'));
        $this->assertSame(0, (int) $this->db->valore('SELECT COUNT(*) FROM tutorato_accessi_dati'));
        $this->assertFileDoesNotExist((string) $unilav, 'anche l\x27UNILAV (dati personali)');
    }

    public function testDocumentiPerRuolo(): void
    {
        $id = $this->inUfficio();
        $p = $this->pagamenti();
        $i = $this->lettera($id);
        $tutor = ['id' => 7, 'codice_fiscale' => 'rsslcu00a31d086x', 'email' => 'qualcuno@x.it'];

        $this->assertSame('referente', $p->ruolo(null, $i, true));
        $this->assertSame('ufficio', $p->ruolo(self::UFFICIO, $i, false));
        $this->assertSame('tutor', $p->ruolo($tutor, $i, false), 'dal codice fiscale');
        $this->assertSame('tutor', $p->ruolo(['id' => 8, 'email' => 'LUCA@x.it'], $i, false), 'o dall\'email');
        $this->assertNull($p->ruolo(['id' => 5, 'email' => 'estraneo@x.it'], $i, false));
        $this->assertNull($p->ruolo(null, $i, false));
        $this->db->esegui("UPDATE tutorato_incarichi SET pagamento_stato = '' WHERE id = ?", [$id]);
        $this->assertNull($p->ruolo(self::UFFICIO, $this->lettera($id), false), "l'ufficio vede solo le pratiche che gli sono state passate");

        $d = array_column($p->documenti($i), 'disponibile', 'chiave');
        $this->assertSame(['decreto_bando' => true, 'decreto_commissione' => true, 'decreto_atti' => true, 'unilav' => true, 'lettera' => true, 'registro' => true, 'fine' => true, 'dichiarazione' => true], $d);

        foreach (['decreto_bando', 'decreto_atti', 'unilav', 'lettera', 'fine'] as $chiave) {
            $doc = $p->documento($i, $chiave, self::UFFICIO);
            $this->assertNotNull($doc, $chiave);
            $this->assertFileExists((string) $doc['percorso'], $chiave);
        }
        $reg = $p->documento($i, 'registro', self::UFFICIO);
        $this->assertStringStartsWith('%PDF-', (string) $reg['contenuto']);
        $this->assertNull($p->documento($i, 'sconosciuto', self::UFFICIO));

        $dich = $this->servizio(ServizioDichiarazione::class);
        $this->assertCount(0, $dich->accessi($id));
        $this->assertStringStartsWith('%PDF-', (string) $p->documento($i, 'dichiarazione', self::UFFICIO)['contenuto']);
        $this->assertCount(1, $dich->accessi($id), 'chi apre la dichiarazione resta registrato');
    }
}
