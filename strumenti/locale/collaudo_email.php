<?php
// Collaudo della pagina «Testi delle email» con richieste HTTP vere al portale locale: elenco, ricerca, modifica con errore e con successo,
// anteprima, prova inviata, ripristino, e l'email del tutorato che parte davvero con il testo modificato.
//   Prima: bash strumenti/locale/avvia.sh --nuovo e php strumenti/locale/scenario_demo.php
//   Poi:   php -d extension=zip -d extension=gd -d extension=intl strumenti/locale/collaudo_email.php
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
    echo ($esito ? "  \033[32mOK\033[0m  " : "  \033[31mKO\033[0m  ") . $nome . ($esito || $dettaglio === '' ? '' : "  -> $dettaglio") . "\n";
}
function titolo(string $t): void { echo "\n== $t\n"; }
function q(string $sql): array { global $db; $r = $db->query($sql); return $r ? ($r->fetch_assoc() ?: []) : []; }

final class Persona
{
    public int $codice = 0;
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
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 120, CURLOPT_MAXREDIRS => 5]);
        if ($post !== null) {
            curl_setopt($c, CURLOPT_POST, true);
            curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $r = (string) curl_exec($c);
        $this->codice = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
        curl_close($c);

        return $r;
    }

    public function invia(string $paginaForm, array $campi): string
    {
        preg_match('/name="csrf_token"\s+value="([^"]+)"/', $this->vai($paginaForm), $m);
        $campi['csrf_token'] = $m[1] ?? '';

        return $this->vai($paginaForm, $campi);
    }
}

$admin = new Persona($BASE, 1);
$studente = new Persona($BASE, 5);
$P = '/eventi/admin/email.php';
$db->query("DELETE FROM email_modelli");

titolo('L\'elenco');
$h = $admin->vai($P);
prova('la pagina si apre e mostra le email del tutorato', $admin->codice === 200 && str_contains($h, 'Tutorato · Lettera di incarico') && str_contains($h, 'Invito al tutor a confermare la lettera'), 'HTTP ' . $admin->codice);
prova('ogni email dice quando parte e a chi arriva', str_contains($h, 'Il tutor vincitore') && str_contains($h, 'Il Direttore del Dipartimento'));
prova('di partenza sono tutte «originale»', !str_contains($h, '>modificata<'));
prova('c\'è la ricerca', str_contains($h, 'id="cerca-email"'));
$studente->vai($P);
prova('uno studente non entra', $studente->codice !== 200 || !str_contains($studente->vai($P), 'Testi delle email'));

titolo('Una scheda');
$h = $admin->vai($P . '?k=tutorato_attivita_completate');
prova('si apre l\'editor con oggetto, testo e segnaposto', str_contains($h, 'name="oggetto"') && str_contains($h, 'name="corpo"') && str_contains($h, '{{tutor}}') && str_contains($h, '{{ore}}'));
prova('un\'email senza pulsante non ha il campo del pulsante', !str_contains($h, 'name="bottone"'));
$h = $admin->vai($P . '?k=tutorato_invito_studente');
prova('un\'email con pulsante ha il campo del pulsante', str_contains($h, 'name="bottone"'));
$admin->vai($P . '?k=chiave_inventata');
prova('una chiave inventata è rifiutata', $admin->codice !== 200 || !str_contains($admin->vai($P . '?k=chiave_inventata'), 'name="oggetto"'));

titolo('Modifica con errore, anteprima, salvataggio');
$h = $admin->invia($P . '?k=tutorato_attivita_completate', ['chiave' => 'tutorato_attivita_completate', 'oggetto' => 'Finito!', 'corpo' => 'Ciao {{inventato}}', 'bottone' => '', 'salva' => 1]);
prova('un segnaposto che non esiste è respinto', str_contains($h, 'non esiste per questa email') && !q("SELECT chiave FROM email_modelli"));
$h = $admin->invia($P . '?k=tutorato_attivita_completate', ['chiave' => 'tutorato_attivita_completate', 'oggetto' => 'Hai finito, {{tutor}}', 'corpo' => "Ciao {{tutor}},\n\nhai fatto **{{ore}} ore** <b>davvero</b>.", 'bottone' => '', 'anteprima' => 1]);
prova('l\'anteprima mostra il testo con i dati d\'esempio', str_contains($h, 'Hai finito, Maria Verdi') && str_contains($h, '<strong>38 ore</strong>') && str_contains($h, '&lt;b&gt;davvero&lt;/b&gt;'), 'HTTP ' . $admin->codice);
prova('l\'anteprima non salva niente', !q("SELECT chiave FROM email_modelli"));
$admin->invia($P . '?k=tutorato_attivita_completate', ['chiave' => 'tutorato_attivita_completate', 'oggetto' => 'Hai finito, {{tutor}}', 'corpo' => "Ciao {{tutor}},\n\nhai fatto **{{ore}} ore**.", 'bottone' => '', 'salva' => 1]);
$r = q("SELECT oggetto, corpo, aggiornato_da FROM email_modelli WHERE chiave = 'tutorato_attivita_completate'");
prova('il testo è salvato con chi l\'ha modificato', ($r['oggetto'] ?? '') === 'Hai finito, {{tutor}}' && ($r['aggiornato_da'] ?? '') !== '', json_encode($r));
$h = $admin->vai($P);
prova('nell\'elenco compare «modificata»', str_contains($h, '>modificata<'));
prova('la modifica è nel registro attività', (int) q("SELECT COUNT(*) n FROM log_attivita WHERE azione LIKE 'Testo di un%email modificato'")['n'] >= 1);

