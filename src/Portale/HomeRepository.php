<?php

declare(strict_types=1);

namespace App\Portale;

use App\Core\Database;

/** Dati mostrati dai widget della home: slide, prossimi eventi, statistiche e scadenze della didattica. */
final class HomeRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return list<array<string, mixed>> slide attive per il carousel */
    public function slideAttive(): array
    {
        return $this->db->righe('SELECT * FROM slide_home WHERE attiva = 1 ORDER BY ordine ASC, id ASC');
    }

    /**
     * Prossimi eventi (cross-area, ordinati per prossimo turno). I turni senza data non scadono mai: l'evento compare
     * in coda come "data da definire". Sentinelle per l'ordinamento: data NULL → 9999-12-31, orario NULL → 99:99:99.
     *
     * @param list<int> $areeIds
     * @return list<array<string, mixed>> con prossima_data (null se da definire) e prossimo_orario ('' se manca)
     */
    public function prossimiEventi(array $areeIds, int $limite): array
    {
        $segnaposto = implode(',', array_fill(0, count($areeIds), '?'));
        $out = [];
        foreach ($this->db->righe(
            "SELECT e.id, e.titolo, e.tipo, e.locandina_path, e.pagina_id,
                p.titolo as area_titolo, p.colore_primario, p.slug,
                MIN(CONCAT(COALESCE(t.data_turno, '9999-12-31'), ' ', COALESCE(t.orario_inizio, '99:99:99'))) AS prossimo
         FROM eventi e
         JOIN pagine_eventi p ON e.pagina_id = p.id
         JOIN turni t ON t.evento_id = e.id
         WHERE e.archiviato = 0 AND p.visibile = 1 AND e.pagina_id IN ($segnaposto)
           AND (t.data_turno IS NULL OR t.data_turno >= CURDATE())
         GROUP BY e.id, e.titolo, e.tipo, e.locandina_path, e.pagina_id, p.titolo, p.colore_primario, p.slug
         ORDER BY prossimo ASC
         LIMIT $limite",
            array_map('intval', $areeIds)
        ) as $r) {
            $d = substr((string) $r['prossimo'], 0, 10);
            $o = substr((string) $r['prossimo'], 11, 5);
            $r['prossima_data'] = $d === '9999-12-31' ? null : $d;
            $r['prossimo_orario'] = $o === '99:99' ? '' : $o;
            $out[] = $r;
        }

        return $out;
    }

    /** @return array{eventi: int, aree: int, iscritti: int} statistiche automatiche (iscrizioni solo effettive) */
    public function statistiche(): array
    {
        return [
            'eventi' => (int) $this->db->valore('SELECT COUNT(DISTINCT e.id) as n FROM eventi e JOIN pagine_eventi p ON e.pagina_id = p.id JOIN turni t ON t.evento_id=e.id
                         WHERE e.archiviato=0 AND p.visibile=1 AND (t.data_turno IS NULL OR t.data_turno>=CURDATE())'),
            'aree' => (int) $this->db->valore('SELECT COUNT(*) as n FROM pagine_eventi WHERE visibile=1'),
            // Solo iscrizioni effettive (niente annullate, rifiutate, scadute o liste d'attesa)
            'iscritti' => (int) $this->db->valore("SELECT COUNT(*) as n FROM prenotazioni WHERE IFNULL(stato, 'confermata') = 'confermata'"),
        ];
    }

    /** @return list<array<string, mixed>> moduli online della didattica con data di chiusura futura (primi 4) */
    public function scadenzeDidattica(): array
    {
        return $this->db->righe("SELECT id, titolo, categoria, aperto_al FROM didattica_moduli WHERE attivo = 1 AND tipo = 'online' AND aperto_al IS NOT NULL AND aperto_al >= CURDATE()
                         AND (aperto_dal IS NULL OR aperto_dal <= CURDATE()) ORDER BY aperto_al, titolo LIMIT 4");
    }
}
