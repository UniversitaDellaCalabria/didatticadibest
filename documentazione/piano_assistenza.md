# Piano — Assistenza, notifiche e Didacticus

> Nato il 22/10/2026 dalla richiesta: un servizio di assistenza per studenti e utenti esterni (non solo sulle pratiche), con chat, notifiche a finestrella su Windows per gli operatori e un bot, **Didacticus**, che risponde alle prime domande e poi passa la parola all'operatore.
> Stesso metodo del `piano_nuove_feature.md`: **uno step alla volta**; prima di ogni step si elencano i materiali che servono; a fine step si aggiorna il Registro (§5) e `mappa_file_da_caricare.html`.
> Regole del progetto (schema in `Definizioni.php` + `Migrazioni::VERSIONE`, classi in `src/`, pagine sottili, CSRF, nessun BOM, niente attribuzioni a Claude nei commit) = §0 e «Regole» del piano principale.

---

## 1. Decisioni prese (22/10/2026)

| # | Decisione | Perché |
|---|---|---|
| H-1 | Si fanno **chat, notifiche push e Didacticus a regole** (nessun servizio esterno, nessun modello linguistico). | Scelta dell'utente: niente costi, niente dati verso terzi, niente parere privacy da attendere. |
| H-2 | La chat **non usa WebSocket** (il server è Apache/PHP): la pagina chiede i messaggi nuovi ogni 4 secondi (ogni 20 se la scheda è nascosta, si ferma se la conversazione è chiusa). | Per qualche decina di persone contemporanee basta ed è affidabile. |
| H-3 | **Operatori** = persone dell'ufficio fisso **«Assistenza»** (si scelgono da Gestione del portale → Uffici, come per l'ufficio convenzioni FSL) + gli amministratori. | Riusa l'anagrafe degli uffici (F8): niente nuovo elenco di persone. |
| H-4 | **Studenti e personale** entrano col loro accesso (SSO) e la conversazione è collegata al loro utente. Gli **esterni** scrivono nome ed email, risolvono il CAPTCHA interno (già presente) e ricevono per email un **link personale** (token) per riprendere la conversazione. | Come già per scuole (convenzione) e aziende (tesi). |
| H-5 | Le notifiche a finestrella sono **Web Push** (le mostra Windows da Chrome/Edge, anche a scheda chiusa se il browser è aperto in background). Il motore è **scritto in PHP puro con OpenSSL**, senza Composer. | Su Drive Composer non estrae bene, il server non ha `vendor/`; il progetto scrive già in proprio SMTP, PDF, Excel. Verificabile con i vettori di prova della RFC 8291. |
| H-6 | La notifica **agli studenti e agli esterni non contiene il testo** del messaggio («Hai una risposta dall'assistenza»); quella agli operatori mostra chi scrive e le prime parole. | Il testo può contenere dati personali e finisce nel centro notifiche del computer. |
| H-7 | **Didacticus** risponde confrontando la domanda con una **base di conoscenza** (voci: domande/parole chiave → risposta + link), gestita dal pannello. Non inventa: se non trova una voce abbastanza simile, lo dice e propone l'operatore. | Prevedibile e controllabile; cresce con l'uso (le domande senza risposta diventano voci con un clic). |
| H-8 | Conservazione: conversazioni chiuse da più di **12 mesi** → testi cancellati, resta il conteggio (come per gli studenti, cron_background). Da confermare con il DPO insieme all'informativa. | Coerenza con le altre retention del portale. |
| H-9 | Nel widget compare l'avviso «Non scrivere password, codice fiscale o dati bancari». | Riduce i dati inutili nelle conversazioni. |

---

## 2. Gli step

### H1 — Assistenza e chat *(schema v86 e successivi)*

**Materiali da te (non bloccanti):** i nomi delle persone che faranno da operatori (si inseriscono dal pannello, anche dopo); gli **orari** di assistenza (es. lun–ven 9–13 e 14–16, serve per «fuori orario»); eventuali argomenti da aggiungere a quelli di partenza.

* **H1a — Dati e servizi.** Tabelle `assistenza_conversazioni` (utente, nome, email, argomento, contesto — es. pratica o prenotazione —, stato `nuova|assegnata|chiusa`, operatore, token, canale `utente|esterno`, tempi) e `assistenza_messaggi` (da `utente|operatore|bot|sistema`, testo, letto). Ufficio fisso `assistenza` (in `CHIAVI_FISSE`). Nuovo modulo `src/Assistenza/`: `Costanti` (argomenti: Tirocini e FSL, Certificati e attestati, Pratiche e moduli, Tutorato, Tesi, Eventi e prenotazioni, Accesso e account, Altro), `ConversazioniRepository`, `MessaggiAssistenzaRepository`, `ServizioAssistenza` (apri, scrivi, prendi in carico, passa, chiudi, riapri; limiti anti-abuso), `NotificheAssistenza` (email). Prove unit e integration.
* **H1b — Casella degli operatori.** Nuovo tipo «assistenza» nella casella unica (`CasellaMessaggi`) e pagina `admin/assistenza.php`: code *Da prendere / Mie / Tutte / Chiuse*, chat a tutta pagina con la scheda della persona (e della pratica collegata), «Prendo io», «Passa a…», «Chiudi», note interne, risposte pronte. Contatore nel pulsante Messaggi e in «Da fare per te».
* **H1c — Il lato di chi chiede aiuto.** Pagina `assistenza.php` (chat a tutta pagina; SSO o link personale) e **widget «Serve aiuto?»** in basso a destra su ogni pagina pubblica, con scelta dell'argomento. Endpoint JSON `assistenza_ajax.php` per i messaggi nuovi. Link «Chiedi aiuto su questa pratica» dalle pratiche. Fuori orario: si lascia il messaggio e si riceve la risposta per email.
* **H1d — Rifiniture.** Orari e servizio acceso/spento, avviso fuori orario, conservazione a 12 mesi nel cron, collegamento alle pratiche e alle prenotazioni, valutazione di chi ha chiesto aiuto (utile / non utile), `mappa_file_da_caricare.html`. *(Fatto il 22/10/2026.)*
* **H1e — Statistiche e guide.** Statistiche (prima risposta, durata, argomenti, valutazioni) con **Scarica Excel/PDF/CSV** (X1), guida per ruolo (operatore, studente) e riga nell'informativa privacy. *(Fatto il 22/10/2026.)*

**Verifica iniziale:** `CasellaMessaggi`, `admin/messaggi.php`, `ServizioUffici`/`UfficiRepository::CHIAVI_FISSE`, `captcha_prenotazione()`/`check_rate_limit()`, `header.php`/`footer.php` (dove agganciare il widget), `CoseDaFare`.

### H2 — Notifiche push

**Materiali:** nessuno. Serve poter provare da un PC Windows con Chrome o Edge sul sito vero (HTTPS) e **una riga nel `.env`** del server per le chiavi (le genera lo strumento dello step).

* **H2a — Motore Web Push.** `src/Assistenza/Push/`: chiavi VAPID (ES256), cifratura del messaggio `aes128gcm` (RFC 8291), invio con `curl`, gestione di 404/410 (sottoscrizione scaduta → si elimina). Tabella `push_sottoscrizioni`. Strumento `strumenti/genera_chiavi_push.php`. **Prove con i vettori ufficiali della RFC 8291** (cifro, poi decifro con la chiave del «browser»).
* **H2b — Operatori.** Pulsante «Attiva le notifiche su questo computer» in `admin/assistenza.php`; `sw.js` con `push` e `notificationclick` (apre la chat giusta, o porta in primo piano la scheda già aperta); notifica a nuova richiesta, nuovo messaggio nelle mie chat, passaggio dal bot. Preferenze: silenzia per un'ora, solo le mie chat. Se la notifica non parte resta l'email.
* **H2c — Studenti ed esterni.** Dopo il primo messaggio il widget propone «Avvisami quando risponde l'assistenza»; notifica senza testo (H-6). Anche per le pratiche: «la tua pratica è cambiata» (solo se l'utente ha acconsentito).

