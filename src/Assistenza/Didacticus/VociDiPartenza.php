<?php

declare(strict_types=1);

namespace App\Assistenza\Didacticus;

use App\Core\Database;

/**
 * Le voci con cui Didacticus parte: descrivono solo come si usa il portale (dove cliccare, quali pagine, come funziona l'iter), senza importi, scadenze o regole di
 * Ateneo, che cambiano e vanno scritte da chi le conosce. Si inseriscono una sola volta; da lì in poi si modificano, si disattivano o si cancellano dal pannello
 * (Didattica → Didacticus), dove si aggiungono le voci vere del Dipartimento, con i link e gli allegati.
 */
final class VociDiPartenza
{
    /** @return list<array{argomento: string, titolo: string, domande: string, parole_chiave: string, risposta: string, link: list<array{e: string, u: string}>, proponi_operatore?: bool, solo_login?: bool}> */
    public static function elenco(): array
    {
        return [
            ['argomento' => 'accesso', 'titolo' => 'Come accedo al portale',
                'domande' => "Come accedo al portale\nNon riesco ad entrare\nCome faccio il login\nCome mi autentico\nAccesso con credenziali Unical\nAccesso con SPID o CIE",
                'parole_chiave' => "login, accedere, credenziali, spid, cie, sso, autenticazione",
                'risposta' => "Studenti, docenti e personale entrano con il pulsante «Accedi» in alto a destra, usando le credenziali Unical.\n\nScuole, aziende ed esterni non hanno bisogno di registrarsi: per le convenzioni ricevono per email un link personale; i docenti che iscrivono una classe accedono con SPID o CIE.\n\nSe le credenziali Unical non funzionano, prova prima il recupero delle credenziali dal portale di Ateneo; se il problema resta, scrivi a un operatore.",
                'link' => [['e' => 'Accedi al portale', 'u' => 'saml_login.php']], 'proponi_operatore' => true],
            ['argomento' => 'accesso', 'titolo' => 'Non ricevo le email del portale',
                'domande' => "Non mi arrivano le email\nNon ho ricevuto l'email di conferma\nEmail del portale non arriva\nNon trovo la mail di conferma della prenotazione",
                'parole_chiave' => "email, mail, posta, spam, conferma, non arriva, non ricevo",
                'risposta' => "Controlla la cartella dello spam o della posta indesiderata e cerca il mittente del portale.\n\nTutto quello che ti è stato inviato (prenotazioni, pratiche, attestati) lo ritrovi comunque nella tua Area personale dopo l'accesso, senza bisogno dell'email.\n\nSe l'email non c'è davvero, scrivi a un operatore indicando l'indirizzo con cui hai fatto la richiesta.",
                'link' => [['e' => 'La mia Area personale', 'u' => 'area_personale.php']], 'proponi_operatore' => true],
            ['argomento' => 'pratiche', 'titolo' => 'Come presento una pratica o una richiesta',
                'domande' => "Come presento una pratica\nCome faccio una richiesta alla segreteria\nDove trovo i moduli\nDove compilo la richiesta online\nModulistica dei corsi di studio",
                'parole_chiave' => "pratica, richiesta, modulo, moduli, modulistica, segreteria didattica, presentare",
                'risposta' => "Le richieste agli uffici si presentano online dalla pagina «Modulistica»: scegli il modulo, compila i campi, allega i documenti richiesti e invia.\n\nDopo l'invio la pratica compare in «Le mie pratiche», dove vedi lo stato, la cronologia e i messaggi con l'ufficio, e ricevi un'email a ogni passaggio importante.",
                'link' => [['e' => 'Modulistica', 'u' => 'modulistica.php'], ['e' => 'Le mie pratiche', 'u' => 'pratiche.php']]],
            ['argomento' => 'pratiche', 'titolo' => 'Dove vedo a che punto è la mia pratica',
                'domande' => "Dove vedo lo stato della mia pratica\nA che punto è la mia pratica\nCome controllo la pratica\nLa mia pratica è ferma\nQuanto ci vuole per la pratica",
                'parole_chiave' => "stato pratica, a che punto, pratica ferma, tempi pratica, quanto tempo, sollecito",
                'risposta' => "Apri «Le mie pratiche»: per ogni pratica trovi lo stato attuale, lo storico dei passaggi e i messaggi dell'ufficio. Ogni cambio di stato ti arriva anche per email.\n\nI tempi dipendono dal tipo di pratica e dall'ufficio che la lavora. Se ti sembra ferma da troppo, scrivi un messaggio dalla pagina della pratica oppure parla con un operatore.",
                'link' => [['e' => 'Le mie pratiche', 'u' => 'pratiche.php']], 'proponi_operatore' => true],
            ['argomento' => 'pratiche', 'titolo' => 'Posso modificare una pratica già inviata',
                'domande' => "Posso modificare la pratica\nHo sbagliato a compilare il modulo\nCome correggo una pratica inviata\nVoglio cambiare la richiesta\nCome annullo una pratica",
                'parole_chiave' => "modificare, correggere, sbagliato, errore nel modulo, cambiare richiesta, annullare pratica",
                'risposta' => "Sì, finché l'ufficio non l'ha presa in carico: apri la pratica da «Le mie pratiche» e usa «Modifica la richiesta».\n\nDopo la presa in carico non si modifica più da sola: scrivi un messaggio all'ufficio dalla pagina della pratica, spiegando cosa va corretto.",
                'link' => [['e' => 'Le mie pratiche', 'u' => 'pratiche.php']], 'proponi_operatore' => true],
            ['argomento' => 'pratiche', 'titolo' => "L'ufficio mi ha chiesto un'integrazione",
                'domande' => "L'ufficio mi ha chiesto un'integrazione\nMi hanno chiesto altri documenti\nCome invio documenti aggiuntivi alla pratica\nCome allego un file alla pratica\nIntegrazione documentazione",
                'parole_chiave' => "integrazione, integrare, documenti aggiuntivi, allegare, allegato, documentazione",
                'risposta' => "Apri la pratica da «Le mie pratiche»: in evidenza trovi cosa ti viene chiesto. Rispondi dalla stessa pagina con un messaggio e, se serve, allega il file: l'ufficio lo vede subito e la pratica riprende il suo percorso.",
                'link' => [['e' => 'Le mie pratiche', 'u' => 'pratiche.php']]],
            ['argomento' => 'certificati', 'titolo' => 'Come richiedo un certificato (iscrizione, laurea, Diploma Supplement)',
                'domande' => "Come richiedo il certificato di iscrizione\nCome richiedo il certificato di laurea\nCome ottengo il Diploma Supplement\nCertificato di iscrizione online\nHo bisogno di un certificato",
                'parole_chiave' => "certificato, certificati, iscrizione, laurea, diploma supplement, autocertificazione",
                'risposta' => "I certificati si richiedono come ogni altra pratica, dalla «Modulistica»: scegli il certificato che ti serve e invia la richiesta.\n\nLa pratica segue il suo percorso (ufficio, firma del Direttore) e, a conclusione, scarichi il documento firmato direttamente dalla pagina della pratica. Se per il certificato è prevista la marca da bollo, il portale te lo indica nel percorso della pratica.",
                'link' => [['e' => 'Modulistica', 'u' => 'modulistica.php'], ['e' => 'Le mie pratiche', 'u' => 'pratiche.php']]],
            ['argomento' => 'certificati', 'titolo' => 'Marca da bollo sulle pratiche',
                'domande' => "Serve la marca da bollo\nCome pago la marca da bollo\nMarca da bollo certificato\nBollo sulla pratica",
                'parole_chiave' => "marca da bollo, bollo, pagamento bollo, bollo virtuale",
                'risposta' => "Per alcune pratiche è prevista la marca da bollo, e il portale ti dice quando e come assolverla:\n\n- **Certificati (iscrizione, laurea, rinuncia):** l'ufficio del tuo corso carica le marche da bollo su Esse3 e ti manda una email; paghi da Esse3 (Segreteria, Situazione tasse). Dopo il pagamento il Direttore firma il certificato e lo scarichi dalla pratica.\n- **Convalida di inglese:** la marca da bollo è caricata su Esse3 (Segreteria, Situazione tasse); l'operatore ti informa e l'ufficio protocollo verifica il pagamento.\n- **Trasferimento in entrata, iscrizione per rinuncia o decadenza, abbreviazione di corso:** la marca da bollo si paga prima di inviare, su UniPay (il link è nel modulo), e si allega la ricevuta.\n\nNon devi stampare nulla: il certificato è firmato digitalmente e vale come originale. Se hai dubbi sul tuo caso, parla con un operatore.",
                'link' => [['e' => 'Le mie pratiche', 'u' => 'pratiche.php']], 'proponi_operatore' => true],
            ['argomento' => 'certificati', 'titolo' => 'Attestati degli eventi: dove li trovo e come si verificano',
                'domande' => "Dove scarico l'attestato\nCome ottengo l'attestato di partecipazione\nCome verifico un attestato\nAttestato di un evento o seminario\nAttestato Formazione Scuola Lavoro",
                'parole_chiave' => "attestato, attestati, partecipazione, verifica attestato, codice attestato",
                'risposta' => "Gli attestati degli eventi e dei progetti arrivano per email dopo la conclusione, se previsti per quell'attività, e li ritrovi nella tua Area personale.\n\nOgni attestato ha un codice: chiunque può controllarne l'autenticità dalla pagina di verifica, inserendo il codice.",
                'link' => [['e' => 'La mia Area personale', 'u' => 'area_personale.php'], ['e' => 'Verifica un attestato', 'u' => 'verifica_attestato.php']]],
            ['argomento' => 'pratiche', 'titolo' => 'Convalide, esoneri, piano di studi e altre pratiche di carriera',
                'domande' => "Come chiedo la convalida di esami\nCome modifico il piano di studi\nCome cambio curriculum\nEsonero dalla frequenza\nCome chiedo il part-time\nPasso a un altro corso di studi\nTrasferimento in entrata",
                'parole_chiave' => "convalida, convalide, esoneri, esonero, piano di studi, curriculum, part time, tempo pieno, fuori corso, trasferimento, passaggio, immatricolazione, abbreviazione, rinuncia, decadenza, cancellazione",
                'risposta' => "Tutte queste richieste (convalide, esoneri, variazioni del piano di studi, cambio di curriculum, tempo pieno o part-time, trasferimenti e passaggi, abbreviazioni di corso, rinuncia e decadenza, cancellazione di un'iscrizione errata) si presentano online dalla «Modulistica», scegliendo il modulo che corrisponde al tuo caso.\n\nPer capire quale modulo ti serve o con quali documenti, scrivi un messaggio dalla pratica una volta avviata oppure parla con un operatore.",
                'link' => [['e' => 'Modulistica', 'u' => 'modulistica.php']], 'proponi_operatore' => true],
            ['argomento' => 'pratiche', 'titolo' => 'Mobilità internazionale (Erasmus e altre esperienze all\'estero)',
                'domande' => "Come faccio l'Erasmus\nMobilità internazionale\nStudiare all'estero\nPratiche per esperienza all'estero",
                'parole_chiave' => "erasmus, mobilità, estero, internazionale, internazionalizzazione, learning agreement",
                'risposta' => "Le pratiche di mobilità internazionale si presentano dalla «Modulistica» e le segue l'ufficio Internazionalizzazione del Dipartimento, che ti risponde dalla pagina della pratica.\n\nPer bandi, scadenze e requisiti fai riferimento agli avvisi pubblicati dall'Ateneo; per dubbi sulla pratica scrivi dalla pagina della pratica o a un operatore.",
                'link' => [['e' => 'Modulistica', 'u' => 'modulistica.php']], 'proponi_operatore' => true],
            ['argomento' => 'tesi', 'titolo' => 'Come chiedo la tesi',
                'domande' => "Come chiedo la tesi\nRichiesta di tesi\nCome scelgo il relatore\nAssegnazione tesi\nVoglio fare la tesi",
                'parole_chiave' => "tesi, relatore, richiesta tesi, assegnazione tesi, laurea, tesi esterna",
                'risposta' => "La richiesta di tesi è una pratica online: dalla «Modulistica» scegli il modulo di richiesta della tesi, indica relatore e argomento (e se la tesi si svolge all'esterno, l'azienda o l'ente) e invia.\n\nIl relatore riceve un link personale per confermare la sua disponibilità; poi la pratica prosegue con l'ufficio e il Direttore. Lo stato lo segui da «Le mie pratiche».",
                'link' => [['e' => 'Modulistica', 'u' => 'modulistica.php'], ['e' => 'Le mie pratiche', 'u' => 'pratiche.php']]],
            ['argomento' => 'tesi', 'titolo' => 'Tesi in azienda: la convenzione con l\'ente o l\'azienda',
                'domande' => "Voglio fare la tesi in azienda\nCome si fa la convenzione per la tesi\nLa mia azienda vuole ospitare un tesista\nAziende convenzionate per la tesi\nConvenzione di tesi",
                'parole_chiave' => "tesi in azienda, tesi esterna, convenzione tesi, azienda convenzionata, ente ospitante, tesista",
                'risposta' => "Per svolgere la tesi presso un ente o un'azienda serve una convenzione con il Dipartimento. Se l'azienda è già convenzionata la trovi nell'elenco pubblico; altrimenti la convenzione si richiede online: l'azienda riceve un link personale, senza registrazione, compila i dati e segue l'iter fino alla firma del Direttore.",
                'link' => [['e' => 'Aziende già convenzionate', 'u' => 'aziende_convenzionate_tesi.php'], ['e' => 'Richiedi una convenzione di tesi', 'u' => 'richiedi_convenzione_tesi.php']]],
            ['argomento' => 'tirocini', 'titolo' => 'Tirocini curriculari: dove e come farli',
                'domande' => "Dove posso fare il tirocinio\nCome faccio il tirocinio curriculare\nElenco aziende per il tirocinio\nTirocinio presso un'azienda\nHo bisogno di un tirocinio",
                'parole_chiave' => "tirocinio, tirocini, stage, curriculare, azienda ospitante, tsp, tirocinio curriculare",
                'risposta' => "Dopo l'accesso, nella pagina «Tirocini» trovi le aziende e gli enti con convenzione attiva (puoi cercare per regione, provincia, comune e settore) e i laboratori interni dell'Ateneo dove svolgere il tirocinio.

Per i requisiti del tuo corso di studi (CFU, periodi, tutor) fai riferimento all'ordinamento; per dubbi sul tuo caso parla con un operatore.",
                'link' => [['e' => 'Tirocini', 'u' => 'tirocini.php']], 'proponi_operatore' => true],
            ['argomento' => 'tirocini', 'titolo' => 'Scuole: Formazione Scuola Lavoro e convenzione online',
                'domande' => "Come iscrivo la mia classe a un progetto\nConvenzione per la Formazione Scuola Lavoro\nCome funziona il programma FSL\nPCTO con il Dipartimento\nCome stipulare la convenzione come scuola",
                'parole_chiave' => "fsl, pcto, formazione scuola lavoro, scuola, classe, convenzione scuola, programma fsl, docente referente, alternanza",
                'risposta' => "Il docente referente sceglie le attività dalle pagine della Formazione Scuola Lavoro e le aggiunge al «Programma FSL» (accedendo con SPID o CIE). Dal programma si invia la richiesta: la scuola riceve per email il link personale della convenzione online, la compila, carica la copia firmata e segue l'iter fino alla conferma.\n\nGli studenti si inseriscono poi nell'elenco della classe e, a conclusione, il docente scarica gli attestati.",
                'link' => [['e' => 'Programma FSL', 'u' => 'programma_fsl.php']], 'proponi_operatore' => true],
            ['argomento' => 'eventi', 'titolo' => 'Come mi iscrivo a un evento o a un seminario',
                'domande' => "Come mi iscrivo a un evento\nCome prenoto un seminario\nCome partecipo all'open day\nPrenotazione evento\nCome mi prenoto a un laboratorio",
                'parole_chiave' => "evento, eventi, seminario, seminari, iscrizione evento, prenotazione evento, open day, openlab, turno, laboratorio",
                'risposta' => "Dalla home apri l'area dell'evento che ti interessa, scegli l'evento e il turno e premi «Prenota». Se serve, accedi oppure inserisci i dati richiesti: ricevi una email di conferma con il codice e il biglietto con QR.\n\nI posti sono limitati: se il turno è pieno puoi metterti in lista d'attesa e ricevere l'avviso se si libera un posto.",
                'link' => [['e' => 'Vai alla home', 'u' => 'index.php']]],
            ['argomento' => 'eventi', 'titolo' => 'Come annullo o controllo la mia prenotazione a un evento',
                'domande' => "Come annullo la prenotazione\nVoglio cancellare l'iscrizione all'evento\nDove vedo le mie prenotazioni\nDove trovo il biglietto QR\nHo perso il codice di prenotazione",
                'parole_chiave' => "annullare prenotazione, cancellare iscrizione, mie prenotazioni, biglietto, qr, codice prenotazione, disdetta",
                'risposta' => "Le tue prenotazioni sono nella tua Area personale: lì vedi i dettagli, il biglietto con il QR da mostrare all'ingresso e il pulsante per annullare.\n\nPer alcune attività l'annullamento è possibile solo fino a una certa data: se è passata, il pulsante non compare e devi scrivere a un operatore.",
                'link' => [['e' => 'La mia Area personale', 'u' => 'area_personale.php']], 'proponi_operatore' => true],
            ['argomento' => 'eventi', 'titolo' => 'Prenotare aule, laboratori, sportelli e auto',
                'domande' => "Come prenoto un'aula\nCome prenoto un laboratorio\nCome prenoto l'auto del Dipartimento\nDiario di bordo dell'auto\nPrenotazione risorse",
                'parole_chiave' => "aula, aule, laboratorio, risorsa, risorse, auto, mezzo, diario di bordo, sportello, slot",
                'risposta' => "Aule, laboratori, sportelli e auto si prenotano a slot dalle pagine «Prenotazioni e risorse»: scegli la risorsa, il giorno e la fascia oraria. Alcune richiedono l'approvazione del responsabile e, se lo slot è occupato, puoi metterti in lista d'attesa.\n\nPer le auto, dopo il viaggio ricevi l'invito a compilare il diario di bordo (chilometri, carburante, segnalazioni).",
                'link' => [['e' => 'Vai alla home', 'u' => 'index.php'], ['e' => 'Le mie prenotazioni', 'u' => 'area_personale.php']]],
            ['argomento' => 'tutorato', 'titolo' => 'Tutorato: lettera di incarico, registro e pagamento',
                'domande' => "Come funziona il tutorato\nHo vinto il bando di tutorato\nCome confermo la lettera di incarico\nDove compilo il registro delle attività di tutorato\nCome vengo pagato per il tutorato",
                'parole_chiave' => "tutorato, tutor, bando, lettera di incarico, incarico, registro attività, ore, compenso, pagamento tutor, dichiarazione individuale, iban",
                'risposta' => "Se risulti vincitore del bando ricevi per email il link personale per confermare la lettera di incarico (con SPID o CIE) e firmarla; poi la lettera passa alle firme del docente e del Direttore.\n\nDurante l'attività compili il registro delle ore dalla tua Area personale; a fine attività il docente responsabile lo valida e si procede con la dichiarazione individuale per il pagamento. Per importi e tempi di pagamento fai riferimento al bando.",
                'link' => [['e' => 'La mia Area personale', 'u' => 'area_personale.php']], 'proponi_operatore' => true],
            ['argomento' => 'altro', 'titolo' => 'Prenotare un appuntamento con l\'ufficio didattico o con un docente',
                'domande' => "Come prenoto un appuntamento con la segreteria\nRicevimento dell'ufficio didattico\nOrari di ricevimento\nPosso parlare con qualcuno di persona\nSportello studenti",
                'parole_chiave' => "ricevimento, appuntamento, sportello, orari ufficio, segreteria, di persona",
                'risposta' => "Il ricevimento dell'ufficio didattico e dei docenti è uno sportello a appuntamenti, prenotabile come le altre risorse: dalle pagine «Prenotazioni e risorse» scegli lo sportello, il giorno e la fascia oraria e ricevi la conferma per email. Gli appuntamenti prenotati li ritrovi e li annulli dalla tua Area personale.",
                'link' => [['e' => 'Vai alla home', 'u' => 'index.php'], ['e' => 'La mia Area personale', 'u' => 'area_personale.php']]],
            ['argomento' => 'altro', 'titolo' => 'Verbali dei consigli',
                'domande' => "Dove trovo i verbali dei consigli\nCome consulto i verbali\nVerbali del consiglio di corso di studi\nPassword per i verbali",
                'parole_chiave' => "verbale, verbali, consiglio, consigli, seduta, password verbali",
                'risposta' => "I verbali dei consigli si consultano da una pagina riservata: serve la password che il Dipartimento fornisce a chi è autorizzato. Con la sola password i verbali si leggono nella pagina; per scaricarli serve anche l'accesso con le credenziali di Ateneo.\n\nSe non hai la password, parla con un operatore.",
                'link' => [['e' => 'Verbali', 'u' => 'verbali.php']], 'proponi_operatore' => true],
            ['argomento' => 'altro', 'titolo' => 'Privacy e trattamento dei dati',
                'domande' => "Come vengono trattati i miei dati\nInformativa privacy\nQuanto tempo conservate i miei dati\nCome cancello i miei dati\nCookie del portale",
                'parole_chiave' => "privacy, dati personali, gdpr, informativa, cookie, conservazione dati, cancellare dati",
                'risposta' => "L'informativa completa sul trattamento dei dati personali e sui cookie è nella pagina «Privacy»: vi trovi chi tratta i dati, per quali finalità, per quanto tempo si conservano e come esercitare i tuoi diritti con il Responsabile della protezione dei dati di Ateneo.",
                'link' => [['e' => 'Privacy e cookie', 'u' => 'privacy.php']]],
            ['argomento' => 'altro', 'titolo' => 'Come parlo con una persona dell\'ufficio',
                'domande' => "Come parlo con un operatore\nVoglio parlare con una persona\nChi mi risponde\nNon mi hai risposto bene\nPosso scrivere a qualcuno",
                'parole_chiave' => "operatore, persona, assistenza, aiuto, contattare, contatti, segreteria",
                'risposta' => "Scrivi «operatore» in qualunque momento, oppure premi il pulsante «Parla con un operatore»: ti metto in contatto con una persona dell'ufficio e ricevi la risposta qui in chat (o per email, se chiudi la pagina). Negli orari di assistenza risponde subito che può; fuori orario lascia il messaggio e ti rispondono appena possibile.",
                'link' => [], 'proponi_operatore' => true],
        ];
    }

    /** Inserisce le voci di partenza (solo se la base di conoscenza è vuota). */
    public static function inserisci(Database $db): int
    {
        if ((int) $db->valore('SELECT COUNT(*) FROM didacticus_voci') > 0) {
            return 0;
        }
        $repo = new VociRepository($db);
        $quando = date('Y-m-d H:i:s');
        $n = 0;
        foreach (self::elenco() as $i => $v) {
            $repo->salva(0, [
                'argomento' => $v['argomento'], 'titolo' => $v['titolo'], 'domande' => $v['domande'], 'parole_chiave' => $v['parole_chiave'], 'risposta' => $v['risposta'],
                'link' => $v['link'], 'allegati' => [], 'solo_login' => !empty($v['solo_login']), 'proponi_operatore' => !empty($v['proponi_operatore']), 'attiva' => true, 'ordine' => $i,
            ], $quando);
            ++$n;
        }

        return $n;
    }
}
