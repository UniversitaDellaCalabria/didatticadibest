<?php

declare(strict_types=1);

namespace App\Auth\Abilitazioni;

/** Modulo del portale a cui appartiene un'area (lo fornisce il modulo Portale). */
interface ModuliAree
{
    /** La funzione che classifica le aree è caricata (come il vecchio controllo function_exists('modulo_di_area')). */
    public function disponibile(): bool;

    /** @param array<string, mixed> $pagina riga di pagine_eventi */
    public function moduloDiArea(array $pagina): string;
}
