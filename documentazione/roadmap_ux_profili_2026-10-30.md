# Roadmap tecnica: ogni profilo arriva a ciò che deve fare con un clic (30/10/2026)

Analisi del codice del portale (area personale, «Da fare per te», firme, tutorato, FSL, convenzioni di tesi, pratiche) dal punto di vista di chi lo usa:
scuole, aziende, studenti, docenti, tutor, Direttore. Completa `analisi_portale_2026-10-30.md` (performance della home, file esposti, backup, menu),
che qui non si ripete.

**Come è stata fatta:** lettura del codice locale (area personale e riquadri, `DaFarePersona`, `CruscottoDirettore`, pagine di firma, tutorato,
FSL, convenzioni di tesi, pratiche, testata, accesso). **Non** ho provato i percorsi sul sito vero con «Entra come», né misurato i tempi: i conteggi dei clic
sono ricavati dai collegamenti nel codice. Ogni affermazione ha il file e la riga da cui viene.

---

## Regole per chi implementa (da leggere prima di ogni punto)

1. **PHP 8.2.** La logica nuova va in `src/` (classi `final`, `declare(strict_types=1)`, PHPStan livello 8); le pagine in radice restano sottili e chiamano i servizi.
2. **Schema del database:** nessuna pagina esegue `ALTER`/`CREATE`. Tabelle e colonne nuove solo in `src/Core/Schema/Definizioni.php` + `Migrazioni.php`
   (oggi `VERSIONE = 106`: ogni punto che tocca lo schema la incrementa di 1).
3. **Permessi:** mai fidarsi di un dato modificabile dall'utente per decidere chi può firmare/approvare (vedi Fase 0). CSRF su ogni POST (`csrf_field()`/`csrf_verify()`).
4. **Riquadri dell'area personale:** riquadro di sistema = voce in `CatalogoRiquadri::sistema()` + file `inc/area/<chiave>.php` (un test verifica la corrispondenza).
   Stile: `area_tessera()`, `area_icona()`, `area_riga()` in `inc/area/_tessera.php`, colori di stato da `assets/css/portale-ui.css` / `inc/ui.php`.
5. **Vecchi indirizzi:** i link già partiti per email (`?t=token`) devono continuare a funzionare.
6. **Esportazioni:** ogni elenco scaricabile in Excel + PDF + CSV (`TabellaEsportabile`, `pn_scarica`).
7. **Prove:** per ogni punto una prova in `tests/Unit` o `tests/Integration` e, per le pagine, in `strumenti/prove/oop/` (`php -d extension=zip -d extension=gd -d extension=intl strumenti/prove/esegui.php`, `PROVE_SOLO=…`).
8. **File senza BOM**, testi in italiano semplice, nessuna attribuzione a Claude nei commit.
9. **Prima di costruire una schermata nuova**, mostrarne la bozza all'utente (metodo «materiali prima di ogni step», `piano_nuove_feature.md`).

Formato di ogni punto: **Problema** (con prova) · **Impatto** · **Soluzione logica** · **Requisiti tecnici** · **Fatto quando** · **Peso** (S = mezza giornata, M = 1-2 giorni, L = 3+ giorni).

---

## Quadro per profilo: oggi e obiettivo

| Profilo | Compito | Percorso oggi | Problema | Obiettivo | Punti |
|---|---|---|---|---|---|
| Scuola | Caricare la convenzione FSL firmata | Area personale → «Da fare» (solo se ha l'email) → riga; altrimenti → icona «Eventi e attestati» → 4ª scheda «Programma FSL» → «Carica la convenzione firmata» | Il nome della scheda non dice «convenzione»; senza email non c'è «Da fare» | 1 clic dalla tessera «La mia scuola» | 1.1, 3.S1 |
| Scuola | Elenco studenti + autorizzazione prima dell'attività | Eventi → card della prenotazione → «Carica elenco e autorizzazione» | In «Da fare» c'è solo l'elenco **dopo** l'attività; l'autorizzazione prima dell'inizio non compare | In «Da fare» con la scadenza | 1.2, 3.S1 |
| Scuola | Costruire il programma FSL in più giorni | Pulsante nella testata | Il programma è nella **sessione**: si perde uscendo o cambiando dispositivo | Programma salvato per persona | 3.S2 |
| Azienda | Convenzione di tesi | Solo il link dell'email | L'area personale dell'azienda non mostra la convenzione; link perso = ricominciare | Tessera «Le convenzioni della tua azienda» | 3.A1, 3.A2 |
| Studente | Compilare un modulo | Menu → Modulistica | 0 moduli pubblicati; la tessera «Le mie pratiche» compare solo a chi ha già una pratica | Tessera sempre visibile con «Nuova richiesta» | 3.St3 |
| Studente | Tesi in un'azienda non convenzionata | La pratica dice «l'azienda può chiederla da una pagina pubblica» | Lo studente non può invitare l'azienda; l'abbinamento convenzione-pratica è per somiglianza del nome | Pulsante «Invita l'azienda» nella pratica | 3.St2 |
| Docente | Confermare gli attestati di N studenti | Da fare → riga → pagina → conferma (×N) | Una pagina per studente, nessuna conferma in blocco, nessuno storico | Elenco con conferma delle righe scelte | 2.5, 2.6 |
| Direttore | Firmare | Da fare / Cruscotto → riga → pagina di firma → utente, password, **OTP per ogni documento** | 5 pagine di firma diverse; le **lettere di tutorato da firmare non compaiono** né in «Da fare» né nel Cruscotto | Scrivania firme unica, firma di più documenti con una OTP | 2.1-2.4 |
| Tutor | Accettare l'incarico (lettera + dichiarazione) | Solo dal link dell'email | «Il mio tutorato» mostra il «prossimo passo» ma senza pulsante | Pulsante sul passo | 3.T1 |
| Tutor | Compilare il registro | Area → tessera Registro → modulo | Buono; manca modifica della riga, inserimento rapido, promemoria automatico. Dottorandi/personale tutor non vedono le tessere | Registro più rapido, tessere a tutti | 3.T2, 3.T3 |
| Tutti | Sapere che c'è qualcosa da fare | Entrare in Area personale (nascosta nel menu a tendina utente) | Dopo l'accesso si va in home; nessun numero nella testata | Campanella con numero in ogni pagina, accesso → area personale | 4.1, 4.2 |

**Cosa funziona già bene (non toccare):** il cruscotto a tessere con profili configurabili dal pannello; «Il mio lavoro» per chi gestisce; le pagine di firma
che accettano firma remota Aruba o caricamento PAdES con verifica che il documento non sia cambiato; il registro del tutorato con approvazione in blocco
lato docente; bozze delle pratiche; percorsi a passi per convenzione FSL e convenzione di tesi; riepilogo settimanale «Da fare» via email.

---

## FASE 0 — Identità: chi è davvero la persona (bloccante, prima di tutto il resto)

### 0.1 L'email si cambia senza verifica e dà i permessi di un'altra persona — **CRITICO**

> **Stato 30/10/2026: FATTO in locale (Pacchetto 43, schema v107), da caricare.** Fatta la parte che chiude la falla: la nuova email vale solo dopo il
> codice di 6 cifre inviato a quell'indirizzo (`src/Auth/VerificaEmail.php`, `profilo.php` in due passi, avviso al vecchio indirizzo, tolte
> `aggiorna_email_utente()`/`ServizioUtenti::aggiornaEmail()`), più lo script `strumenti/controllo_email_personalizzate.php` per le email scelte prima
> (punto 4 qui sotto). Con la verifica, chi riceve i compiti di un'email ne controlla davvero la casella, quindi i confronti per email restano validi.
> **Non fatto** (resta per Sonnet, non è più urgente): il servizio unico `Identita` e la separazione email di identità / email per gli avvisi (punti 1 e 3),
> utili per 0.2 e 1.1. Prove: `tests/Integration/Auth/VerificaEmailTest.php`, `strumenti/prove/oop/auth.php`.

