<?php

declare(strict_types=1);

namespace Tests\Doppi;

use App\Core\IndirizzoClient;

/** Indirizzo IP dei test. */
final class ClientFinto implements IndirizzoClient
{
    public function __construct(public ?string $ip = '10.0.0.1')
    {
    }

    public function ip(): ?string
    {
        return $this->ip;
    }
}
