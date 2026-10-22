<?php

declare(strict_types=1);

namespace App\Assistenza;

use App\Assistenza\Push\ServizioPush;
use App\Core\Sito;
use App\Email\InvioEmail;

/** Le email dell'assistenza (testi nel catalogo, modificabili dalla pagina «Testi delle email»). */
final class NotificheAssistenza
{
    /** Quanti caratteri del messaggio si mettono nell'email. */
    private const ANTEPRIMA = 600;

    public function __construct(private InvioEmail $invio, private Sito $sito, private ServizioPush $push)
    {
    }

    /** Il link con cui chi chiede aiuto riprende la chat: la sua pagina se ha il login, il link personale se è esterno. @param array<string, string|null> $conv */
    public function linkUtente(array $conv): string
    {
        return $this->sito->urlBase() . '/assistenza.php' . ($conv['canale'] === Costanti::CANALE_ESTERNO ? '?t=' . urlencode((string) $conv['token']) : '');
    }

    /** @param array<string, string|null> $conv */
    public function linkOperatore(array $conv): string
    {
        return $this->sito->urlBase() . '/admin/assistenza.php?c=' . (int) $conv['id'];
    }

    /**
     * Avvisa gli operatori di una nuova richiesta (o la ricorda). Ritorna quante email sono partite.
     *
     * @param array<string, string|null> $conv
     * @param list<string> $destinatari
     */
    public function nuovaRichiesta(array $conv, string $testo, array $destinatari): int
    {
        $contesto = (string) $conv['contesto_testo'] !== '' ? ', riguarda ' . $conv['contesto_testo'] : '';
        $n = 0;
        foreach ($destinatari as $a) {
            $n += $this->invio->invia($a, 'assistenza_operatori_nuova', [
                'persona' => (string) $conv['nome'], 'email' => (string) $conv['email'], 'argomento' => Costanti::nomeArgomento((string) $conv['argomento']),
                'contesto' => $contesto, 'testo' => mb_substr($testo, 0, self::ANTEPRIMA),
            ], $this->linkOperatore($conv)) ? 1 : 0;
        }

        return $n;
    }

    /** @param array<string, string|null> $conv */
    public function messaggioPerOperatore(array $conv, string $testo, string $a): bool
    {
        return $a !== '' && $this->invio->invia($a, 'assistenza_operatori_messaggio', [
            'persona' => (string) $conv['nome'], 'argomento' => Costanti::nomeArgomento((string) $conv['argomento']), 'testo' => mb_substr($testo, 0, self::ANTEPRIMA),
        ], $this->linkOperatore($conv));
    }

    /** Conferma a un esterno, con il link personale. @param array<string, string|null> $conv */
    public function ricevuta(array $conv): bool
    {
        return filter_var($conv['email'], FILTER_VALIDATE_EMAIL) !== false && $this->invio->invia((string) $conv['email'], 'assistenza_utente_ricevuta', [
            'nome' => (string) $conv['nome'], 'argomento' => Costanti::nomeArgomento((string) $conv['argomento']),
        ], $this->linkUtente($conv));
    }

    /** @param array<string, string|null> $conv */
    public function rispostaPerUtente(array $conv, string $testo): bool
    {
        return filter_var($conv['email'], FILTER_VALIDATE_EMAIL) !== false && $this->invio->invia((string) $conv['email'], 'assistenza_utente_risposta', [
            'nome' => (string) $conv['nome'], 'argomento' => Costanti::nomeArgomento((string) $conv['argomento']), 'testo' => mb_substr($testo, 0, self::ANTEPRIMA),
        ], $this->linkUtente($conv));
    }

    /** Un guasto delle notifiche push non deve mai fermare la chat: si scrive nel registro e si va avanti. */
    private function sicuro(callable $f): int
    {
        try {
            return $f();
        } catch (\Throwable $e) {
            error_log('Assistenza, notifica push: ' . $e->getMessage());

            return 0;
        }
    }

    /** Finestrella agli operatori per una chat nuova. @param array<string, string|null> $conv */
    public function pushNuovaRichiesta(array $conv, string $testo): int
    {
        return $this->sicuro(fn() => $this->push->avvisaOperatori($conv, $testo, $this->linkOperatore($conv), true));
    }

    /** Finestrella per un messaggio: all'operatore che ha la chat, o a tutti se nessuno l'ha presa. @param array<string, string|null> $conv */
    public function pushMessaggio(array $conv, string $testo): int
    {
        return $this->sicuro(fn() => (int) $conv['operatore_id'] > 0 && $conv['stato'] === Costanti::STATO_ASSEGNATA
            ? $this->push->avvisaOperatore((int) $conv['operatore_id'], $conv, $testo, $this->linkOperatore($conv))
            : $this->push->avvisaOperatori($conv, $testo, $this->linkOperatore($conv), false));
    }

    /** Finestrella a chi ha chiesto aiuto (senza il testo della risposta). @param array<string, string|null> $conv */
    public function pushRispostaUtente(array $conv): int
    {
        return $this->sicuro(fn() => $this->push->avvisaUtente($conv, $this->linkUtente($conv)));
    }
}
