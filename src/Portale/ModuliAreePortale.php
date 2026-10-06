<?php

declare(strict_types=1);

namespace App\Portale;

use App\Auth\Abilitazioni\ModuliAree;

/** Il modulo del portale a cui appartiene un'area, per le abilitazioni del modulo Auth. */
final class ModuliAreePortale implements ModuliAree
{
    public function disponibile(): bool
    {
        return true;
    }

    public function moduloDiArea(array $pagina): string
    {
        return Sezioni::moduloDiArea($pagina);
    }
}