- **Problema.** In `profilo.php:14-23` chiunque imposta una nuova email; `ServizioUtenti::aggiornaEmail()` (`src/Auth/ServizioUtenti.php:18`) controlla solo che
  non sia già di un altro *account* (`UtenteRepository::emailUsataDaAltri`, riga 79) e la salva con `email_personalizzata = 1` (riga 85), senza conferma.
  Molte autorizzazioni si basano **solo sull'email**:
  - `ServizioConfermeDocenti::puoConfermare()` (`src/Didattica/ServizioConfermeDocenti.php:251`): conferma degli attestati;
  - `FirmaPratica::puoFirmare()` (`src/Didattica/FirmaPratica.php:168`): firma del relatore;
  - `ServizioIncarichi::firmatario()` (`src/Tutorato/ServizioIncarichi.php:595`): docente responsabile del tutorato (→ `registro_tutorato.php` approva le ore);
  - `DaFarePersona::gruppi()` (`src/Portale/DaFarePersona.php:34`) mostra i link con token di tutte le cose legate a quell'email.
- **Impatto.** Uno studente che mette nel profilo l'email di un docente che non ha ancora un account nel portale (o che non ha l'email salvata: 143 utenti su 439)
  vede in «Da fare per te» le richieste rivolte al docente e può confermare la frequenza del proprio attestato o approvare le ore di un tutor. Lo stesso vale
  per un tutor con l'email del proprio docente responsabile.
- **Soluzione logica.**
  1. Separare **email di identità** (arriva dal login di Ateneo/SPID, non modificabile) da **email per gli avvisi** (scelta dalla persona).
  2. L'email per gli avvisi vale solo dopo la **verifica**: codice di 6 cifre o link inviato al nuovo indirizzo, valido 30 minuti, massimo 5 tentativi.
  3. Tutte le decisioni «puoi firmare / confermare / approvare / vedere questo token» usano un solo servizio `Identita` che confronta, in quest'ordine,
     codice fiscale, `persona_id` dell'anagrafe, email di identità, email per gli avvisi **verificate**. Mai l'email non verificata.
  4. Controllo una tantum dei dati esistenti: elenco degli utenti con `email_personalizzata = 1` la cui email coincide con un docente, un firmatario o un referente
     (docenti dei tutorati, firmatari delle pratiche, conferme, uffici): da far controllare all'amministratore.
- **Requisiti tecnici.**
  - Schema: in `utenti` colonne `email_sso` (VARCHAR, scritta solo da `sync_sso_user`), `email_avvisi`, `email_avvisi_verificata_il` (DATETIME NULL);
    tabella `verifiche_email` (utente_id, email, codice_hash, scade_il, tentativi). Migrazione: `email_sso` = email attuale dove `email_personalizzata = 0`.
  - `src/Auth/Identita.php` (nuovo): `corrisponde(array $utente, ?string $email, ?string $cf, ?string $personaId): bool` e `emailAffidabili(array $utente): list<string>`.
  - Sostituire i confronti di email nei tre punti sopra, in `ServizioConvenzioniTesi::puoFirmare`, `ServizioFirmaDirettore::puoFirmare`, `firmatario_verbale`
    (`inc/sedute.php`) e in `DaFarePersona` con `Identita`. Cercare `=== strtolower(` e `LOWER(email) = ?` per trovare gli altri.
  - `profilo.php`: due passi (inserisci email → inserisci il codice). Rate limit con `check_rate_limit`.
  - Script `strumenti/controllo_email_personalizzate.php` (solo CLI) per il punto 4.
- **Fatto quando.** Una prova di integrazione dimostra che un utente con email personalizzata non verificata uguale a quella di un docente non vede né può usare
  conferme, firme e registri del docente; con il codice verificato le notifiche arrivano alla nuova email ma i permessi restano legati a CF/persona.
- **Peso.** M.

### 0.2 Docenti con più indirizzi (alias) non riconosciuti

> **Stato 30/10/2026: FATTO in locale (schema v108), da caricare.** Firme e conferme delle pratiche salvano `persona_id` del docente scelto nell'anagrafe;
> `puoFirmare`/`puoConfermare` accettano anche la stessa scheda (`src/Auth/Identita::stessaScheda`). L'anagrafe non ha il codice fiscale, quindi il confronto è per `persona_id`.
> Le richieste già create prima della v108 non hanno `persona_id` e restano valide solo per email. Non toccato: «Da fare per te» cerca ancora per email.
- **Problema.** Stessi confronti per uguaglianza esatta dell'email: un docente registrato in un modulo come `nome.cognome@unical.it` che entra con un alias
  diverso non può confermare (`ServizioConfermeDocenti.php:255`).
- **Soluzione.** In `Identita` accettare anche l'email di Ateneo presente nell'anagrafe persone (`PersonaRepository`) per lo stesso `persona_id`/CF; quando si
  crea una richiesta (conferma, firma, incarico) salvare anche `persona_id` e CF del destinatario se noti, non solo l'email.
- **Requisiti.** Colonne `persona_id`/`cf` nelle tabelle delle richieste che oggi hanno solo l'email (`pratiche_conferme`, `pratiche_firme`); valorizzarle alla
  creazione dalla scelta del docente nell'anagrafe (già fatta in `controlla_docenti_modulo`).
- **Fatto quando.** Prova: richiesta creata per l'email A, docente entra con email B ma stesso CF → può confermare. **Peso.** S (dopo 0.1).

---

## FASE 1 — «Da fare per te» completo, per tutti, visibile ovunque

### 1.1 «Da fare» è vuoto per chi non ha l'email

> **Stato 31/10/2026: PARZIALE.** Per ora solo il tutor senza email vede «Incarico da accettare» (per codice fiscale); le altre fonti restano per email: serve ancora `IdentitaPersona`.
- **Problema.** `DaFarePersona::gruppi()` restituisce `[]` se l'email è vuota (`src/Portale/DaFarePersona.php:36-38`). Tutte le fonti cercano per email.
- **Impatto.** Il 32% degli utenti (quasi tutti studenti) non vede nessun compito, anche quelli legati al codice fiscale (tutor).
- **Soluzione.** Le fonti ricevono l'oggetto `Identita` (email affidabili + CF + persona_id + utente_id) e cercano per tutto ciò che hanno.
- **Requisiti.** Dipende da 0.1. Cambiare la firma dei metodi privati di `DaFarePersona` da `string $email` a `IdentitaPersona $id`.
- **Fatto quando.** Prova: tutor senza email con incarico in stato `inviata` vede «Accetta l'incarico». **Peso.** S.

### 1.2 Compiti che mancano in «Da fare» (il punto più importante per «un clic»)

