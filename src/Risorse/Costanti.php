<?php

declare(strict_types=1);

namespace App\Risorse;

/**
 * Tipi di risorsa, chi può prenotare, giorni e colori della vista a calendario. Restano costanti globali
 * (TIPI_RISORSA, ACCESSI_RISORSA, GIORNI_SETTIMANA, LEGENDA_CALENDARIO_RISORSE, definite in inc/) perché le usano anche le pagine.
 */
final class Costanti
{
    public const TIPI = [
        'aula' => ['Aula', 'fa-chalkboard'], 'laboratorio' => ['Laboratorio', 'fa-flask'],
        'sportello' => ['Sportello / appuntamento', 'fa-user-clock'], 'altro' => ['Altra risorsa', 'fa-cube'],
    ];
    public const ACCESSI = [
        'tutti' => 'Chiunque abbia fatto l\'accesso', 'studenti' => 'Studenti', 'docenti' => 'Docenti',
        'personale' => 'Docenti e personale di Ateneo',
    ];
    public const GIORNI = [1 => 'Lunedì', 2 => 'Martedì', 3 => 'Mercoledì', 4 => 'Giovedì', 5 => 'Venerdì', 6 => 'Sabato', 7 => 'Domenica'];
    public const LEGENDA_CALENDARIO = [
        'libero' => ['Prenotabile', '#ffffff', '#334155'],
        'chiuso' => ['Non prenotabile', '#dc2626', '#ffffff'],
        'occupato' => ['Prenotato', '#b07d3e', '#ffffff'],
        'mia' => ['Mie prenotazioni', '#16a34a', '#ffffff'],
        'sospesa' => ['In sospeso', '#f97316', '#ffffff'],
        'passato' => ['Passato', '#cbd5e1', '#334155'],
    ];
}
