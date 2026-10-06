<?php

declare(strict_types=1);

namespace Tests\Doppi;

use App\Iscrizioni\CampiAnagrafe;

/** Campi «Scuola» e «Corso di studio» dei test: un segnaposto al posto dell'HTML del modulo Anagrafi. */
final class IscrizioniCampiAnagrafeFinti implements CampiAnagrafe
{
    public function campoScuola(string $campo, string $valore, string $codice): string
    {
        return "[scuola $campo=$valore codice=$codice]";
    }

    public function campoCorso(string $campo, string $valore, string $attr, string $classi, string $id): string
    {
        return "[corso $campo=$valore id=$id]";
    }
}
