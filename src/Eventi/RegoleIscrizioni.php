<?php

declare(strict_types=1);

namespace App\Eventi;

/** Regole delle iscrizioni (modulo Iscrizioni) usate dagli eventi e dai progetti: posti occupati, limiti, liste d'attesa. */
interface RegoleIscrizioni
{
    /** Posti occupati di un turno (confermate, da confermare, da approvare), come getPostiOccupati(). */
    public function postiOccupati(int $turnoId): int;

    /**
     * Limiti di partecipanti di un'edizione (quelli del turno, altrimenti del progetto), come limiti_partecipanti().
     *
     * @param array<string, mixed>|null $dettagli
     * @param array<string, mixed>|null $turno
     * @return array{min: int, max: int|null}
     */
    public function limitiPartecipanti(?array $dettagli, ?array $turno = null): array;

    /** Dopo la conferma di una prenotazione: fa decadere le attese incompatibili (vincolo dell'area), come decadi_attese_vincolate(). */
    public function decadiAtteseVincolate(int $prenotazioneId): int;
}
