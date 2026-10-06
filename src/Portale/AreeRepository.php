<?php

declare(strict_types=1);

namespace App\Portale;

use App\Core\Database;

/**
 * Aree di lavoro del pannello di amministrazione (tabella pagine_eventi): elenco, visibilità, eliminazione completa
 * e i conteggi mostrati nel menu laterale. Il filtro sulle attività del gestore ($rbac) arriva già come condizione SQL
 * (" AND e.id IN (...) "), costruita da admin_header.php con id interi.
 */
final class AreeRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return list<array<string, mixed>> tutte le aree in ordine */
    public function tutte(): array
    {
        return $this->db->righe('SELECT * FROM pagine_eventi ORDER BY ordine ASC, id ASC');
    }

    /** @return list<array<string, mixed>> titolo e slug di tutte le aree, in ordine alfabetico (per scegliere la pagina di una voce di menu) */
    public function titoliESlug(): array
    {
        return $this->db->righe('SELECT titolo, slug FROM pagine_eventi ORDER BY titolo ASC');
    }

    /** @return array<string, mixed>|null */
    public function perId(int $id): ?array
    {
        return $this->db->riga('SELECT * FROM pagine_eventi WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null slug e titolo dell'area */
    public function slugETitolo(int $id): ?array
    {
        return $this->db->riga('SELECT slug, titolo FROM pagine_eventi WHERE id = ? LIMIT 1', [$id]);
    }

    public function impostaVisibile(int $id, bool $visibile): void
    {
        $this->db->esegui('UPDATE pagine_eventi SET visibile = ? WHERE id = ?', [$visibile ? 1 : 0, $id]);
    }

    /** @return list<int> */
    public function idAttivita(int $paginaId): array
    {
        return array_map(static fn (array $r): int => (int) $r['id'], $this->db->righe('SELECT id FROM eventi WHERE pagina_id = ?', [$paginaId]));
    }

    /** Dati propri dell'area (campi del modulo, sottocategorie, attività rimaste, voce di menu e area stessa). */
    public function eliminaDatiDellArea(int $paginaId, string $urlMenu): void
    {
        $this->db->esegui('DELETE FROM campi_form WHERE pagina_id = ?', [$paginaId]);
        $this->db->esegui('DELETE FROM sottocategorie WHERE pagina_id = ?', [$paginaId]);
        $this->db->esegui('DELETE FROM eventi WHERE pagina_id = ?', [$paginaId]);
        $this->db->esegui('DELETE FROM menu_voci WHERE url = ?', [$urlMenu]);
        $this->db->esegui('DELETE FROM pagine_eventi WHERE id = ?', [$paginaId]);
    }

    /** @return array<string, bool> 'progetto' / 'evento': ci sono attività di quel tipo nel perimetro dell'utente */
    public function tipiAttivita(int $paginaId, string $rbac): array
    {
        $tipi = ['progetto' => false, 'evento' => false];
        foreach ($this->db->righe("SELECT DISTINCT IF(e.tipo = 'progetto', 'progetto', 'evento') AS t FROM eventi e WHERE e.pagina_id = ? $rbac", [$paginaId]) as $x) {
            $tipi[$x['t']] = true;
        }

        return $tipi;
    }

    /** Ci sono attività con studenti o classi da gestire (progetti per le scuole o eventi con attestati). */
    public function haAttivitaConClassi(int $paginaId, string $rbac): bool
    {
        return $this->db->riga("SELECT 1 FROM eventi e LEFT JOIN progetti_dettagli d ON d.evento_id = e.id WHERE e.pagina_id = ? AND e.archiviato = 0 $rbac
                                          AND ((e.tipo = 'progetto' AND IFNULL(d.per_scuole, 1) = 1) OR (IFNULL(e.tipo, 'evento') <> 'progetto' AND d.attestati = 1)) LIMIT 1", [$paginaId]) !== null;
    }

    /** Prenotazioni di risorse da approvare (aree di tipo calendario). */
    public function prenotazioniDaApprovare(int $paginaId): int
    {
        return (int) $this->db->valore("SELECT COUNT(*) n FROM prenotazioni_risorse pr JOIN risorse r ON r.id = pr.risorsa_id WHERE r.pagina_id = ? AND pr.stato = 'da_approvare' AND pr.fine >= NOW()", [$paginaId]);
    }

    /** Prenotazioni con messaggi dell'utente non ancora letti (per il badge del menu). */
    public function messaggiNonLetti(int $paginaId, string $rbac): int
    {
        return (int) $this->db->valore("SELECT COUNT(DISTINCT m.prenotazione_id) as total_unread FROM messaggi_prenotazioni m JOIN prenotazioni p ON m.prenotazione_id = p.id JOIN turni t ON p.turno_id = t.id JOIN eventi e ON t.evento_id = e.id WHERE m.letto = 0 AND m.mittente_tipo = 'utente' AND e.pagina_id = ? $rbac", [$paginaId]);
    }

    /**
     * Numeri di un'area per la pagina iniziale: risorse e prenotazioni (calendari) oppure eventi, progetti e iscritti.
     *
     * @return array<string, mixed>|null null se la query non riesce
     */
    public function numeriArea(int $id, bool $calendario): ?array
    {
        if ($calendario) {
            return $this->db->riga("SELECT (SELECT COUNT(*) FROM risorse WHERE pagina_id = ? AND attiva = 1) AS risorse,
                (SELECT COUNT(*) FROM prenotazioni_risorse pr JOIN risorse r ON r.id = pr.risorsa_id WHERE r.pagina_id = ? AND pr.stato = 'confermata' AND pr.inizio >= NOW()) AS prossime,
                (SELECT COUNT(*) FROM prenotazioni_risorse pr JOIN risorse r ON r.id = pr.risorsa_id WHERE r.pagina_id = ? AND pr.stato = 'da_approvare' AND pr.inizio >= NOW()) AS da_approvare", [$id, $id, $id]);
        }

        return $this->db->riga("SELECT
            (SELECT COUNT(*) FROM eventi e WHERE e.pagina_id = ? AND e.archiviato = 0 AND IFNULL(e.tipo, 'evento') <> 'progetto') AS eventi,
            (SELECT COUNT(*) FROM eventi e WHERE e.pagina_id = ? AND e.archiviato = 0 AND e.tipo = 'progetto') AS progetti,
            (SELECT COUNT(*) FROM prenotazioni p JOIN turni t ON p.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
              WHERE e.pagina_id = ? AND e.archiviato = 0 AND IFNULL(p.stato, 'confermata') = 'confermata') AS iscritti", [$id, $id, $id]);
    }
}
