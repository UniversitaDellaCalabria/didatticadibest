<?php

declare(strict_types=1);

namespace Tests\Integration\Fsl;

use App\Core\Container;
use App\Core\Database;
use App\Core\Orologio;
use App\Core\Sito;
use App\Infrastructure\Mail\Mailer;
use App\Infrastructure\Pdf\VerificaFirme;
use App\Iscrizioni\RegistroOperazioni;
use Tests\Doppi\IscrizioniRegistroFinto;
use Tests\Doppi\MailerFinto;
use Tests\Doppi\OrologioFisso;
use Tests\Integration\DatabaseDiProva;

/**
 * Base dei test di integrazione del modulo FSL: le tabelle (solo le colonne usate), qualche scuola, convenzione e attività, e il
 * contenitore con i doppi (email, orologio fisso al 5 ottobre 2026, registro delle operazioni, verifica delle firme).
 */
abstract class FslBase extends DatabaseDiProva
{
    protected MailerFinto $mailer;
    protected Container $c;
    protected string $radice;

    protected function tabelle(): array
    {
        return [
            'pagine_eventi' => "CREATE TABLE pagine_eventi (id INT PRIMARY KEY, slug VARCHAR(80) DEFAULT '', titolo VARCHAR(150) DEFAULT '', colore_primario VARCHAR(20) DEFAULT '#0056B3', visibile TINYINT DEFAULT 1,
                limite_iscrizioni VARCHAR(20) DEFAULT 'nessuno', conv_url_modello VARCHAR(255) NULL, conv_url_allegato VARCHAR(255) NULL, conv_pec VARCHAR(150) NULL)",
            'eventi' => "CREATE TABLE eventi (id INT AUTO_INCREMENT PRIMARY KEY, pagina_id INT, titolo VARCHAR(150) DEFAULT '', tipo VARCHAR(20) DEFAULT 'evento', archiviato TINYINT DEFAULT 0,
                descrizione TEXT NULL, descrizione_breve TEXT NULL)",
            'turni' => 'CREATE TABLE turni (id INT AUTO_INCREMENT PRIMARY KEY, evento_id INT, nome_turno VARCHAR(150) NULL, data_turno DATE NULL, orario_inizio TIME NULL, orario_fine TIME NULL, richiede_approvazione TINYINT DEFAULT 0)',
            'prenotazioni' => "CREATE TABLE prenotazioni (id INT AUTO_INCREMENT PRIMARY KEY, turno_id INT, utente_id INT NULL, codice_prenotazione VARCHAR(40) DEFAULT '', stato VARCHAR(30) NULL DEFAULT 'confermata',
                presente INT DEFAULT 0, num_posti INT DEFAULT 1, nome VARCHAR(100) NULL, cognome VARCHAR(100) NULL, email VARCHAR(150) NULL, matricola VARCHAR(50) NULL, dati_custom_json TEXT NULL,
                data_prenotazione DATETIME DEFAULT CURRENT_TIMESTAMP, scuola_codice VARCHAR(10) NULL, convenzione VARCHAR(10) NULL, conv_promemoria INT DEFAULT 0, valutazione_token VARCHAR(40) NULL,
                valutazione_inviata DATETIME NULL, valutazione_promemoria TINYINT DEFAULT 0)",
            'progetti_dettagli' => 'CREATE TABLE progetti_dettagli (evento_id INT PRIMARY KEY, data_inizio DATE NULL, data_fine DATE NULL, ore_totali INT NULL, referenti_json TEXT NULL, info_extra_json TEXT NULL,
                moduli_json TEXT NULL, obiettivi TEXT NULL, per_scuole TINYINT DEFAULT 1, attestati TINYINT DEFAULT 0, convenzione TINYINT DEFAULT 0, dedicata_scuole TINYINT DEFAULT 0)',
            'scuole' => "CREATE TABLE scuole (codice VARCHAR(10) PRIMARY KEY, denominazione VARCHAR(255) NOT NULL DEFAULT '', istituto_codice VARCHAR(10) NULL, istituto_denominazione VARCHAR(255) NULL, tipo VARCHAR(150) DEFAULT '',
                comune VARCHAR(120) DEFAULT '', provincia VARCHAR(80) DEFAULT '', regione VARCHAR(80) DEFAULT '', indirizzo VARCHAR(255) DEFAULT '', cap VARCHAR(10) DEFAULT '', email VARCHAR(150) DEFAULT '', pec VARCHAR(150) DEFAULT '')",
            'convenzioni_scuole' => 'CREATE TABLE convenzioni_scuole (id INT AUTO_INCREMENT PRIMARY KEY, scuola_codice VARCHAR(10) NOT NULL, data_stipula DATE NULL, scadenza DATE NULL, protocollo VARCHAR(100) DEFAULT \'\',
                note VARCHAR(500) DEFAULT \'\', avviso_scadenza_inviato TINYINT DEFAULT 0, registrata_da VARCHAR(255) DEFAULT \'\', file_convenzione VARCHAR(255) NULL, file_allegato VARCHAR(255) NULL, docenti_json TEXT NULL)',
            'convenzioni_compilate' => 'CREATE TABLE convenzioni_compilate (id INT AUTO_INCREMENT PRIMARY KEY, token VARCHAR(40) NOT NULL, scuola_codice VARCHAR(10) NULL, prenotazione_id INT NULL, email VARCHAR(255) NULL,
                dati_json MEDIUMTEXT NULL, logo VARCHAR(255) NULL, aggiornata_il DATETIME NULL, scaricata_il DATETIME NULL, protocollo VARCHAR(100) NULL, protocollo_data DATE NULL)',
            'valutazioni_fsl' => 'CREATE TABLE valutazioni_fsl (id INT AUTO_INCREMENT PRIMARY KEY, prenotazione_id INT NOT NULL, evento_id INT NOT NULL, scuola_codice VARCHAR(10) NULL, compilata_da VARCHAR(150) NULL,
                risposte_json TEXT NULL, media DECIMAL(3,2) NULL, ripeterebbe VARCHAR(10) NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq (prenotazione_id))',
            'partecipanti_prenotazione' => 'CREATE TABLE partecipanti_prenotazione (id INT AUTO_INCREMENT PRIMARY KEY, prenotazione_id INT NOT NULL)',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->radice = dirname(__DIR__, 3);
        $this->mailer = new MailerFinto();
        $c = new Container();
        \App\Iscrizioni\Registrazione::registra($c);
        \App\Fsl\Registrazione::registra($c);
        $c->istanza(Container::class, $c);
        $c->istanza(Database::class, $this->db);
        $c->istanza(Mailer::class, $this->mailer);
        $c->istanza(Orologio::class, new OrologioFisso('2026-10-05 12:00:00'));
        $c->istanza(Sito::class, new Sito($this->radice, 'https://portale.test/eventi'));
        $c->istanza(RegistroOperazioni::class, new IscrizioniRegistroFinto());
        $c->istanza(VerificaFirme::class, new class () implements VerificaFirme {
            public function firmePades(string $pdf): array
            {
                return str_contains($pdf, 'FIRMATO') ? [['subfilter' => 'ETSI.CAdES.detached']] : [];
            }
        });
        $this->c = $c;
        $this->db->esegui("INSERT INTO pagine_eventi (id, slug, titolo, colore_primario) VALUES (2, 'fsl', 'FORMAZIONE SCUOLA LAVORO', '#112233')");
        $this->db->esegui("INSERT INTO scuole (codice, denominazione, istituto_codice, istituto_denominazione, comune, provincia, indirizzo, cap) VALUES
            ('CSPS00001A', 'LICEO SCIENTIFICO GALILEI', 'CSIS00001A', 'ISTITUTO GALILEI', 'COSENZA', 'CS', 'VIA ROMA 1', '87100'),
            ('CSPS00002B', 'LICEO SCIENTIFICO FERMI', 'CSIS00002B', 'ISTITUTO FERMI', 'RENDE', 'CS', 'VIA DANTE 2', '87036'),
            ('CSTF00003C', 'ISTITUTO TECNICO VOLTA', NULL, NULL, 'CASTROVILLARI', 'CS', '', '')");
        // Progetto FSL 20 (dal 13/10 al 02/12/2026, 2 edizioni) ed evento FSL 10 con un turno il 23/10/2026
        $this->db->esegui("INSERT INTO eventi (id, pagina_id, titolo, tipo, descrizione_breve) VALUES (20, 2, 'Geologia sul campo', 'progetto', '<p>Uscite <b>sul campo</b> e laboratorio.</p>'), (10, 2, 'Modulo di Genetica', 'evento', '')");
        $this->db->esegui("INSERT INTO progetti_dettagli (evento_id, data_inizio, data_fine, ore_totali, referenti_json, obiettivi, convenzione, per_scuole, attestati) VALUES
            (20, '2026-10-13', '2026-12-02', 40, '[{\"nome\":\"Prof. Rossi\"}]', 'Conoscere le rocce', 1, 1, 1), (10, NULL, NULL, NULL, NULL, '', 1, 0, 1)");
        $this->db->esegui("INSERT INTO turni (id, evento_id, nome_turno, data_turno, orario_inizio, orario_fine, richiede_approvazione) VALUES
            (200, 20, 'Edizione 1', NULL, NULL, NULL, 0), (201, 20, 'Edizione 2', NULL, NULL, NULL, 1), (100, 10, 'Turno A', '2026-10-23', '09:00:00', '12:30:00', 0)");
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

    /** Aggiunge una prenotazione e ritorna il suo id. @param array<string, mixed> $campi */
    protected function pren(int $turnoId, array $campi = []): int
    {
        $n = (int) $this->db->valore('SELECT COUNT(*) FROM prenotazioni') + 1;
        $campi += ['turno_id' => $turnoId, 'stato' => 'confermata', 'codice_prenotazione' => "FS-$n", 'nome' => "Nome$n", 'cognome' => "Cognome$n", 'email' => "p$n@prova.it",
            'dati_custom_json' => '{"numero_partecipanti":"12"}', 'scuola_codice' => 'CSPS00001A', 'convenzione' => 'no'];

        return $this->db->inserisci('INSERT INTO prenotazioni (' . implode(',', array_keys($campi)) . ') VALUES (' . implode(',', array_fill(0, count($campi), '?')) . ')', array_values($campi));
    }

    protected function convenzione(string $codice, ?string $dal, ?string $al, array $extra = []): int
    {
        return $this->db->inserisci('INSERT INTO convenzioni_scuole (scuola_codice, data_stipula, scadenza, protocollo, docenti_json) VALUES (?, ?, ?, ?, ?)', [$codice, $dal, $al, $extra['protocollo'] ?? '', $extra['docenti_json'] ?? null]);
    }

    /** @return array<string, mixed>|null */
    protected function riga(string $tabella, int $id): ?array
    {
        return $this->db->riga("SELECT * FROM `$tabella` WHERE id = ?", [$id]);
    }
}
