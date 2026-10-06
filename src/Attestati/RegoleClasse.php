<?php

declare(strict_types=1);

namespace App\Attestati;

/** Regole delle iscrizioni di classe (modulo FSL, App\Fsl\RegoleClasse): quando e per chi si emettono gli attestati degli studenti. */
interface RegoleClasse
{
    /**
     * Attestati per ogni studente dell'elenco inserito da chi ha prenotato, come attestati_di_classe().
     *
     * @param array<string, mixed> $p riga con evento_tipo (o tipo), per_scuole e attestati (es. prenotazione per gli attestati)
     */
    public function attestatiDiClasse(array $p): bool;

    /**
     * Quando si possono emettere gli attestati della classe, come attivita_conclusa_classe(): progetti dopo la data di fine,
     * eventi dopo il giorno del turno.
     *
     * @param array<string, mixed> $p
     */
    public function attivitaConclusaClasse(array $p): bool;
}
