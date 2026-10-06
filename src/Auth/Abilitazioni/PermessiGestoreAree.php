<?php

declare(strict_types=1);

namespace App\Auth\Abilitazioni;

/** Abilitazioni "tutta l'area" e "singole attività" scritte nei JSON di aree ed eventi (le scrive il modulo Anagrafi). */
interface PermessiGestoreAree
{
    /** Toglie l'utente dai gestori dell'area e delle sue attività. */
    public function revoca(int $paginaId, int $utenteId): void;

    /** @param list<int> $eventiIds */
    public function applica(int $utenteId, string $ambito, int $paginaId, array $eventiIds, int $da): bool;
}
