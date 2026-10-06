<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Core\Orologio;

/** Periodi da coprire con la convenzione (attività e prenotazioni) e validità proposta per una convenzione nuova. */
final class PeriodiConvenzione
{
    public function __construct(private Orologio $orologio)
    {
    }

    /**
     * Periodo da coprire con la convenzione: progetto dal/al, evento il giorno del turno; senza date: oggi.
     *
     * @return array{0: string, 1: string}
     */
    public function attivita(?string $inizio, ?string $fine, ?string $dataTurno = null): array
    {
        $dal = $inizio ?: ($dataTurno ?: ($fine ?: null));
        $al = $fine ?: ($dataTurno ?: $dal);
        if (!$dal) {
            $dal = $al = $this->oggi();
        }
        if ($al < $dal) {
            $al = $dal;
        }

        return [$dal, (string) $al];
    }

    /**
     * @param array<string, mixed> $p con pd_inizio, pd_fine (progetti_dettagli) e data_turno
     * @return array{0: string, 1: string}
     */
    public function prenotazione(array $p): array
    {
        return $this->attivita($p['pd_inizio'] ?? null, $p['pd_fine'] ?? null, $p['data_turno'] ?? null);
    }

    /**
     * Validità proposta per una convenzione appena arrivata: da oggi (o dall'inizio dell'attività, se prima) per la durata
     * predefinita, allungata se l'attività finisce dopo.
     *
     * @return array{0: string, 1: string}
     */
    public function nuova(?string $attivitaDal = null, ?string $attivitaAl = null): array
    {
        $oggi = $this->orologio->adesso();
        $dal = $oggi->format('Y-m-d');
        $al = $oggi->modify('+' . Costanti::DURATA_ANNI . ' years -1 day')->format('Y-m-d');
        if ($attivitaDal && $attivitaDal < $dal) {
            $dal = $attivitaDal;
        }
        if ($attivitaAl && $attivitaAl > $al) {
            $al = $attivitaAl;
        }

        return [$dal, $al];
    }

    /**
     * «dal 01/10/2026 al 30/09/2029», «fino al …», «senza scadenza».
     *
     * @param array<string, mixed> $c convenzione del registro
     */
    public static function testoValidita(array $c): string
    {
        $d = static fn ($x): string => date('d/m/Y', (int) strtotime((string) $x));
        if (!empty($c['data_stipula']) && !empty($c['scadenza'])) {
            return 'dal ' . $d($c['data_stipula']) . ' al ' . $d($c['scadenza']);
        }
        if (!empty($c['scadenza'])) {
            return 'fino al ' . $d($c['scadenza']);
        }

        return !empty($c['data_stipula']) ? 'dal ' . $d($c['data_stipula']) . ', senza scadenza' : 'senza scadenza';
    }

    private function oggi(): string
    {
        return $this->orologio->adesso()->format('Y-m-d');
    }
}
