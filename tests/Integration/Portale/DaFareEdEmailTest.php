<?php

declare(strict_types=1);

namespace Tests\Integration\Portale;

use App\Core\Container;
use App\Core\Database;
use App\Core\Orologio;
use App\Core\Schema\Definizioni;
use App\Core\Sito;
use App\Infrastructure\Mail\Mailer;
use App\Portale\DaFarePersona;
use App\Portale\RiepilogoPersone;
use App\Sistema\FileEnv;
use Tests\Doppi\MailerFinto;
use Tests\Doppi\OrologioFisso;
use Tests\Integration\ConDirezione;
use Tests\Integration\DatabaseDiProva;

/** «Da fare per te» (l'elenco di ciò che aspetta una persona nei vari moduli) e il riepilogo settimanale per email. */
final class DaFareEdEmailTest extends DatabaseDiProva
{
    use ConDirezione;

    private Container $c;
    private MailerFinto $mailer;
    private string $envFile;

    /** Tabelle con lo schema vero (colonne aggiunte comprese). */
    private const REALI = ['pratiche', 'pratiche_conferme', 'pratiche_firme', 'didattica_moduli', 'tutorato_incarichi', 'tutorato_registro', 'risorse', 'risorse_responsabili', 'prenotazioni_risorse',
                           'didattica_sedute', 'didattica_consigli_persone', 'uffici_persone', 'riepilogo_persone', 'valutazioni_fsl', 'partecipanti_prenotazione', 'progetti_dettagli', 'risorse_scadenze', 'convenzioni_tesi'];

    protected function tabelle(): array
    {
        return [
            ...$this->tabelleUffici(),
            'pagine_eventi' => 'CREATE TABLE pagine_eventi (id INT PRIMARY KEY, titolo VARCHAR(150) DEFAULT \'\')',
            'eventi' => 'CREATE TABLE eventi (id INT AUTO_INCREMENT PRIMARY KEY, pagina_id INT, titolo VARCHAR(150) DEFAULT \'\', tipo VARCHAR(20) DEFAULT \'evento\', archiviato TINYINT DEFAULT 0)',
            'turni' => 'CREATE TABLE turni (id INT AUTO_INCREMENT PRIMARY KEY, evento_id INT, data_turno DATE NULL, orario_inizio TIME NULL, orario_fine TIME NULL)',
            'prenotazioni' => 'CREATE TABLE prenotazioni (id INT AUTO_INCREMENT PRIMARY KEY, turno_id INT, stato VARCHAR(30) NULL DEFAULT \'confermata\', codice_prenotazione VARCHAR(40) DEFAULT \'\', nome VARCHAR(100) NULL,
                cognome VARCHAR(100) NULL, email VARCHAR(150) NULL, valutazione_token VARCHAR(40) NULL, valutazione_inviata DATETIME NULL)',
            'impostazioni_sistema' => 'CREATE TABLE impostazioni_sistema (id INT PRIMARY KEY, direttore_assente TINYINT(1) NOT NULL DEFAULT 0, direttore_assente_fino DATE NULL, direttore_assente_da VARCHAR(150) NOT NULL DEFAULT \'\', direttore_assente_il DATETIME NULL)',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $defs = Definizioni::tabelle();
        $cols = Definizioni::colonne();
        foreach (self::REALI as $t) {
            self::$conn->query("DROP TABLE IF EXISTS `$t`");
            self::$conn->query($defs[$t]);
            foreach ($cols[$t] ?? [] as $spec) {
                self::$conn->query("ALTER TABLE `$t` " . (is_array($spec) ? $spec[0] : $spec));
            }
        }
        $this->mailer = new MailerFinto();
        $this->envFile = tempnam(sys_get_temp_dir(), 'env') ?: '';
        file_put_contents($this->envFile, "RIEPILOGO_PERSONE=1\n");
        $this->c = new Container();
        $this->c->istanza(Container::class, $this->c);
        $this->c->istanza(Database::class, $this->db);
        $this->c->istanza(Mailer::class, $this->mailer);
        $this->c->istanza(Orologio::class, new OrologioFisso('2026-10-14 08:00:00')); // un mercoledì
        $this->c->istanza(Sito::class, new Sito(dirname(__DIR__, 3), 'https://portale.test/eventi'));
        $this->c->istanza(FileEnv::class, new FileEnv($this->envFile));
    }

    protected function tearDown(): void
    {
        @unlink($this->envFile);
    }

