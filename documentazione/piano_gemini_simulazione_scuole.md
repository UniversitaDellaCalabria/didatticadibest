# Piano — Gemini in Didacticus, gruppo «Scuole», simulazione di accesso

> Nato il 30/10/2026. Tre lavori, da fare **uno step alla volta** (metodo «materiali prima di ogni step», Registro in fondo).
> Il piano `piano_didacticus_ia_esterna.md` (I1) resta valido: questo lo sostituisce nella parte «servizio esterno» scegliendo Gemini.

## 0. Cosa c'è già (non si rifà)

| Pezzo | Dove | Note |
|---|---|---|
| Parte «R» del RAG (ricerca dei brani) | `Motore` (voci), `RicercaPagine`, `RicercaSito` (FULLTEXT su unical.it e dibest.unical.it) | c'è |
| Parte «G» (modello che risponde **solo con le fonti**, altrimenti `NON_SO`) | `ModelloLinguistico` + `ServizioDidacticus::generata()` | c'è, parla l'interfaccia OpenAI `chat/completions` |
| Gemini offre proprio quell'interfaccia | `https://generativelanguage.googleapis.com/v1beta/openai/` (`/chat/completions`, `/embeddings`), chiave in `Authorization: Bearer` | verificato su ai.google.dev il 30/10/2026 |
| Profili dell'area personale | `ProfiliPersona`, `Profili` (studente, docente, direttore, personale, gestore, amministratore, esterno) | «Scuola, azienda o ospite» è un profilo unico |
| Collegamento utente→scuola | `utenti.scuola_codice` (scritto da `PrenotazioneRepository`/`AreaPersonaleRepository`) | c'è, ma **non è un gruppo** |
| Gruppi di persone | tabella `ruoli` + `utenti.ruoli_secondari` (CSV) | si possono aggiungere gruppi nuovi, come Docenti/PTA/Altro (ids 6-8) |
| Simulazione di un altro utente | — | **non esiste**. In `admin/area_personale.php` si sceglie un profilo da modificare, ma non si "entra" come qualcuno |

Quindi per Gemini il lavoro è piccolo (chiave, intestazione, tetto di spesa, privacy). Il resto del piano è per scuole e simulazione.

## 1. Decisioni da prendere (tue)

| # | Domanda | Mia proposta |
|---|---|---|
| D1 | Le domande degli studenti (senza nome né email) vanno a Google: serve sentire il DPO? | Sì, una riga all'avv. Niger prima dell'accensione. Si usa **solo il piano a pagamento** (vedi guida): i contenuti non servono a migliorare i prodotti Google |
| D2 | Modello | `gemini-2.5-flash` (il più economico, stabile). Si prova anche `gemini-3.6-flash`; si cambia con una riga del `.env` |
| D3 | Tetto di spesa | Tetto **nel codice** (chiamate al giorno) più avviso di budget su Google Cloud |
| D4 | Chi intesta il conto Google Cloud (serve una carta)? | Da chiarire con l'Ateneo: se non si può usare una carta personale, serve un ordine/carta dipartimentale |
| D5 | Gruppo **Aziende**: lo facciamo come le Scuole? | Sì, stessa tecnica; le aziende si riconoscono da convenzioni di tesi/tirocinio o si assegnano a mano da Utenti |
| D6 | In simulazione si può **scrivere** (inviare moduli, prenotare) o solo guardare? | Solo guardare: ogni azione che modifica dati è bloccata, con avviso. Chiedere se serve un'eccezione |

## 2. Gli step

### S1 — Gruppo «Scuole» *(schema nuovo, piccolo)*
* Nuovo gruppo in `ruoli`: **«Scuole»** (e, se D5, **«Aziende»**), creato da `datiDiPartenza()` come gli altri.
* **Associazione automatica**: ogni utente con `utenti.scuola_codice` valorizzato riceve il gruppo in `ruoli_secondari`; nasce nello stesso punto in cui oggi si scrive `scuola_codice` (`PrenotazioneRepository`, `AreaPersonaleRepository`). Una sola funzione `assegnaGruppoScuola($utenteId)`.
* **Recupero dello storico**: la migrazione assegna il gruppo a tutti i docenti che hanno già una scuola.
* **Se una persona è già Unical** (studente/dipendente) e ha anche una scuola? Il gruppo si aggiunge comunque, non toglie nulla.
* **Profilo dell'area personale**: `Profili::SCUOLA` (e `AZIENDA`) separati da «esterno», che resta per gli ospiti. `ProfiliPersona::di()` lo riconosce dal gruppo. L'amministratore decide dal pannello quali riquadri vede una scuola (la tabella `area_riquadri` ha già i profili: i riquadri esistenti per «esterno» vanno copiati al profilo nuovo).
* **Utenti** (`admin/utenti.php`): il gruppo si vede e si può togliere/mettere a mano.
* Prove: utente con scuola → profilo scuola; senza scuola → esterno; migrazione ripetuta non duplica.

