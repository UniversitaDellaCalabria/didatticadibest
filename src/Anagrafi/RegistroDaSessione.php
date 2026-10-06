<?php

declare(strict_types=1);

namespace App\Anagrafi;

use App\Infrastructure\Audit\AuditLog;

/** Registro delle operazioni di chi è collegato: utente dalla sessione della richiesta e indirizzo IP del client (senza utente non si registra nulla). */
final class RegistroDaSessione implements RegistroOperazioni
{
    public function __construct(private AuditLog $audit)
    {
    }

    public function registra(string $azione, array $dettagli): void
    {
        if (empty($_SESSION['utente_id'])) {
            return;
        }
        $this->audit->registra((int) $_SESSION['utente_id'], $azione, $dettagli, (string) ($_SERVER['REMOTE_ADDR'] ?? 'Sconosciuto'));
    }
}
