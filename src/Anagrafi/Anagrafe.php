<?php

declare(strict_types=1);

namespace App\Anagrafi;

use DateTimeInterface;

/**
 * Costanti delle anagrafi di Ateneo (personale, corsi, catalogo). In inc/anagrafi.php e inc/catalogo_ateneo.php
 * restano le costanti globali di prima (GRUPPI_PERSONALE, RUOLI_DOCENTI…) definite con questi valori.
 */
final class Anagrafe
{
    /** API pubbliche del portale di Ateneo */
    public const API_UNICAL = 'https://storage.portale.unical.it/api/ricerca/';

    /** Gruppi automatici del personale (chiave in personale_ateneo.gruppo => nome del gruppo nella tabella ruoli) */
    public const GRUPPI_PERSONALE = ['docenti' => 'Docenti', 'pta' => 'Personale tecnico amministrativo', 'altro' => 'Altro personale di Ateneo'];

    /** Codici ruolo dell'Ateneo (API roles): docenti e ricercatori */
    public const RUOLI_DOCENTI = ['PO', 'PA', 'RU', 'RD', 'RM', 'PD', 'PF', 'SC', 'AS'];

    /** Codici ruolo dell'Ateneo: personale tecnico amministrativo e dirigenti */
    public const RUOLI_PTA = ['ND', 'NM', 'NT', 'NC', 'D0', 'DC', 'D6', 'NG', 'OA', 'NB'];

    /** Contratti di docenza (autonomi, professionisti, incaricati…): "Docenti" solo se nell'elenco dei docenti del dipartimento */
    public const RUOLI_CONTRATTI = ['AU', 'PR', 'IE', 'II', 'BG', 'CB', 'CC', 'PE', 'LC', 'LS', 'CL'];

    /** Campi della scheda di Ateneo che la persona può modificare dall'Area personale */
    public const CAMPI_SCHEDA_PERSONA = [
        'telefono' => 'Telefono', 'ufficio' => 'Ufficio', 'ricevimento' => 'Orari di ricevimento', 'bio' => 'Profilo', 'sito' => 'Sito web',
    ];

    /** Tipi di corso proposti nel catalogo di Ateneo */
    public const TIPI_CORSO_ATENEO = [
        'L' => 'Laurea (triennale)', 'LM' => 'Laurea magistrale', 'LM5' => 'Laurea magistrale a ciclo unico (5 anni)', 'LM6' => 'Laurea magistrale a ciclo unico (6 anni)',
    ];

    /** Anno accademico in corso come lo usano le API (2026 = 2026/2027): da settembre quello nuovo */
    public static function annoAccademico(DateTimeInterface $data): int
    {
        $anno = (int) $data->format('Y');

        return (int) $data->format('n') >= 9 ? $anno : $anno - 1;
    }
}
