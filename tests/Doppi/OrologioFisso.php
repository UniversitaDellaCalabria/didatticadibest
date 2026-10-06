<?php

declare(strict_types=1);

namespace Tests\Doppi;

use App\Core\Orologio;
use DateTimeImmutable;

/** Orologio dei test: sempre la stessa data e ora. */
final class OrologioFisso implements Orologio
{
    public function __construct(private string $quando = '2026-10-05 12:00:00')
    {
    }

    public function adesso(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->quando);
    }
}
