<?php

declare(strict_types=1);

namespace Tests\Doppi;

use App\Tutorato\OperatoriUfficio;

/** Operatori dell'Ufficio didattico dei test: un elenco in un array. */
final class OperatoriFinti implements OperatoriUfficio
{
    /** @param list<array<string, mixed>> $operatori righe con id, email e compiti (elenco separato da virgole) */
    public function __construct(public array $operatori = [])
    {
    }

    public function perId(int $id): ?array
    {
        foreach ($this->operatori as $o) {
            if ((int) $o['id'] === $id) {
                return $o;
            }
        }

        return null;
    }

    public function delCompito(?string $compito = null): array
    {
        return array_values(array_filter($this->operatori, static fn (array $o): bool => $compito === null || in_array($compito, explode(',', (string) ($o['compiti'] ?? '')), true)));
    }
}