> **Stato 31/10/2026: FATTE 3 fonti su 9, in locale (nessuna modifica allo schema):** lettere di tutorato da firmare per il Direttore (anche nel Cruscotto, gruppo `tutorato_firma`) e incarico di tutorato da accettare per il tutor.
> Restano: convenzioni FSL da firmare nel «Da fare» del Direttore, registro fermo/fine attività, pratiche con integrazione, posto liberato, sondaggi, scuola (autorizzazione), azienda. Il registro delle fonti (`FonteDaFare`) non è ancora stato fatto.
- **Problema.** Le 12 fonti di `DaFarePersona` (riga 41) coprono soprattutto chi **firma**; mancano i compiti di chi **fa la richiesta** e alcuni del Direttore.
  Mancanti verificati nel codice:

  | Chi | Compito mancante | Dove si trova oggi | Dati |
  |---|---|---|---|
  | **Direttore** | Lettere di incarico del tutorato da firmare (stato `firmata_docente`) | solo email (`ServizioRegistroTutorato.php:425`); **assenti anche dal Cruscotto** (`CruscottoDirettore.php:55` non ha la voce) | `tutorato_incarichi.stato='firmata_docente'`, `token_direttore` |
  | **Direttore** | Convenzioni FSL da firmare | solo nel Cruscotto (`CruscottoDirettore.php:116`) | come `convenzioniFirma` del Cruscotto |
  | Tutor | Accettare l'incarico e compilare la dichiarazione individuale | solo email (`incarico.php`, `dichiarazione_tutorato.php`) | `stato='inviata'`, `codice_fiscale`, `token_studente` |
  | Tutor | Registro aperto senza attività da 14 giorni / dichiarare la fine a periodo concluso | nessun avviso automatico | `tutorato_registro`, `periodo` |
  | Studente | Pratica con integrazione richiesta / autodichiarazione | solo numero sulla tessera Pratiche (`inc/area/pratiche.php`) | `pratiche.stato='integrazione'` |
  | Studente/tutti | Posto liberato da confermare (con scadenza) | numero sull'icona Eventi | `prenotazioni.stato='richiesta_conferma'` |
  | Tutti | Sondaggio post-evento da compilare | scheda Sondaggi di `miei_eventi.php` | sondaggi attivi non compilati |
  | Scuola | Elenco studenti e autorizzazione **prima** dell'attività | pulsante nella card (`miei_eventi.php:115-122`) | `ServizioDocumentiClasse::consegnaAperta()/stato()` |
  | Azienda | Convenzione di tesi da compilare, da ricaricare dopo rifiuto, risposta alle modifiche | solo email | `convenzioni_tesi.stato IN ('invitata','compilata','risposta','rifiutata')` + email affidabile |

- **Soluzione logica.** Trasformare `DaFarePersona` da elenco fisso di metodi a **registro di fonti**: ogni fonte è una classe piccola che risponde
  «quanti e quali compiti ha questa persona», con titolo, link diretto all'azione, data di richiesta e **scadenza** se esiste. Aggiungere le 9 fonti sopra.
  Ogni fonte decide da sola se si applica (nessun costo per chi non ha quel ruolo).
- **Requisiti tecnici.**
  - `src/Portale/DaFare/FonteDaFare.php` (interfaccia: `chiave()`, `gruppo(IdentitaPersona): ?GruppoDaFare`), `GruppoDaFare` e `CompitoDaFare`
    (testo, dettaglio, link, giorni, `scadenza: ?DateTimeImmutable`, `urgente: bool`) come value object.
  - Spostare i 12 metodi esistenti in 12 classi fonte senza cambiarne le query; registrarle nel container (`src/Portale/Registrazione.php`).
  - `CruscottoDirettore`: aggiungere il gruppo «Lettere di tutorato da firmare» (riusare la fonte).
  - `candidati()` (riga 66) va esteso alle nuove fonti, altrimenti il riepilogo settimanale non le include.
- **Fatto quando.** Una prova per fonte (dati minimi → compito atteso, persona estranea → niente); il Cruscotto mostra le lettere del tutorato.
- **Peso.** L (si può spezzare: prima le due del Direttore e quelle del tutor, poi il resto).

### 1.3 «Da fare» mostra 4 righe per gruppo e poi «… e altre N» senza link

> **Stato 31/10/2026: FATTO in locale.** Pagina `da_fare.php` (filtro per gruppo con `?g=`, fino a 200 righe per gruppo, prima ciò che aspetta da più giorni); in area personale «… e altre N: vedi tutte» e «Vedi tutto l'elenco». Manca l'ordinamento per scadenza (arriva con 1.5).
- **Problema.** `inc/area/da_fare.php:22` (`array_slice(..., 0, 4)`) e riga 27: il resto non è raggiungibile.
- **Soluzione.** Pagina `da_fare.php` con l'elenco completo: filtri per gruppo, ordinamento per scadenza poi per giorni di attesa, pulsante d'azione su ogni riga.
  Nell'area personale «… e altre N» diventa un link a `da_fare.php#<gruppo>`.
- **Requisiti.** Pagina sottile su `DaFarePersona`; stile `portale-ui.css`; esportazione non necessaria. **Peso.** S.

### 1.4 Numero dei compiti visibile in ogni pagina

> **Stato 31/10/2026: FATTO in locale.** Pulsante «Da fare N» accanto al nome nella barra (e voce nel menu mobile) solo quando N > 0; `ContatoreDaFare` lo tiene 5 minuti in sessione per persona; ogni POST in una pagina protetta (`middleware.php`) lo azzera. Non toccato l'aspetto della testata. Il pulsante «Area personale» resta nel menu utente.
- **Problema.** La testata (`header.php:368-381`) non mostra nulla; «Area Personale» è dentro il menu a tendina dell'utente.
- **Soluzione.** Pulsante «Area personale» sempre visibile nella barra in alto con un **numero rosso** = totale «Da fare»; su telefono nella barra mobile.
  Il numero si calcola una volta per sessione e si aggiorna ogni 5 minuti o dopo un'azione (valore in sessione con orario).
- **Requisiti.** `DaFarePersona::totale()` con memorizzazione in `$_SESSION['da_fare_n']` + `['da_fare_t']`; invalidare dopo ogni POST che chiude un compito
  (helper `da_fare_aggiorna()` chiamato dalle pagine di firma/conferma). Non toccare l'aspetto della testata (decisione dell'utente): solo un elemento nella barra utente.
- **Fatto quando.** Il numero compare su tutte le pagine pubbliche per un utente con compiti e scende subito dopo una firma. **Peso.** S.

### 1.5 Scadenze ben visibili
- **Soluzione.** Ogni compito con scadenza mostra «entro il gg/mm» e, se mancano ≤ 3 giorni, è in cima e rosso; oltre la scadenza resta con «scaduto».
- **Requisiti.** Campo `scadenza` di 1.2; ordinamento in `DaFarePersona`. **Peso.** S.

### 1.6 Notifiche sul telefono (facoltativo, dopo 1.2)
- **Soluzione.** Riutilizzare le notifiche push già fatte per l'assistenza (`src/Assistenza/Push`) per un avviso quando nasce un compito urgente (firma, posto liberato).
  Interruttore nel profilo. Serve anche a chi non ha email.
- **Peso.** M.

---

## FASE 2 — Firme del Direttore e approvazioni dei docenti

