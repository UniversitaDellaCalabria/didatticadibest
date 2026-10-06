<?php

declare(strict_types=1);

namespace App\Didattica;

/** Lo storico di una pratica: cambi di stato, messaggi, attività, passaggi dell'iter e autodichiarazioni. */
final class StoricoPratica
{
    public function __construct(private PraticaRepository $pratiche)
    {
    }

    /**
     * Riga dello storico (con eventuale allegato). $interno = nota tra i referenti, non visibile allo studente;
     * $autoreNome = chi scrive (es. «Rossi Mario · Referente del corso»).
     */
    public function evento(int $praticaId, string $tipo, string $autore, int $uid, ?string $stato, string $testo, ?string $allegato = null, ?string $nomeAll = null, bool $interno = false, string $autoreNome = ''): void
    {
        $this->pratiche->aggiungiEvento($praticaId, $tipo, $autore, $uid > 0 ? $uid : null, $stato, $testo, $allegato, $nomeAll, $interno, mb_substr($autoreNome, 0, 200));
    }
}
