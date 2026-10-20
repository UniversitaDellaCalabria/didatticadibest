<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Core\Sito;
use App\Email\InvioEmail;
use App\Eventi\Turni;
use App\Fsl\Vista\Istruzioni;
use App\Portale\ColoriAree;

/**
 * Email alla scuola sulla convenzione: richiesta (con i modelli e la PEC), promemoria, conferma di ricezione, firme e messaggi.
 * I testi stanno nel catalogo (CatalogoConvenzioni) e si modificano dalla pagina «Testi delle email» del pannello.
 */
final class NotificheConvenzioni
{
    public function __construct(
        private ConvenzioneRepository $convenzioni,
        private PeriodiConvenzione $periodi,
        private Istruzioni $istruzioni,
        private ColoriAree $colori,
        private InvioEmail $invio,
        private Sito $sito
    ) {
    }

    private function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    private function linkScuola(string $token): string
    {
        return $this->sito->urlBase() . '/convenzione_online.php?t=' . urlencode($token);
    }

    private function linkPannello(): string
    {
        return $this->sito->urlBase() . '/admin/fsl.php?tab=iter';
    }

    /** Email alla scuola da un indirizzo (non valido = non parte). @param array<string, scalar|null> $dati */
    private function alla(string $email, string $chiave, array $dati, string $link = ''): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $this->invio->invia($email, $chiave, $dati, $link) : false;
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
        $inAttesa = in_array($p['stato'], ['da_approvare', 'in_attesa', 'richiesta_conferma'], true);
        $att = $p['evento_titolo'] . (Turni::etichetta($p) !== '' ? ' (' . Turni::etichetta($p) . ')' : '');
        [$dal, $al] = $this->periodi->prenotazione($p);
        $periodo = $dal === $al ? 'il ' . date('d/m/Y', (int) strtotime($dal)) : 'dal ' . date('d/m/Y', (int) strtotime($dal)) . ' al ' . date('d/m/Y', (int) strtotime($al));
        // Convenzione già registrata ma che non copre il periodo: va stipulata una nuova
        $ultima = !empty($p['scuola_codice']) ? ($this->convenzioni->dellaScuola($p['scuola_codice'])[0] ?? null) : null;
        $nota = $ultima ? '<p>La convenzione della scuola che risulta al Dipartimento (valida ' . $this->h(PeriodiConvenzione::testoValidita($ultima)) . ") <strong>non copre il periodo dell'attività</strong> ($periodo): va stipulata una <strong>nuova convenzione</strong>.</p>" : '';

