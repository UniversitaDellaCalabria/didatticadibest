<?php

declare(strict_types=1);

namespace App\Iscritti;

use App\Core\Orologio;
use App\Core\Sito;
use App\Email\InvioEmail;
use App\Portale\AreeRepository;
use App\Portale\ColoriAree;

/** Messaggi tra la segreteria e gli iscritti: inbox dell'area, risposte (con email all'iscritto), lettura. */
final class ServizioMessaggi
{
    /** Tag HTML ammessi nei messaggi (il resto viene tolto). */
    public const TAG_CONSENTITI = '<b><strong><i><em><u><br><p><ul><ol><li><span>';

    public function __construct(
        private MessaggiRepository $messaggi,
        private IscrittiRepository $iscritti,
        private AreeRepository $aree,
        private InvioEmail $invio,
        private ColoriAree $colori,
        private Sito $sito,
        private Orologio $orologio,
    ) {
    }

    /**
     * @return list<array<string, string|null>>
     */
    public function conversazioni(int $paginaId, string $rbac): array
    {
        return $this->messaggi->conversazioni($paginaId, $rbac);
    }

    /**
     * @param list<int|string> $prenotazioniIds
     * @return array<int, list<array<string, string|null>>>
     */
    public function perPrenotazioni(array $prenotazioniIds): array
    {
        return $this->messaggi->perPrenotazioni($prenotazioniIds);
    }

    /**
     * @return list<array<string, string|null>>
     */
    public function chat(int $prenotazioneId): array
    {
        return $this->messaggi->chat($prenotazioneId);
    }

    /** Prenotazioni dell'area con messaggi dell'utente non ancora letti (badge del menu). */
    public function nonLetti(int $paginaId): int
    {
        return $this->aree->messaggiNonLetti($paginaId, '');
    }

    public function segnaLetti(int $prenotazioneId): void
    {
        $this->messaggi->segnaLettiDellUtente($prenotazioneId);
    }

    /**
     * Messaggio scritto dal pannello Iscritti: si salva e, se la prenotazione ha un'email, si avvisa l'iscritto.
     * Il testo non si pulisce (lo scrive la segreteria). Ritorna false se non c'è nulla da inviare.
     */
    public function inviaDaIscritti(int $prenotazioneId, string $testo, Operatore $op): bool
    {
        $p = $this->iscritti->prenotazioneConTurnoEvento($prenotazioneId);
        $emailDest = (string) ($p['email'] ?? '');   // dal database: il campo del form non è affidabile
        $titolo = (string) ($p['evento_titolo'] ?? '');
        $messaggio = trim($testo);
        if (!($prenotazioneId > 0 && !empty($messaggio))) {
            return false;
        }
        $this->messaggi->inserisciDellAdmin($prenotazioneId, $op->id, $messaggio, false);
        if (!empty($emailDest)) {
            $nome = $this->messaggi->nomeOperatore($op->id) ?? 'Segreteria DiBEST';
            $this->invia($emailDest, $nome, $titolo, $messaggio);
        }

        return true;
    }

    /**
     * Risposta dall'inbox: il testo si pulisce (solo tag sicuri), i messaggi dell'utente si segnano letti e l'iscritto riceve l'email
     * con il colore dell'area. Ritorna false se non c'è nulla da inviare.
     */
    public function rispondiDaInbox(int $prenotazioneId, string $testoGrezzo, Operatore $op): bool
    {
        $p = $this->iscritti->prenotazioneConTurnoEvento($prenotazioneId);   // destinatario e titolo dal database, non dal form
        $emailDest = (string) ($p['email'] ?? '');
        $titolo = (string) ($p['evento_titolo'] ?? '');
        // Sanitizzazione: mantieni solo tag HTML sicuri, rimuovi script e attributi pericolosi
        $messaggio = strip_tags(trim($testoGrezzo), self::TAG_CONSENTITI);
        if (!($prenotazioneId > 0 && !empty($messaggio))) {
            return false;
        }
        // Segna come letti tutti i messaggi precedenti dell'utente in questa chat, poi salva la risposta
        $this->messaggi->segnaLettiDellUtente($prenotazioneId);
        $this->messaggi->inserisciDellAdmin($prenotazioneId, $op->id, $messaggio, true);
        if (!empty($emailDest)) {
            $nome = $this->messaggi->nomeOperatore($op->id) ?? 'Segreteria DiBEST';
            $this->invia($emailDest, $nome, $titolo, $messaggio, $this->colori->delTurno((int) ($p['turno_id'] ?? 0)));
        }

        return true;
    }

    /** Email all'iscritto con il messaggio della segreteria, dal modello «evento_messaggio_segreteria». */
    private function invia(string $emailDest, string $nomeOperatore, string $titolo, string $messaggioHtml, ?string $colore = null): void
    {
        $box = "<div style='background:#f8fafc; padding:15px; border-left:4px solid #B80000; margin:15px 0; font-style:italic;'>$messaggioHtml</div>";
        $this->invio->invia(
            $emailDest,
            'evento_messaggio_segreteria',
            ['operatore' => $nomeOperatore, 'evento' => $titolo, 'ora' => $this->orologio->adesso()->format('d/m H:i'), 'messaggio' => $box],
            $this->sito->urlBase() . '/area_personale.php',
            [],
            '',
            $colore
        );
    }
}
