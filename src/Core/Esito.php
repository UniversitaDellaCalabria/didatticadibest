<?php

declare(strict_types=1);

namespace App\Core;

/** Esito di un'operazione richiesta dall'utente: riuscita con un messaggio, o errore da mostrare. */
final class Esito
{
    private function __construct(public readonly bool $riuscito, public readonly string $messaggio)
    {
    }

    public static function ok(string $messaggio): self
    {
        return new self(true, $messaggio);
    }

    public static function errore(string $messaggio): self
    {
        return new self(false, $messaggio);
    }

    /**
     * Nel formato delle funzioni procedurali: [messaggio, errore] (uno dei due è null).
     *
     * @return array{0: ?string, 1: ?string}
     */
    public function comeCoppia(): array
    {
        return $this->riuscito ? [$this->messaggio, null] : [null, $this->messaggio];
    }
}
