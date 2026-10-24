# Piano: più pagine per gli studenti, pannello semplice, news (24/10/2026)

Nato dall'analisi del 24/10/2026 (sito pubblico, 22 pagine admin misurate, 61 voci di Didacticus, 22 moduli, 18 uffici). Regole del progetto: vedi `piano_nuove_feature.md` (materiali prima di ogni step, ogni esportazione in Excel+PDF+CSV, mai attribuzioni a Claude nei commit).

## Cosa è emerso
- Il menu «Studenti» ha 4 voci; il resto del sito parla a scuole e orientamento.
- Contenuti già pronti ma non pubblicati: **61 voci di Didacticus** (iscrizione, tasse, OFA, esami, tesi, tirocini, certificati, Erasmus…), **22 moduli** (tutti «non pubblicati» → la pagina Modulistica è vuota e non è nel menu), **18 uffici** con persone, **ricevimento dei docenti** (pagina non nel menu).
- Pannello admin: 5 pagine sono troppo pesanti (Tirocini 44.808 px / 231 pulsanti / 1.546 campi; Utenti 31.107 px / 447 pulsanti; Moduli 95; Didacticus 192; Menu 172). Le altre 17 sono già gestibili.
- Nessuna sezione «news» né «pagine informative».

## Blocco S — Sito pubblico per gli studenti
| Step | Cosa | Contenuti nuovi da scrivere |
|---|---|---|
| **S1** | **Guida dello studente** (`guida_studente.php`): argomenti, ricerca, risposte in fisarmonica con link e allegati, «Non trovi risposta? Chiedi all'assistenza». Dalle voci di Didacticus. | nessuno |
| S2 | Modulistica pubblicata e nel menu + **Contatti e uffici** («Chi contattare per cosa») dagli uffici del pannello | decidere quali moduli pubblicare; descrizioni degli uffici |
| S3 | **Tesi e laurea** e **Tirocini** come percorsi a passi (1-2-3), con le pagine esistenti | testi dei passi (partire dalle voci Didacticus) |
| S4 | **Docenti e ricevimento**: cerca un docente, prenota il ricevimento | solo attivare gli sportelli |
| S5 | **Corsi e insegnamenti** con l'API pubblica Unical (codice DiBEST 002014) | nessuno |

## Blocco N — Comunicazioni (news) e pagine informative
**N1** Un solo strumento: la «comunicazione» (titolo, testo, data, «valida fino al», argomento, allegati, «in evidenza in home», «invia per email a chi è iscritto agli avvisi»). Senza data diventa una **pagina informativa**. Pannello: scrivania «Pubblica una notizia» a un passo. Pubblico: elenco, singola notizia, riquadro in home, feed.
**N2** Pagina «Scadenze e date utili» alimentata dalle comunicazioni con scadenza.

## Blocco B — Pannello semplice per chi non è pratico
| Step | Cosa |
|---|---|
| **B1** | Scrivania per ruolo: 3-5 pulsanti grandi in linguaggio comune («Rispondi a una chat», «Controlla le pratiche», «Pubblica una notizia») |
| **B2** | **Tirocini**: si parte dalla ricerca; il modulo si apre solo per l'azienda scelta |
| **B3** | **Utenti e abilitazioni**: elenco con ricerca e una riga per persona; le azioni rare dentro «Apri» |
| **B4** | Moduli, Menu, Didacticus: righe cliccabili con un solo «Apri» (come F16) |
| **B5** | Testo base più grande, pulsanti più alti, etichette in italiano comune («Template Layout» → «Aspetto della pagina»…), pulsante **«Come si fa?»** su ogni pagina (collegato a `guida.php`) |
Principio: nessun restyling totale (lo stile attuale è coerente); interventi mirati. Mantenere i vecchi indirizzi. Gli schermi vanno mostrati all'utente prima di costruirli.

## Ordine
1. **S1** (nessun contenuto da scrivere) → S2.
2. **B2-B4** (le 5 pagine pesanti) e B1.
3. **N1** → N2, poi S3-S5 e B5.

## Registro di avanzamento
- 24/10/2026: piano scritto. **S1 fatto in locale** (`guida_studente.php`, inserita nel catalogo pagine del menu e nella proposta «Studenti»; provata con 23 voci di partenza, ricerca e telefono OK). Da caricare; poi aggiungerla al menu dal pannello (Menu → pagine del portale). Prossimo: S2.
