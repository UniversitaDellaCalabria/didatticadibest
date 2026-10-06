<?php

declare(strict_types=1);

namespace App\Iscrizioni;

use App\Core\Container;
use App\Core\Database;
use App\Eventi\PresentazioneComune;
use App\Eventi\RegoleIscrizioni;
use App\Infrastructure\Audit\AuditLog;

/** Servizi del modulo Iscrizioni nel container (scoperta da App\Core\App). */
final class Registrazione
{
    public static function registra(Container $c): void
    {
        $c->set(RegistroOperazioni::class, static fn (Container $c): RegistroOperazioni => new RegistroDaSessione($c->get(AuditLog::class)));
        // I campi «Scuola» e «Corso di studio» segnalano alla pagina (variabile globale letta dal piè di pagina) che serve il loro script
        $c->set(CampiAnagrafe::class, static fn (Container $c): CampiAnagrafe => new CampiAnagrafeIscrizioni($c->get(Database::class)));
        // Le regole delle iscrizioni e i testi dei posti usati dagli eventi
        $c->set(RegoleIscrizioni::class, static fn (Container $c): RegoleIscrizioni => $c->get(ServizioRegoleIscrizioni::class));
        $c->set(PresentazioneComune::class, static fn (Container $c): PresentazioneComune => $c->get(PresentazioneIscrizioni::class));
    }
}
