<?php

declare(strict_types=1);

namespace App\Risorse;

use App\Core\Container;
use App\Eventi\AuleTurni;
use App\Iscritti\PromemoriaRisorse;

/** Servizi del modulo Risorse nel container (scoperta da App\Core\App). */
final class Registrazione
{
    public static function registra(Container $c): void
    {
        // Le aule dei turni degli eventi sono delle Risorse
        $c->set(AuleTurni::class, static fn (Container $c): AuleTurni => $c->get(ServizioAuleTurni::class));
        // I promemoria delle prenotazioni girano con il cron degli iscritti
        $c->set(PromemoriaRisorse::class, static fn (Container $c): PromemoriaRisorse => $c->get(PromemoriaPrenotazioni::class));
    }
}
