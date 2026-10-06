<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Protezione CSRF: un token per sessione, valido per tutti i form della sessione corrente
 * (spostata da csrf_token/csrf_field/csrf_verify di inc/sistema.php).
 */
final class Csrf
{
    private const CHIAVE = 'csrf_token';

    public function __construct(private Sessione $sessione)
    {
    }

    /** Il token corrente, generato se non esiste ancora. */
    public function token(): mixed
    {
        if (empty($this->sessione->leggi(self::CHIAVE))) {
            $this->sessione->scrivi(self::CHIAVE, bin2hex(random_bytes(32)));
        }

        return $this->sessione->leggi(self::CHIAVE);
    }

    /** Campo hidden pronto da inserire in un <form>. */
    public function campo(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars((string) $this->token()) . '">';
    }

    /** Il token ricevuto (form POST, o link "azione" dell'admin) è quello della sessione. */
    public function valido(mixed $ricevuto): bool
    {
        $atteso = $this->sessione->leggi(self::CHIAVE);
        if (empty($atteso) || empty($ricevuto)) {
            return false;
        }

        // Un valore non testuale (es. csrf_token[]=…) fa fallire la richiesta come prima (TypeError di hash_equals)
        return hash_equals((string) $atteso, $ricevuto);
    }
}
