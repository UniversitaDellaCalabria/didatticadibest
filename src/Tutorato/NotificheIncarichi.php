<?php

declare(strict_types=1);

namespace App\Tutorato;

use App\Auth\ServizioUtenti;
use App\Infrastructure\Mail\Mailer;

/** Email dell'iter della lettera di incarico: allo studente, al docente, al direttore e all'operatore che protocolla. */
final class NotificheIncarichi
{
    public function __construct(private Mailer $mailer, private OperatoriUfficio $operatori, private ServizioUtenti $utenti)
    {
    }

    /**
     * Email con il colore del Tutorato, un pulsante con il link personale (facoltativo) e allegati.
     *
     * @param list<array{path: string, nome: string}> $allegati
     */
    public function invia(?string $a, string $oggetto, string $corpo, string $link = '', string $bottone = '', array $allegati = []): void
    {
        $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $this->mailer->invia(
            (string) $a,
            $oggetto,
            $corpo . ($link !== '' ? "<p style='margin-top:18px;'><a href='" . $h($link) . "' style='background:#047857;color:#fff;padding:10px 18px;text-decoration:none;border-radius:6px;font-weight:bold;'>" . $h($bottone) . "</a></p><p style='font-size:12px;color:#64748b;'>Il link è personale: non inoltrarlo.</p>" : ''),
            '#047857',
            $allegati
        );
    }

    /**
     * Chi riceve la lettera firmata: l'operatore scelto nel bando, altrimenti chi ha il compito «Bandi», altrimenti gli amministratori.
     *
     * @param array<string, mixed> $i
     * @return list<string>
     */
    public function emailOperatori(array $i): array
    {
        $o = !empty($i['operatore_id']) ? $this->operatori->perId((int) $i['operatore_id']) : null;
        if ($o) {
            return [$o['email']];
        }
        $e = array_column($this->operatori->delCompito('bandi'), 'email');

        return $e ?: $this->utenti->emailAmministratori();
    }
}