**Limiti da dire agli utenti:** funziona solo se il browser è aperto (anche ridotto a icona), non in «Non disturbare» di Windows; sull'iPhone solo se il portale è installato come app.

### H3 — Didacticus (bot a regole)

**Materiali da te (servono PRIMA di H3a):** le **30–50 domande che ricevete più spesso** (da email, ricevimento, Uniticket) con la risposta che dareste, divise per argomento; gli orari di ricevimento e i contatti degli uffici; i link giusti (pagine del portale, di Ateneo, moduli). Più un'idea per l'**immagine di Didacticus**: se non ne hai una, la disegno io in SVG (una mascotte con tocco da laureato, in colori Unical) e la cambi dal pannello.

* **H3a — Base di conoscenza e pannello.** Tabelle `didacticus_voci` (argomento, domanda, varianti/parole chiave, risposta con link e pulsanti, attiva, contatori «utile / non utile»). Pagina `admin/didacticus.php` per scrivere, cercare, attivare, importare/esportare (Excel) e **provare** («scrivi una domanda e vedi cosa risponde e con che punteggio»). **Voci di partenza** ricavate dalle guide e dagli iter già scritti (pratiche, tirocini/FSL, certificati, tutorato, tesi, eventi).
* **H3b — Il motore.** `src/Assistenza/Didacticus/`: normalizzazione dell'italiano (minuscole, accenti, apostrofi, parole vuote, radice delle parole), punteggio per somiglianza con le varianti (pesi più alti alle parole rare), soglia di sicurezza, due o tre risposte alternative quando il punteggio è vicino. Prove con una batteria di domande reali e formulazioni diverse.
* **H3c — Nella chat.** Didacticus apre ogni conversazione (saluto + pulsanti per argomento), risponde, chiede «Ti è stato utile?», e **passa all'operatore** se: l'utente lo chiede («voglio parlare con una persona»), non capisce due volte di seguito, o la risposta non è stata utile. Il passaggio manda la notifica all'operatore e gli mostra il riepilogo di cosa è già stato detto. Fuori orario lo dice e raccoglie la richiesta.
* **H3d — Impara dall'uso.** Cruscotto «Domande senza risposta» (quelle finite all'operatore) con il pulsante **«Crea una voce»** partendo dalla risposta data dall'operatore; voci più usate e meno utili da rivedere.

