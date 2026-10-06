<?php

declare(strict_types=1);

namespace App\Tutorato;

/** Valori fissi del Tutorato: stati della lettera di incarico, del registro e della fine attività, cartelle e modelli. */
final class Costanti
{
    /** Stato della lettera => [nome, colore, icona]. */
    public const STATI_INCARICO = [
        'bozza' => ['Bozza', '#64748b', 'fa-pen'],
        'inviata' => ['Allo studente per la conferma', '#0056B3', 'fa-paper-plane'],
        'confermata' => ['Al docente per la firma', '#7c3aed', 'fa-user-check'],
        'firmata_docente' => ['Al direttore per la firma', '#b45309', 'fa-file-signature'],
        'firmata' => ['Firmata: da protocollare', '#15803d', 'fa-circle-check'],
        'protocollata' => ['Protocollata', '#334155', 'fa-box-archive'],
        'annullata' => ['Annullata', '#b91c1c', 'fa-ban'],
    ];

    /** Stato di una riga del registro => [nome, colore]. */
    public const STATI_REGISTRO = ['inviata' => ['Da approvare', '#b45309'], 'approvata' => ['Approvata', '#15803d'], 'respinta' => ['Respinta', '#b91c1c']];

    /** Stato della fine attività => [nome, colore]. */
    public const STATI_FINE_ATTIVITA = [
        '' => ['In corso', '#0056B3'],
        'richiesta' => ['Il tutor ha dichiarato concluse le attività: conferma del docente', '#7c3aed'],
        'da_firmare' => ['Dichiarazione di fine attività da firmare (docente)', '#b45309'],
        'firmata' => ['Attività completate: dichiarazione firmata, da protocollare', '#15803d'],
        'protocollata' => ['Attività completate e protocollate', '#334155'],
    ];

    /** I PDF stanno in una cartella bloccata al web: si scaricano solo dal pannello e dalle pagine con il link personale. */
    public const DIR_INCARICHI = 'uploads/incarichi/';
    public const MODELLO_LETTERA = 'modelli_documenti/lettera_incarico_tutorato.docx';
    public const LOGO_LETTERA = 'assets/modelli/logo_lettera_incarico.jpg';

    /** Come lo studente si è autenticato (per il riquadro nel PDF e nei testi). */
    public const METODI_ACCESSO = ['spid' => 'SPID', 'cie' => 'CIE (Carta d\'Identità Elettronica)', 'ateneo' => 'credenziali di Ateneo (Unical ID)'];

    /** Ruoli con un link personale (colonna token_<ruolo>): chi firma o conferma la lettera, e il docente per la fine attività. */
    public const RUOLI_TOKEN = ['studente', 'docente', 'direttore', 'fine'];

    /** Titoli ammessi del docente responsabile. */
    public const TITOLI_DOCENTE = ['Prof.', 'Prof.ssa', 'Dott.', 'Dott.ssa'];
}
