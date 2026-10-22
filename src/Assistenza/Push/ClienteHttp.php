<?php

declare(strict_types=1);

namespace App\Assistenza\Push;

/** Chi spedisce la richiesta al servizio push (nel portale curl, nelle prove un finto che ricorda cosa è stato mandato). */
interface ClienteHttp
{
    /**
     * @param list<string> $intestazioni righe «Nome: valore»
     * @return array{codice: int, corpo: string} codice 0 se il servizio non risponde
     */
    public function post(string $url, array $intestazioni, string $corpo, int $secondi = 10): array;
}
