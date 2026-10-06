<?php

declare(strict_types=1);

namespace App\Sistema\Backup;

use App\Core\Sito;

/** Esito dell'ultimo backup, in cache/backup_stato.json (scritto da admin/cron_backup.php). */
final class StatoBackup
{
    public function __construct(private Sito $sito)
    {
    }

    /**
     * Lo stato dell'ultimo backup ([] se non è mai stato eseguito).
     *
     * @return array{data?: string, ok?: bool, ultima_email?: string, nas?: array<string, mixed>}
     */
    public function leggi(): array
    {
        $f = $this->file();

        return is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    }

    /** @param array<string, mixed> $stato */
    public function scrivi(array $stato): void
    {
        $cache = $this->sito->radice() . '/cache';
        if (!is_dir($cache)) {
            @mkdir($cache, 0755, true);
        }
        @file_put_contents($this->file(), json_encode($stato, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function file(): string
    {
        return $this->sito->radice() . '/cache/backup_stato.json';
    }
}
