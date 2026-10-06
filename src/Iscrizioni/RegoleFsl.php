<?php

declare(strict_types=1);

namespace App\Iscrizioni;

/** Regole delle prenotazioni di classe e delle convenzioni con le scuole (modulo Formazione Scuola Lavoro, App\Fsl\RegoleIscrizioniFsl). */
interface RegoleFsl
{
    /**
     * Prenotazione fatta da un docente per una classe: si chiede il numero di studenti (con minimo e massimo).
     *
     * @param array<string, mixed>|null $dettagli riga di progetti_dettagli (null se assente)
     */
    public function prenotazioneDiClasse(bool $eProgetto, ?array $dettagli): bool;

    /**
     * Attestati per ogni studente dell'elenco inserito da chi ha prenotato.
     *
     * @param array<string, mixed> $p riga con evento_tipo (o tipo), per_scuole e attestati
     */
    public function attestatiDiClasse(array $p): bool;

    /**
     * Il campo «numero di partecipanti» vale solo per le prenotazioni di classe: altrove non va mostrato né richiesto.
     *
     * @param array<string, mixed> $campo riga di campi_form
     * @param array<string, mixed>|null $dettagli
     */
    public function campoFormVisibile(array $campo, bool $eProgetto, ?array $dettagli): bool;

    /**
     * Cosa fare quando la scuola non ha ancora la convenzione (pagina, email, Area personale).
     *
     * @param array<string, mixed> $areaCfg riga di pagine_eventi dell'area
     */
    public function istruzioniConvenzione(array $areaCfg, bool $perEmail = false, string $codice = '', bool $inAttesa = true): string;

    /**
     * Periodo da coprire con la convenzione: progetto dal/al, evento il giorno del turno.
     *
     * @return array{0: string, 1: string}
     */
    public function periodoAttivita(?string $inizio, ?string $fine, ?string $dataTurno = null): array;

    /**
     * Convenzione del registro valida per tutto il periodo dal-al, null se non c'è.
     *
     * @return array<string, mixed>|null
     */
    public function convenzioneValida(?string $codiceScuola, ?string $dal = null, ?string $al = null): ?array;

    /**
     * Tutte le convenzioni della scuola nel registro, dalla più recente.
     *
     * @return list<array<string, mixed>>
     */
    public function convenzioniDellaScuola(?string $codiceScuola): array;
}
