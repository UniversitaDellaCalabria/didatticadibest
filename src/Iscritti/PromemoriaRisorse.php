<?php

declare(strict_types=1);

namespace App\Iscritti;

/** Promemoria delle prenotazioni di aule, laboratori e sportelli, che girano insieme a quelli degli iscritti (li fornisce il modulo Risorse). */
interface PromemoriaRisorse
{
    /** Invia il promemoria delle prenotazioni confermate che iniziano tra 1 e 24 ore e non ne hanno ancora avuto uno. Ritorna le email inviate. */
    public function inviaPromemoria(): int;
}
