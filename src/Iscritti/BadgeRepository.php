<?php

declare(strict_types=1);

namespace App\Iscritti;

use App\Core\Database;
use App\Eventi\Righe;

/** Query della stampa dei badge nominativi: eventi e turni dell'area, iscritti confermati, gestori e amministratori (staff). */
final class BadgeRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Eventi non archiviati dell'area, ognuno con i suoi turni (chiave 'turni'); $filtroSql = filtro dei permessi (AND e.id IN …) o ''.
     *
     * @return list<array<string, mixed>>
     */
    public function eventiConTurni(int $paginaId, string $filtroSql): array
    {
        $eventi = [];
        foreach (Righe::testo($this->db->righe(
            "SELECT e.id, e.titolo FROM eventi e WHERE e.pagina_id = ? AND e.archiviato = 0 $filtroSql ORDER BY e.ordine ASC, e.id DESC",
            [$paginaId]
        )) as $row) {
            $row['turni'] = Righe::testo($this->db->righe('SELECT * FROM turni WHERE evento_id = ? ORDER BY data_turno ASC, orario_inizio ASC', [(int) $row['id']]));
            $eventi[] = $row;
        }

        return $eventi;
    }

    /**
     * Data, titolo e luogo dell'evento del turno, con il logo del portale.
     *
     * @return array<string, string|null>|null
     */
    public function infoTurno(int $turnoId): ?array
    {
        return Righe::riga($this->db->riga(
            'SELECT t.data_turno, e.titolo, e.luogo, e.id as ev_id, c.logo_path
             FROM turni t JOIN eventi e ON t.evento_id = e.id
             JOIN configurazione_portale c ON c.id = 1 WHERE t.id = ?',
            [$turnoId]
        ));
    }

    /**
     * Iscritti confermati del turno, in ordine alfabetico.
     *
     * @return list<array<string, string|null>>
     */
    public function confermati(int $turnoId): array
    {
        return Righe::testo($this->db->righe(
            "SELECT codice_prenotazione, nome, cognome, matricola FROM prenotazioni WHERE turno_id = ? AND stato = 'confermata' ORDER BY cognome ASC, nome ASC",
            [$turnoId]
        ));
    }

    /**
     * Gestori dell'evento: lista (CSV) e permessi (JSON).
     *
     * @return array<string, string|null>|null
     */
    public function gestoriEvento(int $eventoId): ?array
    {
        return Righe::riga($this->db->riga('SELECT gestori_utenti_ids, permessi_gestori_json FROM eventi WHERE id = ?', [$eventoId]));
    }

    /**
     * Gestori dell'area: lista (CSV) e permessi (JSON).
     *
     * @return array<string, string|null>|null
     */
    public function gestoriArea(int $paginaId): ?array
    {
        return Righe::riga($this->db->riga('SELECT gestori_utenti_ids, permessi_gestori_json FROM pagine_eventi WHERE id = ?', [$paginaId]));
    }

    /** @return list<string> id degli amministratori (ruolo principale o secondario 1) */
    public function idAmministratori(): array
    {
        return array_map(static fn (array $r): string => (string) $r['id'], $this->db->righe("SELECT id FROM utenti WHERE ruolo_id = 1 OR FIND_IN_SET('1', ruoli_secondari) > 0"));
    }

    /**
     * Nome e cognome degli utenti, in ordine di cognome.
     *
     * @param list<int> $ids
     * @return list<array<string, string|null>>
     */
    public function nomiUtenti(array $ids): array
    {
        if (!$ids) {
            return [];
        }

        return Righe::testo($this->db->righe(
            'SELECT nome, cognome FROM utenti WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY cognome ASC',
            $ids
        ));
    }
}