### 2.1 Una sola «scrivania delle firme»
- **Stato (31/10/2026): passo 1 fatto** — `src/Firme/`: cinque adattatori (`FirmePratiche`, `FirmeConvenzioniFsl`, `FirmeConvenzioniTesi`, `FirmeTutorato`, `FirmeVerbali`) dietro l'interfaccia `FonteFirme` e `ScrivaniaFirme` che li unisce; riusano le regole di ogni modulo su chi può firmare; prova `firme_scrivania.php`. **Passo 2 fatto**: pagina `firme.php` (elenco, filtri per tipo, anteprima PDF laterale, «Firma →» alla pagina del modulo), tessera «Da firmare» con numero rosso (riquadro di sistema `firme`, solo a chi ha qualcosa) e «Firma ora (N)» nel Cruscotto. **Passo 3 fatto**: caselle di selezione e «Scarica i selezionati (ZIP)» (`PacchettoFirme`, un PDF per documento con nome `<tipo>_<id>_<codice>.pdf`). **Passo 4 fatto**: «Carica i firmati» (`RicaricaFirmati`: PDF singoli, più file o ZIP; abbinamento dal nome; stessa verifica del modulo; esito file per file; ogni documento porta la sua funzione di registrazione). **Passo 5 fatto (2.5)**: `mie_conferme.php` con `ConfermeInBlocco` (conferme degli attestati del docente tutte in una pagina, più richieste con una sola dichiarazione, passo successivo dell'iter quando sono arrivate tutte; il rifiuto resta richiesta per richiesta). **Passo 6 fatto (2.6)**: in `firme.php` la sezione «Fatte negli ultimi 12 mesi» (`StoricoFirme`: firme e conferme date, in tutti i moduli, dalla più recente). **La Fase 2 è completa.**
- **Problema.** Cinque pagine di firma con codice simile ma separato: `firma_pratica.php`, `firma_convenzione.php`, `firma_convenzione_tesi.php`, `firma_incarico.php`
  (lettera e fine attività), `firma_verbale.php`. Si arriva a ognuna da un link diverso; il Cruscotto (`cruscotto_direttore.php`) è «solo lettura».
- **Soluzione logica.** Pagina `firme.php` («Da firmare») per chiunque firmi (Direttore, vice, relatori, docenti del tutorato, segretari e coordinatori):
  elenco di tutti i documenti in attesa della **sua** firma, con anteprima del PDF in un pannello laterale, casella di selezione e due modi:
  «Firma i selezionati con la firma remota» (2.2) o «Scarica i selezionati / Carica i firmati» (2.3). Le pagine attuali restano per i link delle email.
- **Requisiti tecnici.**
  - Interfaccia `src/Firme/DocumentoDaFirmare.php`: `tipo()`, `id()`, `titolo()`, `pdfCorrente(): string`, `spazioFirma(): ?array`, `motivo(): string`,
    `puoFirmare(IdentitaPersona): bool`, `registraFirma(string $pdfFirmato, string $come): ?string`.
  - Adattatori che **richiamano i servizi esistenti** (nessuna logica duplicata): `FirmaPratica`, `ServizioFirmaDirettore` (FSL), `ServizioConvenzioniTesi`,
    funzioni del tutorato (`registra_firma_incarico`, `registra_firma_fine`), sedute (`registra_firma_verbale`).
  - `src/Firme/ScrivaniaFirme.php`: raccoglie i documenti dagli adattatori per la persona (stesse query delle fonti di 1.2).
  - Riquadro di sistema `firme` (forma icona, numero rosso) per Docente, Direttore, Personale, Amministratore; il Cruscotto del Direttore ha in cima «Firma ora (N)».
- **Fatto quando.** Il Direttore vede in una pagina pratiche, convenzioni FSL, convenzioni di tesi, lettere di tutorato e verbali che aspettano lui, e ne firma uno
  senza cambiare pagina. **Peso.** L.

### 2.2 Firma di più documenti con una sola OTP
- **Problema.** `FirmaRemotaAruba::firma()` (`src/Tutorato/FirmaRemotaAruba.php:32`) firma un documento per chiamata con utente, password e OTP: per 10 documenti il
  Direttore genera 10 OTP.
- **Soluzione logica.** Il servizio Aruba ARSS prevede una **sessione di firma** (apertura con credenziali + una OTP, firme multiple con l'identificativo di sessione,
  chiusura). **Da verificare sul manuale ARSS del contratto di Ateneo** (operazioni `opensession`/`closesession` e firma PAdES con sessione); se non è attiva per il
  dominio `ARUBA_ARSS_DOMINIO`, chiederla ad Aruba/all'Ateneo. Il portale apre la sessione, firma i documenti uno per volta (una richiesta AJAX per documento,
  per non superare i tempi del server), mostra l'avanzamento e chiude la sessione anche in caso di errore.
- **Requisiti tecnici.**
  - Estendere `FirmaRemotaAruba` con `apriSessione()`, `firmaInSessione()`, `chiudiSessione()`; l'identificativo di sessione resta solo in memoria di sessione PHP,
    mai su disco; password e OTP mai salvate né scritte nei log.
  - Endpoint `firme_ajax.php` (POST + CSRF): `apri`, `firma` (un documento), `chiudi`; esito per documento (firmato / errore con motivo).
  - Idempotenza: se un documento risulta già firmato (stato cambiato) si salta. `check_rate_limit` come oggi.
  - Ripiego: se la sessione non è disponibile, la pagina chiede l'OTP per ogni documento (comportamento attuale).
- **Fatto quando.** Prova con un finto client Aruba (doppio in `tests/Doppi`): 5 documenti, uno in errore → 4 firmati, 1 con motivo, sessione chiusa.
- **Peso.** M (dopo 2.1), più il tempo della verifica con Aruba.

### 2.3 Firma «fuori dal portale» in blocco
- **Soluzione.** «Scarica i selezionati» produce uno ZIP con nomi file univoci (`<tipo>_<id>_<codice>.pdf`); chi firma con ArubaSign/Dike firma tutti i file insieme
  e ricarica i PDF firmati (più file o uno ZIP). Il portale abbina ogni file al documento dal nome e lo controlla con la verifica già esistente (stesso PDF + una firma).
- **Requisiti.** `ZipArchive` (già usato); limite di dimensione per file come oggi (20 MB); risposta con l'esito file per file. **Peso.** M.

### 2.4 Cruscotto del Direttore operativo
- **Problema.** `cruscotto_direttore.php` è dichiaratamente «solo lettura» (riga 2), non ha le lettere del tutorato (vedi 1.2) e mostra «Pagamenti del tutorato fermi»
  anche quando i pagamenti sono spenti dal pannello.
- **Soluzione.** In testa il pulsante «Firma ora (N)» → `firme.php`; gruppo lettere del tutorato; nascondere il gruppo pagamenti se il riquadro
  `tutorato_pagamenti` è spento; dopo l'accesso il Direttore arriva sul Cruscotto (vedi 4.1).
- **Peso.** S.

