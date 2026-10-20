<?php

declare(strict_types=1);

namespace App\Iscritti;

use App\Avvisi\ServizioAvvisi;
use App\Eventi\Turni;
use App\Email\InvioEmail;
use App\Portale\ColoriAree;

/**
 * Promemoria dei cron (admin/cron_reminders.php): email di promemoria agli iscritti di eventi nelle prossime 72 ore, poi i promemoria
 * delle prenotazioni di risorse e quelli degli altri moduli (didattica, tutorato, avvisi, sedute).
 */
final class ServizioPromemoria
{
    public function __construct(
        private CronRepository $cron,
        private InvioEmail $invio,
        private ColoriAree $colori,
        private Pianificati $pianificati,
        private PromemoriaRisorse $risorse,
        private ServizioAvvisi $avvisi,
    ) {
    }

    /**
     * Invia tutti i promemoria; ritorna quante email sono partite.
     */
    public function invia(): int
    {
        $inviati = 0;

        // Prenotazioni confermate per eventi che iniziano nelle prossime 72 ore, senza promemoria
        foreach ($this->cron->daPromemoria() as $p) {
            $data = implode(' · ', array_filter([$p['nome_turno'] ?? '', date('d/m/Y', (int) strtotime((string) $p['data_turno']))]));
            $ora = Turni::orario($p) ?: 'da definire';
            $token = array_combine(
                ['{NOME}', '{COGNOME}', '{MATRICOLA}', '{TITOLO_EVENTO}', '{DATA_TURNO}', '{ORARIO_TURNO}', '{LUOGO}', '{CODICE_PRENOTAZIONE}'],
                array_map('strval', [$p['nome'], $p['cognome'], $p['matricola'], $p['evento_titolo'], $data, $ora, $p['luogo'], $p['codice_prenotazione']])
            );
            $ok = $this->invio->inviaHtml((string) $p['email'], 'evento_promemoria', $token, $this->colori->delTurno((int) $p['turno_id']));
            // Se inviata correttamente, si segna per non rimandarla
            if ($ok) {
                $this->cron->segnaPromemoriaInviato((int) $p['id']);
                ++$inviati;
            }
        }

        // Calendari e risorse: promemoria delle prenotazioni di aule, laboratori e sportelli che iniziano entro 24 ore
        $inviati += $this->risorse->inviaPromemoria();

        // Didattica, tutorato, avvisi per email dei nuovi eventi, sedute
        $inviati += $this->pianificati->praticheFerme();
        $inviati += $this->pianificati->tutorato();
        $inviati += $this->avvisi->inviaNovita();
        $inviati += $this->pianificati->sollecitiVerbali();

        return $inviati;
    }
}
