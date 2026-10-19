<?php
// Collaudo dell'«Iter per Uniticket» del tutorato con richieste HTTP vere al portale locale: scelta dell'iter nel bando, lettera in bozza che lo eredita,
// invio bloccato finché l'ufficio non verifica la presa di servizio, poi invio; i due file da allegare e l'assenza dei pagamenti si provano con i test PHPUnit.
//   Prima: bash strumenti/locale/avvia.sh --nuovo e php strumenti/locale/scenario_demo.php
//   Poi:   php -d extension=zip -d extension=gd -d extension=intl strumenti/locale/collaudo_iter_uniticket.php
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
$T = '/eventi/admin/tutorato.php?p_id=1';

$db->query("DELETE FROM tutorato_incarichi WHERE codice_fiscale = 'VRDMRA00A01D086X'");
$db->query("DELETE FROM tutorato_bandi WHERE titolo = 'Bando di prova Uniticket'");

titolo('Il bando sceglie l\'iter');
$h = $admin->vai($T . '&nuovo_bando=1');
prova('il modulo del bando ha la scelta dell\'iter', $admin->codice === 200 && str_contains($h, 'name="iter"') && str_contains($h, 'Iter per Uniticket'), 'HTTP ' . $admin->codice);
$admin->invia($T . '&nuovo_bando=1', ['salva_bando' => 1, 'bando_id' => 0, 'titolo' => 'Bando di prova Uniticket', 'anno_accademico' => '2026/2027', 'decreto_bando' => '99/2026',
    'direttore_nome' => 'Mario Bianchi', 'direttore_email' => 'mario.bianchi@example.org', 'iter' => 'uniticket']);
$bando = q("SELECT id, iter FROM tutorato_bandi WHERE titolo = 'Bando di prova Uniticket'");
prova('il bando è salvato con l\'iter per Uniticket', ($bando['iter'] ?? '') === 'uniticket', json_encode($bando));
$bid = (int) ($bando['id'] ?? 0);

titolo('Una lettera nuova eredita l\'iter e aspetta la presa di servizio');
$admin->invia($T . '&nuovo_incarico=1&bando=' . $bid, ['salva_incarico' => 1, 'incarico_id' => 0, 'bando_id' => $bid, 'genere' => 'F', 'cognome' => 'Verdi', 'nome' => 'Maria', 'luogo_nascita' => 'Cosenza',
    'data_nascita' => '2000-01-01', 'comune_residenza' => 'Rende', 'indirizzo' => 'Via Roma', 'civico' => '1', 'codice_fiscale' => 'VRDMRA00A01D086X', 'email' => 'maria.verdi.prova@example.org',
    'telefono' => '333', 'attivita' => 'Tutorato di Chimica', 'ore' => '10', 'periodo' => 'dal 01/11 al 28/02', 'compenso' => '', 'docente_nome' => 'Anna', 'docente_cognome' => 'Verdi',
    'docente_email' => 'anna.verdi@example.org', 'data_lettera' => date('Y-m-d')]);
$inc = q("SELECT id, iter, stato, presa_servizio_il FROM tutorato_incarichi WHERE codice_fiscale = 'VRDMRA00A01D086X' ORDER BY id DESC LIMIT 1");
prova('la lettera in bozza eredita l\'iter', ($inc['iter'] ?? '') === 'uniticket' && ($inc['stato'] ?? '') === 'bozza', json_encode($inc));
$iid = (int) ($inc['id'] ?? 0);
$pg = $admin->vai($T . '&incarico=' . $iid);
prova('la scheda mostra l\'iter e il pulsante «Presa di servizio verificata»', str_contains($pg, 'Iter per Uniticket') && str_contains($pg, 'Presa di servizio verificata'), 'HTTP ' . $admin->codice);
prova('l\'invio allo studente è bloccato finché non si verifica', (bool) preg_match('/name="invia_studente"[^>]*disabled/', $pg));
$admin->invia($T . '&incarico=' . $iid, ['incarico_id' => $iid, 'invia_studente' => 1]);
prova('provare a inviare lo stesso non fa partire nulla', q("SELECT stato FROM tutorato_incarichi WHERE id = $iid")['stato'] === 'bozza');

