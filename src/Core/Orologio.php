<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;

/** Data e ora correnti: iniettate nei servizi per poter provare scadenze e promemoria con una data fissa. */
interface Orologio
{
    public function adesso(): DateTimeImmutable;
}
