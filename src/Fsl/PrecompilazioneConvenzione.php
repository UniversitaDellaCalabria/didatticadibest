<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Anagrafi\ServizioScuole;
use App\Anagrafi\Testi;
use App\Eventi\EventoRepository;
use App\Eventi\Turni;

/** Dati con cui si precompilano la Convenzione e l'Allegato A (scuola, attività, studenti, periodo, tutor) e attività FSL della scuola. */
final class PrecompilazioneConvenzione
{
    /** Segnaposto dell'attività nell'Allegato A (il resto sono dati della scuola e del Dirigente). */
    private const CAMPI_ATTIVITA = ['TITOLO', 'DESCRIZIONE', 'STUDENTI', 'PERIODO', 'DURATA', 'TUTOR_DIBEST', 'TUTOR_SCUOLA'];

    public function __construct(
        private PrenotazioneFslRepository $prenotazioni,
        private EventoRepository $eventi,
        private ServizioScuole $scuole,
        private PeriodiConvenzione $periodi,
        private DocumentoConvenzione $documenti
    ) {
    }

    /**
     * Dati per la convenzione precompilata (modelli_documenti/convenzione_precompilabile.docx): scuola dall'anagrafe (istituto
     * principale se c'è), attività, studenti, periodo, durata e tutor. Vuoto = campo lasciato da compilare.
     *
     * @param array<string, mixed> $p prenotazione con i dati dell'attività (PrenotazioneFslRepository::dati)
     * @return array<string, string>
     */
    public function dati(array $p): array
    {
        $s = !empty($p['scuola_codice']) ? $this->scuole->perCodice($p['scuola_codice']) : null;
        $ist = $s && !empty($s['istituto_codice']) ? $this->scuole->perCodice($s['istituto_codice']) : null;
        $sede = $ist ?: $s;
        $nomeIst = $s ? Testi::maiuscoleScuola((string) ($s['istituto_denominazione'] ?: $s['denominazione'])) : '';
        $codIst = $s ? (string) ($s['istituto_codice'] ?: $s['codice']) : '';
        $dett = $this->eventi->dettagliProgetti([(int) $p['evento_id']])[(int) $p['evento_id']] ?? [];
        $ev = $this->prenotazioni->descrizioneEvento((int) $p['evento_id']);
        $testo = static fn ($html): string => trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], ' ', (string) $html)), ENT_QUOTES, 'UTF-8')));
        $descr = $testo($ev['descrizione_breve'] ?? '') ?: $testo($dett['obiettivi'] ?? '') ?: $testo($ev['descrizione'] ?? '');
        if (mb_strlen($descr) > 600) {
            $descr = rtrim(mb_substr($descr, 0, 597)) . '…';
        }
        $descr = rtrim($descr, ' .');   // nel modello dopo la descrizione c'è già il punto
        [$dal, $al] = $this->periodi->prenotazione($p);
        $periodo = $dal === $al ? date('d/m/Y', (int) strtotime($dal)) : 'dal ' . date('d/m/Y', (int) strtotime($dal)) . ' al ' . date('d/m/Y', (int) strtotime($al));
        $durata = '';
        if (!empty($dett['ore_totali'])) {
            $durata = (int) $dett['ore_totali'] . ' ore';
        } elseif (!empty($p['orario_inizio']) && !empty($p['orario_fine'])) {
            $min = (strtotime($p['orario_fine']) - strtotime($p['orario_inizio'])) / 60;
            if ($min > 0) {
                $durata = rtrim(rtrim(number_format($min / 60, 1, ',', ''), '0'), ',') . ' ore (' . Turni::orario($p) . ')';
            }
        }
        $tutorDip = '';
        foreach ((array) ($dett['referenti'] ?? []) as $ref) {
            if (!empty($ref['nome'])) {
                $tutorDip = $ref['nome'];
                break;
            }
        }
        $custom = json_decode((string) ($p['dati_custom_json'] ?? ''), true) ?: [];
        $tutorSc = trim((string) ($custom['docente_riferimento'] ?? '')) ?: trim($p['nome'] . ' ' . $p['cognome']);

        return [
            'ISTITUTO' => $nomeIst !== '' ? $nomeIst . ' (codice meccanografico ' . $codIst . ')' : '',
            'COMUNE' => $sede ? Testi::maiuscoleScuola((string) $sede['comune']) . (!empty($sede['provincia']) ? ' (' . Testi::maiuscoleScuola((string) $sede['provincia']) . ')' : '') : '',
            'INDIRIZZO' => $sede && !empty($sede['indirizzo']) ? Testi::maiuscoleScuola((string) $sede['indirizzo']) . (!empty($sede['cap']) ? ', ' . $sede['cap'] : '') : '',
            'ISTITUTO_FIRMA' => $nomeIst,
            'TITOLO' => (string) $p['evento_titolo'] . (Turni::etichetta($p) !== '' && ($ev['tipo'] ?? '') !== 'progetto' ? ' – ' . Turni::etichetta($p) : ''),
            'DESCRIZIONE' => $descr,
            'STUDENTI' => (int) ($custom[CAMPO_PARTECIPANTI] ?? 0) > 0 ? (string) (int) $custom[CAMPO_PARTECIPANTI] : '',
            'PERIODO' => $periodo,
            'DURATA' => $durata,
            'TUTOR_DIBEST' => $tutorDip,
            'TUTOR_SCUOLA' => $tutorSc,
        ];
    }

    /**
     * Convenzione o Allegato A precompilati con i dati di una prenotazione (link «già compilata» delle email precedenti).
     * Ritorna il file temporaneo da cancellare dopo l'uso, null se la prenotazione o il modello non ci sono.
     */
    public function genera(int $prenotazioneId, string $doc = 'convenzione'): ?string
    {
        $p = $this->prenotazioni->dati($prenotazioneId);
        if (!$p) {
            return null;
        }
        $v = $this->dati($p);
        $att = array_intersect_key($v, array_flip(self::CAMPI_ATTIVITA));

        return $this->documenti->genera($doc, array_diff_key($v, $att), [$att]);
    }

    /**
     * Per la convenzione online: attività FSL prenotate dalla scuola (dalla prenotazione $prenotazioneId e, se la scuola è
     * dell'anagrafe, tutte le sue prenotazioni FSL attive non concluse) e attività FSL ancora prenotabili.
     * $altreDaIncludere = altre prenotazioni da mettere tra quelle prenotate (le scelte della compilazione, per le scuole fuori anagrafe).
     *
     * @param list<int> $altreDaIncludere
     * @return array{0: array<int, array<string, string|null>>, 1: array<int, array<string, mixed>>} [prenotate, prenotabili]
     */
    public function attivitaScuola(int $prenotazioneId, ?string $codiceScuola, array $altreDaIncludere = []): array
    {
        $sc = strtoupper(trim((string) $codiceScuola));
        $prenotate = [];
        $ids = $this->prenotazioni->idPrenotateDallaScuola($prenotazioneId, $sc);
        foreach ($altreDaIncludere as $altra) {
            if ((int) $altra > 0 && !in_array((int) $altra, $ids, true)) {
                $ids[] = (int) $altra;
            }
        }
        foreach ($ids as $id) {
            if ($p = $this->prenotazioni->dati($id)) {
                $prenotate[$id] = $p;
            }
        }
        $evPrenotati = array_map(static fn ($p): int => (int) $p['evento_id'], $prenotate);
        $prenotabili = [];
        foreach ($this->prenotazioni->attivitaPrenotabili() as $x) {
            if (!in_array((int) $x['evento_id'], $evPrenotati, true)) {
                $prenotabili[(int) $x['evento_id']] = $x + ['nome_turno' => '', 'orario_inizio' => null, 'orario_fine' => null, 'nome' => '', 'cognome' => '', 'dati_custom_json' => null, 'scuola_codice' => null];
            }
        }

        return [$prenotate, $prenotabili];
    }
}
