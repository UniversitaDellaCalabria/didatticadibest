<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Core\Database;

/**
 * Le pratiche degli studenti preparate dal portale (come l'elenco delle pratiche del Dipartimento): ogni pratica nasce come modulo online inattivo,
 * con i campi che c'erano su Uniticket, l'iter e la domanda in PDF/A. L'amministratore la rivede e la attiva dal costruttore dei moduli
 * (Didattica → Moduli e documenti): da lì si può cambiare qualunque campo o passo.
 */
final class ModelliPratiche
{
    /** Certificati di laurea che si possono chiedere (Uniticket: «Richiesta certificato di laurea»). */
    public const CERTIFICATI_LAUREA = [
        'Laurea', 'Laurea con esami e date', 'Laurea con esami senza date', 'Laurea con anni accademici', 'Laurea con anni accademici ed esami',
        'Laurea con esami, tesi e relatore', 'Laurea con tesi',
    ];

    /** Certificati di iscrizione che si possono chiedere (Uniticket: «Richiesta Certificato di Iscrizione»). */
    public const CERTIFICATI_ISCRIZIONE = [
        'Iscrizione', 'Iscrizione con esami', 'Iscrizione con anni accademici', 'Iscrizione con esami da superare', 'Iscrizione con anni accademici ed esami',
        'Iscrizione con piano', 'Iscrizione con esami e date', 'Iscrizione corsi singoli con esami e date',
        'Carriera completa (da richiedere solo in caso di passaggi di corso di laurea con la stessa matricola)',
    ];

    /** I campi singoli dell'attestato di esame sostenuto (l'attestazione che il docente conferma). */
    public const CAMPI_ATTESTAZIONE_ESAME = ['Insegnamento', 'Docente titolare', 'Data dell\'esame', 'Dalle ore', 'Alle ore', 'Aula'];

    /** La tabella delle lezioni dell'attestato di frequenza e le sue colonne. */
    public const TABELLA_LEZIONI = 'Lezioni seguite';
    public const COLONNE_LEZIONI = ['Insegnamento:insegnamento_dip', 'Docente:docente', 'Dal:data', 'Al:data', 'Dalle ore:ora', 'Alle ore:ora', 'Aula:testo'];

    public function __construct(private Database $db)
    {
    }

    /** Crea le pratiche che mancano (si chiama a ogni aggiornamento dello schema: non tocca quelle già presenti). */
    public function prepara(): void
    {
        $this->ufficioAttestati();
        foreach (['certificato_iscrizione' => 'certificatoIscrizione', 'certificato_laurea' => 'certificatoLaurea', 'diploma_supplement' => 'diplomaSupplement', 'attestato_esame' => 'attestatoEsame', 'attestato_frequenza' => 'attestatoFrequenza'] as $chiave => $metodo) {
            if (!$this->db->valore('SELECT id FROM didattica_moduli WHERE chiave = ?', [$chiave])) {
                $this->$metodo();
            }
        }
    }

    /**
     * I dati del richiedente, uguali in tutte le richieste di certificato.
     *
     * @return list<array<string, mixed>>
     */
    private function datiRichiedente(): array
    {
        return [
            ['tipo' => 'titolo', 'etichetta' => 'Dati del richiedente'],
            ['tipo' => 'codice_fiscale', 'etichetta' => 'Codice fiscale', 'obbligatorio' => 1],
            ['tipo' => 'text', 'etichetta' => 'Luogo di nascita', 'obbligatorio' => 1, 'aiuto' => 'Comune (provincia) o Stato estero'],
            ['tipo' => 'date', 'etichetta' => 'Data di nascita', 'obbligatorio' => 1],
            ['tipo' => 'tel', 'etichetta' => 'Cellulare', 'obbligatorio' => 1],
            ['tipo' => 'email', 'etichetta' => 'Email personale', 'obbligatorio' => 1],
        ];
    }

    /** La dichiarazione di responsabilità che chiude il modulo (come su Uniticket). */
    private function dichiarazione(): array
    {
        return ['tipo' => 'dichiarazione', 'etichetta' => 'Dichiaro di aver letto e compreso quanto scritto sopra e di assumermi ogni responsabilità su quanto dichiarato', 'obbligatorio' => 1,
                'aiuto' => 'Dichiaro di essere a conoscenza delle responsabilità penali cui vado incontro in caso di dichiarazione mendace, reticente o contenente dati non rispondenti a verità (art. 483 c.p., art. 495 c.p., art. 640 c.p. e art. 76 D.P.R. n. 445/2000).'];
    }

