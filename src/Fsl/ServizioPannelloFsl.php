<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Anagrafi\ServizioScuole;
use App\Anagrafi\Testi;
use App\Core\Orologio;

/** Dati del pannello Formazione Scuola Lavoro (admin/fsl.php): riepilogo dell'anno scolastico, valutazioni, registro delle convenzioni, iscrizioni da stipulare. */
final class ServizioPannelloFsl
{
    public function __construct(
        private PannelloRepository $pannello,
        private ConvenzioneRepository $convenzioni,
        private ServizioConvenzioni $servizio,
        private PeriodiConvenzione $periodi,
        private ServizioScuole $scuole,
        private Orologio $orologio
    ) {
    }

    /** Anno scolastico in corso: va dal 1° settembre al 31 agosto, e si indica con l'anno d'inizio. */
    public function annoCorrente(): int
    {
        $adesso = $this->orologio->adesso();

        return (int) $adesso->format('n') >= 9 ? (int) $adesso->format('Y') : (int) $adesso->format('Y') - 1;
    }

    /**
     * Riepilogo dell'anno scolastico con le iscrizioni alle attività FSL, i numeri per scuola e per attività e le schede di valutazione.
     *
     * @return array{righe: list<array<string, mixed>>, conf: array<int, array<string, mixed>>, per_scuola: array<string, array<string, mixed>>, per_att: array<int, array<string, mixed>>, n_studenti: int, n_conv_mancanti: int, valutazioni: list<array<string, mixed>>, somme: array<int|string, list<int>>, rip: array<string, int>, n_inviti: int}
     */
    public function riepilogo(string $dal, string $al): array
    {
        $righe = [];
        foreach ($this->pannello->iscrizioniAnno($dal, $al) as $x) {
            $x['studenti'] = (int) ((json_decode((string) $x['dati_custom_json'], true) ?: [])[CAMPO_PARTECIPANTI] ?? 0);
            [$x['dal'], $x['al']] = $this->periodi->prenotazione($x);
            $x['conv_ok'] = $x['convenzione'] === 'ricevuta' || (!empty($x['scuola_codice']) && $this->servizio->valida($x['scuola_codice'], false, $x['dal'], $x['al']));
            $x['scuola_nome'] = $this->nomeScuola($x);
            $righe[] = $x;
        }
        $conf = array_filter($righe, static fn ($x): bool => ($x['stato'] ?: 'confermata') === 'confermata');

        // Numeri principali
        $perScuola = [];
        $perAtt = [];
        foreach ($righe as $x) {
            $k = $x['scuola_codice'] ?: 'txt:' . mb_strtolower($x['scuola_nome']);
            $s = &$perScuola[$k];
            $s['nome'] = $x['scuola_nome'];
            $s['codice'] = $x['scuola_codice'];
            $s['attivita'][$x['titolo']] = true;
            $s['iscrizioni'] = ($s['iscrizioni'] ?? 0) + 1;
            if (($x['stato'] ?: 'confermata') === 'confermata') {
                $s['studenti'] = ($s['studenti'] ?? 0) + $x['studenti'];
                $s['presenze'] = ($s['presenze'] ?? 0) + (int) $x['presente'];
            }
            $s['conv_mancanti'] = ($s['conv_mancanti'] ?? 0) + ($x['conv_ok'] ? 0 : 1);
            if ($x['val_id']) {
                $s['voti'][] = (float) $x['val_media'];
            }
            unset($s);
            $a = &$perAtt[$x['evento_id']];
            $a['titolo'] = $x['titolo'];
            $a['area'] = $x['area'];
            $a['tipo'] = $x['tipo'];
            $a['dal'] = min($a['dal'] ?? $x['dal'], $x['dal']);
            $a['al'] = max($a['al'] ?? $x['al'], $x['al']);
            $a['scuole'][$k] = true;
            $a['iscrizioni'] = ($a['iscrizioni'] ?? 0) + 1;
            if (($x['stato'] ?: 'confermata') === 'confermata') {
                $a['studenti'] = ($a['studenti'] ?? 0) + $x['studenti'];
                $a['presenze'] = ($a['presenze'] ?? 0) + (int) $x['presente'];
                $a['confermate'] = ($a['confermate'] ?? 0) + 1;
            }
            $a['conv_mancanti'] = ($a['conv_mancanti'] ?? 0) + ($x['conv_ok'] ? 0 : 1);
            if ($x['valutazione_inviata']) {
                $a['val_inviate'] = ($a['val_inviate'] ?? 0) + 1;
            }
            if ($x['val_id']) {
                $a['voti'][] = (float) $x['val_media'];
            }
            unset($a);
        }
        uasort($perScuola, static fn ($a, $b): int => strcmp($a['nome'], $b['nome']));

        // Schede di valutazione dell'anno
        $valutazioni = [];
        $somme = [];
        $rip = ['si' => 0, 'forse' => 0, 'no' => 0];
        foreach ($this->pannello->valutazioniAnno($dal, $al) as $x) {
            $x['risposte'] = json_decode((string) $x['risposte_json'], true) ?: [];
            foreach (($x['risposte']['voti'] ?? []) as $k => $v) {
                $somme[$k][] = (int) $v;
            }
            if (isset($rip[$x['ripeterebbe']])) {
                ++$rip[$x['ripeterebbe']];
            }
            $valutazioni[] = $x;
        }

        return [
            'righe' => $righe,
            'conf' => $conf,
            'per_scuola' => $perScuola,
            'per_att' => $perAtt,
            'n_studenti' => array_sum(array_map(static fn ($x): int => $x['studenti'], $conf)),
            'n_conv_mancanti' => count(array_filter($righe, static fn ($x): bool => !$x['conv_ok'])),
            'valutazioni' => $valutazioni,
            'somme' => $somme,
            'rip' => $rip,
            'n_inviti' => count(array_filter($righe, static fn ($x): bool => !empty($x['valutazione_inviata']))),
        ];
    }

