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
    /** 99: area personale con icone di servizio a tre per riga e righe di lavoro (nuovi riquadri «messaggi» e «cerca»). 98: disponibilità dei docenti per tesi e tirocini (tabella disponibilita_docenti). 97: area personale con «Il mio lavoro» (riquadro di sistema «lavoro»: le postazioni di chi gestisce pratiche, protocollo, sedute, convenzioni, tutorato, assistenza). 96: Didacticus separa i tirocini (studenti) dalla Formazione Scuola Lavoro (scuole): nuovo argomento «fsl». 95: calendario personale (tabella calendario_token) e interruttore del riepilogo settimanale con l'agenda (colonne riepilogo_agenda* di impostazioni_sistema). 94: area personale a cruscotto (riquadro «Il mio profilo»). 93: area personale per profilo (tabella area_riquadri e riquadri di sistema di partenza). 89: Didacticus (tabelle didacticus_voci e didacticus_mancate, colonne dell'assistenza e voci di partenza). 88: notifiche push (tabella push_sottoscrizioni). 87: assistenza acceso/spento e orari (colonne assistenza_attiva e assistenza_orari di impostazioni_sistema). 86: assistenza (tabelle assistenza_conversazioni e assistenza_messaggi, ufficio fisso «assistenza»). 82: testi delle email modificabili dal pannello (tabella email_modelli). 80: verbali pubblici in sola visualizzazione, download con le credenziali di Ateneo (ufficio «verbali_scarico»). 75: dati del Direttore nel testo della convenzione di tesi (colonne tesi_direttore_* di impostazioni_sistema). 74: nella richiesta di tesi la sede esterna sceglie l'azienda tra le convenzionate (aggiorna il modulo già creato). 73: convenzioni di tesi con enti e aziende (tabella convenzioni_tesi e ufficio «convenzioni_tesi»). 72: interruttore per sospendere i promemoria al Direttore e agli uffici. 71: uffici di Dipartimento e di Ateneo in un'unica anagrafe (tabelle uffici e uffici_persone): la migrazione copia i dati delle quattro tabelle di prima. */
    public const VERSIONE = 99;

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

        // 4a2. v89: le voci di partenza di Didacticus (una volta sola: se il pannello le cancella non tornano)
        if ((int) ($this->db->valore('SELECT didacticus_seminato FROM impostazioni_sistema WHERE id = 1') ?? 1) === 0 && (int) ($this->db->valore('SELECT COUNT(*) FROM impostazioni_sistema WHERE id = 1') ?? 0) === 1) {
            \App\Assistenza\Didacticus\VociDiPartenza::inserisci($this->db);
            $this->db->esegui('UPDATE impostazioni_sistema SET didacticus_seminato = 1 WHERE id = 1');
        }

        // 4a2b. v96: Didacticus separa i tirocini degli studenti dalla Formazione Scuola Lavoro delle scuole (argomento «fsl»; la voce di partenza sulle scuole passa lì)
        if ((int) ($this->db->valore("SELECT COUNT(*) FROM didacticus_argomenti WHERE chiave = 'tirocini'") ?? 0) === 1) {
            $this->db->esegui("UPDATE didacticus_argomenti SET nome = 'Tirocini', aiuto = ? WHERE chiave = 'tirocini' AND nome = 'Tirocini e Formazione Scuola Lavoro'", [\App\Assistenza\Costanti::ARGOMENTI['tirocini']['aiuto']]);
            if ((int) ($this->db->valore("SELECT COUNT(*) FROM didacticus_argomenti WHERE chiave = 'fsl'") ?? 1) === 0) {
                $this->db->esegui('INSERT INTO didacticus_argomenti (chiave, nome, icona, aiuto, ordine) VALUES (?, ?, ?, ?, ?)', ['fsl', \App\Assistenza\Costanti::ARGOMENTI['fsl']['nome'], 'fa-school', \App\Assistenza\Costanti::ARGOMENTI['fsl']['aiuto'], 1 + (int) ($this->db->valore('SELECT COALESCE(MAX(ordine), 0) FROM didacticus_argomenti') ?? 0)]);
            }
        }
        $this->db->esegui("UPDATE didacticus_voci SET argomento = 'fsl' WHERE argomento = 'tirocini' AND titolo = 'Scuole: Formazione Scuola Lavoro e convenzione online'");

        // 4a3. v93-94: i riquadri di sistema dell'area personale (quelli che mancano: i presenti, anche spenti o cambiati dal pannello, non si toccano)
        (new \App\Portale\Area\RiquadriRepository($this->db))->semina(date('Y-m-d H:i:s'));

        // 4b. v32: indice per le tendine regione / provincia / comune della scelta guidata della scuola
        $idx = $this->db->comando("SHOW INDEX FROM scuole WHERE Key_name = 'idx_luogo'");
        if ($idx instanceof \mysqli_result && $idx->num_rows === 0) {
            $this->db->comando('ALTER TABLE scuole ADD INDEX idx_luogo (regione, provincia, comune)');
        }

        // 4c. v37: personale già assegnato a un profilo (tabella di prima, poi copiata negli uffici); v71: uffici e persone in un solo posto
        $profilo = $this->db->comando("SHOW COLUMNS FROM ufficio_didattica LIKE 'profilo'");
        if ($profilo instanceof \mysqli_result && $profilo->num_rows) {
            $this->db->comando('UPDATE ufficio_didattica o JOIN didattica_uffici u ON u.chiave = o.profilo SET o.ufficio_id = u.id WHERE o.ufficio_id IS NULL');
        }
        $this->migraUffici();

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

    /**
     * v71: copia uffici e persone delle quattro tabelle di prima nell'organigramma (uffici, uffici_persone), una volta sola (finché non c'è l'ufficio
     * «direzione»), poi crea gli uffici di partenza. Gli uffici del Dipartimento tengono lo stesso id (le pratiche in corso e l'iter dei moduli non cambiano);
     * quelli di Ateneo ne hanno uno nuovo e i bandi di tutorato che li usano sono aggiornati. Le tabelle di prima restano, non più usate.
     */
    private function migraUffici(): void
    {
        $giaFatto = (int) ($this->db->valore("SELECT COUNT(*) n FROM uffici WHERE chiave = 'direzione'") ?? 1) > 0;
        if (!$giaFatto) {
            // 1. uffici dell'Ufficio didattico: stessi id
            $this->db->comando("INSERT IGNORE INTO uffici (id, nome, descrizione, chiave, ambito, smista, segue_corsi, ordine, tipo, consiglio_id, creato_il)
                SELECT id, nome, descrizione, chiave, 'dipartimento', smista, segue_corsi, ordine, tipo, consiglio_id, creato_il FROM didattica_uffici");
            // 2. personale dell'Ufficio didattico: una riga per ufficio (il principale e gli altri); chi non ha ufficio va nella segreteria didattica
            $generico = (int) ($this->db->valore("SELECT id FROM uffici WHERE chiave = 'operatore' LIMIT 1") ?? 0);
            if ($generico === 0 && (int) $this->db->valore('SELECT COUNT(*) FROM ufficio_didattica') > 0) {
                $generico = $this->db->inserisci("INSERT INTO uffici (nome, descrizione, chiave, ambito, ordine) VALUES ('Segreteria didattica', 'Operatori dell’ufficio', 'operatore', 'dipartimento', 99)");
            }
            // Due passate: prima l'appartenenza principale con lo stesso id di prima (pratiche.assegnata_a punta a quell'id), poi le altre
            $altre = [];
            foreach ($this->db->righe('SELECT * FROM ufficio_didattica ORDER BY id') as $o) {
                $ids = [];
                if (!empty($o['ufficio_id'])) {
                    $ids[] = (int) $o['ufficio_id'];
                }
                foreach (json_decode((string) ($o['uffici_json'] ?? ''), true) ?: [] as $x) {
                    if ((int) $x > 0) {
                        $ids[] = (int) $x;
                    }
                }
                $ids = array_values(array_unique($ids)) ?: [$generico];
                $this->db->esegui('INSERT IGNORE INTO uffici_persone (id, ufficio_id, persona_id, email, nominativo, ruolo, compiti, corsi, op_id, creato_il) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [(int) $o['id'], array_shift($ids), $o['persona_id'], strtolower((string) $o['email']), $o['nominativo'], $o['ruolo'], $o['compiti'], $o['corsi'], (int) $o['id'], $o['creato_il']]);
                $altre[] = [$o, $ids];
            }
            foreach ($altre as [$o, $ids]) {
                foreach ($ids as $uid) {
                    $this->db->esegui('INSERT IGNORE INTO uffici_persone (ufficio_id, persona_id, email, nominativo, ruolo, compiti, corsi, op_id, creato_il) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [$uid, $o['persona_id'], strtolower((string) $o['email']), $o['nominativo'], $o['ruolo'], $o['compiti'], $o['corsi'], (int) $o['id'], $o['creato_il']]);
                }
            }
            // 3. uffici di Ateneo: nuovi id; le persone seguono; i bandi di tutorato puntano ai nuovi id (un solo UPDATE: nessun scambio a catena)
            $mappa = [];
            foreach ($this->db->righe('SELECT * FROM uffici_ateneo ORDER BY id') as $ua) {
                $nuovo = $this->db->inserisci("INSERT INTO uffici (nome, descrizione, ambito, attivo, creato_il) VALUES (?, ?, 'ateneo', ?, ?)", [$ua['nome'], $ua['descrizione'], $ua['attivo'], $ua['creato_il']]);
                $mappa[(int) $ua['id']] = $nuovo;
                $this->db->esegui('INSERT IGNORE INTO uffici_persone (ufficio_id, persona_id, email, nominativo, struttura, creato_il)
                    SELECT ?, persona_id, email, nominativo, struttura, creato_il FROM uffici_ateneo_persone WHERE ufficio_id = ?', [$nuovo, (int) $ua['id']]);
            }
            if ($mappa) {
                $casi = '';
                foreach ($mappa as $vecchio => $nuovo) {
                    $casi .= ' WHEN ' . (int) $vecchio . ' THEN ' . (int) $nuovo;
                }
                $this->db->comando('UPDATE tutorato_bandi SET ufficio_ateneo_id = CASE ufficio_ateneo_id' . $casi . ' ELSE ufficio_ateneo_id END WHERE ufficio_ateneo_id IN (' . implode(',', array_keys($mappa)) . ')');
            }
        }

        // Uffici di partenza di una installazione nuova (si cambiano dal pannello)
        if ((int) ($this->db->valore("SELECT COUNT(*) n FROM uffici WHERE ambito = 'dipartimento'") ?? 1) === 0) {
            $this->db->comando("INSERT INTO uffici (nome, descrizione, chiave, ambito, smista, segue_corsi, ordine) VALUES
                ('Manager dell’Ufficio didattico', 'Riceve le pratiche nuove e le smista', 'manager', 'dipartimento', 1, 0, 1),
                ('Referenti dei corsi di studio', 'Istruttoria e verbali dei consigli di corso', 'referente_cdl', 'dipartimento', 0, 1, 2),
                ('Carriere studenti', 'Registrazione in carriera', 'carriere', 'dipartimento', 0, 0, 3),
                ('Internazionalizzazione', 'Mobilità e attività all’estero', 'internazionalizzazione', 'dipartimento', 0, 0, 4),
                ('Segreteria didattica', 'Operatori dell’ufficio', 'operatore', 'dipartimento', 0, 0, 5)");
        }

        // Dopo gli uffici di partenza (che si creano solo se il Dipartimento non ne ha ancora): l'ufficio che prende in carico le convenzioni di tesi con enti e aziende
        // (persone dall'anagrafe, si scelgono da Gestione del portale → Uffici)
        if ((int) ($this->db->valore("SELECT COUNT(*) FROM uffici WHERE chiave = 'convenzioni_tesi'") ?? 0) === 0) {
            $this->db->comando("INSERT INTO uffici (nome, descrizione, chiave, ambito, ordine) VALUES ('Ufficio convenzioni di tesi', 'Prende in carico le convenzioni per la tesi con enti e aziende: protocollo in entrata e definitivo, modifiche agli articoli', 'convenzioni_tesi', 'dipartimento', 0)");
        }

        // Uffici fissi che servono al portale (se mancano si creano): convenzioni FSL e persone che possono scaricare i verbali
        foreach ([['convenzioni_fsl', 'Ufficio convenzioni FSL', 'Prende in carico le convenzioni delle scuole (Formazione Scuola Lavoro): protocollo in entrata e definitivo', 90],
                  ['verbali_scarico', 'Download dei verbali', 'Persone che possono scaricare i verbali dalla pagina pubblica (accedendo con le credenziali di Ateneo)', 91],
                  ['assistenza', 'Assistenza a studenti e utenti', 'Risponde alle chat di assistenza (le richieste che Didacticus non risolve arrivano qui)', 92]] as [$chiave, $nome, $descr, $ord]) {
            if ((int) $this->db->valore('SELECT COUNT(*) FROM uffici WHERE chiave = ?', [$chiave]) === 0) {
                $this->db->inserisci("INSERT INTO uffici (nome, descrizione, chiave, ambito, ordine) VALUES (?, ?, ?, 'dipartimento', ?)", [$nome, $descr, $chiave, $ord]);
            }
        }
        // Direzione del Dipartimento (Direttore e vice) e segreteria di direzione: sempre presenti. Il Direttore delle impostazioni diventa il primo membro.
        if (!$giaFatto) {
            $dir = $this->db->inserisci("INSERT INTO uffici (nome, descrizione, chiave, ambito, ordine) VALUES ('Direzione del Dipartimento', 'Direttore e vice direttore: firmano convenzioni e pratiche', 'direzione', 'dipartimento', 0)");
            if ((int) $this->db->valore("SELECT COUNT(*) FROM uffici WHERE chiave = 'segreteria_direzione'") === 0) {
                $this->db->inserisci("INSERT INTO uffici (nome, descrizione, chiave, ambito, ordine) VALUES ('Segreteria di direzione', 'Può attivare «Direttore assente»', 'segreteria_direzione', 'dipartimento', 0)");
            }
            $imp = $this->db->riga('SELECT conv_direttore_nome, conv_direttore_email FROM impostazioni_sistema WHERE id = 1');
            $mail = strtolower(trim((string) ($imp['conv_direttore_email'] ?? '')));
            if ($mail !== '') {
                $pid = $this->db->valore('SELECT id FROM personale_ateneo WHERE LOWER(email) = ? LIMIT 1', [$mail]);
                $this->db->esegui("INSERT IGNORE INTO uffici_persone (ufficio_id, persona_id, email, nominativo, ruolo, compiti) VALUES (?, ?, ?, ?, 'Direttore', '')",
                    [$dir, $pid === null ? null : (string) $pid, $mail, (string) ($imp['conv_direttore_nome'] ?? '')]);
            }
        }
    }
}
