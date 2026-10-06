<?php

declare(strict_types=1);

namespace App\Didattica;

/** Testi e dati di una pratica per il verbale, l'estratto e la domanda: segnaposti, risposte, richieste dello studente e quadro delle decisioni. */
final class TestiPratica
{
    /**
     * Risposte dello studente + campi compilati dall'ufficio, per etichetta (minuscola) => risposta.
     *
     * @param array<string, mixed> $p
     * @return array<string, array<string, mixed>>
     */
    public static function risposteTutte(array $p): array
    {
        $out = [];
        foreach (array_merge(json_decode((string) $p['risposte_json'], true) ?: [], json_decode((string) ($p['ufficio_json'] ?? ''), true) ?: []) as $r) {
            $out[mb_strtolower(trim((string) $r['etichetta']))] = $r;
        }

        return $out;
    }

    /**
     * {STUDENTE} (COGNOME NOME), {NOME}, {COGNOME}, {MATRICOLA}, {EMAIL}, {CODICE}, {MODULO}, {DATA}, {PROTOCOLLO},
     * {ATENEO_PRECEDENTE} (Ateneo scritto o scelto della carriera precedente), {Etichetta di un campo}.
     *
     * @param array<string, mixed> $p
     */
    public static function segnaposti(string $tpl, array $p): string
    {
        $ris = self::risposteTutte($p);
        $fissi = ['studente' => mb_strtoupper(trim($p['cognome'] . ' ' . $p['nome'])), 'nome' => $p['nome'], 'cognome' => $p['cognome'], 'matricola' => $p['matricola'],
                  'email' => $p['email'], 'codice' => $p['codice'], 'modulo' => $p['modulo_titolo'] ?? '', 'data' => date('d/m/Y', strtotime($p['creata_il'])),
                  'protocollo' => trim(($p['protocollo'] ?? '') . (!empty($p['protocollo_data']) ? ' del ' . date('d/m/Y', strtotime($p['protocollo_data'])) : '')),
                  'ateneo_precedente' => DomandaPdfa::ateneoPrecedente($p)];

        return (string) preg_replace_callback('/\{([^{}\n]{1,200})\}/u', function ($m) use ($fissi, $ris) {
            $k = mb_strtolower(trim($m[1]));
            if (isset($fissi[$k])) {
                return (string) $fissi[$k];
            }
            if (isset($ris[$k])) {
                $v = (string) $ris[$k]['valore'];
                if (($ris[$k]['tipo'] ?? '') === 'tabella') {
                    return '';
                }

                return ($ris[$k]['tipo'] ?? '') === 'date' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? date('d/m/Y', (int) strtotime($v)) : $v;
            }

            return $m[0];
        }, $tpl);
    }

    /**
     * Come compare il modulo nel verbale: titolo della sezione, stile, testo per ogni pratica con i segnaposto, delibera, colonne, raggruppamento.
     *
     * @param array<string, mixed> $m
     * @return array<string, string>
     */
    public static function verbaleModulo(array $m): array
    {
        $v = json_decode((string) ($m['verbale_json'] ?? ''), true) ?: [];

        return [
            'sezione' => trim((string) ($v['sezione'] ?? '')) ?: (string) ($m['titolo'] ?? $m['modulo_titolo'] ?? ''),
            'stile' => ($v['stile'] ?? '') === 'elenco' ? 'elenco' : 'scheda',
            'intro' => (string) ($v['intro'] ?? ''),
            'testo' => trim((string) ($v['testo'] ?? '')) ?: '{STUDENTE}, matricola {MATRICOLA}, presenta la richiesta: {MODULO}.',
            'delibera' => (string) ($v['delibera'] ?? 'Il Consiglio approva.'),
            'chiusura' => (string) ($v['chiusura'] ?? ''),
            'colonne' => (string) ($v['colonne'] ?? ''),
            'raggruppa' => (string) ($v['raggruppa'] ?? ''),
            'decisione' => isset(Costanti::DECISIONI_SEDUTA[$v['decisione'] ?? '']) ? (string) ($v['decisione'] ?? '') : '',
        ];
    }

    /**
     * Tipo di decisioni che il modulo prevede in seduta: «convalide», «piano» o vuoto.
     *
     * @param array<string, mixed>|null $m
     */
    public static function decisioneModulo(?array $m): string
    {
        $v = json_decode((string) ($m['verbale_json'] ?? ''), true) ?: [];

        return isset(Costanti::DECISIONI_SEDUTA[$v['decisione'] ?? '']) ? (string) ($v['decisione'] ?? '') : '';
    }

