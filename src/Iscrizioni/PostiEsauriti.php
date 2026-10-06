<?php

declare(strict_types=1);

namespace App\Iscrizioni;

use RuntimeException;

/** Il turno è pieno e non ha lista d'attesa: interrompe la transazione di prenotazione (che viene annullata). */
final class PostiEsauriti extends RuntimeException
{
}
