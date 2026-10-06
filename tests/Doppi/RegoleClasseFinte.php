<?php

declare(strict_types=1);

namespace Tests\Doppi;

use App\Attestati\RegoleClasse;

/** Regole delle classi dei test: le stesse di inc/fsl.php (attestati_di_classe, attivita_conclusa_classe), con la data corrente. */
final class RegoleClasseFinte implements RegoleClasse
{
    public function __construct(private string $oggi = '2026-10-05')
    {
    }

    public function attestatiDiClasse(array $p): bool
    {
        $isProgetto = ($p['evento_tipo'] ?? $p['tipo'] ?? 'evento') === 'progetto';
        if ((int) ($p['attestati'] ?? 0) !== 1) {
            return false;
        }

        return $isProgetto ? (int) ($p['per_scuole'] ?? 1) === 1 : true;
    }

    public function attivitaConclusaClasse(array $p): bool
    {
        if (($p['evento_tipo'] ?? 'evento') === 'progetto') {
            return !empty($p['data_fine']) && $p['data_fine'] < $this->oggi;
        }

        return empty($p['data_turno']) || $p['data_turno'] < $this->oggi;
    }
}
