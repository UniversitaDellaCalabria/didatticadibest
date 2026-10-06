<?php

declare(strict_types=1);

namespace App\Core;

/** L'indirizzo della richiesta HTTP corrente ($_SERVER['REMOTE_ADDR']). */
final class IndirizzoClientDaServer implements IndirizzoClient
{
    public function ip(): ?string
    {
        return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : null;
    }
}
