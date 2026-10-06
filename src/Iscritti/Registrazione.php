<?php

declare(strict_types=1);

namespace App\Iscritti;

use App\Attestati\AttestatiPerIscritti;
use App\Core\Container;
use App\Iscrizioni\IscrizioniPerIscritti;

/** Servizi del modulo Iscritti nel container (scoperta da App\Core\App). */
final class Registrazione
{
    public static function registra(Container $c): void
    {
        // Iscrizioni e attestati li forniscono i loro moduli
        $c->set(Iscrizioni::class, static fn (Container $c): Iscrizioni => $c->get(IscrizioniPerIscritti::class));
        $c->set(Attestati::class, static fn (Container $c): Attestati => $c->get(AttestatiPerIscritti::class));
    }
}
