<?php

declare(strict_types=1);

namespace App\Portale;

/**
 * Le guide «Come si fa» per ruolo: una pagina breve per chi usa il portale (studente, docente, uffici, referente del consiglio, responsabile di risorsa, tutor, Direttore,
 * amministratore), con i passaggi e le schermate. Il testo sta qui, in un posto solo: la pagina guida.php lo mostra, e la stessa pagina in versione da stampa
 * (guida.php?r=…&stampa=1) è la fonte dei PDF in documentazione/guide/.
 *
 * Le schermate sono in assets/guide/ (si rifanno con strumenti/locale/schermate_documentazione.py).
 */
final class GuidePerRuolo
{
    /**
     * @return array<string, array{titolo: string, icona: string, colore: string, a_chi: string, in_breve: string, sezioni: list<array{titolo: string, passi: list<string>, immagine?: string, didascalia?: string, nota?: string}>}>
     */
    public static function tutte(): array
    {
        return [
            'studente' => [
                'titolo' => 'Studente', 'icona' => 'fa-user-graduate', 'colore' => '#0056B3',
                'a_chi' => 'Per chi presenta una pratica (certificati, tesi, convalide, mobilità…) o prenota aule, laboratori e mezzi.',
                'in_breve' => 'Entri con le credenziali di Ateneo (o SPID/CIE), compili il modulo online, segui la pratica passo per passo e scarichi i documenti quando sono pronti. Non devi mandare email né portare fogli: ogni passaggio ti arriva per email e lo ritrovi nella pratica.',
                'sezioni' => [
                    ['titolo' => 'Trovare la pratica che ti serve', 'immagine' => 'stud_moduli.png', 'didascalia' => 'La modulistica: ogni pratica ha una breve spiegazione di cosa serve e a chi arriva.',
                     'passi' => ['Accedi con le credenziali di Ateneo, SPID o CIE: dal menu del sito apri «Modulistica». Le pratiche che hai già presentato sono in «Le mie pratiche», nella tua Area personale.', 'Vedi solo le pratiche per te. Leggi la breve descrizione: dice cosa serve prima di iniziare e chi la lavorerà.', 'Premi sul titolo della pratica per aprire il modulo.']],
                    ['titolo' => 'Compilare e inviare', 'immagine' => 'stud_modulo_tesi.png', 'didascalia' => 'Un modulo lungo si divide in pagine: «Avanti» e «Indietro». Con «Salva come bozza» riprendi più tardi.',
                     'passi' => ['I dati che il portale conosce (nome, email, matricola) sono già pieni. Controllali e completa il resto.', 'Alcuni campi compaiono solo se servono: se scegli un tipo di richiesta, vedi i campi di quel tipo.', 'Corso di studio, insegnamento e docente si scelgono dall’elenco di Ateneo: scrivi qualche lettera e scegli dalla lista.', 'Allega i documenti richiesti (PDF o immagine, fino a 10 MB). Usa nomi di file corti, per esempio doc.pdf.', 'Spunta le dichiarazioni e premi «Invia». Ricevi subito una email con il codice della pratica e trovi la domanda già in PDF/A.'],
                     'nota' => 'Puoi correggere la richiesta anche dopo l’invio, finché l’ufficio non la prende in carico: dalla pratica premi «Modifica la richiesta».'],
                    ['titolo' => 'Seguire la pratica', 'immagine' => 'stud_pratica_tesi.png', 'didascalia' => 'Le tappe in alto dicono dove si trova la pratica; sotto trovi la tua richiesta e lo storico.',
                     'passi' => ['Da «Le mie pratiche» apri la pratica: in alto vedi le tappe (per esempio conferma del relatore, protocollo, ufficio del corso).', 'Lo storico elenca ogni passaggio e ogni messaggio dell’ufficio, con data e chi l’ha fatto.', 'Se l’ufficio chiede un’integrazione ricevi una email: rispondi dalla pratica, allega il documento e l’iter riprende.', 'Quando la pratica è conclusa trovi il documento firmato o l’estratto del verbale e puoi scaricarli.']],
                    ['titolo' => 'Marca da bollo: come si paga', 'immagine' => 'stud_pratica_laurea.png', 'didascalia' => 'Certificato con marche da bollo: l’ufficio le carica su Esse3 e ti avvisa, poi paghi.',
                     'passi' => ['<b>Certificati (iscrizione, laurea, rinuncia):</b> l’ufficio del tuo corso carica le 2 marche da bollo su Esse3 e ti manda una email. Paghi da Esse3 (Segreteria, Situazione tasse). Dopo il pagamento il Direttore firma il certificato e lo scarichi dalla pratica.', '<b>Convalida di inglese:</b> la marca da bollo è caricata su Esse3 (Segreteria, Situazione tasse): l’operatore ti informa e l’ufficio protocollo verifica il pagamento prima di passare la pratica all’ufficio del corso.', '<b>Trasferimento in entrata, iscrizione per rinuncia o decadenza, abbreviazione di corso:</b> la marca da bollo da 16 euro si paga prima di inviare, su UniPay (il link è nel modulo), e si allega la ricevuta.', 'Non devi stampare nulla: il certificato è firmato digitalmente e vale come originale.']],
                    ['titolo' => 'La tesi e il relatore', 'passi' => ['Nel modulo scegli il relatore dall’elenco del personale (deve avere l’email in anagrafe).', 'Il relatore riceve un link personale e conferma con le sue credenziali: non devi più generare o mandare link.', 'Se il relatore non conferma ricevi il motivo per email: correggi la richiesta dalla pratica e la conferma riparte.', 'Dopo la conferma la richiesta va al protocollo e al referente del corso di studio.']],
                    ['titolo' => 'Prenotare un’aula, un laboratorio o un mezzo', 'immagine' => 'stud_calendario.png', 'didascalia' => 'Gli slot liberi sono in verde. Quelli già prenotati hanno il pulsante «lista d’attesa».',
                     'passi' => ['Apri la risorsa dal sito (Aule e laboratori): scegli la settimana e uno slot libero, indica il motivo e conferma.', 'Se la risorsa richiede l’approvazione, la richiesta resta «in sospeso» e ricevi una email con la risposta.', 'Per un’attività periodica spunta «Ripeti» e scegli la frequenza: ogni settimana, ogni due settimane o ogni mese, lo stesso giorno della settimana. Le date non disponibili vengono saltate e te le indichiamo.', 'Se lo slot è occupato premi il pulsante «lista d’attesa» sullo slot: se la prenotazione viene annullata o rifiutata ricevi un’email e hai 24 ore per prenotare. Se non fai in tempo l’avviso passa a chi è dopo di te.', 'Le tue prenotazioni sono nell’Area personale, dove puoi annullarle. Le tue liste d’attesa le vedi nella pagina della risorsa, sotto il calendario: da lì puoi toglierti dalla coda.'],
                     'nota' => 'La lista d’attesa non riserva lo slot: chi arriva prima lo prende. Quando prenoti lo slot per cui eri in coda, la coda si chiude da sola.'],
                ],
            ],
            'docente' => [
                'titolo' => 'Docente e relatore', 'icona' => 'fa-chalkboard-user', 'colore' => '#7c3aed',
                'a_chi' => 'Per chi è indicato da uno studente come relatore, o come docente di un attestato, e per chi prenota aule e laboratori.',
                'in_breve' => 'Quando uno studente ti indica ricevi una email con un link personale. Apri il link con le tue credenziali di Ateneo, controlli i dati e premi «Confermo» (oppure «Non confermo» con il motivo). Resta registrato chi ha confermato, quando e con quale accesso.',
                'sezioni' => [
                    ['titolo' => 'Confermare una richiesta (tesi, attestati)', 'immagine' => 'rel_conferma.png', 'didascalia' => 'La pagina di conferma: i dati della richiesta e, sotto, «Confermo» o «Non confermo».',
                     'passi' => ['Apri il link nella email «Da confermare». Se non sei ancora entrato, accedi con Unical ID, SPID o CIE: solo tu puoi aprire il tuo link.', 'Controlla i dati della richiesta (progetto formativo, sede, laboratorio…).', 'Per confermare spunta la dichiarazione e premi «Confermo». Per non confermare scrivi il motivo e premi «Non confermo»: lo studente lo riceve e può correggere.', 'Se hai più richieste in attesa le trovi elencate in fondo alla pagina.'],
                     'nota' => 'Se dimentichi una conferma il portale ti manda un promemoria dopo alcuni giorni e avvisa l’ufficio.'],
                    ['titolo' => 'Firmare un documento', 'immagine' => 'dir_firma.png', 'didascalia' => 'La pagina di firma: il PDF accanto e le istruzioni per firmare in PAdES.',
                     'passi' => ['Alcuni passi chiedono la firma digitale (per esempio la lettera d’incarico di un tutor o il verbale come segretario). Ricevi una email con il link personale.', 'Se è attiva la firma remota firmi dal portale; altrimenti scarichi il PDF, lo firmi con il tuo strumento scegliendo PAdES (non .p7m) e lo ricarichi senza modificarlo.', 'Il portale controlla che il file sia davvero firmato e che il firmatario sia tu. Fino a tre solleciti ti ricordano la firma.']],
                    ['titolo' => 'Prenotare aule e laboratori', 'passi' => ['Dalla pagina della risorsa scegli lo slot libero e conferma: funziona come per gli studenti, con la ripetizione e la lista d’attesa.', 'Se sei responsabile di una risorsa trovi «Le mie risorse» nell’Area personale: vedi la guida «Responsabile di risorsa».']],
                ],
            ],
            'protocollo' => [
                'titolo' => 'Ufficio protocollo', 'icona' => 'fa-stamp', 'colore' => '#b45309',
                'a_chi' => 'Per chi protocolla le domande degli studenti e verifica la marca da bollo.',
                'in_breve' => 'Ogni domanda arriva già come PDF/A: la protocolli nel sistema di Ateneo, scrivi numero e data nella pratica e il portale la manda da sola all’ufficio del corso (o all’ufficio indicato nell’iter).',
                'sezioni' => [
                    ['titolo' => 'Vedere cosa c’è da protocollare', 'immagine' => 'proto_elenco.png', 'didascalia' => 'L’elenco delle pratiche, con la fase di ciascuna e i filtri.',
                     'passi' => ['Entra nel pannello: nella «Scrivania» trovi le pratiche da protocollare. Oppure apri Didattica, «Pratiche degli studenti».', 'Ogni riga dice in che fase è la pratica: «Da protocollare» è quella che tocca a te.', 'Con i filtri per fase, modulo e ricerca trovi subito una pratica.']],
                    ['titolo' => 'Registrare il protocollo', 'immagine' => 'proto_scheda.png', 'didascalia' => 'La scheda della pratica: scarica la domanda, protocolla, scrivi numero e data.',
                     'passi' => ['Apri la pratica e scarica la domanda in PDF/A («Domanda»).', 'Protocolla nel sistema di Ateneo e inserisci qui numero e data del protocollo.', 'Se il modulo richiede la marca da bollo spunta «pagamento verificato»: finché non lo fai la pratica resta da te.', 'Premi per registrare: la pratica passa all’ufficio successivo e lo studente riceve l’avviso.'],
                     'nota' => 'Sbagliato il numero? Puoi correggerlo dalla stessa scheda: lo storico conserva la modifica.'],
                ],
            ],
            'referente' => [
                'titolo' => 'Ufficio del corso (referente)', 'icona' => 'fa-people-group', 'colore' => '#7c3aed',
                'a_chi' => 'Per chi lavora le pratiche del proprio corso di studio: istruttoria, marche da bollo, documenti, mobilità, attestati.',
                'in_breve' => 'La pratica arriva all’ufficio del corso indicato dallo studente. La prendi in carico, la lavori, chiedi integrazioni se serve e passi al passo successivo dell’iter: il portale ti dice sempre qual è la prossima azione.',
                'sezioni' => [
                    ['titolo' => 'Prendere in carico e capire il percorso', 'immagine' => 'ref_scheda.png', 'didascalia' => 'In alto l’iter della pratica con la persona in carico; sotto «Passa al passo successivo».',
                     'passi' => ['Dalla «Scrivania» o da «Pratiche degli studenti» apri una pratica: il riquadro verde dice cosa fare ora.', 'L’iter mostra i passi (protocollo, ufficio del corso, segreteria…) e chi ha in carico la pratica: i passi dell’iter sono diversi da modulo a modulo.', 'Se manca qualcosa scrivi un messaggio allo studente («Storico e messaggi»): riceve una email e risponde dalla pratica. Le note interne le vedi solo tu.', 'Quando hai finito premi «Passa al passo successivo». Se serve puoi passarla a un altro ufficio o a un operatore.']],
                    ['titolo' => 'Certificati con marche da bollo', 'immagine' => 'ref_marche.png', 'didascalia' => 'Il riquadro «Marche da bollo» guida i tre momenti: carica su Esse3, avvisa lo studente, registra il pagamento.',
                     'passi' => ['1. Carica le 2 marche da bollo su Esse3 (Segreteria, Situazione tasse) e premi «Marche caricate su Esse3: avvisa lo studente».', '2. Quando il pagamento risulta, spunta «marca da bollo assolta virtualmente» e carica il certificato generato da Esse3 (PDF).', '3. Premi «Al Direttore»: il Direttore riceve il link per firmare in PAdES; poi lo studente scarica e stampa il certificato e tu ricevi l’avviso.', 'Per il Diploma Supplement non c’è la marca: carichi il documento da far firmare e la pratica va al Direttore.']],
                    ['titolo' => 'Attestati, mobilità e altre pratiche', 'passi' => ['<b>Attestati (frequenza, esame):</b> l’ufficio attestati controlla l’attestato in PDF preparato dal portale, lo manda al Direttore e, dopo la firma, registra il protocollo in uscita: lo studente scarica il PDF protocollato.', '<b>Mobilità internazionale:</b> prima passa dall’ufficio Internazionalizzazione, poi dal referente del corso, quindi va nel verbale e alla segreteria.', '<b>Tesi:</b> arriva dopo la conferma del relatore. <b>Convalide, piani di studio, esoneri, trasferimenti:</b> dopo l’istruttoria si portano in seduta.', 'Dopo la decisione del Consiglio la pratica va alla segreteria studenti, che registra l’esito sulla carriera.']],
                ],
            ],
            'consiglio' => [
                'titolo' => 'Referente del consiglio e segretario', 'icona' => 'fa-gavel', 'colore' => '#15803d',
                'a_chi' => 'Per chi gestisce le sedute di un consiglio di corso di studio: presenze, decisioni sulle pratiche, verbale e firme.',
                'in_breve' => 'Ogni seduta ha un percorso a sette tappe, uguale per tutti i consigli: il portale propone sempre il passo successivo. Il verbale esce in Word, come il modello, e si firma in PAdES dal segretario e dal coordinatore.',
                'sezioni' => [
                    ['titolo' => 'Preparare la seduta', 'immagine' => 'ref_sedute.png', 'didascalia' => 'L’elenco delle sedute del tuo consiglio.',
                     'passi' => ['In Didattica scegli «Sedute e verbali» e crea la seduta: consiglio, data, ora, luogo, ordine del giorno (un punto per riga).', 'I componenti si inseriscono una volta sola dall’anagrafe di Ateneo e ogni seduta riparte da lì.', 'Facoltativo: manda la convocazione. Ogni componente ha un link personale per giustificare l’assenza, che finisce da sola nelle presenze.']],
                    ['titolo' => 'Le sette tappe', 'immagine' => 'ref_seduta.png', 'didascalia' => 'La seduta guidata: si vede subito cosa è fatto e cosa manca.',
                     'passi' => ['<b>Presenze:</b> presente, assente giustificato o ingiustificato, con i pulsanti «tutti presenti».', '<b>Cosa è stato detto:</b> un riquadro di testo per ogni punto dell’ordine del giorno. Le pratiche studenti portano già le loro decisioni.', '<b>Pratiche e decisioni:</b> esito per ogni pratica (approvata, con modifiche, respinta, rinviata) e testo della delibera.', '<b>Esiti agli studenti:</b> «Applica gli esiti» aggiorna le pratiche e avvisa gli studenti con l’estratto del verbale.', '<b>Verbale:</b> scarichi il Word, lo completi se serve e lo salvi in PDF.', '<b>Firme:</b> carichi il PDF e indichi le email di segretario e coordinatore: ricevono un link personale e firmano uno dopo l’altro. Se la firma tarda parte un sollecito ogni pochi giorni (fino a tre); puoi mandarlo anche tu.']],
                    ['titolo' => 'Dopo la firma', 'passi' => ['Il verbale firmato va ai referenti del consiglio e resta nell’archivio dei verbali, con ricerca nel testo.', 'Le pratiche lavorate passano alla segreteria studenti insieme al verbale.']],
                ],
            ],
            'risorsa' => [
                'titolo' => 'Responsabile di risorsa', 'icona' => 'fa-user-shield', 'colore' => '#0f766e',
                'a_chi' => 'Per chi è indicato come responsabile di un’aula, di un laboratorio o di un mezzo.',
                'in_breve' => 'Ricevi le richieste di prenotazione e le approvi dal portale, controlli quanto la risorsa è usata e tieni sotto controllo le scadenze (bollo, assicurazione, tarature). Per le auto e i mezzi controlli anche il diario di bordo.',
                'sezioni' => [
                    ['titolo' => 'Approvare le richieste', 'immagine' => 'resp_risorse.png', 'didascalia' => '«Le mie risorse»: richieste da approvare e prenotazioni in arrivo.',
                     'passi' => ['Nella tua Area personale apri «Le mie risorse»: vedi quante richieste aspettano una risposta.', 'Per ogni richiesta puoi approvare o rifiutare: chi ha prenotato riceve l’email. Le richieste che aspettano da una settimana compaiono anche nel cruscotto del Direttore.', 'Se una prenotazione viene annullata o rifiutata, il primo in lista d’attesa per quello slot è avvisato da solo.']],
                    ['titolo' => 'Il cruscotto della risorsa', 'immagine' => 'resp_cruscotto.png', 'didascalia' => 'Prenotazioni, ore, utilizzo, fasce più richieste, chi la usa di più e le scadenze.',
                     'passi' => ['Dal cruscotto scegli il periodo e vedi prenotazioni, ore prenotate, percentuale di utilizzo e le fasce orarie più richieste: servono a decidere se serve più disponibilità.', '«Chi la usa di più» e le quantità consumate aiutano a pianificare. Il pulsante «Excel» scarica tutti i numeri.', 'Nella sezione «Scadenze» vedi bollo, assicurazione, revisione, taratura: prima della data e il giorno della scadenza ricevi un avviso. Quando rinnovi scrivi la nuova data e premi «Rinnovata».']],
                    ['titolo' => 'Diario di bordo (auto e mezzi)', 'passi' => ['Chi prenota un mezzo compila il diario dopo l’uso (km, rifornimenti, segnalazioni); i km iniziali si propongono dall’ultimo viaggio.', 'Tu controlli e correggi dal registro del mezzo; ogni correzione azzera la verifica. Puoi scaricare l’Excel mensile o annuale.']],
                ],
            ],
            'scuola' => [
                'titolo' => 'Scuole: la convenzione FSL', 'icona' => 'fa-school', 'colore' => '#B30000',
                'a_chi' => 'Per le scuole che prenotano attività di Formazione Scuola Lavoro e devono stipulare (o rinnovare) la convenzione con il Dipartimento: dirigenti, segreterie e docenti referenti.',
                'in_breve' => 'Compili i dati della scuola e il portale prepara la Convenzione e l’Allegato A. Il Dirigente firma la Convenzione in PAdES, la scuola carica i file e il Dipartimento fa il resto: protocollo in entrata, firma del Direttore e protocollo definitivo. A ogni passaggio ricevi una email e le prenotazioni in attesa si confermano da sole. La convenzione vale un anno.',
                'sezioni' => [
                    ['titolo' => 'Prima di cominciare', 'passi' => ['Prenota le attività di Formazione Scuola Lavoro dal sito. Se la scuola non ha ancora una convenzione valida, la prenotazione resta in attesa e si conferma da sola quando la convenzione diventa attiva.', 'Ti arriva una email con il link personale alla pagina della convenzione; puoi anche aprirla con il codice della prenotazione.', 'Serve la firma digitale del Dirigente Scolastico.']],
                    ['titolo' => 'Compilare i dati della scuola', 'immagine' => 'fsl_modulo.png', 'didascalia' => 'La pagina della convenzione: istituzione scolastica, Dirigente e attività dell’Allegato A.',
                     'passi' => ['Apri il link ricevuto per email.', '<b>Istituzione scolastica:</b> denominazione, codice meccanografico, comune, indirizzo, codice fiscale, PEC e email della scuola.', '<b>Dirigente Scolastico:</b> nome, codice fiscale, luogo e data di nascita.', '<b>Attività dell’Allegato A:</b> per ogni attività prenotata indichi il numero di studenti e il docente tutor.', 'Premi il pulsante per preparare i documenti. Puoi tornare sulla stessa pagina dal link e correggere i dati: i documenti si aggiornano.']],
                    ['titolo' => 'Scaricare i documenti', 'immagine' => 'fsl_documenti_pronti.png', 'didascalia' => '«I documenti sono pronti»: l’Allegato A in PDF e la Convenzione in Word, con le istruzioni dei passi successivi.',
                     'passi' => ['Scarica l’<b>Allegato A (PDF)</b> e la <b>Convenzione (Word)</b>.', 'Controlla i dati. Eventuali correzioni si fanno nel Word; poi salva la Convenzione in PDF.'],
                     'nota' => 'Conserva la email con il link: serve per tornare sulla pagina e seguire la pratica.'],
                    ['titolo' => 'La firma del Dirigente', 'passi' => ['Il Dirigente firma la Convenzione in PDF <b>in formato PAdES</b> (un PDF firmato). Il formato CAdES (file .p7m) non è accettato.', 'Dopo la firma il file non va più modificato né salvato di nuovo: il portale controlla che la firma sia integra.', 'L’Allegato A si carica in PDF, anche senza firma digitale.', 'In alternativa al caricamento si può inviare tutto dalla PEC della scuola alla PEC del Dipartimento (dipartimento.best@pec.unical.it).']],
                    ['titolo' => 'Caricare la convenzione firmata', 'immagine' => 'fsl_firmata_caricata.png', 'didascalia' => 'Il riquadro «Convenzione firmata» conferma la ricezione, con data e firmatario.',
                     'passi' => ['Nella stessa pagina scegli il PDF firmato e l’Allegato A e premi il pulsante di caricamento.', 'Compare la conferma di ricezione e ti arriva la email «Convenzione ricevuta».', 'Se hai sbagliato file puoi sostituirlo con uno nuovo finché il Dipartimento non lo ha preso in carico.']],
                    ['titolo' => 'Che cosa fa il Dipartimento', 'immagine' => 'fsl_presa_in_carico.png', 'didascalia' => 'Il Dipartimento ha preso in carico la convenzione: la pagina mostra il protocollo in entrata.',
                     'passi' => ['<b>Presa in carico:</b> l’ufficio registra il protocollo in entrata e ti scrive (email «Convenzione presa in carico»).', '<b>Firma del Direttore:</b> la convenzione va al Direttore, che firma in PAdES. Tu non devi fare nulla.', '<b>Protocollo definitivo:</b> l’ufficio registra il protocollo e la validità: la convenzione diventa attiva e le prenotazioni in attesa si confermano.', 'Se c’è un problema l’ufficio <b>rifiuta il file con un motivo</b>: ricevi l’email «Convenzione da correggere», correggi e carichi di nuovo il file.']],
                    ['titolo' => 'La convenzione è attiva', 'immagine' => 'fsl_attiva.png', 'didascalia' => 'La pagina conferma che la convenzione è firmata dal Direttore e protocollata.',
                     'passi' => ['Ricevi l’email «Convenzione attiva».', 'Le prenotazioni in attesa della scuola sono confermate.']],
                    ['titolo' => 'Dove ritrovarla', 'immagine' => 'fsl_area_attiva.png', 'didascalia' => 'Area personale, scheda «Programma FSL»: la convenzione in vigore, con protocollo e validità, e i documenti da scaricare.',
                     'passi' => ['Tutti i docenti collegati alla scuola vedono e scaricano la convenzione firmata e l’Allegato A dalla scheda «Programma FSL» dell’Area personale.', 'La convenzione vale <b>un anno</b>. Poco prima della scadenza il Dipartimento avvisa e si rifà lo stesso percorso con una nuova convenzione; le scadute restano consultabili.']],
                ],
            ],
            'elenco' => [
                'titolo' => 'Docenti delle scuole: l’elenco degli studenti FSL', 'icona' => 'fa-list-ol', 'colore' => '#0f766e',
                'a_chi' => 'Per il docente che ha prenotato un’attività di Formazione Scuola Lavoro per la sua classe e deve consegnare l’elenco dei partecipanti.',
                'in_breve' => 'Dopo la prenotazione, e prima dell’attività, consegni due cose dalla tua Area personale: l’elenco degli studenti (cognome e nome) e l’autorizzazione della scuola in PDF. Con quei nomi il portale prepara gli attestati di partecipazione.',
                'sezioni' => [
                    ['titolo' => 'Aprire la pagina dell’elenco', 'immagine' => 'fsl_elenco_1_area.png', 'didascalia' => 'Area personale, «Prossimi eventi»: sotto la prenotazione c’è il pulsante «Carica elenco e autorizzazione», con la data entro cui consegnare.',
                     'passi' => ['Accedi al portale e apri la tua Area personale.', 'Nella prenotazione dell’attività premi <b>«Carica elenco e autorizzazione»</b>. Accanto vedi la scadenza («entro il …»).', 'Il pulsante è disponibile anche se la convenzione della scuola non è ancora attiva.'],
                     'nota' => 'Se la scadenza si avvicina e manca qualcosa, il portale ti manda promemoria periodici e un ultimo avviso.'],
                    ['titolo' => 'La pagina dei documenti', 'immagine' => 'fsl_elenco_2_pagina.png', 'didascalia' => 'In alto i documenti ancora da consegnare (studenti inseriti e autorizzazione); sotto, a sinistra l’elenco a mano, a destra l’autorizzazione e il modello Excel.',
                     'passi' => ['In alto un riepilogo: quanti studenti hai inserito sul totale iscritto («0 / 15 studenti»), se manca l’autorizzazione e la data entro cui consegnare.', 'Quando tutto è completo compare «Documenti completi».']],
                    ['titolo' => 'Inserire i nomi a mano', 'immagine' => 'fsl_elenco_4_compilato.png', 'didascalia' => 'Una riga per studente: cognome e nome. In fondo il conteggio e «Salva l’elenco».',
                     'passi' => ['Scrivi <b>cognome</b> e <b>nome</b> di ogni studente, una riga per studente.', 'Servono altre righe? Premi «Aggiungi studente». Per toglierne una usa la X rossa.', 'Hai l’elenco in Excel? Seleziona le due colonne, copia e incolla nella prima casella libera: le righe si riempiono da sole.', 'Premi <b>«Salva l’elenco»</b>. Puoi tornare e correggerlo quando vuoi.'],
                     'nota' => 'Ogni studente deve avere cognome e nome. Non puoi inserire più studenti di quelli indicati nell’iscrizione: per aumentare il numero chiedi alla segreteria.'],
                    ['titolo' => 'Oppure usare il modello Excel', 'immagine' => 'fsl_elenco_3_excel.png', 'didascalia' => 'Riquadro «Usa il modello Excel»: scarica, compila, carica.',
                     'passi' => ['Premi <b>«Scarica il modello»</b> e compila le colonne Cognome e Nome.', 'Salva il file e caricalo con <b>«Carica e sostituisci»</b> (file .xlsx o .csv).', 'Il file <b>sostituisce</b> l’elenco attuale: il portale te lo chiede prima di procedere.']],
                    ['titolo' => 'Controllare che sia salvato', 'immagine' => 'fsl_elenco_5_salvato.png', 'didascalia' => 'Dopo il salvataggio il riepilogo mostra «5 / 15 studenti» e i nomi restano nelle righe.',
                     'passi' => ['Il contatore in alto si aggiorna (qui «5 / 15 studenti»).', 'Se ti manca ancora qualche nome puoi aggiungerlo più tardi, finché non sono stati inviati gli attestati.']],
                    ['titolo' => 'L’autorizzazione della scuola', 'passi' => ['Nel riquadro «Autorizzazione della scuola» scegli il PDF (al massimo 10 MB) e premi <b>«Carica l’autorizzazione»</b>.', 'Puoi aprirla per controllarla, sostituirla con un altro PDF o toglierla.', 'A elenco e autorizzazione consegnati compare «Documenti completi».']],
                    ['titolo' => 'Dopo l’attività: gli attestati', 'passi' => ['I nomi servono a generare gli attestati di partecipazione, che partono dopo l’attività (per le classi con presenza registrata).', 'Dopo l’invio degli attestati l’elenco non è più modificabile dal docente: per correggere un nome scrivi alla segreteria.']],
                ],
            ],
            'tutor' => [
                'titolo' => 'Tutor', 'icona' => 'fa-user-pen', 'colore' => '#b45309',
                'a_chi' => 'Per chi ha vinto un bando di tutorato: lettera d’incarico, registro delle attività e compenso.',
                'in_breve' => 'Confermi l’incarico con SPID o CIE, indichi i dati per il pagamento, registri le attività svolte e, a fine attività, il docente firma la dichiarazione e l’ufficio pagamenti procede.',
                'sezioni' => [
                    ['titolo' => 'L’incarico', 'immagine' => 'tutorato_1_dati_pagamento.png', 'didascalia' => 'Dati per il pagamento nella dichiarazione individuale.',
                     'passi' => ['Ricevi una email con il link alla lettera d’incarico: apri, controlla e conferma con SPID o CIE (livello 2).', 'Compili online la dichiarazione individuale: l’IBAN viene conservato cifrato.', 'La lettera è firmata dal docente e dal Direttore: ricevi il documento firmato e il protocollo.']],
                    ['titolo' => 'Registro e fine attività', 'immagine' => 'tutorato_7_area_personale.png', 'didascalia' => 'L’Area personale del tutor.',
                     'passi' => ['Nel registro scrivi le attività svolte, con data e ore. Il compenso in lettera lo calcola il portale (ore × 25 euro lordi per l’ente, diviso 1,24); se fai meno ore viene ricalcolato.', 'A fine attività richiedi la dichiarazione di fine attività: il docente titolare approva le ore e la firma, poi il referente del tutorato controlla ore e compenso e passa la pratica ai pagamenti.', 'L’ufficio pagamenti riceve la pratica e registra il pagamento: vedi lo stato dalla tua Area personale.']],
                ],
            ],
            'direttore' => [
                'titolo' => 'Direttore', 'icona' => 'fa-gauge-high', 'colore' => '#0f2a4a',
                'a_chi' => 'Per il Direttore del Dipartimento: cose da firmare e cose che si sono fermate.',
                'in_breve' => 'Il cruscotto riunisce in una pagina ciò che aspetta la tua firma e ciò che si è fermato nei vari moduli. Non cambia nulla: serve a vedere dove intervenire. Le firme si fanno dal link personale.',
                'sezioni' => [
                    ['titolo' => 'Il cruscotto', 'immagine' => 'dir_cruscotto.png', 'didascalia' => 'Nove gruppi: firme, pratiche ferme, conferme in ritardo, verbali, convenzioni, tutorato, risorse.',
                     'passi' => ['Dall’Area personale premi «Cruscotto del Direttore»: in alto vedi quante cose da guardare ci sono.', 'Ogni gruppo ha il suo numero. Le righe con i giorni in rosso aspettano da sette giorni o più.', 'Premi su una riga per andare direttamente alla pratica, al documento da firmare o alla pagina del modulo.', 'Gruppi: documenti da firmare, convenzioni FSL da firmare, pratiche ferme, conferme dei docenti in ritardo, verbali in attesa di firma, convenzioni in scadenza, pagamenti del tutorato fermi, scadenze delle risorse, prenotazioni da approvare ferme.']],
                    ['titolo' => 'Firmare', 'immagine' => 'dir_firma.png', 'didascalia' => 'La pagina di firma: accedi, controlla il PDF, firma in PAdES.',
                     'passi' => ['Per certificati, attestati, convenzioni e lettere ricevi una email con il link personale (lo trovi anche nel cruscotto).', 'Accedi con le tue credenziali: solo tu puoi aprire il tuo link. Vedi il PDF accanto e firmi con la firma remota oppure scarichi, firmi in PAdES e ricarichi.', 'Il portale controlla che il file sia firmato e che il firmatario corrisponda al codice fiscale indicato. Dopo la firma l’ufficio riceve l’avviso.'],
                     'nota' => 'Nome ed email del Direttore si indicano una volta sola nelle impostazioni delle convenzioni: sono usati per tutte le firme.'],
                ],
            ],
            'amministratore' => [
                'titolo' => 'Amministratore e Ufficio didattico', 'icona' => 'fa-gear', 'colore' => '#475569',
                'a_chi' => 'Per chi configura il portale: moduli e iter, uffici, utenti, backup.',
                'in_breve' => 'La «Scrivania» mostra ciò che aspetta te. I moduli delle pratiche si creano e si modificano dal pannello, senza toccare il codice; gli uffici decidono chi lavora ogni passo; il backup gira da solo e si può provare a ripristinare.',
                'sezioni' => [
                    ['titolo' => 'La scrivania', 'immagine' => 'adm_scrivania.png', 'didascalia' => 'Le cose da fare e i moduli a cui hai accesso.',
                     'passi' => ['Dopo l’accesso al pannello trovi «Cose da fare»: pratiche da assegnare, verbali da firmare, convenzioni, tutorato.', 'I moduli (Eventi, Formazione Scuola Lavoro, Didattica, Gestione del portale) si aprono dalle card.']],
                    ['titolo' => 'Moduli e iter delle pratiche', 'immagine' => 'adm_moduli.png', 'didascalia' => 'Moduli e documenti: le pratiche attive, i campi e l’iter di ciascuna.',
                     'passi' => ['In Didattica, «Moduli e documenti»: ogni pratica nasce inattiva. Aprila con «Anteprima», controlla campi e testi e attivala quando va bene.', 'Dalla procedura guidata a sette passi cambi campi, iter (chi la lavora e in che ordine), testo del verbale ed email. Con «Copia» parti da una pratica simile.', 'Gli uffici e le persone si indicano in «Ufficio e ricevimento»: l’ufficio protocollo, quelli dei corsi di studio, Internazionalizzazione, Ufficio attestati.', 'I certificati sono una sola pratica con tendina (iscrizione, laurea, rinuncia); la tesi unisce richiesta, Conservazione e Restauro e variazione.']],
                    ['titolo' => 'Prenotazioni e risorse', 'immagine' => 'adm_prenotazioni.png', 'didascalia' => 'Le prenotazioni dell’area, con gli stati e i filtri.',
                     'passi' => ['Ogni risorsa ha orari, durata dello slot, approvazione sì/no e, se vuoi, la ripetizione (settimanale, ogni due settimane, mensile).', 'Le liste d’attesa funzionano da sole: non c’è nulla da configurare. Il responsabile e il Direttore vedono le richieste ferme.']],
                    ['titolo' => 'Backup e ripristino', 'immagine' => 'adm_sistema.png', 'didascalia' => 'Sistema: email, backup e controlli notturni.',
                     'passi' => ['Ogni notte il portale esporta il database, comprime i file del sito (comprese le cartelle uploads con verbali, convenzioni e allegati) e li copia sul NAS. Una copia cifrata del solo database arriva per email agli indirizzi indicati.', 'Dopo ogni backup viene <b>verificato</b>: il file del database si rilegge per intero e lo ZIP dei file si riapre. Se qualcosa non torna ricevi un’email e lo vedi in «Sistema».', 'Una volta al mese conviene <b>provare il ripristino</b> (non tocca il portale): <code>php strumenti/ripristina_backup.php prova backup_DB_…sql.gz</code> importa il database in un database di prova e confronta tabelle e righe.', 'Per i file: <code>php strumenti/ripristina_backup.php file backup_SITO_…zip cartella_nuova</code> li estrae in una cartella a parte, da cui si copiano quelli che servono.', 'Il <b>ripristino vero</b> del database cancella e ricrea le tabelle: <code>… database backup_DB_…sql.gz --sovrascrivi-il-database-del-portale</code>. Si usa solo dopo aver provato il backup; poi si svuota la cartella cache e si apre il portale.'],
                     'nota' => 'Il backup completo dipende dalle variabili nel file .env: BACKUP_NAS_PATH (cartella del NAS), BACKUP_EMAIL e BACKUP_PASSWORD (almeno 12 caratteri) per la copia cifrata del database.'],
                ],
            ],
        ];
    }
}
