<?php

declare(strict_types=1);

namespace App\Iscrizioni;

/** Risultato di una prenotazione dal modulo pubblico: dove rimandare la persona e l'eventuale messaggio da tenere in sessione. */
final readonly class EsitoPrenotazione
{
    /**
     * @param string $destinazione indirizzo del rimando (Location), relativo alla cartella del portale
     * @param string|null $erroreSessione messaggio da mettere in $_SESSION['errore_prenotazione'] prima del rimando
     */
    public function __construct(public string $destinazione, public ?string $erroreSessione = null)
    {
    }

    /**
     * Parametri del rimando (status, code, st_tipo, conv…): chi prenota più attività insieme legge l'esito di ognuna da qui
     * invece di seguire il rimando.
     *
     * @return array<string, string>
     */
    public function parametri(): array
    {
        $q = strpos($this->destinazione, '?');
        parse_str($q === false ? '' : substr($this->destinazione, $q + 1), $p);

        return array_map('strval', array_filter($p, 'is_scalar'));
    }

    /** «success» se la prenotazione è stata registrata; altrimenti il motivo (full, closed, dup, limite, studenti, error…). */
    public function stato(): string
    {
        return $this->parametri()['status'] ?? 'error';
    }

    public function riuscita(): bool
    {
        return $this->stato() === 'success' && $this->codice() !== null;
    }

    /** Codice della prenotazione registrata. */
    public function codice(): ?string
    {
        $c = $this->parametri()['code'] ?? '';

        return $c === '' ? null : $c;
    }

    /** Registrata in lista d'attesa (posti esauriti). */
    public function listaAttesa(): bool
    {
        return $this->riuscita() && ($this->parametri()['st_tipo'] ?? '') === 'attesa';
    }

    /** Registrata ma in attesa della convenzione della scuola (da approvare, o in lista d'attesa e senza convenzione). */
    public function inAttesaConvenzione(): bool
    {
        $p = $this->parametri();

        return $this->riuscita() && (($p['st_tipo'] ?? '') === 'convenzione' || ($p['conv'] ?? '') === 'no');
    }

    /** La convenzione registrata dalla scuola non copre il periodo dell'attività: ne va stipulata una nuova. */
    public function convenzioneDaRinnovare(): bool
    {
        return $this->riuscita() && ($this->parametri()['conv_rinnovo'] ?? '') === '1';
    }
}