### 2.5 Conferme dei docenti sugli attestati in blocco
- **Problema.** `conferma_attestato.php` lavora su un token per volta; un docente con 30 studenti apre 30 pagine.
- **Soluzione logica.** Pagina `mie_conferme.php`: tutte le conferme in attesa per la persona, raggruppate per insegnamento, con le righe che la riguardano
  (`ServizioConfermeDocenti::righeDelDocente`) già visibili; seleziona → una sola dichiarazione → «Conferma le selezionate»; il «Non confermo» resta riga per riga con il motivo.
  Dopo ogni conferma si richiama la stessa logica di avanzamento dell'iter di `conferma_attestato.php` (spostarla in un metodo del servizio se ora è nella pagina).
- **Requisiti.** Spostare in `ServizioConfermeDocenti` il blocco «se tutte confermate → passo successivo» oggi nella pagina (`conferma_attestato.php` dopo riga 27);
  registro operazioni per ogni pratica. **Peso.** M.

### 2.6 Storico di ciò che ho firmato/confermato
- **Problema.** Una volta firmato, il compito sparisce: il docente non ritrova cosa ha confermato o firmato.
- **Soluzione.** Scheda «Fatte» in `firme.php` e `mie_conferme.php` (ultimi 12 mesi) con data, documento e link al PDF firmato se la persona può vederlo. **Peso.** S.

---

## FASE 3 — Percorsi per profilo

### Scuole

**3.S1 Tessera «La mia scuola — Formazione Scuola Lavoro»** (M)

> **Stato 31/10/2026: passo 1 fatto.** `src/Fsl/PercorsoScuola.php` (regole pure, prova `fsl_percorso_scuola.php`): per ogni prenotazione i passi programma → prenotazione → convenzione → elenco e autorizzazione (solo se l'attività li chiede) → attività → attestati, con l'unico pulsante del passo corrente. **Passo 2 fatto:** `src/Fsl/ServizioPercorsiScuola.php` raccoglie per la persona le prenotazioni FSL (prenotazioni, documenti della classe, convenzione e link personale), prima quelle con qualcosa da fare; le finite da più di 180 giorni non compaiono. **Passo 3 fatto:** riquadro di sistema `fsl_scuola` (profili Scuola e Amministratore, ordine 14; compare con lo schema v110 alla semina dei riquadri) disegnato da `inc/area/fsl_scuola.php`, con la barra a passi (`.ui-passi` in `portale-ui.css`) e un solo pulsante per prenotazione; la scheda di `miei_eventi.php` resta. Non visto nel browser (login SSO). Restano per 3.S1: il numero sull'icona «Eventi e attestati». La valutazione non è nei passi (si compila dal link dell'email, senza collegamento dall'area personale).
- **Problema.** Tutto il lavoro della scuola (programma, convenzione, Allegato A, elenco studenti, autorizzazione, valutazione, attestati) è nella 4ª scheda
  di `miei_eventi.php` (righe 266-272 e 690-758) dietro l'icona «Eventi e attestati», il cui numero conta solo i posti liberati (`inc/area/eventi.php`).
- **Soluzione.** Riquadro di sistema `fsl_scuola` (tessera grande, profili Scuola e Amministratore) con, per ogni prenotazione FSL, una **barra a passi**:
  1 Programma inviato → 2 Prenotazione confermata → 3 Convenzione (scarica / carica firmata / ricevuta / firmata dal Direttore) → 4 Elenco studenti e autorizzazione
  (entro il …) → 5 Attività svolta → 6 Valutazione → 7 Attestati. Il passo corrente ha **un solo pulsante** con l'azione. Riutilizzare i dati già calcolati in
  `miei_eventi.php` (`ServizioConvenzioneOnline::dellePrenotazioni`, `ServizioDocumentiScuola::perUtente`, `ServizioDocumentiClasse::stato`).
- **Requisiti.** Classe `src/Fsl/PercorsoScuola.php` che restituisce i passi per prenotazione (senza HTML, con prova unitaria); `inc/area/fsl_scuola.php` per il disegno.
  La scheda in `miei_eventi.php` resta (vecchi link `#programma-fsl`).

**3.S2 Programma FSL salvato per persona** (M)
- **Problema.** `ProgrammaFsl` è «tenuto nella sessione di chi sta navigando» (`src/Fsl/ProgrammaFsl.php:10`): uscire, scadere la sessione o cambiare computer lo svuota.
- **Soluzione.** Per chi ha fatto l'accesso il programma si salva in una tabella; all'accesso il programma della sessione (fatto da anonimo) si unisce a quello salvato.
  Chi non ha fatto l'accesso continua con la sessione e vede l'invito «Accedi per non perdere il programma».
- **Requisiti.** Tabella `fsl_programmi` (utente_id, turno_id, evento_id, custom_json, aggiunto_il, UNIQUE utente+turno); `ProgrammaFsl` dietro un'interfaccia
  con due implementazioni (sessione / database) scelte in base all'accesso; unione in `sync_sso_user` o al primo caricamento dopo il login. Le regole
  «una sola edizione per progetto» e il limite di voci restano in `ProgrammaFsl`.

**3.S3 Primo accesso di una scuola** (S)
- **Problema.** Il profilo «Scuola» nasce solo con `scuola_codice` o il gruppo Scuole (`ProfiliPersona.php`, chiave SCUOLA): al primo accesso un docente di scuola
  è «Ospite» e vede tessere generiche.
- **Soluzione.** Per il profilo Ospite senza dati, una tessera di benvenuto «Sei una scuola? / Sei un'azienda? / Sei uno studente di un altro Ateneo?» con i
  collegamenti all'offerta FSL, alla richiesta di convenzione di tesi e alla guida; la scelta «Sono una scuola» chiede la scuola (campo `scuola` già esistente,
  `html_campo_scuola()`) e imposta `utenti.scuola_codice`, così il profilo diventa Scuola subito.

**3.S4 Tessere inutili per le scuole** (S)

