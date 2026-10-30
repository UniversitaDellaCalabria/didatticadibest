<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Casi d'uso sugli utenti del portale: amministratori da avvisare. Il cambio dell'email del profilo passa da VerificaEmail (con il codice):
 * il vecchio aggiornaEmail() senza verifica è stato tolto il 30/10/2026.
 */
final class ServizioUtenti
{
    public function __construct(private UtenteRepository $utenti)
    {
    }

    /**
     * Email degli amministratori globali (ruolo principale o secondario 1), valide, minuscole e senza doppioni
     * (spostata da email_amministratori() di inc/sistema.php).
     *
     * @return list<string>
     */
    public function emailAmministratori(): array
    {
        $out = [];
        foreach ($this->utenti->emailAmministratori() as $email) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $out[] = strtolower($email);
            }
        }

        return array_values(array_unique($out));
    }
}
