<?php

declare(strict_types=1);

namespace Tests\Integration\Iscrizioni;

use App\Auth\Abilitazioni\ModuliAree;
use App\Auth\Sessione;
use App\Core\Container;
use App\Core\Database;
use App\Core\Orologio;
use App\Core\Sito;
use App\Infrastructure\Mail\Mailer;
use App\Iscrizioni\CampiAnagrafe;
use App\Iscrizioni\Registrazione;
use App\Iscrizioni\RegistroOperazioni;
use App\Iscrizioni\RegoleFsl;
use Tests\Doppi\AuthSessioneInMemoria;
use Tests\Doppi\IscrizioniCampiAnagrafeFinti;
use Tests\Doppi\IscrizioniOrologioFisso;
use Tests\Doppi\IscrizioniRegistroFinto;
use Tests\Doppi\IscrizioniRegoleFslFinte;
use Tests\Doppi\MailerFinto;
use Tests\Integration\DatabaseDiProva;

/**
 * Base dei test di integrazione del modulo Iscrizioni: le tabelle (solo le colonne usate) e il contenitore con i doppi
 * (email, sessione, orologio, registro, regole FSL), così i servizi si creano come nel sito, con l'autowiring.
 */
abstract class IscrizioniBase extends DatabaseDiProva
{
    protected MailerFinto $mailer;
    protected AuthSessioneInMemoria $sessione;
    protected IscrizioniOrologioFisso $orologio;
    protected IscrizioniRegistroFinto $registro;
    protected IscrizioniRegoleFslFinte $fsl;
    protected Container $c;

