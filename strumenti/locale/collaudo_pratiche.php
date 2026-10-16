<?php
// =========================================================================
// Collaudo degli iter: per ogni pratica attiva crea una richiesta e la porta avanti passo dopo passo come farebbero gli uffici (protocollo, marche da bollo,
// conferma dei docenti, passaggi), finché arriva alla firma del Direttore o alla fine dell'iter. Dice dove si ferma e perché. Non tocca altro.
//   php -d extension=zip -d extension=gd -d extension=intl strumenti/locale/collaudo_pratiche.php
// Si lancia dopo avvia.sh --nuovo e scenario_demo.php (servono uffici, persone e anagrafe di prova).
// =========================================================================
if (PHP_SAPI !== 'cli') {
    exit;
}
chdir(realpath(__DIR__ . '/../..'));
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SESSION = [];
define('AMBIENTE_LOCALE_CLI', 1);
$e = parse_ini_file('.env.locale');
mysqli_report(MYSQLI_REPORT_OFF);
$conn = new mysqli($e['DB_HOST'], $e['DB_USER'], $e['DB_PASS'], $e['DB_NAME']);
$conn->set_charset('utf8mb4');
// Le email non partono: si contano (l'invio vero da riga di comando sarebbe lento e inutile)
$EMAIL = [];
function inviaNotificaEmail($to, $subject, $body_html, $conn, $colore = null, array $allegati = []) { global $EMAIL; $EMAIL[] = ['a' => $to, 'oggetto' => $subject]; return true; }
require 'functions.php';

$c = \App\Core\App::per($conn);
$db = $c->get(\App\Core\Database::class);
$pratiche = $c->get(\App\Didattica\ServizioPratiche::class);
$flusso = $c->get(\App\Didattica\ServizioFlussoConvalide::class);
$marche = $c->get(\App\Didattica\ServizioMarcheBollo::class);
$iter = $c->get(\App\Didattica\ServizioIter::class);
$conferme = $c->get(\App\Didattica\ServizioConfermeDocenti::class);
$domanda = $c->get(\App\Didattica\DomandaPdfa::class);
$attestati = $c->get(\App\Didattica\ServizioAttestati::class);
$studente = $db->riga('SELECT * FROM utenti WHERE id = 5');
if (!$studente) {
    exit("Manca lo studente di prova: lancia prima scenario_demo.php\n");
}

$pdf = function (): array {
    $out = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    $tmp = tempnam(sys_get_temp_dir(), 'col');
    file_put_contents($tmp, $out);

    return ['name' => 'doc.pdf', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($out)];
};

/** Risposte generiche di un modulo: un valore plausibile per ogni campo dello studente (il corso è Biologia, il docente Anna Verdi). */
$risposte = function (array $m): array {
    $out = [];
    foreach (\App\Didattica\CampiModulo::studente(\App\Didattica\CampiModulo::da((string) $m['campi_json'])) as $c) {
        if (in_array($c['tipo'], ['titolo', 'info'], true)) {
            continue;
        }
        $v = match ($c['tipo']) {
            'corso_studio' => 'Corso di laurea in Biologia', 'docente' => 'Verdi Anna', 'date' => '2026-07-10', 'anno_accademico' => '2026/2027', 'email' => 'luca.rossi@example.org',
            'number' => '10', 'dichiarazione', 'checkbox' => 'Sì', 'select', 'radio' => $c['opzioni'][0] ?? 'x', 'multicheck' => $c['opzioni'][0] ?? 'x', 'codice_fiscale' => 'RSSLCU02A01D086X', 'tel' => '3331234567', 'tabella' => '',
            default => 'prova',
        };
        $r = ['etichetta' => $c['etichetta'], 'tipo' => $c['tipo'], 'valore' => $v, 'file' => null, 'nome_file' => null];
        if ($c['tipo'] === 'tabella') {
            $r['colonne'] = $c['opzioni'];
            $r['righe'] = [array_map(fn($n) => stripos((string) $n, 'docente') !== false ? 'Verdi Anna' : 'prova', $c['opzioni'])];
        }
        $out[] = $r;
    }

    return $out;
};

