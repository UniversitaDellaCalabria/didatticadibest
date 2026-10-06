<?php

declare(strict_types=1);

namespace App\Attestati;

/** Chi può vedere gli attestati di una prenotazione. Ricevono i dati della sessione già letti dalla pagina. */
final class Accesso
{
    /** Amministratore o gestore (ruolo principale o secondario 1 o 2). */
    public static function eStaff(int $ruoloId, ?string $ruoliSecondari): bool
    {
        $sec = $ruoliSecondari !== null ? explode(',', $ruoliSecondari) : [];

        return in_array($ruoloId, [1, 2], true) || in_array('1', $sec, true) || in_array('2', $sec, true);
    }

    /**
     * L'utente collegato è il titolare della prenotazione (per id o per email), un amministratore o un gestore.
     *
     * @param array<string, mixed> $p prenotazione (utente_id, email)
     */
    public static function puoVedere(array $p, int $utenteId, int $ruoloId, ?string $ruoliSecondari, ?string $email): bool
    {
        if ($utenteId <= 0) {
            return false;
        }
        if (self::eStaff($ruoloId, $ruoliSecondari)) {
            return true;
        }

        return (int) ($p['utente_id'] ?? 0) === $utenteId
            || (!empty($email) && strtolower((string) $p['email']) === strtolower($email));
    }
}
