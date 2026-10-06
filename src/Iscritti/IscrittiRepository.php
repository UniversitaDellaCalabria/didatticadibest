<?php

declare(strict_types=1);

namespace App\Iscritti;

use App\Core\Database;
use App\Eventi\Righe;

/**
 * Query del pannello degli iscritti (prenotazioni, turni, eventi): elenco filtrato, aggiornamenti di stato e presenza,
 * inserimento e modifica manuale, esportazioni. Le righe che prima uscivano da $conn->query() restano stringhe (Righe).
 */
final class IscrittiRepository
{
    private const STATI_ATTIVI = "'confermata', 'in_attesa', 'da_approvare', 'richiesta_conferma'";

    public function __construct(private Database $db)
    {
    }

    // ------------------------------------------------------------------ letture singole (ex inc/dati.php)

    /**
     * Prenotazione con i dati del turno e dell'evento, come get_prenotazione_con_turno_evento().
     *
     * @return array<string, string|null>|null
     */
    public function prenotazioneConTurnoEvento(int $prenotazioneId): ?array
    {
        return Righe::riga($this->db->riga(
            'SELECT pr.*, t.id as turno_id, t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine,
                    e.titolo as evento_titolo, e.luogo
             FROM prenotazioni pr
             JOIN turni t ON pr.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             WHERE pr.id = ? LIMIT 1',
            [$prenotazioneId]
        ));
    }

    /**
     * Turno con il titolo dell'evento e lo slug dell'area, come get_turno_admin().
     *
     * @return array<string, string|null>|null
     */
    public function turnoAdmin(int $turnoId): ?array
    {
        return Righe::riga($this->db->riga(
            'SELECT t.*, e.titolo as evento_titolo, pe.slug
             FROM turni t
             JOIN eventi e ON t.evento_id = e.id
             LEFT JOIN pagine_eventi pe ON e.pagina_id = pe.id
             WHERE t.id = ? LIMIT 1',
            [$turnoId]
        ));
    }

    /**
     * Email, nome e cognome delle prenotazioni confermate dell'area (o di un turno), come get_destinatari_email_massiva().
     *
     * @return list<array<string, string|null>>
     */
    public function destinatariEmailMassiva(int $paginaId, int $turnoId = 0): array
    {
        $cond = $turnoId > 0 ? ' AND t.id = ?' : '';
        $par = $turnoId > 0 ? [$paginaId, $turnoId] : [$paginaId];

        return Righe::testo($this->db->righe(
            "SELECT pr.email, pr.nome, pr.cognome
             FROM prenotazioni pr
             JOIN turni t ON pr.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             WHERE e.pagina_id = ?
               AND IFNULL(pr.stato, 'confermata') = 'confermata'
               $cond
               AND pr.email != ''",
            $par
        ));
    }

    /**
     * Campi personalizzati dei moduli dell'area (nome_campo => etichetta) per le colonne dell'esportazione, come get_campi_custom_export().
     *
     * @return array<string, string|null>
     */
    public function campiCustomExport(int $paginaId): array
    {
        $cols = [];
        foreach (Righe::testo($this->db->righe(
            'SELECT DISTINCT nome_campo, etichetta FROM campi_form
             WHERE pagina_id = ? OR evento_id IN (SELECT id FROM eventi WHERE pagina_id = ?)
             ORDER BY id ASC',
            [$paginaId, $paginaId]
        )) as $cf) {
            $cols[$cf['nome_campo']] = $cf['etichetta'];
        }

        return $cols;
    }

    /**
     * Prenotazione con i permessi dei gestori dell'evento e dell'area per il check-in, come get_prenotazione_per_checkin_admin().
     *
     * @return array<string, mixed>|null
     */
    public function prenotazionePerCheckinAdmin(string $codice): ?array
    {
        return $this->db->riga(
            'SELECT pr.id, pr.stato, pr.presente, pr.nome, pr.cognome,
               e.id AS evento_id, e.titolo as evento_titolo,
               e.gestori_utenti_ids as ev_gestori,
               e.permessi_gestori_json as ev_permessi_json,
               pe.gestore_utente_id as pg_gestore_singolo,
               pe.gestori_utenti_ids as pg_gestori,
               pe.permessi_gestori_json as pg_permessi_json
             FROM prenotazioni pr
             JOIN turni t ON pr.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             LEFT JOIN pagine_eventi pe ON e.pagina_id = pe.id
             WHERE pr.codice_prenotazione = ? LIMIT 1',
            [$codice]
        );
    }

