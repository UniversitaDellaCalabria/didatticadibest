<?php

declare(strict_types=1);

namespace Tests\Integration\Iscritti;

use App\Anagrafi\ScuolaRepository;
use App\Anagrafi\ServizioScuole;
use App\Auth\Abilitazioni\AbilitazioniRepository;
use App\Auth\Abilitazioni\ModuliAree;
use App\Auth\Abilitazioni\ServizioAbilitazioni;
use App\Auth\UtenteRepository;
use App\Core\Orologio;
use App\Core\Sito;
use App\Eventi\EventoRepository;
use App\Infrastructure\Audit\AuditLog;
use App\Iscritti\Attestati;
use App\Iscritti\BadgeRepository;
use App\Iscritti\ClassiRepository;
use App\Iscritti\Convenzioni;
use App\Iscritti\CronRepository;
use App\Iscritti\EsitoAzione;
use App\Iscritti\FiltriIscritti;
use App\Iscritti\FormBuilderRepository;
use App\Iscritti\IscrittiRepository;
use App\Iscritti\Iscrizioni;
use App\Iscritti\MessaggiRepository;
use App\Iscritti\Operatore;
use App\Iscritti\ScannerRepository;
use App\Iscritti\ServizioBadge;
use App\Iscritti\ServizioClassi;
use App\Iscritti\ServizioEmailMassiva;
use App\Iscritti\ServizioFormBuilder;
use App\Iscritti\ServizioIscritti;
use App\Iscritti\ServizioMessaggi;
use App\Iscritti\ServizioScanner;
use App\Portale\AreeRepository;
use App\Portale\ColoriAree;
use DateTimeImmutable;
use Tests\Doppi\MailerFinto;
use Tests\Integration\DatabaseDiProva;

/** Il pannello degli iscritti (App\Iscritti) su un database di prova: repository e servizi con doppi per i moduli non migrati. */
final class IscrittiIntegrazioneTest extends DatabaseDiProva
{
    private MailerFinto $mailer;
    private IscrittiRepository $repo;
    private Operatore $op;
    /** @var list<string> */
    private array $chiamate = [];
    private bool $richiestaConvenzioneRiesce = true;
    private ?string $erroreProgetto = null;

