<?php
// =========================================================================
// Collaudo del processo delle convenzioni FSL, dall'inizio alla fine, come lo farebbero le persone (richieste HTTP vere al portale locale):
//   Direttore impostato dal pannello · scuola che compila e carica · ufficio che protocolla in entrata · email al Direttore · Direttore (controlli di accesso e
//   firma non valida) · ufficio che registra il protocollo definitivo · convenzione nel registro e prenotazioni confermate · rifiuto con motivo ·
//   Direttore non impostato. La firma PAdES del Direttore NON si può provare qui (servono un certificato e un PDF firmato veri): quel passo si simula
//   scrivendo il PDF firmato nel database, come in scenario_fsl.php; la verifica vera della firma è nei test PHPUnit.
//   Prima: bash strumenti/locale/avvia.sh --nuovo (portale acceso su 8080) e php strumenti/locale/scenario_demo.php
//   Poi:   php -d extension=zip -d extension=gd -d extension=intl -d extension=curl strumenti/locale/collaudo_convenzioni.php
// Le email non partono: si leggono da cache/email_locali/. Non tocca altro che la prenotazione OP-PROVA001 e le convenzioni della sua scuola.
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
function scenario(string $tappa): string
{
    $cmd = '"' . PHP_BINARY . '" -d extension=zip -d extension=gd -d extension=intl strumenti/locale/scenario_fsl.php ' . $tappa . ' 2>&1';
    return trim((string) shell_exec($cmd));
}