    /**
     * Insegnamenti indicati dallo studente (righe delle tabelle con una colonna «insegnamento», campi Insegnamento):
     * [['richiesto', 'cfu', 'voto', 'ssd', 'data']].
     *
     * @param array<string, mixed> $p
     * @param list<array<string, mixed>> $campi
     * @return list<array<string, mixed>>
     */
    public static function righeRichieste(array $p, array $campi = []): array
    {
        $tipiCol = [];
        foreach ($campi as $c) {
            if ($c['tipo'] === 'tabella') {
                $tipiCol[mb_strtolower($c['etichetta'])] = array_column($c['colonne'] ?: CampiModulo::colonne($c['opzioni']), 'tipo');
            }
        }
        $out = [];
        foreach (json_decode((string) $p['risposte_json'], true) ?: [] as $r) {
            if (!empty($r['nascosto'])) {
                continue;
            }
            if (($r['tipo'] ?? '') === 'tabella' && !empty($r['righe'])) {
                $tipi = $tipiCol[mb_strtolower((string) $r['etichetta'])] ?? array_map([CampiModulo::class, 'tipoColonnaDaNome'], $r['colonne'] ?? []);
                $jIns = array_search('insegnamento', $tipi, true);
                if ($jIns === false) {
                    $jIns = array_search('insegnamento_dip', $tipi, true);
                }
                if ($jIns === false) {
                    $jIns = array_search('denominazione', $tipi, true);
                }
                if ($jIns === false) {
                    continue;
                }
                $col = fn (string $t, array $riga) => ($j = array_search($t, $tipi, true)) !== false ? (string) ($riga[$j] ?? '') : '';
                foreach ($r['righe'] as $riga) {
                    if (trim((string) ($riga[$jIns] ?? '')) === '') {
                        continue;
                    }
                    [$piano, $elimina] = CampiModulo::valorePiano($col('piano', $riga));
                    $cod = trim($col('codice', $riga));
                    $out[] = ['richiesto' => ($cod !== '' ? $cod . ' – ' : '') . (string) $riga[$jIns], 'cfu' => $col('cfu', $riga), 'voto' => $col('voto', $riga), 'ssd' => $col('ssd', $riga), 'data' => $col('data', $riga)]
                           + ($piano ? ['piano' => 1, 'elimina' => $elimina] : []);
                }
            } elseif (in_array($r['tipo'] ?? '', ['insegnamento', 'insegnamento_ateneo'], true) && trim((string) $r['valore']) !== '') {
                $m = $r['meta'] ?? [];
                $out[] = ['richiesto' => (string) $r['valore'], 'cfu' => isset($m['cfu']) ? (string) $m['cfu'] : '', 'voto' => '', 'ssd' => (string) ($m['ssd'] ?? ''), 'data' => ''];
            }
        }

        return $out;
    }

    /**
     * Decisioni salvate in seduta, altrimenti le righe proposte dalle richieste dello studente: ['tipo', 'righe' => [...]].
     *
     * @param array<string, mixed> $p
     * @param list<array<string, mixed>> $campi
     * @return array<string, mixed>
     */
    public static function decisioniPratica(array $p, string $tipo, array $campi = []): array
    {
        $d = json_decode((string) ($p['decisioni_json'] ?? ''), true);
        if (is_array($d) && isset($d['righe']) && ($d['tipo'] ?? '') === $tipo) {
            return $d;
        }
        $righe = [];
        foreach (self::righeRichieste($p, $campi) as $r) {
            $righe[] = $tipo === 'piano' ? $r + ['esito' => 'in_piano']
                                          : $r + ['ins' => '', 'ins_id' => 0, 'ins_cfu' => '', 'esito' => 'totale', 'cfu_ric' => '', 'cfu_int' => ''];
        }

        return ['tipo' => $tipo, 'righe' => $righe, '_proposte' => true];
    }

    /**
     * Intestazioni e righe delle decisioni per il verbale, la pagina e l'Excel.
     *
     * @param array<string, mixed> $d
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    public static function tabellaDecisioni(array $d): array
    {
        if (($d['tipo'] ?? '') === 'piano') {
            return [['Insegnamento', 'CFU', 'Data sostenimento', 'Decisione'], array_values(array_map(fn ($r) => [$r['richiesto'], $r['cfu'], $r['data'] ?? '', Costanti::ESITI_PIANO[$r['esito']] ?? ''], $d['righe'] ?? []))];
        }

        return [['Insegnamento sostenuto', 'CFU', 'Voto', 'S.S.D.', 'Data sostenimento', 'Insegnamento convalidato', 'CFU ins.', 'CFU riconosciuti', 'CFU da integrare', 'Convalida'],
                array_values(array_map(fn ($r) => [$r['richiesto'], $r['cfu'], $r['voto'], $r['ssd'], $r['data'] ?? '', $r['esito'] === 'no' ? '—' : $r['ins'], $r['ins_cfu'], $r['cfu_ric'], $r['cfu_int'], Costanti::ESITI_CONVALIDA[$r['esito']] ?? ''], $d['righe'] ?? []))];
    }

    /** Le decisioni salvate (JSON) come testo, una riga per decisione. */
    public static function testoDecisioni(?string $json): string
    {
        $d = json_decode((string) $json, true);
        if (!is_array($d) || empty($d['righe'])) {
            return '';
        }
        [, $righe] = self::tabellaDecisioni($d);

        return implode("\n", array_map(fn ($r) => implode(' | ', array_filter($r, fn ($x) => $x !== '')), $righe));
    }
}
