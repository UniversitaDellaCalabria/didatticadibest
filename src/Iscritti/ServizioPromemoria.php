<?php

declare(strict_types=1);

namespace App\Iscritti;

use App\Avvisi\ServizioAvvisi;
use App\Eventi\Turni;
use App\Infrastructure\Mail\Mailer;
use App\Portale\ColoriAree;
use App\Sistema\ImpostazioniSistemaRepository;

/**
 * Promemoria dei cron (admin/cron_reminders.php): email di promemoria agli iscritti di eventi nelle prossime 72 ore, poi i promemoria
 * delle prenotazioni di risorse e quelli degli altri moduli (didattica, tutorato, avvisi, sedute).
 */
final class ServizioPromemoria
{
    private const OGGETTO_PREDEFINITO = 'Promemoria Evento Imminente - DiBEST';
    private const CORPO_PREDEFINITO = "<p>Gentile <strong>{NOME} {COGNOME}</strong>,</p><p>Ti ricordiamo che l'evento <strong>{TITOLO_EVENTO}</strong> si terrà a breve.</p><p><strong>Dettagli:</strong><br>📅 Data: {DATA_TURNO}<br>🕒 Orario: {ORARIO_TURNO}<br>📍 Luogo: {LUOGO}<br>🎟️ Codice: <strong>{CODICE_PRENOTAZIONE}</strong></p><p style='color:red;'>Se non potrai più partecipare, ti preghiamo di accedere alla tua Area Personale e <strong>annullare la prenotazione</strong>, così da cedere il posto a chi è in lista d'attesa.</p>";

    public function __construct(
        private CronRepository $cron,
        private ImpostazioniSistemaRepository $impostazioni,
        private Mailer $mailer,
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
        $sys = $this->impostazioni->riga() ?? [];
        $oggettoModello = (string) ($sys['email_reminder_oggetto'] ?? '') ?: self::OGGETTO_PREDEFINITO;
        $corpoModello = (string) ($sys['email_reminder_corpo'] ?? '') ?: self::CORPO_PREDEFINITO;
        $inviati = 0;

        // Prenotazioni confermate per eventi che iniziano nelle prossime 72 ore, senza promemoria
        foreach ($this->cron->daPromemoria() as $p) {
            $data = implode(' · ', array_filter([$p['nome_turno'] ?? '', date('d/m/Y', (int) strtotime((string) $p['data_turno']))]));
            $ora = Turni::orario($p) ?: 'da definire';
            $trova = ['{NOME}', '{COGNOME}', '{MATRICOLA}', '{TITOLO_EVENTO}', '{DATA_TURNO}', '{ORARIO_TURNO}', '{LUOGO}', '{CODICE_PRENOTAZIONE}'];
            $sostituisci = array_map('strval', [$p['nome'], $p['cognome'], $p['matricola'], $p['evento_titolo'], $data, $ora, $p['luogo'], $p['codice_prenotazione']]);
            $ok = $this->mailer->invia(
                (string) $p['email'],
                str_replace($trova, $sostituisci, $oggettoModello),
                str_replace($trova, $sostituisci, $corpoModello),
                $this->colori->delTurno((int) $p['turno_id'])
            );
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
