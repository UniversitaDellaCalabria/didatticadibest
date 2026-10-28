<?php

declare(strict_types=1);

namespace App\Assistenza\Didacticus;

/**
 * La ricerca nell'indice delle pagine lette dal crawler: l'indice FULLTEXT del database sceglie i brani che contengono le parole della domanda (per prefisso, così
 * «scadono» trova «scadenza»), poi si applicano gli stessi pesi e la stessa copertura minima di RicercaPagine. Non carica in memoria le pagine: ogni domanda è una query.
 */
final class RicercaSito
{
    public function __construct(private SitoRepository $sito)
    {
    }

    /**
     * @return list<array{titolo: string, url: string, testo: string, punteggio: int}> dal migliore, al massimo $quanti
     */
    public function cerca(string $domanda, int $quanti = 2): array
    {
        $radici = Motore::parole($domanda);
        if (count($radici) < 2) {
            return [];
        }
        $candidati = $this->sito->braniCandidati($radici);
        if (!$candidati) {
            return [];
        }
        // ogni brano è già una «pagina» a sé: RicercaPagine ne calcola i pesi e la copertura
        $pagine = array_map(static fn (array $c): array => ['titolo' => $c['titolo'], 'url' => $c['url'], 'testo' => $c['testo']], $candidati);

        return RicercaPagine::cerca($domanda, $pagine, $quanti);
    }
}
