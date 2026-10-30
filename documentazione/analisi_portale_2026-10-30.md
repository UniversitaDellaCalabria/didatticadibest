# Analisi del portale DidatticaDiBEST — 30/10/2026

Come è stata fatta: dal browser, con il tuo accesso da amministratore sul sito vero (https://dibest2.unical.it/didattica/), più la lettura del codice
locale (101 pagine in radice, 70 in admin/, 468 classi in src/, 99 file di prove, 1788 prove automatiche che passano).
**Cosa NON ho fatto:** non ho misurato l'accessibilità con uno strumento (axe), non ho provato i telefoni veri, non ho letto riga per riga tutte le
pagine, non ho guardato il database direttamente. Il giudizio visivo (restyling) viene dalla struttura delle pagine, non da uno studio di schermate.

## Cosa funziona bene (da non toccare)
- **Sicurezza di base solida:** nessuna query costruita con testo dell'utente, nessun `eval`/`unserialize`, nessun `include` da input, un solo punto per i
  caricamenti di file (`Upload.php`, con controllo del tipo), `display_errors` spento, CSRF su tutte le pagine che modificano (tre eccezioni sotto).
  Intestazioni di sicurezza presenti sulle pagine PHP (HSTS, nosniff, referrer-policy, SAMEORIGIN).
- **Cartelle riservate chiuse** (`.env`, `config.php`, `cache`, `uploads`, `src`, `strumenti`, `documentazione`, `database`, i cron senza chiave): 403.
- **Gestione notturna:** backup ogni notte (DB riletto per intero, 103 tabelle/50.360 righe, ZIP dei file verificato), copia sul NAS, copia cifrata via email
  ogni settimana, controllo del sito con avviso agli amministratori, email 45 accettate / 0 fallite negli ultimi 7 giorni.
- **Velocità del server:** tutte le pagine rispondono in 10–170 ms. Nessuna pagina controllata mostra errori PHP (21 pagine pubbliche e di pannello provate).

## Problemi, in ordine di importanza

### 1. La home pesa 11,5 MB (alto, facile da risolvere)
- 12 immagini della slide, tra 160 KB e **2,4 MB** ciascuna (tre sopra 1,7 MB), tutte caricate subito, senza `loading="lazy"` né dimensioni.
- Niente compressione per HTML, CSS e JavaScript (Bootstrap Italia 585 KB di CSS e 977 KB di JS arrivano non compressi: 3–4 volte più pesanti).
- Nessuna intestazione di cache su immagini, CSS e JS: a ogni visita si riscaricano. La home stessa è `no-store`.
- Su una rete mobile scolastica significa decine di secondi. **Rimedi:** a) al caricamento di una slide ridimensionare a 1600 px e salvare in WebP/JPEG
  al 80%; b) caricare subito solo la prima slide; c) in Apache attivare `mod_deflate` e `mod_expires` per `assets/` e `uploads/` (con nome file che cambia).

### 2. Pagine di pannello enormi (medio, peggiora con i dati)
`admin/utenti.php` genera **7,5 MB** di HTML (439 persone, ognuna con 4 moduli già dentro la pagina); `admin/menu.php` 1,2 MB; `admin/audit_log.php` 951 KB.
Funziona oggi (167 ms), ma sul telefono o a 2.000 utenti diventa lenta e fa bloccare il browser (mi è successo anche a me nel caricare le modifiche in blocco).
**Fatto in locale il 30/10/2026 (da caricare):** in `admin/utenti.php` la scheda di ogni persona (anagrafica, gruppi, abilitazioni) si scarica solo alla prima apertura (`?scheda=ID`): l'elenco passa da ~17 KB a ~2 KB per persona (7,5 MB → circa 1 MB con 439 utenti). Il registro operazioni è già limitato alle ultime 1000 righe (951 KB, accettabile). `menu.php` (1,2 MB) resta com'è: ogni voce porta con sé la sua finestra di modifica, si può rendere differita allo stesso modo se servirà.

