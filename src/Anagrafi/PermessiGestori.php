<?php

declare(strict_types=1);

namespace App\Anagrafi;

use App\Auth\Abilitazioni\AbilitazioniRepository;
use App\Auth\Abilitazioni\PermessiGestoreAree;
use App\Auth\Abilitazioni\ServizioAbilitazioni;

/**
 * Abilita e toglie gli utenti dalle aree e dalle attività: «tutta l'area» e «singole attività» (permessi nei JSON di aree ed eventi),
 * oltre ai perimetri (progetti, eventi, FSL, moduli: ServizioAbilitazioni). Spostato da assegna_permessi_gestore(),
 * applica_abilitazione() e revoca_permessi_gestore() di inc/anagrafi.php, che restano come facciate.
 */
final class PermessiGestori implements PermessiGestoreAree
{
    public function __construct(
        private AbilitazioniRepository $repo,
        private ServizioAbilitazioni $abilitazioni,
    ) {
    }

    /**
     * Abilita un utente su un'area: su tutta l'area ($eventiIds vuoto) o solo su alcuni eventi.
     * Toglie prima le abilitazioni precedenti dell'utente in quell'area.
     *
     * @param list<string> $permessi
     * @param list<int> $eventiIds
     */
    public function assegna(int $paginaId, int $utenteId, array $permessi, array $eventiIds = []): void
    {
        $permessi = array_values(array_intersect($permessi, ['eventi', 'iscritti', 'sondaggi', 'form', 'full']));
        if ($utenteId <= 0 || $paginaId <= 0 || !$permessi) {
            return;
        }
        $this->revoca($paginaId, $utenteId);
        if (!$eventiIds) {
            $row = $this->repo->gestoriArea($paginaId);
            $pj = $row ? (json_decode($row['permessi_gestori_json'] ?: '{}', true) ?: []) : [];
            $pj[$utenteId] = $permessi;
            $this->repo->salvaGestoriArea($paginaId, (string) json_encode($pj));

            return;
        }
        foreach ($eventiIds as $eId) {
            $eId = (int) $eId;
            $row = $this->repo->permessiAttivita($eId, $paginaId);
            if (!$row) {
                continue;
            }
            $ej = json_decode($row['permessi_gestori_json'] ?: '{}', true) ?: [];
            $ej[$utenteId] = $permessi;
            $this->repo->salvaGestoriAttivita($eId, (string) json_encode($ej));
        }
    }

    /**
     * Abilita un utente a un perimetro: 'area' (tutta l'area), 'attivita' ($eventiIds dell'area), 'progetti' / 'eventi'
     * (tutte le attività di quel tipo dell'area), 'fsl', 'fsl_convenzioni', 'fsl_scuole'.
     * Dentro il perimetro l'utente gestisce tutto (le vecchie sezioni separate non si usano più).
     *
     * @param list<int> $eventiIds
     */
    public function applica(int $utenteId, string $ambito, int $paginaId, array $eventiIds = [], int $da = 0): bool
    {
        if ($ambito === 'area' && $eventiIds) {
            $ambito = 'attivita'; // abilitazioni in attesa salvate prima dei perimetri
        }
        if ($ambito === 'area') {
            $this->assegna($paginaId, $utenteId, ['full']);

            return true;
        }
        if ($ambito === 'attivita') {
            if (!$eventiIds) {
                return false;
            }
            // Si aggiungono alle attività già assegnate nell'area (senza toglierle)
            foreach ($eventiIds as $eId) {
                $eId = (int) $eId;
                $row = $this->repo->permessiAttivita($eId, $paginaId);
                if (!$row) {
                    continue;
                }
                $ej = json_decode($row['permessi_gestori_json'] ?: '{}', true) ?: [];
                $ej[$utenteId] = ['full'];
                $this->repo->salvaGestoriAttivita($eId, (string) json_encode($ej));
            }

            return true;
        }

        return $this->abilitazioni->assegnaAmbito($utenteId, $ambito, $paginaId, $da, defined('TIPI_AMBITO') ? TIPI_AMBITO : []);
    }

    /** Toglie l'utente dai gestori dell'area e delle sue attività. */
    public function revoca(int $paginaId, int $utenteId): void
    {
        $row = $this->repo->gestoriArea($paginaId);
        if ($row) {
            $pj = json_decode($row['permessi_gestori_json'] ?: '{}', true) ?: [];
            unset($pj[$utenteId]);
            $csv = array_diff(array_filter(array_map('trim', explode(',', (string) ($row['gestori_utenti_ids'] ?? '')))), [(string) $utenteId]);
            $this->repo->salvaGestoriArea($paginaId, (string) json_encode($pj), implode(',', $csv));
        }
        foreach ($this->repo->gestoriAttivitaAreaConTitolo($paginaId) as $row) {
            $ej = json_decode($row['permessi_gestori_json'] ?: '{}', true) ?: [];
            $csv = array_filter(array_map('trim', explode(',', (string) ($row['gestori_utenti_ids'] ?? ''))));
            if (!isset($ej[$utenteId]) && !in_array((string) $utenteId, $csv, true)) {
                continue;
            }
            unset($ej[$utenteId]);
            $csv = array_diff($csv, [(string) $utenteId]);
            $this->repo->salvaGestoriAttivita((int) $row['id'], (string) json_encode($ej), implode(',', $csv));
        }
    }
}
