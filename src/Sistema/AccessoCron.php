<?php

declare(strict_types=1);

namespace App\Sistema;

/**
 * Chi può avviare gli script cron: SOLO da riga di comando (crontab con "php script.php"), con la chiave
 * CRON_KEY del file .env (crontab con wget/curl: script.php?key=...), oppure un utente collegato
 * con uno dei ruoli ammessi (pulsanti del pannello admin). Spostato da consenti_esecuzione_cron() di inc/sistema.php,
 * che resta come facciata (legge richiesta e sessione e risponde 403).
 */
final class AccessoCron
{
    /** La chiave ricevuta è quella del .env (almeno 16 caratteri: una chiave corta o assente non apre nulla). */
    public static function chiaveValida(string $chiaveEnv, string $chiaveRicevuta): bool
    {
        return strlen($chiaveEnv) >= 16 && hash_equals($chiaveEnv, $chiaveRicevuta);
    }

    /**
     * @param list<int> $ruoliUtente ruolo principale e gruppi secondari dell'utente collegato
     * @param list<int> $ruoliAmmessi
     */
    public static function ruoloAmmesso(array $ruoliUtente, array $ruoliAmmessi): bool
    {
        return (bool) array_intersect($ruoliUtente, $ruoliAmmessi);
    }
}