### 3. Una persona su tre non ha l'email (alto per il lavoro quotidiano)
**143 utenti su 439 (32%), 142 dei quali studenti, non hanno nessuna email** nel portale (il login di Ateneo non l'ha passata). A loro non arrivano conferme di
prenotazione, promemoria, avvisi di pratica, riepilogo settimanale. **Rimedi:** a) verificare con chi gestisce SSO se l'attributo `mail` può essere rilasciato;
b) in area personale, un invito «Aggiungi la tua email» finché manca; c) in alternativa derivarla dalla matricola (`matricola@studenti.unical.it`) solo se l'Ateneo
conferma che esiste sempre. Si può misurare dal pannello: filtro «senza email».

### 4. Promemoria FSL duplicati (medio)
Il cron dei documenti FSL manda **un'email per ogni prenotazione**. Il 29/10 alle 23:15 lo stesso docente ha ricevuto lo stesso oggetto 3 volte
(Macro-Area: Fisiologia), altri 2 volte (Macro-Area: Biochimica, Conservazione e Restauro…). Sono classi diverse, ma l'oggetto è uguale e sembra un errore.
**Rimedio:** una sola email per docente e attività con l'elenco delle classi che mancano (`ServizioDocumentiClasse::promemoria()`).
**Fatto in locale il 30/10/2026 (da caricare):** il cron raggruppa per email del docente e titolo dell'attività: una sola email con l'elenco «per la prenotazione FS-… manca …» e tutti i codici; il contatore dei promemoria avanza per ogni classe inclusa. Con una sola classe l'email è identica a prima.

### 5. File inutili raggiungibili dall'esterno (medio-basso, 5 minuti)
Rispondono 200 da fuori: `install.php` (oggi dice «già installato», ma va tolto), `composer.json`, `README.md`, `MIGRATION_PLAN.md`, `phpunit.xml.dist`.
Non sono pericolosi di per sé ma dicono come è fatto il sito. **Rimedio:** cancellarli dal server (non sono nel pacchetto che costruisco) oppure bloccarli in Apache.
Inoltre l'intestazione `Server` mostra Apache 2.4.58 + **OpenSSL 1.1.1w (non più supportato dal 2023)** + mod_perl, e `X-Powered-By` mostra PHP 8.4.27:
nascondere le versioni (`ServerTokens Prod`, `expose_php=Off`) e, appena possibile, usare un Apache con OpenSSL 3.

### 6. Piccole mancanze di sicurezza (basso)
- `admin/stampa_badge.php` accetta POST senza controllo CSRF (stampa soltanto, rischio minimo).
- `sondaggio.php` senza CSRF per scelta (protetto dal token): corretto, ma da documentare.
- La CSP contiene solo `frame-ancestors`: non limita da dove arrivano gli script. Cambiarla è difficile perché molte pagine usano script nella pagina;
  si può fare a gradini (prima `report-only`).
- Nessuna `Permissions-Policy`.

### 7. Backup: tutto sulla stessa sede (medio)
Il NAS è collegato allo stesso server, la copia via email è cifrata ma è un solo file settimanale. Non c'è una copia fuori sede né una **prova di ripristino**
documentata. Con 50.000 righe e 67 MB di file ci vuole un'ora per farla bene: una volta l'anno ripristinare su una macchina di prova e controllare che si apra.

### 8. Menu del sito
- Ho acceso 17 voci (vedi sotto); 10 restano nascoste perché la pagina non è pronta o non serve a tutti.
- «Ricevimento dei docenti» (nascosta) punta a `ricevimento.php`, che è la pagina del **docente** per impostare i propri orari: la pagina giusta per gli studenti è `docenti.php`.
- Nuova pagina «Tirocini Scienze motorie» non è ancora nel menu (va sotto Studenti › Convenzioni).
- Le voci «Pubblico» che portano a pagine con login (es. Le mie pratiche) dovrebbero essere «Solo autenticati».

