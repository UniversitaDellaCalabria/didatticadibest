# MIGRATION_PLAN.md — Didattica DiBEST: da PHP procedurale a OOP (pattern Strangler Fig)

> Ramo di lavoro: `feature/refactoring-oop`. Documento di analisi e pianificazione: **nessun file sorgente è stato modificato**.
> Regola d'oro dell'intero percorso: **ogni step va in produzione da solo e il sito continua a funzionare come prima** (stessi URL, stesse pagine, stesso database, stesse email, stessi PDF).

---

## STATO DI AVANZAMENTO

| Step | Stato | Note |
|---|---|---|
| 0 — Rete di sicurezza e inventario | **fatto** | `strumenti/inventario_oop.php` (funzioni procedurali/facciate per file, query nelle pagine, classi in `src/`, versione PHP ed estensioni) |
| 1 — Composer, autoload, bootstrap | **fatto** | `composer.json`, `src/autoload.php` (autoload PSR-4 senza Composer, usato sul server), `src/bootstrap.php` agganciato in `functions.php`; `PdfSemplice`/`TtfSemplice` → `App\Infrastructure\Pdf\Documento`/`TrueType` con alias |
| 2 — Core | **fatto** | `App\Core\Database`, `Config`, `Container`, `App` (ponte per il legacy); `db_query/db_righe/db_riga/db_valore/db_esegui` sono facciate; 462 prove + 10 test PHPUnit verdi |
| 3 — Infrastruttura condivisa | da fare | prossimo step |