---

## 3. Ordine

`H1a → H1b → H1c → H1d → H1e` (dopo H1d il servizio di assistenza funziona già con soli operatori) → `H2a → H2b → H2c` → `H3a` (con i tuoi materiali) `→ H3b → H3c → H3d`.
Gli step sono indipendenti: dopo ognuno il portale funziona. H3 richiede H1; H2b/H2c richiedono H1b/H1c.

## 4. Elementi trasversali

* **Privacy:** informativa (privacy.php) con una riga sull'assistenza (chat conservata 12 mesi, finalità, notifiche push facoltative); da far vedere al DPO. Nessun dato inviato a servizi esterni.
* **Accessibilità:** il widget si usa da tastiera, annuncia i messaggi nuovi (`aria-live`), rispetta `prefers-reduced-motion`, contrasto AA.
* **Abusi:** limite di conversazioni e di messaggi per IP e per ora, CAPTCHA per gli esterni, testo massimo 1500 caratteri, link e HTML ripuliti.
* **Esportazioni:** Excel, PDF e CSV con `TabellaEsportabile` (X1).
* **Deploy:** a mano, come sempre. H2 richiede aggiungere le chiavi push al `.env` del server.

## 5. Registro di avanzamento

| Step | Cosa | Stato | Data | Note |
|---|---|---|---|---|
| H1a | Dati e servizi dell'assistenza | **fatto in locale (da caricare)** | 22/10/2026 | Schema v86: tabelle `assistenza_conversazioni` e `assistenza_messaggi`, ufficio fisso «Assistenza». Classi in `src/Assistenza/`, quattro email nel catalogo (`CatalogoAssistenza`). Prove: `strumenti/prove/oop/assistenza.php` |
| H1b | Casella degli operatori | **fatto in locale (da caricare)** | 22/10/2026 | `admin/assistenza.php` + `admin/assistenza_ajax.php` (`ChatApi`), voce nel menu di Didattica, scheda sulla scrivania, chat nella casella unica Messaggi e nel suo contatore. Le chat si aprono da Assistenza, non in una finestra |
| H1c | Chat e pulsante per l'utente | **fatto in locale (da caricare)** | 22/10/2026 | `assistenza.php` (a pagina intera e come finestrella `?pannello=1`), `assistenza_ajax.php`, `assets/js/assistenza-*.js`, pulsante «Serve aiuto?» in `footer.php`. Esterni: link personale (`?t=`) ricordato anche nel browser |
| H1d | Orari, servizio acceso/spento, conservazione, collegamento alle pratiche | **fatto in locale (da caricare)** | 22/10/2026 | Schema v87 (colonne `assistenza_attiva`, `assistenza_orari`). Fuori orario la chat funziona e lo dice; servizio spento = niente pulsante e niente nuove chat; conservazione 12 mesi nel cron (`CONSERVAZIONE_ASSISTENZA_MESI`); «Chiedi aiuto su questa pratica» in `pratiche.php`, riquadro in `area_personale.php`. Pacchetto 34 della mappa |
| H1e | Statistiche (Excel/PDF/CSV) e guide per ruolo | **fatto in locale (da caricare)** | 22/10/2026 | Scheda «Statistiche» di Assistenza (`StatisticheAssistenza`, tabelle scaricabili), guida «Assistenza e Didacticus» (`GuidePerRuolo`, PDF in `documentazione/guide/`), sezione per gli studenti, informativa privacy, mappe aggiornate. Pacchetto 36 |
| H3a | Base di conoscenza e pannello | **fatto in locale (da caricare)** | 22/10/2026 | Schema v89. `admin/didacticus.php`: voci con domande, parole chiave, risposta, **link e allegati** (`didacticus_file.php`, cartella chiusa al web), solo per chi accede, «proponi operatore»; esportazione Excel/PDF/CSV e importazione CSV; 23 voci di partenza (solo come si usa il portale, `VociDiPartenza`) |
| H3b | Motore di Didacticus | **fatto in locale (da caricare)** | 22/10/2026 | `Motore` (radici delle parole, peso per rarità, parole chiave, argomento) e `ServizioDidacticus` (risposta · suggerimenti · nessuna · riservata · saluto · grazie · operatore). Provato su 16 domande realistiche e su domande fuori argomento |
| H3c | Didacticus nella chat | **fatto in locale (da caricare)** | 22/10/2026 | Immagine del gufo (SVG), chat che parte con Didacticus (stato `bot`), pulsanti «Sì, grazie / Non mi basta / Parla con un operatore», suggerimenti e argomenti, passaggio all'operatore con email, finestrella e nota; scheda «Con Didacticus» per gli operatori; «scrivi subito a un operatore» |
| H3d | Impara dall'uso | **fatto in locale (da caricare)** | 22/10/2026 | «Domande senza risposta» (nessuna risposta o «non mi basta») con «Crea una voce» (bozza dalla risposta dell'operatore), prova, contatori usi/utile/non utile per voce, conservazione |
| H3e | Istruzione sui temi della didattica | **fatto in locale (da caricare)** | 23/10/2026 | 48 voci scritte da uniTicket (modulistica), dalle risposte dell'ufficio sui tirocini, dalle guide TSP 2.0 e dalle pagine di dibest.unical.it/unical.it: tirocini (iter, convenzioni, fine tirocinio, crediti), tesi e laurea, esami e piano di studi, certificati, iscrizione, tasse, OFA, trasferimenti, tutorato, mobilità. File `strumenti/didacticus/voci_didattica.txt` → `genera_csv.php` → `documentazione/didacticus_voci_didattica.csv` da importare dal pannello. Codice: `VociRepository::importaCsv()`, colonne «Propone operatore»/«Solo con accesso», argomenti `iscrizione` ed `esami`. La modulistica non è ancora nel portale: le voci rimandano a uniTicket. Da rivedere ogni anno: importi, finestre, scadenze. |

**Stati**: `da fare` · `in corso` · `fatto in locale (da caricare)` · `in produzione`.
