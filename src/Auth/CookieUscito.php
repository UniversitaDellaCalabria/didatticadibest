<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Cookie "uscito": dopo "Esci" niente accesso automatico dalla sessione SSO di Ateneo (che può sopravvivere
 * al logout e alla chiusura del browser); si rientra solo con "Accedi". Stesso nome e parametri usati da esci.php
 * (costante COOKIE_USCITO di inc/base.php). Spostato da imposta_cookie_uscito().
 */
final class CookieUscito
{
    public const NOME = 'dibest_uscito';

    /** true = l'utente ha fatto "Esci" (30 giorni); false = ha rifatto l'accesso */
    public function imposta(bool $uscito): void
    {
        setcookie(self::NOME, $uscito ? '1' : '', ['expires' => $uscito ? time() + 30 * 86400 : time() - 3600,
            'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
    }
}
