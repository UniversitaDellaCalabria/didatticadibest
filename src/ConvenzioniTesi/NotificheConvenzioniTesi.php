<?php

declare(strict_types=1);

namespace App\ConvenzioniTesi;

use App\Core\Sito;
use App\Infrastructure\Mail\Mailer;

/** Le email dell'iter delle convenzioni di tesi: all'azienda, all'ufficio e al Direttore. */
final class NotificheConvenzioniTesi
{
    public function __construct(private Mailer $mailer, private Sito $sito)
    {
    }

    private function h(mixed $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    private function pulsante(string $url, string $testo): string
    {
        return "<p style='margin-top:15px;'><a href='" . $this->h($url) . "' style='background:#B80000; color:#ffffff; padding:10px 18px; text-decoration:none; border-radius:6px; font-weight:bold;'>" . $this->h($testo) . '</a></p>';
    }

    public function linkAzienda(string $token): string
    {
        return $this->sito->urlBase() . '/convenzione_tesi.php?t=' . urlencode($token);
    }

    public function linkPannello(): string
    {
        return $this->sito->urlBase() . '/admin/convenzioni_tesi.php';
    }

    private function scrivi(string $a, string $oggetto, string $corpo): bool
    {
        return filter_var($a, FILTER_VALIDATE_EMAIL) !== false && $this->mailer->invia($a, $oggetto, $corpo);
    }

    /** @param list<string> $destinatari @return int quante email sono partite */
    private function alUfficio(array $destinatari, string $oggetto, string $corpo): int
    {
        $n = 0;
        foreach (array_unique(array_map('strtolower', $destinatari)) as $a) {
            if ($this->scrivi($a, $oggetto, $corpo)) {
                ++$n;
            }
        }

        return $n;
    }

    // ───────────── All'azienda ─────────────

    /** Il link per compilare la convenzione (invito dell'ufficio o richiesta dalla pagina pubblica). */
    public function invito(string $email, string $azienda, string $token, string $da = ''): bool
    {
        $corpo = '<p>Gentile <strong>' . $this->h($azienda !== '' ? $azienda : 'azienda') . '</strong>,</p>'
            . '<p>il Dipartimento di Biologia, Ecologia e Scienze della Terra (DiBEST) dell\'Università della Calabria stipula con gli enti e le aziende che accolgono i propri studenti una <strong>convenzione per lo svolgimento della tesi</strong>.</p>'
            . '<p>Si fa online, in pochi minuti e <strong>senza registrarsi</strong>: dal link qui sotto si inseriscono i dati dell\'azienda e del rappresentante legale, si scarica la convenzione già compilata (Word e PDF), la si firma digitalmente (PAdES) e la si ricarica. Al resto pensiamo noi.</p>'
            . '<p>Se vuoi cambiare qualche articolo puoi proporlo nella stessa pagina: lo valutiamo prima della firma.</p>'
            . $this->pulsante($this->linkAzienda($token), 'Compila la convenzione')
            . '<p style="font-size:12px;color:#64748b;">Il link è personale: puoi chiuderlo e riprendere più tardi.</p>';

        return $this->scrivi($email, 'Convenzione per la tesi con il Dipartimento DiBEST', $corpo);
    }

    /** Alla richiesta ripetuta della stessa azienda si rimanda il link della pratica già aperta. */
    public function invitoRipreso(string $email, string $azienda, string $token): bool
    {
        $corpo = '<p>Gentile <strong>' . $this->h($azienda !== '' ? $azienda : 'azienda') . '</strong>,</p><p>hai già una convenzione per la tesi in corso con il Dipartimento DiBEST: la riprendi da questo link.</p>'
            . $this->pulsante($this->linkAzienda($token), 'Riprendi la convenzione');

        return $this->scrivi($email, 'Convenzione per la tesi: riprendi dove eri rimasto', $corpo);
    }

    public function rispostaModifiche(string $email, string $azienda, string $token, bool $tutteAccettate): bool
    {
        $corpo = '<p>Gentile <strong>' . $this->h($azienda) . '</strong>,</p>'
            . ($tutteAccettate
                ? '<p>il Dipartimento ha <strong>accettato le modifiche</strong> che hai proposto alla convenzione. Ora puoi scaricarla, firmarla in PAdES e ricaricarla.</p>'
                : '<p>il Dipartimento ha risposto alle modifiche che hai proposto alla convenzione. Apri la pagina per vedere le risposte: puoi confermare il testo proposto dal Dipartimento oppure scrivere una nuova proposta.</p>')
            . $this->pulsante($this->linkAzienda($token), 'Apri la convenzione');

        return $this->scrivi($email, 'Convenzione per la tesi: risposta alle modifiche', $corpo);
    }

    public function firmataRicevuta(string $email, string $azienda, string $token): bool
    {
        $corpo = '<p>Gentile <strong>' . $this->h($azienda) . '</strong>,</p>'
            . '<p>abbiamo ricevuto la <strong>convenzione firmata</strong>. Il Dipartimento la protocolla e la fa firmare dal Direttore: appena è pronta ti scriviamo e potrai scaricarla dalla stessa pagina.</p>'
            . '<p>Hai caricato il file sbagliato? Fino alla presa in carico puoi sostituirlo da questa pagina.</p>' . $this->pulsante($this->linkAzienda($token), 'Apri la pagina della convenzione');

        return $this->scrivi($email, 'Convenzione ricevuta: ' . $azienda, $corpo);
    }

    public function presaInCarico(string $email, string $azienda, string $token, string $protocollo): bool
    {
        $corpo = '<p>Gentile <strong>' . $this->h($azienda) . '</strong>,</p><p>la convenzione è stata presa in carico dal Dipartimento (protocollo in entrata <strong>' . $this->h($protocollo) . '</strong>) e passa alla firma del Direttore. Ti scriviamo appena è stipulata.</p>'
            . $this->pulsante($this->linkAzienda($token), 'Segui la convenzione');

        return $this->scrivi($email, 'Convenzione presa in carico: ' . $azienda, $corpo);
    }

    public function rifiutata(string $email, string $azienda, string $token, string $motivo): bool
    {
        $corpo = '<p>Gentile <strong>' . $this->h($azienda) . '</strong>,</p><p>il file della convenzione che hai caricato <strong>non è stato accettato</strong> per questo motivo:</p>'
            . "<blockquote style='margin:12px 0;padding:8px 14px;border-left:4px solid #B80000;background:#fef2f2;'>" . nl2br($this->h($motivo)) . '</blockquote>'
            . '<p>Correggi il documento e caricalo di nuovo dalla pagina della convenzione.</p>' . $this->pulsante($this->linkAzienda($token), 'Apri la convenzione');

        return $this->scrivi($email, 'Convenzione da ricaricare: ' . $azienda, $corpo);
    }

    public function attiva(string $email, string $azienda, string $token, string $protocollo, string $dal, string $al): bool
    {
        $corpo = '<p>Gentile <strong>' . $this->h($azienda) . '</strong>,</p>'
            . '<p>la convenzione per la tesi è stata <strong>stipulata</strong> (protocollo ' . $this->h($protocollo) . ') ed è valida dal ' . $this->h(date('d/m/Y', (int) strtotime($dal))) . ' al ' . $this->h(date('d/m/Y', (int) strtotime($al))) . '.</p>'
            . '<p>Da questa pagina puoi scaricare la convenzione firmata. Grazie per la collaborazione!</p>' . $this->pulsante($this->linkAzienda($token), 'Scarica la convenzione firmata');

        return $this->scrivi($email, 'Convenzione stipulata: ' . $azienda, $corpo);
    }

    public function promemoria(string $email, string $azienda, string $token, string $stato): bool
    {
        $cosa = match ($stato) {
            Costanti::INVITATA => 'inserire i dati dell\'azienda',
            Costanti::RISPOSTA => 'leggere la risposta del Dipartimento alle modifiche proposte',
            Costanti::RIFIUTATA => 'ricaricare la convenzione corretta',
            default => 'firmare digitalmente la convenzione (PAdES) e ricaricarla',
        };
        $corpo = '<p>Gentile <strong>' . $this->h($azienda !== '' ? $azienda : 'azienda') . '</strong>,</p><p>ti ricordiamo che per completare la convenzione per la tesi con il Dipartimento DiBEST resta da <strong>' . $cosa . '</strong>.</p>'
            . $this->pulsante($this->linkAzienda($token), 'Apri la convenzione');

        return $this->scrivi($email, 'Promemoria: convenzione per la tesi con il DiBEST', $corpo);
    }

    public function inScadenza(string $email, string $azienda, string $al, string $linkRichiesta): bool
    {
        $corpo = '<p>Gentile <strong>' . $this->h($azienda) . '</strong>,</p><p>la convenzione per la tesi con il Dipartimento DiBEST scade il <strong>' . $this->h(date('d/m/Y', (int) strtotime($al))) . '</strong>. '
            . 'Se vuoi continuare ad accogliere i nostri studenti puoi rinnovarla dal link qui sotto: con i dati già inseriti ci vogliono pochi minuti.</p>' . $this->pulsante($linkRichiesta, 'Rinnova la convenzione');

        return $this->scrivi($email, 'Convenzione per la tesi in scadenza: ' . $azienda, $corpo);
    }

    // ───────────── All'ufficio e al Direttore ─────────────

    /** @param list<string> $destinatari */
    public function modificheProposte(array $destinatari, string $azienda, int $quanti): int
    {
        $corpo = '<p>L\'azienda <strong>' . $this->h($azienda) . '</strong> propone <strong>' . $quanti . ' modific' . ($quanti === 1 ? 'a' : 'he') . '</strong> agli articoli della convenzione per la tesi.</p>'
            . '<p>Vanno valutate prima che l\'azienda possa firmare: puoi accettarle, rifiutarle o rispondere con un testo diverso.</p>' . $this->pulsante($this->linkPannello(), 'Apri le convenzioni di tesi');

        return $this->alUfficio($destinatari, 'Convenzione di tesi: modifiche da valutare - ' . $azienda, $corpo);
    }

    /** @param list<string> $destinatari */
    public function firmataCaricata(array $destinatari, string $azienda, string $firmatario, bool $sostituita): int
    {
        $corpo = '<p>L\'azienda <strong>' . $this->h($azienda) . '</strong> ha ' . ($sostituita ? 'caricato una <strong>nuova versione</strong> della' : 'caricato la') . ' convenzione per la tesi firmata'
            . ($firmatario !== '' ? ' (firma di <strong>' . $this->h($firmatario) . '</strong>)' : '') . '.</p>'
            . '<p>Va <strong>protocollata in entrata</strong>: scarica il PDF, protocollalo e registra il numero nel portale. Subito dopo la convenzione va al Direttore per la firma.</p>' . $this->pulsante($this->linkPannello(), 'Apri le convenzioni di tesi');

        return $this->alUfficio($destinatari, 'Convenzione di tesi da prendere in carico: ' . $azienda, $corpo);
    }

    public function firmaRichiesta(string $email, string $nome, string $azienda, string $tokenDirettore): bool
    {
        $link = $this->sito->urlBase() . '/firma_convenzione_tesi.php?t=' . urlencode($tokenDirettore);
        $corpo = '<p>Gentile <strong>' . $this->h($nome) . '</strong>,</p><p>la convenzione per la tesi con <strong>' . $this->h($azienda) . '</strong> è già firmata dall\'azienda e protocollata in entrata: attende la <strong>firma del Direttore</strong>.</p>'
            . '<p>Si firma dal portale con la firma remota oppure scaricando il PDF, firmandolo in PAdES e ricaricandolo. Per accedere servono le credenziali di Ateneo.</p>' . $this->pulsante($link, 'Firma la convenzione');

        return $this->scrivi($email, 'Convenzione di tesi da firmare: ' . $azienda, $corpo);
    }

    /** @param list<string> $destinatari */
    public function firmataDalDirettore(array $destinatari, string $azienda): int
    {
        $corpo = '<p>Il Direttore ha <strong>firmato</strong> la convenzione per la tesi con <strong>' . $this->h($azienda) . '</strong>.</p>'
            . '<p>Ora va <strong>protocollata in uscita</strong>: scarica il PDF firmato, protocollalo e nel portale registra numero, data e validità (poi puoi caricare anche il PDF con la segnatura di protocollo).</p>' . $this->pulsante($this->linkPannello(), 'Apri le convenzioni di tesi');

        return $this->alUfficio($destinatari, 'Convenzione di tesi firmata dal Direttore: ' . $azienda, $corpo);
    }

    /** @param list<string> $destinatari @param list<array<string, string|null>> $convenzioni */
    public function scadenzeAllUfficio(array $destinatari, array $convenzioni): int
    {
        $righe = '';
        foreach ($convenzioni as $c) {
            $righe .= '<li><strong>' . $this->h($c['azienda']) . '</strong>: scade il ' . $this->h(date('d/m/Y', (int) strtotime((string) $c['valida_al']))) . '</li>';
        }

        return $this->alUfficio($destinatari, 'Convenzioni di tesi in scadenza (' . count($convenzioni) . ')', '<p>Queste convenzioni per la tesi scadono a breve; le aziende hanno ricevuto il link per rinnovarle:</p><ul>' . $righe . '</ul>' . $this->pulsante($this->linkPannello(), 'Apri le convenzioni di tesi'));
    }
}