    private function pratica(string $stato = 'in_lavorazione'): int
    {
        $this->db->esegui("INSERT IGNORE INTO didattica_moduli (id, titolo, tipo) VALUES (1, 'Attestato Frequenza Lezione', 'online')");

        return $this->db->inserisci('INSERT INTO pratiche (modulo_id, utente_id, codice, nome, cognome, stato, creata_il, aggiornata_il) VALUES (1, 2, ?, \'Luca\', \'Rossi\', ?, NOW() - INTERVAL 10 DAY, NOW() - INTERVAL 10 DAY)', ['PR-' . random_int(100000, 999999), $stato]);
    }

    private function dafare(): DaFarePersona
    {
        return $this->c->get(DaFarePersona::class);
    }

    /** @return array<string, array<string, mixed>> i gruppi per chiave */
    private function gruppi(string $email): array
    {
        $out = [];
        foreach ($this->dafare()->gruppi(['email' => $email]) as $g) {
            $out[$g['chiave']] = $g;
        }

        return $out;
    }

    public function testNienteDaFare(): void
    {
        $this->assertSame([], $this->dafare()->gruppi(['email' => 'nessuno@x.it']));
        $this->assertSame([], $this->dafare()->gruppi(null));
        $this->assertSame([], $this->dafare()->gruppi(['email' => '']));
    }

    public function testConfermeFirmeEPratiche(): void
    {
        $p = $this->pratica();
        $this->db->esegui("INSERT INTO pratiche_conferme (pratica_id, passo, nome, email, token, richiesta_il) VALUES (?, 1, 'Verdi Anna', 'Anna.Verdi@x.it', 'tc1', NOW() - INTERVAL 9 DAY)", [$p]);
        $this->db->esegui("INSERT INTO pratiche_conferme (pratica_id, passo, nome, email, token, stato) VALUES (?, 1, 'Neri Giulia', 'giulia@x.it', 'tc2', 'confermata')", [$p]);
        $this->db->esegui("INSERT INTO pratiche_firme (pratica_id, passo, tipo, nome, email, token) VALUES (?, 1, 'persona', 'Anna Verdi', 'anna.verdi@x.it', 'tf1')", [$p]);
        $g = $this->gruppi('anna.verdi@x.it');
        $this->assertSame(['conferme', 'firme'], array_keys($g));
        $this->assertSame(1, $g['conferme']['n']);
        $this->assertSame('conferma_attestato.php?t=tc1', $g['conferme']['righe'][0]['link']);
        $this->assertSame(9, $g['conferme']['righe'][0]['giorni']);
        $this->assertStringContainsString('Attestato Frequenza Lezione · Rossi Luca', $g['conferme']['righe'][0]['testo']);
        $this->assertSame('firma_pratica.php?t=tf1', $g['firme']['righe'][0]['link']);
        $this->assertSame([], $this->gruppi('giulia@x.it'), 'una conferma già data non conta');
        // pratica conclusa o in bozza: non conta più
        $this->db->esegui("UPDATE pratiche SET stato = 'chiusa' WHERE id = ?", [$p]);
        $this->assertSame([], $this->gruppi('anna.verdi@x.it'));
    }

    public function testTutorato(): void
    {
        $this->db->esegui("INSERT INTO tutorato_incarichi (bando_id, codice, stato, cognome, nome, attivita, docente_email, token_docente, confermata_il) VALUES (1, 'TU-1', 'confermata', 'Greco', 'Marco', 'Tutorato di Chimica', 'Prof@x.it', 'td1', NOW() - INTERVAL 3 DAY)");
        $this->db->esegui("INSERT INTO tutorato_incarichi (bando_id, codice, stato, cognome, nome, docente_email, fine_stato) VALUES (1, 'TU-2', 'protocollata', 'Neri', 'Paola', 'prof@x.it', 'richiesta')");
        $id3 = $this->db->inserisci("INSERT INTO tutorato_incarichi (bando_id, codice, stato, cognome, nome, docente_email) VALUES (1, 'TU-3', 'protocollata', 'Basile', 'Anna', 'prof@x.it')");
        $this->db->esegui("INSERT INTO tutorato_registro (incarico_id, data, ore, stato, creata_il) VALUES (?, '2026-10-01', 4, 'inviata', NOW() - INTERVAL 8 DAY), (?, '2026-10-02', 4, 'approvata', NOW())", [$id3, $id3]);
        $g = $this->gruppi('prof@x.it');
        $this->assertSame(['tutorato_lettere', 'tutorato_registri'], array_keys($g));
        $this->assertSame('firma_incarico.php?t=td1', $g['tutorato_lettere']['righe'][0]['link']);
        $this->assertSame(2, $g['tutorato_registri']['n'], 'un tutor con la dichiarazione di fine attività da confermare e uno con attività da approvare');
        $dett = implode('|', array_column($g['tutorato_registri']['righe'], 'dettaglio'));
        $this->assertStringContainsString('1 attività da approvare', $dett);
        $this->assertStringContainsString('conferma', $dett);
    }