> **Stato 31/10/2026: FATTO in locale (schema v110).** La scheda «Tirocini» non ha più il profilo Scuola; la migrazione lo toglie solo se i profili sono ancora quelli di partenza (confronto senza badare all'ordine).
- **Problema.** La scheda «Tirocini» (TSP 2.0, tirocini universitari) è assegnata anche a Scuola ed Esterno (`CatalogoRiquadri.php:47-48`).
- **Soluzione.** Togliere Scuola dai profili di partenza; per le installazioni esistenti una migrazione che toglie il profilo solo se l'amministratore non l'ha cambiato.

**3.S5 Caricamento della convenzione firmata più chiaro** (S)

> **Stato 31/10/2026: passo 1 fatto.** `src/Infrastructure/Pdf/MessaggiFirma.php`: una sola fonte dei messaggi di rifiuto (vuoto, troppo grande, non PDF, firma .p7m riconosciuta, senza firma, non integra, modificata dopo la firma), ognuno con «Cosa fare:»; usata dal caricamento della convenzione FSL firmata. **Passo 2 fatto:** `assets/js/controllo-pdf-firmato.js` controlla nel browser estensione (.pdf, non .p7m), file vuoto e dimensione prima dell'invio, con gli stessi testi del server; attivo su `convenzione_online.php` (convenzione e Allegato A), `convenzione_tesi.php` e le cinque pagine di firma (`firma_pratica`, `firma_incarico`, `firma_verbale`, `firma_convenzione`, `firma_convenzione_tesi`); provato in un browser con un `.p7m`, un `.docx`, un file vuoto e un PDF. **Passo 3 fatto:** `MessaggiFirma` anche nel caricamento della convenzione di tesi dell'azienda; link alla guida «Scuole» (sezione «La firma del Dirigente») accanto al campo di `convenzione_online.php`. Non toccati: i messaggi di `VerificaPdfFirmato` (lettere di tutorato, verbali, pratiche, firma del Direttore), che hanno già una causa precisa ma non il «Cosa fare:» e sono in test con testo esatto; si possono portare su `MessaggiFirma` in un passo a parte.
- **Soluzione.** Prima dell'invio, controllo nel browser dell'estensione (`.pdf`, non `.p7m`) e della dimensione; dopo un rifiuto della verifica PAdES, messaggio
  in italiano semplice con la causa (file modificato, firma assente, firma CAdES) e cosa fare, più il link alla guida `Guida_Scuole_convenzione_FSL.pdf`.
  Stesso trattamento per `convenzione_tesi.php` e per le pagine di firma.

### Aziende

**3.A1 Tessera «Le convenzioni della tua azienda»** (M, dopo 0.1)

> **Stato 31/10/2026: FATTO in locale.** `PercorsoAzienda` (regole dei passi, prova `tesi_percorso_azienda.php`), `ServizioConvenzioniAzienda` (le convenzioni legate all'email verificata della persona; la stipulata sparisce un anno dopo la scadenza o appena c'è un rinnovo aperto), `ConvenzioneTesiRepository::perEmail()`, riquadro `convenzioni_azienda` (profili Azienda e Amministratore, ordine 16; compare con la semina della v110) disegnato da `inc/area/convenzioni_azienda.php`. Il «Rinnova» compare con la scadenza entro 60 giorni (la costante `GIORNI_AVVISO_SCADENZA`, non 90) e porta alla pagina con il link personale, dove c'è il modulo di rinnovo. Non visto nel browser (login SSO).
- **Problema.** Il profilo Azienda esiste (`ProfiliPersona.php`, chiave AZIENDA, anche da `haConvenzione`) ma l'area personale non mostra la convenzione:
  si lavora solo dal link dell'email (`convenzione_tesi.php?t=`).
- **Soluzione.** Riquadro `convenzioni_azienda`: per ogni convenzione legata a un'email **affidabile** della persona (0.1), stato a passi (dati → modifiche agli articoli →
  firma e caricamento → protocollo e firma del Direttore → stipulata, scade il …), con il pulsante del passo corrente che porta alla pagina con il token.
  Se la convenzione scade entro 90 giorni, pulsante «Rinnova» (`ServizioConvenzioniTesi::rinnova`).
- **Requisiti.** Metodo `ConvenzioneTesiRepository::perEmail(list<string>)`; nessun token mostrato a chi non corrisponde.

**3.A2 «Ho perso il link»** (S)

> **Stato 31/10/2026: FATTO in locale.** Aziende: la richiesta con un'email che ha già una convenzione in corso (già così) o stipulata e ancora valida rimanda lo stesso link e non ne apre un'altra (`ServizioConvenzioniTesi::avvia`); una scaduta ne apre una nuova. Scuole: nuova pagina `ricevi_link_convenzione.php` (email + domanda di controllo + limite per IP, risposta sempre uguale) che rimanda il link delle compilazioni fatte con quell'email (`ServizioConvenzioneOnline::rimandaLink`, modello email `fsl_link_ripreso`); vi si arriva da `convenzione_online.php` senza link né codice e dal messaggio «Convenzione non disponibile». Prove: `link_perso.php`.
- **Problema.** Senza link l'azienda deve ripartire da `richiedi_convenzione_tesi.php`; il servizio `rimandaLink()` esiste (`ServizioConvenzioniTesi.php:281`) ma
  serve all'ufficio.
- **Soluzione.** Su `richiedi_convenzione_tesi.php`, se per quell'email c'è già una convenzione in corso, si **rimanda lo stesso link** invece di crearne una nuova
  (risposta identica a schermo per non rivelare nulla, come oggi). Stesso schema per le scuole su `convenzione_online.php`.

### Studenti

**3.St1 Tessera «Tesi e laurea»** (S)

