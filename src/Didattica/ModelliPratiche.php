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

    /** Certificati di rinuncia agli studi: gli stessi dell'iscrizione (con esami, date, anni accademici, piano), tranne la carriera completa e i corsi singoli. */
    public const CERTIFICATI_RINUNCIA = [
        'Rinuncia', 'Rinuncia con esami', 'Rinuncia con anni accademici', 'Rinuncia con esami da superare', 'Rinuncia con anni accademici ed esami',
        'Rinuncia con piano', 'Rinuncia con esami e date',
    ];

    /** Le tre richieste della pratica «Richiesta Certificato». */
    public const TIPI_CERTIFICATO = ['Iscrizione', 'Laurea', 'Rinuncia agli studi'];

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
        foreach (['certificato' => 'certificato', 'diploma_supplement' => 'diplomaSupplement', 'attestato_esame' => 'attestatoEsame', 'attestato_frequenza' => 'attestatoFrequenza',
                 'trasferimento_entrata' => 'trasferimentoInEntrata', 'iscrizione_rinuncia' => 'iscrizioneRinunciaDecadenza', 'abbreviazione_corso' => 'abbreviazioneDiCorso',
                 'convalida_inglese' => 'convalidaInglese', 'esonero_inglese' => 'esoneroInglese', 'modifica_piano' => 'modificaPianoDiStudi', 'cambio_curriculum' => 'cambioCurriculum',
                 'tempo_pieno_part_time' => 'cambioTempoPienoPartTime', 'esami_fuori_piano' => 'esamiFuoriPiano', 'anticipo_insegnamenti' => 'anticipoInsegnamenti', 'titolo_estero' => 'titoloEstero',
                 'tesi' => 'tesi', 'cancellazione_iscrizione' => 'cancellazioneIscrizione', 'esonero_frequenza' => 'esoneroFrequenza',
                 'mobilita_svolgimento' => 'mobilitaSvolgimento', 'mobilita_variazione' => 'mobilitaVariazione', 'mobilita_fine' => 'mobilitaFine'] as $chiave => $metodo) {
            if (!$this->db->valore('SELECT id FROM didattica_moduli WHERE chiave = ?', [$chiave])) {
                $this->$metodo();
            }
        }
        // Le pratiche di mobilità passano dall'ufficio «Internazionalizzazione» (già esistente): se in una versione precedente era stato creato un ufficio a parte, si unifica
        $intern = (int) $this->db->valore("SELECT id FROM didattica_uffici WHERE chiave = 'internazionalizzazione'");
        $vecchio = (int) $this->db->valore("SELECT id FROM didattica_uffici WHERE chiave = 'mobilita'");
        if ($vecchio) {
            if ($intern) {
                $this->db->esegui("UPDATE didattica_moduli SET iter_json = REPLACE(iter_json, '\"mobilita\"', '\"internazionalizzazione\"') WHERE chiave LIKE 'mobilita\\_%'");
                $this->db->esegui('UPDATE pratiche SET ufficio_id = ? WHERE ufficio_id = ?', [$intern, $vecchio]);
                $this->db->esegui('UPDATE ufficio_didattica SET ufficio_id = ? WHERE ufficio_id = ?', [$intern, $vecchio]);
                $this->db->esegui('DELETE FROM didattica_uffici WHERE id = ?', [$vecchio]);
            }
        }
        // I due certificati separati (iscrizione, laurea) sono sostituiti dalla richiesta unica: si eliminano se non hanno pratiche, altrimenti si spengono
        $this->db->esegui("DELETE FROM didattica_moduli WHERE chiave IN ('certificato_iscrizione', 'certificato_laurea') AND NOT EXISTS (SELECT 1 FROM pratiche WHERE pratiche.modulo_id = didattica_moduli.id)");
        $this->db->esegui("UPDATE didattica_moduli SET attivo = 0 WHERE chiave IN ('certificato_iscrizione', 'certificato_laurea') AND attivo = 1");
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
    private function inserisci(string $chiave, string $titolo, string $descrizione, array $campi, array $domanda, array $iter, int $ordine, string $categoria = 'Certificati e attestati', ?array $verbale = null): int
    {
        // Senza testo per il verbale la pratica non ci va (certificati e attestati); con il testo ci va, come le convalide
        $inVerbale = $verbale === null ? 0 : 1;
        $verbale ??= ['sezione' => $titolo, 'stile' => 'scheda', 'testo' => '', 'delibera' => ''];
        $this->db->esegui(
            "INSERT INTO didattica_moduli (titolo, descrizione, categoria, tipo, campi_json, destinatari, attivo, ordine, verbale_json, iter_json, giorni_promemoria, chiave, domanda_json, in_verbale, aggiornato_il)
                          VALUES (?, ?, ?, 'online', ?, 'studenti', 0, ?, ?, ?, 7, ?, ?, ?, NOW())",
            [$titolo, $descrizione, $categoria, json_encode($campi, JSON_UNESCAPED_UNICODE), $ordine, json_encode($verbale, JSON_UNESCAPED_UNICODE), json_encode($iter), $chiave, json_encode($domanda, JSON_UNESCAPED_UNICODE), $inVerbale]
        );

        return (int) $this->db->valore('SELECT id FROM didattica_moduli WHERE chiave = ?', [$chiave]);
    }

    /**
     * «Richiesta Certificato»: una sola pratica per iscrizione, laurea e rinuncia agli studi. Lo studente sceglie il tipo e compaiono i campi e l'elenco dei
     * certificati di quel tipo. Iter: protocollo → ufficio del corso di studio, che carica le 2 marche da bollo in Esse3 e aspetta il pagamento → firma del Direttore.
     */
    public function certificato(): int
    {
        $tipo = 'Tipo di certificato';
        $e = static fn (string $v): array => ['campo' => $tipo, 'op' => 'uguale', 'valore' => $v];
        $campi = array_merge(
            [['tipo' => 'info', 'etichetta' => 'Note operative', 'aiuto' => self::NOTE_MARCHE]],
            $this->datiRichiedente(),
            [
                ['tipo' => 'titolo', 'etichetta' => 'Che certificato ti serve'],
                ['tipo' => 'select', 'etichetta' => $tipo, 'obbligatorio' => 1, 'opzioni' => self::TIPI_CERTIFICATO],
                ['tipo' => 'text', 'etichetta' => 'Matricola', 'obbligatorio' => 1],
                ['tipo' => 'corso_studio', 'etichetta' => 'Al Corso di Laurea in', 'obbligatorio' => 1, 'aiuto' => 'Scegli il corso dall\'elenco di Ateneo: la pratica va all\'ufficio di quel corso.'],
                ['tipo' => 'anno_accademico', 'etichetta' => 'Iscritto per l\'A.A.', 'obbligatorio' => 1, 'cond' => ['campo' => $tipo, 'op' => 'diverso', 'valore' => 'Laurea']],
                ['tipo' => 'anno_accademico', 'etichetta' => 'Laureato nell\'A.A.', 'obbligatorio' => 1, 'cond' => $e('Laurea')],
                ['tipo' => 'date', 'etichetta' => 'In data', 'obbligatorio' => 1, 'cond' => $e('Laurea')],
                ['tipo' => 'text', 'etichetta' => 'Con voto', 'obbligatorio' => 1, 'aiuto' => 'Es. 110/110 e lode', 'cond' => $e('Laurea')],
                ['tipo' => 'titolo', 'etichetta' => 'Cosa chiedi'],
                ['tipo' => 'multicheck', 'etichetta' => 'Certificati di iscrizione richiesti', 'obbligatorio' => 1, 'opzioni' => self::CERTIFICATI_ISCRIZIONE, 'cond' => $e('Iscrizione')],
                ['tipo' => 'multicheck', 'etichetta' => 'Certificati di laurea richiesti', 'obbligatorio' => 1, 'opzioni' => self::CERTIFICATI_LAUREA, 'cond' => $e('Laurea')],
                ['tipo' => 'multicheck', 'etichetta' => 'Certificati di rinuncia richiesti', 'obbligatorio' => 1, 'opzioni' => self::CERTIFICATI_RINUNCIA, 'cond' => $e('Rinuncia agli studi')],
                ['tipo' => 'textarea', 'etichetta' => 'Note'],
                $this->dichiarazione(),
            ]
        );
        $domanda = ['pdf' => 1, 'bollo' => 0, 'oggetto' => 'Richiesta certificato', 'destinatario' => "Al Direttore del DiBEST
UNICAL
SEDE",
                    'chiede' => 'il rilascio del/i seguente/i certificato/i ({' . $tipo . '}): {Certificati di iscrizione richiesti}{Certificati di laurea richiesti}{Certificati di rinuncia richiesti}.'];

        return $this->inserisci(
            'certificato',
            'Richiesta Certificato',
            '<p>Per chiedere un certificato di iscrizione, di laurea o di rinuncia agli studi (anche con esami, date, anni accademici o piano). La richiesta arriva all\'Ufficio protocollo; l\'ufficio del tuo corso di studio carica le marche da bollo in Esse3 e ti avvisa; dopo il pagamento il Direttore firma il certificato e lo scarichi da qui.</p>',
            $campi,
            $domanda,
            ['protocollo', ['t' => 'ufficio', 'u' => '@cdl', 'pagamento' => 1], ['t' => 'direttore']],
            10
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

    private const CATEGORIA_IMMATRICOLAZIONI = 'Immatricolazioni, passaggi e trasferimenti';

    private const NOTA_OFA = "Obbligo formativo aggiuntivo (OFA): chi chiede il trasferimento, il passaggio, l'abbreviazione di corso o l'iscrizione per rinuncia o decadenza è soggetto alla verifica dell'adeguata preparazione.\n– TOLC-B/TOLC-S sostenuto: si verifica il punteggio della sezione di matematica; se è 7 o più l'obbligo è estinto, se è inferiore ci si iscrive con l'obbligo formativo aggiuntivo.\n– TOLC-B/TOLC-S non sostenuto: ci si iscrive con l'obbligo formativo aggiuntivo e non si accede agli esami del 2° anno.\n– Esame di matematica sostenuto: se l'esame viene convalidato l'obbligo è estinto, anche se il test non è stato sostenuto.\n– Obbligo di matematica già assolto nel corso di laurea di provenienza: non ci si iscrive con l'obbligo formativo aggiuntivo.\nSi può chiedere il riconoscimento di un TOLC CISIA (TOLC-I) diverso da quello previsto dal corso di laurea, se il punteggio di matematica non è inferiore alla soglia minima stabilita.";

    /** Link UniPay della marca da bollo (16 euro) di ciascuna istanza. */
    private const BOLLO_TRASFERIMENTO = 'https://unipay.unical.it/prodotto/imposta-di-bollo-su-istanza-di-trasferimento-verso-universita-della-calabria/';
    private const BOLLO_RINUNCIA = 'https://unipay.unical.it/prodotto/imposta-di-bollo-su-istanza-di-iscrizione-ad-anni-successivi-al-primo-per-rinuncia-decadenza/';
    private const BOLLO_ABBREVIAZIONE = 'https://unipay.unical.it/prodotto/imposta-di-bollo-su-istanza-di-immatricolazione-per-abbreviazione-di-carriera/';

    /**
     * I dati del richiedente delle pratiche di immatricolazione (anche la residenza, che serve per la domanda).
     *
     * @return list<array<string, mixed>>
     */
    private function datiRichiedenteResidente(): array
    {
        return array_merge(
            array_slice($this->datiRichiedente(), 0, 4),
            [
                ['tipo' => 'text', 'etichetta' => 'Comune di residenza', 'obbligatorio' => 1, 'aiuto' => 'Con la provincia, es. Rende (CS)'],
                ['tipo' => 'text', 'etichetta' => 'Indirizzo di residenza', 'obbligatorio' => 1, 'aiuto' => 'Via, numero civico e CAP'],
            ],
            array_slice($this->datiRichiedente(), 4)
        );
    }

    /**
     * La parte finale di queste pratiche: ricevuta della marca da bollo (pagata dallo studente su UniPay), note, dichiarazione e il campo dell'ufficio per l'OFA.
     *
     * @return list<array<string, mixed>>
     */
    private function chiusuraImmatricolazione(string $linkBollo): array
    {
        return [
            ['tipo' => 'titolo', 'etichetta' => 'Marca da bollo', 'aiuto' => "È prevista una marca da bollo da 16 euro, da pagare prima dell'invio su UniPay:\n" . $linkBollo . "\nAllega la ricevuta di avvenuto pagamento."],
            ['tipo' => 'file', 'etichetta' => 'Ricevuta di pagamento della marca da bollo', 'obbligatorio' => 1],
            ['tipo' => 'textarea', 'etichetta' => 'Note per l\'ufficio'],
            $this->dichiarazione(),
            ['tipo' => 'select', 'etichetta' => 'Obbligo formativo aggiuntivo (OFA)', 'ufficio' => 1, 'opzioni' => ['Non previsto', 'Estinto', 'Da assolvere (non accede agli esami del 2° anno)']],
            ['tipo' => 'textarea', 'etichetta' => 'Note dell\'ufficio', 'ufficio' => 1],
        ];
    }

    /** Iter delle pratiche di immatricolazione: protocollo → ufficio del corso di studio (referente) → Segreteria studenti; poi il Consiglio decide. */
    private const ITER_IMMATRICOLAZIONE = ['protocollo', '@cdl', '@segreteria'];

    /**
     * «Richiesta Trasferimento in Entrata»: lo studente di un altro Ateneo chiede il trasferimento a un corso del DiBEST. Va in verbale (decide il Consiglio).
     */
    public function trasferimentoInEntrata(): int
    {
        $campi = array_merge(
            [['tipo' => 'info', 'etichetta' => 'Obbligo formativo aggiuntivo – leggere con attenzione', 'aiuto' => self::NOTA_OFA . "\n\nAll'invio il portale prepara la domanda in PDF/A, con i dati del tuo accesso."]],
            $this->datiRichiedenteResidente(),
            [
                ['tipo' => 'titolo', 'etichetta' => 'Carriera di provenienza'],
                ['tipo' => 'text', 'etichetta' => 'Università di provenienza', 'obbligatorio' => 1, 'aiuto' => 'es. Università degli Studi di Messina'],
                ['tipo' => 'text', 'etichetta' => 'Corso di laurea di provenienza', 'obbligatorio' => 1],
                ['tipo' => 'text', 'etichetta' => 'Matricola nell\'Ateneo di provenienza', 'obbligatorio' => 1],
                ['tipo' => 'text', 'etichetta' => 'A.A. di prima immatricolazione', 'obbligatorio' => 1, 'aiuto' => 'es. 2023/2024'],
                ['tipo' => 'titolo', 'etichetta' => 'Cosa chiedi', 'aiuto' => 'L\'autorizzazione al trasferimento presso l\'Università della Calabria, al corso di laurea del DiBEST che scegli qui sotto.'],
                ['tipo' => 'corso_studio', 'etichetta' => 'Corso di destinazione', 'obbligatorio' => 1, 'aiuto' => 'Scegli il corso dall\'elenco di Ateneo: la pratica va all\'ufficio di quel corso.'],
                ['tipo' => 'titolo', 'etichetta' => 'Allegati'],
                ['tipo' => 'file', 'etichetta' => 'Autocertificazione degli esami sostenuti', 'obbligatorio' => 1, 'aiuto' => 'Esame, data, CFU, S.S.D., voto. PDF con nome del file corto (es. doc.pdf).'],
                ['tipo' => 'file', 'etichetta' => 'Programmi degli esami sostenuti', 'obbligatorio' => 1, 'aiuto' => 'Un unico PDF con i programmi, nell\'ordine dell\'autocertificazione.'],
            ],
            $this->chiusuraImmatricolazione(self::BOLLO_TRASFERIMENTO)
        );
        $verbale = ['sezione' => 'Immatricolazioni, passaggi e trasferimenti', 'stile' => 'scheda',
                    'testo' => '{STUDENTE}, proveniente da {Università di provenienza} (corso di laurea in {Corso di laurea di provenienza}, matricola {Matricola nell\'Ateneo di provenienza}), con domanda prot. n. {PROTOCOLLO}, chiede il trasferimento in entrata al {Corso di destinazione} con il riconoscimento degli esami sostenuti. OFA: {Obbligo formativo aggiuntivo (OFA)}.',
                    'delibera' => 'Il Consiglio, esaminata la documentazione, delibera in merito all\'autorizzazione al trasferimento e al riconoscimento degli esami.'];
        $domanda = ['pdf' => 1, 'bollo' => 1, 'oggetto' => 'Richiesta Trasferimento in Entrata', 'destinatario' => "Al Direttore del DiBEST\ne/o Coordinatore del CdS\nUNICAL\nSEDE",
                    'chiede' => 'l\'autorizzazione al trasferimento presso codesto Ateneo al Corso di Laurea {Corso di destinazione} del Dipartimento di Biologia, Ecologia e Scienze della Terra, dall\'Università {Università di provenienza} (corso di laurea in {Corso di laurea di provenienza}).'];

        return $this->inserisci(
            'trasferimento_entrata',
            'Richiesta Trasferimento in Entrata',
            '<p>Per chiedere il trasferimento in entrata da un altro Ateneo a un corso di laurea del DiBEST, con il riconoscimento degli esami sostenuti. Prima di inviare paga la marca da bollo da 16 euro su UniPay e allega la ricevuta. La richiesta arriva all\'Ufficio protocollo, poi all\'ufficio del corso di studio e alla Segreteria studenti; l\'esito lo decide il Consiglio di corso di studio.</p>',
            $campi,
            $domanda,
            self::ITER_IMMATRICOLAZIONE,
            20,
            self::CATEGORIA_IMMATRICOLAZIONI,
            $verbale
        );
    }

    /**
     * «Richiesta di Iscrizione per rinuncia e decadenza»: chi ha rinunciato o è decaduto da una carriera si iscrive di nuovo con il riconoscimento degli esami.
     * Prima ci si iscrive su Esse3 (casella obbligatoria).
     */
    public function iscrizioneRinunciaDecadenza(): int
    {
        $campi = array_merge(
            [['tipo' => 'info', 'etichetta' => 'Obbligo formativo aggiuntivo – leggere con attenzione', 'aiuto' => self::NOTA_OFA . "\n\nAlla richiesta si allega l'autocertificazione degli esami sostenuti con i relativi programmi (nome del file corto, es. doc.pdf, prog.pdf). All'invio il portale prepara la domanda in PDF/A, con i dati del tuo accesso."]],
            [['tipo' => 'checkbox', 'etichetta' => 'Mi sono iscritto/sono già iscritto sul sistema ESSE3', 'obbligatorio' => 1, 'aiuto' => 'Prima di presentare la richiesta è necessario iscriversi su Esse3, avendo cura di inserire il titolo di studio.']],
            $this->datiRichiedenteResidente(),
            [
                ['tipo' => 'titolo', 'etichetta' => 'Carriera precedente'],
                ['tipo' => 'text', 'etichetta' => 'Università frequentata', 'obbligatorio' => 1, 'aiuto' => 'Anche l\'Università della Calabria, se la carriera era qui'],
                ['tipo' => 'text', 'etichetta' => 'Corso di laurea frequentato', 'obbligatorio' => 1],
                ['tipo' => 'number', 'etichetta' => 'Numero di esami superati', 'obbligatorio' => 1],
                ['tipo' => 'text', 'etichetta' => 'A.A. di prima immatricolazione', 'obbligatorio' => 1, 'aiuto' => 'es. 2019/2020'],
                ['tipo' => 'titolo', 'etichetta' => 'Cosa chiedi', 'aiuto' => 'L\'iscrizione al corso di laurea del DiBEST che scegli qui sotto, con il riconoscimento degli esami sostenuti.'],
                ['tipo' => 'corso_studio', 'etichetta' => 'Corso di destinazione', 'obbligatorio' => 1, 'aiuto' => 'Scegli il corso dall\'elenco di Ateneo: la pratica va all\'ufficio di quel corso.'],
                ['tipo' => 'titolo', 'etichetta' => 'Allegati'],
                ['tipo' => 'file', 'etichetta' => 'Autocertificazione degli esami sostenuti', 'obbligatorio' => 1, 'aiuto' => 'Esame, data, CFU, S.S.D., voto.'],
                ['tipo' => 'file', 'etichetta' => 'Programmi degli esami sostenuti', 'aiuto' => 'Da allegare solo se gli esami sono stati sostenuti in un altro Ateneo.'],
            ],
            $this->chiusuraImmatricolazione(self::BOLLO_RINUNCIA)
        );
        $verbale = ['sezione' => 'Immatricolazioni, passaggi e trasferimenti', 'stile' => 'scheda',
                    'testo' => '{STUDENTE}, già iscritto/a all\'Università {Università frequentata} (corso di laurea in {Corso di laurea frequentato}, {Numero di esami superati} esami superati), con domanda prot. n. {PROTOCOLLO}, chiede l\'iscrizione per rinuncia o decadenza al {Corso di destinazione} con il riconoscimento degli esami sostenuti. OFA: {Obbligo formativo aggiuntivo (OFA)}.',
                    'delibera' => 'Il Consiglio, esaminata la documentazione, delibera in merito all\'iscrizione e al riconoscimento degli esami.'];
        $domanda = ['pdf' => 1, 'bollo' => 1, 'oggetto' => 'Richiesta di Iscrizione per rinuncia e decadenza', 'destinatario' => "Al Direttore del DiBEST\ne/o Coordinatore del CdS\nUNICAL\nSEDE",
                    'chiede' => 'l\'iscrizione al Corso di Laurea {Corso di destinazione} del Dipartimento di Biologia, Ecologia e Scienze della Terra con il riconoscimento degli esami sostenuti ({Numero di esami superati} esami superati presso l\'Università {Università frequentata}, corso di laurea in {Corso di laurea frequentato}).'];

        return $this->inserisci(
            'iscrizione_rinuncia',
            'Richiesta di Iscrizione per rinuncia e decadenza',
            '<p>Per chiedere l\'iscrizione a un corso di laurea del DiBEST dopo una rinuncia agli studi o una decadenza, con il riconoscimento degli esami sostenuti. Prima iscriviti su Esse3 e paga la marca da bollo da 16 euro su UniPay. La richiesta arriva all\'Ufficio protocollo, poi all\'ufficio del corso di studio e alla Segreteria studenti; l\'esito lo decide il Consiglio di corso di studio.</p>',
            $campi,
            $domanda,
            self::ITER_IMMATRICOLAZIONE,
            21,
            self::CATEGORIA_IMMATRICOLAZIONI,
            $verbale
        );
    }

    /**
     * «Richiesta Abbreviazione di corso»: chi ha già un titolo di studio (anche conseguito altrove) si immatricola con il riconoscimento degli esami.
     */
    public function abbreviazioneDiCorso(): int
    {
        $campi = array_merge(
            [['tipo' => 'info', 'etichetta' => 'Obbligo formativo aggiuntivo – leggere con attenzione', 'aiuto' => self::NOTA_OFA . "\n\nAlla richiesta si allegano l'autocertificazione degli esami sostenuti e, solo se il titolo è stato conseguito in un altro Ateneo, i programmi degli insegnamenti. All'invio il portale prepara la domanda in PDF/A, con i dati del tuo accesso."]],
            $this->datiRichiedenteResidente(),
            [
                ['tipo' => 'titolo', 'etichetta' => 'Titolo di studio già conseguito'],
                ['tipo' => 'text', 'etichetta' => 'Titolo di studio', 'obbligatorio' => 1, 'aiuto' => 'es. Laurea, Laurea magistrale'],
                ['tipo' => 'text', 'etichetta' => 'Conseguito presso', 'obbligatorio' => 1, 'aiuto' => 'L\'Ateneo'],
                ['tipo' => 'text', 'etichetta' => 'Corso di laurea del titolo', 'obbligatorio' => 1],
                ['tipo' => 'date', 'etichetta' => 'Titolo conseguito in data', 'obbligatorio' => 1],
                ['tipo' => 'text', 'etichetta' => 'Con voto', 'obbligatorio' => 1, 'aiuto' => 'Es. 110/110 e lode'],
                ['tipo' => 'titolo', 'etichetta' => 'Cosa chiedi', 'aiuto' => 'L\'abbreviazione al corso di laurea del DiBEST che scegli qui sotto, con il riconoscimento degli esami sostenuti.'],
                ['tipo' => 'corso_studio', 'etichetta' => 'Corso di destinazione', 'obbligatorio' => 1, 'aiuto' => 'Scegli il corso dall\'elenco di Ateneo: la pratica va all\'ufficio di quel corso.'],
                ['tipo' => 'titolo', 'etichetta' => 'Allegati'],
                ['tipo' => 'file', 'etichetta' => 'Autocertificazione degli esami sostenuti', 'obbligatorio' => 1, 'aiuto' => 'Esame, data, CFU, S.S.D., voto.'],
                ['tipo' => 'file', 'etichetta' => 'Programmi degli esami sostenuti', 'aiuto' => 'Da allegare in PDF solo se il titolo è stato conseguito in un altro Ateneo.'],
            ],
            $this->chiusuraImmatricolazione(self::BOLLO_ABBREVIAZIONE)
        );
        $verbale = ['sezione' => 'Immatricolazioni, passaggi e trasferimenti', 'stile' => 'scheda',
                    'testo' => '{STUDENTE}, in possesso del titolo di {Titolo di studio} in {Corso di laurea del titolo} conseguito presso {Conseguito presso}, con domanda prot. n. {PROTOCOLLO}, chiede l\'abbreviazione di corso al {Corso di destinazione} con il riconoscimento degli esami sostenuti. OFA: {Obbligo formativo aggiuntivo (OFA)}.',
                    'delibera' => 'Il Consiglio, esaminata la documentazione, delibera in merito all\'abbreviazione di corso e al riconoscimento degli esami.'];
        $domanda = ['pdf' => 1, 'bollo' => 1, 'oggetto' => 'Richiesta Abbreviazione di corso', 'destinatario' => "Al Direttore del DiBEST\ne/o Coordinatore del CdS\nUNICAL\nSEDE",
                    'chiede' => 'l\'abbreviazione al Corso di Laurea {Corso di destinazione} del Dipartimento di Biologia, Ecologia e Scienze della Terra con il riconoscimento degli esami sostenuti (titolo di {Titolo di studio} in {Corso di laurea del titolo}, conseguito presso {Conseguito presso} il {Titolo conseguito in data} con voto {Con voto}).'];

        return $this->inserisci(
            'abbreviazione_corso',
            'Richiesta Abbreviazione di corso',
            '<p>Per chiedere l\'immatricolazione con abbreviazione di corso a un corso di laurea del DiBEST, con il riconoscimento degli esami sostenuti. Prima di inviare paga la marca da bollo da 16 euro su UniPay e allega la ricevuta. La richiesta arriva all\'Ufficio protocollo, poi all\'ufficio del corso di studio e alla Segreteria studenti; l\'esito lo decide il Consiglio di corso di studio.</p>',
            $campi,
            $domanda,
            self::ITER_IMMATRICOLAZIONE,
            22,
            self::CATEGORIA_IMMATRICOLAZIONI,
            $verbale
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

    private const CATEGORIA_CARRIERA = 'Carriera';

    /** Le due scelte di English for Basic Academic Skills / Inglese (Scienze e Tecnologie delle attività motorie e sportive). */
    private const ESAMI_INGLESE = ['English for Basic Academic Skills', 'Inglese (per gli studenti di Scienze e Tecnologie per le attività motorie e sportive)'];

    /**
     * I dati dello studente nelle pratiche di carriera (variazioni, convalide e esoneri): come nei moduli Uniticket «Il sottoscritto/a è nato/a a… iscritto per l'A.A.… con matricola…».
     *
     * @return list<array<string, mixed>>
     */
    private function datiCarriera(): array
    {
        return [
            ['tipo' => 'titolo', 'etichetta' => 'Dati dello studente'],
            ['tipo' => 'text', 'etichetta' => 'Luogo di nascita', 'obbligatorio' => 1, 'aiuto' => 'Comune (provincia) o Stato estero'],
            ['tipo' => 'date', 'etichetta' => 'Data di nascita', 'obbligatorio' => 1],
            ['tipo' => 'tel', 'etichetta' => 'Cellulare', 'obbligatorio' => 1],
            ['tipo' => 'anno_accademico', 'etichetta' => 'Iscritto per l\'A.A.', 'obbligatorio' => 1],
            ['tipo' => 'text', 'etichetta' => 'Matricola', 'obbligatorio' => 1],
            ['tipo' => 'corso_studio', 'etichetta' => 'Al Corso di Laurea in', 'obbligatorio' => 1, 'aiuto' => 'Scegli il corso dall\'elenco di Ateneo: la pratica va all\'ufficio di quel corso.'],
        ];
    }

    /**
     * Il testo che chiude le pratiche di carriera: note facoltative e dichiarazione di responsabilità.
     *
     * @return list<array<string, mixed>>
     */
    private function chiusuraCarriera(string $etichettaNote = 'Note'): array
    {
        return [['tipo' => 'textarea', 'etichetta' => $etichettaNote], $this->dichiarazione()];
    }

    /**
     * Inserisce una pratica di carriera: iter protocollo → ufficio del corso (referente) → Segreteria, con il passaggio nel verbale e la decisione del Consiglio.
     * Il protocollo, se serve la marca da bollo, aspetta che risulti pagata prima di passare all'ufficio del corso.
     *
     * @param list<array<string, mixed>> $campi
     */
    private function inserisciCarriera(string $chiave, string $titolo, string $descrizione, array $campi, string $destinatario, string $chiede, string $testoVerbale, string $delibera, int $ordine, bool $bollo = false, ?array $iter = null, string $categoria = self::CATEGORIA_CARRIERA): int
    {
        $verbale = ['sezione' => $titolo, 'stile' => 'scheda', 'testo' => $testoVerbale, 'delibera' => $delibera];
        $domanda = ['pdf' => 1, 'bollo' => $bollo ? 1 : 0, 'oggetto' => $titolo, 'destinatario' => $destinatario, 'chiede' => $chiede];

        return $this->inserisci($chiave, $titolo, $descrizione, $campi, $domanda, $iter ?? self::ITER_IMMATRICOLAZIONE, $ordine, $categoria, $verbale);
    }

    private const DESTINATARIO_CDS = "Al Coordinatore del CdS\nUNICAL\nSEDE";
    private const DESTINATARIO_DIRETTORE_CDS = "Al Direttore del DiBEST\ne/o Coordinatore del CdS\nUNICAL\nSEDE";
    private const TESTO_VERBALE_STUDENTE = '{STUDENTE}, matricola {MATRICOLA}, iscritto/a per l\'a.a. {Iscritto per l\'A.A.} al {Al Corso di Laurea in}, con domanda prot. n. {PROTOCOLLO}, ';

    /** «Richiesta Convalida/Riconoscimento English for Basic Academic Skills e Inglese»: con marca da bollo, certificazione di livello B2 o superiore. */
    public function convalidaInglese(): int
    {
        $campi = array_merge(
            [['tipo' => 'info', 'etichetta' => 'Note operative', 'aiuto' => "Alla richiesta va allegata copia delle certificazioni riconosciute di livello superiore al B1, conseguite entro i quattro anni precedenti. Usa un nome di file corto (es. doc.pdf, cert.pdf).\nÈ prevista la marca da bollo, che viene caricata in Esse3 (Segreteria > Situazione tasse): l'operatore che prende in carico la richiesta ti informa.\nAll'invio il portale prepara la domanda in PDF/A, con i dati del tuo accesso."]],
            $this->datiCarriera(),
            [
                ['tipo' => 'titolo', 'etichetta' => 'Cosa chiedi', 'aiuto' => 'La convalida dell\'esame di:'],
                ['tipo' => 'radio', 'etichetta' => 'Esame oggetto della richiesta', 'obbligatorio' => 1, 'opzioni' => self::ESAMI_INGLESE],
                ['tipo' => 'file', 'etichetta' => 'Copia della certificazione del livello B2 (ed oltre)', 'obbligatorio' => 1],
            ],
            $this->chiusuraCarriera()
        );

        return $this->inserisciCarriera(
            'convalida_inglese',
            'Richiesta Convalida English for Basic Academic Skills e Inglese',
            '<p>Per chiedere la convalida dell\'esame di English for Basic Academic Skills (o di Inglese, per Scienze e Tecnologie delle attività motorie e sportive) con una certificazione di livello B2 o superiore. La richiesta arriva all\'Ufficio protocollo (che verifica la marca da bollo), poi all\'ufficio del corso di studio; l\'esito lo decide il Consiglio di corso di studio.</p>',
            $campi,
            self::DESTINATARIO_DIRETTORE_CDS,
            'la convalida dell\'esame di {Esame oggetto della richiesta}, allegando copia della certificazione linguistica di livello B2 o superiore.',
            self::TESTO_VERBALE_STUDENTE . 'chiede la convalida dell\'esame di {Esame oggetto della richiesta} sulla base della certificazione linguistica allegata.',
            'Il Consiglio, esaminata la certificazione, delibera in merito alla convalida.',
            30,
            true
        );
    }

    /** «Richiesta Esonero dalla Frequenza English for Basic Academic Skills e Inglese»: senza marca da bollo, certificazione di livello B1. */
    public function esoneroInglese(): int
    {
        $campi = array_merge(
            [['tipo' => 'info', 'etichetta' => 'Note operative', 'aiuto' => "Alla richiesta va allegata copia delle certificazioni riconosciute di livello B1, conseguite entro i quattro anni precedenti. Usa un nome di file corto (es. doc.pdf, cert.pdf).\nAll'invio il portale prepara la domanda in PDF/A, con i dati del tuo accesso."]],
            $this->datiCarriera(),
            [
                ['tipo' => 'titolo', 'etichetta' => 'Cosa chiedi', 'aiuto' => 'L\'esonero dalla frequenza dell\'esame di:'],
                ['tipo' => 'radio', 'etichetta' => 'Esame oggetto della richiesta', 'obbligatorio' => 1, 'opzioni' => self::ESAMI_INGLESE],
                ['tipo' => 'file', 'etichetta' => 'Copia della certificazione del livello B1', 'obbligatorio' => 1],
            ],
            $this->chiusuraCarriera()
        );

        return $this->inserisciCarriera(
            'esonero_inglese',
            'Richiesta Esonero dalla Frequenza English for Basic Academic Skills e Inglese',
            '<p>Per chiedere l\'esonero dalla frequenza di English for Basic Academic Skills (o di Inglese, per Scienze e Tecnologie delle attività motorie e sportive) con una certificazione di livello B1. La richiesta arriva all\'Ufficio protocollo, poi all\'ufficio del corso di studio; l\'esito lo decide il Consiglio di corso di studio.</p>',
            $campi,
            self::DESTINATARIO_DIRETTORE_CDS,
            'l\'esonero dalla frequenza dell\'esame di {Esame oggetto della richiesta}, allegando copia della certificazione linguistica di livello B1.',
            self::TESTO_VERBALE_STUDENTE . 'chiede l\'esonero dalla frequenza dell\'esame di {Esame oggetto della richiesta} sulla base della certificazione linguistica allegata.',
            'Il Consiglio, esaminata la certificazione, delibera in merito all\'esonero dalla frequenza.',
            31
        );
    }

    /** «Richiesta Variazione Piano di Studi»: gli insegnamenti da inserire o togliere si scelgono dal catalogo (al posto del PDF da compilare e ricaricare). */
    public function modificaPianoDiStudi(): int
    {
        $campi = array_merge(
            [['tipo' => 'info', 'etichetta' => 'Note operative', 'aiuto' => "Il piano di studi si può modificare a condizione che ti sia iscritto all'anno accademico per il quale chiedi la variazione.\nL'operatore che prende in carico la richiesta ti informa sull'accettazione.\nAll'invio il portale prepara la domanda in PDF/A, con i dati del tuo accesso."]],
            $this->datiCarriera(),
            [
                ['tipo' => 'titolo', 'etichetta' => 'Cosa chiedi', 'aiuto' => 'Che per l\'anno accademico indicato vengano accettate le variazioni del tuo piano di studi.'],
                ['tipo' => 'anno_accademico', 'etichetta' => 'Anno accademico della richiesta', 'obbligatorio' => 1],
                ['tipo' => 'tabella', 'etichetta' => 'Variazioni al piano di studi', 'obbligatorio' => 1, 'opzioni' => ['Insegnamento:insegnamento', 'CFU:cfu', 'S.S.D.:ssd', 'Variazione:scelta(Inserire|Togliere)'],
                 'aiuto' => 'Una riga per ogni insegnamento: scegli l\'insegnamento dal catalogo di Ateneo (CFU e S.S.D. si compilano da soli) e indica se va inserito o tolto dal piano.'],
                ['tipo' => 'file', 'etichetta' => 'Modulo del piano compilato (facoltativo)', 'aiuto' => 'Solo se il tuo corso richiede il modulo in PDF.'],
            ],
            $this->chiusuraCarriera()
        );

        return $this->inserisciCarriera(
            'modifica_piano',
            'Richiesta Variazione Piano di Studi',
            '<p>Per chiedere la variazione del tuo piano di studi per un anno accademico a cui sei iscritto. La richiesta arriva all\'Ufficio protocollo, poi all\'ufficio del corso di studio; l\'esito lo decide il Consiglio di corso di studio.</p>',
            $campi,
            self::DESTINATARIO_CDS,
            'che per l\'Anno Accademico {Anno accademico della richiesta} vengano accettate le seguenti variazioni del proprio piano di studi:',
            self::TESTO_VERBALE_STUDENTE . 'chiede che per l\'a.a. {Anno accademico della richiesta} siano accettate le variazioni del piano di studi indicate nella domanda.',
            'Il Consiglio delibera in merito alle variazioni del piano di studi richieste.',
            32
        );
    }

    /** «Richiesta cambio Classe/Curriculum». */
    public function cambioCurriculum(): int
    {
        $campi = array_merge(
            [['tipo' => 'info', 'etichetta' => 'Note operative', 'aiuto' => "Il piano di studi si può modificare a condizione che ti sia iscritto all'anno accademico per il quale chiedi la variazione.\nL'operatore che prende in carico la richiesta ti informa sull'accettazione.\nAll'invio il portale prepara la domanda in PDF/A, con i dati del tuo accesso."]],
            $this->datiCarriera(),
            [
                ['tipo' => 'titolo', 'etichetta' => 'Cosa chiedi', 'aiuto' => 'Che venga accettata la variazione della classe o del curriculum del tuo piano di studi.'],
                ['tipo' => 'anno_accademico', 'etichetta' => 'Anno accademico della richiesta', 'obbligatorio' => 1],
                ['tipo' => 'text', 'etichetta' => 'Classe o curriculum attuale', 'obbligatorio' => 1],
                ['tipo' => 'text', 'etichetta' => 'Classe o curriculum prescelto', 'obbligatorio' => 1],
            ],
            $this->chiusuraCarriera()
        );

        return $this->inserisciCarriera(
            'cambio_curriculum',
            'Richiesta cambio Classe/Curriculum',
            '<p>Per chiedere il cambio di classe o di curriculum del tuo piano di studi. La richiesta arriva all\'Ufficio protocollo, poi all\'ufficio del corso di studio; l\'esito lo decide il Consiglio di corso di studio.</p>',
            $campi,
            self::DESTINATARIO_CDS,
            'che per l\'Anno Accademico {Anno accademico della richiesta} venga accettata la variazione della classe/curriculum del proprio piano di studi: da {Classe o curriculum attuale} a {Classe o curriculum prescelto}.',
            self::TESTO_VERBALE_STUDENTE . 'chiede per l\'a.a. {Anno accademico della richiesta} il cambio di classe/curriculum da {Classe o curriculum attuale} a {Classe o curriculum prescelto}.',
            'Il Consiglio delibera in merito al cambio di classe/curriculum richiesto.',
            33
        );
    }

    /** «Richiesta Cambio Tempo Pieno / Part-time». */
    public function cambioTempoPienoPartTime(): int
    {
        $impegno = ['Tempo pieno', 'Part-time'];
        $campi = array_merge(
            [['tipo' => 'info', 'etichetta' => 'Note operative', 'aiuto' => "L'operatore che prende in carico la richiesta ti informa sull'accettazione.\nAll'invio il portale prepara la domanda in PDF/A, con i dati del tuo accesso."]],
            $this->datiCarriera(),
            [
                ['tipo' => 'titolo', 'etichetta' => 'Cosa chiedi', 'aiuto' => 'Che venga accettata la variazione dell\'impegno.'],
                ['tipo' => 'anno_accademico', 'etichetta' => 'Anno accademico della richiesta', 'obbligatorio' => 1],
                ['tipo' => 'radio', 'etichetta' => 'Impegno attuale', 'obbligatorio' => 1, 'opzioni' => $impegno],
                ['tipo' => 'radio', 'etichetta' => 'Impegno scelto', 'obbligatorio' => 1, 'opzioni' => $impegno],
            ],
            $this->chiusuraCarriera()
        );

        return $this->inserisciCarriera(
            'tempo_pieno_part_time',
            'Richiesta Cambio Tempo Pieno / Part-time',
            '<p>Per chiedere il passaggio da tempo pieno a part-time o viceversa. La richiesta arriva all\'Ufficio protocollo, poi all\'ufficio del corso di studio; l\'esito lo decide il Consiglio di corso di studio.</p>',
            $campi,
            self::DESTINATARIO_CDS,
            'che per l\'Anno Accademico {Anno accademico della richiesta} venga accettata la variazione dell\'impegno: da {Impegno attuale} a {Impegno scelto}.',
            self::TESTO_VERBALE_STUDENTE . 'chiede per l\'a.a. {Anno accademico della richiesta} la variazione dell\'impegno da {Impegno attuale} a {Impegno scelto}.',
            'Il Consiglio delibera in merito alla variazione dell\'impegno richiesta.',
            34
        );
    }

    /** «Richiesta inserimento insegnamenti FUORI PIANO». */
    public function esamiFuoriPiano(): int
    {
        $campi = array_merge(
            [['tipo' => 'info', 'etichetta' => 'Note operative', 'aiuto' => "Gli insegnamenti fuori piano si inseriscono ai sensi delle vigenti disposizioni del Regolamento Didattico di Ateneo.\nAll'invio il portale prepara la domanda in PDF/A, con i dati del tuo accesso."]],
            $this->datiCarriera(),
            [
                ['tipo' => 'titolo', 'etichetta' => 'Cosa chiedi', 'aiuto' => 'Che vengano inseriti i seguenti insegnamenti FUORI PIANO.'],
                ['tipo' => 'tabella', 'etichetta' => 'Insegnamenti da inserire', 'obbligatorio' => 1,
                 'opzioni' => ['Codice insegnamento:codice', 'Denominazione insegnamento:denominazione', 'CFU:cfu', 'S.S.D.:ssd', 'Corso di laurea:testo'],
                 'aiuto' => 'Una riga per ogni insegnamento; con «Aggiungi riga» ne inserisci altri.'],
            ],
            $this->chiusuraCarriera()
        );

        return $this->inserisciCarriera(
            'esami_fuori_piano',
            'Richiesta inserimento insegnamenti FUORI PIANO',
            '<p>Per chiedere l\'inserimento di insegnamenti fuori piano. La richiesta arriva all\'Ufficio protocollo, poi all\'ufficio del corso di studio; l\'esito lo decide il Consiglio di corso di studio.</p>',
            $campi,
            self::DESTINATARIO_CDS,
            'ai sensi delle vigenti disposizioni contenute nel Regolamento Didattico di Ateneo, che vengano inseriti i seguenti insegnamenti FUORI PIANO:',
            self::TESTO_VERBALE_STUDENTE . 'chiede, ai sensi del Regolamento Didattico di Ateneo, l\'inserimento di insegnamenti fuori piano, indicati nella domanda.',
            'Il Consiglio delibera in merito all\'inserimento degli insegnamenti fuori piano.',
            35
        );
    }

    /** «Richiesta Anticipo Insegnamenti»: gli insegnamenti da anticipare si scelgono dal catalogo (al posto del PDF da compilare e ricaricare). */
    public function anticipoInsegnamenti(): int
    {
        $campi = array_merge(
            [['tipo' => 'info', 'etichetta' => 'Note operative', 'aiuto' => "L'operatore che prende in carico la richiesta ti informa sull'accettazione.\nAll'invio il portale prepara la domanda in PDF/A, con i dati del tuo accesso."]],
            $this->datiCarriera(),
            [
                ['tipo' => 'titolo', 'etichetta' => 'Cosa chiedi', 'aiuto' => 'L\'anticipo degli insegnamenti indicati di seguito.'],
                ['tipo' => 'anno_accademico', 'etichetta' => 'Anno accademico della richiesta', 'obbligatorio' => 1],
                ['tipo' => 'tabella', 'etichetta' => 'Insegnamenti da anticipare', 'obbligatorio' => 1, 'opzioni' => ['Insegnamento:insegnamento', 'CFU:cfu', 'S.S.D.:ssd'],
                 'aiuto' => 'Una riga per ogni insegnamento: scegli l\'insegnamento dal catalogo di Ateneo (CFU e S.S.D. si compilano da soli).'],
                ['tipo' => 'file', 'etichetta' => 'Modulo degli insegnamenti compilato (facoltativo)', 'aiuto' => 'Solo se il tuo corso richiede il modulo in PDF.'],
            ],
            $this->chiusuraCarriera('Note (es. motivazioni della richiesta)')
        );

        return $this->inserisciCarriera(
            'anticipo_insegnamenti',
            'Richiesta Anticipo Insegnamenti',
            '<p>Per chiedere l\'anticipo di insegnamenti di anni successivi. La richiesta arriva all\'Ufficio protocollo, poi all\'ufficio del corso di studio; l\'esito lo decide il Consiglio di corso di studio.</p>',
            $campi,
            self::DESTINATARIO_CDS,
            'per l\'Anno Accademico {Anno accademico della richiesta}, l\'anticipo degli insegnamenti indicati di seguito:',
            self::TESTO_VERBALE_STUDENTE . 'chiede per l\'a.a. {Anno accademico della richiesta} l\'anticipo degli insegnamenti indicati nella domanda.',
            'Il Consiglio delibera in merito all\'anticipo degli insegnamenti richiesto.',
            36
        );
    }

    /**
     * «Richiesta riconoscimento titolo di studio conseguito all'estero»: va al Direttore; il corso di destinazione serve per mandare la pratica all'ufficio giusto.
     */
    public function titoloEstero(): int
    {
        $campi = array_merge(
            [['tipo' => 'info', 'etichetta' => 'Note operative', 'aiuto' => "Alla richiesta si allegano la dichiarazione di valore (o il Diploma Supplement), il certificato degli esami sostenuti e i loro programmi.\nAll'invio il portale prepara la domanda in PDF/A, con i dati del tuo accesso."]],
            array_slice($this->datiRichiedente(), 0, 4),
            [
                ['tipo' => 'tel', 'etichetta' => 'Cellulare', 'obbligatorio' => 1],
                ['tipo' => 'email', 'etichetta' => 'Email personale', 'obbligatorio' => 1],
                ['tipo' => 'titolo', 'etichetta' => 'Titolo conseguito all\'estero'],
                ['tipo' => 'textarea', 'etichetta' => 'Università estera', 'obbligatorio' => 1, 'aiuto' => 'L\'università presso cui hai conseguito il titolo, con il Paese'],
                ['tipo' => 'text', 'etichetta' => 'Corso di laurea conseguito', 'obbligatorio' => 1],
                ['tipo' => 'corso_studio', 'etichetta' => 'Corso di destinazione', 'obbligatorio' => 1, 'aiuto' => 'Il corso di laurea del DiBEST per cui chiedi il riconoscimento: la pratica va all\'ufficio di quel corso.'],
                ['tipo' => 'titolo', 'etichetta' => 'Allegati'],
                ['tipo' => 'file', 'etichetta' => 'Dichiarazione di valore o Diploma Supplement', 'obbligatorio' => 1],
                ['tipo' => 'file', 'etichetta' => 'Certificato degli esami sostenuti', 'obbligatorio' => 1],
                ['tipo' => 'file', 'etichetta' => 'Programmi degli esami sostenuti', 'obbligatorio' => 1],
            ],
            $this->chiusuraCarriera()
        );

        return $this->inserisciCarriera(
            'titolo_estero',
            'Richiesta riconoscimento titolo di studio conseguito all\'estero',
            '<p>Per chiedere il riconoscimento di un titolo di studio conseguito all\'estero. La richiesta arriva all\'Ufficio protocollo, poi all\'ufficio del corso di studio; l\'esito lo decide il Consiglio di corso di studio.</p>',
            $campi,
            "Al Direttore del DiBEST\nUNICAL\nSEDE",
            'il riconoscimento del titolo di studio conseguito all\'estero presso l\'università di {Università estera}, corso di laurea in {Corso di laurea conseguito}, ai fini dell\'iscrizione al {Corso di destinazione}.',
            '{STUDENTE}, con domanda prot. n. {PROTOCOLLO}, chiede il riconoscimento del titolo di studio conseguito all\'estero presso {Università estera} (corso di laurea in {Corso di laurea conseguito}), ai fini dell\'iscrizione al {Corso di destinazione}.',
            'Il Consiglio, esaminata la documentazione, delibera in merito al riconoscimento del titolo.',
            37
        );
    }

    private const CATEGORIA_LAUREE = 'Lauree';
    private const CATEGORIA_MOBILITA = 'Mobilità internazionale';

    /** Le tre richieste della pratica «Richiesta Tesi». */
    public const TIPI_TESI = ['Richiesta tesi', 'Richiesta tesi LMCU Conservazione e Restauro dei Beni Culturali', 'Variazione della richiesta di tesi'];

    /** Dopo la conferma del relatore: protocollo → ufficio del corso (referente), poi il verbale. */
    private const ITER_TESI = [['t' => 'conferma', 'campo' => 'Relatore/trice'], 'protocollo', '@cdl'];

    /** Senza il relatore: protocollo → ufficio del corso (referente), poi il verbale. */
    private const ITER_REFERENTE = ['protocollo', '@cdl'];

    /** Mobilità internazionale: protocollo → ufficio Internazionalizzazione (referente della mobilità) → ufficio del corso (referente) → verbale → segreteria. */
    private const ITER_MOBILITA = ['protocollo', ['t' => 'ufficio', 'u' => 'internazionalizzazione'], '@cdl', '@segreteria'];

    /**
     * «Richiesta Tesi»: una sola pratica per la richiesta di tesi, quella del corso di Conservazione e Restauro e la variazione della richiesta; lo studente
     * sceglie il tipo e compaiono i campi di quel tipo. Il relatore conferma nel portale (link personale, accesso di Ateneo) e poi la pratica va al protocollo
     * e al referente del corso; passa nel verbale.
     */
    public function tesi(): int
    {
        [$tesi, $restauro, $variazione] = self::TIPI_TESI;
        $tipo = 'Tipo di richiesta';
        $c = static fn (string $op, string $v): array => ['campo' => $tipo, 'op' => $op, 'valore' => $v];
        $interna = ['campo' => 'Sede del lavoro di tesi', 'op' => 'uguale', 'valore' => 'Interna'];
        $esterna = ['campo' => 'Sede del lavoro di tesi', 'op' => 'uguale', 'valore' => 'Esterna'];
        $cosa = static fn (string $v): array => ['campo' => 'Cosa varia', 'op' => 'contiene', 'valore' => $v];
        $campi = [
            ['tipo' => 'info', 'etichetta' => 'Come funziona', 'aiuto' => "La richiesta si compila e si invia da qui: non serve più generare e mandare un link.\nAll'invio il relatore (o la relatrice) riceve un'email con il link per confermare con le credenziali di Ateneo; dopo la conferma la richiesta va al protocollo e al referente del corso di studio.\nIl relatore va scelto dall'elenco del personale: deve avere l'email in anagrafe."],
            ['tipo' => 'titolo', 'etichetta' => 'Che richiesta presenti'],
            ['tipo' => 'select', 'etichetta' => $tipo, 'obbligatorio' => 1, 'opzioni' => self::TIPI_TESI],
            ['tipo' => 'titolo', 'etichetta' => 'Dati dello studente'],
            ['tipo' => 'text', 'etichetta' => 'Luogo di nascita', 'obbligatorio' => 1, 'aiuto' => 'Comune (provincia) o Stato estero'],
            ['tipo' => 'date', 'etichetta' => 'Data di nascita', 'obbligatorio' => 1],
            ['tipo' => 'tel', 'etichetta' => 'Cellulare', 'obbligatorio' => 1],
            ['tipo' => 'anno_accademico', 'etichetta' => 'Iscritto per l\'A.A.', 'obbligatorio' => 1],
            ['tipo' => 'text', 'etichetta' => 'Matricola', 'obbligatorio' => 1],
            ['tipo' => 'corso_studio', 'etichetta' => 'Al Corso di Laurea in', 'obbligatorio' => 1, 'aiuto' => 'Scegli il corso dall\'elenco di Ateneo: la pratica va all\'ufficio di quel corso.'],
            ['tipo' => 'number', 'etichetta' => 'Crediti acquisiti', 'obbligatorio' => 1, 'cond' => $c('uguale', $tesi)],
            ['tipo' => 'select', 'etichetta' => 'Anno di corso', 'obbligatorio' => 1, 'opzioni' => ['1° anno', '2° anno', '3° anno', '4° anno', '5° anno'], 'cond' => $c('uguale', $restauro)],
            ['tipo' => 'titolo', 'etichetta' => 'Variazione: a quale richiesta si riferisce', 'cond' => $c('uguale', $variazione)],
            ['tipo' => 'select', 'etichetta' => 'Tipo di numerazione', 'obbligatorio' => 1, 'opzioni' => ['Protocollo', 'Decreto', 'Delibera'], 'cond' => $c('uguale', $variazione), 'aiuto' => 'Se la numerazione della richiesta di tesi è un protocollo, un decreto o una delibera'],
            ['tipo' => 'text', 'etichetta' => 'Numero di protocollo, decreto o delibera', 'obbligatorio' => 1, 'cond' => $c('uguale', $variazione)],
            ['tipo' => 'date', 'etichetta' => 'Data di protocollo, decreto o delibera', 'obbligatorio' => 1, 'cond' => $c('uguale', $variazione)],
            ['tipo' => 'titolo', 'etichetta' => 'Il lavoro finale', 'aiuto' => 'Sulla base di quanto previsto dal Regolamento Didattico del Corso di Studio.'],
            ['tipo' => 'textarea', 'etichetta' => 'Progetto formativo', 'obbligatorio' => 1, 'cond' => $c('diverso', $variazione), 'aiuto' => 'Il lavoro finale che chiedi di svolgere'],
            ['tipo' => 'radio', 'etichetta' => 'Sede del lavoro di tesi', 'obbligatorio' => 1, 'opzioni' => ['Interna', 'Esterna'], 'cond' => $c('uguale', $tesi)],
            ['tipo' => 'text', 'etichetta' => 'Laboratorio DiBEST', 'cond' => $interna, 'aiuto' => 'Il laboratorio DiBEST in cui si svolge il lavoro finale'],
            ['tipo' => 'textarea', 'etichetta' => 'Laboratorio UNICAL', 'cond' => $interna, 'aiuto' => 'Nome del laboratorio e cubo'],
            ['tipo' => 'text', 'etichetta' => 'Azienda o ente', 'cond' => $esterna],
            ['tipo' => 'text', 'etichetta' => 'Riferimento convenzione', 'cond' => $esterna],
            ['tipo' => 'multicheck', 'etichetta' => 'Cosa varia', 'obbligatorio' => 1, 'opzioni' => ['Progetto formativo', 'Laboratorio', 'Relatore/trice o correlatori/trici'], 'cond' => $c('uguale', $variazione)],
            ['tipo' => 'textarea', 'etichetta' => 'Nuovo progetto formativo', 'obbligatorio' => 1, 'cond' => $cosa('progetto formativo')],
            ['tipo' => 'text', 'etichetta' => 'Nuovo laboratorio', 'obbligatorio' => 1, 'cond' => $cosa('laboratorio')],
            ['tipo' => 'text', 'etichetta' => 'Nuovi relatore/trice o correlatori/trici', 'obbligatorio' => 1, 'cond' => $cosa('relatore'), 'aiuto' => 'Separa i nomi con una virgola, indicando chi è relatore e chi correlatore'],
            ['tipo' => 'titolo', 'etichetta' => 'Relatore e correlatori'],
            ['tipo' => 'docente', 'etichetta' => 'Relatore/trice', 'obbligatorio' => 1, 'aiuto' => 'Scegli il nome dall\'elenco: riceve il link per confermare la richiesta. Per Conservazione e Restauro: il relatore della prova pratico-laboratoriale. Per una variazione: il relatore attuale.'],
            ['tipo' => 'docente', 'etichetta' => 'Correlatore/trice interno', 'cond' => $c('diverso', $variazione), 'aiuto' => 'Se presente'],
            ['tipo' => 'text', 'etichetta' => 'Correlatore/trice esterno', 'cond' => $c('uguale', $tesi), 'aiuto' => 'Se presente'],
            ['tipo' => 'docente', 'etichetta' => 'Relatore prova teorico-metodologica (1)', 'obbligatorio' => 1, 'cond' => $c('uguale', $restauro)],
            ['tipo' => 'text', 'etichetta' => 'Area (1)', 'obbligatorio' => 1, 'cond' => $c('uguale', $restauro)],
            ['tipo' => 'docente', 'etichetta' => 'Relatore prova teorico-metodologica (2)', 'obbligatorio' => 1, 'cond' => $c('uguale', $restauro)],
            ['tipo' => 'text', 'etichetta' => 'Area (2)', 'obbligatorio' => 1, 'cond' => $c('uguale', $restauro)],
            ['tipo' => 'text', 'etichetta' => 'Correlatore', 'cond' => $c('uguale', $restauro)],
            ['tipo' => 'file', 'etichetta' => 'Proposta abstract del progetto di tirocinio in italiano (max 300 parole)', 'obbligatorio' => 1, 'cond' => $c('uguale', $restauro)],
            ['tipo' => 'checkbox', 'etichetta' => 'Sono a conoscenza che il lavoro di tesi ha una durata minima di 8 mesi', 'cond' => $c('uguale', $tesi), 'aiuto' => 'Solo gli studenti delle magistrali di Biologia e di Biotecnologie per la salute devono spuntare la casella.'],
            ['tipo' => 'textarea', 'etichetta' => 'Note'],
            $this->dichiarazione(),
        ];
        $verbale = ['sezione' => 'Lavoro finale (tesi)', 'stile' => 'scheda',
                    'testo' => self::TESTO_VERBALE_STUDENTE . 'presenta la richiesta «{Tipo di richiesta}» (relatore/trice: {Relatore/trice}).',
                    'delibera' => 'Il Consiglio delibera in merito alla richiesta relativa al lavoro finale.'];
        $domanda = ['pdf' => 1, 'bollo' => 0, 'oggetto' => 'Domanda Lavoro Finale (Richiesta TESI)', 'destinatario' => self::DESTINATARIO_CDS,
                    'chiede' => '{Tipo di richiesta}, sulla base di quanto previsto dal Regolamento Didattico del Corso di Studio, con relatore/trice {Relatore/trice}, come indicato di seguito:'];

        return $this->inserisci(
            'tesi',
            'Richiesta Tesi',
            '<p>Per chiedere l\'assegnazione del lavoro finale (tesi), anche per Conservazione e Restauro dei Beni Culturali, o per comunicare una variazione di una richiesta già approvata. Il relatore conferma dal portale; poi la richiesta arriva all\'Ufficio protocollo e al referente del corso di studio, e passa nel verbale del Consiglio.</p>',
            $campi,
            $domanda,
            self::ITER_TESI,
            40,
            self::CATEGORIA_LAUREE,
            $verbale
        );
    }

    /** «Richiesta cancellazione errata iscrizione»: va al Direttore e/o al Coordinatore, poi al referente del corso e al verbale. */
    public function cancellazioneIscrizione(): int
    {
        $campi = array_merge(
            [['tipo' => 'info', 'etichetta' => 'Note operative', 'aiuto' => "All'invio il portale prepara la domanda in PDF/A, con i dati del tuo accesso."]],
            $this->datiCarriera(),
            [
                ['tipo' => 'titolo', 'etichetta' => 'Cosa chiedi', 'aiuto' => 'La cancellazione dell\'iscrizione erroneamente effettuata, perché intendi laurearti nella sessione indicata.'],
                ['tipo' => 'anno_accademico', 'etichetta' => 'Anno accademico dell\'iscrizione da cancellare', 'obbligatorio' => 1],
                ['tipo' => 'text', 'etichetta' => 'Sessione di laurea', 'obbligatorio' => 1, 'aiuto' => 'La sessione in cui intendi laurearti'],
                ['tipo' => 'anno_accademico', 'etichetta' => 'Anno accademico della sessione di laurea', 'obbligatorio' => 1],
                ['tipo' => 'number', 'etichetta' => 'Esami ancora da sostenere', 'obbligatorio' => 1, 'aiuto' => 'Quanti esami devi sostenere (escluso il tirocinio e la prova finale) per il conseguimento del titolo di studio'],
            ],
            $this->chiusuraCarriera()
        );

        return $this->inserisciCarriera(
            'cancellazione_iscrizione',
            'Richiesta cancellazione errata iscrizione',
            '<p>Per chiedere la cancellazione di un\'iscrizione effettuata per errore, quando intendi laurearti in una sessione precedente. La richiesta arriva all\'Ufficio protocollo e al referente del corso di studio, e passa nel verbale.</p>',
            $campi,
            self::DESTINATARIO_DIRETTORE_CDS,
            'la cancellazione dell\'iscrizione all\'A.A. {Anno accademico dell\'iscrizione da cancellare}, erroneamente effettuata in quanto intende laurearsi nella sessione di {Sessione di laurea} (a.a. {Anno accademico della sessione di laurea}). Dichiara di dover sostenere n. {Esami ancora da sostenere} esami (escluso il tirocinio e la prova finale) per il conseguimento del titolo di studio.',
            self::TESTO_VERBALE_STUDENTE . 'chiede la cancellazione dell\'iscrizione all\'a.a. {Anno accademico dell\'iscrizione da cancellare}, perché intende laurearsi nella sessione di {Sessione di laurea} (a.a. {Anno accademico della sessione di laurea}); esami da sostenere: {Esami ancora da sostenere}.',
            'Il Consiglio delibera in merito alla cancellazione dell\'iscrizione.',
            41,
            false,
            self::ITER_REFERENTE
        );
    }

    /** «Richiesta esonero dalla frequenza delle lezioni»: motivi di salute, mobilità internazionale o ammissione ritardata (un solo modulo). */
    public function esoneroFrequenza(): int
    {
        $campi = array_merge(
            [['tipo' => 'info', 'etichetta' => 'Note operative', 'aiuto' => "Alla richiesta va allegato un documento che attesti la condizione dichiarata (certificato medico, learning agreement, autocertificazione di iscrizione scaricabile da Esse3). Le dichiarazioni e la documentazione sono fornite sotto la piena responsabilità del dichiarante, che ne risponde anche penalmente in caso di dichiarazione falsa o mendace (art. 76 del DPR 445/2000).\nAll'invio il portale prepara la domanda in PDF/A, con i dati del tuo accesso."]],
            $this->datiCarriera(),
            [
                ['tipo' => 'titolo', 'etichetta' => 'Cosa chiedi', 'aiuto' => 'L\'esonero dalla frequenza delle lezioni per:'],
                ['tipo' => 'radio', 'etichetta' => 'Motivo dell\'esonero', 'obbligatorio' => 1, 'opzioni' => ['Motivi di salute', 'Mobilità internazionale', 'Ammissione ritardata']],
                ['tipo' => 'file', 'etichetta' => 'Attestato che prova la condizione dichiarata', 'obbligatorio' => 1, 'aiuto' => 'Certificato medico, learning agreement o autocertificazione di iscrizione scaricabile da Esse3.'],
            ],
            $this->chiusuraCarriera()
        );

        return $this->inserisciCarriera(
            'esonero_frequenza',
            'Richiesta esonero dalla frequenza delle lezioni',
            '<p>Per chiedere l\'esonero dalla frequenza delle lezioni per motivi di salute, per mobilità internazionale o per ammissione ritardata. La richiesta arriva all\'Ufficio protocollo e al referente del corso di studio, e passa nel verbale.</p>',
            $campi,
            self::DESTINATARIO_CDS,
            'l\'esonero dalla frequenza delle lezioni per: {Motivo dell\'esonero}.',
            self::TESTO_VERBALE_STUDENTE . 'chiede l\'esonero dalla frequenza delle lezioni per: {Motivo dell\'esonero}.',
            'Il Consiglio, esaminata la documentazione, delibera in merito all\'esonero dalla frequenza.',
            42,
            false,
            self::ITER_REFERENTE
        );
    }

    /**
     * I dati dello studente nelle pratiche di mobilità: come quelli di carriera, con l'email personale.
     *
     * @return list<array<string, mixed>>
     */
    private function datiMobilita(): array
    {
        $dati = $this->datiCarriera();
        array_splice($dati, 3, 0, [['tipo' => 'email', 'etichetta' => 'Email personale', 'obbligatorio' => 1]]);

        return $dati;
    }

    private const PROGRAMMI_MOBILITA = ['Studio', 'Short Term', 'Traineeship', 'MOST', 'Erasmus Italiano'];

    private const NOTA_VARIAZIONI_MOBILITA = 'Eventuali successive variazioni dovranno essere comunicate tempestivamente al Coordinatore Erasmus del corso di studio e alla Dott.ssa Micol Pellegrini (micol.pellegrini@unical.it).';

    /** «Svolgimento attività di studio all'estero»: comunicazione al Consiglio con il Learning Agreement. */
    public function mobilitaSvolgimento(): int
    {
        $variaPiano = ['campo' => 'Chiedo la variazione degli insegnamenti a scelta del piano di studi', 'op' => 'uguale', 'valore' => 'Sì'];
        $campi = array_merge(
            [['tipo' => 'info', 'etichetta' => 'Note operative', 'aiuto' => "Comunichi al Consiglio di corso di studio che svolgerai un periodo di studi all'estero, secondo il Learning Agreement approvato dal Coordinatore Erasmus del tuo corso.\n" . self::NOTA_VARIAZIONI_MOBILITA . "\nAll'invio il portale prepara la domanda in PDF/A, con i dati del tuo accesso."]],
            $this->datiMobilita(),
            [
                ['tipo' => 'titolo', 'etichetta' => 'Periodo di studi all\'estero'],
                ['tipo' => 'radio', 'etichetta' => 'Programma', 'obbligatorio' => 1, 'opzioni' => self::PROGRAMMI_MOBILITA],
                ['tipo' => 'text', 'etichetta' => 'Sede ospitante', 'obbligatorio' => 1, 'aiuto' => 'Università o ente presso cui svolgerai il periodo, con il Paese'],
                ['tipo' => 'date', 'etichetta' => 'Periodo dal', 'obbligatorio' => 1],
                ['tipo' => 'date', 'etichetta' => 'Periodo al', 'obbligatorio' => 1],
                ['tipo' => 'multicheck', 'etichetta' => 'Attività all\'estero', 'obbligatorio' => 1, 'opzioni' => ['Frequentare le lezioni e sostenere gli esami', 'Svolgere lavoro di ricerca per la tesi', 'Svolgere lavoro di tirocinio']],
                ['tipo' => 'text', 'etichetta' => 'Coordinatore Erasmus del corso (Prof.)', 'obbligatorio' => 1, 'aiuto' => 'Il docente che ha approvato il Learning Agreement'],
                ['tipo' => 'text', 'etichetta' => 'Del corso di studio in', 'obbligatorio' => 1],
                ['tipo' => 'checkbox', 'etichetta' => 'Chiedo la variazione degli insegnamenti a scelta del piano di studi', 'aiuto' => 'Chiedo di considerare questa comunicazione ai fini di un\'eventuale convalida dell\'attività svolta nella sede ospitante, in base al Transcript of Records o al Traineeship Certificate rilasciato a fine periodo.'],
                ['tipo' => 'tabella', 'etichetta' => 'Insegnamenti da eliminare', 'cond' => $variaPiano, 'opzioni' => ['Insegnamento:insegnamento', 'CFU:cfu', 'S.S.D.:ssd'], 'aiuto' => 'Se presenti.'],
                ['tipo' => 'tabella', 'etichetta' => 'Insegnamenti da inserire', 'cond' => $variaPiano, 'opzioni' => ['Insegnamento:insegnamento', 'CFU:cfu', 'S.S.D.:ssd']],
                ['tipo' => 'titolo', 'etichetta' => 'Allegato'],
                ['tipo' => 'file', 'etichetta' => 'Learning Agreement', 'obbligatorio' => 1],
            ],
            $this->chiusuraCarriera()
        );

        return $this->inserisciCarriera(
            'mobilita_svolgimento',
            'Svolgimento attività di studio all\'estero',
            '<p>Per comunicare al Consiglio di corso di studio che svolgerai un periodo di studio, tirocinio o ricerca all\'estero (Erasmus, Short Term, Traineeship, MOST, Erasmus Italiano), con il Learning Agreement approvato. La comunicazione passa dal referente della mobilità internazionale e dal referente del corso di studio, poi nel verbale e alla segreteria.</p>',
            $campi,
            "Al Consiglio di Corso di Studio\nUNICAL\nSEDE",
            'di informare il Consiglio che svolgerà un periodo di studi all\'estero nell\'ambito del programma {Programma}, presso {Sede ospitante}, dal {Periodo dal} al {Periodo al}, secondo il Learning Agreement approvato dal Coordinatore Erasmus del corso di laurea (prof. {Coordinatore Erasmus del corso (Prof.)}).',
            self::TESTO_VERBALE_STUDENTE . 'comunica che svolgerà un periodo di studi all\'estero ({Programma}) presso {Sede ospitante}, dal {Periodo dal} al {Periodo al}, secondo il Learning Agreement allegato.',
            'Il Consiglio prende atto della comunicazione e approva il Learning Agreement.',
            50,
            false,
            self::ITER_MOBILITA,
            self::CATEGORIA_MOBILITA
        );
    }

    /** «Variazione attività di studio all'estero». */
    public function mobilitaVariazione(): int
    {
        $campi = array_merge(
            [['tipo' => 'info', 'etichetta' => 'Note operative', 'aiuto' => self::NOTA_VARIAZIONI_MOBILITA . "\nAll'invio il portale prepara la domanda in PDF/A, con i dati del tuo accesso."]],
            $this->datiCarriera(),
            [
                ['tipo' => 'titolo', 'etichetta' => 'Cosa chiedi'],
                ['tipo' => 'radio', 'etichetta' => 'Programma di mobilità', 'obbligatorio' => 1, 'opzioni' => self::PROGRAMMI_MOBILITA],
                ['tipo' => 'textarea', 'etichetta' => 'Variazione richiesta', 'obbligatorio' => 1, 'aiuto' => 'Descrivi la variazione (insegnamenti, periodo, sede o altro).'],
                ['tipo' => 'file', 'etichetta' => 'Learning Agreement o autorizzazione dell\'ufficio internazionalizzazione', 'obbligatorio' => 1],
            ],
            $this->chiusuraCarriera()
        );

        return $this->inserisciCarriera(
            'mobilita_variazione',
            'Variazione attività di studio all\'estero',
            '<p>Per comunicare una variazione dell\'attività di studio all\'estero già comunicata. La richiesta passa dal referente della mobilità internazionale e dal referente del corso di studio, poi nel verbale e alla segreteria.</p>',
            $campi,
            self::DESTINATARIO_DIRETTORE_CDS,
            'la seguente variazione dell\'attività di studio all\'estero ({Programma di mobilità}): {Variazione richiesta}',
            self::TESTO_VERBALE_STUDENTE . 'comunica una variazione dell\'attività di studio all\'estero ({Programma di mobilità}): {Variazione richiesta}',
            'Il Consiglio delibera in merito alla variazione dell\'attività di studio all\'estero.',
            51,
            false,
            self::ITER_MOBILITA,
            self::CATEGORIA_MOBILITA
        );
    }

    /** «Comunicazione fine attività di studio all'estero», con il TOR o il Traineeship Certificate. */
    public function mobilitaFine(): int
    {
        $campi = array_merge(
            [['tipo' => 'info', 'etichetta' => 'Note operative', 'aiuto' => "Alla comunicazione si allega la documentazione di fine attività.\nAll'invio il portale prepara la domanda in PDF/A, con i dati del tuo accesso."]],
            $this->datiCarriera(),
            [
                ['tipo' => 'titolo', 'etichetta' => 'Documentazione di fine attività'],
                ['tipo' => 'select', 'etichetta' => 'Documento allegato', 'obbligatorio' => 1, 'opzioni' => ['Transcript of Records (TOR)', 'Traineeship Certificate']],
                ['tipo' => 'file', 'etichetta' => 'TOR o Traineeship Certificate', 'obbligatorio' => 1],
            ],
            $this->chiusuraCarriera()
        );

        return $this->inserisciCarriera(
            'mobilita_fine',
            'Comunicazione fine attività di studio all\'estero',
            '<p>Per comunicare la fine dell\'attività di studio all\'estero, allegando il Transcript of Records o il Traineeship Certificate. La comunicazione passa dal referente della mobilità internazionale e dal referente del corso di studio, poi nel verbale e alla segreteria.</p>',
            $campi,
            self::DESTINATARIO_DIRETTORE_CDS,
            'di comunicare la fine dell\'attività di studio all\'estero, allegando la documentazione di fine attività ({Documento allegato}).',
            self::TESTO_VERBALE_STUDENTE . 'comunica la fine dell\'attività di studio all\'estero, con la documentazione allegata ({Documento allegato}).',
            'Il Consiglio prende atto della comunicazione di fine attività e delibera sul riconoscimento dei crediti.',
            52,
            false,
            self::ITER_MOBILITA,
            self::CATEGORIA_MOBILITA
        );
    }
}
