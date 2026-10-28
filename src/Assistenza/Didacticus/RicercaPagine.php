<?php

declare(strict_types=1);

namespace App\Assistenza\Didacticus;

/**
 * La ricerca nel testo delle pagine di Ateneo: divide ogni pagina in brani e dà i brani che contengono le parole della domanda (con il peso delle parole rare, come il
 * Motore di Didacticus). Serve a scegliere le fonti da dare al modello linguistico; non risponde da sola.
 */
final class RicercaPagine
{
    /** Lunghezza orientativa di un brano, in caratteri. */
    public const BRANO = 600;
    /** Quanta parte del peso delle parole della domanda deve comparire in un brano (0-1). */
    public const COPERTURA_MIN = 0.6;

    /**
     * I brani di una pagina, spezzati a fine riga.
     *
     * @return list<string>
     */
    public static function brani(string $testo): array
    {
        $out = [];
        $corrente = '';
        foreach (explode("\n", $testo) as $riga) {
            $riga = trim($riga);
            if ($riga === '') {
                continue;
            }
            if ($corrente !== '' && mb_strlen($corrente) + mb_strlen($riga) > self::BRANO) {
                $out[] = $corrente;
                $corrente = '';
            }
            $corrente .= ($corrente === '' ? '' : ' ') . $riga;
        }
        if ($corrente !== '') {
            $out[] = $corrente;
        }

        return $out;
    }

    /**
     * I brani più vicini alla domanda.
     *
     * @param list<array<string, mixed>> $pagine righe di PagineRepository::conTesto()
     * @return list<array{titolo: string, url: string, testo: string, punteggio: int}> dal migliore, al massimo $quanti
     */
    public static function cerca(string $domanda, array $pagine, int $quanti = 2): array
    {
        $q = Motore::parole($domanda);
        if (count($q) < 2) {
            return [];
        }
        $brani = [];
        $df = [];
        foreach ($pagine as $p) {
            foreach (self::brani((string) $p['testo']) as $b) {
                $parole = array_flip(Motore::parole((string) $p['titolo'] . ' ' . $b));
                $brani[] = ['p' => $p, 'b' => $b, 'parole' => $parole];
                foreach ($q as $w) {
                    if (isset($parole[$w])) {
                        $df[$w] = ($df[$w] ?? 0) + 1;
                    }
                }
            }
        }
        $n = count($brani);
        if ($n === 0) {
            return [];
        }
        $peso = static fn (string $w): float => 1.0 + log(($n + 1) / (($df[$w] ?? 0) + 1));
        $tot = array_sum(array_map($peso, $q));
        $out = [];
        foreach ($brani as $x) {
            $presenti = array_filter($q, static fn (string $w): bool => isset($x['parole'][$w]));
            $copertura = $presenti ? array_sum(array_map($peso, $presenti)) / $tot : 0.0;
            if ($copertura >= self::COPERTURA_MIN) {
                $out[] = ['titolo' => (string) $x['p']['titolo'], 'url' => (string) $x['p']['url'], 'testo' => $x['b'], 'punteggio' => (int) round(100 * $copertura)];
            }
        }
        usort($out, static fn (array $a, array $b): int => $b['punteggio'] <=> $a['punteggio']);

        return array_slice($out, 0, max(1, $quanti));
    }
}
