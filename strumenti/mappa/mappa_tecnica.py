# -*- coding: utf-8 -*-
"""Contenuto della mappa del portale per sviluppatori: stack, architettura, oggetti, funzioni, flussi, database, test e rilascio."""
from mappa_comune import fig, gal, card, griglia, passi, sezione, esc


import os, re


def versione_schema():
    """Versione dello schema letta da src/Core/Schema/Migrazioni.php (non si aggiorna a mano)."""
    p = os.path.join(os.path.dirname(__file__), '..', '..', 'src', 'Core', 'Schema', 'Migrazioni.php')
    m = re.search(r'VERSIONE\s*=\s*(\d+)', open(p, encoding='utf8').read())
    return m.group(1) if m else '?'


def cod(t):
    return '<pre><code>%s</code></pre>' % esc(t.strip('\n'))


def tab(intest, righe):
    return '<table><thead><tr>%s</tr></thead><tbody>%s</tbody></table>' % (
        ''.join('<th>%s</th>' % i for i in intest), ''.join('<tr>%s</tr>' % ''.join('<td>%s</td>' % c for c in r) for r in righe))


def box(x, y, w, h, testo, colore='#fdf2f4', bordo='#8e0f1f', t2=''):
    return ('<rect x="%d" y="%d" width="%d" height="%d" rx="8" fill="%s" stroke="%s" stroke-width="1.4"/>'
            '<text x="%d" y="%d" text-anchor="middle" font-size="14" font-weight="600" fill="#1e293b">%s</text>%s') % (
        x, y, w, h, colore, bordo, x + w // 2, y + (h // 2 - (6 if t2 else -5)), testo,
        ('<text x="%d" y="%d" text-anchor="middle" font-size="11.5" fill="#475569">%s</text>' % (x + w // 2, y + h // 2 + 12, t2)) if t2 else '')


def freccia(x1, y1, x2, y2, etichetta=''):
    s = '<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="#64748b" stroke-width="1.6" marker-end="url(#fr)"/>' % (x1, y1, x2, y2)
    if etichetta:
        s += '<text x="%d" y="%d" font-size="11" fill="#64748b" text-anchor="middle">%s</text>' % ((x1 + x2) // 2, (y1 + y2) // 2 - 4, etichetta)
    return s


def svg(w, h, corpo, titolo):
    return ('<figure><svg viewBox="0 0 %d %d" width="100%%" role="img" aria-label="%s"><defs><marker id="fr" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto">'
            '<path d="M0 0L10 5L0 10z" fill="#64748b"/></marker></defs>%s</svg><figcaption>%s</figcaption></figure>') % (w, h, esc(titolo), corpo, titolo)


def costruisci():
    S = []

    # 1 ------------------------------------------------------------------------------------------------
    S.append(('panoramica', 'Panoramica', sezione('panoramica', '1', 'Panoramica e stack',
        'Applicazione PHP monolitica «senza framework», in migrazione graduale da codice procedurale a classi (pattern <i>strangler fig</i>). Nessun build step, nessuna dipendenza Composer in produzione.',
        '<div class="kpi"><div><b>PHP 8.2</b><span>strict_types, senza framework</span></div><div><b>MySQL/MariaDB</b><span>mysqli, prepared statements</span></div>'
        '<div><b>≈ 395</b><span>classi in <code>src/</code> (51.000 righe)</span></div><div><b>74 + 56</b><span>pagine pubbliche + pannello</span></div>'
        '<div><b>≈ 90</b><span>tabelle (23 di base + 67 automatiche)</span></div><div><b>v' + versione_schema() + '</b><span>versione dello schema</span></div>'
        '<div><b>70 + 1.600</b><span>test PHPUnit + prove automatiche</span></div></div>'
        + tab(['Strato', 'Tecnologia', 'Note'], [
            ['Linguaggio', 'PHP 8.2+ (<code>declare(strict_types=1)</code> nelle classi)', 'Nessun <code>enum</code>/<code>readonly</code> obbligatorio: compatibile con 8.0 finché serve'],
            ['Web server', 'Apache + <code>mod_rewrite</code> (<code>.htaccess</code>); Nginx con <code>try_files</code>', 'Il codice non dipende dal nome della cartella (<code>/didattica</code>, <code>/eventi</code>…)'],
            ['Database', 'MySQL 5.7+ / MariaDB 10.3+ via <code>mysqli</code>', 'Un wrapper <code>App\\Core\\Database</code> sulla stessa connessione; <code>mysqli_report(OFF)</code>: errori silenziosi come da progetto'],
            ['Autenticazione', 'SimpleSAMLphp (SSO Unical, SPID/CIE) + sessione PHP', 'Classe <code>App\\Auth\\Saml\\AccessoSso</code>'],
            ['Front-end', 'Bootstrap 5.3 / Bootstrap Italia, Font Awesome 6, jQuery, DataTables, Select2, Chart.js, SortableJS, FullCalendar, TinyMCE, PDF.js', 'Tutto salvato in <code>assets/vendor</code>: nessun CDN a runtime'],
            ['PDF / Office', 'Generatore PDF proprio (<code>Documento</code>), Word/Excel via <code>ZipArchive</code> + XML', 'Nessuna libreria esterna: PDF/A, PAdES, DOCX, XLSX scritti in casa'],
            ['Email', 'Client SMTP interno (<code>SmtpMailer</code>), fallback <code>mail()</code>', 'STARTTLS/SSL, autenticazione, registro in <code>log_email</code>'],
            ['Test', 'PHPUnit 9.6 + suite «prove» (<code>strumenti/prove/esegui.php</code>)', 'Prove su un database usa-e-getta e, in locale, anche via HTTP'],
            ['Qualità', 'PHP-CS-Fixer (PSR-12), PHPStan (<code>phpstan.neon.dist</code>)', '<code>composer test</code>, <code>composer cs</code>'],
        ])
        + '<div class="info"><b>Licenza:</b> AGPL v3. Le modifiche eseguite su un server vanno rese disponibili agli utenti del servizio.</div>'
    )))

    # 2 ------------------------------------------------------------------------------------------------
    corpo = (box(30, 20, 840, 56, 'Pagine PHP (radice e admin/)', '#fff7ed', '#c2410c', 'azioni POST in alto (redirect + exit), HTML sotto · 74 + 56 file')
             + box(30, 100, 400, 56, 'Funzioni di facciata inc/', '#eff6ff', '#1d4ed8', 'db_*, csrf_field, flash_set, html_*, facciate delle classi')
             + box(470, 100, 400, 56, 'Viste (src/*/Vista)', '#eff6ff', '#1d4ed8', 'HTML di blocchi riusati, già estratto dalle pagine')
             + box(30, 180, 840, 56, 'Servizi (regole di dominio)', '#ecfdf5', '#15803d', 'ServizioPratiche, ServizioConvenzioneOnline, ServizioPrenotazioni, Direzione…')
             + box(30, 260, 400, 56, 'Repository (accesso ai dati)', '#ecfdf5', '#15803d', 'una classe per aggregato: PraticaRepository, UfficiRepository…')
             + box(470, 260, 400, 56, 'Infrastructure', '#f5f3ff', '#6d28d9', 'Mailer, Documento (PDF), Word, Excel, Upload, Cifratura, AuditLog')
             + box(30, 340, 840, 56, 'Core', '#f1f5f9', '#475569', 'Container (DI + autowiring) · App (ponte) · Database · Config · Sito · Orologio')
             + box(30, 420, 840, 50, 'MySQL / MariaDB  ·  file riservati in uploads/ (bloccati dal web)', '#fef2f2', '#b91c1c')
             + freccia(450, 76, 450, 100) + freccia(450, 156, 450, 180) + freccia(450, 236, 450, 260) + freccia(450, 316, 450, 340) + freccia(450, 396, 450, 420))
    S.append(('architettura', 'Architettura', sezione('architettura', '2', 'Architettura a strati',
        'Le pagine restano «a scheda» e parlano con le classi di <code>src/</code> tramite il container. Le classi non conoscono né il container né la sessione: ricevono tutto nel costruttore.',
        svg(900, 490, corpo, 'Strati: pagine → facciate/viste → servizi → repository/infrastruttura → core → database.')
        + tab(['Cartella', 'Contenuto', 'Regola'], [
            ['<code>/</code> (radice)', '74 pagine pubbliche, <code>config.php</code>, <code>functions.php</code>, <code>middleware.php</code>, cron', '<code>config.php</code> e <code>functions.php</code> sono negati dal web server'],
            ['<code>admin/</code>', '56 pagine del pannello; <code>admin_header.php</code> fa autenticazione, RBAC e menu', 'Ogni pagina «a scheda» ha le azioni POST in cima'],
            ['<code>inc/</code>', '28 file di funzioni per argomento (3.500 righe), caricati da <code>functions.php</code> in ordine fisso', 'Solo facciate e funzioni non ancora migrate'],
            ['<code>src/</code>', '19 namespace <code>App\\…</code> (PSR-4, autoload senza Composer)', 'Classi con costruttore tipizzato: niente globali'],
            ['<code>database/</code>', '<code>schema.sql</code> (23 tabelle di base)', 'Il resto lo crea <code>assicura_schema()</code>'],
            ['<code>strumenti/</code>', 'prove, ambiente locale, pacchetti, script', 'Bloccata al web; non entra nel pacchetto'],
            ['<code>modelli_documenti/</code>, <code>uploads/</code>, <code>cache/</code>', 'Modelli Word, file caricati, cache e marcatori di schema', '<code>uploads/</code> mai in git; <code>cache/</code> scrivibile da PHP'],
        ])
    )))

    # 3 ------------------------------------------------------------------------------------------------
    corpo = (box(10, 40, 130, 56, 'Richiesta', '#f1f5f9', '#475569', 'Apache + .htaccess')
             + box(170, 40, 150, 56, 'config.php', '#fff7ed', '#c2410c', 'sessione, header, .env, $conn')
             + box(350, 40, 170, 56, 'functions.php', '#eff6ff', '#1d4ed8', 'inc/*, App::avvia, schema')
             + box(550, 40, 170, 56, 'middleware.php', '#ecfdf5', '#15803d', 'SSO, $user_info, ruoli')
             + box(750, 40, 140, 56, 'Pagina', '#fdf2f4', '#8e0f1f', 'azioni + HTML')
             + freccia(140, 68, 170, 68) + freccia(320, 68, 350, 68) + freccia(520, 68, 550, 68) + freccia(720, 68, 750, 68)
             + '<text x="450" y="140" text-anchor="middle" font-size="12" fill="#64748b">le pagine pubbliche senza login (verbali, convenzioni con link) definiscono PAGINA_SENZA_SSO prima di config.php</text>')
    S.append(('richiesta', 'Ciclo di una richiesta', sezione('richiesta', '3', 'Il ciclo di una richiesta',
        'Quattro file di bootstrap, sempre nello stesso ordine. Cron e prove caricano solo <code>functions.php</code>.',
        svg(900, 160, corpo, 'Bootstrap: config.php → functions.php → middleware.php → pagina.')
        + '<ol><li><b><code>config.php</code></b>: apre la sessione con cookie <code>Secure</code>, <code>HttpOnly</code>, <code>SameSite=Lax</code>; imposta gli header di sicurezza (HSTS, CSP, X-Frame-Options…); legge <code>.env</code>; apre <code>$conn</code> (mysqli).</li>'
          '<li><b><code>functions.php</code></b>: include <code>inc/*.php</code> in ordine fisso, carica l\'autoload PSR-4 (<code>src/autoload.php</code>), avvia il container (<code>App::avvia($conn)</code>) e chiama <code>assicura_schema()</code>.</li>'
          '<li><b><code>middleware.php</code></b> (solo pagine protette): <code>sync_sso_user($conn)</code> ricollega l\'utente dalla sessione SSO; se manca, reindirizza a <code>saml_login.php?redirect=…</code>; poi espone <code>$u_id</code>, <code>$u_ruolo</code>, <code>$is_full_admin</code>, <code>$user_info</code>.</li>'
          '<li><b><code>admin/admin_header.php</code></b> (pannello): autentica, calcola i permessi (<code>$puo_fsl</code>, <code>$puo_didattica_tutto</code>, <code>$is_full_admin</code>…), disegna menu e barra superiore.</li></ol>'
        + '<h3>Una pagina tipica (schema)</h3>'
        + cod('''<?php
require_once 'middleware.php';                          // login + $user_info
$serv = \\App\\Core\\App::get(\\App\\Didattica\\ServizioPratiche::class);   // dal container

if ($_SERVER['REQUEST_METHOD'] === 'POST') {            // azioni in cima
    csrf_verify($_POST['csrf_token'] ?? '');
    $err = $serv->invia($_POST, $user_info);
    flash_set($err ?? 'Pratica inviata.', $err ? 'danger' : 'success');
    header('Location: pratiche.php'); exit;             // PRG: redirect + exit
}
$elenco = $serv->dello($user_info);                     // dati
require_once 'header.php'; ?>                           <!-- HTML sotto -->''')
        + '<div class="nota"><b>Errori silenziosi.</b> Le query che falliscono restituiscono <code>false</code>/<code>[]</code>/<code>null</code> (nessuna eccezione), come nel codice storico. Le classi nuove validano gli input e restituiscono messaggi d\'errore come stringa (<code>?string</code>) o <code>Esito</code>.</div>'
    )))

    # 4 ------------------------------------------------------------------------------------------------
    S.append(('routing', 'Routing e aree', sezione('routing', '4', 'Routing, aree e layout',
        'Non c\'è un router PHP: contano il file system e un <code>.htaccess</code>. Le aree create da pannello non generano file.',
        '<ul><li><b>URL puliti</b>: <code>/area_personale</code> → <code>area_personale.php</code> se il file esiste; nel pannello <code>admin/x</code> → <code>admin/x.php</code>.</li>'
        '<li><b>Aree dinamiche</b>: una riga di <code>pagine_eventi</code> (<code>slug</code>, <code>tipo_area</code>: <code>fsl</code>, <code>eventi</code>, <code>gruppi</code>, <code>calendario</code>) è servita da <code>area.php</code> tramite regola di rewrite; gli slug che coincidono con file reali sono rifiutati.</li>'
        '<li><b>Motore dei layout</b>: <code>master_template.php</code> (≈ 2.500 righe) disegna la pagina di un\'area con uno di otto layout: <code>grid</code>, <code>list</code>, <code>advanced_list</code>, <code>calendar</code>, <code>timeline</code>, <code>agenda</code>, <code>gruppi</code>, <code>progetti</code>. Le aree di tipo <code>calendario</code> includono <code>calendario_area.php</code>.</li>'
        '<li><b>Moduli del pannello</b>: <code>MODULI_PORTALE</code> (<code>inc/sezioni.php</code>) elenca cinque moduli (<code>orientamento</code>, <code>fsl</code>, <code>calendari</code>, <code>didattica</code>, <code>portale</code>); <code>PAGINE_MODULO</code> assegna ogni pagina al suo modulo.</li>'
        '<li><b>Pagine a schede</b>: <code>admin/didattica.php</code> prepara i dati comuni e include <code>didattica_&lt;scheda&gt;.php</code> due volte, con <code>$fase = \'azioni\'</code> e poi <code>\'vista\'</code>.</li></ul>'
        + cod('''# .htaccess (estratto)
Options -Indexes -MultiViews
<Files "config.php">      Require all denied </Files>
<FilesMatch "^\\.env">      Require all denied </FilesMatch>
RewriteEngine On
RewriteRule (^|/)vendor/ - [F,L]
RewriteRule ^(admin/.*)$ $1.php [NC,QSA,L]      # admin senza estensione
# file o cartella esistenti: si servono così; poi /nome -> nome.php; infine area.php?slug=…''')
        + gal(fig('map_adm_aree', '<b>Aree</b> (righe di <code>pagine_eventi</code>) gestite da <code>admin/aree.php</code>.'),
              fig('map_adm_impostazioni_area', '<b>Impostazioni area</b>: <code>layout_template</code>, colori e regole.'))
    )))

    # 5 ------------------------------------------------------------------------------------------------
    S.append(('core', 'Core', sezione('core', '5', 'Core: container, database, configurazione',
        'Poche classi, tutte in <code>App\\Core</code>. Le dipendenze si passano nel costruttore; il container le risolve da solo (<i>autowiring</i>).',
        tab(['Classe', 'Ruolo', 'API principale'], [
            ['<code>Container</code>', 'Dependency injection minimale, senza librerie. Servizi creati una volta e condivisi; rileva le dipendenze circolari.', '<code>set($id, fn)</code>, <code>istanza($id, $obj)</code>, <code>get(Classe::class)</code>, <code>has()</code>'],
            ['<code>App</code>', 'Ponte per il codice procedurale: un container per connessione (<code>WeakMap&lt;mysqli, Container&gt;</code>).', '<code>avvia($conn)</code>, <code>get(X::class)</code>, <code>per($conn)->get(X::class)</code>'],
            ['<code>Database</code>', 'Wrapper sulla connessione <code>mysqli</code> del portale, solo prepared statement. Tipi inferiti: int/bool → <code>i</code>, float → <code>d</code>, resto → <code>s</code>.', '<code>righe()</code>, <code>riga()</code>, <code>valore()</code>, <code>esegui()</code>, <code>inserisci()</code>, <code>comando()</code>, <code>transazione()</code>, <code>grezza()</code>'],
            ['<code>Config</code>', 'Valori dal file <code>.env</code> (righe che iniziano con <code>;</code> o <code>#</code> ignorate).', '<code>daFile()</code>, <code>testo()</code>, <code>intero()</code>, <code>booleano()</code>, <code>ha()</code>'],
            ['<code>Sito</code>', 'Radice su disco e indirizzo pubblico (calcolato o da <code>URL_SITO</code> per i cron).', '<code>radice()</code>, <code>urlBase()</code>'],
            ['<code>Orologio</code>', 'Interfaccia dell\'ora corrente: nei test si usa un orologio fisso.', '<code>adesso(): DateTimeImmutable</code>'],
            ['<code>Esito</code>', 'Risultato di un\'operazione (ok / errore con messaggio).', '<code>ok()</code>, <code>errore()</code>, <code>comeCoppia()</code>'],
        ])
        + '<h3>Esempi</h3>'
        + cod('''// Una classe riceve le dipendenze: il container le trova da sola
final class ServizioMessaggiConvenzione
{
    public function __construct(
        private ConvenzioneCompilataRepository $compilate,
        private MessaggiConvenzioneRepository $messaggi,
        private NotificheConvenzioni $notifiche
    ) {}
}

// Dalle pagine / facciate procedurali
$srv = \\App\\Core\\App::get(ServizioMessaggiConvenzione::class);
$srv = \\App\\Core\\App::per($conn)->get(ServizioMessaggiConvenzione::class);   // per un'altra connessione (cron, prove)

// Database: prepared statement, tipi inferiti
$righe = $db->righe('SELECT * FROM uffici_persone WHERE ufficio_id = ? AND email = ?', [$id, $email]);
$nuovoId = $db->inserisci('INSERT INTO uffici (nome, ambito) VALUES (?, ?)', [$nome, 'dipartimento']);
$db->transazione(fn () => $this->spostaTutto());''')
        + '<p>Quando un servizio ha bisogno di un\'implementazione diversa si registra nella <code>Registrazione</code> del namespace (scoperta da <code>App</code>):</p>'
        + cod('''final class Registrazione
{
    public static function registra(Container $c): void
    {
        $c->set(AuleTurni::class, static fn (Container $c): AuleTurni => $c->get(ServizioAuleTurni::class));
    }
}''')
    )))

    # 6 ------------------------------------------------------------------------------------------------
    S.append(('convenzioni', 'Convenzioni di codice', sezione('convenzioni', '6', 'Convenzioni di codice',
        'Ogni modulo segue lo stesso schema, e i nomi sono in italiano (dominio) per restare leggibili a chi lavora con gli uffici.',
        tab(['Suffisso / pattern', 'Cosa fa', 'Esempio'], [
            ['<code>*Repository</code>', 'Accesso ai dati di un aggregato. Nessuna regola di dominio.', '<code>PraticaRepository</code>, <code>UfficiRepository</code>, <code>ConvenzioneCompilataRepository</code>'],
            ['<code>Servizio*</code>', 'Regole di dominio e orchestrazione. Restituisce <code>?string</code> (errore) o dati; non produce HTML.', '<code>ServizioConvenzioneFirmata</code>, <code>ServizioUffici</code>, <code>ServizioIter</code>'],
            ['<code>Vista/*</code>', 'HTML di blocchi riusati (estratto dalle pagine).', '<code>PaginaAttestati</code>, <code>FlashVista</code>'],
            ['<code>Notifiche*</code>', 'Preparano i dati delle email di un modulo e le spediscono con i modelli del catalogo.', '<code>NotificheConvenzioni</code>, <code>NotifichePratiche</code>'],
            ['<code>Costanti</code>', 'Costanti di dominio del modulo (stati, tipi, cartelle).', '<code>Fsl\\Costanti::ITER_PROTOCOLLATA</code>'],
            ['<code>Registrazione</code>', 'Registra nel container i servizi che non si risolvono da soli.', '<code>Risorse\\Registrazione</code>'],
            ['Facciata procedurale', 'Funzione di <code>inc/</code> che inoltra a una classe. Resta finché esistono chiamanti legacy.', '<code>db_righe()</code> → <code>Database::righe()</code>'],
        ])
        + '<ul><li>Strategia <b>strangler fig</b>: si sposta un modulo alla volta (query → repository, regole → servizi, viste per ultime) lasciando le pagine funzionanti. Il piano è in <code>MIGRATION_PLAN.md</code>.</li>'
          '<li>Le classi di <code>src/</code> <b>non usano mai</b> <code>App</code>: è un ponte per le pagine, destinato a sparire con l\'ultimo codice procedurale.</li>'
          '<li><code>declare(strict_types=1)</code> ovunque, <code>final</code> per default, costruttori con <i>promoted properties</i>.</li>'
          '<li>Soglia di sicurezza: ogni cambiamento lascia verdi tutte le prove (<code>strumenti/prove/esegui.php</code>).</li></ul>'
    )))

    # 7 ------------------------------------------------------------------------------------------------
    S.append(('auth', 'Autenticazione e permessi', sezione('auth', '7', 'Autenticazione, ruoli e permessi',
        'Tre livelli di identità (SSO, SPID/CIE, link/password) e un RBAC a ruoli + perimetri.',
        '<h3>Identità</h3>'
        + tab(['Modo', 'Chi', 'Come'], [
            ['SSO di Ateneo', 'Studenti, docenti, PTA', '<code>saml_login.php</code> → <code>AccessoSso::accedi()</code> (SimpleSAMLphp); <code>sync_sso_user()</code> crea/aggiorna l\'utente, riconosce il tipo da <code>matricola_studente</code> / <code>matricola_dipendente</code>; dopo «Esci» nessun accesso automatico (<code>CookieUscito</code>)'],
            ['SPID / CIE', 'Esterni; conferma lettere di tutorato', 'Metodo e livello letti da attributi e contesto SAML (<code>metadati_accesso_saml()</code>); <code>INCARICHI_SOLO_SPID_CIE=1</code>'],
            ['Link personale con token', 'Scuole, aziende, relatori, Direttore', 'Token casuale (<code>random_bytes</code>) in tabella; pagine come <code>convenzione_online.php?t=</code>, <code>firma_pratica.php</code>; il Direttore apre il suo link <i>e</i> accede'],
            ['Password dedicata', 'Esterni sui verbali', '<code>verbali_accessi</code> (hash <code>password_hash</code>), sessione separata, rate limit; pagina con <code>PAGINA_SENZA_SSO</code>'],
        ])
        + '<h3>Ruoli e perimetri</h3>'
        + tab(['Elemento', 'Dettaglio'], [
            ['Ruoli (<code>ruoli</code>)', '1 Super Amministratore · 2 Gestore Area/Evento · 3 Studente · 4 Docente/Dipendente · 5 Ospite/Esterno; più <code>utenti.ruoli_secondari</code> (CSV)'],
            ['Moduli (<code>MODULI_PORTALE</code>)', 'Si abilita un utente a uno o più moduli (<code>ServizioAbilitazioni::haModulo()</code>)'],
            ['Perimetri (<code>abilitazioni_ambito</code>)', 'tutta l\'area · tutti i progetti · tutti gli eventi · attività scelte · FSL (tutto / solo convenzioni / solo scuole)'],
            ['Abilitazioni in attesa (<code>abilitazioni_attesa</code>)', 'Date a chi non ha ancora fatto accesso: si attivano al primo login con quell\'email'],
            ['Uffici (<code>uffici</code>, <code>uffici_persone</code>)', 'Appartenere a un ufficio dà accesso alle pratiche dell\'ufficio; i <i>compiti</i> (pratiche, sedute, ricevimento, bandi) aprono il pannello Didattica'],
            ['Flag calcolati', '<code>$is_full_admin</code>, <code>$puo_fsl</code>, <code>$puo_fsl_convenzioni</code>, <code>$puo_didattica_tutto</code>, <code>utente_operatore_ufficio()</code>… in <code>admin_header.php</code>'],
        ])
        + cod('''// Direzione: chi firma ora (vice se «Direttore assente»)
$dir = App::get(\\App\\Uffici\\Direzione::class);
$dir->chiFirma();                    // ['nome'=>…, 'email'=>…, 'cf'=>…]  (cf dall'utente collegato)
$dir->puoFirmare($utente);           // email o codice fiscale della persona in carica
$dir->puoImpostareAssenza($utente);  // Direttore, vice, segreteria, amministratori''')
        + '<h3>Difese di base</h3><ul><li><code>csrf_field()</code> / <code>csrf_verify()</code> (classe <code>Auth\\Csrf</code> sulla sessione) su ogni POST e AJAX.</li>'
          '<li><code>check_rate_limit($conn, $endpoint, $max, $finestra)</code> (<code>LimiteRichieste</code> + <code>rate_limit_attempts</code>) per IP sugli endpoint pubblici.</li>'
          '<li>Prenotazioni anonime: CAPTCHA interno (<code>CaptchaPrenotazione</code>), campo trappola, limite per IP.</li>'
          '<li><code>nega_accesso()</code> (<code>RispostaHttp::negaAccesso</code>) per ogni risorsa che non appartiene all\'area/ufficio dell\'utente.</li></ul>'
        + gal(fig('map_adm_utenti', '<b>Utenti e abilitazioni</b> (<code>admin/utenti.php</code>).'),
              fig('map_adm_uffici_direzione', '<b>Direzione e «Direttore assente»</b> (<code>admin/uffici.php</code>, <code>Direzione</code>).'))
    )))

    # 8 ------------------------------------------------------------------------------------------------
    S.append(('db', 'Database e migrazioni', sezione('db', '8', 'Database e migrazioni automatiche',
        'Non esistono script SQL da lanciare dopo un aggiornamento: lo schema si adegua da solo alla prima richiesta.',
        '<ul><li><b><code>Core\\Schema\\Definizioni</code></b>: <code>tabelle()</code> (<code>CREATE TABLE IF NOT EXISTS</code>), <code>colonne()</code> (<code>ALTER … ADD COLUMN</code> se manca), <code>tipi()</code> (correzioni di tipo per database vecchi).</li>'
        '<li><b><code>Core\\Schema\\Migrazioni::aggiorna()</code></b>: se esiste <code>cache/schema_v&lt;VERSIONE&gt;.ok</code> non fa nulla; altrimenti crea tabelle e colonne, poi <code>datiDiPartenza()</code> (seed, migrazioni di dati) e scrive il marcatore. Oggi <code>VERSIONE = ' + versione_schema() + '</code>.</li>'
        '<li>Si alza <code>VERSIONE</code> ogni volta che si tocca <code>Definizioni</code> o i dati di partenza.</li>'
        '<li>Le migrazioni di dati sono idempotenti con una guardia. Esempio: <code>migraUffici()</code> copia le 4 tabelle storiche negli <code>uffici</code>/<code>uffici_persone</code> unificati <i>una sola volta</i> (finché non esiste l\'ufficio <code>direzione</code>), preservando gli id e introducendo <code>op_id</code>, il numero stabile con cui <code>pratiche.assegnata_a</code> identifica l\'operatore.</li>'
        '<li>Il marcatore è condiviso tra database diversi sullo stesso codice: dopo un bump, su un secondo database va cancellato per rilanciare la migrazione.</li></ul>'
        + cod('''// Definizioni::colonne() — aggiunge la colonna solo se manca
'verbali_accessi_log' => [
    'utente' => "ADD COLUMN utente VARCHAR(150) DEFAULT ''",
],
// Migrazioni::datiDiPartenza() — guardia per eseguirla una volta
$giaFatto = (int) $this->db->valore("SELECT COUNT(*) FROM uffici WHERE chiave = 'direzione'") > 0;
if (!$giaFatto) { /* copia uffici, persone, uffici di Ateneo e remap dei bandi */ }''')
        + '<h3>Le tabelle per dominio</h3>'
        + tab(['Dominio', 'Tabelle principali'], [
            ['Utenti e accessi', '<code>ruoli</code>, <code>utenti</code>, <code>abilitazioni_ambito</code>, <code>abilitazioni_attesa</code>, <code>log_accessi</code>, <code>log_attivita</code> (audit), <code>log_email</code>, <code>rate_limit_attempts</code>'],
            ['Aree, eventi, prenotazioni', '<code>pagine_eventi</code>, <code>sottocategorie</code>, <code>eventi</code>, <code>progetti_dettagli</code>, <code>turni</code>, <code>prenotazioni</code>, <code>campi_form</code>, <code>messaggi_prenotazioni</code>, <code>partecipanti_prenotazione</code>, <code>sondaggi*</code>, <code>avvisi_*</code>'],
            ['Portale', '<code>configurazione_portale</code>, <code>slide_home</code>, <code>menu_voci</code>, <code>impostazioni_sistema</code>, <code>template_email</code>, <code>copie_configurazione</code>, <code>riepilogo_persone</code>'],
            ['Anagrafi', '<code>scuole</code>, <code>personale_ateneo</code>, <code>personale_modifiche</code>, <code>anagrafe_strutture</code>, <code>corsi_studio</code>, <code>insegnamenti</code>, <code>ateneo_cds</code>, <code>ateneo_insegnamenti*</code>'],
            ['Uffici', '<code>uffici</code>, <code>uffici_persone</code> (+ le 4 tabelle storiche, non più usate), <code>operatori_sostituzioni</code>'],
            ['Didattica', '<code>didattica_moduli</code>, <code>pratiche</code>, <code>pratiche_eventi</code>, <code>pratiche_firme</code>, <code>pratiche_conferme</code>, <code>pratiche_operatori</code>, <code>didattica_consigli*</code>, <code>didattica_sedute*</code>, <code>didattica_convocazioni</code>, <code>archivio_organi</code>, <code>verbali_accessi*</code>'],
            ['FSL e convenzioni', '<code>convenzioni_scuole</code>, <code>convenzioni_compilate</code>, <code>convenzioni_messaggi</code>, <code>convenzioni_tesi</code>, <code>valutazioni_fsl</code>'],
            ['Tutorato', '<code>tutorato_bandi</code>, <code>tutorato_incarichi</code>, <code>tutorato_eventi</code>, <code>tutorato_registro</code>, <code>tutorato_dichiarazioni</code>, <code>tutorato_accessi_dati</code>'],
            ['Email', '<code>email_modelli</code> (solo le modifiche ai testi: senza riga vale il testo originale del catalogo)'],
            ['Risorse', '<code>risorse</code>, <code>risorse_orari</code>, <code>risorse_chiusure</code>, <code>prenotazioni_risorse</code>, <code>prenotazioni_risorse_attesa</code>, <code>risorse_responsabili</code>, <code>risorse_uffici</code>, <code>risorse_contatori*</code>, <code>risorse_diario</code>, <code>risorse_scadenze</code>'],
        ])
        + '<div class="nota">I campi variabili (moduli di pratica, iter, risposte) sono salvati come JSON in colonne <code>*_json</code>: <code>didattica_moduli.campi_json</code>, <code>iter_json</code>, <code>domanda_json</code>, <code>verbale_json</code>; <code>pratiche.risposte_json</code>; <code>convenzioni_compilate.dati_json</code>.</div>'
    )))

    # 9 ------------------------------------------------------------------------------------------------
    S.append(('moduli', 'Moduli (namespace)', sezione('moduli', '9', 'I moduli e le classi principali',
        'Ogni namespace di <code>src/</code> è un modulo del portale, con le sue pagine e le sue tabelle.',
        tab(['Namespace', 'Responsabilità', 'Classi chiave', 'Pagine'], [
            ['<code>Core</code>', 'Container, DB, config', '<code>Container</code>, <code>App</code>, <code>Database</code>, <code>Config</code>, <code>Sito</code>, <code>Schema\\Definizioni</code>, <code>Schema\\Migrazioni</code>', '—'],
            ['<code>Auth</code>', 'Utenti, sessione, SSO, abilitazioni', '<code>Saml\\AccessoSso</code>, <code>Sessione</code>, <code>Csrf</code>, <code>LimiteRichieste</code>, <code>ServizioUtenti</code>, <code>Abilitazioni\\ServizioAbilitazioni</code>', '<code>saml_login.php</code>, <code>admin/utenti.php</code>'],
            ['<code>Anagrafi</code>', 'Persone, scuole, corsi, insegnamenti di Ateneo', '<code>ClientApiAteneo</code>, <code>SincronizzazioneAnagrafe</code>, <code>ServizioPersone</code>, <code>ServizioScuole</code>, <code>ServizioCatalogo</code>, <code>PermessiGestori</code>', '<code>admin/anagrafe_*.php</code>, <code>persona.php</code>'],
            ['<code>Eventi</code>', 'Eventi, progetti, turni, agenda, report', '<code>ServizioEventi</code>, <code>ServizioProgetti</code>, <code>ServizioTurni</code>, <code>ServizioAgenda</code>, <code>ServizioReport</code>, <code>ServizioSeminari</code>', '<code>admin/eventi.php</code>, <code>progetti.php</code>, <code>agenda.php</code>'],
            ['<code>Iscrizioni</code>', 'Prenotazioni dei turni, area personale, lista d\'attesa', '<code>ServizioPrenotazioni</code>, <code>ServizioDisponibilita</code>, <code>ServizioListaAttesa</code>, <code>ServizioCheckin</code>, <code>ServizioAreaPersonale</code>, <code>CaptchaPrenotazione</code>', '<code>area.php</code>, <code>area_personale.php</code>'],
            ['<code>Iscritti</code>', 'Gestione iscritti lato pannello, cron, badge, scanner', '<code>ServizioIscritti</code>, <code>ServizioCron</code>, <code>ServizioScanner</code>, <code>ServizioBadge</code>, <code>ServizioEmailMassiva</code>, <code>ServizioFormBuilder</code>', '<code>admin/iscritti.php</code>, <code>scanner.php</code>'],
            ['<code>Attestati</code>', 'Attestati e elenchi degli studenti', '<code>ServizioAttestati</code>, <code>ElencoStudenti</code>, <code>DatiAttestato</code>, <code>RegoleClasse</code>', '<code>elenco_studenti.php</code>, <code>stampa_attestato.php</code>, <code>verifica_attestato.php</code>'],
            ['<code>Fsl</code>', 'Formazione Scuola Lavoro e convenzioni con le scuole', '<code>ServizioConvenzioneOnline</code>, <code>ServizioConvenzioneFirmata</code>, <code>ServizioFirmaDirettore</code>, <code>ServizioMessaggiConvenzione</code>, <code>AllegatoAPdf/Word</code>, <code>ProgrammaFsl</code>, <code>ServizioDocumentiClasse</code>', '<code>convenzione_online.php</code>, <code>firma_convenzione.php</code>, <code>admin/fsl.php</code>'],
            ['<code>ConvenzioniTesi</code>', 'Convenzioni di tesi con enti e aziende', '<code>ServizioConvenzioniTesi</code>, <code>ConvenzioneTesiRepository</code>, <code>ModelloConvenzione</code>, <code>NotificheConvenzioniTesi</code>', '<code>convenzione_tesi.php</code>, <code>richiedi_convenzione_tesi.php</code>'],
            ['<code>Tirocini</code>', 'Tirocini curriculari: aziende (report TSP) e laboratori interni', '<code>ServizioAziende</code>, <code>ServizioLaboratori</code>, <code>LettoreFoglio</code> (xlsx/csv), <code>Province</code>', '<code>tirocini_aziende</code>, <code>tirocini_laboratori</code>; pagine <code>tirocini.php</code> (dopo l\'accesso), <code>admin/tirocini.php</code>'],
            ['<code>Didattica</code>', 'Moduli, pratiche, iter, firme, consigli, sedute, verbali, archivio', '<code>ServizioPratiche</code>, <code>ServizioIter</code>, <code>FirmaPratica</code>, <code>ServizioFlussoConvalide</code>, <code>ServizioMarcheBollo</code>, <code>ServizioSedute</code>, <code>ServizioConvocazioni</code>, <code>ArchivioVerbali</code>, <code>ServizioVerbaliPubblici</code>, <code>ModelliPratiche</code>, <code>DomandaPdfa</code>', '<code>modulo.php</code>, <code>pratiche.php</code>, <code>verbali.php</code>, <code>admin/didattica*.php</code>'],
            ['<code>Tutorato</code>', 'Bandi, lettere, firme, registro, pagamenti', '<code>ServizioIncarichi</code>, <code>ServizioRegistroTutorato</code>, <code>ServizioPagamenti</code>, <code>ServizioImportBando</code>, <code>FirmaRemotaAruba</code>, <code>VerificaPdfFirmato</code>, <code>Compenso</code>', '<code>incarico.php</code>, <code>firma_incarico.php</code>, <code>registro_tutorato.php</code>, <code>pagamenti_tutorato.php</code>'],
            ['<code>Email</code>', 'Testi delle email modificabili dal pannello, con segnaposto', '<code>ModelliEmail</code>, <code>InvioEmail</code>, <code>CatalogoEmail</code> e i cataloghi dei moduli (<code>CatalogoDidattica</code>, <code>CatalogoConvenzioni</code>, <code>CatalogoRisorse</code>, <code>CatalogoEventi</code>, <code>CatalogoSistema</code>)', '<code>admin/email.php</code>'],
            ['<code>Risorse</code>', 'Aule, laboratori, auto: prenotazioni a slot', '<code>ServizioRisorse</code>, <code>ListaAttesaRisorse</code>, <code>ServizioResponsabiliRisorse</code>, <code>ServizioDiarioBordo</code>, <code>ServizioContatoriRisorse</code>, <code>ServizioScadenzeRisorsa</code>', '<code>calendario_area.php</code>, <code>risorse_responsabile.php</code>, <code>diario_bordo.php</code>'],
            ['<code>Uffici</code>', 'Uffici di Dipartimento e Ateneo, Direzione', '<code>UfficiRepository</code>, <code>ServizioUffici</code>, <code>Direzione</code>', '<code>admin/uffici.php</code>, <code>direttore_assente.php</code>'],
            ['<code>Portale</code>', 'Home, menu, cruscotti, guide, riepiloghi', '<code>CruscottoDirettore</code>, <code>DaFarePersona</code>, <code>RiepilogoPersone</code>, <code>SospensionePromemoria</code>, <code>GuidePerRuolo</code>, <code>MenuRepository</code>, <code>OrganizzazioneProposta</code>', '<code>index.php</code>, <code>cruscotto_direttore.php</code>, <code>guida.php</code>'],
            ['<code>Sistema</code>', 'Controlli, backup, impostazioni', '<code>ControlloSito</code>, <code>FileEnv</code>, <code>ImpostazioniSistemaRepository</code>, <code>Backup\\VerificaBackup</code>', '<code>admin/sistema.php</code>, <code>admin/cron_backup.php</code>'],
            ['<code>Infrastructure</code>', 'Servizi tecnici condivisi', '<code>Mail\\SmtpMailer</code>, <code>Pdf\\Documento</code>, <code>Pdf\\FirmePades</code>, <code>Pdf\\TestoPdf</code>, <code>Documenti\\Word</code>/<code>Excel</code>, <code>Storage\\Upload</code>, <code>Sicurezza\\Cifratura</code>, <code>Audit\\AuditLog</code>', '—'],
            ['<code>Ateneo</code>, <code>Avvisi</code>, <code>Sondaggi</code>', 'Uffici di Ateneo (wrapper), avvisi per email, questionari', '<code>ServizioUfficiAteneo</code>, <code>ServizioAvvisi</code>, <code>ServizioSondaggi</code>', '<code>avvisi.php</code>, <code>sondaggio.php</code>'],
        ])
        + '<h3>Dalla schermata alle classi</h3><p>Le stesse pagine viste dall\'utente, con le classi che le servono.</p>'
        + gal(fig('map_pub_agenda', '<b>Agenda</b> · <code>agenda.php</code> → <code>ServizioAgenda</code>, <code>AgendaRepository</code>, <code>FonteEventiAgenda</code>; <code>agenda_ics.php</code> per il calendario.'),
              fig('map_pub_evento', '<b>Scheda evento</b> · <code>master_template.php</code> → <code>ServizioEventi</code>, <code>ServizioTurni</code>, <code>ServizioDisponibilita</code>, <code>ServizioPrenotazioni</code>.'),
              fig('map_area_studente', '<b>Area personale</b> · <code>area_personale.php</code> → <code>ServizioAreaPersonale</code>, <code>AreaPersonaleRepository</code>, <code>DaFarePersona</code>.'),
              fig('map_pub_verifica', '<b>Verifica attestato</b> · <code>verifica_attestato.php</code> → <code>ServizioAttestati</code>, <code>AttestatoRepository</code>, <code>DatiAttestato</code>.'),
              fig('stud_prenota', '<b>Prenotazione di una risorsa</b> · <code>calendario_area.php</code> → <code>CalendarioRisorse</code>, <code>ServizioRisorse</code>, <code>ListaAttesaRisorse</code>.'),
              fig('fsl_modulo', '<b>Convenzione online</b> · <code>convenzione_online.php</code> → <code>ServizioConvenzioneOnline</code>, <code>PrecompilazioneConvenzione</code>, <code>LogoScuola</code>.'),
              fig('fsl_documenti_pronti', '<b>Documenti generati</b> · <code>ModelliConvenzione</code>, <code>AllegatoAModello</code> → <code>AllegatoAPdf</code> e <code>AllegatoAWord</code>.'),
              fig('stud_modulo_tesi', '<b>Modulo a pagine</b> · <code>modulo.php</code> → <code>CampiModulo</code>, <code>HtmlCampi</code>, <code>LetturaRisposte</code>, <code>ServizioPratiche::crea()</code>.'),
              fig('proto_scheda', '<b>Protocollo e iter</b> · <code>admin/didattica_pratiche.php</code> → <code>ServizioFlussoConvalide</code>, <code>ServizioMarcheBollo</code>, <code>FlussoPratica</code>.'),
              fig('ref_seduta', '<b>Seduta guidata</b> · <code>admin/didattica_sedute.php</code> → <code>ServizioSedute</code>, <code>ServizioPuntiSeduta</code>, <code>DecisioniSeduta</code>, <code>TestoVerbale</code>.'),
              fig('map_adm_did_archivio', '<b>Archivio verbali</b> · <code>ArchivioVerbali</code>, <code>ScansioneArchivio</code>, <code>OrganiArchivio</code>, <code>TestoPdf</code>.'),
              fig('map_adm_tirocini', '<b>Tirocini</b> · <code>admin/tirocini.php</code> → <code>ServizioAziende::importa()</code> (report TSP, solo la «x»), <code>LettoreFoglio</code>, <code>Province</code>.'),
              fig('map_pub_tirocini_accesso', '<b>Pagina dopo l\'accesso</b> · <code>accesso_richiesto.php</code> → <code>richiedi_accesso()</code> (401 + pulsante verso <code>saml_login.php?redirect=</code>).'),
              fig('tesi_1_pannello_da_seguire', '<b>Convenzioni di tesi</b> · <code>admin/convenzioni_tesi.php</code> → <code>ServizioConvenzioniTesi</code>, <code>ModelloConvenzione</code>.'),
              fig('tutorato_1_dati_pagamento', '<b>Dichiarazione del tutor</b> · <code>dichiarazione_tutorato.php</code> → <code>ServizioDichiarazione</code>, <code>Cifratura</code> (IBAN).'),
              fig('resp_diario', '<b>Diario di bordo</b> · <code>diario_bordo.php</code> → <code>ServizioDiarioBordo</code>, <code>DiarioBordoRepository</code>.'),
              fig('map_dir_cruscotto', '<b>Cruscotto</b> · <code>cruscotto_direttore.php</code> → <code>CruscottoDirettore</code> (dieci gruppi, ognuno isolato in un <code>try</code>).'),
              fig('map_adm_iscritti', '<b>Iscritti</b> · <code>admin/iscritti.php</code> → <code>ServizioIscritti</code>, <code>FiltriIscritti</code>, <code>RisultatoMassivo</code>.'),
              fig('map_adm_form', '<b>Form Builder</b> · <code>ServizioFormBuilder</code>, <code>FormBuilderRepository</code>, tabella <code>campi_form</code>.'),
              fig('map_adm_statistiche', '<b>Statistiche</b> · <code>StatisticheRepository</code>, Chart.js, esportazione con <code>Excel</code>.'),
              fig('map_adm_menu', '<b>Menu</b> · <code>MenuRepository</code>, <code>ServizioMenu</code>, <code>CatalogoPagineMenu</code>, ordinamento con SortableJS.'),
              fig('map_adm_anagrafe_docenti', '<b>Anagrafe</b> · <code>SincronizzazioneAnagrafe</code>, <code>ClientApiAteneoHttp</code>, <code>PersonaRepository</code>.'),
              fig('map_adm_audit', '<b>Registro operazioni</b> · <code>AuditLog</code>, <code>RegistroAuditRepository</code>, <code>registra_log_audit()</code>.'),
              fig('map_adm_uffici', '<b>Uffici</b> · <code>UfficiRepository</code>, <code>ServizioUffici</code> (regole), vista <code>admin/uffici.php</code>.'),
              fig('map_adm_risorse', '<b>Risorse</b> · <code>admin/risorse.php</code> → <code>ServizioGestioneRisorse</code>, <code>ServizioResponsabiliRisorse</code>, <code>ServizioCampiRisorsa</code>.'),
              fig('map_adm_scuole', '<b>Anagrafe scuole</b> · <code>ServizioScuole</code>, <code>ScuolaRepository</code>, tabella <code>scuole</code> (open data del Ministero).'))
    )))

    # 10 -----------------------------------------------------------------------------------------------
    corpo = ''.join([
        box(10, 30, 120, 50, 'compilata', '#f1f5f9', '#475569', "stato_iter ''"),
        box(160, 30, 150, 50, 'caricata_scuola', '#fff7ed', '#c2410c', 'PDF PAdES ricevuto'),
        box(340, 30, 130, 50, 'in_carico', '#eff6ff', '#1d4ed8', 'protocollo entrata'),
        box(500, 30, 130, 50, 'da_firmare', '#f5f3ff', '#6d28d9', 'link al Direttore'),
        box(660, 30, 130, 50, 'firmata_direttore', '#f5f3ff', '#6d28d9', 'PAdES + 1'),
        box(760, 130, 130, 50, 'protocollata', '#ecfdf5', '#15803d', 'attiva'),
        box(340, 130, 130, 50, 'rifiutata', '#fef2f2', '#b91c1c', 'con motivo'),
        freccia(130, 55, 160, 55), freccia(310, 55, 340, 55), freccia(470, 55, 500, 55), freccia(630, 55, 660, 55), freccia(725, 80, 800, 130),
        freccia(405, 80, 405, 130), '<path d="M340 155 Q 230 150 235 82" fill="none" stroke="#64748b" stroke-width="1.6" marker-end="url(#fr)"/>',
        '<text x="250" y="170" font-size="11" fill="#64748b">la scuola ricarica</text>'])
    S.append(('flussi', 'Flussi chiave', sezione('flussi', '10', 'Flussi chiave',
        'Le macchine a stati dei processi principali. Gli stati sono costanti nel modulo; le transizioni stanno nei servizi, mai nelle pagine.',
        '<h3>Convenzione FSL (<code>convenzioni_compilate.stato_iter</code>)</h3>'
        + svg(900, 200, corpo, 'Stati della convenzione FSL: compilata → caricata_scuola → in_carico → da_firmare → firmata_direttore → protocollata; rifiutata → ricarica.')
        + '<ul><li><code>ServizioConvenzioneFirmata::carica()</code> valida con <code>VerificaFirme</code> (solo PAdES, nessuna modifica dopo la firma), salva il file in cartella bloccata, avvisa l\'ufficio e manda la ricevuta.</li>'
          '<li><code>protocolloIngresso()</code> registra il numero, manda il link al Direttore in carica (<code>Direzione::chiFirma()</code>); <code>ServizioFirmaDirettore</code> firma con Aruba o accetta il PDF firmato (stesso documento + una firma).</li>'
          '<li><code>completa()</code> registra la convenzione (<code>convenzioni_scuole</code>), conferma le prenotazioni in attesa e avvisa scuola e docenti.</li>'
          '<li><code>ServizioMessaggiConvenzione</code>: promemoria «carica il PDF» (max uno ogni 24 ore), messaggi e risposte (<code>convenzioni_messaggi</code>).</li></ul>'
        + '<h3>Pratica studente (<code>didattica_moduli.iter_json</code>)</h3>'
        + '<p>L\'iter è un array di passi. Ogni passo è un ufficio (id o chiave), un marcatore (<code>"protocollo"</code>, <code>"@cdl"</code>, <code>"@segreteria"</code>) o un oggetto tipizzato:</p>'
        + cod('''["protocollo", "@cdl", "@segreteria"]                                  // iter semplice
[{"t":"persona","campo":"Relatore","gg":3},                       // firma/conferma di una persona del modulo
 {"t":"ufficio","u":"protocollo","gg":2,"pagamento":true},        // marche da bollo
 {"t":"ufficio","u":"@cdl","documento":true},                     // l'ufficio carica un documento
 {"t":"conferma","campo":"Docente"},                              // conferma docenti (senza firma digitale)
 {"t":"direttore","gg":4}]                                        // firma PAdES del Direttore''')
        + '<p><code>ServizioIter::definizione()</code> normalizza i passi; <code>ServizioFlussoConvalide::trasmettiAlPasso()</code> sposta la pratica verificando le condizioni del passo; <code>PromemoriaPratiche</code> (cron) sollecita dopo <code>gg</code> giorni; <code>FirmaPratica</code> gestisce firme e link; la domanda in PDF/A è prodotta da <code>DomandaPdfa</code> e il testo del verbale da <code>TestoVerbale</code>.</p>'
        + '<h3>Prenotazioni di eventi e risorse</h3>'
        + '<ul><li><b>Turni</b>: <code>prenotazioni.stato</code> ∈ <code>confermata</code>, <code>da_approvare</code>, <code>in_attesa</code> (lista d\'attesa), <code>richiesta_conferma</code> (posto offerto, 24 h), <code>annullata</code>, <code>rifiutata</code>; vincoli e regole in <code>ServizioRegoleIscrizioni</code>, <code>ServizioVincoli</code>, <code>RegoleFsl</code>.</li>'
          '<li><b>Risorse</b>: <code>prenotazioni_risorse.stato</code> ∈ <code>confermata</code>, <code>da_approvare</code>, <code>rifiutata</code>, <code>annullata</code>; slot calcolati da <code>CalendarioRisorse</code>; <code>ListaAttesaRisorse</code> (coda con finestra di 24 ore, il cron <code>scala()</code> passa al successivo).</li></ul>'
        + '<h3>Verbali pubblici in sola lettura</h3>'
        + cod('''puoScaricare($utente)          // admin  oppure  membro dell'ufficio fisso 'verbali_scarico'
accessoDaCredenziali($utente)  // accesso virtuale (id 0, ambiti ["*"]) per chi è abilitato
tokenVista($sedutaId)          // permesso monouso (10 min) in sessione
consumaToken($t, $sedutaId)    // vale una sola volta, per quel verbale''')
        + '<p><code>verbali.php</code> ha tre rotte: <code>?v=ID</code> (visualizzatore: PDF.js disegna le pagine su <code>&lt;canvas&gt;</code> con filigrana; niente toolbar, niente testo selezionabile), <code>?f=ID&amp;t=TOKEN</code> (legge il PDF, rifiuta <code>Sec-Fetch-Dest: document</code> e un token già usato; <code>Cache-Control: no-store</code>) e <code>?d=ID</code> (download: 403 senza <code>puoScaricare</code>). Ogni lettura e download va in <code>verbali_accessi_log</code>.</p>'
        + gal(fig('map_adm_fsl_home', '<b>Pannello FSL</b> (<code>admin/fsl.php</code>, <code>tab=home</code>): schede «cosa devo fare» (<code>Pannello::compiti</code>), esportazioni con <code>TabellaEsportabile</code>.'),
              fig('map_adm_fsl_iter', '<b>Pannello delle convenzioni</b> (<code>admin/fsl.php?tab=iter</code>): le fasi corrispondono agli stati.'))
        + gal(fig('map_adm_fsl_scuola', '<b>Scheda della scuola</b> (<code>tab=scuola</code>): i cinque passi dello <code>stato_iter</code>.'),
              fig('map_pub_verbali_sola_lettura', '<b><code>verbali.php</code></b> in sola lettura (viste «Per corso» e «Cerca» disegnate da JavaScript su un array JSON).'))
    )))

    # 11 -----------------------------------------------------------------------------------------------
    S.append(('documenti', 'Documenti e firme', sezione('documenti', '11', 'Documenti, PDF, Office e firme digitali',
        'Nessuna libreria esterna: PDF, DOCX e XLSX sono costruiti dal codice. Una scelta di portabilità (niente Composer in produzione) e di controllo sui formati richiesti dagli uffici.',
        tab(['Componente', 'Cosa fa'], [
            ['<code>Pdf\\Documento</code> (alias <code>PdfSemplice</code>)', 'Generatore PDF: pagine, paragrafi, tabelle, fasce, riquadri, font TrueType (<code>TrueType</code>). Usato per domande in <b>PDF/A</b> (<code>DomandaPdfa</code>), attestati, estratti dei verbali.'],
            ['<code>Pdf\\FirmePades</code> / <code>VerificaFirme</code>', 'Legge le firme PAdES di un PDF (ByteRange, <code>/Contents</code>, catena CMS) e verifica integrità e titolare; usata da convenzioni, lettere di tutorato, verbali.'],
            ['<code>Tutorato\\FirmaRemotaAruba</code>', 'Firma remota (ArubaSignService SOAP) con utente, password e OTP; <code>disponibile()</code> dipende dalle variabili <code>ARUBA_*</code> nel <code>.env</code>.'],
            ['<code>Pdf\\TestoPdf</code>', 'Estrae il testo da PDF digitali per la ricerca nell\'archivio verbali.'],
            ['<code>Documenti\\Word</code>', 'Costruisce <code>.docx</code> (ZIP di XML) partendo dai modelli in <code>modelli_documenti/</code> e <code>assets/modelli/</code> (segnaposto sostituiti); usata da Convenzione, Allegato A (<code>AllegatoAWord</code>), verbali (<code>EsportazioneVerbale</code>), lettere di incarico.'],
            ['<code>Documenti\\Excel</code>', 'Costruisce <code>.xlsx</code> da array di fogli (report, esportazioni).'],
            ['<code>Storage\\Upload</code>', 'Salvataggio di file caricati: estensione e MIME ammessi, nome casuale, <code>cartellaProtetta()</code> scrive un <code>.htaccess</code> che nega il web.'],
            ['<code>Sicurezza\\Cifratura</code>', 'Cifra i dati sensibili (IBAN) con la frase <code>CIFRATURA_CHIAVE</code>; <code>cifra()</code>/<code>decifra()</code>.'],
            ['<code>Audit\\AuditLog</code> + <code>registra_log_audit()</code>', 'Traccia delle azioni di gestione in <code>log_attivita</code>.'],
        ])
        + '<div class="info">I file riservati (convenzioni firmate, lettere, verbali, allegati) stanno in <code>uploads/</code> con <code>.htaccess</code> che nega l\'accesso: si scaricano solo passando da una pagina PHP che controlla il permesso (<code>invia_file_scaricabile()</code> → <code>Download::invia()</code>).</div>'
    )))

    # 12 -----------------------------------------------------------------------------------------------
    S.append(('email', 'Email', sezione('email', '12', 'Email',
        'Un\'interfaccia (<code>Mailer</code>) e due implementazioni: SMTP e <code>mail()</code>.',
        cod('''interface Mailer {
    public function invia(string $a, string $oggetto, string $corpoHtml, ?string $colore = null, array $allegati = []): bool;
    public function ultimoErrore(): string;
}''')
        + '<ul><li><code>SmtpMailer</code>: STARTTLS/SSL, autenticazione, controllo di ogni risposta del server, registro in <code>log_email</code> (accettata/rifiutata). Password SMTP cifrate nel database.</li>'
          '<li><code>MailerDaFunzione</code>: usa la funzione <code>inviaNotificaEmail()</code> (ambiente di prova e fallback a <code>mail()</code>).</li>'
          '<li><code>ImpaginatoreEmail</code>: layout HTML con il colore dell\'area e testo a contrasto calcolato.</li>'
          '<li><b>I testi stanno nel catalogo</b> (<code>src/Email/Catalogo*.php</code>: un modello per ogni email, con gruppo, quando parte, a chi, segnaposto e testo originale) e si modificano dalla pagina <code>admin/email.php</code> (Testi delle email); la tabella <code>email_modelli</code> tiene solo le modifiche. <code>ModelliEmail</code> compone oggetto e testo (formato semplice con <code>{{segnaposto}}</code> sempre ripuliti, oppure HTML con <code>{NOME}</code> per le email degli eventi) e <code>InvioEmail</code> le spedisce con il colore del modulo.</li>'
          '<li>Le classi <code>Notifiche*</code> e i servizi preparano i dati e chiamano <code>InvioEmail</code>; nessuna classe scrive il testo di un\'email (lo controlla il test <code>NessunaEmailScrittaNelCodiceTest</code>). Le email non bloccano la pratica se falliscono (errore registrato).</li></ul>'
    )))

    # 13 -----------------------------------------------------------------------------------------------
    S.append(('cron', 'Job pianificati', sezione('cron', '13', 'Job pianificati (cron)',
        'Quattro script, avviabili da riga di comando o via URL con <code>CRON_KEY</code> (≥ 16 caratteri).',
        tab(['Script', 'Cosa fa'], [
            ['<code>cron_background.php</code>', 'Passo principale: auto-archiviazione, email post evento, promemoria elenco studenti, attestati delle classi, verifica/promemoria/avvisi/scadenze delle convenzioni, documenti FSL, valutazioni FSL, conservazione dei dati, <b>riepilogo «Da fare per te»</b> (<code>RiepilogoPersone::cron()</code>), <b>convenzioni di tesi</b>, controllo notturno del sito, aggiornamento anagrafe di Ateneo.'],
            ['<code>cron_attestati.php</code>', 'Invio degli attestati.'],
            ['<code>admin/cron_reminders.php</code>', 'Promemoria agli iscritti (prossime 72 ore) e, tramite <code>ServizioPromemoria</code>, quelli dei moduli registrati con le interfacce <code>PromemoriaRisorse</code> e <code>Pianificati</code>: prenotazioni di risorse, lista d\'attesa (<code>scala()</code>), scadenze delle risorse, pratiche ferme e solleciti di firma.'],
            ['<code>admin/cron_backup.php</code>', 'Esporta il database (<code>.sql.gz</code>) e i file (<code>.zip</code>), copia sul NAS, invia la copia cifrata del DB; poi <code>VerificaBackup</code> rilegge tutto.'],
        ])
        + '<ul><li>La sospensione dei promemoria (<code>SospensionePromemoria</code>) non segna nulla come inviato: alla riattivazione il cron rimanda ciò che è ancora dovuto.</li>'
          '<li>Conservazione (variabili <code>CONSERVAZIONE_*_MESI</code>): log, audit, prenotazioni anonimizzate, account inattivi, lettere di incarico, convocazioni.</li>'
          '<li>Ripristino: <code>php strumenti/ripristina_backup.php verifica|prova|file|database</code>.</li></ul>'
        + fig('map_adm_sistema', '<b>Email e controlli</b> (<code>admin/sistema.php</code>): stato del controllo notturno, backup, prova email.')
    )))

    # 14 -----------------------------------------------------------------------------------------------
    S.append(('sicurezza', 'Sicurezza', sezione('sicurezza', '14', 'Sicurezza',
        'Difesa in profondità, con le scelte che contano per un portale universitario.',
        '<div class="grid">'
        + card('Input e output', '<p>Prepared statement ovunque; output HTML sempre codificato; dati JS in <code>json_encode</code>; colori validati prima degli stili; QR letti mai aperti come link.</p>')
        + card('Sessione e CSRF', '<p>Cookie <code>Secure</code>/<code>HttpOnly</code>/<code>SameSite=Lax</code>; token CSRF su ogni POST; <code>rigenera()</code> all\'accesso; dopo «Esci» nessun accesso SSO automatico.</p>')
        + card('Header', '<p>CSP in <code>config.php</code>, HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy; librerie con SRI SHA-384.</p>')
        + card('Permessi', '<p>Ogni azione verifica che evento, turno o pratica appartengano all\'area o all\'ufficio dell\'utente; cron solo da CLI o con <code>CRON_KEY</code>.</p>')
        + card('File', '<p><code>uploads/</code>, <code>.env</code> e cartelle interne negati dal web server; download solo da pagine che controllano il permesso.</p>')
        + card('Anti-abuso', '<p>Rate limit per IP, CAPTCHA interno, campo trappola; limite ai tentativi di password sui verbali.</p>')
        + card('Privacy', '<p>Nessuna connessione a terzi (font e librerie locali); IP anonimizzati nei registri dei verbali; conservazione e anonimizzazione automatiche.</p>')
        + card('Segreti', '<p>Solo nel <code>.env</code> (mai in git): DB, chiavi, Aruba, backup. Password SMTP cifrate nel database.</p>')
        + '</div>'
    )))

    # 15 -----------------------------------------------------------------------------------------------
    S.append(('frontend', 'Front-end', sezione('frontend', '15', 'Front-end e asset',
        'Rendering lato server con Bootstrap; JavaScript solo dove serve.',
        tab(['File', 'Ruolo'], [
            ['<code>assets/css/pannello.css</code>, <code>assets/js/pannello.js</code>', 'Stile e comportamenti del pannello (menu a gruppi richiudibili, «?» con le guide)'],
            ['<code>campi-pratica.js</code>, <code>campo-scuola.js</code>, <code>ricerca-personale.js</code>, <code>decisioni-pratica.js</code>', 'Campi guidati dei moduli (catalogo insegnamenti, scuola del Ministero, ricerca nell\'anagrafe, decisioni di seduta)'],
            ['<code>qrcode-generator</code>, <code>html5-qrcode</code>', 'QR generati nella pagina e scanner del check-in, locali con SRI'],
            ['<code>assets/vendor/…/pdf.js</code>', 'Visualizzatore dei verbali (PDF.js 3.11.174, Apache-2.0)'],
            ['<code>sw.js</code>, <code>manifest.json</code>', 'PWA: in cache solo le librerie statiche; le pagine arrivano sempre dal server'],
        ])
        + '<ul><li>Eventi personalizzati: il selettore delle persone emette <code>persona-scelta</code> sul form che lo contiene (<code>detail.id</code>), usato per uffici, responsabili, abilitazioni.</li>'
          '<li><code>data-confirm</code> su pulsanti e form apre una conferma prima dell\'invio.</li>'
          '<li>Pagine pubbliche che non devono usare la sessione SimpleSAML (verbali) definiscono <code>PAGINA_SENZA_SSO</code>.</li></ul>'
        + gal(fig('map_adm_testata', '<b>Testata e home a widget</b> (<code>admin/testata.php</code>, <code>WidgetHome</code>).'),
              fig('map_adm_did_moduli', '<b>Costruttore dei moduli</b>: campi, condizioni e iter generano <code>campi_json</code> / <code>iter_json</code>.'))
    )))

    # 16 -----------------------------------------------------------------------------------------------
    S.append(('test', 'Test e strumenti', sezione('test', '16', 'Test, ambiente locale e strumenti',
        'La rete di sicurezza sono le «prove»: girano su un database usa-e-getta e, in locale, anche contro le pagine reali.',
        tab(['Cosa', 'Come', 'Note'], [
            ['Prove del portale', '<code>php -d extension=zip -d extension=gd -d extension=intl strumenti/prove/esegui.php</code>', 'Crea il database <code>eventi_prova</code> da <code>database/schema.sql</code> + migrazioni; un file per modulo in <code>strumenti/prove/oop/</code>; ≈ 1.600 verifiche; esce 1 se una fallisce. Con l\'ambiente locale acceso prova anche le pagine via HTTP'],
            ['PHPUnit', '<code>composer test</code>', '70 file in <code>tests/</code>; <code>DatabaseDiProva</code> crea solo le colonne usate dal test; doppi in <code>tests/Doppi</code> (<code>MailerFinto</code>, <code>OrologioFisso</code>); trait <code>ConDirezione</code> per i test che servono il Direttore'],
            ['Ambiente locale', '<code>bash strumenti/locale/avvia.sh</code>', 'Avvia MariaDB e il server PHP su <code>127.0.0.1:8080</code> con il database <code>eventi_locale</code>; <code>router.php</code> offre <code>/__accesso?u=ID</code> (accesso di prova), <code>/__email</code> (email intercettate), <code>/__cron</code>'],
            ['Dati di dimostrazione', '<code>strumenti/locale/scenario_*.php</code>', 'Persone, uffici, pratiche in ogni punto dell\'iter, convenzioni, verbali; <code>collaudo_*.php</code> porta ogni iter fino in fondo'],
            ['Schermate', '<code>strumenti/locale/schermate*.py|php</code>', 'Chrome headless (CDP) con accesso di prova; sono le immagini di guide, presentazioni e di queste mappe'],
            ['Presentazioni e PDF', '<code>strumenti/locale/presentazioni.py</code>, <code>genera_pdf.sh</code>', 'PDF delle guide per ruolo e degli iter con Chrome <code>--print-to-pdf</code>'],
            ['Controlli sul server', '<code>strumenti/prove_server.sh</code>, <code>verifica_sito.sh</code>', 'Versione PHP/estensioni, <code>php -l</code> su tutti i file, cartelle scrivibili, prove su un DB dedicato'],
        ])
        + cod('''// Un test d'integrazione tipico (PHPUnit)
final class FirmaDirettoreTest extends FslBase
{
    use ConDirezione;                      // tabelle uffici + impostaDirettore()
    public function testIlDirettoreVieneDallaDirezione(): void
    {
        $this->impostaDirettore('Mario Bianchi', 'M@Y.IT', 'bnchmr60a01h501x');
        $this->assertTrue($this->firma()->puoFirmare(['id' => 5, 'email' => 'M@Y.it']));
    }
}''')
    )))

    # 17 -----------------------------------------------------------------------------------------------
    S.append(('rilascio', 'Rilascio', sezione('rilascio', '17', 'Rilascio e operazioni',
        'Il deploy è manuale e verificabile: si costruisce un pacchetto dall\'ultimo commit e lo si estrae sul server.',
        cod('''# 1. sul PC: pacchetto con tutti i file committati (senza .env, uploads, cache, tests, strumenti, documentazione)
bash strumenti/crea_pacchetto.sh            # -> aggiornamento_completo.tgz  (+ SHA256SUMS_pacchetto.txt dentro)

# 2. sul server
cd /opt/lampp/htdocs/didattica
tar -xzf aggiornamento_completo.tgz
sha256sum -c SHA256SUMS_pacchetto.txt | grep -v ": OK"     # non deve stampare nulla
find . -name '*.php' -not -path './uploads/*' -not -path './cache/*' -print0 | xargs -0 -n1 php -l | grep -v "^No syntax"
rm -f cache/schema_v*.ok                      # se serve: forza il ricalcolo dello schema alla prossima richiesta''')
        + '<ul><li>Dopo il caricamento basta aprire una pagina: <code>assicura_schema()</code> aggiorna database e dati di partenza.</li>'
          '<li><code>install.php</code> è l\'installer guidato (da eliminare dopo l\'uso). Il passaggio da <code>/eventi</code> a <code>/didattica</code> è automatizzato da <code>strumenti/migra_a_didattica.sh</code>.</li>'
          '<li>Il <code>.env</code> contiene i segreti e non si sovrascrive mai; <code>.env.example</code> elenca le variabili.</li></ul>'
    )))

    # 18 -----------------------------------------------------------------------------------------------
    S.append(('estendere', 'Come estendere', sezione('estendere', '18', 'Come aggiungere una funzione',
        'La ricetta per un nuovo modulo o una nuova tabella, seguendo i pattern esistenti.',
        passi('<b>Tabella</b>: aggiungi il <code>CREATE TABLE IF NOT EXISTS</code> in <code>Definizioni::tabelle()</code> (o la colonna in <code>colonne()</code>) e alza <code>Migrazioni::VERSIONE</code>. I dati di partenza vanno in <code>datiDiPartenza()</code>, con una guardia di idempotenza.',
              '<b>Repository</b> in <code>src/&lt;Modulo&gt;/</code>: costruttore con <code>Database</code>, metodi con query preparate che restituiscono array.',
              '<b>Servizio</b>: regole e validazioni; restituisce <code>?string</code> d\'errore o i dati; riceve repository, notifiche e <code>Orologio</code> (mai <code>new DateTime</code> diretto, per testare).',
              '<b>Notifiche</b>: una classe che usa <code>Mailer</code> e <code>Sito::urlBase()</code> per i link.',
              '<b>Pagina</b>: <code>require_once \'middleware.php\'</code>, <code>App::get(Servizio::class)</code>, azioni POST con <code>csrf_verify</code> + redirect, poi HTML. Se serve il pannello, usa <code>admin_header.php</code> e registra la pagina in <code>PAGINE_MODULO</code> e nel menu.',
              '<b>Prove</b>: un file in <code>strumenti/prove/oop/&lt;modulo&gt;.php</code> con <code>prova(condizione, "descrizione")</code> e, se serve, un test PHPUnit con <code>DatabaseDiProva</code>.',
              '<b>Documenti</b>: aggiorna <code>README.md</code>, il piano e la mappa dei file da caricare; costruisci il pacchetto e verifica con <code>sha256sum -c</code>.')
        + '<div class="nota"><b>Errori da evitare.</b> Non scrivere HTML nei servizi; non leggere la sessione nelle classi (passa i dati); non fare <code>ALTER</code> fuori da <code>Definizioni</code>; non usare <code>App::get()</code> dentro <code>src/</code>; non dimenticare <code>csrf_field()</code> nei form.</div>'
    )))

    # 19 -----------------------------------------------------------------------------------------------
    S.append(('appendice', 'Appendice', sezione('appendice', '19', 'Appendice',
        '',
        '<h3>Variabili del file <code>.env</code> (nomi, mai i valori)</h3>'
        + tab(['Gruppo', 'Variabili'], [
            ['Database', '<code>DB_HOST</code>, <code>DB_USER</code>, <code>DB_PASS</code>, <code>DB_NAME</code>'],
            ['SSO', '<code>SIMPLESAML_PATH</code>'],
            ['Indirizzi e cron', '<code>URL_SITO</code>, <code>CRON_KEY</code>'],
            ['Backup', '<code>BACKUP_NAS_PATH</code>, <code>BACKUP_NAS_GIORNI</code>, <code>BACKUP_EMAIL</code>, <code>BACKUP_EMAIL_FREQUENZA</code>, <code>BACKUP_PASSWORD</code>'],
            ['Conservazione', '<code>CONSERVAZIONE_LOG_MESI</code>, <code>_AUDIT_MESI</code>, <code>_PRENOTAZIONI_MESI</code>, <code>_UTENTI_MESI</code>, <code>_INCARICHI_MESI</code>, <code>_CONVOCAZIONI_MESI</code>'],
            ['Tutorato e firme', '<code>INCARICHI_SOLO_SPID_CIE</code>, <code>ARUBA_ARSS_URL</code>, <code>ARUBA_ARSS_DOMINIO</code>, <code>ARUBA_ARSS_CERTID</code>, <code>CIFRATURA_CHIAVE</code>, <code>FIRME_GIORNI_SOLLECITO</code>'],
            ['Riepiloghi', '<code>RIEPILOGO_PERSONE</code>'],
        ])
        + '<h3>Glossario</h3>'
        + tab(['Termine', 'Significato'], [
            ['Area', 'Una pagina del portale (riga di <code>pagine_eventi</code>) con eventi o risorse e il suo aspetto'],
            ['Modulo (del pannello)', 'Una delle cinque sezioni del pannello (<code>MODULI_PORTALE</code>)'],
            ['Modulo (di pratica)', 'Una richiesta online costruita da pannello (<code>didattica_moduli</code>)'],
            ['Iter', 'La sequenza di passi (uffici, firme, conferme) di un modulo o di una convenzione'],
            ['PAdES', 'Firma digitale incorporata nel PDF (il PDF firmato), accettata al posto del .p7m'],
            ['Facciata', 'Funzione procedurale che inoltra a una classe'],
            ['Strangler fig', 'Strategia di sostituzione graduale del codice vecchio con quello nuovo'],
            ['op_id', 'Numero stabile di una persona come operatore della Didattica, quello salvato in <code>pratiche.assegnata_a</code>'],
            ['Direttore in carica', 'Chi firma ora: il Direttore o, con «Direttore assente», il vice'],
        ])
    )))
    return S
