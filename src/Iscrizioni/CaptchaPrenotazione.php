<?php

declare(strict_types=1);

namespace App\Iscrizioni;

use App\Auth\Sessione;
use App\Core\Orologio;

/**
 * Controllo anti-robot delle prenotazioni pubbliche (chi prenota senza accesso): domanda semplice (una somma) generata dal server,
 * senza servizi esterni né cookie di terzi. Una domanda per pagina (vale per tutte le finestre di prenotazione della pagina),
 * risposta in sessione, valida una sola volta; si tengono le ultime 10 pagine aperte (più schede del browser).
 * Spostato da captcha_prenotazione() e captcha_verifica() di inc/prenotazioni.php, che restano come facciate.
 */
final class CaptchaPrenotazione
{
    private const CHIAVE = 'captcha_pren';

    /** @var array{id: string, domanda: string}|null */
    private ?array $corrente = null;

    public function __construct(private Sessione $sessione, private Orologio $orologio)
    {
    }

    /** @return array{id: string, domanda: string} */
    public function domanda(): array
    {
        if ($this->corrente !== null) {
            return $this->corrente;
        }
        $a = random_int(2, 9);
        $b = random_int(1, 9);
        $id = bin2hex(random_bytes(8));
        $lista = $this->sessione->leggi(self::CHIAVE) ?? [];
        $lista[$id] = ['r' => $a + $b, 't' => $this->orologio->adesso()->getTimestamp()];
        $this->sessione->scrivi(self::CHIAVE, array_slice($lista, -10, null, true));

        return $this->corrente = ['id' => $id, 'domanda' => "Quanto fa $a + $b?"];
    }

    /**
     * Ritorna null se la risposta è giusta, altrimenti il messaggio da mostrare.
     * Rifiuta anche i moduli inviati in meno di 3 secondi (tipico dei programmi automatici).
     */
    public function verifica(string $id, string $risposta): ?string
    {
        $lista = $this->sessione->leggi(self::CHIAVE);
        $c = is_array($lista) ? ($lista[$id] ?? null) : null;
        if ($c !== null) {
            unset($lista[$id]);
            $this->sessione->scrivi(self::CHIAVE, $lista);
        }
        $adesso = $this->orologio->adesso()->getTimestamp();
        if ($c === null || $adesso - (int) $c['t'] > 7200) {
            return 'La domanda di controllo è scaduta: ricarica la pagina e riprova.';
        }
        if ($adesso - (int) $c['t'] < 3) {
            return 'Modulo inviato troppo in fretta: attendi qualche secondo e riprova.';
        }
        if (!preg_match('/^\s*\d+\s*$/', $risposta) || (int) $risposta !== (int) $c['r']) {
            return 'La risposta alla domanda di controllo non è corretta: riprova.';
        }

        return null;
    }
}