/** Una persona davanti al portale: ha i suoi cookie e le sue richieste. */
final class Persona
{
    public string $ultima = '';
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
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 60, CURLOPT_MAXREDIRS => 5]);
        if ($post !== null) {
            curl_setopt($c, CURLOPT_POST, true);
            curl_setopt($c, CURLOPT_POSTFIELDS, $post);
        }
        $r = (string) curl_exec($c);
        $this->codice = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
        $this->tipo = (string) curl_getinfo($c, CURLINFO_CONTENT_TYPE);
        $this->ultima = (string) curl_getinfo($c, CURLINFO_EFFECTIVE_URL);
        curl_close($c);

        return $r;
    }

    /** Apre la pagina, prende il token CSRF e invia il modulo con i campi dati. */
    public function invia(string $paginaForm, array $campi, string $postUrl = ''): string
    {
        $html = $this->vai($paginaForm);
        preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m);
        $campi['csrf_token'] = $m[1] ?? '';

        return $this->vai($postUrl !== '' ? $postUrl : $paginaForm, $campi);
    }
}
function sparisce(string $html, string $testo): bool { return !str_contains($html, $testo); }
/** L'ultimo messaggio mostrato (flash) in una pagina, senza tag. */
function messaggio(string $html): string
{
    if (preg_match('/class="alert[^"]*"[^>]*>(.*?)<\/div>/s', $html, $m)) {
        return trim(preg_replace('/\s+/', ' ', strip_tags($m[1])));
    }

    return '';
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

$admin = new Persona($BASE, 1);
$direttore = new Persona($BASE, 7);   // Mario Bianchi, il Direttore dei dati di prova
$studente = new Persona($BASE, 5);
$scuola = new Persona($BASE);         // la scuola non ha accesso: usa il link ricevuto

// ─────────────────────────────────────────────────────────────────────
titolo('0 · Il Direttore del Dipartimento si imposta una volta sola (Sistema e registri)');
echo '  ' . scenario('nuova') . "\n";
$db->query("UPDATE impostazioni_sistema SET conv_direttore_nome = '', conv_direttore_email = '', conv_direttore_cf = '' WHERE id = 1");
$p = $admin->vai('/eventi/admin/sistema.php');
prova('la scheda «Direttore del Dipartimento» è in Sistema, con «da indicare»', str_contains($p, 'id="direttore"') && str_contains($p, 'da indicare'));
$p = $admin->invia('/eventi/admin/sistema.php', ['salva_direttore' => 1, 'dir_nome' => 'Mario Bianchi', 'dir_email' => 'non-una-email', 'dir_cf' => '']);
prova('email non valida rifiutata', q('SELECT conv_direttore_email AS e FROM impostazioni_sistema WHERE id = 1')['e'] === '', messaggio($p));
$p = $admin->invia('/eventi/admin/sistema.php', ['salva_direttore' => 1, 'dir_nome' => 'Mario Bianchi', 'dir_email' => 'mario.bianchi@example.org', 'dir_cf' => 'CORTO']);
prova('codice fiscale non valido rifiutato', q('SELECT conv_direttore_email AS e FROM impostazioni_sistema WHERE id = 1')['e'] === '', messaggio($p));
$p = $admin->invia('/eventi/admin/sistema.php', ['salva_direttore' => 1, 'dir_nome' => 'Mario Bianchi', 'dir_email' => 'Mario.Bianchi@example.org', 'dir_cf' => 'bncmra65a01d086z']);
$r = q('SELECT conv_direttore_nome n, conv_direttore_email e, conv_direttore_cf c FROM impostazioni_sistema WHERE id = 1');
prova('salvato (email in minuscolo, codice fiscale in maiuscolo)', $r['e'] === 'mario.bianchi@example.org' && $r['c'] === 'BNCMRA65A01D086Z', json_encode($r));
$p = $admin->vai('/eventi/admin/fsl.php?tab=iter');
prova('la scheda FSL mostra lo stesso Direttore', str_contains($p, 'Mario Bianchi') && str_contains($p, 'vale per convenzioni, pratiche'));
$p = $direttore->vai('/eventi/cruscotto_direttore.php');
prova('il Cruscotto del Direttore riconosce lo stesso Direttore', $direttore->codice === 200 && !str_contains($p, 'Accesso negato'));
$p = $studente->vai('/eventi/admin/sistema.php');
prova('uno studente non può aprire la pagina di Sistema', !str_contains($p, 'id="direttore"'));

// ─────────────────────────────────────────────────────────────────────
titolo('1 · La scuola compila e carica la convenzione firmata');
echo '  ' . scenario('compila') . "\n";
$st = json_decode((string) file_get_contents('cache/scenario_fsl.json'), true) ?: [];
$token = (string) ($st['token'] ?? '');
$idc = (int) ($st['id'] ?? 0);
prova('c\'è il link personale della scuola', $token !== '');
$p = $scuola->vai('/eventi/convenzione_online.php?t=' . $token);
prova('la pagina della scuola si apre senza accesso e mostra la sua scuola', $scuola->codice === 200 && str_contains($p, 'IIS Volta'), 'HTTP ' . $scuola->codice);
prova('prima del caricamento la convenzione non compare tra quelle da prendere in carico', str_contains($admin->vai('/eventi/admin/fsl.php?tab=iter'), 'in attesa del PDF firmato'));
// PDF non firmato: la scuola non può caricarlo (serve PAdES)
$pdf_semplice = sys_get_temp_dir() . '/conv_semplice.pdf';
file_put_contents($pdf_semplice, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
$html = $scuola->vai('/eventi/convenzione_online.php?t=' . $token);
preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m);
$p = $scuola->vai('/eventi/convenzione_online.php?t=' . $token, ['csrf_token' => $m[1] ?? '', 'carica_firmata' => 1, 'convenzione_firmata' => new CURLFile($pdf_semplice, 'application/pdf', 'convenzione.pdf')]);
prova('un PDF senza firma digitale (PAdES) viene rifiutato', q("SELECT stato_iter s FROM convenzioni_compilate WHERE id = $idc")['s'] !== 'caricata_scuola', messaggio($p));
echo '  ' . scenario('carica') . "   (la firma PAdES della scuola si simula)\n";
prova('stato: caricata dalla scuola', q("SELECT stato_iter s FROM convenzioni_compilate WHERE id = $idc")['s'] === 'caricata_scuola');

// ─────────────────────────────────────────────────────────────────────
titolo('2 · L\'ufficio prende in carico: protocollo in entrata');
$p = $admin->vai('/eventi/admin/fsl.php?tab=iter');
prova('la scheda «Convenzioni da seguire» mostra la scuola nel passo ②', str_contains($p, 'da prendere in carico (1)') && str_contains($p, 'IIS Volta'));
prova('ci sono i pulsanti per scaricare il PDF e l\'Allegato A', str_contains($p, 'convenzione_file.php?c=' . $idc));
$pdf_scuola = $admin->vai('/eventi/admin/convenzione_file.php?c=' . $idc);
prova('il PDF della scuola si scarica dal pannello', $admin->codice === 200 && str_contains($admin->tipo, 'pdf') && str_starts_with($pdf_scuola, '%PDF'), $admin->tipo);
$pdf_studente = $studente->vai('/eventi/admin/convenzione_file.php?c=' . $idc);
prova('uno studente non può scaricarlo', !str_starts_with($pdf_studente, '%PDF'));
vuota_posta();
$p = $admin->invia('/eventi/admin/fsl.php?tab=iter', ['cv_prendi' => $idc, 'prot_ingresso' => '', 'prot_ingresso_data' => date('Y-m-d')]);
prova('senza numero di protocollo non si va avanti', q("SELECT stato_iter s FROM convenzioni_compilate WHERE id = $idc")['s'] === 'caricata_scuola');
$p = $admin->invia('/eventi/admin/fsl.php?tab=iter', ['cv_prendi' => $idc, 'prot_ingresso' => '2154', 'prot_ingresso_data' => date('Y-m-d')]);
$r = q("SELECT stato_iter s, protocollo_ingresso pi, token_direttore td FROM convenzioni_compilate WHERE id = $idc");
prova('protocollo in entrata registrato e convenzione mandata al Direttore (stato da_firmare)', $r['s'] === 'da_firmare' && $r['pi'] === '2154', json_encode($r));
$mail_dir = email_a('mario.bianchi@example.org');
prova('il Direttore ha ricevuto l\'email con il link per firmare', count($mail_dir) === 1 && preg_match('#firma_convenzione\.php\?t=[a-f0-9]+#', $mail_dir[0]) === 1, 'email: ' . count($mail_dir));
prova('la scuola è stata avvisata della presa in carico', count(email_a('segreteria@iisvolta.example.org')) >= 1);
$p = $admin->vai('/eventi/admin/fsl.php?tab=iter');
prova('ora la convenzione è nel passo ③ «Dal Direttore»', str_contains($p, 'Dal Direttore per la firma (1)') && str_contains($p, 'Rimanda il link'));
$p = $admin->invia('/eventi/admin/fsl.php?tab=iter', ['cv_prendi' => $idc, 'prot_ingresso' => '9999', 'prot_ingresso_data' => date('Y-m-d')]);
prova('ripetere la presa in carico non cambia il protocollo', q("SELECT protocollo_ingresso p FROM convenzioni_compilate WHERE id = $idc")['p'] === '2154');

// ─────────────────────────────────────────────────────────────────────
titolo('3 · Il Direttore apre il link');
preg_match('#firma_convenzione\.php\?t=([a-f0-9]+)#', $mail_dir[0] ?? '', $m);
$tok_dir = $m[1] ?? '';
$p = (new Persona($BASE))->vai('/eventi/firma_convenzione.php?t=' . $tok_dir);
prova('senza accedere non si vede il documento', !str_contains($p, 'Convenzione_FSL_da_firmare') && !str_contains($p, 'pdf_firmato'));
$p = $studente->vai('/eventi/firma_convenzione.php?t=' . $tok_dir);
prova('uno studente con il link non può firmare', !str_contains($p, 'name="pdf_firmato"'));
$studente->vai('/eventi/firma_convenzione.php?t=' . $tok_dir . '&pdf=1');
prova('né vedere il PDF', !str_contains($studente->tipo, 'pdf'));
$p = $direttore->vai('/eventi/firma_convenzione.php?t=' . $tok_dir);
prova('il Direttore vede la pagina di firma con il caricamento del PDF firmato', $direttore->codice === 200 && str_contains($p, 'pdf_firmato'));
$direttore->vai('/eventi/firma_convenzione.php?t=' . $tok_dir . '&pdf=1');
prova('il Direttore vede il PDF della scuola da firmare', str_contains($direttore->tipo, 'pdf'));
$html = $direttore->vai('/eventi/firma_convenzione.php?t=' . $tok_dir);
preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m);
$p = $direttore->vai('/eventi/firma_convenzione.php?t=' . $tok_dir, ['csrf_token' => $m[1] ?? '', 'carica' => 1, 'pdf_firmato' => new CURLFile($pdf_semplice, 'application/pdf', 'firmato.pdf')]);
prova('un PDF che non è quello della scuola con la firma in più viene rifiutato', q("SELECT stato_iter s FROM convenzioni_compilate WHERE id = $idc")['s'] === 'da_firmare', messaggio($p));
$p = $admin->invia('/eventi/admin/fsl.php?tab=iter', ['cv_invia_direttore' => $idc]);
prova('«Rimanda il link» manda una seconda email al Direttore', count(email_a('mario.bianchi@example.org')) === 2);

