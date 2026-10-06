<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Configurazione del portale in sola lettura: le stesse chiavi del file .env (o .env.locale nell'ambiente di prova)
 * lette da config.php. Le righe che iniziano con # si scartano prima della lettura, come in config.php,
 * perché nei file ini # non è un commento valido e farebbe fallire la lettura di tutto il file.
 */
final class Config
{
    /** @param array<string, string> $valori */
    public function __construct(private array $valori = [])
    {
    }

    public static function daFile(string $percorso): self
    {
        $testo = is_file($percorso) ? (string) file_get_contents($percorso) : '';
        $valori = @parse_ini_string((string) preg_replace('/^\s*#.*$/m', '', $testo)) ?: [];

        return new self(array_map('strval', $valori));
    }

    public function ha(string $chiave): bool
    {
        return array_key_exists($chiave, $this->valori);
    }

    public function testo(string $chiave, string $predefinito = ''): string
    {
        return $this->valori[$chiave] ?? $predefinito;
    }

    public function intero(string $chiave, int $predefinito = 0): int
    {
        $v = $this->valori[$chiave] ?? null;

        return is_numeric($v) ? (int) $v : $predefinito;
    }

    /** Vero per 1, true, yes, on, sì (come le opzioni del .env del portale, es. INCARICHI_SOLO_SPID_CIE=1). */
    public function booleano(string $chiave, bool $predefinito = false): bool
    {
        if (!$this->ha($chiave)) {
            return $predefinito;
        }

        return in_array(strtolower(trim($this->valori[$chiave])), ['1', 'true', 'yes', 'on', 'si', 'sì'], true);
    }
}
