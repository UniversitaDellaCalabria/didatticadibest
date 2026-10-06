<?php

declare(strict_types=1);

namespace App\Eventi;

use App\Core\Database;

/** Query del report per ambito: turni del periodo con il loro evento e le iscrizioni confermate. */
final class ReportRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return list<array<string, mixed>> turni con data nel periodo (anche di eventi archiviati), in ordine di data */
    public function turniDelPeriodo(string $dal, string $al): array
    {
        return $this->db->righe(
            'SELECT t.id, t.evento_id, t.data_turno, t.orario_inizio, t.orario_fine, e.titolo, e.pagina_id, e.ambiti, e.luogo, e.relatore, e.relatore_ente, e.tipo
             FROM turni t JOIN eventi e ON e.id = t.evento_id
             WHERE t.data_turno BETWEEN ? AND ? ORDER BY t.data_turno, t.orario_inizio',
            [$dal, $al]
        );
    }

    /**
     * @param list<int> $turniIds
     * @return array<int, list<array<string, mixed>>> iscrizioni confermate per turno
     */
    public function prenotazioniConfermate(array $turniIds): array
    {
        $out = [];
        foreach ($this->db->righe(
            "SELECT turno_id, num_posti, presente, scuola_codice, dati_custom_json FROM prenotazioni
             WHERE IFNULL(stato, 'confermata') = 'confermata' AND turno_id IN (" . implode(',', array_map('intval', $turniIds)) . ')'
        ) as $p) {
            $out[(int) $p['turno_id']][] = $p;
        }

        return $out;
    }
}
