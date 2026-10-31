<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Core\Orologio;
use App\Iscrizioni\ServizioAreaPersonale;

/**
 * I percorsi FSL di una scuola per la tessera dell'area personale: per ogni prenotazione di un'attività di Formazione Scuola Lavoro della persona,
 * i passi e l'unica cosa da fare (regole in PercorsoScuola). Mette insieme dati che la pagina «Eventi e attestati» calcola già: prenotazioni,
 * elenco studenti e autorizzazione (ServizioDocumentiClasse), convenzione firmata e link personale (ServizioConvenzioneOnline). Non scrive niente.
 */
final class ServizioPercorsiScuola
{
    /** Le prenotazioni finite da più di tanti giorni non compaiono più nella tessera (restano in «Eventi e attestati»). */
    public const GIORNI_STORICO = 180;

    public const MAX = 8;

    private const STATI_FUORI = ['annullata', 'rifiutata', 'scaduta'];

    public function __construct(
        private ServizioAreaPersonale $area,
        private ServizioDocumentiClasse $documenti,
        private ServizioConvenzioneOnline $convenzioni,
        private Orologio $orologio
    ) {
    }

    /**
     * @return list<array{id: int, titolo: string, turno: string, data: string, codice: string, percorso: array<string, mixed>}> prima le prenotazioni con
     *   qualcosa da fare, poi per data
     */
    public function perUtente(int $utenteId, string $email): array
    {
        $oggi = $this->orologio->adesso()->format('Y-m-d');
        $lista = $this->area->prenotazioni($utenteId, $email);
        $fsl = array_values(array_filter(
            array_merge($lista['attive'], $lista['passate']),
            fn (array $pr): bool => $this->documenti->richiesti($pr) && !in_array((string) ($pr['stato'] ?? 'confermata'), self::STATI_FUORI, true)
        ));
        if (!$fsl) {
            return [];
        }
        $perCodice = [];
        foreach ($this->convenzioni->dellePrenotazioni(array_map(static fn (array $pr): int => (int) $pr['id'], $fsl)) as $cv) {
            foreach ($cv['attivita'] as $a) {
                $perCodice[$a['codice']] ??= $cv;
            }
        }

        $out = [];
        foreach ($fsl as $pr) {
            $docs = null;
            if ($this->documenti->consegnaAperta($pr)) {
                $docs = $this->documenti->stato($pr);
            } elseif ($this->documenti->richiesti($pr) && $this->documenti->attiva($pr) && ($s = $this->documenti->stato($pr))['completi']) {
                $docs = $s;
            }
            $dati = self::dati($pr, $perCodice[(string) $pr['codice_prenotazione']] ?? null, $docs);
            if (!empty($dati['al']) && (string) $dati['al'] < date('Y-m-d', (int) strtotime($oggi . ' -' . self::GIORNI_STORICO . ' days'))) {
                continue;
            }
            $out[] = [
                'id' => (int) $pr['id'], 'titolo' => (string) $pr['evento_titolo'], 'turno' => trim((string) ($pr['nome_turno'] ?? '')),
                'data' => (string) ($pr['data_turno'] ?? ''), 'codice' => (string) $pr['codice_prenotazione'],
                'percorso' => PercorsoScuola::per($dati, $oggi),
            ];
        }
        usort($out, static fn (array $a, array $b): int => [$a['percorso']['azione'] === null, $a['data'] === '', $a['data']] <=> [$b['percorso']['azione'] === null, $b['data'] === '', $b['data']]);

        return array_slice($out, 0, self::MAX);
    }

    /**
     * I dati di una prenotazione nella forma che vuole PercorsoScuola::per().
     *
     * @param array<string, mixed> $pr riga di ServizioAreaPersonale::prenotazioni()
     * @param array<string, mixed>|null $conv voce di ServizioConvenzioneOnline::dellePrenotazioni() che contiene la prenotazione
     * @param array<string, mixed>|null $docs risultato di ServizioDocumentiClasse::stato(), null se per l'attività non si chiedono i documenti
     * @return array<string, mixed>
     */
    public static function dati(array $pr, ?array $conv, ?array $docs): array
    {
        $eProgetto = ($pr['evento_tipo'] ?? 'evento') === 'progetto';
        $fine = $eProgetto ? ($pr['progetto_fine'] ?? null) : ($pr['data_turno'] ?? null);

        return [
            'id' => (int) $pr['id'], 'codice' => (string) $pr['codice_prenotazione'], 'stato' => (string) ($pr['stato'] ?? 'confermata'),
            'token' => (string) ($conv['token'] ?? ''), 'convenzione' => (string) ($conv['convenzione'] ?? ''), 'iter' => (string) ($conv['iter'] ?? ''),
            'documenti' => $docs, 'al' => $fine ? substr((string) $fine, 0, 10) : null,
            'attestati' => PercorsoFsl::haAttestati(['stato' => $pr['stato'] ?? 'confermata', 'tipo' => $pr['evento_tipo'] ?? 'evento', 'pd_attestati' => $pr['attestati'] ?? 0, 'pd_per_scuole' => $pr['per_scuole'] ?? 1]),
            'attestato_inviato' => !empty($pr['attestato_inviato']),
        ];
    }
}
