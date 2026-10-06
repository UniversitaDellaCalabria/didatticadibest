<?php

declare(strict_types=1);

namespace App\Iscrizioni;

use RuntimeException;

/** Il posto offerto dalla lista d'attesa non c'è più (già confermato, annullato o non della persona) o la scadenza è passata. */
final class OffertaNonValida extends RuntimeException
{
    public function __construct(public readonly bool $scaduta)
    {
        parent::__construct($scaduta ? 'Offerta di posto scaduta' : 'Offerta di posto non valida');
    }
}
