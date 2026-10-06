<?php

declare(strict_types=1);

namespace App\Anagrafi;

/** Chiamate alle API pubbliche del portale di Ateneo (rubrica, docenti, corsi, insegnamenti). Nei test si simula. */
interface ClientApiAteneo
{
    /**
     * Una chiamata GET (percorso relativo alle API, es. "teachers/"): null se la rete o la risposta non vanno.
     *
     * @param array<string, mixed> $query
     * @return array<mixed>|null
     */
    public function get(string $percorso, array $query = [], int $timeout = 25): ?array;

    /**
     * Tutte le pagine di un elenco ("results"): null se una pagina non arriva, così non si scambia un errore per "nessuno".
     *
     * @param array<string, mixed> $query
     * @return list<array<string, mixed>>|null
     */
    public function tutte(string $percorso, array $query = []): ?array;

    /** Contenuto di un file (es. la foto di una persona), null se non arriva. */
    public function scarica(string $url, int $timeout = 10): ?string;
}
