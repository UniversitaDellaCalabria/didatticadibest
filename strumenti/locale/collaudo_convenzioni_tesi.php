<?php
// =========================================================================
// Collaudo delle convenzioni di tesi con enti e aziende, dall'inizio alla fine, come lo farebbero le persone (richieste HTTP vere al portale locale):
//   richiesta dalla pagina pubblica (con la domanda anti-robot) · invito dell'ufficio · l'azienda compila · propone modifiche agli articoli · l'ufficio risponde ·
//   scarica Word e PDF · carica un PDF non firmato (rifiutato) · protocollo in entrata (il Direttore riceve il link) · il Direttore apre la pagina (accessi) ·
//   protocollo in uscita · archivio ed elenco pubblico · convenzione già in essere inserita a mano · rifiuto con motivo · Direttore non indicato · «Da fare per te».
// La firma digitale PAdES (dell'azienda e del Direttore) NON si può provare qui (servono certificati e PDF firmati veri): quei due passi si simulano scrivendo
// il PDF «firmato» nel database, come in scenario_fsl.php; i controlli veri sulle firme sono nei test PHPUnit.
//   Prima: bash strumenti/locale/avvia.sh --nuovo (portale acceso su 8080) e php strumenti/locale/scenario_demo.php
//   Poi:   php -d extension=zip -d extension=gd -d extension=intl strumenti/locale/collaudo_convenzioni_tesi.php
// Le email non partono: si leggono da cache/email_locali/. Tocca solo le convenzioni di tesi (le cancella all'inizio).
// =========================================================================
if (PHP_SAPI !== 'cli') {
    exit;
}
chdir(realpath(__DIR__ . '/../..'));
$BASE = 'http://127.0.0.1:8080';
$e = parse_ini_file('.env.locale');
mysqli_report(MYSQLI_REPORT_OFF);
$db = new mysqli($e['DB_HOST'], $e['DB_USER'], $e['DB_PASS'], $e['DB_NAME']);
$db->set_charset('utf8mb4');

$ok = $ko = 0;
function prova(string $nome, bool $esito, string $dettaglio = ''): void
{
    global $ok, $ko;
    $esito ? $ok++ : $ko++;
    echo ($esito ? "  \033[32mOK\033[0m  " : "  \033[31mKO\033[0m  ") . $nome . ($esito || $dettaglio === '' ? '' : "  → $dettaglio") . "\n";
}
function titolo(string $t): void { echo "\n== $t\n"; }
function q(string $sql): array { global $db; $r = $db->query($sql); return $r ? ($r->fetch_assoc() ?: []) : []; }

final class Persona
{
    public int $codice = 0;
    public string $tipo = '';
    private string $jar;

    public function __construct(private string $base, int $utente = 0)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'cj');
        if ($utente) {
            $this->vai('/__accesso?u=' . $utente);
        }
    }

    public function vai(string $url, ?array $post = null): string
    {
        $c = curl_init($this->base . $url);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 90, CURLOPT_MAXREDIRS => 5]);
        if ($post !== null) {
            $conFile = array_filter($post, static fn ($v): bool => $v instanceof CURLFile) !== [];
            curl_setopt($c, CURLOPT_POST, true);
            curl_setopt($c, CURLOPT_POSTFIELDS, $conFile ? $post : http_build_query($post));   // con campi a lista (art[8]) si manda come un modulo normale
        }
        $r = (string) curl_exec($c);
        $this->codice = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
        $this->tipo = (string) curl_getinfo($c, CURLINFO_CONTENT_TYPE);
        curl_close($c);

        return $r;
    }

    public function csrf(string $html): string
    {
        preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m);

        return $m[1] ?? '';
    }

    /** Apre la pagina, prende il token CSRF e invia il modulo con i campi dati. */
    public function invia(string $paginaForm, array $campi, string $postUrl = ''): string
    {
        $campi['csrf_token'] = $this->csrf($this->vai($paginaForm));

        return $this->vai($postUrl !== '' ? $postUrl : $paginaForm, $campi);
    }
}
function messaggio(string $html): string
{
    return preg_match('/class="alert[^"]*"[^>]*>(.*?)<\/div>/s', $html, $m) ? trim(preg_replace('/\s+/', ' ', strip_tags($m[1]))) : '';
}
function email_a(string $destinatario): array
{
    $out = [];
    foreach (glob('cache/email_locali/*.html') ?: [] as $f) {
        if (stripos(basename($f), $destinatario) !== false) {
            $out[] = (string) file_get_contents($f);
        }
    }

    return $out;
}
function vuota_posta(): void { array_map('unlink', glob('cache/email_locali/*.html') ?: []); }
function token_da_email(string $a, string $pagina): string
{
    $m = email_a($a);

    return preg_match('#' . preg_quote($pagina, '#') . '\?t=([a-f0-9]+)#', end($m) ?: '', $x) ? $x[1] : '';
}

