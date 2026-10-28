# Piano — Il lavoro dei colleghi dall'area personale

Richiesta del 28/10/2026: i colleghi (ufficio didattico, protocollo, referenti dei consigli, tutorato, convenzioni, assistenza, gestori) non devono più «passare dal pannello gestori» per capire cosa fare e da dove cominciare: lo trovano nella **propria area personale**.

## Cosa ho trovato (censimento)

- Le **azioni** (assegna, protocolla, cambia stato, firma, verbalizza…) sono già funzioni e servizi riusabili (`inc/didattica.php`, `src/Didattica/*`, `src/ConvenzioniTesi/*`, `src/Fsl/*`, `src/Assistenza/*`). Le pagine in `admin/` sono soprattutto interfaccia e controlli dei permessi.
- I **permessi** sono già per persona: `admin/admin_header.php` calcola `puo_didattica_tutto`, `puo_didattica` (referente di consiglio), `puo_conv_tesi`, `puo_tutorato`, `puo_fsl_convenzioni`, `puo_assistenza`. Il menu mostra già solo ciò che spetta.
- I **numeri** delle cose che aspettano (`CoseDaFare`) esistono, ma si vedono solo nella «scrivania» del pannello (`admin/inizio.php`) e nel menu.
- L'area personale ha già `Da fare per te` (cose in cui la persona è *destinataria*: firme, conferme) ma non il lavoro di chi *gestisce* una pratica, un consiglio, una convenzione.

## Scelta di progetto

**Non si duplicano le schermate di lavoro nell'area personale**: raddoppierebbero controlli, permessi e prove, e dopo qualche mese i due percorsi si comporterebbero in modo diverso. Si fa invece:

1. **Il lavoro sta nel cruscotto**: nuovo riquadro di sistema «Il mio lavoro» con **una tessera per ogni postazione** che la persona può usare (Pratiche, Protocollo, Sedute e verbali, Convenzioni di tesi, Tutorato, Convenzioni FSL, Assistenza, Eventi e aree), con il **numero delle cose che aspettano** e i pulsanti diretti sulla vista giusta (per es. «Da assegnare», «Assegnate a me»).
2. **Si lavora nella stessa pagina di prima** (un solo codice, stessi permessi, stesso registro operazioni), ma con un ingresso unico: dal pannello si torna all'area personale con un pulsante sempre presente.
3. **Chi non ha nulla da gestire non vede nulla**: il riquadro appare solo se la persona ha almeno una postazione.
4. **Il pannello resta** per chi configura (amministratori) e come luogo dove si compie l'azione.

Fase 2 (da decidere dopo l'uso): azioni brevi direttamente dalla tessera («Prendi in carico», «Approva») con una piccola richiesta che richiama le stesse funzioni, senza aprire la pagina.

## Step

| Step | Cosa | Stato | Data |
|---|---|---|---|
| L1 | Censimento di azioni, permessi e numeri | **fatto** | 28/10/2026 |
| L2 | `PostazioniLavoro` (regole, senza database) e prove | **fatto** | 28/10/2026 |
| L3 | Riquadro «Il mio lavoro» nell'area personale (schema v97) | **fatto** | 28/10/2026 |
| L4 | Pulsante «Area personale» nel pannello, numero «Pratiche da protocollare» | **fatto** | 28/10/2026 |
| L5 | Prove, mappe, guida, pacchetto per il server | **fatto** | 28/10/2026 |
| L6 | Tutte le azioni sulle tessere (pratiche, sedute, tutorato, FSL, convenzioni) | **fatto** | 28/10/2026 |
| L7 | Tessere delle aree per i gestori: iscritti, presenze, messaggi, sondaggi, statistiche, «Nuovo evento» e «Nuovo progetto» come scorciatoie | **fatto** | 28/10/2026 |
| L8 | Azioni brevi senza aprire la pagina (Prendi in carico, Approva) | da decidere | |

## Materiali che servono

Nessuno per le fasi fatte. Per la fase 2: quali azioni i colleghi fanno più spesso (le tre da portare in tessera per prime).

## Decisioni del 28/10/2026 (seconda parte)

- **Ogni tessera elenca tutte le azioni** che la persona può fare (non solo la coda): ogni pulsante apre la pagina del pannello già sulla vista o sul modulo giusto.
- **Eventi e progetti:** la creazione resta nel pannello (modulo lungo con turni, campi, attestati) ma si raggiunge con «Nuovo evento» e «Nuovo progetto» dalla tessera dell'area, solo a chi può crearli. Le cose che si fanno ogni giorno (iscritti, presenze, scanner, messaggi, sondaggi, statistiche) sono tutte sulla tessera.
- **Una regola sola per le aree:** `AreeGestite` decide quali aree gestisce una persona e lo usano sia il menu del pannello sia l'area personale. L'amministratore vede una tessera solo per le aree con qualcosa da guardare.

## Decisioni del 28/10/2026 (terza parte): disposizione dell'area personale (schema v99)

- **Icone di servizio in alto, tre per riga**, con la scritta sotto e il numero in rosso: Assistenza, **Messaggi** (la casella unica di chi gestisce, solo per chi ha aree, convenzioni FSL o assistenza), disponibilità, eventi e attestati, calendario, profilo, risorse, ricerca, «Come si fa». Forma `icona` in `CatalogoRiquadri`; la riga di stato sta nel suggerimento.
- **«Da fare per te» subito sotto**, per tutti: ci sono le firme del docente (registri e lettere del tutorato, firme sulle pratiche di tesi, valutazioni, convenzioni di tesi); sopra l'elenco un pulsante per ogni gruppo col numero in rosso.
- **«Il mio lavoro» solo per chi gestisce qualcosa o è di un ufficio:** una riga per postazione a tutta larghezza, i pulsanti d'azione in fila a destra, ognuno col suo numero in rosso; le righe con qualcosa da fare stanno in cima.
- Regole dei permessi in `inc/area/_permessi_lavoro.php`, usate dal riquadro «lavoro» e dall'icona «messaggi».