// ─────────────────────────────────────────────────────────────────────
titolo('4 · Il Direttore firma (simulato) e l\'ufficio registra il protocollo definitivo');
echo '  ' . scenario('firmata') . "   (la firma PAdES del Direttore si simula)\n";
$p = $admin->vai('/eventi/admin/fsl.php?tab=iter');
prova('la convenzione passa al passo ④ «Firmate dal Direttore: da protocollare»', str_contains($p, 'da protocollare e registrare (1)') && str_contains($p, 'PDF firmato dal Direttore'));
$pdf_dir = $admin->vai('/eventi/admin/convenzione_file.php?c=' . $idc . '&f=dir');
prova('il PDF firmato dal Direttore si scarica', str_starts_with($pdf_dir, '%PDF'));
// una prenotazione della scuola aspetta la convenzione
$db->query("UPDATE prenotazioni SET stato = 'da_approvare', convenzione = 'no' WHERE codice_prenotazione = 'OP-PROVA001'");
vuota_posta();
$p = $admin->invia('/eventi/admin/fsl.php?tab=iter', ['cv_completa' => $idc, 'protocollo' => '', 'protocollo_data' => date('Y-m-d'), 'data_stipula' => date('Y-m-d'), 'scadenza' => date('Y-m-d', strtotime('+1 year -1 day'))]);
prova('senza numero di protocollo definitivo non si registra', q("SELECT stato_iter s FROM convenzioni_compilate WHERE id = $idc")['s'] === 'firmata_direttore');
$p = $admin->invia('/eventi/admin/fsl.php?tab=iter', ['cv_completa' => $idc, 'protocollo' => '2201', 'protocollo_data' => date('Y-m-d'), 'data_stipula' => date('Y-m-d'), 'scadenza' => date('Y-m-d', strtotime('-1 day'))]);
prova('con la scadenza prima dell\'inizio non si registra', q("SELECT stato_iter s FROM convenzioni_compilate WHERE id = $idc")['s'] === 'firmata_direttore', messaggio($p));
$p = $admin->invia('/eventi/admin/fsl.php?tab=iter', ['cv_completa' => $idc, 'protocollo' => '2201', 'protocollo_data' => date('Y-m-d'), 'data_stipula' => date('Y-m-d'), 'scadenza' => date('Y-m-d', strtotime('+1 year -1 day'))]);
$r = q("SELECT stato_iter s, protocollo p, convenzione_id c FROM convenzioni_compilate WHERE id = $idc");
prova('convenzione registrata (stato protocollata, protocollo 2201)', $r['s'] === 'protocollata' && str_starts_with($r['p'], '2201') && (int) $r['c'] > 0, json_encode($r));
$cv = q("SELECT COUNT(*) n, MAX(scadenza) s FROM convenzioni_scuole WHERE scuola_codice = 'CSTF00003C'");
prova('è nel registro delle convenzioni della scuola', (int) $cv['n'] === 1, json_encode($cv));
$pr = q("SELECT stato, convenzione FROM prenotazioni WHERE codice_prenotazione = 'OP-PROVA001'");
prova('la prenotazione in attesa è stata confermata', $pr['stato'] === 'confermata', json_encode($pr));
prova('la scuola ha ricevuto l\'avviso che la convenzione è attiva', count(email_a('segreteria@iisvolta.example.org')) >= 1);
$p = $admin->vai('/eventi/admin/fsl.php?tab=iter');
prova('ora è nel passo ⑤ «Registrate»', str_contains($p, 'Registrate: convenzione attiva, prenotazioni confermate (1)'));
$p = $admin->vai('/eventi/admin/fsl.php?tab=convenzioni');
prova('compare in «Convenzioni in vigore»', str_contains($p, 'Volta') && $admin->codice === 200);
$p = $admin->invia('/eventi/admin/fsl.php?tab=iter', ['cv_completa' => $idc, 'protocollo' => '2202', 'protocollo_data' => date('Y-m-d'), 'data_stipula' => date('Y-m-d'), 'scadenza' => date('Y-m-d', strtotime('+1 year -1 day'))]);
prova('registrarla due volte non crea un doppione', (int) q("SELECT COUNT(*) n FROM convenzioni_scuole WHERE scuola_codice = 'CSTF00003C'")['n'] === 1);
foreach (['iter', 'convenzioni', 'stipulare', 'verifica', 'report', 'riepilogo'] as $tab) {
    $p = $admin->vai('/eventi/admin/fsl.php?tab=' . $tab);
    prova("la scheda «{$tab}» si apre senza errori", $admin->codice === 200 && !preg_match('/Fatal error|Warning:|Notice:|Deprecated:/', $p));
}

