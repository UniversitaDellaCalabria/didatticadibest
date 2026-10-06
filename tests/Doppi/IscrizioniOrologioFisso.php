<?php

declare(strict_types=1);

namespace Tests\Doppi;

use App\Core\Orologio;
use DateTimeImmutable;

/** Orologio dei test del modulo Iscrizioni: una data fissa, spostabile. */
final class IscrizioniOrologioFisso implements Orologio
{
    public function __construct(public DateTimeImmutable $ora = new DateTimeImmutable('2026-10-05 12:00:00'))
    {
    }

    public function adesso(): DateTimeImmutable
    {
        return $this->ora;
    }

    public function avanza(string $intervallo): void
    {
        $this->ora = $this->ora->modify($intervallo);
    }
}
