<?php

declare(strict_types=1);

namespace App\Anagrafi;

/** Registro delle operazioni (chi le fa lo decide la sessione: senza utente collegato non si registra nulla). */
interface RegistroOperazioni
{
    /** @param array<string, mixed> $dettagli */
    public function registra(string $azione, array $dettagli): void;
}
