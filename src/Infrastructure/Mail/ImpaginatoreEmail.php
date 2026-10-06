<?php

declare(strict_types=1);

namespace App\Infrastructure\Mail;

/** Impaginazione comune delle email (intestazione con il colore dell'area, corpo, piè di pagina). */
interface ImpaginatoreEmail
{
    public function impagina(string $corpo, string $titolo, ?string $colore = null): string;
}
