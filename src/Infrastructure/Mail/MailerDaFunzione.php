<?php

declare(strict_types=1);

namespace App\Infrastructure\Mail;

use App\Core\Database;

/**
 * Mailer dei servizi durante la migrazione: passa da inviaNotificaEmail(), così le email dei moduli migrati e di quelli
 * procedurali seguono la stessa strada (registro log_email, ambiente locale, intercettazione nelle prove automatiche).
 */
final class MailerDaFunzione implements Mailer
{
    public function __construct(private Database $db)
    {
    }

    public function invia(string $a, string $oggetto, string $corpoHtml, ?string $colore = null, array $allegati = []): bool
    {
        return (bool) inviaNotificaEmail($a, $oggetto, $corpoHtml, $this->db->mysqli(), $colore, $allegati);
    }

    public function ultimoErrore(): string
    {
        return (string) ($GLOBALS['ultimo_errore_email'] ?? '');
    }
}
