<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Attestati\RegoleClasse as RegoleClasseAttestati;
use App\Core\Orologio;

/** Quando una prenotazione è «di classe» (numero di studenti, elenco, attestati) e quando l'attività della classe è conclusa. */
final class RegoleClasse implements RegoleClasseAttestati
{
    public function __construct(private Orologio $orologio)
    {
    }

    /**
     * Prenotazione fatta da un docente per una classe/gruppo: si chiede il numero di studenti (con min/max) e il docente può
     * inserire l'elenco. Progetti: quelli dedicati alle scuole. Eventi: «Dedicato alle scuole», «Attività di Formazione Scuola
     * Lavoro» o «Attestati per gli studenti della classe». $dett = riga di progetti_dettagli (null se assente).
     *
     * @param array<string, mixed>|null $dett
     */
    public function prenotazioneDiClasse(bool $eProgetto, ?array $dett): bool
    {
        if ($eProgetto) {
            return (int) ($dett['per_scuole'] ?? 1) === 1;
        }

        return (int) ($dett['attestati'] ?? 0) === 1 || (int) ($dett['dedicata_scuole'] ?? 0) === 1 || (int) ($dett['convenzione'] ?? 0) === 1;
    }

    /**
     * Attestati per ogni studente dell'elenco inserito da chi ha prenotato.
     *
     * @param array<string, mixed> $p riga con evento_tipo (o tipo), per_scuole e attestati (es. prenotazione per gli attestati)
     */
    public function attestatiDiClasse(array $p): bool
    {
        $eProgetto = ($p['evento_tipo'] ?? $p['tipo'] ?? 'evento') === 'progetto';

        return (int) ($p['attestati'] ?? 0) === 1 && $this->prenotazioneDiClasse($eProgetto, $p);
    }

    /**
     * Quando si possono emettere gli attestati della classe: progetti dopo la data di fine, eventi dopo il giorno del turno
     * (turno senza data: subito, cioè dopo il check-in).
     *
     * @param array<string, mixed> $p
     */
    public function attivitaConclusaClasse(array $p): bool
    {
        $oggi = $this->orologio->adesso()->format('Y-m-d');
        if (($p['evento_tipo'] ?? 'evento') === 'progetto') {
            return !empty($p['data_fine']) && $p['data_fine'] < $oggi;
        }

        return empty($p['data_turno']) || $p['data_turno'] < $oggi;
    }

    /**
     * Il campo «numero di partecipanti» vale solo per le prenotazioni di classe (progetti per le scuole, eventi con attestati
     * per gli studenti): altrove non va mostrato né richiesto.
     *
     * @param array<string, mixed> $campo riga di campi_form
     * @param array<string, mixed>|null $dett riga di progetti_dettagli dell'evento/progetto (null se assente)
     */
    public function campoFormVisibile(array $campo, bool $eProgetto, ?array $dett): bool
    {
        if (($campo['nome_campo'] ?? '') !== CAMPO_PARTECIPANTI) {
            return true;
        }

        return $this->prenotazioneDiClasse($eProgetto, $dett);
    }
}
