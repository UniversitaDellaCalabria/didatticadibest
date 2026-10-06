<?php

declare(strict_types=1);

namespace App\Anagrafi;

use App\Eventi\Anagrafe;

/** Le anagrafi di Ateneo per le schede degli eventi: personale, insegnamenti, corsi di studio e campi dei progetti. */
final class AnagrafePerEventi implements Anagrafe
{
    public function __construct(private ServizioPersone $persone, private ServizioCorsi $corsi, private CampiProgettoRepository $campiProgetto)
    {
    }

    public function persona(string $id): ?array
    {
        return $this->persone->persona($id);
    }

    public function completaPersona(array $persona): void
    {
        $this->persone->dettaglio($persona);
    }

    public function insegnamento(int $id): ?array
    {
        return $this->corsi->insegnamento($id);
    }

    public function corsoStudio(string $codice): ?array
    {
        return $this->corsi->corso($codice);
    }

    public function assicuraCampiProgetto(int $paginaId): void
    {
        $this->campiProgetto->assicuraPartecipanti($paginaId, CAMPO_PARTECIPANTI);
    }
}
