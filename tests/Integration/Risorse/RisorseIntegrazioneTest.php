<?php

declare(strict_types=1);

namespace Tests\Integration\Risorse;

use App\Auth\ServizioUtenti;
use App\Auth\UtenteRepository;
use App\Core\Sito;
use App\Risorse\NotificheRisorse;
use App\Risorse\PromemoriaPrenotazioni;
use App\Risorse\RisorsaRepository;
use App\Risorse\ServizioAuleTurni;
use App\Risorse\ServizioGestioneRisorse;
use App\Risorse\ServizioRisorse;
use App\Risorse\StatisticheRisorse;
use App\Risorse\Vista\Calendario;
use Tests\Doppi\MailerFinto;
use Tests\Doppi\OrologioFisso;
use Tests\Integration\DatabaseDiProva;

/** Il «oggi» dei test è lunedì 5 ottobre 2026, ore 08:00. */
final class RisorseIntegrazioneTest extends DatabaseDiProva
{
    private RisorsaRepository $repo;
    private MailerFinto $mailer;
    private ServizioRisorse $servizio;
    private NotificheRisorse $notifiche;

    protected function tabelle(): array
    {
        return [
            'pagine_eventi' => "CREATE TABLE pagine_eventi (id INT PRIMARY KEY, slug VARCHAR(80), titolo VARCHAR(150) DEFAULT '', colore_primario VARCHAR(20) DEFAULT '#1D4ED8', gestore_utente_id INT DEFAULT 0,
                gestori_utenti_ids VARCHAR(200) DEFAULT '', permessi_gestori_json TEXT NULL)",
            'utenti' => "CREATE TABLE utenti (id INT PRIMARY KEY, nome VARCHAR(80) DEFAULT '', cognome VARCHAR(80) DEFAULT '', email VARCHAR(150) DEFAULT '', ruolo_id INT DEFAULT 5, ruoli_secondari VARCHAR(100) DEFAULT '', matricola_studente VARCHAR(20) NULL)",
            'ruoli' => 'CREATE TABLE ruoli (id INT PRIMARY KEY, nome VARCHAR(100))',
            'risorse' => "CREATE TABLE risorse (id INT AUTO_INCREMENT PRIMARY KEY, pagina_id INT NOT NULL, nome VARCHAR(150) NOT NULL DEFAULT '', tipo VARCHAR(20) NOT NULL DEFAULT 'aula', descrizione TEXT NULL,
                luogo VARCHAR(255) DEFAULT '', capienza INT NULL, referente VARCHAR(150) DEFAULT '', email_notifiche VARCHAR(500) DEFAULT '', durata_slot SMALLINT NOT NULL DEFAULT 60, max_slot TINYINT NOT NULL DEFAULT 2,
                anticipo_ore SMALLINT NOT NULL DEFAULT 2, max_giorni SMALLINT NOT NULL DEFAULT 60, accesso VARCHAR(20) NOT NULL DEFAULT 'tutti', approvazione TINYINT NOT NULL DEFAULT 0, ripetizione TINYINT NOT NULL DEFAULT 0,
                chiede_motivo TINYINT NOT NULL DEFAULT 1, attiva TINYINT NOT NULL DEFAULT 1, ordine INT NOT NULL DEFAULT 0, colore VARCHAR(7) NULL, persona_id VARCHAR(80) NULL, ufficio VARCHAR(20) NOT NULL DEFAULT '')",
            'risorse_orari' => 'CREATE TABLE risorse_orari (id INT AUTO_INCREMENT PRIMARY KEY, risorsa_id INT NOT NULL, giorno TINYINT NOT NULL, dalle TIME NOT NULL, alle TIME NOT NULL)',
            'risorse_chiusure' => "CREATE TABLE risorse_chiusure (id INT AUTO_INCREMENT PRIMARY KEY, pagina_id INT NOT NULL, risorsa_id INT NULL, dal DATE NOT NULL, al DATE NOT NULL, motivo VARCHAR(255) DEFAULT '')",
            'prenotazioni_risorse' => "CREATE TABLE prenotazioni_risorse (id INT AUTO_INCREMENT PRIMARY KEY, risorsa_id INT NOT NULL, utente_id INT NULL, nome VARCHAR(100) DEFAULT '', cognome VARCHAR(100) DEFAULT '',
                email VARCHAR(255) DEFAULT '', inizio DATETIME NOT NULL, fine DATETIME NOT NULL, motivo VARCHAR(500) DEFAULT '', stato VARCHAR(20) NOT NULL DEFAULT 'confermata', serie VARCHAR(12) NULL,
                codice VARCHAR(20) NOT NULL, nota_gestore VARCHAR(500) DEFAULT '', promemoria_inviato TINYINT NOT NULL DEFAULT 0, creata_il DATETIME DEFAULT CURRENT_TIMESTAMP, turno_id INT NULL, UNIQUE KEY uq (codice))",
            'turni' => 'CREATE TABLE turni (id INT AUTO_INCREMENT PRIMARY KEY, evento_id INT, nome_turno VARCHAR(150) NULL, data_turno DATE NULL, orario_inizio TIME NULL, orario_fine TIME NULL, risorsa_id INT NULL)',
            'eventi' => "CREATE TABLE eventi (id INT AUTO_INCREMENT PRIMARY KEY, titolo VARCHAR(150) DEFAULT '')",
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new RisorsaRepository($this->db);
        $this->mailer = new MailerFinto();
        $this->notifiche = new NotificheRisorse($this->repo, new ServizioUtenti(new UtenteRepository($this->db)), $this->mailer, new Sito(sys_get_temp_dir(), 'https://portale.test/eventi'));
        $this->servizio = new ServizioRisorse($this->repo, $this->notifiche, new OrologioFisso('2026-10-05 08:00:00'));
        $this->db->esegui("INSERT INTO pagine_eventi (id, slug, titolo, colore_primario, gestore_utente_id) VALUES (1, 'aule', 'AULE', '#112233', 2)");
        $this->db->esegui("INSERT INTO ruoli (id, nome) VALUES (1, 'Super Amministratore'), (2, 'Gestore'), (3, 'Studente'), (4, 'Docente/Dipendente'), (5, 'Ospite'), (6, 'Docenti'), (7, 'Personale tecnico amministrativo'), (8, 'Altro personale di Ateneo')");
        $this->db->esegui("INSERT INTO utenti (id, nome, cognome, email, ruolo_id, ruoli_secondari) VALUES
            (1, 'Ada', 'Admin', 'ADMIN@x.it', 1, ''), (2, 'Gino', 'Gestore', 'gestore@x.it', 2, ''), (3, 'Stella', 'Studente', 'stella@x.it', 3, ''),
            (4, 'Dario', 'Docente', 'dario@x.it', 5, '6'), (5, 'Olga', 'Ospite', 'olga@x.it', 5, ''), (6, 'Piero', 'Pta', 'piero@x.it', 5, '7')");
    }

    private function risorsa(array $v = []): int
    {
        $v += ['nome' => 'Aula 1', 'tipo' => 'aula', 'accesso' => 'tutti', 'approvazione' => 0, 'ripetizione' => 1, 'durata_slot' => 60, 'max_slot' => 2, 'anticipo_ore' => 0, 'max_giorni' => 60, 'attiva' => 1, 'email_notifiche' => '', 'luogo' => 'Cubo 4B'];
        $id = $this->db->inserisci(
            'INSERT INTO risorse (pagina_id, nome, tipo, accesso, approvazione, ripetizione, durata_slot, max_slot, anticipo_ore, max_giorni, attiva, email_notifiche, luogo) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$v['nome'], $v['tipo'], $v['accesso'], $v['approvazione'], $v['ripetizione'], $v['durata_slot'], $v['max_slot'], $v['anticipo_ore'], $v['max_giorni'], $v['attiva'], $v['email_notifiche'], $v['luogo']]
        );
        // lunedì e mercoledì 09-13 e 14:30-17:30
        foreach ([1, 3] as $g) {
            $this->db->esegui("INSERT INTO risorse_orari (risorsa_id, giorno, dalle, alle) VALUES (?, ?, '09:00:00', '13:00:00'), (?, ?, '14:30:00', '17:30:00')", [$id, $g, $id, $g]);
        }

        return $id;
    }

    /** @return array<string, string|null> */
    private function riga(int $id): array
    {
        return $this->repo->perId($id) ?? [];
    }

    private function utente(int $id): array
    {
        return $this->db->riga('SELECT * FROM utenti WHERE id = ?', [$id]) ?? [];
    }

    public function testSlotDelGiorno(): void
    {
        $id = $this->risorsa(['anticipo_ore' => 24, 'max_giorni' => 10]);
        $this->db->esegui("INSERT INTO prenotazioni_risorse (risorsa_id, inizio, fine, stato, codice) VALUES (?, '2026-10-12 10:00:00', '2026-10-12 11:00:00', 'confermata', 'A'), (?, '2026-10-12 12:00:00', '2026-10-12 13:00:00', 'annullata', 'B')", [$id, $id]);
        $r = $this->riga($id);

        $oggi = $this->servizio->slot($r, '2026-10-05');
        self::assertSame('passato', $oggi[0]['stato'], 'meno di 24 ore di anticipo');
        self::assertSame('09:00:00', substr($oggi[0]['inizio'], 11));

        $lun = $this->servizio->slot($r, '2026-10-12');
        self::assertCount(7, $lun, '4 slot di mattina e 3 di pomeriggio');
        self::assertSame(['libero', 'occupato', 'libero', 'libero'], array_column(array_slice($lun, 0, 4), 'stato'), 'la prenotazione annullata non occupa');
        self::assertSame([0, 0, 0, 0, 1, 1, 1], array_column($lun, 'fascia'));
        self::assertSame('2026-10-12 14:30:00', $lun[4]['inizio']);

        self::assertSame('libero', $this->servizio->slot($r, '2026-10-12', 1)[1]['stato'] ?? null);
        self::assertSame([], $this->servizio->slot($r, '2026-10-13'), 'martedì senza orari');
        self::assertSame('lontano', $this->servizio->slot($r, '2026-10-19')[0]['stato'], 'oltre i 10 giorni prenotabili');

        $this->db->esegui("INSERT INTO risorse_chiusure (pagina_id, risorsa_id, dal, al, motivo) VALUES (1, NULL, '2026-10-12', '2026-10-12', 'Ponte')");
        self::assertSame(['chiuso'], array_unique(array_column($this->servizio->slot($r, '2026-10-12'), 'stato')));
        self::assertSame('Ponte', $this->repo->chiusura($id, 1, '2026-10-12'));
        self::assertNull($this->repo->chiusura($id, 1, '2026-10-13'));
        $this->db->esegui("INSERT INTO risorse_chiusure (pagina_id, risorsa_id, dal, al, motivo) VALUES (1, ?, '2026-10-13', '2026-10-14', '')", [$id]);
        self::assertSame('Chiuso', $this->repo->chiusura($id, 1, '2026-10-14'), 'senza motivo');
    }

    public function testChiPuoPrenotare(): void
    {
        $tutti = $this->riga($this->risorsa());
        $studenti = $this->riga($this->risorsa(['accesso' => 'studenti']));
        $docenti = $this->riga($this->risorsa(['accesso' => 'docenti']));
        $personale = $this->riga($this->risorsa(['accesso' => 'personale']));
        $s = $this->servizio;

        self::assertFalse($s->puoPrenotare($tutti, null));
        self::assertFalse($s->puoPrenotare($tutti, ['nome' => 'senza id']));
        foreach ([$tutti, $studenti, $docenti, $personale] as $r) {
            self::assertTrue($s->puoPrenotare($r, $this->utente(1)), 'l\'amministratore prenota sempre');
            self::assertTrue($s->puoPrenotare($r, $this->utente(2)), 'il gestore dell\'area prenota sempre');
        }
        self::assertTrue($s->puoPrenotare($tutti, $this->utente(5)));
        self::assertTrue($s->puoPrenotare($studenti, $this->utente(3)));
        self::assertFalse($s->puoPrenotare($studenti, $this->utente(4)));
        self::assertTrue($s->puoPrenotare($docenti, $this->utente(4)), 'ruolo secondario Docenti');
        self::assertFalse($s->puoPrenotare($docenti, $this->utente(3)));
        self::assertTrue($s->puoPrenotare($personale, $this->utente(4)));
        self::assertTrue($s->puoPrenotare($personale, $this->utente(6)), 'personale tecnico amministrativo');
        self::assertFalse($s->puoPrenotare($personale, $this->utente(5)));
        self::assertTrue($s->puoPrenotare($studenti, ['id' => 9, 'ruolo_id' => 5, 'matricola_studente' => '12345']), 'con matricola si è studenti');
        self::assertSame(['studente', 'docenti'], $s->gruppiUtenteNomi(['ruolo_id' => 3, 'ruoli_secondari' => '6']));
    }

    public function testPrenotazioneSingolaESerie(): void
    {
        $id = $this->risorsa(['max_slot' => 2]);
        $r = $this->riga($id);
        $stella = $this->utente(3);
        $this->db->esegui("INSERT INTO prenotazioni_risorse (risorsa_id, inizio, fine, stato, codice) VALUES (?, '2026-10-19 09:00:00', '2026-10-19 10:00:00', 'confermata', 'X')", [$id]);

        $e = $this->servizio->prenota($r, $stella, '2026-10-12 09:00:00', 5, '  <b>Studio</b> di gruppo ');
        self::assertNull($e['errore']);
        self::assertSame('confermata', $e['stato']);
        self::assertCount(1, $e['codici'], 'una sola prenotazione');
        self::assertMatchesRegularExpression('/^RS-[0-9A-F]{8}$/', $e['codici'][0]);
        $p = $this->repo->prenotazione($e['codici'][0]);
        self::assertSame('2026-10-12 09:00:00', $p['inizio']);
        self::assertSame('2026-10-12 11:00:00', $p['fine'], 'al massimo 2 slot');
        self::assertSame('Studio di gruppo', $p['motivo']);
        self::assertSame('stella@x.it', $p['email']);
        self::assertSame('AULE', $p['area_titolo']);

        $e = $this->servizio->prenota($r, $stella, '2026-10-12 10:00:00', 1);
        self::assertSame("Lo slot scelto non è più disponibile (già prenotato): scegline un altro.", $e['errore']);
        $e = $this->servizio->prenota($r, $stella, '2026-10-12 12:00:00', 2);
        self::assertSame("Lo slot scelto non è più disponibile (durata oltre l'orario di apertura): scegline un altro.", $e['errore']);
        self::assertStringContainsString('orario non disponibile', (string) $this->servizio->prenota($r, $stella, '2026-10-12 20:00:00', 1)['errore']);
        self::assertSame('Orario non valido.', $this->servizio->prenota($r, $stella, 'boh', 1)['errore']);
    }

    public function testSerieSettimanaleConConflittoSaltato(): void
    {
        $id = $this->risorsa();
        $r = $this->riga($id);
        $this->db->esegui("INSERT INTO prenotazioni_risorse (risorsa_id, inizio, fine, stato, codice) VALUES (?, '2026-10-19 09:00:00', '2026-10-19 10:00:00', 'confermata', 'X')", [$id]);
        $e = $this->servizio->prenota($r, $this->utente(3), '2026-10-12 09:00:00', 1, 'Corso', '2026-11-02');
        self::assertNull($e['errore']);
        self::assertCount(3, $e['codici'], '12/10, 26/10 e 2/11');
        self::assertSame(['2026-10-19' => 'già prenotato'], $e['saltate']);
        self::assertMatchesRegularExpression('/^[0-9A-F]{8}$/', (string) $e['serie']);
        self::assertSame([$e['serie']], array_unique(array_column($this->db->righe("SELECT serie FROM prenotazioni_risorse WHERE codice LIKE 'RS-%'"), 'serie')));
        self::assertSame(['2026-10-12', '2026-10-19', '2026-10-26', '2026-11-02'], array_map(static fn (array $x): string => substr((string) $x['inizio'], 0, 10), $this->db->righe('SELECT inizio FROM prenotazioni_risorse ORDER BY inizio')));

        // senza ripetizione consentita la serie non si crea
        $senza = $this->riga($this->risorsa(['ripetizione' => 0]));
        $e = $this->servizio->prenota($senza, $this->utente(3), '2026-10-12 09:00:00', 1, '', '2026-11-30');
        self::assertCount(1, $e['codici']);
        self::assertNull($e['serie']);
    }

    public function testPrenotazioniNonConsentite(): void
    {
        $r = $this->riga($this->risorsa(['attiva' => 0]));
        self::assertSame('La risorsa non è prenotabile.', $this->servizio->prenota($r, $this->utente(1), '2026-10-12 09:00:00', 1)['errore']);
        $r = $this->riga($this->risorsa(['accesso' => 'studenti']));
        self::assertSame('Non sei abilitato a prenotare questa risorsa.', $this->servizio->prenota($r, $this->utente(5), '2026-10-12 09:00:00', 1)['errore']);
        $r = $this->riga($this->risorsa(['approvazione' => 1]));
        $e = $this->servizio->prenota($r, $this->utente(5), '2026-10-12 09:00:00', 1);
        self::assertSame('da_approvare', $e['stato']);
        self::assertSame('da_approvare', $this->repo->prenotazione($e['codici'][0])['stato']);
        // il blocco del calendario si può prendere due volte dalla stessa connessione e va sempre rilasciato
        self::assertTrue($this->repo->bloccaCalendario((int) $r['id']));
        $this->repo->rilasciaCalendario((int) $r['id']);
    }

    public function testApprovazioneERifiutoConLeEmail(): void
    {
        $id = $this->risorsa(['approvazione' => 1, 'email_notifiche' => 'segreteria@x.it, boh, SEGRETERIA@x.it, altro@x.it']);
        $e = $this->servizio->prenota($this->riga($id), $this->utente(3), '2026-10-12 09:00:00', 1, 'Tesi');
        $pid = (int) $this->repo->prenotazione($e['codici'][0])['id'];

        self::assertFalse($this->servizio->cambiaStato(9999, 'confermata'));
        self::assertFalse($this->servizio->cambiaStato($pid, 'inventato'));
        self::assertTrue($this->servizio->cambiaStato($pid, 'confermata'));
        self::assertFalse($this->servizio->cambiaStato($pid, 'rifiutata'), 'già approvata');
        self::assertCount(1, $this->mailer->inviate);
        $m = $this->mailer->inviate[0];
        self::assertSame('stella@x.it', $m['a']);
        self::assertSame('Prenotazione approvata: Aula 1', $m['oggetto']);
        self::assertSame('#112233', $m['colore']);
        self::assertStringContainsString('Lunedì 12/10/2026, 09:00–10:00', $m['corpo']);
        self::assertStringContainsString('https://portale.test/eventi/risorsa_ics.php?code=' . $e['codici'][0], $m['corpo']);
        self::assertStringContainsString('Motivo: Tesi', $m['corpo']);

        // annullata dall'utente: le email vanno ai gestori (indirizzi della risorsa validi, senza doppioni)
        self::assertTrue($this->servizio->cambiaStato($pid, 'annullata', false));
        $dest = array_column(array_slice($this->mailer->inviate, 1), 'a');
        self::assertSame(['segreteria@x.it', 'altro@x.it'], $dest);
        self::assertStringStartsWith('Annullata: Aula 1 – 12/10 09:00', $this->mailer->inviate[1]['oggetto']);
        self::assertStringContainsString('ha annullato la prenotazione', $this->mailer->inviate[1]['corpo']);

        // rifiuto da parte del gestore
        $e2 = $this->servizio->prenota($this->riga($id), $this->utente(3), '2026-10-12 10:00:00', 1);
        $p2 = (int) $this->repo->prenotazione($e2['codici'][0])['id'];
        $prima = count($this->mailer->inviate);
        self::assertTrue($this->servizio->cambiaStato($p2, 'rifiutata'));
        self::assertSame('Prenotazione non approvata: Aula 1', $this->mailer->inviate[$prima]['oggetto']);
        self::assertSame('rifiutata', $this->repo->prenotazione($p2)['stato']);
    }

    public function testChiRiceveLeNotificheDeiGestori(): void
    {
        $id = $this->risorsa();
        $p = $this->repo->perId($id) + ['pagina_id' => 1];
        $p['email_notifiche'] = '';
        // gestore dell'area (campo singolo) e gestori nel JSON dei permessi
        $this->db->esegui("UPDATE pagine_eventi SET permessi_gestori_json = '{\"3\":\"gestore\"}' WHERE id = 1");
        self::assertSame(['gestore@x.it', 'stella@x.it'], $this->notifiche->emailGestori($p));
        $this->db->esegui("UPDATE pagine_eventi SET gestore_utente_id = 0, permessi_gestori_json = NULL WHERE id = 1");
        self::assertSame(['admin@x.it'], $this->notifiche->emailGestori($p), 'senza gestori: gli amministratori');
    }

    public function testCalendarioDelGiornoDellaSettimanaEDelMese(): void
    {
        $id = $this->risorsa(['nome' => 'Aula <1>']);
        $this->db->esegui("UPDATE risorse SET colore = '#3366CC', capienza = 40 WHERE id = ?", [$id]);
        $this->db->esegui("INSERT INTO prenotazioni_risorse (risorsa_id, utente_id, nome, cognome, inizio, fine, motivo, stato, codice) VALUES
            (?, 3, 'Stella', 'Studente', '2026-10-12 09:00:00', '2026-10-12 11:00:00', 'Tesi', 'confermata', 'A'),
            (?, 4, 'Dario', 'Docente', '2026-10-12 11:00:00', '2026-10-12 12:00:00', 'Riunione', 'da_approvare', 'B')", [$id, $id]);
        $vista = new Calendario($this->repo, new OrologioFisso('2026-10-05 08:00:00'));
        $url = static fn (array $c): string => 'cal.php?' . http_build_query($c);
        $opz = ['vista' => 'giorno', 'data' => '2026-10-12', 'uid' => 3, 'gestore' => false, 'url' => $url, 'url_risorsa' => static fn (int $i, string $g): string => "aula.php?risorsa=$i&dal=$g"];
        $ris = $this->db->righe('SELECT * FROM risorse');

        $g = $vista->html($ris, $opz);
        self::assertStringContainsString('Lunedì 12/10/2026', $g);
        self::assertStringContainsString('Aula &lt;1&gt;', $g);
        self::assertStringContainsString('40 posti', $g);
        self::assertStringContainsString('La tua prenotazione', $g, 'le proprie si riconoscono');
        self::assertStringContainsString('In sospeso', $g);
        self::assertStringNotContainsString('STUDENTE STELLA', $g, 'i nomi si vedono solo ai gestori');
        self::assertStringContainsString('Mie prenotazioni', $g);
        self::assertStringContainsString('data-href="aula.php?risorsa=' . $id . '&amp;dal=2026-10-12"', $g);
        self::assertStringContainsString('border-left:5px solid #3366CC', $g);

        $gest = $vista->html($ris, ['gestore' => true, 'uid' => 0] + $opz);
        self::assertStringContainsString('STUDENTE STELLA', $gest);
        self::assertStringContainsString('Riunione', $gest);
        self::assertStringNotContainsString('Mie prenotazioni', $gest, 'senza utente la legenda non ha «mie»');

        $sett = $vista->html($ris, ['vista' => 'settimana'] + $opz);
        self::assertSame(7, substr_count($sett, 'cal-ris-giorno mb-3'));
        $mese = $vista->html($ris, ['vista' => 'mese'] + $opz);
        self::assertStringContainsString('Ottobre 2026', $mese);
        self::assertStringContainsString('2 prenotazioni', $mese);
        self::assertStringContainsString('1 in sospeso', $mese);
        self::assertStringContainsString('1 tue', $mese);
        self::assertStringContainsString('Nessuna risorsa con questi filtri', $vista->html([], $opz));
        self::assertStringContainsString('Non prenotabile', $vista->html($this->db->righe('SELECT * FROM risorse'), ['data' => '2026-10-13'] + $opz), 'giorno senza orari');
        self::assertStringContainsString('.cal-ris-giorno', $vista->css());
    }

    public function testStatistiche(): void
    {
        $id = $this->risorsa();
        $this->db->esegui("INSERT INTO prenotazioni_risorse (risorsa_id, inizio, fine, stato, codice) VALUES
            (?, '2026-10-12 09:00:00', '2026-10-12 11:00:00', 'confermata', 'A'), (?, '2026-10-12 10:00:00', '2026-10-12 11:30:00', 'confermata', 'B'),
            (?, '2026-10-14 15:00:00', '2026-10-14 16:00:00', 'annullata', 'C'), (?, '2026-10-14 16:00:00', '2026-10-14 17:00:00', 'rifiutata', 'D'),
            (?, '2026-10-14 09:00:00', '2026-10-14 10:00:00', 'da_approvare', 'E'), (?, '2027-01-14 09:00:00', '2027-01-14 10:00:00', 'confermata', 'F')", [$id, $id, $id, $id, $id, $id]);
        $s = (new StatisticheRisorse($this->repo))->statistiche(1, '2026-10-12', '2026-10-18');
        $r = $s['risorse'][$id];
        self::assertSame([5, 2, 1, 1], [$r['prenotazioni'], $r['confermate'], $r['annullate'], $r['rifiutate']]);
        self::assertSame(3.5, $r['ore']);
        self::assertSame(14.0, $r['ore_aperte'], '2 giorni × (4 + 3 ore)');
        self::assertSame(25, $r['utilizzo']);
        self::assertSame([1 => [9 => 1, 10 => 2, 11 => 1]], $s['fasce']);
        self::assertSame(['prenotazioni' => 5, 'ore' => 3.5, 'annullate' => 1, 'rifiutate' => 1], $s['totali']);
        self::assertSame(['risorse' => [], 'fasce' => [], 'totali' => []], (new StatisticheRisorse($this->repo))->statistiche(99, '2026-10-12', '2026-10-18'));
    }

    public function testAulaDelTurnoDiUnEvento(): void
    {
        $id = $this->risorsa(['nome' => 'Aula Magna']);
        $this->db->esegui("UPDATE risorse SET capienza = 200 WHERE id = ?", [$id]);
        $this->risorsa(['nome' => 'Sportello', 'tipo' => 'sportello']);
        $this->db->esegui("INSERT INTO eventi (id, titolo) VALUES (1, 'Seminario')");
        $t = $this->db->inserisci("INSERT INTO turni (evento_id, nome_turno, data_turno, orario_inizio, orario_fine, risorsa_id) VALUES (1, 'Mattina', '2026-10-12', '09:00:00', '11:00:00', ?)", [$id]);
        $s = new ServizioAuleTurni($this->repo);
        self::assertSame(['AULE' => [$id => 'Aula Magna (200 posti)']], $s->perTurni(), 'gli sportelli non sono aule');

        $avvisi = [];
        $s->sincronizza($t, $avvisi, 7);
        self::assertSame([], $avvisi);
        $p = $this->db->riga('SELECT * FROM prenotazioni_risorse WHERE turno_id = ?', [$t]);
        self::assertSame(['2026-10-12 09:00:00', '2026-10-12 11:00:00', 'confermata', 7, 'Seminario'], [$p['inizio'], $p['fine'], $p['stato'], $p['utente_id'], $p['nome']]);
        self::assertSame('Evento: Seminario – Mattina · 12/10/2026 · 09:00–11:00', $p['motivo']);
        self::assertMatchesRegularExpression('/^AU-[0-9A-F]{8}$/', $p['codice']);

        $s->sincronizza($t, $avvisi, 7);
        self::assertSame(1, (int) $this->db->valore('SELECT COUNT(*) FROM prenotazioni_risorse'), 'già a posto: niente doppioni');

        $this->db->esegui("UPDATE turni SET orario_inizio = '10:00:00', orario_fine = '12:00:00' WHERE id = ?", [$t]);
        $s->sincronizza($t, $avvisi);
        self::assertSame(['2026-10-12 10:00:00', '2026-10-12 12:00:00'], array_values($this->db->riga('SELECT inizio, fine FROM prenotazioni_risorse WHERE turno_id = ?', [$t])), 'orari aggiornati');

        // un'altra prenotazione occupa l'aula: il turno non la prende e si avvisa
        $this->db->esegui("UPDATE turni SET orario_inizio = '13:00:00', orario_fine = '14:00:00' WHERE id = ?", [$t]);
        $this->db->esegui("INSERT INTO prenotazioni_risorse (risorsa_id, inizio, fine, stato, codice) VALUES (?, '2026-10-12 13:30:00', '2026-10-12 14:30:00', 'confermata', 'ALTRA')", [$id]);
        $s->sincronizza($t, $avvisi);
        self::assertSame("turno \"Mattina · 12/10/2026 · 13:00–14:00\": Aula Magna non è stata prenotata perché il 12/10/2026 è già occupata dalle 13:30 alle 14:30: scegli un'altra aula o un altro orario", $avvisi[0]);
        self::assertSame('annullata', $this->db->valore('SELECT stato FROM prenotazioni_risorse WHERE turno_id = ?', [$t]), 'liberata la vecchia');

        // giorno chiuso, ora di fine mancante, aula tolta
        $this->db->esegui("INSERT INTO risorse_chiusure (pagina_id, risorsa_id, dal, al, motivo) VALUES (1, NULL, '2026-10-12', '2026-10-12', 'Ponte')");
        $this->db->esegui("UPDATE turni SET orario_inizio = '16:00:00', orario_fine = '17:00:00' WHERE id = ?", [$t]);
        $avvisi = [];
        $s->sincronizza($t, $avvisi);
        self::assertStringContainsString('è chiusa (Ponte)', $avvisi[0]);
        $this->db->esegui("UPDATE turni SET orario_fine = NULL WHERE id = ?", [$t]);
        $avvisi = [];
        $s->sincronizza($t, $avvisi);
        self::assertStringContainsString('servono data, ora di inizio e ora di fine', $avvisi[0]);
        $this->db->esegui("DELETE FROM risorse_chiusure");
        $this->db->esegui("UPDATE turni SET orario_fine = '17:00:00' WHERE id = ?", [$t]);
        $s->sincronizza($t, $avvisi);
        self::assertSame('confermata', $this->db->valore("SELECT stato FROM prenotazioni_risorse WHERE turno_id = ? AND stato = 'confermata'", [$t]));
        $this->db->esegui('UPDATE turni SET risorsa_id = NULL WHERE id = ?', [$t]);
        $s->sincronizza($t, $avvisi);
        self::assertSame(0, (int) $this->db->valore("SELECT COUNT(*) FROM prenotazioni_risorse WHERE turno_id = ? AND stato = 'confermata'", [$t]), 'aula tolta: slot liberato');
        $s->sincronizza(9999, $avvisi);
    }

    public function testGestioneDelleRisorse(): void
    {
        $g = new ServizioGestioneRisorse($this->repo);
        $v = ['nome' => 'Sportello Rossi', 'tipo' => 'sportello', 'descr' => 'Info', 'luogo' => 'Studio 1', 'capienza' => null, 'referente' => '', 'emails' => '', 'durata' => 15, 'max_slot' => 1,
            'anticipo' => 12, 'max_giorni' => 30, 'accesso' => 'tutti', 'appr' => 1, 'rip' => 0, 'motivo' => 1, 'attiva' => 1];
        $id = $g->salva(1, 0, $v, '#AABBCC', ['id' => 'm.rossi', 'email' => 'm.rossi@x.it'], 'Mario Rossi', [[1, '09:00:00', '10:00:00'], [3, '15:00:00', '16:00:00']]);
        $r = $this->riga($id);
        self::assertSame(['Sportello Rossi', '#AABBCC', 'm.rossi', 'm.rossi@x.it', 'Mario Rossi', '1'], [$r['nome'], $r['colore'], $r['persona_id'], $r['email_notifiche'], $r['referente'], $r['ordine']]);
        self::assertSame([1 => [['09:00:00', '10:00:00']], 3 => [['15:00:00', '16:00:00']]], (new RisorsaRepository($this->db))->orari($id));
        $secondo = $g->salva(1, 0, ['nome' => 'Seconda'] + $v, null, null, null, []);
        self::assertSame('2', $this->riga($secondo)['ordine'], 'in fondo all\'elenco');

        // modifica: niente docente, orari sostituiti, la cache degli orari si aggiorna
        $this->repo->orari($id);
        $g->salva(1, $id, ['nome' => 'Sportello bis', 'referente' => 'Ref', 'emails' => 'a@x.it', 'capienza' => 12] + $v, null, null, null, [[2, '10:00:00', '11:00:00']]);
        $r = $this->riga($id);
        self::assertSame(['Sportello bis', null, null, 'a@x.it', 'Ref', '12'], [$r['nome'], $r['colore'], $r['persona_id'], $r['email_notifiche'], $r['referente'], $r['capienza']]);
        self::assertSame([2 => [['10:00:00', '11:00:00']]], $this->repo->orari($id));
        $g->salva(2, $id, ['nome' => 'Altra area'] + $v, null, null, null, []);
        self::assertSame('Sportello bis', $this->riga($id)['nome'], 'una risorsa di un\'altra area non si modifica');

        // eliminazione: con prenotazioni future si disattiva, altrimenti si cancella tutto
        $this->db->esegui("INSERT INTO prenotazioni_risorse (risorsa_id, inizio, fine, stato, codice) VALUES (?, '2999-01-01 09:00:00', '2999-01-01 10:00:00', 'confermata', 'FUT')", [$id]);
        self::assertSame(1, $g->eliminaODisattiva($id));
        self::assertSame('0', $this->riga($id)['attiva']);
        $this->db->esegui('DELETE FROM prenotazioni_risorse');
        $this->db->esegui("INSERT INTO risorse_chiusure (pagina_id, risorsa_id, dal, al) VALUES (1, ?, '2026-10-12', '2026-10-12')", [$id]);
        self::assertSame(0, $g->eliminaODisattiva($id));
        self::assertNull($this->repo->perId($id));
        self::assertSame(0, (int) $this->db->valore('SELECT COUNT(*) FROM risorse_orari WHERE risorsa_id = ?', [$id]) + (int) $this->db->valore('SELECT COUNT(*) FROM risorse_chiusure'));
    }

    public function testElencoFiltriKpiEPromemoria(): void
    {
        $a = $this->risorsa(['nome' => 'A']);
        $b = $this->risorsa(['nome' => 'B']);
        $ora = static fn (string $mod): string => date('Y-m-d H:i:s', strtotime($mod));
        $ins = function (int $r, string $da, string $a2, string $stato, string $codice, string $nome = 'Rosa', ?string $serie = null): int {
            return $this->db->inserisci("INSERT INTO prenotazioni_risorse (risorsa_id, nome, cognome, email, inizio, fine, motivo, stato, serie, codice) VALUES (?, ?, 'Verdi', 'x@x.it', ?, ?, '100% sicuro', ?, ?, ?)", [$r, $nome, $da, $a2, $stato, $serie, $codice]);
        };
        $p1 = $ins($a, $ora('+2 hours'), $ora('+3 hours'), 'confermata', 'RS-0001', 'Rosa', 'S1');
        $ins($a, $ora('+30 hours'), $ora('+31 hours'), 'confermata', 'RS-0002', 'Rosa', 'S1');
        $ins($b, $ora('+3 days'), $ora('+3 days +1 hour'), 'da_approvare', 'RS-0003', 'Luca');
        $ins($b, $ora('-3 days'), $ora('-3 days +1 hour'), 'confermata', 'RS-0004', 'Anna');
        $ins($b, $ora('+5 days'), $ora('+5 days +1 hour'), 'annullata', 'RS-0005');
        $f = static fn (array $x): array => $x + ['risorsa' => 0, 'stato' => 'attive', 'periodo' => 'future', 'q' => ''];

        $codici = static fn (array $righe): array => array_column($righe, 'codice');
        self::assertSame(['RS-0001', 'RS-0002', 'RS-0003'], $codici($this->repo->elenco(1, $f([]))));
        self::assertSame(['RS-0004'], $codici($this->repo->elenco(1, $f(['periodo' => 'passate']))));
        self::assertSame(['RS-0004', 'RS-0001', 'RS-0002', 'RS-0003'], $codici($this->repo->elenco(1, $f(['periodo' => 'tutte']))), 'in ordine di data');
        self::assertSame(['RS-0003'], $codici($this->repo->elenco(1, $f(['stato' => 'da_approvare']))));
        self::assertSame(['RS-0005'], $codici($this->repo->elenco(1, $f(['stato' => 'annullata']))));
        self::assertSame(['RS-0001', 'RS-0002'], $codici($this->repo->elenco(1, $f(['risorsa' => $a]))));
        self::assertSame(['RS-0003'], $codici($this->repo->elenco(1, $f(['q' => 'luca']))));
        self::assertSame(['RS-0001', 'RS-0002', 'RS-0003'], $codici($this->repo->elenco(1, $f(['q' => '100%']))), 'il % si cerca come carattere');
        self::assertSame([], $this->repo->elenco(1, $f(['q' => '100_'])), 'e il _ non è un jolly');
        self::assertSame([], $this->repo->elenco(2, $f([])));
        self::assertSame([(int) $p1, (int) $this->db->valore("SELECT id FROM prenotazioni_risorse WHERE codice = 'RS-0002'")], $this->repo->idPrenotazioniDellaSerie('S1', $a));

        $kpi = $this->repo->kpi(1);
        self::assertSame('1', $kpi['da_approvare']);
        self::assertSame('2', $kpi['settimana']);
        self::assertSame(['A', 'B'], array_column($this->repo->sintesiDellArea(1), 'nome'));
        self::assertCount(2, $this->repo->elencoConFuture(1));
        self::assertSame('2', $this->repo->elencoConFuture(1)[0]['future']);
        self::assertSame(2, $this->repo->prenotazioniFuture($a));

        // promemoria: confermate che iniziano tra 1 e 24 ore e non l'hanno già avuto (qui solo RS-0001, a +2 ore)
        $this->db->esegui("UPDATE prenotazioni_risorse SET email = '' WHERE codice = 'RS-0003'");
        $pr = new PromemoriaPrenotazioni($this->repo, $this->notifiche);
        self::assertSame(1, $pr->inviaPromemoria());
        self::assertSame('x@x.it', $this->mailer->inviate[0]['a']);
        self::assertSame('Promemoria: prenotazione di domani: A', $this->mailer->inviate[0]['oggetto']);
        self::assertSame(0, $pr->inviaPromemoria(), 'una sola volta');
        $this->db->esegui("UPDATE prenotazioni_risorse SET email = '', promemoria_inviato = 0 WHERE codice = 'RS-0001'");
        self::assertSame(0, $pr->inviaPromemoria(), 'senza indirizzo non parte né si segna');
        self::assertSame('0', $this->db->valore("SELECT promemoria_inviato FROM prenotazioni_risorse WHERE codice = 'RS-0001'") . '');
    }

    public function testNoteChiusureEUtente(): void
    {
        $id = $this->risorsa();
        $p = $this->db->inserisci("INSERT INTO prenotazioni_risorse (risorsa_id, utente_id, inizio, fine, codice) VALUES (?, 3, '2999-01-01 09:00:00', '2999-01-01 10:00:00', 'Z')", [$id]);
        $this->repo->impostaNota($p, 'Portare il badge');
        self::assertSame('Portare il badge', $this->db->valore('SELECT nota_gestore FROM prenotazioni_risorse WHERE id = ?', [$p]));
        $this->repo->aggiungiChiusura(1, null, '2999-01-01', '2999-01-02', 'Ponte');
        $this->repo->aggiungiChiusura(1, $id, '2999-02-01', '2999-02-01', '');
        self::assertCount(2, $this->repo->chiusureRecenti(1));
        self::assertCount(1, $this->repo->chiusureFuture($id));
        self::assertSame(1, $this->repo->prenotazioniNeiGiorni(1, null, '2999-01-01', '2999-01-31'));
        self::assertSame(1, $this->repo->prenotazioniNeiGiorni(1, $id, '2999-01-01', '2999-01-31'));
        self::assertSame(0, $this->repo->prenotazioniDellaRisorsaNeiGiorni($id, '2999-03-01', '2999-03-02'));
        $this->repo->eliminaChiusura(1, 2);
        self::assertCount(2, $this->repo->chiusureRecenti(1), 'un\'altra area non cancella');
        $this->repo->eliminaChiusura(1, 1);
        $this->repo->eliminaChiusuraDellaRisorsa(2, $id);
        self::assertCount(0, $this->repo->chiusureRecenti(1));
        self::assertSame('Stella', $this->repo->utente(3)['nome']);
        self::assertNull($this->repo->utente(99));
        self::assertCount(1, $this->repo->prenotazioniDellUtente(1, 3));
        self::assertCount(1, $this->repo->appuntamentiFuturi($id));
    }
}
