<?php

declare(strict_types=1);

namespace App\Assistenza\Push;

use App\Assistenza\Costanti;
use App\Core\Orologio;

/**
 * Le notifiche a finestrella: chi le accetta si iscrive dal suo browser (operatori dalla pagina Assistenza, chi chiede aiuto dalla chat) e poi riceve un
 * avviso a ogni novità, anche con il sito chiuso. Gli operatori vedono chi scrive e le prime parole; chi chiede aiuto riceve solo «hai una risposta», senza
 * il testo (può contenere dati personali e finirebbe nel centro notifiche del computer). Se le chiavi VAPID mancano non succede nulla e restano le email.
 */
final class ServizioPush
{
    /** Quanti caratteri del messaggio si mostrano nella finestrella dell'operatore. */
    private const ANTEPRIMA = 90;

    public function __construct(private InvioPush $invio, private SottoscrizioniRepository $repo, private ChiaviVapid $chiavi, private Orologio $orologio)
    {
    }

    public function attivo(): bool
    {
        return $this->invio->attivo();
    }

    public function chiavePubblica(): string
    {
        return $this->chiavi->pubblica();
    }

    private function adesso(): string
    {
        return $this->orologio->adesso()->format('Y-m-d H:i:s');
    }

    /**
     * Controlla i dati che il browser manda (l'oggetto «subscription» già in forma di array) e li riduce a quelli che servono.
     *
     * @param array<string, mixed> $dati
     * @return array{endpoint: string, p256dh: string, auth: string}|null
     */
    public static function sottoscrizioneValida(array $dati): ?array
    {
        $endpoint = (string) ($dati['endpoint'] ?? '');
        $chiavi = (array) ($dati['keys'] ?? $dati);
        $p256dh = (string) ($chiavi['p256dh'] ?? '');
        $auth = (string) ($chiavi['auth'] ?? '');
        if (!preg_match('#^https://[^\s/]+/\S{8,}$#', $endpoint) || strlen($endpoint) > 1000 || strlen(CurvaEc::b64uDecodifica($p256dh)) !== 65 || strlen(CurvaEc::b64uDecodifica($auth)) < 16 || strlen($auth) > 40) {
            return null;
        }

        return ['endpoint' => $endpoint, 'p256dh' => $p256dh, 'auth' => $auth];
    }

    /**
     * L'operatore accetta le notifiche su questo browser. Ritorna l'errore o null.
     *
     * @param array<string, mixed> $dati
     */
    public function iscriviOperatore(int $utenteId, array $dati, string $agente): ?string
    {
        if (!$this->attivo()) {
            return 'Le notifiche non sono ancora attive sul server.';
        }
        $s = self::sottoscrizioneValida($dati);
        if ($s === null) {
            return 'Il browser non ha dato un recapito valido: riprova.';
        }
        $this->repo->salva($s, SottoscrizioniRepository::OPERATORE, $utenteId, null, $agente, $this->adesso());

        return null;
    }

    /**
     * Chi ha chiesto aiuto accetta di essere avvisato delle risposte a questa chat. Ritorna l'errore o null.
     *
     * @param array<string, string|null> $conv
     * @param array<string, mixed> $dati
     */
    public function iscriviUtente(array $conv, array $dati, string $agente): ?string
    {
        if (!$this->attivo()) {
            return 'Le notifiche non sono ancora attive sul server.';
        }
        $s = self::sottoscrizioneValida($dati);
        if ($s === null) {
            return 'Il browser non ha dato un recapito valido: riprova.';
        }
        $this->repo->salva($s, SottoscrizioniRepository::UTENTE, $conv['utente_id'] !== null ? (int) $conv['utente_id'] : null, (int) $conv['id'], $agente, $this->adesso());

        return null;
    }

    /** Toglie il recapito di questo browser. Gli operatori possono togliere solo i propri. */
    public function disiscrivi(string $endpoint, ?int $soloDiUtente = null): void
    {
        $s = $this->repo->perEndpoint($endpoint);
        if ($s !== null && ($soloDiUtente === null || (int) $s['utente_id'] === $soloDiUtente)) {
            $this->repo->eliminaPerEndpoint($endpoint);
        }
    }

    /** Chi ha chiesto aiuto toglie il recapito di questo browser: solo se è legato alla sua conversazione. @param array<string, string|null> $conv */
    public function disiscriviUtente(array $conv, string $endpoint): void
    {
        $s = $this->repo->perEndpoint($endpoint);
        if ($s !== null && $s['ruolo'] === SottoscrizioniRepository::UTENTE && (int) $s['conversazione_id'] === (int) $conv['id']) {
            $this->repo->eliminaPerEndpoint($endpoint);
        }
    }

    /** Questo browser è iscritto per questa conversazione? @param array<string, string|null> $conv */
    public function iscrittoUtente(array $conv, string $endpoint): bool
    {
        $s = $this->repo->perEndpoint($endpoint);

        return $s !== null && $s['ruolo'] === SottoscrizioniRepository::UTENTE && (int) $s['conversazione_id'] === (int) $conv['id'];
    }

    /** Questo browser è iscritto (per quell'operatore)? */
    public function iscrittoOperatore(string $endpoint, int $utenteId): bool
    {
        $s = $this->repo->perEndpoint($endpoint);

        return $s !== null && $s['ruolo'] === SottoscrizioniRepository::OPERATORE && (int) $s['utente_id'] === $utenteId;
    }

