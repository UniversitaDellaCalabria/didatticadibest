<?php

declare(strict_types=1);

namespace App\Fsl;

/** Valori fissi della Formazione Scuola Lavoro: modelli e PEC della convenzione, segnaposto dei documenti, aspetti della scheda di valutazione. */
final class Costanti
{
    /** Modelli (scaricati dal portale) e PEC predefiniti, sostituibili per ogni area in Impostazioni area. */
    public const URL_MODELLO = 'assets/modelli/Convenzione_FSL_DiBEST.doc';
    public const URL_ALLEGATO = 'assets/modelli/Allegato_A_FSL_DiBEST.doc';
    public const PEC = 'dipartimento.best@pec.unical.it';

    /** Durata proposta: il modello del Dipartimento vale un anno dalla stipula (art. 8). */
    public const DURATA_ANNI = 1;

    /** Testo originale del modello per i segnaposto lasciati vuoti (resta evidenziato in giallo da completare). */
    public const SEGNAPOSTI = [
        'ISTITUTO' => 'Denominazione Istituzione Scolastica', 'COMUNE' => 'xxxx', 'INDIRIZZO' => 'xxx', 'ISTITUTO_FIRMA' => '…………………………',
        'CF_ISTITUTO' => 'xxxxxx', 'DIRIGENTE' => 'Dott./Dott.ssa xxxxxx XXXX', 'DIR_LUOGO_NASCITA' => 'xxxx', 'DIR_DATA_NASCITA' => 'xx/xx/xxxx',
        'DIR_CF' => 'XXXXXXXXXXXXXXXX', 'DIRIGENTE_FIRMA' => 'Dott./Dott.ssa………………..',
        'TITOLO' => '……………………', 'DESCRIZIONE' => '…………………………………', 'STUDENTI' => '……………', 'PERIODO' => '…', 'DURATA' => '……',
        'TUTOR_DIBEST' => 'Prof./Prof.ssa ___________________', 'TUTOR_SCUOLA' => '___________________',
    ];

    /** Voti da 1 a 5 della scheda di valutazione della struttura ospitante (convenzione, art. 3). */
    public const ASPETTI = [
        'accoglienza' => 'Accoglienza e organizzazione delle attività',
        'coerenza' => 'Coerenza delle attività con il percorso concordato (Allegato A)',
        'tutor' => 'Disponibilità e competenza del tutor del Dipartimento',
        'spazi' => 'Adeguatezza di spazi, laboratori e attrezzature',
        'sicurezza' => 'Informazione e formazione sulla salute e sicurezza',
        'coinvolgimento' => 'Coinvolgimento e interesse degli studenti',
        'competenze' => 'Competenze acquisite dagli studenti',
        'orientamento' => "Utilità per l'orientamento degli studenti",
    ];

    /** Domande aperte della scheda di valutazione. */
    public const APERTE = [
        'punti_forza' => "Punti di forza dell'esperienza",
        'criticita' => 'Criticità riscontrate',
        'suggerimenti' => 'Suggerimenti per le prossime edizioni',
    ];

    /** Stati delle prenotazioni a cui si chiede ancora la convenzione (in attesa, da approvare o già confermate). */
    public const STATI_ATTIVI = ['confermata', 'da_approvare', 'in_attesa', 'richiesta_conferma'];
}
