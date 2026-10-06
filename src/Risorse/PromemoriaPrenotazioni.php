<?php

declare(strict_types=1);

namespace App\Risorse;

use App\Iscritti\PromemoriaRisorse;

/** Promemoria del giorno prima delle prenotazioni di aule, laboratori e sportelli (lo chiama il cron dei promemoria). */
final class PromemoriaPrenotazioni implements PromemoriaRisorse
{
    public function __construct(private RisorsaRepository $risorse, private NotificheRisorse $notifiche)
    {
    }

    public function inviaPromemoria(): int
    {
        $inviati = 0;
        foreach ($this->risorse->idDaPromemoria() as $id) {
            $pr = $this->risorse->prenotazione($id);
            if ($pr && $this->notifiche->emailPrenotazione($pr, 'promemoria')) {
                $this->risorse->segnaPromemoria($id);
                ++$inviati;
            }
        }

        return $inviati;
    }
}
