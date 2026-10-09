<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Auth\Sessione;

/**
 * Il «programma» FSL della scuola: le attività scelte e non ancora prenotate, tenute nella sessione di chi sta navigando.
 * Non riserva posti: i posti si assegnano alla conferma. Una voce per ogni edizione (turno) scelta: per un progetto che consente una sola
 * edizione, sceglierne un'altra sostituisce la prima; se il progetto consente più edizioni, le edizioni si sommano.
 */
final class ProgrammaFsl
{
    private const CHIAVE = 'programma_fsl';
    /** Voci nel programma: più di così sarebbe un uso anomalo (e un modulo di conferma ingestibile). */
    public const MASSIMO = 20;

    public function __construct(private Sessione $sessione)
    {
    }

    /**
     * Voci nell'ordine di inserimento: turno_id => attività, edizione scelta e risposte ai campi del modulo.
     * (Le sessioni aperte prima dell'introduzione delle più edizioni hanno l'attività come chiave: si leggono lo stesso.)
     *
     * @return array<int, array{evento_id: int, turno_id: int, custom: array<string, string>}>
     */
    public function voci(): array
    {
        $v = $this->sessione->leggi(self::CHIAVE);
        $pulite = [];
        foreach (is_array($v) ? $v : [] as $chiave => $voce) {
            if (!is_array($voce) || (int) ($voce['turno_id'] ?? 0) <= 0 || (int) $chiave <= 0) {
                continue;
            }
            $turnoId = (int) $voce['turno_id'];
            $pulite[$turnoId] = [
                'evento_id' => (int) ($voce['evento_id'] ?? $chiave),
                'turno_id' => $turnoId,
                'custom' => array_map('strval', array_filter((array) ($voce['custom'] ?? []), 'is_scalar')),
            ];
        }

        return $pulite;
    }

    public function conta(): int
    {
        return count($this->voci());
    }

    /** L'attività ha almeno un'edizione nel programma. */
    public function contiene(int $eventoId): bool
    {
        foreach ($this->voci() as $voce) {
            if ($voce['evento_id'] === $eventoId) {
                return true;
            }
        }

        return false;
    }

    /** L'edizione scelta per l'attività (la prima, se ne ha più d'una), se è nel programma. */
    public function turnoDi(int $eventoId): ?int
    {
        foreach ($this->voci() as $voce) {
            if ($voce['evento_id'] === $eventoId) {
                return $voce['turno_id'];
            }
        }

        return null;
    }

    /** Questa edizione è nel programma. */
    public function contieneTurno(int $turnoId): bool
    {
        return isset($this->voci()[$turnoId]);
    }

    /**
     * Aggiunge l'edizione (o aggiorna le risposte se c'era già). Senza $piuEdizioni toglie prima le altre edizioni della stessa attività,
     * che vengono sostituite. False se il programma è pieno.
     *
     * @param array<string, string> $custom risposte ai campi del modulo (nome del campo => valore)
     */
    public function aggiungi(int $eventoId, int $turnoId, array $custom, bool $piuEdizioni = false): bool
    {
        $voci = $this->voci();
        if (!$piuEdizioni) {
            foreach ($voci as $chiave => $voce) {
                if ($voce['evento_id'] === $eventoId && $chiave !== $turnoId) {
                    unset($voci[$chiave]);
                }
            }
        }
        if (!isset($voci[$turnoId]) && count($voci) >= self::MASSIMO) {
            return false;
        }
        $voci[$turnoId] = ['evento_id' => $eventoId, 'turno_id' => $turnoId, 'custom' => $custom];
        $this->sessione->scrivi(self::CHIAVE, $voci);

        return true;
    }

    /** Toglie un'edizione dal programma. */
    public function togli(int $turnoId): void
    {
        $voci = $this->voci();
        unset($voci[$turnoId]);
        $this->sessione->scrivi(self::CHIAVE, $voci);
    }

    public function svuota(): void
    {
        $this->sessione->togli(self::CHIAVE);
    }
}
