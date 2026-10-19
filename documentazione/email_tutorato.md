# Le email del tutorato: chi le riceve, quando partono, cosa dicono

Elenco di **tutte** le email che il portale invia nell'iter del tutorato (lettera di incarico, firme, registro, fine attività, pagamenti). Le parti tra `{graffe}` sono i dati che il portale inserisce da solo. Ogni email ha un **pulsante** con un link personale (quando indicato) e, in fondo, la frase «Il link è personale: non inoltrarlo.». Il colore è il verde del Tutorato.

Questo è il testo **di oggi** (scritto nel codice, non ancora modificabile dal pannello).

---

## A. Lettera di incarico

### 1. Al vincitore: invito a confermare la lettera
- **Quando:** l'ufficio preme «Invia email allo studente» (o «Invia di nuovo l'email»).
- **Oggetto:** Lettera di incarico di tutorato: controlla e conferma
- **Testo:**
  > Gentile {nome cognome},
  > è pronta la tua **lettera di incarico** per le attività di tutorato ({bando}).
  > Accedi al portale **con SPID o CIE**, controlla i dati e clicca su **Confermo e accetto**: la conferma, con i dati della tua identità digitale, vale come accettazione dell'incarico. Poi la lettera passa alla firma del docente responsabile e del Direttore.
  > Se trovi un errore nei dati segnalalo dalla stessa pagina.
- **Pulsante:** «Controlla e conferma la lettera»

### 1b. Al vincitore: invito a compilare e accettare (iter per Uniticket)
- **Quando:** come sopra, ma solo dopo «Presa di servizio verificata».
- **Oggetto:** Lettera di incarico di tutorato: compila e accetta
- **Testo:** come la 1, con in più, dopo il primo paragrafo:
  > Abbiamo verificato la tua presa di servizio su Uniticket. Ora devi **compilare i dati richiesti** (dichiarazione individuale: dati personali, accredito e posizione contributiva) e **accettare l'incarico**.
- **Pulsante:** «Compila e accetta la lettera»

### 2. Al docente responsabile: lettera da firmare
- **Quando:** il vincitore ha confermato la lettera.
- **Oggetto:** Lettera di incarico da firmare: {Cognome Nome}
- **Testo:**
  > Gentile Prof. {nome cognome docente},
  > {studente} ha accettato l'incarico di tutorato ({bando}) di cui è **responsabile dell'attività**.
  > Apra la lettera e la firmi digitalmente **in formato PAdES**: con la firma remota Aruba direttamente dal portale, oppure scaricandola e caricando il PDF firmato. Dopo la sua firma la lettera passa al Direttore.
- **Pulsante:** «Apri e firma la lettera»

### 3. Al Direttore: lettera da firmare
- **Quando:** il docente ha firmato.
- **Oggetto:** Lettera di incarico di tutorato da firmare: {Cognome Nome}
- **Testo:**
  > Gentile Direttore,
  > la lettera di incarico di tutorato di **{studente}** ({bando}) è stata accettata dallo studente e firmata dal responsabile dell'attività, {Prof. docente}.
  > La firmi digitalmente **in formato PAdES** (firma remota Aruba dal portale o caricando il PDF firmato): poi la lettera va all'Ufficio per il protocollo.
- **Pulsante:** «Apri e firma la lettera»

### 4. Al tutor: lettera firmata, registro aperto
- **Quando:** il Direttore ha firmato.
- **Oggetto:** Tutorato: lettera di incarico firmata, registro delle attività aperto
- **Testo:**
  > Gentile {nome cognome},
  > la tua lettera di incarico è firmata dal docente responsabile e dal Direttore. Da ora segna nel **registro delle attività** i giorni, le ore e le attività svolte: il docente le approva e a fine incarico dichiari concluse le attività.
- **Pulsante:** «Apri il registro»

