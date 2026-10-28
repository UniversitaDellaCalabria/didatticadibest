<?php

declare(strict_types=1);

namespace App\Assistenza\Didacticus;

/** Aggiorna il testo delle pagine di Ateneo che Didacticus legge come fonti: una pagina sola (dal pannello) o tutte quelle da rileggere (ogni settimana, dal cron). */
final class ServizioPagine
{
    /** Ogni quanti giorni si rilegge una pagina. */
    public const GIORNI = 7;

    public function __construct(private PagineRepository $pagine, private LettorePagine $lettore)
    {
    }

    /** Legge la pagina e salva il testo. Ritorna null se è andata bene, altrimenti il motivo (che resta annotato sulla pagina). */
    public function aggiorna(int $id): ?string
    {
        $p = $this->pagine->perId($id);
        if ($p === null) {
            return 'Pagina non trovata';
        }
        $quando = date('Y-m-d H:i:s');
        try {
            $r = $this->lettore->leggi((string) $p['url']);
            if (mb_strlen($r['testo']) < 80) {
                throw new \RuntimeException('La pagina non ha testo utile (forse si compone con JavaScript)');
            }
            $this->pagine->salvaLettura($id, $r['titolo'], $r['testo'], 'Letta: ' . mb_strlen($r['testo']) . ' caratteri', $quando);

            return null;
        } catch (\Throwable $e) {
            $this->pagine->salvaLettura($id, null, null, $e->getMessage(), $quando);

            return $e->getMessage();
        }
    }

    /** Rilegge le pagine mai lette o ferme da più di GIORNI giorni. Ritorna [lette bene, con errore]. @return array{0: int, 1: int} */
    public function aggiornaScadute(): array
    {
        $ok = $ko = 0;
        foreach ($this->pagine->daAggiornare(self::GIORNI) as $id) {
            $this->aggiorna($id) === null ? ++$ok : ++$ko;
        }

        return [$ok, $ko];
    }
}