    /**
     * Utenti registrati per nome, cognome, email o matricola (al massimo 20), per la prenotazione manuale.
     *
     * @return list<array{id: mixed, nome: mixed, cognome: mixed, email: mixed, matricola: mixed}>
     */
    public function cercaUtenti(string $testo): array
    {
        $q = '%' . trim($testo) . '%';
        $out = [];
        foreach ($this->db->righe(
            "SELECT id, nome, cognome, email, COALESCE(NULLIF(matricola_studente,''), NULLIF(matricola_dipendente,''), NULLIF(matricola,''), '') AS matricola FROM utenti WHERE (nome LIKE ? OR cognome LIKE ? OR email LIKE ? OR matricola_studente LIKE ? OR matricola_dipendente LIKE ? OR matricola LIKE ?) ORDER BY cognome, nome LIMIT 20",
            [$q, $q, $q, $q, $q, $q]
        ) as $row) {
            $out[] = ['id' => $row['id'], 'nome' => $row['nome'], 'cognome' => $row['cognome'], 'email' => $row['email'], 'matricola' => $row['matricola']];
        }

        return $out;
    }

    /** Stato attuale della prenotazione ('' se non esiste). */
    public function stato(int $prenotazioneId): string
    {
        return (string) ($this->db->valore('SELECT stato FROM prenotazioni WHERE id = ?', [$prenotazioneId]) ?? '');
    }

    /** JSON dei dati del modulo salvati ('' se vuoto o se la prenotazione non esiste). */
    public function datiCustomJson(int $prenotazioneId): string
    {
        return (string) ($this->db->valore('SELECT dati_custom_json FROM prenotazioni WHERE id = ?', [$prenotazioneId]) ?? '');
    }

    // ------------------------------------------------------------------ elenco filtrato

    public function conta(FiltriIscritti $filtri): int
    {
        [$where, $par] = $filtri->where();

        return (int) $this->db->valore(
            "SELECT COUNT(*) as tot FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id LEFT JOIN utenti u ON pr.utente_id = u.id $where",
            $par
        );
    }

    /**
     * Una pagina dell'elenco, dalla più recente.
     *
     * @return list<array<string, string|null>>
     */
    public function elenco(FiltriIscritti $filtri, int $perPagina, int $offset): array
    {
        [$where, $par] = $filtri->where();
        $par[] = $perPagina;
        $par[] = $offset;

        return Righe::testo($this->db->righe(
            "SELECT pr.*, COALESCE(NULLIF(pr.matricola, ''), u.matricola_studente, u.matricola_dipendente, u.matricola) as matricola_effettiva,
                    t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine, t.evento_id, e.titolo as evento_titolo, e.luogo as evento_luogo, e.abilita_presenze,
                    e.tipo AS evento_tipo, pd.per_scuole, pd.attestati AS progetto_attestati, pd.data_inizio AS pd_inizio, pd.data_fine AS pd_fine,
                    (SELECT COUNT(*) FROM partecipanti_prenotazione pp WHERE pp.prenotazione_id = pr.id) AS n_studenti
             FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id LEFT JOIN utenti u ON pr.utente_id = u.id LEFT JOIN progetti_dettagli pd ON pd.evento_id = e.id
             $where ORDER BY pr.data_prenotazione DESC LIMIT ? OFFSET ?",
            $par
        ));
    }

    /** Iscrizioni dei filtri che non hanno ancora la convenzione (conteggio per l'avviso sopra la tabella). */
    public function contaSenzaConvenzione(FiltriIscritti $filtri): int
    {
        [$where, $par] = $filtri->where();

        return (int) $this->db->valore(
            "SELECT COUNT(*) AS n FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
             LEFT JOIN progetti_dettagli pd ON pd.evento_id = e.id
             $where AND IFNULL(pr.stato, 'confermata') IN (" . self::STATI_ATTIVI . ")
               AND (pr.convenzione = 'no' OR (pr.convenzione IS NULL AND pd.convenzione = 1))",
            $par
        );
    }

    /**
     * Iscrizioni dei filtri senza convenzione, con il periodo dell'attività.
     *
     * @return list<array<string, string|null>>
     */
    public function senzaConvenzione(FiltriIscritti $filtri): array
    {
        [$where, $par] = $filtri->where();

        return Righe::testo($this->db->righe(
            "SELECT pr.id, pr.scuola_codice, t.data_turno, pd.data_inizio AS pd_inizio, pd.data_fine AS pd_fine FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
             LEFT JOIN progetti_dettagli pd ON pd.evento_id = e.id
             $where AND IFNULL(pr.stato, 'confermata') IN (" . self::STATI_ATTIVI . ")
               AND (pr.convenzione = 'no' OR (pr.convenzione IS NULL AND pd.convenzione = 1))",
            $par
        ));
    }

