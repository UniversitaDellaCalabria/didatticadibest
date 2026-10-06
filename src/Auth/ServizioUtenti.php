<?php

declare(strict_types=1);

namespace App\Auth;

/** Casi d'uso sugli utenti del portale: email del profilo, amministratori da avvisare. */
final class ServizioUtenti
{
    public function __construct(private UtenteRepository $utenti)
    {
    }

    /**
     * Aggiorna l'email dell'utente e la marca come personalizzata (spostata da aggiorna_email_utente() di inc/dati.php).
     * Ritorna true in caso di successo, oppure il messaggio di errore.
     */
    public function aggiornaEmail(int $utenteId, string $nuovaEmail): true|string
    {
        if ($this->utenti->emailUsataDaAltri($nuovaEmail, $utenteId)) {
            return 'Questa email è già associata a un altro account.';
        }

        return $this->utenti->impostaEmailPersonalizzata($utenteId, $nuovaEmail) ? true : 'Errore durante il salvataggio. Riprova.';
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
