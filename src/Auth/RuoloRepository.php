<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Database;

/** Ruoli e gruppi degli utenti (tabella ruoli). */
final class RuoloRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return list<array<string, mixed>> tutti i ruoli in ordine di id (spostata da get_ruoli() di inc/dati.php) */
    public function tutti(): array
    {
        return $this->db->righe('SELECT * FROM ruoli ORDER BY id ASC');
    }

    /** Nuovo gruppo creato dal pannello Utenti. */
    public function crea(string $nome): void
    {
        $this->db->esegui('INSERT INTO ruoli (nome) VALUES (?)', [$nome]);
    }

    /** I cinque ruoli di base, se mancano (al login SSO, come prima). */
    public function assicuraRuoliBase(): void
    {
        $this->db->esegui("INSERT IGNORE INTO ruoli (id, nome) VALUES
    (1, 'Amministratore'),
    (2, 'Gestore Prenotazioni'),
    (3, 'Studenti'),
    (4, 'Dipendenti'),
    (5, 'Esterni / Ospiti')");
    }
}
