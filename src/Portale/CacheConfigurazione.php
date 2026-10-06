<?php

declare(strict_types=1);

namespace App\Portale;

use App\Core\Sito;

/**
 * Configurazione del portale (riga 1 di configurazione_portale) con cache in cache/configurazione_portale.json.
 * header.php e footer.php la leggevano ad OGNI caricamento pagina: il risultato resta in un file JSON locale, valido
 * finché un admin non salva nuove impostazioni da admin/testata.php (invalidazione esplicita) o comunque non oltre
 * 5 minuti (rete di sicurezza, in caso di modifiche dirette a DB). Se la cartella cache/ non è scrivibile si ricade
 * sulla query diretta: nessun malfunzionamento, solo niente cache.
 * Spostata da get_configurazione_portale() (inc/sistema.php) e invalidate_configurazione_portale_cache() (inc/dati.php).
 */
final class CacheConfigurazione
{
    private const SCADENZA_SECONDI = 300;

    /** @var array<string, mixed>|null memoizzazione per richiesta: header.php e footer.php girano nella stessa richiesta */
    private ?array $memo = null;

    public function __construct(private ConfigurazioneRepository $repo, private Sito $sito)
    {
    }

    /** @return array<string, mixed> */
    public function leggi(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }
        $file = $this->file();
        if (is_file($file) && (time() - (int) filemtime($file)) < self::SCADENZA_SECONDI) {
            $json = @file_get_contents($file);
            $decoded = ($json !== false) ? json_decode($json, true) : null;
            if (is_array($decoded)) {
                return $this->memo = $decoded;
            }
        }

        // Cache assente, scaduta o corrotta: rileggi dal database
        $cfg = $this->repo->riga() ?? [];

        // Riscrittura cache best-effort
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            @file_put_contents($file, json_encode($cfg, JSON_UNESCAPED_UNICODE), LOCK_EX);
        }

        return $this->memo = $cfg;
    }

    /** Da chiamare dopo ogni modifica di configurazione_portale. */
    public function invalida(): void
    {
        $file = $this->file();
        if (is_file($file)) {
            @unlink($file);
        }
    }

    private function file(): string
    {
        return $this->sito->radice() . '/cache/configurazione_portale.json';
    }
}
