<?php

declare(strict_types=1);

namespace App\Auth;

/** Chi è davvero una persona, al di là dell'indirizzo con cui entra. */
final class Identita
{
    /**
     * L'utente è la persona dell'anagrafe a cui è rivolta una richiesta (stessa scheda): vale anche se entra con un indirizzo diverso da quello salvato.
     * La scheda dell'utente (persona_id) la imposta il sistema all'accesso, non si può modificare dal profilo.
     *
     * @param array<string, mixed>|null $utente
     */
    public static function stessaScheda(?array $utente, ?string $personaId): bool
    {
        $mia = trim((string) ($utente['persona_id'] ?? ''));
        $richiesta = trim((string) $personaId);

        return !empty($utente['id']) && $mia !== '' && $richiesta !== '' && $mia === $richiesta;
    }
}