    /**
     * Righe per la stampa dell'elenco (al massimo 2000), nell'ordine di evento, turno e cognome.
     *
     * @return list<array<string, string|null>>
     */
    public function perStampa(FiltriIscritti $filtri): array
    {
        [$where, $par] = $filtri->where();

        return Righe::testo($this->db->righe(
            "SELECT pr.codice_prenotazione, IFNULL(pr.stato, 'confermata') as stato, pr.presente,
                    pr.nome, pr.cognome, pr.email,
                    COALESCE(NULLIF(pr.matricola,''), u.matricola_studente, u.matricola_dipendente) as matricola,
                    pr.num_posti, t.nome_turno, t.data_turno, t.orario_inizio, e.titolo as evento_titolo,
                    pr.data_prenotazione
             FROM prenotazioni pr
             JOIN turni t ON pr.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             LEFT JOIN utenti u ON pr.utente_id = u.id
             $where
             ORDER BY e.titolo ASC, (t.data_turno IS NULL), t.data_turno ASC, t.nome_turno ASC, pr.cognome ASC
             LIMIT 2000",
            $par
        ));
    }

    // ------------------------------------------------------------------ esportazione

    /** L'area ha almeno una prenotazione con un elenco di studenti? (colonne in più nell'esportazione) */
    public function areaConStudenti(int $paginaId): bool
    {
        return $this->db->riga(
            'SELECT 1 FROM partecipanti_prenotazione pp JOIN prenotazioni pr ON pp.prenotazione_id = pr.id JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id WHERE e.pagina_id = ? LIMIT 1',
            [$paginaId]
        ) !== null;
    }

    /**
     * Righe da esportare (tutte le prenotazioni dell'area, per turno e stato), con l'elenco degli studenti in una colonna.
     *
     * @return list<array<string, string|null>>
     */
    public function perEsportazione(int $paginaId, int $turnoId, string $stato): array
    {
        $this->db->comando('SET SESSION group_concat_max_len = 100000');
        $cond = '';
        $par = [$paginaId];
        if ($turnoId > 0) {
            $cond .= ' AND t.id = ?';
            $par[] = $turnoId;
        }
        if ($stato !== '') {
            $cond .= " AND IFNULL(pr.stato, 'confermata') = ?";
            $par[] = $stato;
        }

        return Righe::testo($this->db->righe(
            "SELECT (SELECT GROUP_CONCAT(TRIM(CONCAT(pp.cognome, ' ', pp.nome)) ORDER BY pp.ordine, pp.id SEPARATOR '; ') FROM partecipanti_prenotazione pp WHERE pp.prenotazione_id = pr.id) AS studenti,
                    (SELECT COUNT(*) FROM partecipanti_prenotazione pp2 WHERE pp2.prenotazione_id = pr.id) AS n_studenti,
                    pr.codice_prenotazione, pr.presente, IFNULL(pr.stato, 'confermata') as stato, COALESCE(pr.num_posti, 1) as num_posti, pr.nome, pr.cognome, COALESCE(NULLIF(pr.matricola, ''), u.matricola_studente, u.matricola_dipendente, u.matricola, '') as matricola_effettiva, pr.email, e.titolo as evento, t.nome_turno, t.data_turno, t.orario_inizio, pr.dati_custom_json, pr.data_prenotazione
             FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id LEFT JOIN utenti u ON pr.utente_id = u.id
             WHERE e.pagina_id = ? $cond ORDER BY (t.data_turno IS NULL), t.data_turno ASC, t.nome_turno ASC, pr.data_prenotazione DESC",
            $par
        ));
    }

    // ------------------------------------------------------------------ presenza, stato, convenzione

    /** Imposta la presenza (qualunque valore intero, come fa il pulsante dell'elenco). */
    public function impostaPresenza(int $prenotazioneId, int $valore): void
    {
        $this->db->esegui('UPDATE prenotazioni SET presente = ? WHERE id = ?', [$valore, $prenotazioneId]);
    }

    /** Segna presente (con l'ora) solo chi non lo è già; ritorna le righe cambiate. */
    public function segnaPresente(int $prenotazioneId): int
    {
        return $this->db->esegui('UPDATE prenotazioni SET presente = 1, data_presenza = NOW() WHERE id = ? AND presente = 0', [$prenotazioneId]);
    }

    /** Annulla la presenza (correzione di un errore di check-in). */
    public function annullaPresenza(int $prenotazioneId): void
    {
        $this->db->esegui('UPDATE prenotazioni SET presente = 0, data_presenza = NULL WHERE id = ?', [$prenotazioneId]);
    }

    /** Passa a "confermata" solo se lo stato è ancora $da; ritorna le righe cambiate. */
    public function confermaDaStato(int $prenotazioneId, string $da): int
    {
        return $this->db->esegui("UPDATE prenotazioni SET stato = 'confermata' WHERE id = ? AND stato = ?", [$prenotazioneId, $da]);
    }

