<?php

declare(strict_types=1);

namespace App\Iscritti;

/** Chi esegue l'operazione dal pannello (dalla sessione e dalla richiesta, letti dal controller): serve al registro delle azioni e alle email. */
final class Operatore
{
    public function __construct(
        public readonly int $id,
        public readonly string $email = '',
        public readonly string $ip = 'Sconosciuto',
    ) {
    }
}