    private const NOTE_MARCHE = "È previsto il pagamento di n. 2 marche da bollo, che verranno caricate in Esse3 (Segreteria > Situazione tasse).\nL'operatore che prenderà in carico la richiesta ti informerà sul caricamento delle marche da bollo.\nAll'invio il portale prepara la domanda in PDF/A, con i dati del tuo accesso. Puoi salvare la richiesta come bozza e modificarla finché l'ufficio non la prende in carico.";

    /**
     * Inserisce un modulo online inattivo (l'amministratore lo rivede e lo attiva dal costruttore dei moduli). Non va in verbale.
     *
     * @param list<array<string, mixed>> $campi
     * @param array<string, mixed> $domanda
     * @param list<mixed> $iter
     */
    private function inserisci(string $chiave, string $titolo, string $descrizione, array $campi, array $domanda, array $iter, int $ordine): int
    {
        $verbale = ['sezione' => $titolo, 'stile' => 'scheda', 'testo' => '', 'delibera' => ''];
        $this->db->esegui(
            "INSERT INTO didattica_moduli (titolo, descrizione, categoria, tipo, campi_json, destinatari, attivo, ordine, verbale_json, iter_json, giorni_promemoria, chiave, domanda_json, in_verbale, aggiornato_il)
                          VALUES (?, ?, 'Certificati e attestati', 'online', ?, 'studenti', 0, ?, ?, ?, 7, ?, ?, 0, NOW())",
            [$titolo, $descrizione, json_encode($campi, JSON_UNESCAPED_UNICODE), $ordine, json_encode($verbale, JSON_UNESCAPED_UNICODE), json_encode($iter), $chiave, json_encode($domanda, JSON_UNESCAPED_UNICODE)]
        );

        return (int) $this->db->valore('SELECT id FROM didattica_moduli WHERE chiave = ?', [$chiave]);
    }

    /**
     * «Richiesta Certificato di Iscrizione»: protocollo → ufficio del corso di studio, che carica le 2 marche da bollo in Esse3 e aspetta il pagamento →
     * firma del Direttore.
     */
    public function certificatoIscrizione(): int
    {
        $chiede = 'Chiede il rilascio del/i seguente/i certificato/i';
        $campi = array_merge(
            [['tipo' => 'info', 'etichetta' => 'Note operative', 'aiuto' => self::NOTE_MARCHE]],
            $this->datiRichiedente(),
            [
                ['tipo' => 'titolo', 'etichetta' => 'Iscrizione'],
                ['tipo' => 'anno_accademico', 'etichetta' => 'Iscritto per l\'A.A.', 'obbligatorio' => 1],
                ['tipo' => 'text', 'etichetta' => 'Matricola', 'obbligatorio' => 1],
                ['tipo' => 'corso_studio', 'etichetta' => 'Al Corso di Laurea in', 'obbligatorio' => 1, 'aiuto' => 'Scegli il corso dall\'elenco di Ateneo: la pratica va all\'ufficio di quel corso.'],
                ['tipo' => 'titolo', 'etichetta' => 'Cosa chiedi'],
                ['tipo' => 'multicheck', 'etichetta' => $chiede, 'obbligatorio' => 1, 'opzioni' => self::CERTIFICATI_ISCRIZIONE],
                ['tipo' => 'textarea', 'etichetta' => 'Note'],
                $this->dichiarazione(),
            ]
        );
        $domanda = ['pdf' => 1, 'bollo' => 0, 'oggetto' => 'Richiesta certificato di iscrizione', 'destinatario' => "Al Direttore del DiBEST\nUNICAL\nSEDE", 'chiede' => 'il rilascio del/i seguente/i certificato/i: {' . $chiede . '}.'];

        return $this->inserisci(
            'certificato_iscrizione',
            'Richiesta Certificato di Iscrizione',
            '<p>Per chiedere il certificato di iscrizione (anche con esami, anni accademici o piano). La richiesta arriva all\'Ufficio protocollo; l\'ufficio del tuo corso di studio carica le marche da bollo in Esse3 e ti avvisa; dopo il pagamento il Direttore firma il certificato e lo scarichi da qui.</p>',
            $campi,
            $domanda,
            ['protocollo', ['t' => 'ufficio', 'u' => '@cdl', 'pagamento' => 1], ['t' => 'direttore']],
            10
        );
    }

