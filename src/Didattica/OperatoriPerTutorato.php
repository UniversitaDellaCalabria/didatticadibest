<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Tutorato\OperatoriUfficio;

/** Gli operatori dell'Ufficio didattico per il Tutorato (chi riceve le lettere firmate). */
final class OperatoriPerTutorato implements OperatoriUfficio
{
    public function __construct(private ServizioUffici $uffici)
    {
    }

    public function perId(int $id): ?array
    {
        return $this->uffici->operatore($id);
    }

    public function delCompito(?string $compito = null): array
    {
        return $this->uffici->operatori($compito);
    }
}
