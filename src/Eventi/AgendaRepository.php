<?php

declare(strict_types=1);

namespace App\Eventi;

use App\Core\Database;

/** Query dell'agenda pubblica: eventi in programma con il prossimo turno, e i turni futuri per il calendario .ics. */
final class AgendaRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Eventi non archiviati, aperti a tutti, con almeno un turno futuro (o senza data) nelle aree indicate,
     * ordinati per prossima data (i «data da definire» in fondo). La colonna «prossimo» è «AAAA-MM-GG HH:MM:SS».
     *
     * @param list<int> $areeIds
     * @return list<array<string, mixed>>
     */
    public function inProgramma(array $areeIds): array
    {
        $in = implode(',', array_map('intval', $areeIds));

        return $this->db->righe(
            "SELECT e.id, e.titolo, e.tipo, e.luogo, e.descrizione_breve, e.locandina_path, e.pagina_id, e.ambiti, e.relatore, e.relatore_ente, e.link_streaming,
                    MAX(IFNULL(pd.convenzione, 0)) AS fsl, MAX(IFNULL(pd.dedicata_scuole, 0)) AS dedicata, MAX(IF(e.tipo = 'progetto', IFNULL(pd.per_scuole, 0), 0)) AS progetto_scuole,
                    MIN(CONCAT(COALESCE(t.data_turno, '9999-12-31'), ' ', COALESCE(t.orario_inizio, '99:99:99'))) AS prossimo,
                    COUNT(DISTINCT t.id) AS n_turni
             FROM eventi e
             JOIN turni t ON t.evento_id = e.id
             LEFT JOIN progetti_dettagli pd ON pd.evento_id = e.id
             WHERE e.archiviato = 0 AND e.pagina_id IN ($in) AND IFNULL(e.ruolo_accesso_id, 0) = 0
               AND (t.data_turno IS NULL OR t.data_turno >= CURDATE())
             GROUP BY e.id, e.titolo, e.tipo, e.luogo, e.descrizione_breve, e.locandina_path, e.pagina_id, e.ambiti, e.relatore, e.relatore_ente, e.link_streaming
             ORDER BY prossimo, e.titolo"
        );
    }

    /**
     * @param list<int> $eventiIds
     * @return list<array<string, mixed>> turni futuri con data degli eventi indicati
     */
    public function turniFuturi(array $eventiIds): array
    {
        return $this->db->righe(
            'SELECT id, evento_id, nome_turno, data_turno, orario_inizio, orario_fine FROM turni WHERE data_turno >= CURDATE() AND evento_id IN ('
            . implode(',', array_map('intval', $eventiIds)) . ') ORDER BY data_turno, orario_inizio'
        );
    }
}