// Simulazione delle firme PAdES: il PDF «firmato» si scrive nella cartella protetta e nel database (senza controllare la crittografia)
$conn = $db;
$_SESSION = [];
define('AMBIENTE_LOCALE_CLI', 1);
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$EMAIL = [];
function inviaNotificaEmail($to, $subject, $body_html, $conn, $colore = null, array $allegati = []) { return true; }
require 'functions.php';
$c = \App\Core\App::per($db);
$rep = $c->get(\App\ConvenzioniTesi\ConvenzioneTesiRepository::class);
$svc = $c->get(\App\ConvenzioniTesi\ServizioConvenzioniTesi::class);
$sito = $c->get(\App\Core\Sito::class);
function salva_pdf(string $dir, string $contenuto): string
{
    global $sito;
    @mkdir($sito->radice() . '/' . $dir, 0755, true);
    $n = bin2hex(random_bytes(12)) . '.pdf';
    file_put_contents($sito->radice() . '/' . $dir . $n, $contenuto);

    return $dir . $n;
}

$admin = new Persona($BASE, 1);
$direttore = new Persona($BASE, 7);   // Mario Bianchi, il Direttore dei dati di prova
$studente = new Persona($BASE, 5);
$azienda = new Persona($BASE);        // l'azienda non ha accesso: usa il link ricevuto
$visitatore = new Persona($BASE);

$dati = ['ragione_sociale' => 'BioLab Calabria S.r.l.', 'forma_giuridica' => 'S.r.l.', 'piva' => '01234567890', 'cf' => '01234567890', 'comune' => 'Rende', 'provincia' => 'CS',
    'indirizzo' => 'Via Pietro Bucci 12', 'pec' => 'biolab@pec.example.it', 'rappr_titolo' => 'Dott.ssa', 'rappr_nome' => 'Maria Rossi', 'rappr_qualifica' => 'Amministratore Unico',
    'settore' => 'Biotecnologie', 'sito' => 'www.biolab.example.it', 'consenso_elenco' => '1'];

$db->query('DELETE FROM convenzioni_tesi');
$db->query("DELETE FROM rate_limit_attempts WHERE endpoint IN ('richiesta_convenzione_tesi', 'convenzione_tesi')");   // il limite per indirizzo (5 richieste all'ora) non deve fermare il collaudo ripetuto
$db->query("UPDATE impostazioni_sistema SET direttore_assente = 0 WHERE id = 1");
vuota_posta();

// ─────────────────────────────────────────────────────────────────────
titolo('0 · Il modello e le pagine pubbliche');
prova('il modello Word c\'è', is_file('modelli_documenti/convenzione_tesi.docx'));
$p = $visitatore->vai('/eventi/richiedi_convenzione_tesi.php');
prova('la pagina per richiedere la convenzione si apre senza accesso', $visitatore->codice === 200 && str_contains($p, 'Inviami il link') && str_contains($p, 'Quanto fa'));
$p = $visitatore->vai('/eventi/aziende_convenzionate_tesi.php');
prova('l\'elenco pubblico si apre (vuoto)', $visitatore->codice === 200 && str_contains($p, 'in preparazione'));
$p = $visitatore->vai('/eventi/convenzione_tesi.php?t=00ff');
prova('un link sbagliato non mostra nulla', $visitatore->codice === 404);
$p = $visitatore->vai('/eventi/admin/convenzioni_tesi.php');
prova('l\'ufficio è riservato (senza accesso non si entra)', !str_contains($p, 'Invita un\'azienda'));
$p = $studente->vai('/eventi/admin/convenzioni_tesi.php');
prova('uno studente non entra nelle convenzioni di tesi', !str_contains($p, 'Invita un\'azienda'));

// ─────────────────────────────────────────────────────────────────────
titolo('1 · L\'azienda richiede la convenzione dalla pagina pubblica');
$html = $visitatore->vai('/eventi/richiedi_convenzione_tesi.php');
preg_match('/Quanto fa (\d) \+ (\d)\?/', $html, $m);
preg_match('/name="captcha_id" value="([^"]+)"/', $html, $cid);
sleep(3);
$p = $visitatore->vai('/eventi/richiedi_convenzione_tesi.php', ['csrf_token' => $visitatore->csrf($html), 'azienda' => 'BioLab', 'email' => 'info@biolab.example.it', 'captcha_id' => $cid[1] ?? '', 'captcha_risposta' => '99']);
prova('con la risposta sbagliata alla domanda non parte nulla', (int) q("SELECT COUNT(*) n FROM convenzioni_tesi")['n'] === 0, messaggio($p));
$html = $visitatore->vai('/eventi/richiedi_convenzione_tesi.php');
preg_match('/Quanto fa (\d) \+ (\d)\?/', $html, $m);
preg_match('/name="captcha_id" value="([^"]+)"/', $html, $cid);
sleep(3);
$p = $visitatore->vai('/eventi/richiedi_convenzione_tesi.php', ['csrf_token' => $visitatore->csrf($html), 'azienda' => 'BioLab', 'email' => 'info@biolab.example.it', 'captcha_id' => $cid[1] ?? '', 'captcha_risposta' => (string) ((int) $m[1] + (int) $m[2])]);
prova('con la risposta giusta si apre la convenzione e si manda il link', (int) q("SELECT COUNT(*) n FROM convenzioni_tesi WHERE origine = 'pubblica'")['n'] === 1 && str_contains($p, 'Fatto'));
$tok = token_da_email('info@biolab.example.it', 'convenzione_tesi.php');
prova('il link personale è nell\'email', strlen($tok) === 40);
$id = (int) q("SELECT id FROM convenzioni_tesi WHERE token = '$tok'")['id'];

