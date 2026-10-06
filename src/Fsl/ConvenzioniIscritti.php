<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Iscritti\Convenzioni;

/** Le convenzioni con le scuole per il modulo Iscritti: richieste, ricevute, verifica delle iscrizioni FSL e inviti alla scheda di valutazione. */
final class ConvenzioniIscritti implements Convenzioni
{
    public function __construct(
        private ServizioConvenzioni $convenzioni,
        private PeriodiConvenzione $periodi,
        private ServizioValutazioniFsl $valutazioni
    ) {
    }

    public function periodoPrenotazione(array $prenotazione): array
    {
        return $this->periodi->prenotazione($prenotazione);
    }

    public function convenzioneValida(?string $codiceScuola, bool $rileggi = false, ?string $dal = null, ?string $al = null): ?array
    {
        return $this->convenzioni->valida($codiceScuola, $rileggi, $dal, $al);
    }

    public function richiedi(int $prenotazioneId, string $tipo = 'richiesta'): bool
    {
        return $this->convenzioni->richiedi($prenotazioneId, $tipo);
    }

    public function segnaRicevuta(int $prenotazioneId, bool $email = true): bool
    {
        return $this->convenzioni->segnaRicevuta($prenotazioneId, $email);
    }

    public function ricevutaDaGestore(int $prenotazioneId, string $autore = ''): bool
    {
        return $this->convenzioni->ricevutaDaGestore($prenotazioneId, $autore);
    }

    public function verificaFsl(): array
    {
        return $this->convenzioni->verifica();
    }

    public function invitoValutazione(int $prenotazioneId, bool $promemoria = false): bool
    {
        return $this->valutazioni->invia($prenotazioneId, $promemoria);
    }
}
