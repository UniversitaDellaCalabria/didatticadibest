<?php

declare(strict_types=1);

namespace App\Portale\Area;

/**
 * I riquadri di sistema dell'area personale: quelli che il portale sa disegnare da solo (con i dati della persona) e le «schede» con un testo e due
 * collegamenti che l'amministratore può cambiare. Qui ci sono i valori di partenza (titolo, profili, ordine); l'amministratore ne cambia da pannello
 * interruttore, profili e ordine, e per le schede anche i testi (tabella area_riquadri).
 *
 * Tipo «speciale»: lo disegna inc/area/<chiave>.php (mostra solo se la persona ha dati: una pratica, un incarico, un ricevimento…).
 * Tipo «scheda»: titolo, testo e collegamenti stanno nella tabella.
 */
final class CatalogoRiquadri
{
    public const SPECIALE = 'speciale';
    public const SCHEDA = 'scheda';

    /** Forma «icona»: una piccola tessera (tre per riga, in alto) per i servizi che si usano spesso; le altre sono tessere grandi. */
    public const ICONA = 'icona';

    private const TUTTI = [Profili::STUDENTE, Profili::DOCENTE, Profili::DIRETTORE, Profili::PERSONALE, Profili::GESTORE, Profili::AMMINISTRATORE, Profili::SCUOLA, Profili::AZIENDA, Profili::ESTERNO];