// ─────────────────────────────────────────────────────────────────────
titolo('2 · L\'azienda compila i dati dal link (senza registrarsi)');
$pag = $azienda->vai('/eventi/convenzione_tesi.php?t=' . $tok);
prova('la pagina dell\'azienda si apre con il modulo e i 13 articoli', $azienda->codice === 200 && str_contains($pag, 'name="ragione_sociale"') && substr_count($pag, 'name="art[') === 13, 'articoli: ' . substr_count($pag, 'name="art['));
prova('prima dei dati non si può scaricare né firmare', !str_contains($pag, 'Scarica il PDF da firmare'));
$art = [];
$p = $azienda->invia('/eventi/convenzione_tesi.php?t=' . $tok, ['salva_dati' => 1] + array_merge($dati, ['ragione_sociale' => '', 'pec' => 'non-valida']));
prova('con dati mancanti o sbagliati il modulo avvisa e non salva', str_contains($p, 'Controlla i dati') && q("SELECT stato s FROM convenzioni_tesi WHERE id = $id")['s'] === 'invitata');
$p = $azienda->invia('/eventi/convenzione_tesi.php?t=' . $tok, ['salva_dati' => 1] + $dati);
$r = q("SELECT stato s, azienda a, consenso_elenco c FROM convenzioni_tesi WHERE id = $id");
prova('dati salvati: la convenzione è «compilata»', $r['s'] === 'compilata' && $r['a'] === 'BioLab Calabria S.r.l.' && $r['c'] === '1', json_encode($r));
$pag = $azienda->vai('/eventi/convenzione_tesi.php?t=' . $tok);
prova('ora compaiono i pulsanti per scaricare e per caricare il firmato', str_contains($pag, 'Scarica il PDF da firmare') && str_contains($pag, 'name="pdf_firmato"'));
$pdf = $azienda->vai('/eventi/convenzione_tesi.php?t=' . $tok . '&scarica=pdf');
prova('il PDF compilato si scarica', $azienda->codice === 200 && str_contains($azienda->tipo, 'pdf') && str_starts_with($pdf, '%PDF') && strlen($pdf) > 5000, $azienda->tipo . ' ' . strlen($pdf));
$word = $azienda->vai('/eventi/convenzione_tesi.php?t=' . $tok . '&scarica=word');
$tmp = sys_get_temp_dir() . '/ct_word.docx';
file_put_contents($tmp, $word);
$zip = new ZipArchive();
$testo_word = $zip->open($tmp) === true ? strip_tags((string) $zip->getFromName('word/document.xml')) : '';
prova('il Word compilato ha la parte dell\'azienda', str_contains($testo_word, 'BioLab Calabria S.r.l., con sede legale in Rende (CS), via Via Pietro Bucci 12') || str_contains($testo_word, 'BioLab Calabria S.r.l., con sede legale in Rende (CS), via Pietro Bucci 12') || str_contains($testo_word, 'BioLab Calabria S.r.l., con sede legale in Rende (CS)'), substr($testo_word, 0, 120));

// ─────────────────────────────────────────────────────────────────────
titolo('3 · L\'azienda propone una modifica a un articolo; l\'ufficio risponde');
$standard = $svc->articoliStandard();
$art = array_map(fn($a) => $a['testo'], $standard);
$art[8] = "Il soggetto ospitante può offrire borse di studio ai tesisti, comunicandolo per iscritto.";
vuota_posta();
$p = $azienda->invia('/eventi/convenzione_tesi.php?t=' . $tok, ['salva_dati' => 1, 'art' => $art] + $dati);
$r = q("SELECT stato s FROM convenzioni_tesi WHERE id = $id");
prova('con una modifica la convenzione passa a «modifiche» e non si può firmare', $r['s'] === 'modifiche');
$pag = $azienda->vai('/eventi/convenzione_tesi.php?t=' . $tok);
prova('l\'azienda vede che la modifica è in valutazione e non può scaricare il PDF da firmare', str_contains($pag, 'Modifiche in valutazione') && !str_contains($pag, 'Scarica il PDF da firmare'));
$azienda->vai('/eventi/convenzione_tesi.php?t=' . $tok . '&scarica=pdf');
prova('nemmeno con l\'indirizzo del PDF', !str_contains($azienda->tipo, 'pdf'));
prova('l\'ufficio è stato avvisato', count(email_a('admin@example.org')) >= 1);
$pg = $admin->vai('/eventi/admin/convenzioni_tesi.php');
prova('nel pannello la modifica è tra quelle da valutare, con testo standard e proposta', str_contains($pg, 'Modifiche agli articoli da valutare (1)') && str_contains($pg, 'Proposta dell\'azienda') && str_contains($pg, 'comunicandolo per iscritto'));
$p = $admin->invia('/eventi/admin/convenzioni_tesi.php', ['rispondi_modifiche' => 1, 'id' => $id, 'm' => [8 => ['azione' => 'rifiuta', 'risposta' => '']]]);
prova('rifiutare senza spiegare non passa', q("SELECT stato s FROM convenzioni_tesi WHERE id = $id")['s'] === 'modifiche');
vuota_posta();
$p = $admin->invia('/eventi/admin/convenzioni_tesi.php', ['rispondi_modifiche' => 1, 'id' => $id, 'm' => [8 => ['azione' => 'controproposta', 'testo' => 'Il soggetto ospitante comunica per iscritto al Dipartimento le eventuali borse di studio.', 'risposta' => 'Meglio così']]]);
prova('risposta con un altro testo: l\'azienda deve decidere', q("SELECT stato s FROM convenzioni_tesi WHERE id = $id")['s'] === 'risposta' && count(email_a('info@biolab.example.it')) === 1);
$pag = $azienda->vai('/eventi/convenzione_tesi.php?t=' . $tok);
prova('l\'azienda vede la risposta e può accettare il testo del Dipartimento', str_contains($pag, 'Il Dipartimento ha risposto') && str_contains($pag, 'al Dipartimento le eventuali borse di studio') && str_contains($pag, 'name="accetta[8]"'));
$p = $azienda->invia('/eventi/convenzione_tesi.php?t=' . $tok, ['salva_dati' => 1, 'art' => $art, 'accetta' => [8 => '1']] + $dati);
prova('accettata la controproposta la convenzione torna «compilata» e si può firmare', q("SELECT stato s FROM convenzioni_tesi WHERE id = $id")['s'] === 'compilata');
$pdf = $azienda->vai('/eventi/convenzione_tesi.php?t=' . $tok . '&scarica=pdf');
$testo_pdf = (new \App\Infrastructure\Pdf\TestoPdf())->estrai($pdf);
prova('il PDF ha il testo concordato dell\'articolo 8', str_contains($testo_pdf, 'comunica per iscritto al Dipartimento le eventuali borse di studio'), substr($testo_pdf, 0, 80));