// ─────────────────────────────────────────────────────────────────────
titolo('5 · Rifiuto con un motivo');
echo '  ' . scenario('nuova') . ' · ' . scenario('compila') . ' · ' . scenario('carica') . "\n";
$idc = (int) ((json_decode((string) file_get_contents('cache/scenario_fsl.json'), true) ?: [])['id'] ?? 0); // dopo «nuova» la compilazione è un'altra
vuota_posta();
$p = $admin->invia('/eventi/admin/fsl.php?tab=iter', ['cv_rifiuta' => $idc, 'motivo' => '']);
prova('il rifiuto senza motivo non passa', q("SELECT stato_iter s FROM convenzioni_compilate WHERE id = $idc")['s'] === 'caricata_scuola');
$p = $admin->invia('/eventi/admin/fsl.php?tab=iter', ['cv_rifiuta' => $idc, 'motivo' => 'Firma del Dirigente mancante']);
$r = q("SELECT stato_iter s, nota_ufficio n FROM convenzioni_compilate WHERE id = $idc");
prova('rifiutata con il motivo', $r['s'] === 'rifiutata' && str_contains($r['n'], 'Firma del Dirigente'), json_encode($r));
prova('la scuola riceve il motivo per email', count(array_filter(email_a('segreteria@iisvolta.example.org'), fn($m) => str_contains($m, 'Firma del Dirigente'))) === 1);
$p = $admin->vai('/eventi/admin/fsl.php?tab=iter');
prova('compare tra quelle in attesa di un nuovo file con il motivo', str_contains($p, 'Rifiutata da te') && str_contains($p, 'Firma del Dirigente'));
echo '  ' . scenario('carica') . "   (la scuola ricarica)\n";
prova('dopo il nuovo caricamento torna da prendere in carico', q("SELECT stato_iter s FROM convenzioni_compilate WHERE id = $idc")['s'] === 'caricata_scuola');

