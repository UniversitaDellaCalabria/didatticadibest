<?php

declare(strict_types=1);

namespace App\Didattica;

/** Statistiche delle pratiche inviate in un periodo: per modulo e per corso di studio, tempi medi di ogni passo dell'iter e di chiusura. */
final class StatisticheDidattica
{
    public function __construct(private PraticaRepository $pratiche)
    {
    }

    /**
     * Pratiche inviate tra $dal e $al: per modulo e per corso di studio (stati ed esiti), tempi medi per passo dell'iter
     * (dal passaggio al passaggio successivo o alla conclusione) e tempo medio di chiusura.
     *
     * @return array<string, mixed>
     */
    public function calcola(string $dal, string $al): array
    {
        $pr = $this->pratiche->inviateTra($dal, $al);
        $vuoto = ['totale' => 0, 'aperte' => 0, 'accolta' => 0, 'respinta' => 0, 'chiusa' => 0, 'giorni' => []];
        $perMod = [];
        $perCorso = [];
        $passi = [];
        $chiusura = [];
        $finali = ['accolta', 'respinta', 'chiusa'];
        foreach ($pr as $p) {
            $corso = 'Senza corso di studio';
            foreach (json_decode((string) $p['risposte_json'], true) ?: [] as $r) {
                if (($r['tipo'] ?? '') === 'corso_studio' && $r['valore'] !== '') {
                    $corso = $r['valore'];
                    break;
                }
            }
            $conta = function (array &$tab, string $k) use ($p, $vuoto, $finali) {
                $tab[$k] ??= $vuoto;
                $tab[$k]['totale']++;
                if (in_array($p['stato'], $finali, true)) {
                    $tab[$k][$p['stato']]++;
                } else {
                    $tab[$k]['aperte']++;
                }
            };
            $conta($perMod, $p['modulo_titolo']);
            $conta($perCorso, $corso);
            // Tempi: eventi della pratica in ordine
            $ev = $this->pratiche->eventiPerTempi((int) $p['id']);
            $inizio = strtotime($p['creata_il']);
            $passoCorr = 'Smistamento';
            $tCorr = $inizio;
            $fine = null;
            foreach ($ev as $e) {
                $t = strtotime($e['creato_il']);
                if ($e['tipo'] === 'passaggio') {
                    $passi[$passoCorr][] = ($t - $tCorr) / 86400;
                    $passoCorr = preg_match('/^In carico a: (.+?) – /u', (string) $e['testo'], $mm) ? $mm[1] : 'Ufficio didattico';
                    $tCorr = $t;
                } elseif ($e['tipo'] === 'stato' && in_array($e['stato'], $finali, true) && $fine === null) {
                    $passi[$passoCorr][] = ($t - $tCorr) / 86400;
                    $fine = $t;
                }
            }
            if ($fine) {
                $chiusura[] = ($fine - $inizio) / 86400;
                $perMod[$p['modulo_titolo']]['giorni'][] = ($fine - $inizio) / 86400;
            }
        }
        $media = fn (array $v) => $v ? round(array_sum($v) / count($v), 1) : null;
        foreach ($perMod as &$x) {
            $x['giorni'] = $media($x['giorni']);
        }
        unset($x);
        foreach ($perCorso as &$x) {
            unset($x['giorni']);
        }
        unset($x);
        $tempi = [];
        foreach ($passi as $nome => $v) {
            $tempi[$nome] = ['media' => $media($v), 'n' => count($v), 'max' => round(max($v), 1)];
        }
        uasort($perMod, fn ($a, $b) => $b['totale'] <=> $a['totale']);
        uasort($perCorso, fn ($a, $b) => $b['totale'] <=> $a['totale']);
        $tot = count($pr);
        $esiti = ['accolta' => 0, 'respinta' => 0, 'chiusa' => 0, 'aperte' => 0];
        foreach ($pr as $p) {
            if (in_array($p['stato'], $finali, true)) {
                $esiti[$p['stato']]++;
            } else {
                $esiti['aperte']++;
            }
        }

        return ['totale' => $tot, 'esiti' => $esiti, 'per_modulo' => $perMod, 'per_corso' => $perCorso, 'tempi_passi' => $tempi, 'chiusura_media' => $media($chiusura)];
    }
}