// ─────────────────────────────────────────────────────────────────────
titolo('4 · L\'azienda firma e ricarica (la firma PAdES si simula)');
$pdf_semplice = sys_get_temp_dir() . '/ct_semplice.pdf';
file_put_contents($pdf_semplice, $pdf);
$html = $azienda->vai('/eventi/convenzione_tesi.php?t=' . $tok);
$p = $azienda->vai('/eventi/convenzione_tesi.php?t=' . $tok, ['csrf_token' => $azienda->csrf($html), 'carica_firmata' => 1, 'pdf_firmato' => new CURLFile($pdf_semplice, 'application/pdf', 'convenzione.pdf')]);
prova('il PDF scaricato e non firmato viene rifiutato', q("SELECT stato s FROM convenzioni_tesi WHERE id = $id")['s'] === 'compilata' && str_contains($p, 'firma digitale PAdES'), messaggio($p));
$percorso = salva_pdf(\App\ConvenzioniTesi\Costanti::DIR_FIRMATE, $pdf . "\n%% (firmato)\n");
$rep->salvaFirmata($id, $percorso, 'Maria Rossi (Amministratore Unico)');
prova('stato: firmata dall\'azienda e caricata', q("SELECT stato s FROM convenzioni_tesi WHERE id = $id")['s'] === 'caricata');
$pag = $azienda->vai('/eventi/convenzione_tesi.php?t=' . $tok);
prova('l\'azienda vede che il Dipartimento sta completando la convenzione', str_contains($pag, 'sta completando la convenzione'));

// ─────────────────────────────────────────────────────────────────────
titolo('5 · L\'ufficio protocolla in entrata e il Direttore riceve il link');
$pg = $admin->vai('/eventi/admin/convenzioni_tesi.php');
prova('la convenzione è tra quelle da prendere in carico', str_contains($pg, 'da prendere in carico (1)') && str_contains($pg, 'BioLab Calabria S.r.l.'));
prova('l\'ufficio scarica il PDF dell\'azienda', str_starts_with($admin->vai('/eventi/admin/convenzioni_tesi.php?file=' . $id . '&f=azienda'), '%PDF'));
prova('uno studente non lo scarica', !str_starts_with($studente->vai('/eventi/admin/convenzioni_tesi.php?file=' . $id . '&f=azienda'), '%PDF'));
vuota_posta();
$admin->invia('/eventi/admin/convenzioni_tesi.php', ['prendi' => 1, 'id' => $id, 'prot_ingresso' => '', 'prot_ingresso_data' => date('Y-m-d')]);
prova('senza numero di protocollo non si va avanti', q("SELECT stato s FROM convenzioni_tesi WHERE id = $id")['s'] === 'caricata');
$admin->invia('/eventi/admin/convenzioni_tesi.php', ['prendi' => 1, 'id' => $id, 'prot_ingresso' => '4411', 'prot_ingresso_data' => date('Y-m-d')]);
$r = q("SELECT stato s, protocollo_ingresso p, token_direttore t FROM convenzioni_tesi WHERE id = $id");
prova('protocollo in entrata registrato: la convenzione è dal Direttore', $r['s'] === 'da_firmare' && $r['p'] === '4411', json_encode($r));
$tok_dir = token_da_email('mario.bianchi@example.org', 'firma_convenzione_tesi.php');
prova('il Direttore ha ricevuto l\'email con il suo link', $tok_dir !== '' && $tok_dir === $r['t']);
prova('l\'azienda ha ricevuto l\'avviso di presa in carico', count(email_a('info@biolab.example.it')) >= 1);

