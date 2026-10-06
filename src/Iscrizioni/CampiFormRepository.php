<?php

declare(strict_types=1);

namespace App\Iscrizioni;

use App\Core\Database;
use App\Eventi\Righe;

/** Campi del modulo di iscrizione (Form Builder: tabella campi_form) di un'area e dei suoi eventi. */
final class CampiFormRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return list<array<string, mixed>> campi propri dell'evento, in ordine di inserimento */
    public function delEvento(int $eventoId): array
    {
        return $this->db->righe('SELECT * FROM campi_form WHERE evento_id = ? ORDER BY id ASC', [$eventoId]);
    }

    /**
     * Campi del modulo di un evento: quelli di tutta l'area (senza evento) più quelli dell'evento, nell'ordine del form.
     *
     * @return list<array<string, string|null>>
     */
    public function perModulo(int $paginaId, int $eventoId): array
    {
        return Righe::testo($this->db->righe(
            'SELECT * FROM campi_form WHERE (pagina_id = ? AND (evento_id IS NULL OR evento_id = 0)) OR evento_id = ? ORDER BY ordine ASC, id ASC',
            [$paginaId, $eventoId]
        ));
    }

    /** @return array<string, string|null>|null id area e tipo dell'evento */
    public function eventoDelModulo(int $eventoId): ?array
    {
        return Righe::riga($this->db->riga('SELECT e.pagina_id, e.tipo FROM eventi e WHERE e.id = ? LIMIT 1', [$eventoId]));
    }

    /** @return array<string, string|null>|null limiti di partecipanti del turno (se appartiene all'evento) */
    public function limitiTurno(int $turnoId, int $eventoId): ?array
    {
        return Righe::riga($this->db->riga('SELECT min_partecipanti, max_partecipanti FROM turni WHERE id = ? AND evento_id = ?', [$turnoId, $eventoId]));
    }
}
