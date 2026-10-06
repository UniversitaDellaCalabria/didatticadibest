<?php

declare(strict_types=1);

namespace App\Iscritti;

/** Convenzioni con le scuole della Formazione Scuola Lavoro (modulo FSL, App\Fsl\ConvenzioniIscritti): richieste, ricevute, promemoria. */
interface Convenzioni
{
    /**
     * Periodo dell'attività di una prenotazione (pd_inizio, pd_fine, data_turno), come periodo_prenotazione().
     *
     * @param array<string, mixed> $prenotazione
     * @return array{0: string, 1: string}
     */
    public function periodoPrenotazione(array $prenotazione): array;

    /**
     * Convenzione del registro valida per tutto il periodo (null se manca), come convenzione_valida().
     *
     * @return array<string, mixed>|null
     */
    public function convenzioneValida(?string $codiceScuola, bool $rileggi = false, ?string $dal = null, ?string $al = null): ?array;

    /** Email alla scuola con i modelli e la PEC ($tipo: 'richiesta' | 'promemoria'), come email_richiesta_convenzione(). */
    public function richiedi(int $prenotazioneId, string $tipo = 'richiesta'): bool;

    /** La convenzione risulta ricevuta (e, se serve, la prenotazione si conferma), come segna_convenzione_ricevuta(). */
    public function segnaRicevuta(int $prenotazioneId, bool $email = true): bool;

    /** Un gestore conferma che la convenzione è arrivata: la registra per la scuola, come convenzione_ricevuta_da_gestore(). */
    public function ricevutaDaGestore(int $prenotazioneId, string $autore = ''): bool;

    /**
     * Verifica delle iscrizioni alle attività FSL con il registro delle convenzioni, come verifica_convenzioni_fsl().
     *
     * @return array{coperte: int, da_stipulare: int, nuove_da_stipulare: int, senza_codice: int}
     */
    public function verificaFsl(): array;

    /** Invito (o promemoria) alla scheda di valutazione della struttura ospitante, come invia_invito_valutazione(). */
    public function invitoValutazione(int $prenotazioneId, bool $promemoria = false): bool;
}