### 9. Per il pubblico e per i motori di ricerca (basso)
Nessuna `meta description`, nessuna immagine di anteprima (`og:`), nessun `canonical`, nessuna `sitemap.xml`. Manca anche una **dichiarazione di accessibilità**
(per i siti della PA è obbligatoria e va pubblicata e compilata sul sito AgID: da chiedere all'Ateneo se la gestisce centralmente).

### 10. Debito tecnico (medio-lungo)
- `master_template.php` ha 2.517 righe e mescola logica e grafica; sette pagine di pannello superano le 900 righe. Il codice nuovo (`src/`, 468 classi, analisi PHPStan livello 8) è
  molto più pulito: le pagine vecchie andrebbero svuotate verso `src/` un po' alla volta, come già fatto.
- 101 file della radice non hanno `declare(strict_types=1)`; 11 usano ancora `onclick=` dentro l'HTML (ostacolo a una CSP severa).
- Le prove automatiche coprono bene la logica (1788) ma poco le pagine (le prove «pagine_*» richiedono l'ambiente locale acceso).

## Cosa ho fatto oggi
- **Gruppo Scuole: da 6 a 12 persone.** Aggiunte come gruppo in più (senza toccare il gruppo principale): Murano Patrizia, Sinopoli Antonio, Panetta Cristina
  (stessa email del referente nell'anagrafe scuole), **Novello Gian Michele e Volpe Rosanna** (abbinati per nome: nell'anagrafe scuole l'email è quella della scuola,
  nel portale è un indirizzo privato) e Ruggiero Letizia (email `@istitutocapialbi.edu.it`, non è nell'anagrafe scuole). Da controllare tu le tre non sicure.
  Restano fuori Pietramale Delia e Caira Elvira: non hanno un account nel portale.
- **Menu: attivate 17 voci** (Area riservata con Profilo, Area personale, Le mie risorse, Cruscotto del Direttore e Direttore assente; Informazioni con Verifica attestato,
  Verbali, Privacy, Crediti, Come si fa; Scanner QR; le agende Ricerca / Public engagement / Didattica; Programma FSL della scuola). Restano nascoste: Ufficio Didattico
  (la pagina dice «in manutenzione»), Modulistica e Le mie pratiche (nessun modulo ancora pubblicato), Ricevimento (link sbagliato), Registro tutorato (solo per tutor con incarico),
  Scienze motorie (+ archivio, vuoto).

## Restyling (da valutare con gli occhi)
- La **home**: oggi la slide è pesante e piena di testi sovrapposti a foto diverse; con foto ottimizzate e una sola riga di invito per scuole / studenti / studenti motorie
  sarebbe più leggera e più chiara per chi arriva dal telefono.
- Stile comune: l'area personale ha già le tessere e il tema scuro; il pannello usa titoli e schede coerenti solo nelle pagine rifatte (`pn_testata`/`pn_schede`):
  portare le pagine vecchie (utenti, eventi, iscritti, sondaggi) allo stesso schema.
- Accessibilità: fare una passata con axe sulle 15 pagine più usate; già risolti contrasto e focus, ma le pagine nuove non sono state controllate.

## Nuove funzioni che ti consiglio (in ordine di utilità)
1. **Avviso quando si libera un posto in un'azienda di Scienze motorie** (lista d'attesa per studente o iscrizione all'avviso) e invio mensile dell'elenco aggiornato.
2. **Pagina «cosa devo fare» per la scuola**, per il profilo Scuola: documenti da consegnare, convenzione, attività prenotate e attestati, in una sola schermata
   (i pezzi esistono già in area personale e nel programma FSL).
3. **Recupero dell'email mancante** (vedi punto 3) con invito in area personale e controllo in Utenti.
4. **Report di Terza Missione per anno accademico** (scuole, studenti, attività), già proposto: usa i codici meccanografici ora collegati.
5. **Una email sola per docente e attività** (punto 4) con il riepilogo di tutte le classi.
6. **Calendario personale in abbonamento** (ICS) per studenti e docenti: i file ICS esistono già per singoli eventi; l'abbonamento unico è un piccolo passo.
7. **Accessi di prova in sola lettura per chi dà assistenza** (ora solo gli amministratori possono usare «Entra come…»; si potrebbe estendere all'ufficio Assistenza).