    protected function tabelle(): array
    {
        return [
            'pagine_eventi' => "CREATE TABLE pagine_eventi (id INT PRIMARY KEY, slug VARCHAR(80), titolo VARCHAR(150) DEFAULT '', colore_primario VARCHAR(20) DEFAULT '#0056B3', gestore_utente_id INT DEFAULT 0, gestori_utenti_ids VARCHAR(200) DEFAULT '', permessi_gestori_json TEXT NULL)",
            'eventi' => "CREATE TABLE eventi (id INT AUTO_INCREMENT PRIMARY KEY, pagina_id INT, titolo VARCHAR(150) DEFAULT '', luogo VARCHAR(150) DEFAULT '', tipo VARCHAR(20) DEFAULT 'evento', archiviato TINYINT DEFAULT 0, ordine INT DEFAULT 0,
                abilita_presenze TINYINT DEFAULT 1, gestori_utenti_ids VARCHAR(200) DEFAULT '', permessi_gestori_json TEXT NULL, blocca_auto_archivio TINYINT DEFAULT 0)",
            'turni' => "CREATE TABLE turni (id INT AUTO_INCREMENT PRIMARY KEY, evento_id INT, nome_turno VARCHAR(150) NULL, data_turno DATE NULL, orario_inizio TIME NULL, orario_fine TIME NULL, max_posti INT DEFAULT 30,
                abilita_lista_attesa TINYINT DEFAULT 0, min_partecipanti INT NULL, max_partecipanti INT NULL)",
            'prenotazioni' => "CREATE TABLE prenotazioni (id INT AUTO_INCREMENT PRIMARY KEY, turno_id INT, utente_id INT NULL, codice_prenotazione VARCHAR(40) DEFAULT '', stato VARCHAR(30) NULL, presente INT DEFAULT 0, num_posti INT DEFAULT 1,
                nome VARCHAR(80) DEFAULT '', cognome VARCHAR(80) DEFAULT '', email VARCHAR(150) DEFAULT '', matricola VARCHAR(50) NULL, dati_custom_json TEXT NULL, data_prenotazione DATETIME DEFAULT CURRENT_TIMESTAMP,
                data_presenza DATETIME NULL, scuola_codice VARCHAR(20) NULL, convenzione VARCHAR(10) NULL, conv_promemoria INT DEFAULT 0, conv_promemoria_il DATETIME NULL, reminder_inviato TINYINT DEFAULT 0,
                email_post_evento_inviata TINYINT DEFAULT 0, promemoria_elenco_inviato TINYINT DEFAULT 0, conv_avviso_gestori TINYINT DEFAULT 0, attestato_inviato TINYINT DEFAULT 0)",
            'utenti' => "CREATE TABLE utenti (id INT PRIMARY KEY, nome VARCHAR(80) DEFAULT '', cognome VARCHAR(80) DEFAULT '', email VARCHAR(150) NULL, ruolo_id INT DEFAULT 5, ruoli_secondari VARCHAR(50) DEFAULT '',
                matricola_studente VARCHAR(50) NULL, matricola_dipendente VARCHAR(50) NULL, matricola VARCHAR(50) NULL, ultimo_accesso DATETIME NULL)",
            'messaggi_prenotazioni' => "CREATE TABLE messaggi_prenotazioni (id INT AUTO_INCREMENT PRIMARY KEY, prenotazione_id INT, mittente_tipo VARCHAR(20), mittente_id INT NULL, messaggio TEXT, letto TINYINT DEFAULT 0, data_invio DATETIME DEFAULT CURRENT_TIMESTAMP)",
            'partecipanti_prenotazione' => "CREATE TABLE partecipanti_prenotazione (id INT AUTO_INCREMENT PRIMARY KEY, prenotazione_id INT, cognome VARCHAR(100) DEFAULT '', nome VARCHAR(100) DEFAULT '', codice VARCHAR(20) NULL,
                escluso TINYINT DEFAULT 0, anonimizzato TINYINT DEFAULT 0, ordine INT DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
            'campi_form' => "CREATE TABLE campi_form (id INT AUTO_INCREMENT PRIMARY KEY, pagina_id INT, evento_id INT NULL, nome_campo VARCHAR(100), etichetta VARCHAR(255), tipo_campo VARCHAR(50) DEFAULT 'text',
                opzioni_select TEXT NULL, obbligatorio TINYINT DEFAULT 0, ordine INT DEFAULT 0, condizione_json TEXT NULL)",
            'progetti_dettagli' => 'CREATE TABLE progetti_dettagli (evento_id INT PRIMARY KEY, per_scuole TINYINT DEFAULT 1, attestati TINYINT DEFAULT 0, convenzione TINYINT DEFAULT 0, data_inizio DATE NULL, data_fine DATE NULL,
                min_studenti INT NULL, max_studenti INT NULL, referenti_json TEXT NULL, info_extra_json TEXT NULL, moduli_json TEXT NULL)',
            'configurazione_portale' => 'CREATE TABLE configurazione_portale (id INT PRIMARY KEY, logo_path VARCHAR(255) NULL)',
            'log_attivita' => 'CREATE TABLE log_attivita (id INT AUTO_INCREMENT PRIMARY KEY, utente_id INT, azione VARCHAR(255), dettagli_json TEXT NULL, indirizzo_ip VARCHAR(45), data_ora DATETIME DEFAULT CURRENT_TIMESTAMP)',
            'scuole' => "CREATE TABLE scuole (codice VARCHAR(10) PRIMARY KEY, denominazione VARCHAR(255) DEFAULT '', istituto_codice VARCHAR(10) NULL, istituto_denominazione VARCHAR(255) NULL, comune VARCHAR(120) DEFAULT '')",
            'abilitazioni_ambito' => 'CREATE TABLE abilitazioni_ambito (utente_id INT)',
            'log_accessi' => 'CREATE TABLE log_accessi (id INT AUTO_INCREMENT PRIMARY KEY, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)',
            'log_email' => 'CREATE TABLE log_email (id INT AUTO_INCREMENT PRIMARY KEY, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        ini_set('error_log', '/dev/null');   // il test dell'annullamento fallito scrive su error_log, come il pannello
        $this->mailer = new MailerFinto();
        $this->repo = new IscrittiRepository($this->db);
        $this->op = new Operatore(1, 'admin@x.it', '10.0.0.1');
        $this->chiamate = [];
        $this->richiestaConvenzioneRiesce = true;
        $this->erroreProgetto = null;
        $this->db->esegui("INSERT INTO pagine_eventi (id, slug, titolo, colore_primario, gestori_utenti_ids) VALUES (1, 'openlab', 'OpenLab', '#112233', '7'), (2, 'fsl', 'FSL', '#445566', '')");
        $this->db->esegui("INSERT INTO eventi (id, pagina_id, titolo, luogo, tipo) VALUES (10, 1, 'Genetica', 'Aula 1', 'evento'), (11, 1, 'Stem Day', 'Aula 2', 'evento'), (20, 2, 'Geologia', 'Campo', 'progetto')");
        $this->db->esegui("INSERT INTO turni (id, evento_id, nome_turno, data_turno, orario_inizio, orario_fine, max_posti, abilita_lista_attesa) VALUES
            (100, 10, 'Turno A', '2999-10-23', '09:00:00', '12:00:00', 1, 1), (110, 11, 'Mattina', '2999-11-02', NULL, NULL, 100, 0), (200, 20, 'Edizione 1', NULL, NULL, NULL, 1, 1), (201, 20, 'Edizione 2', NULL, NULL, NULL, 1, 0)");
        $this->db->esegui("INSERT INTO utenti (id, nome, cognome, email, ruolo_id, ruoli_secondari, matricola_studente) VALUES (1, 'Ada', 'Admin', 'ada@x.it', 1, '', NULL), (5, 'Giulia', 'Verdi', 'giulia@x.it', 5, '', 'S245')");
        $this->db->esegui("INSERT INTO configurazione_portale (id, logo_path) VALUES (1, 'assets/logo.png')");
        $this->db->esegui("INSERT INTO progetti_dettagli (evento_id, per_scuole, attestati, convenzione, min_studenti, max_studenti) VALUES (20, 1, 1, 1, 8, 25)");
        $this->db->esegui("INSERT INTO scuole (codice, denominazione, comune) VALUES ('CSPS00001A', 'LICEO FERMI', 'COSENZA')");
        $this->db->esegui("INSERT INTO prenotazioni (id, turno_id, utente_id, codice_prenotazione, stato, presente, nome, cognome, email, matricola, data_prenotazione) VALUES
            (1, 110, 5, 'T1', 'confermata', 0, 'Anna', 'Rossi', 'anna@x.it', '', '2026-10-01 10:00:00'),
            (2, 110, NULL, 'T2', 'in_attesa', 0, 'Bruno', 'Bianchi', 'bruno@x.it', 'M1', '2026-10-01 10:05:00'),
            (3, 110, NULL, 'T3', 'da_approvare', 0, 'Carla', 'Verdi', 'carla@x.it', '', '2026-10-01 10:10:00'),
            (4, 110, NULL, 'T4', 'annullata', 0, 'Dario', 'Neri', '', '', '2026-10-01 10:15:00'),
            (5, 100, NULL, 'T5', 'confermata', 0, 'Ida', 'Rosa', 'ida@x.it', '', '2026-10-01 11:00:00'),
            (6, 100, NULL, 'T6', 'in_attesa', 0, 'Livio', 'Grigi', 'livio@x.it', '', '2026-10-01 11:05:00')");
    }

    private function servizioAbilitazioni(): ServizioAbilitazioni
    {
        $moduli = new class () implements ModuliAree {
            public function disponibile(): bool
            {
                return false;
            }

            public function moduloDiArea(array $pagina): string
            {
                return 'orientamento';
            }
        };

        return new ServizioAbilitazioni(new AbilitazioniRepository($this->db), new UtenteRepository($this->db), $moduli);
    }

    private function servizio(): ServizioIscritti
    {
        $db = $this->db;
        $t = $this;
        $iscrizioni = new class ($db, $t) implements Iscrizioni {
            public function __construct(private \App\Core\Database $db, private IscrittiIntegrazioneTest $t)
            {
            }

            public function postiOccupati(int $turnoId): int
            {
                return (int) $this->db->valore("SELECT COALESCE(SUM(num_posti), 0) FROM prenotazioni WHERE turno_id = ? AND IFNULL(stato, 'confermata') IN ('confermata', 'richiesta_conferma', 'da_approvare')", [$turnoId]);
            }

            public function decadiAtteseVincolate(int $prenotazioneId): int
            {
                $this->t->registra("decadi:$prenotazioneId");

                return 0;
            }

            public function promuoviListaAttesa(int $turnoId): void
            {
                $this->t->registra("promuovi:$turnoId");
            }

            public function validaPartecipantiProgetto(array $custom, ?array $dettagli, ?array $turno = null): ?string
            {
                return $this->t->erroreProgetto();
            }
        };
        $convenzioni = new class ($t) implements Convenzioni {
            public function __construct(private IscrittiIntegrazioneTest $t)
            {
            }

            public function periodoPrenotazione(array $prenotazione): array
            {
                return ['2027-01-01', '2027-02-01'];
            }

            public function convenzioneValida(?string $codiceScuola, bool $rileggi = false, ?string $dal = null, ?string $al = null): ?array
            {
                return $codiceScuola === 'CSPS00001A' ? ['id' => 1] : null;
            }

            public function richiedi(int $prenotazioneId, string $tipo = 'richiesta'): bool
            {
                $this->t->registra("richiedi:$prenotazioneId");

                return $this->t->richiestaConvenzioneRiesce();
            }

            public function segnaRicevuta(int $prenotazioneId, bool $email = true): bool
            {
                $this->t->registra("segna:$prenotazioneId");

                return true;
            }

            public function ricevutaDaGestore(int $prenotazioneId, string $autore = ''): bool
            {
                $this->t->registra("ricevuta:$prenotazioneId:$autore");

                return true;
            }

            public function verificaFsl(): array
            {
                return ['coperte' => 0, 'da_stipulare' => 0, 'nuove_da_stipulare' => 0, 'senza_codice' => 0];
            }

            public function invitoValutazione(int $prenotazioneId, bool $promemoria = false): bool
            {
                return true;
            }
        };
        $attestati = new class ($t) implements Attestati {
            public function __construct(private IscrittiIntegrazioneTest $t)
            {
            }

            public function inviaSeConcluso(int $prenotazioneId): bool
            {
                $this->t->registra("attestato:$prenotazioneId");

                return true;
            }

            public function inviaGruppo(int $prenotazioneId, bool $forza = false): bool|string
            {
                return true;
            }

            public function regolaEvento(int $eventoId): string
            {
                return 'evento';
            }
        };

        return new ServizioIscritti(
            $this->repo,
            $iscrizioni,
            $convenzioni,
            $attestati,
            $this->mailer,
            new ColoriAree($this->db),
            new Sito('/tmp', 'https://portale.test/eventi'),
            $this->servizioAbilitazioni(),
            new ServizioScuole(new ScuolaRepository($this->db)),
            new EventoRepository($this->db),
            new AuditLog($this->db)
        );
    }

    public function registra(string $chiamata): void
    {
        $this->chiamate[] = $chiamata;
    }

    public function erroreProgetto(): ?string
    {
        return $this->erroreProgetto;
    }

    public function richiestaConvenzioneRiesce(): bool
    {
        return $this->richiestaConvenzioneRiesce;
    }

    private function stato(int $id): string
    {
        return (string) $this->db->valore('SELECT stato FROM prenotazioni WHERE id = ?', [$id]);
    }

    // ------------------------------------------------------------------ repository

    public function testLettureDelleFunzioniDiDati(): void
    {
        $p = $this->repo->prenotazioneConTurnoEvento(1);
        self::assertSame('Stem Day', $p['evento_titolo']);
        self::assertSame('110', $p['turno_id']);   // stringhe, come con il vecchio $conn->query()
        self::assertNull($this->repo->prenotazioneConTurnoEvento(999));
        self::assertSame('openlab', $this->repo->turnoAdmin(110)['slug']);
        self::assertCount(1, $this->repo->destinatariEmailMassiva(1, 100));   // solo la confermata con email del turno 100
        self::assertCount(2, $this->repo->destinatariEmailMassiva(1));        // 1 e 5: la 4 è annullata e senza email
        $this->db->esegui("INSERT INTO campi_form (pagina_id, evento_id, nome_campo, etichetta) VALUES (1, NULL, 'a', 'Alfa'), (2, 20, 'b', 'Beta'), (1, 11, 'a', 'Alfa')");
        self::assertSame(['a' => 'Alfa'], $this->repo->campiCustomExport(1));
        self::assertSame(['b' => 'Beta'], $this->repo->campiCustomExport(2));
        $ck = $this->repo->prenotazionePerCheckinAdmin('T5');
        self::assertSame(5, $ck['id']);   // da prepared statement: tipi nativi, come prima
        self::assertNull($this->repo->prenotazionePerCheckinAdmin('nope'));
        self::assertSame('Verdi', $this->repo->cercaUtenti('S245')[0]['cognome']);
        self::assertSame('S245', $this->repo->cercaUtenti('verdi')[0]['matricola']);
    }

    public function testElencoFiltrato(): void
    {
        $f = static fn (array $a = []) => new FiltriIscritti(1, ...$a);
        self::assertSame(6, $this->repo->conta($f()));
        self::assertSame(4, $this->repo->conta($f(['turno' => 110])));
        self::assertSame(1, $this->repo->conta($f(['stato' => 'annullata'])));
        self::assertSame(1, $this->repo->conta($f(['cerca' => 'rossi'])));
        self::assertSame(6, $this->repo->conta($f(['cerca' => '0'])), 'la ricerca "0" non filtra, come prima');
        self::assertSame(0, $this->repo->conta($f(['cerca' => "' OR 1=1 -- "])));
        self::assertSame(2, $this->repo->conta($f(['dataDa' => '2999-10-01', 'dataFine' => '2999-10-31'])));
        self::assertSame(4, $this->repo->conta($f(['dataDa' => '2999-11-01'])));
        self::assertSame(0, $this->repo->conta($f(['archivio' => 1])));
        self::assertSame(0, $this->repo->conta($f(['rbac' => ' AND e.id = -1 '])));
        $righe = $this->repo->elenco($f(), 4, 0);
        self::assertCount(4, $righe);
        self::assertSame('T6', $righe[0]['codice_prenotazione']);   // dalla più recente
        self::assertSame('0', $righe[0]['n_studenti']);
        self::assertCount(2, $this->repo->elenco($f(), 4, 4));
        self::assertSame('S245', $this->repo->elenco($f(['cerca' => 'anna']), 10, 0)[0]['matricola_effettiva']);
        self::assertCount(6, $this->repo->perStampa($f()));
    }

    public function testEsportazioneConStudenti(): void
    {
        self::assertFalse($this->repo->areaConStudenti(1));
        $this->db->esegui("INSERT INTO partecipanti_prenotazione (prenotazione_id, cognome, nome, ordine) VALUES (5, 'Delta', 'Dora', 1), (5, 'Alfa', 'Aldo', 0)");
        self::assertTrue($this->repo->areaConStudenti(1));
        $righe = $this->repo->perEsportazione(1, 100, 'confermata');
        self::assertCount(1, $righe);
        self::assertSame('Alfa Aldo; Delta Dora', $righe[0]['studenti']);
        self::assertSame('2', $righe[0]['n_studenti']);
        self::assertCount(6, $this->repo->perEsportazione(1, 0, ''));
    }

    public function testAnnullaConErroreRiferisceLeRigheCambiate(): void
    {
        self::assertTrue($this->repo->annullaConErrore(1)['ok']);
        $secondo = $this->repo->annullaConErrore(1);   // già annullata: nessuna riga cambia
        self::assertFalse($secondo['ok']);
        self::assertSame(0, $secondo['errno']);
    }

    // ------------------------------------------------------------------ servizio degli iscritti

    public function testSegnaPresenzaInviaAttestatoELasciaTraccia(): void
    {
        $s = $this->servizio();
        $s->segnaPresenza(1, 1, $this->op);
        self::assertSame(['attestato:1'], $this->chiamate);
        self::assertSame(1, (int) $this->db->valore('SELECT presente FROM prenotazioni WHERE id = 1'));
        $s->segnaPresenza(1, 0, $this->op);
        self::assertSame(0, (int) $this->db->valore('SELECT presente FROM prenotazioni WHERE id = 1'));
        $log = $this->db->righe('SELECT utente_id, azione, dettagli_json, indirizzo_ip FROM log_attivita');
        self::assertCount(2, $log);
        self::assertSame('Modifica Presenza Check-in', $log[0]['azione']);
        self::assertSame('{"ID Prenotazione":1}', $log[0]['dettagli_json']);
        self::assertSame('10.0.0.1', $log[0]['indirizzo_ip']);
    }

    public function testAzioneDiMassaPresenteEAnnulla(): void
    {
        $s = $this->servizio();
        $r = $s->azioneDiMassa('presente', [1, 2, 5, 999], 1, '', $this->op);   // 2 in attesa e 999 inesistente: saltate
        self::assertSame([2, 2], [$r->fatte, $r->saltate]);
        self::assertSame('2 prenotazioni segnate presenti. 2 saltate (stato non compatibile con l\'azione o permessi mancanti).', $r->messaggio());
        self::assertSame('warning', $r->tipo());
        self::assertSame(['attestato:1', 'attestato:5'], $this->chiamate);

        $this->chiamate = [];
        $r = $s->azioneDiMassa('annulla', [5, 6, 4], 1, '', $this->op);
        self::assertSame([2, 1], [$r->fatte, $r->saltate]);
        self::assertSame(['annullata', 'annullata', 'annullata'], [$this->stato(5), $this->stato(6), $this->stato(4)]);
        // il posto liberato dalla confermata (non da chi era in attesa) si offre una sola volta alla lista d'attesa
        self::assertSame(['promuovi:100'], $this->chiamate);
        self::assertCount(2, $this->mailer->inviate);
        self::assertSame('Prenotazione Annullata: Genetica', $this->mailer->inviate[0]['oggetto']);
        self::assertSame('La tua prenotazione per <strong>Genetica</strong> è stata annullata dall\'amministrazione.', $this->mailer->inviate[0]['corpo']);
        self::assertSame('#112233', $this->mailer->inviate[0]['colore']);
    }

    public function testAzioneDiMassaPromuoviApprovaERispettaIPermessi(): void
    {
        $s = $this->servizio();
        $r = $s->azioneDiMassa('promuovi', [2, 6], 1, '', $this->op);   // 6 è sul turno da 1 posto già occupato: oltre capienza
        self::assertSame([2, 0, 1], [$r->fatte, $r->saltate, $r->oltreCapienza]);
        self::assertStringContainsString('Attenzione: 1 promozioni superano la capienza del turno.', $r->messaggio());
        self::assertSame(['decadi:2', 'decadi:6'], $this->chiamate);
        self::assertSame('Posto Confermato: Stem Day', $this->mailer->inviate[0]['oggetto']);
        self::assertStringContainsString('Si è liberato un posto: la tua prenotazione in lista d\'attesa per <strong>Stem Day</strong> (Mattina · 02/11/2999) è stata <strong>CONFERMATA</strong>.', $this->mailer->inviate[0]['corpo']);
        self::assertStringContainsString('https://portale.test/eventi/stampa_ricevuta.php?code=T2', $this->mailer->inviate[0]['corpo']);

        $r = $s->azioneDiMassa('approva', [3], 1, '', $this->op);
        self::assertSame('confermata', $this->stato(3));
        self::assertSame('Prenotazione Approvata: Stem Day', $this->mailer->inviate[2]['oggetto']);
        // un gestore che vede solo l'evento 10 non tocca le prenotazioni dell'evento 11
        $r = $s->azioneDiMassa('assente', [1, 5], 1, ' AND e.id = 10 ', $this->op);
        self::assertSame([1, 1], [$r->fatte, $r->saltate]);
    }

    public function testApprovaConConvenzioneDaRicevere(): void
    {
        $s = $this->servizio();
        $this->db->esegui("UPDATE prenotazioni SET convenzione = 'no' WHERE id = 3");
        // dopo la convenzione ricevuta (doppio: non cambia lo stato) resta da approvare e si conferma
        self::assertFalse($s->approva(3, 'http://h/eventi', $this->op));
        self::assertSame('confermata', $this->stato(3));
        self::assertSame(['ricevuta:3:admin@x.it', 'decadi:3'], $this->chiamate);
        self::assertStringContainsString('http://h/eventi/stampa_ricevuta.php?code=T3', $this->mailer->inviate[0]['corpo']);
        // già confermata dalla convenzione: nessuna approvazione ulteriore
        $this->db->esegui("UPDATE prenotazioni SET convenzione = 'no', stato = 'confermata' WHERE id = 3");
        self::assertTrue($s->approva(3, 'http://h/eventi', $this->op));
    }

    public function testRifiutaAnnullaEElimina(): void
    {
        $s = $this->servizio();
        $s->rifiuta(5);
        self::assertSame('rifiutata', $this->stato(5));
        self::assertSame(['promuovi:100'], $this->chiamate);
        self::assertSame('Aggiornamento Prenotazione: Genetica', $this->mailer->inviate[0]['oggetto']);

        self::assertSame('annullata', $s->annulla(1)['esito']);
        self::assertSame('errore', $s->annulla(1)['esito']);   // già annullata
        self::assertSame('non_trovata', $s->annulla(999)['esito']);

        $this->db->esegui("INSERT INTO messaggi_prenotazioni (prenotazione_id, mittente_tipo, messaggio) VALUES (3, 'utente', 'x')");
        $this->db->esegui('INSERT INTO partecipanti_prenotazione (prenotazione_id, cognome, nome) VALUES (3, \'A\', \'B\')');
        $s->elimina(3);
        self::assertSame(0, (int) $this->db->valore('SELECT COUNT(*) FROM prenotazioni WHERE id = 3'));
        self::assertSame(0, (int) $this->db->valore('SELECT COUNT(*) FROM messaggi_prenotazioni') + (int) $this->db->valore('SELECT COUNT(*) FROM partecipanti_prenotazione'));
        self::assertSame('Cancellazione Prenotazione', end($this->mailer->inviate)['oggetto']);
    }

    public function testConvenzioniRichiestaEATutti(): void
    {
        $s = $this->servizio();
        $this->db->esegui("INSERT INTO prenotazioni (id, turno_id, codice_prenotazione, stato, nome, cognome, email, scuola_codice, convenzione) VALUES
            (20, 200, 'F1', 'confermata', 'Doc', 'Uno', 'd1@s.it', 'CSPS00001A', 'no'), (21, 200, 'F2', 'in_attesa', 'Doc', 'Due', 'd2@s.it', NULL, NULL), (22, 201, 'F3', 'annullata', 'Doc', 'Tre', 'd3@s.it', NULL, 'no')");
        $r = $s->richiediConvenzioneATutti(new FiltriIscritti(2), $this->op);
        // 20: scuola con convenzione nel registro → segnata ricevuta; 21: il progetto la chiede → richiesta
        self::assertSame(['inviate' => 1, 'gia' => 1, 'fallite' => 0], $r);
        self::assertSame(['segna:20', 'richiedi:21'], $this->chiamate);
        self::assertSame('no', $this->db->valore('SELECT convenzione FROM prenotazioni WHERE id = 21'));
        self::assertSame('Richiesta convenzione agli iscritti', $this->db->valore('SELECT azione FROM log_attivita'));
        $this->richiestaConvenzioneRiesce = false;
        self::assertFalse($s->richiediConvenzione(21));
        $s->convenzioneRicevuta(21, $this->op);
        self::assertContains('ricevuta:21:admin@x.it', $this->chiamate);
    }

    public function testPrenotazioneManualeEvento(): void
    {
        $s = $this->servizio();
        $esito = $s->prenotazioneManuale(110, 'Mario', 'Rossi', 'mario@x.it', 'M7', 3, ['custom_dieta' => ' Vegana ', 'custom_vuoto' => '', 'custom_multi' => ['a', 'b'], 'altro' => 'x'], ['scuola' => 'CSPS00001A']);
        self::assertInstanceOf(EsitoAzione::class, $esito);
        self::assertSame('success', $esito->tipo);
        self::assertMatchesRegularExpression('/^Prenotazione manuale inserita\. Codice: OP-[0-9A-F]{8}$/', $esito->messaggio);
        $riga = $this->db->riga('SELECT * FROM prenotazioni ORDER BY id DESC LIMIT 1');
        self::assertSame('confermata', $riga['stato']);
        self::assertSame(3, $riga['num_posti']);
        self::assertSame('{"dieta":"Vegana","multi":"a, b"}', $riga['dati_custom_json']);
        self::assertSame(['decadi:' . $riga['id']], $this->chiamate);
        self::assertSame('Conferma Prenotazione: Stem Day', $this->mailer->inviate[0]['oggetto']);
        self::assertStringContainsString($riga['codice_prenotazione'], $this->mailer->inviate[0]['corpo']);
        self::assertNull($s->prenotazioneManuale(99999, 'X', 'Y', 'x@y.it', '', 1, [], []), 'turno inesistente: niente da fare');
    }

    public function testPrenotazioneManualeProgetto(): void
    {
        $s = $this->servizio();
        $this->erroreProgetto = 'Indica il numero di studenti partecipanti.';
        $e = $s->prenotazioneManuale(200, 'Doc', 'Uno', 'doc@s.it', '', 4, [], []);
        self::assertSame(['Prenotazione non inserita: Indica il numero di studenti partecipanti.', 'danger'], [$e->messaggio, $e->tipo]);
        $this->erroreProgetto = null;
        // edizione da 1 posto, lista d'attesa attiva: una richiesta da 1 posto (num_posti forzato a 1) entra in attesa solo se è pieno
        $this->db->esegui("INSERT INTO prenotazioni (turno_id, codice_prenotazione, stato, nome, cognome, email) VALUES (200, 'F0', 'confermata', 'A', 'B', 'a@b.it')");
        $e = $s->prenotazioneManuale(200, 'Doc', 'Due', 'doc2@s.it', '', 4, [], []);
        self::assertSame('warning', $e->tipo);
        self::assertStringStartsWith("Edizione al completo: prenotazione inserita in lista d'attesa. Codice: FS-", $e->messaggio);
        self::assertSame('in_attesa', $this->db->valore('SELECT stato FROM prenotazioni ORDER BY id DESC LIMIT 1'));
        self::assertSame("Lista d'attesa: Geologia", end($this->mailer->inviate)['oggetto']);
        // stessa email già iscritta a un'edizione dell'evento
        $e = $s->prenotazioneManuale(201, 'Doc', 'Due', 'DOC2@s.it', '', 1, [], []);
        self::assertSame("Prenotazione non inserita: questa email è già iscritta (o in lista d'attesa) a un'edizione del progetto", $e->messaggio);
        // edizione piena senza lista d'attesa
        $this->db->esegui("INSERT INTO prenotazioni (turno_id, codice_prenotazione, stato, nome, cognome, email) VALUES (201, 'F9', 'confermata', 'A', 'B', 'z@b.it')");
        $e = $s->prenotazioneManuale(201, 'Doc', 'Tre', 'doc3@s.it', '', 1, [], []);
        self::assertSame("Prenotazione non inserita: l'edizione è al completo.", $e->messaggio);
    }

    public function testModificaConservaIDatiNonPresentiNelModulo(): void
    {
        $s = $this->servizio();
        $this->db->esegui("UPDATE prenotazioni SET dati_custom_json = '{\"allegato\":\"uploads/a.pdf\",\"scuola\":\"Vecchia\"}', scuola_codice = 'OLD' WHERE id = 1");
        $s->modifica(1, 100, 'Anna', 'Rossi Bis', 'anna@y.it', 'Z', ['custom_nuovo' => 'si', 'custom_scuola' => 'Vecchia'], []);
        $r = $this->db->riga('SELECT * FROM prenotazioni WHERE id = 1');
        self::assertSame(100, $r['turno_id']);
        self::assertSame('Rossi Bis', $r['cognome']);
        self::assertSame('{"allegato":"uploads\/a.pdf","scuola":"Vecchia","nuovo":"si"}', $r['dati_custom_json']);
        self::assertSame('OLD', $r['scuola_codice'], 'il codice della scuola non cambia se nessuna scuola è stata scelta né modificata');
        $s->modifica(1, 100, 'Anna', 'Rossi', 'anna@y.it', 'Z', ['custom_scuola' => 'Nuova'], ['scuola' => '']);
        self::assertNull($this->db->valore('SELECT scuola_codice FROM prenotazioni WHERE id = 1'), 'testo della scuola cambiato a mano: il codice si azzera');
        $s->modifica(1, 100, 'Anna', 'Rossi', 'anna@y.it', 'Z', ['custom_scuola' => 'x'], ['scuola' => 'CSPS00001A']);
        self::assertSame('CSPS00001A', $this->db->valore('SELECT scuola_codice FROM prenotazioni WHERE id = 1'));
        self::assertSame('Liceo Fermi – Cosenza', json_decode((string) $this->db->valore('SELECT dati_custom_json FROM prenotazioni WHERE id = 1'), true)['scuola']);
    }

    // ------------------------------------------------------------------ messaggi

    private function servizioMessaggi(): ServizioMessaggi
    {
        $orologio = new class () implements Orologio {
            public function adesso(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-10-05 09:07:00');
            }
        };

        return new ServizioMessaggi(new MessaggiRepository($this->db), $this->repo, new AreeRepository($this->db), $this->mailer, new ColoriAree($this->db), new Sito('/tmp', 'https://portale.test/eventi'), $orologio);
    }

    public function testMessaggiDaIscritti(): void
    {
        $m = $this->servizioMessaggi();
        self::assertFalse($m->inviaDaIscritti(1, '  ', $this->op));
        self::assertFalse($m->inviaDaIscritti(1, '0', $this->op), 'come prima: "0" conta come vuoto');
        self::assertTrue($m->inviaDaIscritti(1, ' <p>Ciao</p> ', $this->op));
        $msg = $this->db->riga('SELECT * FROM messaggi_prenotazioni');
        self::assertSame(['admin', 1, '<p>Ciao</p>', 0], [$msg['mittente_tipo'], $msg['mittente_id'], $msg['messaggio'], $msg['letto']]);
        $mail = $this->mailer->inviate[0];
        self::assertSame('anna@x.it', $mail['a']);
        self::assertSame('Nuovo messaggio da Ada Admin – Stem Day [05/10 09:07]', $mail['oggetto']);
        self::assertNull($mail['colore']);
        // stessi byte del vecchio corpo: righe rientrate di 20 spazi
        self::assertStringStartsWith("\n" . str_repeat(' ', 20) . '<p>Hai ricevuto un nuovo messaggio da <strong>Ada Admin</strong>' . "\n" . str_repeat(' ', 20) . "riguardante l'evento <strong>Stem Day</strong>:</p>\n", $mail['corpo']);
        self::assertStringContainsString("\n" . str_repeat(' ', 24) . "<p>Ciao</p>\n" . str_repeat(' ', 20) . "</div>\n", $mail['corpo']);
        self::assertStringContainsString("<a href='https://portale.test/eventi/area_personale.php'", $mail['corpo']);
    }

    public function testRispostaDallInboxPulisceIlTestoESegnaLetti(): void
    {
        $this->db->esegui("INSERT INTO messaggi_prenotazioni (prenotazione_id, mittente_tipo, messaggio) VALUES (1, 'utente', 'Domanda'), (1, 'utente', 'Altra')");
        $m = $this->servizioMessaggi();
        self::assertSame(1, $m->nonLetti(1));   // conta le prenotazioni, non i messaggi
        self::assertTrue($m->rispondiDaInbox(1, '<p>Ok <b>si</b><script>x()</script> <a href="u">l</a></p>', $this->op));
        self::assertSame(0, $m->nonLetti(1));
        self::assertSame('<p>Ok <b>si</b>x() l</p>', $this->db->valore("SELECT messaggio FROM messaggi_prenotazioni WHERE mittente_tipo = 'admin'"));
        self::assertSame(1, (int) $this->db->valore("SELECT letto FROM messaggi_prenotazioni WHERE mittente_tipo = 'admin'"));
        self::assertSame('#112233', $this->mailer->inviate[0]['colore']);
        self::assertStringStartsWith("\n" . str_repeat(' ', 16) . '<p>Hai ricevuto', $this->mailer->inviate[0]['corpo']);
        $conv = $m->conversazioni(1, '');
        self::assertCount(1, $conv);
        self::assertSame('0', $conv[0]['messaggi_da_leggere']);
        self::assertSame('3', $conv[0]['totale_messaggi']);
        self::assertCount(3, $m->chat(1));
        self::assertCount(1, $m->perPrenotazioni([1, 2]));
        self::assertSame([], $m->perPrenotazioni([]));
        $m->segnaLetti(1);
    }

    // ------------------------------------------------------------------ scanner, badge, classi, form builder

    public function testScannerRegistraIngressiEErrori(): void
    {
        $attestati = new class () implements Attestati {
            public int $n = 0;

            public function inviaSeConcluso(int $prenotazioneId): bool
            {
                ++$this->n;

                return true;
            }

            public function inviaGruppo(int $prenotazioneId, bool $forza = false): bool|string
            {
                return true;
            }

            public function regolaEvento(int $eventoId): string
            {
                return 'evento';
            }
        };
        $s = new ServizioScanner(new ScannerRepository($this->db), $this->repo, $attestati, new AuditLog($this->db));
        $this->db->esegui("UPDATE prenotazioni SET codice_prenotazione = CONCAT('TK', codice_prenotazione)");
        $turni = $s->turni(1, '');
        self::assertSame([100, 110], array_keys($turni));
        $ok = $s->registra('checkin', 110, 1, $turni, ' tkt1 ', 0, false, false, $this->op);
        self::assertSame(['ok', 'Ingresso consentito', 'Anna Rossi'], [$ok['esito'], $ok['titolo'], $ok['nome']]);
        self::assertSame([1, 1], [$ok['presenti'], $ok['totale']]);
        self::assertSame(1, $attestati->n);
        self::assertSame('gia', $s->registra('checkin', 110, 1, $turni, 'TKT1', 0, false, false, $this->op)['esito']);
        self::assertSame('turno', $s->registra('checkin', 110, 1, $turni, 'TKT5', 0, false, false, $this->op)['esito']);
        $forzato = $s->registra('checkin', 110, 1, $turni, 'TKT5', 0, true, false, $this->op);
        self::assertSame('Registrato su un turno diverso da quello del biglietto.', $forzato['dettaglio']);
        $neg = $s->registra('checkin', 110, 1, $turni, 'TKT4', 0, false, false, $this->op);
        self::assertSame(['errore', 'Ingresso negato', 'Prenotazione annullata.'], [$neg['esito'], $neg['titolo'], $neg['dettaglio']]);
        self::assertSame('Biglietto non trovato', $s->registra('checkin', 110, 1, $turni, 'ZZZZ', 0, false, false, $this->op)['titolo']);
        self::assertSame('QR non riconosciuto', $s->registra('checkin', 110, 1, $turni, 'a b', 0, false, false, $this->op)['titolo']);
        self::assertSame('Azione non valida', $s->registra('boh', 110, 1, $turni, 'TKT1', 0, false, false, $this->op)['titolo']);
        self::assertSame('Biglietto di un altro evento', $s->registra('checkin', 110, 2, $turni, 'TKT1', 0, false, false, $this->op)['titolo']);
        $an = $s->registra('presenza', 110, 1, $turni, '', 1, false, false, $this->op);
        self::assertSame('Presenza annullata', $an['titolo']);
        self::assertNull($this->db->valore('SELECT data_presenza FROM prenotazioni WHERE id = 1'));
        self::assertSame('Check-in annullato', $this->db->valore('SELECT azione FROM log_attivita'));
        $stato = $s->stato(110);
        self::assertSame([0, 1, 1], [$stato['presenti'], $stato['totale'], count($stato['lista'])]);
        self::assertSame('Rossi Anna', $stato['lista'][0]['nome']);
    }

    public function testBadgeDiUnTurno(): void
    {
        $s = new ServizioBadge(new BadgeRepository($this->db), $this->servizioAbilitazioni());
        $r = $s->genera(110, 1, true, true, ['Ospite', ' ', '0'], ['Speciale', '', ''], ['vip']);
        self::assertSame('Stem Day', $r['info']['titolo']);
        self::assertSame('assets/logo.png', $r['info']['logo_path']);
        $ruoli = array_column($r['badge'], 'ruolo');
        self::assertSame(['PARTECIPANTE', 'STAFF / GESTORE', 'VIP'], array_values(array_unique($ruoli)));
        self::assertSame('Anna', $r['badge'][0]['nome']);
        self::assertMatchesRegularExpression('/^STAFF-[0-9a-f]{6}$/', $r['badge'][1]['qr']);
        self::assertMatchesRegularExpression('/^EXT-[0-9a-f]{6}$/', (string) end($r['badge'])['qr']);
        self::assertCount(1 + 1 + 1, $r['badge'], 'un iscritto confermato, l\'amministratore e il badge manuale con nome: "0" e vuoti non contano');
        self::assertSame(['info' => null, 'badge' => []], $s->genera(999, 1, true, true, [], [], []));
        $ev = $s->eventiConTurni(1, '');
        self::assertCount(2, $ev);
        self::assertSame('Mattina', $ev[0]['turni'][0]['nome_turno']);   // in ordine di id decrescente
    }

    public function testClassiEStudenti(): void
    {
        $s = new ServizioClassi(new ClassiRepository($this->db));
        $this->db->esegui("UPDATE progetti_dettagli SET attestati = 1 WHERE evento_id = 20");
        $this->db->esegui("INSERT INTO prenotazioni (id, turno_id, codice_prenotazione, stato, nome, cognome, email) VALUES (30, 200, 'C1', 'confermata', 'Doc', 'Uno', 'd@s.it')");
        self::assertSame(['30'], array_column($s->dellArea(2, ''), 'id'));
        $esistenti = [['cognome' => 'Alfa', 'nome' => 'Anna']];
        $this->db->esegui("INSERT INTO partecipanti_prenotazione (prenotazione_id, cognome, nome, ordine) VALUES (30, 'Alfa', 'Anna', 0)");
        $r = $s->aggiungi(30, [['cognome' => 'ALFA', 'nome' => 'anna'], ['cognome' => 'Beta', 'nome' => 'Bruno']], $esistenti);
        self::assertSame(['aggiunti' => 1, 'totale' => 2], $r, 'chi c\'è già (anche con maiuscole diverse) non si aggiunge');
        $id = (int) $this->db->valore("SELECT id FROM partecipanti_prenotazione WHERE cognome = 'Beta'");
        $s->escludi($id, 30, true);
        self::assertSame(1, (int) $this->db->valore('SELECT escluso FROM partecipanti_prenotazione WHERE id = ?', [$id]));
        $s->elimina($id, 999);   // di un'altra prenotazione: non si tocca
        self::assertSame(1, (int) $this->db->valore('SELECT COUNT(*) FROM partecipanti_prenotazione WHERE id = ?', [$id]));
        $s->segnaPresente(30);
        self::assertSame(1, (int) $this->db->valore('SELECT presente FROM prenotazioni WHERE id = 30'));
        $s->svuota(30);
        self::assertSame(0, (int) $this->db->valore('SELECT COUNT(*) FROM partecipanti_prenotazione'));
    }

    public function testFormBuilder(): void
    {
        $orologio = new class () implements Orologio {
            public function adesso(): DateTimeImmutable
            {
                return new DateTimeImmutable('@1800000000');
            }
        };
        $s = new ServizioFormBuilder(new FormBuilderRepository($this->db), $orologio);
        $s->aggiungi(1, 11, "  Dieta O'Brien \"x\" é\n2 ", 'select', ' A, B ', true, 40, 7, 'Vegana');
        $c = $this->db->riga('SELECT * FROM campi_form');
        self::assertSame('dieta_obrien_x_n2', $c['nome_campo'], 'stesso nome tecnico di prima (dal testo protetto per MySQL)');
        self::assertSame("Dieta O'Brien \"x\" é\n2", $c['etichetta']);
        self::assertSame([11, 'A, B', 1, 40], [$c['evento_id'], $c['opzioni_select'], $c['obbligatorio'], $c['ordine']]);
        self::assertSame('{"se_id":7,"se_val":"Vegana"}', $c['condizione_json']);
        $s->aggiungi(1, 0, '!!!', 'text', '', false, 1, 0, '');
        $senza = $this->db->riga('SELECT * FROM campi_form ORDER BY id DESC LIMIT 1');
        self::assertSame('campo_1800000000', $senza['nome_campo']);
        self::assertNull($senza['evento_id']);
        self::assertNull($senza['condizione_json']);

        $id = (int) $c['id'];
        $s->sposta($id, true);
        self::assertSame(25, (int) $this->db->valore('SELECT ordine FROM campi_form WHERE id = ?', [$id]));
        $s->sposta((int) $senza['id'], true);
        self::assertSame(0, (int) $this->db->valore('SELECT ordine FROM campi_form WHERE id = ?', [$senza['id']]), 'mai sotto zero');
        $s->modifica($id, 0, 'Nuova', 'radio', 'Si,No', false, 0, '');
        $m = $this->db->riga('SELECT * FROM campi_form WHERE id = ?', [$id]);
        self::assertSame(['Nuova', 'radio', 'Si,No', 0, null, null], [$m['etichetta'], $m['tipo_campo'], $m['opzioni_select'], $m['obbligatorio'], $m['evento_id'], $m['condizione_json']]);
        self::assertSame('dieta_obrien_x_n2', $m['nome_campo']);

        // permessi: gestore di singolo evento solo sui campi del suo evento
        self::assertTrue($s->campoAutorizzato($id, 1, true, []));
        self::assertFalse($s->campoAutorizzato($id, 2, true, []), 'campo di un\'altra area');
        self::assertFalse($s->campoAutorizzato($id, 1, false, [11]));   // evento nullo: non è del gestore
        $this->db->esegui('UPDATE campi_form SET evento_id = 11 WHERE id = ?', [$id]);
        self::assertTrue($s->campoAutorizzato($id, 1, false, [11]));
        self::assertTrue(ServizioFormBuilder::eventoAutorizzato(11, false, [11]));
        self::assertFalse(ServizioFormBuilder::eventoAutorizzato(0, false, [11]));
        $s->salvaOrdine([5 => $id, 6 => 999], 1, false, [11]);
        self::assertSame(5, (int) $this->db->valore('SELECT ordine FROM campi_form WHERE id = ?', [$id]));
        self::assertCount(2, $s->campi(1));
        self::assertCount(2, $s->eventi(1));
        $s->elimina($id);
        self::assertCount(1, $s->campi(1));
    }

    // ------------------------------------------------------------------ email massiva e cron

    public function testEmailMassivaAGruppiDiDieci(): void
    {
        for ($i = 0; $i < 12; ++$i) {
            $this->db->esegui("INSERT INTO prenotazioni (turno_id, codice_prenotazione, stato, nome, cognome, email) VALUES (110, 'M$i', 'confermata', 'N', 'C', 'm$i@x.it')");
        }
        $s = new ServizioEmailMassiva($this->repo, $this->mailer);
        $coda = $s->prepara(1, 110, 'Oggetto', '<p>Testo</p>');
        self::assertSame(13, $coda['totale']);   // le 12 e Anna (la 4 è annullata, la 2 e la 3 non sono confermate)
        $coda = $s->invia($coda);
        self::assertSame(10, $coda['inviate']);
        $coda = $s->invia($coda);
        self::assertSame(13, $coda['inviate']);
        self::assertCount(13, $this->mailer->inviate);
        self::assertNull($this->mailer->inviate[0]['colore']);
        self::assertSame(13, $s->invia($coda)['inviate'], 'a coda finita non invia altro');
    }

    public function testRepositoryDeiCron(): void
    {
        $cron = new CronRepository($this->db);
        // eventi con tutti i turni passati: archiviati (salvo quelli bloccati)
        $this->db->esegui("UPDATE turni SET data_turno = '2000-01-01' WHERE evento_id = 10");
        self::assertSame(1, $cron->archiviaScaduti());
        // promemoria: turno di domani
        $this->db->esegui("INSERT INTO turni (id, evento_id, nome_turno, data_turno, orario_inizio) VALUES (300, 11, 'Domani', CURDATE() + INTERVAL 1 DAY, '10:00:00')");
        $this->db->esegui("INSERT INTO prenotazioni (id, turno_id, codice_prenotazione, stato, nome, cognome, email) VALUES (40, 300, 'R1', 'confermata', 'Ugo', 'D', 'ugo@x.it'), (41, 300, 'R2', NULL, 'Una', 'D', 'una@x.it'), (42, 300, 'R3', 'annullata', 'No', 'D', 'no@x.it')");
        self::assertSame(['40', '41'], array_column($cron->daPromemoria(), 'id'));
        $cron->segnaPromemoriaInviato(40);
        self::assertSame(['41'], array_column($cron->daPromemoria(), 'id'));
        // dopo l'evento: presenti a turni finiti
        $this->db->esegui("INSERT INTO turni (id, evento_id, data_turno, orario_fine) VALUES (301, 11, '2000-01-01', '12:00:00')");
        $this->db->esegui("INSERT INTO prenotazioni (id, turno_id, codice_prenotazione, stato, presente, nome, email) VALUES (43, 301, 'P1', 'confermata', 1, 'Vera', 'v@x.it')");
        self::assertSame(['43'], array_column($cron->daEmailPostEvento('2026-10-05 10:00:00'), 'id'));
        $cron->segnaPostEventoInviata(43);
        self::assertSame([], $cron->daEmailPostEvento('2026-10-05 10:00:00'));
        // anonimizzazione: dati personali svuotati, codice e turno restano
        $this->db->esegui("UPDATE prenotazioni SET dati_custom_json = '{\"a\":\"b\"}' WHERE id = 43");
        $this->db->esegui("INSERT INTO messaggi_prenotazioni (prenotazione_id, mittente_tipo, messaggio) VALUES (43, 'utente', 'x')");
        self::assertSame(['5', '6', '43'], array_column($cron->daAnonimizzare(3), 'id'));   // 5 e 6: turno del 2000
        $cron->anonimizza(43);
        $r = $this->db->riga('SELECT * FROM prenotazioni WHERE id = 43');
        self::assertSame(['V.', '', '', null, 'P1'], [$r['nome'], $r['cognome'], $r['email'], $r['dati_custom_json'], $r['codice_prenotazione']]);
        self::assertSame(0, (int) $this->db->valore('SELECT COUNT(*) FROM messaggi_prenotazioni WHERE prenotazione_id = 43'));
        // elenchi di studenti delle iscrizioni annullate e account inattivi
        $this->db->esegui("INSERT INTO partecipanti_prenotazione (prenotazione_id, cognome, nome, codice) VALUES (4, 'X', 'Y', NULL), (4, 'Z', 'W', 'ATT1'), (1, 'K', 'J', NULL)");
        self::assertSame(1, $cron->cancellaElenchiAnnullati());
        self::assertSame([], $cron->utentiConPerimetro());
        $this->db->esegui("UPDATE utenti SET ultimo_accesso = '2000-01-01' WHERE id = 5");
        self::assertSame(0, $cron->eliminaUtentiInattivi(1, [5]));   // escluso perché gestore
        self::assertSame(1, $cron->eliminaUtentiInattivi(1, []));
        self::assertNull($this->db->valore('SELECT utente_id FROM prenotazioni WHERE id = 1'), 'la prenotazione resta, senza il legame con l\'account');
        $this->db->esegui("INSERT INTO log_accessi (created_at) VALUES ('2000-01-01'), (NOW())");
        self::assertSame(1, $cron->eliminaAccessiVecchi(12));
    }
}
