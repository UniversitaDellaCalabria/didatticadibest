<?php

declare(strict_types=1);

namespace App\Firme;

/** Un modulo del portale che chiede firme: dice quali documenti aspettano la firma di una persona, riusando le regole del modulo su chi può firmare. */
interface FonteFirme
{
    /** Chiave breve del tipo di documento (pratica, convenzione_fsl, convenzione_tesi, tutorato, verbale). */
    public function tipo(): string;

    /** Nome al plurale per i filtri della pagina («Pratiche», «Convenzioni FSL»…). */
    public function etichetta(): string;

    /**
     * @param array<string, mixed> $utente riga dell'utente collegato
     * @return list<DocumentoDaFirmare>
     */
    public function documenti(array $utente): array;
}
