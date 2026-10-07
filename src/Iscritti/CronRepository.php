<?php

declare(strict_types=1);

namespace App\Iscritti;

use App\Core\Database;
use App\Eventi\Righe;

/**
 * Query dei cron degli iscritti (cron_background.php e admin/cron_reminders.php): promemoria, email dopo l'evento, convenzioni,
 * conservazione dei dati. Le tabelle opzionali (convenzioni, risorse, abilitazioni) possono mancare nelle installazioni vecchie:
 * in quel caso le letture danno vuoto, come prima (che le silenziava con @).
 */
final class CronRepository
{
    private const STATI_CONVENZIONE = "'confermata', 'in_attesa', 'da_approvare', 'richiesta_conferma'";

    public function __construct(private Database $db)
    {
    }

    // ------------------------------------------------------------------ cron_reminders.php

    /**
     * Prenotazioni confermate con turno nelle prossime 72 ore e promemoria non ancora inviato.
     *
     * @return list<array<string, string|null>>
     */
    public function daPromemoria(): array
    {
        return Righe::testo($this->db->righe(
            "SELECT pr.*, t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine, e.titolo as evento_titolo, e.luogo
             FROM prenotazioni pr
             JOIN turni t ON pr.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             WHERE (pr.stato = 'confermata' OR pr.stato IS NULL)
               AND pr.reminder_inviato = 0
               AND CONCAT(t.data_turno, ' ', COALESCE(t.orario_inizio, '00:00:00')) BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 72 HOUR)"
        ));
    }

    public function segnaPromemoriaInviato(int $prenotazioneId): void
    {
        $this->db->esegui('UPDATE prenotazioni SET reminder_inviato = 1 WHERE id = ?', [$prenotazioneId]);
    }

    // ------------------------------------------------------------------ cron_background.php: eventi e dopo l'evento

    /** Archivia gli eventi con tutti i turni passati (salvo quelli bloccati o con turni senza data); ritorna quanti. */
    public function archiviaScaduti(): int
    {
        return $this->db->esegui('UPDATE eventi e SET e.archiviato = 1 WHERE e.archiviato = 0 AND (e.blocca_auto_archivio IS NULL OR e.blocca_auto_archivio = 0) AND (SELECT MAX(data_turno) FROM turni t WHERE t.evento_id = e.id) < CURDATE() AND NOT EXISTS (SELECT 1 FROM turni t2 WHERE t2.evento_id = e.id AND t2.data_turno IS NULL)');
    }

    /**
     * Presenti a eventi finiti a cui non è ancora stata mandata l'email di attestato e sondaggio (progetti: solo a progetto concluso).
     *
     * @return list<array<string, string|null>>
     */
    public function daEmailPostEvento(string $adesso): array
    {
        return Righe::testo($this->db->righe(
            "SELECT pr.*, t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine, t.evento_id, e.titolo as evento_titolo, e.luogo
             FROM prenotazioni pr
             JOIN turni t ON pr.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             LEFT JOIN progetti_dettagli pd ON pd.evento_id = e.id
             WHERE pr.presente = 1
             AND pr.email_post_evento_inviata = 0
             AND (t.data_turno IS NULL OR CONCAT(t.data_turno, ' ', COALESCE(t.orario_fine, '23:59:59')) < ?)
             AND NOT (IFNULL(e.tipo, 'evento') = 'progetto' AND pd.data_fine IS NOT NULL AND pd.data_fine >= CURDATE())",
            [$adesso]
        ));
    }

    public function segnaPostEventoInviata(int $prenotazioneId): void
    {
        $this->db->esegui('UPDATE prenotazioni SET email_post_evento_inviata = 1 WHERE id = ?', [$prenotazioneId]);
    }

    /**
     * Classi con attestati senza elenco di studenti: progetti per le scuole a 7 giorni dalla fine, eventi da 3 giorni prima a 14 dopo.
     *
     * @return list<array<string, string|null>>
     */
    public function daPromemoriaElenco(): array
    {
        return Righe::testo($this->db->righe(
            "SELECT pr.id, pr.nome, pr.cognome, pr.email, pr.codice_prenotazione, pr.turno_id, e.titolo, e.tipo, pd.data_fine, t.data_turno
             FROM prenotazioni pr
             JOIN turni t ON pr.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             JOIN progetti_dettagli pd ON pd.evento_id = e.id
             WHERE e.archiviato = 0 AND pd.attestati = 1
               AND IFNULL(pr.stato, 'confermata') = 'confermata' AND pr.promemoria_elenco_inviato = 0
               AND ((e.tipo = 'progetto' AND pd.per_scuole = 1 AND pd.data_fine BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY)
                 OR (IFNULL(e.tipo, 'evento') <> 'progetto' AND t.data_turno BETWEEN CURDATE() - INTERVAL 14 DAY AND CURDATE() + INTERVAL 3 DAY))
               AND NOT EXISTS (SELECT 1 FROM partecipanti_prenotazione pp WHERE pp.prenotazione_id = pr.id)"
        ));
    }

    public function segnaPromemoriaElencoInviato(int $prenotazioneId): void
    {
        $this->db->esegui('UPDATE prenotazioni SET promemoria_elenco_inviato = 1 WHERE id = ?', [$prenotazioneId]);
    }

    /**
     * Classi con attestati a attività conclusa, presenti e con gli attestati non ancora inviati.
     *
     * @return list<int>
     */
    public function classiDaAttestare(): array
    {
        return array_map(static fn (array $r): int => (int) $r['id'], $this->db->righe(
            "SELECT pr.id FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
             JOIN progetti_dettagli pd ON pd.evento_id = e.id
             WHERE pd.attestati = 1
               AND ((e.tipo = 'progetto' AND pd.per_scuole = 1 AND pd.data_fine < CURDATE())
                 OR (IFNULL(e.tipo, 'evento') <> 'progetto' AND (t.data_turno IS NULL OR t.data_turno < CURDATE())))
               AND pr.presente = 1 AND IFNULL(pr.stato, 'confermata') = 'confermata' AND pr.attestato_inviato = 0"
        ));
    }

    // ------------------------------------------------------------------ cron_background.php: convenzioni e valutazioni FSL

    /**
     * Iscrizioni senza convenzione per cui è ora di un promemoria (ogni 7 giorni, al massimo 3, fino alla fine dell'attività).
     *
     * @return list<array<string, string|null>>
     */
    public function convenzioniDaSollecitare(): array
    {
        return Righe::testo($this->db->righe(
            "SELECT pr.id, pr.scuola_codice, t.data_turno, pd.data_inizio AS pd_inizio, pd.data_fine AS pd_fine FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
             LEFT JOIN progetti_dettagli pd ON pd.evento_id = e.id
             WHERE e.archiviato = 0 AND pr.convenzione = 'no' AND IFNULL(pr.stato, 'confermata') IN (" . self::STATI_CONVENZIONE . ")
               AND pr.conv_promemoria < 3 AND COALESCE(pr.conv_promemoria_il, pr.data_prenotazione) <= NOW() - INTERVAL 7 DAY
               AND (COALESCE(pd.data_fine, t.data_turno) IS NULL OR COALESCE(pd.data_fine, t.data_turno) >= CURDATE())"
        ));
    }

    public function contaPromemoriaConvenzione(int $prenotazioneId): void
    {
        $this->db->esegui('UPDATE prenotazioni SET conv_promemoria = conv_promemoria + 1, conv_promemoria_il = NOW() WHERE id = ?', [$prenotazioneId]);
    }

    /**
     * Iscrizioni senza convenzione di attività che iniziano entro 7 giorni, per avvisare i gestori (una volta).
     *
     * @return list<array<string, string|null>>
     */
    public function convenzioniMancantiInPartenza(): array
    {
        return Righe::testo($this->db->righe(
            "SELECT pr.id, pr.nome, pr.cognome, pr.email, pr.codice_prenotazione, pr.stato, pr.dati_custom_json, pr.scuola_codice,
                    t.evento_id, t.nome_turno, t.data_turno, e.titolo, e.pagina_id, COALESCE(pd.data_inizio, t.data_turno) AS inizio
             FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
             LEFT JOIN progetti_dettagli pd ON pd.evento_id = e.id
             WHERE e.archiviato = 0 AND pr.convenzione = 'no' AND pr.conv_avviso_gestori = 0 AND IFNULL(pr.stato, 'confermata') IN (" . self::STATI_CONVENZIONE . ")
               AND COALESCE(pd.data_inizio, t.data_turno) BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY
             ORDER BY t.evento_id, inizio"
        ));
    }

    /** @param list<int> $ids */
    public function segnaAvvisoGestori(array $ids): void
    {
        $this->db->esegui('UPDATE prenotazioni SET conv_avviso_gestori = 1 WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
    }

    /**
     * Convenzioni del registro che scadono entro 60 giorni senza un rinnovo già registrato per la stessa scuola.
     *
     * @return list<array<string, string|null>>
     */
    public function convenzioniInScadenza(): array
    {
        return Righe::testo($this->db->righe(
            'SELECT c.* FROM convenzioni_scuole c
             WHERE c.avviso_scadenza_inviato = 0 AND c.scadenza BETWEEN CURDATE() AND CURDATE() + INTERVAL 60 DAY
               AND NOT EXISTS (SELECT 1 FROM convenzioni_scuole c2 WHERE c2.scuola_codice = c.scuola_codice AND c2.id <> c.id
                               AND (c2.scadenza IS NULL OR c2.scadenza > c.scadenza))
             ORDER BY c.scadenza'
        ));
    }

    /** @param list<int> $ids */
    public function segnaAvvisoScadenza(array $ids): void
    {
        $this->db->esegui('UPDATE convenzioni_scuole SET avviso_scadenza_inviato = 1 WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
    }

    /**
     * Attività FSL concluse da non più di 30 giorni, con la presenza registrata, senza invito alla scheda di valutazione.
     *
     * @return list<int>
     */
    public function daInvitoValutazione(): array
    {
        return array_map(static fn (array $r): int => (int) $r['id'], $this->db->righe(
            "SELECT pr.id FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
             JOIN progetti_dettagli pd ON pd.evento_id = e.id
             WHERE pd.convenzione = 1 AND IFNULL(pr.stato, 'confermata') = 'confermata' AND pr.presente = 1 AND pr.valutazione_inviata IS NULL
               AND COALESCE(IF(e.tipo = 'progetto', pd.data_fine, t.data_turno), t.data_turno) < CURDATE()
               AND COALESCE(IF(e.tipo = 'progetto', pd.data_fine, t.data_turno), t.data_turno) >= CURDATE() - INTERVAL 30 DAY"
        ));
    }

    /**
     * Inviti alla scheda di valutazione fatti da più di 7 giorni e ancora senza risposta né promemoria.
     *
     * @return list<int>
     */
    public function daPromemoriaValutazione(): array
    {
        return array_map(static fn (array $r): int => (int) $r['id'], $this->db->righe(
            "SELECT pr.id FROM prenotazioni pr LEFT JOIN valutazioni_fsl v ON v.prenotazione_id = pr.id
             WHERE pr.valutazione_inviata IS NOT NULL AND pr.valutazione_inviata <= NOW() - INTERVAL 7 DAY
               AND pr.valutazione_promemoria = 0 AND v.id IS NULL AND IFNULL(pr.stato, 'confermata') = 'confermata'"
        ));
    }

    // ------------------------------------------------------------------ cron_background.php: conservazione dei dati

    /** Cancella gli elenchi di studenti delle iscrizioni annullate, rifiutate o scadute senza attestati emessi; ritorna le righe cancellate. */
    public function cancellaElenchiAnnullati(): int
    {
        return $this->db->esegui("DELETE pp FROM partecipanti_prenotazione pp JOIN prenotazioni pr ON pp.prenotazione_id = pr.id
              WHERE pr.stato IN ('annullata', 'rifiutata', 'scaduta') AND pp.codice IS NULL");
    }

    /** Riduce alle iniziali i nomi degli studenti dopo $mesi dalla fine del progetto o dell'evento; ritorna le righe cambiate. */
    public function anonimizzaStudenti(int $mesi): int
    {
        return $this->db->esegui(
            "UPDATE partecipanti_prenotazione pp
              JOIN prenotazioni pr ON pp.prenotazione_id = pr.id
              JOIN turni t ON pr.turno_id = t.id
              LEFT JOIN progetti_dettagli pd ON pd.evento_id = t.evento_id
              SET pp.cognome = CONCAT(LEFT(pp.cognome, 1), '.'),
                  pp.nome = IF(pp.nome = '', '', CONCAT(LEFT(pp.nome, 1), '.')),
                  pp.anonimizzato = 1
              WHERE pp.anonimizzato = 0 AND COALESCE(pd.data_fine, t.data_turno, DATE(pp.created_at)) < CURDATE() - INTERVAL ? MONTH",
            [$mesi]
        );
    }

    /** Elimina gli accessi più vecchi di $mesi mesi; ritorna quanti. */
    public function eliminaAccessiVecchi(int $mesi): int
    {
        return max(0, $this->db->esegui('DELETE FROM log_accessi WHERE created_at < NOW() - INTERVAL ? MONTH', [$mesi]));
    }

    public function eliminaEmailVecchie(int $mesi): int
    {
        return max(0, $this->db->esegui('DELETE FROM log_email WHERE created_at < NOW() - INTERVAL ? MONTH', [$mesi]));
    }

    public function eliminaAzioniVecchie(int $mesi): int
    {
        return max(0, $this->db->esegui('DELETE FROM log_attivita WHERE data_ora < NOW() - INTERVAL ? MONTH', [$mesi]));
    }

    /**
     * Prenotazioni con email concluse da più di $mesi mesi, ancora da anonimizzare (al massimo 500 per volta).
     *
     * @return list<array<string, string|null>>
     */
    public function daAnonimizzare(int $mesi): array
    {
        return Righe::testo($this->db->righe(
            "SELECT pr.id, pr.dati_custom_json, pr.autorizzazione_file FROM prenotazioni pr
             JOIN turni t ON pr.turno_id = t.id LEFT JOIN progetti_dettagli pd ON pd.evento_id = t.evento_id
             WHERE IFNULL(pr.email, '') <> ''
               AND COALESCE(pd.data_fine, t.data_turno, DATE(pr.data_prenotazione)) < CURDATE() - INTERVAL ? MONTH
             LIMIT 500",
            [$mesi]
        ));
    }

    /** Anonimizza la prenotazione: nome e cognome alle iniziali, email, matricola, dati del modulo e collegamento all'utente tolti; messaggi eliminati. */
    public function anonimizza(int $prenotazioneId): void
    {
        $this->db->esegui('DELETE FROM messaggi_prenotazioni WHERE prenotazione_id = ?', [$prenotazioneId]);
        $this->db->esegui(
            "UPDATE prenotazioni SET nome = CONCAT(LEFT(nome, 1), '.'), cognome = IF(cognome = '', '', CONCAT(LEFT(cognome, 1), '.')),
                      email = '', matricola = '', dati_custom_json = NULL, utente_id = NULL,
                      autorizzazione_file = NULL, autorizzazione_nome = NULL WHERE id = ?",
            [$prenotazioneId]
        );
    }

    /**
     * Gestori di aree o di eventi (singolo, CSV e JSON dei permessi): chi li ha non va eliminato come account inattivo.
     *
     * @return list<array<string, mixed>>
     */
    public function gestoriDelleAree(): array
    {
        return $this->db->righe('SELECT gestore_utente_id AS singolo, gestori_utenti_ids, permessi_gestori_json FROM pagine_eventi');
    }

    /** @return list<array<string, mixed>> */
    public function gestoriDegliEventi(): array
    {
        return $this->db->righe("SELECT '0' AS singolo, gestori_utenti_ids, permessi_gestori_json FROM eventi");
    }

    /** @return list<int> utenti con un perimetro (tutti i progetti o eventi di un'area, Formazione Scuola Lavoro) */
    public function utentiConPerimetro(): array
    {
        return array_map(static fn (array $r): int => (int) $r['utente_id'], $this->db->righe('SELECT DISTINCT utente_id FROM abilitazioni_ambito'));
    }

    /**
     * Elimina gli account senza accesso da $mesi mesi (esclusi amministratori, gestori di area e chi è in $esclusi); le prenotazioni
     * restano, senza il legame con l'account. Ritorna quanti account.
     *
     * @param list<int> $esclusi
     */
    public function eliminaUtentiInattivi(int $mesi, array $esclusi): int
    {
        $cond = "ruolo_id NOT IN (1, 2) AND FIND_IN_SET('1', IFNULL(ruoli_secondari, '')) = 0 AND FIND_IN_SET('2', IFNULL(ruoli_secondari, '')) = 0
                AND COALESCE(ultimo_accesso, '1970-01-01') < NOW() - INTERVAL ? MONTH";
        $par = [$mesi];
        if ($esclusi) {
            $cond .= ' AND id NOT IN (' . implode(',', array_fill(0, count($esclusi), '?')) . ')';
            array_push($par, ...$esclusi);
        }
        $this->db->esegui("UPDATE prenotazioni SET utente_id = NULL WHERE utente_id IN (SELECT id FROM (SELECT id FROM utenti WHERE $cond) AS x)", $par);

        return max(0, $this->db->esegui("DELETE FROM utenti WHERE $cond", $par));
    }
}
