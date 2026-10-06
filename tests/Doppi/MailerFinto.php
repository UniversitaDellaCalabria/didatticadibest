<?php

declare(strict_types=1);

namespace Tests\Doppi;

use App\Infrastructure\Mail\Mailer;

/** Mailer dei test: non invia nulla, ricorda le email. */
final class MailerFinto implements Mailer
{
    /** @var list<array{a: string, oggetto: string, corpo: string, colore: ?string}> */
    public array $inviate = [];

    public function invia(string $a, string $oggetto, string $corpoHtml, ?string $colore = null, array $allegati = []): bool
    {
        $this->inviate[] = ['a' => $a, 'oggetto' => $oggetto, 'corpo' => $corpoHtml, 'colore' => $colore];

        return true;
    }

    public function ultimoErrore(): string
    {
        return '';
    }
}
