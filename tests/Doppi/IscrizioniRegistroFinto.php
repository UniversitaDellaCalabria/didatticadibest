<?php

declare(strict_types=1);

namespace Tests\Doppi;

use App\Iscrizioni\RegistroOperazioni;

/** Registro delle operazioni dei test: ricorda le voci scritte. */
final class IscrizioniRegistroFinto implements RegistroOperazioni
{
    /** @var list<array{azione: string, dettagli: array<string, mixed>}> */
    public array $voci = [];

    public function registra(string $azione, array $dettagli): void
    {
        $this->voci[] = ['azione' => $azione, 'dettagli' => $dettagli];
    }
}