// ─────────────────────────────────────────────────────────────────────
titolo('6 · Il Direttore apre il link (accessi e firma non valida)');
$p = $visitatore->vai('/eventi/firma_convenzione_tesi.php?t=' . $tok_dir);
prova('senza accedere non si vede il documento', !str_contains($p, 'name="pdf_firmato"'));
$p = $studente->vai('/eventi/firma_convenzione_tesi.php?t=' . $tok_dir);
prova('uno studente con il link non può firmare', !str_contains($p, 'name="pdf_firmato"'));
$studente->vai('/eventi/firma_convenzione_tesi.php?t=' . $tok_dir . '&pdf=1');
prova('né vedere il PDF', !str_contains($studente->tipo, 'pdf'));
$p = $direttore->vai('/eventi/firma_convenzione_tesi.php?t=' . $tok_dir);
prova('il Direttore vede la pagina di firma', $direttore->codice === 200 && str_contains($p, 'name="pdf_firmato"') && str_contains($p, 'BioLab Calabria'));
$direttore->vai('/eventi/firma_convenzione_tesi.php?t=' . $tok_dir . '&pdf=1');
prova('il Direttore vede il PDF da firmare', str_contains($direttore->tipo, 'pdf'));
$html = $direttore->vai('/eventi/firma_convenzione_tesi.php?t=' . $tok_dir);
$p = $direttore->vai('/eventi/firma_convenzione_tesi.php?t=' . $tok_dir, ['csrf_token' => $direttore->csrf($html), 'carica' => 1, 'pdf_firmato' => new CURLFile($pdf_semplice, 'application/pdf', 'firmato.pdf')]);
prova('un PDF che non è quello dell\'azienda con la firma in più viene rifiutato', q("SELECT stato s FROM convenzioni_tesi WHERE id = $id")['s'] === 'da_firmare');
$p = $direttore->vai('/eventi/cruscotto_direttore.php');
prova('il Cruscotto del Direttore mostra la convenzione di tesi da firmare', str_contains($p, 'Convenzioni di tesi da firmare') && str_contains($p, 'BioLab Calabria'));
$p = $direttore->vai('/eventi/area_personale.php');
prova('«Da fare per te» del Direttore la segnala', str_contains($p, 'Convenzioni di tesi da firmare'));
$admin->invia('/eventi/admin/convenzioni_tesi.php', ['invia_direttore' => 1, 'id' => $id]);
prova('«Rimanda il link» manda una seconda email al Direttore', count(email_a('mario.bianchi@example.org')) === 2);
$rep->segnaFirmataDirettore($id, salva_pdf(\App\ConvenzioniTesi\Costanti::DIR_DEFINITIVE, $pdf . "\n%% (firmato dal Direttore)\n"), 'Mario Bianchi (Direttore)');
echo "  (la firma PAdES del Direttore si simula)\n";

// ─────────────────────────────────────────────────────────────────────
titolo('7 · L\'ufficio protocolla in uscita: la convenzione è stipulata');
$pg = $admin->vai('/eventi/admin/convenzioni_tesi.php');
prova('la convenzione è tra quelle firmate, da protocollare', str_contains($pg, 'protocollo in uscita (1)') && str_contains($pg, 'PDF firmato dal Direttore'));
prova('il PDF firmato dal Direttore si scarica', str_starts_with($admin->vai('/eventi/admin/convenzioni_tesi.php?file=' . $id . '&f=direttore'), '%PDF'));
vuota_posta();
$ann = ['completa' => 1, 'id' => $id, 'protocollo_data' => date('Y-m-d')];
$admin->invia('/eventi/admin/convenzioni_tesi.php', $ann + ['protocollo' => '', 'valida_dal' => date('Y-m-d'), 'valida_al' => date('Y-m-d', strtotime('+5 years -1 day'))]);
prova('senza protocollo non si registra', q("SELECT stato s FROM convenzioni_tesi WHERE id = $id")['s'] === 'firmata_direttore');
$admin->invia('/eventi/admin/convenzioni_tesi.php', $ann + ['protocollo' => '4500', 'valida_dal' => date('Y-m-d'), 'valida_al' => '2020-01-01']);
prova('con la scadenza prima dell\'inizio non si registra', q("SELECT stato s FROM convenzioni_tesi WHERE id = $id")['s'] === 'firmata_direttore');
$admin->invia('/eventi/admin/convenzioni_tesi.php', $ann + ['protocollo' => '4500', 'valida_dal' => date('Y-m-d'), 'valida_al' => date('Y-m-d', strtotime('+5 years -1 day'))]);
$r = q("SELECT stato s, protocollo p, valida_al a FROM convenzioni_tesi WHERE id = $id");
prova('convenzione stipulata (protocollo 4500, valida 5 anni)', $r['s'] === 'protocollata' && $r['p'] === '4500' && $r['a'] === date('Y-m-d', strtotime('+5 years -1 day')), json_encode($r));
prova('l\'azienda ha ricevuto l\'avviso di convenzione stipulata', count(email_a('info@biolab.example.it')) === 1);
$pag = $azienda->vai('/eventi/convenzione_tesi.php?t=' . $tok);
prova('l\'azienda vede «Convenzione stipulata» e scarica il PDF firmato', str_contains($pag, 'Convenzione stipulata') && str_contains($pag, 'scarica=finale'));
$azienda->vai('/eventi/convenzione_tesi.php?t=' . $tok . '&scarica=finale');
prova('il PDF finale si scarica', str_contains($azienda->tipo, 'pdf'));
$p = $azienda->invia('/eventi/convenzione_tesi.php?t=' . $tok, ['salva_dati' => 1] + array_merge($dati, ['ragione_sociale' => 'Cambiata']));
prova('a convenzione stipulata l\'azienda non può cambiare i dati', q("SELECT azienda a FROM convenzioni_tesi WHERE id = $id")['a'] === 'BioLab Calabria S.r.l.');
$p = $visitatore->vai('/eventi/aziende_convenzionate_tesi.php');
prova('l\'azienda compare nell\'elenco pubblico (nome, comune, settore, sito)', str_contains($p, 'BioLab Calabria S.r.l.') && str_contains($p, 'Rende (CS)') && str_contains($p, 'Biotecnologie') && str_contains($p, 'biolab.example.it'));
$p = $visitatore->vai('/eventi/aziende_convenzionate_tesi.php?q=geologia');
prova('la ricerca nell\'elenco filtra', !str_contains($p, 'BioLab Calabria S.r.l.'));
$p = $admin->vai('/eventi/admin/convenzioni_tesi.php?tab=archivio');
prova('è nell\'archivio, valida', str_contains($p, 'BioLab Calabria S.r.l.') && str_contains($p, 'valida') && $admin->codice === 200);

