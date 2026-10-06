<?php

declare(strict_types=1);

namespace App\Sondaggi;

use RuntimeException;

/** Interrompe (e annulla) la transazione delle risposte; il messaggio è quello da mostrare all'utente. */
final class RisposteNonRegistrate extends RuntimeException
{
}
