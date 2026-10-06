<?php

declare(strict_types=1);

namespace Tests\Integration\Didattica;

use App\Anagrafi\ClientApiAteneo;
use App\Auth\Abilitazioni\ModuliAree;
use App\Auth\Sessione;
use App\Core\Container;
use App\Core\Database;
use App\Core\IndirizzoClient;
use App\Core\Orologio;
use App\Core\Sito;
use App\Didattica\AllegatiPratiche;
use App\Didattica\ConsiglioRepository;
use App\Didattica\Registrazione;
use App\Didattica\SedutaRepository;
use App\Didattica\VerbaleFirmato;
use App\Infrastructure\Mail\Mailer;
use App\Infrastructure\Pdf\VerificaFirme;
use App\Infrastructure\Storage\Upload;
use App\Sistema\FileEnv;
use App\Tutorato\VerificaPdfFirmato;
use Tests\Doppi\AnagrafiClientApiFinto;
use Tests\Doppi\AuthSessioneInMemoria;
use Tests\Doppi\ClientFinto;
use Tests\Doppi\DidatticaModuliAree;
use Tests\Doppi\FirmeFinte;
use Tests\Doppi\MailerFinto;
use Tests\Doppi\OrologioFisso;
use Tests\Integration\DatabaseDiProva;

/**
 * Base dei test di integrazione del modulo Didattica: le tabelle (stesse colonne dello schema), il contenitore con i doppi (email, orologio
 * fisso al 6 ottobre 2026 alle 12:00, IP, sessione, consigli e sedute) e una cartella temporanea per gli allegati delle pratiche.
 */
abstract class DidatticaBase extends DatabaseDiProva
{
    protected MailerFinto $mailer;
    protected AuthSessioneInMemoria $sessione;
    protected Container $c;
    protected string $cartella;
    protected string $cartellaVerbali;
    protected string $envFile;