### 5. All'operatore che protocolla: lettera firmata da protocollare
- **Quando:** il Direttore ha firmato. (Destinatari: l'operatore scelto nel bando, altrimenti chi ha il compito «Bandi», altrimenti gli amministratori.)
- **Oggetto:** Lettera di incarico firmata: da protocollare – {Cognome Nome}
- **Testo:**
  > La lettera di incarico **{codice}** di {studente} ({bando}) ha tutte le firme: accettazione dello studente con identità digitale, firma PAdES del responsabile dell'attività e del Direttore.
  > Scarica il PDF firmato e protocollalo, poi registra il numero di protocollo nel pannello.
- **Pulsante:** «Scarica e protocolla»

### 6. Al vincitore: copia della lettera protocollata
- **Quando:** l'ufficio registra il protocollo con la casella «invia allo studente la lettera firmata».
- **Oggetto:** Lettera di incarico di tutorato firmata (prot. {numero})
- **Testo:**
  > Gentile {nome cognome},
  > in allegato la tua lettera di incarico con tutte le firme, protocollata con il n. {protocollo} del {data}.
- **Allegato:** la lettera firmata (PDF). Nessun pulsante.

### 7. All'operatore: errore segnalato dal vincitore
- **Quando:** il vincitore preme «segnala un errore nei dati» nella pagina della lettera.
- **Oggetto:** Lettera di incarico: segnalazione di {Cognome Nome}
- **Testo:**
  > Lo studente segnala un errore nei dati della lettera **{codice}**:
  > {testo scritto dallo studente}
  > Annulla la lettera e preparane una corretta (puoi duplicarla), poi inviala di nuovo.
- **Pulsante:** «Apri nel pannello»

---

## B. Registro delle attività e fine attività

### 8. Al tutor: promemoria del registro (automatico)
- **Quando:** ogni giorno il portale controlla; al massimo **una volta ogni 7 giorni** per tutor. Parte (a) negli ultimi 7 giorni prima della fine del periodo e dopo, finché non conclude; oppure (b) se sono passate più di due settimane dalla firma del Direttore e il registro non ha attività recenti.
- **Oggetto:** Tutorato: registro delle attività
- **Testo:**
  > Gentile {nome cognome},
  > **(a)** il periodo dell'incarico termina il {data} (oppure: «è terminato il {data}»): completa il registro delle attività e, quando hai finito, clicca su **«Ho concluso le attività»**. Il docente responsabile confermerà le ore.
  > **(b)** non segni attività nel registro da più di due settimane: aggiornalo con i giorni, le ore e le attività svolte.
  > Ore segnate: {ore segnate} su {ore previste}.
- **Pulsante:** «Apri il registro»

### 9. Al docente: ore da approvare / fine dichiarata (promemoria automatico)
- **Quando:** al massimo una volta ogni 7 giorni, se ci sono ore da approvare da più di 7 giorni, oppure il tutor ha dichiarato concluse le attività da più di 3 giorni.
- **Oggetto:** Tutorato: registro di {Cognome Nome} da controllare
- **Testo:**
  > Nel registro delle attività di tutorato di **{studente}** il tutor ha dichiarato concluse le attività: approvi le ore e confermi la fine. (oppure: «ci sono ore da approvare.»)
- **Pulsante:** «Apri il registro»

### 10. Al docente: il tutor ha dichiarato concluse le attività
- **Quando:** il tutor preme «Ho concluso le attività».
- **Oggetto:** Tutorato: fine attività di {Cognome Nome}
- **Testo:**
  > Gentile {titolo nome cognome docente},
  > {studente} ha dichiarato concluse le attività di tutorato ({bando}) di cui è responsabile.
  > Ore nel registro: **{ore}** su {ore previste} ({n} da approvare).
  > Controlli il registro, approvi le ore e confermi la fine delle attività: il portale prepara la dichiarazione di fine attività da firmare in PAdES.
- **Pulsante:** «Apri il registro»

### 11. All'operatore: fine attività firmata, da validare
- **Quando:** il docente ha firmato la dichiarazione di fine attività.
- **Oggetto:** Fine attività firmata: da validare – {Cognome Nome} – {ore} ore
- **Testo:**
  > Il tutor **{studente}** ({bando}) ha completato le attività: **{ore} ore** approvate su {ore previste}.
  > La dichiarazione di fine attività è firmata in PAdES dal docente titolare (non serve il protocollo): controlla ore e documenti e passa la pratica all'ufficio pagamenti dalla vista «Da validare» del tutorato.
  > {tabella con le giornate approvate}
- **Allegato:** la dichiarazione di fine attività firmata. **Pulsante:** «Apri le pratiche da validare»

### 12. Al tutor: attività completate
- **Quando:** come la 11.
- **Oggetto:** Tutorato: attività completate
- **Testo:**
  > Gentile {nome cognome},
  > il docente responsabile ha confermato la fine delle tue attività di tutorato: **{ore} ore**. L'Ufficio procede con gli adempimenti per il compenso.

---

## C. Firme ferme

### 13. Sollecito della firma (automatico)
- **Quando:** la lettera aspetta la firma del docente o del Direttore, oppure la dichiarazione di fine attività aspetta quella del docente, da più di 5 giorni (impostabile con `FIRME_GIORNI_SOLLECITO` nel `.env`); fino a **3 solleciti**.
- **Destinatario:** il docente o il Direttore, secondo chi deve firmare.
- **Oggetto:** Sollecito: {la lettera di incarico | la dichiarazione di fine attività} di {Cognome Nome} è in attesa della sua firma
- **Testo:**
  > Gentile,
  > {la lettera di incarico | la dichiarazione di fine attività} di tutorato di **{studente}** ({bando}) aspetta la sua firma digitale in PAdES dal {data}.
- **Pulsante:** «Apri e firma»

### 14. All'operatore: firma ferma dopo tre solleciti
- **Oggetto:** Firma ferma: {la lettera di incarico | la dichiarazione di fine attività} di {Cognome Nome}
- **Testo:**
  > Dopo tre solleciti {la lettera di incarico | la dichiarazione…} non è ancora firmata da {indirizzo}: serve un contatto diretto.
- **Pulsante:** «Apri nel pannello»

---

## D. Pagamenti (solo iter nel portale)

### 15. All'ufficio pagamenti: pratiche da pagare
- **Quando:** il referente passa una o più pratiche ai pagamenti (una sola email per ufficio, con l'elenco).
- **Oggetto:** Tutorato: una pratica da pagare – {Cognome Nome} (oppure: Tutorato: {n} pratiche da pagare)
- **Testo:**
  > Gentile {ufficio},
  > {referente} ha validato e passato **una pratica** (oppure **{n} pratiche**) all'ufficio pagamenti:
  > • **{studente}** · {bando} · {ore} ore …
  > Nella scheda trovi decreti, UNILAV, lettera di incarico, registro, fine attività e dati per il pagamento.
- **Pulsante:** «Apri le pratiche da pagare»

### 16. Ai referenti: pratica rimandata dall'ufficio pagamenti
- **Oggetto:** Tutorato: pratica rimandata dall'ufficio pagamenti – {Cognome Nome}
- **Testo:**
  > L'ufficio pagamenti ha rimandato la pratica di **{studente}** ({bando}) ({chi}) per questo motivo:
  > {nota}
  > Correggi quanto serve e passala di nuovo ai pagamenti.
- **Pulsante:** «Apri le pratiche da validare»

### 17. Al docente: fine attività da rivedere
- **Quando:** il referente non valida la fine attività e la rimanda con un motivo.
- **Oggetto:** Tutorato: fine attività da rivedere – {Cognome Nome}
- **Testo:**
  > Gentile {titolo nome cognome docente},
  > il referente del tutorato ha controllato la fine attività di **{studente}** ({bando}) e la rimanda a lei per questo motivo:
  > {motivo}
  > Controlli il registro, approvi le ore e confermi di nuovo la fine delle attività: il portale prepara la dichiarazione da firmare.
- **Pulsante:** «Apri il registro»

### 18. Al tutor: pagamento disposto
- **Oggetto:** Tutorato: pagamento disposto
- **Testo:**
  > Gentile {nome cognome},
  > il pagamento del tuo incarico di tutorato ({bando}) è stato **disposto il {data}** (riferimento: {riferimento}) sull'IBAN che hai indicato.
  > Con questo la pratica è conclusa. Nell'Area personale trovi tutti i tuoi documenti.
- **Pulsante:** «Apri l'Area personale»

---

## Cosa **non** c'è oggi
- **Nessun pulsante manuale** per mandare un promemoria al tutor o al docente: i promemoria 8, 9 e 13 sono solo automatici (ogni giorno il portale controlla se serve).
- Nessuna email al tutor quando il docente **approva o respinge** le ore del registro.
- Nessuna email al docente quando il portale **prepara** la dichiarazione di fine attività da firmare (il docente la firma dalla pagina che apre dopo aver confermato le ore).