### S2 — «Entra come…»: simulazione dell'accesso *(schema: registro `simulazioni_accesso`)*
* Solo **amministratore** (ruolo 1). Pannello Utenti: pulsante «Entra come…» su ogni persona; in `admin/area_personale.php` il «Vedi come» diventa **scelta di una persona reale** oppure di una **persona-tipo** (scuola, azienda, studente, docente, direttore…, la prima persona di quel profilo).
* Come funziona: la sessione conserva l'identità vera (`simula_origine`) e prende `utente_id`, `utente_ruolo_id`, `utente_ruoli_secondari`, nome ed email della persona scelta, **letti dal database** (non dal login). Il portale funziona poi da sé, perché legge solo queste chiavi.
* **Barra fissa** in alto in tutte le pagine: «Stai vedendo il portale come *Nome Cognome (profilo)* — [Torna a te]». Uscita con un clic; scade da sola dopo 60 minuti; il logout la cancella.
* **Sola lettura** (D6): ogni richiesta POST/PUT/DELETE è bloccata con un messaggio, tranne l'uscita; niente email né push verso la persona simulata; i contatori «visto/letto» non cambiano.
* **Registro**: chi, come chi, quando, fino a quando (tabella + riga in `log_accessi`). Visibile da Sistema.
* **Sicurezza**: non si può simulare un altro amministratore; non si può concatenare (simulare da simulato); il token CSRF dell'uscita è separato.
* Prove: la persona simulata vede solo le sue tessere; la scrittura è bloccata; l'uscita ripristina l'admin; un non-admin non entra.

### G1 — Collegare Gemini *(nessuno schema; codice piccolo)*
* `ModelloLinguistico`: nuove variabili `.env`: **`LLM_CHIAVE`** (inviata come `Authorization: Bearer`), **`LLM_MAX_GIORNO`** (tetto di chiamate al giorno, contatore su file/DB) e **`LLM_TIPO`** (`locale` | `esterno`). Con `esterno` si toglie il blocco «un calcolo alla volta» e si limita il pensiero del modello (costo) con `reasoning_effort` basso.
* URL: `LLM_URL=https://generativelanguage.googleapis.com/v1beta/openai`, `LLM_MODELLO=gemini-2.5-flash`.
* La chiave sta **solo nel `.env` del server** (mai in Git, mai nel pannello, mai nei log).
* **Privacy per costruzione**: alla chiamata vanno solo la domanda e i brani pubblici delle fonti, mai nome, email, matricola, né la cronologia della chat. Un controllo toglie dalla domanda email, codici fiscali e numeri lunghi prima dell'invio.
* Se la chiamata fallisce o il tetto è finito: Didacticus risponde come oggi (voci → brano di pagina → operatore).
* Pannello Didacticus: scheda «Modello» con stato (collegato/non collegato), chiamate di oggi/mese, errori, **pulsante «Prova la connessione»**.
* Informativa privacy: riga sul fornitore (Google, piano a pagamento, solo il testo della domanda).
* Prove: sulle stesse 12 domande dell'H4 con una finta API; un controllo reale (a mano) quando c'è la chiave.

