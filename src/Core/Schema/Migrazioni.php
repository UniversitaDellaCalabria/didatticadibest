<?php

declare(strict_types=1);

namespace App\Core\Schema;

use App\Core\Database;
use Closure;

/**
 * Aggiornamento automatico dello schema del database (MySQL e MariaDB): crea tabelle e colonne mancanti, corregge i tipi
 * dei database più vecchi e inserisce i dati di partenza. È l'UNICO punto in cui il codice modifica la struttura del
 * database: nessuna pagina esegue ALTER/CREATE al volo. Il file marcatore (cache/schema_vNN.ok) evita di interrogare lo
 * schema a ogni richiesta: quando si aggiunge qualcosa in Definizioni si alza VERSIONE.
 * Spostato da inc/schema.php (assicura_schema() resta come facciata), con la stessa sequenza di operazioni.
 */
final class Migrazioni
{
    public const VERSIONE = 44;

    /**
     * @param list<string> $gruppiPersonale gruppi assegnati al login in base al ruolo in Ateneo (GRUPPI_PERSONALE)
     * @param Closure(): void|null $datiDeiModuli dati di partenza dei moduli (es. uffici e modulo di convalida della Didattica)
     * @param Closure(): void|null $dopo operazioni a schema aggiornato (es. svuotare la cache della configurazione)
     */
    public function __construct(
        private Database $db,
        private string $cartellaCache,
        private array $gruppiPersonale = [],
        private ?Closure $datiDeiModuli = null,
        private ?Closure $dopo = null,
    ) {
    }

    public function marcatore(): string
    {
        return rtrim($this->cartellaCache, '/') . '/schema_v' . self::VERSIONE . '.ok';
    }

    /** Aggiorna lo schema se non è già stato fatto per questa versione. Ritorna false se un passo non riesce (si riprova alla richiesta dopo). */
    public function aggiorna(): bool
    {
        if (is_file($this->marcatore())) {
            return true;
        }
        if (!$this->creaTabelle() || !$this->aggiungiColonne() || !$this->correggiTipi()) {
            return false;
        }
        $this->datiDiPartenza();
        if (!is_dir($this->cartellaCache)) {
            @mkdir($this->cartellaCache, 0755, true);
        }
        @file_put_contents($this->marcatore(), date('c'));

        return true;
    }

    private function creaTabelle(): bool
    {
        foreach (Definizioni::tabelle() as $nome => $ddl) {
            if (!$this->db->comando($ddl)) {
                error_log("[assicura_schema] CREATE fallito su $nome: " . $this->db->ultimoErrore());

                return false;
            }
        }

        return true;
    }

    private function aggiungiColonne(): bool
    {
        foreach (Definizioni::colonne() as $tabella => $colonne) {
            foreach ($colonne as $colonna => $def) {
                [$alter, $dopo] = is_array($def) ? $def : [$def, null];
                $chk = $this->db->comando("SHOW COLUMNS FROM `$tabella` LIKE '$colonna'");
                if (!$chk) {
                    return false;
                }
                if ($chk !== true && $chk->num_rows > 0) {
                    continue;
                }
                if (!$this->db->comando("ALTER TABLE `$tabella` $alter")) {
                    error_log("[assicura_schema] ALTER fallito su $tabella.$colonna: " . $this->db->ultimoErrore());

                    return false;
                }
                if ($dopo !== null) {
                    $this->db->comando($dopo);
                }
            }
        }

        return true;
    }

    private function correggiTipi(): bool
    {
        foreach (Definizioni::tipi() as [$tabella, $colonna, $daCorreggere, $alter]) {
            $res = $this->db->comando("SHOW COLUMNS FROM `$tabella` LIKE '$colonna'");
            $riga = $res instanceof \mysqli_result ? $res->fetch_assoc() : null;
            if ($riga && $daCorreggere(strtolower((string) $riga['Type'])) && !$this->db->comando("ALTER TABLE `$tabella` $alter")) {
                error_log("[assicura_schema] MODIFY fallito su $tabella.$colonna: " . $this->db->ultimoErrore());

                return false;
            }
        }

        return true;
    }

