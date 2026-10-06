<?php

declare(strict_types=1);

namespace App\Core;

/** Indirizzo IP di chi fa la richiesta (null se non c'è: cron e riga di comando). Le classi non leggono $_SERVER: lo chiedono qui. */
interface IndirizzoClient
{
    public function ip(): ?string;
}