$moduli = $db->righe("SELECT * FROM didattica_moduli WHERE tipo = 'online' AND chiave IS NOT NULL ORDER BY ordine, id");
$esiti = [];
foreach ($moduli as $m) {
    $db->esegui('UPDATE didattica_moduli SET attivo = 1 WHERE id = ?', [(int) $m['id']]);
    $m = $db->riga('SELECT * FROM didattica_moduli WHERE id = ?', [(int) $m['id']]);
    $id = $pratiche->crea($m, $studente, $risposte($m));
    $passi = $iter->passi($m);
    $log = [];
    $stop = '';
    for ($giro = 0; $giro < 12 && $stop === ''; ++$giro) {
        $p = $pratiche->pratica($id);
        if (!$p) {
            $stop = 'pratica sparita';
            break;
        }
        if (in_array($p['stato'], ['accolta', 'respinta', 'chiusa'], true)) {
            $stop = 'conclusa';
            break;
        }
        $def = $iter->definizione($m);
        $idx = (int) $p['passo'] - 1;
        $d = $def[$idx] ?? null;
        if ($p['stato'] === 'integrazione') {
            $stop = 'ferma in integrazione (rifiuto del docente?)';
            break;
        }
        // Passo di conferma dei docenti
        if (($p['stato'] === 'in_lavorazione' || $p['stato'] === 'inviata') && $d && $d['t'] === 'conferma') {
            $conf = $conferme->dellaPratica($id);
            if (!$conf) {
                $stop = 'conferma dei docenti non partita: ' . implode(' ', $conferme->controllaDocenti($m, $risposte($m)));
                break;
            }
            foreach ($conf as $cc) {
                $conferme->conferma($cc);
            }
            [$er] = $flusso->trasmettiAlPasso($id, (int) $p['passo'], 0, 'Conferma dei docenti');
            $log[] = 'conferma' . ($er ? " (ERRORE: $er)" : '');
            if ($er) {
                $stop = $er;
            }
            continue;
        }
        if ($d && $d['t'] === 'direttore') {
            $stop = 'arrivata alla firma del Direttore';
            break;
        }
        if ($d && $d['t'] === 'persona') {
            $stop = 'arrivata alla firma della persona indicata';
            break;
        }
        $nomePasso = $passi[$idx + 1] ?? '?';
        $uffId = (int) ($p['ufficio_id'] ?? 0);
        $ufficio = $uffId ? (string) ($db->valore('SELECT tipo FROM uffici WHERE id = ?', [$uffId]) ?: '') : '';
        if ($ufficio === 'protocollo') {
            [$er] = $flusso->registraProtocollo($id, ($giro + 100) . '/2026', date('Y-m-d'), 8, 'Anna Protocollo', $domanda->config($m)['bollo']);
            $log[] = 'protocollo' . ($er ? " (ERRORE: $er)" : '');
            if ($er) {
                $stop = 'protocollo: ' . $er;
            }
            continue;
        }
        if ($d && $d['genera']) {
            [$er] = $attestati->mandaAlDirettore($id, 12, 'Gianna');
            $log[] = 'attestato controllato e mandato al Direttore' . ($er ? " (ERRORE: $er)" : '');
            if ($er) {
                $stop = 'attestato: ' . $er;
            }
            continue;
        }
        if ($d && $d['uscita']) {
            [$er] = $attestati->protocolloUscita($id, '900/2026', date('Y-m-d'), $pdf(), 12, 'Gianna');
            $log[] = 'protocollo in uscita' . ($er ? " (ERRORE: $er)" : '');
            $stop = $er ? 'protocollo in uscita: ' . $er : 'conclusa dopo il protocollo in uscita';
            break;
        }
        if ($idx + 1 >= count($def)) {
            $stop = 'ultimo passo dell\'iter (' . $nomePasso . '): ora si porta in seduta e si conclude';
            break;
        }
        if ($d && $d['pagamento']) {
            $e1 = $marche->avvisaMarche($id, 9, 'Marco');
            [$e2] = $marche->registraPagamento($id, $pdf(), true, 9, 'Marco');
            $log[] = 'marche' . (($e1 || $e2) ? ' (ERRORE: ' . ($e1 ?: $e2) . ')' : '');
            if ($e1 || $e2) {
                $stop = 'marche da bollo: ' . ($e1 ?: $e2);
            }
            continue;
        }
        if ($d && $d['documento']) {
            [$er] = $marche->caricaDocumento($id, $pdf(), 9, 'Marco');
            $log[] = 'documento' . ($er ? " (ERRORE: $er)" : '');
            if ($er) {
                $stop = 'documento: ' . $er;
            }
            continue;
        }
        [$er] = $flusso->trasmettiAlPasso($id, (int) $p['passo'], 9, 'Marco');
        $log[] = 'passa da «' . $nomePasso . '»' . ($er ? " (STOP: $er)" : '');
        if ($er) {
            $stop = $er;
        }
    }
    $p = $pratiche->pratica($id);
    $esiti[] = [$m['chiave'], $stop ?: 'troppi passaggi', implode(' → ', $log), (string) ($p['stato'] ?? '?') . ' passo ' . (int) ($p['passo'] ?? 0) . '/' . count($iter->definizione($m)), $iter->passi($m)];
}

foreach ($esiti as [$k, $stop, $log, $stato, $passi]) {
    echo str_pad($k, 26) . ' ' . $stato . ' · ' . $stop . "\n    iter: " . implode(' > ', array_slice($passi, 1, -1)) . "\n    fatto: " . $log . "\n";
}
// Pulizia: le pratiche del collaudo si cancellano (restano solo quelle dello scenario)
$db->esegui("DELETE FROM pratiche WHERE nome = 'Luca' AND id > (SELECT * FROM (SELECT COALESCE(MAX(id), 0) - " . count($esiti) . " FROM pratiche) x)");
echo "\nCollaudati " . count($esiti) . " moduli.\n";
