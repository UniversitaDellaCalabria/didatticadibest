<?php

declare(strict_types=1);

namespace App\Infrastructure\Mail;

/** Invio delle email del portale (notifiche, conferme, convocazioni…). Nei test si sostituisce con un finto mailer. */
interface Mailer
{
    /**
     * @param string|null $colore colore dell'area per l'impaginazione (null = rosso istituzionale)
     * @param list<array{path: string, nome?: string}> $allegati
     */
    public function invia(string $a, string $oggetto, string $corpoHtml, ?string $colore = null, array $allegati = []): bool;

    /** Motivo dell'ultimo invio non riuscito ('' se è andato bene). */
    public function ultimoErrore(): string;
}