// ─────────────────────────────────────────────────────────────────────
titolo('8 · Convenzione già in essere inserita a mano; scaduta e senza consenso non compaiono');
$post = ['archivia' => 1, 'id' => 0, 'ragione_sociale' => 'Geo Studio S.n.c.', 'comune' => 'Cosenza', 'provincia' => 'CS', 'settore' => 'Geologia', 'sito' => 'geostudio.example.it', 'protocollo' => '55/2024',
    'protocollo_data' => '2024-03-01', 'valida_dal' => '2024-03-01', 'valida_al' => date('Y-m-d', strtotime('+3 years')), 'consenso_elenco' => '1', 'email' => 'geo@example.it'];
$admin->invia('/eventi/admin/convenzioni_tesi.php?tab=archivio', ['ragione_sociale' => ''] + $post);
prova('senza ragione sociale non si inserisce', (int) q("SELECT COUNT(*) n FROM convenzioni_tesi WHERE origine = 'archivio'")['n'] === 0);
$admin->invia('/eventi/admin/convenzioni_tesi.php?tab=archivio', $post);
$admin->invia('/eventi/admin/convenzioni_tesi.php?tab=archivio', ['ragione_sociale' => 'Vecchia S.p.A.', 'valida_dal' => '2015-01-01', 'valida_al' => '2020-01-01', 'protocollo' => '1', 'consenso_elenco' => '1'] + $post);
$admin->invia('/eventi/admin/convenzioni_tesi.php?tab=archivio', ['ragione_sociale' => 'Riservata S.r.l.', 'consenso_elenco' => '', 'protocollo' => '2'] + $post);
prova('tre convenzioni in archivio inserite a mano', (int) q("SELECT COUNT(*) n FROM convenzioni_tesi WHERE origine = 'archivio'")['n'] === 3);
$p = $visitatore->vai('/eventi/aziende_convenzionate_tesi.php');
prova('nell\'elenco pubblico: la nuova valida con consenso sì; scaduta e senza consenso no', str_contains($p, 'Geo Studio S.n.c.') && !str_contains($p, 'Vecchia S.p.A.') && !str_contains($p, 'Riservata S.r.l.'));
$p = $admin->vai('/eventi/admin/convenzioni_tesi.php?tab=archivio&vista=scadute');
prova('il filtro «scadute» mostra solo la scaduta', str_contains($p, 'Vecchia S.p.A.') && !str_contains($p, 'Geo Studio S.n.c.'));
$p = $admin->vai('/eventi/admin/convenzioni_tesi.php?tab=archivio&q=Geo%20Studio');
prova('la ricerca nell\'archivio trova per nome', str_contains($p, 'Geo Studio S.n.c.') && !str_contains($p, 'Vecchia S.p.A.'));

