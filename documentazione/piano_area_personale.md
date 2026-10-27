# Piano — Area personale per profilo

Richiesta del 27/10/2026: l'area personale cambia in base a chi entra (studente, docente, direttore, personale e uffici, gestore, amministratore, esterno) e l'amministratore può aggiungere, togliere e riordinare i riquadri dal pannello. Bozze grafiche approvate nella chat del 27/10/2026.

## Decisioni dell'utente

- I **tirocini** non si gestiscono nel portale ma sulla piattaforma esterna **TSP 2.0**: nell'area personale di studente, docente e azienda ci sono la guida sui tirocini (`percorso_tirocini.php`) e il collegamento alla piattaforma.
- Ai **docenti** si aggiunge il **tutorato**: possono essere titolari dell'attività e firmare la lettera di incarico e la fine attività.
- All'**amministratore** si mette tutto.
- I **pagamenti del tutorato** non si attivano per ora (riquadro «Ufficio pagamenti» spento di serie e tappe dei pagamenti nascoste al tutor): «poi vediamo».
- L'amministratore deve poter **aggiungere, togliere e riordinare** i riquadri dal pannello.

## Decisioni mie (non contestate)

- Una persona può avere **più profili** (un docente è anche tutor o responsabile di una risorsa): vede i riquadri di tutti i suoi profili, senza doppioni, e se i profili sono più di uno può filtrarli con i pulsanti in alto.
- I **profili** si riconoscono dai dati che il portale ha già (ruolo, gruppo dell'anagrafe di Ateneo, uffici, Direzione), senza nuove anagrafi.
- I **riquadri di sistema** (quelli che esistono già: Da fare per te, Assistenza, Pratiche, Tutorato…) mantengono la loro logica; l'amministratore ne decide **se mostrarli, a quali profili e in che ordine**. La condizione «ha dei dati» resta (la scheda Pratiche non compare a chi non ne ha).
- I **riquadri personalizzati** li scrive l'amministratore dal pannello (titolo, testo, icona, colore, fino a due collegamenti, profili, ordine): servono per il tirocinio, per avvisi e per tutto quello che verrà.
- I riquadri di sistema non si eliminano, si spengono; quelli personalizzati si eliminano.
- I riquadri nuovi (aggiunti da un aggiornamento) compaiono da soli con i profili di partenza; quelli spenti dall'amministratore restano spenti.

## Step

| Step | Cosa | Stato | Data | Note |
|---|---|---|---|---|
| A | Profili e riquadri: struttura e archivio | **fatto** | 27/10/2026 | `ProfiliPersona` (chi è chi), catalogo dei riquadri di sistema, tabella `area_riquadri` (schema v93), `RiquadriRepository` (elenco per profili, salva, ordina, elimina), riquadro «Tirocini» di partenza |
| B | Pannello admin «Area personale» | **fatto** | 27/10/2026 | `admin/area_personale.php`: scheda per profilo con riquadri in ordine, interruttore, su/giù, modifica, nuovo riquadro personalizzato, «Vedi come» un profilo; voce nel menu del pannello e nella guida |
| C | Nuova area personale | **fatto** | 27/10/2026 | `area_personale.php` legge l'elenco dal repository e disegna ogni riquadro (i riquadri di sistema diventano partial in `inc/area/`); intestazione per profilo, pulsanti per filtrare i profili; sezione Eventi in fondo |
| D | Contenuti per profilo | **fatto** | 27/10/2026 | Tirocini (guida + TSP 2.0) per studente, docente, azienda; tutorato per il docente; «tutto» per l'amministratore; pagamenti del tutorato spenti (riquadro e tappe); aggiornare `percorso_tirocini.php` con il passaggio sulla piattaforma TSP 2.0 |
| E | Prove, guide e archivio | da fare | | Prove dei profili e dell'archivio, guida «Come si fa» per ruolo, mappa dei file, archivio da caricare |

## Materiali che servono (metodo «materiali prima di ogni step»)

- Indirizzo della piattaforma TSP 2.0 per le tre categorie (studente, docente, azienda): se è lo stesso, un solo collegamento. Di partenza: `https://unical.tsp.esse3.cineca.it`.
- Per gli **esterni** (scuole, aziende, ospiti): dire se un'azienda è riconoscibile dal login (ora ha il ruolo Ospite/Esterno) o va aggiunta una distinzione.

## Cruscotto a tessere (27/10/2026)

Su richiesta dell'utente l'area personale non mostra più i tab degli eventi in fondo alla pagina: è un **cruscotto di tessere**, una per argomento, con le novità in evidenza (cose da firmare, richieste da approvare, messaggi da leggere, posto liberato…) e il collegamento alla pagina specifica.

| Pagina | Contenuto |
|---|---|
| `area_personale.php` | testata (iniziali, nome, profili) e griglia di tessere per profilo |
| `miei_eventi.php` | prenotazioni, storico e attestati, programma FSL, sondaggi, aule prenotate, scanner QR |
| `profilo.php` | dati personali, scheda di Ateneo, email (modificabili) |
| `il_mio_tutorato.php` | tappe e documenti dell'incarico del tutor |

Nuovo riquadro di sistema «Il mio profilo» (schema v94). I link delle email (`area_personale.php?conferma_posto=…`) continuano a funzionare: rimandano a `miei_eventi.php`.

## Stile comune, calendario, ricerca, app (28/10/2026)

Su approvazione dell'utente (la testata del sito non si tocca):

- **Libreria di stile** (`assets/css/portale-ui.css`, `inc/ui.php`): cinque colori fissi per gli stati (fatto, in attesa, serve un'azione, informazione, chiuso) usati anche dai badge di Bootstrap; tessere, intestazioni, numeri; stati vuoti con illustrazione leggera e pulsante utile; **tema scuro** per l'area personale e le sue pagine, con pulsante e scelta ricordata.
- **Calendario personale** (`calendario.php`, schema v95): eventi, aule, ricevimenti e scadenze in un posto solo; file .ics e abbonamento del telefono con link segreto (si rigenera); tessera nel cruscotto.
- **Riepilogo settimanale con l'agenda**: l'email «Da fare per te» porta anche i sette giorni a venire e arriva a chi ha solo qualcosa in agenda (studenti compresi). **Spento di partenza: lo accende l'amministratore** da Gestione del portale → Email e promemoria.
- **Ricerca unica** (`ricerca.php`): eventi, notizie e pagine, guide, docenti, risposte di Didacticus, raggruppati.
- **App installabile**: la PWA c'era già; ora ha le scorciatoie (area personale, calendario, eventi, cerca), le icone «maskable» e il pulsante «Installa l'app» nell'area personale (su iPhone/iPad spiega come fare).
- Prove: `strumenti/prove/oop/calendario.php` e `pagine_calendario.php`. Sistemate anche le 18 prove che fallivano da prima (erano prove vecchie, nessun errore nel portale).
