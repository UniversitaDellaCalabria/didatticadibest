<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Core\Database;
use App\Core\Orologio;

/** Le sostituzioni degli operatori valide oggi (solo lettura, poche dipendenze): chi sostituisce chi. Gestione e destinatari delle email: SostituzioniOperatori. */
final class SostituzioniInCorso
{
    public function __construct(private Database $db, private Orologio $orologio)
    {
    }

    public function oggi(): string
    {
        return $this->orologio->adesso()->format('Y-m-d');
    }

    /** @return list<array<string, mixed>> */
    public function tutte(): array
    {
        return $this->db->righe('SELECT * FROM operatori_sostituzioni WHERE dal <= ? AND al >= ? ORDER BY id', [$this->oggi(), $this->oggi()]);
    }

    /** Gli operatori che oggi sono sostituiti da questo. @return list<int> id degli assenti */
    public function sostituisce(int $opId): array
    {
        $out = [];
        foreach ($this->tutte() as $s) {
            if ((int) $s['sostituto_op_id'] === $opId) {
                $out[] = (int) $s['op_id'];
            }
        }

        return $out;
    }
}