### Decisioni prese durante l'implementazione (aggiornano le sezioni 1 e 2)
- **Aggancio in `functions.php`, non in `config.php`**: `functions.php` è incluso da tutte le pagine, dai cron e dalle prove automatiche (che non caricano `config.php`), quindi le classi sono disponibili ovunque. Il container si avvia appena esiste la connessione `$conn`.
- **Sul server non si carica `vendor/`**: `src/autoload.php` (15 righe) carica le classi `App\` da `src/` senza Composer. `vendor/` resta solo in sviluppo (PHPUnit, PHP-CS-Fixer) ed è escluso da git; se per errore finisse sul server è comunque bloccato dal `.htaccess`. Niente controllo di piattaforma di Composer (`platform-check: false`): nessun rischio di pagina bianca per la versione di PHP.
- **Compatibilità PHP 8.0** finché non si conosce la versione del server (Step 0 sul server: `php strumenti/inventario_oop.php`): niente `enum` né `readonly` per ora; con PHP ≥ 8.1 si introducono negli step dei moduli. PHPUnit 9.6 (compatibile con 8.0).
- **Errori silenziosi conservati**: le facciate `db_*` restituiscono `false`, `[]`, `null`, `-1` come prima (il sito usa `mysqli_report(OFF)`). Le eccezioni di dominio arrivano con i Service dei moduli.

### Comandi di sviluppo
- `composer install` (una volta) · `composer test` (PHPUnit + prove del portale) · `composer cs` (stile PSR-12 su `src/` e `tests/`) · `composer inventario`.
- Sul server, dopo il caricamento: le stesse verifiche di sempre (`strumenti/prove_server.sh`, `strumenti/verifica_sito.sh`).

---

## 0. FOTOGRAFIA DELLO STATO ATTUALE

| Elemento | Situazione | Impatto sulla migrazione |
|---|---|---|
| Dimensioni | ≈ 27.000 righe in 100 pagine PHP (52 pubbliche, 48 in `admin/`) + 11.300 righe in 27 file `inc/` (484 funzioni) | Troppo grande per una riscrittura: serve sostituzione incrementale |
| Bootstrap | `config.php` (sessione, header di sicurezza, lettura `.env`, connessione `$conn`), `functions.php` (carica `inc/*.php` in ordine fisso, poi `assicura_schema()`), `middleware.php` (SSO, `$user_info`, ruoli) | Punto unico in cui agganciare l'autoloader senza toccare le pagine |
| Database | `mysqli` con `mysqli_report(OFF)`; 535 chiamate `->query()`, 246 `->prepare()`, 175 chiamate agli helper `db_query/db_righe/db_riga/db_valore/db_esegui` (`inc/base.php`) | Gli helper sono già un "mini-repository": diventano la prima facciata verso la classe `Database` |
| Tabelle | circa 56 tabelle (`database/schema.sql` + quelle create da `inc/schema.php`) | Ogni aggregato ha già tabelle ben riconoscibili: i Repository si ricavano direttamente |
| Stato globale | `$conn` passato quasi sempre come primo parametro (solo 6 `global` in tutto il codice); `$user_info`, `$u_id`, `$is_full_admin`, `$utente_admin`, `$filtro_p` creati dal middleware / dagli header | Situazione favorevole: la Dependency Injection si può introdurre senza caccia ai `global` |
| Logica e HTML | Pagine "a scheda": in alto le azioni POST (redirect + exit), sotto l'HTML; molte funzioni `inc/` restituiscono HTML (`html_*`) | Separare per gradi: prima query (Repository), poi regole (Service), per ultimo le viste |
| Schema | `inc/schema.php` → `assicura_schema()` con marcatore `cache/schema_vNN.ok` (oggi v44) | Va mantenuto; più avanti diventa un sistema di migrazioni a classi |
| Prove | `strumenti/prove/esegui.php`: 450 prove (funzioni su DB usa-e-getta + pagine dell'ambiente locale); `strumenti/prove_server.sh`, `strumenti/verifica_sito.sh` | **È la rete di sicurezza**: ogni step deve lasciarle tutte verdi |
| Rilascio | Upload manuale dei file su XAMPP (`/opt/lampp`), nessun Composer sul server, nessuna CI | L'autoload deve funzionare **senza** eseguire Composer in produzione |
| Classi esistenti | Solo `PdfSemplice` e `TtfSemplice` (`inc/pdf.php`) | Primi candidati naturali a spostarsi in `src/` |

Moduli funzionali (dai file `inc/` e dalle schede `admin/`):

| Area | File procedurali attuali |
|---|---|
| Fondamenta | `base` (utenti, SSO, permessi, CSRF, rate limit, email, helper DB), `sistema`, `dati` (log, audit, backup), `schema`, `esporta`, `pdf` |
| Portale | `sezioni`, `aspetto`, `organizzazione` (testata, menu, home, widget) |
| Eventi | `eventi_progetti`, `agenda`, `seminari`, `avvisi`, `report_ambiti` |
| Iscrizioni | `prenotazioni`, `liste_attesa`, `attestati` |
| Risorse e aule | `risorse`, `calendario_risorse` |
| Anagrafi | `anagrafi`, `catalogo_ateneo` |
| FSL | `fsl` (scuole, convenzioni, valutazioni) |
| Tutorato | `tutorato`, `tutorato_registro` |
| Didattica | `didattica` (moduli, pratiche, uffici, iter), `convalide` (protocollo, PDF/A, segreteria), `sedute` (consigli, verbali, firme) |

---

## 1. SETUP PSR-4 E COMPOSER

### 1.1 Tecnologia scelta
- **PHP ≥ 8.1** (enum, proprietà `readonly`, `match`, tipi union). Primo controllo dello Step 0: versione PHP di `/opt/lampp/bin/php`. Se il server fosse fermo a 8.0 si rinuncia a enum/readonly finché non si aggiorna XAMPP, il resto del piano non cambia.
- **Composer** solo sulle macchine di sviluppo. In produzione non serve eseguirlo: si carica la cartella `vendor/` generata (inizialmente contiene **solo** l'autoloader di Composer, zero librerie).
- **Nessun framework** (Laravel/Symfony): imporrebbero una riscrittura e un cambio di modello di rilascio. Si adottano i loro principi (PSR-4, DI, Repository/Service) con codice nostro, piccolo e leggibile.
- Strumenti di qualità **solo in sviluppo** (`require-dev`, mai caricati sul server): PHPUnit (test di `src/`), PHPStan (analisi statica, livello crescente), PHP-CS-Fixer (stile PSR-12 solo su `src/` e `tests/`).

### 1.2 Struttura delle cartelle

```
/                       (pagine legacy: restano dove sono, stessi URL)
├── composer.json
├── composer.lock
├── vendor/             (generato: autoload ottimizzato; caricato sul server; protetto da .htaccess)
├── src/                (codice OOP, namespace App\ ; protetto da .htaccess)
│   ├── bootstrap.php   (crea il Container e registra i servizi; incluso da config.php)
│   ├── Core/           (Config, Database, Container, Session, Clock, Http\Request, Http\Response, Csrf)
│   ├── Infrastructure/ (Mail, Audit, Storage, Pdf, Docx, Xlsx, Logger)
│   ├── Auth/           (Utente corrente, sincronizzazione SSO, politiche di accesso)
│   ├── Portale/        (Testata, Menu, Home, Organizzazione)
│   ├── Eventi/         (Area, Evento, Turno, Agenda, Seminari, Avvisi, Report)
│   ├── Iscrizioni/     (Prenotazione, ListaAttesa, Attestato)
│   ├── Risorse/        (Risorsa, PrenotazioneRisorsa, Calendario)
│   ├── Anagrafi/       (Persona, Docente, CorsoStudio, Insegnamento, CatalogoAteneo, Scuola)
│   ├── Fsl/            (Convenzione, Valutazione)
│   ├── Tutorato/       (Incarico, Registro, Firme)
│   ├── Didattica/      (Moduli, Pratiche, Uffici, Iter, Protocollo, Convalide, Sedute, Consigli)
│   └── Legacy/         (adattatori temporanei verso funzioni non ancora migrate)
├── tests/              (PHPUnit: Unit/ e Integration/; protetto da .htaccess)
├── inc/                (procedurale: si svuota modulo per modulo, le funzioni diventano facciate)
└── strumenti/prove/esegui.php   (prove esistenti: restano e crescono)
```

### 1.3 `composer.json` (da creare allo Step 1)

```json
{
    "name": "dibest/didattica",
    "description": "Portale Didattica DiBEST",
    "type": "project",
    "require": {
        "php": ">=8.1",
        "ext-mysqli": "*",
        "ext-mbstring": "*",
        "ext-json": "*"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.5",
        "phpstan/phpstan": "^1.11",
        "friendsofphp/php-cs-fixer": "^3.50"
    },
    "autoload": {
        "psr-4": { "App\\": "src/" }
    },
    "autoload-dev": {
        "psr-4": { "Tests\\": "tests/" }
    },
    "config": {
        "optimize-autoloader": true,
        "platform": { "php": "8.1.0" },
        "sort-packages": true
    },
    "scripts": {
        "test": ["phpunit", "php strumenti/prove/esegui.php"],
        "stan": "phpstan analyse src --level=5",
        "cs": "php-cs-fixer fix src tests --rules=@PSR12",
        "build": "composer install --no-dev --classmap-authoritative"
    }
}
```

- `platform.php` blocca le dipendenze alla versione del server, così ciò che funziona in sviluppo funziona anche lì.
- Prima di ogni rilascio: `composer build` → `vendor/` contiene solo l'autoload (nessuna libreria di sviluppo). `vendor/` **va versionato** sul ramo di rilascio (o caricato insieme ai file), perché sul server Composer non c'è.

### 1.4 Aggancio senza rompere nulla
Una sola riga in `config.php`, subito dopo la creazione di `$conn`:

```php
// Autoload delle classi App\ (src/) e servizi condivisi; se manca vendor/ il sito continua in modalità solo procedurale
if (is_file(__DIR__ . '/vendor/autoload.php')) require_once __DIR__ . '/src/bootstrap.php';
```

`src/bootstrap.php` include `vendor/autoload.php`, crea il Container e registra la `Database` **attorno alla stessa `$conn`** (nessuna seconda connessione). Se il file manca, il sito si comporta esattamente come oggi: è il paracadute del primo rilascio.

### 1.5 Sicurezza delle nuove cartelle
`src/`, `vendor/`, `tests/` non devono mai essere raggiungibili dal web (come oggi `inc/`): in ognuna un `.htaccess` con `Require all denied`, più `composer.json`/`composer.lock` aggiunti al blocco `FilesMatch` del `.htaccess` principale. Le prove esistenti verificano già i 403 su `inc/`: si estendono alle nuove cartelle.

### 1.6 Convenzioni di codice
- `declare(strict_types=1);` in ogni file di `src/`.
- **Linguaggio di dominio in italiano** (come il codice e le tabelle attuali: `Pratica`, `Seduta`, `Ufficio`, metodi `trovaPerId`, `assegna`), **ruoli tecnici in inglese** (`Repository`, `Service`, `Controller`) per restare riconoscibili a chiunque conosca i pattern.
- Una classe per file, nome file = nome classe, PSR-12.
- Nessun `echo`/`header()`/`exit` dentro Repository e Service: solo valori di ritorno ed eccezioni di dominio.
- Le classi non leggono `$_GET`, `$_POST`, `$_SESSION`, `$_SERVER` direttamente: li ricevono (Request, Session, CurrentUser).

---

## 2. CORE & DATABASE ISOLATION

### 2.1 Obiettivo
Un'unica classe `App\Core\Database` iniettata nei costruttori dei Repository al posto di `$conn` passato a mano o letto con `global`. Le funzioni procedurali continuano a ricevere `$conn` finché non vengono migrate.

### 2.2 Classe `App\Core\Database` (sopra mysqli, non PDO)
Si resta su **mysqli** nella prima fase: stesso driver, stessi tipi restituiti, stesso `SET time_zone` e charset, stesso comportamento di `mysqli_report(OFF)`. Passare a PDO in un secondo momento sarà un cambio interno alla classe, invisibile al resto del codice.

```php
namespace App\Core;

final class Database
{
    public function __construct(private \mysqli $conn) {}

    /** @return list<array<string,mixed>> */
    public function righe(string $sql, array $parametri = []): array;   // = db_righe()
    public function riga(string $sql, array $parametri = []): ?array;   // = db_riga()
    public function valore(string $sql, array $parametri = []): mixed;  // = db_valore()
    public function esegui(string $sql, array $parametri = []): int;    // = db_esegui(): righe toccate
    public function inserisci(string $sql, array $parametri = []): int; // restituisce l'id inserito, letto SUBITO
    public function transazione(callable $lavoro): mixed;               // begin/commit/rollback (FOR UPDATE delle prenotazioni)
    public function mysqli(): \mysqli;                                   // uscita di emergenza per il codice legacy
}
```

Note dalla codebase:
- `inserisci()` risolve il problema noto per cui `$conn->insert_id` viene azzerato da un `UPDATE` successivo (oggi si ripiega su `SELECT MAX(id)`).
- Tipi dei parametri ricavati come oggi negli helper (`i`/`d`/`s`, `null` gestito).
- Errori: oggi la maggior parte delle query fallisce in silenzio (`@$conn->query`). La classe registra sempre l'errore in `error_log` con la query (senza i valori dei parametri) e lancia `DatabaseException` **solo** nei metodi nuovi; le facciate legacy mantengono il comportamento silenzioso finché il chiamante non è migrato.

### 2.3 Facciate: gli helper esistenti delegano alla classe
In `inc/base.php` gli helper restano con la stessa firma, ma il corpo diventa:

```php
function db_righe($conn, string $sql, array $par = []): array {
    return \App\Core\App::db($conn)->righe($sql, $par);
}
```

Così 175 punti del codice passano subito dalla nuova classe senza essere toccati, e la classe viene collaudata dalle 450 prove esistenti.

### 2.4 Container (Dependency Injection)
- `App\Core\Container`: container minimale scritto in casa (registrazione `set(id, factory)`, `get(id)` con istanze condivise, autowiring per costruttori con classi tipizzate). Circa 80 righe, nessuna dipendenza. Se in futuro servisse di più si sostituisce con `php-di/php-di` senza cambiare le classi, perché queste ricevono le dipendenze nel costruttore e non conoscono il container.
- `src/bootstrap.php` registra: `Config`, `Database` (sulla `$conn` di `config.php`), `Session`, `Clock`, `Mailer`, `AuditLog`, `Storage`, e poi Repository e Service man mano che nascono.
- `App\Core\App` è un **ponte statico usato solo dal codice legacy** (`App::get(PraticaService::class)`, `App::db($conn)`). Le classi in `src/` non lo usano mai: ricevono tutto dal costruttore. Quando l'ultima facciata sparisce, sparisce anche il ponte.

### 2.5 Sostituzione delle variabili globali e superglobali

| Oggi | Domani | Come si arriva |
|---|---|---|
| `$conn` (in `config.php`, parametro di quasi tutte le funzioni) | `Database` iniettata | Facciate (2.3); le nuove classi non vedono più `$conn` |
| `.env` letto in `config.php`, costanti sparse (`URL_SITO`, `POSTI_SENZA_LIMITE`, `DIR_*`…) | `App\Core\Config` (sola lettura) | Si legge lo stesso file; le costanti restano definite per il legacy e Config le espone tipizzate |
| `$_SESSION['utente_id']`, `utente_ruolo_id`, `auth_meta`… | `App\Core\Session` + `App\Auth\UtenteCorrente` | `middleware.php` costruisce `UtenteCorrente` e continua a creare `$user_info`, `$u_id`, `$is_full_admin` per le pagine non migrate |
| `$_GET/$_POST/$_FILES/$_SERVER` dentro funzioni (`leggi_risposte_modulo`, `crea_pratica` legge IP e sessione) | `App\Core\Http\Request` passata ai Controller; i Service ricevono dati già validati (DTO) | Modulo per modulo |
| `date()`, `time()` sparsi (scadenze, promemoria, conservazione dati) | `App\Core\Clock` | Permette test deterministici di promemoria e scadenze |
| `inviaNotificaEmail()` chiamata ovunque | `App\Infrastructure\Mail\Mailer` (interfaccia) | La funzione resta e delega; nei test un `MailerFinto` (le prove oggi la ridefiniscono) |
| `static $cache` dentro le funzioni (`uffici_didattica`, `scelte_anagrafe_didattica`…) | Cache nei Repository, con `svuota()` esplicito | Elimina i casi di dati "vecchi" come quello emerso sugli uffici appena creati |

---

## 3. DOMAIN DRIVEN DESIGN

### 3.1 Strati e responsabilità

```
Pagina legacy / Controller  →  Service (regole, transazioni, eventi)  →  Repository (SQL)  →  Database
                                       ↓                                        ↑
                               Infrastructure (Mail, Pdf, Storage, Audit)   Model / Value Object
