# Piano delle nuove funzioni — Didattica DiBEST

> Nato dagli appunti `documentazione/mappa\_nuove\_feature.txt` (non modificati). Questo file è la \*\*mappa operativa\*\*: si aggiorna a ogni step.
> Creato il 13/10/2026. Scritto per poter riprendere il lavoro da \*\*qualsiasi PC\*\* (cartella su Drive + repository git) senza altro contesto.

\---

## 0\. Come riprendere da un altro PC (5 minuti)

1. **Codice**: la cartella è su Google Drive (`…/Eventi DiBEST/sito\_claude`) ed è un repository git. Se lavori su un clone: `git pull` dal remote `tutti` (personale `dodaroe/didatticadibest` + organizzazione `UniversitaDellaCalabria/didatticadibest`; il push si fa su entrambi con `git push tutti main`).
2. **PHP 8.2** necessario in locale (qui: `C:\\xampp\\php\\php.exe`; cambia percorso sull'altro PC). Composer solo per sviluppo (`composer install`, poi `composer test`).
3. **Prove automatiche** (da lanciare prima e dopo ogni step): `composer test` = PHPUnit (`tests/Unit`, `tests/Integration`) + `php strumenti/prove/esegui.php` (prove del portale, oggi \~1200 ok). Le prove del database usano un MariaDB temporaneo (`strumenti/locale/`, `tests/Integration/DatabaseDiProva.php`).
4. **Leggi prima**: questo file (§1–§3), poi `MIGRATION\_PLAN.md` (architettura a classi in `src/`, `App\\…`) e `documentazione/mappa\_pannello.html` (come è fatto il pannello admin e le regole grafiche).
5. **Frase per far ripartire Claude**: *«Leggi documentazione/piano\_nuove\_feature.md, guarda il Registro di avanzamento (§8) e riprendi dal prossimo step (oggi il collaudo G). Titulus e firma Aruba sono in attesa dell'Ateneo: non toccarli. Prima di scrivere codice fai la «Verifica iniziale» dello step.»*

### Regole del progetto (non si discutono, vedi anche la memoria di Claude)

* **Nessun ALTER/CREATE al volo nelle pagine.** Tabelle e colonne si aggiungono solo in `src/Core/Schema/Definizioni.php`, poi si alza `Migrazioni::VERSIONE` in `src/Core/Schema/Migrazioni.php` (oggi **48** → il prossimo step usa **49**). Il marcatore `cache/schema\_vNN.ok` fa girare la migrazione una volta sola sul server.
* **Struttura a classi**: logica in `src/<Modulo>/Servizio\*.php` + `\*Repository.php` + `Costanti.php` + `Notifiche\*.php`; le pagine (`admin/\*.php`, `\*.php` in radice) restano sottili. Ogni classe nuova si registra nel `Registrazione.php` del suo modulo (container `App\\Core\\App::per($conn)->get(Classe::class)`). Le funzioni vecchie in `inc/\*.php` sono facciate: non aggiungere logica lì.
* **Prove**: per ogni classe nuova un test in `tests/Unit/<Modulo>` o `tests/Integration/<Modulo>`; per le pagine le prove in `strumenti/prove/oop/`.
* **PHP salvato senza BOM UTF-8**; email con `inviaNotificaEmail()` / `Notifiche\*`; flash con `flash\_set()`; CSRF sempre sui POST; file caricati in cartelle di `uploads/` bloccate al web e scaricabili solo da una pagina che controlla i permessi.
* **Firme**: `PAdES` = PDF firmato dentro il file. Verifica struttura con `App\\Infrastructure\\Pdf\\FirmePades`; firma remota Aruba già pronta in `App\\Tutorato\\FirmaRemota\*` (da riusare, non da riscrivere).
* **Deploy**: `git push` NON aggiorna il sito: i file si caricano a mano sul server. Se «qualcosa non funziona» dopo un rilascio, controllare prima che tutti i file dello step siano stati caricati (e che `cache/schema\_v49.ok` sia stato creato).
* **Commit**: senza attribuzioni a Claude (l'utente non vuole Claude tra i contributor).
* **Grafica admin**: come in `mappa\_pannello.html` (schede a percorso, pulsanti non `btn-sm` per le azioni importanti, parole semplici, numero di cose da fare nel menu e nella «Scrivania» → `src/Portale/CoseDaFare.php`).

\---

## 1\. Cosa hai chiesto (in sintesi) e cosa c'è già

Ho confrontato gli appunti con il codice. **Molto è già fatto**: i nuovi step servono a chiudere i buchi, non a ricominciare.

|Blocco|Richiesta|Stato oggi (verificato nel codice il 13/10/2026)|Resta da fare|
|-|-|-|-|
|**A · FSL convenzioni**|Convenzione + Allegato A precompilati scaricabili, firma PAdES, **upload della convenzione firmata da parte della scuola**, notifica all'amministratore, firma del direttore + protocollo + ricarico, documenti nell'area personale della scuola|Scarica precompilati: ✅ (`convenzione\_online.php`, `src/Fsl/ServizioConvenzioneOnline`, `AllegatoAPdf/Word`). Registro convenzioni con file firmati caricati **dall'admin**: ✅ (`admin/fsl.php`, `ServizioRegistroConvenzioni`, solo PDF PAdES). Area personale: link ai documenti da scaricare ✅ (`area\_personale.php` \~r.854-890)|Upload della scuola, stati e notifiche dell'iter, ricarico finale con protocollo, documenti definitivi nell'area personale (A1–A3)|
|**B · Risorse**|Responsabile (utente) per risorsa; campi personalizzati nella prenotazione; diario di bordo auto|Risorse con orari, chiusure, approvazione, email notifiche, `referente` **testo libero** (`risorse`, `prenotazioni\_risorse`, `src/Risorse/\*`, `admin/risorse.php`, `admin/prenotazioni\_risorse.php`)|Tutto il blocco: responsabile-utente (B1), campi personalizzati (B2), diario di bordo (B3), report (B4)|
|**C1 · Pratiche studenti**|Manager configura pratica, iter con attori, campi per i referenti che finiscono nel verbale, **wizard**|Moduli con campi a logica condizionale, iter a uffici (`iter\_json`, `@cdl`, `@segreteria`), campi del verbale (`verbale\_json`), uffici configurabili, notifiche, stati, storico, estratto per lo studente (`src/Didattica/\*`, `admin/didattica\_moduli.php`, `pratiche.php`)|Schermata **wizard** unica, attori/ruoli configurabili con più chiarezza, verifica di notifiche e vista iter dello studente (C1)|
|**C2 · Verbale**|Compilazione guidata, bel Word precompilato, testi punto per punto, pratiche in ordine di o.d.g., firma e ricarico|Word con intestazione, o.d.g., presenze P/AG/AI, pratiche nel punto "pratich…", firma PAdES a segretario+coordinatore con link e solleciti (`EsportazioneVerbale`, `VerbaleFirmato`, `firma\_verbale.php`, `admin/didattica\_sedute.php`)|**Testi per punto** (oggi «…»), pratiche assegnabili a **ogni** punto, modello Word fedele al tuo allegato (C2)|
|**C3 · Archivio verbali**|Elenco/consultazione in admin, caricamento dei verbali vecchi|Le sedute sono già elencate in `admin/didattica\_sedute.php`|Archivio con ricerca, importazione storico (C3)|
|**C4 · Pagina pubblica**|Utente esterno con password configurabile da admin|Niente|Pagina `verbali.php`, password, log, scelta di cosa è pubblico, privacy (C4)|
|**D · Tutorato**|Bando+vincitori → SPID/CIE → lettera → docente PAdES → direttore PAdES → protocollo → registro presenze → fine attività precompilata → ufficio pagamenti|**Quasi tutto fatto**: `src/Tutorato/\*`, `admin/tutorato.php`, `incarico.php`, `firma\_incarico.php`, `registro\_tutorato.php`. Stati bozza→inviata→confermata→firmata\_docente→firmata→protocollata; registro ore con approvazione docente; fine attività firmata e protocollata; metadati SPID nel PDF; promemoria e solleciti|Passo finale **«Ufficio pagamenti»** (D1), import massivo dei vincitori (D2), collaudo del flusso intero (D3)|
|**E · Titulus**|Connettore al protocollo Cineca|Il numero di protocollo si scrive **a mano** (tutorato, convenzioni)|Interfaccia `Protocollo` + implementazione Titulus (E1–E2). **Servono i parametri: §6**|

\---

## 2\. Decisioni da prendere con te (con la mia proposta)

|#|Domanda|Proposta predefinita|
|-|-|-|
|D1|**Convenzione FSL**: chi firma per l'Ateneo, e il direttore firma sul portale (Aruba) o fuori portale?|Fuori portale come ora: l'admin scarica, fa firmare, ricarica. Firma remota Aruba opzionale più avanti|
|D2|**Convenzione**: quando la scuola la carica, le prenotazioni in attesa di convenzione si confermano subito o dopo il ricarico finale?|Dopo il ricarico finale protocollato (la convenzione è valida solo dopo la firma del direttore)|
|D3|**Responsabile risorsa**: uno o più per risorsa? Vede solo le sue prenotazioni? Può approvare?|Più responsabili; vedono e approvano solo le loro risorse (non diventano gestori di area)|
|D4|**Campi personalizzati prenotazione**: per risorsa o per area? Stessi tipi del form builder?|Per risorsa, con copia da un'altra risorsa; stessi tipi di `CampiModulo`|
|D5|**Diario di bordo**: lo compila chi guida a fine prenotazione o il responsabile? Quali campi?|Chi guida, a fine uso; il responsabile controlla e può correggere. Campi in §4-B3 (da confermare con il tuo foglio cartaceo)|
|D6|**Verbale**: i punti dell'o.d.g. sono sempre tipizzati («Comunicazioni», «Pratiche studenti», testo libero)?|Sì, 3 tipi per punto; i punti nascono dall'o.d.g. del consiglio|
|D7|**Verbali pubblici**: contengono dati personali degli studenti (pratiche)?|**No** sulla pagina pubblica: versione con studenti in forma anonima (matricola oscurata) oppure solo i punti non-pratiche. **Da concordare con il DPO** (avv. Niger, rpd@unical.it) prima del rilascio|
|D8|**Password verbali pubblici**: una password unica per tutti o una per persona/ente con scadenza?|Più password con etichetta, scadenza e revoca (così si sa chi ha accesso)|
|D9|**Ufficio pagamenti**: è una persona/ufficio dell'Ateneo senza account sul portale?|Riceve email con link personale (come docente/direttore) e conferma «pagato»; nessun account|
|D10|**Titulus**: protocollo in sola uscita dal portale (si invia il PDF firmato e si riceve il numero) o anche ricerca/aggiornamento?|Solo invio + ricezione di numero, data e segnatura|

> Le risposte (13/10/2026) sono qui sotto. Conseguenze sul piano, decise insieme:
> * **D1** → serve anche la **firma remota Aruba** per la convenzione (docente titolare + direttore): nuovo step **A4**, riusando `App\Tutorato\FirmaRemota*`.
> * **D4** → i campi personalizzati sono una **libreria per area**, collegabile a **più risorse** (tabella ponte), alcuni comuni a tutte: cambia lo schema di B2.
> * **D5** → il diario di bordo ha **tre momenti** (inizio, durante, fine missione) e un esempio allegato dall'utente: cambia lo schema di B3.
> * **D7** → i verbali dietro password **restano con i dati personali** (nessun oscuramento); resta da aggiornare l'informativa e da loggare gli accessi.
> * **D8** → l'admin genera **più password**, uniche (monouso) o illimitate o a scadenza.
> * **D9** → l'ufficio pagamenti è una persona **con credenziali Unical** (accede via SSO): servono gli **«uffici di Ateneo»** (non di dipartimento), un concetto nuovo; D1 non usa un link con token ma un account.
> * **Metodo**: si procede **uno step alla volta**; prima di ogni step Claude elenca i modelli/documenti necessari, l'utente li carica in `documentazione/materiali/`, poi si lavora.

\---

## 3\. Ordine consigliato

**D1 - Convenzione FSL**: chi firma per l'Ateneo, e il direttore firma sul portale (Aruba) o fuori portale? Firma il docente titolare del corso e il direttore del dipartimento. Al momento firma fuori verbale (da implementare firma remota con aruba)

**D2 - Convenzione**: quando la scuola la carica, le prenotazioni in attesa di convenzione si confermano subito o dopo il ricarico finale? Dopo il ricarico finale protocollato dal amministratore/referente

**D3 - Responsabile risorsa**: uno o più per risorsa? Vede solo le sue prenotazioni? Può approvare? Possono essere uno o più per risorsa e uno stesso responsabile può avere più risorse. vedono e approvano solo le loro risorse

**D4 - Campi personalizzati prenotazione**: per risorsa o per area? Stessi tipi del form builder? Per risorsa e, se possibile, per area come negli aventi. Alcuni campi potrebbero essere comuni a tutti anche no. Ad uno stesso campo può essere associato più di una risorsa

**D5 - Diario di bordo**: lo compila chi guida a fine prenotazione o il responsabile? Quali campi? Lo compila chi guida all'inizio, durante la missione e alla fine tenendo traccia di tutto quello che fa. Ti allego un esempio

**D6 - Verbale**: i punti dell'o.d.g. sono sempre tipizzati («Comunicazioni», «Pratiche studenti», testo libero)? Alcuni sono tipizzati e da scegliere nel menu a tendina.i punti nascono dall'Od:.G.

**D7 - Verbali pubblici**: contengono dati personali degli studenti (pratiche)? Ad oggi li pubblichiamo anche con i dati personali perché sono protetti da password e solo che ha la password posono consultarli. non sono proprio pubblici

**D8 - Password verbali pubblici**: una password unica per tutti o una per persona/ente con scadenza? Darei la possibilità all'admin di generare password uniche, o più password illimitate o a scadenza

D9 - **Ufficio pagamenti**: è una persona/ufficio dell'Ateneo senza account sul portale? è una persona presente nell'anagrafe di ateneo e che ha credenziali unical. Dovrei, però, creare degli uffici di ateneo perché sono uffici non di dipartimento

**D10 - Titulus**: protocollo in sola uscita dal portale (si invia il PDF firmato e si riceve il numero) o anche ricerca/aggiornamento? Solo invio + ricezione di numero, data e segnatura



&#x20;Fase 0  Preparazione e documenti da te (§7)          ← subito, in parallelo
 Fase 1  E0  Richiesta parametri Titulus a Cineca/Unical (tempi lunghi: parti ora)
 Fase 2  A1→A2→A3   Convenzione FSL completa               (poco lavoro, iter già a metà)
 Fase 3  D1→D2→D3   Tutorato: pagamenti + import + collaudo (chiude un iter intero)
 Fase 4  C2→C3→C4   Verbali: testi per punto, archivio, pagina pubblica
 Fase 5  C1         Wizard delle pratiche studenti
 Fase 6  B1→B2→B3→B4 Prenotazioni e risorse: responsabile, campi, diario di bordo
 Fase 7  E1→E2      Titulus e firma digitale Aruba: IN ATTESA della risposta dell'Ateneo (parametri §6)
 Fase 8  G          Collaudo end-to-end di pratiche, verbali e risorse (come D3 per il tutorato)
 Fase 9  F1→F2→F3→F4 Nuove idee approvate: cruscotto del Direttore, prenotazioni ricorrenti e liste d'attesa, backup, guide per ruolo
 Fase 10 F10        ★ PRIORITÀ 1 (16/10/2026): convenzioni di tesi con enti e aziende esterne (iter come le convenzioni FSL, con SPID/CIE) + archivio
```

* **Perché E1 (interfaccia «Protocollo») conviene PRIMA di A e D**: tutorato e convenzioni oggi chiedono il numero a mano; se si crea subito un'interfaccia con l'implementazione «manuale», quando arriva Titulus basta cambiare un'impostazione. Se preferisci non toccare nulla finché non ci sono i parametri, A e D restano a mano e E1 si fa dopo.
* Gli step sono **indipendenti**: dopo ognuno il portale funziona. L'ordine si può cambiare tranne: A1→A2→A3, D1→D2, C2→C3→C4, B1→B2→B3.
* **Stima** (ore di lavoro mie, comprese prove): A ≈ 1 sessione · D ≈ 1 · C2–C4 ≈ 2–3 · C1 ≈ 2 · B ≈ 2–3 · E ≈ 1 + attesa parametri.

\---

## 4\. Gli step in dettaglio

Per ogni step: **Verifica iniziale** (cosa controllo nel codice prima di scrivere), **Da fare** (schema → classi → pagine → notifiche), **File**, **Prove**, **Documenti da te**, **Fatto quando**.

### BLOCCO A — Formazione Scuola Lavoro: iter delle convenzioni

Flusso finale desiderato:

```
scuola: Aggiunge al programma → scarica Convenzione + Allegato A precompilati
  → firma (PAdES, dirigente scolastico) → CARICA la convenzione firmata (nuovo)
  → notifica all'amministratore (nuovo)
  → admin la scarica → direttore firma → ufficio protocolla → admin la RICARICA con protocollo (esiste già l'upload admin)
  → notifica alla scuola; Convenzione e Allegato A definitivi nell'Area personale della scuola
```

#### A1 — Upload della convenzione firmata da parte della scuola

* **Verifica iniziale**: leggere `convenzione\_online.php` (pagina col link personale `?t=TOKEN`), `src/Fsl/ServizioConvenzioneOnline.php` (`dellePrenotazioni`, `salvaProtocollo`), `ConvenzioneCompilataRepository.php`, `NotificheConvenzioni.php`, `ServizioRegistroConvenzioni.php` (come valida il PDF PAdES e dove salva: `uploads/convenzioni/`), `area\_personale.php` r.854-890 (tab «Programma FSL»).
* **Schema (v49, `Definizioni.php`)**: su `convenzioni\_compilate` aggiungere `file\_firmato\_scuola VARCHAR(255)`, `firmata\_scuola\_il DATETIME`, `stato\_iter VARCHAR(20)` (`bozza` → `caricata\_scuola` → `in\_firma\_direttore` → `protocollata`; `rifiutata` per riga con motivo), `nota\_ufficio VARCHAR(500)`. Cartella protetta `uploads/convenzioni\_compilate/` (già esiste, verificare `.htaccess`).
* **Classi**: in `ServizioConvenzioneOnline` (o nuovo `ServizioIterConvenzione`): `caricaFirmata(token, $\_FILES)` con controlli (solo PDF, dimensione massima, **firma PAdES presente** con `FirmePades` come fa già il registro; messaggio chiaro se è un `.p7m`), stato → `caricata\_scuola`, evento nello storico.
* **Pagine**: campo di caricamento in `convenzione\_online.php` e nel tab Programma FSL di `area\_personale.php` (pulsante grande «Carica la convenzione firmata»; stato dell'iter come percorso a tappe; la scuola può **sostituire** il file finché non è presa in carico).
* **Notifiche**: `NotificheConvenzioni::convenzioneCaricata()` → email agli **amministratori FSL** (utenti con `$puo\_fsl\_convenzioni`) con link a `admin/fsl.php` sulla scuola; voce in `CoseDaFare` («N convenzioni da prendere in carico»); ricevuta alla scuola.
* **Prove**: unit per validazione file (PAdES sì/no, p7m, troppo grande), integration per cambio stato e notifica; aggiornare `strumenti/prove/oop/fsl\_documenti.php`.
* **Fatto quando**: una scuola carica un PDF PAdES e l'admin riceve email + vede «Da prendere in carico» in Scrivania e nella scheda scuola.

#### A2 — Presa in carico e ricarico dell'admin (firma direttore + protocollo)

* **Verifica iniziale**: `admin/fsl.php` (schede Convenzioni, form `cvFileC` r.270-290), `ServizioRegistroConvenzioni::salva`, `ConvenzioneRepository` (colonne `file\_convenzione`, `file\_allegato`), come le prenotazioni `da\_approvare` con convenzione «no» si confermano quando la convenzione viene registrata (cercare in `src/Fsl/ConvenzioniIscritti.php` e `RegoleIscrizioniFsl.php`).
* **Da fare**: nella scheda scuola (`admin/fsl.php`, scheda scuola del Pannello step 3) pulsante «Scarica la convenzione della scuola», «Ho fatto firmare e protocollare → carica la versione definitiva» (usa l'upload esistente, pre-compilando protocollo e data) → crea/aggiorna la riga in `convenzioni\_scuole` (data stipula, scadenza = + `Costanti::DURATA\_ANNI`, protocollo, file) e imposta `stato\_iter = 'protocollata'`; conferma delle prenotazioni in attesa (decisione D2); possibilità di **rifiutare** con motivo (la scuola riceve email e può ricaricare).
* **Notifiche**: alla scuola «Convenzione attiva fino al …» con link all'Area personale; promemoria scadenza già esistente (`avviso\_scadenza\_inviato`).
* **Prove**: integration sul passaggio di stato + conferma prenotazioni.
* **Fatto quando**: dalla scheda scuola si chiude tutto l'iter con 3 clic e la scuola vede la convenzione attiva.

#### A3 — Documenti definitivi nell'Area personale della scuola

* **Da fare**: nel tab «Programma FSL» di `area\_personale.php` mostrare per ogni scuola/prenotazione: Convenzione (precompilata / caricata / **protocollata, scaricabile**), Allegato A (PDF precompilato; se previsto anche quello firmato), scadenza, stato come percorso a tappe. Download protetto con controllo che l'utente appartenga alla scuola (nuova rotta tipo `documento\_scuola.php` o `ServizioConvenzioneOnline::fileDellaScuola`).
* **Prove**: l'utente di un'altra scuola non può scaricare.
* **Fatto quando**: la scuola vede e scarica convenzione e allegato A definitivi senza chiamare nessuno.

**Documenti da te per il blocco A**: nome/ruolo dell'amministratore che riceve la notifica (o gruppo), conferma D1/D2, eventuale modello aggiornato della convenzione (`modelli\_documenti/convenzione\_precompilabile.docx`, `allegato\_a\_precompilabile.docx` sono già presenti).

\---

### BLOCCO D — Tutorato (completamento)

Flusso desiderato (dai tuoi appunti): `referente inserisce DD bando e DD commissione > studente (SPID/CIE) accetta e compila lettera > firma docente > firma direttore > protocollo > registro > firma docente fine attività > pratica all'ufficio Ateneo per i pagamenti`.
Tutto è già implementato **tranne l'ultimo passaggio**. Stati attuali: `Costanti::STATI\_INCARICO` e `STATI\_FINE\_ATTIVITA` in `src/Tutorato/Costanti.php`.

#### D1 — Passo «Ufficio pagamenti»

* **Verifica iniziale**: `ServizioRegistroTutorato::protocollaFine()` (fine attività protocollata = ultimo stato attuale), `NotificheIncarichi.php`, `OperatoriUfficio.php`, `admin/tutorato.php`, `inc/tutorato.php`.
* **Schema (v49)**: su `tutorato\_incarichi` aggiungere `pagamento\_stato VARCHAR(12)` (`''`/`inviata`/`pagata`), `pagamento\_inviato\_il`, `pagamento\_data`, `pagamento\_riferimento VARCHAR(100)`, `token\_pagamenti VARCHAR(40)`; impostazione dell'indirizzo/i dell'ufficio pagamenti (in `impostazioni\_sistema` o per bando: colonna `email\_pagamenti` su `tutorato\_bandi`).
* **Classi**: `ServizioRegistroTutorato` (o nuovo `ServizioPagamentiTutorato`): `inviaAiPagamenti($id)` automatico alla protocollazione della fine attività (o pulsante manuale: decidere con te), email all'ufficio con **pacchetto** (lettera protocollata con numero, dichiarazione di fine attività, riepilogo registro ore PDF, ore e compenso) e link personale `pagamenti\_tutorato.php?t=…` per segnare «pagata» con data e riferimento; notifica allo studente «pagamento disposto».
* **Pagine**: scheda in `admin/tutorato.php` con la tappa «Pagamento» nel percorso; pagina pubblica col token per l'ufficio.
* **Prove**: unit stati, integration invio + conferma + idempotenza.
* **Fatto quando**: protocollata la fine attività l'ufficio riceve il pacchetto, segna «pagata» e il percorso dell'incarico mostra «Concluso».
* **Documenti da te**: nome/email dell'ufficio pagamenti, **elenco dei documenti che pretende** (es. modello di rendicontazione), se vuole PDF unico o allegati separati. D9.

#### D2 — Importazione dei vincitori del bando

* **Verifica iniziale**: `ServizioIncarichi::salvaBando/salva` (oggi un vincitore alla volta, `admin/tutorato.php`), `cerca\_insegnamenti.php`, `InsegnamentoRepository`.
* **Da fare**: carica Excel/CSV con vincitori (cognome, nome, CF, email, insegnamento, ore, compenso, docente responsabile); anteprima con errori riga per riga; creazione incarichi in bozza; riuso del modello Excel (`App\\Infrastructure\\Documenti\\Excel`) e del lettore `testo\_da\_file\_elenco()` (vedi Attestati/ElencoStudenti).
* **Prove**: unit parser (colonne mancanti, CF errato, duplicati).
* **Documenti da te**: **un esempio reale di graduatoria** (anche anonimizzata) per fissare le colonne.

#### D3 — Collaudo del flusso intero e rifiniture

Lista di controllo da eseguire con dati finti in locale (e poi sul server):

1. Bando con DD bando/DD commissione → vincitore → invio → email studente.
2. Accesso SPID/CIE (con `INCARICHI\_SOLO\_SPID\_CIE=1`): metadati SPID nel PDF; con credenziali di Ateneo bloccato.
3. Docente: firma Aruba o caricamento PAdES → direttore → «da protocollare» all'operatore.
4. Registro presenze: invito allo studente quando la lettera è protocollata (verificare che l'email «compila il registro» parta; se manca, aggiungerla in `ServizioRegistroTutorato`/`promemoria`), approvazione ore del docente.
5. Fine attività: **la lettera è precompilata con data fine = ultima data del registro** (verificare in `salvaDatiFine`/`DatiLettera`; correggere se prende un'altra data), firma docente, protocollo.
6. Passaggio all'ufficio pagamenti (D1).
Eventuali scostamenti si correggono qui. Aggiornare `tests/Unit/Tutorato/TutoratoUnitTest.php`.

\---

### BLOCCO C — Didattica

#### C2 — Verbale compilato punto per punto  *(prima di C1: è la parte più visibile)*

* **Verifica iniziale**: `src/Didattica/EsportazioneVerbale.php` (`generaVerbale`: o.d.g. = testo diviso per righe; le pratiche vanno nel primo punto che contiene «pratich», gli altri punti hanno «…»), `ServizioSedute`, `SedutaRepository`, `DecisioniSeduta`, `admin/didattica\_sedute.php` (seduta guidata in sei passi del Pannello step 5), `VerbaleFirmato`, `Word.php`, e **il tuo modello Word allegato** (non è nel repo: vedi documenti da te).
* **Schema (v49)**: nuova tabella `didattica\_sedute\_punti` (`id`, `seduta\_id`, `ordine`, `titolo`, `tipo` = `testo`|`pratiche`|`comunicazioni`, `testo MEDIUMTEXT` HTML pulito, `visibile\_pubblico TINYINT`), colonna `pratiche.punto\_id INT NULL` (a quale punto appartiene la pratica; oggi c'è solo `seduta\_id`). Migrazione dei dati: i punti si ricavano dall'`odg` delle sedute esistenti; le pratiche già in seduta vanno al punto «pratich…».
* **Classi**: `PuntoSedutaRepository`, `ServizioPuntiSeduta` (genera i punti dall'o.d.g., riordina, assegna pratiche, salva testo); `EsportazioneVerbale::generaVerbale` legge i punti: ogni punto → titolo + testo scritto; punto di tipo `pratiche` → solo le pratiche assegnate **nell'ordine dell'o.d.g.** (ordine interno: protocollo/cognome); incipit «Il giorno … alle ore … si è riunito…», elenco presenze con P/AG/AI, chiusura e firme come oggi.
* **Pagine**: nella seduta guidata un passo «Cosa è stato detto» con un riquadro per punto (editor TinyMCE già presente, versione ridotta come `pulisci\_descrizione\_breve`), pulsante «Assegna pratiche al punto», anteprima, download Word (esiste), «Invia alla firma» (esiste).
* **Prove**: unit generazione XML del verbale per tutti i tipi di punto; integration ordine pratiche; confronto visivo del Word con il tuo modello (a mano, aprendolo).
* **Fatto quando**: da una seduta con 4 punti esce un Word identico al modello, firmabile e ricaricabile (flusso firma esistente).
* **Documenti da te**: **il modello Word del verbale** (quello «allegato» agli appunti) e **2-3 verbali veri recenti** per consigli diversi (CdD, CdS, commissioni) per vedere le varianti.

#### C3 — Archivio dei verbali in admin (con importazione dei vecchi)

* **Verifica iniziale**: `ConsiglioRepository`, elenco in `admin/didattica\_sedute.php`, `VerbaleFirmato::percorso` (dove stanno i PDF firmati: `uploads/verbali/`).
* **Schema (v49)**: su `didattica\_sedute` colonne `origine VARCHAR(10)` (`portale`/`importato`), `verbale\_file\_importato VARCHAR(255)`, `parole\_chiave TEXT`; per le importate non servono presenze né pratiche.
* **Pagine**: nuova scheda «Archivio verbali» (in Didattica › Sedute e verbali) con filtri organo / anno accademico / testo, anteprima PDF, download, stato firme; modulo **«Aggiungi un verbale vecchio»** (organo, data, a.a., o.d.g. sintetico, file PDF/Word, anche caricamento multiplo); (opzionale) ricerca nel testo dei verbali del portale.
* **Prove**: integration inserimento, permessi (solo chi gestisce quel consiglio).
* **Fatto quando**: tutti i verbali, vecchi e nuovi, si trovano da un'unica pagina.

#### C4 — Pagina pubblica dei verbali con password

* **Decisioni**: D7 (privacy) e D8 (password). **Va chiarito con il DPO prima di pubblicare.**
* **Schema (v49)**: tabella `verbali\_accessi` (`id`, `etichetta`, `password\_hash`, `scade\_il`, `attivo`, `ambiti\_json` = quali organi vede, `ultimo\_accesso\_il`, `creato\_da`); tabella `verbali\_accessi\_log` (data, IP, accesso\_id, azione). Colonna `didattica\_sedute.pubblicabile TINYINT` (o per organo).
* **Classi**: `ServizioVerbaliPubblici` (login con `password\_verify`, sessione dedicata separata dal login SSO, `LimiteRichieste` già esistente contro tentativi ripetuti, scadenza); download **solo** dei verbali firmati e marcati pubblicabili; versione per esterni con dati degli studenti oscurati.
* **Pagine**: `verbali.php` (pubblica: password → elenco per organo/anno → download) e in admin «Accesso ai verbali» (crea/revoca password, vedi chi è entrato).
* **Sicurezza**: HTTPS già in uso; cookie di sessione `HttpOnly`/`SameSite`; messaggi di errore generici; blocco dopo N tentativi; nessun elenco indicizzabile (`X-Robots-Tag: noindex`).
* **Prove**: unit password/scadenza/blocco; integration che un verbale non pubblicabile non si scarica anche con URL indovinato.
* **Documenti da te**: chi sono gli esterni, quali organi, regole sui dati personali (parere DPO).

#### C1 — Wizard di configurazione delle pratiche

* **Verifica iniziale (audit di 1 sessione, produce un elenco puntuale di cosa manca)**: leggere `admin/didattica\_moduli.php`, `ServizioModuli`, `ModuloRepository`, `CampiModulo`, `HtmlCampi`, `ServizioIter`, `ServizioUffici`, `FlussoPratica`, `NotifichePratiche`, `PromemoriaPratiche`, `EstrattoVerbale`, `pratiche.php` (vista dello studente), `inc/convalide.php`. Verificare che: (a) lo studente veda l'iter e riceva email a ogni passaggio, (b) all'operatore arrivi notifica a ogni assegnazione/integrazione, (c) i campi «per il verbale» compaiano nell'estratto e nel verbale (come per le convalide), (d) l'iter possa contenere **attori diversi** (ufficio, referente di corso, direttore, docente indicato nel modulo).
* **Da fare (probabile)**: wizard a passi in `admin/didattica\_moduli.php` — 1 «Che pratica è» (titolo, chi la può presentare, periodo) · 2 «Cosa compila lo studente» (campi a blocchi con regole) · 3 «Chi la lavora e in che ordine» (iter con attori, tempi massimi) · 4 «Cosa compilano gli uffici» (campi di lavorazione e decisione) · 5 «Cosa finisce nel verbale e nell'estratto» (modello di testo con segnaposti) · 6 «Email e promemoria» · 7 «Anteprima e pubblica» (simulazione con una pratica di prova); salvataggio come bozza; copia di una pratica esistente.
* **Schema**: probabile nessuna tabella nuova (usa `didattica\_moduli.campi\_json/iter\_json/verbale\_json`); eventuali colonne per tempi massimi/modello estratto.
* **Fatto quando**: un manager crea da zero una nuova pratica con iter, campi e testo del verbale **senza toccare il codice**, e la pratica di prova arriva fino nel verbale.
* **Documenti da te**: **elenco di tutte le pratiche studenti** (tabella: nome, chi la presenta, campi, chi la lavora in che ordine, cosa va nel verbale). Basta anche un Excel o un elenco puntato.

\---

### BLOCCO B — Prenotazioni e risorse

Oggi: `risorse` (aula/laboratorio/sportello/altro), `prenotazioni\_risorse` (slot, approvazione, ripetizioni, promemoria), email `email\_notifiche`, `referente` testuale; codice in `src/Risorse/\*`, `admin/risorse.php`, `admin/prenotazioni\_risorse.php`, `calendario\_area.php`, `risorsa\_ics.php`.

#### B1 — Utente responsabile della risorsa

* **Verifica iniziale**: `ServizioGestioneRisorse`, `NotificheRisorse`, `admin/prenotazioni\_risorse.php` (come filtra le prenotazioni), `Auth/Abilitazioni/\*` (perimetri e RBAC: un responsabile non è un gestore d'area), `ricerca-personale.js` (scelta persona dall'anagrafe).
* **Schema (v49)**: tabella `risorse\_responsabili` (`risorsa\_id`, `utente\_id`, `persona\_id`, `email`, `riceve\_notifiche`, `puo\_approvare`); `risorse.referente` resta come testo visibile al pubblico (si precompila col responsabile).
* **Classi**: `ResponsabileRisorsaRepository`, estensione `NotificheRisorse` (richieste, approvazioni, annullamenti → responsabili) e `PermessiGestoreAree`/`Perimetri` (il responsabile vede solo le sue risorse nel pannello); abilitazione come in `utenti.php`.
* **Pagine**: sezione «Chi se ne occupa» nella scheda risorsa; voce «Le mie risorse» nella Scrivania con le richieste da approvare.
* **Fatto quando**: un docente indicato come responsabile di un laboratorio riceve le richieste, le approva e vede solo quelle.

#### B2 — Campi personalizzati nella prenotazione

* **Verifica iniziale**: `CampiModulo`/`HtmlCampi` (form builder delle pratiche) e `admin/form\_builder.php` (form builder eventi): scegliere quale riusare (consigliato: `CampiModulo` + `HtmlCampi`).
* **Schema (v49)**: `risorse.campi\_json MEDIUMTEXT`, `prenotazioni\_risorse.risposte\_json MEDIUMTEXT`.
* **Da fare**: editor dei campi nella scheda risorsa (tipo, obbligatorio, opzioni, regole), campo mostrato nel modulo di prenotazione (`calendario\_area.php`/`master\_template.php`), validazione lato server, risposte visibili al responsabile e nell'email, export CSV con le colonne personalizzate; «copia campi da un'altra risorsa».
* **Prove**: validazione e salvataggio, campi obbligatori, XSS nelle risposte.

#### B3 — Diario di bordo (auto e mezzi)

* **Da fare**: nuovo tipo di risorsa `veicolo` in `Costanti::TIPI` + opzione `diario\_attivo` su ogni risorsa. **Schema (v49)**: `risorse\_diario` (`id`, `risorsa\_id`, `prenotazione\_id`, `utente\_id`, `data`, `destinazione`, `km\_partenza`, `km\_arrivo`, `km\_percorsi` calcolati, `carburante\_litri`, `carburante\_importo`, `luogo\_rifornimento`, `note`, `compilato\_il`, `verificato\_da`). Regole: `km\_partenza` precompilato dal `km\_arrivo` dell'ultima riga (non si può ripartire con meno km), compilazione richiesta alla fine della prenotazione con promemoria email, **blocca nuove prenotazioni** se c'è un diario da compilare (opzionale), il responsabile controlla/corregge.
* **Pagine**: «Compila il diario» dall'Area personale e dalla prenotazione; scheda «Diario di bordo» della risorsa con totale km, consumi, costo; export Excel mensile/annuale.
* **Documenti da te**: **il foglio del diario di bordo cartaceo** o l'elenco dei dati da registrare; regole su rifornimenti e carte carburante.

#### B4 — Report e rifiniture

* Cruscotto del responsabile (prenotazioni, utilizzo %, km/consumi), ricordi di scadenza (bollo/assicurazione/tagliando come campi opzionali della risorsa — solo se ti servono), export. Si decide dopo B3.

\---

### BLOCCO G — Collaudo (prossimo step)

* Provare con l'ambiente locale (`strumenti/locale/avvia.sh`) i flussi interi, come per il tutorato (D3), senza scrivere funzioni nuove: **pratiche** (modulo costruito dall'admin → studente → iter con firma del relatore e del Direttore → seduta → verbale → esiti), **verbali** (testi per punto, archivio con import di PDF, pagina pubblica con password) e **risorse** (responsabile, approvazione, campi, contatori, diario di bordo, cruscotto, scadenze).
* Correggere quello che emerge e scrivere nel Registro cosa è stato provato. Controllare anche la resa su telefono delle pagine pubbliche e del diario di bordo.

### BLOCCO F — Nuove idee approvate (dopo il collaudo)

#### F1 — Cruscotto unico per il Direttore

* Una pagina che raccoglie le cose in sospeso: pratiche ferme oltre il tempo del passo, verbali da firmare, convenzioni FSL in scadenza o da firmare, scadenze delle risorse (bollo, assicurazione…), pagamenti del tutorato fermi. Riusa `src/Portale/CoseDaFare.php` e i servizi già esistenti: niente tabelle nuove, solo una vista che aggrega.

#### F2 — Prenotazioni ricorrenti e liste d'attesa per le risorse

* Ricorrenza oltre la «ripetizione settimanale» già presente (es. ogni martedì per un periodo, o due volte al mese) e **lista d'attesa**: se lo slot è occupato ci si mette in coda e, se la prenotazione viene annullata o rifiutata, il primo in coda riceve l'avviso. Prima si guarda dal cruscotto B4 quali risorse sono davvero molto richieste.

#### F3 — Backup e ripristino dei file caricati

* Copia periodica (cron) di `uploads/` (verbali, incarichi, convenzioni, allegati delle pratiche) e del database, con controllo dello spazio e avviso all'admin; istruzioni di ripristino. Da concordare dove si salva la copia (server dell'Ateneo o altro).

#### F4 — Guide per ruolo («Come si fa»)

* Una pagina breve per ogni ruolo, scritta in modo semplice e con le schermate: **referente di consiglio** (componenti, seduta guidata, verbale), **relatore** (firma di una pratica dal link personale), **responsabile di risorsa** (approvare, cruscotto, scadenze, diario di bordo), **tutor** (registro delle attività, fine attività), **studente** (come fare una pratica e seguirla). Raggiungibili dal «?» delle pagine e dall'Area personale; in PDF per chi le vuole stampare. Si scrive dopo il collaudo, quando i flussi sono stabili.

#### F10 — Convenzioni di tesi con enti e aziende esterne ★ PRIORITÀ 1 *(richiesta del 16/10/2026, da fare per prima)*
* **Che cosa**: l'iter per stipulare le convenzioni per lo svolgimento di **tesi presso enti e aziende esterne**. È simile a quello delle convenzioni FSL (`ServizioConvenzioneOnline`, `ServizioConvenzioneFirmata`, `ServizioFirmaDirettore`, pagina «Convenzioni da seguire» di `admin/fsl.php`), con la differenza che qui **l'azienda accede con SPID/CIE**.
* **L'iter** (come l'ha descritto l'utente):
  1. **L'ufficio manda un link all'azienda** (email, dal pannello).
  2. L'azienda apre il link, **accede con le credenziali SPID/CIE** e **compila i campi necessari** (dati dell'ente/azienda e del legale rappresentante, referente, ecc.: da definire con il format).
  3. L'azienda **scarica la convenzione precompilata in Word e/o PDF/A**, la **firma in PAdES** e la **ricarica in piattaforma**.
  4. L'operatore **prende in carico**, **protocolla** e **registra il numero di protocollo** (in entrata); la convenzione **passa al Direttore per la firma**.
  5. **Il Direttore firma** (stesso meccanismo delle convenzioni FSL: link personale, firma PAdES con Aruba o PDF caricato; con «Direttore assente» firma il vice).
  6. L'ufficio **scarica il firmato, lo protocolla** (protocollo definitivo) e **carica il file protocollato**, dove si legge il numero di protocollo.
  7. A quel punto la **convenzione è stipulata**: registrata con validità, avvisi alla persona e all'azienda.
* **Archivio delle convenzioni**: elenco di tutte le convenzioni di tesi (azienda, validità, protocollo, file, stato), con possibilità di **caricare anche quelle già in essere** (senza passare dall'iter: azienda, date, numero di protocollo, PDF), scadenze e avvisi come per le convenzioni FSL.
* **Da riusare** (per fare in fretta): link personale con token (come `convenzione_online.php`), precompilazione Word/PDF dal format, validazione PAdES e controllo che il PDF non sia modificato, protocollo in entrata e definitivo, firma del Direttore e «Direttore assente» (`src/Uffici/Direzione.php`), registro con scadenze (60 giorni), **promemoria e messaggi alla scuola → qui all'azienda** (`ServizioMessaggiConvenzione`), uffici dall'anagrafe unica (un «Ufficio convenzioni di tesi» in `uffici`).
* **Da decidere/verificare domani** (prima di scrivere codice):
  * **Accesso SPID/CIE dell'azienda**: il portale ha già l'accesso SAML (Unical, SPID, CIE) e il livello 2 per il tutorato; va verificato se **un utente esterno non in anagrafe** entra senza account (come i tutor) e quale **codice fiscale/identità** si registra (il legale rappresentante?).
  * **Campi del modulo azienda**: ragione sociale, forma giuridica, codice fiscale e P.IVA, sede legale, PEC, legale rappresentante (nome, CF, luogo e data di nascita), referente aziendale, durata, eventuali settori; si completano dal format.
  * **Firma dell'azienda**: chi firma (legale rappresentante) e controllo del certificato con il suo codice fiscale come per il Direttore; PAdES come per le scuole, niente .p7m.
  * **Validità**: durata fissa (per esempio un anno o più) e rinnovo; se la convenzione vale per più tesi.
  * **Collegamento con la pratica di tesi** (modulo «Tesi»): in un secondo momento, scegliere l'azienda convenzionata nella richiesta di tesi e avvisare se non c'è una convenzione valida (non incluso nel primo passo).
  * **Ufficio e persone**: quale ufficio (da anagrafe) prende in carico, protocolla e carica il file protocollato.
* **Materiali che servono**: il **format della convenzione** (Word) che l'utente carica nella cartella `materiali/` alla radice del progetto (per esempio `materiali/convenzione_tesi_modello.docx`; è già in `.gitignore`) e, se esiste, un esempio compilato. Con il format si decidono i campi e i segnaposto da precompilare.
* **Stima**: ≈ 2 sessioni (iter e firma riusano le convenzioni FSL; nuovo l'accesso SPID/CIE per l'azienda, l'archivio e il caricamento di quelle esistenti).

#### F5 — Assistente IA interno per i verbali *(da valutare)*

* **Idea**: bozza del testo di un punto del verbale ricavata da testi inviati dai docenti e da vocali, con IA **gratuita e dentro il server** (niente dati fuori dall'Ateneo). La bozza è sempre rivista e approvata da una persona (referente) prima di entrare nel verbale; si salva da dove viene (file, modello, chi ha approvato). L'IA non deve decidere esiti, votazioni, numeri o CFU: li propone e li evidenzia da verificare.
* **Server (controllato il 20/10/2026)**: Ubuntu 24.04, Xeon E-2124G a 4 core (AVX2, niente hyperthreading), **15 GB di RAM** (14 liberi), nessuna scheda grafica utile (solo Intel UHD P630), 1,3 TB liberi, accesso root, carico basso. Python 3.12; Docker, Ollama e ffmpeg non installati.
* **Scelte**: riassunti con **Ollama + Qwen 2.5 7B** (5 GB di RAM; alternativa più veloce ma peggiore: modello da 3B); vocali con **faster-whisper** (modello «small» o «medium»). Tutto open source, licenze gratuite.
* **Tempi stimati su questo server**: bozza da un testo di 1-2 pagine 2-5 minuti; vocale di 5 minuti 3-5 minuti («small») o 10-15 («medium»); seduta intera di un'ora 1-3 ore (da far girare di notte).
* **Come convive col portale**: un solo lavoro IA alla volta in coda (tabella dei lavori + cron già esistente), al massimo 3 core su 4, priorità bassa, audio lunghi solo di notte, servizio in ascolto solo su 127.0.0.1. Chi carica vede «in elaborazione» e riceve l'avviso a bozza pronta.
* **Due fasi**: (1) **da testi**; (2) **da audio**, dopo il parere del DPO (voci registrate, conservazione e cancellazione automatica dell'audio, informativa ai componenti).
* **Se diventa un uso frequente**: serve una macchina con scheda grafica (trascrizione 10-20 volte più veloce, modelli più grandi).
* **Prova preliminare sul server (nessuna modifica al portale)**: si installa tutto in cartelle separate e si prova su un testo e un vocale di esempio, poi si valuta la qualità. Comandi (utente con sudo):

```bash
# 1) Ollama (servizio locale) e modello per i riassunti
curl -fsSL https://ollama.com/install.sh | sh
ollama pull qwen2.5:7b-instruct
# prova su un testo (misura anche il tempo)
time ollama run qwen2.5:7b-instruct "Riassumi in stile verbale di consiglio di dipartimento, in italiano formale: <incolla qui il testo del docente>"

# 2) Trascrizione dei vocali
sudo apt install -y ffmpeg python3-venv
python3 -m venv ~/ia/venv
~/ia/venv/bin/pip install faster-whisper
# prova su un vocale (sostituisci il file); il modello si scarica la prima volta
time ~/ia/venv/bin/python -c "from faster_whisper import WhisperModel; m = WhisperModel('small', device='cpu', compute_type='int8', cpu_threads=3); s, i = m.transcribe('/percorso/vocale.m4a', language='it'); print(' '.join(x.text for x in s))"

# 3) Controllo che il servizio sia solo locale e quanta RAM usa
ss -ltnp | grep 11434
free -h
```

* **Alternativa esterna provata (18/10/2026):** collegamento a Gemini via chiave API provato con `strumenti/locale/prova_gemini.php` (chiave in `.env.locale`, testo inventato): funziona, riassunto in stile verbale corretto in 6 secondi con il modello `gemini-flash-latest`. **Non si procede:** per usare l'API serve un account di fatturazione (piano gratuito con limiti, poi a pagamento; il piano gratuito può usare i dati per addestrare i modelli) e il parere del DPO. Si riprende solo se l'Ateneo mette a disposizione un servizio, o per l'IA locale. Copilot non ha un'API utilizzabile (servirebbe Azure OpenAI).
* **Per togliere tutto** se la qualità non convince: `sudo systemctl disable --now ollama`, poi `sudo rm -rf /usr/local/bin/ollama /usr/share/ollama ~/.ollama ~/ia`.
* **Da concordare prima di costruirlo**: chi approva la bozza (referente o anche Presidente), se le sedute si registrano già e con quale consenso, parere del DPO per la fase audio.

### F15 — Email modificabili da una pagina del pannello, e promemoria manuali del tutorato *(step 1-4 fatti in locale, Pacchetto 28; restano 5-8)*

* **Obiettivo**: tutte le email che il portale invia (≈110 punti di invio in ≈45 file) con oggetto e testo modificabili da una pagina «Email», senza toccare il codice; più due pulsanti manuali nel tutorato. L'elenco delle email del tutorato com'è oggi: `documentazione/email_tutorato.md`.
* **Decisioni prese** (l'utente non aveva ancora risposto: si è scelto il default più sicuro, cambiabile): (1) modificano gli **amministratori** (tutte le email) e **chi gestisce il modulo** per le email del proprio modulo (per il tutorato chi ha il compito «Bandi» o gestisce la Didattica); (2) **testo semplice con segnaposto**, non HTML: righe vuote = paragrafi, `**così**` = grassetto, `- ` = elenco, `> ` = citazione evidenziata. Il testo scritto nel modello non può introdurre HTML e i dati inseriti dai segnaposto sono sempre «ripuliti».
* **Step 1 — Struttura** ✅ *fatto*: tabella `email_modelli` (chiave, oggetto, corpo, aggiornato_il, aggiornato_da; solo le modifiche: se non c'è la riga vale il testo originale) e classe `ModelliEmail`: registro dei modelli (chiave, gruppo, nome, «quando parte», destinatario, segnaposto disponibili con descrizione, oggetto e corpo originali) e `render(chiave, dati)` che sostituisce i segnaposto in modo sicuro (i valori si «escapano», il pulsante con il link resta com'è). Test: senza modifiche il testo è identico a quello di oggi; segnaposto mancanti, HTML pericoloso (script, link non https) rimosso o rifiutato.
* **Step 2 — La pagina «Email»** ✅ *fatto*: `admin/email.php` in Gestione del portale, elenco per gruppo con ricerca e stato («modificata»/«originale»); scheda di un modello con oggetto e testo, bottoni dei segnaposto che inseriscono il testo, **anteprima** con dati d'esempio (come arriverà), «Invia una prova a me», «Ripristina il testo originale» e storico dell'ultima modifica (chi e quando, registro attività). Permessi come da decisione (1).
* **Step 3 — Le email del tutorato** ✅ *fatto*: le 18 email di `email_tutorato.md` passano dal registro dei modelli (`NotificheIncarichi::invia` e i 16 punti in `ServizioIncarichi`, `ServizioRegistroTutorato`, `NotifichePagamenti`); i test del tutorato devono restare verdi senza cambiarli.
* **Step 4 — Due pulsanti nel tutorato** ✅ *fatto*: «Invita a compilare il registro» (scheda del tutor e, in blocco, per tutti i tutor di un bando con il registro indietro) e «Invia al docente la fine attività da firmare» (attivo quando le ore sono tutte approvate: il portale prepara la dichiarazione e manda al docente l'email con «Apri e firma»); ogni invio resta nella storia della lettera. Due nuovi modelli di email, modificabili dalla pagina.
* **Com'è fatto (step 1-4)**: tabella `email_modelli` (chiave, oggetto, corpo, **bottone**, aggiornato_il/da; schema **v82**; solo le modifiche); `App\Email\CatalogoEmail` (registro con i **23 modelli** del tutorato: gruppo, nome, quando parte, a chi, segnaposto con descrizione ed esempio, testo originale; un'email nuova si aggiunge solo lì) e `App\Email\ModelliEmail` (`render`, `componi` per l'anteprima, `controlla`, `salva`, `ripristina`, `datiDEsempio`). Segnaposto `{{nome}}`; `{{!nome}}` = pezzo di HTML preparato dal portale (tabella, elenco), da solo su una riga. Le email con più testi (promemoria registro, promemoria docente, invito iter nel portale/Uniticket) hanno un modello ciascuna. `NotificheIncarichi::inviaModello()` sostituisce le 18 chiamate dirette: oggetto, testo e pulsante vengono dal modello, il link personale resta quello del portale. Pagina `admin/email.php` (menu Gestione del portale → «Testi delle email» e pulsante nella pagina del tutorato): elenco per gruppo con ricerca, stato modificata/originale, editor con barra (grassetto, elenco, citazione) e bottoni dei segnaposto, **Anteprima** con dati d'esempio, **Inviami una prova** (con «[PROVA]»), **Ripristina l'originale**, testo originale consultabile, modifiche nel registro attività. Due modelli nuovi: `tutorato_invito_registro` (pulsante «Invita a compilare il registro» nella scheda della lettera e «Invita… (N in ritardo)» nella pagina del bando: solo i tutor con meno ore segnate di quelle previste) e `tutorato_docente_fine_da_firmare` (pulsante «Invia al docente la fine attività da firmare»: attivo con tutte le ore approvate, prepara la dichiarazione con `confermaFine` e manda l'email con «Apri e firma»; se è già pronta diventa «Rimanda… l'email»). Ogni invio finisce nella storia della lettera. Provato con `ModelliEmailTest` (7), `PromemoriaManualiTest` (5), un test in `IncarichiTest` (la modifica cambia l'email che parte), i test del tutorato già esistenti (tutti verdi senza cambiarli) e `strumenti/locale/collaudo_email.php` (31 controlli HTTP).
* **Decisioni confermate dall'utente (sera del 19/10/2026)**: (a) **una sola pagina** «Testi delle email» per tutte le email del portale, divise per modulo e sezione (gruppi: Didattica · Pratiche, Didattica · Sedute e verbali, Convenzioni FSL, Convenzioni di tesi, Risorse e prenotazioni, Eventi e iscrizioni, Direzione, Tutorato), con ricerca; in ogni modulo un pulsante «Testi delle email» che apre la stessa pagina; permessi per gruppo (amministratori tutto, chi gestisce il modulo il proprio). (b) **Le email degli eventi oggi in Sistema** (colonne `email_conferma_*`, `email_cancellazione*`, `email_reminder_*`, `email_attestato_*`, `email_sondaggio_*` di `impostazioni_sistema`) **si spostano nella stessa pagina** e i testi già scritti si **portano nei modelli** (migrazione dei dati, poi i campi spariscono da Sistema: un solo posto). (c) **Un'email con parti variabili = segnaposto per i dati e un modello separato per ogni variante di testo**, come nel tutorato.
* **Come si lavora (per ogni gruppo, un pacchetto)**: 1) leggere i punti di invio e scrivere l'elenco dei testi originali (come `documentazione/email_tutorato.md`); 2) aggiungere i modelli in `src/Email/CatalogoEmail.php` (un metodo per gruppo, come `tutorato()`; aggiungere il gruppo in `CatalogoEmail::colore()` e il permesso in `$puo_gruppo` di `admin/email.php`); 3) sostituire le chiamate dirette con un `inviaModello`-simile (il tutorato usa `NotificheIncarichi::inviaModello`; per gli altri moduli un metodo analogo che usa `ModelliEmail::render` e poi il `Mailer` con il colore del modulo); 4) test: senza modifiche il testo è identico, i test del modulo restano verdi, un test per «la modifica cambia l'email che parte»; 5) pulsante «Testi delle email» nel modulo; 6) piano, `mappa_file_da_caricare.html` e mappa del sito, pacchetto.
* **Ordine di domani (da un altro PC: prima `git pull`, poi controllare `Migrazioni::VERSIONE`, ora 82)**: step 5 Pratiche e Sedute → step 6 Convenzioni FSL e di tesi → step 7 il resto (risorse, iscrizioni/eventi con migrazione dei testi da Sistema, attestati, sondaggi, direzione, riepilogo settimanale, avvisi) → step 8 chiusura (guida, test che cerca le chiamate dirette a `Mailer::invia` fuori dal catalogo, mappe).
* **Step 5 — Pratiche e Didattica** *(≈ 1 sessione)*: `NotifichePratiche`, `PromemoriaPratiche`, `ServizioFlussoConvalide`, `FirmaPratica`, `ServizioConfermeDocenti`, `ServizioConvocazioni`, `VerbaleFirmato`, `ServizioIter`, sondaggio a fine pratica.
* **Step 6 — Convenzioni** *(≈ 1 sessione)*: FSL (`NotificheConvenzioni`, convenzione online e firmata, documenti di classe, programma, valutazioni) e convenzioni di tesi (`NotificheConvenzioniTesi`).
* **Step 7 — Il resto** *(≈ 1 sessione)*: risorse, iscrizioni e prenotazioni, avvisi, attestati e sondaggi, direzione (cruscotto, riepilogo settimanale «Da fare per te», report): quelle degli eventi che oggi stanno in Sistema (conferma, cancellazione, promemoria, attestato, sondaggio) si spostano nella stessa pagina.
* **Step 8 — Chiusura** *(≈ ½ sessione)*: guida per i ruoli («Come si cambia il testo di un'email»), controllo che nessun punto di invio abbia ancora il testo scritto nel codice (un test che cerca le chiamate dirette), mappa e piano aggiornati.
* **Ordine consigliato**: step 1-4 insieme (= quello che hai chiesto: pagina Email, email del tutorato e i due pulsanti); poi gli altri a gruppi, una funzione alla volta, ognuno con il suo pacchetto.
### BLOCCO E — Connettore Titulus (Cineca)

#### E0 — Raccogliere i parametri *(subito, tempi lunghi)*

Vedi §6: richiesta da mandare all'Ufficio protocollo / Cineca.

#### E1 — Interfaccia «Protocollo» (indipendente da Titulus)

* Nuovo modulo `src/Protocollo/`: `interface ServizioProtocollo { disponibile(): bool; protocolla(DocumentoDaProtocollare $d): EsitoProtocollo; }` con `EsitoProtocollo` (numero, data, segnatura, id documento, errore); `ProtocolloManuale` (comportamento di oggi: l'operatore digita il numero); `ProtocolloDiProva` per i test (stesso schema di `FirmaRemota`/`FirmaRemotaAruba`); tabella `protocollo\_richieste` (`id`, `contesto` = tutorato\_lettera|tutorato\_fine|convenzione|verbale, `riferimento\_id`, `stato`, `numero`, `data`, `risposta\_json`, `errore`, `tentativi`, `creata\_il`) per avere **traccia e ripetizione dei tentativi** e un'unica pagina «Protocollo» in admin.
* Collegare i punti dove si protocolla oggi: `ServizioIncarichi::protocolla`, `ServizioRegistroTutorato::protocollaFine`, `ServizioConvenzioneOnline::salvaProtocollo` (A2), verbale (se serve).
* **Fatto quando**: tutto funziona come prima con `ProtocolloManuale`; i test coprono l'interfaccia.

#### E2 — Implementazione Titulus

* `TitulusClient implements ServizioProtocollo` (SOAP o REST secondo la versione/documentazione ricevuta), impostazioni in `.env` (`TITULUS\_URL`, `TITULUS\_UTENTE`, `TITULUS\_PASSWORD`, `TITULUS\_AOO`, `TITULUS\_UOR`, `TITULUS\_RPA`, `TITULUS\_CLASSIFICAZIONE`, …) e aggiunte a `.env.example`; test di connessione in `admin/sistema.php` (come «Invia email di prova»); fallback: se Titulus non risponde, la pratica resta «da protocollare» e si riprova (cron `cron\_background.php`) oppure l'operatore inserisce il numero a mano.
* Primo uso: **lettera di incarico firmata** (tutorato), poi fine attività, poi convenzioni.
* **Sicurezza**: credenziali solo nel `.env` (mai nel database né nei log), invio solo di PDF già firmati, nessun dato personale nei log di errore, whitelist IP del server `dibest2.unical.it` verso Titulus.
* **Fatto quando**: da `admin/tutorato.php` un clic su «Protocolla» invia il PDF a Titulus di **test** e mostra numero/data/segnatura registrati; poi si passa all'ambiente di produzione.

\---

## 5\. Elementi trasversali (da tenere d'occhio in ogni step)

* **Notifiche e Scrivania**: ogni nuova attesa (convenzione da prendere in carico, richiesta di approvazione risorsa, verbale da firmare…) deve comparire in `src/Portale/CoseDaFare.php` e nel numero nel menu laterale.
* **Permessi**: usare i perimetri esistenti (`Auth/Abilitazioni`), non creare altri ruoli; test negativo «chi non deve non vede».
* **Privacy**: ogni nuovo dato personale va nell'informativa (`privacy.php`) e nelle regole di conservazione del `cron\_background.php` (task 1c–1e, variabili `CONSERVAZIONE\_\*\_MESI` del `.env`).
* **Audit**: azioni sensibili (upload convenzioni, protocollo, accesso verbali pubblici) nel registro (`src/Sistema/RegistroAuditRepository`).
* **Documentazione**: a fine di ogni step aggiornare **il Registro (§8)**, la memoria di progetto e, se cambia il pannello, `mappa\_pannello.html`.
* **Prove sul server**: dopo il rilascio `strumenti/prove\_server.sh` e `strumenti/verifica\_sito.sh`.

\---

## 6\. Parametri per Titulus (cosa chiedere e a chi)

> Da chiedere all'\*\*Ufficio protocollo / Area sistemi informativi di Unical\*\* e al referente \*\*Cineca\*\* di Titulus. Questi sono i dati che servono per un'integrazione di questo tipo: i nomi esatti dei campi e il tipo di servizio (SOAP/REST) dipendono dalla versione di Titulus in uso a Unical e vanno \*\*confermati dalla loro documentazione tecnica\*\*.

1. **Documentazione tecnica** dei web service Titulus della versione installata (specifiche, WSDL/OpenAPI, esempi di richiesta e risposta, codici di errore).
2. **Indirizzi**: URL dell'ambiente di **test** e di **produzione**; versione di Titulus.
3. **Accesso del portale**: utenza di servizio (o token/certificato client) dedicata all'applicazione, con i **permessi** di inserire documenti; modalità di autenticazione; scadenza/rotazione credenziali.
4. **Rete**: il server del portale (`dibest2.unical.it`) deve poter raggiungere Titulus: indirizzo IP da abilitare, porte, eventuale VPN, certificati TLS.
5. **Struttura di protocollazione**: codice **AOO** (area organizzativa omogenea), codice **struttura/ufficio** (UOR) di protocollazione per il Dipartimento, **RPA** (responsabile del procedimento) di default per ogni tipo di documento.
6. **Classificazione e fascicoli**: voce di classificazione (titolo/classe) per ciascun tipo di documento — lettere di incarico, dichiarazioni di fine attività, convenzioni FSL, verbali —, se i documenti vanno in **fascicoli** esistenti o se il servizio deve crearli (e con quali regole di numerazione).
7. **Tipo di protocollo**: arrivo / partenza / **interno** (o non protocollato) per ciascun documento; chi sono mittente e destinatari da indicare (lo studente, la scuola, il docente, il Direttore) e se vanno anagrafati a priori.
8. **Formato dei documenti**: PDF PAdES accettato? dimensione massima, numero di allegati, necessità di **segnatura** sul PDF (timbro) e da chi viene apposta; eventuale conservazione sostitutiva.
9. **Risposta**: cosa restituisce (numero di protocollo, data, segnatura, identificativo documento/fascicolo, link) e come si **recupera/annulla** una protocollazione sbagliata.
10. **Oggetto e metadati**: modello dell'oggetto (es. «Lettera di incarico di tutorato – Cognome Nome – a.a.»), parole chiave, tipologia documentale, riservatezza (dati personali: livello di accesso).
11. **Limiti e tempi**: richieste al minuto consentite, orari di manutenzione, gestione degli errori e dei tentativi ripetuti.
12. **Responsabilità**: chi in Ateneo autorizza il collegamento e chi risponde ai problemi (utile anche per il DPO/ADS).

\---

## 7\. Documenti e informazioni che mi servono da te

Mettili in `documentazione/materiali/` (cartella da creare, su Drive) con questi nomi, così su qualsiasi PC li ritrovo:

|Per lo step|Cosa|Nome file suggerito|
|-|-|-|
|C2, C3|**Modello Word del verbale** (quello citato negli appunti: «come il modello allegato»)|`materiali/verbale\_modello.docx`|
|C2, C3|2-3 verbali veri recenti (anonimizzati se vuoi) di organi diversi|`materiali/verbale\_esempio\_1.docx` …|
|C3|Elenco/cartella dei verbali vecchi da importare (anche solo dove si trovano)|nota in `materiali/verbali\_vecchi.txt`|
|C1|Elenco di **tutte le pratiche studenti** con iter, attori e campi|`materiali/pratiche\_elenco.xlsx`|
|C4|Parere DPO sui verbali pubblici; chi sono gli esterni e quali organi|`materiali/verbali\_pubblici.txt`|
|B3|Foglio cartaceo del diario di bordo auto|`materiali/diario\_bordo.jpg/.pdf`|
|B|Elenco delle risorse reali (aule, laboratori, auto) con chi è responsabile e le informazioni che si chiedono a chi prenota|`materiali/risorse\_elenco.xlsx`|
|D1|Ufficio pagamenti: nome/email e documenti richiesti|`materiali/tutorato\_pagamenti.txt`|
|D2|Un esempio di graduatoria/vincitori reale (anonimizzabile)|`materiali/tutorato\_graduatoria.xlsx`|
|A|Chi è l'amministratore che riceve la convenzione; conferma D1/D2|nota in questo file|
|E|Risposte alle voci di §6|`materiali/titulus\_parametri.txt` + documentazione tecnica|

> Gli appunti e i materiali con dati personali \*\*non vanno nel repository git\*\* (aggiungere `documentazione/materiali/` al `.gitignore` se contiene dati reali).

\---

> **Decisioni D1 (15/10/2026):** UNILAV caricato dal referente DOPO il protocollo della lettera; compenso: 25 € lordi/ora per l'ente, in lettera = lordo ÷ 1,24 (netto oneri), ricalcolo automatico quando le ore effettive sono meno (e UNILAV da ricaricare); pagamento SOLO con accredito su IBAN (niente cassa); IBAN e dati bancari cifrati nel database, visibili a tutor e ufficio pagamenti con registro di chi li apre (avvisare il DPO); il «referente amministrativo» è l'ufficio pagamenti; l'ufficio può rimandare al referente con una nota, il referente può rimandare al docente con il motivo (email); una persona può stare in più uffici di Ateneo; lo studente riceve l'email a pagamento disposto; la lettera di fine attività la firma in PAdES il docente titolare, senza protocollo. Anteprime grafiche: `documentazione/mockup_tutorato_pagamenti.html`, `anteprime_tutorato/`, presentazione `Tutorato_iter_e_pagamenti.pdf`.


## 8\. Registro di avanzamento (da aggiornare)

|Step|Titolo|Stato|Data|Note / commit|
|-|-|-|-|-|
|0|Piano e documenti|**in corso**|13/10/2026|Piano scritto, in attesa risposte §2 e materiali §7|
|E0|Richiesta parametri Titulus|da fare||Inviare §6|
|A1|Upload convenzione firmata dalla scuola|**fatto in locale (da caricare)**|13/10/2026|Schema v49 e v50 (A1 e A2 vanno caricati insieme: `cache/schema_v50.ok`). Nuovi: `src/Fsl/ServizioConvenzioneFirmata.php`, `tests/Integration/Fsl/ConvenzioneFirmataTest.php`. Modificati: `convenzione_online.php`, `area_personale.php`, `admin/fsl.php`, `admin/convenzione_file.php`, `admin/inizio.php`, `admin/admin_header.php`, `src/Fsl/Costanti.php`, `NotificheConvenzioni.php`, `ConvenzioneCompilataRepository.php`, `ServizioConvenzioneOnline.php`, `src/Portale/CoseDaFare.php`, `src/Core/Schema/Definizioni.php`, `Migrazioni.php`, `tests/Integration/Fsl/FslBase.php`. Prove: 5 nuove + 872 di esegui.php ok|
|A2|Presa in carico/ricarico admin + protocollo|**fatto in locale (da caricare)**|14/10/2026|Schema v50. Stati `stato_iter`: `''` → `caricata_scuola` → `in_carico` → `protocollata` (o `rifiutata` con motivo, la scuola ricarica). Pulsante «Prendi in carico» (email alla scuola), «Rifiuta con un motivo», «Carica la versione definitiva» (PDF PAdES + protocollo + validità → registra in `convenzioni_scuole`, conferma le prenotazioni in attesa, avvisa la scuola). Tutto in `ServizioConvenzioneFirmata`; UI in `admin/fsl.php` (scheda Convenzioni) e `convenzione_online.php`. Decisioni: validità 1 anno; si confermano tutte le prenotazioni coperte. Prove: 8 test in `ConvenzioneFirmataTest`|
|A3|Documenti nell'Area personale della scuola|**fatto in locale (da caricare)**|14/10/2026|Nessuna modifica di schema. Nuovi: `src/Fsl/ServizioDocumentiScuola.php`, `documento_scuola.php` (download protetto), `tests/Integration/Fsl/DocumentiScuolaTest.php`. Modificati: `area_personale.php` (tab Programma FSL), `tests/Integration/Fsl/FslBase.php`. Decisioni: Allegato A resta generato dal portale (PDF senza firma); si vede anche lo storico delle scadute; vedono e scaricano TUTTI i docenti collegati alla scuola (prenotazione FSL non annullata della persona o `utenti.scuola_codice`). Prove: 5 test|
|A4|Firma del Direttore sulla convenzione (Aruba o PDF caricato)|**fatto in locale (da caricare)**|14/10/2026|Schema v52 (`cache/schema_v52.ok`; A1-A4 vanno caricati insieme). ITER DEFINITIVO (15/10/2026, voluto dall'utente): la scuola carica convenzione PAdES + Allegato A (PDF anche senza firma, obbligatorio al primo caricamento) → notifica all'ufficio → protocollo in entrata (solo numero e data, nessun file) → il link di firma parte AUTOMATICAMENTE al Direttore → firma (Aruba o PDF caricato) → notifica all'ufficio → protocollo definitivo + PDF protocollato facoltativo → registro, conferma prenotazioni, avviso alla scuola; l'Allegato A della scuola va nel registro. Chiarito con l'utente: firma SOLO il Direttore (nessun docente titolare). Stati `stato_iter` ora: `caricata_scuola` → `in_carico` → `da_firmare` → `firmata_direttore` → `protocollata`. Nuovi: `src/Fsl/ServizioFirmaDirettore.php`, `firma_convenzione.php`, `tests/Integration/Fsl/FirmaDirettoreTest.php`. Direttore impostato dal pannello (scheda Convenzioni → «Direttore che firma le convenzioni»: nome, email, CF facoltativo) in `impostazioni_sistema`. Firma remota Aruba riusa `App\Tutorato\FirmaRemota*`: NON ancora configurata in produzione (`ARUBA_ARSS_URL`, `ARUBA_ARSS_DOMINIO`, `ARUBA_ARSS_CERTID` nel `.env`): senza, il Direttore scarica, firma e carica il PDF. Firma visibile sull'ultima pagina (riquadro fisso in `ServizioFirmaDirettore::RIQUADRO`), invisibile se non si contano le pagine. Prove: 8 test|
|D1a|Uffici di Ateneo (l'admin crea l'ufficio e associa i dipendenti dall'anagrafe)|**fatto in locale (da caricare)**|15/10/2026|Schema v53. Pacchetto 2 della mappa dei file. Provato anche end-to-end in locale (studente → referente → ufficio) oltre alle prove automatiche|
|D1b|Decreti (bando, commissione, approvazione atti) in PDF e UNILAV per tutor|**fatto in locale (da caricare)**|15/10/2026|Schema v53. Pacchetto 2 della mappa dei file. Provato anche end-to-end in locale (studente → referente → ufficio) oltre alle prove automatiche|
|D1c|Dichiarazione individuale (modello N. 6) online nell'accettazione + PDF compilato|**fatto in locale (da caricare)**|15/10/2026|Schema v53. Pacchetto 2 della mappa dei file. Provato anche end-to-end in locale (studente → referente → ufficio) oltre alle prove automatiche|
|D1d|Fine attività: firma PAdES del docente titolare, SENZA protocollo; validazione del referente|**fatto in locale (da caricare)**|15/10/2026|Schema v53. Pacchetto 2 della mappa dei file. Provato anche end-to-end in locale (studente → referente → ufficio) oltre alle prove automatiche|
|D1e|Passaggio all'ufficio pagamenti (una/più/tutte) + lavorazione e «pagato» + avviso allo studente|**fatto in locale (da caricare)**|15/10/2026|Schema v53. Pacchetto 2 della mappa dei file. Provato anche end-to-end in locale (studente → referente → ufficio) oltre alle prove automatiche|
|D1f|Documenti e stato del tutorato nell'Area personale del tutor|**fatto in locale (da caricare)**|15/10/2026|Schema v53. Pacchetto 2 della mappa dei file. Provato anche end-to-end in locale (studente → referente → ufficio) oltre alle prove automatiche|
|D2|Import dei vincitori dal Word del decreto di approvazione atti (tabelle ART. 2), abbinamento all'anagrafe (insegnamento, codice, corso, docente titolare modificabile), lettere in bozza|**fatto in locale (da caricare)**|16/10/2026|Schema v54. Pacchetto 3 della mappa dei file. Nel decreto non ci sono CF, email e genere: si completano in anteprima o nella lettera (bozza incompleta non inviabile). Ufficio pagamenti impostato nella scheda del bando, non serve per l'import|
|D3|Collaudo tutorato|**fatto**|16/10/2026|Nessun file modificato. esegui.php: 1311 prove (con portale locale) OK; PHPUnit Tutorato+Unit: 38 test OK. Il flusso import -> bozza -> completamento dati -> invio -> conferma con dichiarazione -> firme -> registro -> fine attivita -> validazione -> ufficio pagamenti e coperto|
|C2|Verbale a punti|**fatto in locale (da caricare)**|17/10/2026|Schema v55. Pacchetto 4 della mappa dei file. Modello: `materiali/verbale_modello.docx` (Consiglio unificato CdS: testata «Verbale n. 8 – 07 settembre 2026», firmatari nel piè di pagina, punti con titolo in grassetto, pratiche a sottosezioni per tipo). Nuovi: `PuntoSedutaRepository`, `ServizioPuntiSeduta`, `TestoVerbale`, `tests/Unit/Didattica/TestoVerbaleTest.php`. Modificati: `EsportazioneVerbale`, `Word::crea` (opzioni `testata`, `piede_sx`, `piede_dx`), `SedutaRepository`, `ServizioSedute`, `admin/didattica_sedute.php` (passo «Cosa è stato detto», campo «Verbale n.»). Tabella `didattica_sedute_punti`, colonne `pratiche.punto_id` e `didattica_sedute.numero_verbale`. Fuori da C2 (rinviato): `visibile_pubblico` dei punti (serve a C4). Prove: 9 nuove in esegui.php (884 ok); il test PHPUnit nuovo non è stato eseguito (manca vendor/ su questo PC) ma le stesse asserzioni sono state verificate a mano|
|C3|Archivio verbali|**fatto in locale (da caricare)**|18/10/2026|Schema v56. Pacchetto 5 della mappa. Nuovi: `admin/didattica_archivio.php`, `ArchivioVerbali`, `Infrastructure/Pdf/TestoPdf` (lettura del testo dei PDF digitali, senza librerie). Importazione di PDF multipli o di una cartella (a gruppi di 8 file/16 MB via fetch: `max_file_uploads` e `post_max_size` del server non bloccano), data/numero/a.a. dal nome del file e dalla cartella, poi dal testo; dati sintetici: organo/consiglio, data, a.a., numero, o.d.g. breve. Ricerca nel testo anche dei PDF importati (anche ignorando gli spazi). Permessi: referente = verbali del proprio consiglio, Ufficio didattico tutti. Colonne `origine`, `testo_ricerca`, `file_hash`, `nome_originale` su `didattica_sedute`. Il lettore PDF è provato su PDF di Chrome e del portale, NON su PDF di Word/scansioni: se un PDF non si legge si archivia lo stesso. Prove: 15 nuove in esegui.php (899 ok); provato anche in locale (caricamento, ricerca, modifica, download)|
|C4|Verbali pubblici con password|**fatto in locale (da caricare)**|18/10/2026|Schema v57. Pacchetto 6 della mappa. Fatto su richiesta dell'utente PRIMA del parere del DPO (D7): nulla è pubblico finché l'Ufficio didattico non crea un accesso e segna un verbale come pubblicabile. Nuovi: `verbali.php`, `admin/didattica_accessi.php`, `ServizioVerbaliPubblici`, `AccessoVerbaliRepository`. Scelte di privacy: un PDF firmato in PAdES non si può oscurare, quindi per ogni verbale si pubblica o una VERSIONE CON OMISSIS caricata a parte o il PDF firmato con conferma esplicita «nessun dato personale»; registro accessi con IP anonimizzato (ultimo ottetto a 0) conservato 12 mesi; password solo come hash e mostrata una volta; sessione separata (`verbali_pubblici_accesso`), scadenza per inattività 1 ora, blocco dopo 8 tentativi in 15 minuti, `X-Robots-Tag: noindex`. Si vedono solo verbali pubblicabili e firmati (o importati dall'archivio) dei consigli dell'accesso (D8: una password per gruppo di esterni, non per persona). Prove: 19 nuove in esegui.php (918 ok); provato in locale (accesso con password giusta/sbagliata, elenco, download, intestazioni)|
|C1|Wizard pratiche|**fatto in locale (da caricare)**|18/10/2026|Schema v58. Pacchetto 7 della mappa. Dall'elenco `materiali/pratiche_elenco.xlsx` (~30 pratiche, 6 gruppi): servivano il passo «firma del relatore» (persona scelta dallo studente), la «firma del Direttore» (certificati e attestati) e l'interruttore «in verbale» (sì/no). Iter: `iter_json` accetta anche oggetti `{t: ufficio|persona|direttore, u, campo, gg}` (retrocompatibile col vecchio elenco di uffici); `gg` = tempo massimo del passo, al posto dell'unico promemoria del modulo. Nuovi: `FirmaPratica`, `FirmaPraticaRepository`, tabella `pratiche_firme`, `firma_pratica.php` (link personale + accesso Unical con la stessa email; firma PAdES Aruba o PDF caricato; rifiuto con motivo → la pratica torna allo studente come integrazione). Decisioni dell'utente: relatore con link personale come i verbali; Direttore sempre lo stesso (quello delle convenzioni); certificati/attestati: solo passo «firma Direttore» con documento caricato, PEC fuori dal portale (collegabile a Titulus dopo, step E). Il documento da firmare è la domanda in PDF/A (relatore) o l'ultimo PDF caricato dall'ufficio (Direttore). Dopo la firma `ServizioFlussoConvalide::trasmettiAlPasso` porta la pratica al passo successivo; «Passa al passo successivo» e «Manda alla firma» nella scheda della pratica. Procedura guidata a 7 passi in `admin/didattica_moduli.php` (bozza/pubblica, copia, riepilogo, modelli dal foglio: certificato, attestato, esoneri, carriera, titolo estero, cancellazione, tesi, variazione tesi; iter standard protocollo → @cdl → @segreteria). Cambio di comportamento: il promemoria di una pratica senza persona in carico va a tutto l'ufficio (non solo ai manager). Non fatto: simulazione con una pratica di prova e testi email personalizzati per modulo. Prove: 25 nuove in esegui.php (944 ok); provato in locale la maschera (modello, salvataggio bozza, iter a righe), la scheda pratica e la pagina di firma con rifiuto. PHPUnit non eseguito su questo PC|
|B1|Responsabile risorsa|**fatto in locale (da caricare)**|18/10/2026|Schema v59. Pacchetto 8 della mappa. Tabella `risorse_responsabili` (persone dall'anagrafe, email obbligatoria, flag «riceve le email» e «approva e rifiuta»). Nessun accesso al pannello: il responsabile gestisce le sue risorse da `risorse_responsabile.php` (riquadro «Le mie risorse» in Area personale), come LibreBooking. `NotificheRisorse` manda le richieste anche ai responsabili, con il link alla loro pagina. Il referente scritto si precompila col primo responsabile. Prove: 15 in esegui.php|
|B2|Campi personalizzati prenotazione|**fatto in locale (da caricare)**|18/10/2026|Schema v60. Campi: stesso formato e controlli dei moduli della didattica (`CampiModulo`/`LetturaRisposte`) con soli tipi semplici; editor nella scheda della risorsa, copia da un'altra risorsa, risposte nell'elenco, nel CSV, nelle email e nella pagina del responsabile. CONTATORI (richiesta dell'utente, esempio i campioni di un laboratorio): modalità «per periodo» (capacità per giorno/settimana/mese, si ricarica da sola) e «scorta» (carichi/rettifiche con registro movimenti), minimo e massimo per prenotazione, obbligatorio sì/no; scalano anche con la richiesta in attesa di approvazione (decisione dell'utente) e tornano se rifiutata/annullata (il disponibile si calcola dalle prenotazioni attive); controllo dentro il blocco del calendario (niente doppie prenotazioni dell'ultima quantità); le serie settimanali saltano le date senza quantità. Modulo pubblico con «ne restano N» per il giorno scelto. Prove: 30 in esegui.php|
|B3|Diario di bordo|**fatto in locale (da caricare)**|18/10/2026|Schema v61. Tipo di risorsa «Auto o mezzo»; diario attivabile per qualunque risorsa. Campi di partenza dal modulo cartaceo dell'utente (data, conducente, partenza, arrivo, ore, km iniziali e finali, litri, segnalazioni), modificabili dall'admin con ruoli (km, litri, data…) che reggono controlli e totali anche se rinominati. Lo compila chi ha prenotato, gli altri conducenti indicati alla prenotazione (scelti dall'anagrafe) o un responsabile; i km iniziali si propongono dall'ultimo viaggio e non possono scendere; km percorsi calcolati; verifica del responsabile (una correzione la azzera); promemoria col cron esistente; blocco delle nuove prenotazioni se manca il diario (opzione, spenta di default); registro, totali ed Excel (con riepilogo per mese). Pagina `diario_bordo.php` pensata anche per telefono. Prove: 30 in esegui.php (1013 totali)|
|B4|Report risorse|**fatto in locale (da caricare)**|19/10/2026|Schema v62. Pacchetto 9 della mappa. Tabella `risorse_scadenze` (bollo, assicurazione, tagliando… con preavviso, inserite dall'admin nella scheda «Scadenze» della risorsa; avviso al responsabile prima e dopo la data con il cron dei promemoria, una volta sola; rinnovo dal cruscotto). Pagina `risorse_cruscotto.php` per responsabili e amministratori: prenotazioni per stato, ore e utilizzo, % rifiuti, fasce orarie, persone, mesi, contatori, km e consumo l/100 km, Excel. Nuovi: `src/Risorse/{ScadenzaRisorsaRepository,ServizioScadenzeRisorsa,CruscottoRisorsa}.php`, `risorse_cruscotto.php`. Modificati: `risorse_responsabile.php`, `admin/risorse.php`, `RisorsaRepository`, `NotificheRisorse`, `PromemoriaPrenotazioni`, schema, `RisorseIntegrazioneTest`. esegui.php: 1033 prove OK|
|C1b|Pratica «Certificato di iscrizione» (prima dell'elenco Uniticket): bozza, modifica fino alla presa in carico, attesa del pagamento delle marche da bollo|**fatto in locale (da caricare)**|21/10/2026|Schema v63. Pacchetto 10 della mappa. Campi da `cert_iscr.pdf` di Uniticket. Iter: protocollo → ufficio del corso (carica marche in Esse3, avvisa lo studente, spunta pagamento «marca da bollo assolta virtualmente», carica il certificato di Esse3) → Direttore firma PAdES → lo studente scarica e stampa, operatore avvisato. Non va in verbale. Provato con PHPUnit (8 test nuovi) e di persona sul portale locale. Aperto: dove scrivere la dicitura sul PDF|
|C1c|Pratiche «Certificato di laurea» e «Diploma Supplement» + anteprima dei moduli non pubblicati|**fatto in locale (da caricare)**|21/10/2026|Schema v63 (invariato). Pacchetto 11 della mappa. Certificato di laurea: stesso iter dell'iscrizione. Diploma Supplement: senza marca da bollo, nuovo passo «l'ufficio carica il documento da firmare» (flag `documento` nell'iter). Anteprima: `modulo.php?id=…&anteprima=1` per chi gestisce la Didattica, anche se non pubblicato. Provato con PHPUnit (2 test nuovi) e sul portale locale. **22/10/2026: certificati di iscrizione e di laurea unificati in «Richiesta Certificato»** (tendina Iscrizione / Laurea / Rinuncia agli studi, caselle diverse per tipo, stesso iter con marche da bollo); tolta la scritta «Sistema di Prenotazione e Gestione Eventi» dal footer|
|C1d|Attestati di frequenza alle lezioni e di esame sostenuto (conferma dei docenti nel portale)|**fatto in locale (da caricare)**|22/10/2026|Schema v64. Pacchetto 12 della mappa. Iter: studente (frequenza: tabella di lezioni con insegnamento, docente dall'anagrafe, date, ore, aula; bozza) → ogni docente conferma o rifiuta le sue righe nel portale (rifiuto = torna allo studente, poi ripartono le conferme) → Ufficio attestati controlla l'attestato PDF/A preparato dal portale → Direttore firma PAdES → protocollo in uscita (numero, data, PDF protocollato) → studente scarica. Niente protocollo in entrata. Provato con PHPUnit (8 test) e sul portale locale|
|C1e|Pratiche «Trasferimento in entrata», «Iscrizione per rinuncia e decadenza», «Abbreviazione di corso»|**fatto in locale (da caricare)**|22/10/2026|Schema v67 (solo la versione, per rifare i dati di partenza). Pacchetto 13 della mappa. Campi dai moduli Uniticket in `materiali/`. Iter: protocollo → ufficio del corso → Segreteria studenti, poi Consiglio (vanno in verbale). La marca da bollo la paga lo studente su UniPay e allega la ricevuta (non è un passo dell'iter). OFA: avviso allo studente e campo dell'ufficio. Domanda PDF/A: «Corso di destinazione» non compare più come corso di iscrizione. Provato con la suite `esegui.php` (5 prove nuove); non provata la domanda in PDF su una pratica vera|
|C1f|Pratiche di carriera: convalida ed esonero di inglese, variazione piano di studi, cambio curriculum, tempo pieno/part-time, fuori piano, anticipo insegnamenti, titolo estero|**fatto in locale (da caricare)**|22/10/2026|Schema v67 (solo la versione). Pacchetto 14 della mappa. Moduli Uniticket in `materiali/`. Iter uguale per tutte (protocollo → ufficio del corso → Segreteria, in verbale, decide il Consiglio); la marca da bollo (verificata dal protocollo) solo per la convalida di inglese. Piano di studi e anticipo: tabella con scelta dal catalogo al posto del PDF da ricaricare. Titolo estero: aggiunto il «Corso di destinazione» per instradare la pratica. Convalida esami già esistente, non toccata. Suite `esegui.php` verde (1054 prove)|
|C1g|Pratiche: tesi (unica, con conferma del relatore), cancellazione errata iscrizione, esonero dalla frequenza (unico), mobilità internazionale (3)|**fatto in locale (da caricare)**|22/10/2026|Schema v67 (solo la versione). Pacchetto 15 della mappa. Tesi: richiesta, restauro e variazione in un solo modulo con campi condizionati; il relatore conferma nel portale (passo «conferma», niente firma digitale) prima del protocollo, poi referente del corso e verbale. Mobilità: passano dall'ufficio «Internazionalizzazione» (già esistente) prima del referente del corso, poi verbale e segreteria. Pagina di conferma del docente resa generica. **Manca `semestre_aggiuntivo.pdf`**: la pratica non è stata creata. Ipotesi da verificare: opzioni del select «Scegli» nella variazione di mobilità (ora un testo libero) e «Documento allegato» nella fine attività. Suite `esegui.php` verde (1067 prove)|
|F6|Attestato FSL con la scuola|**fatto in locale (da caricare)**|15/10/2026|Pacchetto 17 della mappa (schema invariato). L'attestato delle attività FSL (convenzione) ha la fascia «Formazione Scuola Lavoro», il nome della scuola e il comune, titolo con prefisso; gli attestati degli eventi non cambiano. Corretti gli schemi dei test degli attestati (ora verdi)|
|F8|Uffici di Dipartimento e di Ateneo in un'unica anagrafe (organigramma)|**fatto in locale (da caricare)**|16/10/2026|Pacchetto 19 della mappa (schema v71). Nuove tabelle `uffici` e `uffici_persone` (un'unica anagrafe con ambito dipartimento / ateneo), pagina `admin/uffici.php` («Uffici e persone», in Gestione del portale; `admin/uffici_ateneo.php` rimanda lì), `src/Uffici/` (`UfficiRepository`, `ServizioUffici`, `Direzione`). **Migrazione automatica** dalle quattro tabelle di prima (`didattica_uffici`, `ufficio_didattica`, `uffici_ateneo`, `uffici_ateneo_persone`): gli uffici del Dipartimento tengono lo stesso id (iter dei moduli e pratiche in corso non cambiano), gli operatori tengono il numero di prima (`op_id`, quello di `pratiche.assegnata_a`), gli uffici di Ateneo hanno id nuovi e i bandi di tutorato sono aggiornati; il Direttore delle impostazioni entra nella Direzione. Le vecchie tabelle restano, non più usate. **Persone solo dall'anagrafe** (tolto l'inserimento a mano). **Direzione**: ufficio fisso con Direttore e vice (ruoli «Direttore» / «Vice direttore»), più «Segreteria di direzione» (fisso); il Direttore unico del portale è quello della Direzione (`ServizioFirmaDirettore`, `FirmaPratica`, cruscotto, riepilogo). **Direttore assente**: interruttore in `impostazioni_sistema` (con data di rientro facoltativa; passata la data torna da solo), lo attivano Direttore, vice, segreteria di direzione e amministratori da `admin/uffici.php` (scheda Direzione) o da `direttore_assente.php` (card in Area personale e nel cruscotto); registrato nel registro operazioni; con l'interruttore attivo firme, link e avvisi vanno al vice, Direttore e vice vedono comunque il cruscotto. **Uffici richiamati altrove**: responsabili di una risorsa (persone o un ufficio intero: `risorse_uffici`), convenzioni FSL (persone dell'ufficio «Ufficio convenzioni FSL» ricevono gli avvisi e aprono la pagina), pratiche e tutorato (già per ufficio). Dalla Didattica sono tolte le schede «Persone» e «Uffici e iter» (resta il «Ricevimento»). Gli operatori sono le persone con almeno un compito (pratiche, sedute, ricevimento, bandi): chi è in un ufficio senza compiti riceve gli avvisi ma non apre la Didattica. Prove: `strumenti/prove/oop/uffici.php` (comprese la migrazione con dati di prima e «Direttore assente»); anche i test PHPUnit sono stati adeguati (non eseguibili su questo PC). **Ancora aperti**: una pagina pubblica «Chi siamo / Organigramma» e un selettore unico di ufficio per gli eventi (organizzatori).|
|F9|Promemoria e messaggi alle scuole sulla convenzione FSL|**fatto in locale (da caricare)**|16/10/2026|Pacchetto 19 della mappa (schema v70). In «Convenzioni da seguire»: «Ricorda di caricare il PDF» (una scuola o tutte; non più di uno ogni 24 ore; ripete il motivo se il file era stato rifiutato), messaggi liberi dell'ufficio e risposte della scuola dalla sua pagina (`convenzioni_messaggi`, `src/Fsl/ServizioMessaggiConvenzione.php`), «Nuova risposta» sulla scheda e avviso a chi gestisce le convenzioni. Prove: `strumenti/prove/oop/fsl_messaggi_convenzione.php`.|
|F11|Verbali pubblici: sola visualizzazione con la password, download con le credenziali di Ateneo|**fatto in locale (da caricare)**|19/10/2026|Schema v80. Con la sola password i verbali si **leggono nella pagina** (PDF.js salvato in `assets/vendor/cdnjs/ajax/libs/pdf.js/3.11.174/`, PDF disegnato su canvas con filigrana «Consultazione riservata · accesso · data e ora», senza pulsanti di scaricamento o stampa, niente selezione del testo; il file arriva con un permesso monouso di 10 minuti, `Cache-Control: no-store`, rifiutato se l'indirizzo si apre direttamente) e l'indirizzo `?d=` risponde 403. **Scarica** solo chi accede con le **credenziali di Ateneo** (link «Accedi con le credenziali di Ateneo» nella pagina) ed è amministratore o è nell'ufficio fisso **«Download dei verbali»** (`uffici`, chiave `verbali_scarico`): le persone si scelgono dall'anagrafe nel pannello (Didattica → Sedute e verbali → Accesso ai verbali, card «Chi può scaricare i verbali», oppure Uffici e persone). Chi è abilitato vede tutti i verbali pubblicabili senza password. Registro con letture (`visualizza`) e scaricamenti (`scarica`), con l'email di chi è entrato con le credenziali di Ateneo. Prove: `strumenti/prove/oop/verbali_download.php`. **Limite**: un file mostrato in un browser non si può rendere davvero «non scaricabile» (uno strumento per sviluppatori o uno screenshot lo aggirano); filigrana e registro scoraggiano e permettono di risalire all'accesso.|
|F10|Convenzioni di tesi con enti e aziende esterne|**fatto in locale (da caricare)**|17/10/2026|Pacchetto 20 della mappa (schema v73). Moduli Didattica › «Convenzioni di tesi» (`admin/convenzioni_tesi.php`, `src/ConvenzioniTesi/`). **Decisioni:** l'azienda lavora da un link personale **senza SPID/CIE e senza registrarsi** (più facile per le aziende); si può chiedere la convenzione anche da una pagina pubblica (`richiedi_convenzione_tesi.php`); **modifiche agli articoli**: l'azienda le propone nel modulo, l'ufficio accetta/rifiuta/risponde con un altro testo **prima** della firma; Word e PDF dal modello `modelli_documenti/convenzione_tesi.docx` (il PDF si legge dal Word compilato: stesso testo); firma PAdES di azienda e Direttore (con «Direttore assente» il vice); protocollo in entrata e in uscita; archivio anche con le convenzioni già in essere; elenco pubblico (nome, comune, settore, sito, solo con il consenso e a convenzione valida); promemoria alle aziende ferme e avviso di scadenza a 60 giorni con rinnovo. **Da verificare sul server:** la firma PAdES vera (non provabile in locale). **Fatto dopo (Pacchetto 22, schema v75):** F10b azienda convenzionata scelta nella pratica di tesi (campo «azienda_tesi», riquadro con lo stato della convenzione per studente e ufficio, il modulo già creato si aggiorna da solo); F10c dati del Direttore (nome, luogo e data di nascita) nel Word e nel PDF dalla Direzione, con avviso se il Direttore cambia; strumento `strumenti/verifica_firma_pdf.php` e guida `documentazione/prova_firma_vera_convenzioni_tesi.md` per la prova della firma vera.|
|F7|Da fare per te, riepilogo settimanale e Report FSL|**fatto in locale (da caricare)**|15/10/2026|Pacchetto 18 della mappa (schema v69). Scheda «Da fare per te» in Area personale, email del mercoledì con disiscrizione (`RIEPILOGO_PERSONE`), scheda «Report FSL» in FSL con Excel. Scheda «Convenzioni da seguire» in FSL (iter guidato per le convenzioni caricate dalle scuole); «Report PCTO» ribattezzato «Report FSL». Idee future nella mappa: tempi per passo, valutazione studente FSL, cerca ovunque, sostituzioni operatori, sondaggio a fine pratica.|
|E1|Interfaccia Protocollo|in attesa|||Insieme alla firma digitale Aruba: si riprende quando l'Ateneo risponde|
|E2|Titulus|in attesa||Servono parametri||
|G|Collaudo pratiche, verbali, risorse|**fatto in locale**|22/10/2026|Scenario di dimostrazione (`strumenti/locale/scenario_demo.php`: persone, uffici, pratiche in vari punti dell'iter, seduta con verbale in firma, risorse con prenotazioni e liste d'attesa), collaudo automatico degli iter di tutte le pratiche (`strumenti/locale/collaudo_pratiche.php`), schermate di tutte le pagine principali per ruolo (`schermate_documentazione.py`). Corretto: l'ufficio «Referente mobilità internazionale» duplicava «Internazionalizzazione» (ora si usa quello esistente e si unifica se era già stato creato). Non provati: PHPUnit, firma PAdES vera, invio email reale|
|F1|Cruscotto del Direttore|**fatto in locale (da caricare)**|22/10/2026|Pacchetto 16. `cruscotto_direttore.php` e `src/Portale/CruscottoDirettore.php`: nove gruppi (documenti da firmare con link diretto, convenzioni FSL da firmare, pratiche ferme oltre i giorni del modulo, conferme dei docenti in ritardo, verbali in firma, convenzioni in scadenza, tutorato fermo, scadenze delle risorse, prenotazioni da approvare ferme). Lo vedono gli amministratori e il Direttore indicato nelle impostazioni; card nell'Area personale. Solo lettura, nessuna tabella|
|F2|Ricorrenti e liste d'attesa|**fatto in locale (da caricare)**|22/10/2026|Schema v68 (tabella `prenotazioni_risorse_attesa`). Pacchetto 16. Ripetizione ogni settimana, ogni due settimane o ogni mese (stesso giorno della settimana); lista d'attesa sugli slot occupati: avviso al primo in coda quando la prenotazione è annullata o rifiutata, 24 ore per prenotare, poi passa al successivo (cron); la coda si chiude se prenota. Lo slot non resta riservato. 15 prove nuove in `esegui.php`|
|F3|Backup e ripristino|**fatto in locale (da caricare)**|22/10/2026|Pacchetto 16. Il backup (database `.sql.gz`, file del sito `.zip`, copia sul NAS, copia cifrata del database per email, avvisi) esisteva già (`admin/cron_backup.php`). Aggiunti: verifica automatica dopo ogni backup (`VerificaBackup`: il database si rilegge per intero, lo ZIP si riapre e contiene `config.php`), controllo dello spazio libero e `strumenti/ripristina_backup.php` (verifica, prova su un database a parte, estrazione dei file, ripristino vero con opzione scritta per esteso). Provato su una copia del database locale: 74 tabelle e 110 righe ripristinate. Resta da decidere dove tenere la copia lunga (NAS già previsto da `BACKUP_NAS_PATH`)|
|F4|Guide per ruolo|**fatto in locale (da caricare)**|22/10/2026|Pacchetto 16. `guida.php` e `src/Portale/GuidePerRuolo.php`: dieci guide (studente, scuole per la convenzione FSL; vedi anche, docente e relatore, ufficio protocollo, ufficio del corso, referente del consiglio, responsabile di risorsa, tutor, Direttore, amministratore) con schermate in `assets/guide/`; versione da stampare e PDF in `documentazione/guide/`; il «?» del pannello e l'Area personale rimandano alle guide. Presentazioni dell'iter con schermate: `Pratiche_verbali_iter` (aggiornata), `Certificati_attestati_iter`, `Risorse_prenotazioni_iter` in `documentazione/`|
|F11|Idee: Cerca ovunque nel pannello, Sostituzioni degli operatori, Sondaggio a fine pratica|**fatto in locale (da caricare)**|17/10/2026|Pacchetto 23, schema v76 (tabelle `operatori_sostituzioni` e `pratiche_sondaggi`). **Cerca ovunque:** `RicercaPannello` + `admin/cerca.php` + casella nella barra; sei gruppi (pratiche, persone, verbali, aziende di tesi, scuole, risorse) filtrati dai permessi. **Sostituzioni:** `SostituzioniOperatori`/`SostituzioniInCorso`, `admin/sostituzioni.php`; nel periodo email, promemoria e «Da fare» vanno anche al sostituto, la pratica resta all'assente. **Sondaggio:** `SondaggioPratica`, `sondaggio_pratica.php`, invito facoltativo nell'email di conclusione e nella pratica, risultati anonimi nelle statistiche. Provato con 12 test PHPUnit nuovi e `strumenti/locale/collaudo_idee_pannello.php` (23 controlli HTTP).|
|F13|Archivio verbali: altri organi del corso di studi e cartella sul NAS letta in automatico|**fatto in locale (da caricare)**|19/10/2026|Pacchetto 24, schema v77 (tabella `archivio_organi`; colonne `organo_id`, `da_cartella`, `cartella_firma` in `didattica_sedute`, `cartella_archivio` nei consigli). `OrganiArchivio` (commissione didattica, gruppo di riesame, riunioni con gli studenti, altri; creazione dei tre standard per corso), `ScansioneArchivio` (legge `uploads/verbali/archivio/`: cartella → organo più specifico, data/numero/anno dal nome e dal percorso, file cambiati riletti, spostati seguiti, spariti tolti con cautela se il NAS non risponde; cron ogni ora + «Scansiona ora»). Provato con 9 test PHPUnit e `strumenti/locale/collaudo_archivio_organi.php` (18 controlli HTTP). Guida: `guida_file_sul_nas.md` punto 9.|
|F15|Email modificabili da una pagina del pannello (tutte quelle del sistema) e promemoria manuali del tutorato|**step 1-4 fatti in locale (da caricare)**, restano 5-8|19/10/2026|Pacchetto 28, schema v82. Pagina «Testi delle email», 23 modelli del tutorato, due pulsanti manuali. 8 step, vedi §F15: pagina «Email» con anteprima e prova, le 18 email del tutorato, due pulsanti (registro al tutor, fine attività al docente), poi pratiche, convenzioni e il resto. Decisioni aperte: chi modifica e che tipo di editor.|
|F14|Tutorato: «Iter per Uniticket»|**fatto in locale (da caricare)**|19/10/2026|Pacchetto 27, schema v81. Iter scelto per bando (`tutorato_bandi.iter`) e per lettera in bozza (`tutorato_incarichi.iter`): `portale` (com'è) o `uniticket`. Uniticket: l'ufficio verifica la presa di servizio (`presa_servizio_il`) prima di poter inviare la lettera; lo studente compila la dichiarazione individuale (modello SCAFIS = modello N. 6) e accetta con SPID/CIE; alla conferma si salva il PDF SCAFIS con il riquadro dei metadati (`scafis_pdf`, sul NAS in `uploads/incarichi/`); firme docente e Direttore PAdES come prima; nella scheda «Da allegare su Uniticket» i due file (lettera firmata + SCAFIS) e il protocollo. Restano registro presenze e fine attività; i pagamenti solo nell'iter nel portale. Provato con 7 test PHPUnit (`IterUniticketTest`) e `strumenti/locale/collaudo_iter_uniticket.php` (16 controlli HTTP).|
|F12|File del portale (verbali, convenzioni, pratiche) sul NAS|**fatto in locale (da caricare); spostamento a cura dell'utente**|19/10/2026|Pacchetto 25: `strumenti/sposta_su_nas.sh` (prova, copia, controllo, collegamento, ritorno indietro) e controllo in Sistema (`ControlloSito::archiviSuNas`, avviso notturno agli amministratori). Cartelle da spostare: verbali, pratiche, convenzioni, convenzioni_compilate, convenzioni_tesi, autorizzazioni, incarichi, allegati_prenotazioni; restano sul server quelle pubbliche (personale, modulistica, modelli_convenzione, slide_home, loghi). Il NAS dell'utente: `/mnt/nas_nuovo/portale_didattica`. |Approccio senza modifiche al codice: le cartelle `uploads/…` diventano collegamenti alle cartelle del NAS montato (CIFS) sul server. Guida passo per passo: `documentazione/guida_file_sul_nas.md`. Poi, nel codice: controllo «NAS raggiungibile» in Sistema, backup che non scarica i file dal NAS, messaggio chiaro se il NAS è fuori linea. Da sapere prima: modello del NAS, IP, montaggio già usato per il backup, utente di Apache.|
|F5|Assistente IA interno per i verbali|**in stand-by**|||Su decisione dell'utente: non si fa per ora. La prova sul server (comandi in F5) resta pronta se si riprende|

**Stati**: `da fare` · `in corso` · `fatto in locale (da caricare)` · `in produzione`.
Per ogni step chiuso scrivere: data, file toccati, versione schema, cosa è stato provato, cosa va caricato sul server.

