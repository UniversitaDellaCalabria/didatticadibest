<?php

declare(strict_types=1);

namespace App\Portale;

/**
 * Widget della home: valori predefiniti e normalizzazione della configurazione salvata
 * (spostata da widgets_home_default() e get_widgets_home() di inc/eventi_progetti.php).
 */
final class WidgetHome implements ConfigurazioneHome
{
    public function __construct(private CacheConfigurazione $cache)
    {
    }

    /** @return array<string, mixed> */
    public static function predefiniti(): array
    {
        return [
            'slideshow' => 1, 'mia_prenotazione' => 1, 'annunci' => 0, 'card_aree' => 1,
            'ultimi_posti' => 0, 'prossimi_eventi' => 1, 'statistiche' => 0,
            // Widget dell'organizzazione per pubblico: spenti finché non si accendono o si applica la proposta
            'percorsi' => 0, 'agenda' => 0, 'scadenze' => 0,
            'ordine' => ['slideshow', 'percorsi', 'mia_prenotazione', 'annunci', 'agenda', 'scadenze', 'card_aree', 'ultimi_posti', 'prossimi_eventi', 'statistiche'],
            'aree_colonne' => 2,         // 2 | 3 | 4 card per riga (desktop)
            'aree_max' => 0,             // 0 = tutte; altrimenti le altre si aprono con "Mostra tutte"
            'eventi_num' => 8,           // 4 | 8 | 12
            'eventi_layout' => 'scroll', // scroll | griglia
        ];
    }

    /**
     * Legge la configurazione salvata e la normalizza (valori non validi → predefiniti).
     *
     * @return array<string, mixed>
     */
    public function widgets(?string $json): array
    {
        $w = self::predefiniti();
        $dec = !empty($json) ? json_decode($json, true) : null;
        if (is_array($dec)) {
            $w = array_merge($w, $dec);
        }

        $chiavi = self::predefiniti()['ordine'];
        foreach ($chiavi as $k) {
            $w[$k] = (int) !empty($w[$k]);
        }

        // Ordine: solo chiavi note, senza duplicati. I widget nuovi (assenti in una
        // configurazione salvata prima che esistessero) vanno subito dopo il widget
        // che li precede nell'ordine predefinito, non in fondo alla pagina.
        $ordine = array_values(array_unique(array_intersect((array) $w['ordine'], $chiavi)));
        foreach ($chiavi as $i => $k) {
            if (in_array($k, $ordine, true)) {
                continue;
            }
            $pos = 0;
            for ($j = $i - 1; $j >= 0; $j--) {
                $p = array_search($chiavi[$j], $ordine, true);
                if ($p !== false) {
                    $pos = $p + 1;
                    break;
                }
            }
            array_splice($ordine, $pos, 0, [$k]);
        }
        $w['ordine'] = $ordine;

        $w['aree_colonne'] = in_array((int) $w['aree_colonne'], [2, 3, 4], true) ? (int) $w['aree_colonne'] : 2;
        $w['aree_max'] = max(0, min(48, (int) $w['aree_max']));
        $w['eventi_num'] = in_array((int) $w['eventi_num'], [4, 8, 12], true) ? (int) $w['eventi_num'] : 8;
        $w['eventi_layout'] = $w['eventi_layout'] === 'griglia' ? 'griglia' : 'scroll';

        return $w;
    }

    public function invalidaCache(): void
    {
        $this->cache->invalida();
    }
}
