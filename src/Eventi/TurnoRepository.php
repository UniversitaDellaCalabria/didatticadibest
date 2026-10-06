<?php

declare(strict_types=1);

namespace App\Eventi;

use App\Core\Database;
use RuntimeException;

/** Query dei turni (tabella turni) usate dalle schede di eventi e progetti. */
final class TurnoRepository
{
    public function __construct(private Database $db)
    {
    }

    public function maxPosti(int $turnoId): int
    {
        return (int) $this->db->valore('SELECT max_posti FROM turni WHERE id = ?', [$turnoId]);
    }

    /** Evento a cui appartiene il turno (0 se il turno non esiste). */
    public function eventoDelTurno(int $turnoId): int
    {
        return (int) $this->db->valore('SELECT evento_id FROM turni WHERE id = ?', [$turnoId]);
    }

    /** @return list<array<string, string|null>> prenotazioni in lista d'attesa del turno, in ordine di arrivo, con il titolo dell'evento */
    public function inAttesa(int $turnoId): array
    {
        return Righe::testo($this->db->righe(
            "SELECT p.*, e.titolo as evento_titolo FROM prenotazioni p JOIN turni t ON p.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
             WHERE p.turno_id = ? AND p.stato = 'in_attesa' ORDER BY p.data_prenotazione ASC, p.id ASC",
            [$turnoId]
        ));
    }

    public function confermaPrenotazione(int $prenotazioneId): void
    {
        $this->db->esegui("UPDATE prenotazioni SET stato = 'confermata' WHERE id = ?", [$prenotazioneId]);
    }

    /** Prenotazioni del turno che occupano ancora un posto (non annullate, rifiutate o scadute). */
    public function prenotazioniAttive(int $turnoId): int
    {
        return (int) $this->db->valore("SELECT COUNT(*) AS n FROM prenotazioni WHERE turno_id = ? AND IFNULL(stato, 'confermata') NOT IN ('annullata', 'rifiutata', 'scaduta')", [$turnoId]);
    }

    /** Persone in lista d'attesa su tutti i turni dell'evento. */
    public function inAttesaDellEvento(int $eventoId): int
    {
        return (int) $this->db->valore("SELECT COUNT(*) AS n FROM prenotazioni p JOIN turni t ON p.turno_id = t.id WHERE t.evento_id = ? AND p.stato = 'in_attesa'", [$eventoId]);
    }

    /** @return array<string, string|null> nome e data del turno ([] se non esiste) */
    public function nomeEData(int $turnoId): array
    {
        return Righe::riga($this->db->riga('SELECT nome_turno, data_turno FROM turni WHERE id = ?', [$turnoId])) ?? [];
    }

    public function nome(int $turnoId): ?string
    {
        $n = $this->db->valore('SELECT nome_turno FROM turni WHERE id = ?', [$turnoId]);

        return $n === null ? null : (string) $n;
    }

    /**
     * Turni dell'evento con il numero di iscrizioni attive (n_iscr), per la scheda di modifica.
     *
     * @param bool $perData true = in ordine di data (eventi), false = in ordine di creazione (edizioni dei progetti)
     * @return list<array<string, string|null>>
     */
    public function conIscritti(int $eventoId, bool $perData): array
    {
        $ordine = $perData ? 'ORDER BY (t.data_turno IS NULL), t.data_turno ASC, t.orario_inizio ASC, t.id ASC' : 'ORDER BY t.id ASC';

        return Righe::testo($this->db->righe(
            "SELECT t.*, (SELECT COUNT(*) FROM prenotazioni p WHERE p.turno_id = t.id AND IFNULL(p.stato, 'confermata') NOT IN ('annullata', 'rifiutata', 'scaduta')) AS n_iscr
             FROM turni t WHERE t.evento_id = ? $ordine",
            [$eventoId]
        ));
    }

    /** @return list<array<string, string|null>> turni dell'evento in ordine di data (quelli senza data in fondo) */
    public function perEventoPerData(int $eventoId): array
    {
        return Righe::testo($this->db->righe(
            'SELECT * FROM turni WHERE evento_id = ? ORDER BY (data_turno IS NULL), data_turno ASC, orario_inizio ASC, nome_turno ASC, id ASC',
            [$eventoId]
        ));
    }

