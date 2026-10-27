<?php

declare(strict_types=1);

namespace App\Assistenza;

/** Valori fissi dell'assistenza a studenti e utenti: stati, chi scrive, argomenti e limiti contro gli abusi. */
final class Costanti
{
    /** Ufficio fisso (anagrafe degli uffici) le cui persone rispondono alle chat. */
    public const UFFICIO = 'assistenza';

    public const STATO_BOT = 'bot';
    public const STATO_NUOVA = 'nuova';
    public const STATO_ASSEGNATA = 'assegnata';
    public const STATO_CHIUSA = 'chiusa';
    public const STATI = [self::STATO_BOT => 'Con Didacticus', self::STATO_NUOVA => 'Da prendere', self::STATO_ASSEGNATA => 'In carico', self::STATO_CHIUSA => 'Chiusa'];

    /** Chi ha aperto la conversazione: una persona con il login oppure un esterno (riconosciuto dal link personale). */
    public const CANALE_UTENTE = 'utente';
    public const CANALE_ESTERNO = 'esterno';

    public const DA_UTENTE = 'utente';
    public const DA_OPERATORE = 'operatore';
    public const DA_BOT = 'bot';
    public const DA_SISTEMA = 'sistema';

    /** Il messaggio lo vedono tutti; la nota solo gli operatori. */
    public const TIPO_MESSAGGIO = 'messaggio';
    public const TIPO_NOTA = 'nota';

    /** Collegamenti a una pratica o a una prenotazione, per sapere di cosa si parla. */
    public const CONTESTI = ['pratica', 'prenotazione', 'convenzione', 'tutorato'];

    public const MAX_CARATTERI = 1500;
    public const MAX_NOME = 150;
    public const ARGOMENTO_PREDEFINITO = 'altro';

    /** Quante conversazioni nuove nell'ultima ora: per indirizzo IP, per email, per persona collegata. */
    public const MAX_NUOVE_PER_IP_ORA = 6;
    public const MAX_NUOVE_PER_EMAIL_ORA = 3;
    public const MAX_NUOVE_PER_UTENTE_ORA = 4;
    /** Messaggi di chi chiede aiuto nei dieci minuti precedenti, in una conversazione. */
    public const MAX_MESSAGGI_UTENTE_10_MIN = 20;

    /** Dopo quanti minuti dall'ultima apertura della chat si considera che la persona non la stia guardando (e si manda l'email). */
    public const MINUTI_PRESENZA = 2;
    /** Minuti minimi tra due email di avviso sulla stessa conversazione. */
    public const MINUTI_TRA_AVVISI_OPERATORE = 15;
    public const MINUTI_TRA_AVVISI_UTENTE = 10;
    /** Una richiesta che nessuno ha preso in carico si ricorda agli operatori ogni tanto. */
    public const MINUTI_RICORDO_NUOVA = 60;

    /** Argomenti tra cui scegliere (chiave => nome, icona Font Awesome, frase di aiuto). */
    public const ARGOMENTI = [
        'tirocini' => ['nome' => 'Tirocini', 'icona' => 'fa-briefcase', 'aiuto' => 'Tirocini curriculari degli studenti su TSP 2.0, convenzioni con aziende ed enti'],
        'fsl' => ['nome' => 'Formazione Scuola Lavoro', 'icona' => 'fa-school', 'aiuto' => 'Per scuole e docenti: attività FSL (PCTO) e Open Lab, convenzione, elenco studenti, attestati'],
        'certificati' => ['nome' => 'Certificati e attestati', 'icona' => 'fa-certificate', 'aiuto' => 'Certificato di iscrizione o di laurea, attestati, Diploma Supplement'],
        'pratiche' => ['nome' => 'Pratiche e moduli', 'icona' => 'fa-file-signature', 'aiuto' => 'Piano di studi, convalide, esoneri, cambi di corso, mobilità'],
        'iscrizione' => ['nome' => 'Iscrizione, tasse e orientamento', 'icona' => 'fa-id-card', 'aiuto' => 'Ammissione, immatricolazione, tasse, OFA, trasferimenti, borse di studio'],
        'esami' => ['nome' => 'Esami e piano di studi', 'icona' => 'fa-book-open', 'aiuto' => 'Appelli e prenotazioni su Esse3, piano di studi, convalide'],
        'tutorato' => ['nome' => 'Tutorato', 'icona' => 'fa-people-arrows', 'aiuto' => 'Bandi, incarichi e pagamenti del tutorato'],
        'tesi' => ['nome' => 'Tesi e laurea', 'icona' => 'fa-graduation-cap', 'aiuto' => 'Richiesta di tesi, relatore, convenzioni con enti e aziende'],
        'eventi' => ['nome' => 'Eventi e prenotazioni', 'icona' => 'fa-calendar-check', 'aiuto' => 'Iscrizione a eventi e seminari, prenotazione di aule e risorse'],
        'accesso' => ['nome' => 'Accesso e account', 'icona' => 'fa-user-lock', 'aiuto' => 'Non riesco ad entrare, il mio profilo, le email del portale'],
        'altro' => ['nome' => 'Altro', 'icona' => 'fa-circle-question', 'aiuto' => 'Una domanda che non rientra negli altri argomenti'],
    ];

    /** A chi può rivolgersi una domanda frequente (chiave => nome); nessuno spuntato = tutti. */
    public const PUBBLICI = ['studenti' => 'Studenti', 'scuole' => 'Scuole', 'aziende' => 'Aziende ed enti', 'docenti' => 'Docenti e personale'];

    /** @var array<string, array{nome: string, icona: string, aiuto: string}>|null */
    private static ?array $argomenti = null;
    /** @var (callable(): array<string, array{nome: string, icona: string, aiuto: string}>)|null */
    private static $sorgente = null;

    /** Da dove leggere gli argomenti scritti dal pannello (la registra App alla creazione del container). */
    public static function sorgente(?callable $sorgente): void
    {
        self::$sorgente = $sorgente;
        self::$argomenti = null;
    }

    /** Gli argomenti in uso: quelli del pannello, o (se mancano) quelli di serie. @return array<string, array{nome: string, icona: string, aiuto: string}> */
    public static function argomenti(): array
    {
        if (self::$argomenti === null) {
            $letti = self::$sorgente !== null ? (self::$sorgente)() : [];
            self::$argomenti = $letti ?: self::ARGOMENTI;
            if (!isset(self::$argomenti[self::ARGOMENTO_PREDEFINITO])) {
                self::$argomenti[self::ARGOMENTO_PREDEFINITO] = self::ARGOMENTI[self::ARGOMENTO_PREDEFINITO];
            }
        }

        return self::$argomenti;
    }

    public static function dimentica(): void
    {
        self::$argomenti = null;
    }

    /** I pubblici di una voce come lista di chiavi valide (lista vuota = per tutti). @return list<string> */
    public static function pubblici(string $csv): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $csv)), fn (string $p) => isset(self::PUBBLICI[$p])));
    }

    public static function argomentoValido(string $chiave): string
    {
        return isset(self::argomenti()[$chiave]) ? $chiave : self::ARGOMENTO_PREDEFINITO;
    }

    public static function nomeArgomento(string $chiave): string
    {
        return self::argomenti()[$chiave]['nome'] ?? self::argomenti()[self::ARGOMENTO_PREDEFINITO]['nome'];
    }
}
