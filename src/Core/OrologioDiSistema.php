<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;

final class OrologioDiSistema implements Orologio
{
    public function adesso(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