```

- **Model (Entity)**: oggetto con identità e stato del dominio (`Pratica`, `Seduta`). Costruito dal Repository (`daRiga(array)`), espone metodi di dominio (`$pratica->eConclusa()`, `$pratica->richiedeBollo()`), non contiene SQL.
- **Value Object**: immutabili, confrontati per valore: `CodiceFiscale`, `Email`, `AnnoAccademico` (`"2025/2026"`), `Protocollo` (numero + data), `Cfu`, `IntervalloDate`.
- **Enum** (PHP 8.1) al posto delle costanti array: `StatoPratica` (oggi `STATI_PRATICA`), `EsitoSeduta`, `EsitoConvalida`, `TipoUfficio` (`protocollo`, `cdl`, `segreteria`), `StatoPrenotazione`, `MetodoAccesso` (SPID, CIE, Ateneo), `StatoVerbale`. Ogni enum offre `etichetta()` e `colore()` per le viste: le costanti attuali restano come alias finché servono.
- **Repository**: tutto e solo l'SQL di un aggregato. Restituiscono Model/array tipizzati, accettano criteri espliciti (`cercaPerFiltri(FiltriPratiche $f)`). Uno per aggregato.
- **Service (Application Service)**: un caso d'uso per metodo (`registraProtocollo`, `inviaAllaSegreteria`), apre la transazione, coordina Repository e Infrastructure, scrive l'audit, lancia eccezioni di dominio (`PraticaNonTrovata`, `ProtocolloNonValido`). È l'unico posto delle regole di business.
- **Domain Service / Policy**: regole riusabili senza stato: `PoliticaAccessoPratica` (oggi `utente_vede_pratica`), `CalcoloPostiDisponibili` (oggi `getPostiOccupati`, definita in `config.php`, + soglia `POSTI_SENZA_LIMITE`), `RisolutoreIter` (oggi `iter_modulo` con `@cdl`/`@segreteria`).
- **Presenter/View helper**: le funzioni `html_*` diventano classi di presentazione in `src/<Modulo>/Vista/` (stesso HTML, prima) e solo alla fine, eventualmente, template.

### 3.2 Entità principali per contesto (bounded context)

| Contesto | Entità / aggregati | Tabelle principali | Repository | Service |
|---|---|---|---|---|
| **Auth & Utenti** | `Utente`, `Ruolo`, `Abilitazione`, `UtenteCorrente`, `MetadatiAccesso` | `utenti`, `ruoli`, `abilitazioni_ambito`, `abilitazioni_attesa`, `log_accessi`, `rate_limit_attempts` | `UtenteRepository`, `AbilitazioneRepository` | `SincronizzazioneSso`, `ServizioPermessi` (oggi `ha_modulo`, `utente_ha_abilitazioni`, `utente_gestisce_didattica`) |
| **Portale** | `VoceMenu`, `Slide`, `ConfigurazionePortale`, `OrganizzazioneHome` | `menu_voci`, `slide_home`, `configurazione_portale`, `copie_configurazione`, `impostazioni_sistema` | `MenuRepository`, `ConfigurazioneRepository` | `ServizioOrganizzazione` (proposta, backup, ripristino) |
| **Eventi** | `Area` (`pagine_eventi`), `Evento`, `Turno`, `Seminario`, `IscrizioneAvvisi` | `pagine_eventi`, `eventi`, `turni`, `progetti_dettagli`, `sottocategorie`, `avvisi_eventi`, `avvisi_iscrizioni` | `AreaRepository`, `EventoRepository`, `TurnoRepository`, `AvvisoRepository` | `ServizioAgenda`, `ServizioAvvisi`, `ReportAmbiti` |
| **Iscrizioni** | `Prenotazione`, `ListaAttesa`, `Attestato`, `Sondaggio` | `prenotazioni` (stati, lista d'attesa, presenze e attestati), `partecipanti_prenotazione`, `messaggi_prenotazioni`, `campi_form`, `sondaggi`, `sondaggi_domande`, `sondaggi_risposte` | `PrenotazioneRepository`, `AttestatoRepository` | `ServizioPrenotazioni` (con `FOR UPDATE` anti-overbooking), `ServizioListaAttesa`, `ServizioAttestati`, `ServizioSondaggi` |
| **Risorse** | `Risorsa`, `PrenotazioneRisorsa`, `Sportello` | `risorse`, `risorse_orari`, `risorse_chiusure`, `prenotazioni_risorse` | `RisorsaRepository` | `ServizioCalendarioRisorse` |
| **Anagrafi** | `Persona`, `Docente`, `CorsoStudio`, `Insegnamento`, `Scuola`, `CorsoCatalogo` | `personale_ateneo`, `personale_modifiche`, `anagrafe_strutture`, `corsi_studio`, `insegnamenti`, `scuole`, `ateneo_cds`, `ateneo_insegnamenti`, `ateneo_insegnamenti_scaricati` | `PersonaRepository`, `CorsoRepository`, `InsegnamentoRepository`, `CatalogoAteneoRepository` | `SincronizzazioneAnagrafe`, `ClientApiAteneo` |
| **FSL** | `Convenzione`, `ConvenzioneCompilata`, `ValutazioneFsl` | `convenzioni_scuole`, `convenzioni_compilate`, `valutazioni_fsl` | `ConvenzioneRepository` | `ServizioConvenzioni` (documenti Word, PAdES) |
| **Tutorato** | `Bando`, `Incarico`, `AttivitaRegistro`, `Firma` | `tutorato_bandi`, `tutorato_incarichi`, `tutorato_eventi`, `tutorato_registro` | `IncaricoRepository`, `RegistroRepository` | `ServizioIncarichi`, `ServizioFirmePades`, `ServizioRegistro` |
| **Didattica – Pratiche** | `Modulo` (con `Campo`, `ColonnaTabella`, `Condizione`), `Pratica`, `EventoPratica`, `Risposta`, `DecisioniConvalida`, `RigaConvalida` | `didattica_moduli`, `pratiche`, `pratiche_eventi`, `pratiche_operatori` | `ModuloRepository`, `PraticaRepository`, `EventoPraticaRepository` | `ServizioInvioPratica`, `ServizioIter`, `ServizioProtocollo`, `ServizioConvalide`, `ServizioSegreteria`, `GeneratoreDomandaPdfa` |
| **Didattica – Uffici** | `Ufficio` (con `TipoUfficio`), `Operatore` | `didattica_uffici`, `ufficio_didattica` | `UfficioRepository`, `OperatoreRepository` | `ServizioSmistamento` |
| **Didattica – Organi** | `Consiglio`, `ComponenteConsiglio`, `Seduta`, `Presenza`, `Convocazione`, `Verbale` | `didattica_consigli`, `didattica_consigli_persone`, `didattica_sedute`, `didattica_sedute_presenze`, `didattica_convocazioni` | `ConsiglioRepository`, `SedutaRepository` | `ServizioSedute`, `ServizioVerbale` (Word, PAdES), `ServizioEstratti` |
| **Infrastruttura** | — | `log_email`, `log_attivita`, `template_email` | — | `Mailer`, `AuditLog`, `Storage` (uploads protetti), `Pdf` (oggi `PdfSemplice` PDF/A), `Docx`, `Xlsx`, `Logger` |

Esempio di confine dai casi recenti: oggi `registra_protocollo_pratica()` legge la pratica, aggiorna protocollo e bollo, scrive due eventi, risolve l'ufficio del corso, assegna e manda email. Domani:

```php
final class ServizioProtocollo
{
    public function __construct(
        private PraticaRepository $pratiche,
        private ModuloRepository $moduli,
        private EventoPraticaRepository $eventi,
        private RisolutoreIter $iter,
        private ServizioSmistamento $smistamento,
        private Clock $orologio,
    ) {}

