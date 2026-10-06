<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * La sessione PHP del portale ($_SESSION), letta e scritta al momento di ogni chiamata: resta corretta anche
 * quando SimpleSAML chiude e il portale riapre la sessione durante l'accesso. È l'unico punto delle classi
 * di App\Auth che tocca $_SESSION.
 */
final class SessioneNativa implements Sessione
{
    public function leggi(string $chiave): mixed
    {
        return $_SESSION[$chiave] ?? null;
    }

    public function scrivi(string $chiave, mixed $valore): void
    {
        $_SESSION[$chiave] = $valore;
    }

    public function togli(string $chiave): void
    {
        unset($_SESSION[$chiave]);
    }
}
