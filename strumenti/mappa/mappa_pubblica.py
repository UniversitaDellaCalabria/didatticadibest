# -*- coding: utf-8 -*-
"""Contenuto della mappa del portale per il pubblico generale: che cos'è la piattaforma e cosa permette di fare."""
from mappa_comune import fig, gal, duo, card, griglia, passi, sezione


def costruisci():
    S = []

    # 1 ----------------------------------------------------------------------------------------------
    S.append(('cose', 'Cos\'è', sezione('cose', '1', 'In due parole',
        'Didattica DiBEST è il <b>portale del Dipartimento</b>: un unico sito dove si prenotano eventi e aule, si presentano le pratiche degli studenti, si stipulano le convenzioni con scuole e aziende e si consultano i verbali dei consigli. Niente più moduli cartacei, email sparse e file passati di mano in mano.',
        griglia(
            card('Orientamento', '<p>Eventi, seminari e open day; progetti di Formazione Scuola Lavoro per le scuole, con convenzioni, elenco degli studenti e attestati.</p>', '🧭'),
            card('Didattica', '<p>Pratiche degli studenti (certificati, tesi, convalide, mobilità…), sedute e verbali dei consigli, tutorato e convenzioni di tesi con le aziende.</p>', '🎓'),
            card('Prenotazioni e risorse', '<p>Aule, laboratori, sportelli e auto del Dipartimento prenotabili a slot, con approvazione, lista d\'attesa e diario di bordo.</p>', '📅'),
            card('Gestione', '<p>Un pannello per chi organizza: aree, utenti, uffici, firme, controlli e statistiche. Tutto con un registro di ogni operazione.</p>', '⚙️'),
        )
        + '<p>Ogni persona vede <b>solo ciò che la riguarda</b>: lo studente le sue pratiche, il docente le sue conferme, l\'ufficio le pratiche da lavorare, il Direttore ciò che aspetta la sua firma. Il portale tiene il filo di ogni pratica e avvisa per email a ogni passaggio importante.</p>'
        + fig('map_pub_home', '<b>La home del portale.</b> Le aree del Dipartimento e le novità, con la ricerca in alto.')
    )))

    # 2 ----------------------------------------------------------------------------------------------
    S.append(('per-chi', 'Per chi è', sezione('per-chi', '2', 'Per chi è fatto',
        'Sei in sei: ognuno ha la sua porta d\'ingresso e la sua guida «Come si fa».',
        griglia(
            card('Studenti', '<p>Presentano pratiche online, seguono l\'avanzamento, scaricano certificati e attestati, prenotano aule e partecipano agli eventi.</p><span class="pill b">Credenziali di Ateneo</span>'),
            card('Scuole e docenti referenti', '<p>Prenotano le attività di Formazione Scuola Lavoro, stipulano la convenzione online, caricano l\'elenco degli studenti e ricevono gli attestati.</p><span class="pill g">Link personale</span>'),
            card('Aziende ed enti', '<p>Stipulano la convenzione per le tesi dal proprio link personale: compilano, firmano digitalmente e ricaricano, senza registrarsi.</p><span class="pill g">Link personale</span>'),
            card('Docenti e personale', '<p>Confermano richieste (tesi, attestati), firmano, gestiscono ricevimento e risorse, partecipano alle sedute dei consigli.</p><span class="pill b">Credenziali di Ateneo</span>'),
            card('Uffici', '<p>Protocollo, uffici dei corsi di studio e segreteria lavorano le pratiche passo per passo, con tempi, solleciti e storico.</p><span class="pill">Pannello</span>'),
            card('Direttore e amministratori', '<p>Il Direttore ha un cruscotto di ciò che aspetta la sua firma; gli amministratori configurano il portale.</p><span class="pill">Pannello</span>'),
        )
    )))

    # 3 ----------------------------------------------------------------------------------------------
    S.append(('accesso', 'Come si entra', sezione('accesso', '3', 'Come si entra',
        'Quattro modi, a seconda di chi sei. Nessuno deve ricordare una password in più.',
        griglia(
            card('Credenziali di Ateneo', '<p>Studenti, docenti e personale entrano con il login unico dell\'Università (SSO). Il portale riconosce subito il ruolo.</p>'),
            card('SPID e CIE', '<p>Per chi è esterno e per le operazioni sensibili (per esempio la conferma della lettera di tutorato) si usa l\'identità digitale.</p>'),
            card('Link personale', '<p>Scuole, aziende e docenti che devono solo confermare o firmare ricevono per email un link personale: non serve creare un account.</p>'),
            card('Password dedicata', '<p>I rappresentanti esterni leggono i verbali dei consigli con una password data dall\'Ufficio didattico, valida per un periodo.</p>'),
        )
        + gal(fig('map_area_studente', '<b>Area personale dello studente.</b> Prenotazioni, pratiche, attestati e le cose da fare.'),
              fig('map_area_docente', '<b>Area personale del docente.</b> In più le conferme da dare, le risorse di cui si occupa e il «Da fare per te».'))
        + fig('map_profilo', '<b>Il profilo.</b> I dati arrivano dal login di Ateneo; si può aggiungere un\'email personale.')
    )))

    # 4 ----------------------------------------------------------------------------------------------
    S.append(('sito', 'Il sito pubblico', sezione('sito', '4', 'Il sito pubblico',
        'Senza accedere si può già esplorare: eventi, agenda, attività per le scuole e ricerca. Per prenotare serve il login o i propri dati.',
        gal(fig('map_pub_agenda', '<b>L\'agenda unica.</b> Eventi e seminari con filtri per ambito, ricerca e calendario da aggiungere a Google o Outlook.'),
            fig('map_pub_orientamento', '<b>La vetrina dell\'Orientamento.</b> Per i futuri studenti e per le scuole.'),
            fig('map_pub_ricerca', '<b>La ricerca.</b> Trova eventi, attività e aule per parola.'),
            fig('map_pub_avvisi', '<b>Gli avvisi per email.</b> Ci si iscrive per argomento e si riceve un riepilogo dei nuovi eventi.'))
        + '<p>Ogni area ha il suo colore e il suo aspetto, scelto da chi la gestisce (griglia, lista, calendario, timeline, agenda, gruppi, progetti). Le pagine sono pensate anche per il telefono e rispettano le regole di accessibilità.</p>'    )))

    # 5 ----------------------------------------------------------------------------------------------
    S.append(('eventi', 'Eventi e seminari', sezione('eventi', '5', 'Eventi, seminari e attestati',
        'Open day, seminari di ricerca, giornate per le scuole: ogni evento ha la sua scheda, i suoi turni e i suoi posti.',
        gal(fig('map_pub_area', '<b>Un\'area del portale</b> (qui OpenLab): le attività con date, posti e luogo.'),
            fig('map_pub_evento', '<b>La scheda di un evento.</b> Turni, posti liberi, referenti, luogo con mappa e pulsante per prenotare.'))
        + griglia(
            card('Prenotazione', '<p>Con o senza accesso, a seconda dell\'evento. Posti limitati o «senza limite», approvazione manuale se serve, conferma per email con <b>QR code</b>.</p>'),
            card('Lista d\'attesa', '<p>Se i posti sono finiti ci si mette in coda e si vede la propria posizione. Se si libera un posto arriva un\'email e si hanno 24 ore per confermare.</p>'),
            card('Check-in con QR', '<p>All\'ingresso lo staff legge il QR con il telefono; lo studente può anche fare self check-in. Le presenze si contano in tempo reale.</p>'),
            card('Attestati', '<p>A evento concluso si generano gli attestati in PDF con codice e QR di verifica.</p>'),
        )
        + gal(fig('map_pub_verifica', '<b>Verifica di un attestato.</b> Chiunque può controllare l\'autenticità dal codice o dal QR.'),
              fig('map_adm_iscritti', '<b>Chi gestisce</b> vede gli iscritti, le presenze e può agire in blocco.'))
    )))

    # 6 ----------------------------------------------------------------------------------------------
    S.append(('fsl', 'Formazione Scuola Lavoro', sezione('fsl', '6', 'Formazione Scuola Lavoro',
        'Il percorso di una scuola, dalla prenotazione all\'attestato, senza carta e con ogni passaggio visibile.',
        passi('<b>Prenota</b> le attività dal sito (anche se la convenzione non c\'è ancora: la prenotazione resta in attesa).',
              '<b>Consegna i documenti</b>: il docente referente carica l\'elenco degli studenti e l\'autorizzazione della scuola prima dell\'attività.',
              '<b>Stipula la convenzione</b> online (vedi sotto): compila, il Dirigente firma, il Dipartimento protocolla e firma.',
              '<b>Partecipa</b>: la presenza della classe si registra con il check-in.',
              '<b>Ricevi gli attestati</b> di tutta la classe, con codice di verifica.',
              '<b>Valuta</b> l\'esperienza con una breve scheda.')
        + fig('map_pub_fsl', '<b>I progetti per le scuole.</b> Ogni progetto ha periodo, requisiti, ore, referenti ed edizioni.')
        + '<h3>La convenzione online, in cinque tappe</h3>'
        + passi('<b>La scuola compila</b> i dati dell\'istituto e del Dirigente: il portale prepara Convenzione e Allegato A sui modelli del Dipartimento.',
                '<b>Il Dirigente firma</b> la convenzione in formato PAdES (un PDF firmato digitalmente) e la scuola la carica.',
                '<b>Il Dipartimento prende in carico</b>, protocolla in entrata e avvisa la scuola.',
                '<b>Il Direttore firma</b> dal suo link personale.',
                '<b>Protocollo definitivo</b>: la convenzione è attiva, vale un anno e le prenotazioni in attesa si confermano da sole.')
        + gal(fig('fsl_modulo', '<b>1 · La scuola compila</b> i dati dell\'istituto, del Dirigente e delle attività.'),
              fig('fsl_documenti_pronti', '<b>2 · Documenti pronti.</b> Allegato A in PDF e Convenzione in Word da scaricare e far firmare.'),
              fig('fsl_firmata_caricata', '<b>3 · Caricamento</b> del PDF firmato: conferma di ricezione.'),
              fig('fsl_presa_in_carico', '<b>4 · Presa in carico</b> con il protocollo in entrata.'),
              fig('fsl_attiva', '<b>5 · Convenzione attiva.</b> Le prenotazioni in attesa sono confermate.'),
              fig('fsl_email_link', '<b>Le email</b> accompagnano ogni passaggio, con il link personale.'))
        + '<h3>Parlare con la scuola</h3><p>Se una scuola non ha ancora caricato il PDF, l\'ufficio può mandare con un clic un <b>promemoria</b> (o a tutte le scuole in attesa) oppure scrivere un <b>messaggio</b>. La scuola lo legge e risponde dalla propria pagina; l\'ufficio vede il bollino «Nuova risposta». Anche dalla scheda delle scuole senza convenzione ci sono due pulsanti: <b>Chiedi la convenzione</b> (email con i modelli e la PEC ai docenti della scuola) e <b>Scrivi alla scuola</b> (un messaggio ai docenti, che resta anche nella chat della prenotazione).</p>'
        + '<h3>Per l\'ufficio: cosa devo fare</h3><p>La prima schermata di <b>Formazione Scuola Lavoro</b> mostra solo quello che c\'è da fare, con un numero e un pulsante: PDF da prendere in carico, convenzioni da protocollare, convenzioni dal Direttore, scuole senza convenzione. Nella scheda di ogni scuola i cinque passi della convenzione e l\'unica cosa da fare adesso. Le azioni rare sono nel menu «Altro»; il registro si scarica in Excel, PDF e CSV.</p>'
        + gal(fig('map_adm_fsl_home', '<b>Convenzioni con le scuole:</b> le schede «cosa devo fare», la ricerca di una scuola e il menu «Altro».'),
              fig('map_adm_fsl_scuola', '<b>Scheda della scuola:</b> i cinque passi e il riquadro con la cosa da fare adesso.'))
        + gal(fig('map_adm_fsl_iter', '<b>Convenzioni da seguire:</b> le convenzioni suddivise per tappa, con i pulsanti per ricordare e scrivere.'),
              fig('map_adm_fsl_registro', '<b>Registro delle convenzioni:</b> in vigore o scadute, con «Rinnova» e il menu «Scarica» (Excel, PDF, CSV).'))
        + fig('map_adm_fsl_stipulare', '<b>Scuole senza convenzione:</b> una scheda per ogni scuola, con «Registra la convenzione ricevuta», «Chiedi la convenzione», «Scrivi alla scuola» e i documenti già compilati.')
        + '<h3>Una scuola che fa l\'attività solo come orientamento</h3><p>Se una scuola non ha la convenzione e sceglie di partecipare solo come <b>orientamento</b> e non come Formazione Scuola Lavoro, dal menu «…» della sua iscrizione si sceglie <b>Segna come solo orientamento</b>. Per quella prenotazione il portale non chiede più né la convenzione né l\'elenco degli studenti e l\'autorizzazione, non manda promemoria, non la conta nel registro FSL e nelle valutazioni, e l\'attestato non porta la dicitura FSL. Un clic sulla stessa voce («Riporta a Formazione Scuola Lavoro») la rimette com\'era. Le altre scuole della stessa attività non cambiano.</p>'
        + fig('map_adm_iscritti_orientamento', '<b>Iscritti:</b> la prima scuola ha la convenzione da stipulare e i documenti mancanti; la seconda è segnata «Solo orientamento» e non ha nulla da consegnare.')
        + '<h3>L\'elenco degli studenti</h3><p>Il docente referente riceve un link e inserisce i nomi (a mano, incollando da Excel o con il modello): bastano cognome e nome. Con quell\'elenco il portale prepara gli attestati.</p>'
        + gal(fig('fsl_elenco_2_pagina', '<b>La pagina dei documenti</b> da consegnare: elenco, autorizzazione e modello Excel.'),
              fig('fsl_elenco_4_compilato', '<b>Elenco compilato</b> riga per riga, con il contatore degli studenti.'))
        + gal(fig('map_adm_fsl_riepilogo', '<b>Riepilogo</b> per anno scolastico: attività, scuole, studenti, presenze.'),
              fig('map_adm_fsl_report', '<b>Report FSL</b> per scuola, con esportazione in Excel.'))
    )))

    # 7 ----------------------------------------------------------------------------------------------
    S.append(('pratiche', 'Pratiche studenti', sezione('pratiche', '7', 'Le pratiche degli studenti',
        'Certificati, convalide di esami, tesi, cambi di curriculum, mobilità internazionale… Ogni richiesta è un modulo online che segue un percorso fatto di uffici e firme.',
        passi('<b>Lo studente sceglie la pratica</b> dalla Modulistica e la compila a pagine, con campi guidati (corso, insegnamento, docente, scuola…). Può salvare una bozza.',
              '<b>L\'ufficio protocollo</b> scarica la domanda (PDF/A), la protocolla e registra il numero. Dove serve verifica anche la <b>marca da bollo</b>.',
              '<b>L\'ufficio del corso di studio</b> istruisce la pratica e, se deve decidere il consiglio, la porta in seduta.',
              '<b>Il consiglio delibera</b>; l\'esito entra nel verbale e lo studente riceve l\'estratto.',
              '<b>La segreteria</b> registra in carriera e la pratica si chiude. Per certificati e attestati firma il <b>Direttore</b>.')
        + gal(fig('map_pub_modulistica', '<b>La Modulistica.</b> Documenti da scaricare e richieste da compilare online.'),
              fig('stud_modulo_tesi', '<b>Il modulo a pagine</b> (qui la richiesta di tesi) con «Salva come bozza».'),
              fig('map_pub_pratiche', '<b>Le mie pratiche.</b> Stato e ultimo aggiornamento di ognuna.'),
              fig('stud_pratica_tesi', '<b>Il dettaglio</b>: dove si trova la pratica, la richiesta, lo storico e i messaggi.'))
        + '<h3>Chi lavora la pratica</h3>'
        + gal(fig('proto_scheda', '<b>Ufficio protocollo.</b> Scarica la domanda e registra numero e data.'),
              fig('ref_scheda', '<b>Ufficio del corso.</b> L\'iter con la persona in carico e il pulsante per passare avanti.'),
              fig('ref_marche', '<b>Marche da bollo:</b> l\'ufficio le carica su Esse3, avvisa lo studente e registra il pagamento.'),
              fig('rel_conferma', '<b>Conferma del docente</b> (per tesi e attestati): una pagina semplice, senza firma digitale.'))
        + griglia(
            card('Tempi e solleciti', '<p>Ogni passo ha un tempo massimo. Se una pratica si ferma, l\'ufficio riceve un promemoria; chi deve firmare viene sollecitato.</p>'),
            card('Storico completo', '<p>Ogni passaggio, messaggio e documento resta registrato. Chi ha avuto la pratica in carico continua a vederla.</p>'),
            card('Firme', '<p>Relatore e Direttore firmano dal loro link personale, in formato PAdES. Per il Direttore assente firma il vice.</p>'),
            card('Per gli studenti', '<p>Lo studente può sempre aggiungere documenti e rispondere ai messaggi dell\'ufficio, e scarica il certificato firmato.</p>'),
        )
        + '<h3>Per l\'ufficio: la prima schermata</h3><p>La Didattica si apre su <b>«Cosa devo fare»</b>: poche schede con un numero e un pulsante (pratiche da lavorare, da assegnare, verbali da firmare, da decidere in seduta, in attesa di altri) e, sotto, il collegamento a tutte le sezioni, <b>Tirocini</b> compresi. Una pratica mostra in alto <b>cosa fare adesso</b>; una seduta mostra il passo corrente con un solo pulsante. Le azioni rare sono in «Altro», i file in «Scarica» (Excel, PDF, CSV).</p>'
        + gal(fig('map_adm_did_home', '<b>Cosa devo fare:</b> i numeri e, sotto, tutte le sezioni della Didattica.'),
              fig('map_adm_did_pratica', '<b>Una pratica:</b> cosa fare adesso, iter, assegnazione nascosta in un riquadro.'),
              fig('map_adm_did_seduta', '<b>Una seduta:</b> il passo corrente e un solo pulsante per andare avanti.'))
        + gal(fig('map_adm_did_pratiche', '<b>Il pannello delle pratiche:</b> filtri, fase e «cosa fare» per ognuna.'),
              fig('map_adm_did_moduli', '<b>I moduli si costruiscono da pannello</b>, senza programmare: campi, iter, testi ed email.'),
              fig('map_adm_did_statistiche', '<b>Statistiche</b> per modulo: aperte, accolte, tempi.'),
              fig('dir_firma', '<b>La firma del Direttore</b> dal link personale (firma remota o PDF firmato).'))
    )))

    # 8 ----------------------------------------------------------------------------------------------
    S.append(('sedute', 'Sedute e verbali', sezione('sedute', '8', 'Sedute dei consigli e verbali',
        'Dalla convocazione al verbale firmato, ogni seduta ha i suoi passi e i suoi documenti.',
        passi('<b>Convocazione</b>: ordine del giorno e invito per email, con il link per giustificare l\'assenza.',
              '<b>Presenze</b> dei componenti (presente, assente giustificato o no).',
              '<b>Punti all\'ordine del giorno</b>: ogni punto ha il suo testo; le pratiche degli studenti stanno sotto il punto giusto.',
              '<b>Decisioni</b> sulle pratiche, con l\'estratto per lo studente.',
              '<b>Verbale</b> in Word con testata e piede del Dipartimento, poi <b>firma digitale</b> del segretario e del coordinatore.')
        + gal(fig('ref_sedute', '<b>I consigli dei corsi di studio</b> e le sedute prossime e passate.'),
              fig('ref_seduta', '<b>Una seduta:</b> sette tappe guidate, dalla convocazione al verbale firmato.'))
        + '<h3>L\'archivio dei verbali</h3><p>Tutti i verbali, quelli nati nel portale e quelli storici in PDF, in un archivio con ricerca anche nel testo. L\'Ufficio didattico può caricare cartelle intere; ogni referente vede i verbali del proprio consiglio.</p>'
        + fig('map_adm_did_archivio', '<b>L\'archivio verbali.</b> Carica, cerca e scegli quali verbali pubblicare.')
    )))

    # 9 ----------------------------------------------------------------------------------------------
    S.append(('verbali', 'Verbali online', sezione('verbali', '9', 'I verbali online: leggere o scaricare',
        'I verbali pubblicati si consultano da una pagina dedicata. Cambia ciò che si può fare in base a <b>come</b> si entra.',
        '<div class="grid">'
        + card('Con la sola password', '<p><b>Sola lettura.</b> Il verbale si legge nella pagina con una filigrana che riporta chi lo sta consultando, la data e l\'ora. Non c\'è il pulsante per scaricare né per stampare.</p><span class="pill a">Rappresentanti esterni</span>')
        + card('Con le credenziali di Ateneo', '<p><b>Lettura e download</b>, ma solo per le persone abilitate dall\'Ufficio didattico, scelte dall\'anagrafe di Ateneo, e per gli amministratori.</p><span class="pill g">Persone abilitate</span>')
        + '</div>'
        + '<p>La pagina offre due modi per trovare un verbale: <b>«Per corso»</b> (si sceglie il corso, poi l\'organo, poi l\'anno) e <b>«Cerca»</b> (ricerca per data, numero, anno o organo, con filtri e tabella ordinata).</p>'
        + gal(fig('map_pub_verbali_password', '<b>L\'ingresso</b> con la password, oppure con le credenziali di Ateneo.'),
              fig('map_pub_verbali_sola_lettura', '<b>Vista «Per corso»</b> con la sola password: solo «Leggi».'),
              fig('map_pub_verbali_sola_lettura_cerca', '<b>Vista «Cerca»</b>: filtri e tabella per data.'),
              fig('map_pub_verbale_lettura_password', '<b>La lettura</b>: il verbale è disegnato nella pagina, con filigrana.'),
              fig('map_pub_verbali', '<b>Chi è abilitato</b> vede anche «Scarica» su ogni verbale.'),
              fig('map_adm_did_accessi', '<b>Per l\'Ufficio didattico:</b> le password, i consigli visibili, chi è abilitato a scaricare e il registro di chi è entrato.'))
        + '<div class="nota"><b>Una precisazione onesta.</b> Un file che si mostra in un browser non può essere reso davvero «non scaricabile»: uno screenshot è sempre possibile. La filigrana personale e il registro degli accessi servono a scoraggiare e a risalire a chi ha consultato.</div>'
    )))

    # 10 ---------------------------------------------------------------------------------------------
    S.append(('tutorato', 'Tutorato', sezione('tutorato', '10', 'Il tutorato: dal bando al pagamento',
        'Gli studenti tutor vincono un bando e il portale accompagna tutto: lettera di incarico, firme, registro delle ore e pagamento.',
        passi('<b>Il bando</b> e i vincitori: l\'elenco si importa dal decreto di approvazione atti (Word) e ogni vincitore è abbinato al suo insegnamento e al docente.',
              '<b>La lettera di incarico</b> arriva allo studente, che la <b>conferma con SPID o CIE</b>.',
              '<b>Le firme</b> in PAdES: docente responsabile e Direttore; poi il protocollo.',
              '<b>Il registro</b>: il tutor segna giorno, ore e attività; il docente approva.',
              '<b>Fine attività</b>: il portale compila la dichiarazione, il docente la firma e il referente valida.',
              '<b>Promemoria a mano</b>: dalla scheda della lettera l\'ufficio può invitare il tutor a compilare il registro (anche in blocco, per tutti i tutor in ritardo di un bando) e mandare al docente la dichiarazione di fine attività già pronta da firmare.',
              '<b>Il pagamento</b>: lo studente indica i dati (l\'IBAN è conservato cifrato) e l\'ufficio pagamenti procede.')
        + gal(fig('tutorato_1_dati_pagamento', '<b>La dichiarazione individuale</b> compilata online dal tutor.'),
              fig('tutorato_7_area_personale', '<b>L\'Area personale del tutor</b> con la linea del tempo dell\'incarico.'),
              fig('map_adm_tutorato', '<b>Il pannello del tutorato:</b> cosa devo fare (lettere da inviare, da firmare, da protocollare, pagamenti) e l\'elenco dei bandi. Il percorso dopo la firma è lo stesso anche con l\'iter per Uniticket.'))
    )))

    # 11 ---------------------------------------------------------------------------------------------
    S.append(('tesi', 'Convenzioni di tesi', sezione('tesi', '11', 'Convenzioni di tesi con aziende ed enti',
        'Per fare la tesi in azienda serve una convenzione. L\'azienda fa tutto da un link, senza registrarsi.',
        passi('<b>Il link</b>: l\'ufficio lo manda all\'azienda, oppure l\'azienda lo chiede da sola dalla pagina pubblica.',
              '<b>L\'azienda compila</b> i dati dell\'ente e del legale rappresentante e può proporre <b>modifiche agli articoli</b>.',
              '<b>Scarica</b> la convenzione precompilata (Word o PDF/A), la <b>firma in PAdES</b> e la ricarica.',
              '<b>L\'operatore</b> prende in carico, protocolla e registra il numero.',
              '<b>Il Direttore firma</b> dal suo link; l\'ufficio protocolla il firmato e carica il file protocollato.',
              '<b>Stipulata</b>: entra nell\'<b>archivio</b> delle convenzioni, dove si caricano anche quelle già in essere.')
        + gal(fig('map_pub_convenzione_tesi_richiesta', '<b>La richiesta dell\'azienda</b>, dalla pagina pubblica.'),
              fig('tesi_2_azienda_modulo', '<b>La pagina dell\'azienda:</b> dati, articoli e modifiche proposte.'),
              fig('tesi_6_direttore_firma', '<b>La firma del Direttore</b> sul PDF già firmato dall\'azienda.'),
              fig('map_adm_convenzioni_tesi', '<b>Il pannello:</b> le convenzioni per tappa e l\'archivio.'),
              fig('tesi_3_archivio', '<b>L\'archivio</b> delle convenzioni stipulate, con scadenze.'),
              fig('tesi_4_elenco_pubblico', '<b>L\'elenco</b> delle aziende convenzionate, da cui lo studente sceglie nella richiesta di tesi. Si vede solo dopo l\'accesso.'))
    )))

    # 12 ---------------------------------------------------------------------------------------------
    S.append(('tirocini', 'Tirocini', sezione('tirocini', '12', 'Tirocini curriculari: dove farli',
        'Una pagina, riservata a chi ha fatto l\'accesso, con le aziende convenzionate e i laboratori dell\'Ateneo. Il tirocinio si attiva dalla piattaforma TSP di Cineca.',
        passi('<b>L\'ufficio</b> scarica dal TSP il report delle aziende e lo carica nel pannello: restano solo quelle con la convenzione attiva.',
              '<b>Gli indirizzi</b> si aggiungono a mano oppure con un file Excel; la regione si ricava dalla provincia. Un indirizzo trovato in rete si pubblica solo dopo la conferma.',
              '<b>Le ditte individuali</b> non si pubblicano se non lo si decide: sono dati di una persona.',
              '<b>I laboratori</b> arrivano da soli dalle API pubbliche di Ateneo, insieme all\'anagrafe; se ne può nascondere qualcuno.',
              '<b>Lo studente</b> cerca per regione, provincia, comune o settore, oppure sceglie un laboratorio interno.')
        + gal(fig('map_pub_tirocini_accesso', '<b>Senza accesso</b> la pagina dice subito che bisogna accedere, poi riporta qui.'),
              fig('map_pub_tirocini', '<b>Aziende ed enti</b> con ricerca per luogo e settore.'),
              fig('map_pub_tirocini_lab', '<b>Laboratori dell\'Ateneo</b>, presi dalle anagrafi di Ateneo.'),
              fig('map_adm_tirocini', '<b>Il pannello:</b> carica il report, completa gli indirizzi, conferma quelli trovati.'),
              fig('map_adm_tirocini_lab', '<b>I laboratori</b> nel pannello: si possono nascondere dall\'elenco.'))
    )))

    # 13 ---------------------------------------------------------------------------------------------
    S.append(('risorse', 'Prenotazioni e risorse', sezione('risorse', '13', 'Aule, laboratori, sportelli e auto',
        'Le risorse del Dipartimento si prenotano a slot da un calendario, con le regole scelte per ognuna.',
        gal(fig('map_pub_risorse', '<b>Le risorse</b> con orari e regole.'),
            fig('stud_calendario', '<b>Il calendario.</b> Gli slot liberi si prenotano con un clic; su quelli occupati ci si mette in lista d\'attesa.'))
        + griglia(
            card('Regole su misura', '<p>Orari settimanali, durata dello slot, preavviso, chi può prenotare, approvazione sì/no, chiusure e festività.</p>'),
            card('Ripetizioni', '<p>Ogni settimana, ogni due settimane o ogni mese; i giorni non disponibili vengono saltati e segnalati.</p>'),
            card('Lista d\'attesa', '<p>Se si libera uno slot, il primo in coda ha 24 ore per prenotarlo, poi passa al successivo.</p>'),
            card('Responsabili', '<p>Una o più persone (o un intero ufficio) ricevono le richieste e le approvano dalla loro Area personale.</p>'),
            card('Diario di bordo', '<p>Per auto e mezzi: chi li usa compila km, rifornimenti e segnalazioni; il responsabile controlla ed esporta in Excel.</p>'),
            card('Scadenze', '<p>Bollo, assicurazione, tagliando, tarature: avviso prima e dopo la data e rinnovo con un clic.</p>'),
        )
        + gal(fig('resp_risorse', '<b>«Le mie risorse»</b> del responsabile: richieste da approvare e prenotazioni in arrivo.'),
              fig('resp_cruscotto', '<b>Il cruscotto della risorsa:</b> utilizzo, fasce più richieste, chi la usa di più.'),
              fig('resp_diario', '<b>Il diario di bordo</b> di un\'auto.'),
              fig('map_adm_risorse', '<b>Chi gestisce</b> imposta orari, chiusure, campi e responsabili.'))
    )))

    # 13 ---------------------------------------------------------------------------------------------
    # 14 ---------------------------------------------------------------------------------------------
    S.append(('assistenza', 'Assistenza e Didacticus', sezione('assistenza', '14', 'Assistenza e Didacticus, l\'assistente virtuale',
        'Per qualsiasi dubbio (tirocini, certificati, pratiche, tutorato, tesi, eventi, accesso) studenti, personale e utenti esterni scrivono dal pulsante <b>«Serve aiuto?»</b>, in basso a destra su ogni pagina. Risponde prima <b>Didacticus</b>, l\'assistente virtuale; se non basta, passa la parola a una persona dell\'ufficio, che riceve un avviso e continua la stessa chat.',
        griglia(
            card('Per tutti', '<p>Chi ha il login ritrova la sua chat e le sue pratiche; gli esterni scrivono nome ed email e ricevono un <b>link personale</b> per riprendere la conversazione, senza registrarsi.</p>', '💬'),
            card('Didacticus', '<p>Risponde subito alle domande più comuni con le risposte scritte dal Dipartimento, con <b>pulsanti, link e allegati</b>. Se non sa, lo dice: non inventa. Se la risposta non basta, un clic e arriva una persona.</p>', '🦉'),
            card('Gli operatori', '<p>Le persone dell\'ufficio «Assistenza» vedono le chat da prendere, le loro, quelle ancora con Didacticus e quelle chiuse. «Prendo io», «Passa a…», note interne, «Chiudi».</p>', '🎧'),
            card('Avvisi a finestrella', '<p>Gli operatori possono ricevere una <b>finestrella di Windows</b> a ogni richiesta, anche a sito chiuso; chi ha chiesto aiuto può essere avvisato delle risposte (senza il testo).</p>', '🔔'),
            card('Orari e privacy', '<p>Gli orari si scrivono dal pannello e fuori orario si può scrivere lo stesso. Le chat si cancellano dopo 12 mesi; restano solo i numeri per le statistiche.</p>', '🛡️'),
            card('Impara dall\'uso', '<p>Le domande a cui Didacticus non ha saputo rispondere finiscono in una lista: con un clic diventano nuove voci, con la risposta data dall\'operatore come bozza.</p>', '🌱'),
        )
        + '<h3>Come funziona, per chi chiede aiuto</h3>'
        + passi(
            'Dal pulsante <b>«Serve aiuto?»</b> (o dalla pagina Assistenza, o dal pulsante «Chiedi aiuto su questa pratica») si sceglie l\'argomento e si scrive la domanda. Gli esterni indicano nome ed email.',
            '<b>Didacticus risponde subito</b>, con il testo, i pulsanti dei link e gli eventuali allegati. Se non è sicuro propone delle voci vicine («intendevi…?»).',
            'Sotto ogni risposta: <b>«Sì, grazie»</b> chiude la chat; <b>«Non mi basta»</b> o <b>«Parla con un operatore»</b> (o la parola «operatore» scritta in chat) passano la parola a una persona.',
            'La chat passa agli operatori, che ricevono email e finestrella con la domanda e vedono già quello che Didacticus ha risposto. Chi ha chiesto aiuto continua a scrivere nella stessa chat e, se chiude la pagina, riceve la risposta per email.',
            'A fine conversazione si può dire se l\'assistenza è stata utile.')
        + gal(fig('map_pub_assistenza_form', '<b>Nuova richiesta.</b> Argomento, domanda e, per gli esterni, nome ed email. Chi preferisce può scrivere subito a un operatore.'),
              fig('map_pub_assistenza_didacticus', '<b>La chat con Didacticus:</b> risposta, link, «Ti è stato utile?» e «Parla con un operatore».'),
              fig('map_pub_assistenza_esterno', '<b>La chat di un utente esterno</b>, ripresa dal suo link personale.'))
        + '<h3>Per chi risponde</h3><p>Le persone dell\'ufficio <b>Assistenza</b> (scelte da Gestione del portale → Uffici) trovano la voce <b>Assistenza</b> nel menu di Didattica e le chat anche nella casella <b>Messaggi</b>. La chat si aggiorna da sola; il pulsante «Prendo io» assegna la chat, «Passa a…» la gira a un collega, le <b>note interne</b> non le vede chi ha chiesto aiuto.</p>'
        + gal(fig('map_adm_assistenza_coda', '<b>Una chat da prendere</b>, con le schede Da prendere · Mie · Tutte · Con Didacticus · Chiuse.'),
              fig('map_adm_assistenza', '<b>Una chat in carico</b>: la domanda, la risposta di Didacticus, la nota interna e la risposta dell\'operatrice.'),
              fig('map_adm_assistenza_statistiche', '<b>Le statistiche</b>: chat per argomento e per operatore, tempo di prima risposta, utilità, e quante ne ha risolte Didacticus. Si scaricano in Excel, PDF e CSV.'))
        + '<h3>Istruire Didacticus</h3><p>Da <b>Didattica → Didacticus</b> chi gestisce l\'assistenza scrive e cura la <b>base di conoscenza</b>: ogni <b>voce</b> ha le domande che la persona potrebbe fare (una per riga), le parole chiave e i sinonimi, la risposta, i <b>link</b> che diventano pulsanti e gli <b>allegati</b> da scaricare (PDF, Word, Excel, immagini). Una voce si può spegnere, riservare a chi ha fatto l\'accesso o impostare perché proponga sempre un operatore. L\'elenco si scarica e si può importare da un file CSV per scrivere tante voci in una volta.</p>'
        + passi(
            '<b>Voci</b>: l\'elenco con quante volte ogni risposta è stata usata e quanti l\'hanno trovata utile.',
            '<b>Prova</b>: si scrive una domanda come la scriverebbe uno studente e si vede cosa risponderebbe Didacticus, con il punteggio di ogni voce.',
            '<b>Domande senza risposta</b>: quelle che Didacticus non ha saputo gestire; «Crea una voce» le trasforma in risposte.',
            '<b>Impostazioni</b>: acceso o spento, saluto, soglia di sicurezza e immagine di profilo.')
        + gal(fig('map_adm_didacticus_voci', '<b>La base di conoscenza:</b> voci, usi, utilità, stato e azioni.'),
              fig('map_adm_didacticus_voce', '<b>Una voce:</b> domande, parole chiave, risposta, link e allegati.'),
              fig('map_adm_didacticus_prova', '<b>La prova:</b> cosa risponderebbe e le voci più vicine con il punteggio.'),
              fig('map_adm_didacticus_mancate', '<b>Domande senza risposta</b> da trasformare in voci.'),
              fig('map_adm_didacticus_impostazioni', '<b>Impostazioni:</b> acceso/spento, saluto, soglia e immagine del gufo.'))
    )))

    S.append(('direttore', 'Il Direttore', sezione('direttore', '15', 'Il Direttore: un cruscotto e le firme',
        'Una pagina sola con le cose che aspettano la firma del Direttore e quelle che si sono fermate nei vari moduli.',
        gal(fig('map_dir_cruscotto', '<b>Il Cruscotto del Direttore.</b> Documenti da firmare, pratiche ferme, conferme in ritardo, verbali, convenzioni, tutorato e risorse.'),
            fig('map_dir_assente', '<b>«Direttore assente».</b> Con un interruttore firme e avvisi passano al vice direttore, fino al rientro.'))
        + '<p>Direttore e vice direttore hanno gli stessi permessi. L\'interruttore «Direttore assente» può essere attivato dal Direttore, dal vice, dalla segreteria di direzione e dagli amministratori; ogni volta è registrato nel registro operazioni. Una volta a settimana il Direttore (come chiunque abbia cose in sospeso) riceve per email un riepilogo di ciò che lo aspetta.</p>'
    )))

    # 14 ---------------------------------------------------------------------------------------------
    S.append(('pannello', 'Il pannello', sezione('pannello', '16', 'Il pannello di gestione',
        'Per chi organizza: un pannello unico, diviso in cinque moduli. Ognuno entra solo in quelli per cui è abilitato.',
        fig('map_adm_inizio', '<b>L\'ingresso.</b> Le cose da fare e i moduli a cui si ha accesso.')
        + griglia(
            card('Eventi e seminari', '<p>Aree, eventi e turni, progetti, iscritti e presenze, scanner QR, sondaggi, statistiche e report per ambito.</p>'),
            card('Formazione Scuola Lavoro', '<p>Convenzioni, verifica delle iscrizioni, valutazioni, anagrafe delle scuole e report.</p>'),
            card('Prenotazioni e risorse', '<p>Risorse, orari, prenotazioni, approvazioni e cruscotto.</p>'),
            card('Didattica', '<p>Pratiche, sedute e verbali, moduli e documenti, ricevimento, statistiche, tutorato, convenzioni di tesi.</p>'),
            card('Gestione del portale', '<p>Anagrafi di Ateneo, testata e menu del sito, utenti e abilitazioni, uffici e persone, sistema e registri.</p>'),
        )
        + '<h3>Aree, eventi e iscritti</h3>'
        + gal(fig('map_adm_aree', '<b>Le aree</b> del portale: visibili o nascoste, con i loro gestori.'),
              fig('map_adm_nuova_area', '<b>Nuova area</b> in pochi campi: nasce nascosta, pronta da preparare.'),
              fig('map_adm_dashboard', '<b>La dashboard</b> di un\'area: prenotazioni, presenze, messaggi.'),
              fig('map_adm_eventi', '<b>Eventi e turni.</b> Posti, orari, aula, duplicazione.'),
              fig('map_adm_progetti', '<b>Progetti</b> con edizioni, requisiti e attestati.'),
              fig('map_adm_form', '<b>Il Form Builder</b>: campi personalizzati per ogni prenotazione.'),
              fig('map_adm_iscritti', '<b>Iscritti e presenze</b>, con azioni di massa.'),
              fig('map_adm_statistiche', '<b>Statistiche e report</b> con esportazione.'),
              fig('map_adm_report_ambiti', '<b>Report per ambito</b>, anche per la Terza missione.'),
              fig('map_adm_impostazioni_area', '<b>Impostazioni dell\'area</b>: colori, layout e regole di iscrizione.'),
              fig('map_adm_sondaggi', '<b>Sondaggi</b> e questionari collegati agli eventi.'))
        + '<h3>I messaggi, tutti in un posto</h3><p>Il pulsante <b>Messaggi</b> in alto apre una casella unica: le conversazioni sulle prenotazioni di tutte le aree che gestisci e le risposte delle scuole sulle convenzioni FSL, ognuna con il <b>modulo</b> da cui arriva. Si filtra per modulo o per «da leggere», e il numero rosso nel pulsante somma tutto. Si risponde da lì e una conversazione finita si <b>archivia</b>: sparisce dalla casella ma non si cancella, si ritrova in «Archiviate» e torna da sola se l\'altra parte scrive di nuovo. Chi gestisce solo alcune attività vede solo i loro messaggi.</p>'
        + fig('map_adm_messaggi', '<b>La casella dei messaggi:</b> prenotazioni e convenzioni insieme, col modulo, i filtri, «Leggi / Rispondi» e «Archivia».')
        + '<h3>Il sito e le persone</h3>'
        + gal(fig('map_adm_testata', '<b>Testata e home</b>: logo, carosello e widget.'),
              fig('map_adm_menu', '<b>Il menu</b> a tre livelli, con ordine a trascinamento.'),
              fig('map_adm_utenti', '<b>Utenti e abilitazioni</b>: chi può fare cosa e dove.'),
              fig('map_adm_anagrafe_docenti', '<b>Le anagrafi di Ateneo</b>: docenti e personale, aggiornati ogni settimana.'),
              fig('map_adm_anagrafe_strutture', '<b>Strutture</b> e corsi di studio.'),
              fig('map_adm_scuole', '<b>L\'anagrafe delle scuole</b> del Ministero.'))
        + '<h3>Testi delle email</h3><p>Tutte le email che il portale invia hanno <b>oggetto, testo e pulsante modificabili</b> dalla pagina «Testi delle email», senza toccare il codice: per ognuna si vede quando parte e a chi arriva, si scrive con i segnaposto (<code>{{tutor}}</code>, <code>{{bando}}</code>…) che il portale riempie da solo, si guarda l\'anteprima, ci si manda una prova e si può tornare al testo originale. I moduli sono <b>schede</b> in alto e la ricerca cerca in tutti. Chi gestisce il tutorato modifica quelle del tutorato; gli amministratori tutte. Le modifiche restano nel registro attività.</p>'
        + '<h3>Uffici e persone</h3><p>Gli uffici del Dipartimento (Direzione, protocollo, uffici dei corsi, segreteria…) e di Ateneo (per esempio i pagamenti) si creano <b>una volta sola</b> e si richiamano in pratiche, tutorato, risorse e convenzioni. Le persone si scelgono <b>sempre dall\'anagrafe di Ateneo</b>; la Direzione ha Direttore e vice.</p>'
        + gal(fig('map_adm_uffici', '<b>Un ufficio</b> con le sue persone e il suo ruolo nelle pratiche.'),
              fig('map_adm_uffici_direzione', '<b>La Direzione</b>: chi firma ora e l\'interruttore «Direttore assente».'),
              fig('map_adm_uffici_ateneo', '<b>Gli uffici di Ateneo</b>.'))
        + '<h3>Sistema e controlli</h3>'
        + fig('map_adm_portale_home', '<b>Gestione del portale:</b> in alto i controlli da guardare (controlli del sito, email non partite, persone non più in anagrafe, Direttore), poi tutte le sezioni.')
        + gal(fig('map_adm_sistema', '<b>Email e controlli:</b> invio, backup, controllo notturno del sito.'),
              fig('map_adm_sistema_promemoria', '<b>Promemoria:</b> si possono sospendere per Direttore e uffici durante l\'avvio, e riattivare.'),
              fig('map_adm_audit', '<b>Il registro operazioni</b>: chi ha fatto cosa e quando.'))
    )))

    # 15 ---------------------------------------------------------------------------------------------
    S.append(('fiducia', 'Fiducia e privacy', sezione('fiducia', '17', 'Firme, protocollo, privacy',
        'Il portale è pensato per documenti che valgono: firmati, protocollati, tracciati e protetti.',
        griglia(
            card('Firme digitali', '<p>I documenti importanti si firmano in formato <b>PAdES</b> (PDF firmato). Il portale controlla che la firma sia integra e del titolare.</p>'),
            card('Protocollo', '<p>Numero e data si registrano passo per passo; il file protocollato resta collegato alla pratica.</p>'),
            card('Privacy', '<p>Informativa, cookie solo tecnici, dati conservati per il tempo necessario e poi cancellati o anonimizzati. Nessun servizio esterno riceve i tuoi dati.</p>'),
            card('Tracciabilità', '<p>Ogni operazione di gestione e ogni accesso ai verbali pubblici è registrato, con l\'indirizzo IP reso anonimo.</p>'),
            card('Copie di sicurezza', '<p>Ogni notte il database e i file sono copiati e riletti per verificarli; il sito si autocontrolla e avvisa se qualcosa non va.</p>'),
            card('Accessibilità', '<p>Struttura dei titoli, navigazione da tastiera, contrasti verificati e carattere ingrandibile.</p>'),
        )
    )))

    # 16 ---------------------------------------------------------------------------------------------
    S.append(('guide', 'Guide', sezione('guide', '18', 'Se hai bisogno di aiuto',
        'Il portale ha le sue guide «Come si fa», una per ruolo, con le schermate. Si trovano nella pagina <code>guida.php</code> e si possono stampare.',
        griglia(
            card('Studente', '<p>Presentare una pratica, seguirla, prenotare aule.</p>'),
            card('Docente e relatore', '<p>Confermare, firmare, gestire il ricevimento.</p>'),
            card('Uffici', '<p>Protocollo, ufficio del corso, referente del consiglio.</p>'),
            card('Responsabile di risorsa', '<p>Approvare, controllare il diario e le scadenze.</p>'),
            card('Tutor', '<p>Lettera di incarico, registro e pagamento.</p>'),
            card('Scuole', '<p>La convenzione FSL passo per passo; l\'elenco degli studenti.</p>'),
            card('Chi risponde all\'assistenza', '<p>Le chat, «Prendo io», le note e le statistiche; come istruire Didacticus.</p>'),
            card('Direttore', '<p>Cruscotto, firme e «Direttore assente».</p>'),
            card('Amministratore', '<p>Moduli, uffici, utenti, backup e ripristino.</p>'),
        )
        + gal(fig('map_pub_guida', '<b>L\'indice delle guide.</b>'), fig('map_pub_guida_studente', '<b>Una guida</b> (qui quella dello studente), con le schermate.'))
    )))
    return S
