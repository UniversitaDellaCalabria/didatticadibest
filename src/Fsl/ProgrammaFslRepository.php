<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Core\Database;
use App\Eventi\Righe;

/** Turni, eventi e aree FSL letti per costruire il programma della scuola. */
final class ProgrammaFslRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Il turno con l'evento, l'area e le impostazioni FSL (convenzione, per le scuole, evento archiviato); null se il turno non c'è.
     *
     * @return array<string, string|null>|null
     */
    public function turno(int $turnoId): ?array
    {
        return Righe::riga($this->db->riga(
            "SELECT t.*, e.titolo AS evento_titolo, e.pagina_id, e.archiviato, e.tipo AS evento_tipo, e.luogo AS evento_luogo, e.ruolo_accesso_id, e.richiede_prenotazione,
                    pe.slug, IFNULL(pd.convenzione, 0) AS fsl, IFNULL(pd.per_scuole, 0) AS per_scuole
             FROM turni t JOIN eventi e ON e.id = t.evento_id JOIN pagine_eventi pe ON pe.id = e.pagina_id LEFT JOIN progetti_dettagli pd ON pd.evento_id = e.id
             WHERE t.id = ? LIMIT 1",
            [$turnoId]
        ));
    }

    /**
     * La riga dell'area (pagine_eventi) su cui si prenota.
     *
     * @return array<string, mixed>|null
     */
    public function area(int $paginaId): ?array
    {
        return $this->db->riga('SELECT * FROM pagine_eventi WHERE id = ?', [$paginaId]);
    }

    /**
     * Il nome del campo «scuola» del modulo dell'attività, se ce n'è uno.
     */
    public function campoScuola(int $paginaId, int $eventoId): ?string
    {
        foreach ((new \App\Iscrizioni\CampiFormRepository($this->db))->perModulo($paginaId, $eventoId) as $c) {
            if (($c['tipo_campo'] ?? '') === 'scuola') {
                return (string) $c['nome_campo'];
            }
        }

        return null;
    }
}