    public function conferma(int $prenotazioneId): void
    {
        $this->db->esegui("UPDATE prenotazioni SET stato = 'confermata' WHERE id = ?", [$prenotazioneId]);
    }

    public function rifiuta(int $prenotazioneId): void
    {
        $this->db->esegui("UPDATE prenotazioni SET stato = 'rifiutata' WHERE id = ?", [$prenotazioneId]);
    }

    public function annulla(int $prenotazioneId): void
    {
        $this->db->esegui("UPDATE prenotazioni SET stato = 'annullata' WHERE id = ?", [$prenotazioneId]);
    }

    /**
     * Annulla con la stessa query testuale di prima, per riportare l'errore del database nel messaggio all'amministratore.
     *
     * @return array{ok: bool, errno: int, errore: string}
     */
    public function annullaConErrore(int $prenotazioneId): array
    {
        $ok = $this->db->comando("UPDATE prenotazioni SET stato = 'annullata' WHERE id = $prenotazioneId");
        $conn = $this->db->mysqli();   // serve solo il codice dell'errore, che Database non espone

        return ['ok' => $ok !== false && $conn->affected_rows > 0, 'errno' => (int) $conn->errno, 'errore' => $this->db->ultimoErrore()];
    }

    /** Segnata "convenzione da stipulare" dopo l'invio della richiesta (promemoria da capo); $soloNonRicevuta lascia stare chi l'ha già consegnata. */
    public function convenzioneRichiesta(int $prenotazioneId, bool $soloNonRicevuta): void
    {
        $this->db->esegui(
            "UPDATE prenotazioni SET convenzione = 'no', conv_promemoria = 0, conv_promemoria_il = NOW() WHERE id = ?"
            . ($soloNonRicevuta ? " AND IFNULL(convenzione, '') <> 'ricevuta'" : ''),
            [$prenotazioneId]
        );
    }

    /** Elimina la prenotazione con i suoi studenti e messaggi. */
    public function elimina(int $prenotazioneId): void
    {
        $this->db->esegui('DELETE FROM partecipanti_prenotazione WHERE prenotazione_id = ?', [$prenotazioneId]);
        $this->db->esegui('DELETE FROM messaggi_prenotazioni WHERE prenotazione_id = ?', [$prenotazioneId]);
        $this->db->esegui('DELETE FROM prenotazioni WHERE id = ?', [$prenotazioneId]);
    }

    // ------------------------------------------------------------------ prenotazione manuale e modifica

    /**
     * Dati del turno e tipo dell'evento per le regole dei progetti.
     *
     * @return array<string, string|null>|null
     */
    public function turnoConTipoEvento(int $turnoId): ?array
    {
        return Righe::riga($this->db->riga(
            'SELECT t.evento_id, t.max_posti, t.abilita_lista_attesa, t.min_partecipanti, t.max_partecipanti, e.tipo FROM turni t JOIN eventi e ON t.evento_id = e.id WHERE t.id = ?',
            [$turnoId]
        ));
    }

    /** L'email è già iscritta (o in attesa) a un'edizione dell'evento? */
    public function emailGiaIscrittaAEvento(int $eventoId, string $emailMinuscola): bool
    {
        return $this->db->riga(
            "SELECT 1 FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id WHERE t.evento_id = ? AND IFNULL(pr.stato, 'confermata') NOT IN ('annullata', 'rifiutata', 'scaduta') AND LOWER(pr.email) = ? LIMIT 1",
            [$eventoId, $emailMinuscola]
        ) !== null;
    }

    /** Inserisce la prenotazione inserita dalla segreteria; ritorna l'id (0 se l'inserimento non riesce). */
    public function inserisciManuale(int $turnoId, string $codice, string $stato, int $numPosti, string $nome, string $cognome, string $email, string $matricola, ?string $datiCustomJson): int
    {
        return $this->db->inserisci(
            'INSERT INTO prenotazioni (turno_id, codice_prenotazione, stato, num_posti, nome, cognome, email, matricola, dati_custom_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$turnoId, $codice, $stato, $numPosti, $nome, $cognome, $email, $matricola, $datiCustomJson]
        );
    }

    public function impostaScuola(int $prenotazioneId, ?string $codiceScuola): void
    {
        $this->db->esegui('UPDATE prenotazioni SET scuola_codice = ? WHERE id = ?', [$codiceScuola, $prenotazioneId]);
    }

    public function aggiornaDati(int $prenotazioneId, int $turnoId, string $nome, string $cognome, string $email, string $matricola, ?string $datiCustomJson): void
    {
        $this->db->esegui(
            'UPDATE prenotazioni SET turno_id=?, nome=?, cognome=?, email=?, matricola=?, dati_custom_json=? WHERE id=?',
            [$turnoId, $nome, $cognome, $email, $matricola, $datiCustomJson, $prenotazioneId]
        );
    }
}
