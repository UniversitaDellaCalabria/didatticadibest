<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Core\Sito;
use App\Eventi\Turni;
use App\Fsl\Vista\Istruzioni;
use App\Infrastructure\Mail\Mailer;
use App\Portale\ColoriAree;

/** Email alla scuola sulla convenzione: richiesta (con i modelli e la PEC), promemoria e conferma di ricezione. */
final class NotificheConvenzioni
{
    public function __construct(
        private ConvenzioneRepository $convenzioni,
        private PeriodiConvenzione $periodi,
        private Istruzioni $istruzioni,
        private ColoriAree $colori,
        private Mailer $mailer,
        private Sito $sito
    ) {
    }

    /**
     * Email alla scuola con modelli e PEC. $tipo: «richiesta» (pulsante in Iscrizioni) | «promemoria» (cron).
     *
     * @param array<string, mixed> $p prenotazione con i dati dell'attività (PrenotazioneFslRepository::dati)
     */
    public function richiesta(array $p, string $tipo = 'richiesta'): bool
    {
        if (empty($p['email']) || !filter_var($p['email'], FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $inAttesa = in_array($p['stato'], ['da_approvare', 'in_attesa', 'richiesta_conferma'], true);
        $att = $h($p['evento_titolo']) . (Turni::etichetta($p) !== '' ? ' (' . $h(Turni::etichetta($p)) . ')' : '');
        [$dal, $al] = $this->periodi->prenotazione($p);
        $periodo = $dal === $al ? 'il ' . date('d/m/Y', (int) strtotime($dal)) : 'dal ' . date('d/m/Y', (int) strtotime($dal)) . ' al ' . date('d/m/Y', (int) strtotime($al));
        // Convenzione già registrata ma che non copre il periodo: va stipulata una nuova
        $ultima = !empty($p['scuola_codice']) ? ($this->convenzioni->dellaScuola($p['scuola_codice'])[0] ?? null) : null;
        $nota = $ultima ? '<p>La convenzione della scuola che risulta al Dipartimento (valida ' . $h(PeriodiConvenzione::testoValidita($ultima)) . ") <strong>non copre il periodo dell'attività</strong> ($periodo): va stipulata una <strong>nuova convenzione</strong>.</p>" : '';
        $intro = $tipo === 'promemoria'
            ? "<p>ti ricordiamo che per la prenotazione di <strong>$att</strong> non abbiamo ancora ricevuto la convenzione della scuola con il Dipartimento.</p>"
            : "<p>per la prenotazione di <strong>$att</strong> ($periodo) la scuola deve avere una convenzione con il Dipartimento per la Formazione Scuola Lavoro valida per tutto il periodo dell'attività.</p>";
        $corpo = '<p>Gentile <strong>' . $h(trim($p['nome'] . ' ' . $p['cognome'])) . '</strong>,</p>' . $intro . $nota
               . '<p>🎟️ Codice della prenotazione: <strong>' . $h($p['codice_prenotazione']) . '</strong></p>'
               . $this->istruzioni->html($p, true, (string) $p['codice_prenotazione'], $inAttesa);
        $oggetto = ($tipo === 'promemoria' ? 'Promemoria: convenzione da inviare - ' : 'Convenzione con il Dipartimento - ') . $p['evento_titolo'];

        return $this->mailer->invia($p['email'], $oggetto, $corpo, $this->colori->delTurno((int) $p['turno_id']));
    }

    /**
     * Conferma di ricezione della convenzione, con il link alla ricevuta. $confermata: la prenotazione è appena stata confermata.
     *
     * @param array<string, mixed> $p
     */
    public function ricevuta(array $p, bool $confermata): void
    {
        $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $att = $h($p['evento_titolo']) . (Turni::etichetta($p) !== '' ? ' (' . $h(Turni::etichetta($p)) . ')' : '');
        $link = $this->sito->urlBase() . '/stampa_ricevuta.php?code=' . urlencode((string) $p['codice_prenotazione']);
        $statoTxt = $confermata || $p['stato'] === 'confermata' ? "La prenotazione per <strong>$att</strong> è <strong>CONFERMATA</strong>."
                  : ($p['stato'] === 'da_approvare' ? "La richiesta per <strong>$att</strong> resta in valutazione degli organizzatori: riceverai l'esito per email."
                  : "La prenotazione per <strong>$att</strong> resta in lista d'attesa: se si libera un posto ti avvisiamo per email.");
        $corpo = '<p>Gentile <strong>' . $h(trim($p['nome'] . ' ' . $p['cognome'])) . '</strong>,</p>'
               . "<p>abbiamo ricevuto la <strong>convenzione</strong> della scuola con il Dipartimento. $statoTxt</p>"
               . "<p style='margin-top:15px;'><a href='" . $h($link) . "' target='_blank' style='background:#B80000; color:#ffffff; padding:10px 18px; text-decoration:none; border-radius:6px; font-weight:bold;'>📄 Scarica Ricevuta PDF</a></p>";
        $this->mailer->invia($p['email'], 'Convenzione ricevuta: ' . $p['evento_titolo'], $corpo, $this->colori->delTurno((int) $p['turno_id']));
    }
}
