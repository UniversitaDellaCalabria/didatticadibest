<?php

declare(strict_types=1);

namespace App\Iscrizioni;

use App\Core\Database;
use App\Eventi\Righe;
use mysqli_result;

/** Posti e prenotazioni per la home, l'agenda e le schede: ultimi posti, riepilogo per evento o area, coda, prenotazioni attive dell'utente. */
final class DisponibilitaRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Prenotazioni ancora da vivere dell'utente (turno non concluso), la più urgente per prima:
     * prima i posti offerti da confermare, poi per data; i turni senza data in coda. In caso di errore SQL: nessun risultato.
     *
     * @return list<array<string, mixed>>
     */
    public function attiveDellUtente(int $utenteId, int $limite): array
    {
        $r = $this->db->grezza(
            "SELECT pr.id, pr.codice_prenotazione, IFNULL(pr.stato, 'confermata') AS stato, pr.num_posti, pr.scadenza_conferma,
                    t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine,
                    e.titolo AS evento_titolo, e.luogo, e.locandina_path,
                    pe.titolo AS area_titolo, pe.colore_primario, pe.slug
             FROM prenotazioni pr
             JOIN turni t  ON pr.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             JOIN pagine_eventi pe ON e.pagina_id = pe.id
             WHERE (pr.utente_id = ? OR LOWER(pr.email) = (SELECT LOWER(u.email) FROM utenti u WHERE u.id = ? AND u.email != ''))
               AND IFNULL(pr.stato, 'confermata') IN ('confermata', 'richiesta_conferma', 'da_approvare', 'in_attesa')
               AND e.archiviato = 0
               AND (t.data_turno IS NULL OR CONCAT(t.data_turno, ' ', COALESCE(t.orario_fine, '23:59:59')) >= NOW())
             ORDER BY (IFNULL(pr.stato, 'confermata') = 'richiesta_conferma') DESC,
                      (t.data_turno IS NULL), t.data_turno ASC, t.orario_inizio ASC, pr.id ASC
             LIMIT ?",
            [$utenteId, $utenteId, $limite]
        );
        // Mai bloccare la home per un widget: in caso di errore SQL, niente widget + log
        if (!$r instanceof mysqli_result) {
            error_log('[get_prenotazioni_attive_utente] ' . $this->db->ultimoErrore());

            return [];
        }

        return $r->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Posizione in coda (1 = il prossimo a essere promosso) e persone in coda nel turno, delle prenotazioni «in_attesa» indicate.
     * Stesso ordine della promozione: data di prenotazione, a parità l'id.
     *
     * @param list<int> $prenotazioniIds
     * @return array<int, array{posizione: int, totale: int}>
     */
    public function posizioniInCoda(array $prenotazioniIds): array
    {
        $in = implode(',', $prenotazioniIds);
        $out = [];
        foreach ($this->db->righe(
            "SELECT p.id,
                    1 + (SELECT COUNT(*) FROM prenotazioni q
                          WHERE q.turno_id = p.turno_id AND q.stato = 'in_attesa'
                            AND (q.data_prenotazione < p.data_prenotazione
                                 OR (q.data_prenotazione = p.data_prenotazione AND q.id < p.id))) AS posizione,
                    (SELECT COUNT(*) FROM prenotazioni r WHERE r.turno_id = p.turno_id AND r.stato = 'in_attesa') AS totale
             FROM prenotazioni p
             WHERE p.id IN ($in) AND p.stato = 'in_attesa'"
        ) as $r) {
            $out[(int) $r['id']] = ['posizione' => (int) $r['posizione'], 'totale' => (int) $r['totale']];
        }

        return $out;
    }

    /**
     * Turni prenotabili adesso con pochi posti (<= 10% della capienza, almeno 1) o con iscrizioni che chiudono entro 48 ore:
     * fino a 30 righe, le più urgenti per prime (poi la scelta di un turno per evento la fa il servizio).
     *
     * @param list<int> $pagineIds
     * @return list<array<string, string|null>>
     */
    public function turniConPochiPosti(array $pagineIds): array
    {
        $in = implode(',', $pagineIds);

        // Conteggio posti in una sottoquery: MariaDB non accetta alias di aggregati dentro espressioni di HAVING/ORDER BY, e così vale anche ONLY_FULL_GROUP_BY.
        return Righe::testo($this->db->righe(
            "SELECT x.* FROM (
                SELECT t.id AS turno_id, t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine, t.max_posti, t.data_chiusura,
                       e.id AS evento_id, e.titolo, e.tipo, e.locandina_path,
                       pe.titolo AS area_titolo, pe.colore_primario, pe.slug,
                       (SELECT COALESCE(SUM(pr.num_posti), 0) FROM prenotazioni pr
                         WHERE pr.turno_id = t.id
                           AND IFNULL(pr.stato, 'confermata') IN ('confermata', 'richiesta_conferma', 'da_approvare')) AS occupati
                FROM turni t
                JOIN eventi e ON t.evento_id = e.id
                JOIN pagine_eventi pe ON e.pagina_id = pe.id
                WHERE e.archiviato = 0 AND pe.visibile = 1 AND e.pagina_id IN ($in)
                  AND IFNULL(e.richiede_prenotazione, 1) = 1
                  AND IFNULL(e.tipo, 'evento') <> 'progetto'
                  AND t.max_posti > 0 AND t.max_posti < " . (int) POSTI_SENZA_LIMITE . "
                  AND (t.data_apertura IS NULL OR t.data_apertura <= NOW())
                  AND (t.data_chiusura IS NULL OR t.data_chiusura >= NOW())
                  AND (t.data_turno IS NULL OR CONCAT(t.data_turno, ' ', COALESCE(t.orario_inizio, '23:59:59')) > NOW())
             ) x
             WHERE x.max_posti - x.occupati > 0
               AND (x.max_posti - x.occupati <= GREATEST(1, CEIL(x.max_posti * 0.10))
                    OR (x.data_chiusura IS NOT NULL AND x.data_chiusura <= NOW() + INTERVAL 48 HOUR))
             ORDER BY (x.max_posti - x.occupati) / x.max_posti ASC, x.data_chiusura IS NULL, x.data_chiusura ASC
             LIMIT 30"
        ));
    }

    /**
     * Capienza e posti occupati dei turni ancora prenotabili, raggruppati per evento ($perArea = false) o per area.
     * Esclusi: eventi senza prenotazione, turni senza limite (>= POSTI_SENZA_LIMITE), conclusi o con iscrizioni chiuse.
     *
     * @param list<int> $ids
     * @return list<array{chiave: int, capienza: int, occupati: int}>
     */
    public function capienzaEOccupati(bool $perArea, array $ids): array
    {
        $col = $perArea ? 'e.pagina_id' : 'e.id';
        $in = implode(',', $ids);
        $righe = [];
        foreach ($this->db->righe(
            "SELECT x.chiave, SUM(x.max_posti) AS capienza, SUM(x.occupati) AS occupati FROM (
                SELECT $col AS chiave, t.max_posti,
                       (SELECT COALESCE(SUM(pr.num_posti), 0) FROM prenotazioni pr
                         WHERE pr.turno_id = t.id
                           AND IFNULL(pr.stato, 'confermata') IN ('confermata', 'richiesta_conferma', 'da_approvare')) AS occupati
                FROM turni t
                JOIN eventi e ON t.evento_id = e.id
                WHERE $col IN ($in) AND e.archiviato = 0
                  AND IFNULL(e.richiede_prenotazione, 1) = 1
                  AND t.max_posti > 0 AND t.max_posti < " . (int) POSTI_SENZA_LIMITE . "
                  AND (t.data_chiusura IS NULL OR t.data_chiusura >= NOW())
                  AND (t.data_turno IS NULL OR CONCAT(t.data_turno, ' ', COALESCE(t.orario_inizio, '23:59:59')) > NOW())
             ) x
             GROUP BY x.chiave"
        ) as $r) {
            $righe[] = ['chiave' => (int) $r['chiave'], 'capienza' => (int) $r['capienza'], 'occupati' => (int) $r['occupati']];
        }

        return $righe;
    }
}
