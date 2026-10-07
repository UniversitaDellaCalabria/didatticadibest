<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Core\Orologio;
use DateTimeImmutable;

/**
 * Documenti che il docente referente consegna dopo la prenotazione e prima dell'inizio di un'attività di Formazione Scuola
 * Lavoro: l'elenco degli studenti e l'autorizzazione della scuola (PDF). Qui solo le regole (chi, quando, ogni quanto): niente
 * database, così si provano con date finte.
 */
final class DocumentiClasse
{
    /** I documenti vanno consegnati almeno questi giorni prima dell'inizio dell'attività. */
    public const GIORNI_SCADENZA = 7;

    /** Giorni tra un promemoria e il successivo (il primo parte dopo una settimana dalla prenotazione). */
    public const INTERVALLO_PROMEMORIA = 7;

    /** Promemoria periodici al massimo: se l'attività è molto lontana non si scrive per mesi; poi c'è l'ultimo avviso. */
    public const MAX_PROMEMORIA = 6;

    /** Stati in cui la prenotazione è attiva: si possono già consegnare i documenti anche in attesa della convenzione. */
    public const STATI_ATTIVI = ['confermata', 'da_approvare', 'in_attesa', 'richiesta_conferma'];

    /** Dimensione massima dell'autorizzazione in PDF. */
    public const MAX_BYTE_AUTORIZZAZIONE = 10 * 1024 * 1024;

    public function __construct(private Orologio $orologio)
    {
    }

    /**
     * L'attività è di Formazione Scuola Lavoro e la prenotazione è di una classe: servono i documenti. Progetti: solo quelli
     * per le scuole.
     *
     * @param array<string, mixed> $p riga con fsl (progetti_dettagli.convenzione), evento_tipo (o tipo) e per_scuole
     */
    public function richiesti(array $p): bool
    {
        if ((int) ($p['fsl'] ?? 0) !== 1) {
            return false;
        }
        $eProgetto = ($p['evento_tipo'] ?? $p['tipo'] ?? 'evento') === 'progetto';

        return !$eProgetto || (int) ($p['per_scuole'] ?? 1) === 1;
    }

    /** @param array<string, mixed> $p riga con stato */
    public function attiva(array $p): bool
    {
        return in_array($p['stato'] ?? 'confermata', self::STATI_ATTIVI, true);
    }

    /**
     * La consegna dei documenti è aperta: attività FSL di classe, prenotazione attiva e attività non ancora iniziata (o senza data).
     *
     * @param array<string, mixed> $p
     */
    public function consegnaAperta(array $p): bool
    {
        $inizio = $this->inizio($p);

        return $this->richiesti($p) && $this->attiva($p) && ($inizio === null || $inizio >= $this->oggi());
    }

    /**
     * Primo giorno dell'attività (Y-m-d): quello del turno, o l'inizio del progetto; null se non c'è una data.
     *
     * @param array<string, mixed> $p riga con data_turno e data_inizio (o pd_inizio)
     */
    public function inizio(array $p): ?string
    {
        $d = ($p['data_turno'] ?? null) ?: ($p['data_inizio'] ?? $p['pd_inizio'] ?? null);

        return $d ? substr((string) $d, 0, 10) : null;
    }

    /**
     * Ultimo giorno per consegnare i documenti: GIORNI_SCADENZA giorni prima dell'inizio. Chi prenota più tardi ha tempo fino
     * al giorno prima dell'attività.
     *
     * @param array<string, mixed> $p
     */
    public function scadenza(array $p): ?string
    {
        $inizio = $this->inizio($p);
        if ($inizio === null) {
            return null;
        }
        $data = new DateTimeImmutable($inizio);
        $scadenza = $data->modify('-' . self::GIORNI_SCADENZA . ' days')->format('Y-m-d');
        $prenotata = !empty($p['data_prenotazione']) ? substr((string) $p['data_prenotazione'], 0, 10) : null;

        return $prenotata !== null && $prenotata > $scadenza ? $data->modify('-1 day')->format('Y-m-d') : $scadenza;
    }

    /**
     * Cosa manca: studenti indicati e autorizzazione.
     *
     * @param array<string, mixed> $p riga con autorizzazione_file
     * @return array{studenti: int, max: int, elenco: bool, autorizzazione: bool, completi: bool, scadenza: ?string, scaduti: bool}
     */
    public function stato(array $p, int $studenti, int $max): array
    {
        $elenco = $studenti > 0;
        $autorizzazione = !empty($p['autorizzazione_file']);
        $scadenza = $this->scadenza($p);

        return [
            'studenti' => $studenti,
            'max' => $max,
            'elenco' => $elenco,
            'autorizzazione' => $autorizzazione,
            'completi' => $elenco && $autorizzazione,
            'scadenza' => $scadenza,
            'scaduti' => $scadenza !== null && $scadenza < $this->oggi(),
        ];
    }

    /**
     * Cosa fare oggi per una prenotazione senza tutti i documenti: 'promemoria' (periodico), 'ultimo' (ultimo avviso, una volta
     * sola, quando si è oltre la scadenza ma l'attività non è ancora iniziata) o null (niente oggi).
     *
     * @param array<string, mixed> $p riga con data_turno/data_inizio, data_prenotazione, doc_promemoria, doc_promemoria_il, doc_ultimo_avviso
     */
    public function prossimoAvviso(array $p): ?string
    {
        $adesso = $this->orologio->adesso();
        $inizio = $this->inizio($p);
        if ($inizio === null || $inizio < $adesso->format('Y-m-d')) {
            return null;
        }
        // L'email della prenotazione vale come primo avviso: si parte da lì (o dall'ultimo promemoria)
        $ultimo = !empty($p['doc_promemoria_il']) ? (string) $p['doc_promemoria_il'] : (string) ($p['data_prenotazione'] ?? '');
        $dopo = static fn (string $da, string $giorni): bool => $da === '' || (new DateTimeImmutable($da))->modify("+$giorni days") <= $adesso;

        if ($this->scadenza($p) !== null && $this->scadenza($p) <= $adesso->format('Y-m-d')) {
            return empty($p['doc_ultimo_avviso']) && $dopo($ultimo, '1') ? 'ultimo' : null;
        }
        if ((int) ($p['doc_promemoria'] ?? 0) >= self::MAX_PROMEMORIA) {
            return null;
        }

        return $dopo($ultimo, (string) self::INTERVALLO_PROMEMORIA) ? 'promemoria' : null;
    }

    private function oggi(): string
    {
        return $this->orologio->adesso()->format('Y-m-d');
    }
}
