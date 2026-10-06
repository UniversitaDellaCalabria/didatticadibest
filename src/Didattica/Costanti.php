<?php

declare(strict_types=1);

namespace App\Didattica;

/** Valori fissi della Didattica: stati delle pratiche, tipi di campo e di colonna, compiti degli uffici, iter e cartelle. */
final class Costanti
{
    /** Stato della pratica => [nome, colore, icona]. */
    public const STATI_PRATICA = [
        'inviata'        => ['Inviata', '#0056B3', 'fa-paper-plane'],
        'in_lavorazione' => ['In lavorazione', '#7c3aed', 'fa-gears'],
        'integrazione'   => ['Integrazione richiesta', '#b45309', 'fa-circle-exclamation'],
        'accolta'        => ['Accolta', '#15803d', 'fa-circle-check'],
        'respinta'       => ['Respinta', '#b91c1c', 'fa-circle-xmark'],
        'chiusa'         => ['Chiusa', '#475569', 'fa-box-archive'],
    ];

    public const TIPI_CAMPO_PRATICA = [
        'text' => 'Testo breve', 'textarea' => 'Testo lungo', 'email' => 'Email', 'tel' => 'Telefono', 'number' => 'Numero', 'date' => 'Data', 'time' => 'Ora',
        'url' => 'Indirizzo web', 'codice_fiscale' => 'Codice fiscale',
        'select' => 'Tendina', 'radio' => 'Scelta singola', 'multicheck' => 'Scelta multipla (caselle)', 'checkbox' => 'Casella (sì/no)', 'dichiarazione' => 'Dichiarazione da accettare',
        'file' => 'Allegato (PDF o immagine)',
        'corso_studio' => 'Corso di studio (anagrafe)', 'insegnamento' => 'Insegnamento del Dipartimento (anagrafe)', 'insegnamento_ateneo' => 'Insegnamento di Ateneo (tipo, corso, a.a. di offerta)',
        'docente' => 'Docente (anagrafe)', 'anno_accademico' => 'Anno accademico', 'tabella' => 'Tabella a righe (es. esami)',
        'titolo' => 'Titolo di sezione (solo testo)', 'info' => 'Testo informativo (solo testo)',
    ];

    /** Gruppi dei tipi nel costruttore dei moduli. */
    public const GRUPPI_TIPI_CAMPO = [
        'Campi di base' => ['text', 'textarea', 'email', 'tel', 'number', 'date', 'time', 'url', 'codice_fiscale', 'file'],
        'Scelte' => ['select', 'radio', 'multicheck', 'checkbox', 'dichiarazione'],
        'Dalle anagrafi' => ['corso_studio', 'insegnamento', 'insegnamento_ateneo', 'docente', 'anno_accademico'],
        'Tabelle' => ['tabella'],
        'Impaginazione' => ['titolo', 'info'],
    ];

    /** Tipi delle colonne delle tabelle a righe ("Nome:tipo" nelle opzioni; senza tipo si riconosce dal nome). */
    public const TIPI_COLONNA_TABELLA = [
        'testo' => 'Testo', 'insegnamento' => 'Insegnamento (catalogo di Ateneo)', 'insegnamento_dip' => 'Insegnamento del Dipartimento', 'cfu' => 'CFU',
        'voto' => 'Voto', 'ssd' => 'S.S.D.', 'data' => 'Data', 'numero' => 'Numero', 'docente' => 'Docente (anagrafe)', 'anno_accademico' => 'Anno accademico', 'scelta' => 'Tendina',
        'codice' => 'Codice insegnamento', 'denominazione' => 'Insegnamento scritto a mano (altro Ateneo)',
        'piano' => 'Piano di studi: da inserire come a scelta (con l\'eventuale insegnamento da eliminare)',
    ];

    /** Condizioni della logica dei campi: "mostra solo se" e "compila in automatico se". */
    public const OPERATORI_CONDIZIONE = [
        'uguale' => 'è uguale a', 'diverso' => 'è diverso da', 'contiene' => 'contiene', 'compilato' => 'è compilato', 'vuoto' => 'è vuoto',
    ];

    /** Tipi che non chiedono nulla (solo testo nel modulo). */
    public const TIPI_SOLO_TESTO = ['titolo', 'info'];

    public const DESTINATARI_MODULO = [
        'tutti' => 'Chiunque abbia fatto l\'accesso', 'studenti' => 'Studenti', 'docenti' => 'Docenti', 'personale' => 'Docenti e personale di Ateneo',
    ];

    public const COMPITI_UFFICIO = [
        'pratiche' => 'Pratiche e modulistica', 'sedute' => 'Sedute e verbali', 'ricevimento' => 'Ricevimento studenti', 'bandi' => 'Bandi',
    ];

    /** Gli allegati delle pratiche stanno in una cartella bloccata al web: si scaricano solo da allegato_pratica.php. */
    public const DIR_PRATICHE = 'uploads/pratiche/';
    public const DIR_MODULISTICA = 'uploads/modulistica/';
    public const LOGO_VERBALE = 'assets/modelli/logo_verbale_dibest.jpg';

