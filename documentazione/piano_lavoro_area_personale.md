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
| L6 | Azioni brevi dalla tessera (fase 2) | da decidere | |

## Materiali che servono

Nessuno per le fasi fatte. Per la fase 2: quali azioni i colleghi fanno più spesso (le tre da portare in tessera per prime).
