<?php

declare(strict_types=1);

namespace Tests\Doppi;

use App\Auth\Abilitazioni\ModuliAree;

/** Moduli delle aree dei test: tutto è «orientamento» salvo indicazione. */
final class DidatticaModuliAree implements ModuliAree
{
    public function disponibile(): bool
    {
        return true;
    }

    public function moduloDiArea(array $pagina): string
    {
        return (string) ($pagina['modulo'] ?? 'orientamento');
    }
}
