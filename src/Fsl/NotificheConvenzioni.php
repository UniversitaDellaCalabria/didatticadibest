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
     * Avviso a chi gestisce le convenzioni: una scuola ha caricato la convenzione firmata e va presa in carico (firma del direttore e protocollo).
     *
     * @param list<string> $destinatari email (senza doppioni)
     * @return int quante email sono partite
     */
    public function firmataCaricata(string $scuola, string $firmatario, bool $sostituita, array $destinatari): int
    {
        $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $link = $this->sito->urlBase() . '/admin/fsl.php?tab=convenzioni';
        $corpo = '<p>La scuola <strong>' . $h($scuola) . '</strong> ha ' . ($sostituita ? 'caricato una <strong>nuova versione</strong> della' : 'caricato la') . ' convenzione firmata'
               . ($firmatario !== '' ? ' (firma di <strong>' . $h($firmatario) . '</strong>)' : '') . '.</p>'
               . '<p>Va <strong>protocollata in entrata</strong>: scarica il PDF, protocollalo e registra solo il numero nel portale (senza ricaricare il file). Subito dopo la convenzione va in automatico al Direttore per la firma.</p>'
               . "<p style='margin-top:15px;'><a href='" . $h($link) . "' style='background:#B80000; color:#ffffff; padding:10px 18px; text-decoration:none; border-radius:6px; font-weight:bold;'>Apri le convenzioni</a></p>";
        $inviate = 0;
        foreach ($destinatari as $a) {
            if ($this->mailer->invia($a, 'Convenzione FSL da prendere in carico: ' . $scuola, $corpo)) {
                ++$inviate;
            }
        }

        return $inviate;
    }

    /** Ricevuta alla scuola: la convenzione firmata è arrivata e sarà presa in carico dal Dipartimento. */
    public function firmataRicevuta(string $email, string $scuola, string $tokenPagina): bool
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $link = $this->sito->urlBase() . '/convenzione_online.php?t=' . urlencode($tokenPagina);
        $corpo = '<p>Gentile <strong>' . $h($scuola) . '</strong>,</p>'
               . '<p>abbiamo ricevuto la <strong>convenzione firmata</strong> per la Formazione Scuola Lavoro. Il Dipartimento la prende in carico, la fa firmare dal Direttore e la protocolla: '
               . 'appena è pronta ti scriviamo e la trovi nell\'Area personale. Le prenotazioni in attesa si confermano dopo la protocollazione.</p>'
               . '<p>Hai caricato il file sbagliato? Fino alla presa in carico puoi sostituirlo da questa pagina.</p>'
               . "<p style='margin-top:15px;'><a href='" . $h($link) . "' style='background:#B80000; color:#ffffff; padding:10px 18px; text-decoration:none; border-radius:6px; font-weight:bold;'>Apri la pagina della convenzione</a></p>";

        return $this->mailer->invia($email, 'Convenzione ricevuta: ' . $scuola, $corpo);
    }

    /** Alla scuola: l'ufficio ha preso in carico la convenzione firmata. */
    public function presaInCarico(string $email, string $scuola, string $tokenPagina, string $protocolloIngresso = ''): bool
    {
        $corpo = '<p>Gentile <strong>' . $this->h($scuola) . '</strong>,</p>'
               . '<p>il Dipartimento ha <strong>preso in carico</strong> la convenzione firmata' . ($protocolloIngresso !== '' ? ' e l\'ha protocollata in entrata (' . $this->h($protocolloIngresso) . ')' : '') . ': ora la fa firmare dal Direttore e la protocolla in via definitiva. '
               . 'Ti scriviamo appena è pronta; le prenotazioni in attesa si confermano in quel momento.</p>' . $this->pulsante($tokenPagina, 'Apri la pagina della convenzione');

        return $this->scrivi($email, 'Convenzione presa in carico: ' . $scuola, $corpo);
    }

    /** Alla scuola: il file non va bene, con il motivo; la scuola può caricarne un altro dalla stessa pagina. */
    public function rifiutata(string $email, string $scuola, string $motivo, string $tokenPagina): bool
    {
        $corpo = '<p>Gentile <strong>' . $this->h($scuola) . '</strong>,</p>'
               . '<p>la convenzione che hai caricato <strong>non può essere accettata</strong> per questo motivo:</p>'
               . "<blockquote style='margin:12px 0;padding:8px 14px;border-left:4px solid #B80000;background:#fef2f2;'>" . nl2br($this->h($motivo)) . '</blockquote>'
               . '<p>Correggi il documento e <strong>caricalo di nuovo</strong> dalla stessa pagina.</p>' . $this->pulsante($tokenPagina, 'Carica di nuovo la convenzione');

        return $this->scrivi($email, 'Convenzione da correggere: ' . $scuola, $corpo);
    }

    /** Alla scuola: convenzione firmata dal Direttore, protocollata e registrata; le prenotazioni in attesa sono confermate. */
    public function attiva(string $email, string $scuola, string $tokenPagina, string $protocollo, ?string $dal, ?string $al, int $prenotazioni): bool
    {
        $validita = $dal && $al ? 'dal ' . date('d/m/Y', (int) strtotime($dal)) . ' al ' . date('d/m/Y', (int) strtotime($al)) : '';
        $corpo = '<p>Gentile <strong>' . $this->h($scuola) . '</strong>,</p>'
               . '<p>la convenzione è stata <strong>firmata dal Direttore e protocollata</strong>' . ($protocollo !== '' ? ' (' . $this->h($protocollo) . ')' : '') . ($validita !== '' ? ', valida ' . $validita : '') . '.</p>'
               . ($prenotazioni > 0 ? '<p>Le tue <strong>' . $prenotazioni . ($prenotazioni === 1 ? ' prenotazione era' : ' prenotazioni erano') . ' in attesa</strong> della convenzione: ' . ($prenotazioni === 1 ? 'è stata confermata' : 'sono state confermate') . ' e riceverai le email di conferma.</p>' : '')
               . '<p>Trovi la convenzione e l\'Allegato A nell\'Area personale, nella scheda «Programma FSL».</p>' . $this->pulsante($tokenPagina, 'Apri la pagina della convenzione');

        return $this->scrivi($email, 'Convenzione attiva: ' . $scuola, $corpo);
    }

    /** Al Direttore: una convenzione da firmare, con il suo link personale (accesso al portale richiesto). */
    public function firmaRichiesta(string $email, string $nomeDirettore, string $scuola, string $tokenDirettore): bool
    {
        $link = $this->sito->urlBase() . '/firma_convenzione.php?t=' . urlencode($tokenDirettore);
        $corpo = '<p>Gentile ' . $this->h($nomeDirettore !== '' ? $nomeDirettore : 'Direttore') . ',</p>'
               . '<p>la convenzione per la Formazione Scuola Lavoro con <strong>' . $this->h($scuola) . '</strong> è già firmata dal Dirigente Scolastico e attende la <strong>tua firma</strong>.</p>'
               . '<p>Accedi al portale e firmala in PAdES: con la firma remota Aruba direttamente dal portale oppure scaricando il PDF, firmandolo e ricaricandolo.</p>'
               . "<p style='margin-top:15px;'><a href='" . $this->h($link) . "' style='background:#B80000; color:#ffffff; padding:10px 18px; text-decoration:none; border-radius:6px; font-weight:bold;'>Apri e firma la convenzione</a></p>";

        return $this->scrivi($email, 'Convenzione FSL da firmare: ' . $scuola, $corpo);
    }

    /**
     * All'ufficio: il Direttore ha firmato, la convenzione è da protocollare e registrare.
     *
     * @param list<string> $destinatari
     */
    public function firmataDalDirettore(string $scuola, array $destinatari): int
    {
        $link = $this->sito->urlBase() . '/admin/fsl.php?tab=convenzioni';
        $corpo = '<p>Il Direttore ha <strong>firmato</strong> la convenzione con <strong>' . $this->h($scuola) . '</strong>.</p>'
               . '<p>Ora va <strong>protocollata in via definitiva</strong>: scarica il PDF firmato, protocollalo, poi nel portale registra numero e data e carica il PDF protocollato. Le prenotazioni in attesa della scuola si confermano alla registrazione.</p>'
               . "<p style='margin-top:15px;'><a href='" . $this->h($link) . "' style='background:#B80000; color:#ffffff; padding:10px 18px; text-decoration:none; border-radius:6px; font-weight:bold;'>Apri le convenzioni</a></p>";
        $n = 0;
        foreach ($destinatari as $a) {
            if ($this->scrivi($a, 'Convenzione FSL firmata dal Direttore: da protocollare - ' . $scuola, $corpo)) {
                ++$n;
            }
        }

        return $n;
    }

    private function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    private function pulsante(string $token, string $testo): string
    {
        $link = $this->sito->urlBase() . '/convenzione_online.php?t=' . urlencode($token);

        return "<p style='margin-top:15px;'><a href='" . $this->h($link) . "' style='background:#B80000; color:#ffffff; padding:10px 18px; text-decoration:none; border-radius:6px; font-weight:bold;'>" . $this->h($testo) . '</a></p>';
    }

    private function scrivi(string $email, string $oggetto, string $corpo): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $this->mailer->invia($email, $oggetto, $corpo) : false;
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
