<?php

declare(strict_types=1);

namespace App\Eventi;

/** Aule e laboratori di Prenotazioni e risorse occupati dai turni degli eventi (modulo Risorse). */
interface AuleTurni
{
    /**
     * Allinea la prenotazione dell'aula al turno; $avvisi riceve i problemi da mostrare a chi salva.
     *
     * @param list<string> $avvisi
     */
    public function sincronizza(int $turnoId, array &$avvisi, int $utenteId = 0): void;
}
