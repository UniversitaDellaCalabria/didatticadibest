<?php

declare(strict_types=1);

namespace Tests\Integration\Sistema;

use App\Auth\ServizioUtenti;
use App\Auth\UtenteRepository;
use App\Core\Sito;
use App\Sistema\Backup\StatoBackup;
use App\Sistema\ControlloSito;
use App\Sistema\ImpostazioniSistemaRepository;
use App\Sistema\LogEmailRepository;
use App\Sistema\RegistroAuditRepository;
use App\Sistema\ReportEmailSettimanale;
use Tests\Doppi\MailerFinto;
use Tests\Integration\DatabaseDiProva;

final class SistemaIntegrazioneTest extends DatabaseDiProva
{
    private string $radice;
    private MailerFinto $mailer;
    private Sito $sito;
    private StatoBackup $backup;
    private LogEmailRepository $logEmail;
    private ServizioUtenti $utenti;

    protected function tabelle(): array
    {
        return [
            'utenti' => "CREATE TABLE utenti (id INT AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(80) DEFAULT '', cognome VARCHAR(80) DEFAULT '', codice_fiscale VARCHAR(16) NULL, email VARCHAR(150) NULL,
                ruolo_id INT DEFAULT 5, ruoli_secondari VARCHAR(100) DEFAULT '')",
            'log_email' => "CREATE TABLE log_email (id INT AUTO_INCREMENT PRIMARY KEY, destinatario VARCHAR(150), oggetto VARCHAR(200) DEFAULT '', esito TINYINT, errore VARCHAR(200) DEFAULT '',
                canale VARCHAR(20) DEFAULT 'smtp', created_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
            'log_attivita' => 'CREATE TABLE log_attivita (id INT AUTO_INCREMENT PRIMARY KEY, utente_id INT, azione VARCHAR(100), dettagli_json TEXT NULL, indirizzo_ip VARCHAR(45), data_ora DATETIME DEFAULT CURRENT_TIMESTAMP)',
            'impostazioni_sistema' => "CREATE TABLE impostazioni_sistema (id INT PRIMARY KEY, smtp_host VARCHAR(100), smtp_port INT, smtp_username VARCHAR(100), smtp_password VARCHAR(100),
                smtp_secure VARCHAR(10), smtp_from_email VARCHAR(100), smtp_from_name VARCHAR(100), email_conferma_oggetto TEXT, email_conferma_corpo TEXT, email_canc_utente_oggetto TEXT,
                email_canc_utente_corpo TEXT, email_canc_admin_oggetto TEXT, email_canc_admin_corpo TEXT, email_reminder_oggetto TEXT, email_reminder_corpo TEXT,
                email_attestato_oggetto TEXT, email_attestato_corpo TEXT, email_sondaggio_oggetto TEXT, email_sondaggio_corpo TEXT)",
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->radice = sys_get_temp_dir() . '/sito_prova_' . uniqid();
        mkdir($this->radice . '/cache', 0755, true);
        mkdir($this->radice . '/uploads');
        $this->sito = new Sito($this->radice, 'https://portale.test/didattica');
        $this->backup = new StatoBackup($this->sito);
        $this->logEmail = new LogEmailRepository($this->db);
        $this->utenti = new ServizioUtenti(new UtenteRepository($this->db));
        $this->mailer = new MailerFinto();
        $this->db->esegui("INSERT INTO utenti (id, nome, cognome, codice_fiscale, email, ruolo_id) VALUES (1, 'Ada', 'Admin', 'CF1', 'ada@unical.it', 1)");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->radice . '/cache/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->radice . '/cache');
        @rmdir($this->radice . '/uploads');
        @rmdir($this->radice);
    }

    private function controllo(): ControlloSito
    {
        return new ControlloSito($this->db, $this->sito, $this->backup, $this->logEmail, $this->utenti, $this->mailer, true);
    }

    public function testControlloSitoSegnalaIlBackupMancanteESalvaLoStato(): void
    {
        $voci = array_column($this->controllo()->esiti(), 'voce');
        self::assertContains('Database raggiungibile', $voci);
        self::assertContains('Cartella cache/ scrivibile', $voci);
        self::assertContains('Backup: nessun backup registrato', $voci);
        self::assertContains("Controlli delle pagine saltati nell'ambiente locale", $voci);
        $stato = $this->controllo()->esegui(false);
        self::assertSame(0, $stato['problemi']);
        self::assertFileExists($this->radice . '/cache/controllo_sito.json');
        self::assertSame([], $this->mailer->inviate);
    }

    public function testAvvisoAgliAmministratoriUnaVoltaPerGliStessiProblemi(): void
    {
        rmdir($this->radice . '/uploads');
        $stato = $this->controllo()->esegui();
        self::assertSame(1, $stato['problemi']);
        self::assertCount(1, $this->mailer->inviate);
        self::assertSame('ada@unical.it', $this->mailer->inviate[0]['a']);
        self::assertSame('Portale Didattica DiBEST: 1 problemi rilevati', $this->mailer->inviate[0]['oggetto']);
        $this->controllo()->esegui();
        self::assertCount(1, $this->mailer->inviate, 'stessi problemi: niente secondo avviso nello stesso giorno');
        mkdir($this->radice . '/uploads');
        $this->controllo()->esegui();
        self::assertCount(2, $this->mailer->inviate);
        self::assertSame('Portale Didattica DiBEST: tutto di nuovo a posto', $this->mailer->inviate[1]['oggetto']);
    }

    public function testStatoBackupSiScriveESiLegge(): void
    {
        self::assertSame([], $this->backup->leggi());
        $this->backup->scrivi(['data' => '2026-01-01 03:00:00', 'ok' => true]);
        self::assertSame('2026-01-01 03:00:00', $this->backup->leggi()['data']);
    }

    public function testReportSettimanale(): void
    {
        $this->db->esegui("INSERT INTO log_email (destinatario, esito, errore, canale) VALUES ('a@x.it', 1, '', 'smtp'), ('b@x.it', 0, 'Destinatario rifiutato', 'smtp'), ('c@x.it', 1, '', 'mail()')");
        $report = new ReportEmailSettimanale($this->sito, $this->backup, $this->logEmail, $this->utenti, $this->mailer);
        self::assertTrue($report->invia());
        self::assertCount(1, $this->mailer->inviate);
        self::assertStringContainsString('2 inviate, 1 fallite', $this->mailer->inviate[0]['oggetto']);
        self::assertStringStartsWith('⚠️ ', $this->mailer->inviate[0]['oggetto'], 'senza backup registrato è un avviso');
        self::assertStringContainsString('Destinatario rifiutato', $this->mailer->inviate[0]['corpo']);
        self::assertSame('già inviato questa settimana', $report->invia());
        self::assertTrue($report->invia(true));
    }

    public function testReportSenzaAmministratori(): void
    {
        $this->db->esegui('UPDATE utenti SET ruolo_id = 5');
        $report = new ReportEmailSettimanale($this->sito, $this->backup, $this->logEmail, $this->utenti, $this->mailer);
        self::assertSame('nessun amministratore con email', $report->invia());
    }

    public function testImpostazioniERegistroAudit(): void
    {
        $this->db->esegui("INSERT INTO impostazioni_sistema (id, smtp_host) VALUES (1, 'vecchio')");
        $modelli = array_fill_keys(ImpostazioniSistemaRepository::CAMPI_TESTO, "Ciao 'a' \"b\"");
        (new ImpostazioniSistemaRepository($this->db))->salva('smtp.test', 465, 'u', 'p', 'ssl', 'da@x.it', 'Portale', $modelli);
        $r = $this->db->riga('SELECT * FROM impostazioni_sistema WHERE id = 1');
        self::assertSame(['smtp.test', '465', 'ssl', "Ciao 'a' \"b\""], [$r['smtp_host'], (string) $r['smtp_port'], $r['smtp_secure'], $r['email_sondaggio_corpo']]);
        $this->db->esegui("INSERT INTO log_attivita (utente_id, azione, indirizzo_ip) VALUES (1, 'Prova', '::1')");
        $ultime = (new RegistroAuditRepository($this->db))->ultime(10);
        self::assertSame(['Prova', 'Ada', 'CF1'], [$ultime[0]['azione'], $ultime[0]['nome'], $ultime[0]['codice_fiscale']]);
    }
}
