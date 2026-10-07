<?php

declare(strict_types=1);

namespace App\Eventi;

use App\Core\Database;
use RuntimeException;

/** Query di eventi, turni e sottocategorie (tabelle eventi, turni, sottocategorie, progetti_dettagli). */
final class EventoRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return list<array<string, mixed>> eventi archiviati dell'area, con sottocategoria e anno del primo turno */
    public function archivio(int $paginaId): array
    {
        return $this->db->righe(
            'SELECT e.*, sc.nome as nome_sottocategoria,
             (SELECT YEAR(MIN(data_turno)) FROM turni WHERE evento_id = e.id) as anno_evento
             FROM eventi e
             LEFT JOIN sottocategorie sc ON e.sottocategoria_id = sc.id
             WHERE e.pagina_id = ? AND e.archiviato = 1
             ORDER BY anno_evento DESC, sc.ordine ASC, e.ordine ASC',
            [$paginaId]
        );
    }

    /** @return list<array<string, mixed>> eventi di aree visibili che contengono il testo (titolo, descrizione, luogo), con la prossima data */
    public function cerca(string $q): array
    {
        $like = '%' . $q . '%';

        return $this->db->righe(
            'SELECT e.*,
                    pe.titolo as nome_area, pe.slug as slug_area, pe.colore_primario,
                    (SELECT MIN(data_turno) FROM turni WHERE evento_id = e.id AND data_turno >= CURDATE()) as prossima_data
                FROM eventi e
                JOIN pagine_eventi pe ON e.pagina_id = pe.id
                WHERE pe.visibile = 1
                  AND (e.titolo LIKE ? OR e.descrizione LIKE ? OR e.luogo LIKE ?)
                ORDER BY e.archiviato ASC, prossima_data ASC',
            [$like, $like, $like]
        );
    }

    /** @return list<array<string, string|null>> sottocategorie dell'area */
    public function sottocategorie(int $paginaId): array
    {
        return Righe::testo($this->db->righe('SELECT * FROM sottocategorie WHERE pagina_id = ? ORDER BY ordine ASC', [$paginaId]));
    }

    /**
     * Eventi (non) archiviati dell'area con tutti i loro turni, per il pannello. $rbac = condizione SQL sui permessi del gestore.
     *
     * @return list<array<string, mixed>>
     */
    public function conTurniAdmin(int $paginaId, int $archiviato, string $rbac): array
    {
        $eventi = [];
        foreach (Righe::testo($this->db->righe("SELECT id, titolo FROM eventi e WHERE e.pagina_id = ? AND e.archiviato = ? $rbac ORDER BY e.ordine ASC, e.id DESC", [$paginaId, $archiviato])) as $row) {
            $row['turni'] = Righe::testo($this->db->righe(
                'SELECT * FROM turni WHERE evento_id = ? ORDER BY (data_turno IS NULL), data_turno ASC, orario_inizio ASC, nome_turno ASC, id ASC',
                [(int) $row['id']]
            ));
            $eventi[] = $row;
        }

        return $eventi;
    }

    /**
     * @param list<int> $eventiIds
     * @return array<int, array<string, mixed>> schede progetto (progetti_dettagli) per evento, con referenti, info extra e moduli già decodificati
     */
    public function dettagliProgetti(array $eventiIds): array
    {
        $eventiIds = array_values(array_filter(array_map('intval', $eventiIds)));
        if (!$eventiIds) {
            return [];
        }
        $out = [];
        foreach (Righe::testo($this->db->righe('SELECT * FROM progetti_dettagli WHERE evento_id IN (' . implode(',', $eventiIds) . ')')) as $r) {
            $r['referenti'] = json_decode((string) ($r['referenti_json'] ?? ''), true) ?: [];
            $r['info_extra'] = json_decode((string) ($r['info_extra_json'] ?? ''), true) ?: [];
            $r['moduli'] = json_decode((string) ($r['moduli_json'] ?? ''), true) ?: [];
            $out[(int) $r['evento_id']] = $r;
        }

        return $out;
    }

    /** @return list<string> colonne di una tabella tranne id e quelle escluse: la copia resta corretta anche se lo schema cambia */
    public function colonneCopiabili(string $tabella, array $escludi = []): array
    {
        $cols = [];
        foreach ($this->db->righe('SHOW COLUMNS FROM `' . str_replace('`', '', $tabella) . '`') as $c) {
            if ($c['Field'] !== 'id' && !in_array($c['Field'], $escludi, true)) {
                $cols[] = (string) $c['Field'];
            }
        }

        return $cols;
    }

    /** @return list<int> id dei turni dell'evento */
    public function idTurni(int $eventoId): array
    {
        return array_map(static fn (array $r): int => (int) $r['id'], $this->db->righe('SELECT id FROM turni WHERE evento_id = ? ORDER BY id', [$eventoId]));
    }

    /** @return list<int> id dei sondaggi dell'evento */
    public function idSondaggi(int $eventoId): array
    {
        return array_map(static fn (array $r): int => (int) $r['id'], $this->db->righe('SELECT id FROM sondaggi WHERE evento_id = ? ORDER BY id', [$eventoId]));
    }

    /** @return list<int> id delle domande del sondaggio */
    public function idDomande(int $sondaggioId): array
    {
        return array_map(static fn (array $r): int => (int) $r['id'], $this->db->righe('SELECT id FROM sondaggi_domande WHERE sondaggio_id = ? ORDER BY id', [$sondaggioId]));
    }

    public function condizioneDomanda(int $domandaId): ?string
    {
        $c = $this->db->valore('SELECT condizione_json FROM sondaggi_domande WHERE id = ?', [$domandaId]);

        return $c === null ? null : (string) $c;
    }

    public function impostaCondizioneDomanda(int $domandaId, ?string $json): void
    {
        $this->db->esegui('UPDATE sondaggi_domande SET condizione_json = ? WHERE id = ?', [$json, $domandaId]);
    }

    /**
     * Copia di una riga con INSERT … SELECT (colonne scelte con colonneCopiabili). $sql è costruito da chi chiama con nomi
     * di colonna letti dallo schema e id interi. Ritorna l'id creato; lancia RuntimeException se non riesce.
     */
    public function copiaRighe(string $sql): int
    {
        if ($this->db->esegui($sql) < 0) {
            throw new \RuntimeException($this->db->mysqli()->error);
        }

        return (int) $this->db->mysqli()->insert_id;
    }

    // ---- archivio ----

    /** Riporta l'evento tra quelli attivi; blocca_auto_archivio evita che l'auto-archiviazione lo rimetta subito in archivio (i turni sono ancora nel passato). */
    public function ripristina(int $eventoId): void
    {
        $this->db->esegui('UPDATE eventi SET archiviato = 0, blocca_auto_archivio = 1 WHERE id = ?', [$eventoId]);
    }

    /**
     * Eventi archiviati dell'area per la pagina Archivio, con numero di turni, primo turno e iscritti. $rbac = condizione SQL sui permessi del gestore.
     *
     * @return list<array<string, string|null>>
     */
    public function archiviatiAdmin(int $paginaId, string $rbac): array
    {
        return Righe::testo($this->db->righe(
            "SELECT e.*, sc.nome as nome_sottocategoria,
            (SELECT COUNT(*) FROM turni WHERE evento_id = e.id) as tot_turni,
            (SELECT id FROM turni WHERE evento_id = e.id ORDER BY data_turno ASC, orario_inizio ASC LIMIT 1) as primo_turno_id,
            (SELECT COUNT(pr.id) FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id WHERE t.evento_id = e.id) as tot_iscritti
            FROM eventi e
            LEFT JOIN sottocategorie sc ON e.sottocategoria_id = sc.id
            WHERE e.pagina_id = $paginaId AND e.archiviato = 1 $rbac
            ORDER BY e.id DESC"
        ));
    }

    /** @return array<string, string> [nome_campo => etichetta] dei campi del form dell'evento */
    public function campiFormEvento(int $eventoId): array
    {
        $out = [];
        foreach ($this->db->righe('SELECT nome_campo, etichetta FROM campi_form WHERE evento_id = ? ORDER BY id ASC', [$eventoId]) as $cf) {
            $out[$cf['nome_campo']] = $cf['etichetta'];
        }

        return $out;
    }

    /** @return list<array<string, string|null>> iscrizioni dell'evento per l'esportazione (rendicontazione) */
    public function iscrizioniPerRendicontazione(int $eventoId): array
    {
        return Righe::testo($this->db->righe(
            'SELECT pr.codice_prenotazione, pr.presente, pr.stato, pr.num_posti, pr.nome, pr.cognome, pr.email, t.data_turno, pr.dati_custom_json
             FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id
             WHERE t.evento_id = ? ORDER BY t.data_turno ASC, pr.cognome ASC',
            [$eventoId]
        ));
    }

    // ---- schede di eventi e progetti ----

    /** @return array<string, string|null>|null la riga dell'evento (tutte le colonne) */
    public function perId(int $id): ?array
    {
        return Righe::riga($this->db->riga('SELECT * FROM eventi WHERE id = ?', [$id]));
    }

    public function titolo(int $id): string
    {
        return (string) ($this->db->valore('SELECT titolo FROM eventi WHERE id = ?', [$id]) ?? '');
    }

    /** L'evento è un progetto dell'area, visibile al gestore ($rbac = condizione SQL sui permessi, alias e). */
    public function progettoAutorizzato(int $id, int $paginaId, string $rbac): bool
    {
        return $this->db->riga("SELECT 1 FROM eventi e WHERE e.id = ? AND e.pagina_id = ? AND e.tipo = 'progetto' $rbac LIMIT 1", [$id, $paginaId]) !== null;
    }

    /**
     * Nuovo evento normale. $v: sub_id, titolo, luogo, desc, locandina, pdf, evid, req_pren, abilita_pres, ruolo_acc, ord. Ritorna l'id.
     *
     * @param array<string, mixed> $v
     */
    public function inserisciEvento(int $paginaId, array $v): int
    {
        return $this->db->inserisci(
            "INSERT INTO eventi (pagina_id, sottocategoria_id, titolo, luogo, descrizione, locandina_path, allegato_pdf, is_evidenza, richiede_prenotazione, abilita_presenze, ruolo_accesso_id, ordine, gestori_utenti_ids) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '')",
            [$paginaId, $v['sub_id'], (string) $v['titolo'], (string) $v['luogo'], (string) $v['desc'], (string) $v['locandina'], $v['pdf'], (int) $v['evid'], (int) $v['req_pren'], (int) $v['abilita_pres'], (int) $v['ruolo_acc'], (int) $v['ord']]
        );
    }

    /**
     * Modifica di un evento normale: locandina e PDF cambiano solo se se ne passa uno nuovo.
     *
     * @param array<string, mixed> $v come inserisciEvento()
     */
    public function aggiornaEvento(int $id, array $v, ?string $nuovaLocandina, ?string $nuovoPdf): void
    {
        $sql = 'UPDATE eventi SET sottocategoria_id=?, titolo=?, luogo=?, descrizione=?, ordine=?, is_evidenza=?, richiede_prenotazione=?, abilita_presenze=?, ruolo_accesso_id=?';
        $par = [$v['sub_id'], (string) $v['titolo'], (string) $v['luogo'], (string) $v['desc'], (int) $v['ord'], (int) $v['evid'], (int) $v['req_pren'], (int) $v['abilita_pres'], (int) $v['ruolo_acc']];
        if ($nuovaLocandina !== null) {
            $sql .= ', locandina_path=?';
            $par[] = $nuovaLocandina;
        }
        if ($nuovoPdf !== null) {
            $sql .= ', allegato_pdf=?';
            $par[] = $nuovoPdf;
        }
        $this->db->esegui($sql . ' WHERE id=?', [...$par, $id]);
    }

    public function eliminaLocandina(int $id): void
    {
        $this->db->esegui('UPDATE eventi SET locandina_path = NULL WHERE id = ?', [$id]);
    }

    public function eliminaAllegatoPdf(int $id): void
    {
        $this->db->esegui('UPDATE eventi SET allegato_pdf = NULL WHERE id = ?', [$id]);
    }

    /** Indirizzi aggiuntivi per le notifiche delle prenotazioni (csv, null = nessuno). */
    public function impostaNotificheExtra(int $id, ?string $csv): void
    {
        $this->db->esegui('UPDATE eventi SET email_notifiche_extra = ? WHERE id = ?', [$csv, $id]);
    }

    public function impostaDescrizioneBreve(int $id, ?string $testo): void
    {
        $this->db->esegui('UPDATE eventi SET descrizione_breve = ? WHERE id = ?', [$testo, $id]);
    }

    /** Ambiti dell'evento (chiavi separate da virgola). */
    public function impostaAmbiti(int $id, string $ambiti): void
    {
        $this->db->esegui('UPDATE eventi SET ambiti = ? WHERE id = ?', [$ambiti, $id]);
    }

    /** Archivia gli eventi dell'area senza più turni futuri (né iscrizioni ancora aperte): $ora = «AAAA-MM-GG HH:MM:SS». */
    public function archiviaConclusi(int $paginaId, string $ora): void
    {
        $this->db->esegui(
            'UPDATE eventi e SET archiviato = 1 WHERE pagina_id = ? AND NOT EXISTS (SELECT 1 FROM turni t WHERE t.evento_id = e.id AND (t.data_turno IS NULL OR t.data_turno >= CURDATE() OR (t.data_chiusura IS NOT NULL AND t.data_chiusura >= ?)))',
            [$paginaId, $ora]
        );
    }

    public function archivia(int $id): void
    {
        $this->db->esegui('UPDATE eventi SET archiviato = 1, blocca_auto_archivio = 0 WHERE id = ?', [$id]);
    }

    /**
     * Eventi normali (non progetti) non archiviati dell'area con la sezione, in ordine di pagina.
     *
     * @return list<array<string, string|null>>
     */
    public function normaliAdmin(int $paginaId, string $rbac): array
    {
        return Righe::testo($this->db->righe(
            "SELECT e.*, sc.nome as nome_sottocategoria FROM eventi e LEFT JOIN sottocategorie sc ON e.sottocategoria_id = sc.id
             WHERE e.pagina_id = ? AND e.archiviato = 0 AND IFNULL(e.tipo, 'evento') <> 'progetto' $rbac ORDER BY sc.ordine ASC, e.ordine ASC, e.id DESC",
            [$paginaId]
        ));
    }

    public function contaProgetti(int $paginaId, string $rbac): int
    {
        return (int) $this->db->valore("SELECT COUNT(*) AS n FROM eventi e WHERE e.pagina_id = ? AND e.archiviato = 0 AND e.tipo = 'progetto' $rbac", [$paginaId]);
    }

    public function contaNormali(int $paginaId): int
    {
        return (int) $this->db->valore("SELECT COUNT(*) AS n FROM eventi WHERE pagina_id = ? AND archiviato = 0 AND IFNULL(tipo, 'evento') <> 'progetto'", [$paginaId]);
    }

    /**
     * Progetti non archiviati dell'area in ordine di pagina.
     *
     * @return list<array<string, string|null>>
     */
    public function progettiAdmin(int $paginaId, string $rbac): array
    {
        return Righe::testo($this->db->righe(
            "SELECT e.* FROM eventi e WHERE e.pagina_id = ? AND e.archiviato = 0 AND e.tipo = 'progetto' $rbac ORDER BY e.ordine ASC, e.id DESC",
            [$paginaId]
        ));
    }

    /**
     * Posizione dei progetti nella pagina pubblica: l'ordine delle chiavi di $ids.
     *
     * @param list<int> $ids
     */
    public function salvaOrdineProgetti(int $paginaId, array $ids): void
    {
        foreach ($ids as $pos => $id) {
            $this->db->esegui("UPDATE eventi SET ordine = ? WHERE id = ? AND pagina_id = ? AND tipo = 'progetto'", [$pos + 1, $id, $paginaId]);
        }
    }

    /**
     * Nuovo progetto (iscrizione solo con accesso: ruolo_accesso_id = -1). Ritorna l'id; lancia RuntimeException se non riesce.
     *
     * @param array<string, mixed> $v
     */
    public function inserisciProgetto(int $paginaId, array $v): int
    {
        $id = $this->db->inserisci(
            "INSERT INTO eventi (pagina_id, sottocategoria_id, titolo, luogo, descrizione, locandina_path, allegato_pdf, is_evidenza, richiede_prenotazione, abilita_presenze, ruolo_accesso_id, ordine, gestori_utenti_ids, tipo, email_notifiche_extra)
             VALUES (?, NULL, ?, ?, ?, ?, ?, ?, 1, ?, -1, ?, '', 'progetto', ?)",
            [$paginaId, $v['titolo'], $v['luogo'], $v['desc'], (string) ($v['locandina'] ?? ''), $v['pdf'], (int) $v['evid'], (int) $v['attestati'], (int) $v['ord'], $v['notif_csv']]
        );
        if ($id <= 0) {
            throw new RuntimeException($this->db->ultimoErrore());
        }

        return $id;
    }

    /**
     * Modifica di un progetto; le presenze (check-in) si accendono se servono gli attestati. Lancia RuntimeException se non riesce.
     *
     * @param array<string, mixed> $v titolo, luogo, desc, evid, ord, notif_csv, attestati
     */
    public function aggiornaProgetto(int $id, array $v, bool $eliminaLocandina, bool $eliminaPdf, ?string $nuovaLocandina, ?string $nuovoPdf): void
    {
        $sql = 'UPDATE eventi SET titolo=?, luogo=?, descrizione=?, is_evidenza=?, ordine=?, email_notifiche_extra=?, richiede_prenotazione=1, ruolo_accesso_id=-1, abilita_presenze=IF(? = 1, 1, abilita_presenze)';
        $par = [$v['titolo'], $v['luogo'], $v['desc'], (int) $v['evid'], (int) $v['ord'], $v['notif_csv'], (int) $v['attestati']];
        if ($eliminaLocandina) {
            $sql .= ", locandina_path=''";
        }
        if ($eliminaPdf) {
            $sql .= ', allegato_pdf=NULL';
        }
        if ($nuovaLocandina !== null) {
            $sql .= ', locandina_path=?';
            $par[] = $nuovaLocandina;
        }
        if ($nuovoPdf !== null) {
            $sql .= ', allegato_pdf=?';
            $par[] = $nuovoPdf;
        }
        if ($this->db->esegui($sql . ' WHERE id=?', [...$par, $id]) < 0) {
            throw new RuntimeException($this->db->ultimoErrore());
        }
    }

    // ---- sezioni (sottocategorie) ----

    /** Sezione «affiancata in alto» nel layout Griglia (attiva/disattiva). */
    public function impostaSezioneInAlto(int $sezioneId, int $paginaId, int $valore): void
    {
        $this->db->esegui('UPDATE sottocategorie SET affiancata_in_alto = ? WHERE id = ? AND pagina_id = ?', [$valore, $sezioneId, $paginaId]);
    }

    public function creaSezione(int $paginaId, string $nome, int $ordine, int $affiancata): void
    {
        $this->db->esegui('INSERT INTO sottocategorie (pagina_id, nome, ordine, affiancata_in_alto) VALUES (?, ?, ?, ?)', [$paginaId, $nome, $ordine, $affiancata]);
    }

    // ---- edizioni dei progetti ----

    /** Persone in lista d'attesa del turno. */
    public function inAttesa(int $turnoId): int
    {
        return (int) $this->db->valore("SELECT COUNT(*) AS n FROM prenotazioni WHERE turno_id = ? AND stato = 'in_attesa'", [$turnoId]);
    }

    /** @return array<string, string|null>|null la prima prenotazione attiva del turno (a cui è assegnata l'edizione) */
    public function primaPrenotazioneAttiva(int $turnoId): ?array
    {
        return Righe::riga($this->db->riga(
            "SELECT id, nome, cognome, email, stato, presente, attestato_inviato, dati_custom_json FROM prenotazioni
             WHERE turno_id = ? AND IFNULL(stato, 'confermata') IN ('confermata', 'richiesta_conferma', 'da_approvare')
             ORDER BY data_prenotazione ASC, id ASC LIMIT 1",
            [$turnoId]
        ));
    }

    // ---- scheda dell'evento (progetti_dettagli) ----

    public function salvaReferenti(int $eventoId, ?string $json): void
    {
        $this->db->esegui(
            'INSERT INTO progetti_dettagli (evento_id, referenti_json, updated_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE referenti_json = VALUES(referenti_json), updated_at = NOW()',
            [$eventoId, $json]
        );
    }

    public function salvaInsegnamento(int $eventoId, ?int $insegnamentoId): void
    {
        $this->db->esegui(
            'INSERT INTO progetti_dettagli (evento_id, insegnamento_id, updated_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE insegnamento_id = VALUES(insegnamento_id), updated_at = NOW()',
            [$eventoId, $insegnamentoId]
        );
    }

    public function haScheda(int $eventoId): bool
    {
        return $this->db->riga('SELECT 1 FROM progetti_dettagli WHERE evento_id = ?', [$eventoId]) !== null;
    }

    /** @param list<string> $corsiCodici tutti i corsi scelti (corso_codice è il primo) */
    public function salvaCorso(int $eventoId, string $struttura, ?string $corsoCodice, array $corsiCodici = []): void
    {
        $this->db->esegui(
            'INSERT INTO progetti_dettagli (evento_id, struttura, corso_codice, corsi_codici, updated_at) VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE struttura = VALUES(struttura), corso_codice = VALUES(corso_codice), corsi_codici = VALUES(corsi_codici), updated_at = NOW()',
            [$eventoId, $struttura, $corsoCodice, $corsiCodici ? implode(',', $corsiCodici) : null]
        );
    }

    // ---- seminario ----

    /** Relatore, abstract e link del seminario (le slide si aggiornano a parte). */
    public function salvaSeminario(int $eventoId, string $relatore, string $ente, ?string $personaId, ?string $abstract, string $streaming, string $registrazione): void
    {
        $this->db->esegui(
            'UPDATE eventi SET relatore = ?, relatore_ente = ?, relatore_persona_id = ?, abstract = ?, link_streaming = ?, link_registrazione = ? WHERE id = ?',
            [$relatore, $ente, $personaId, $abstract, $streaming, $registrazione, $eventoId]
        );
    }

    public function impostaSlide(int $eventoId, ?string $percorso): void
    {
        $this->db->esegui('UPDATE eventi SET slide_pdf = ? WHERE id = ?', [$percorso, $eventoId]);
    }

    // ---- eliminazione ----

    public function eliminaTurno(int $turnoId): void
    {
        $this->db->esegui('DELETE m FROM messaggi_prenotazioni m JOIN prenotazioni pr ON m.prenotazione_id = pr.id WHERE pr.turno_id = ?', [$turnoId]);
        $this->db->esegui('DELETE pp FROM partecipanti_prenotazione pp JOIN prenotazioni pr ON pp.prenotazione_id = pr.id WHERE pr.turno_id = ?', [$turnoId]);
        $this->db->esegui('DELETE FROM prenotazioni WHERE turno_id = ?', [$turnoId]);
        // Aula occupata dal turno (Prenotazioni e risorse): torna libera
        $this->db->esegui("UPDATE prenotazioni_risorse SET stato = 'annullata' WHERE turno_id = ? AND stato IN ('confermata', 'da_approvare')", [$turnoId]);
        $this->db->esegui('DELETE FROM turni WHERE id = ?', [$turnoId]);
    }

    /** Sondaggi dell'evento con domande e risposte, campi del form e scheda del progetto; poi l'evento stesso. Ritorna false se l'ultimo DELETE non riesce. */
    public function eliminaDatiEvento(int $eventoId): bool
    {
        $this->db->esegui('DELETE r FROM sondaggi_risposte r JOIN sondaggi s ON r.sondaggio_id = s.id WHERE s.evento_id = ?', [$eventoId]);
        $this->db->esegui('DELETE d FROM sondaggi_domande d JOIN sondaggi s ON d.sondaggio_id = s.id WHERE s.evento_id = ?', [$eventoId]);
        $this->db->esegui('DELETE FROM sondaggi WHERE evento_id = ?', [$eventoId]);

        return true;
    }

    public function eliminaCampiEProgetto(int $eventoId): void
    {
        $this->db->esegui('DELETE FROM campi_form WHERE evento_id = ?', [$eventoId]);
        $this->db->esegui('DELETE FROM progetti_dettagli WHERE evento_id = ?', [$eventoId]);
    }

    public function eliminaEvento(int $eventoId): bool
    {
        return $this->db->esegui('DELETE FROM eventi WHERE id = ?', [$eventoId]) >= 0;
    }

    public function messaggioErrore(): string
    {
        return $this->db->mysqli()->error;
    }
}
