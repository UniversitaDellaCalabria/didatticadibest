<?php

declare(strict_types=1);

namespace App\Iscritti;

/** Esito di un'azione di massa sulle prenotazioni selezionate: quante eseguite, quante saltate, quante promozioni oltre la capienza. */
final class RisultatoMassivo
{
    public function __construct(
        public readonly string $azione,
        public readonly int $fatte,
        public readonly int $saltate,
        public readonly int $oltreCapienza,
    ) {
    }

    /** Messaggio flash, con gli stessi testi di sempre. */
    public function messaggio(): string
    {
        $msg = $this->fatte . ' prenotazioni ' . ServizioIscritti::AZIONI_DI_MASSA[$this->azione] . '.';
        if ($this->saltate > 0) {
            $msg .= " {$this->saltate} saltate (stato non compatibile con l'azione o permessi mancanti).";
        }
        if ($this->oltreCapienza > 0) {
            $msg .= " Attenzione: {$this->oltreCapienza} promozioni superano la capienza del turno.";
        }

        return $msg;
    }

    public function tipo(): string
    {
        return $this->saltate > 0 || $this->oltreCapienza > 0 ? 'warning' : 'success';
    }
}
