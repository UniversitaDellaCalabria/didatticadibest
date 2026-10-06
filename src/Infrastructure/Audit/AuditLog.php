<?php

declare(strict_types=1);

namespace App\Infrastructure\Audit;

use App\Core\Database;

/** Registro delle operazioni degli amministratori e del personale (tabella log_attivita). */
final class AuditLog
{
    public function __construct(private Database $db)
    {
    }

    /** @param array<string, mixed> $dettagli */
    public function registra(int $utenteId, string $azione, array $dettagli, string $ip): bool
    {
        if ($utenteId <= 0) {
            return false;
        }
        $json = $dettagli ? json_encode($dettagli, JSON_UNESCAPED_UNICODE) : null;

        return $this->db->esegui(
            'INSERT INTO log_attivita (utente_id, azione, dettagli_json, indirizzo_ip) VALUES (?, ?, ?, ?)',
            [$utenteId, $azione, $json, $ip]
        ) > 0;
    }
}
