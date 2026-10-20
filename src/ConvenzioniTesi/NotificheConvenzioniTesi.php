<?php

declare(strict_types=1);

namespace App\ConvenzioniTesi;

use App\Core\Sito;
use App\Email\InvioEmail;

/**
 * Le email dell'iter delle convenzioni di tesi: all'azienda, all'ufficio e al Direttore.
 * I testi stanno nel catalogo (CatalogoConvenzioni) e si modificano dalla pagina «Testi delle email» del pannello.
 */
final class NotificheConvenzioniTesi
{
    public function __construct(private InvioEmail $invio, private Sito $sito)
    {
    }

    private function h(mixed $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    public function linkAzienda(string $token): string
    {
        return $this->sito->urlBase() . '/convenzione_tesi.php?t=' . urlencode($token);
    }

    public function linkPannello(): string
    {
        return $this->sito->urlBase() . '/admin/convenzioni_tesi.php';
    }

    /** @param array<string, scalar|null> $dati */
    private function scrivi(string $a, string $chiave, array $dati, string $link): bool
    {
        return filter_var($a, FILTER_VALIDATE_EMAIL) !== false && $this->invio->invia($a, $chiave, $dati, $link);
    }

    /** @param list<string> $destinatari @param array<string, scalar|null> $dati @return int quante email sono partite */
    private function alUfficio(array $destinatari, string $chiave, array $dati): int
    {
        $n = 0;
        foreach (array_unique(array_map('strtolower', $destinatari)) as $a) {
            if ($this->scrivi($a, $chiave, $dati, $this->linkPannello())) {
                ++$n;
            }
        }

        return $n;
    }

    private function nomeAzienda(string $azienda): string
    {
        return $azienda !== '' ? $azienda : 'azienda';
    }

    // ───────────── All'azienda ─────────────

    /** Il link per compilare la convenzione (invito dell'ufficio o richiesta dalla pagina pubblica). */
    public function invito(string $email, string $azienda, string $token, string $da = ''): bool
    {
        return $this->scrivi($email, 'tesi_invito', ['azienda' => $this->nomeAzienda($azienda)], $this->linkAzienda($token));
    }

    /** Alla richiesta ripetuta della stessa azienda si rimanda il link della pratica già aperta. */
    public function invitoRipreso(string $email, string $azienda, string $token): bool
    {
        return $this->scrivi($email, 'tesi_invito_ripreso', ['azienda' => $this->nomeAzienda($azienda)], $this->linkAzienda($token));
    }

    public function rispostaModifiche(string $email, string $azienda, string $token, bool $tutteAccettate): bool
    {
        return $this->scrivi($email, $tutteAccettate ? 'tesi_modifiche_accettate' : 'tesi_modifiche_risposta', ['azienda' => $azienda], $this->linkAzienda($token));
    }

    public function firmataRicevuta(string $email, string $azienda, string $token): bool
    {
        return $this->scrivi($email, 'tesi_firmata_ricevuta', ['azienda' => $azienda], $this->linkAzienda($token));
    }

    public function presaInCarico(string $email, string $azienda, string $token, string $protocollo): bool
    {
        return $this->scrivi($email, 'tesi_presa_in_carico', ['azienda' => $azienda, 'protocollo' => $protocollo], $this->linkAzienda($token));
    }

    public function rifiutata(string $email, string $azienda, string $token, string $motivo): bool
    {
        return $this->scrivi($email, 'tesi_rifiutata', ['azienda' => $azienda, 'motivo' => $motivo], $this->linkAzienda($token));
    }

    public function attiva(string $email, string $azienda, string $token, string $protocollo, string $dal, string $al): bool
    {
        return $this->scrivi($email, 'tesi_attiva', ['azienda' => $azienda, 'protocollo' => $protocollo, 'dal' => date('d/m/Y', (int) strtotime($dal)), 'al' => date('d/m/Y', (int) strtotime($al))], $this->linkAzienda($token));
    }

    public function promemoria(string $email, string $azienda, string $token, string $stato): bool
    {
        $chiave = match ($stato) {
            Costanti::INVITATA => 'tesi_promemoria_invitata',
            Costanti::RISPOSTA => 'tesi_promemoria_risposta',
            Costanti::RIFIUTATA => 'tesi_promemoria_rifiutata',
            default => 'tesi_promemoria_firma',
        };

        return $this->scrivi($email, $chiave, ['azienda' => $this->nomeAzienda($azienda)], $this->linkAzienda($token));
    }

    public function inScadenza(string $email, string $azienda, string $al, string $linkRichiesta): bool
    {
        return $this->scrivi($email, 'tesi_in_scadenza', ['azienda' => $azienda, 'al' => date('d/m/Y', (int) strtotime($al))], $linkRichiesta);
    }

    // ───────────── All'ufficio e al Direttore ─────────────

    /** @param list<string> $destinatari */
    public function modificheProposte(array $destinatari, string $azienda, int $quanti): int
    {
        return $this->alUfficio($destinatari, 'tesi_modifiche_proposte', ['azienda' => $azienda, 'modifiche' => $quanti . ' modific' . ($quanti === 1 ? 'a' : 'he')]);
    }

    /** @param list<string> $destinatari */
    public function firmataCaricata(array $destinatari, string $azienda, string $firmatario, bool $sostituita): int
    {
        return $this->alUfficio($destinatari, 'tesi_firmata_caricata', ['azienda' => $azienda, 'azione' => $sostituita ? 'caricato una nuova versione della' : 'caricato la', 'firmatario_testo' => $firmatario !== '' ? ' (firma di ' . $firmatario . ')' : '']);
    }

    public function firmaRichiesta(string $email, string $nome, string $azienda, string $tokenDirettore): bool
    {
        return $this->scrivi($email, 'tesi_firma_richiesta', ['direttore' => $nome, 'azienda' => $azienda], $this->sito->urlBase() . '/firma_convenzione_tesi.php?t=' . urlencode($tokenDirettore));
    }

    /** @param list<string> $destinatari */
    public function firmataDalDirettore(array $destinatari, string $azienda): int
    {
        return $this->alUfficio($destinatari, 'tesi_firmata_direttore', ['azienda' => $azienda]);
    }

    /** @param list<string> $destinatari @param list<array<string, string|null>> $convenzioni */
    public function scadenzeAllUfficio(array $destinatari, array $convenzioni): int
    {
        $righe = '';
        foreach ($convenzioni as $c) {
            $righe .= '<li><strong>' . $this->h($c['azienda']) . '</strong>: scade il ' . $this->h(date('d/m/Y', (int) strtotime((string) $c['valida_al']))) . '</li>';
        }

        return $this->alUfficio($destinatari, 'tesi_scadenze_ufficio', ['quante' => (string) count($convenzioni), 'elenco' => '<ul>' . $righe . '</ul>']);
    }
}
