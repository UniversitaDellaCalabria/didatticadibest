<?php

declare(strict_types=1);

namespace App\Eventi;

use App\Anagrafi\AnagrafePerEventi;
use App\Core\Container;

/** Servizi del modulo Eventi nel container (scoperta da App\Core\App). */
final class Registrazione
{
    public static function registra(Container $c): void
    {
        // Le anagrafi di Ateneo le fornisce il modulo Anagrafi
        $c->set(Anagrafe::class, static fn (Container $c): Anagrafe => $c->get(AnagrafePerEventi::class));
        // L'agenda degli eventi
        $c->set(FonteEventiAgenda::class, static fn (Container $c): FonteEventiAgenda => $c->get(ServizioAgenda::class));
    }
}