> **Stato 31/10/2026: FATTO in locale.** `App\Didattica\TesiStudente` (lettura della pratica del modulo con chiave `tesi` e della convenzione dell'azienda scelta; regole in `riepilogo()`), riquadro `tesi_laurea` (profili Studente e Amministratore, ordine 42; compare con la semina della v110) disegnato da `inc/area/tesi_laurea.php`: stato della richiesta, convenzione dell'azienda, «Fai la richiesta di tesi» (solo se il modulo è pubblicato), «Cerca un relatore», «I passi della tesi»; con una bozza o un'integrazione il pulsante è in evidenza e conta un «da fare». Prove: `tesi_studente.php`. Non visto nel browser (login SSO).
- **Soluzione.** Scheda di sistema per gli studenti: percorso (`percorso_tesi.php`), «Fai la richiesta» (modulo della tesi se pubblicato), stato della propria
  pratica di tesi e della convenzione dell'azienda (riquadro di `AziendaDellaPratica`), «Cerca un relatore» (`disponibilita_tesi.php`).

**3.St2 «Invita l'azienda» dalla pratica di tesi** (M, dopo 0.1)
- **Problema.** Con «Altra azienda» la pratica dice solo che l'azienda può chiedere la convenzione da sola (`AziendaDellaPratica.php:118`); il legame tra pratica e
  convenzione è una ricerca per nome con `LIKE` (riga 72), fragile.
- **Soluzione.** Nel riquadro «Azienda non ancora convenzionata» un modulo «Invita l'azienda» (nome, email del referente): avvia la convenzione con origine nuova
  `studente` e la **collega alla pratica**; lo studente vede l'avanzamento e riceve l'avviso a convenzione stipulata. Limite: una richiesta aperta per pratica, rate limit.
- **Requisiti.** Costante `ORIGINE_STUDENTE` in `src/ConvenzioniTesi/Costanti.php`; colonna `pratiche.convenzione_tesi_id` (o tabella di collegamento);
  `AziendaDellaPratica::stato()` usa prima il collegamento, poi il nome; avviso allo studente in `NotificheConvenzioniTesi` quando lo stato diventa `protocollata`.

**3.St3 Pratiche e modulistica sempre raggiungibili** (S + decisione del Dipartimento)
- **Problema.** La tessera Pratiche compare solo con almeno una pratica (`inc/area/pratiche.php:5`); la Modulistica è vuota perché nessuno dei 22 moduli è pubblicato
  (`analisi_portale_2026-10-30.md`, punto 8).
- **Soluzione.** Se ci sono moduli pubblicati, la tessera compare sempre con «Nuova richiesta» e i 3 moduli più usati; **decisione da chiedere all'utente**:
  quali moduli pubblicare (Pannello → Moduli e documenti → «Anteprima e pubblica»).

**3.St4 Prenotazioni** (S)

> **Stato 31/10/2026: FATTO in locale (la riga di stato).** `area_icona()` ha un settimo parametro `$stato`: riga breve e visibile sotto il nome (anche sul telefono), solo quando c'è una novità; messa su Eventi e attestati («Posto da confermare»), Il mio calendario, La mia disponibilità, Messaggi, Le mie risorse e Assistenza. Prove: `area_icone_stato.php`. **Non fatto:** il posto da confermare in «Da fare» con la scadenza (resta legato a 1.2).
- **Problema.** Lo stato delle icone (es. «posto liberato da confermare») è solo nel `title` (`inc/area/_tessera.php:44-49`): su telefono non si vede.
- **Soluzione.** Riga di stato breve sotto il nome dell'icona quando c'è una novità; il posto da confermare va anche in «Da fare» con la scadenza (1.2).

### Docenti

**3.D1 Tessera «Le mie approvazioni»** (S, dopo 2.1 e 2.5): conferme degli attestati, firme come relatore, registri e lettere dei tutor, verbali, con numeri
separati e link a `firme.php` / `mie_conferme.php` / `registro_tutorato.php`.

**3.D2 «I miei studenti»** (M, **chiedere prima all'utente per la privacy**): elenco in sola lettura delle pratiche in cui il docente è relatore o indicato,
con lo stato; utile per sapere se un tesista ha inviato la richiesta.

### Tutor

**3.T1 Ogni passo con il suo pulsante** (S)

> **Stato 31/10/2026: FATTO in locale (nessuna modifica allo schema).** `area_tappe_tutorato()` restituisce `azione` (accetta l'incarico / compila la dichiarazione, apri il registro, dichiara la fine); pulsante in `il_mio_tutorato.php` e sulla tessera, che ha il numero rosso «da fare».
- **Problema.** `il_mio_tutorato.php` elenca le tappe (`inc/area/_tutorato_tappe.php:16-23`) ma non offre azioni: accettazione e dichiarazione si fanno solo dal link
  dell'email; la tessera «Il mio tutorato» non ha numero rosso (`inc/area/tutorato_mio.php`, `area_tessera` senza `$novita`).
- **Soluzione.** Sul passo corrente: «Accetta l'incarico» → `dichiarazione_tutorato.php?t=<token_studente>` (o `incarico.php` se la dichiarazione è completa);
  «Apri il registro»; «Dichiara la fine delle attività». Numero rosso sulla tessera quando il passo corrente tocca al tutor.
- **Requisiti.** `IncaricoRepository::delTutor()` deve restituire `token_studente`; l'accesso alla pagina resta protetto dal confronto del codice fiscale già presente
  (`incarico.php:11`). In `area_tappe_tutorato()` aggiungere per ogni tappa «azione per il tutor» (etichetta + link) oppure null.

**3.T2 Tessere del tutorato a tutti i profili** (S)

> **Stato 31/10/2026: FATTO in locale (schema v109).** `tutorato_mio` e `tutorato_registro` assegnate a tutti i profili (compaiono solo con un incarico); la migrazione cambia i profili solo dove l'amministratore non li aveva modificati.
- **Problema.** `tutorato_mio` e `tutorato_registro` sono assegnati solo a Studente/Docente/Amministratore (`CatalogoRiquadri.php:51-54`): un dottorando o un tutor
  riconosciuto come Personale o Ospite non le vede, anche se ha l'incarico.
- **Soluzione.** Assegnarle a tutti i profili: compaiono comunque solo se ci sono dati. Migrazione come in 3.S4.

**3.T3 Registro più rapido** (M)

> **Stato 31/10/2026: FATTA SOLO la parte dei promemoria (nessuna modifica allo schema).** Promemoria automatico al tutor col registro fermo portato da 14 a 7 giorni (`ServizioRegistroTutorato::GIORNI_REGISTRO_FERMO`, testo della email aggiornato). I pulsanti per admin e gestore al singolo tutor **c'erano già** in `admin/tutorato.php`: «Invita a compilare il registro» (per lettera e in blocco per bando), «Invia di nuovo l'email» per la lettera non ancora accettata e, nell'iter Uniticket, «Presa di servizio verificata» + «Invia email allo studente per compilare e accettare». Restano da fare: modifica di una riga, «Ripeti l'ultima», più date in una volta, riepilogo mensile in PDF.
- **Problema.** In `registro_tutorato.php` si inserisce una riga alla volta; una riga sbagliata si può solo togliere (righe 21 e 92-93); nessun promemoria automatico
  (esistono solo i pulsanti manuali dell'ufficio, `tests/Integration/Tutorato/PromemoriaManualiTest.php`).
- **Soluzione.** Modifica di una riga ancora «da approvare»; «Ripeti l'ultima» (stessa attività e ore, data di oggi); inserimento di più date in una volta
  (selettore di giorni); totale ore restanti sempre visibile; promemoria automatico nel cron (`cron_background.php`) se il registro è aperto e non ha righe nuove da
  14 giorni, e alla fine del periodo per «dichiara la fine». Riepilogo mensile in PDF per il tutor.
- **Requisiti.** Metodo `ServizioRegistroTutorato::modifica()` con gli stessi controlli di `aggiungi()` (riga 97); opzione nel pannello per i giorni del promemoria.

**3.T4 Accettazione dell'incarico riprendibile** (S): la barra a passi di `dichiarazione_tutorato.php` (righe 40-45) diventa il passo corrente della tessera
(«Ti mancano: posizione contributiva»), così chi interrompe riprende dall'area personale senza cercare l'email.

---

## FASE 4 — Navigazione: arrivare subito dove serve

**4.1 Dopo l'accesso, all'area personale** (S)

> **Stato 31/10/2026: FATTO in locale, tranne il Cruscotto del Direttore** (non c'è ancora la scelta nel profilo). «Accedi» passa la pagina corrente (solo da richieste GET); senza `redirect` si va in `area_personale.php`. Decisione 6 presa come proposta: area personale per tutti.
- **Problema.** `saml_login.php:32`: senza `redirect` si torna a `index.php`; il pulsante «Accedi» della barra (`header.php:384`) non passa la pagina corrente.
- **Soluzione.** «Accedi» passa la pagina corrente come `redirect`; senza `redirect` si va in `area_personale.php` (il Direttore al Cruscotto, se l'utente lo sceglie
  nel profilo). Mantenere il controllo anti open-redirect già presente (righe 23-30).

**4.2 Area personale sempre a vista** (S): vedi 1.4 (pulsante con numero nella barra utente, anche su telefono, `header.php:506`).

**4.3 Dopo un'azione, la prossima** (S): nelle pagine di firma/conferma/caricamento, dopo il successo, riquadro «Prossima cosa da fare: …» con il primo compito di
`DaFarePersona` e il link «Torna all'area personale»; tutte queste pagine hanno il percorso «Area personale / …» come `registro_tutorato.php:41`.

**4.4 Stati con le stesse parole e gli stessi colori** (M): `miei_eventi.php` usa ancora i badge Bootstrap con colori propri (righe 340-350); `registro_tutorato.php`,
le pagine di firma e `convenzione_*.php` hanno stili in linea. Portarli ai cinque stati di `portale-ui.css` (fatto, in attesa, serve un'azione, informazione, chiuso)
con un helper unico `ui_stato(string $tipo, string $testo)`.

**4.5 Link delle email sempre funzionanti** (S): verificare che ogni pagina con token, se l'utente non ha fatto l'accesso, porti al login e poi **ritorni** alla stessa
pagina (il `middleware.php:12` lo fa; controllare le pagine con `PAGINA_SENZA_SSO`); se il token è scaduto o già usato, il messaggio porta all'area personale e
spiega dove trovare il compito.

---

## FASE 5 — Prestazioni

**5.1 Registro del tutorato: una query per ogni lettera mai firmata** (S, alta priorità)

> **Stato 31/10/2026: FATTO in locale, da caricare (nessuna modifica allo schema).** `IncaricoRepository::firmateDi()` legge in una sola query le lettere firmate della persona
> (tutor per codice fiscale; docente per email, codice fiscale o scheda); `idFirmate()` tolta. Prova in `strumenti/prove/oop/tutorato.php`: 41 lettere, 1 query per persona.
- **Problema.** `ServizioRegistroTutorato::incarichiRegistro()` (`src/Tutorato/ServizioRegistroTutorato.php:82-92`) legge **tutte** le lettere firmate o protocollate
  di sempre (`IncaricoRepository::idFirmate()`, riga 61) e per ognuna fa `perId()`. Si esegue per ogni utente a ogni apertura dell'area personale (tessera
  `tutorato_registro`) e di `registro_tutorato.php`: cresce ogni anno.
- **Soluzione.** Una sola query filtrata per CF del tutor **oppure** email/CF/persona_id del docente, con `stato IN ('firmata','protocollata')`.
- **Fatto quando.** Prova con 500 incarichi: numero di query costante (≤ 2). 

**5.2 Area personale: meno query per pagina** (M)
- **Soluzione.** Misurare (contatore di query in modalità sviluppo nel wrapper `Database`); memorizzare per la durata della richiesta i risultati condivisi
  (profili, identità, prenotazioni usate da più tessere); i totali «Da fare» in sessione per 5 minuti (1.4). Obiettivo: area personale sotto 25 query.

**5.3 Pagine con tante finestre già pronte** (S): `miei_eventi.php` crea due finestre modali per ogni prenotazione (chat e modifica, righe 361-446 e 579-593):
caricarle alla prima apertura come fatto in `admin/utenti.php` (`?scheda=ID`).

**5.4 Compressione e cache sul server** (S, sul server): il `.htaccess` locale ha `mod_deflate` e `mod_expires` (righe 32-44) ma la misura del 30/10 sul sito vero
non trovava compressione né cache: verificare che i moduli Apache siano attivi e che `AllowOverride` permetta queste direttive nella cartella del portale.

**5.5 `master_template.php` (2.517 righe)** (L, a pezzi): estrarre il flusso di prenotazione (validazione, posti, lista d'attesa, email) in `src/Iscrizioni` con prove,
lasciando nella pagina solo il disegno. Da fare dopo le fasi 0-3.

---

## FASE 6 — Interfaccia e accessibilità

- **6.1** Passata con axe-core (come già fatto il 25/09) sulle pagine per profilo: `area_personale.php`, `da_fare.php`, `firme.php`, `miei_eventi.php`, `programma_fsl.php`,
  `convenzione_online.php`, `elenco_studenti.php`, `convenzione_tesi.php`, `modulo.php`, `pratiche.php`, `il_mio_tutorato.php`, `registro_tutorato.php`,
  `dichiarazione_tutorato.php`, `conferma_attestato.php`, `cruscotto_direttore.php`. Zero errori «serious/critical». (M)
- **6.2** Moduli lunghi (`convenzione_online.php`, `dichiarazione_tutorato.php`, `convenzione_tesi.php`): salvataggio automatico della bozza ogni 30 secondi e avviso se si
  lascia la pagina con modifiche non salvate. (M)
- **6.3** Tema scuro e stile comune anche sulle pagine raggiunte dall'area personale che oggi non lo hanno (`registro_tutorato.php`, pagine di firma). (S)
- **6.4** Telefono: le tabelle del registro e del Cruscotto diventano schede sotto i 576 px; pulsanti alti almeno 44 px. (S)

---

## FASE 7 — Prove per profilo e misure

- **7.1 Scenario per profilo** (M): script in `strumenti/locale/scenario_profili.php` (sul modello di `scenario_convenzioni_tesi.php`) che crea un utente di prova per
  ogni profilo con un compito per ciascuna fonte di 1.2 e verifica, con le prove delle pagine, che ogni compito sia **a un clic** dall'area personale
  (link presente nel HTML della tessera o di «Da fare»).
- **7.2 Misure nel pannello** (S): in Sistema, quattro numeri da seguire nel tempo: utenti senza email affidabile, compiti fermi da più di 7 giorni per gruppo,
  giorni medi tra «inviata al Direttore» e «firmata», incarichi di tutorato con registro vuoto.

---

## Ordine consigliato

| Ordine | Punti | Perché |
|---|---|---|
| 1 | **0.1**, 0.2 | Falla di permessi: va chiusa prima di mostrare più cose in «Da fare» |
| 2 | 5.1, 1.1, 1.2 (prima Direttore e tutor), 2.4 | Le lettere del tutorato oggi non sono visibili al Direttore; costo basso |
| 3 | 1.3, 1.4, 4.1, 3.T1, 3.T2, 3.S4 | Piccoli, grande effetto su «un clic» |
| 4 | 2.1 → 2.2 (dopo la verifica Aruba) → 2.3, 2.5, 2.6 | Firme e approvazioni in blocco |
| 5 | 3.S1, 3.S2, 3.S3, 3.A1, 3.A2, 3.St1-3.St4 | Percorsi di scuole, aziende, studenti |
| 6 | 3.T3, 3.T4, 3.D1, 3.D2, 4.3-4.5, 1.5, 1.6 | Rifiniture dei percorsi |
| 7 | Fase 5 (resto), Fase 6, Fase 7 | Prestazioni, accessibilità, prove complessive |

## Decisioni prese dall'utente (31/10/2026)

- **Metodo:** piccoli passi, un commit dopo ciascuno; un solo archivio completo da caricare sul server alla fine.
- **2.2 (una OTP per più documenti): per ora NON si usa.** Si resta sull'iter «scarica il file, firma in locale, ricarica» → si fa 2.3 (firma in blocco fuori dal portale) al posto di 2.2; 2.1 e 2.3 non dipendono da Aruba.
- **3.D2: sì**, il docente può vedere le pratiche in cui è relatore (sola lettura).
- **3.St3: per ora nessun modulo pubblicato**; li pubblica l'utente a mano al momento opportuno. La tessera «Nuova richiesta» si fa comunque, ma resta vuota finché non ci sono moduli.
- **3.T3: promemoria dopo 7 giorni** (non 14). In più un **pulsante per amministratore e gestore** che manda il promemoria al singolo tutor. Caso Uniticket: il tutor compila il ticket, poi il gestore con un pulsante gli manda la notifica «compila la lettera di incarico».
- Decisioni 1 (email solo per gli avvisi) e 6 (accesso → area personale per tutti): restano come proposte già applicate.

## Decisioni da chiedere all'utente prima di implementare

1. **0.1:** l'email scelta dalla persona resta solo per gli avvisi (proposta) o deve contare anche per i permessi dopo la verifica?
2. **2.2:** chi può verificare con Aruba/Ateneo se la sessione di firma (una OTP per più documenti) è attiva sul contratto ARSS?
3. **3.D2:** il docente può vedere le pratiche in cui è relatore (privacy)?
4. **3.St3:** quali dei 22 moduli pubblicare.
5. **3.T3:** dopo quanti giorni senza righe mandare il promemoria al tutor (proposta: 14).
6. **4.1:** dopo l'accesso, area personale per tutti (proposta) o home per chi non ha compiti?
