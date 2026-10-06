<?php

declare(strict_types=1);

namespace App\Portale;

use App\Core\Database;

/**
 * Numeri e elenchi della dashboard di un'area (admin/dashboard.php). Il filtro sulle attività del gestore ($rbac) è già
 * una condizione SQL (" AND e.id IN (...) "), costruita da admin_header.php con id interi. Se una query non riesce
 * (tabella o colonna mancante in installazioni vecchie) il risultato è vuoto, come prima.
 */
final class DashboardRepository
{
    public function __construct(private Database $db)
    {
    }

    /** Prenotazioni attive, confermate, in attesa e check-in del perimetro (tutte tranne le annullate).
     * @return array<string, mixed> vuoto se la query non riesce */
    public function kpi(int $paginaId, string $rbac): array
    {
        return $this->db->riga("SELECT COUNT(*) as tot,
            SUM(CASE WHEN stato IN ('confermata','confermato','confirmed') THEN 1 ELSE 0 END) as confermate,
            SUM(CASE WHEN stato IN ('in_attesa','pending') THEN 1 ELSE 0 END) as in_attesa,
            SUM(CASE WHEN presente = 1 THEN 1 ELSE 0 END) as checkin_tot
     FROM prenotazioni p
     JOIN turni t ON p.turno_id = t.id
     JOIN eventi e ON t.evento_id = e.id
     WHERE e.pagina_id = ? $rbac
       AND p.stato NOT IN ('annullata','annullato','cancelled')", [$paginaId]) ?? [];
    }

    /** Check-in di oggi.
     * @return array<string, mixed> vuoto se la query non riesce */
    public function checkinOggi(int $paginaId, string $rbac): array
    {
        return $this->db->riga("SELECT COUNT(*) as checkin_oggi
     FROM prenotazioni p
     JOIN turni t ON p.turno_id = t.id
     JOIN eventi e ON t.evento_id = e.id
     WHERE e.pagina_id = ? $rbac
       AND p.presente = 1
       AND DATE(t.data_turno) = CURDATE()", [$paginaId]) ?? [];
    }

    /** Prenotazioni con messaggi non letti dell'utente. */
    public function messaggiNonLetti(int $paginaId, string $rbac): int
    {
        return (int) $this->db->valore("SELECT COUNT(DISTINCT m.prenotazione_id) as n
     FROM messaggi_prenotazioni m
     JOIN prenotazioni p ON m.prenotazione_id = p.id
     JOIN turni t ON p.turno_id = t.id
     JOIN eventi e ON t.evento_id = e.id
     WHERE m.letto = 0 AND m.mittente_tipo = 'utente'
       AND e.pagina_id = ? $rbac", [$paginaId]);
    }

    /** Ultime 8 prenotazioni.
     * @return list<array<string, mixed>> */
    public function ultimePrenotazioni(int $paginaId, string $rbac): array
    {
        return $this->db->righe("SELECT p.codice_prenotazione, p.nome, p.cognome, p.stato, p.presente, p.convenzione,
            p.data_prenotazione, e.titolo as evento_titolo,
            t.data_turno, t.orario_inizio
     FROM prenotazioni p
     JOIN turni t ON p.turno_id = t.id
     JOIN eventi e ON t.evento_id = e.id
     WHERE e.pagina_id = ? $rbac
     ORDER BY p.data_prenotazione DESC
     LIMIT 8", [$paginaId]);
    }

    /** Ultimi 5 messaggi non letti (per conversazione).
     * @return list<array<string, mixed>> */
    public function ultimiMessaggi(int $paginaId, string $rbac): array
    {
        return $this->db->righe("SELECT m.messaggio, m.data_invio,
            p.codice_prenotazione, p.nome, p.cognome, p.id as pr_id,
            e.titolo as evento_titolo
     FROM messaggi_prenotazioni m
     JOIN prenotazioni p ON m.prenotazione_id = p.id
     JOIN turni t ON p.turno_id = t.id
     JOIN eventi e ON t.evento_id = e.id
     WHERE m.letto = 0 AND m.mittente_tipo = 'utente'
       AND e.pagina_id = ? $rbac
     GROUP BY m.prenotazione_id
     ORDER BY m.data_invio DESC
     LIMIT 5", [$paginaId]);
    }

    /** Prossimi 6 turni con i posti occupati.
     * @return list<array<string, mixed>> */
    public function prossimiTurni(int $paginaId, string $rbac): array
    {
        return $this->db->righe("SELECT e.titolo, t.nome_turno, t.data_turno, t.orario_inizio,
            t.max_posti AS posti_totali,
            (SELECT COALESCE(SUM(p.num_posti), 0) FROM prenotazioni p
              WHERE p.turno_id = t.id
                AND IFNULL(p.stato, 'confermata') IN ('confermata', 'richiesta_conferma', 'da_approvare')) AS num_iscritti
     FROM turni t
     JOIN eventi e ON t.evento_id = e.id
     WHERE t.data_turno >= CURDATE() AND e.archiviato = 0
       AND e.pagina_id = ? $rbac
     ORDER BY t.data_turno ASC, t.orario_inizio ASC
     LIMIT 6", [$paginaId]);
    }

    /** Prenotazioni per stato (grafico).
     * @return list<array<string, mixed>> */
    public function prenotazioniPerStato(int $paginaId, string $rbac): array
    {
        return $this->db->righe("SELECT p.stato, COUNT(*) as cnt
     FROM prenotazioni p
     JOIN turni t ON p.turno_id = t.id
     JOIN eventi e ON t.evento_id = e.id
     WHERE e.pagina_id = ? $rbac
     GROUP BY p.stato", [$paginaId]);
    }

    /** Prenotazioni arrivate oggi (max 8).
     * @return list<array<string, mixed>> */
    public function prenotazioniDiOggi(int $paginaId, string $rbac): array
    {
        return $this->db->righe("SELECT 'prenotazione' as tipo, p.nome, p.cognome, e.titolo as evento_titolo,
                p.data_prenotazione as quando, p.codice_prenotazione as ref, p.stato
         FROM prenotazioni p
         JOIN turni t ON p.turno_id = t.id
         JOIN eventi e ON t.evento_id = e.id
         WHERE e.pagina_id = ? $rbac
           AND DATE(p.data_prenotazione) = CURDATE()
         ORDER BY p.data_prenotazione DESC LIMIT 8", [$paginaId]);
    }

    /** Messaggi ricevuti oggi (max 5).
     * @return list<array<string, mixed>> */
    public function messaggiDiOggi(int $paginaId, string $rbac): array
    {
        return $this->db->righe("SELECT 'messaggio' as tipo, p.nome, p.cognome, e.titolo as evento_titolo,
                m.data_invio as quando, p.codice_prenotazione as ref, '' as stato
         FROM messaggi_prenotazioni m
         JOIN prenotazioni p ON m.prenotazione_id = p.id
         JOIN turni t ON p.turno_id = t.id
         JOIN eventi e ON t.evento_id = e.id
         WHERE e.pagina_id = ? $rbac
           AND m.mittente_tipo = 'utente'
           AND DATE(m.data_invio) = CURDATE()
         ORDER BY m.data_invio DESC LIMIT 5", [$paginaId]);
    }

    /** @return list<array<string, mixed>> prenotazioni di classi con attestati non ancora inviati (presenze, studenti, data di fine) */
    public function attestatiClassiDaControllare(int $paginaId, string $rbac): array
    {
        return $this->db->righe("SELECT pr.presente, pr.attestato_inviato,
                CASE WHEN e.tipo = 'progetto' THEN d.data_fine ELSE t.data_turno END AS fine,
                (SELECT COUNT(*) FROM partecipanti_prenotazione pp WHERE pp.prenotazione_id = pr.id AND pp.escluso = 0) AS n_studenti
         FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
         JOIN progetti_dettagli d ON d.evento_id = e.id
         WHERE e.pagina_id = ? AND e.archiviato = 0 $rbac
           AND d.attestati = 1 AND IFNULL(pr.stato, 'confermata') = 'confermata' AND IFNULL(pr.attestato_inviato, 0) = 0
           AND ((e.tipo = 'progetto' AND IFNULL(d.per_scuole, 1) = 1) OR (IFNULL(e.tipo, 'evento') <> 'progetto'))", [$paginaId]);
    }
}
