<?php

declare(strict_types=1);

namespace App\Iscritti;

/** Attestati di partecipazione (Step 9 parte C, oggi procedurali): invii dopo la presenza o a fine attività. */
interface Attestati
{
    /** Presenza registrata: se l'attività è conclusa parte l'email con l'attestato, come invia_email_attestato_se_concluso(). */
    public function inviaSeConcluso(int $prenotazioneId): bool;

    /** Attestati degli studenti di una classe al docente; true o il motivo del mancato invio, come invia_attestati_gruppo(). */
    public function inviaGruppo(int $prenotazioneId, bool $forza = false): bool|string;

    /** 'evento' | 'no' | 'attendi' | 'gruppo' | 'singolo': come si comporta l'attestato dell'evento, come regola_attestato_evento(). */
    public function regolaEvento(int $eventoId): string;
}
