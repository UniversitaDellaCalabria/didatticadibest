<?php

declare(strict_types=1);

namespace App\Auth\Saml;

/** Al login: collega l'utente alla persona dell'anagrafe di Ateneo (modulo Anagrafi, collega_utente_anagrafe()). */
interface CollegamentoAnagrafe
{
    /** I gruppi secondari aggiornati (per la sessione) o null se l'utente non esiste. */
    public function collega(int $utenteId, string $emailSso): ?string;
}