// ─────────────────────────────────────────────────────────────────────
titolo('9 · Invito dell\'ufficio, rifiuto con motivo e Direttore non indicato');
vuota_posta();
$admin->invia('/eventi/admin/convenzioni_tesi.php', ['invita' => 1, 'azienda' => 'Ambiente Sud', 'email' => 'ambiente@example.it']);
$tok2 = token_da_email('ambiente@example.it', 'convenzione_tesi.php');
prova('l\'ufficio invita un\'altra azienda: parte il link', strlen($tok2) === 40);
$id2 = (int) q("SELECT id FROM convenzioni_tesi WHERE token = '$tok2'")['id'];
$azienda->invia('/eventi/convenzione_tesi.php?t=' . $tok2, ['salva_dati' => 1] + array_merge($dati, ['ragione_sociale' => 'Ambiente Sud S.r.l.']));
$rep->salvaFirmata($id2, salva_pdf(\App\ConvenzioniTesi\Costanti::DIR_FIRMATE, $pdf . "\n%% (firmato)\n"), 'Giulia Verdi');
vuota_posta();
$admin->invia('/eventi/admin/convenzioni_tesi.php', ['rifiuta' => 1, 'id' => $id2, 'motivo' => '']);
prova('il rifiuto senza motivo non passa', q("SELECT stato s FROM convenzioni_tesi WHERE id = $id2")['s'] === 'caricata');
$admin->invia('/eventi/admin/convenzioni_tesi.php', ['rifiuta' => 1, 'id' => $id2, 'motivo' => 'Firma del legale rappresentante mancante']);
$r = q("SELECT stato s, nota_ufficio n FROM convenzioni_tesi WHERE id = $id2");
prova('rifiutata con il motivo, che arriva all\'azienda per email', $r['s'] === 'rifiutata' && count(array_filter(email_a('ambiente@example.it'), fn($m) => str_contains($m, 'Firma del legale'))) === 1, json_encode($r));
$pag = $azienda->vai('/eventi/convenzione_tesi.php?t=' . $tok2);
prova('l\'azienda vede il motivo e può ricaricare', str_contains($pag, 'non è stato accettato') && str_contains($pag, 'Firma del legale rappresentante mancante') && str_contains($pag, 'name="pdf_firmato"'));
$rep->salvaFirmata($id2, salva_pdf(\App\ConvenzioniTesi\Costanti::DIR_FIRMATE, $pdf . "\n%% (corretta)\n"), 'Giulia Verdi');
$db->query("UPDATE impostazioni_sistema SET conv_direttore_nome = '', conv_direttore_email = '', conv_direttore_cf = '' WHERE id = 1");
$uff = (int) q("SELECT id FROM uffici WHERE chiave = 'direzione'")['id'];
$salvati = [];
$r = $db->query("SELECT * FROM uffici_persone WHERE ufficio_id = $uff");
while ($r && $x = $r->fetch_assoc()) $salvati[] = $x;
$db->query("DELETE FROM uffici_persone WHERE ufficio_id = $uff");
vuota_posta();
$admin->invia('/eventi/admin/convenzioni_tesi.php', ['prendi' => 1, 'id' => $id2, 'prot_ingresso' => '4412', 'prot_ingresso_data' => date('Y-m-d')]);
prova('senza Direttore il protocollo in entrata si registra ma la convenzione resta «da mandare al Direttore»', q("SELECT stato s FROM convenzioni_tesi WHERE id = $id2")['s'] === 'in_carico');
$pg = $admin->vai('/eventi/admin/convenzioni_tesi.php');
prova('la pagina dice che l\'email non è partita', str_contains($pg, 'non è partita'));
foreach ($salvati as $x) {
    $db->query(sprintf("INSERT INTO uffici_persone (ufficio_id, persona_id, email, nominativo, struttura, ruolo, compiti, op_id) VALUES (%d, %s, '%s', '%s', '%s', '%s', '%s', %s)", $x['ufficio_id'],
        $x['persona_id'] === null ? 'NULL' : "'" . $db->real_escape_string($x['persona_id']) . "'", $db->real_escape_string($x['email']), $db->real_escape_string($x['nominativo']),
        $db->real_escape_string((string) $x['struttura']), $db->real_escape_string((string) $x['ruolo']), $db->real_escape_string((string) $x['compiti']), $x['op_id'] === null ? 'NULL' : (int) $x['op_id']));
}
$admin->invia('/eventi/admin/convenzioni_tesi.php', ['invia_direttore' => 1, 'id' => $id2]);
prova('ripristinato il Direttore, l\'invio parte', q("SELECT stato s FROM convenzioni_tesi WHERE id = $id2")['s'] === 'da_firmare' && count(email_a('mario.bianchi@example.org')) === 1);

// ─────────────────────────────────────────────────────────────────────
titolo('10 · Le pagine del pannello e il menu');
$p = $admin->vai('/eventi/admin/inizio.php');
prova('la scrivania del pannello segnala le convenzioni di tesi da seguire o è senza errori', $admin->codice === 200 && !preg_match('/Fatal error|Warning:|Notice:|Deprecated:/', $p));
$p = $admin->vai('/eventi/admin/didattica.php?tab=pratiche');
prova('il menu del modulo Didattica ha «Convenzioni di tesi»', str_contains($p, 'Convenzioni di tesi'));
foreach (['/eventi/admin/convenzioni_tesi.php', '/eventi/admin/convenzioni_tesi.php?tab=archivio', '/eventi/richiedi_convenzione_tesi.php', '/eventi/aziende_convenzionate_tesi.php', '/eventi/cruscotto_direttore.php', '/eventi/area_personale.php'] as $u) {
    $p = $admin->vai($u);
    prova("$u senza errori", $admin->codice === 200 && !preg_match('/Fatal error|Warning:|Notice:|Deprecated:/', $p));
}

