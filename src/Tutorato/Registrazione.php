<?php

declare(strict_types=1);

namespace App\Tutorato;

use App\Core\Container;
use App\Core\Sito;
use App\Infrastructure\Sicurezza\Cifratura;
use App\Infrastructure\Storage\Upload;
use App\Sistema\FileEnv;

/** Servizi del modulo Tutorato nel container (scoperta da App\Core\App). */
final class Registrazione
{
    public static function registra(Container $c): void
    {
        // Il .env letto una sola volta (ARUBA_ARSS_*, INCARICHI_SOLO_SPID_CIE, FIRME_GIORNI_SOLLECITO): come env_valore()
        $c->set(FileEnv::class, static fn (): FileEnv => new FileEnv(defined('FILE_ENV') ? (string) FILE_ENV : (defined('RADICE_SITO') ? (string) RADICE_SITO : dirname(__DIR__, 2)) . '/.env'));
        // Cifratura dei dati bancari della dichiarazione individuale: chiave CIFRATURA_CHIAVE del .env
        $c->set(Cifratura::class, static fn (Container $c): Cifratura => Cifratura::daEnv($c->get(FileEnv::class)));
        // Firma remota Aruba (ARSS): nei test c'è un doppio che non chiama il servizio
        $c->set(FirmaRemota::class, static fn (Container $c): FirmaRemota => $c->get(FirmaRemotaAruba::class));
        // Decreti e UNILAV (PDF caricati a mano): stessa cartella dei PDF delle lettere
        $c->set(FileTutorato::class, static fn (Container $c): FileTutorato => new FileTutorato(
            $c->get(BandoRepository::class),
            $c->get(IncaricoRepository::class),
            $c->get(Upload::class),
            $c->get(Sito::class),
            defined('DIR_INCARICHI') ? (string) DIR_INCARICHI : Costanti::DIR_INCARICHI
        ));
        // Cartella dei PDF: DIR_INCARICHI se è stata ridefinita (le prove automatiche scrivono in cache/prove/)
        $c->set(ArchivioIncarichi::class, static fn (Container $c): ArchivioIncarichi => new ArchivioIncarichi(
            $c->get(IncaricoRepository::class),
            $c->get(Upload::class),
            $c->get(Sito::class),
            defined('DIR_INCARICHI') ? (string) DIR_INCARICHI : Costanti::DIR_INCARICHI
        ));
    }
}