    public function testValutazioniElenchiRisorseVerbaliEPraticheDelloOperatore(): void
    {
        // scheda di valutazione FSL invitata e non compilata
        $this->db->esegui("INSERT INTO pagine_eventi (id, titolo) VALUES (1, 'FSL')");
        $ev = $this->db->inserisci("INSERT INTO eventi (pagina_id, titolo, tipo) VALUES (1, 'Laboratorio di zoologia', 'progetto')");
        $t = $this->db->inserisci('INSERT INTO turni (evento_id, data_turno) VALUES (?, CURDATE())', [$ev]);
        $this->db->esegui('INSERT INTO progetti_dettagli (evento_id, per_scuole, attestati, convenzione, data_fine) VALUES (?, 1, 1, 1, CURDATE() - INTERVAL 5 DAY)', [$ev]);
        $pr = $this->db->inserisci("INSERT INTO prenotazioni (turno_id, codice_prenotazione, email, valutazione_token, valutazione_inviata) VALUES (?, 'FS-1', 'Doc@scuola.it', 'tv1', NOW() - INTERVAL 4 DAY)", [$t]);
        // elenco degli studenti mancante (stesso docente)
        // risorsa da approvare e verbale da firmare
        $ris = $this->db->inserisci("INSERT INTO risorse (nome, tipo) VALUES ('Aula 3', 'aula')");
        $this->db->esegui("INSERT INTO risorse_responsabili (risorsa_id, persona_id, email, puo_approvare) VALUES (?, 'p1', 'resp@x.it', 1)", [$ris]);
        $this->db->esegui("INSERT INTO prenotazioni_risorse (risorsa_id, stato, nome, cognome, inizio, fine, creata_il) VALUES (?, 'da_approvare', 'Luca', 'Rossi', NOW() + INTERVAL 3 DAY, NOW() + INTERVAL 3 DAY + INTERVAL 2 HOUR, NOW() - INTERVAL 2 DAY)", [$ris]);
        $this->db->esegui("INSERT INTO didattica_consigli_persone (consiglio_id, ruolo, email, nominativo) VALUES (1, 'segretario', 'seg@x.it', 'Maria Segretaria'), (1, 'componente', 'comp@x.it', 'Paolo')");
        $this->db->esegui("INSERT INTO didattica_sedute (consiglio_id, organo, data, verbale_stato, verbale_token) VALUES (1, 'Consiglio di Biologia', '2026-10-01', 'segretario', 'vt1')");
        // pratiche dell'operatore
        $op = $this->db->inserisci("INSERT INTO uffici_persone (email, nominativo, ufficio_id) VALUES ('op@x.it', 'Gianna', 7)");
        $this->db->esegui('UPDATE uffici_persone SET op_id = id WHERE id = ?', [$op]);   // numero stabile della persona come operatore
        $p1 = $this->pratica('in_lavorazione');
        $this->db->esegui('UPDATE pratiche SET assegnata_a = ? WHERE id = ?', [$op, $p1]);
        $p2 = $this->pratica('inviata');
        $this->db->esegui('UPDATE pratiche SET ufficio_id = 7 WHERE id = ?', [$p2]);

        $g = $this->gruppi('doc@scuola.it');
        $this->assertSame(['valutazioni', 'elenchi'], array_keys($g));
        $this->assertSame('valutazione_fsl.php?t=tv1', $g['valutazioni']['righe'][0]['link']);
        $this->assertSame('elenco_studenti.php?code=FS-1', $g['elenchi']['righe'][0]['link']);
        $this->db->esegui('INSERT INTO partecipanti_prenotazione (prenotazione_id, cognome, nome) VALUES (?, \'Rossi\', \'Luca\')', [$pr]);
        $this->assertArrayNotHasKey('elenchi', $this->gruppi('doc@scuola.it'), 'con l\'elenco inserito non c\'è più nulla da fare');
        $this->db->esegui("INSERT INTO valutazioni_fsl (prenotazione_id, evento_id) VALUES (?, ?)", [$pr, $ev]);
        $this->assertSame([], $this->gruppi('doc@scuola.it'));

        $this->assertSame(['risorse'], array_keys($this->gruppi('RESP@x.it')));
        $this->assertSame(['verbali'], array_keys($this->gruppi('seg@x.it')));
        $this->assertSame('firma_verbale.php?t=vt1', $this->gruppi('seg@x.it')['verbali']['righe'][0]['link']);
        $this->assertSame([], $this->gruppi('comp@x.it'), 'un componente semplice non firma');
        $pr_op = $this->gruppi('op@x.it')['pratiche'];
        $this->assertSame(2, $pr_op['n'], 'quella in carico a lui e quella del suo ufficio da prendere in carico');
        $this->assertStringContainsString('in carico a te', implode('|', array_column($pr_op['righe'], 'dettaglio')));
    }

