<?php

declare(strict_types=1);

namespace App\Iscrizioni;

/** Campi del modulo di iscrizione che si appoggiano alle anagrafi di Ateneo (scuole e corsi di studio): HTML del modulo Anagrafi. */
interface CampiAnagrafe
{
    /** Campo «Scuola» con ricerca nell'anagrafe, come html_campo_scuola(). */
    public function campoScuola(string $campo, string $valore, string $codice): string;

    /** Campo «Corso di studio»: tendina con i corsi dei dipartimenti, come html_campo_corso(). */
    public function campoCorso(string $campo, string $valore, string $attr, string $classi, string $id): string;
}
