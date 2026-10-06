<?php

declare(strict_types=1);

namespace App\Portale;

/** Aree pubblicate (pagine_eventi visibili, in ordine): le fornisce il modulo Eventi. */
interface FonteAree
{
    /** @return list<array<string, mixed>> righe di pagine_eventi */
    public function areeVisibili(): array;
}
