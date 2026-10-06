<?php

declare(strict_types=1);

namespace App\Iscritti;

/**
 * Compiti pianificati di altri moduli che girano insieme ai cron degli iscritti (didattica, tutorato, sedute):
 * li fornisce App\Didattica\PianificatiDidattica. Ogni metodo ritorna le email inviate
 * (le conservazioni, quanti dati hanno tolto).
 */
interface Pianificati
{
    /** Promemoria delle pratiche ferme (Didattica). */
    public function praticheFerme(): int;

    /** Promemoria del registro e solleciti delle firme (Tutorato). */
    public function tutorato(): int;

    /** Solleciti delle firme dei verbali (Sedute). */
    public function sollecitiVerbali(): int;

    /** Conservazione dei dati del tutorato: lettere vecchie senza dati personali (null se il modulo non è presente). */
    public function conservaTutorato(int $mesi): ?int;

    /** Conservazione delle convocazioni delle sedute (null se il modulo non è presente). */
    public function conservaSedute(int $mesi): ?int;
}
