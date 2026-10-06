<?php

declare(strict_types=1);

namespace App\Iscritti;

use App\Infrastructure\Mail\Mailer;

/**
 * Email massiva agli iscritti confermati di un'area (o di un turno), a gruppi di 10 con una richiesta AJAX per volta.
 * La coda sta nella sessione dell'amministratore (la tiene il controller): qui si prepara e si fa avanzare.
 */
final class ServizioEmailMassiva
{
    /** Email inviate a ogni richiesta. */
    public const PER_RICHIESTA = 10;

    public function __construct(
        private IscrittiRepository $iscritti,
        private Mailer $mailer,
    ) {
    }

    /**
     * Prepara la coda con i destinatari (iscritti confermati con email).
     *
     * @return array{destinatari: list<array<string, string|null>>, totale: int, inviate: int, oggetto: string, messaggio: string}
     */
    public function prepara(int $paginaId, int $turnoId, string $oggetto, string $messaggio): array
    {
        $destinatari = $this->iscritti->destinatariEmailMassiva($paginaId, $turnoId);

        return ['destinatari' => $destinatari, 'totale' => count($destinatari), 'inviate' => 0, 'oggetto' => $oggetto, 'messaggio' => $messaggio];
    }

    /**
     * Invia il prossimo gruppo di email e ritorna la coda aggiornata.
     *
     * @param array{destinatari: list<array<string, string|null>>, totale: int, inviate: int, oggetto: string, messaggio: string} $coda
     * @return array{destinatari: list<array<string, string|null>>, totale: int, inviate: int, oggetto: string, messaggio: string}
     */
    public function invia(array $coda): array
    {
        $fatte = 0;
        while ($fatte < self::PER_RICHIESTA && $coda['inviate'] < $coda['totale']) {
            $utente = $coda['destinatari'][$coda['inviate']];
            $this->mailer->invia((string) $utente['email'], $coda['oggetto'], $coda['messaggio']);
            ++$coda['inviate'];
            ++$fatte;
        }

        return $coda;
    }
}
