<?php

declare(strict_types=1);

namespace App\Assistenza;

use App\Assistenza\Push\ClienteCurl;
use App\Assistenza\Push\ClienteHttp;
use App\Core\Container;

/** Servizi del modulo Assistenza nel container (scoperta da App\Core\App). */
final class Registrazione
{
    public static function registra(Container $c): void
    {
        // Le notifiche push partono con curl; nelle prove si sostituisce con un finto servizio push
        $c->set(ClienteHttp::class, static fn (): ClienteHttp => new ClienteCurl());
    }
}
