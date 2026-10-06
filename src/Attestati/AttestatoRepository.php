<?php

declare(strict_types=1);

namespace App\Attestati;

use App\Core\Database;
use App\Eventi\Righe;

/**
 * Tutto l'SQL degli attestati: prenotazioni con i dati dell'evento, elenco degli studenti, codici di verifica,
 * invio degli attestati. I risultati delle vecchie query "testuali" (stringhe) restano stringhe (Righe).
 */
final class AttestatoRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Tipo dell'evento e scheda del progetto, per decidere la regola dell'attestato.
     *
     * @return array<string, string|null>|null
     */
    public function schedaRegola(int $eventoId): ?array
    {
        return Righe::riga($this->db->riga(
            'SELECT e.tipo, d.per_scuole, d.attestati, d.data_fine FROM eventi e LEFT JOIN progetti_dettagli d ON d.evento_id = e.id WHERE e.id = ? LIMIT 1',
            [$eventoId]
        ));
    }

    /**
     * Studenti di una prenotazione, in ordine di inserimento.
     *
     * @return list<array<string, string|null>>
     */
    public function partecipanti(int $prenotazioneId): array
    {
        return Righe::testo($this->db->righe(
            'SELECT * FROM partecipanti_prenotazione WHERE prenotazione_id = ? ORDER BY ordine ASC, id ASC',
            [$prenotazioneId]
        ));
    }

    /**
     * Sostituisce l'elenco degli studenti (cancella e riscrive) in una transazione.
     *
     * @param list<array{cognome: string, nome: string}> $righe
     */
    public function sostituisciPartecipanti(int $prenotazioneId, array $righe): void
    {
        $this->db->transazione(function (Database $db) use ($prenotazioneId, $righe): void {
            $db->esegui('DELETE FROM partecipanti_prenotazione WHERE prenotazione_id = ?', [$prenotazioneId]);
            foreach ($righe as $i => $r) {
                $db->esegui(
                    'INSERT INTO partecipanti_prenotazione (prenotazione_id, cognome, nome, ordine) VALUES (?, ?, ?, ?)',
                    [$prenotazioneId, $r['cognome'], $r['nome'], $i]
                );
            }
        });
    }

    /** @return list<int> id degli studenti senza codice di verifica */
    public function idsSenzaCodice(int $prenotazioneId): array
    {
        $ids = [];
        foreach ($this->db->righe(
            "SELECT id FROM partecipanti_prenotazione WHERE prenotazione_id = ? AND (codice IS NULL OR codice = '')",
            [$prenotazioneId]
        ) as $r) {
            $ids[] = (int) $r['id'];
        }

        return $ids;
    }

    /** Assegna il codice a uno studente: false se non riesce (il codice è UNIQUE: in caso di doppione si riprova con un altro). */
    public function impostaCodice(int $partecipanteId, string $codice): bool
    {
        return $this->db->esegui('UPDATE partecipanti_prenotazione SET codice = ? WHERE id = ?', [$codice, $partecipanteId]) >= 0;
    }

    public function contaNonEsclusi(int $prenotazioneId): int
    {
        return (int) $this->db->valore(
            'SELECT COUNT(*) AS n FROM partecipanti_prenotazione WHERE prenotazione_id = ? AND escluso = 0',
            [$prenotazioneId]
        );
    }

    /**
     * Prenotazione con evento, area, portale e scheda del progetto: i dati che servono agli attestati.
     *
     * @return array<string, string|null>|null
     */
    public function prenotazionePerAttestati(int $prenotazioneId): ?array
    {
        return Righe::riga($this->db->riga(
            'SELECT pr.*, t.data_turno, t.orario_inizio, t.orario_fine, t.nome_turno, t.evento_id, t.min_partecipanti, t.max_partecipanti,
                    e.titolo AS evento_titolo, e.luogo AS evento_luogo, e.tipo AS evento_tipo, e.pagina_id,
                    pe.titolo AS pagina_titolo, pe.firma_nome, pe.firma_titolo, pe.logo_attestato_path, pe.colore_primario, pe.testo_attestato,
                    cp.logo_path, cp.nome_portale, cp.sottotitolo_portale,
                    d.per_scuole, d.attestati, d.data_inizio, d.data_fine, d.ore_totali
             FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
             LEFT JOIN pagine_eventi pe ON e.pagina_id = pe.id
             LEFT JOIN progetti_dettagli d ON d.evento_id = e.id
             LEFT JOIN configurazione_portale cp ON cp.id = 1
             WHERE pr.id = ? LIMIT 1',
            [$prenotazioneId]
        ));
    }

    /** Id della prenotazione con questo codice (esatto), 0 se non esiste. */
    public function idPerCodice(string $codice): int
    {
        return (int) $this->db->valore('SELECT id FROM prenotazioni WHERE codice_prenotazione = ? LIMIT 1', [$codice]);
    }

    /** Id della prenotazione con questo codice senza badare alle maiuscole (verifica pubblica), null se non esiste. */
    public function idPerCodiceMaiuscolo(string $codice): ?int
    {
        $riga = $this->db->riga('SELECT id FROM prenotazioni WHERE UPPER(codice_prenotazione) = ? LIMIT 1', [$codice]);

        return $riga ? (int) $riga['id'] : null;
    }

    /**
     * Studente con questo codice di verifica (attestato di un progetto per le scuole).
     *
     * @return array<string, mixed>|null cognome, nome, escluso, anonimizzato, pr_id
     */
    public function partecipantePerCodice(string $codice): ?array
    {
        return $this->db->riga(
            'SELECT pp.cognome, pp.nome, pp.escluso, pp.anonimizzato, pr.id AS pr_id FROM partecipanti_prenotazione pp JOIN prenotazioni pr ON pp.prenotazione_id = pr.id WHERE pp.codice = ? LIMIT 1',
            [$codice]
        );
    }

    /**
     * Dati per l'attestato personale a partire dal codice della prenotazione.
     *
     * @return array<string, mixed>|null
     */
    public function attestatoPerCodice(string $codice): ?array
    {
        return $this->db->riga(
            "SELECT pr.*, t.data_turno, t.orario_inizio, t.orario_fine,
               e.titolo as evento_titolo, e.luogo as evento_luogo,
               pe.titolo as pagina_titolo, pe.firma_nome, pe.firma_titolo, pe.logo_attestato_path,
               cp.logo_path, cp.nome_portale, cp.sottotitolo_portale,
               COALESCE(NULLIF(pr.matricola,''), u.matricola_studente, u.matricola_dipendente, u.matricola, '') as matricola_effettiva
            FROM prenotazioni pr
            JOIN turni t ON pr.turno_id = t.id
            JOIN eventi e ON t.evento_id = e.id
            LEFT JOIN pagine_eventi pe ON e.pagina_id = pe.id
            LEFT JOIN utenti u ON pr.utente_id = u.id
            JOIN configurazione_portale cp ON cp.id = 1
            WHERE pr.codice_prenotazione = ? LIMIT 1",
            [$codice]
        );
    }

    public function segnaAttestatoInviato(int $prenotazioneId): void
    {
        $this->db->esegui('UPDATE prenotazioni SET attestato_inviato = 1 WHERE id = ?', [$prenotazioneId]);
    }

    /**
     * Prenotazione, turno ed evento per l'email dell'attestato personale.
     *
     * @return array<string, mixed>|null
     */
    public function perEmailAttestato(int $prenotazioneId): ?array
    {
        return $this->db->riga(
            'SELECT p.id, p.turno_id, p.nome, p.cognome, p.email, p.codice_prenotazione,
                    p.attestato_inviato, p.presente,
                    t.data_turno, t.orario_fine, e.titolo
             FROM prenotazioni p
             JOIN turni t ON p.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             WHERE p.id = ? LIMIT 1',
            [$prenotazioneId]
        );
    }

    /** Evento di un turno (0 se il turno non esiste). */
    public function eventoDelTurno(int $turnoId): int
    {
        return (int) $this->db->valore('SELECT t.evento_id FROM turni t WHERE t.id = ?', [$turnoId]);
    }

    /**
     * Presenti con attestato non ancora inviato, a evento concluso (turno senza data o già terminato alla data $adesso).
     *
     * @return list<array<string, string|null>>
     */
    public function daInviare(string $adesso): array
    {
        return Righe::testo($this->db->righe(
            "SELECT p.id, p.turno_id, p.nome, p.cognome, p.email, p.codice_prenotazione,
                    t.data_turno, t.evento_id, e.titolo
             FROM prenotazioni p
             JOIN turni t ON p.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             WHERE p.stato = 'confermata'
               AND p.presente = 1
               AND p.attestato_inviato = 0
               AND (t.data_turno IS NULL OR CONCAT(t.data_turno, ' ', COALESCE(t.orario_fine, '23:59:59')) <= ?)",
            [$adesso]
        ));
    }
}
