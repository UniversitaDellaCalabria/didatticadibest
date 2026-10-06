<?php

declare(strict_types=1);

namespace App\Sistema;

use App\Core\Database;

/** Lettura del registro delle operazioni (tabella log_attivita, scritta da App\Infrastructure\Audit\AuditLog). */
final class RegistroAuditRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return list<array<string, mixed>> ultime operazioni con nome, cognome e codice fiscale di chi le ha fatte */
    public function ultime(int $quante = 1000): array
    {
        return $this->db->righe('SELECT l.*, u.nome, u.cognome, u.codice_fiscale
            FROM log_attivita l
            LEFT JOIN utenti u ON l.utente_id = u.id
            ORDER BY l.data_ora DESC LIMIT ' . $quante);
    }
}
