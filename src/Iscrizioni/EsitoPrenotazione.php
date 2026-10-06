<?php

declare(strict_types=1);

namespace App\Iscrizioni;

/** Risultato di una prenotazione dal modulo pubblico: dove rimandare la persona e l'eventuale messaggio da tenere in sessione. */
final readonly class EsitoPrenotazione
{
    /**
     * @param string $destinazione indirizzo del rimando (Location), relativo alla cartella del portale
     * @param string|null $erroreSessione messaggio da mettere in $_SESSION['errore_prenotazione'] prima del rimando
     */
    public function __construct(public string $destinazione, public ?string $erroreSessione = null)
    {
    }
}
