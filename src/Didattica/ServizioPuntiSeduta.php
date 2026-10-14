<?php

declare(strict_types=1);

namespace App\Didattica;

/**
 * I punti dell'ordine del giorno di una seduta come blocchi del verbale: si ricavano dall'o.d.g. (una riga = un punto), ognuno ha il suo
 * testo; le pratiche portate in seduta vanno sotto il punto «pratiche» (di default quello il cui titolo contiene «pratich»).
 */
final class ServizioPuntiSeduta
{
    public const TIPI = ['testo' => 'Testo scritto', 'pratiche' => 'Pratiche studenti'];

    public function __construct(private PuntoSedutaRepository $punti, private SedutaRepository $sedute)
    {
    }

    /**
     * I titoli dei punti dall'o.d.g.: una riga ciascuno, senza numerazione iniziale.
     *
     * @return list<string>
     */
    public static function titoli(string $odg): array
    {
        $out = [];
        foreach (preg_split('/\R/', $odg) ?: [] as $riga) {
            $t = trim((string) preg_replace('/^\s*\d+\s*[\.\)]\s*/u', '', $riga));
            if ($t !== '') {
                $out[] = mb_substr($t, 0, 500);
            }
        }

        return $out;
    }

    private static function chiave(string $titolo): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($titolo)));
    }

    public static function tipoPerTitolo(string $titolo): string
    {
        return preg_match('/pratich/i', $titolo) ? 'pratiche' : 'testo';
    }

    /**
     * Allinea i punti all'o.d.g.: i punti con lo stesso titolo restano (col loro testo, anche se cambia l'ordine), quelli con titolo cambiato nella
     * stessa posizione si rinominano, i nuovi si creano, quelli tolti dall'o.d.g. si eliminano.
     *
     * @return list<array<string, mixed>>
     */
    public function sincronizza(int $sedutaId, string $odg): array
    {
        $titoli = self::titoli($odg);
        $esistenti = $this->punti->elenco($sedutaId);
        $usati = [];
        $assegnati = [];
        foreach ($titoli as $i => $t) {
            foreach ($esistenti as $e) {
                if (!isset($usati[(int) $e['id']]) && self::chiave((string) $e['titolo']) === self::chiave($t)) {
                    $usati[(int) $e['id']] = true;
                    $assegnati[$i] = $e;
                    break;
                }
            }
        }
        foreach ($titoli as $i => $t) {
            $e = $esistenti[$i] ?? null;
            if (!isset($assegnati[$i]) && $e && !isset($usati[(int) $e['id']])) {
                $usati[(int) $e['id']] = true;
                $assegnati[$i] = $e;
            }
        }
        foreach ($esistenti as $e) {
            if (!isset($usati[(int) $e['id']])) {
                $this->punti->elimina((int) $e['id']);
            }
        }
        foreach ($titoli as $i => $t) {
            if (isset($assegnati[$i])) {
                $this->punti->aggiornaTitolo((int) $assegnati[$i]['id'], $i + 1, $t);
            } else {
                $this->punti->inserisci($sedutaId, $i + 1, $t, self::tipoPerTitolo($t));
            }
        }

        return $this->punti->elenco($sedutaId);
    }

    /**
     * I punti della seduta; se non ci sono ancora (sedute precedenti) li ricava dall'o.d.g.
     *
     * @param array<string, mixed> $seduta
     * @return list<array<string, mixed>>
     */
    public function punti(array $seduta): array
    {
        $p = $this->punti->elenco((int) $seduta['id']);

        return $p ?: $this->sincronizza((int) $seduta['id'], (string) ($seduta['odg'] ?? ''));
    }

    /**
     * Salva testo e tipo di ogni punto della seduta e il punto sotto cui va ogni pratica. Ritorna quanti punti ha salvato.
     *
     * @param array<string, mixed> $seduta
     * @param array<int|string, string> $testi id punto => HTML dell'editor
     * @param array<int|string, string> $tipi id punto => tipo
     * @param array<int|string, string> $praticaPunto id pratica => id punto
     */
    public function salva(array $seduta, array $testi, array $tipi, array $praticaPunto = []): int
    {
        $punti = [];
        foreach ($this->punti($seduta) as $p) {
            $punti[(int) $p['id']] = $p;
        }
        $tipo_di = [];
        foreach ($punti as $id => $p) {
            $tipo = isset(self::TIPI[$tipi[$id] ?? '']) ? (string) $tipi[$id] : (string) $p['tipo'];
            $tipo_di[$id] = $tipo;
            $this->punti->salvaTestoETipo($id, array_key_exists($id, $testi) ? TestoVerbale::pulisci((string) $testi[$id]) : (string) $p['testo'], $tipo);
        }
        $valide = array_flip(array_map('intval', $this->sedute->idPratiche((int) $seduta['id'])));
        foreach ($praticaPunto as $pid => $punto) {
            if (isset($valide[(int) $pid])) {
                $this->punti->assegnaPratica((int) $pid, ($tipo_di[(int) $punto] ?? '') === 'pratiche' ? (int) $punto : null);
            }
        }

        return count($punti);
    }

    /**
     * Il punto delle pratiche a cui va ognuna: quello scelto, altrimenti il primo punto di tipo «pratiche».
     *
     * @param list<array<string, mixed>> $punti
     * @param list<array<string, mixed>> $pratiche
     * @return array<int, list<array<string, mixed>>> id punto => pratiche (le senza punto, se non c'è nessun punto delle pratiche, sotto 0)
     */
    public static function praticheDeiPunti(array $punti, array $pratiche): array
    {
        $prime = null;
        $validi = [];
        foreach ($punti as $p) {
            if ($p['tipo'] === 'pratiche') {
                $validi[(int) $p['id']] = true;
                $prime ??= (int) $p['id'];
            }
        }
        $out = [];
        foreach ($pratiche as $x) {
            $k = isset($validi[(int) ($x['punto_id'] ?? 0)]) ? (int) $x['punto_id'] : (int) $prime;
            $out[$k][] = $x;
        }

        return $out;
    }
}
