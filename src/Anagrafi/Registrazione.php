<?php

declare(strict_types=1);

namespace App\Anagrafi;

use App\Auth\Abilitazioni\PermessiGestoreAree;
use App\Auth\Saml\CollegamentoAnagrafe;
use App\Core\Container;
use App\Infrastructure\Audit\AuditLog;

/** Servizi del modulo Anagrafi nel container: client delle API di Ateneo e collegamenti con Auth (login e abilitazioni). */
final class Registrazione
{
    public static function registra(Container $c): void
    {
        $c->set(ClientApiAteneo::class, static fn (): ClientApiAteneo => new ClientApiAteneoHttp());
        $c->set(RegistroOperazioni::class, static fn (Container $c): RegistroOperazioni => new RegistroDaSessione($c->get(AuditLog::class)));
        // Il modulo Auth chiede ad Anagrafi di collegare l'utente alla persona al login e di scrivere le abilitazioni nei JSON di aree ed eventi
        $c->set(PermessiGestoreAree::class, static fn (Container $c): PermessiGestoreAree => $c->get(PermessiGestori::class));
        $c->set(CollegamentoAnagrafe::class, static fn (Container $c): CollegamentoAnagrafe => $c->get(CollegamentoUtente::class));
    }
}
