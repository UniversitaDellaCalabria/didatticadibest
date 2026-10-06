<?php

declare(strict_types=1);

namespace Tests\Integration\Tutorato;

use App\Anagrafi\ClientApiAteneo;
use App\Core\Container;
use App\Core\Database;
use App\Core\IndirizzoClient;
use App\Core\Orologio;
use App\Core\Sito;
use App\Infrastructure\Mail\Mailer;
use App\Infrastructure\Pdf\VerificaFirme;
use App\Infrastructure\Storage\Upload;
use App\Sistema\FileEnv;
use App\Tutorato\ArchivioIncarichi;
use App\Tutorato\IncaricoRepository;
use App\Tutorato\OperatoriUfficio;
use App\Tutorato\Registrazione;
use Tests\Doppi\AnagrafiClientApiFinto;
use Tests\Doppi\ClientFinto;
use Tests\Doppi\FirmeFinte;
use Tests\Doppi\MailerFinto;
use Tests\Doppi\OperatoriFinti;
use Tests\Doppi\OrologioFisso;
use Tests\Integration\DatabaseDiProva;

/**
 * Base dei test di integrazione del modulo Tutorato: le tabelle (stesse colonne dello schema), il contenitore con i doppi (email, orologio
 * fisso al 5 ottobre 2026 alle 12:00, firme, IP, operatori, .env) e una cartella temporanea per i PDF delle lettere.
 */
abstract class TutoratoBase extends DatabaseDiProva
{
    protected MailerFinto $mailer;
    protected OperatoriFinti $operatori;
    protected ClientFinto $client;
    protected Container $c;
    protected string $cartella;
    protected string $envFile;

