<?php

declare(strict_types=1);

namespace App\Eventi;

use App\Core\Database;

/**
 * Numeri e grafici della pagina «Statistiche» di un'area. $rbac è la condizione SQL (alias e, pe) sugli eventi visibili
 * al gestore, già costruita da admin_header.php: non contiene input dell'utente.
 */
final class StatisticheRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array{confermate: int, attesa: int, perse: int, capienza: int} */
    public function kpi(int $paginaId, string $rbac): array
    {
        $kpi = ['confermate' => 0, 'attesa' => 0, 'perse' => 0, 'capienza' => 0];
        $riga = $this->db->riga(
            "SELECT
               SUM(CASE WHEN p.stato IN ('confermata','richiesta_conferma') THEN p.num_posti ELSE 0 END) as tot_confermate,
               SUM(CASE WHEN p.stato = 'in_attesa' THEN p.num_posti ELSE 0 END) as tot_attesa,
               SUM(CASE WHEN p.stato IN ('scaduta','rifiutata') THEN p.num_posti ELSE 0 END) as tot_perse
             FROM prenotazioni p
             JOIN turni t ON p.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             JOIN pagine_eventi pe ON e.pagina_id = pe.id
             WHERE e.pagina_id = $paginaId $rbac"
        );
        if ($riga !== null) {
            $kpi['confermate'] = (int) $riga['tot_confermate'];
            $kpi['attesa'] = (int) $riga['tot_attesa'];
            $kpi['perse'] = (int) $riga['tot_perse'];
        }
        $kpi['capienza'] = $this->capienza($paginaId, $rbac);

        return $kpi;
    }

    /** @return array{confermate: int, attesa: int, perse: int, annullate: int, presenti: int, capienza: int} */
    public function kpiCompleti(int $paginaId, string $rbac): array
    {
        $kpi = ['confermate' => 0, 'attesa' => 0, 'perse' => 0, 'annullate' => 0, 'presenti' => 0, 'capienza' => 0];
        $riga = $this->db->riga(
            "SELECT
               SUM(CASE WHEN p.stato IN ('confermata','richiesta_conferma') THEN p.num_posti ELSE 0 END) as tot_confermate,
               SUM(CASE WHEN p.stato = 'in_attesa' THEN p.num_posti ELSE 0 END) as tot_attesa,
               SUM(CASE WHEN p.stato IN ('scaduta','rifiutata') THEN p.num_posti ELSE 0 END) as tot_perse,
               SUM(CASE WHEN p.stato IN ('annullata','annullato','cancelled') THEN p.num_posti ELSE 0 END) as tot_annullate,
               SUM(CASE WHEN p.presente = 1 THEN p.num_posti ELSE 0 END) as tot_presenti
             FROM prenotazioni p
             JOIN turni t ON p.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             JOIN pagine_eventi pe ON e.pagina_id = pe.id
             WHERE e.pagina_id = $paginaId $rbac"
        );
        if ($riga !== null) {
            $kpi['confermate'] = (int) $riga['tot_confermate'];
            $kpi['attesa'] = (int) $riga['tot_attesa'];
            $kpi['perse'] = (int) $riga['tot_perse'];
            $kpi['annullate'] = (int) $riga['tot_annullate'];
            $kpi['presenti'] = (int) $riga['tot_presenti'];
        }
        $kpi['capienza'] = $this->capienza($paginaId, $rbac);

        return $kpi;
    }

    /** Capienza dei turni con un limite reale di posti. */
    private function capienza(int $paginaId, string $rbac): int
    {
        return (int) $this->db->valore(
            "SELECT SUM(t.max_posti) as capienza_max
             FROM turni t
             JOIN eventi e ON t.evento_id = e.id
             JOIN pagine_eventi pe ON e.pagina_id = pe.id
             WHERE e.pagina_id = $paginaId $rbac AND t.max_posti < " . (int) POSTI_SENZA_LIMITE
        );
    }

    /**
     * Posti occupati e capienza per evento (solo quelli con almeno un iscritto o un limite di posti).
     * I nomi sono già racchiusi tra virgolette per il JavaScript dei grafici.
     *
     * @return array{nomi: list<string>, occupati: list<int>, capienza: list<mixed>}
     */
    public function graficoEventi(int $paginaId, string $rbac): array
    {
        $nomi = [];
        $occupati = [];
        $capienza = [];
        $eventi = Righe::testo($this->db->righe(
            "SELECT e.id, e.titolo,
               COALESCE((SELECT SUM(max_posti) FROM turni WHERE evento_id = e.id AND max_posti < " . (int) POSTI_SENZA_LIMITE . "), 0) as cap_max
             FROM eventi e
             JOIN pagine_eventi pe ON e.pagina_id = pe.id
             WHERE e.pagina_id = $paginaId $rbac
             ORDER BY e.id ASC"
        ));
        foreach ($eventi as $ev) {
            $occ = (int) $this->db->valore(
                "SELECT COALESCE(SUM(p.num_posti), 0) as occupati
                 FROM prenotazioni p JOIN turni t ON p.turno_id = t.id
                 WHERE t.evento_id = ? AND p.stato IN ('confermata','richiesta_conferma')",
                [(int) $ev['id']]
            );
            if ($occ > 0 || $ev['cap_max'] > 0) {
                $titolo = (string) $ev['titolo'];
                $titoloCorto = mb_strlen($titolo) > 25 ? mb_substr($titolo, 0, 22) . '...' : $titolo;
                $nomi[] = '"' . addslashes($titoloCorto) . '"';
                $occupati[] = $occ;
                $capienza[] = $ev['cap_max'];
            }
        }

        return ['nomi' => $nomi, 'occupati' => $occupati, 'capienza' => $capienza];
    }

    /** @return list<array<string, string|null>> turni dell'area con confermati e in attesa */
    public function turni(int $paginaId, string $rbac): array
    {
        return Righe::testo($this->db->righe(
            "SELECT e.titolo as evento_titolo, t.id as turno_id,
               t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine, t.max_posti,
               COALESCE(SUM(CASE WHEN p.stato IN ('confermata','richiesta_conferma') THEN p.num_posti ELSE 0 END), 0) as confermati,
               COALESCE(SUM(CASE WHEN p.stato = 'in_attesa' THEN p.num_posti ELSE 0 END), 0) as attesa
             FROM turni t
             JOIN eventi e ON t.evento_id = e.id
             JOIN pagine_eventi pe ON e.pagina_id = pe.id
             LEFT JOIN prenotazioni p ON p.turno_id = t.id
             WHERE e.pagina_id = $paginaId $rbac
             GROUP BY t.id
             ORDER BY (t.data_turno IS NULL), t.data_turno ASC, t.orario_inizio ASC, t.nome_turno ASC, t.id ASC"
        ));
    }

    /** @return list<array<string, string|null>> come turni(), con anche annullate e presenti */
    public function turniCompleti(int $paginaId, string $rbac): array
    {
        return Righe::testo($this->db->righe(
            "SELECT e.titolo as evento_titolo, t.id as turno_id,
               t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine, t.max_posti,
               COALESCE(SUM(CASE WHEN p.stato IN ('confermata','richiesta_conferma') THEN p.num_posti ELSE 0 END), 0) as confermati,
               COALESCE(SUM(CASE WHEN p.stato = 'in_attesa' THEN p.num_posti ELSE 0 END), 0) as attesa,
               COALESCE(SUM(CASE WHEN p.stato IN ('annullata','annullato','cancelled') THEN p.num_posti ELSE 0 END), 0) as annullate,
               COALESCE(SUM(CASE WHEN p.presente = 1 THEN p.num_posti ELSE 0 END), 0) as presenti
             FROM turni t
             JOIN eventi e ON t.evento_id = e.id
             JOIN pagine_eventi pe ON e.pagina_id = pe.id
             LEFT JOIN prenotazioni p ON p.turno_id = t.id
             WHERE e.pagina_id = $paginaId $rbac
             GROUP BY t.id
             ORDER BY (t.data_turno IS NULL), t.data_turno ASC, t.orario_inizio ASC, t.nome_turno ASC, t.id ASC"
        ));
    }

    /** @return list<array<string, string|null>> iscrizioni per giorno degli ultimi $giorni giorni */
    public function trendIscrizioni(int $paginaId, string $rbac, int $giorni = 30): array
    {
        return Righe::testo($this->db->righe(
            "SELECT DATE(p.data_prenotazione) as giorno, COUNT(*) as cnt
             FROM prenotazioni p
             JOIN turni t ON p.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             JOIN pagine_eventi pe ON e.pagina_id = pe.id
             WHERE e.pagina_id = $paginaId $rbac
               AND p.data_prenotazione >= DATE_SUB(CURDATE(), INTERVAL $giorni DAY)
             GROUP BY DATE(p.data_prenotazione)
             ORDER BY giorno ASC"
        ));
    }

    /** @return list<array<string, string|null>> tutte le iscrizioni dell'area (per l'esportazione), in ordine di turno e di prenotazione */
    public function iscrizioniPerEsportazione(int $paginaId, string $rbac): array
    {
        return Righe::testo($this->db->righe(
            "SELECT p.*, e.titolo as evento_titolo, t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine
             FROM prenotazioni p
             JOIN turni t ON p.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             JOIN pagine_eventi pe ON e.pagina_id = pe.id
             WHERE e.pagina_id = $paginaId $rbac
             ORDER BY t.data_turno ASC, t.orario_inizio ASC, p.data_prenotazione ASC"
        ));
    }
}