### G2 — Qualità del RAG
* Più brani e meglio scelti: si passano a Gemini i 5-6 migliori fra voci scritte dal Dipartimento e pagine di Ateneo, **senza ripetizioni** e con il link, ordinati per punteggio.
* Opzionale: **embedding di Gemini** (`gemini-embedding-001`, circa 0,15 $ per milione di token) al posto di `EMB_URL` locale: già compatibile, basta `EMB_URL`/`EMB_CHIAVE`. Si decide dopo aver visto G1.
* Risposta con **citazione obbligatoria**: sotto il testo i link delle fonti usate (già c'è `AVVISO_GENERATA`).
* Solo-login: i brani riservati non vanno mai al modello per chi non è entrato (già così nelle voci).

### G3 — Statistiche e bozze *(da piano I3/I4)*
* Quante risposte con il modello, quante `NON_SO`, costo stimato del mese, domande senza risposta.
* Bozze di voci dalle pagine cambiate, da approvare a mano (resta come in `piano_didacticus_ia_esterna.md`, I3).

## 3. Ordine

`S1 → S2` (indipendenti da Gemini) e `G1 → G2 → G3`. **Consiglio di cominciare da G1 e dalla chiave**, perché la chiave si ottiene in autonomia (guida sotto) mentre si fanno S1 e S2.

## 4. Quanto costa (prezzi del 30/10/2026, da ricontrollare sulla pagina Google)

Una domanda "incerta" manda a Gemini circa 1.500 token (istruzioni + fonti + domanda) e ne riceve circa 150.

| Modello | Prezzo per milione di token (ingresso / uscita) | Costo di una domanda | 1.000 domande |
|---|---|---|---|
| `gemini-2.5-flash` | 0,30 $ / 2,50 $ | circa 0,0008 $ | **circa 0,8 $** |
| `gemini-3.6-flash` | 0,75 $ / 3,75 $ fino al 31/12/2026, poi 1,50 $ / 7,50 $ | 0,0017 $ poi 0,0035 $ | 1,7 $ poi 3,5 $ |

* Quindi: **qualche euro ogni mille domande**, non "pochi centesimi per migliaia". Resta poco: Didacticus chiama il modello solo quando nessuna voce è sicura (modalità «incerto»).
* Attenzione ai **token di ragionamento**, che si pagano come uscita: per questo G1 li limita.
* Sul piano gratuito i contenuti **servono a migliorare i prodotti di Google**: per domande di studenti si usa solo il piano a pagamento.

## 5. Rischi

* **Risposte sbagliate su scadenze e importi:** solo fonti, citazione dei link, avviso «preparata automaticamente», `NON_SO` quando non basta.
* **Spesa fuori controllo:** tetto giornaliero nel codice + avviso budget su Google Cloud (l'avviso **non** blocca da solo).
* **Chiave esposta:** solo `.env` del server; se trapela si revoca da Google AI Studio e se ne crea un'altra.
* **Modelli che cambiano nome:** il modello sta in `LLM_MODELLO`, non nel codice.
* **Simulazione:** può mostrare dati personali a chi non dovrebbe: solo amministratori, in sola lettura, con registro.

## 6. Registro di avanzamento

| Step | Cosa | Stato | Data | Note |
|---|---|---|---|---|
| S1 | Gruppo Scuole e Aziende | **fatto in locale (da caricare)** (Aziende: `GruppoAziende`, profilo `azienda` dall'email di `convenzioni_tesi` o dal gruppo dato a mano; «Ospite» resta per gli altri; base comune `GruppoPersone`; 1741 prove) | 30/10/2026 | Schema v104. `App\Auth\GruppoScuole` (crea il gruppo «Scuole» in `ruoli`, lo dà quando si scrive `utenti.scuola_codice`, lo toglie se la scuola si cancella, storico alla migrazione). Nuovo profilo `Profili::SCUOLA` (da scuola o da gruppo); «Azienda o ospite» resta per gli altri. I riquadri degli esterni passano anche alle scuole. Suite: 1735 prove superate |
| S2 | Entra come… | **fatto in locale (da caricare)** | 30/10/2026 | `App\Auth\SimulazioneAccesso`, guardia `inc/simulazione_guardia.php` (caricata da `config.php`), barra `inc/barra_simulazione.php` nei due piè di pagina, `simula_esci.php`, pagina `admin/simula.php` (voce «Entra come…» in Sito pubblico, ricerca e un esempio per profilo) e pulsante nella scheda di Utenti. Sola lettura (D6: solo guardare), un'ora, non si simula un altro amministratore; inizio e fine nel registro delle operazioni (non serve una tabella nuova). Provato anche a mano sul portale locale. Suite: 1764 prove superate. Limiti: vedi sotto |

Limiti di S2 noti: la guardia ferma POST e indirizzi con un'azione (`csrf*`, `conferma*`, `annulla*`, `elimina*`…), ma una pagina che modifica qualcosa con un normale GET senza questi nomi passerebbe; alcune pagine aggiornano da sole segni di lettura (messaggi letti, ultimo accesso) anche in visione; le chat di assistenza in simulazione non si possono usare (sono POST).
| G1 | Collegare Gemini | **fatto in locale (da caricare)** | 30/10/2026 | `ModelloLinguistico`: `LLM_CHIAVE` (Bearer), `LLM_MAX_GIORNO` (300), `LLM_RAGIONAMENTO`, conteggi in `cache/didacticus_llm_uso.json`, domanda ripulita da email/CF/telefoni, «Prova la connessione» nel pannello (scheda Pagine di Ateneo), riga nella privacy. Provato con un finto servizio Gemini (16 prove) e nella suite (784 superate). **Manca la prova con la chiave vera** |
| G2 | Qualità del RAG | da fare | | |
| G3 | Statistiche e bozze | da fare | | |