        return $this->invio->invia(
            $p['email'],
            $tipo === 'promemoria' ? 'fsl_convenzione_promemoria' : 'fsl_convenzione_richiesta',
            [
                'nome' => trim($p['nome'] . ' ' . $p['cognome']),
                'attivita' => $att,
                'attivita_titolo' => (string) $p['evento_titolo'],
                'periodo' => $periodo,
                'codice' => (string) $p['codice_prenotazione'],
                'nota' => $nota,
                'istruzioni' => $this->istruzioni->html($p, true, (string) $p['codice_prenotazione'], $inAttesa),
            ],
            '',
            [],
            '',
            $this->colori->delTurno((int) $p['turno_id'])
        );
    }

    /**
     * Avviso a chi gestisce le convenzioni: una scuola ha caricato la convenzione firmata e va presa in carico (firma del direttore e protocollo).
     *
     * @param list<string> $destinatari email (senza doppioni)
     * @return int quante email sono partite
     */
    public function firmataCaricata(string $scuola, string $firmatario, bool $sostituita, array $destinatari): int
    {
        $dati = ['scuola' => $scuola, 'azione' => $sostituita ? 'caricato una nuova versione della' : 'caricato la', 'firmatario_testo' => $firmatario !== '' ? ' (firma di ' . $firmatario . ')' : ''];
        $inviate = 0;
        foreach ($destinatari as $a) {
            if ($this->invio->invia($a, 'fsl_firmata_caricata', $dati, $this->linkPannello())) {
                ++$inviate;
            }
        }

        return $inviate;
    }

    /** Ricevuta alla scuola: la convenzione firmata è arrivata e sarà presa in carico dal Dipartimento. */
    public function firmataRicevuta(string $email, string $scuola, string $tokenPagina): bool
    {
        return $this->alla($email, 'fsl_firmata_ricevuta', ['scuola' => $scuola], $this->linkScuola($tokenPagina));
    }

    /** Alla scuola: promemoria di caricare la convenzione firmata in PDF (o di caricarla di nuovo dopo il rifiuto). */
    public function promemoriaPdf(string $email, string $scuola, string $tokenPagina, string $motivoRifiuto, bool $rifiutata): bool
    {
        if (!$rifiutata) {
            return $this->alla($email, 'fsl_promemoria_pdf', ['scuola' => $scuola], $this->linkScuola($tokenPagina));
        }

        return $this->alla($email, 'fsl_promemoria_pdf_rifiutata', [
            'scuola' => $scuola,
            'motivo_intro' => $motivoRifiuto !== '' ? ' per questo motivo:' : '.',
            'motivo' => InvioEmail::citazione($motivoRifiuto),
        ], $this->linkScuola($tokenPagina));
    }

    /** Alla scuola: un messaggio dell'ufficio, con il link alla pagina dove si può rispondere. */
    public function messaggioDallUfficio(string $email, string $scuola, string $tokenPagina, string $testo): bool
    {
        return $this->alla($email, 'fsl_messaggio_ufficio', ['scuola' => $scuola, 'testo' => $testo], $this->linkScuola($tokenPagina));
    }

    /**
     * A chi gestisce le convenzioni: la scuola ha scritto dalla sua pagina.
     *
     * @param list<string> $destinatari
     */
    public function messaggioDallaScuola(array $destinatari, string $scuola, string $testo): int
    {
        $inviate = 0;
        foreach ($destinatari as $a) {
            if ($this->invio->invia($a, 'fsl_messaggio_scuola', ['scuola' => $scuola, 'testo' => $testo], $this->linkPannello())) {
                ++$inviate;
            }
        }

        return $inviate;
    }

    /** Alla scuola: l'ufficio ha preso in carico la convenzione firmata. */
    public function presaInCarico(string $email, string $scuola, string $tokenPagina, string $protocolloIngresso = ''): bool
    {
        return $this->alla($email, 'fsl_presa_in_carico', ['scuola' => $scuola, 'protocollo_testo' => $protocolloIngresso !== '' ? " e l'ha protocollata in entrata (" . $protocolloIngresso . ')' : ''], $this->linkScuola($tokenPagina));
    }

    /** Alla scuola: il file non va bene, con il motivo; la scuola può caricarne un altro dalla stessa pagina. */
    public function rifiutata(string $email, string $scuola, string $motivo, string $tokenPagina): bool
    {
        return $this->alla($email, 'fsl_rifiutata', ['scuola' => $scuola, 'motivo' => $motivo], $this->linkScuola($tokenPagina));
    }

    /** Alla scuola: convenzione firmata dal Direttore, protocollata e registrata; le prenotazioni in attesa sono confermate. */
    public function attiva(string $email, string $scuola, string $tokenPagina, string $protocollo, ?string $dal, ?string $al, int $prenotazioni): bool
    {
        $validita = $dal && $al ? 'dal ' . date('d/m/Y', (int) strtotime($dal)) . ' al ' . date('d/m/Y', (int) strtotime($al)) : '';
        $pren = $prenotazioni > 0 ? '<p>Le tue <strong>' . $prenotazioni . ($prenotazioni === 1 ? ' prenotazione era' : ' prenotazioni erano') . ' in attesa</strong> della convenzione: ' . ($prenotazioni === 1 ? 'è stata confermata' : 'sono state confermate') . ' e riceverai le email di conferma.</p>' : '';

        return $this->alla($email, 'fsl_attiva', [
            'scuola' => $scuola,
            'protocollo_testo' => $protocollo !== '' ? ' (' . $protocollo . ')' : '',
            'validita_testo' => $validita !== '' ? ', valida ' . $validita : '',
            'prenotazioni' => $pren,
        ], $this->linkScuola($tokenPagina));
    }

    /** Al Direttore: una convenzione da firmare, con il suo link personale (accesso al portale richiesto). */
    public function firmaRichiesta(string $email, string $nomeDirettore, string $scuola, string $tokenDirettore): bool
    {
        return $this->alla($email, 'fsl_firma_richiesta', ['direttore' => $nomeDirettore !== '' ? $nomeDirettore : 'Direttore', 'scuola' => $scuola], $this->sito->urlBase() . '/firma_convenzione.php?t=' . urlencode($tokenDirettore));
    }

    /**
     * All'ufficio: il Direttore ha firmato, la convenzione è da protocollare e registrare.
     *
     * @param list<string> $destinatari
     */
    public function firmataDalDirettore(string $scuola, array $destinatari): int
    {
        $n = 0;
        foreach ($destinatari as $a) {
            if ($this->alla($a, 'fsl_firmata_direttore', ['scuola' => $scuola], $this->linkPannello())) {
                ++$n;
            }
        }

        return $n;
    }

    /**
     * Conferma di ricezione della convenzione, con il link alla ricevuta. $confermata: la prenotazione è appena stata confermata.
     *
     * @param array<string, mixed> $p
     */
    public function ricevuta(array $p, bool $confermata): void
    {
        $h = fn (string $s): string => $this->h($s);
        $att = $h($p['evento_titolo']) . (Turni::etichetta($p) !== '' ? ' (' . $h(Turni::etichetta($p)) . ')' : '');
        $statoTxt = $confermata || $p['stato'] === 'confermata' ? "La prenotazione per <strong>$att</strong> è <strong>CONFERMATA</strong>."
                  : ($p['stato'] === 'da_approvare' ? "La richiesta per <strong>$att</strong> resta in valutazione degli organizzatori: riceverai l'esito per email."
                  : "La prenotazione per <strong>$att</strong> resta in lista d'attesa: se si libera un posto ti avvisiamo per email.");
        $this->invio->invia(
            $p['email'],
            'fsl_ricevuta_convenzione',
            ['nome' => trim($p['nome'] . ' ' . $p['cognome']), 'attivita' => (string) $p['evento_titolo'], 'attivita_titolo' => (string) $p['evento_titolo'], 'stato' => '<p>' . $statoTxt . '</p>'],
            $this->sito->urlBase() . '/stampa_ricevuta.php?code=' . urlencode((string) $p['codice_prenotazione']),
            [],
            '',
            $this->colori->delTurno((int) $p['turno_id'])
        );
    }
}
