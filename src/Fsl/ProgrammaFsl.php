<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Auth\Sessione;

/**
 * Il «programma» FSL della scuola: le attività scelte e non ancora prenotate, tenute nella sessione di chi sta navigando.
 * Non riserva posti: i posti si assegnano alla conferma. Una sola edizione per ogni attività (se ne scegli un'altra sostituisce la prima).
 */
final class ProgrammaFsl
{
    private const CHIAVE = 'programma_fsl';
    /** Attività nel programma: più di così sarebbe un uso anomalo (e un modulo di conferma ingestibile). */
    public const MASSIMO = 20;

    public function __construct(private Sessione $sessione)
    {
    }

    /**
     * Voci nell'ordine di inserimento: evento_id => turno scelto e risposte ai campi del modulo.
     *
     * @return array<int, array{turno_id: int, custom: array<string, string>}>
     */
    public function voci(): array
    {
        $v = $this->sessione->leggi(self::CHIAVE);
        $pulite = [];
        foreach (is_array($v) ? $v : [] as $eventoId => $voce) {
            if (is_array($voce) && (int) ($voce['turno_id'] ?? 0) > 0 && (int) $eventoId > 0) {
                $pulite[(int) $eventoId] = ['turno_id' => (int) $voce['turno_id'], 'custom' => array_map('strval', array_filter((array) ($voce['custom'] ?? []), 'is_scalar'))];
            }
        }

        return $pulite;
    }

    public function conta(): int
    {
        return count($this->voci());
    }

    public function contiene(int $eventoId): bool
    {
        return isset($this->voci()[$eventoId]);
    }

    /** Il turno scelto per l'attività, se è nel programma. */
    public function turnoDi(int $eventoId): ?int
    {
        return $this->voci()[$eventoId]['turno_id'] ?? null;
    }

    /**
     * Aggiunge l'attività (o sostituisce l'edizione già scelta). False se il programma è pieno.
     *
     * @param array<string, string> $custom risposte ai campi del modulo (nome del campo => valore)
     */
    public function aggiungi(int $eventoId, int $turnoId, array $custom): bool
    {
        $voci = $this->voci();
        if (!isset($voci[$eventoId]) && count($voci) >= self::MASSIMO) {
            return false;
        }
        $voci[$eventoId] = ['turno_id' => $turnoId, 'custom' => $custom];
        $this->sessione->scrivi(self::CHIAVE, $voci);

        return true;
    }

    public function togli(int $eventoId): void
    {
        $voci = $this->voci();
        unset($voci[$eventoId]);
        $this->sessione->scrivi(self::CHIAVE, $voci);
    }

    public function svuota(): void
    {
        $this->sessione->togli(self::CHIAVE);
    }
}
