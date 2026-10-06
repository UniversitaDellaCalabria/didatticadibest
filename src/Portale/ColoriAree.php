<?php

declare(strict_types=1);

namespace App\Portale;

use App\Core\Database;

/** Colore primario dell'area di un turno (email delle prenotazioni), memorizzato per la richiesta. */
final class ColoriAree
{
    /** @var array<int, string> */
    private array $perTurno = [];

    public function __construct(private Database $db)
    {
    }

    public function delTurno(int $turnoId): string
    {
        return $this->perTurno[$turnoId] ??= Colori::valido($this->db->valore(
            'SELECT pe.colore_primario FROM turni t JOIN eventi e ON t.evento_id = e.id JOIN pagine_eventi pe ON e.pagina_id = pe.id WHERE t.id = ? LIMIT 1',
            [$turnoId]
        ) ?? '');
    }
}
