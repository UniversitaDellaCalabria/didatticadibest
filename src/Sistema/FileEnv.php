<?php

declare(strict_types=1);

namespace App\Sistema;

/**
 * Valori del file .env (config.php lo legge solo per il database e poi lo scarta): spostato da env_valore() di inc/sistema.php.
 * Lettura "grezza": "no"/"yes"/"true" restano testo e le password possono contenere ! ; = senza rompere il file.
 * Il file si legge una sola volta, alla prima richiesta.
 */
final class FileEnv
{
    /** @var array<string, mixed>|null */
    private ?array $valori = null;

    public function __construct(private string $percorso)
    {
    }

    /** Valore della chiave; null se assente o vuoto. */
    public function valore(string $chiave): ?string
    {
        if ($this->valori === null) {
            // Righe con # scartate: nei file ini il commento è ; (vedi config.php)
            $this->valori = @parse_ini_string((string) preg_replace('/^\s*#.*$/m', '', (string) @file_get_contents($this->percorso)), false, INI_SCANNER_RAW) ?: [];
        }
        $v = trim((string) ($this->valori[$chiave] ?? ''));
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) {
            $v = substr($v, 1, -1); // valore tra virgolette
        }

        return $v === '' ? null : $v;
    }
}
