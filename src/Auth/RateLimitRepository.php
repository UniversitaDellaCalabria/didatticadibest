<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Database;

/** Tentativi per IP (hashato) e pagina, tabella rate_limit_attempts. */
final class RateLimitRepository
{
    public function __construct(private Database $db)
    {
    }

    /** Pulisce i record più vecchi di un'ora, per tenere la tabella piccola. */
    public function pulisci(): void
    {
        $this->db->esegui('DELETE FROM rate_limit_attempts WHERE hit_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)');
    }

    /** Tentativi nella finestra temporale corrente. */
    public function conta(string $ipHash, string $endpoint, int $finestraSecondi): int
    {
        return (int) $this->db->valore(
            'SELECT COUNT(*) AS hits FROM rate_limit_attempts
             WHERE ip_hash = ? AND endpoint = ? AND hit_at > DATE_SUB(NOW(), INTERVAL ? SECOND)',
            [$ipHash, $endpoint, $finestraSecondi]
        );
    }

    public function registra(string $ipHash, string $endpoint): void
    {
        $this->db->esegui('INSERT INTO rate_limit_attempts (ip_hash, endpoint, hit_at) VALUES (?, ?, NOW())', [$ipHash, $endpoint]);
    }
}
