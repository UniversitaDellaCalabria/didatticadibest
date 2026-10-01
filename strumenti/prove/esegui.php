<?php
// =========================================================================
// Prove automatiche del portale, da lanciare prima di ogni caricamento sul server:
//   /c/xampp/php/php.exe -d extension=zip strumenti/prove/esegui.php
// 1. Prove delle funzioni su un database di prova usa e getta (eventi_prova, ricreato ogni volta da
//    database/schema.sql + migrazioni): convenzioni, periodi, verifica FSL, valutazioni, documenti precompilati…
// 2. Se l'ambiente locale è acceso (strumenti/locale/avvia.sh), prove delle pagine via HTTP: pagine pubbliche,
//    file riservati bloccati, accesso al pannello, iscrizione completa a un progetto FSL.
// Nessuna email parte: l'invio è sostituito da una funzione che le conta. Esce con codice 1 se una prova fallisce.
// =========================================================================
if (PHP_SAPI !== 'cli') exit;
$SITO = realpath(__DIR__ . '/../..');
chdir($SITO);
$EMAIL = [];
function inviaNotificaEmail($to, $subject, $body_html, $conn, $colore = null, array $allegati = []) { global $EMAIL; $EMAIL[] = ['a' => $to, 'oggetto' => $subject, 'corpo' => $body_html]; return true; }
$_SERVER['HTTP_HOST'] = 'dibest2.unical.it'; $_SERVER['PHP_SELF'] = '/eventi/index.php';

$OK = 0; $KO = 0;
function prova(bool $esito, string $nome, string $dettaglio = ''): void {
    global $OK, $KO;
    if ($esito) { $OK++; echo "  \e[32mOK\e[0m  $nome\n"; } else { $KO++; echo "  \e[31mKO\e[0m  $nome" . ($dettaglio !== '' ? "  → $dettaglio" : '') . "\n"; }
}
function sezione(string $t): void { echo "\n== $t\n"; }

