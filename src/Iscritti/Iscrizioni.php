<?php

declare(strict_types=1);

namespace App\Iscritti;

/**
 * Regole e operazioni delle iscrizioni (prenotazione pubblica, posti, liste d'attesa: modulo Iscrizioni, Step 9 parte A)
 * usate dal pannello degli iscritti: le fornisce App\Iscrizioni\IscrizioniPerIscritti.
 */
interface Iscrizioni
{
    /** Posti occupati di un turno (confermate, da confermare, da approvare), come getPostiOccupati(). */
    public function postiOccupati(int $turnoId): int;

    /** Dopo la conferma di una prenotazione: fa decadere le attese incompatibili (vincolo dell'area), come decadi_attese_vincolate(). */
    public function decadiAtteseVincolate(int $prenotazioneId): int;

    /** Offre i posti liberi al primo in lista d'attesa del turno (richiesta di conferma entro 24 ore), come promuovi_lista_attesa(). */
    public function promuoviListaAttesa(int $turnoId): void;

    /**
     * Numero di partecipanti dei progetti per le scuole: null se va bene, altrimenti il messaggio di errore (valida_partecipanti_progetto()).
     *
     * @param array<string, mixed> $custom
     * @param array<string, mixed>|null $dettagli
     * @param array<string, mixed>|null $turno
     */
    public function validaPartecipantiProgetto(array $custom, ?array $dettagli, ?array $turno = null): ?string;
}