    /** @return list<array<string, string|null>> turni dell'evento in ordine di creazione */
    public function perEvento(int $eventoId): array
    {
        return Righe::testo($this->db->righe('SELECT * FROM turni WHERE evento_id = ? ORDER BY id ASC', [$eventoId]));
    }

    /**
     * Aggiorna un turno dalla scheda dell'evento. $t: nome, data, in, fi, max, ap, ch, ann, min, maxs, wa, mp, app.
     *
     * @param array<string, mixed> $t
     */
    public function aggiorna(int $turnoId, int $eventoId, array $t): void
    {
        $this->db->esegui(
            'UPDATE turni SET nome_turno=?, data_turno=?, orario_inizio=?, orario_fine=?, max_posti=?, data_apertura=?, data_chiusura=?, annullabile_fino=?, min_partecipanti=?, max_partecipanti=?, abilita_lista_attesa=?, abilita_multi_posto=?, richiede_approvazione=? WHERE id=? AND evento_id=?',
            [$t['nome'], $t['data'], $t['in'], $t['fi'], (int) $t['max'], $t['ap'], $t['ch'], $t['ann'], $t['min'], $t['maxs'], (int) $t['wa'], (int) $t['mp'], (int) $t['app'], $turnoId, $eventoId]
        );
    }

    /**
     * @param array<string, mixed> $t come in aggiorna()
     * @return int id del nuovo turno (0 se non riesce)
     */
    public function inserisci(int $eventoId, array $t): int
    {
        return $this->db->inserisci(
            'INSERT INTO turni (evento_id, nome_turno, data_turno, orario_inizio, orario_fine, max_posti, data_apertura, data_chiusura, annullabile_fino, min_partecipanti, max_partecipanti, abilita_lista_attesa, abilita_multi_posto, richiede_approvazione) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$eventoId, $t['nome'], $t['data'], $t['in'], $t['fi'], (int) $t['max'], $t['ap'], $t['ch'], $t['ann'], $t['min'], $t['maxs'], (int) $t['wa'], (int) $t['mp'], (int) $t['app']]
        );
    }

    /** Aula di «Prenotazioni e risorse» occupata dal turno (null = nessuna). */
    public function impostaRisorsa(int $turnoId, ?int $risorsaId): void
    {
        $this->db->esegui('UPDATE turni SET risorsa_id = ? WHERE id = ?', [$risorsaId, $turnoId]);
    }

    /**
     * Aggiorna un'edizione di un progetto (turno senza data). Lancia RuntimeException se non riesce.
     *
     * @param array<string, mixed> $ed nome, posti, apertura, chiusura, min, max
     */
    public function aggiornaEdizione(int $turnoId, string $nome, array $ed, int $listaAttesa, int $approvazione): void
    {
        if ($this->db->esegui(
            'UPDATE turni SET nome_turno = ?, max_posti = ?, data_apertura = ?, data_chiusura = ?, min_partecipanti = ?, max_partecipanti = ?, abilita_lista_attesa = ?, abilita_multi_posto = 0, richiede_approvazione = ? WHERE id = ?',
            [$nome, (int) $ed['posti'], $ed['apertura'], $ed['chiusura'], $ed['min'], $ed['max'], $listaAttesa, $approvazione, $turnoId]
        ) < 0) {
            throw new RuntimeException($this->db->ultimoErrore());
        }
    }

    /**
     * Crea l'edizione di un progetto; ritorna l'id. Lancia RuntimeException se non riesce.
     *
     * @param array<string, mixed> $ed
     */
    public function inserisciEdizione(int $eventoId, string $nome, array $ed, int $listaAttesa, int $approvazione): int
    {
        $id = $this->db->inserisci(
            'INSERT INTO turni (evento_id, nome_turno, data_turno, orario_inizio, orario_fine, max_posti, data_apertura, data_chiusura, min_partecipanti, max_partecipanti, abilita_lista_attesa, abilita_multi_posto, richiede_approvazione)
             VALUES (?, ?, NULL, NULL, NULL, ?, ?, ?, ?, ?, ?, 0, ?)',
            [$eventoId, $nome, (int) $ed['posti'], $ed['apertura'], $ed['chiusura'], $ed['min'], $ed['max'], $listaAttesa, $approvazione]
        );
        if ($id <= 0) {
            throw new RuntimeException($this->db->ultimoErrore());
        }

        return $id;
    }
}
