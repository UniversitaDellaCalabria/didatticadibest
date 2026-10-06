<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Attestati\RegoleClasse as RegoleClasseAttestati;
use App\Core\Container;
use App\Infrastructure\Pdf\FirmePades;
use App\Infrastructure\Pdf\VerificaFirme;
use App\Iscritti\Convenzioni;
use App\Iscrizioni\RegoleFsl;

/** Servizi del modulo FSL nel container (scoperta da App\Core\App). */
final class Registrazione
{
    public static function registra(Container $c): void
    {
        // Le regole e le convenzioni della FSL, usate dai moduli Attestati, Iscrizioni e Iscritti
        $c->set(RegoleClasseAttestati::class, static fn (Container $c): RegoleClasseAttestati => $c->get(RegoleClasse::class));
        $c->set(RegoleFsl::class, static fn (Container $c): RegoleFsl => $c->get(RegoleIscrizioniFsl::class));
        $c->set(Convenzioni::class, static fn (Container $c): Convenzioni => $c->get(ConvenzioniIscritti::class));
        // La verifica delle firme PAdES dei PDF
        $c->set(VerificaFirme::class, static fn (): VerificaFirme => new FirmePades());
    }
}
