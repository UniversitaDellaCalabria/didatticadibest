<?php

declare(strict_types=1);

namespace App\Auth\Abilitazioni;

use App\Core\Database;

/** Abilitazioni date a chi non ha ancora fatto accesso: si attivano al primo login con quell'email (tabella abilitazioni_attesa). */
final class AbilitazioniAttesaRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return list<array<string, mixed>> quelle dell'area e quelle FSL / dei moduli (pagina_id 0), dalla più recente */
    public function perArea(int $paginaId): array
    {
        return $this->db->righe('SELECT * FROM abilitazioni_attesa WHERE pagina_id IN (?, 0) ORDER BY created_at DESC', [$paginaId]);
    }

    /** Email dell'abilitazione in attesa (se è dell'area o FSL), null se non c'è. */
    public function emailDi(int $id, int $paginaId): ?string
    {
        $r = $this->db->riga('SELECT email FROM abilitazioni_attesa WHERE id = ? AND pagina_id IN (?, 0)', [$id, $paginaId]);

        return $r ? (string) $r['email'] : null;
    }

    /** Toglie tutte le abilitazioni in attesa di quell'email nell'area e quelle FSL. */
    public function eliminaPerEmail(string $email, int $paginaId): void
    {
        $this->db->esegui('DELETE FROM abilitazioni_attesa WHERE email = ? AND pagina_id IN (?, 0)', [$email, $paginaId]);
    }

    public function inserisci(string $email, ?string $personaId, string $nominativo, int $paginaId, string $eventiIds, int $da, string $ambito): void
    {
        $this->db->esegui(
            "INSERT INTO abilitazioni_attesa (email, persona_id, nominativo, pagina_id, permessi, eventi_ids, creata_da, ambito) VALUES (?, ?, ?, ?, 'full', ?, ?, ?)",
            [$email, $personaId, $nominativo, $paginaId, $eventiIds, $da, $ambito]
        );
    }
}
