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
| **S1** | **Domande frequenti** (`domande_frequenti.php`, ex guida dello studente, dentro Assistenza): argomenti, ricerca, risposte in fisarmonica con link e allegati, «Non trovi risposta? Chiedi all'assistenza». Dalle voci di Didacticus. | nessuno |
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
- 24/10/2026: **S2 fatto in locale**: pagina pubblica `contatti.php` («Chi contattare per cosa») con gestione in Sito pubblico › «Contatti per gli studenti» (`admin/contatti.php`, tabella `contatti_studenti`, schema v90, cinque voci di esempio nascoste, recapiti scritti a mano: niente dai dati di uffici e persone). Modulistica: stato vuoto con rimandi a Guida e Contatti; i 22 moduli restano da pubblicare a scelta del Dipartimento (Moduli e documenti → Modifica → «Anteprima e pubblica»). Da fare dal pannello dopo il caricamento: aggiungere Guida, Contatti e Modulistica al menu. Prossimo: B2-B4 / B1 oppure S3.
- 24/10/2026: **Guida → «Domande frequenti» dentro Assistenza** (su richiesta): pagina `domande_frequenti.php` (il vecchio `guida_studente.php` rimanda lì), blocco «Prima di scrivere, cerca la risposta» in `assistenza.php`. Dal pannello (voce «Domande e risposte», ex Didacticus) si modificano domande, risposte, **argomenti** (nuova scheda: nome, spiegazione, icona, ordine, nuovi/eliminati; tabella `didacticus_argomenti`) e **a chi si rivolge** ogni domanda (studenti, scuole, aziende, docenti; colonna `pubblico`); la pagina pubblica filtra per pubblico. Schema v91. Le domande su FSL/scuole sono segnate «Scuole» in automatico.
- 24/10/2026: **S3, S4, S5 fatti in locale**: `percorso_tesi.php` e `percorso_tirocini.php` (passi 1-2-3 con pulsanti, vista comune in `inc/percorso_vista.php`), `docenti.php` (cerca un docente dall'anagrafe + «Prenota il ricevimento» se ha lo sportello attivo), `corsi.php` (corsi visibili, insegnamenti dell'anno in corso per corso, ricerca per insegnamento o docente, link alla pagina ufficiale). Nel catalogo pagine del menu e nella proposta «Studenti». Per avere il ricevimento prenotabile la segreteria deve creare gli sportelli in Risorse scegliendo il docente.
