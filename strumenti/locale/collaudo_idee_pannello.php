<?php
// Collaudo di «Cerca ovunque», «Sostituzioni degli operatori» e «Sondaggio a fine pratica» con richieste HTTP vere al portale locale.
//   Prima: bash strumenti/locale/avvia.sh --nuovo e php strumenti/locale/scenario_demo.php
//   Poi:   php -d extension=zip -d extension=gd -d extension=intl strumenti/locale/collaudo_idee_pannello.php
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
function messaggio(string $html): string
{
    return preg_match('/class="alert[^"]*"[^>]*>(.*?)<\/div>/s', $html, $m) ? trim(preg_replace('/\s+/', ' ', strip_tags($m[1]))) : '';
}

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
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 90, CURLOPT_MAXREDIRS => 5]);
        if ($post !== null) {
            curl_setopt($c, CURLOPT_POST, true);
            curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $r = (string) curl_exec($c);
        $this->codice = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
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

$admin = new Persona($BASE, 1);
$studente = new Persona($BASE, 5);
$ospite = new Persona($BASE);

titolo('Cerca ovunque');
$h = $admin->vai('/eventi/admin/cerca.php?p_id=1&q=' . urlencode('rossi'));
prova('la pagina si apre', $admin->codice === 200 && str_contains($h, 'Cerca ovunque'), 'HTTP ' . $admin->codice);
prova('trova la pratica di Luca Rossi', str_contains($h, 'Pratiche degli studenti') && str_contains($h, 'Rossi Luca'));
prova('con il link alla pratica', (bool) preg_match('#href="didattica\.php\?p_id=\d+&amp;tab=pratiche&amp;id=\d+"#', $h));
prova('nessun risultato: avviso', str_contains($admin->vai('/eventi/admin/cerca.php?p_id=1&q=zzzzqqq'), 'Nessun risultato'));
$h3 = $admin->vai('/eventi/admin/inizio.php?p_id=1');
prova('la casella è nella barra in alto', str_contains($h3, 'action="cerca.php"'));
prova('un visitatore non vede nulla', !str_contains($ospite->vai('/eventi/admin/cerca.php?q=rossi'), 'Pratiche degli studenti'));
prova('uno studente non vede le pratiche', !str_contains($studente->vai('/eventi/admin/cerca.php?q=rossi'), 'Rossi Luca'));

titolo('Sostituzioni degli operatori');
$db->query('DELETE FROM operatori_sostituzioni');
$h = $admin->vai('/eventi/admin/sostituzioni.php?p_id=1');
prova('la pagina si apre', $admin->codice === 200 && str_contains($h, 'Sostituzioni degli operatori'), 'HTTP ' . $admin->codice);
$opA = (int) q("SELECT op_id AS i FROM uffici_persone WHERE email = 'anna.protocollo@example.org' LIMIT 1")['i'];
$opM = (int) q("SELECT op_id AS i FROM uffici_persone WHERE email = 'marco.referente@example.org' LIMIT 1")['i'];
$oggi = date('Y-m-d');
$tra = date('Y-m-d', strtotime('+10 days'));
$r = $admin->invia('/eventi/admin/sostituzioni.php?p_id=1', ['aggiungi' => 1, 'assente' => $opA, 'sostituto' => $opM, 'dal' => $oggi, 'al' => $tra, 'motivo' => 'ferie']);
$r = $admin->vai('/eventi/admin/sostituzioni.php?p_id=1'); // l'invio risponde con un rimando: l'esito si legge nella pagina successiva
prova('registra la sostituzione', str_contains($r, 'Sostituzione registrata') && str_contains($r, 'Marco Referente'), messaggio($r));
$r = $admin->invia('/eventi/admin/sostituzioni.php?p_id=1', ['aggiungi' => 1, 'assente' => $opA, 'sostituto' => $opA, 'dal' => $oggi, 'al' => $tra]);
$r = $admin->vai('/eventi/admin/sostituzioni.php?p_id=1');
prova('rifiuta se stessi', str_contains($r, 'stessa'), messaggio($r));
$hm = (new Persona($BASE, 9))->vai('/eventi/admin/sostituzioni.php?p_id=1');
prova('il sostituto la vede', str_contains($hm, 'Anna Protocollo') && str_contains($hm, 'in corso'));
$id = (int) q('SELECT id AS i FROM operatori_sostituzioni LIMIT 1')['i'];
$db->query("UPDATE pratiche SET assegnata_a = $opA, stato = 'in_lavorazione' WHERE id = 1");
$hp = (new Persona($BASE, 9))->vai('/eventi/admin/didattica.php?p_id=1&tab=pratiche&carico=me&stato=aperte');
prova('«in carico a me» include la pratica dell\'assente', str_contains($hp, 'Rossi'));
$hs = $admin->vai('/eventi/admin/didattica.php?p_id=1&tab=pratiche&id=1');
prova('la scheda indica chi sostituisce', str_contains($hs, 'sostituito da Marco Referente'));
$r = $admin->invia('/eventi/admin/sostituzioni.php?p_id=1', ['elimina' => $id]);
$r = $admin->vai('/eventi/admin/sostituzioni.php?p_id=1');
prova('elimina la sostituzione', str_contains($r, 'Sostituzione eliminata'), messaggio($r));

titolo('Sondaggio a fine pratica');
$db->query('DELETE FROM pratiche_sondaggi');
$db->query("UPDATE pratiche SET stato = 'in_lavorazione' WHERE id = 1");
prova('pratica aperta: nessun invito', !str_contains($studente->vai('/eventi/pratiche.php?id=1'), 'sondaggio_pratica.php'));
$db->query("UPDATE pratiche SET stato = 'accolta' WHERE id = 1");
$hp = $studente->vai('/eventi/pratiche.php?id=1');
prova('pratica conclusa: invito facoltativo', preg_match('#sondaggio_pratica\.php\?t=([a-f0-9]{32})#', $hp, $m) === 1 && str_contains($hp, 'facoltative'));
$t = $m[1] ?? '';
$hf = $ospite->vai('/eventi/sondaggio_pratica.php?t=' . $t);
prova('il link si apre senza accedere', $ospite->codice === 200 && str_contains($hf, 'andata la tua pratica'), 'HTTP ' . $ospite->codice);
$r = $ospite->invia('/eventi/sondaggio_pratica.php?t=' . $t, ['t' => $t, 'voto_servizio' => 5, 'voto_tempi' => 3, 'commento' => 'Tutto chiaro']);
prova('registra le risposte', str_contains($r, 'Grazie'), messaggio($r));
$riga = q('SELECT voto_servizio AS a, voto_tempi AS b, commento AS c FROM pratiche_sondaggi LIMIT 1');
prova('salvate nel database', ($riga['a'] ?? 0) == 5 && ($riga['b'] ?? 0) == 3 && ($riga['c'] ?? '') === 'Tutto chiaro');
$r = $ospite->invia('/eventi/sondaggio_pratica.php?t=' . $t, ['t' => $t, 'voto_servizio' => 1, 'voto_tempi' => 1]);
prova('una sola risposta', str_contains($r, 'già risposto') && (int) q('SELECT voto_servizio AS a FROM pratiche_sondaggi LIMIT 1')['a'] === 5);
prova('dopo la risposta niente più invito', !str_contains($studente->vai('/eventi/pratiche.php?id=1'), 'sondaggio_pratica.php'));
prova('link inventato', str_contains($ospite->vai('/eventi/sondaggio_pratica.php?t=' . str_repeat('a', 32)), 'non è valido'));
$hst = $admin->vai('/eventi/admin/didattica.php?p_id=1&tab=statistiche&dal=2020-01-01&al=2030-01-01');
prova('statistiche con il parere degli studenti', str_contains($hst, 'Il parere degli studenti') && str_contains($hst, 'Tutto chiaro'));

echo "\n$ok OK, $ko KO\n";
exit($ko ? 1 : 0);