    /** «Richiesta Certificato di Laurea»: stesso iter del certificato di iscrizione (marche da bollo, firma del Direttore). */
    public function certificatoLaurea(): int
    {
        $chiede = 'Chiede il rilascio del/i seguente/i certificato/i';
        $campi = array_merge(
            [['tipo' => 'info', 'etichetta' => 'Note operative', 'aiuto' => self::NOTE_MARCHE]],
            $this->datiRichiedente(),
            [
                ['tipo' => 'titolo', 'etichetta' => 'Laurea'],
                ['tipo' => 'text', 'etichetta' => 'Matricola', 'obbligatorio' => 1],
                ['tipo' => 'anno_accademico', 'etichetta' => 'Laureato nell\'A.A.', 'obbligatorio' => 1],
                ['tipo' => 'date', 'etichetta' => 'In data', 'obbligatorio' => 1],
                ['tipo' => 'text', 'etichetta' => 'Con voto', 'obbligatorio' => 1, 'aiuto' => 'Es. 110/110 e lode'],
                ['tipo' => 'corso_studio', 'etichetta' => 'Al Corso di Laurea in', 'obbligatorio' => 1, 'aiuto' => 'Scegli il corso dall\'elenco di Ateneo: la pratica va all\'ufficio di quel corso.'],
                ['tipo' => 'titolo', 'etichetta' => 'Cosa chiedi'],
                ['tipo' => 'multicheck', 'etichetta' => $chiede, 'obbligatorio' => 1, 'opzioni' => self::CERTIFICATI_LAUREA],
                ['tipo' => 'textarea', 'etichetta' => 'Note'],
                $this->dichiarazione(),
            ]
        );
        $domanda = ['pdf' => 1, 'bollo' => 0, 'oggetto' => 'Richiesta certificato di laurea', 'destinatario' => "Al Direttore del DiBEST\nUNICAL\nSEDE", 'chiede' => 'il rilascio del/i seguente/i certificato/i: {' . $chiede . '}.'];

        return $this->inserisci(
            'certificato_laurea',
            'Richiesta Certificato di Laurea',
            '<p>Per chiedere il certificato di laurea (anche con esami, anni accademici, tesi e relatore). La richiesta arriva all\'Ufficio protocollo; l\'ufficio del tuo corso di studio carica le marche da bollo in Esse3 e ti avvisa; dopo il pagamento il Direttore firma il certificato e lo scarichi da qui.</p>',
            $campi,
            $domanda,
            ['protocollo', ['t' => 'ufficio', 'u' => '@cdl', 'pagamento' => 1], ['t' => 'direttore']],
            11
        );
    }

    /**
     * «Richiesta Diploma Supplement»: come i certificati ma senza marca da bollo. Dopo il protocollo l'ufficio del corso carica il documento e il Direttore lo firma.
     * Il corso di laurea si chiede comunque (serve a mandare la pratica all'ufficio giusto).
     */
    public function diplomaSupplement(): int
    {
        $campi = array_merge(
            [['tipo' => 'info', 'etichetta' => 'Note operative', 'aiuto' => "Il Diploma Supplement è predisposto dall'ufficio del tuo corso di studio e firmato dal Direttore: non è dovuta la marca da bollo.\nAll'invio il portale prepara la domanda in PDF/A, con i dati del tuo accesso. Puoi salvare la richiesta come bozza e modificarla finché l'ufficio non la prende in carico."]],
            $this->datiRichiedente(),
            [
                ['tipo' => 'titolo', 'etichetta' => 'Laurea'],
                ['tipo' => 'text', 'etichetta' => 'Matricola', 'obbligatorio' => 1],
                ['tipo' => 'anno_accademico', 'etichetta' => 'Laureato nell\'A.A.', 'obbligatorio' => 1],
                ['tipo' => 'date', 'etichetta' => 'In data', 'obbligatorio' => 1],
                ['tipo' => 'corso_studio', 'etichetta' => 'Al Corso di Laurea in', 'obbligatorio' => 1, 'aiuto' => 'Scegli il corso dall\'elenco di Ateneo: la pratica va all\'ufficio di quel corso.'],
                ['tipo' => 'textarea', 'etichetta' => 'Note'],
                $this->dichiarazione(),
            ]
        );
        $domanda = ['pdf' => 1, 'bollo' => 0, 'oggetto' => 'Richiesta rilascio Diploma Supplement', 'destinatario' => "Al Direttore del DiBEST\nUNICAL\nSEDE", 'chiede' => 'il rilascio del Diploma Supplement.'];

        return $this->inserisci(
            'diploma_supplement',
            'Richiesta Diploma Supplement',
            '<p>Per chiedere il rilascio del Diploma Supplement. La richiesta arriva all\'Ufficio protocollo; l\'ufficio del tuo corso di studio predispone il documento, il Direttore lo firma e lo scarichi da qui. Non è dovuta la marca da bollo.</p>',
            $campi,
            $domanda,
            ['protocollo', ['t' => 'ufficio', 'u' => '@cdl', 'documento' => 1], ['t' => 'direttore']],
            12
        );
    }

