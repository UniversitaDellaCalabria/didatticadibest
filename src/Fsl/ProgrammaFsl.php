<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Auth\Sessione;

/**
 * Il «programma» FSL della scuola: le attività scelte e non ancora prenotate, tenute nella sessione di chi sta navigando e, per chi ha fatto l'accesso, anche
 * salvate nel database (fsl_programmi): uscire, scadere la sessione o cambiare computer non lo svuota. Il programma fatto prima dell'accesso si unisce a quello
 * salvato appena la persona entra.
 * Non riserva posti: i posti si assegnano alla conferma. Una voce per ogni edizione (turno) scelta: per un progetto che consente una sola
 * edizione, sceglierne un'altra sostituisce la prima; se il progetto consente più edizioni, le edizioni si sommano.
 */
final class ProgrammaFsl
{
    private const CHIAVE = 'programma_fsl';
    /** Nella sessione: di quale persona è il programma già unito a quello salvato (così l'unione si fa una volta sola). */
    private const CHIAVE_UNITO = 'programma_fsl_unito';
    /** Voci nel programma: più di così sarebbe un uso anomalo (e un modulo di conferma ingestibile). */
    public const MASSIMO = 20;

    public function __construct(private Sessione $sessione, private ?ProgrammiSalvatiRepository $salvati = null)
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
        $this->uniscoAlSalvato();

        return $this->vociInSessione();
    }

    /** @return array<int, array{evento_id: int, turno_id: int, custom: array<string, string>}> */
    private function vociInSessione(): array
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
        $this->scrivi($voci);

        return true;
    }

    /** Toglie un'edizione dal programma. */
    public function togli(int $turnoId): void
    {
        $voci = $this->voci();
        unset($voci[$turnoId]);
        $this->scrivi($voci);
    }

    public function svuota(): void
    {
        $this->sessione->togli(self::CHIAVE);
        $u = $this->utenteConAccesso();
        if ($u > 0) {
            $this->salva($u, []);
        }
    }

    /** La persona che ha fatto l'accesso (0 se naviga senza accesso o se il salvataggio non è disponibile). */
    private function utenteConAccesso(): int
    {
        return $this->salvati === null ? 0 : max(0, (int) $this->sessione->leggi('utente_id'));
    }

    /** Scrive il programma nella sessione e, per chi ha fatto l'accesso, nel database. */
    private function scrivi(array $voci): void
    {
        $this->sessione->scrivi(self::CHIAVE, $voci);
        $u = $this->utenteConAccesso();
        if ($u > 0) {
            $this->salva($u, $voci);
        }
    }

    private function salva(int $utente, array $voci): void
    {
        try {
            $this->salvati?->salva($utente, $voci);
        } catch (\Throwable $e) {
            error_log('Programma FSL non salvato: ' . $e->getMessage());
        }
    }

    /**
     * Alla prima lettura dopo l'accesso: il programma fatto da anonimo (nella sessione) si somma a quello salvato; poi il database e la sessione dicono la stessa cosa.
     * Se nella sessione c'era il programma di un'altra persona non viene riportato.
     */
    private function uniscoAlSalvato(): void
    {
        $u = $this->utenteConAccesso();
        if ($u <= 0 || (int) $this->sessione->leggi(self::CHIAVE_UNITO) === $u) {
            return;
        }
        try {
            $salvate = $this->salvati?->dellUtente($u) ?? [];
        } catch (\Throwable $e) {
            error_log('Programma FSL non letto: ' . $e->getMessage());

            return;
        }
        $primo = $this->sessione->leggi(self::CHIAVE_UNITO);
        $anonimo = $primo === null || $primo === '' ? $this->vociInSessione() : [];
        $unite = $salvate;
        foreach ($anonimo as $turnoId => $voce) {
            if (count($unite) >= self::MASSIMO && !isset($unite[$turnoId])) {
                break;
            }
            $unite[$turnoId] = $voce;
        }
        $this->sessione->scrivi(self::CHIAVE, $unite);
        $this->sessione->scrivi(self::CHIAVE_UNITO, $u);
        if ($unite !== $salvate) {
            $this->salva($u, $unite);
        }
    }
}
