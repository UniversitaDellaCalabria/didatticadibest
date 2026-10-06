<?php

declare(strict_types=1);

namespace App\Sistema;

use App\Auth\ServizioUtenti;
use App\Core\Container;
use App\Core\Database;
use App\Core\Sito;
use App\Infrastructure\Mail\Mailer;
use App\Sistema\Backup\StatoBackup;

/** Servizi del modulo Sistema che ricevono valori non di classe (ambiente locale). */
final class Registrazione
{
    public static function registra(Container $c): void
    {
        // In locale il server di sviluppo serve una richiesta alla volta: niente controlli HTTP delle pagine
        $c->set(ControlloSito::class, static fn (Container $c): ControlloSito => new ControlloSito(
            $c->get(Database::class),
            $c->get(Sito::class),
            $c->get(StatoBackup::class),
            $c->get(LogEmailRepository::class),
            $c->get(ServizioUtenti::class),
            $c->get(Mailer::class),
            defined('AMBIENTE_LOCALE') && AMBIENTE_LOCALE,
        ));
    }
}
