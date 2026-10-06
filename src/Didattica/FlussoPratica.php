<?php

declare(strict_types=1);

namespace App\Didattica;

/** Cosa succede subito dopo l'invio di una pratica: domanda in PDF/A e primo ufficio dell'iter (es. il protocollo). */
interface FlussoPratica
{
    /**
     * @param array<string, mixed> $modulo
     * @param array<string, mixed> $utente
     */
    public function avvia(int $pratica, array $modulo, array $utente): void;
}
