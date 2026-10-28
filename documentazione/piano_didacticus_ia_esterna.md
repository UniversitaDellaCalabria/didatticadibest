# Piano — Didacticus con un servizio di IA esterno e bozze automatiche

> Nato il 28/10/2026. Il modello aperto in locale (H4) e gli embedding (H5) funzionano ma il server di produzione è troppo debole. Idea: lasciare il calcolo a un servizio esterno e tenere sul server solo la ricerca e il PHP.
> Stesso metodo degli altri piani: **uno step alla volta**, materiali prima di ogni step, Registro in fondo.

## 1. Cosa c'è già (H4/H5) e non va rifatto

| Pezzo | Dove | Stato |
|---|---|---|
| Elenco delle pagine di Ateneo da leggere | `admin/didacticus.php`, scheda «Pagine di Ateneo»; `PagineRepository` | c'è |
| Lettura e rilettura settimanale del testo | `LettorePagine`, `ServizioPagine::aggiornaScadute()`, `cron_background.php` | c'è |
| Ricerca dei brani più vicini alla domanda | `RicercaPagine`, `Motore` (regole) | c'è |
| Modello che risponde **solo con le fonti**, altrimenti `NON_SO`, poi torna alle regole | `ModelloLinguistico` (interfaccia OpenAI, `LLM_URL`) | c'è, ma pensato per Ollama locale |
| Significato delle domande | `Significato`, `ServizioSignificato` (`EMB_URL`) | c'è, pesante per il server |

Quindi la «ricerca sui siti indicati» esiste. **Manca solo spostare il modello fuori dal server** e, a parte, la generazione di nuove voci.

## 2. Decisioni da prendere (tue)

| # | Domanda | Perché conta |
|---|---|---|
| D1 | Si accetta che le domande degli studenti (senza nome né email) vadano a un fornitore esterno? Serve sentire il referente/DPO? | Finora la scelta H-1 era «nessun dato verso terzi». Cambia l'informativa privacy. |
| D2 | Chi paga la chiave API e con quale tetto mensile? | Si imposta un limite di spesa sul fornitore; con le sole domande «incerte» sono pochi centesimi ogni 100. |
| D3 | Il server 160.97.37.188 può uscire verso internet (HTTPS)? | Da provare con `curl` dal server prima di scrivere codice. |
| D4 | Elenco dei siti/pagine da indicare come fonti (oltre a quelli già nel pannello). | Più fonti buone = risposte migliori. |

## 3. Gli step

### I0 — Il brano della pagina, senza IA *(fatto in locale il 28/10/2026, nessuna modifica allo schema)*
* Se nessuna voce è sicura, `ServizioDidacticus::dallaPagina()` cerca nelle pagine di Ateneo già lette (`RicercaPagine`) e, se il brano ha almeno 70 punti su 100, lo riporta com'è (al massimo 600 caratteri) con «Ho cercato nelle pagine del sito di Ateneo e ho trovato questo nella pagina «…»», l'avviso «Testo preso dal sito» e fino a due link alle pagine. Nuovo tipo di risposta `pagina`, con «Ti è stato utile?» e il pulsante per l'operatore.
* Le voci scritte dal Dipartimento restano prima di tutto: il brano prevale sui «forse intendevi…» solo da 85 punti.
* Funziona anche con `LLM_URL` vuoto (il modello, se acceso, resta davanti). Prove: 825 superate (`PROVE_SOLO=assistenza_didacticus`).

### I0b — Leggere tutto unical.it e dibest.unical.it *(fatto in locale il 28/10/2026, schema v102)*
* **Crawler** `CrawlerSito` (dal cron notturno, a dosi): parte dalle pagine indicate nel pannello (di partenza `https://www.unical.it/` e `https://dibest.unical.it/`), segue i collegamenti interni di unical.it e dei sottodomini, rispetta `robots.txt` (oggi vieta solo gli indirizzi con `gAAAAAB`), una pagina al secondo, niente PDF né indirizzi con `?`, tetto di pagine (5.000 di partenza, fino a 20.000), profondità dei collegamenti (4), minuti per notte (20). Ogni passata riprende dove era arrivata; le pagine si rileggono dopo 7 giorni e i brani si rifanno solo se il testo è cambiato (impronta MD5).
* **Indice**: tabelle `didacticus_sito` (pagine: coda · letta · vuota · errore · esclusa), `didacticus_sito_brani` con indice `FULLTEXT` e `didacticus_sito_impostazioni`. `RicercaSito` sceglie i brani con una query (per prefisso delle radici delle parole), poi applica gli stessi pesi di `RicercaPagine`: niente più pagine caricate in memoria. Le 60 pagine a mano (`didacticus_pagine`) restano e vincono a parità di punteggio.
* **Pannello** (Domande e risposte › Pagine di Ateneo): interruttore, pagine di partenza, indirizzi esclusi, limiti, stato (pagine lette, brani, coda, errori), «Leggi per 30 secondi», «Svuota l'indice», ultimi errori.
* **Spenta di partenza**: dopo il caricamento sul server si accende dal pannello. Provata anche sui due siti veri (solo lettura): `dibest.unical.it` 6.800 caratteri e 119 collegamenti seguibili, `www.unical.it` 189.
* Prove: 843 superate (sito finto con robots.txt, PDF, altri domini, indirizzi con parametri, pagine escluse, rilettura dei soli cambiamenti, ricerca FULLTEXT). Le prove hanno trovato un errore, già corretto: una pagina rimasta senza testo e poi tornata uguale non rifaceva i brani.
* Non letti: PDF e pagine che si compongono con JavaScript (segnate «senza testo»).