    /** Passi dell'iter risolti con la pratica: ufficio del corso di studio e segreteria studenti. */
    public const ITER_CDL = -1;
    public const ITER_SEGRETERIA = -2;

    /** Uffici con un tipo (didattica_uffici.tipo). */
    public const TIPI_UFFICIO = [
        ''           => 'Ufficio didattico',
        'protocollo' => 'Protocollo (riceve le domande da protocollare)',
        'cdl'        => 'Ufficio di corso di studio (riceve le pratiche dei corsi del consiglio)',
        'segreteria' => 'Segreteria studenti (riceve le pratiche lavorate con il verbale)',
    ];

    /** Nomi brevi degli uffici dei cinque consigli di partenza (per ordine del consiglio); per gli altri «Ufficio del <consiglio>». */
    public const NOMI_UFFICI_CONSIGLI = [
        1 => 'Ufficio CdS Scienze Naturali e Ambientali – Biodiversità e Conservazione',
        2 => 'Ufficio CdS Scienze Geologiche',
        3 => 'Ufficio CdS Scienze Motorie e Sportive',
        4 => 'Ufficio CdS Biologia, Scienze e Tecnologie Biologiche, Health Biotechnology',
        5 => 'Ufficio CdS Conservazione e Restauro dei Beni Culturali',
    ];

    public const TESTO_DICHIARAZIONE_DPR = 'Il/La sottoscritto/a, ai sensi degli artt. 46 e 47 del D.P.R. 28 dicembre 2000, n. 445, consapevole delle sanzioni penali previste dall\'art. 76 del medesimo decreto per le ipotesi di falsità in atti e dichiarazioni mendaci, nonché di quanto previsto dagli artt. 483, 495 e 640 del Codice penale, dichiara che le informazioni riportate nella presente domanda, compresi gli esami indicati con le relative date, i crediti e le votazioni, corrispondono al vero. Dichiara altresì di essere a conoscenza che l\'Amministrazione effettuerà controlli sulla veridicità delle dichiarazioni rese e che, in caso di dichiarazioni non veritiere, decadrà dai benefici eventualmente conseguiti (art. 75 del D.P.R. 445/2000).';

    // ------------------------------------------------------------------ consigli e sedute (usati anche dalle convalide)

    public const STATI_PRESENZA = ['P' => 'Presente', 'AG' => 'Assente giustificato', 'AI' => 'Assente ingiustificato'];

    public const QUALIFICHE_CONSIGLIO = ['Professori ordinari', 'Professori associati', 'Ricercatori', 'Docenti a contratto', 'Rappresentanti degli studenti', 'Personale tecnico-amministrativo'];

    /** Esito della pratica in seduta => [nome, colore]. */
    public const ESITI_SEDUTA = [
        'approvata' => ['Approvata', '#15803d'], 'approvata_mod' => ['Approvata con modifiche', '#0f766e'], 'respinta' => ['Respinta', '#b91c1c'], 'rinviata' => ['Rinviata', '#b45309'],
    ];

    public const DECISIONI_SEDUTA = ['' => 'Solo esito e delibera', 'convalide' => 'Convalida degli esami (totale o parziale)', 'piano' => 'Piano di studi (in piano / fuori piano)'];

    public const ESITI_CONVALIDA = ['totale' => 'Convalida totale', 'parziale' => 'Convalida parziale', 'no' => 'Non convalidato'];

    public const ESITI_PIANO = ['in_piano' => 'Approvato in piano', 'fuori_piano' => 'Approvato fuori piano', 'no' => 'Non approvato'];

    // ------------------------------------------------------------------ verbali e convocazioni

    /** I verbali stanno in una cartella bloccata al web: si scaricano solo dal pannello o con il link personale. */
    public const DIR_VERBALI = 'uploads/verbali/';

    /** Stato del verbale => [nome, colore]. */
    public const STATI_VERBALE = [
        ''             => ['Non inviato alla firma', '#64748b'],
        'segretario'   => ['Da firmare: segretario verbalizzante', '#b45309'],
        'coordinatore' => ['Da firmare: coordinatore', '#7c3aed'],
        'firmato'      => ['Firmato in PAdES', '#15803d'],
    ];

    /** Testo proposto per la convocazione: segnaposti {NOME} {ORGANO} {DATA} {ORA} {LUOGO} {ODG} {COORDINATORE} {LINK_GIUSTIFICA}. */
    public const TESTO_CONVOCAZIONE = "Gentile {NOME},\n\nè convocata la seduta del {ORGANO} per il giorno {DATA} alle ore {ORA}, presso {LUOGO}, con il seguente ordine del giorno:\n\n{ODG}\n\nIn caso di impedimento può giustificare l'assenza da qui: {LINK_GIUSTIFICA}\n\nCordiali saluti,\n{COORDINATORE}";
}
