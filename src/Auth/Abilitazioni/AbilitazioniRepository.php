<?php

declare(strict_types=1);

namespace App\Auth\Abilitazioni;

use App\Core\Database;

/**
 * Chi lavora su aree e attività: gestori salvati nelle aree (pagine_eventi) e negli eventi, perimetri
 * (tabella abilitazioni_ambito) e destinatari delle notifiche sulle prenotazioni. Le tabelle possono mancare
 * (installazioni vecchie): in quel caso le letture restituiscono vuoto, come prima.
 */
final class AbilitazioniRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string, mixed>|null gestore singolo, CSV e JSON dei gestori dell'area */
    public function gestoriArea(int $paginaId): ?array
    {
        return $this->db->riga('SELECT gestore_utente_id, gestori_utenti_ids, permessi_gestori_json FROM pagine_eventi WHERE id = ? LIMIT 1', [$paginaId]);
    }

    /** @return list<array<string, mixed>> CSV e JSON dei gestori di ogni attività dell'area */
    public function gestoriAttivitaArea(int $paginaId): array
    {
        return $this->db->righe('SELECT gestori_utenti_ids, permessi_gestori_json FROM eventi WHERE pagina_id = ?', [$paginaId]);
    }

    /** @return list<array<string, mixed>> gestori di tutte le aree */
    public function gestoriTutteLeAree(): array
    {
        return $this->db->righe('SELECT gestore_utente_id, gestori_utenti_ids, permessi_gestori_json FROM pagine_eventi');
    }

    /** @return list<array<string, mixed>> gestori delle attività non archiviate */
    public function gestoriAttivitaNonArchiviate(): array
    {
        return $this->db->righe('SELECT gestori_utenti_ids, permessi_gestori_json FROM eventi WHERE archiviato = 0');
    }

    /** @return array<string, mixed>|null gestori dell'attività (ev_csv, ev_json) e della sua area (p_csv, p_json), con pagina_id */
    public function gestoriAttivita(int $eventoId): ?array
    {
        return $this->db->riga('SELECT e.pagina_id, e.gestori_utenti_ids AS ev_csv, e.permessi_gestori_json AS ev_json,
                                    pe.gestore_utente_id, pe.gestori_utenti_ids AS p_csv, pe.permessi_gestori_json AS p_json
                             FROM eventi e JOIN pagine_eventi pe ON e.pagina_id = pe.id WHERE e.id = ? LIMIT 1', [$eventoId]);
    }

    /** Permessi (JSON) dei gestori dell'area, con la lista legacy; $csv null = quella lista non si tocca. */
    public function salvaGestoriArea(int $paginaId, string $json, ?string $csv = null): void
    {
        if ($csv === null) {
            $this->db->esegui('UPDATE pagine_eventi SET permessi_gestori_json = ? WHERE id = ?', [$json, $paginaId]);
        } else {
            $this->db->esegui('UPDATE pagine_eventi SET permessi_gestori_json = ?, gestori_utenti_ids = ? WHERE id = ?', [$json, $csv, $paginaId]);
        }
    }

    /** @return array<string, mixed>|null permessi_gestori_json dell'attività, se appartiene all'area */
    public function permessiAttivita(int $eventoId, int $paginaId): ?array
    {
        return $this->db->riga('SELECT permessi_gestori_json FROM eventi WHERE id = ? AND pagina_id = ? LIMIT 1', [$eventoId, $paginaId]);
    }

    public function salvaGestoriAttivita(int $eventoId, string $json, ?string $csv = null): void
    {
        if ($csv === null) {
            $this->db->esegui('UPDATE eventi SET permessi_gestori_json = ? WHERE id = ?', [$json, $eventoId]);
        } else {
            $this->db->esegui('UPDATE eventi SET permessi_gestori_json = ?, gestori_utenti_ids = ? WHERE id = ?', [$json, $csv, $eventoId]);
        }
    }

    /** @return array<string, mixed>|null riga di notifiche_gestori_ids dell'area (null = area inesistente) */
    public function notificheGestori(int $paginaId): ?array
    {
        return $this->db->riga('SELECT notifiche_gestori_ids FROM pagine_eventi WHERE id = ? LIMIT 1', [$paginaId]);
    }

    public function impostaNotificheGestori(int $paginaId, string $csv): void
    {
        $this->db->esegui('UPDATE pagine_eventi SET notifiche_gestori_ids = ? WHERE id = ?', [$csv, $paginaId]);
    }

    /** Il gestore principale (campo legacy) dell'area, se è quell'utente, viene tolto. */
    public function togliGestorePrincipale(int $paginaId, int $utenteId): void
    {
        $this->db->esegui('UPDATE pagine_eventi SET gestore_utente_id = 0 WHERE id = ? AND gestore_utente_id = ?', [$paginaId, $utenteId]);
    }

    /** @return list<array<string, mixed>> attività dell'area (id, titolo, tipo, archiviato), le attive prima */
    public function attivitaDellArea(int $paginaId): array
    {
        return $this->db->righe("SELECT id, titolo, IFNULL(tipo, 'evento') AS tipo, archiviato FROM eventi WHERE pagina_id = ? ORDER BY archiviato ASC, ordine ASC, id DESC", [$paginaId]);
    }

    /** @return list<array<string, mixed>> gestori (id, titolo, CSV e JSON) delle attività dell'area */
    public function gestoriAttivitaAreaConTitolo(int $paginaId): array
    {
        return $this->db->righe('SELECT id, titolo, gestori_utenti_ids, permessi_gestori_json FROM eventi WHERE pagina_id = ?', [$paginaId]);
    }

    /** @return list<array<string, mixed>> perimetri (utente_id, tipo, pagina_id) dell'area e quelli senza area (FSL, moduli) */
    public function perimetriArea(int $paginaId): array
    {
        return $this->db->righe('SELECT utente_id, tipo, pagina_id FROM abilitazioni_ambito WHERE pagina_id IN (?, 0)', [$paginaId]);
    }

    /** @return list<int> utenti con un perimetro qualsiasi in quell'area */
    public function utentiConPerimetriInArea(int $paginaId): array
    {
        return $this->interi($this->db->righe('SELECT DISTINCT utente_id FROM abilitazioni_ambito WHERE pagina_id = ?', [$paginaId]));
    }

    /** @return list<array{tipo: string, pagina_id: int}> perimetri dell'utente */
    public function ambitiDi(int $utenteId): array
    {
        $out = [];
        foreach ($this->db->righe('SELECT tipo, pagina_id FROM abilitazioni_ambito WHERE utente_id = ?', [$utenteId]) as $x) {
            $out[] = ['tipo' => (string) $x['tipo'], 'pagina_id' => (int) $x['pagina_id']];
        }

        return $out;
    }

    /** @return list<int> utenti con il perimetro progetti o eventi dell'area */
    public function utentiConTipiArea(int $paginaId): array
    {
        return $this->interi($this->db->righe("SELECT DISTINCT utente_id FROM abilitazioni_ambito WHERE pagina_id = ? AND tipo IN ('progetti', 'eventi')", [$paginaId]));
    }

    /**
     * Utenti con almeno uno dei perimetri indicati.
     *
     * @param list<array{0: string, 1: int|null}> $perimetri [tipo, pagina_id] (pagina_id null = in qualunque area)
     * @return list<int>
     */
    public function utentiConPerimetri(array $perimetri): array
    {
        if (!$perimetri) {
            return [];
        }
        $cond = [];
        $par = [];
        foreach ($perimetri as [$tipo, $pagina]) {
            if ($pagina === null) {
                $cond[] = 'tipo = ?';
                $par[] = $tipo;
            } else {
                $cond[] = '(tipo = ? AND pagina_id = ?)';
                $par[] = $tipo;
                $par[] = $pagina;
            }
        }

        return $this->interi($this->db->righe('SELECT DISTINCT utente_id FROM abilitazioni_ambito WHERE ' . implode(' OR ', $cond), $par));
    }

    /** INSERT IGNORE del perimetro; false se la query non riesce. */
    public function inserisciAmbito(int $utenteId, string $tipo, int $paginaId, int $da): bool
    {
        return $this->db->esegui('INSERT IGNORE INTO abilitazioni_ambito (utente_id, tipo, pagina_id, creata_da) VALUES (?, ?, ?, ?)', [$utenteId, $tipo, $paginaId, $da]) >= 0;
    }

    /** Toglie un perimetro ($tipo) o tutti quelli dell'utente nell'area ($tipo null). */
    public function eliminaAmbito(int $utenteId, ?string $tipo, int $paginaId): void
    {
        if ($tipo !== null) {
            $this->db->esegui('DELETE FROM abilitazioni_ambito WHERE utente_id = ? AND tipo = ? AND pagina_id = ?', [$utenteId, $tipo, $paginaId]);
        } else {
            $this->db->esegui('DELETE FROM abilitazioni_ambito WHERE utente_id = ? AND pagina_id = ?', [$utenteId, $paginaId]);
        }
    }

    /** @return array<string, mixed>|null riga completa dell'area */
    public function area(int $paginaId): ?array
    {
        return $this->db->riga('SELECT * FROM pagine_eventi WHERE id = ?', [$paginaId]);
    }

    /** @return list<array<string, mixed>> tutte le aree (righe complete) */
    public function tutteLeAree(): array
    {
        return $this->db->righe('SELECT * FROM pagine_eventi');
    }

    /**
     * ID delle attività dell'area che soddisfano la condizione (alias e = eventi, prodotta da sqlAttivitaAmbiti).
     *
     * @return list<int>
     */
    public function attivitaArea(int $paginaId, string $condizione): array
    {
        return $this->interi($this->db->righe("SELECT e.id FROM eventi e WHERE e.pagina_id = ? AND $condizione", [$paginaId]), 'id');
    }

    /** @return list<int> aree che contengono almeno un'attività FSL */
    public function areeConAttivitaFsl(): array
    {
        return $this->interi($this->db->righe('SELECT DISTINCT e.pagina_id FROM eventi e JOIN progetti_dettagli pd ON pd.evento_id = e.id WHERE pd.convenzione = 1'), 'pagina_id');
    }

    /** @return array<string, mixed>|null area, tipo (progetto/evento) e FSL dell'attività */
    public function tipoAttivita(int $eventoId): ?array
    {
        return $this->db->riga("SELECT e.pagina_id, IFNULL(e.tipo, 'evento') AS tipo, IFNULL(pd.convenzione, 0) AS fsl
                           FROM eventi e LEFT JOIN progetti_dettagli pd ON pd.evento_id = e.id WHERE e.id = ? LIMIT 1", [$eventoId]);
    }

    /** L'attività (o il turno, o la prenotazione) appartiene all'area e rispetta il filtro dei permessi del gestore ($rbac, SQL). */
    public function attivitaVisibile(int $eventoId, int $paginaId, string $rbac): bool
    {
        return $this->db->riga("SELECT 1 FROM eventi e WHERE e.id = ? AND e.pagina_id = ? $rbac LIMIT 1", [$eventoId, $paginaId]) !== null;
    }

    public function turnoVisibile(int $turnoId, int $paginaId, string $rbac): bool
    {
        return $this->db->riga("SELECT 1 FROM turni t JOIN eventi e ON t.evento_id = e.id WHERE t.id = ? AND e.pagina_id = ? $rbac LIMIT 1", [$turnoId, $paginaId]) !== null;
    }

    public function prenotazioneVisibile(int $prenotazioneId, int $paginaId, string $rbac): bool
    {
        return $this->db->riga("SELECT 1 FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
                           WHERE pr.id = ? AND e.pagina_id = ? $rbac LIMIT 1", [$prenotazioneId, $paginaId]) !== null;
    }

    /**
     * @param list<array<string, mixed>> $righe
     * @return list<int>
     */
    private function interi(array $righe, string $campo = 'utente_id'): array
    {
        return array_map(static fn (array $r): int => (int) $r[$campo], $righe);
    }
}
