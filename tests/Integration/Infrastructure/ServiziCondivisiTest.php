<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure;

use App\Infrastructure\Audit\AuditLog;
use App\Infrastructure\Mail\ImpaginatoreEmail;
use App\Infrastructure\Mail\SmtpMailer;
use Tests\Integration\DatabaseDiProva;

final class ServiziCondivisiTest extends DatabaseDiProva
{
    protected function tabelle(): array
    {
        return [
            'log_email' => 'CREATE TABLE log_email (id INT AUTO_INCREMENT PRIMARY KEY, destinatario VARCHAR(255), oggetto VARCHAR(255), esito TINYINT(1), canale VARCHAR(10), errore VARCHAR(500))',
            'log_attivita' => 'CREATE TABLE log_attivita (id INT AUTO_INCREMENT PRIMARY KEY, utente_id INT, azione VARCHAR(255), dettagli_json TEXT, indirizzo_ip VARCHAR(45))',
            'impostazioni_sistema' => 'CREATE TABLE impostazioni_sistema (id INT PRIMARY KEY, smtp_host VARCHAR(100))',
        ];
    }

    private function impaginatore(): ImpaginatoreEmail
    {
        return new class () implements ImpaginatoreEmail {
            public function impagina(string $corpo, string $titolo, ?string $colore = null): string
            {
                return "[$titolo] $corpo";
            }
        };
    }

    public function testInAmbienteLocaleLEmailSiSalvaESiRegistra(): void
    {
        $dir = sys_get_temp_dir() . '/email_' . bin2hex(random_bytes(4)) . '/';
        $mailer = new SmtpMailer($this->db, $this->impaginatore(), $dir);

        self::assertTrue($mailer->invia('anna@unical.it', 'Prova', '<p>Ciao</p>'));
        $file = glob($dir . '*.html');
        self::assertCount(1, $file);
        self::assertStringContainsString('[Didattica DiBEST (locale)] <p>Ciao</p>', (string) file_get_contents($file[0]));
        self::assertSame(['destinatario' => 'anna@unical.it', 'esito' => 1, 'canale' => 'locale'], $this->db->riga('SELECT destinatario, esito, canale FROM log_email'));
        array_map('unlink', $file);
        rmdir($dir);
    }

    public function testIndirizzoNonValidoEImpostazioniMancanti(): void
    {
        $mailer = new SmtpMailer($this->db, $this->impaginatore());

        self::assertFalse($mailer->invia('non-una-email', 'x', 'y'));
        self::assertSame('Indirizzo destinatario non valido', $mailer->ultimoErrore());
        self::assertFalse($mailer->invia('anna@unical.it', 'x', 'y'));
        self::assertSame('Impostazioni di sistema mancanti', $mailer->ultimoErrore());
        self::assertSame(0, (int) $this->db->valore('SELECT esito FROM log_email'));
    }

    public function testRegistroDelleOperazioni(): void
    {
        $log = new AuditLog($this->db);

        self::assertFalse($log->registra(0, 'senza utente', [], '1.2.3.4'));
        self::assertTrue($log->registra(7, 'Pratica: assegnata', ['Pratica' => 3], '1.2.3.4'));
        self::assertSame(['utente_id' => 7, 'dettagli_json' => '{"Pratica":3}'], $this->db->riga('SELECT utente_id, dettagli_json FROM log_attivita'));
    }
}
