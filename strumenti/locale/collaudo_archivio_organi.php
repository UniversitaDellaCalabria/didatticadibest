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

titolo('non ancora assegnate a un organo');
pdf('Biologia/2019/Verbale_08_2019-03-12.pdf');
pdf('Biologia/Commissione didattica/2020/Verbale_2_2020-05-05.pdf');
pdf('Biologia/Gruppo di riesame/2021-2022/Riesame_2022-01-20.pdf');
pdf('Biologia/Riunioni con gli studenti/Incontro_2021-04-01.pdf');
pdf('Chimica/2021/Verbale_1_2021-02-02.pdf');
$h = $admin->vai($P);
prova('la pagina dell\'archivio si apre con la sezione Organi', $admin->codice === 200 && str_contains($h, 'Organi per corso di studi'), 'HTTP ' . $admin->codice);
prova('segnala le cartelle trovate da collegare', str_contains($h, 'non ancora assegnate a un organo') && str_contains($h, 'Chimica') && str_contains($h, 'Biologia/Commissione didattica'));
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
prova('Chimica resta da collegare', str_contains($h, 'Chimica') && str_contains($h, 'non ancora assegnate a un organo'));
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
prova('creato l\'organo e letto il PDF', (int) q("SELECT COUNT(*) n FROM didattica_sedute WHERE da_cartella = 1 AND organo = 'Consiglio di corso di studi in Chimica'")['n'] === 1 && !str_contains($h, 'non ancora assegnate a un organo'));

titolo('Modifiche sul NAS');
unlink('uploads/verbali/archivio/Chimica/2021/Verbale_1_2021-02-02.pdf');
$admin->invia($P, ['scansiona_archivio' => 1]);
prova('un PDF tolto dal NAS sparisce dall\'archivio', (int) q("SELECT COUNT(*) n FROM didattica_sedute WHERE da_cartella = 1 AND organo LIKE '%Chimica'")['n'] === 0);
$admin->invia($P, ['elimina_verbale_importato' => $id_v]);
prova('dal portale non si cancella un file del NAS', is_file('uploads/verbali/archivio/Biologia/Commissione didattica/2020/Verbale_2_2020-05-05.pdf') && (int) q("SELECT COUNT(*) n FROM didattica_sedute WHERE id = $id_v")['n'] === 1);

