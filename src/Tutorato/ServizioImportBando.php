<?php

declare(strict_types=1);

namespace App\Tutorato;

/**
 * Import dei vincitori di un bando dal Word del decreto di approvazione atti: legge la tabella dell'ART. 2, abbina ogni riga a un insegnamento
 * dell'anagrafe di Ateneo (codice, corso di laurea, docente titolare) e crea le lettere in bozza. Mancano nel decreto codice fiscale, email e genere
 * dei vincitori: si completano nell'anteprima o dopo, nella lettera. Il docente titolare proposto dall'anagrafe si può cambiare.
 */
final class ServizioImportBando
{
    public function __construct(
        private ImportGraduatoria $parser,
        private AbbinamentoInsegnamenti $abbinamento,
        private IncaricoRepository $incarichi,
        private BandoRepository $bandi,
        private ServizioIncarichi $lettere
    ) {
    }

    /**
     * Legge il Word e prepara le righe per l'anteprima, con i candidati dell'anagrafe e il docente proposto.
     *
     * @return array{errore: string|null, righe: list<array<string, mixed>>, decreti: array<string, string>}
     */
    public function anteprima(int $bandoId, string $docx): array
    {
        $bando = $this->bandi->perId($bandoId);
        if (!$bando) {
            return ['errore' => 'Bando non trovato.', 'righe' => [], 'decreti' => []];
        }
        $l = $this->parser->leggi($docx);
        if ($l['errore'] !== null) {
            return ['errore' => $l['errore'], 'righe' => [], 'decreti' => []];
        }
        $anno = AbbinamentoInsegnamenti::annoDa((string) $bando['anno_accademico']);
        $righe = [];
        foreach ($l['righe'] as $r) {
            $cand = $this->abbinamento->candidati($anno, (string) $r['corso'], (string) $r['insegnamento']);
            // il docente titolare di ogni candidato, per cambiarlo scegliendo un altro insegnamento
            foreach ($cand as $k => $c) {
                $cand[$k]['persona'] = $c['docente'] !== '' ? $this->abbinamento->docente($c['docente']) : null;
            }
            $scelto = $cand[0] ?? null;
            $doc = $scelto['persona'] ?? null;
            $ore = (float) ($r['ore'] ?? 0);
            $calcolato = $ore > 0 ? Compenso::lettera($ore) : null;
            $r += ['candidati' => $cand, 'insegnamento_id' => $scelto['id'] ?? 0, 'docente' => $doc, 'docente_testo' => $scelto['docente'] ?? '', 'compenso_calcolato' => $calcolato,
                'compenso_diverso' => $calcolato !== null && $r['compenso_decreto'] !== null && abs($calcolato - (float) $r['compenso_decreto']) > 0.01,
                'duplicato' => $r['cognome'] !== '' && $r['nome'] !== '' && $this->incarichi->esisteNelBando($bandoId, (string) $r['cognome'], (string) $r['nome'], (string) ($scelto['nome'] ?? $r['insegnamento']))];
            $righe[] = $r;
        }

        return ['errore' => null, 'righe' => $righe, 'decreti' => $l['decreti']];
    }

    /**
     * Crea le lettere in bozza dalle righe confermate (come arrivano dall'anteprima). Ritorna quante create, quante saltate (già presenti) e gli errori.
     *
     * @param array<int|string, array<string, mixed>> $righe
     * @return array{creati: int, saltati: int, errori: list<string>, ids: list<int>}
     */
    public function importa(int $bandoId, array $righe): array
    {
        $out = ['creati' => 0, 'saltati' => 0, 'errori' => [], 'ids' => []];
        if (!$this->bandi->perId($bandoId)) {
            $out['errori'][] = 'Bando non trovato.';

            return $out;
        }
        foreach ($righe as $n => $r) {
            if (empty($r['includi'])) {
                continue;
            }
            $etichetta = 'Riga ' . ((int) $n + 1);
            $ore = DatiLettera::numeroItaliano($r['ore'] ?? '');
            if ($ore === null || $ore <= 0) {
                $out['errori'][] = "$etichetta: indica le ore.";
                continue;
            }
            $cognome = mb_substr(trim((string) ($r['cognome'] ?? '')), 0, 100);
            $nome = mb_substr(trim((string) ($r['nome'] ?? '')), 0, 100);
            $ins = $this->abbinamento->insegnamento((int) ($r['insegnamento_id'] ?? 0));
            $nomeIns = $ins ? (string) $ins['nome'] . ((string) ($ins['partizione'] ?? '') !== '' ? ' (' . $ins['partizione'] . ')' : '') : mb_substr(trim((string) ($r['insegnamento'] ?? '')), 0, 255);
            $corso = $ins ? (string) $ins['cds_nome'] : mb_substr(trim((string) ($r['corso'] ?? '')), 0, 255);
            $codice = $ins ? (string) $ins['codice'] : '';
            if ($nomeIns === '') {
                $out['errori'][] = "$etichetta: manca l'insegnamento.";
                continue;
            }
            if ($cognome !== '' && $nome !== '' && $this->incarichi->esisteNelBando($bandoId, $cognome, $nome, $nomeIns)) {
                ++$out['saltati'];
                continue;
            }
            $compenso = DatiLettera::numeroItaliano($r['compenso'] ?? '') ?? Compenso::lettera($ore);
            $attivita = 'Tutorato e attività didattico-integrative per l\'insegnamento di ' . $nomeIns . ($codice !== '' ? " (cod. $codice)" : '') . ($corso !== '' ? ' – Corso di laurea in ' . $corso : '');
            $genere = ($r['genere'] ?? 'M') === 'F' ? 'F' : 'M';
            $cf = strtoupper(str_replace(' ', '', mb_substr(trim((string) ($r['codice_fiscale'] ?? '')), 0, 16)));
            $email = strtolower(mb_substr(trim((string) ($r['email'] ?? '')), 0, 255));
            $dtF = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($r['data_fine'] ?? '')) ? (string) $r['data_fine'] : null;
            $v = [$genere, $cognome, $nome, '', null, '', '', '', $cf, filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '', '', mb_substr($attivita, 0, 3000), $ore, mb_substr(trim((string) ($r['periodo'] ?? '')), 0, 255), $compenso,
                trim((string) ($r['docente_persona_id'] ?? '')) ?: null, mb_substr(trim((string) ($r['docente_nome'] ?? '')), 0, 100), mb_substr(trim((string) ($r['docente_cognome'] ?? '')), 0, 100),
                strtolower(mb_substr(trim((string) ($r['docente_email'] ?? '')), 0, 150)), '', null];
            $id = $this->incarichi->inserisci([$bandoId, 'TU-' . strtoupper(bin2hex(random_bytes(4))), ...$v]);
            if ($id <= 0) {
                $out['errori'][] = "$etichetta: salvataggio non riuscito.";
                continue;
            }
            $this->incarichi->impostaGraduatoria($id, $ins ? (int) $ins['id'] : null, $codice, $nomeIns, $corso, mb_substr(trim((string) ($r['ambito'] ?? '')), 0, 150), $dtF);
            $this->lettere->evento($id, 'creata', 'Lettera preparata dall\'import della graduatoria');
            ++$out['creati'];
            $out['ids'][] = $id;
        }

        return $out;
    }
}