### I1 — Servizio esterno al posto di Ollama *(piccolo, nessuna modifica allo schema)*
* `ModelloLinguistico` impara a parlare con l'**API Messages di Claude** (`/v1/messages`, intestazioni `x-api-key` e `anthropic-version`) oltre all'interfaccia OpenAI già presente. Nuove variabili nel `.env.example`: `LLM_TIPO` (`openai` | `claude`), `LLM_CHIAVE`, `LLM_MODELLO`. Spento se `LLM_URL` è vuoto, come adesso.
* Il blocco «un calcolo alla volta» resta solo per il tipo locale: con un servizio esterno non serve e non rallenta il server.
* Tetto di sicurezza: massimo N chiamate al giorno (`LLM_MAX_GIORNO`) e per persona; oltre, si risponde a regole.
* Si riusano le stesse istruzioni (risposta solo dalle fonti, `NON_SO`) e il tag «preparata automaticamente».
* Informativa privacy (testo già variabile con `LLM_URL`): aggiunta la riga sul fornitore esterno. Non vanno mai nel testo inviato nome, email o dati della persona.
* Prova: `strumenti/locale/prova_didacticus_modello.php` sulle stesse 12 domande dell'H4 per confrontare con Llama 3.2.

### I2 — Significato delle domande senza pesare sul server
Gli embedding (H5) sono la parte che il server regge peggio. Tre strade, da scegliere dopo I1:
1. **Spegnerli** (`EMB_URL` vuoto) e lasciare regole + ricerca sulle pagine: zero costo.
2. **Embedding esterni** (un secondo fornitore: Anthropic non ne offre, serve per esempio Voyage). Altro fornitore, altra riga di privacy.
3. **Lasciare alla IA di I1 la scelta** tra le 3-5 voci candidate: più semplice, un solo fornitore, una chiamata in più solo nei casi incerti.
Mia preferenza: la 3.

### I3 — Bozze di nuove voci dalle pagine di Ateneo *(schema nuovo: tabella `didacticus_bozze`)*
* Dopo ogni rilettura settimanale, per le pagine **cambiate** il cron chiede alla IA di proporre domande e risposte, solo con il testo della pagina e con il link di origine.
* Le proposte finiscono in «Bozze da approvare» nel pannello: si vedono pagina, testo nuovo, voce proposta; pulsanti **Approva** (diventa voce), **Modifica**, **Scarta**. Mai pubblicazione automatica.
* Se una pagina cambia e una voce già approvata nasce da lì, il pannello avvisa «la fonte è cambiata, rivedi la voce» (utile per importi, scadenze, finestre).
* Il lavoro gira nel cron di notte, con tetto di chiamate, quindi non tocca gli studenti.

### I4 — Rifiniture
Statistiche (quante risposte con IA, quante `NON_SO`, costo stimato), guida per il server aggiornata (`guida_modello_server.md`), mappe aggiornate, pacchetto con `crea_pacchetto.sh`.

## 4. Ordine e rischi

`D1-D4 → I1 → (I2) → I3 → I4`. Dopo I1 il bot è già migliore; I3 è indipendente.
* **Errori della IA su scadenze e regolamenti:** per questo si risponde solo con fonti, con link, e le voci nuove passano dall'approvazione.
* **Sito di Ateneo che cambia struttura:** la pagina senza testo utile viene segnalata (già così in `ServizioPagine`).
* **Costi imprevisti:** tetto giornaliero nel codice più limite di spesa sul conto del fornitore.
* **Servizio esterno non raggiungibile:** Didacticus torna alle regole, come adesso con Ollama.

## 5. Registro di avanzamento

| Step | Cosa | Stato | Data | Note |
|---|---|---|---|---|
| I0 | Brano della pagina senza IA | **fatto in locale (da caricare)** | 28/10/2026 | `ServizioDidacticus::dallaPagina()`, tipo `pagina`; 825 prove superate |
| I0b | Crawler di tutto unical.it/dibest e indice FULLTEXT | **fatto in locale (da caricare)** | 28/10/2026 | Schema v102; `CrawlerSito`, `RicercaSito`, `SitoRepository`; spento di partenza, si accende dal pannello |
| I1 | Servizio esterno (Claude/OpenAI-compatibile) | da fare | | aspetta D1-D3 |
| I2 | Significato senza embedding locali | da fare | | |
| I3 | Bozze di voci dalle pagine | da fare | | |
| I4 | Statistiche, guida, mappe | da fare | | |
