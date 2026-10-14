<?php

declare(strict_types=1);

namespace App\Risorse;

use App\Iscritti\PromemoriaRisorse;

/** Promemoria del giorno prima delle prenotazioni di aule, laboratori e sportelli (lo chiama il cron dei promemoria). */
final class PromemoriaPrenotazioni implements PromemoriaRisorse
{
    public function __construct(private RisorsaRepository $risorse, private NotificheRisorse $notifiche, private DiarioBordoRepository $diario, private ServizioScadenzeRisorsa $scadenze)
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

        // Diario di bordo da compilare: un promemoria a chi ha prenotato e agli altri conducenti, una sola volta per prenotazione
        foreach ($this->diario->daRicordare() as $pr) {
            if ($this->notifiche->emailDiario($pr)) {
                ++$inviati;
            }
            $this->diario->segnaPromemoria((int) $pr['id']);
        }

        // Scadenze delle risorse (bollo, assicurazione, tagliando…): un avviso prima della data e uno se è scaduta
        foreach ($this->scadenze->daAvvisare() as $s) {
            $inviati += $this->notifiche->emailScadenza($s);
            $this->scadenze->segnaAvviso((int) $s['id'], $s['scaduta'] === '1');
        }

        return $inviati;
    }
}
