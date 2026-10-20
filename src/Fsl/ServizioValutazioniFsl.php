<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Anagrafi\ServizioScuole;
use App\Anagrafi\Testi;
use App\Core\Sito;
use App\Email\InvioEmail;
use App\Portale\ColoriAree;

/**
 * Scheda di valutazione della struttura ospitante. La convenzione (art. 3) prevede che la scuola valuti la struttura: a fine
 * attività FSL il docente che ha prenotato riceve un link personale (valutazione_fsl.php?t=…). Le risposte sono legate alla scuola.
 */
final class ServizioValutazioniFsl
{
    public function __construct(
        private PrenotazioneFslRepository $prenotazioni,
        private ValutazioneRepository $valutazioni,
        private ServizioScuole $scuole,
        private ColoriAree $colori,
        private InvioEmail $invio,
        private Sito $sito
    ) {
    }

    /**
     * Prenotazione (con attività, periodo e scuola) dal link personale della scheda di valutazione.
     *
     * @return array<string, mixed>|null
     */
    public function prenotazioneDaToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{40}$/', $token)) {
            return null;
        }

        return $this->prenotazioni->perValutazione($token);
    }

    /** Email al docente con il link alla scheda. $promemoria = true per il secondo invio. */
    public function invia(int $prenotazioneId, bool $promemoria = false): bool
    {
        $p = $this->prenotazioni->dati($prenotazioneId);
        if (!$p || empty($p['email']) || !filter_var($p['email'], FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $token = (string) ($p['valutazione_token'] ?? '');
        if (!preg_match('/^[a-f0-9]{40}$/', $token)) {
            $token = bin2hex(random_bytes(20));
            $this->prenotazioni->impostaTokenValutazione($prenotazioneId, $token);
        }
        $scuola = !empty($p['scuola_codice']) ? $this->scuole->perCodice($p['scuola_codice']) : null;
        $ok = $this->invio->invia(
            $p['email'],
            $promemoria ? 'fsl_valutazione_promemoria' : 'fsl_valutazione_invito',
            ['nome' => trim($p['nome'] . ' ' . $p['cognome']), 'attivita_titolo' => (string) $p['evento_titolo'], 'scuola_testo' => $scuola ? ' (' . Testi::etichettaScuola($scuola) . ')' : ''],
            $this->sito->urlBase() . '/valutazione_fsl.php?t=' . $token,
            [],
            '',
            $this->colori->delTurno((int) $p['turno_id'])
        );
        if ($ok) {
            $this->prenotazioni->segnaInvitoValutazione($prenotazioneId, $promemoria);
        }

        return $ok;
    }

    /**
     * Salva la scheda (una sola per prenotazione). Ritorna null se va bene, altrimenti il messaggio d'errore.
     *
     * @param array<string, mixed> $p prenotazione da prenotazioneDaToken()
     * @param array<string, mixed> $post voto[aspetto], ripeterebbe, testo[domanda], compilata_da
     */
    public function salva(array $p, array $post): ?string
    {
        if (!empty($p['valutazione_id'])) {
            return 'La scheda di valutazione è già stata compilata. Grazie!';
        }
        $voti = [];
        foreach (Costanti::ASPETTI as $k => $etichetta) {
            $v = (int) ($post['voto'][$k] ?? 0);
            if ($v < 1 || $v > 5) {
                return 'Indica un voto da 1 a 5 per: ' . $etichetta . '.';
            }
            $voti[$k] = $v;
        }
        $rip = in_array($post['ripeterebbe'] ?? '', ['si', 'forse', 'no'], true) ? $post['ripeterebbe'] : '';
        if ($rip === '') {
            return "Indica se riproporresti l'attività ad altre classi.";
        }
        $testi = [];
        foreach (array_keys(Costanti::APERTE) as $k) {
            $testi[$k] = mb_substr(trim((string) ($post['testo'][$k] ?? '')), 0, 2000);
        }
        $compilataDa = mb_substr(trim((string) ($post['compilata_da'] ?? '')), 0, 150) ?: trim($p['nome'] . ' ' . $p['cognome']);
        $json = json_encode(['voti' => $voti, 'testi' => $testi], JSON_UNESCAPED_UNICODE);
        $media = round(array_sum($voti) / count($voti), 2);
        if ($this->valutazioni->inserisci((int) $p['id'], (int) $p['evento_id'], $p['scuola_codice'] ?: null, $compilataDa, (string) $json, $media, $rip) <= 0) {
            return 'La scheda di valutazione è già stata compilata. Grazie!';
        }

        return null;
    }
}
