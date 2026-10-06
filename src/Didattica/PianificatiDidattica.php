<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Iscritti\Pianificati;
use App\Tutorato\ServizioRegistroTutorato;

/** I compiti pianificati dei moduli Didattica, Tutorato e Sedute che girano insieme ai cron degli iscritti. */
final class PianificatiDidattica implements Pianificati
{
    public function __construct(
        private PromemoriaPratiche $pratiche,
        private ServizioRegistroTutorato $tutorato,
        private VerbaleFirmato $verbali,
        private ServizioConvocazioni $convocazioni
    ) {
    }

    public function praticheFerme(): int
    {
        return $this->pratiche->invia();
    }

    public function tutorato(): int
    {
        return $this->tutorato->promemoria();
    }

    public function sollecitiVerbali(): int
    {
        return $this->verbali->solleciti();
    }

    public function conservaTutorato(int $mesi): int
    {
        return $this->tutorato->conserva($mesi);
    }

    public function conservaSedute(int $mesi): int
    {
        return $this->convocazioni->conserva($mesi);
    }
}
