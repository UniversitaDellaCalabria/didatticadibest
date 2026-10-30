<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use App\Auth\ControlloEmailPersonalizzate;
use App\Auth\UtenteRepository;
use App\Auth\VerificaEmail;
use App\Email\InvioEmail;
use App\Email\ModelliEmail;
use Tests\Doppi\MailerFinto;
use Tests\Doppi\OrologioFisso;
use Tests\Integration\DatabaseDiProva;

/** Cambio dell'email del profilo con il codice di 6 cifre e controllo delle email scelte prima della verifica. */
final class VerificaEmailTest extends DatabaseDiProva
{
    private MailerFinto $mailer;

    protected function tabelle(): array
    {
        return [
            'utenti' => "CREATE TABLE utenti (id INT AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(80) DEFAULT '', cognome VARCHAR(80) DEFAULT '', email VARCHAR(150) NULL,
                ruolo_id INT DEFAULT 5, ruoli_secondari VARCHAR(100) DEFAULT '', email_personalizzata TINYINT DEFAULT 0, email_verificata_il DATETIME NULL, persona_id VARCHAR(80) NULL)",
            'verifiche_email' => 'CREATE TABLE verifiche_email (utente_id INT NOT NULL PRIMARY KEY, email VARCHAR(150) NOT NULL, codice_hash CHAR(64) NOT NULL, scade_il DATETIME NOT NULL,
                tentativi TINYINT NOT NULL DEFAULT 0, invii TINYINT NOT NULL DEFAULT 1, creata_il DATETIME NOT NULL)',
            'tutorato_incarichi' => "CREATE TABLE tutorato_incarichi (id INT AUTO_INCREMENT PRIMARY KEY, docente_email VARCHAR(150) DEFAULT '', direttore_email VARCHAR(150) DEFAULT '')",
            'pratiche_conferme' => "CREATE TABLE pratiche_conferme (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(150) DEFAULT '')",
            'personale_ateneo' => "CREATE TABLE personale_ateneo (id VARCHAR(80) PRIMARY KEY, email VARCHAR(150) DEFAULT '')",
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailer = new MailerFinto();
        $this->db->esegui("INSERT INTO utenti (id, nome, cognome, email) VALUES (1, 'Stefi', 'Studente', 'stefi@studenti.unical.it'), (2, 'Dora', 'Docente', 'dora@unical.it'), (3, 'Zeno', 'Senza', NULL)");
    }

    private function servizio(string $quando = '2026-10-30 10:00:00'): VerificaEmail
    {
        return new VerificaEmail($this->db, new UtenteRepository($this->db), new InvioEmail($this->mailer, new ModelliEmail($this->db)), new OrologioFisso($quando));
    }

    private function codiceInviato(): string
    {
        $ultima = end($this->mailer->inviate);
        self::assertNotFalse($ultima);
        self::assertSame(1, preg_match('/>(\d{6})</', $ultima['corpo'], $m), 'il codice è nel testo dell\'email');

        return $m[1];
    }

    public function testLEmailCambiaSoloConIlCodiceGiusto(): void
    {
        $s = $this->servizio();
        self::assertNull($s->richiedi(3, 'Zeno@Gmail.com'));
        self::assertSame('zeno@gmail.com', $this->mailer->inviate[0]['a'], 'il codice va al nuovo indirizzo, in minuscolo');
        self::assertNull($this->db->valore('SELECT email FROM utenti WHERE id = 3'), 'prima del codice l\'email non cambia');
        self::assertSame('zeno@gmail.com', $s->inCorso(3)['email'] ?? null);
        $codice = $this->codiceInviato();
        self::assertStringNotContainsString($codice, (string) $this->db->valore('SELECT codice_hash FROM verifiche_email WHERE utente_id = 3'), 'il codice non si salva in chiaro');

        [$err, $email] = $s->conferma(3, substr($codice, 0, 3) . ' ' . substr($codice, 3));
        self::assertNull($err);
        self::assertSame('zeno@gmail.com', $email);
        $u = $this->db->riga('SELECT email, email_personalizzata, email_verificata_il FROM utenti WHERE id = 3');
        self::assertSame(['zeno@gmail.com', 1, '2026-10-30 10:00:00'], [$u['email'], (int) $u['email_personalizzata'], $u['email_verificata_il']]);
        self::assertNull($s->inCorso(3), 'a verifica fatta non resta nulla in corso');
        self::assertCount(1, $this->mailer->inviate, 'senza vecchia email nessun avviso di cambio');
    }

    public function testAvvisaIlVecchioIndirizzo(): void
    {
        $s = $this->servizio();
        $s->richiedi(1, 'stefi@gmail.com');
        $s->conferma(1, $this->codiceInviato());
        self::assertSame('stefi@studenti.unical.it', $this->mailer->inviate[1]['a']);
        self::assertStringContainsString('stefi@gmail.com', $this->mailer->inviate[1]['corpo']);
    }

    public function testCodiceSbagliatoTentativiFinitiEScadenza(): void
    {
        $s = $this->servizio();
        $s->richiedi(1, 'dora@unical.it');
        self::assertSame('Questa email è già associata a un altro account.', $s->richiedi(1, 'dora@unical.it'), 'non si chiede l\'email di un altro account');
        $s->richiedi(1, 'altra@gmail.com');
        $giusto = $this->codiceInviato();
        $sbagliato = $giusto === '000000' ? '111111' : '000000';
        for ($i = 1; $i < VerificaEmail::TENTATIVI; $i++) {
            [$err] = $s->conferma(1, $sbagliato);
            self::assertStringStartsWith('Codice sbagliato:', (string) $err);
        }
        [$err] = $s->conferma(1, $sbagliato);
        self::assertSame('Codice sbagliato per troppe volte: chiedi un nuovo codice.', $err);
        [$err] = $s->conferma(1, $giusto);
        self::assertSame('Non c\'è una verifica in corso: chiedi un nuovo codice.', $err, 'dopo i tentativi finiti nemmeno il codice giusto vale');
        self::assertSame('stefi@studenti.unical.it', $this->db->valore('SELECT email FROM utenti WHERE id = 1'));

        $this->servizio('2026-10-30 11:00:00')->richiedi(1, 'altra@gmail.com');
        $codice = $this->codiceInviato();
        [$err] = $this->servizio('2026-10-30 11:31:00')->conferma(1, $codice);
        self::assertSame('Il codice è scaduto: chiedine uno nuovo.', $err);
    }

    public function testLimitiSugliInvii(): void
    {
        $s = $this->servizio();
        self::assertSame('Inserisci un indirizzo email valido.', $s->richiedi(1, 'non-una-email'));
        self::assertSame('Questa è già la tua email.', $s->richiedi(1, 'Stefi@Studenti.unical.it'));
        self::assertNull($s->richiedi(1, 'a@gmail.com'));
        self::assertStringStartsWith('Ti abbiamo appena mandato un codice', (string) $this->servizio('2026-10-30 10:00:30')->richiedi(1, 'a@gmail.com'));
        for ($i = 2; $i <= VerificaEmail::INVII_AL_GIORNO; $i++) {
            self::assertNull($this->servizio(sprintf('2026-10-30 %02d:00:00', 10 + $i))->richiedi(1, 'a@gmail.com'));
        }
        self::assertStringStartsWith('Hai chiesto troppi codici oggi', (string) $this->servizio('2026-10-30 23:00:00')->richiedi(1, 'a@gmail.com'));
    }

    public function testIlCodiceValeSoloPerChiLHaChiesto(): void
    {
        $s = $this->servizio();
        $s->richiedi(1, 'uno@gmail.com');
        $codice = $this->codiceInviato();
        [$err] = $s->conferma(3, $codice);
        self::assertNotNull($err, 'un altro utente non può usare il codice');
        self::assertNull($this->db->valore('SELECT email FROM utenti WHERE id = 3'));
    }

    public function testControlloDelleEmailSceltePrimaDellaVerifica(): void
    {
        $this->db->esegui("INSERT INTO utenti (id, nome, cognome, email, email_personalizzata, email_verificata_il, persona_id) VALUES
            (10, 'Furbo', 'Uno', 'prof@unical.it', 1, NULL, NULL), (11, 'Onesto', 'Due', 'mio@gmail.com', 1, NULL, NULL),
            (12, 'Verificato', 'Tre', 'prof2@unical.it', 1, '2026-10-30 09:00:00', NULL), (13, 'Prof', 'Quattro', 'p4@unical.it', 1, NULL, 'P4')");
        $this->db->esegui("INSERT INTO tutorato_incarichi (docente_email) VALUES ('Prof@unical.it'), ('prof2@unical.it')");
        $this->db->esegui("INSERT INTO personale_ateneo (id, email) VALUES ('P4', 'p4@unical.it')");
        $c = new ControlloEmailPersonalizzate($this->db);
        $sospette = $c->elenco();
        self::assertSame([10], array_column($sospette, 'id'), 'solo chi ha l\'email di un docente senza averla verificata (non la propria scheda di anagrafe)');
        self::assertSame(['docente o Direttore di un incarico di tutorato'], $sospette[0]['dove']);
        self::assertSame([11, 13, 10], array_column($c->elenco(false), 'id'), 'con --tutte anche le altre non verificate, in ordine di cognome');
        self::assertSame(1, $c->azzera([10, 12]), 'si azzera solo chi non ha verificato');
        self::assertSame([null, 0], array_values(array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, $this->db->riga('SELECT email, email_personalizzata FROM utenti WHERE id = 10'))));
        self::assertSame('prof2@unical.it', $this->db->valore('SELECT email FROM utenti WHERE id = 12'));
    }
}
