<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Core\Container;
use App\Core\Database;
use App\Core\Sito;
use App\Infrastructure\Mail\Mailer;
use App\Infrastructure\Storage\Upload;
use App\Iscritti\Pianificati;
use App\Sistema\FileEnv;
use App\Tutorato\OperatoriUfficio;
use App\Tutorato\VerificaPdfFirmato;

/** Servizi del modulo Didattica nel container (scoperta da App\Core\App). */
final class Registrazione
{
    public static function registra(Container $c): void
    {
        // Cartella degli allegati: DIR_PRATICHE se è stata ridefinita (le prove automatiche scrivono in cache/prove/)
        $c->set(AllegatiPratiche::class, static fn (Container $c): AllegatiPratiche => new AllegatiPratiche(
            $c->get(Upload::class),
            $c->get(Sito::class),
            defined('DIR_PRATICHE') ? (string) DIR_PRATICHE : Costanti::DIR_PRATICHE
        ));
        // Domanda in PDF/A e primo ufficio dell'iter: il flusso delle convalide
        $c->set(FlussoPratica::class, static fn (Container $c): FlussoPratica => $c->get(ServizioFlussoConvalide::class));
        // Cartella dei verbali: DIR_VERBALI se è stata ridefinita (le prove automatiche scrivono in cache/prove/)
        $c->set(VerbaleFirmato::class, static fn (Container $c): VerbaleFirmato => new VerbaleFirmato(
            $c->get(Database::class),
            $c->get(SedutaRepository::class),
            $c->get(ConsiglioRepository::class),
            $c->get(Mailer::class),
            $c->get(Sito::class),
            $c->get(FileEnv::class),
            $c->get(VerificaPdfFirmato::class),
            defined('DIR_VERBALI') ? (string) DIR_VERBALI : Costanti::DIR_VERBALI
        ));
        // I compiti pianificati dei cron (pratiche ferme, tutorato, solleciti dei verbali, conservazione dei dati)
        $c->set(Pianificati::class, static fn (Container $c): Pianificati => $c->get(PianificatiDidattica::class));
        // Il Tutorato manda le lettere firmate agli operatori dell'Ufficio didattico
        $c->set(OperatoriUfficio::class, static fn (Container $c): OperatoriUfficio => $c->get(OperatoriPerTutorato::class));
    }
}