// ─────────────────────────────────────────────────────────────────────
titolo('6 · Il Direttore non è stato indicato');
$db->query("UPDATE impostazioni_sistema SET conv_direttore_nome = '', conv_direttore_email = '', conv_direttore_cf = '' WHERE id = 1");
vuota_posta();
$p = $admin->vai('/eventi/admin/fsl.php?tab=iter');
prova('la pagina avvisa che manca il Direttore', str_contains($p, 'Manca il Direttore') || str_contains($p, 'da indicare'));
$p = $admin->invia('/eventi/admin/fsl.php?tab=iter', ['cv_prendi' => $idc, 'prot_ingresso' => '3000', 'prot_ingresso_data' => date('Y-m-d')]);
$r = q("SELECT stato_iter s FROM convenzioni_compilate WHERE id = $idc");
prova('il protocollo in entrata si registra ma la convenzione resta «da mandare al Direttore»', $r['s'] === 'in_carico', $r['s'] . ' · ' . messaggio($p));
$p = $admin->vai('/eventi/admin/fsl.php?tab=iter');
prova('la scheda dice che l\'email non è partita e propone «Invia al Direttore»', str_contains($p, 'non è partita') && str_contains($p, 'Invia al Direttore per la firma'));
$admin->invia('/eventi/admin/sistema.php', ['salva_direttore' => 1, 'dir_nome' => 'Mario Bianchi', 'dir_email' => 'mario.bianchi@example.org', 'dir_cf' => '']);
$admin->invia('/eventi/admin/fsl.php?tab=iter', ['cv_invia_direttore' => $idc]);
prova('indicato il Direttore, l\'invio parte e la convenzione passa a «da_firmare»', q("SELECT stato_iter s FROM convenzioni_compilate WHERE id = $idc")['s'] === 'da_firmare' && count(email_a('mario.bianchi@example.org')) === 1);

// ─────────────────────────────────────────────────────────────────────
echo "\n";
echo "Collaudo: $ok prove riuscite, $ko fallite.\n";
echo "Per riportare l'ambiente com'era: php strumenti/locale/scenario_fsl.php nuova\n";
exit($ko ? 1 : 0);