    public function registra(int $praticaId, Protocollo $protocollo, bool $bolloVerificato, Autore $autore): EsitoProtocollo
    {
        // regole: data non futura, bollo obbligatorio se il modulo lo richiede, passo successivo dell'iter
    }
}
```

e la funzione legacy diventa una facciata di 3 righe che converte parametri e risultato nel formato di oggi `[errore|null, messaggio]`.

### 3.3 Regole che restano invariate
- Lo schema del database non cambia per effetto del refactoring (le tabelle si rinominano solo se serve davvero, mai insieme a una migrazione di codice).
- Gli URL non cambiano. Le pagine diventano "controller sottili" restando file `.php` agli stessi indirizzi; un front controller con router è facoltativo e solo alla fine (Step 15).
- I testi visibili all'utente, le email e i PDF devono risultare identici prima e dopo ogni step (vedi prove di caratterizzazione).

---

## 4. ROADMAP A STEP INCREMENTALI

### Come si lavora in ogni step (vale per tutti)
1. **Prove di caratterizzazione prima di toccare il codice**: si fissano in `strumenti/prove/esegui.php` (o in `tests/Integration`) gli output attuali delle funzioni del modulo: righe del DB, HTML significativo, email intercettate, PDF (testo estratto e conformità veraPDF dove serve).
2. **Nuove classi in `src/`** + test unitari PHPUnit.
3. **Le funzioni legacy diventano facciate** con la stessa firma e lo stesso risultato. Nessuna pagina viene toccata in questa fase.
4. **Pagine/schede del modulo** convertite in controller sottili (azioni POST → Service; vista che usa Repository/Presenter).
5. **Verifica**: `composer test` (PHPUnit + 450 prove esistenti), PHPStan sul nuovo codice, giro manuale delle pagine del modulo in locale (`strumenti/locale`).
6. **Rilascio**: un PR dal ramo dello step verso `main`, elenco esatto dei file da caricare, `prove_server.sh` e `verifica_sito.sh` dopo l'upload.
7. **Rollback**: ricaricare i file della versione precedente (le facciate fanno sì che nessuno step richieda modifiche al database; se uno step le richiede, sono solo additive e retrocompatibili).
8. Le facciate si eliminano **solo** quando nessun file le chiama più (verifica con `grep` e PHPStan), in uno step di pulizia separato.

Ramificazione: `feature/refactoring-oop` è il ramo di coordinamento (questo piano, `composer.json`, Core). Ogni step lavora su `feature/refactoring-oop/step-NN-nome` e va in `main` (e quindi in produzione) appena verde, così la distanza dal codice in produzione resta piccola e le nuove funzionalità continuano a entrare normalmente.

---

### STEP 0 — Rete di sicurezza e inventario (nessun cambio in produzione)
- Verifica della versione PHP ed estensioni sul server (`prove_server.sh` già le elenca); decisione 8.1+ o 8.0.
- Inventario automatico delle funzioni `inc/` e dei loro chiamanti (script in `strumenti/`) per misurare l'avanzamento: "funzioni procedurali rimaste / facciate / migrate".
- Prove di caratterizzazione aggiuntive sulle parti meno coperte: prenotazione completa con lista d'attesa, generazione attestati, menu e home, esportazioni Excel/Word.
- **Fine step**: piano approvato, prove verdi, nessun file di produzione modificato.

### STEP 1 — Composer, autoload PSR-4 e bootstrap (le fondamenta, zero cambi di comportamento)
- `composer.json`, `vendor/` generato con `composer build`, `src/bootstrap.php`, `.htaccess` di protezione per `src/`, `vendor/`, `tests/`.
- Riga di aggancio condizionale in `config.php` (1.4).
- Prima classe spostata come prova del meccanismo: `PdfSemplice` / `TtfSemplice` → `App\Infrastructure\Pdf\Documento` e `\TrueType`, con `class_alias` in `inc/pdf.php` per i nomi vecchi.
- Prove: 403 su `src/`, `vendor/`, `tests/`, `composer.json`; PDF generati identici (stesse pagine, veraPDF conforme).
- **Rilascio**: `composer.json`, `composer.lock`, `vendor/`, `src/`, `.htaccess` nuovi, `config.php`, `inc/pdf.php`.

### STEP 2 — Core: Config, Database, Container, Session, Clock
- Classi del paragrafo 2; helper `db_*` di `inc/base.php` trasformati in facciate.
- `Config` legge lo stesso `.env` / `.env.locale` con la stessa logica di `config.php` (che continua a funzionare per il legacy).
- Le prove esistenti girano sopra la nuova `Database` senza modifiche: è la verifica principale.
- **Rilascio**: `src/Core/*`, `src/bootstrap.php`, `inc/base.php`.

### STEP 3 — Infrastruttura condivisa: Mailer, AuditLog, Storage, Logger, documenti
- `inviaNotificaEmail()` → `Mailer` (client SMTP attuale spostato in classe, stesso registro `log_email`); `registra_log_audit()` (`inc/sistema.php`, tabella `log_attivita`) → `AuditLog`; upload protetti (`secure_upload`, cartelle con `.htaccess` automatico, `allegato_pratica.php`) → `Storage`; Word/Excel (`docx_*`, `xlsx_crea`) → `Infrastructure\Documenti`.
- Tutte le funzioni restano come facciate: nessuna pagina cambia.
- **Perché qui**: ogni modulo successivo ne ha bisogno; migrarli prima evita di toccare due volte gli stessi file.

### STEP 4 — Modulo pilota: Avvisi (periferico, piccolo, completo)
- `inc/avvisi.php` (7 funzioni) + `avvisi.php`: `AvvisoRepository`, `ServizioAvvisi`, enum degli ambiti, controller sottile per la pagina.
- È il **modello di riferimento** per tutti gli step successivi: struttura delle cartelle, test, facciate, controller, elenco file di rilascio. Si sceglie un modulo periferico come pilota proprio perché un errore qui non tocca login, prenotazioni o pratiche.
- **Fine step**: pagina avvisi identica, email di avviso identiche, documento "come migrare un modulo" aggiunto in fondo a questo piano con le lezioni apprese.

### STEP 5 — Autenticazione, utente corrente e permessi
- `UtenteCorrente` costruito dal `middleware.php` (che continua a esporre `$user_info`, `$u_id`, `$is_full_admin`, `$is_gestore` per le pagine non migrate); `ServizioPermessi` per `ha_modulo`, `utente_ha_abilitazioni`, `utente_gestisce_didattica`, `require_admin_or_gestore`; `MetadatiAccesso` (SPID/CIE/Ateneo) per `auth_meta`.
- `saml_login.php` e `sync_sso_user()`: il codice SimpleSAML **si sposta senza cambiare logica** in `SincronizzazioneSso`, con prove sul percorso locale `__accesso` e controllo manuale del login reale in un orario di basso traffico.
- CSRF (`csrf_field`/`csrf_verify`, `inc/sistema.php`) e rate limit (`check_rate_limit`, `inc/base.php`) → `Core\Csrf`, `Core\RateLimiter`.
- **Perché dopo il pilota**: è il punto più critico del sito; ci si arriva con Core, infrastruttura e procedura già collaudati.

### STEP 6 — Portale: testata, menu, home, organizzazione proposta
- `inc/sezioni.php`, `aspetto.php`, `organizzazione.php` → `Portale\*`; `header.php` diventa un presenter (la recente regressione del menu dovuta a una variabile `$m` riusata nell'header è esattamente il tipo di errore che le variabili locali delle classi eliminano).
- Prova da aggiungere prima: confronto dell'HTML del menu per utente anonimo e amministratore.

### STEP 7 — Anagrafi e catalogo di Ateneo (sola lettura, molto usati)
- `anagrafi.php`, `catalogo_ateneo.php`, `cerca_insegnamenti.php`, `cerca_scuole.php`, `cerca_personale.php`: Repository e `ClientApiAteneo` (chiamate HTTP alle API di Ateneo isolate e simulabili nei test).
- Servono a Eventi, FSL, Tutorato e Didattica: migrarli presto semplifica tutto il resto.

### STEP 8 — Eventi: aree, eventi, turni, agenda, seminari, report per ambito
- `eventi_progetti.php`, `agenda.php`, `seminari.php`, `report_ambiti.php`, pagine `area.php`, `agenda.php`, `agenda_ics.php`, schede admin eventi/progetti.

### STEP 9 — Iscrizioni: prenotazioni, liste d'attesa, check-in, attestati, sondaggi
- Cuore del portale e unico punto con concorrenza (`getPostiOccupati(..., FOR UPDATE)`): `ServizioPrenotazioni::prenota()` dentro `Database::transazione()`, con prova di concorrenza (due prenotazioni parallele sull'ultimo posto) da aggiungere prima di migrare.
- Cron (`cron_attestati.php`, `cron_background.php`, `admin/cron_reminders.php`) come comandi che chiamano i Service.

### STEP 10 — Risorse, aule e sportelli di ricevimento
- `risorse.php`, `calendario_risorse.php`, `prenotazioni_risorse`, `ricevimento.php`, `risorsa_ics.php`.

### STEP 11 — FSL
- `fsl.php` (convenzioni compilate online, documenti Word, firme PAdES, valutazioni).

### STEP 12 — Tutorato
- `tutorato.php`, `tutorato_registro.php`, `incarico.php`, `firma_incarico.php`, `registro_tutorato.php`: `ServizioFirmePades` condiviso poi da Sedute (verbali) — firma remota Aruba isolata dietro un'interfaccia `FirmaRemota` (simulabile nei test).

### STEP 13 — Didattica: uffici, moduli, pratiche, iter, protocollo, convalide, segreteria
- Il modulo più grande (`didattica.php` 80 funzioni + `convalide.php` 20) e il più ricco di regole. Si divide in sotto-step rilasciabili:
  - 13a `Uffici` e `Operatori` (+ `TipoUfficio`, risoluzione ufficio del corso);
  - 13b `Modulo` e campi (costruttore, logica condizionale, colonne tipizzate, lettura delle risposte: oggi `campi_modulo`, `leggi_risposte_modulo`);
  - 13c `Pratica` e iter (`crea_pratica`, `assegna_pratica`, stati, eventi, email);
  - 13d Protocollo, marca da bollo, domanda PDF/A (`GeneratoreDomandaPdfa`), invio alla segreteria;
  - 13e Convalide e piano di studi (`DecisioniConvalida`, frasi per verbale ed estratto).
- Le schede `admin/didattica_*.php` diventano controller sottili; `modulo.php`, `pratiche.php`, `allegato_pratica.php` idem.
- Il flusso protocollo → ufficio del corso → segreteria diventa configurazione del `Modulo` gestita dal `ServizioIter`: è la base generica per tutte le pratiche future richieste.

### STEP 14 — Sedute, consigli e verbali
- `sedute.php` e parte di `didattica.php` (consigli, presenze, convocazioni, decisioni in seduta, verbale Word, estratti PDF/A, firma PAdES segretario → coordinatore).

### STEP 15 — Schema, cron, pulizia e (facoltativo) front controller
- `assicura_schema()` → `App\Core\Migrazioni` con una classe per versione (v44, v45…), stesso marcatore in `cache/`, stessa idempotenza.
- Eliminazione delle facciate non più chiamate, di `App\Core\App` (ponte statico) dove possibile, delle costanti array sostituite dagli enum.
- Facoltativo: `public/index.php` con router e template (Twig o PHP puro) mantenendo gli stessi URL tramite le regole del `.htaccess` esistente; si valuta solo quando tutte le pagine sono già controller sottili.
- PHPStan portato al livello 8 su tutto `src/`.
- **Esito (fatto)**: lo schema era già in `App\Core\Schema\Migrazioni` (Step 2: versione unica, stesso marcatore `cache/schema_vNN.ok`, stessa idempotenza: non serve una classe per versione); tolte le 42 facciate di `inc/` senza più chiamanti; `src/Legacy/` eliminata (restano `MailerDaFunzione`, perché le email passano da `inviaNotificaEmail()` per il registro e l'intercettazione nelle prove, e `CampiAnagrafeIscrizioni`, per la variabile globale letta dal piè di pagina); firme PAdES in `App\Infrastructure\Pdf\FirmePades`; PHPStan livello 8 pulito con `phpstan.neon.dist` (unica esclusione: i tipi dei valori degli array, 148 punti, da precisare man mano). Non fatti: enum al posto delle costanti array e front controller (facoltativo, ci sono ancora pagine con query dirette); `App\Core\App` resta finché le pagine sono procedurali.

---

### Ordine e dipendenze in sintesi

```
0 Rete di sicurezza
└─ 1 Composer/autoload ─ 2 Core (DB, Config, DI) ─ 3 Infrastruttura (Mail, Audit, Storage, Documenti)
                                                     ├─ 4 Avvisi (pilota)
                                                     ├─ 5 Auth & permessi
                                                     ├─ 6 Portale
                                                     └─ 7 Anagrafi ─┬─ 8 Eventi ─ 9 Iscrizioni ─ 10 Risorse
                                                                    ├─ 11 FSL
                                                                    ├─ 12 Tutorato ─┐
                                                                    └─ 13 Didattica ─┴─ 14 Sedute
                                                                                          └─ 15 Pulizia e migrazioni
```

Gli step 8–14 dipendono solo da 1–7 e possono essere riordinati in base alle priorità del Dipartimento (es. Didattica prima di Eventi se arrivano nuove pratiche da gestire).

### Criteri di "step completato"
- Tutte le prove esistenti verdi + nuovi test PHPUnit del modulo verdi.
- PHPStan senza errori sul nuovo codice al livello concordato.
- Nessuna query SQL nelle pagine del modulo né nei Service (solo nei Repository).
- Funzioni legacy del modulo ridotte a facciate (o rimosse se senza chiamanti).
- Pagine e PDF verificati a mano in locale; elenco file di rilascio pronto.
- Dopo l'upload: `prove_server.sh` e `verifica_sito.sh` verdi.

### Rischi principali e contromisure

| Rischio | Contromisura |
|---|---|
| `vendor/` mancante o incompleto in produzione | Aggancio condizionale (1.4) nel primo rilascio; dal secondo step `prove_server.sh` controlla la presenza dell'autoload |
| Differenze sottili di comportamento (tipi restituiti da mysqli, stringhe vs interi, errori silenziosi) | Stesso driver mysqli; facciate con risultato identico; prove di caratterizzazione prima di ogni step |
| Login SSO/SAML rotto | Step 5 dopo il pilota, logica spostata senza modifiche, prova reale in orario di basso traffico, rollback immediato dei soli file dello step |
| Overbooking delle prenotazioni | Transazione e `FOR UPDATE` conservati in `ServizioPrenotazioni`; prova di concorrenza dedicata |
| Lavoro in parallelo con nuove funzionalità | Step brevi che vanno in `main` appena verdi; nuove funzionalità scritte direttamente come classi in `src/` per i moduli già migrati |
| Deriva del piano | Inventario automatico (Step 0) aggiornato a ogni step: numero di funzioni procedurali rimaste per modulo |

---

## 5. GUIDA OPERATIVA: COME SI MIGRA UN MODULO (dal pilota Avvisi, Step 4)

Riferimento completo: `src/Avvisi/` (Model `Iscrizione`, `IscrizioneRepository`, `ServizioAvvisi`), `inc/avvisi.php` (facciate),
`avvisi.php` (controller), `tests/Integration/Avvisi/ServizioAvvisiTest.php`.

### 5.1 Regole tassative
- PHP 8.2: `declare(strict_types=1);`, tipi su parametri e ritorni, constructor property promotion, `readonly` nei Model, `enum` dove servono.
- PSR-12 (`composer cs`); nessuna keyword `global`; nessun `$_GET/$_POST/$_SESSION/$_SERVER` dentro Repository e Service (li legge il controller e li passa).
- **Model** = dati puri (`final class` con proprietà `readonly`, `daRiga(array)`); **Repository** = tutto e solo l'SQL (via `App\Core\Database`, mai `mysqli` diretto);
  **Service** = regole del caso d'uso; **Vista** (`src/<Modulo>/Vista/`) = le vecchie funzioni `html_*`, che restituiscono lo stesso HTML.
- Comportamento esterno identico: stesso HTML, stesse email (testo, oggetto, colore), stessi dati scritti nel database, stessi rimandi e messaggi.
  Lo schema del database non si modifica.
- Le funzioni di `inc/` restano con **la stessa firma e lo stesso risultato** e diventano facciate:
  `return App::per($conn)->get(Servizio::class)->metodo(...)` (la connessione ricevuta decide il container: pagine, cron e prove usano connessioni diverse).
- Email dai servizi solo tramite l'interfaccia `App\Infrastructure\Mail\Mailer` (nel container è `MailerLegacy`, che passa da `inviaNotificaEmail()`:
  stesso registro, stesso ambiente locale, le prove automatiche le intercettano).
- Dipendenze verso moduli non ancora migrati: interfaccia nel proprio namespace + adattatore in `src/Legacy/` che chiama la vecchia funzione
  (esempio: `App\Eventi\FonteEventiAgenda` + `App\Legacy\EventiAgendaLegacy`). Chiamare dalle classi le vecchie funzioni di altri moduli è ammesso
  solo dentro un adattatore `src/Legacy/`.
- Registrazione nel container: autowiring per le classi concrete; per interfacce e parametri non di classe, `src/<Modulo>/Registrazione.php`
  con `public static function registra(Container $c): void` (scoperta da `App`, nessuna modifica a `src/Core/App.php`).
- Attenzione a `strict_types` quando si sposta codice: le funzioni native ricevono i tipi esatti (es. `htmlspecialchars((string) $x)`, `(int)` sugli id dal database).

### 5.2 Passi
1. **Fotografia prima**: elenco delle pagine del modulo (anonimo, amministratore, docente, studente) e
   `bash strumenti/confronta_pagine.sh foto <cartella_prima> <elenco>`; prove esistenti verdi.
2. Classi in `src/<Modulo>/` (Model, Repository, Service, Vista), spostando il codice delle funzioni di `inc/<file>.php`.
3. Funzioni di `inc/` → facciate. Prove: `php strumenti/prove/esegui.php` deve restare tutto verde senza toccare le prove esistenti.
4. Pagine del modulo → controller: le query dirette (`$conn->query/prepare`, `db_*`) diventano metodi dei Repository o dei Service;
   la parte HTML resta com'è.
5. Test nuovi: PHPUnit in `tests/Unit/<Modulo>` e `tests/Integration/<Modulo>` (con `Tests\Integration\DatabaseDiProva`, `Tests\Doppi\MailerFinto`);
   prove del portale in `strumenti/prove/oop/<modulo>.php` (funzioni) e `strumenti/prove/oop/pagine_<modulo>.php` (pagine via HTTP).
6. **Fotografia dopo** e `bash strumenti/confronta_pagine.sh confronta <prima> <dopo>`: nessuna differenza (salvo quelle dovute ai dati cambiati dalle prove).
7. `php strumenti/inventario_oop.php`: le funzioni del modulo risultano facciate, le pagine senza query dirette.

### 5.3 Ambiente di prova per lavorare in parallelo su più moduli
Ogni copia di lavoro usa database e porta propri, per non disturbare le altre:
`PROVE_DB_NAME=eventi_prova_<modulo> PROVE_DB_UNIT=eventi_prova_unit_<modulo> MYSQL_BIN=mysql php strumenti/prove/esegui.php`,
ambiente locale con `.env.locale` che punta a una copia del database (`eventi_locale_<modulo>`) e `PORTA=81xx bash strumenti/locale/avvia.sh`,
prove delle pagine con `BASE_LOCALE=http://127.0.0.1:81xx`.
