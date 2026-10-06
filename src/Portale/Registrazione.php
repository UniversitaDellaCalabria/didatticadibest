<?php

declare(strict_types=1);

namespace App\Portale;

use App\Core\Container;
use App\Eventi\FonteAreeEventi;
use App\Infrastructure\Mail\ImpaginatoreEmail;

/** Servizi del modulo Portale nel container (scoperta da App\Core\App). */
final class Registrazione
{
    public static function registra(Container $c): void
    {
        // L'impaginazione delle email
        $c->set(ImpaginatoreEmail::class, static fn (): ImpaginatoreEmail => new ImpaginatoreEmailPortale());
        // Le aree pubblicate le fornisce il modulo Eventi
        $c->set(FonteAree::class, static fn (Container $c): FonteAree => $c->get(FonteAreeEventi::class));
        $c->set(ConfigurazioneHome::class, static fn (Container $c): ConfigurazioneHome => $c->get(WidgetHome::class));
    }
}