    protected function tabelle(): array
    {
        return [
            'tutorato_bandi' => <<<'SQL'
                CREATE TABLE `tutorato_bandi` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `titolo` varchar(255) NOT NULL DEFAULT '',
                `anno_accademico` varchar(20) DEFAULT '',
                `decreto_bando` varchar(100) DEFAULT '',
                `decreto_bando_data` date DEFAULT NULL,
                `decreto_commissione` varchar(100) DEFAULT '',
                `decreto_commissione_data` date DEFAULT NULL,
                `direttore_persona_id` varchar(80) DEFAULT NULL,
                `direttore_nome` varchar(200) DEFAULT '',
                `direttore_email` varchar(150) DEFAULT '',
                `direttore_cf` varchar(16) DEFAULT '',
                `operatore_id` int(11) DEFAULT NULL,
                `luogo` varchar(100) DEFAULT 'Rende',
                `creato_da` int(11) DEFAULT NULL,
                `creato_il` datetime DEFAULT current_timestamp(),
                `aggiornato_il` datetime DEFAULT NULL,
                PRIMARY KEY (`id`)
                )
                SQL,
            'tutorato_incarichi' => <<<'SQL'
                CREATE TABLE `tutorato_incarichi` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `bando_id` int(11) NOT NULL,
                `codice` varchar(20) NOT NULL,
                `stato` varchar(20) NOT NULL DEFAULT 'bozza',
                `genere` char(1) NOT NULL DEFAULT 'M',
                `cognome` varchar(100) NOT NULL DEFAULT '',
                `nome` varchar(100) NOT NULL DEFAULT '',
                `luogo_nascita` varchar(150) DEFAULT '',
                `data_nascita` date DEFAULT NULL,
                `comune_residenza` varchar(150) DEFAULT '',
                `indirizzo` varchar(255) DEFAULT '',
                `civico` varchar(20) DEFAULT '',
                `codice_fiscale` varchar(16) NOT NULL DEFAULT '',
                `email` varchar(255) NOT NULL DEFAULT '',
                `telefono` varchar(40) DEFAULT '',
                `attivita` text DEFAULT NULL,
                `ore` decimal(6,1) DEFAULT NULL,
                `periodo` varchar(255) DEFAULT '',
                `compenso` decimal(10,2) DEFAULT NULL,
                `docente_persona_id` varchar(80) DEFAULT NULL,
                `docente_nome` varchar(100) DEFAULT '',
                `docente_cognome` varchar(100) DEFAULT '',
                `docente_email` varchar(150) DEFAULT '',
                `docente_cf` varchar(16) DEFAULT '',
                `token_studente` varchar(40) DEFAULT NULL,
                `token_docente` varchar(40) DEFAULT NULL,
                `token_direttore` varchar(40) DEFAULT NULL,
                `studente_firma_json` text DEFAULT NULL,
                `file_pdf` varchar(255) DEFAULT NULL,
                `data_lettera` date DEFAULT NULL,
                `protocollo` varchar(100) NOT NULL DEFAULT '',
                `protocollo_data` date DEFAULT NULL,
                `nota_studente` text DEFAULT NULL,
                `inviata_il` datetime DEFAULT NULL,
                `confermata_il` datetime DEFAULT NULL,
                `firmata_docente_il` datetime DEFAULT NULL,
                `firmata_direttore_il` datetime DEFAULT NULL,
                `protocollata_il` datetime DEFAULT NULL,
                `creata_il` datetime DEFAULT current_timestamp(),
                `aggiornata_il` datetime DEFAULT NULL,
                `insegnamento_docente` varchar(255) DEFAULT '',
                `corso_laurea` varchar(255) DEFAULT '',
                `data_inizio` date DEFAULT NULL,
                `data_fine` date DEFAULT NULL,
                `fine_stato` varchar(20) NOT NULL DEFAULT '',
                `fine_pdf` varchar(255) DEFAULT NULL,
                `token_fine` varchar(40) DEFAULT NULL,
                `fine_richiesta_il` datetime DEFAULT NULL,
                `fine_firmata_il` datetime DEFAULT NULL,
                `fine_protocollo` varchar(100) NOT NULL DEFAULT '',
                `ore_approvate` decimal(6,1) DEFAULT NULL,
                `sollecito_il` datetime DEFAULT NULL,
                `solleciti` tinyint(4) NOT NULL DEFAULT 0,
                `promemoria_tutor_il` datetime DEFAULT NULL,
                `promemoria_docente_il` datetime DEFAULT NULL,
                `anonimizzata` tinyint(1) NOT NULL DEFAULT 0,
                `docente_titolo` varchar(10) NOT NULL DEFAULT 'Prof.',
                PRIMARY KEY (`id`)
                )
                SQL,
            'tutorato_eventi' => <<<'SQL'
                CREATE TABLE `tutorato_eventi` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `incarico_id` int(11) NOT NULL,
                `tipo` varchar(20) NOT NULL DEFAULT '',
                `testo` text DEFAULT NULL,
                `autore` varchar(200) DEFAULT '',
                `ip` varchar(45) DEFAULT '',
                `creato_il` datetime DEFAULT current_timestamp(),
                PRIMARY KEY (`id`)
                )
                SQL,
            'tutorato_registro' => <<<'SQL'
                CREATE TABLE `tutorato_registro` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `incarico_id` int(11) NOT NULL,
                `data` date NOT NULL,
                `ore` decimal(5,1) NOT NULL DEFAULT 0.0,
                `attivita` text DEFAULT NULL,
                `stato` varchar(12) NOT NULL DEFAULT 'inviata',
                `nota_docente` varchar(500) DEFAULT '',
                `creata_il` datetime DEFAULT current_timestamp(),
                `decisa_il` datetime DEFAULT NULL,
                PRIMARY KEY (`id`)
                )
                SQL,
            'utenti' => "CREATE TABLE utenti (id INT PRIMARY KEY, email VARCHAR(150) DEFAULT '', ruolo_id INT DEFAULT 5, ruoli_secondari VARCHAR(100) DEFAULT '')",
            'personale_ateneo' => "CREATE TABLE personale_ateneo (id VARCHAR(80) PRIMARY KEY, nome VARCHAR(100) DEFAULT '', cognome VARCHAR(100) DEFAULT '', email VARCHAR(150) DEFAULT '')",
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailer = new MailerFinto();
        $this->operatori = new OperatoriFinti();
        $this->client = new ClientFinto();
        $this->cartella = 'cache/prove_tutorato_' . bin2hex(random_bytes(4)) . '/';
        $this->envFile = tempnam(sys_get_temp_dir(), 'env') ?: '';
        file_put_contents($this->envFile, "INCARICHI_SOLO_SPID_CIE=1\n");
        $radice = dirname(__DIR__, 3);
        $c = new Container();
        Registrazione::registra($c);
        $c->istanza(Container::class, $c);
        $c->istanza(Database::class, $this->db);
        $c->istanza(Mailer::class, $this->mailer);
        $c->istanza(Orologio::class, new OrologioFisso('2026-10-05 12:00:00'));
        $c->istanza(Sito::class, new Sito($radice, 'https://portale.test/eventi'));
        $c->istanza(IndirizzoClient::class, $this->client);
        $c->istanza(VerificaFirme::class, new FirmeFinte());
        $c->istanza(OperatoriUfficio::class, $this->operatori);
        $c->istanza(ClientApiAteneo::class, new AnagrafiClientApiFinto());
        $c->istanza(FileEnv::class, new FileEnv($this->envFile));
        $c->istanza(ArchivioIncarichi::class, new ArchivioIncarichi($c->get(IncaricoRepository::class), new Upload(), $c->get(Sito::class), $this->cartella));
        $this->c = $c;
        $this->db->esegui("INSERT INTO utenti (id, email, ruolo_id) VALUES (1, 'admin@x.it', 1), (2, 'altro@x.it', 5)");
        $this->db->esegui("INSERT INTO personale_ateneo (id, nome, cognome, email) VALUES ('m.verdi', 'Maria', 'Verdi', 'MARIA.VERDI@x.it'), ('d.neri', 'Dario', 'Neri', 'non-una-email')");
    }

    protected function tearDown(): void
    {
        $dir = dirname(__DIR__, 3) . '/' . $this->cartella;
        foreach (glob($dir . '{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($dir);
        @unlink($this->envFile);
    }

    /**
     * @template T of object
     * @param class-string<T> $classe
     * @return T
     */
    protected function servizio(string $classe): object
    {
        return $this->c->get($classe);
    }

    /** Un bando di prova (id 1, direttore dall'indirizzo scritto, operatore facoltativo). */
    protected function bando(?int $operatoreId = null): int
    {
        return $this->db->inserisci(
            "INSERT INTO tutorato_bandi (id, titolo, anno_accademico, decreto_bando, decreto_bando_data, decreto_commissione, decreto_commissione_data, direttore_nome, direttore_email, direttore_cf, operatore_id, luogo)
             VALUES (1, 'Tutorato I semestre', '2026/2027', '123/2026', '2026-09-01', '150/2026', '2026-09-20', 'Mario Bianchi', 'direttore@x.it', 'BNCMRA60A01D086X', ?, 'Rende')",
            [$operatoreId]
        );
    }

    /**
     * I campi del modulo di una lettera valida.
     *
     * @return array<string, mixed>
     */
    protected function campi(array $v = []): array
    {
        return $v + [
            'bando_id' => 1, 'cognome' => 'Rossi', 'nome' => 'Luca', 'genere' => 'M', 'luogo_nascita' => 'Cosenza', 'data_nascita' => '2000-01-31', 'comune_residenza' => 'Rende', 'indirizzo' => 'Via Roma', 'civico' => '12',
            'codice_fiscale' => 'rsslcu00a31d086x', 'email' => 'LUCA@x.it', 'telefono' => '333', 'attivita' => "Tutorato di Chimica\nseconda riga", 'ore' => '10', 'periodo' => 'dal 01/11 al 28/02', 'compenso' => '1.200,50',
            'docente_persona_id' => 'm.verdi', 'data_lettera' => '2026-10-03',
        ];
    }

    /** @return array<string, string|null> */
    protected function lettera(int $id): array
    {
        return $this->c->get(IncaricoRepository::class)->perId($id) ?? [];
    }

    /** Aggiunge il «%%FIRMA» che i test considerano una firma PAdES. */
    protected function firmato(string $pdf): string
    {
        return $pdf . "\n%%FIRMA\n";
    }
}
