<?php

declare(strict_types=1);

namespace App\Risorse;

/** Statistiche delle prenotazioni di un'area: ore prenotate e utilizzo per risorsa, fasce più richieste, annullate e rifiutate. */
final class StatisticheRisorse
{
    public function __construct(private RisorsaRepository $risorse)
    {
    }

    /**
     * Statistiche tra $dal e $al: per risorsa (prenotazioni, ore prenotate, ore aperte secondo gli orari settimanali, utilizzo,
     * annullate e rifiutate) e fasce più richieste (giorno della settimana × ora).
     *
     * @return array{risorse: array<int, array<string, mixed>>, fasce: array<int, array<int, int>>, totali: array<string, mixed>}
     */
    public function statistiche(int $paginaId, string $dal, string $al): array
    {
        $risorse = $this->risorse->risorseDellArea($paginaId);
        $per = [];
        $fasce = [];
        foreach ($risorse as $r) {
            $per[(int) $r['id']] = ['nome' => $r['nome'], 'tipo' => $r['tipo'], 'prenotazioni' => 0, 'confermate' => 0, 'annullate' => 0, 'rifiutate' => 0, 'ore' => 0.0, 'ore_aperte' => 0.0];
        }
        if (!$per) {
            return ['risorse' => [], 'fasce' => [], 'totali' => []];
        }
        // Ore aperte: orari settimanali di ogni giorno del periodo (al massimo un anno)
        $orari = $this->risorse->oreDiAperturaPerGiorno(array_keys($per));
        $giorni = [];
        for ($t = (int) strtotime($dal), $fine = min((int) strtotime($al), (int) strtotime($dal . ' +366 days')); $t <= $fine; $t += 86400) {
            $giorni[] = (int) date('N', $t);
        }
        foreach ($per as $id => &$p) {
            foreach ($giorni as $g) {
                $p['ore_aperte'] += $orari[$id][$g] ?? 0;
            }
        }
        unset($p);
        foreach ($this->risorse->prenotazioniDelPeriodo($paginaId, $dal, $al) as $x) {
            $id = (int) $x['risorsa_id'];
            if (!isset($per[$id])) {
                continue;
            }
            ++$per[$id]['prenotazioni'];
            if ($x['stato'] === 'annullata') {
                ++$per[$id]['annullate'];
                continue;
            }
            if ($x['stato'] === 'rifiutata') {
                ++$per[$id]['rifiutate'];
                continue;
            }
            if ($x['stato'] !== 'confermata') {
                continue;
            }
            ++$per[$id]['confermate'];
            $i = (int) strtotime((string) $x['inizio']);
            $f = (int) strtotime((string) $x['fine']);
            $per[$id]['ore'] += max(0, ($f - $i) / 3600);
            for ($t = $i; $t < $f; $t += 3600) {
                $g = (int) date('N', $t);
                $o = (int) date('G', $t);
                $fasce[$g][$o] = ($fasce[$g][$o] ?? 0) + 1;
            }
        }
        foreach ($per as &$p) {
            $p['ore'] = round($p['ore'], 1);
            $p['ore_aperte'] = round($p['ore_aperte'], 1);
            $p['utilizzo'] = $p['ore_aperte'] > 0 ? (int) round($p['ore'] * 100 / $p['ore_aperte']) : null;
        }
        unset($p);
        $tot = ['prenotazioni' => array_sum(array_column($per, 'prenotazioni')), 'ore' => round(array_sum(array_column($per, 'ore')), 1),
            'annullate' => array_sum(array_column($per, 'annullate')), 'rifiutate' => array_sum(array_column($per, 'rifiutate'))];

        return ['risorse' => $per, 'fasce' => $fasce, 'totali' => $tot];
    }
}