    private function datiDiPartenza(): void
    {
        // 4. v23: struttura di partenza dell'anagrafe (DiBEST) e gruppi assegnati al login in base al ruolo in Ateneo
        $this->db->comando("INSERT IGNORE INTO anagrafe_strutture (codice, nome) VALUES ('002014', 'Dipartimento di Biologia, Ecologia e Scienze della Terra')");
        foreach ($this->gruppiPersonale as $g) {
            $this->db->esegui('INSERT INTO ruoli (nome) SELECT ? FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM ruoli WHERE nome = ?)', [$g, $g]);
        }

        // 4b. v32: indice per le tendine regione / provincia / comune della scelta guidata della scuola
        $idx = $this->db->comando("SHOW INDEX FROM scuole WHERE Key_name = 'idx_luogo'");
        if ($idx instanceof \mysqli_result && $idx->num_rows === 0) {
            $this->db->comando('ALTER TABLE scuole ADD INDEX idx_luogo (regione, provincia, comune)');
        }

        // 4c. v37: uffici di partenza dell'Ufficio didattico (si cambiano dal pannello) e personale già assegnato a un profilo
        if ((int) ($this->db->valore('SELECT COUNT(*) n FROM didattica_uffici') ?? 1) === 0) {
            $this->db->comando("INSERT INTO didattica_uffici (nome, descrizione, chiave, smista, segue_corsi, ordine) VALUES
                ('Manager dell’Ufficio didattico', 'Riceve le pratiche nuove e le smista', 'manager', 1, 0, 1),
                ('Referenti dei corsi di studio', 'Istruttoria e verbali dei consigli di corso', 'referente_cdl', 0, 1, 2),
                ('Carriere studenti', 'Registrazione in carriera', 'carriere', 0, 0, 3),
                ('Internazionalizzazione', 'Mobilità e attività all’estero', 'internazionalizzazione', 0, 0, 4),
                ('Segreteria didattica', 'Operatori dell’ufficio', 'operatore', 0, 0, 5)");
        }
        $profilo = $this->db->comando("SHOW COLUMNS FROM ufficio_didattica LIKE 'profilo'");
        if ($profilo instanceof \mysqli_result && $profilo->num_rows) {
            $this->db->comando('UPDATE ufficio_didattica o JOIN didattica_uffici u ON u.chiave = o.profilo SET o.ufficio_id = u.id WHERE o.ufficio_id IS NULL');
        }

        // 4d. v38: i consigli dei corsi di studio del Dipartimento (si cambiano dal pannello Didattica → Sedute e verbali → Consigli)
        if ((int) ($this->db->valore('SELECT COUNT(*) n FROM didattica_consigli') ?? 1) === 0) {
            $this->db->comando("INSERT INTO didattica_consigli (nome, ordine) VALUES
                ('Consiglio Unificato del Corso di Laurea in Scienze Naturali e Ambientali e del Corso di Laurea Magistrale in Biodiversità e Conservazione dei Sistemi Naturali', 1),
                ('Consiglio Unificato del Corso di Laurea in Scienze Geologiche e del Corso di Laurea Magistrale in Scienze Geologiche per la Gestione dei Rischi Ambientali e le Georisorse', 2),
                ('Consiglio del Corso di Laurea in Scienze e Tecnologie per le Attività Motorie e Sportive', 3),
                ('Consiglio di Coordinamento del Corso di Laurea in Biologia, del Corso di Laurea Magistrale in Biologia, del Corso di Laurea in Scienze e Tecnologie Biologiche e del Corso di Laurea Magistrale in Health Biotechnology', 4),
                ('Consiglio di Coordinamento del Corso di Laurea Magistrale a Ciclo Unico in Conservazione e Restauro dei Beni Culturali', 5)");
        }

        // 4e. v42: dati di partenza dei moduli (Ufficio protocollo, uffici dei corsi di studio, modulo di convalida degli esami)
        if ($this->datiDeiModuli !== null) {
            ($this->datiDeiModuli)();
        }

        // 5. v31: il portale diventa "Didattica DiBEST" (solo dove c'è ancora il nome predefinito di prima)
        $this->db->comando("UPDATE configurazione_portale SET nome_portale = 'Didattica DiBEST' WHERE nome_portale IN ('EventiDiBEST', 'Eventi DiBEST', 'Eventi Dibest')");
        $this->db->comando("UPDATE impostazioni_sistema SET smtp_from_name = REPLACE(REPLACE(smtp_from_name, 'EventiDiBEST', 'Didattica DiBEST'), 'Eventi DiBEST', 'Didattica DiBEST')
                      WHERE smtp_from_name LIKE '%Eventi%DiBEST%'");
        if ($this->dopo !== null) {
            ($this->dopo)();
        }
    }
}
