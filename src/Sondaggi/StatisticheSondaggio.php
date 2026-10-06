<?php

declare(strict_types=1);

namespace App\Sondaggi;

/** Risultati di un sondaggio per domanda: medie delle stelle e dell'NPS, conteggi delle scelte, medie della matrice, testi liberi. */
final class StatisticheSondaggio
{
    /**
     * @param list<array<string, mixed>> $risposte risposte con tipo e testo della domanda (SondaggioRepository::risposteConDomande)
     * @return array<int|string, array<string, mixed>> per id della domanda: testo, tipo, risposte, totale_voti, somma_voti, conteggi_opzioni, conteggi_matrice, distribuzione_nps
     */
    public static function calcola(array $risposte): array
    {
        $stat = [];
        foreach ($risposte as $r) {
            $dId = $r['domanda_id'];
            if (!isset($stat[$dId])) {
                $stat[$dId] = [
                    'testo' => $r['testo_domanda'], 'tipo' => $r['tipo'],
                    'risposte' => [], 'totale_voti' => 0, 'somma_voti' => 0,
                    'conteggi_opzioni' => [], 'conteggi_matrice' => [],
                    'distribuzione_nps' => array_fill(0, 11, 0),
                ];
            }
            if ($r['tipo'] === 'rating') {
                $stat[$dId]['totale_voti']++;
                $stat[$dId]['somma_voti'] += (int) $r['risposta'];
            } elseif ($r['tipo'] === 'nps') {
                $val = min(10, max(0, (int) $r['risposta']));
                $stat[$dId]['totale_voti']++;
                $stat[$dId]['somma_voti'] += $val;
                $stat[$dId]['distribuzione_nps'][$val]++;
            } elseif ($r['tipo'] === 'matrice') {
                $valJson = json_decode((string) $r['risposta'], true);
                if (is_array($valJson)) {
                    foreach ($valJson as $sub => $voto) {
                        if (!isset($stat[$dId]['conteggi_matrice'][$sub])) {
                            $stat[$dId]['conteggi_matrice'][$sub] = ['somma' => 0, 'tot' => 0];
                        }
                        $stat[$dId]['conteggi_matrice'][$sub]['somma'] += (int) $voto;
                        $stat[$dId]['conteggi_matrice'][$sub]['tot']++;
                    }
                    $stat[$dId]['totale_voti']++;
                }
            } elseif (in_array($r['tipo'], ['radio', 'select', 'checkbox', 'checkboxes'])) {
                $val = trim((string) $r['risposta']);
                if (!isset($stat[$dId]['conteggi_opzioni'][$val])) {
                    $stat[$dId]['conteggi_opzioni'][$val] = 0;
                }
                $stat[$dId]['conteggi_opzioni'][$val]++;
                $stat[$dId]['totale_voti']++;
            } else {
                $stat[$dId]['risposte'][] = $r['risposta'];
            }
        }

        return $stat;
    }
}
