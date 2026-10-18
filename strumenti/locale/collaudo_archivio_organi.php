<?php
// Collaudo dell'archivio verbali con organi e cartella sul NAS, con richieste HTTP vere al portale locale:
//   organi (commissione didattica, riesame, studenti), cartelle da collegare, scansione, file spostati e tolti, download, permessi.
//   Prima: bash strumenti/locale/avvia.sh --nuovo e php strumenti/locale/scenario_demo.php
//   Poi:   php -d extension=zip -d extension=gd -d extension=intl strumenti/locale/collaudo_archivio_organi.php
// Crea e poi cancella file di prova in uploads/verbali/archivio/ e i relativi dati.
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
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 120, CURLOPT_MAXREDIRS => 5]);
        if ($post !== null) {
            curl_setopt($c, CURLOPT_POST, true);
            curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($post));
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

    /** Apre la pagina, prende il token CSRF e invia il modulo (l'esito si legge poi aprendo di nuovo la pagina: la risposta è un rimando). */
    public function invia(string $paginaForm, array $campi): string
    {
        $campi['csrf_token'] = $this->csrf($this->vai($paginaForm));

        return $this->vai($paginaForm, $campi);
    }
}

function pdf(string $rel, string $contenuto = ''): void
{
    $p = 'uploads/verbali/archivio/' . $rel;
    @mkdir(dirname($p), 0755, true);
    file_put_contents($p, "%PDF-1.4\n" . ($contenuto !== '' ? $contenuto : $rel) . "\n%%EOF");
}
function pulisci(string $dir): void
{
    foreach (glob(rtrim($dir, '/') . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
        if (in_array(basename($f), ['.', '..'], true)) {
            continue;
        }
        is_dir($f) ? pulisci($f) : @unlink($f);
    }
    @rmdir($dir);
}

$admin = new Persona($BASE, 1);
$referente = new Persona($BASE, 5); // uno studente: nessun accesso
$P = '/eventi/admin/didattica.php?p_id=1&tab=sedute&archivio=1';

// pulizia iniziale
pulisci('uploads/verbali/archivio');
$db->query('DELETE FROM didattica_sedute WHERE da_cartella = 1');
$db->query('DELETE FROM archivio_organi');
$db->query("UPDATE didattica_consigli SET cartella_archivio = ''");
@unlink('cache/archivio_scansione.json');

titolo('Cartelle da collegare');
pdf('Biologia/2019/Verbale_08_2019-03-12.pdf');
pdf('Biologia/Commissione didattica/2020/Verbale_2_2020-05-05.pdf');
pdf('Biologia/Gruppo di riesame/2021-2022/Riesame_2022-01-20.pdf');
pdf('Biologia/Riunioni con gli studenti/Incontro_2021-04-01.pdf');
pdf('Chimica/2021/Verbale_1_2021-02-02.pdf');
$h = $admin->vai($P);
prova('la pagina dell\'archivio si apre con la sezione Organi', $admin->codice === 200 && str_contains($h, 'Organi e cartelle sul NAS'), 'HTTP ' . $admin->codice);
prova('segnala le cartelle trovate da collegare', str_contains($h, 'Cartelle trovate sul NAS da collegare') && str_contains($h, 'Chimica') && str_contains($h, 'Biologia/Commissione didattica'));
prova('il portale non ha ancora importato nulla', (int) q('SELECT COUNT(*) n FROM didattica_sedute WHERE da_cartella = 1')['n'] === 0);

titolo('Organi di un corso');
$admin->invia($P, ['crea_organi_standard' => 1, 'corso' => 'Corso di laurea in Biologia', 'cartella_corso' => 'Biologia']);
$h = $admin->vai($P);
prova('creati i tre organi', str_contains($h, 'Commissione didattica – Corso di laurea in Biologia') && str_contains($h, 'Gruppo di riesame – Assicurazione della Qualità') && str_contains($h, 'Riunione con gli studenti'), messaggio($h));
prova('nel database con le cartelle', (int) q("SELECT COUNT(*) n FROM archivio_organi WHERE cartella IN ('Biologia/Commissione didattica','Biologia/Gruppo di riesame','Biologia/Riunioni con gli studenti')")['n'] === 3);
$cons = q('SELECT id, nome FROM didattica_consigli ORDER BY id LIMIT 1');
$admin->invia($P, ['cartella_consiglio' => $cons['id'], 'cartella' => 'Biologia']);
prova('cartella del consiglio salvata', q('SELECT cartella_archivio c FROM didattica_consigli WHERE id = ' . (int) $cons['id'])['c'] === 'Biologia');

prova('il campo Tipo ha il Consiglio di corso di studi e la scelta di un tipo nuovo', str_contains($h, 'Consiglio di corso di studi') && str_contains($h, 'Nuovo tipo'));
titolo('Scansione');
$admin->invia($P, ['scansiona_archivio' => 1]);
$h = $admin->vai($P);
$n = (int) q('SELECT COUNT(*) n FROM didattica_sedute WHERE da_cartella = 1')['n'];
prova('importati i 4 PDF collegati (non Chimica)', $n === 4, "importati: $n");
prova('ogni PDF ha il suo organo', (int) q("SELECT COUNT(*) n FROM didattica_sedute WHERE da_cartella = 1 AND organo LIKE 'Commissione didattica%'")['n'] === 1
    && (int) q("SELECT COUNT(*) n FROM didattica_sedute WHERE da_cartella = 1 AND consiglio_id = " . (int) $cons['id'])['n'] === 1
    && (int) q("SELECT COUNT(*) n FROM didattica_sedute WHERE da_cartella = 1 AND organo LIKE 'Riunione con gli studenti%'")['n'] === 1);
prova('Chimica resta da collegare', str_contains($h, 'Chimica') && str_contains($h, 'Cartelle trovate sul NAS da collegare'));
$id_comm = (int) q("SELECT id FROM archivio_organi WHERE tipo = 'commissione'")['id'];
$hf = $admin->vai($P . '&organo=' . $id_comm);
prova('filtro per organo', str_contains($hf, 'Verbale_2_2020-05-05.pdf') && !str_contains($hf, 'Incontro_2021-04-01.pdf'));
prova('il verbale è segnato «NAS»', str_contains($hf, 'NAS'));

titolo('Download');
$id_v = (int) q("SELECT id FROM didattica_sedute WHERE da_cartella = 1 AND nome_originale = 'Verbale_2_2020-05-05.pdf'")['id'];
$pdf = $admin->vai('/eventi/admin/didattica.php?p_id=1&tab=sedute&id=' . $id_v . '&esporta=verbale_pdf');
prova('il PDF si scarica dal portale', $admin->codice === 200 && str_starts_with($pdf, '%PDF'), 'HTTP ' . $admin->codice . ' ' . $admin->tipo);
$h = $admin->vai('/eventi/uploads/verbali/archivio/Biologia/Commissione%20didattica/2020/Verbale_2_2020-05-05.pdf');
prova('dal web non si raggiunge direttamente', !str_starts_with($h, '%PDF'));

titolo('Collegare una cartella nuova');
$admin->invia($P, ['collega_cartella' => 1, 'cartella' => 'Chimica', 'scelta' => 'nuovo', 'nome' => 'Consiglio di corso di studi in Chimica', 'tipo' => 'altro']);
$h = $admin->vai($P);
prova('creato l\'organo e letto il PDF', (int) q("SELECT COUNT(*) n FROM didattica_sedute WHERE da_cartella = 1 AND organo = 'Consiglio di corso di studi in Chimica'")['n'] === 1 && !str_contains($h, 'Cartelle trovate sul NAS da collegare'));

titolo('Modifiche sul NAS');
unlink('uploads/verbali/archivio/Chimica/2021/Verbale_1_2021-02-02.pdf');
$admin->invia($P, ['scansiona_archivio' => 1]);
prova('un PDF tolto dal NAS sparisce dall\'archivio', (int) q("SELECT COUNT(*) n FROM didattica_sedute WHERE da_cartella = 1 AND organo LIKE '%Chimica'")['n'] === 0);
$admin->invia($P, ['elimina_verbale_importato' => $id_v]);
prova('dal portale non si cancella un file del NAS', is_file('uploads/verbali/archivio/Biologia/Commissione didattica/2020/Verbale_2_2020-05-05.pdf') && (int) q("SELECT COUNT(*) n FROM didattica_sedute WHERE id = $id_v")['n'] === 1);

titolo('Permessi');
$hr = $referente->vai($P);
prova('chi non gestisce la Didattica non vede gli organi e le cartelle', !str_contains($hr, 'Organi e cartelle sul NAS'));
$referente->invia($P, ['scansiona_archivio' => 1]);
prova('e non può avviare la scansione', $referente->codice === 403 || (int) q("SELECT COUNT(*) n FROM didattica_sedute WHERE da_cartella = 1")['n'] === 4);

// pulizia finale
pulisci('uploads/verbali/archivio');
$db->query('DELETE FROM didattica_sedute WHERE da_cartella = 1');
$db->query('DELETE FROM archivio_organi');
$db->query("UPDATE didattica_consigli SET cartella_archivio = ''");
@unlink('cache/archivio_scansione.json');

echo "\n$ok OK, $ko KO\n";
exit($ko ? 1 : 0);
