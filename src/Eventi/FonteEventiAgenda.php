<?php

declare(strict_types=1);

namespace App\Eventi;

/**
 * Eventi in programma delle aree visibili (quelli dell'agenda pubblica), come righe con: id, titolo, url, ambiti,
 * per_scuole, prossima_data, prossimo_orario, luogo, relatore…
 */
interface FonteEventiAgenda
{
    /** @return list<array<string, mixed>> */
    public function eventiInProgramma(): array;
}
