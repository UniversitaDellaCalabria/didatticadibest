<?php

declare(strict_types=1);

namespace App\Attestati;

/** Come si comporta l'attestato per l'evento di una prenotazione (valori usati da regola_attestato_evento()). */
enum RegolaAttestato: string
{
    /** Evento normale: attestato personale dopo il check-in. */
    case Evento = 'evento';
    /** Progetto senza attestati. */
    case No = 'no';
    /** Progetto non ancora concluso (data di fine futura). */
    case Attendi = 'attendi';
    /** Progetto per le scuole o evento con attestati per la classe: un attestato per ogni studente dell'elenco. */
    case Gruppo = 'gruppo';
    /** Progetto generico concluso: attestato alla persona iscritta. */
    case Singolo = 'singolo';
}
