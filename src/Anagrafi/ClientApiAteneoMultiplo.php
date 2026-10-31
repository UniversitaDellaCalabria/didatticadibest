<?php

declare(strict_types=1);

namespace App\Anagrafi;

/** Client che sa fare più chiamate GET insieme (per scaricare il dettaglio di migliaia di insegnamenti in pochi minuti). */
interface ClientApiAteneoMultiplo
{
    /**
     * Più chiamate GET in parallelo: percorso => risposta (array vuoto se la risorsa non esiste, null se la chiamata non va).
     *
     * @param list<string> $percorsi percorsi relativi alle API, es. "activities/123/"
     * @return array<string, array<mixed>|null>
     */
    public function getMolti(array $percorsi, int $timeout = 25): array;
}