    /**
     * Preferenze dell'operatore: silenzia per N minuti (0 = riattiva) e solo le chat in carico a lui.
     *
     * @return array{silenzia_fino: ?string, solo_mie: bool}
     */
    public function preferenze(int $utenteId, int $silenziaMinuti, bool $soloMie): array
    {
        $fino = $silenziaMinuti > 0 ? $this->orologio->adesso()->modify('+' . min($silenziaMinuti, 24 * 60) . ' minutes')->format('Y-m-d H:i:s') : null;
        $this->repo->preferenze($utenteId, $fino, $soloMie);

        return ['silenzia_fino' => $fino, 'solo_mie' => $soloMie];
    }

    /** Le preferenze di un operatore (da uno dei suoi browser). @return array{silenzia_fino: ?string, solo_mie: bool} */
    public function leggiPreferenze(int $utenteId): array
    {
        $s = $this->repo->perUtente($utenteId, SottoscrizioniRepository::OPERATORE)[0] ?? null;
        $fino = $s && $s['silenzia_fino'] !== null && strtotime((string) $s['silenzia_fino']) > $this->orologio->adesso()->getTimestamp() ? (string) $s['silenzia_fino'] : null;

        return ['silenzia_fino' => $fino, 'solo_mie' => $s !== null && (int) $s['solo_mie'] === 1];
    }

    /**
     * Manda un messaggio a un elenco di recapiti: elimina quelli scaduti e conta gli errori degli altri. Ritorna quanti sono partiti.
     *
     * @param list<array<string, string|null>> $recapiti
     * @param array<string, mixed> $messaggio titolo, corpo, url, tag
     */
    private function manda(array $recapiti, array $messaggio, string $urgenza = 'normal'): int
    {
        $json = (string) json_encode($messaggio, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $n = 0;
        foreach ($recapiti as $r) {
            $e = $this->invio->invia(['endpoint' => (string) $r['endpoint'], 'p256dh' => (string) $r['p256dh'], 'auth' => (string) $r['auth']], $json, 3600, $urgenza);
            if ($e['scaduta']) {
                $this->repo->elimina((int) $r['id']);
            } else {
                $this->repo->esito((int) $r['id'], $e['ok'], $this->adesso());
            }
            $n += $e['ok'] ? 1 : 0;
        }

        return $n;
    }

    /** @param array<string, string|null> $r */
    private function silenziato(array $r): bool
    {
        return $r['silenzia_fino'] !== null && strtotime((string) $r['silenzia_fino']) > $this->orologio->adesso()->getTimestamp();
    }

    private static function anteprima(string $testo): string
    {
        $t = trim((string) preg_replace('/\s+/u', ' ', $testo));

        return mb_strlen($t) > self::ANTEPRIMA ? mb_substr($t, 0, self::ANTEPRIMA - 1) . '…' : $t;
    }

    /**
     * Una chat nuova da prendere, o un messaggio in una chat che nessuno ha ancora preso: lo sentono tutti gli operatori che non hanno silenziato
     * e che non hanno scelto «solo le mie chat».
     *
     * @param array<string, string|null> $conv
     */
    public function avvisaOperatori(array $conv, string $testo, string $link, bool $nuova): int
    {
        $recapiti = array_filter($this->repo->operatori(), fn(array $r) => !$this->silenziato($r) && (int) $r['solo_mie'] === 0);

        return $this->manda(array_values($recapiti), [
            'titolo' => $nuova ? 'Nuova richiesta di assistenza' : 'Nuovo messaggio da prendere', 'corpo' => $conv['nome'] . ' · ' . Costanti::nomeArgomento((string) $conv['argomento']) . ': ' . self::anteprima($testo),
            'url' => $link, 'tag' => 'assistenza-' . $conv['id'],
        ], 'high');
    }

    /**
     * Un messaggio nella chat in carico a un operatore: lo sente lui (tutti i suoi browser), se non ha silenziato.
     *
     * @param array<string, string|null> $conv
     */
    public function avvisaOperatore(int $operatoreId, array $conv, string $testo, string $link): int
    {
        $recapiti = array_filter($this->repo->perUtente($operatoreId, SottoscrizioniRepository::OPERATORE), fn(array $r) => !$this->silenziato($r));

        return $this->manda(array_values($recapiti), [
            'titolo' => 'Messaggio da ' . $conv['nome'], 'corpo' => self::anteprima($testo), 'url' => $link, 'tag' => 'assistenza-' . $conv['id'],
        ], 'high');
    }

    /**
     * Una risposta per chi ha chiesto aiuto: solo «hai una risposta», senza il testo.
     *
     * @param array<string, string|null> $conv
     */
    public function avvisaUtente(array $conv, string $link): int
    {
        return $this->manda($this->repo->perConversazione((int) $conv['id'], $conv['utente_id'] !== null ? (int) $conv['utente_id'] : null), [
            'titolo' => 'Assistenza Didattica DiBEST', 'corpo' => 'Hai una risposta dall\'assistenza. Tocca per leggerla.', 'url' => $link, 'tag' => 'assistenza-risposta',
        ]);
    }

    /** Notifica di prova per l'operatore (pulsante «Prova»). Ritorna quante sono partite. */
    public function prova(int $utenteId, string $link): int
    {
        return $this->manda($this->repo->perUtente($utenteId, SottoscrizioniRepository::OPERATORE), [
            'titolo' => 'Notifiche attive', 'corpo' => 'Funziona: così vedrai le nuove richieste di assistenza.', 'url' => $link, 'tag' => 'assistenza-prova',
        ]);
    }
}
