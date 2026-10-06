<?php

declare(strict_types=1);

namespace Tests\Doppi;

use App\Anagrafi\ClientApiAteneo;

/** API di Ateneo dei test: risposte preparate per percorso (e parametri), nessuna rete. null = API non raggiungibili. */
final class AnagrafiClientApiFinto implements ClientApiAteneo
{
    /** @var array<string, array<string, mixed>|null> risposte di get(), per "percorso" */
    public array $risposte = [];

    /** @var array<string, list<array<string, mixed>>|null> elenchi di tutte(), per "percorso?chiave=valore&…" o solo "percorso" */
    public array $elenchi = [];

    /** @var array<string, string> contenuto scaricato, per url */
    public array $file = [];

    /** @var list<string> chiamate ricevute */
    public array $chiamate = [];

    public function get(string $percorso, array $query = [], int $timeout = 25): ?array
    {
        $this->chiamate[] = "get $percorso";

        return $this->risposte[$percorso] ?? null;
    }

    public function tutte(string $percorso, array $query = []): ?array
    {
        $chiave = $percorso . ($query ? '?' . http_build_query($query) : '');
        $this->chiamate[] = "tutte $chiave";
        if (array_key_exists($chiave, $this->elenchi)) {
            return $this->elenchi[$chiave];
        }

        return array_key_exists($percorso, $this->elenchi) ? $this->elenchi[$percorso] : [];
    }

    public function scarica(string $url, int $timeout = 10): ?string
    {
        $this->chiamate[] = "scarica $url";

        return $this->file[$url] ?? null;
    }
}
