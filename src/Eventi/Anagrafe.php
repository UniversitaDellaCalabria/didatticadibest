<?php

declare(strict_types=1);

namespace App\Eventi;

/** Anagrafi di Ateneo (modulo Anagrafi) usate dalle schede degli eventi: personale, insegnamenti, corsi di studio. */
interface Anagrafe
{
    /** @return array<string, mixed>|null persona dell'anagrafe del personale (personale_ateneo), come persona_ateneo() */
    public function persona(string $id): ?array;

    /**
     * Prepara foto e scheda della persona per le pagine pubbliche, come dettaglio_persona().
     *
     * @param array<string, mixed> $persona
     */
    public function completaPersona(array $persona): void;

    /** @return array<string, mixed>|null insegnamento dell'anagrafe, come insegnamento() */
    public function insegnamento(int $id): ?array;

    /** @return array<string, mixed>|null corso di studio, come corso_studio() */
    public function corsoStudio(string $codice): ?array;

    /** Campo «numero di partecipanti» del modulo di iscrizione dell'area, creato se manca (assicura_campi_progetto()). */
    public function assicuraCampiProgetto(int $paginaId): void;
}