titolo('Verbale con gli allegati');
pdf('Biologia/Commissione didattica/2021/Verb. 05-03-2021/Verb. 05-03-2021.pdf', 'verbale con allegati');
pdf('Biologia/Commissione didattica/2021/Verb. 05-03-2021/ALLEGATO_relazione.pdf', 'allegato pdf');
file_put_contents('uploads/verbali/archivio/Biologia/Commissione didattica/2021/Verb. 05-03-2021/ALLEGATO_dati.xlsx', 'PK-finto');
$admin->invia($P, ['scansiona_archivio' => 1]);
$riga_v = q("SELECT id, data, allegati_json FROM didattica_sedute WHERE nome_originale = 'Verb. 05-03-2021.pdf'");
prova('il verbale della cartella è uno solo, con la data del nome', !empty($riga_v['id']) && $riga_v['data'] === '2021-03-05');
$all_v = json_decode((string) ($riga_v['allegati_json'] ?? ''), true) ?: [];
prova('gli allegati (PDF ed Excel) sono agganciati', array_column($all_v, 'n') === ['ALLEGATO_dati.xlsx', 'ALLEGATO_relazione.pdf'], json_encode(array_column($all_v, 'n')));
prova('l\'allegato PDF non è un verbale a sé', (int) q("SELECT COUNT(*) n FROM didattica_sedute WHERE nome_originale = 'ALLEGATO_relazione.pdf'")['n'] === 0);
$hl = $admin->vai($P . '&organo=' . $id_comm);
prova('l\'elenco mostra «2 allegati»', str_contains($hl, '2 allegati') && str_contains($hl, 'ALLEGATO_dati.xlsx'));
$x = $admin->vai('/eventi/admin/didattica.php?p_id=1&tab=sedute&id=' . (int) $riga_v['id'] . '&esporta=allegato_verbale&n=0');
prova('l\'allegato Excel si scarica', $admin->codice === 200 && str_contains($admin->tipo, 'spreadsheetml') && $x === 'PK-finto', 'HTTP ' . $admin->codice . ' ' . $admin->tipo);
$admin->vai('/eventi/admin/didattica.php?p_id=1&tab=sedute&id=' . (int) $riga_v['id'] . '&esporta=allegato_verbale&n=9');
prova('un allegato inesistente non si scarica', $admin->codice !== 200);
titolo('Pubblicazione di più verbali insieme');
$ids_c = [];
$rs = $db->query('SELECT id FROM didattica_sedute WHERE da_cartella = 1 AND consiglio_id = ' . (int) $cons['id']);
while ($rs && ($rw = $rs->fetch_assoc())) { $ids_c[] = (int) $rw['id']; }
$db->query('UPDATE didattica_sedute SET pubblicabile = 0');
$hb = $admin->vai($P);
prova('la tabella ha le caselle e il blocco «più verbali insieme»', str_contains($hb, 'name="ids[]"') && str_contains($hb, 'Pubblica i selezionati'));
$admin->invia($P, ['pubblica_blocco' => 1, 'ids' => $ids_c]);
$admin->vai($P);
prova('senza la conferma sui dati personali non pubblica', (int) q('SELECT COUNT(*) n FROM didattica_sedute WHERE pubblicabile = 1')['n'] === 0);
$admin->invia($P, ['pubblica_blocco' => 1, 'conferma_blocco' => 1, 'ids' => $ids_c]);
$n_pub = (int) q('SELECT COUNT(*) n FROM didattica_sedute WHERE pubblicabile = 1')['n'];
prova('con la conferma pubblica tutti i selezionati del consiglio', $n_pub === count($ids_c) && $n_pub > 0, "pubblici: $n_pub su " . count($ids_c));
$admin->invia($P, ['ritira_blocco' => 1, 'ids' => $ids_c]);
prova('il ritiro in blocco li toglie dalla pagina pubblica', (int) q('SELECT COUNT(*) n FROM didattica_sedute WHERE pubblicabile = 1')['n'] === 0);
titolo('Pagina pubblica: organi e filtri');
$db->query('DELETE FROM verbali_accessi_log');
$db->query('DELETE FROM verbali_accessi');
// la commissione appartiene al corso del consiglio (stesso nome) e il suo nome ripete il corso
$admin->invia($P, ['salva_organo' => 1, 'id' => $id_comm, 'nome' => 'Commissione didattica – ' . $cons['nome'], 'tipo' => 'commissione', 'corso' => $cons['nome'], 'cartella' => 'Biologia/Commissione didattica']);
$ids_p = [];
$rs = $db->query('SELECT id FROM didattica_sedute WHERE da_cartella = 1 AND (consiglio_id = ' . (int) $cons['id'] . ' OR organo_id = ' . $id_comm . ')');
while ($rs && ($rw = $rs->fetch_assoc())) { $ids_p[] = (int) $rw['id']; }
$admin->invia($P, ['pubblica_blocco' => 1, 'conferma_blocco' => 1, 'ids' => $ids_p]);
$admin->invia('/eventi/admin/didattica.php?p_id=1&tab=sedute&accessi=1', ['crea_accesso_verbali' => 1, 'etichetta' => 'Prova esterni', 'password' => 'PasswordProva-2026!', 'consigli_ids' => [(int) $cons['id']]]);
$ext = new Persona($BASE);
$ext->invia('/eventi/verbali.php', ['accedi' => 1, 'password' => 'PasswordProva-2026!']);
$pg = $ext->vai('/eventi/verbali.php');
prova('con la password si entra', str_contains($pg, 'Verbali dei consigli e degli organi') && str_contains($pg, 'Prova esterni'), 'HTTP ' . $ext->codice);
prova('i verbali sono per organo: il consiglio e la commissione del suo corso', str_contains($pg, '<h3>Consiglio</h3>') && str_contains($pg, '<h3>Commissione didattica</h3>') && count($ids_p) >= 3, count($ids_p) . ' verbali');
prova('ci sono i filtri a tendina per organo e anno accademico', str_contains($pg, 'name="organo"') && str_contains($pg, 'name="aa"'));
prova('i verbali pubblicati sono tutti elencati', substr_count($pg, 'verbali.php?d=') === count($ids_p), substr_count($pg, 'verbali.php?d=') . ' link');
$pf = $ext->vai('/eventi/verbali.php?organo=' . urlencode('Commissione didattica'));
$n_comm = (int) q('SELECT COUNT(*) n FROM didattica_sedute WHERE da_cartella = 1 AND organo_id = ' . $id_comm)['n'];
prova('filtrando per organo restano solo i suoi verbali', substr_count($pf, 'verbali.php?d=') === $n_comm && $n_comm > 0, substr_count($pf, 'verbali.php?d=') . ' link, attesi ' . $n_comm);
preg_match('#verbali\.php\?d=(\d+)#', $pg, $md);
$dl = $ext->vai('/eventi/verbali.php?d=' . ($md[1] ?? 0));
prova('un verbale si scarica', $ext->codice === 200 && str_starts_with($dl, '%PDF'), 'HTTP ' . $ext->codice);
$altri = new Persona($BASE);
$altri->vai('/eventi/verbali.php?d=' . ($md[1] ?? 0));
prova('senza password non si scarica nulla', $altri->codice === 404 || !str_starts_with($altri->vai('/eventi/verbali.php?d=' . ($md[1] ?? 0)), '%PDF'));
if (!getenv('COLLAUDO_TIENI')) { // COLLAUDO_TIENI=1 lascia i dati per guardare la pagina pubblica nel browser
    $db->query('DELETE FROM verbali_accessi_log');
    $db->query('DELETE FROM verbali_accessi');
    $db->query('UPDATE didattica_sedute SET pubblicabile = 0');
}
titolo('Permessi');
$hr = $referente->vai($P);
prova('chi non gestisce la Didattica non vede gli organi e le cartelle', !str_contains($hr, 'Organi per corso di studi'));
$referente->invia($P, ['scansiona_archivio' => 1]);
prova('e non può avviare la scansione', $referente->codice === 403 || (int) q("SELECT COUNT(*) n FROM didattica_sedute WHERE da_cartella = 1")['n'] === 4);

// pulizia finale (con COLLAUDO_TIENI=1 si lasciano file e dati)
if (!getenv('COLLAUDO_TIENI')) {
    pulisci('uploads/verbali/archivio');
    $db->query('DELETE FROM didattica_sedute WHERE da_cartella = 1');
    $db->query('DELETE FROM archivio_organi');
    $db->query("UPDATE didattica_consigli SET cartella_archivio = ''");
    @unlink('cache/archivio_scansione.json');
}

echo "\n$ok OK, $ko KO\n";
exit($ko ? 1 : 0);
