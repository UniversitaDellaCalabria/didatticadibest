<?php

declare(strict_types=1);

namespace App\Iscritti;

/** Messaggio da mostrare dopo un'azione del pannello (il controller lo passa a flash_set()). */
final class EsitoAzione
{
    public function __construct(
        public readonly string $messaggio,
        public readonly string $tipo = 'success',
    ) {
    }
}