    /**
     * Registro delle convenzioni raggruppato per scuola (la più recente per prima), diviso in due viste: in vigore (valide, in
     * scadenza, non ancora valide) e archivio (scadute: ci passano da sole il giorno dopo la scadenza).
     *
     * @return array{vigore: array<string, list<array<string, string|null>>>, archivio: array<string, list<array<string, string|null>>>}
     */
    public function registro(): array
    {
        $vigore = [];
        $archivio = [];
        $oggi = $this->orologio->adesso()->format('Y-m-d');
        foreach ($this->pannello->registro() as $x) {
            if ($x['scadenza'] !== null && $x['scadenza'] < $oggi) {
                $archivio[$x['scuola_codice']][] = $x;
            } else {
                $vigore[$x['scuola_codice']][] = $x;
            }
        }
        $perNome = fn (array $a, array $b): int => strcmp(
            Testi::etichettaScuola($this->scuole->perCodice($a[0]['scuola_codice']) ?? ['denominazione' => $a[0]['scuola_codice']]),
            Testi::etichettaScuola($this->scuole->perCodice($b[0]['scuola_codice']) ?? ['denominazione' => $b[0]['scuola_codice']])
        );
        uasort($vigore, $perNome);
        uasort($archivio, $perNome);

        return ['vigore' => $vigore, 'archivio' => $archivio];
    }

    /**
     * Iscrizioni senza convenzione valida: da stipulare (attività FSL o richiesta dai gestori) e scuole scritte a mano nelle attività FSL.
     * Il registro può essere cambiato dopo l'ultima verifica: chi ora è coperto non va in elenco (si aggiorna con «Verifica ora» o dal cron).
     *
     * @return array{0: list<array<string, string|null>>, 1: int} [iscrizioni da stipulare, iscrizioni ora coperte]
     */
    public function daStipulare(): array
    {
        $daStipulare = [];
        $coperte = 0;
        foreach ($this->pannello->iscrizioniDaStipulare() as $x) {
            [$dal, $al] = $this->periodi->prenotazione($x);
            if (!empty($x['scuola_codice']) && $this->servizio->valida($x['scuola_codice'], false, $dal, $al)) {
                ++$coperte;
                continue;
            }
            $daStipulare[] = $x;
        }

        return [$daStipulare, $coperte];
    }

    /**
     * Una convenzione per id (per modificarla o rinnovarla).
     *
     * @return array<string, string|null>|null
     */
    public function convenzione(int $id): ?array
    {
        return $this->convenzioni->perId($id);
    }

    /**
     * Nome della scuola: l'etichetta dell'anagrafe, altrimenti quello scritto nel modulo, «—» se non c'è.
     *
     * @param array<string, mixed> $x
     */
    private function nomeScuola(array $x): string
    {
        return $this->scuole->nomeDaPrenotazione($x) ?: '—';
    }
}