    protected function tabelle(): array
    {
        return [
            'didattica_uffici' => <<<'SQL'
                CREATE TABLE `didattica_uffici` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `nome` varchar(150) NOT NULL DEFAULT '',
                  `descrizione` varchar(500) DEFAULT '',
                  `chiave` varchar(30) DEFAULT NULL,
                  `smista` tinyint(1) NOT NULL DEFAULT 0,
                  `segue_corsi` tinyint(1) NOT NULL DEFAULT 0,
                  `ordine` int(11) NOT NULL DEFAULT 0,
                  `creato_il` datetime DEFAULT current_timestamp(),
                  `tipo` varchar(20) NOT NULL DEFAULT '',
                  `consiglio_id` int(11) DEFAULT NULL,
                  PRIMARY KEY (`id`)
                )
                SQL,
            'ufficio_didattica' => <<<'SQL'
                CREATE TABLE `ufficio_didattica` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `persona_id` varchar(80) DEFAULT NULL,
                  `email` varchar(150) NOT NULL DEFAULT '',
                  `nominativo` varchar(200) NOT NULL DEFAULT '',
                  `ruolo` varchar(100) DEFAULT '',
                  `compiti` varchar(100) NOT NULL DEFAULT '',
                  `creato_il` datetime DEFAULT current_timestamp(),
                  `ufficio_id` int(11) DEFAULT NULL,
                  `corsi` text DEFAULT NULL,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `uq_email` (`email`)
                )
                SQL,
            'didattica_moduli' => <<<'SQL'
                CREATE TABLE `didattica_moduli` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `titolo` varchar(200) NOT NULL DEFAULT '',
                  `descrizione` text DEFAULT NULL,
                  `categoria` varchar(100) NOT NULL DEFAULT '',
                  `tipo` varchar(10) NOT NULL DEFAULT 'documento',
                  `file_path` varchar(255) DEFAULT NULL,
                  `link` varchar(500) DEFAULT NULL,
                  `campi_json` text DEFAULT NULL,
                  `destinatari` varchar(20) NOT NULL DEFAULT 'tutti',
                  `email_ufficio` varchar(500) DEFAULT '',
                  `attivo` tinyint(1) NOT NULL DEFAULT 1,
                  `ordine` int(11) NOT NULL DEFAULT 0,
                  `creato_il` datetime DEFAULT current_timestamp(),
                  `aggiornato_il` datetime DEFAULT NULL,
                  `verbale_json` text DEFAULT NULL,
                  `iter_json` text DEFAULT NULL,
                  `aperto_dal` date DEFAULT NULL,
                  `aperto_al` date DEFAULT NULL,
                  `giorni_promemoria` smallint(6) NOT NULL DEFAULT 7,
                  `chiave` varchar(40) DEFAULT NULL,
                  `domanda_json` text DEFAULT NULL,
                  PRIMARY KEY (`id`)
                )
                SQL,
            'pratiche' => <<<'SQL'
                CREATE TABLE `pratiche` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `modulo_id` int(11) NOT NULL,
                  `utente_id` int(11) DEFAULT NULL,
                  `codice` varchar(20) NOT NULL,
                  `nome` varchar(100) DEFAULT '',
                  `cognome` varchar(100) DEFAULT '',
                  `email` varchar(255) DEFAULT '',
                  `matricola` varchar(50) DEFAULT '',
                  `risposte_json` mediumtext DEFAULT NULL,
                  `stato` varchar(20) NOT NULL DEFAULT 'inviata',
                  `creata_il` datetime DEFAULT current_timestamp(),
                  `aggiornata_il` datetime DEFAULT NULL,
                  `seduta_id` int(11) DEFAULT NULL,
                  `delibera` text DEFAULT NULL,
                  `ufficio_json` mediumtext DEFAULT NULL,
                  `assegnata_a` int(11) DEFAULT NULL,
                  `passo` tinyint(4) NOT NULL DEFAULT 0,
                  `richiesta_json` text DEFAULT NULL,
                  `protocollo` varchar(100) NOT NULL DEFAULT '',
                  `protocollo_data` date DEFAULT NULL,
                  `promemoria_il` datetime DEFAULT NULL,
                  `decisioni_json` mediumtext DEFAULT NULL,
                  `esito_seduta` varchar(20) NOT NULL DEFAULT '',
                  `ufficio_id` int(11) DEFAULT NULL,
                  `cdl_id` int(11) DEFAULT NULL,
                  `segreteria_id` int(11) DEFAULT NULL,
                  `inviata_segreteria_il` datetime DEFAULT NULL,
                  `domanda_pdf` varchar(255) DEFAULT NULL,
                  `domanda_sha` char(64) DEFAULT NULL,
                  `ip_invio` varchar(45) DEFAULT '',
                  `accesso_json` text DEFAULT NULL,
                  `bollo_il` datetime DEFAULT NULL,
                  `bollo_da` varchar(200) DEFAULT '',
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `uq_codice` (`codice`)
                )
                SQL,
            'pratiche_eventi' => <<<'SQL'
                CREATE TABLE `pratiche_eventi` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `pratica_id` int(11) NOT NULL,
                  `tipo` varchar(10) NOT NULL DEFAULT 'messaggio',
                  `autore` varchar(10) NOT NULL DEFAULT 'studente',
                  `utente_id` int(11) DEFAULT NULL,
                  `stato` varchar(20) DEFAULT NULL,
                  `testo` text DEFAULT NULL,
                  `allegato` varchar(255) DEFAULT NULL,
                  `nome_allegato` varchar(255) DEFAULT NULL,
                  `creato_il` datetime DEFAULT current_timestamp(),
                  `interno` tinyint(1) NOT NULL DEFAULT 0,
                  `autore_nome` varchar(200) DEFAULT '',
                  PRIMARY KEY (`id`)
                )
                SQL,
            'pratiche_operatori' => <<<'SQL'
                CREATE TABLE `pratiche_operatori` (
                  `pratica_id` int(11) NOT NULL,
                  `operatore_id` int(11) NOT NULL,
                  `passo` tinyint(4) NOT NULL DEFAULT 0,
                  `dal` datetime DEFAULT current_timestamp(),
                  PRIMARY KEY (`pratica_id`,`operatore_id`)
                )
                SQL,
            'utenti' => "CREATE TABLE utenti (id INT PRIMARY KEY, nome VARCHAR(100) DEFAULT '', cognome VARCHAR(100) DEFAULT '', email VARCHAR(150) DEFAULT '', ruolo_id INT DEFAULT 5, ruoli_secondari VARCHAR(100) DEFAULT '', persona_id VARCHAR(80) DEFAULT NULL)",
            'personale_ateneo' => "CREATE TABLE personale_ateneo (id VARCHAR(80) PRIMARY KEY, nome VARCHAR(100) DEFAULT '', cognome VARCHAR(100) DEFAULT '', email VARCHAR(150) DEFAULT '', ruolo VARCHAR(100) DEFAULT '')",
            'didattica_consigli' => <<<'SQL'
                CREATE TABLE `didattica_consigli` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `nome` varchar(500) NOT NULL DEFAULT '',
                  `corsi` text DEFAULT NULL,
                  `coordinatore` varchar(200) DEFAULT '',
                  `segretario` varchar(200) DEFAULT '',
                  `luogo` varchar(255) DEFAULT '',
                  `odg` text DEFAULT NULL,
                  `attivo` tinyint(1) NOT NULL DEFAULT 1,
                  `ordine` int(11) NOT NULL DEFAULT 0,
                  `creato_il` datetime DEFAULT current_timestamp(),
                  PRIMARY KEY (`id`)
                )
                SQL,
            'didattica_consigli_persone' => <<<'SQL'
                CREATE TABLE `didattica_consigli_persone` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `consiglio_id` int(11) NOT NULL,
                  `ruolo` varchar(12) NOT NULL DEFAULT 'componente',
                  `persona_id` varchar(80) DEFAULT NULL,
                  `email` varchar(150) DEFAULT '',
                  `nominativo` varchar(200) NOT NULL DEFAULT '',
                  `qualifica` varchar(150) DEFAULT '',
                  `ordine` int(11) NOT NULL DEFAULT 0,
                  `creato_il` datetime DEFAULT current_timestamp(),
                  PRIMARY KEY (`id`)
                )
                SQL,
            'didattica_sedute' => <<<'SQL'
                CREATE TABLE `didattica_sedute` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `organo` varchar(500) NOT NULL DEFAULT '',
                  `anno_accademico` varchar(20) DEFAULT '',
                  `data` date DEFAULT NULL,
                  `ora_inizio` varchar(5) DEFAULT '',
                  `ora_fine` varchar(5) DEFAULT '',
                  `luogo` varchar(255) DEFAULT '',
                  `odg` text DEFAULT NULL,
                  `presenze` text DEFAULT NULL,
                  `segretario` varchar(200) DEFAULT '',
                  `coordinatore` varchar(200) DEFAULT '',
                  `creata_il` datetime DEFAULT current_timestamp(),
                  `consiglio_id` int(11) DEFAULT NULL,
                  `convocazione_oggetto` varchar(255) DEFAULT '',
                  `convocazione_testo` text DEFAULT NULL,
                  `convocazione_il` datetime DEFAULT NULL,
                  `segretario_email` varchar(150) DEFAULT '',
                  `coordinatore_email` varchar(150) DEFAULT '',
                  `verbale_pdf` varchar(255) DEFAULT NULL,
                  `verbale_stato` varchar(20) NOT NULL DEFAULT '',
                  `verbale_token` varchar(40) DEFAULT NULL,
                  `verbale_inviato_il` datetime DEFAULT NULL,
                  `verbale_firmato_il` datetime DEFAULT NULL,
                  `verbale_sollecito_il` datetime DEFAULT NULL,
                  `verbale_solleciti` tinyint(4) NOT NULL DEFAULT 0,
                  PRIMARY KEY (`id`)
                )
                SQL,
            'didattica_sedute_presenze' => <<<'SQL'
                CREATE TABLE `didattica_sedute_presenze` (
                  `seduta_id` int(11) NOT NULL,
                  `componente_id` int(11) NOT NULL,
                  `nominativo` varchar(200) NOT NULL DEFAULT '',
                  `qualifica` varchar(150) DEFAULT '',
                  `ordine` int(11) NOT NULL DEFAULT 0,
                  `stato` varchar(2) NOT NULL DEFAULT 'P',
                  PRIMARY KEY (`seduta_id`,`componente_id`)
                )
                SQL,
            'didattica_convocazioni' => <<<'SQL'
                CREATE TABLE `didattica_convocazioni` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `seduta_id` int(11) NOT NULL,
                  `componente_id` int(11) NOT NULL,
                  `email` varchar(150) DEFAULT '',
                  `token` varchar(40) NOT NULL,
                  `inviata_il` datetime DEFAULT NULL,
                  `giustificata_il` datetime DEFAULT NULL,
                  `motivo` varchar(500) DEFAULT '',
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `uq_token` (`token`),
                  UNIQUE KEY `uq_seduta_comp` (`seduta_id`,`componente_id`)
                )
                SQL,
            'pagine_eventi' => "CREATE TABLE pagine_eventi (id INT PRIMARY KEY, titolo VARCHAR(255) DEFAULT '', slug VARCHAR(150) DEFAULT '', tipo_area VARCHAR(20) DEFAULT '')",
            'risorse' => "CREATE TABLE risorse (id INT AUTO_INCREMENT PRIMARY KEY, pagina_id INT, nome VARCHAR(150) DEFAULT '', tipo VARCHAR(20) DEFAULT '', luogo VARCHAR(255) DEFAULT '', referente VARCHAR(150) DEFAULT '', email_notifiche VARCHAR(255) DEFAULT '', durata_slot INT DEFAULT 15, max_slot INT DEFAULT 1, anticipo_ore INT DEFAULT 12, max_giorni INT DEFAULT 30, accesso VARCHAR(20) DEFAULT 'tutti', approvazione TINYINT DEFAULT 0, chiede_motivo TINYINT DEFAULT 0, attiva TINYINT DEFAULT 1, ufficio VARCHAR(20) DEFAULT '', persona_id VARCHAR(80) DEFAULT NULL)",
            'abilitazioni_ambito' => 'CREATE TABLE abilitazioni_ambito (utente_id INT NOT NULL, tipo VARCHAR(40) NOT NULL, pagina_id INT NOT NULL DEFAULT 0)',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailer = new MailerFinto();
        $this->sessione = new AuthSessioneInMemoria();
        $this->cartella = 'cache/prove_didattica_' . bin2hex(random_bytes(4)) . '/';
        $radice = dirname(__DIR__, 3);
        $c = new Container();
        Registrazione::registra($c);
        $c->istanza(Container::class, $c);
        $c->istanza(Database::class, $this->db);
        $c->istanza(Mailer::class, $this->mailer);
        $c->istanza(Orologio::class, new OrologioFisso('2026-10-06 12:00:00'));
        $c->istanza(Sito::class, new Sito($radice, 'https://portale.test/eventi'));
        $c->istanza(IndirizzoClient::class, new ClientFinto());
        $c->istanza(Sessione::class, $this->sessione);
        $c->istanza(VerificaFirme::class, new FirmeFinte());
        $this->envFile = tempnam(sys_get_temp_dir(), 'env') ?: '';
        file_put_contents($this->envFile, "FIRME_GIORNI_SOLLECITO=5\n");
        $c->istanza(FileEnv::class, new FileEnv($this->envFile));
        $c->istanza(ModuliAree::class, new DidatticaModuliAree());
        $c->istanza(ClientApiAteneo::class, new AnagrafiClientApiFinto());
        $c->istanza(AllegatiPratiche::class, new AllegatiPratiche(new Upload(), $c->get(Sito::class), $this->cartella));
        $this->cartellaVerbali = 'cache/prove_verbali_' . bin2hex(random_bytes(4)) . '/';
        $c->istanza(VerbaleFirmato::class, new VerbaleFirmato($this->db, $c->get(SedutaRepository::class), $c->get(ConsiglioRepository::class), $this->mailer, $c->get(Sito::class), $c->get(FileEnv::class), $c->get(VerificaPdfFirmato::class), $this->cartellaVerbali));
        $this->c = $c;
        $this->db->esegui("INSERT INTO utenti (id, nome, cognome, email, ruolo_id) VALUES (1, 'Ada', 'Admin', 'admin@x.it', 1), (2, 'Luca', 'Rossi', 'luca@x.it', 5), (3, 'Maria', 'Verdi', 'maria.verdi@x.it', 5), (4, 'Paolo', 'Bianchi', 'paolo@x.it', 5)");
        $this->db->esegui("INSERT INTO personale_ateneo (id, nome, cognome, email, ruolo) VALUES ('m.verdi', 'Maria', 'Verdi', 'MARIA.VERDI@x.it', 'Professore Ordinario'), ('p.bianchi', 'Paolo', 'Bianchi', 'paolo@x.it', 'Professore Associato'), ('s.senzamail', 'Sara', 'Neri', 'boh', '')");
    }

    protected function tearDown(): void
    {
        foreach ([$this->cartella, $this->cartellaVerbali] as $cartella) {
            $dir = dirname(__DIR__, 3) . '/' . $cartella;
            foreach (glob($dir . '{,.}*', GLOB_BRACE) ?: [] as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
            @rmdir($dir);
        }
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

    /** Un ufficio dell'Ufficio didattico. */
    protected function ufficio(string $nome, string $tipo = '', int $smista = 0, ?string $chiave = null): int
    {
        return $this->db->inserisci('INSERT INTO didattica_uffici (nome, tipo, smista, chiave, segue_corsi) VALUES (?, ?, ?, ?, 1)', [$nome, $tipo, $smista, $chiave]);
    }

    /** Un operatore (email e compiti), eventualmente in un ufficio e con i corsi che segue. */
    protected function operatore(string $nome, string $email, string $compiti = 'pratiche', ?int $ufficio = null, ?string $corsiJson = null): int
    {
        return $this->db->inserisci('INSERT INTO ufficio_didattica (nominativo, email, compiti, ufficio_id, corsi) VALUES (?, ?, ?, ?, ?)', [$nome, $email, $compiti, $ufficio, $corsiJson]);
    }

    /** Un modulo online. */
    protected function modulo(string $titolo, array $campi = [], ?array $iter = null, string $destinatari = 'tutti', array $altro = []): int
    {
        return $this->db->inserisci(
            'INSERT INTO didattica_moduli (titolo, tipo, categoria, campi_json, iter_json, destinatari, email_ufficio, aperto_dal, aperto_al, giorni_promemoria, verbale_json, domanda_json) VALUES (?, \'online\', \'Prova\', ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$titolo, $campi ? json_encode($campi, JSON_UNESCAPED_UNICODE) : null, $iter ? json_encode($iter) : null, $destinatari, $altro['email'] ?? '', $altro['dal'] ?? null, $altro['al'] ?? null, $altro['giorni'] ?? 7, $altro['verbale'] ?? null, $altro['domanda'] ?? null]
        );
    }
}