    public function testRiepilogoSettimanaleUnaVoltaASettimanaESoloSeCeQualcosa(): void
    {
        $p = $this->pratica();
        $this->db->esegui("INSERT INTO pratiche_conferme (pratica_id, passo, nome, email, token) VALUES (?, 1, 'Verdi Anna', 'anna.verdi@x.it', 'tc1'), (?, 1, 'Neri Giulia', 'giulia.neri@x.it', 'tc2')", [$p, $p]);
        $this->db->esegui("INSERT INTO uffici_persone (email, nominativo, ufficio_id) VALUES ('senza@x.it', 'Senza Pratiche', 7)");
        $r = $this->c->get(RiepilogoPersone::class);
        $this->assertTrue($r->ora(new \DateTimeImmutable('2026-10-14 08:00:00')));
        $this->assertFalse($r->ora(new \DateTimeImmutable('2026-10-14 06:30:00')), 'non prima delle 7');
        $this->assertFalse($r->ora(new \DateTimeImmutable('2026-10-17 09:00:00')), 'non il sabato');
        [$inviati, $saltati] = $r->invia();
        $this->assertSame(2, $inviati);
        $this->assertSame(1, $saltati, 'chi non ha nulla da fare non riceve niente');
        $a = array_column($this->mailer->inviate, 'a');
        sort($a);
        $this->assertSame(['anna.verdi@x.it', 'giulia.neri@x.it'], $a);
        $m = $this->mailer->inviate[0];
        $this->assertSame('Da fare questa settimana: 1 cosa', $m['oggetto']);
        $this->assertStringContainsString('conferma_attestato.php?t=tc', $m['corpo']);
        $this->assertMatchesRegularExpression('#riepilogo_persone\.php\?t=[a-f0-9]{40}#', $m['corpo'], 'il link per non riceverlo più');
        // un secondo passaggio nella stessa settimana non rimanda nulla
        $this->mailer->inviate = [];
        $this->assertSame([0, 0], $r->invia());
        $this->assertSame([], $this->mailer->inviate);
    }

    public function testDisiscrizioneERiattivazione(): void
    {
        $p = $this->pratica();
        $this->db->esegui("INSERT INTO pratiche_conferme (pratica_id, passo, nome, email, token) VALUES (?, 1, 'Verdi Anna', 'anna.verdi@x.it', 'tc1')", [$p]);
        $r = $this->c->get(RiepilogoPersone::class);
        $r->invia();
        $token = (string) $this->db->valore("SELECT token FROM riepilogo_persone WHERE email = 'anna.verdi@x.it'");
        $this->assertNotNull($r->perToken($token));
        $this->assertNull($r->perToken('non-valido'));
        $this->assertTrue($r->imposta($token, false));
        $this->assertSame(1, (int) $this->db->valore("SELECT escluso FROM riepilogo_persone WHERE email = 'anna.verdi@x.it'"));
        // la settimana dopo: disiscritta, non riceve
        $this->db->esegui("UPDATE riepilogo_persone SET settimana = '2026-01' WHERE email = 'anna.verdi@x.it'");
        $this->mailer->inviate = [];
        $r->invia();
        $this->assertSame([], $this->mailer->inviate);
        $this->assertTrue($r->imposta($token, true));
        $this->db->esegui("UPDATE riepilogo_persone SET settimana = '2026-01' WHERE email = 'anna.verdi@x.it'");
        $r->invia();
        $this->assertCount(1, $this->mailer->inviate, 'riattivato');
    }

    public function testIlDirettoreRiceveAncheIlCruscotto(): void
    {
        $this->impostaDirettore('Mario Bianchi', 'direttore@x.it');
        $p = $this->pratica();
        $this->db->esegui("INSERT INTO pratiche_firme (pratica_id, passo, tipo, nome, email, token) VALUES (?, 3, 'direttore', 'Mario Bianchi', 'direttore@x.it', 'tf9')", [$p]);
        $r = $this->c->get(RiepilogoPersone::class);
        $r->invia();
        $m = array_values(array_filter($this->mailer->inviate, fn ($x) => $x['a'] === 'direttore@x.it'))[0] ?? null;
        $this->assertNotNull($m);
        $this->assertStringContainsString('Il cruscotto del Direttore', $m['corpo']);
        $this->assertStringContainsString('Documenti da firmare', $m['corpo']);
    }

    public function testSpentoDalFileEnv(): void
    {
        file_put_contents($this->envFile, "RIEPILOGO_PERSONE=0\n");
        $this->assertSame('', $this->c->get(RiepilogoPersone::class)->cron());
    }
}