    /**
     * Chiave => tipo, titolo, descrizione (per il pannello), icona, colore, profili di partenza, ordine, acceso di partenza e, per le schede, testo e collegamenti.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function sistema(): array
    {
        return [
            'da_fare' => ['tipo' => self::SPECIALE, 'titolo' => 'Da fare per te', 'descrizione' => 'Le cose che aspettano la persona nei vari moduli: firme, conferme, registri, pratiche da lavorare', 'icona' => 'fa-list-check', 'colore' => '#b45309',
                'profili' => self::TUTTI, 'ordine' => 10],
            'firme' => ['tipo' => self::SPECIALE, 'titolo' => 'Da firmare', 'descrizione' => 'I documenti che aspettano la tua firma in tutti i moduli (pratiche, convenzioni, lettere di tutorato, verbali), in una scrivania sola; compare solo a chi ha qualcosa da firmare', 'icona' => 'fa-file-signature', 'colore' => '#0f766e',
                'profili' => self::TUTTI, 'ordine' => 12],
            'fsl_scuola' => ['tipo' => self::SPECIALE, 'titolo' => 'La mia scuola: Formazione Scuola Lavoro', 'descrizione' => 'Per le scuole: ogni prenotazione di Formazione Scuola Lavoro con i passi (programma, convenzione, elenco studenti e autorizzazione, attività, attestati) e un solo pulsante per quello che c\'è da fare; compare solo a chi ha prenotazioni FSL', 'icona' => 'fa-school', 'colore' => '#15803d',
                'profili' => [Profili::SCUOLA, Profili::AMMINISTRATORE], 'ordine' => 14],
            'convenzioni_azienda' => ['tipo' => self::SPECIALE, 'titolo' => 'Le convenzioni della tua azienda', 'descrizione' => 'Per le aziende e gli enti: la convenzione per le tesi con i passi (dati, firma e caricamento, protocollo e firma del Direttore, stipulata), il pulsante del passo corrente e il rinnovo; compare solo a chi ha una convenzione legata alla sua email', 'icona' => 'fa-handshake', 'colore' => '#0e7490',
                'profili' => [Profili::AZIENDA, Profili::AMMINISTRATORE], 'ordine' => 16],
            'lavoro' => ['tipo' => self::SPECIALE, 'titolo' => 'Il mio lavoro', 'descrizione' => 'Per chi gestisce qualcosa: una tessera per ogni postazione (pratiche, protocollo, sedute, convenzioni, tutorato, assistenza, aree) con i numeri e i pulsanti sulla vista giusta; compare solo a chi ha almeno una postazione', 'icona' => 'fa-briefcase', 'colore' => '#1e293b',
                'profili' => [Profili::DOCENTE, Profili::DIRETTORE, Profili::PERSONALE, Profili::GESTORE, Profili::AMMINISTRATORE], 'ordine' => 15],
            'cruscotto_direttore' => ['tipo' => self::SPECIALE, 'titolo' => 'Cruscotto del Direttore', 'descrizione' => 'Firme e cose ferme in tutti i moduli', 'icona' => 'fa-gauge-high', 'colore' => '#0f2a4a',
                'profili' => [Profili::DIRETTORE, Profili::AMMINISTRATORE], 'ordine' => 20],
            'direttore_assente' => ['tipo' => self::SPECIALE, 'titolo' => 'Direttore assente', 'descrizione' => 'Passa firme e avvisi al vice direttore (lo attivano Direttore, vice, segreteria di direzione e amministratori)', 'icona' => 'fa-user-clock', 'colore' => '#b45309',
                'profili' => [Profili::DIRETTORE, Profili::PERSONALE, Profili::AMMINISTRATORE], 'ordine' => 30],
            'pratiche' => ['tipo' => self::SPECIALE, 'titolo' => 'Le mie pratiche', 'descrizione' => 'Le richieste fatte con i moduli online, con lo stato di ognuna', 'icona' => 'fa-folder-open', 'colore' => '#047857',
                'profili' => [Profili::STUDENTE, Profili::DOCENTE, Profili::PERSONALE, Profili::AMMINISTRATORE], 'ordine' => 40],
            'tesi_laurea' => ['tipo' => self::SPECIALE, 'titolo' => 'Tesi e laurea', 'descrizione' => 'Per gli studenti: a che punto è la richiesta di tesi, la convenzione dell\'azienda scelta e i passi successivi (cerca un relatore, fai la richiesta, i passi del percorso)', 'icona' => 'fa-graduation-cap', 'colore' => '#7c3aed',
                'profili' => [Profili::STUDENTE, Profili::AMMINISTRATORE], 'ordine' => 42],
            'assistenza' => ['tipo' => self::SPECIALE, 'forma' => self::ICONA, 'titolo' => 'Serve aiuto?', 'descrizione' => 'La chat con Didacticus e con gli operatori, e lo storico delle conversazioni', 'icona' => 'fa-headset', 'colore' => '#B30000',
                'profili' => self::TUTTI, 'ordine' => 50],
            'messaggi' => ['tipo' => self::SPECIALE, 'forma' => self::ICONA, 'titolo' => 'Messaggi', 'descrizione' => 'La casella unica di chi gestisce (aree, convenzioni FSL, assistenza) con i messaggi da leggere; compare solo a chi ha una di queste postazioni', 'icona' => 'fa-envelope', 'colore' => '#1d4ed8',
                'profili' => [Profili::DOCENTE, Profili::DIRETTORE, Profili::PERSONALE, Profili::GESTORE, Profili::AMMINISTRATORE], 'ordine' => 52],
            'tirocini' => ['tipo' => self::SCHEDA, 'titolo' => 'Tirocini', 'descrizione' => 'Guida sui tirocini e collegamento alla piattaforma esterna TSP 2.0 (i tirocini non si gestiscono nel portale)', 'icona' => 'fa-briefcase', 'colore' => '#0e7490',
                'profili' => [Profili::STUDENTE, Profili::DOCENTE, Profili::AZIENDA, Profili::ESTERNO, Profili::AMMINISTRATORE], 'ordine' => 60,
                'testo' => 'I tirocini si gestiscono sulla piattaforma TSP 2.0 di Ateneo, non in questo portale. La guida spiega i passi, dalla proposta alla relazione finale.',
                'link' => 'percorso_tirocini.php', 'etichetta_link' => 'La guida sui tirocini', 'link2' => 'https://unical.tsp.esse3.cineca.it', 'etichetta_link2' => 'Vai alla piattaforma TSP 2.0'],
            'tutorato_mio' => ['tipo' => self::SPECIALE, 'titolo' => 'Il mio tutorato', 'descrizione' => 'Per chi ha un incarico di tutorato (anche dottorandi e personale): a che punto è e i suoi documenti; compare solo a chi ha un incarico', 'icona' => 'fa-user-graduate', 'colore' => '#0f766e',
                'profili' => self::TUTTI, 'ordine' => 70],
            'tutorato_registro' => ['tipo' => self::SPECIALE, 'titolo' => 'Registro delle attività di tutorato', 'descrizione' => 'Per il tutor e per il docente titolare dell\'attività: registro, lettera di incarico e fine attività da firmare', 'icona' => 'fa-clipboard-list', 'colore' => '#0f766e',
                'profili' => self::TUTTI, 'ordine' => 72],
            'tutorato_pagamenti' => ['tipo' => self::SPECIALE, 'titolo' => 'Ufficio pagamenti del tutorato', 'descrizione' => 'Per le persone dell\'ufficio pagamenti: spento finché i pagamenti non passano dal portale', 'icona' => 'fa-euro-sign', 'colore' => '#15803d',
                'profili' => [Profili::PERSONALE, Profili::AMMINISTRATORE], 'ordine' => 74, 'attivo' => 0],
            'disponibilita' => ['tipo' => self::SPECIALE, 'forma' => self::ICONA, 'titolo' => 'La mia disponibilità per tesi e tirocini', 'descrizione' => 'Per i docenti: posti per tesisti e tirocinanti, corsi di laurea, attività e obiettivi; compaiono sulle pagine pubbliche con un semaforo', 'icona' => 'fa-traffic-light', 'colore' => '#0e7490',
                'profili' => [Profili::DOCENTE, Profili::AMMINISTRATORE], 'ordine' => 82],
            'ricevimento' => ['tipo' => self::SPECIALE, 'titolo' => 'Il mio ricevimento', 'descrizione' => 'Giorni, orari, assenze e appuntamenti in programma', 'icona' => 'fa-user-clock', 'colore' => '#7c3aed',
                'profili' => [Profili::DOCENTE, Profili::PERSONALE, Profili::AMMINISTRATORE], 'ordine' => 80],
            'risorse_responsabile' => ['tipo' => self::SPECIALE, 'forma' => self::ICONA, 'titolo' => 'Le mie risorse', 'descrizione' => 'Per i responsabili di aule e laboratori: richieste da approvare e prenotazioni in arrivo', 'icona' => 'fa-user-shield', 'colore' => '#0f766e',
                'profili' => [Profili::DOCENTE, Profili::PERSONALE, Profili::GESTORE, Profili::AMMINISTRATORE], 'ordine' => 85],
            'risorse_prenotate' => ['tipo' => self::SPECIALE, 'titolo' => 'Aule, laboratori e appuntamenti', 'descrizione' => 'Le risorse prenotate dalla persona, con l\'annullamento', 'icona' => 'fa-door-open', 'colore' => '#0056B3',
                'profili' => self::TUTTI, 'ordine' => 90],
            'eventi' => ['tipo' => self::SPECIALE, 'forma' => self::ICONA, 'titolo' => 'Eventi, attestati e sondaggi', 'descrizione' => 'Prenotazioni, presenze, attestati, sondaggi, aule prenotate e scanner QR (pagina miei_eventi.php); sulla tessera si vede se c\'è un posto liberato da confermare', 'icona' => 'fa-calendar-check', 'colore' => '#0d6efd',
                'profili' => self::TUTTI, 'ordine' => 100],
            'calendario' => ['tipo' => self::SPECIALE, 'forma' => self::ICONA, 'titolo' => 'Il mio calendario', 'descrizione' => 'Eventi, aule, ricevimenti e scadenze in un posto solo, con il file .ics e il calendario del telefono (pagina calendario.php)', 'icona' => 'fa-calendar-days', 'colore' => '#0891b2',
                'profili' => self::TUTTI, 'ordine' => 102],
            'profilo' => ['tipo' => self::SPECIALE, 'forma' => self::ICONA, 'titolo' => 'Il mio profilo', 'descrizione' => 'Dati personali, email e scheda di Ateneo modificabili (pagina profilo.php)', 'icona' => 'fa-user-pen', 'colore' => '#475569',
                'profili' => self::TUTTI, 'ordine' => 105],
            'cerca' => ['tipo' => self::SPECIALE, 'forma' => self::ICONA, 'titolo' => 'Cerca nel sito', 'descrizione' => 'Scorciatoia alla ricerca unica del sito (eventi, notizie, guide, docenti, risposte)', 'icona' => 'fa-magnifying-glass', 'colore' => '#334155',
                'profili' => self::TUTTI, 'ordine' => 108],
            'guida' => ['tipo' => self::SPECIALE, 'forma' => self::ICONA, 'titolo' => 'Come si fa', 'descrizione' => 'Guide brevi con le schermate, una per ruolo', 'icona' => 'fa-circle-question', 'colore' => '#64748b',
                'profili' => self::TUTTI, 'ordine' => 110],
        ];
    }

    /** Se il riquadro è una tessera piccola «icona» (vedi ICONA). */
    public static function eIcona(string $chiave): bool
    {
        return (self::sistema()[$chiave]['forma'] ?? '') === self::ICONA;
    }

    /** @return array<string, mixed>|null */
    public static function voce(string $chiave): ?array
    {
        return self::sistema()[$chiave] ?? null;
    }
}