// ---------------------------------------------------------------------------
// Database di prova
$MYSQL = getenv('MYSQL_BIN') ?: 'C:/xampp/mysql/bin/mysql.exe';
$conn = @new mysqli('127.0.0.1', 'root', '');
if ($conn->connect_error) { fwrite(STDERR, "Database non raggiungibile: avvia MariaDB (bash strumenti/locale/avvia.sh).\n"); exit(2); }
$conn->query("DROP DATABASE IF EXISTS eventi_prova");
$conn->query("CREATE DATABASE eventi_prova CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
exec('"' . $MYSQL . '" -u root eventi_prova < "' . $SITO . '/database/schema.sql" 2>&1', $out_sql, $rc);
if ($rc !== 0) { fwrite(STDERR, "Import dello schema non riuscito: " . implode("\n", $out_sql) . "\n"); exit(2); }
$conn->select_db('eventi_prova');
$conn->set_charset('utf8mb4');
require $SITO . '/functions.php';
@unlink($SITO . '/cache/schema_v29.ok');
$marker = glob($SITO . '/cache/schema_v*.ok');
foreach ($marker as $m) rename($m, $m . '.bak');     // non toccare l'ambiente locale: si ripristina alla fine
assicura_schema($conn);
foreach (glob($SITO . '/cache/schema_v*.ok') as $m) @unlink($m);
foreach ($marker as $m) rename($m . '.bak', $m);

$q = function (string $sql) use ($conn) { if (!$conn->query($sql)) { fwrite(STDERR, "SQL: " . $conn->error . "\n$sql\n"); exit(2); } };
$st = fn(int $id) => $conn->query("SELECT stato, convenzione, conv_promemoria FROM prenotazioni WHERE id = $id")->fetch_assoc();
$giorni = fn(int $n) => date('Y-m-d', strtotime(($n >= 0 ? '+' : '') . $n . ' days'));

$q("INSERT INTO pagine_eventi (id, titolo, slug) VALUES (1, 'OPENLAB', 'openlab'), (2, 'FSL', 'fsl')");
$q("INSERT INTO eventi (id, pagina_id, titolo, tipo, descrizione_breve) VALUES (10, 1, 'Laboratorio', 'evento', 'Esperienze di laboratorio.'), (20, 2, 'Progetto FSL', 'progetto', NULL)");
$q("INSERT INTO progetti_dettagli (evento_id, convenzione, dedicata_scuole, per_scuole, attestati) VALUES (10, 1, 1, 1, 0)");
$q("INSERT INTO progetti_dettagli (evento_id, convenzione, per_scuole, data_inizio, data_fine, ore_totali, referenti_json, obiettivi) VALUES (20, 1, 1, '" . $giorni(10) . "', '" . $giorni(60) . "', 30, '[{\"nome\":\"Tutor Dipartimento\"}]', '<p>Obiettivi del percorso.</p>')");
$q("INSERT INTO turni (id, evento_id, nome_turno, data_turno, orario_inizio, orario_fine, max_posti, richiede_approvazione) VALUES
    (100, 10, 'Turno A', '" . $giorni(20) . "', '09:00', '12:00', 5, 0), (200, 20, 'Edizione 1', NULL, NULL, NULL, 1, 0), (201, 20, 'Edizione 2', NULL, NULL, NULL, 1, 1)");
$q("INSERT INTO scuole (codice, denominazione, istituto_codice, istituto_denominazione, tipo, comune, provincia, regione, indirizzo, cap) VALUES
    ('CSPS00001A', 'LICEO UNO', 'CSIS00001A', 'IIS UNO', 'LICEO', 'COSENZA', 'COSENZA', 'CALABRIA', 'VIA ROMA 1', '87100'),
    ('CSPS00002B', 'LICEO DUE', NULL, NULL, 'LICEO', 'RENDE', 'COSENZA', 'CALABRIA', '', '')");

// ---------------------------------------------------------------------------
sezione("Periodi e validità delle convenzioni");
prova(periodo_attivita('2026-10-10', '2026-12-10') === ['2026-10-10', '2026-12-10'], "periodo di un progetto (dal/al)");
prova(periodo_attivita(null, null, '2026-11-02') === ['2026-11-02', '2026-11-02'], "periodo di un evento (giorno del turno)");
prova(periodo_attivita(null, null, null) === [date('Y-m-d'), date('Y-m-d')], "senza date: oggi");
salva_convenzione($conn, ['scuola_codice' => 'CSPS00001A', 'data_stipula' => $giorni(-300), 'scadenza' => $giorni(30), 'docenti' => [['nome' => 'Docente Uno', 'email' => 'uno@example.org'], ['nome' => '', 'email' => '']]]);
prova(convenzione_valida($conn, 'CSPS00001A', true, $giorni(20), $giorni(20)) !== null, "convenzione copre il giorno del turno");
prova(convenzione_valida($conn, 'CSPS00001A', true, $giorni(10), $giorni(60)) === null, "convenzione che scade prima della fine del progetto non basta");
prova(convenzione_valida($conn, 'CSPS00002B', true) === null, "scuola senza convenzione");
prova(salva_convenzione($conn, ['scuola_codice' => 'CSPS00002B', 'data_stipula' => '2027-01-01', 'scadenza' => '2026-01-01']) === null, "date invertite rifiutate");
prova(salva_convenzione($conn, ['scuola_codice' => 'NONESISTE1', 'data_stipula' => $giorni(0)]) === null, "scuola fuori anagrafe rifiutata");
$doc = json_decode((string)$conn->query("SELECT docenti_json FROM convenzioni_scuole WHERE scuola_codice = 'CSPS00001A'")->fetch_assoc()['docenti_json'], true);
prova(count($doc) === 1 && $doc[0]['email'] === 'uno@example.org', "docenti dell'Allegato A (righe vuote scartate)");
$cs = cerca_scuole($conn, 'liceo uno');
prova(isset($cs[0]['conv'][0]) && $cs[0]['conv'][0][1] === $giorni(30), "ricerca scuole con i periodi di validità");
prova(testo_validita_convenzione(['data_stipula' => '2026-01-01', 'scadenza' => '2026-12-31']) === 'dal 01/01/2026 al 31/12/2026', "testo della validità");

sezione("Verifica delle iscrizioni FSL e registrazione");
$q("INSERT INTO prenotazioni (id, turno_id, codice_prenotazione, stato, nome, cognome, email, scuola_codice, convenzione, dati_custom_json) VALUES
    (1, 200, 'FS-1', 'confermata', 'Anna', 'Rossi', 'anna@example.org', 'CSPS00001A', NULL, '{\"numero_partecipanti\":\"20\"}'),
    (2, 100, 'OL-2', 'confermata', 'Anna', 'Rossi', 'anna@example.org', 'CSPS00001A', NULL, NULL),
    (3, 100, 'OL-3', 'confermata', 'Bruno', 'Verdi', 'bruno@example.org', NULL, NULL, '{\"scuola\":\"Scuola scritta a mano\"}'),
    (4, 201, 'FS-4', 'da_approvare', 'Carla', 'Neri', 'carla@example.org', 'CSPS00002B', 'no', NULL)");
$EMAIL = [];
$v = verifica_convenzioni_fsl($conn);
prova($v == ['coperte' => 1, 'da_stipulare' => 2, 'nuove_da_stipulare' => 1, 'senza_codice' => 1], "verifica: coperte / da stipulare / scritte a mano", json_encode($v));
prova($st(1) == ['stato' => 'confermata', 'convenzione' => 'no', 'conv_promemoria' => '3'], "prenotazione confermata non coperta: da stipulare, stato invariato, senza promemoria", json_encode($st(1)));
prova($st(2)['convenzione'] === 'ricevuta', "prenotazione coperta: ricevuta");
prova(count($EMAIL) === 0, "la verifica non manda email");
[$id_c, $n] = salva_convenzione($conn, ['scuola_codice' => 'CSPS00001A', 'data_stipula' => $giorni(0), 'scadenza' => $giorni(364)]);
prova($n === 1 && $st(1)['convenzione'] === 'ricevuta', "rinnovo: la prenotazione al progetto diventa coperta");
$EMAIL = [];
prova(convenzione_ricevuta_da_gestore($conn, 4, 'prova') === true, "convenzione ricevuta dal gestore");
prova($st(4)['stato'] === 'da_approvare', "turno con approvazione: resta da approvare");
prova(convenzione_valida($conn, 'CSPS00002B', true, $giorni(10), $giorni(60)) !== null, "convenzione registrata dal gestore copre il periodo del progetto");
prova(count($EMAIL) === 1 && str_contains($EMAIL[0]['corpo'], 'valutazione degli organizzatori'), "email alla scuola: resta in valutazione");

sezione("Email e documenti per la scuola");
$istr = html_istruzioni_convenzione([], true, 'FS-1');
prova(str_contains($istr, 'convenzione_precompilata.php?code=FS-1') && str_contains($istr, 'dipartimento.best@pec.unical.it'), "istruzioni con convenzione precompilata e PEC");
prova(!str_contains(html_istruzioni_convenzione(['conv_url_modello' => 'https://example.org/mio.docx'], true, 'FS-1'), 'precompilata'), "area con modello proprio: niente precompilata");
$dc = dati_convenzione([]);
prova(str_ends_with($dc['modello'], '/eventi/assets/modelli/Convenzione_FSL_DiBEST.doc'), "modello servito dal portale");
prova(!str_contains(dati_convenzione(['conv_url_modello' => '../../etc/passwd'])['modello'], 'passwd'), "percorso non valido ignorato");
$EMAIL = [];
prova(email_richiesta_convenzione($conn, 2) && count($EMAIL) === 1, "richiesta della convenzione per email");
if (class_exists('ZipArchive')) {
    $f = genera_convenzione_precompilata($conn, 1);
    $xml = $f ? (string)(new class { function leggi($f) { $z = new ZipArchive(); $z->open($f); $x = $z->getFromName('word/document.xml'); $z->close(); return $x; } })->leggi($f) : '';
    $testo = strip_tags($xml);
    prova($f && !preg_match('/\{\{\w+\}\}/', $xml), "convenzione precompilata senza segnaposto");
    prova(str_contains($testo, 'IIS Uno (codice meccanografico CSIS00001A)') && str_contains($testo, 'Via Roma 1, 87100'), "istituto, codice e indirizzo dall'anagrafe", mb_substr($testo, 0, 0));
    prova(str_contains($testo, 'Numero di studenti: 20') && str_contains($testo, 'Durata: 30 ore') && str_contains($testo, 'Tutor Dipartimento'), "studenti, durata e tutor");
    prova(str_contains($testo, 'Obiettivi del percorso') && !str_contains($testo, 'percorso..'), "descrizione dal progetto, senza doppio punto");
    if ($f) @unlink($f);
} else prova(false, "ZipArchive non disponibile: lancia con -d extension=zip");

sezione("Scheda di valutazione FSL");
$EMAIL = [];
prova(invia_invito_valutazione($conn, 1) && count($EMAIL) === 1, "invito alla scheda");
$tok = (string)$conn->query("SELECT valutazione_token FROM prenotazioni WHERE id = 1")->fetch_assoc()['valutazione_token'];
$p_v = prenotazione_da_valutazione($conn, $tok);
prova($p_v !== null && prenotazione_da_valutazione($conn, str_repeat('0', 40)) === null, "link personale valido / non valido");
$voti = array_fill_keys(array_keys(VALUTAZIONE_FSL_ASPETTI), 4);
prova(salva_valutazione_fsl($conn, $p_v, ['voto' => array_merge($voti, ['tutor' => 9]), 'ripeterebbe' => 'si']) !== null, "voto fuori scala rifiutato");
prova(salva_valutazione_fsl($conn, $p_v, ['voto' => $voti]) !== null, "risposta obbligatoria mancante rifiutata");
prova(salva_valutazione_fsl($conn, $p_v, ['voto' => $voti, 'ripeterebbe' => 'si', 'testo' => ['punti_forza' => 'Ottimo']]) === null, "scheda salvata");
prova(salva_valutazione_fsl($conn, prenotazione_da_valutazione($conn, $tok), ['voto' => $voti, 'ripeterebbe' => 'si']) !== null, "seconda compilazione rifiutata");
prova((float)$conn->query("SELECT media FROM valutazioni_fsl WHERE prenotazione_id = 1")->fetch_assoc()['media'] === 4.0, "media calcolata");

sezione("Scheda di Ateneo modificabile dalla persona");
$q("INSERT INTO personale_ateneo (id, cognome, nome, email, telefono, ufficio, ruolo, gruppo, dettaglio_json, dettaglio_il) VALUES ('mario.rossi', 'Rossi', 'Mario', 'mario.rossi@example.org', '0984 111', 'Cubo 1', 'Personale Tecnico Amministrativo', 'pta', '{\"ricevimento\":\"Lunedi 9-11\",\"siti\":[]}', NOW())");
$p_ate = persona_ateneo($conn, 'mario.rossi');
$sch = scheda_persona($conn, $p_ate);
prova($sch['valori']['telefono'] === '0984 111' && $sch['valori']['ricevimento'] === 'Lunedi 9-11' && !$sch['modificati'], "senza modifiche: dati del portale");
prova(salva_modifiche_persona($conn, 'mario.rossi', ['telefono' => 'abc<script>']) !== null, "telefono non valido rifiutato");
prova(salva_modifiche_persona($conn, 'mario.rossi', ['ufficio' => 'Cubo 4B', 'sito' => 'example.org/mario', 'bio' => '<b>Ciao</b>']) === null, "modifiche salvate");
$sch = scheda_persona($conn, $p_ate);
prova($sch['valori']['ufficio'] === 'Cubo 4B' && $sch['valori']['telefono'] === '0984 111' && $sch['valori']['sito'] === 'https://example.org/mario' && $sch['valori']['bio'] === 'Ciao', "modifiche sopra i dati del portale (sito https, niente HTML)");
prova(in_array('ufficio', $sch['modificati'], true) && !in_array('telefono', $sch['modificati'], true), "campi modificati riconosciuti");
salva_modifiche_persona($conn, 'mario.rossi', []);
prova(!scheda_persona($conn, $p_ate)['modificati'], "ripristino dei dati del portale");

sezione("Altre regole");
prova(prenotazione_di_classe(false, ['convenzione' => 1]) && prenotazione_di_classe(false, ['dedicata_scuole' => 1]) && !prenotazione_di_classe(false, []), "eventi: classe con FSL o dedicato alle scuole");
prova(prenotazione_di_classe(true, ['per_scuole' => 1]) && !prenotazione_di_classe(true, ['per_scuole' => 0]), "progetti: classe se dedicati alle scuole");
prova(annullamento_scaduto(['annullabile_fino' => date('Y-m-d H:i:s', time() - 60)]) && !annullamento_scaduto(['annullabile_fino' => null]), "termine per annullare");
prova(colore_valido('#abc') === '#AABBCC' && colore_valido('red') === '#B30000', "colori validati");

sezione("Abilitazioni per perimetro");
// Area 2: progetto FSL 20 + evento 21 non FSL; area 1: evento FSL 10
$q("INSERT INTO eventi (id, pagina_id, titolo, tipo) VALUES (21, 2, 'Evento FSL no', 'evento')");
$q("INSERT INTO utenti (id, codice_fiscale, nome, cognome, email, ruolo_id) VALUES (71, 'PERIM71XXXXXXXXX', 'Pia', 'Progetti', 'pia@unical.it', 4), (72, 'PERIM72XXXXXXXXX', 'Fabio', 'Fsl', 'fabio@unical.it', 4)");
assegna_ambito($conn, 71, 'progetti', 2);
assegna_ambito($conn, 72, 'fsl');
prova(attivita_da_ambiti($conn, 71, 2) === [20], "tutti i progetti: solo i progetti dell'area");
prova(attivita_da_ambiti($conn, 71, 1) === [], "tutti i progetti: niente nelle altre aree");
$a72 = aree_da_ambiti($conn, 72); sort($a72);
prova($a72 === [1, 2] && attivita_da_ambiti($conn, 72, 2) === [20], "FSL: attività FSL di tutte le aree, non le altre");
prova(utente_gestisce_attivita($conn, 71, 20) && !utente_gestisce_attivita($conn, 71, 21) && !utente_gestisce_attivita($conn, 71, 10), "gestione della singola attività");
prova(in_array(71, ids_ambito_attivita($conn, 20, false), true) && !in_array(72, ids_ambito_attivita($conn, 20, false), true), "notifiche: perimetri dell'area sì, FSL di tutte le aree no");
$q("INSERT INTO abilitazioni_attesa (email, nominativo, pagina_id, permessi, eventi_ids, ambito) VALUES ('nuovo@unical.it', 'Nuovo', 2, 'full', '', 'eventi'), ('nuovo@unical.it', 'Nuovo', 0, 'full', '', 'fsl_scuole')");
$q("INSERT INTO utenti (id, codice_fiscale, nome, cognome, email, ruolo_id) VALUES (73, 'PERIM73XXXXXXXXX', 'Nuovo', 'Arrivato', 'nuovo@unical.it', 4)");
collega_utente_anagrafe($conn, 73, 'nuovo@unical.it');
prova(ha_ambito($conn, 73, 'eventi', 2) && ha_ambito($conn, 73, 'fsl_scuole') && (int)$conn->query("SELECT COUNT(*) n FROM abilitazioni_attesa")->fetch_assoc()['n'] === 0, "abilitazioni in attesa attivate al primo accesso");
revoca_ambito($conn, 71, null, 2);
prova(!ha_ambito($conn, 71, 'progetti', 2), "revoca");

sezione("Convenzione chiesta in un'attività non FSL");
// Evento 21 (non FSL): convenzione chiesta da Iscrizioni ('no'), poi la convenzione nel registro copre il giorno del turno
$q("INSERT INTO turni (id, evento_id, data_turno, max_posti) VALUES (210, 21, '" . $giorni(20) . "', 30)");
$q("INSERT INTO prenotazioni (id, turno_id, codice_prenotazione, stato, nome, cognome, email, scuola_codice, convenzione) VALUES (210, 210, 'OP-NONFSL1', 'confermata', 'Ada', 'Docente', 'ada@scuola.it', 'CSPS020009', 'no')");
$q("INSERT INTO convenzioni_scuole (scuola_codice, data_stipula, scadenza) VALUES ('CSPS020009', '" . $giorni(-30) . "', '" . $giorni(300) . "')");
convenzione_valida($conn, 'CSPS020009', true);
verifica_convenzioni_fsl($conn);
prova($conn->query("SELECT convenzione FROM prenotazioni WHERE id = 210")->fetch_assoc()['convenzione'] === 'ricevuta', "la verifica la segna ricevuta anche fuori dalle attività FSL");

// ---------------------------------------------------------------------------
// Prove delle pagine sull'ambiente locale (se acceso)
$BASE = getenv('BASE_LOCALE') ?: 'http://127.0.0.1:8080';
$http = function (string $url, ?array $post = null, string $jar = '') use ($BASE) {
    $ch = curl_init(str_starts_with($url, 'http') ? $url : $BASE . $url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20]);
    if ($jar !== '') curl_setopt_array($ch, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $corpo = (string)curl_exec($ch);
    $r = ['codice' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'corpo' => $corpo, 'dove' => (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL)];
    curl_close($ch);
    return $r;
};
if (!function_exists('curl_init') || $http('/eventi/')['codice'] !== 200) {
    echo "\n(ambiente locale spento: prove delle pagine saltate — avvia bash strumenti/locale/avvia.sh)\n";
} else {
    sezione("Pagine dell'ambiente locale ($BASE)");
    foreach (['/eventi/' => 200, '/eventi/privacy.php' => 200, '/eventi/fsl.php' => 200, '/eventi/openlab' => 200, '/eventi/verifica_attestato.php' => 200,
              '/eventi/assets/modelli/Convenzione_FSL_DiBEST.doc' => 200, '/eventi/.env.locale' => 403, '/eventi/config.php' => 403,
              '/eventi/cache/' => 403, '/eventi/uploads/convenzioni/' => 403, '/eventi/modelli_documenti/convenzione_precompilabile.docx' => 403,
              '/eventi/strumenti/prove/esegui.php' => 403, '/eventi/inc/base.php' => 403, '/eventi/cron_background.php' => 403] as $u => $atteso) {
        $r = $http($u);
        $err_php = preg_match('/<b>(Fatal error|Parse error|Warning)<\/b>|Uncaught /', $r['corpo']);
        prova($r['codice'] === $atteso && !$err_php, "$u → $atteso", "risposta " . $r['codice'] . ($err_php ? ' con errore PHP' : ''));
    }
    $jar = tempnam(sys_get_temp_dir(), 'jar');
    $http('/__accesso?u=1', null, $jar);
    foreach (['dashboard.php', 'utenti.php?p_id=1', 'iscritti.php?p_id=1', 'eventi.php?p_id=1', 'progetti.php?p_id=2', 'scuole.php', 'fsl.php', 'fsl.php?tab=convenzioni', 'fsl.php?tab=verifica', 'fsl.php?tab=valutazioni', 'fsl.php?tab=convenzioni&conv_mod=1', 'sistema.php', 'impostazioni_area.php?p_id=1', 'statistiche.php?p_id=1'] as $pag) {
        $r = $http('/eventi/admin/' . $pag, null, $jar);
        $err_php = preg_match('/<b>(Fatal error|Parse error|Warning)<\/b>|Uncaught /', $r['corpo']);
        prova($r['codice'] === 200 && !$err_php && str_contains($r['corpo'], '</html>'), "pannello: $pag", "risposta " . $r['codice'] . ($err_php ? ' con errore PHP' : ''));
    }
    $r = $http('/eventi/area_personale.php', null, $jar);
    prova($r['codice'] === 200 && !preg_match('/<b>(Fatal error|Parse error|Warning)<\/b>|Uncaught /', $r['corpo']) && str_contains($r['corpo'], 'pills-profilo-tab'), "Area personale (con la scheda Profilo)");
    // Iscrizione completa a un progetto FSL (docente di prova, scuola dall'anagrafe) e pulizia
    // (dati di esempio di strumenti/locale: utente 4, progetto 20 edizione 2 = turno 201, scuola senza convenzione CZPC00004D)
    $jar2 = tempnam(sys_get_temp_dir(), 'jar');
    $http('/__accesso?u=4', null, $jar2);
    $pag = $http('/eventi/fsl.php?progetto=20', null, $jar2);
    preg_match('/name="csrf_token" value="([^"]+)"/', $pag['corpo'], $m_csrf);
    if (empty($m_csrf[1]) || !str_contains($pag['corpo'], 'name="turno_id" value="201"')) prova(false, "modulo di iscrizione presente nella scheda del progetto");
    else {
        $r = $http('/eventi/fsl.php?progetto=20', ['csrf_token' => $m_csrf[1], 'invia_prenotazione' => 1, 'turno_id' => 201, 'nome' => 'Luca', 'cognome' => 'Insegnante',
                   'email' => 'prova.automatica@example.org', 'email_conferma' => 'prova.automatica@example.org', 'custom_scuola' => 'Liceo', 'scuola_codice' => ['scuola' => 'CZPC00004D'],
                   'custom_numero_partecipanti' => 15, 'convenzione' => 'no', 'accetta_privacy' => 'on'], $jar2);
        prova(in_array($r['codice'], [302, 303], true) && str_contains($r['dove'], 'st_tipo=convenzione'), "iscrizione FSL senza convenzione: in attesa", $r['codice'] . ' ' . $r['dove']);
        $loc = new mysqli('127.0.0.1', 'root', '', (parse_ini_file($SITO . '/.env.locale')['DB_NAME'] ?? 'eventi_locale'));
        $pr = $loc->query("SELECT id, stato, convenzione FROM prenotazioni WHERE email = 'prova.automatica@example.org' ORDER BY id DESC LIMIT 1")->fetch_assoc();
        prova(($pr['stato'] ?? '') === 'da_approvare' && ($pr['convenzione'] ?? '') === 'no', "prenotazione salvata da approvare, convenzione da stipulare", json_encode($pr));
        if ($pr) $loc->query("DELETE FROM prenotazioni WHERE id = " . (int)$pr['id']);
    }
    @unlink($jar); @unlink($jar2);
}

$conn->query("DROP DATABASE IF EXISTS eventi_prova");
echo "\n" . ($KO ? "\e[31m$KO prove fallite\e[0m, $OK superate.\n" : "\e[32mTutte le $OK prove superate.\e[0m\n");
exit($KO ? 1 : 0);