    /**
     * L'ufficio che segue gli attestati (di solito il manager dell'Ufficio didattico): controlla l'attestato preparato dal portale, lo manda al Direttore e
     * registra il protocollo in uscita. Si crea una volta; le persone si indicano in «Ufficio e ricevimento».
     */
    private function ufficioAttestati(): void
    {
        if ($this->db->valore("SELECT id FROM didattica_uffici WHERE chiave = 'attestati'")) {
            return;
        }
        $this->db->esegui(
            "INSERT INTO didattica_uffici (nome, descrizione, chiave, smista, segue_corsi, ordine, tipo) VALUES ('Ufficio attestati', ?, 'attestati', 0, 0, 5, '')",
            ['Controlla gli attestati preparati dal portale (frequenza, esame sostenuto), li manda al Direttore e registra il protocollo in uscita: di solito è il manager dell\'Ufficio didattico']
        );
    }

    /**
     * I campi in comune agli attestati: come funziona, studente, motivo e azienda.
     *
     * @return list<array<string, mixed>>
     */
    private function campiAttestato(string $come): array
    {
        return [
            ['tipo' => 'info', 'etichetta' => 'Come funziona', 'aiuto' => $come],
            ['tipo' => 'titolo', 'etichetta' => 'Dati dello studente'],
            ['tipo' => 'text', 'etichetta' => 'Matricola', 'obbligatorio' => 1],
            ['tipo' => 'corso_studio', 'etichetta' => 'Al Corso di Laurea in', 'obbligatorio' => 1, 'aiuto' => 'Scegli il corso dall\'elenco di Ateneo.'],
            ['tipo' => 'titolo', 'etichetta' => 'Motivo e destinatario'],
            ['tipo' => 'text', 'etichetta' => 'Motivo della richiesta', 'obbligatorio' => 1, 'aiuto' => 'Es. giustificazione per il datore di lavoro'],
            ['tipo' => 'text', 'etichetta' => 'Ente o azienda', 'aiuto' => 'In caso di giustificazione per il datore di lavoro, l\'ente o l\'azienda che la richiede.'],
            ['tipo' => 'email', 'etichetta' => 'PEC o email dell\'azienda', 'obbligatorio' => 1, 'aiuto' => 'Preferibile un indirizzo PEC dell\'azienda: l\'attestato firmato viene inviato lì.'],
        ];
    }

    /** @return list<mixed> l'iter degli attestati: conferma dei docenti → ufficio attestati (controlla) → Direttore firma → protocollo in uscita */
    private function iterAttestato(array $conferma): array
    {
        return [['t' => 'conferma'] + $conferma, ['t' => 'ufficio', 'u' => 'attestati', 'genera' => 1], ['t' => 'direttore'], ['t' => 'ufficio', 'u' => 'attestati', 'uscita' => 1]];
    }