    protected function tabelle(): array
    {
        return [
            'pagine_eventi' => "CREATE TABLE pagine_eventi (id INT PRIMARY KEY, slug VARCHAR(80) DEFAULT '', titolo VARCHAR(150) DEFAULT '', colore_primario VARCHAR(20) DEFAULT '#0056B3', visibile TINYINT DEFAULT 1,
                limite_iscrizioni VARCHAR(20) DEFAULT 'nessuno', gestore_utente_id INT DEFAULT 0, gestori_utenti_ids VARCHAR(200) DEFAULT '', permessi_gestori_json TEXT NULL, notifiche_gestori_ids VARCHAR(200) NULL,
                tipo_area VARCHAR(20) DEFAULT '', conv_url_modello VARCHAR(255) NULL, conv_url_allegato VARCHAR(255) NULL, conv_pec VARCHAR(150) NULL)",
            'eventi' => "CREATE TABLE eventi (id INT AUTO_INCREMENT PRIMARY KEY, pagina_id INT, titolo VARCHAR(150) DEFAULT '', luogo VARCHAR(150) NULL, tipo VARCHAR(20) DEFAULT 'evento', archiviato TINYINT DEFAULT 0,
                ruolo_accesso_id INT DEFAULT 0, richiede_prenotazione TINYINT DEFAULT 1, email_notifiche_extra VARCHAR(255) NULL, gestori_utenti_ids VARCHAR(200) DEFAULT '', permessi_gestori_json TEXT NULL,
                locandina_path VARCHAR(255) DEFAULT '', abilita_presenze TINYINT DEFAULT 1, sottocategoria_id INT NULL, ordine INT DEFAULT 0, is_evidenza TINYINT DEFAULT 0)",
            'turni' => "CREATE TABLE turni (id INT AUTO_INCREMENT PRIMARY KEY, evento_id INT, nome_turno VARCHAR(150) NULL, data_turno DATE NULL, orario_inizio TIME NULL, orario_fine TIME NULL, max_posti INT DEFAULT 30,
                data_apertura DATETIME NULL, data_chiusura DATETIME NULL, token_checkin VARCHAR(64) NULL, min_partecipanti INT NULL, max_partecipanti INT NULL, abilita_lista_attesa TINYINT DEFAULT 0,
                abilita_multi_posto TINYINT DEFAULT 0, richiede_approvazione TINYINT DEFAULT 0, annullabile_fino DATETIME NULL)",
            'prenotazioni' => "CREATE TABLE prenotazioni (id INT AUTO_INCREMENT PRIMARY KEY, turno_id INT, utente_id INT NULL, codice_prenotazione VARCHAR(40) DEFAULT '', stato VARCHAR(30) NULL DEFAULT 'confermata',
                presente INT DEFAULT 0, num_posti INT DEFAULT 1, nome VARCHAR(100) NULL, cognome VARCHAR(100) NULL, email VARCHAR(150) NULL, matricola VARCHAR(50) NULL, dati_custom_json TEXT NULL,
                data_prenotazione DATETIME DEFAULT CURRENT_TIMESTAMP, data_presenza DATETIME NULL, scadenza_conferma DATETIME NULL, scuola_codice VARCHAR(10) NULL, convenzione VARCHAR(10) NULL,
                token_sondaggio VARCHAR(64) NULL, sondaggio_completato TINYINT DEFAULT 0, attestato_inviato TINYINT DEFAULT 0)",
            'campi_form' => "CREATE TABLE campi_form (id INT AUTO_INCREMENT PRIMARY KEY, pagina_id INT NOT NULL, evento_id INT NULL, nome_campo VARCHAR(100) NOT NULL, etichetta VARCHAR(255) NOT NULL, tipo_campo VARCHAR(50) DEFAULT 'text',
                opzioni_select TEXT NULL, obbligatorio TINYINT DEFAULT 0, ordine INT DEFAULT 0, condizione_json TEXT NULL)",
            'progetti_dettagli' => "CREATE TABLE progetti_dettagli (evento_id INT PRIMARY KEY, data_inizio DATE NULL, data_fine DATE NULL, min_studenti INT NULL, max_studenti INT NULL, referenti_json TEXT NULL,
                info_extra_json TEXT NULL, moduli_json TEXT NULL, per_scuole TINYINT DEFAULT 1, attestati TINYINT DEFAULT 0, convenzione TINYINT DEFAULT 0, dedicata_scuole TINYINT DEFAULT 0)",
            'messaggi_prenotazioni' => "CREATE TABLE messaggi_prenotazioni (id INT AUTO_INCREMENT PRIMARY KEY, prenotazione_id INT, mittente_tipo VARCHAR(20), mittente_id INT NULL, messaggio TEXT, letto TINYINT DEFAULT 0,
                data_invio DATETIME DEFAULT CURRENT_TIMESTAMP)",
            'utenti' => "CREATE TABLE utenti (id INT PRIMARY KEY, nome VARCHAR(100) DEFAULT '', cognome VARCHAR(100) DEFAULT '', email VARCHAR(150) DEFAULT '', scuola_codice VARCHAR(10) NULL)",
            'impostazioni_sistema' => "CREATE TABLE impostazioni_sistema (id INT PRIMARY KEY, email_conferma_oggetto VARCHAR(255) NULL, email_conferma_corpo TEXT NULL, email_canc_utente_oggetto VARCHAR(255) NULL, email_canc_utente_corpo TEXT NULL)",
            'rate_limit_attempts' => 'CREATE TABLE rate_limit_attempts (id INT AUTO_INCREMENT PRIMARY KEY, ip_hash CHAR(64) NOT NULL, endpoint VARCHAR(100) NOT NULL, hit_at DATETIME DEFAULT CURRENT_TIMESTAMP)',
            'sondaggi' => 'CREATE TABLE sondaggi (id INT AUTO_INCREMENT PRIMARY KEY, evento_id INT, attivo TINYINT DEFAULT 1)',
            'configurazione_portale' => "CREATE TABLE configurazione_portale (id INT PRIMARY KEY, logo_path VARCHAR(255) NULL, nome_portale VARCHAR(150) NULL, sottotitolo_portale VARCHAR(150) NULL)",
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$conn?->query('SELECT RELEASE_ALL_LOCKS()');
        $this->mailer = new MailerFinto();
        $this->sessione = new AuthSessioneInMemoria();
        $this->orologio = new IscrizioniOrologioFisso();
        $this->registro = new IscrizioniRegistroFinto();
        $this->fsl = new IscrizioniRegoleFslFinte();

        $c = new Container();
        Registrazione::registra($c);   // interfacce del modulo; i doppi qui sotto hanno la precedenza
        $c->istanza(Container::class, $c);
        $c->istanza(Database::class, $this->db);
        $c->istanza(Mailer::class, $this->mailer);
        $c->istanza(Sessione::class, $this->sessione);
        $c->istanza(Orologio::class, $this->orologio);
        $c->istanza(RegistroOperazioni::class, $this->registro);
        $c->istanza(RegoleFsl::class, $this->fsl);
        $c->istanza(CampiAnagrafe::class, new IscrizioniCampiAnagrafeFinti());
        $c->istanza(Sito::class, new Sito(dirname(__DIR__, 3), 'http://prova.it/eventi'));
        $c->istanza(ModuliAree::class, new class () implements ModuliAree {
            public function disponibile(): bool
            {
                return false;
            }

            public function moduloDiArea(array $pagina): string
            {
                return 'orientamento';
            }
        });
        $this->c = $c;
        $this->db->esegui("INSERT INTO pagine_eventi (id, slug, titolo) VALUES (1, 'openlab', 'OpenLab')");
        $this->db->esegui("INSERT INTO impostazioni_sistema (id) VALUES (1)");
    }

    protected function tearDown(): void
    {
        self::$conn?->query('SELECT RELEASE_ALL_LOCKS()');
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

    /** Crea un evento con i suoi turni ($turni: righe parziali di turni) e ritorna gli id dei turni. @param list<array<string, mixed>> $turni @return list<int> */
    protected function evento(int $id, array $turni, array $extra = []): array
    {
        $campi = $extra + ['id' => $id, 'pagina_id' => 1, 'titolo' => "Evento $id", 'luogo' => 'Aula'];
        $this->db->inserisci('INSERT INTO eventi (' . implode(',', array_keys($campi)) . ') VALUES (' . implode(',', array_fill(0, count($campi), '?')) . ')', array_values($campi));
        $ids = [];
        foreach ($turni as $t) {
            $t += ['evento_id' => $id, 'data_turno' => '2999-03-01', 'orario_inizio' => '10:00:00', 'orario_fine' => '12:00:00', 'max_posti' => 5];
            $ids[] = $this->db->inserisci('INSERT INTO turni (' . implode(',', array_keys($t)) . ') VALUES (' . implode(',', array_fill(0, count($t), '?')) . ')', array_values($t));
        }

        return $ids;
    }

    /** Aggiunge una prenotazione e ritorna il suo id. @param array<string, mixed> $campi */
    protected function pren(int $turnoId, string $stato, array $campi = []): int
    {
        $n = (int) $this->db->valore('SELECT COUNT(*) FROM prenotazioni') + 1;
        $campi += ['turno_id' => $turnoId, 'stato' => $stato === 'NULL' ? null : $stato, 'codice_prenotazione' => "PR-$n", 'nome' => "Nome$n", 'cognome' => "Cognome$n", 'email' => "p$n@prova.it",
            'num_posti' => 1, 'data_prenotazione' => sprintf('2026-01-%02d 10:00:00', min($n, 28))];

        return $this->db->inserisci('INSERT INTO prenotazioni (' . implode(',', array_keys($campi)) . ') VALUES (' . implode(',', array_fill(0, count($campi), '?')) . ')', array_values($campi));
    }

    protected function stato(int $prenotazioneId): ?string
    {
        $s = $this->db->valore('SELECT stato FROM prenotazioni WHERE id = ?', [$prenotazioneId]);

        return $s === null ? null : (string) $s;
    }
}
