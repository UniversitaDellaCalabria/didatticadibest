<?php

declare(strict_types=1);

namespace App\Auth;

use App\Auth\Abilitazioni\ModuliAree;
use App\Core\Container;
use App\Portale\ModuliAreePortale;

/** Servizi del modulo Auth nel container: sessione PHP e modulo delle aree. Le abilitazioni nei JSON e il collegamento all'anagrafe sono di Anagrafi. */
final class Registrazione
{
    public static function registra(Container $c): void
    {
        $c->set(Sessione::class, static fn (): Sessione => new SessioneNativa());
        $c->set(ModuliAree::class, static fn (): ModuliAree => new ModuliAreePortale());
    }
}