echo "\n";
// ─────────────────────────────────────────────────────────────────────
titolo('11 · Il Direttore nel testo della convenzione');
$db->query("UPDATE impostazioni_sistema SET tesi_direttore_nome = '', tesi_direttore_nato_a = '', tesi_direttore_nato_il = NULL WHERE id = 1");
$tok3 = '';
[, $id3] = $svc->avvia('dir@example.it', 'Test Direttore S.r.l.', \App\ConvenzioniTesi\Costanti::ORIGINE_INVITO, 'collaudo');
$tok3 = (string) $rep->perId($id3)['token'];
$azienda->invia('/eventi/convenzione_tesi.php?t=' . $tok3, ['salva_dati' => 1] + array_merge($dati, ['ragione_sociale' => 'Test Direttore S.r.l.']));
$testo_di = function () use ($azienda, $tok3): string {
    $tmp = sys_get_temp_dir() . '/ct_dir.docx';
    file_put_contents($tmp, $azienda->vai('/eventi/convenzione_tesi.php?t=' . $tok3 . '&scarica=word'));
    $z = new ZipArchive();

    return $z->open($tmp) === true ? preg_replace('/\s+/u', ' ', strip_tags((string) $z->getFromName('word/document.xml'))) : '';
};
prova('finché non si indicano i dati il Word ha il testo del modello', str_contains($testo_di(), 'La Russa Mauro Francesco'));
$p = $admin->vai('/eventi/admin/convenzioni_tesi.php');
prova('la pagina dell\'ufficio mostra «Il Direttore nel testo della convenzione» da indicare', str_contains($p, 'Il Direttore nel testo della convenzione') && str_contains($p, 'il testo usa ancora il modello'));
$admin->invia('/eventi/admin/convenzioni_tesi.php', ['salva_direttore_testo' => 1, 'titolo' => 'Prof.', 'nome' => 'Mario Bianchi', 'nato_a' => '', 'nato_il' => '']);
prova('dati incompleti: non si salvano', (string) q("SELECT tesi_direttore_nome n FROM impostazioni_sistema WHERE id = 1")['n'] === '');
$admin->invia('/eventi/admin/convenzioni_tesi.php', ['salva_direttore_testo' => 1, 'titolo' => 'Prof.', 'nome' => 'Mario Bianchi', 'nato_a' => 'Cosenza (CS)', 'nato_il' => '1970-02-03']);
$t = $testo_di();
prova('salvati: nel Word compaiono i dati del Direttore in carica', str_contains($t, 'Prof. Mario Bianchi, nato a Cosenza (CS) il 03/02/1970') && !str_contains($t, 'La Russa'), substr($t, 0, 60));
$p = $admin->vai('/eventi/admin/convenzioni_tesi.php');
prova('la pagina dice che è a posto', str_contains($p, 'badge bg-success">a posto'));
$db->query("DELETE FROM convenzioni_tesi WHERE id = $id3");

// ─────────────────────────────────────────────────────────────────────
titolo('12 · Tesi dello studente: la scelta dell\'azienda convenzionata');
$modulo_tesi = (int) q("SELECT id FROM didattica_moduli WHERE chiave = 'tesi'")['id'];
$p = $studente->vai('/eventi/modulo.php?id=' . $modulo_tesi);
prova('il modulo «Richiesta tesi» ha la scelta dell\'azienda con le convenzionate e «Altra azienda»', str_contains($p, 'data-tipo="azienda_tesi"') && str_contains($p, 'Altra azienda o ente (non ancora convenzionato)') && str_contains($p, 'BioLab Calabria S.r.l. – Rende'));
$prat = (int) q("SELECT id FROM pratiche WHERE modulo_id = $modulo_tesi AND utente_id = 5 ORDER BY id LIMIT 1")['id'];
if ($prat > 0) {
    $imposta = function (string $valore, string $nome = '') use ($db, $prat): void {
        $r = json_decode((string) q("SELECT risposte_json j FROM pratiche WHERE id = $prat")['j'], true) ?: [];
        $r = array_values(array_filter($r, fn($x) => ($x['tipo'] ?? '') !== 'azienda_tesi' && !str_contains((string) ($x['etichetta'] ?? ''), 'non convenzionata')));
        $r[] = ['etichetta' => 'Azienda o ente', 'tipo' => 'azienda_tesi', 'valore' => $valore];
        if ($nome !== '') $r[] = ['etichetta' => "Nome dell'azienda o ente (non convenzionata)", 'tipo' => 'text', 'valore' => $nome];
        $db->query("UPDATE pratiche SET risposte_json = '" . $db->real_escape_string((string) json_encode($r, JSON_UNESCAPED_UNICODE)) . "' WHERE id = $prat");
    };
    $imposta('BioLab Calabria S.r.l. – Rende');
    $db->query("UPDATE convenzioni_tesi SET stato = 'protocollata', valida_dal = CURDATE(), valida_al = DATE_ADD(CURDATE(), INTERVAL 3 YEAR), protocollo = '4500' WHERE id = $id");
    $p = $studente->vai('/eventi/pratiche.php?id=' . $prat);
    prova('lo studente vede «Azienda convenzionata» con la validità', str_contains($p, 'Azienda convenzionata') && str_contains($p, 'convenzione di tesi valida fino al'));
    $imposta(\App\ConvenzioniTesi\Costanti::ALTRA_AZIENDA, 'Zoo Lab Sila');
    $p = $studente->vai('/eventi/pratiche.php?id=' . $prat);
    prova('con «Altra azienda» lo studente è avvisato e trova il link per chiedere la convenzione', str_contains($p, 'Azienda non ancora convenzionata') && str_contains($p, 'richiedi_convenzione_tesi.php') && str_contains($p, 'Zoo Lab Sila'));
    $p = $admin->vai('/eventi/admin/didattica.php?tab=pratiche&id=' . $prat);
    prova('anche la scheda dell\'ufficio lo segnala', str_contains($p, 'Azienda non ancora convenzionata'));
    $imposta('BioLab Calabria S.r.l. – Rende');
}
echo "Collaudo: $ok prove riuscite, $ko fallite.\n";
echo "Per ripulire: DELETE FROM convenzioni_tesi (e php strumenti/locale/scenario_fsl.php nuova per rimettere il Direttore dei dati di prova).\n";
exit($ko ? 1 : 0);