titolo('La prova');
foreach (glob('cache/email_locali/*') ?: [] as $f) { @unlink($f); }
$admin->invia($P . '?k=tutorato_invito_studente', ['chiave' => 'tutorato_invito_studente', 'oggetto' => 'Lettera: {{bando}}', 'corpo' => 'Gentile {{tutor}}, c\'è la lettera.', 'bottone' => 'Vai', 'prova' => 1]);
$mail = glob('cache/email_locali/*') ?: [];
$testo = $mail ? (string) file_get_contents(end($mail)) : '';
prova('la prova arriva con [PROVA] e i dati d\'esempio', str_contains($testo, '[PROVA]') && str_contains($testo, 'Maria Verdi'), count($mail) . ' email');
prova('la prova non salva il testo', !q("SELECT chiave FROM email_modelli WHERE chiave = 'tutorato_invito_studente'"));

titolo('L\'email del tutorato parte con il testo modificato');
$db->query("DELETE FROM tutorato_incarichi WHERE codice_fiscale = 'VRDMRA00A01D086X'");
$db->query("DELETE FROM tutorato_bandi WHERE titolo = 'Bando di prova email'");
$T = '/eventi/admin/tutorato.php?p_id=1';
$admin->invia($P . '?k=tutorato_invito_studente', ['chiave' => 'tutorato_invito_studente', 'oggetto' => 'MODIFICATA {{tutor}}', 'corpo' => 'Testo nuovo per {{tutor}} ({{bando}}).', 'bottone' => 'Pulsante nuovo', 'salva' => 1]);
$admin->invia($T . '&nuovo_bando=1', ['salva_bando' => 1, 'bando_id' => 0, 'titolo' => 'Bando di prova email', 'anno_accademico' => '2026/2027', 'decreto_bando' => '99/2026', 'direttore_nome' => 'Mario Bianchi', 'direttore_email' => 'mario.bianchi@example.org', 'iter' => 'portale']);
$bid = (int) (q("SELECT id FROM tutorato_bandi WHERE titolo = 'Bando di prova email'")['id'] ?? 0);
$admin->invia($T . '&nuovo_incarico=1&bando=' . $bid, ['salva_incarico' => 1, 'incarico_id' => 0, 'bando_id' => $bid, 'genere' => 'F', 'cognome' => 'Verdi', 'nome' => 'Maria', 'luogo_nascita' => 'Cosenza',
    'data_nascita' => '2000-01-01', 'comune_residenza' => 'Rende', 'indirizzo' => 'Via Roma', 'civico' => '1', 'codice_fiscale' => 'VRDMRA00A01D086X', 'email' => 'maria.verdi.email@example.org',
    'telefono' => '333', 'attivita' => 'Tutorato di Chimica', 'ore' => '10', 'periodo' => 'dal 01/11 al 28/02', 'compenso' => '', 'docente_nome' => 'Anna', 'docente_cognome' => 'Verdi',
    'docente_email' => 'anna.verdi@example.org', 'data_lettera' => date('Y-m-d')]);
$iid = (int) (q("SELECT id FROM tutorato_incarichi WHERE codice_fiscale = 'VRDMRA00A01D086X' ORDER BY id DESC LIMIT 1")['id'] ?? 0);
foreach (glob('cache/email_locali/*') ?: [] as $f) { @unlink($f); }
$admin->invia($T . '&incarico=' . $iid, ['incarico_id' => $iid, 'invia_studente' => 1]);
$mail = glob('cache/email_locali/*maria.verdi.email*') ?: [];
$testo = $mail ? (string) file_get_contents(end($mail)) : '';
prova('la lettera è partita allo studente', q("SELECT stato FROM tutorato_incarichi WHERE id = $iid")['stato'] === 'inviata');
prova('con oggetto, testo e pulsante modificati', str_contains($testo, 'MODIFICATA Maria Verdi') && str_contains($testo, 'Testo nuovo per Maria Verdi (Bando di prova email).') && str_contains($testo, 'Pulsante nuovo'), $testo === '' ? 'nessuna email' : substr(strip_tags($testo), 0, 200));
prova('il link personale c\'è ancora', str_contains($testo, 'incarico.php?t='));