titolo('Presa di servizio verificata → si invia');
$admin->invia($T . '&incarico=' . $iid, ['incarico_id' => $iid, 'presa_servizio' => 1]);
prova('la presa di servizio è registrata', !empty(q("SELECT presa_servizio_il p FROM tutorato_incarichi WHERE id = $iid")['p']));
$pg = $admin->vai($T . '&incarico=' . $iid);
prova('ora il pulsante di invio è attivo e dice «compilare e accettare»', !preg_match('/name="invia_studente"[^>]*disabled/', $pg) && str_contains($pg, 'compilare e accettare'));
$admin->invia($T . '&incarico=' . $iid, ['incarico_id' => $iid, 'invia_studente' => 1]);
prova('la lettera è inviata allo studente', q("SELECT stato FROM tutorato_incarichi WHERE id = $iid")['stato'] === 'inviata');
$mail = glob('cache/email_locali/*maria.verdi.prova*') ?: [];
prova('l\'email allo studente chiede di compilare e accettare', $mail && str_contains((string) file_get_contents(end($mail)), 'compilare'));

titolo('Cambiare iter solo in bozza');
$admin->invia($T . '&incarico=' . $iid, ['incarico_id' => $iid, 'cambia_iter' => 1, 'iter' => 'portale']);
prova('una lettera già inviata non cambia iter', q("SELECT iter FROM tutorato_incarichi WHERE id = $iid")['iter'] === 'uniticket');

titolo('A firme finite: i due file da allegare e niente pagamenti');
@mkdir('uploads/incarichi', 0755, true);
file_put_contents('uploads/incarichi/PROVA_UNITICKET_scafis.pdf', "%PDF-1.4\nscafis\n%%EOF");
file_put_contents('uploads/incarichi/PROVA_UNITICKET_lettera.pdf', "%PDF-1.4\nlettera\n%%EOF");
$db->query("UPDATE tutorato_incarichi SET stato = 'firmata', file_pdf = 'uploads/incarichi/PROVA_UNITICKET_lettera.pdf', scafis_pdf = 'uploads/incarichi/PROVA_UNITICKET_scafis.pdf', confermata_il = NOW() WHERE id = $iid");
$pg = $admin->vai($T . '&incarico=' . $iid);
prova('compare «Da allegare su Uniticket» con i due file', str_contains($pg, 'Da allegare su Uniticket') && str_contains($pg, 'Lettera di incarico firmata') && str_contains($pg, 'Modello SCAFIS compilato'), 'HTTP ' . $admin->codice);
$sc = $admin->vai('/eventi/documento_tutorato.php?i=' . $iid . '&f=scafis&scarica=1');
prova('il referente scarica il modello SCAFIS', $admin->codice === 200 && str_starts_with($sc, '%PDF'), 'HTTP ' . $admin->codice);
prova('l\'apertura del file con l\'IBAN è registrata', (int) q("SELECT COUNT(*) n FROM tutorato_accessi_dati WHERE incarico_id = $iid AND azione = 'scafis_file'")['n'] >= 1);
$db->query("UPDATE tutorato_incarichi SET stato = 'protocollata', protocollo = '1234', protocollo_data = CURDATE() WHERE id = $iid");
$pg = $admin->vai($T . '&incarico=' . $iid);
prova('nell\'iter per Uniticket non c\'è il riquadro dei pagamenti', !str_contains($pg, 'UNILAV, compenso e pagamento') && str_contains($pg, 'Registro'), 'HTTP ' . $admin->codice);
$db->query("UPDATE tutorato_incarichi SET iter = 'portale' WHERE id = $iid");
$pg = $admin->vai($T . '&incarico=' . $iid);
prova('nell\'iter nel portale il riquadro dei pagamenti c\'è', str_contains($pg, 'UNILAV, compenso e pagamento') && !str_contains($pg, 'Da allegare su Uniticket'));
@unlink('uploads/incarichi/PROVA_UNITICKET_scafis.pdf');
@unlink('uploads/incarichi/PROVA_UNITICKET_lettera.pdf');

// pulizia
$db->query("DELETE FROM tutorato_eventi WHERE incarico_id = $iid");
$db->query("DELETE FROM tutorato_incarichi WHERE id = $iid");
$db->query("DELETE FROM tutorato_bandi WHERE id = $bid");

echo "\n$ok OK, $ko KO\n";
exit($ko ? 1 : 0);
