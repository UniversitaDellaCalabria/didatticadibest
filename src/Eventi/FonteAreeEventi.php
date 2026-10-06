<?php

declare(strict_types=1);

namespace App\Eventi;

use App\Portale\FonteAree;

/** Le aree pubblicate (pagine_eventi visibili, in ordine) per il modulo Portale. */
final class FonteAreeEventi implements FonteAree
{
    public function __construct(private AreaRepository $aree)
    {
    }

    /** @return list<array<string, mixed>> */
    public function areeVisibili(): array
    {
        return $this->aree->visibili();
    }
}