    /**
     * «Attestato Esame sostenuto»: lo studente compila insegnamento, docente titolare, data, ore e aula; il docente conferma nel portale; l'ufficio attestati
     * controlla, il Direttore firma e l'ufficio registra il protocollo in uscita. Senza protocollo in entrata.
     */
    public function attestatoEsame(): int
    {
        $campi = array_merge(
            $this->campiAttestato("Compila i dati dell'esame e invia: il docente titolare che indichi riceve un link per confermare nel portale (non serve la sua firma digitale). Quando conferma, l'Ufficio prepara l'attestato, il Direttore lo firma e ricevi il PDF protocollato da inviare all'azienda. Puoi salvare la richiesta come bozza."),
            [
                ['tipo' => 'titolo', 'etichetta' => 'Esame sostenuto'],
                ['tipo' => 'insegnamento', 'etichetta' => 'Insegnamento', 'obbligatorio' => 1, 'aiuto' => 'Scrivi il nome e scegli dall\'elenco: il docente titolare si compila da solo.'],
                ['tipo' => 'docente', 'etichetta' => 'Docente titolare', 'obbligatorio' => 1, 'aiuto' => 'Il docente a cui arriva la conferma: deve essere nell\'anagrafe di Ateneo.'],
                ['tipo' => 'date', 'etichetta' => 'Data dell\'esame', 'obbligatorio' => 1],
                ['tipo' => 'time', 'etichetta' => 'Dalle ore', 'obbligatorio' => 1],
                ['tipo' => 'time', 'etichetta' => 'Alle ore', 'obbligatorio' => 1],
                ['tipo' => 'text', 'etichetta' => 'Aula', 'obbligatorio' => 1],
                $this->dichiarazione(),
            ]
        );
        $domanda = ['pdf' => 0, 'bollo' => 0, 'oggetto' => 'Attestato di esame sostenuto', 'destinatario' => "Al Direttore del DiBEST\nUNICAL\nSEDE", 'chiede' => ''];

        return $this->inserisci(
            'attestato_esame',
            'Attestato Esame sostenuto',
            '<p>Per chiedere un attestato di esame sostenuto da presentare a un\'azienda o a un ente. Il docente titolare conferma nel portale, il Direttore firma e ricevi il PDF protocollato.</p>',
            $campi,
            $domanda,
            $this->iterAttestato(['campo' => 'Docente titolare']),
            13
        );
    }

    /**
     * «Attestato Frequenza Lezione»: lo studente aggiunge le lezioni seguite (anche in tempi diversi, in bozza) con insegnamento, docente, date, ore e aula; ogni docente
     * conferma le sue righe nel portale; poi l'ufficio attestati controlla, il Direttore firma e si registra il protocollo in uscita.
     */
    public function attestatoFrequenza(): int
    {
        $campi = array_merge(
            $this->campiAttestato("Aggiungi una riga per ogni lezione seguita (insegnamento, docente, data, orario e aula): puoi tenere la richiesta in bozza e aggiungere lezioni nel tempo, poi inviarla quando vuoi. Ogni docente indicato riceve un link per confermare le sue righe nel portale. Poi l'Ufficio prepara l'attestato, il Direttore lo firma e ricevi il PDF protocollato da inviare all'azienda."),
            [
                ['tipo' => 'titolo', 'etichetta' => 'Lezioni seguite'],
                ['tipo' => 'tabella', 'etichetta' => self::TABELLA_LEZIONI, 'obbligatorio' => 1, 'opzioni' => self::COLONNE_LEZIONI,
                 'aiuto' => 'Una riga per ogni lezione o periodo: scegli l\'insegnamento dall\'elenco (il docente si compila da solo), poi data, orario e aula. Per un solo giorno lascia vuota la data «Al».'],
                $this->dichiarazione(),
            ]
        );
        $domanda = ['pdf' => 0, 'bollo' => 0, 'oggetto' => 'Attestato di frequenza alle lezioni', 'destinatario' => "Al Direttore del DiBEST\nUNICAL\nSEDE", 'chiede' => ''];

        return $this->inserisci(
            'attestato_frequenza',
            'Attestato Frequenza Lezione',
            '<p>Per chiedere un attestato di frequenza alle lezioni da presentare a un\'azienda o a un ente. Puoi inserire più lezioni, anche di giorni e docenti diversi, e inviare la richiesta quando vuoi: ogni docente conferma le sue righe nel portale.</p>',
            $campi,
            $domanda,
            $this->iterAttestato(['campo' => self::TABELLA_LEZIONI, 'colonna' => 'Docente']),
            14
        );
    }
}