titolo('Ripristino');
$admin->invia($P . '?k=tutorato_invito_studente', ['chiave' => 'tutorato_invito_studente', 'ripristina' => 1]);
prova('il testo torna originale', !q("SELECT chiave FROM email_modelli WHERE chiave = 'tutorato_invito_studente'"));

titolo('I due pulsanti manuali del tutorato');
$db->query("UPDATE tutorato_incarichi SET stato = 'firmata', firmata_direttore_il = NOW(), data_inizio = CURDATE() - INTERVAL 40 DAY, data_fine = CURDATE() + INTERVAL 30 DAY, docente_email = 'anna.verdi@example.org' WHERE id = $iid");
$db->query("INSERT INTO tutorato_registro (incarico_id, data, ore, attivita, stato) VALUES ($iid, CURDATE() - INTERVAL 5 DAY, 4, 'Esercitazione', 'inviata')");
$pg = $admin->vai($T . '&incarico=' . $iid);
prova('la scheda ha i due pulsanti', str_contains($pg, 'Invita a compilare il registro') && str_contains($pg, 'Invia al docente la fine attività da firmare'), 'HTTP ' . $admin->codice);
prova('quello per il docente è spento finché ci sono ore da approvare', (bool) preg_match('/name="invia_fine_docente"[^>]*disabled/', $pg));
$pb = $admin->vai($T . '&bando=' . $bid);
prova('nella pagina del bando c\'è l\'invito in blocco con il numero', str_contains($pb, 'Invita a compilare il registro (1 in ritardo)'));
foreach (glob('cache/email_locali/*') ?: [] as $f) { @unlink($f); }
$admin->invia($T . '&incarico=' . $iid, ['incarico_id' => $iid, 'invita_registro' => 1]);
$mail = glob('cache/email_locali/*maria.verdi.email*') ?: [];
prova('l\'invito arriva al tutor con il link del registro', $mail && str_contains((string) file_get_contents(end($mail)), 'registro_tutorato.php?id=' . $iid));
$pg = $admin->vai($T . '&incarico=' . $iid);
prova('l\'ultimo invito compare nella scheda', str_contains($pg, 'Ultimo invito al tutor'));
foreach (glob('cache/email_locali/*') ?: [] as $f) { @unlink($f); }
$admin->invia($T . '&bando=' . $bid, ['bando_id' => $bid, 'invita_registro_bando' => 1]);
prova('l\'invito in blocco manda un\'email al tutor in ritardo', count(glob('cache/email_locali/*maria.verdi.email*') ?: []) === 1);
$db->query("UPDATE tutorato_registro SET stato = 'approvata' WHERE incarico_id = $iid");
$pg = $admin->vai($T . '&incarico=' . $iid);
prova('con le ore approvate il pulsante per il docente è acceso', !preg_match('/name="invia_fine_docente"[^>]*disabled/', $pg));
foreach (glob('cache/email_locali/*') ?: [] as $f) { @unlink($f); }
$admin->invia($T . '&incarico=' . $iid, ['incarico_id' => $iid, 'invia_fine_docente' => 1]);
$r = q("SELECT fine_stato, token_fine FROM tutorato_incarichi WHERE id = $iid");
prova('la dichiarazione è pronta da firmare', ($r['fine_stato'] ?? '') === 'da_firmare' && !empty($r['token_fine']), json_encode($r));
$mail = glob('cache/email_locali/*anna.verdi*') ?: [];
$testo = $mail ? (string) file_get_contents(end($mail)) : '';
prova('il docente riceve l\'email con il link per firmare', str_contains($testo, 'firma_incarico.php?t=' . ($r['token_fine'] ?? 'x')) && str_contains($testo, 'Apri e firma la dichiarazione'), $testo === '' ? 'nessuna email' : '');
$pg = $admin->vai($T . '&incarico=' . $iid);
prova('ora si può rimandare l\'email', str_contains($pg, 'Rimanda al docente l&#039;email per la firma') || str_contains($pg, "Rimanda al docente l'email per la firma"));
// pulizia
$db->query("DELETE FROM tutorato_registro WHERE incarico_id = $iid");
$db->query("DELETE FROM tutorato_eventi WHERE incarico_id = $iid");
$db->query("DELETE FROM tutorato_incarichi WHERE id = $iid");
$db->query("DELETE FROM tutorato_bandi WHERE id = $bid");
$db->query("DELETE FROM email_modelli");

echo "\n$ok OK, $ko KO\n";
exit($ko ? 1 : 0);
