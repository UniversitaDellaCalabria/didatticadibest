<?php

declare(strict_types=1);

namespace Tests\Doppi;

use App\Auth\Sessione;

/** Sessione dei test: i dati stanno in un array, non in $_SESSION. */
final class AuthSessioneInMemoria implements Sessione
{
    /** @var array<string, mixed> */
    public array $dati = [];

    public function leggi(string $chiave): mixed
    {
        return $this->dati[$chiave] ?? null;
    }

    public function scrivi(string $chiave, mixed $valore): void
    {
        $this->dati[$chiave] = $valore;
    }

    public function togli(string $chiave): void
    {
        unset($this->dati[$chiave]);
    }
}
