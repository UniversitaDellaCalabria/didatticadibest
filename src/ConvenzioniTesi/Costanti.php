<?php

declare(strict_types=1);

namespace App\ConvenzioniTesi;

/** Valori fissi delle convenzioni per la tesi presso enti e aziende: stati dell'iter, cartelle, durata e titoli delle persone. */
final class Costanti
{
    /** File firmati dall'azienda e dal Direttore (cartelle bloccate al web: si scaricano solo dal pannello o dalla pagina con il link personale). */
    public const DIR_FIRMATE = 'uploads/convenzioni_tesi/firmate/';
    public const DIR_DEFINITIVE = 'uploads/convenzioni_tesi/definitive/';
    public const MAX_FILE = 10 * 1024 * 1024;

    /** Il modello del Dipartimento (Word) nella cartella dei modelli. */
    public const MODELLO = 'modelli_documenti/convenzione_tesi.docx';

    /** Durata: l'art. 10 del modello dice 5 anni dalla data di decorrenza. */
    public const DURATA_ANNI = 5;

    // Stati dell'iter
    public const INVITATA = 'invitata';              // il link è partito, l'azienda non ha ancora compilato
    public const COMPILATA = 'compilata';            // dati inseriti: l'azienda scarica la convenzione, la firma e la ricarica
    public const MODIFICHE = 'modifiche';            // l'azienda ha proposto modifiche agli articoli: l'ufficio le valuta (l'azienda non può firmare)
    public const RISPOSTA = 'risposta';              // l'ufficio ha risposto alle modifiche: l'azienda le vede e le conferma o le cambia
    public const CARICATA = 'caricata';              // convenzione firmata dall'azienda: da protocollare in entrata
    public const IN_CARICO = 'in_carico';            // protocollo in entrata registrato, il Direttore non è stato ancora raggiunto
    public const DA_FIRMARE = 'da_firmare';          // dal Direttore per la firma
    public const FIRMATA = 'firmata_direttore';      // firmata dal Direttore: da protocollare in uscita
    public const RIFIUTATA = 'rifiutata';            // l'ufficio ha rifiutato il file dell'azienda (con motivo): ne carica un altro
    public const PROTOCOLLATA = 'protocollata';      // stipulata: in archivio

    /** Stati in cui le persone dell'ufficio hanno qualcosa da fare. */
    public const DA_FARE_UFFICIO = [self::MODIFICHE, self::CARICATA, self::IN_CARICO, self::FIRMATA];

    public const ORIGINE_INVITO = 'invito';
    public const ORIGINE_PUBBLICA = 'pubblica';
    public const ORIGINE_ARCHIVIO = 'archivio';

    /** Come si presentano gli stati nel pannello: [etichetta, colore bootstrap]. */
    public const ETICHETTE = [
        self::INVITATA => ['Invitata: attende i dati', 'secondary'],
        self::COMPILATA => ['Compilata: attende il PDF firmato', 'secondary'],
        self::MODIFICHE => ['Modifiche da valutare', 'danger'],
        self::RISPOSTA => ['Risposta data: attende l\'azienda', 'info'],
        self::CARICATA => ['Da prendere in carico', 'danger'],
        self::IN_CARICO => ['Da mandare al Direttore', 'danger'],
        self::DA_FIRMARE => ['Dal Direttore per la firma', 'warning'],
        self::FIRMATA => ['Firmata: da protocollare', 'success'],
        self::RIFIUTATA => ['Rifiutata: attende un nuovo file', 'secondary'],
        self::PROTOCOLLATA => ['Stipulata', 'success'],
    ];

    /** Forma di rivolgersi al rappresentante dell'azienda nel testo («rappresentato/a dal/la …»). */
    public const TITOLI = ['Dott.' => 'Dott.', 'Dott.ssa' => 'Dott.ssa', 'Prof.' => 'Prof.', 'Prof.ssa' => 'Prof.ssa', 'Ing.' => 'Ing.', 'Avv.' => 'Avv.', 'Sig.' => 'Sig.', 'Sig.ra' => 'Sig.ra'];
}
