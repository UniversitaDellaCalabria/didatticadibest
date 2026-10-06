<?php

declare(strict_types=1);

namespace App\Tutorato;

/** Operatori dell'Ufficio didattico (modulo Didattica): chi riceve le lettere firmate. */
interface OperatoriUfficio
{
    /**
     * Operatore per id (null se non c'è).
     *
     * @return array<string, mixed>|null
     */
    public function perId(int $id): ?array;

    /**
     * Operatori dell'Ufficio, o solo quelli con un compito (es. «bandi»).
     *
     * @return list<array<string, mixed>>
     */
    public function delCompito(?string $compito = null): array;
}
