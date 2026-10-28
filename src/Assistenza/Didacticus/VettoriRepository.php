<?php

declare(strict_types=1);

namespace App\Assistenza\Didacticus;

use App\Core\Database;

/** I vettori di significato delle voci di Didacticus (tabella didacticus_vettori): uno per voce, con l'impronta del testo da cui sono stati calcolati. */
final class VettoriRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<int, array{impronta: string, vettore: list<float>}> per id di voce */
    public function tutti(): array
    {
        $out = [];
        foreach ($this->db->righe('SELECT voce_id, impronta, vettore FROM didacticus_vettori') as $r) {
            $out[(int) $r['voce_id']] = ['impronta' => (string) $r['impronta'], 'vettore' => Significato::espandi((string) $r['vettore'])];
        }

        return $out;
    }

    /** @return array<int, string> impronte per id di voce */
    public function impronte(): array
    {
        $out = [];
        foreach ($this->db->righe('SELECT voce_id, impronta FROM didacticus_vettori') as $r) {
            $out[(int) $r['voce_id']] = (string) $r['impronta'];
        }

        return $out;
    }

    public function salva(int $voceId, string $impronta, array $vettore, string $quando): void
    {
        $this->db->esegui(
            'INSERT INTO didacticus_vettori (voce_id, impronta, vettore, aggiornato_il) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE impronta = VALUES(impronta), vettore = VALUES(vettore), aggiornato_il = VALUES(aggiornato_il)',
            [$voceId, $impronta, Significato::comprimi($vettore), $quando]
        );
    }

    /** Toglie i vettori delle voci che non ci sono più o non sono attive. */
    public function togliInutili(): void
    {
        $this->db->esegui('DELETE FROM didacticus_vettori WHERE voce_id NOT IN (SELECT id FROM didacticus_voci WHERE attiva = 1)');
    }
}
