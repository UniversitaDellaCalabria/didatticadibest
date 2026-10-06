<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Fsl\Vista\Istruzioni;
use App\Iscrizioni\RegoleFsl;

/** Le regole della Formazione Scuola Lavoro per il modulo Iscrizioni: prenotazioni di classe, istruzioni e periodi della convenzione, registro. */
final class RegoleIscrizioniFsl implements RegoleFsl
{
    public function __construct(
        private RegoleClasse $classi,
        private Istruzioni $istruzioni,
        private PeriodiConvenzione $periodi,
        private ServizioConvenzioni $convenzioni
    ) {
    }

    public function prenotazioneDiClasse(bool $eProgetto, ?array $dettagli): bool
    {
        return $this->classi->prenotazioneDiClasse($eProgetto, $dettagli);
    }

    public function attestatiDiClasse(array $p): bool
    {
        return $this->classi->attestatiDiClasse($p);
    }

    public function campoFormVisibile(array $campo, bool $eProgetto, ?array $dettagli): bool
    {
        return $this->classi->campoFormVisibile($campo, $eProgetto, $dettagli);
    }

    public function istruzioniConvenzione(array $areaCfg, bool $perEmail = false, string $codice = '', bool $inAttesa = true): string
    {
        return $this->istruzioni->html($areaCfg, $perEmail, $codice, $inAttesa);
    }

    public function periodoAttivita(?string $inizio, ?string $fine, ?string $dataTurno = null): array
    {
        return $this->periodi->attivita($inizio, $fine, $dataTurno);
    }

    public function convenzioneValida(?string $codiceScuola, ?string $dal = null, ?string $al = null): ?array
    {
        return $this->convenzioni->valida($codiceScuola, false, $dal, $al);
    }

    public function convenzioniDellaScuola(?string $codiceScuola): array
    {
        return $this->convenzioni->dellaScuola($codiceScuola);
    }
}
