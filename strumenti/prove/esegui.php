<?php
// =========================================================================
// Prove automatiche del portale, da lanciare prima di ogni caricamento sul server:
//   /c/xampp/php/php.exe -d extension=zip strumenti/prove/esegui.php
// e sul server, dopo il caricamento: bash strumenti/prove_server.sh (vedi lì le variabili PROVE_DB_*)
// 1. Prove delle funzioni su un database di prova usa e getta (eventi_prova, ricreato ogni volta da
//    database/schema.sql + migrazioni): convenzioni, periodi, verifica FSL, valutazioni, documenti precompilati…
// 2. Se l'ambiente locale è acceso (strumenti/locale/avvia.sh), prove delle pagine via HTTP: pagine pubbliche,
//    file riservati bloccati, accesso al pannello, iscrizione completa a un progetto FSL.
// Nessuna email parte: l'invio è sostituito da una funzione che le conta. Esce con codice 1 se una prova fallisce.
// =========================================================================
if (PHP_SAPI !== 'cli') exit;
// Windows (XAMPP): OpenSSL non trova openssl.cnf da solo e le prove delle firme PAdES non riescono a creare i certificati
// (la variabile va impostata prima dell'avvio di PHP: si rilancia lo script con la stessa riga di comando)
if (!getenv('OPENSSL_CONF') && DIRECTORY_SEPARATOR === '\\') foreach (['C:/xampp/php/extras/ssl/openssl.cnf', 'C:/xampp/apache/conf/openssl.cnf'] as $__cnf) if (is_file($__cnf)) {
    putenv("OPENSSL_CONF=$__cnf");
    $__args = [];
    foreach (array_filter(['zip', 'gd', 'intl'], 'extension_loaded') as $__ext) array_push($__args, '-d', "extension=$__ext");
    passthru(escapeshellarg(PHP_BINARY) . ' ' . implode(' ', array_map('escapeshellarg', array_merge($__args, $_SERVER['argv']))), $__rc);
    exit($__rc);
}
$SITO = realpath(__DIR__ . '/../..');
chdir($SITO);
$EMAIL = [];
function inviaNotificaEmail($to, $subject, $body_html, $conn, $colore = null, array $allegati = []) { global $EMAIL; $EMAIL[] = ['a' => $to, 'oggetto' => $subject, 'corpo' => $body_html, 'allegati' => $allegati]; return true; }
$_SERVER['HTTP_HOST'] = 'dibest2.unical.it'; $_SERVER['PHP_SELF'] = '/eventi/index.php';

$OK = 0; $KO = 0;
function prova(bool $esito, string $nome, string $dettaglio = ''): void {
    global $OK, $KO;
    if ($esito) { $OK++; echo "  \e[32mOK\e[0m  $nome\n"; } else { $KO++; echo "  \e[31mKO\e[0m  $nome" . ($dettaglio !== '' ? "  → $dettaglio" : '') . "\n"; }
}
/** Dopo un INSERT diretto in uffici_persone: la persona diventa operatore (op_id = id, come fa il portale). */
function op_fix(callable $q): void { $q("UPDATE uffici_persone SET op_id = id WHERE op_id IS NULL AND ufficio_id IN (SELECT id FROM uffici WHERE ambito = 'dipartimento' AND COALESCE(chiave, '') NOT IN ('direzione', 'segreteria_direzione'))"); }
function sezione(string $t): void { echo "\n== $t\n"; }

// ---------------------------------------------------------------------------
// Database di prova
// Sul server (strumenti/prove_server.sh): PROVE_DB_HOST, PROVE_DB_USER, PROVE_DB_PASS e PROVE_DB_NAME indicano un utente MySQL
// che può creare e cancellare il database di prova (il nome deve contenere «prova»: il database del portale non si tocca mai)
$MYSQL = getenv('MYSQL_BIN') ?: 'C:/xampp/mysql/bin/mysql.exe';
$DB_H = getenv('PROVE_DB_HOST') ?: '127.0.0.1'; $DB_U = getenv('PROVE_DB_USER') ?: 'root'; $DB_P = (string)(getenv('PROVE_DB_PASS') ?: ''); $DB_N = getenv('PROVE_DB_NAME') ?: 'eventi_prova';
if (!preg_match('/^[A-Za-z0-9_]*prova[A-Za-z0-9_]*$/', $DB_N)) { fwrite(STDERR, "PROVE_DB_NAME deve contenere «prova» (es. eventi_prova): il database viene cancellato e ricreato.\n"); exit(2); }
$conn = @new mysqli($DB_H, $DB_U, $DB_P);
if ($conn->connect_error) { fwrite(STDERR, "Database non raggiungibile: avvia MariaDB (bash strumenti/locale/avvia.sh) o controlla PROVE_DB_HOST/USER/PASS.\n"); exit(2); }
$conn->query("DROP DATABASE IF EXISTS `$DB_N`");
if (!$conn->query("CREATE DATABASE `$DB_N` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci")) { fwrite(STDERR, "Non posso creare il database di prova $DB_N: " . $conn->error . "\n"); exit(2); }
putenv('MYSQL_PWD=' . $DB_P);
exec('"' . $MYSQL . '" -h ' . escapeshellarg($DB_H) . ' -u ' . escapeshellarg($DB_U) . ' ' . $DB_N . ' < "' . $SITO . '/database/schema.sql" 2>&1', $out_sql, $rc);
putenv('MYSQL_PWD');
if ($rc !== 0) { fwrite(STDERR, "Import dello schema non riuscito: " . implode("\n", $out_sql) . "\n"); exit(2); }
$conn->select_db($DB_N);
$conn->set_charset('utf8mb4');
// I file generati dalle prove (lettere, estratti, verbali) vanno in cache/prove/, mai nelle cartelle vere del portale
define('DIR_INCARICHI', 'cache/prove/incarichi/'); define('DIR_PRATICHE', 'cache/prove/pratiche/'); define('DIR_VERBALI', 'cache/prove/verbali/');
$pulisci_prove = function () use ($SITO) {
    foreach (['incarichi', 'pratiche', 'verbali'] as $d) { foreach (glob("$SITO/cache/prove/$d/{,.}*", GLOB_BRACE) ?: [] as $f) if (is_file($f)) @unlink($f); @rmdir("$SITO/cache/prove/$d"); }
    @rmdir("$SITO/cache/prove");
};
$pulisci_prove();
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
prova(str_contains($istr, 'convenzione_online.php?code=FS-1') && str_contains($istr, 'dipartimento.best@pec.unical.it'), "istruzioni con la convenzione da compilare online e PEC");
prova(!str_contains(html_istruzioni_convenzione(['conv_url_modello' => 'https://example.org/mio.docx'], true, 'FS-1'), 'convenzione_precompilata.php?code='), "area con modello di convenzione proprio: niente convenzione precompilata");
$dc = dati_convenzione([]);
prova(str_ends_with($dc['modello'], '/assets/modelli/Convenzione_FSL_DiBEST.doc'), "modello servito dal portale");
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
    $fa = genera_convenzione_precompilata($conn, 1, 'allegato');
    $xa = $fa ? (string)(new class { function leggi($f) { $z = new ZipArchive(); $z->open($f); $x = $z->getFromName('word/document.xml'); $z->close(); return $x; } })->leggi($fa) : '';
    $ta = strip_tags($xa);
    prova($fa && !preg_match('/\{\{\w+\}\}/', $xa) && str_contains($ta, 'Numero di studenti: 20') && str_contains($ta, 'Tutor Dipartimento') && !str_contains($ta, 'A.A.'), "Allegato A precompilato (senza anno accademico nel titolo)");
    if ($fa) @unlink($fa);
    prova(str_contains(html_istruzioni_convenzione([], true, 'FS-1'), 'Allegato A') && file_exists(RADICE_SITO . '/convenzione_precompilata.php'), "Allegato A nel modulo online (restano validi i link precompilati già inviati)");
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

sezione("Macroaree e tipi di area");
prova(tipo_area(['tipo_area' => 'fsl']) === 'fsl' && tipo_area(['tipo_area' => 'boh']) === '' && tipo_area([]) === '', "tipo dell'area (valido / sconosciuto / assente)");
prova(sezione_area(['tipo_area' => 'gruppi']) === 'calendari' && sezione_area(['tipo_area' => 'eventi']) === 'orientamento' && sezione_area([]) === '', "macroarea dal tipo (gruppi in Prenotazioni e risorse)");
$gr = raggruppa_aree_per_sezione([['id' => 1, 'tipo_area' => 'gruppi'], ['id' => 2, 'tipo_area' => 'fsl'], ['id' => 3, 'tipo_area' => ''], ['id' => 4, 'tipo_area' => 'eventi']]);
prova(array_keys($gr) === ['orientamento', 'fsl', 'calendari', ''] && array_column($gr['orientamento'], 'id') === [4] && array_column($gr['fsl'], 'id') === [2], "aree raggruppate nell'ordine delle macroaree (FSL a sé), non assegnate in fondo");
prova(str_contains(html_scelta_tipo_area('t', 'fsl'), 'value="fsl" selected') && str_contains(html_scelta_tipo_area('t', 'calendario'), 'value="calendario" selected'), "tendina del tipo (anche Calendari e risorse)");
prova(sezione_area(['tipo_area' => 'calendario']) === 'calendari', "Calendari e risorse nella sua macroarea");

sezione("Moduli");
prova(modulo_di_area(['tipo_area' => '']) === 'orientamento' && modulo_di_area(['tipo_area' => 'fsl']) === 'fsl' && modulo_di_area(['tipo_area' => 'gruppi']) === 'calendari' && modulo_di_area(['tipo_area' => 'calendario']) === 'calendari', "modulo dell'area (senza tipo: Orientamento)");
prova(array_keys(MODULI_PORTALE) === ['orientamento', 'fsl', 'calendari', 'didattica', 'portale'] && PAGINE_MODULO['fsl.php'] === 'fsl' && PAGINE_MODULO['utenti.php'] === 'portale' && MODULI_PORTALE['orientamento']['nome'] === 'Eventi e seminari', "moduli (Eventi e seminari, FSL a sé) e pagine dei moduli");
$q("INSERT INTO utenti (id, codice_fiscale, nome, cognome, email, ruolo_id) VALUES (96, 'MODUL96XXXXXXXXX', 'Mara', 'Moduli', 'mara@unical.it', 4)");
$q("UPDATE pagine_eventi SET tipo_area = 'fsl' WHERE id = 2");
$q("UPDATE pagine_eventi SET tipo_area = 'calendario' WHERE id = 1");
assegna_ambito($conn, 96, 'modulo_orientamento');
$a81 = aree_da_ambiti($conn, 96);
prova(in_array(2, $a81, true) && attivita_da_ambiti($conn, 96, 1) === [10], "modulo Orientamento: le sue aree; in Prenotazioni e risorse solo le attività FSL");
prova(area_nel_modulo_utente($conn, 96, ['tipo_area' => 'fsl']) && !area_nel_modulo_utente($conn, 96, ['tipo_area' => 'calendario']), "area nel modulo dell'utente");
prova(utente_gestisce_attivita($conn, 96, 20), "modulo intero: gestisce le attività delle sue aree");
// Separazione della FSL: nessuno perde gli accessi (modulo Orientamento o perimetro «fsl» = anche modulo FSL)
$q("INSERT INTO utenti (id, codice_fiscale, nome, cognome, email, ruolo_id) VALUES (95, 'FSLPRV95XXXXXXXX', 'Franca', 'Fsl', 'franca@unical.it', 4), (94, 'FSLPRV94XXXXXXXX', 'Fabio', 'Nuovo', 'fabio.fsl@unical.it', 4)");
assegna_ambito($conn, 95, 'fsl'); assegna_ambito($conn, 94, 'modulo_fsl');
prova(ha_modulo($conn, 96, 'fsl') && ha_modulo($conn, 95, 'fsl') && ha_modulo($conn, 94, 'fsl') && !ha_modulo($conn, 94, 'orientamento'), "modulo FSL: anche a chi aveva il modulo Orientamento o il perimetro FSL");
prova(in_array(2, aree_da_ambiti($conn, 94), true) && area_nel_modulo_utente($conn, 94, ['tipo_area' => 'fsl']) && !area_nel_modulo_utente($conn, 94, ['tipo_area' => 'eventi']), "modulo FSL: le aree FSL, non gli eventi");
prova(in_array(94, ids_ambito_attivita($conn, 20), true) && in_array(96, ids_ambito_attivita($conn, 20), true), "le attività dell'area FSL sono di chi ha il modulo FSL o Orientamento");
$q("UPDATE pagine_eventi SET tipo_area = '' WHERE id IN (1, 2)");

sezione("Ambiti degli eventi e agenda unica");
prova(ambito_area(['tipo_area' => 'fsl']) === 'orientamento' && ambito_area(['tipo_area' => 'gruppi']) === 'didattica' && ambito_area(['tipo_area' => 'eventi', 'ambito' => 'ricerca']) === 'ricerca' && ambito_area([]) === 'orientamento', "ambito predefinito dell'area (scelto o dal tipo)");
prova(ambiti_evento(['ambiti' => ''], ['ambito' => 'ricerca']) === ['ricerca'] && ambiti_evento(['ambiti' => 'terza_missione,boh,orientamento'], []) === ['orientamento', 'terza_missione'], "ambiti dell'evento (propri o dell'area, valori sconosciuti ignorati)");
prova(ambiti_da_post(['ambiti' => ['orientamento']], ['tipo_area' => 'eventi']) === '' && ambiti_da_post(['ambiti' => ['ricerca', 'terza_missione']], []) === 'ricerca,terza_missione', "ambiti dal modulo: uguali all'area = vuoto");
prova(testo_posti_liberi(5000) === 'Posti disponibili' && testo_posti_liberi(1) === '1 posto libero' && testo_posti_liberi(12) === '12 posti liberi' && testo_posti_liberi(0) === '', "posti: oltre la soglia «Posti disponibili», non il numero");
$q("UPDATE pagine_eventi SET tipo_area = 'eventi', visibile = 1 WHERE id = 1");
$q("UPDATE pagine_eventi SET tipo_area = 'fsl', visibile = 1 WHERE id = 2");
$q("INSERT INTO pagine_eventi (id, titolo, slug, tipo_area, ambito, visibile) VALUES (3, 'Seminari', 'seminari', 'eventi', 'ricerca', 1)");
$q("INSERT INTO eventi (id, pagina_id, titolo, tipo, luogo, ambiti) VALUES (30, 3, 'Microbiomi marini', 'evento', 'Aula Magna', ''), (31, 3, 'Notte dei ricercatori', 'evento', 'Piazza', 'terza_missione,orientamento'), (32, 3, 'Seminario riservato', 'evento', '', '')");
$q("UPDATE eventi SET ruolo_accesso_id = 3 WHERE id = 32");
$q("INSERT INTO turni (id, evento_id, nome_turno, data_turno, orario_inizio, orario_fine, max_posti) VALUES (300, 30, 'Unico', '" . $giorni(5) . "', '15:00:00', '17:00:00', 5000), (310, 31, 'Sera', '" . $giorni(7) . "', '18:00:00', NULL, 40), (320, 32, 'Unico', '" . $giorni(6) . "', NULL, NULL, 10), (311, 31, 'Passato', '" . $giorni(-3) . "', NULL, NULL, 40)");
$ag = eventi_agenda($conn);
prova(array_column($ag, 'id') === ['30', '31', '10', '20'] || array_map('intval', array_column($ag, 'id')) === [30, 31, 10, 20], "agenda: eventi futuri di tutte le aree in ordine di data, senza quelli riservati, «da definire» in fondo", json_encode(array_column($ag, 'id')));
prova($ag[0]['ambiti'] === ['ricerca'] && $ag[1]['ambiti'] === ['orientamento', 'terza_missione'] && $ag[3]['per_scuole'] && !$ag[0]['per_scuole'] && $ag[3]['prossima_data'] === null && $ag[0]['url'] === 'seminari.php?evento=30', "agenda: ambiti, attività per le scuole, link");
$id_f = fn(array $f) => array_map('intval', array_column(eventi_agenda($conn, $f), 'id'));
prova($id_f(['ambito' => 'ricerca']) === [30] && $id_f(['ambito' => 'orientamento']) === [31, 10, 20] && $id_f(['scuole' => true]) === [10, 20] && $id_f(['q' => 'notte']) === [31] && $id_f(['limite' => 2]) === [30, 31], "agenda: filtri per ambito, per le scuole, ricerca e limite");
prova(conta_ambiti_agenda($ag)['orientamento'] === 3 && conta_ambiti_agenda($ag)['ricerca'] === 1 && conta_ambiti_agenda($ag)['scuole'] === 2 && conta_ambiti_agenda($ag)[''] === 4, "agenda: conteggi per i filtri");
prova(!isset(get_riepilogo_posti($conn, 'evento', [30])[30]) && (get_riepilogo_posti($conn, 'evento', [31])[31]['capienza'] ?? 0) === 40, "posti: i turni «senza limite» non fanno somme enormi");
$ics = ics_agenda($conn, eventi_agenda($conn, ['ambito' => 'ricerca']), 'Prova');
prova(str_starts_with($ics, 'BEGIN:VCALENDAR') && substr_count($ics, 'BEGIN:VEVENT') === 1 && str_contains($ics, 'SUMMARY:Microbiomi marini') && str_contains($ics, 'DTSTART;TZID=Europe/Rome:' . str_replace('-', '', $giorni(5)) . 'T150000') && str_contains($ics, 'CATEGORIES:Ricerca'), "calendario .ics dell'agenda per ambito");
prova(str_contains(html_voce_agenda($ag[0], ['capienza' => 10, 'liberi' => 3, 'occupati' => 7]), '3 posti liberi') && str_contains(html_voce_agenda($ag[3]), 'Per le scuole'), "voce dell'agenda con posti e «per le scuole»");
// Organizzazione proposta: home e menu per pubblico, con copia di sicurezza e ripristino
$q("INSERT IGNORE INTO configurazione_portale (id) VALUES (1)");
$q("DELETE FROM menu_voci");
$q("INSERT INTO menu_voci (id, genitore_id, etichetta, url, ordine) VALUES (1, 0, 'Home', 'index.php', 1), (2, 0, 'Openlab', 'openlab.php', 2), (3, 0, 'Archivio', '#', 3), (4, 3, 'Archivio Openlab', 'openlab_archivio.php', 1), (5, 0, 'Link Utili', '#', 4), (6, 5, 'Portale Unical', 'https://www.unical.it', 1)");
prova(array_column(voci_menu_da_tenere($conn), 'etichetta') === ['Link Utili'], "menu: si tengono solo le voci che la proposta non comprende");
$n_menu = applica_organizzazione_proposta($conn, 'Prova');
$top = array_column(db_righe($conn, "SELECT etichetta FROM menu_voci WHERE genitore_id = 0 ORDER BY ordine"), 'etichetta');
$wh = get_widgets_home(['widgets_home' => db_valore($conn, "SELECT widgets_home FROM configurazione_portale WHERE id = 1")]);
prova($n_menu > 10 && $top === ['Home', 'Orientamento', 'Studenti', 'Eventi e seminari', 'Area riservata', 'Link Utili'] && db_valore($conn, "SELECT COUNT(*) FROM menu_voci WHERE genitore_id = 5") == 1, "menu proposto: per pubblico, Link utili in fondo con le sue voci", json_encode($top));
prova($wh['percorsi'] === 1 && $wh['agenda'] === 1 && $wh['scadenze'] === 1 && $wh['prossimi_eventi'] === 0 && array_slice($wh['ordine'], 0, 4) === ['slideshow', 'annunci', 'mia_prenotazione', 'percorsi'], "home proposta: Cosa cerchi?, agenda e scadenze");
prova((bool)db_valore($conn, "SELECT COUNT(*) FROM menu_voci WHERE url = 'agenda.php?ambito=ricerca'") && (bool)db_valore($conn, "SELECT COUNT(*) FROM menu_voci WHERE url = 'seminari_archivio.php'"), "menu proposto: agenda per ambito e archivi delle aree");
prova(ripristina_organizzazione($conn) && array_column(db_righe($conn, "SELECT etichetta FROM menu_voci WHERE genitore_id = 0 ORDER BY ordine"), 'etichetta') === ['Home', 'Openlab', 'Archivio', 'Link Utili'] && get_widgets_home(['widgets_home' => db_valore($conn, "SELECT widgets_home FROM configurazione_portale WHERE id = 1")])['agenda'] === 0
      && !ripristina_organizzazione($conn), "ripristino: home e menu com'erano (una volta)");
// Menu proposto creato nascosto: il sito non cambia finché non si mostra
$vis_top = fn() => array_column(db_righe($conn, "SELECT etichetta FROM menu_voci WHERE genitore_id = 0 AND visibile = 1 ORDER BY ordine, id"), 'etichetta');
$n_nasc = crea_menu_proposto_nascosto($conn, 'Prova');
prova($n_nasc > 10 && $vis_top() === ['Home', 'Openlab', 'Archivio', 'Link Utili'] && count(voci_menu_proposto($conn)) === 5 && crea_menu_proposto_nascosto($conn) === 0, "voci del menu proposto create nascoste (Home riusata), una volta sola; il menu visibile non cambia");
prova((int)db_valore($conn, "SELECT COUNT(*) FROM menu_voci WHERE genitore_id = 0 AND url = 'index.php'") === 1 && (int)db_valore($conn, "SELECT COUNT(*) FROM menu_voci WHERE genitore_id = 0 AND visibile = 0") === 4, "nessuna Home doppia; quattro voci nuove nascoste");
prova(mostra_menu_proposto($conn, 'Prova') && $vis_top() === ['Home', 'Orientamento', 'Studenti', 'Eventi e seminari', 'Area riservata', 'Link Utili'] && (int)db_valore($conn, "SELECT COUNT(*) FROM menu_voci WHERE etichetta IN ('Openlab', 'Archivio') AND visibile = 0") === 2, "mostra: voci proposte visibili, quelle comprese nascoste (non cancellate), Link utili in fondo");
prova(ripristina_organizzazione($conn) && $vis_top() === ['Home', 'Openlab', 'Archivio', 'Link Utili'], "ripristino dopo «mostra»: il menu di prima");
applica_organizzazione_proposta($conn, 'Prova');
prova(array_column(db_righe($conn, "SELECT etichetta FROM menu_voci WHERE genitore_id = 0 ORDER BY ordine"), 'etichetta') === ['Home', 'Orientamento', 'Studenti', 'Eventi e seminari', 'Area riservata', 'Link Utili'] && !voci_menu_proposto($conn), "«Applica» dopo le voci nascoste: nessun doppione");
ripristina_organizzazione($conn); $q("DELETE FROM copie_configurazione");

sezione("Seminari: relatore, abstract, diretta, registrazione");
salva_seminario_evento($conn, 30, ['relatore' => 'Prof.ssa Elena Mari', 'relatore_ente' => 'CNR', 'abstract' => "<b>Testo</b> riga\nseconda", 'link_streaming' => 'javascript:alert(1)',
                                    'link_registrazione' => 'https://video.unical.it/x', 'relatore_persona_id' => 'inesistente']);
$s30 = db_riga($conn, "SELECT * FROM eventi WHERE id = 30");
prova($s30['relatore'] === 'Prof.ssa Elena Mari' && $s30['abstract'] === "Testo riga\nseconda" && $s30['link_streaming'] === '' && $s30['relatore_persona_id'] === null && e_seminario($s30), "seminario salvato: abstract senza HTML, solo link http(s), relatore dall'anagrafe verificato");
$hs = html_seminario($s30);
prova(str_contains($hs, 'Prof.ssa Elena Mari') && str_contains($hs, 'CNR') && str_contains($hs, 'Testo riga<br') && str_contains($hs, 'Registrazione') && !str_contains($hs, 'Segui in diretta'), "scheda: relatore, abstract e registrazione");
$q("UPDATE eventi SET link_streaming = 'https://teams.example.org/live' WHERE id = 30");
$s30 = db_riga($conn, "SELECT * FROM eventi WHERE id = 30");
prova(str_contains(html_seminario($s30), 'Segui in diretta') && !str_contains(html_seminario($s30, true), 'Segui in diretta') && html_seminario(['relatore' => '', 'abstract' => '']) === '', "diretta solo finché l'evento non è concluso; niente riquadro senza dati");
$ag_m = eventi_agenda($conn, ['q' => 'mari']);
prova(array_map('intval', array_column($ag_m, 'id')) === [30] && str_contains(html_voce_agenda($ag_m[0]), 'Prof.ssa Elena Mari (CNR)') && str_contains(ics_agenda($conn, $ag_m, 'x'), 'DESCRIPTION:Relatore: Prof.ssa Elena Mari (CNR)\\nDiretta: https://teams.example.org/live'), "agenda e .ics con il relatore (anche nella ricerca)");

sezione("Avvisi per email dei nuovi eventi");
prova(iscrivi_avvisi($conn, 'non-email', ['ricerca'], false)[1] !== null && iscrivi_avvisi($conn, 'a@b.it', [], false)[1] !== null, "iscrizione: email valida e almeno un argomento");
$EMAIL = [];
[$m_av, $e_av] = iscrivi_avvisi($conn, 'Lia@Example.org', ['ricerca', 'boh'], false);
$lia = db_riga($conn, "SELECT * FROM avvisi_iscrizioni WHERE email = 'lia@example.org'");
prova($e_av === null && $lia && $lia['ambiti'] === 'ricerca' && $lia['confermata_il'] === null && ($EMAIL[0]['a'] ?? '') === 'lia@example.org' && str_contains($EMAIL[0]['corpo'], 'avvisi.php?conferma=' . $lia['token']), "iscrizione con conferma per email");
$EMAIL = [];
prova(iscrivi_avvisi($conn, 'rita.ref@unical.it', ['orientamento'], true, ['id' => 96, 'email' => 'Rita.Ref@unical.it'])[1] === null && $EMAIL === [] && db_valore($conn, "SELECT confermata_il FROM avvisi_iscrizioni WHERE email = 'rita.ref@unical.it'") !== null, "chi è entrato con la stessa email è confermato subito");
prova(invia_avvisi_eventi($conn) === 0 && (int)db_valore($conn, "SELECT COUNT(*) FROM avvisi_eventi WHERE evento_id IN (30, 31, 10, 20)") === 4, "primo avvio: gli eventi già pubblicati non si annunciano");
prova(conferma_avvisi($conn, $lia['token']) && !conferma_avvisi($conn, str_repeat('f', 40)), "conferma dal link");
$q("INSERT INTO eventi (id, pagina_id, titolo, tipo, luogo) VALUES (33, 3, 'Genomica delle piante', 'evento', 'Aula 7'), (34, 2, 'Laboratorio per le classi', 'progetto', '')");
$q("INSERT INTO turni (id, evento_id, nome_turno, data_turno, orario_inizio, max_posti) VALUES (330, 33, 'Unico', '" . $giorni(40) . "', '10:00:00', 50), (340, 34, 'Ed. 1', '" . $giorni(41) . "', NULL, 1)");
$EMAIL = [];
prova(invia_avvisi_eventi($conn) === 2 && count($EMAIL) === 2, "nuovi eventi: un riepilogo per iscritto interessato", json_encode(array_column($EMAIL, 'oggetto')));
$m_lia = array_values(array_filter($EMAIL, fn($e) => $e['a'] === 'lia@example.org'))[0] ?? [];
$m_rit = array_values(array_filter($EMAIL, fn($e) => $e['a'] === 'rita.ref@unical.it'))[0] ?? [];
prova(($m_lia['oggetto'] ?? '') === 'Nuovo evento: Genomica delle piante' && str_contains($m_lia['corpo'] ?? '', 'avvisi.php?t=' . $lia['token']) && !str_contains($m_lia['corpo'] ?? '', 'Laboratorio per le classi')
      && str_contains($m_rit['corpo'] ?? '', 'Laboratorio per le classi') && !str_contains($m_rit['corpo'] ?? '', 'Genomica'), "ognuno riceve solo gli eventi dei suoi argomenti, con il link per cancellarsi");
$EMAIL = [];
prova(invia_avvisi_eventi($conn) === 0 && $EMAIL === [] && (int)db_valore($conn, "SELECT destinatari FROM avvisi_eventi WHERE evento_id = 33") === 1, "ogni evento si annuncia una volta sola");
$q("INSERT INTO avvisi_iscrizioni (email, ambiti, token, creata_il) VALUES ('vecchia@example.org', 'ricerca', '" . str_repeat('e', 40) . "', NOW() - INTERVAL 40 DAY)");
invia_avvisi_eventi($conn);
$n_av = conta_iscritti_avvisi($conn);
prova(!db_valore($conn, "SELECT id FROM avvisi_iscrizioni WHERE email = 'vecchia@example.org'") && $n_av[''] === 2 && $n_av['ricerca'] === 1 && $n_av['scuole'] === 1, "iscrizioni mai confermate cancellate dopo 30 giorni; conteggi per argomento");
prova(cancella_avvisi($conn, $lia['token']) && !iscrizione_avvisi_per_token($conn, $lia['token']), "cancellazione dal link");
$q("DELETE FROM turni WHERE evento_id IN (33, 34)"); $q("DELETE FROM eventi WHERE id IN (33, 34)"); $q("DELETE FROM avvisi_iscrizioni"); $q("DELETE FROM avvisi_eventi");

sezione("Report per ambito (Terza missione)");
prova(periodo_report('2026') === ['2026-01-01', '2026-12-31'] && periodo_report('2025/2026') === ['2025-10-01', '2026-09-30'] && periodo_report('2025/2027') === [date('Y') . '-01-01', date('Y') . '-12-31'], "periodo: anno solare o accademico");
$q("INSERT INTO prenotazioni (turno_id, codice_prenotazione, stato, num_posti, nome, cognome, email, presente, scuola_codice, dati_custom_json) VALUES
    (310, 'RPT1', 'confermata', 1, 'Docente', 'Uno', 'd1@example.org', 1, 'CSPS00001A', '{\"numero_partecipanti\":\"25\"}'),
    (310, 'RPT2', 'confermata', 1, 'Mario', 'Rossi', 'm@example.org', 0, NULL, NULL),
    (310, 'RPT3', 'annullata', 1, 'Anna', 'Bianchi', 'a@example.org', 0, NULL, NULL)");
$rp = dati_report_ambiti($conn, $giorni(4), $giorni(8));
$rp31 = array_values(array_filter($rp['eventi'], fn($e) => $e['id'] === 31))[0] ?? [];
prova(count($rp['eventi']) === 3 && $rp['ambiti']['ricerca']['eventi'] === 2 && (float)$rp['ambiti']['ricerca']['ore'] === 2.0 && $rp['ambiti']['orientamento']['eventi'] === 1 && $rp['ambiti']['terza_missione']['partecipanti'] === 26, "report: eventi e ore per ambito", json_encode($rp['ambiti']));
prova(($rp31['iscrizioni'] ?? 0) === 2 && ($rp31['partecipanti'] ?? 0) === 26 && ($rp31['presenze'] ?? 0) === 25 && ($rp31['scuole'] ?? 0) === 1 && $rp['totale']['eventi'] === 3 && $rp['totale']['scuole'] === 1, "report: partecipanti dichiarati per le classi, presenze, scuole; annullate escluse; totale senza doppioni");
prova(array_sum(array_map('array_sum', $rp['mesi'])) === 4 && $rp30_rel = (array_values(array_filter($rp['eventi'], fn($e) => $e['id'] === 30))[0]['relatore'] ?? '') === 'Prof.ssa Elena Mari (CNR)', "report: per mese e relatore");
$xr = excel_report_ambiti($rp, 'prova'); $xr_ok = false;
if ($xr && ($z = new ZipArchive())->open($xr) === true) { $wb = (string)$z->getFromName('xl/workbook.xml'); $tutto = ''; for ($i = 0; $i < $z->numFiles; $i++) $tutto .= (string)$z->getFromIndex($i); $xr_ok = substr_count($wb, '<sheet ') === 3 && str_contains($tutto, 'Public engagement') && str_contains($tutto, 'Notte dei ricercatori'); $z->close(); } @unlink((string)$xr);
prova($xr_ok, "report in Excel: riepilogo, eventi, per mese");
$q("DELETE FROM prenotazioni WHERE codice_prenotazione LIKE 'RPT%'");
$q("DELETE FROM menu_voci"); $q("DELETE FROM turni WHERE evento_id IN (30, 31, 32)"); $q("DELETE FROM eventi WHERE id IN (30, 31, 32)"); $q("DELETE FROM pagine_eventi WHERE id = 3");
$q("UPDATE pagine_eventi SET tipo_area = '' WHERE id IN (1, 2)");

sezione("Vista a calendario delle risorse");
prova(periodo_calendario_risorse('settimana', '2026-10-08') === ['2026-10-05', '2026-10-11'] && periodo_calendario_risorse('mese', '2026-02-10') === ['2026-02-01', '2026-02-28'] && periodo_calendario_risorse('giorno', '2026-10-08') === ['2026-10-08', '2026-10-08'], "periodi di giorno, settimana e mese");
$cal_html = html_calendario_risorse($conn, [], ['vista' => 'giorno', 'data' => '2026-10-05', 'url' => fn($c) => '?', 'url_risorsa' => fn($i, $g) => '?']);
prova(str_contains($cal_html, 'Nessuna risorsa') && str_contains($cal_html, 'Non prenotabile'), "senza risorse: legenda e avviso");

sezione("Aule collegate agli eventi");
$q("INSERT INTO risorse (id, pagina_id, nome, tipo, durata_slot, max_slot, attiva) VALUES (901, 1, 'Aula prova', 'aula', 60, 4, 1)");
$q("INSERT INTO turni (id, evento_id, data_turno, orario_inizio, orario_fine, max_posti, risorsa_id) VALUES (901, 10, '" . $giorni(30) . "', '10:00:00', '12:00:00', 30, 901), (902, 10, '" . $giorni(30) . "', '11:00:00', '13:00:00', 30, 901)");
$avv = [];
sincronizza_aula_turno($conn, 901, $avv);
$b901 = $conn->query("SELECT * FROM prenotazioni_risorse WHERE turno_id = 901 AND stato = 'confermata'")->fetch_assoc();
prova($b901 && $b901['inizio'] === $giorni(30) . ' 10:00:00' && $b901['fine'] === $giorni(30) . ' 12:00:00' && !$avv, "il turno occupa l'aula");
sincronizza_aula_turno($conn, 902, $avv);
prova(count($avv) === 1 && str_contains($avv[0], 'già occupata') && !$conn->query("SELECT 1 FROM prenotazioni_risorse WHERE turno_id = 902 AND stato = 'confermata'")->num_rows, "aula già occupata: niente doppia prenotazione, avviso");
$q("UPDATE turni SET orario_inizio = '14:00:00', orario_fine = '15:00:00' WHERE id = 901");
$avv = []; sincronizza_aula_turno($conn, 901, $avv);
prova((int)$conn->query("SELECT COUNT(*) n FROM prenotazioni_risorse WHERE turno_id = 901 AND stato = 'confermata' AND TIME(inizio) = '14:00:00'")->fetch_assoc()['n'] === 1, "cambio di orario: la stessa prenotazione si sposta");
$q("UPDATE turni SET risorsa_id = NULL WHERE id = 901");
sincronizza_aula_turno($conn, 901, $avv);
prova(!$conn->query("SELECT 1 FROM prenotazioni_risorse WHERE turno_id = 901 AND stato = 'confermata'")->num_rows, "aula tolta dal turno: torna libera");

sezione("Scelta guidata della scuola");
prova(array_column(luoghi_scuole($conn, 'regioni'), 'valore') === ['CALABRIA'], "regioni dell'anagrafe");
prova(array_column(luoghi_scuole($conn, 'province', 'Calabria'), 'n', 'valore') === ['COSENZA' => 2], "province della regione con il numero di scuole");
prova(array_column(luoghi_scuole($conn, 'comuni', 'CALABRIA', 'COSENZA'), 'nome') === ['Cosenza', 'Rende'], "comuni della provincia");
prova(luoghi_scuole($conn, 'boh') === [], "livello sconosciuto: niente");
$cs_c = cerca_scuole($conn, '', 200, ['regione' => 'CALABRIA', 'provincia' => 'COSENZA', 'comune' => 'RENDE']);
prova(count($cs_c) === 1 && $cs_c[0]['codice'] === 'CSPS00002B', "scuole del comune senza scrivere nulla");
prova(cerca_scuole($conn, 'liceo', 20, ['comune' => 'COSENZA'])[0]['codice'] === 'CSPS00001A' && cerca_scuole($conn, '', 20, ['regione' => 'CALABRIA']) === [], "ricerca nel comune; solo la regione non basta");
prova(cerca_scuole($conn, 'liceo uno')[0]['istituto'] === 'IIS Uno', "istituto di appartenenza nei risultati");

sezione("Calendari e risorse");
// Area 5 di tipo calendario; risorsa 1: lunedì 9-11 e 14-16, slot da 60', fino a 2 di seguito, ripetibile
$lun = date('Y-m-d', strtotime('monday next week'));
$sett = fn(int $n) => date('Y-m-d', strtotime("+$n week", strtotime($lun)));
$q("INSERT INTO pagine_eventi (id, titolo, slug, tipo_area) VALUES (5, 'Aule', 'aule', 'calendario')");
$q("INSERT INTO risorse (id, pagina_id, nome, tipo, luogo, durata_slot, max_slot, anticipo_ore, max_giorni, accesso, approvazione, ripetizione) VALUES (1, 5, 'Laboratorio', 'laboratorio', 'Cubo 4B', 60, 2, 0, 60, 'tutti', 0, 1)");
$q("INSERT INTO risorse_orari (risorsa_id, giorno, dalle, alle) VALUES (1, 1, '09:00', '11:00'), (1, 1, '14:00', '16:00')");
$q("INSERT INTO utenti (id, codice_fiscale, nome, cognome, email, ruolo_id) VALUES (81, 'RISOR81XXXXXXXXX', 'Ugo', 'Uno', 'ugo@unical.it', 5), (82, 'RISOR82XXXXXXXXX', 'Eva', 'Due', 'eva@unical.it', 5)");
$ris = risorsa($conn, 1); $u81 = $conn->query("SELECT * FROM utenti WHERE id = 81")->fetch_assoc(); $u82 = $conn->query("SELECT * FROM utenti WHERE id = 82")->fetch_assoc();
$sl = slot_risorsa($conn, $ris, $lun);
prova(count($sl) === 4 && array_unique(array_column($sl, 'stato')) === ['libero'] && $sl[2]['inizio'] === "$lun 14:00:00" && $sl[2]['fascia'] === 1, "slot del giorno nelle due fasce");
prova(slot_risorsa($conn, $ris, date('Y-m-d', strtotime("$lun +1 day"))) === [], "giorno senza orari: nessuno slot");
$e1 = prenota_risorsa($conn, $ris, $u81, "$lun 09:00:00", 2, 'Esercitazione');
prova(!$e1['errore'] && count($e1['codici']) === 1 && $e1['stato'] === 'confermata', "prenotazione di due slot", (string)$e1['errore']);
$p1 = prenotazione_risorsa($conn, $e1['codici'][0] ?? '');
prova($p1 && $p1['fine'] === "$lun 11:00:00" && $p1['email'] === 'ugo@unical.it', "la prenotazione copre 9-11");
prova(slot_risorsa($conn, $ris, $lun)[1]['stato'] === 'occupato', "slot occupato dopo la prenotazione");
prova((bool)prenota_risorsa($conn, $ris, $u82, "$lun 10:00:00", 1)['errore'], "sovrapposizione rifiutata");
prova((bool)prenota_risorsa($conn, $ris, $u82, "$lun 15:00:00", 2)['errore'], "durata oltre la fascia oraria rifiutata");
prova((bool)prenota_risorsa($conn, $ris, $u82, "$lun 14:30:00", 1)['errore'], "orario fuori dagli slot rifiutato");
prova((bool)prenota_risorsa($conn, $ris, $u82, $sett(12) . " 09:00:00", 1)['errore'], "oltre i giorni prenotabili rifiutato");
$e5 = prenota_risorsa($conn, $ris, $u82, "$lun 14:00:00", 5);
prova(!$e5['errore'] && prenotazione_risorsa($conn, $e5['codici'][0])['fine'] === "$lun 16:00:00", "numero di slot limitato al massimo consentito");
$q("INSERT INTO risorse_chiusure (pagina_id, risorsa_id, dal, al, motivo) VALUES (5, NULL, '" . $sett(2) . "', '" . $sett(2) . "', 'Ponte')");
prova(chiusura_risorsa($conn, $ris, $sett(2)) === 'Ponte' && slot_risorsa($conn, $ris, $sett(2))[0]['stato'] === 'chiuso', "chiusura di tutta l'area");
$er = prenota_risorsa($conn, $ris, $u82, $sett(1) . " 09:00:00", 1, 'Corso', $sett(3));
prova(count($er['codici']) === 2 && array_keys($er['saltate']) === [$sett(2)] && $er['serie'] !== null, "ripetizione settimanale: la settimana chiusa viene saltata", json_encode($er));
$q("UPDATE risorse SET ripetizione = 0 WHERE id = 1"); $ris = risorsa($conn, 1);
prova(count(prenota_risorsa($conn, $ris, $u82, $sett(4) . " 09:00:00", 1, '', $sett(6))['codici']) === 1, "ripetizione ignorata se la risorsa non la consente");
$q("UPDATE risorse SET accesso = 'docenti' WHERE id = 1"); $ris = risorsa($conn, 1);
prova(!puo_prenotare_risorsa($conn, $ris, $u82) && puo_prenotare_risorsa($conn, $ris, ['id' => 1, 'ruolo_id' => 1]) && !puo_prenotare_risorsa($conn, $ris, null), "chi può prenotare (solo docenti; amministratori sempre)");
$q("UPDATE risorse SET accesso = 'tutti', approvazione = 1 WHERE id = 1"); $ris = risorsa($conn, 1);
$EMAIL = [];
$ea = prenota_risorsa($conn, $ris, $u82, $sett(5) . " 10:00:00", 1, 'Tesi');
$pa = prenotazione_risorsa($conn, $ea['codici'][0] ?? '');
prova($ea['stato'] === 'da_approvare' && $pa['stato'] === 'da_approvare' && slot_risorsa($conn, $ris, $sett(5))[1]['stato'] === 'occupato', "richiesta da approvare: lo slot resta riservato");
prova(cambia_stato_prenotazione_risorsa($conn, (int)$pa['id'], 'confermata') && !cambia_stato_prenotazione_risorsa($conn, (int)$pa['id'], 'confermata'), "approvazione (una sola volta)");
prova(count($EMAIL) === 1 && $EMAIL[0]['a'] === 'eva@unical.it' && str_contains($EMAIL[0]['oggetto'], 'approvata'), "email di approvazione a chi ha prenotato", json_encode(array_column($EMAIL, 'oggetto')));
prova(cambia_stato_prenotazione_risorsa($conn, (int)$pa['id'], 'annullata', false) && slot_risorsa($conn, $ris, $sett(5))[1]['stato'] === 'libero', "annullamento: lo slot torna libero");
prova(str_contains(ics_prenotazione_risorsa($p1), 'DTSTART:' . gmdate('Ymd\THis\Z', strtotime("$lun 09:00:00"))) && str_contains(ics_prenotazione_risorsa($p1), 'LOCATION:Cubo 4B'), "file .ics della prenotazione");
prova(quando_risorsa($p1) === 'Lunedì ' . date('d/m/Y', strtotime($lun)) . ', 09:00–11:00', "testo di data e orario");

sezione("Anagrafe degli insegnamenti e gruppi");
$aa_p = anno_accademico_corrente();
$q("INSERT INTO insegnamenti (id, codice, nome, cds_cod, cds_nome, anno_corso, anno_accademico, coorte, semestre, docente, partizione, dipartimento_cod) VALUES
    (900001, 'A1', 'Anatomia umana', '0827', 'Scienze motorie', 1, $aa_p, $aa_p, 'Primo Semestre', 'Rossi Mario', '', '002014'),
    (900002, 'B2', 'Fisiologia', '0827', 'Scienze motorie', 2, $aa_p, " . ($aa_p - 1) . ", 'Secondo Semestre', 'Bianchi Anna', 'A-L', '002014'),
    (900003, 'C3', 'Botanica', '0724', 'Biologia', 1, " . ($aa_p + 1) . ", " . ($aa_p + 1) . ", 'Primo Semestre', '', '', '002014')");
$ipc = insegnamenti_per_corso($conn);
prova(array_keys($ipc) === ['Scienze motorie'] && count($ipc['Scienze motorie']) === 2, "insegnamenti dell'anno accademico in corso, per corso");
prova(etichetta_insegnamento(insegnamento($conn, 900002)) === 'Fisiologia (A-L) · 2° anno · Secondo Semestre · Bianchi Anna', "etichetta dell'insegnamento");
$_POST = ['insegnamento_id' => '900001']; salva_insegnamento_evento($conn, 10);
prova((int)$conn->query("SELECT insegnamento_id FROM progetti_dettagli WHERE evento_id = 10")->fetch_assoc()['insegnamento_id'] === 900001, "evento collegato all'insegnamento");
$_POST = ['insegnamento_id' => '123']; salva_insegnamento_evento($conn, 10);
prova($conn->query("SELECT insegnamento_id FROM progetti_dettagli WHERE evento_id = 10")->fetch_assoc()['insegnamento_id'] === null, "insegnamento inesistente: nessun collegamento");
$_POST = [];
prova(prenotazione_di_classe(false, ['convenzione' => 1]), "il collegamento non tocca gli interruttori dell'evento (FSL ancora attivo)");

sezione("Altre regole");
prova(prenotazione_di_classe(false, ['convenzione' => 1]) && prenotazione_di_classe(false, ['dedicata_scuole' => 1]) && !prenotazione_di_classe(false, []), "eventi: classe con FSL o dedicato alle scuole");
prova(prenotazione_di_classe(true, ['per_scuole' => 1]) && !prenotazione_di_classe(true, ['per_scuole' => 0]), "progetti: classe se dedicati alle scuole");
prova(annullamento_scaduto(['annullabile_fino' => date('Y-m-d H:i:s', time() - 60)]) && !annullamento_scaduto(['annullabile_fino' => null]), "termine per annullare");
prova(colore_valido('#abc') === '#AABBCC' && colore_valido('red') === '#B30000', "colori validati");

sezione("Responsabili delle risorse: scelti dall'anagrafe, email, approvazione dalla loro Area personale");
$resp_svc = \App\Core\App::per($conn)->get(\App\Risorse\ServizioResponsabiliRisorse::class);
$q("INSERT INTO personale_ateneo (id, cognome, nome, email, gruppo, docente, attivo) VALUES ('resp.gina', 'Rossi', 'Gina', 'gina.rossi@unical.it', 'docenti', 1, 1), ('resp.senza', 'Senza', 'Mail', '', 'pta', 0, 1)");
$q("INSERT INTO utenti (id, codice_fiscale, nome, cognome, email, ruolo_id) VALUES (83, 'RISOR83XXXXXXXXX', 'Gina', 'Rossi', 'gina.rossi@unical.it', 5)");
$u83 = $conn->query("SELECT * FROM utenti WHERE id = 83")->fetch_assoc();
$q("INSERT INTO risorse (id, pagina_id, nome, tipo, luogo, durata_slot, max_slot, anticipo_ore, max_giorni, accesso, approvazione, ripetizione, referente) VALUES (902, 5, 'Laboratorio con approvazione', 'laboratorio', 'Cubo 5', 60, 2, 0, 60, 'tutti', 1, 0, '')");
$q("INSERT INTO risorse_orari (risorsa_id, giorno, dalle, alle) VALUES (902, 1, '09:00', '13:00')");
prova($resp_svc->aggiungi(902, 'resp.sconosciuta') !== null && $resp_svc->aggiungi(902, 'resp.senza') !== null && $resp_svc->aggiungi(9999, 'resp.gina') !== null, "responsabile: persona inesistente, senza email o risorsa inesistente rifiutati");
prova($resp_svc->aggiungi(902, 'resp.gina') === null && $resp_svc->aggiungi(902, 'resp.gina') !== null && count($resp_svc->elenco(902)) === 1 && risorsa($conn, 902)['referente'] === 'Gina Rossi', "responsabile aggiunto dall'anagrafe (non due volte); diventa il referente mostrato al pubblico");
prova($resp_svc->risorseDi($u83) !== [] && $resp_svc->risorseDi($u83)[0]['nome'] === 'Laboratorio con approvazione' && $resp_svc->risorseDi($u81) === [] && $resp_svc->risorseDi(null) === [], "«Le mie risorse»: solo quelle di cui si è responsabili");
$ris2 = risorsa($conn, 902); $EMAIL = [];
$e_r = prenota_risorsa($conn, $ris2, $u81, "$lun 09:00:00", 1, 'Analisi');
prova(!$e_r['errore'] && $e_r['stato'] === 'da_approvare', "prenotazione con approvazione: in attesa");
notifica_gestori_risorsa($conn, prenotazione_risorsa($conn, $e_r['codici'][0]), 'nuova', 1);
$em_resp = array_values(array_filter($EMAIL, fn($e) => $e['a'] === 'gina.rossi@unical.it'));
prova(count($em_resp) === 1 && str_contains($em_resp[0]['corpo'], 'risorse_responsabile.php') && !str_contains($em_resp[0]['corpo'], 'admin/prenotazioni_risorse.php'), "il responsabile riceve la richiesta con il link alle sue risorse (non al pannello)");
$pr_r = prenotazione_risorsa($conn, $e_r['codici'][0]);
$lista_r = $resp_svc->prenotazioni($u83, 902);
prova(count($lista_r) === 1 && $lista_r[0]['stato'] === 'da_approvare' && $resp_svc->prenotazioni($u81, 902) === [] && (int)$resp_svc->risorseDi($u83)[0]['da_approvare'] === 1, "il responsabile vede le richieste della sua risorsa; gli altri no");
prova($resp_svc->decidi($u81, (int)$pr_r['id'], 'approva') !== null && prenotazione_risorsa($conn, $e_r['codici'][0])['stato'] === 'da_approvare', "chi non è responsabile non può approvare");
$EMAIL = [];
prova($resp_svc->decidi($u83, (int)$pr_r['id'], 'approva', 'Portare il camice') === null && prenotazione_risorsa($conn, $e_r['codici'][0])['stato'] === 'confermata' && in_array('ugo@unical.it', array_column($EMAIL, 'a'), true) && prenotazione_risorsa($conn, $e_r['codici'][0])['nota_gestore'] === 'Portare il camice', "il responsabile approva: chi ha prenotato riceve l'email con la nota");
prova($resp_svc->decidi($u83, (int)$pr_r['id'], 'approva') !== null, "una richiesta già gestita non si approva due volte");
$e_r2 = prenota_risorsa($conn, $ris2, $u82, "$lun 10:00:00", 1, 'Altro');
$pr_r2 = prenotazione_risorsa($conn, $e_r2['codici'][0]);
$resp_id = (int)$resp_svc->elenco(902)[0]['id'];
$resp_svc->imposta($resp_id, true, false);
prova($resp_svc->decidi($u83, (int)$pr_r2['id'], 'rifiuta') !== null && $resp_svc->responsabileDi($u83, 902) && !$resp_svc->responsabileDi($u83, 902, true), "responsabile senza il permesso di approvare: vede ma non decide");
$resp_svc->imposta($resp_id, false, true);
$EMAIL = [];
$e_r3 = prenota_risorsa($conn, $ris2, $u82, "$lun 11:00:00", 1, 'Terza');
notifica_gestori_risorsa($conn, prenotazione_risorsa($conn, $e_r3['codici'][0]), 'nuova', 1);
prova(!in_array('gina.rossi@unical.it', array_column($EMAIL, 'a'), true), "responsabile con le email disattivate: non le riceve");
$resp_svc->imposta($resp_id, true, true);
prova($resp_svc->decidi($u83, (int)$pr_r2['id'], 'rifiuta', 'Occupato') === null && prenotazione_risorsa($conn, $e_r2['codici'][0])['stato'] === 'rifiutata' && $resp_svc->decidi($u83, (int)$pr_r['id'], 'annulla') === null && prenotazione_risorsa($conn, $e_r['codici'][0])['stato'] === 'annullata', "il responsabile rifiuta una richiesta e annulla una prenotazione confermata");
$resp_svc->togli($resp_id);
prova($resp_svc->elenco(902) === [] && $resp_svc->risorseDi($u83) === [], "responsabile tolto");
$resp_svc->aggiungi(902, 'resp.gina');
\App\Core\App::per($conn)->get(\App\Risorse\ServizioGestioneRisorse::class)->eliminaODisattiva(902);
prova((int)db_valore($conn, "SELECT attiva FROM risorse WHERE id = 902") === 0 && (int)db_valore($conn, "SELECT COUNT(*) FROM risorse_responsabili WHERE risorsa_id = 902") === 1, "eliminazione con prenotazioni future: la risorsa si disattiva e i responsabili restano");
$conn->query("DELETE FROM prenotazioni_risorse WHERE risorsa_id = 902");
\App\Core\App::per($conn)->get(\App\Risorse\ServizioGestioneRisorse::class)->eliminaODisattiva(902);
prova((int)db_valore($conn, "SELECT COUNT(*) FROM risorse_responsabili WHERE risorsa_id = 902") === 0, "risorsa eliminata: spariscono anche i suoi responsabili");

sezione("Contatori delle risorse (per periodo e scorta) e campi personalizzati della prenotazione");
$cont_svc = \App\Core\App::per($conn)->get(\App\Risorse\ServizioContatoriRisorse::class);
$campi_svc = \App\Core\App::per($conn)->get(\App\Risorse\ServizioCampiRisorsa::class);
$q("INSERT INTO risorse (id, pagina_id, nome, tipo, luogo, durata_slot, max_slot, anticipo_ore, max_giorni, accesso, approvazione, ripetizione) VALUES (903, 5, 'Laboratorio campioni', 'laboratorio', 'Cubo 6', 60, 1, 0, 90, 'tutti', 0, 1)");
$q("INSERT INTO risorse_orari (risorsa_id, giorno, dalle, alle) VALUES (903, 1, '09:00', '13:00'), (903, 2, '09:00', '13:00')");
[$e_c, $id_cont] = $cont_svc->salva(903, 0, ['nome' => 'Campioni', 'unita' => 'campioni', 'modalita' => 'periodo', 'periodo' => 'giorno', 'capacita' => '10', 'minimo' => '1', 'massimo' => '6', 'obbligatorio' => '1'], 'Admin');
prova($e_c === null && $id_cont > 0 && $cont_svc->salva(903, 0, ['nome' => ' '], 'Admin')[0] !== null, "contatore creato dall'admin (il nome è obbligatorio)");
$r903 = risorsa($conn, 903);
$cont903 = $cont_svc->elenco(903);
$pren_q = fn($u, $giorno, $ora, $qta, $extra = []) => prenota_risorsa($conn, $r903, $u, "$giorno $ora", 1, 'Prova', null, ['quantita' => [$id_cont => $qta]] + $extra);
prova(str_contains((string)prenota_risorsa($conn, $r903, $u81, "$lun 09:00:00", 1, 'Prova')['errore'], 'Indica la quantità'), "quantità obbligatoria: senza non si prenota");
prova(str_contains((string)$pren_q($u81, $lun, '09:00:00', 7)['errore'], 'al massimo 6') && str_contains((string)$pren_q($u81, $lun, '09:00:00', 0)['errore'], 'Indica la quantità'), "massimo per prenotazione e quantità zero rifiutati");
$qa = $pren_q($u81, $lun, '09:00:00', 4);
prova(!$qa['errore'] && $cont_svc->disponibile($cont903[0], $lun) === 6 && $cont_svc->disponibile($cont903[0], $sett(1)) === 10, "prenotazione con quantità: il contatore scala per quel giorno, non per gli altri");
$qb = $pren_q($u82, $lun, '10:00:00', 4);
prova(!$qb['errore'] && $cont_svc->disponibile($cont903[0], $lun) === 2, "seconda prenotazione dello stesso giorno");
$err_q = $pren_q($u81, $lun, '11:00:00', 3)['errore'];
prova(str_contains((string)$err_q, 'ne restano solo 2') && str_contains((string)$err_q, 'il ' . date('d/m/Y', strtotime($lun))), "oltre il disponibile: rifiutata con il numero che resta");
$qc = $pren_q($u81, $lun, '11:00:00', 2);
prova(!$qc['errore'] && str_contains((string)$pren_q($u82, $lun, '12:00:00', 1)['errore'], 'esaurito'), "esaurito il giorno: non si prenota più");
$pa = prenotazione_risorsa($conn, $qa['codici'][0]);
cambia_stato_prenotazione_risorsa($conn, (int)$pa['id'], 'annullata', false);
prova($cont_svc->disponibile($cont903[0], $lun) === 4 && !$pren_q($u82, $lun, '12:00:00', 3)['errore'], "prenotazione annullata: le quantità tornano disponibili subito");
prova($cont_svc->testoUsi($cont_svc->usi((int)prenotazione_risorsa($conn, $qb['codici'][0])['id'])) === '4 campioni', "quantità della prenotazione leggibile per email ed elenchi");
$ril = $cont_svc->riepilogo(903, [$lun, $sett(1)]);
prova(count($ril) === 1 && $ril[0]['resta'] === [$lun => 1, $sett(1) => 10] && $ril[0]['massimo'] === 6, "riepilogo per il modulo di prenotazione: quanti ne restano per ogni giorno", json_encode($ril[0]['resta']));
// Ripetizione settimanale: la serie salta le date in cui non ce ne sono abbastanza
$pren_q($u82, $sett(6), '09:00:00', 6);
$serie = prenota_risorsa($conn, risorsa($conn, 903), $u81, $sett(5) . " 10:00:00", 1, 'Serie', $sett(6), ['quantita' => [$id_cont => 6]]);
prova(count($serie['codici']) === 1 && ($serie['saltate'][$sett(6)] ?? '') === 'quantità non disponibile', "serie settimanale: la data senza abbastanza quantità si salta, le altre si prenotano");
prova(str_contains((string)prenota_risorsa($conn, risorsa($conn, 903), $u81, $sett(6) . " 10:00:00", 1, 'Serie', null, ['quantita' => [$id_cont => 5]])['errore'], 'ne restano solo 4'), "la prima data senza abbastanza quantità ferma la prenotazione");
// Settimana: la capacità vale per tutta la settimana
[$e_s, $id_set] = $cont_svc->salva(903, 0, ['nome' => 'Provette', 'unita' => '', 'modalita' => 'periodo', 'periodo' => 'settimana', 'capacita' => '5', 'minimo' => '1', 'obbligatorio' => '0'], 'Admin');
$c_set = $cont_svc->perId($id_set);
$mar = date('Y-m-d', strtotime("$lun +1 day"));
prova($cont_svc->finestra($c_set, $mar) === [$lun, date('Y-m-d', strtotime("$lun +6 days"))] && $cont_svc->finestra(['modalita' => 'scorta', 'periodo' => 'giorno'], $lun) === null, "finestra della settimana (lunedì-domenica) e scorta senza finestra");
$qs1 = prenota_risorsa($conn, risorsa($conn, 903), $u82, "$mar 09:00:00", 1, 'Provette', null, ['quantita' => [$id_cont => 1, $id_set => 3]]);
$qs2 = prenota_risorsa($conn, risorsa($conn, 903), $u81, "$mar 10:00:00", 1, 'Provette', null, ['quantita' => [$id_cont => 1, $id_set => 3]]);
prova(!$qs1['errore'] && str_contains((string)$qs2['errore'], 'ne restano solo 2') && str_contains((string)$qs2['errore'], 'settimana') && $cont_svc->disponibile($c_set, $sett(1)) === 5, "contatore settimanale: le quantità di giorni diversi si sommano; la settimana dopo riparte");
prova(!prenota_risorsa($conn, risorsa($conn, 903), $u81, "$mar 11:00:00", 1, 'Senza provette', null, ['quantita' => [$id_cont => 1]])['errore'], "contatore non obbligatorio: si può non indicare");
// Scorta
[$e_t, $id_sc] = $cont_svc->salva(903, 0, ['nome' => 'Kit', 'unita' => 'kit', 'modalita' => 'scorta', 'capacita' => '20', 'minimo' => '1', 'obbligatorio' => '0'], 'Admin');
$c_sc = $cont_svc->perId($id_sc);
prova($cont_svc->disponibile($c_sc, $lun) === 20 && $cont_svc->movimenti($id_sc)[0]['nota'] === 'Scorta iniziale', "scorta: la quantità iniziale è il primo carico");
$qk = prenota_risorsa($conn, risorsa($conn, 903), $u82, $sett(8) . " 09:00:00", 1, 'Kit', null, ['quantita' => [$id_cont => 1, $id_sc => 8]]);
prova(!$qk['errore'] && $cont_svc->disponibile($c_sc, $lun) === 12 && $cont_svc->disponibile($c_sc, $sett(9)) === 12, "scorta: si consuma e vale per qualunque data");
prova($cont_svc->movimento($id_sc, 10, 'Nuovo arrivo', 'Resp') === null && $cont_svc->disponibile($c_sc, $lun) === 22 && $cont_svc->movimento($id_sc, -30, '', 'Resp') !== null && $cont_svc->movimento($id_sc, 0, '', 'Resp') !== null && $cont_svc->movimento($id_cont, 5, '', 'Resp') !== null, "carico di scorta; non si scala più di quanto resta; il carico vale solo per le scorte");
$pk = prenotazione_risorsa($conn, $qk['codici'][0]);
cambia_stato_prenotazione_risorsa($conn, (int)$pk['id'], 'annullata', false);
prova($cont_svc->disponibile($c_sc, $lun) === 30, "scorta: la prenotazione annullata restituisce i kit");
// Approvazione: la richiesta in attesa tiene occupate le quantità
$conn->query("UPDATE risorse SET approvazione = 1 WHERE id = 903");
$resto_prima = $cont_svc->disponibile($c_sc, $lun);
$qp = prenota_risorsa($conn, risorsa($conn, 903), $u82, $sett(3) . " 09:00:00", 1, 'In attesa', null, ['quantita' => [$id_cont => 1, $id_sc => 5]]);
$pp = prenotazione_risorsa($conn, $qp['codici'][0]);
prova($qp['stato'] === 'da_approvare' && $cont_svc->disponibile($c_sc, $lun) === $resto_prima - 5, "richiesta da approvare: tiene occupata la quantità");
cambia_stato_prenotazione_risorsa($conn, (int)$pp['id'], 'rifiutata', true);
prova($cont_svc->disponibile($c_sc, $lun) === $resto_prima, "richiesta rifiutata: la quantità torna disponibile");
$conn->query("UPDATE risorse SET approvazione = 0 WHERE id = 903");
// Campi personalizzati
$post_c = ['c_etichetta' => ['Tipo di analisi', 'Referente del gruppo', '', 'Allegato'], 'c_tipo' => ['select', 'text', 'text', 'file'], 'c_opzioni' => ['chimica, biologica', '', '', ''], 'c_obbl' => ['1', '0', '0', '0'], 'c_aiuto' => ['', 'es. Prof. Rossi', '', '']];
$json_c = $campi_svc->jsonDaForm($post_c);
prova(count(json_decode($json_c, true)) === 2 && $campi_svc->jsonDaForm(['c_etichetta' => ['']]) === null, "campi della risorsa: le righe vuote e i tipi non ammessi (allegati) si scartano");
$campi_svc->salva(903, $post_c);
$campi_r = $campi_svc->campi(risorsa($conn, 903));
prova(count($campi_r) === 2 && $campi_r[0]['nome'] === 'c1' && $campi_r[0]['opzioni'] === ['chimica', 'biologica'] && (int)$campi_r[0]['obbligatorio'] === 1, "campi letti dalla risorsa", json_encode($campi_r));
[$j_err, $err_r] = $campi_svc->leggi($campi_r, []);
[$j_ok, $err_ok] = $campi_svc->leggi($campi_r, ['campo_c1' => 'chimica', 'campo_c2' => 'Prof. Neri']);
prova($j_err === null && count($err_r) === 1 && $err_ok === [] && $j_ok !== null, "risposte: il campo obbligatorio manca → errore; compilato → JSON");
$qf = prenota_risorsa($conn, risorsa($conn, 903), $u82, $sett(4) . " 09:00:00", 1, 'Con campi', null, ['quantita' => [$id_cont => 2], 'risposte_json' => $j_ok]);
$pf = prenotazione_risorsa($conn, $qf['codici'][0]);
prova(!$qf['errore'] && \App\Risorse\ServizioCampiRisorsa::coppie($pf['risposte_json']) === [['Tipo di analisi', 'chimica'], ['Referente del gruppo', 'Prof. Neri']], "risposte ai campi personalizzati salvate con la prenotazione e leggibili");
$altra = $conn->query("SELECT id FROM risorse WHERE id = 1")->fetch_assoc();
prova($campi_svc->copiaDa(1, 903) && $campi_svc->campi(risorsa($conn, 1)) !== [] && !$campi_svc->copiaDa(1, 1) && !$campi_svc->copiaDa(1, 902), "copia dei campi da un'altra risorsa");
// Eliminazione
\App\Core\App::per($conn)->get(\App\Risorse\RisorsaRepository::class);
$conn->query("DELETE FROM prenotazioni_risorse WHERE risorsa_id = 903");
\App\Core\App::per($conn)->get(\App\Risorse\ServizioGestioneRisorse::class)->eliminaODisattiva(903);
prova((int)db_valore($conn, "SELECT COUNT(*) FROM risorse_contatori WHERE risorsa_id = 903") === 0 && (int)db_valore($conn, "SELECT COUNT(*) FROM risorse_contatori_movimenti WHERE contatore_id = $id_sc") === 0, "risorsa eliminata: spariscono contatori e movimenti");

sezione("Diario di bordo: campi dell'admin, co-conducenti, km, blocco, responsabile, promemoria, Excel");
$dia_svc = \App\Core\App::per($conn)->get(\App\Risorse\ServizioDiarioBordo::class);
$q("INSERT INTO personale_ateneo (id, cognome, nome, email, gruppo, docente, attivo) VALUES ('resp.luca', 'Verdi', 'Luca', 'luca.verdi@unical.it', 'pta', 0, 1)");
$q("INSERT INTO utenti (id, codice_fiscale, nome, cognome, email, ruolo_id) VALUES (84, 'RISOR84XXXXXXXXX', 'Luca', 'Verdi', 'luca.verdi@unical.it', 5)");
$u84 = $conn->query("SELECT * FROM utenti WHERE id = 84")->fetch_assoc();
$q("INSERT INTO risorse (id, pagina_id, nome, tipo, luogo, durata_slot, max_slot, anticipo_ore, max_giorni, accesso, approvazione, ripetizione) VALUES (904, 5, 'Auto di servizio', 'veicolo', 'Parcheggio', 60, 2, 0, 90, 'tutti', 0, 0)");
$q("INSERT INTO risorse_orari (risorsa_id, giorno, dalle, alle) VALUES (904, 1, '08:00', '18:00')");
prova(isset(TIPI_RISORSA['veicolo']) && $dia_svc->configurazione(['diario_json' => null]) === \App\Risorse\ServizioDiarioBordo::PREDEFINITO, "tipo «Auto o mezzo»; i campi di partenza sono quelli del modulo cartaceo");
$dia_svc->salvaConfigurazione(904, ['diario_attivo' => '1', 'diario_blocca' => '1'], $campi_svc);
$r904 = risorsa($conn, 904);
prova((int)$r904['diario_attivo'] === 1 && (int)$r904['diario_blocca'] === 1 && $r904['diario_json'] === null, "diario attivato con i campi di partenza (nessuna personalizzazione salvata)");
[$campi904, $ruoli904] = $dia_svc->campi($r904);
$nome_di = fn($ruolo) => array_search($ruolo, $ruoli904, true);
prova(count($campi904) === 10 && count($ruoli904) === 7 && $nome_di('km_iniziali') !== false && $nome_di('km_finali') !== false && $nome_di('litri') !== false, "10 campi e 7 ruoli (data, conducente, ore, km, litri) riconosciuti dai nomi", json_encode($ruoli904));
// Co-conducenti
$cc_json = $dia_svc->coConducentiDaForm(['conducenti' => ['Rossi Gina', 'Mario Ignoto', '', 'Gina Rossi']]);
$cc = json_decode($cc_json, true);
prova(count($cc) === 3 && $cc[0]['email'] === 'gina.rossi@unical.it' && $cc[0]['persona_id'] === 'resp.gina' && $cc[1]['email'] === '' && $cc[1]['persona_id'] === null && $dia_svc->coConducentiDaForm(['conducenti' => ['', ' ']]) === null, "altri conducenti: riconosciuti nell'anagrafe (anche «Nome Cognome»); chi non c'è resta scritto, senza email");
// Una prenotazione passata di Ugo (u81) con Gina come co-conducente
$ieri = date('Y-m-d', strtotime('-2 days'));
$q("INSERT INTO prenotazioni_risorse (risorsa_id, utente_id, nome, cognome, email, inizio, fine, motivo, stato, codice, co_conducenti_json) VALUES (904, 81, 'Ugo', 'Uno', 'ugo@unical.it', '$ieri 08:00:00', '$ieri 12:00:00', 'Trasferta', 'confermata', 'RS-DIARIO1', '" . $conn->real_escape_string($cc_json) . "')");
$p_d1 = prenotazione_risorsa($conn, 'RS-DIARIO1');
prova($dia_svc->puoCompilare($p_d1, $u81) && $dia_svc->puoCompilare($p_d1, $u83) && !$dia_svc->puoCompilare($p_d1, $u82) && !$dia_svc->puoCompilare($p_d1, $u84) && !$dia_svc->puoCompilare($p_d1, null), "il diario lo compila chi ha prenotato o un co-conducente indicato; gli altri no");
$resp_svc->aggiungi(904, 'resp.luca');
prova($dia_svc->puoCompilare($p_d1, $u84) && $dia_svc->eResponsabile($p_d1, $u84) && !$dia_svc->eResponsabile($p_d1, $u81), "anche un responsabile della risorsa può compilarlo (e solo lui verifica)");
prova(count($dia_svc->daCompilare($u81)) === 1 && count($dia_svc->daCompilare($u83)) === 1 && $dia_svc->daCompilare($u82) === [] && count($dia_svc->mancanti(904)) === 1, "diari da compilare: per chi ha prenotato e per i co-conducenti; per la risorsa dal responsabile");
// Blocco delle nuove prenotazioni
$r904 = risorsa($conn, 904);
$err_b = prenota_risorsa($conn, $r904, $u81, "$lun 09:00:00", 1, 'Altra trasferta')['errore'];
prova(str_contains((string)$err_b, 'compila il diario di bordo del viaggio del ' . date('d/m/Y', strtotime($ieri))) && !prenota_risorsa($conn, $r904, $u82, "$lun 09:00:00", 1, 'Altra auto')['errore'], "con il blocco attivo chi non ha compilato il diario non prenota di nuovo; gli altri sì");
$conn->query("UPDATE risorse SET diario_blocca = 0 WHERE id = 904"); $conn->query("DELETE FROM prenotazioni_risorse WHERE risorsa_id = 904 AND codice <> 'RS-DIARIO1'");
prova(!prenota_risorsa($conn, risorsa($conn, 904), $u81, "$lun 11:00:00", 1, 'Senza blocco')['errore'], "blocco spento (di partenza): si prenota anche senza diario");
$conn->query("DELETE FROM prenotazioni_risorse WHERE risorsa_id = 904 AND codice <> 'RS-DIARIO1'");
// Compilazione
$v0 = $dia_svc->valoriIniziali($r904, $p_d1);
prova($v0[$nome_di('data')] === $ieri && $v0[$nome_di('conducente')] === 'Ugo Uno' && $v0[$nome_di('ora_partenza')] === '08:00' && $v0[$nome_di('ora_arrivo')] === '12:00' && !isset($v0[$nome_di('km_iniziali')]), "diario nuovo: data, conducente e ore dalla prenotazione; primo viaggio senza km proposti");
$base_d = ['campo_' . array_search('Luogo di partenza', array_column($campi904, 'etichetta', 'nome'), true) => 'Arcavacata', 'campo_' . array_search('Luogo di arrivo', array_column($campi904, 'etichetta', 'nome'), true) => 'Catanzaro'];
$d_post = fn(array $extra) => $extra + $base_d + array_combine(array_map(fn($n) => 'campo_' . $n, array_keys($v0)), array_values($v0));
$k = fn($ruolo) => 'campo_' . $nome_di($ruolo);
prova($dia_svc->salva($p_d1, $u82, $d_post([$k('km_iniziali') => '100', $k('km_finali') => '150'])) !== null, "un estraneo non salva il diario");
prova(str_contains((string)$dia_svc->salva($p_d1, $u81, $d_post([$k('km_iniziali') => '100'])), 'Km finali'), "campo obbligatorio mancante: errore");
prova(str_contains((string)$dia_svc->salva($p_d1, $u81, $d_post([$k('km_iniziali') => '200', $k('km_finali') => '150'])), 'inferiori ai km iniziali'), "km finali inferiori agli iniziali: rifiutati");
prova($dia_svc->salva($p_d1, $u83, $d_post([$k('km_iniziali') => '100', $k('km_finali') => '150', $k('litri') => '12,5'])) === null, "il co-conducente compila il diario");
$d1 = $dia_svc->diarioDi((int)$p_d1['id']);
prova($d1 && (int)$d1['km_percorsi'] === 50 && (int)$d1['km_iniziali'] === 100 && (float)$d1['litri'] === 12.5 && $d1['compilato_da'] === 'Gina Rossi' && $d1['data'] === $ieri && $d1['conducente'] === 'Ugo Uno', "diario salvato: km percorsi calcolati, litri, chi l'ha compilato", json_encode($d1));
prova($dia_svc->daCompilare($u81) === [] && $dia_svc->mancanti(904) === [], "compilato: non risulta più da fare");
// Secondo viaggio: i km iniziali si propongono e non possono essere più bassi
$oggi_d = date('Y-m-d', strtotime('-1 day'));
$q("INSERT INTO prenotazioni_risorse (risorsa_id, utente_id, nome, cognome, email, inizio, fine, motivo, stato, codice) VALUES (904, 82, 'Eva', 'Due', 'eva@unical.it', '$oggi_d 09:00:00', '$oggi_d 11:00:00', 'Seconda', 'confermata', 'RS-DIARIO2')");
$p_d2 = prenotazione_risorsa($conn, 'RS-DIARIO2');
$v2 = $dia_svc->valoriIniziali($r904, $p_d2);
prova($v2[$nome_di('km_iniziali')] === '150' && $dia_svc->ultimiKm($p_d2) === 150, "i km iniziali del viaggio dopo sono i km finali dell'ultimo");
$d_post2 = fn(array $extra) => $extra + $base_d + array_combine(array_map(fn($n) => 'campo_' . $n, array_keys($v2)), array_values($v2));
prova(str_contains((string)$dia_svc->salva($p_d2, $u82, $d_post2([$k('km_iniziali') => '120', $k('km_finali') => '180'])), 'ultimi km finali registrati (150)'), "km iniziali più bassi dell'ultimo viaggio: rifiutati");
prova($dia_svc->salva($p_d2, $u82, $d_post2([$k('km_iniziali') => '150', $k('km_finali') => '230'])) === null, "secondo viaggio salvato");
// Verifica del responsabile e correzione
prova($dia_svc->verifica($p_d1, $u81) !== null && $dia_svc->verifica($p_d1, $u84) === null && $dia_svc->diarioDi((int)$p_d1['id'])['verificato_da'] === 'Luca Verdi', "solo il responsabile verifica");
$dia_svc->salva($p_d1, $u84, $d_post([$k('km_iniziali') => '100', $k('km_finali') => '160']));
$d1b = $dia_svc->diarioDi((int)$p_d1['id']);
prova((int)$d1b['km_percorsi'] === 60 && $d1b['verificato_il'] === null && $d1b['modificato_il'] !== null, "una correzione azzera la verifica");
$reg = $dia_svc->registro(904);
prova($reg['totali'] === ['viaggi' => 2, 'km' => 140, 'litri' => 0.0] && count($reg['righe']) === 2, "registro e totali del responsabile", json_encode($reg['totali']));
$xls = $dia_svc->esporta(risorsa($conn, 904));
prova($xls && str_starts_with((string)file_get_contents($xls), 'PK'), "Excel del diario");
if ($xls) @unlink($xls);
// Promemoria
$q("INSERT INTO prenotazioni_risorse (risorsa_id, utente_id, nome, cognome, email, inizio, fine, motivo, stato, codice, co_conducenti_json) VALUES (904, 81, 'Ugo', 'Uno', 'ugo@unical.it', '$ieri 14:00:00', '$ieri 16:00:00', 'Terzo', 'confermata', 'RS-DIARIO3', '" . $conn->real_escape_string($cc_json) . "')");
$EMAIL = [];
$prom_d = \App\Core\App::per($conn)->get(\App\Risorse\PromemoriaPrenotazioni::class);
$n1 = $prom_d->inviaPromemoria();
$dest_d = array_column(array_filter($EMAIL, fn($e) => str_contains($e['oggetto'], 'Diario di bordo da compilare')), 'a');
prova(in_array('ugo@unical.it', $dest_d, true) && in_array('gina.rossi@unical.it', $dest_d, true) && str_contains($EMAIL[0]['corpo'] ?? '', 'diario_bordo.php?c=RS-DIARIO3'), "promemoria del diario: a chi ha prenotato e ai co-conducenti con il link", json_encode($dest_d));
$EMAIL = [];
prova($prom_d->inviaPromemoria() === 0 && $EMAIL === [], "il promemoria del diario parte una volta sola");
// Campi personalizzati dell'admin con ruoli
$dia_svc->salvaConfigurazione(904, ['diario_attivo' => '1', 'c_etichetta' => ['Giorno', 'Odometro partenza', 'Odometro arrivo', 'Note'], 'c_tipo' => ['date', 'number', 'number', 'textarea'], 'c_opzioni' => ['', '', '', ''],
    'c_obbl' => ['1', '1', '1', '0'], 'c_aiuto' => ['', '', '', ''], 'ruolo_data' => 'Giorno', 'ruolo_km_iniziali' => 'Odometro partenza', 'ruolo_km_finali' => 'Odometro arrivo', 'ruolo_litri' => 'Inesistente'], $campi_svc);
[$camp_c, $ruoli_c] = $dia_svc->campi(risorsa($conn, 904));
prova(count($camp_c) === 4 && array_values($ruoli_c) === ['data', 'km_iniziali', 'km_finali'], "campi personalizzati: i ruoli seguono le etichette scelte (un'etichetta che non c'è si scarta)", json_encode($ruoli_c));
$nm = array_flip(array_column($camp_c, 'etichetta', 'nome'));
$p_x = ['campo_' . $nm['Giorno'] => $oggi_d, 'campo_' . $nm['Odometro partenza'] => '230', 'campo_' . $nm['Odometro arrivo'] => '300'];
prova($dia_svc->salva($p_d2, $u82, $p_x) === null && (int)$dia_svc->diarioDi((int)$p_d2['id'])['km_percorsi'] === 70, "i controlli e i km percorsi funzionano con i campi rinominati dall'admin");
$conn->query("DELETE FROM prenotazioni_risorse WHERE risorsa_id = 904");
sezione("Cruscotto del responsabile: scadenze con avvisi e rinnovo, numeri del periodo, chi può vederlo, Excel");
$scad_svc = \App\Core\App::per($conn)->get(\App\Risorse\ServizioScadenzeRisorsa::class);
$cru_svc = \App\Core\App::per($conn)->get(\App\Risorse\CruscottoRisorsa::class);
$q("INSERT INTO risorse (id, pagina_id, nome, tipo, luogo, durata_slot, max_slot, anticipo_ore, max_giorni, accesso, approvazione, ripetizione) VALUES (905, 5, 'Furgone', 'veicolo', 'Garage', 60, 4, 0, 90, 'tutti', 1, 0)");
$q("INSERT INTO risorse_orari (risorsa_id, giorno, dalle, alle) SELECT 905, g, '08:00', '18:00' FROM (SELECT 1 g UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7) x");
$resp_svc->aggiungi(905, 'resp.luca');
$oggi_c = date('Y-m-d');
prova(\App\Risorse\ServizioScadenzeRisorsa::stato(['scade_il' => date('Y-m-d', strtotime('+10 days')), 'preavviso_giorni' => 30])['stato'] === 'in_scadenza'
    && \App\Risorse\ServizioScadenzeRisorsa::stato(['scade_il' => date('Y-m-d', strtotime('+90 days')), 'preavviso_giorni' => 30])['stato'] === 'ok'
    && \App\Risorse\ServizioScadenzeRisorsa::stato(['scade_il' => date('Y-m-d', strtotime('-1 day')), 'preavviso_giorni' => 30]) === ['stato' => 'scaduta', 'giorni' => -1], "stato di una scadenza: in regola, in scadenza (entro il preavviso), scaduta");
prova(str_contains((string)$scad_svc->salva(905, 0, ['nome' => '', 'scade_il' => '2030-01-01'])[0], 'Scrivi cosa scade') && str_contains((string)$scad_svc->salva(905, 0, ['nome' => 'Bollo', 'scade_il' => '2030-02-31'])[0], 'data'), "scadenza senza nome o con data impossibile: rifiutata");
[$e1, $id_bollo] = $scad_svc->salva(905, 0, ['nome' => 'Bollo', 'scade_il' => date('Y-m-d', strtotime('+10 days')), 'preavviso_giorni' => '30']);
[$e2, $id_ass] = $scad_svc->salva(905, 0, ['nome' => 'Assicurazione', 'scade_il' => date('Y-m-d', strtotime('-2 days')), 'preavviso_giorni' => '15', 'note' => 'Polizza 123']);
[$e3] = $scad_svc->salva(905, 0, ['nome' => 'Tagliando', 'scade_il' => date('Y-m-d', strtotime('+200 days'))]);
prova($e1 === null && $e2 === null && $e3 === null && count($scad_svc->elenco(905)) === 3 && $scad_svc->elenco(905)[0]['nome'] === 'Assicurazione', "tre scadenze inserite dall'admin, in ordine di data");
prova(array_column($scad_svc->daControllare([905]), 'nome') === ['Assicurazione', 'Bollo'] && $scad_svc->daControllare([]) === [], "il responsabile vede solo quelle scadute o in scadenza");
$EMAIL = [];
$prom_c = \App\Core\App::per($conn)->get(\App\Risorse\PromemoriaPrenotazioni::class);
$prom_c->inviaPromemoria();
$og_c = array_column(array_filter($EMAIL, fn($e) => str_contains($e['oggetto'], 'Furgone')), 'oggetto');
sort($og_c);
prova($og_c === ['In scadenza: Bollo – Furgone', 'Scaduta: Assicurazione – Furgone'] && str_contains(implode(' ', array_column($EMAIL, 'corpo')), 'risorse_cruscotto.php?r=905'), "il cron avvisa il responsabile: una email per quella in scadenza e una per quella scaduta, con il link al cruscotto", json_encode($og_c));
$EMAIL = [];
$prom_c->inviaPromemoria();
prova(!array_filter($EMAIL, fn($e) => str_contains($e['oggetto'], 'Furgone')), "gli avvisi di scadenza partono una volta sola");
prova(str_contains((string)$scad_svc->rinnova(905, $id_ass, date('Y-m-d', strtotime('-1 day'))), 'futura') && str_contains((string)$scad_svc->rinnova(904, $id_ass, date('Y-m-d', strtotime('+300 days'))), 'non trovata'), "rinnovo con data non futura o di un'altra risorsa: rifiutato");
prova($scad_svc->rinnova(905, $id_ass, date('Y-m-d', strtotime('+365 days'))) === null && $scad_svc->elenco(905)[0]['nome'] !== 'Assicurazione' && array_column($scad_svc->daControllare([905]), 'nome') === ['Bollo'], "rinnovata: torna in regola e sparisce dall'avviso");
$q("UPDATE risorse_scadenze SET scade_il = '" . date('Y-m-d', strtotime('-1 day')) . "' WHERE id = $id_ass");
$EMAIL = [];
$prom_c->inviaPromemoria();
prova(count(array_filter($EMAIL, fn($e) => str_contains($e['oggetto'], 'Scaduta: Assicurazione'))) >= 1, "dopo il rinnovo l'avviso si azzera: se scade di nuovo, riparte");
// Prenotazioni del periodo
$lun_c = date('Y-m-d', strtotime('-3 days'));
$q("INSERT INTO prenotazioni_risorse (risorsa_id, utente_id, nome, cognome, email, inizio, fine, motivo, stato, codice) VALUES
    (905, 81, 'Ugo', 'Uno', 'ugo@unical.it', '$lun_c 09:00:00', '$lun_c 11:00:00', 'Uscita 1', 'confermata', 'RS-CRU1'),
    (905, 81, 'Ugo', 'Uno', 'ugo@unical.it', '$lun_c 14:00:00', '$lun_c 15:00:00', 'Uscita 2', 'confermata', 'RS-CRU2'),
    (905, 82, 'Eva', 'Due', 'eva@unical.it', '$lun_c 11:00:00', '$lun_c 12:00:00', 'Uscita 3', 'confermata', 'RS-CRU3'),
    (905, 82, 'Eva', 'Due', 'eva@unical.it', '$lun_c 16:00:00', '$lun_c 17:00:00', 'Rifiutata', 'rifiutata', 'RS-CRU4'),
    (905, 83, 'Gina', 'Rossi', 'gina.rossi@unical.it', '$lun_c 17:00:00', '$lun_c 18:00:00', 'Annullata', 'annullata', 'RS-CRU5')");
$r905 = risorsa($conn, 905);
[$dal_c, $al_c] = \App\Risorse\CruscottoRisorsa::periodo('30', null, null, $oggi_c);
$dc = $cru_svc->dati($r905, $dal_c, $al_c);
prova($dc['conteggi']['totale'] === 5 && $dc['conteggi']['confermata'] === 3 && $dc['conteggi']['rifiutata'] === 1 && $dc['conteggi']['annullata'] === 1, "conteggi per stato nel periodo", json_encode($dc['conteggi']));
prova($dc['ore'] == 4.0 && $dc['ore_aperte'] > 100 && $dc['utilizzo'] !== null && $dc['utilizzo'] <= 5, "ore prenotate (solo le confermate) e utilizzo sulle ore di apertura", json_encode([$dc['ore'], $dc['ore_aperte'], $dc['utilizzo']]));
prova($dc['rifiutate_pct'] === 25, "percentuale di rifiuti tra le richieste decise (la risorsa richiede approvazione)", json_encode($dc['rifiutate_pct']));
prova($dc['utenti'][0]['email'] === 'ugo@unical.it' && $dc['utenti'][0]['prenotazioni'] === 2 && $dc['utenti'][0]['ore'] == 3.0 && count($dc['utenti']) === 2, "chi la usa di più", json_encode($dc['utenti']));
$g_c = (int)date('N', strtotime($lun_c));
prova(($dc['fasce'][$g_c][9] ?? 0) === 1 && ($dc['fasce'][$g_c][14] ?? 0) === 1 && ($dc['fasce'][$g_c][16] ?? 0) === 0 && count($dc['mesi']) >= 1, "fasce orarie e mesi: contano solo le prenotazioni confermate");
prova(count($dc['scadenze']) === 3 && $dc['diario'] === null, "scadenze incluse; senza diario attivo non c'è la parte km");
$q("UPDATE risorse SET diario_attivo = 1 WHERE id = 905");
$p_cr = prenotazione_risorsa($conn, 'RS-CRU1');
$q("INSERT INTO risorse_diario (prenotazione_id, risorsa_id, data, km_iniziali, km_finali, km_percorsi, litri, compilato_da) VALUES ({$p_cr['id']}, 905, '$lun_c', 1000, 1200, 200, 14, 'Ugo Uno')");
$dc2 = $cru_svc->dati(risorsa($conn, 905), $dal_c, $al_c);
prova($dc2['diario']['km'] === 200 && $dc2['diario']['viaggi'] === 1 && $dc2['diario']['consumo'] === 7.0 && $dc2['diario']['km_medi'] === 200 && $dc2['diario']['senza_diario'] === 2, "diario: km, consumo l/100 km, km a viaggio e viaggi senza diario", json_encode($dc2['diario']));
$xls_c = $cru_svc->esporta(risorsa($conn, 905), $dal_c, $al_c);
prova($xls_c && str_starts_with((string)file_get_contents($xls_c), 'PK'), "Excel del cruscotto");
if ($xls_c) @unlink($xls_c);
$u_admin = ['id' => 1, 'ruolo_id' => 1, 'email' => 'admin@unical.it', 'ruoli_secondari' => ''];
prova($cru_svc->puoVedere($u84, 905) && !$cru_svc->puoVedere($u84, 999) && !$cru_svc->puoVedere($u81, 905) && $cru_svc->puoVedere($u_admin, 905) && !$cru_svc->puoVedere(null, 905), "il cruscotto lo vedono il responsabile di quella risorsa e gli amministratori");
[$d1_c, $d2_c] = \App\Risorse\CruscottoRisorsa::periodo('altro', '2026-03-01', '2026-03-31', '2026-10-14');
prova($d1_c === '2026-03-01' && $d2_c === '2026-03-31' && \App\Risorse\CruscottoRisorsa::periodo('boh', null, null, '2026-10-14')[0] === '2026-09-14' && \App\Risorse\CruscottoRisorsa::periodo('anno', null, null, '2026-10-14')[0] === '2026-01-01', "periodi: intervallo scelto, anno corrente, valore non valido = ultimi 30 giorni");
$conn->query("DELETE FROM risorse WHERE id = 905"); $risorse_repo_c = \App\Core\App::per($conn)->get(\App\Risorse\RisorsaRepository::class); $risorse_repo_c->elimina(905);
prova((int)db_valore($conn, "SELECT COUNT(*) FROM risorse_scadenze WHERE risorsa_id = 905") === 0, "risorsa eliminata: spariscono anche le sue scadenze");
$conn->query("DELETE FROM prenotazioni_risorse WHERE risorsa_id = 905");

sezione("Risorse: prenotazioni ripetute (ogni settimana, due settimane, mese) e lista d'attesa");
$svc_rs = \App\Core\App::per($conn)->get(\App\Risorse\ServizioRisorse::class);
$t_rip = strtotime('2026-10-13 10:00:00'); // un martedì, il secondo del mese
prova(\App\Risorse\ServizioRisorse::dateRipetute($t_rip, '2026-11-03', 'settimana') === ['2026-10-13', '2026-10-20', '2026-10-27', '2026-11-03']
    && \App\Risorse\ServizioRisorse::dateRipetute($t_rip, '2026-11-30', 'quindici') === ['2026-10-13', '2026-10-27', '2026-11-10', '2026-11-24']
    && \App\Risorse\ServizioRisorse::dateRipetute($t_rip, '2027-01-31', 'mese') === ['2026-10-13', '2026-11-10', '2026-12-08', '2027-01-12'], "ripetizione: ogni settimana, ogni due settimane e ogni mese (il secondo martedì)");
prova(\App\Risorse\ServizioRisorse::dateRipetute(strtotime('2026-10-27 10:00:00'), '2026-12-31', 'mese') === ['2026-10-27', '2026-11-24', '2026-12-22']
    && \App\Risorse\ServizioRisorse::dateRipetute(strtotime('2026-12-29 10:00:00'), '2027-02-28', 'mese') === ['2026-12-29', '2027-01-26', '2027-02-23'], "ripetizione mensile: il quarto martedì resta il quarto, il quinto diventa l'ultimo");
$q("INSERT INTO risorse (id, pagina_id, nome, tipo, luogo, durata_slot, max_slot, anticipo_ore, max_giorni, accesso, approvazione, ripetizione) VALUES (906, 5, 'Aula Magna', 'aula', 'Cubo 4', 60, 2, 0, 90, 'tutti', 0, 1)");
$q("INSERT INTO risorse_orari (risorsa_id, giorno, dalle, alle) SELECT 906, g, '08:00', '18:00' FROM (SELECT 1 g UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7) x");
$r906 = risorsa($conn, 906);
$ua = ['id' => 81, 'nome' => 'Ugo', 'cognome' => 'Uno', 'email' => 'ugo@unical.it', 'ruolo_id' => 3];
$ub = ['id' => 82, 'nome' => 'Eva', 'cognome' => 'Due', 'email' => 'eva@unical.it', 'ruolo_id' => 3];
$uc = ['id' => 83, 'nome' => 'Gina', 'cognome' => 'Rossi', 'email' => 'gina.rossi@unical.it', 'ruolo_id' => 3];
$giorno_a = date('Y-m-d', strtotime('+3 days'));
$ini_a = "$giorno_a 10:00:00";
$es_a = $svc_rs->prenota($r906, $ua, $ini_a, 1, 'Lezione', null);
prova($es_a['errore'] === null && count($es_a['codici']) === 1, "prenotazione normale dello slot");
prova(str_contains((string)$svc_rs->mettiInAttesa($r906, $ub, "$giorno_a 11:00:00", 1), 'libero'), "lista d'attesa: per uno slot libero si prenota e basta");
prova($svc_rs->mettiInAttesa($r906, $ub, $ini_a, 1) === null && str_contains((string)$svc_rs->mettiInAttesa($r906, $ub, $ini_a, 1), 'già in lista'), "lista d'attesa: ci si mette in coda per lo slot occupato, una volta sola");
prova($svc_rs->mettiInAttesa($r906, $uc, $ini_a, 1) === null, "lista d'attesa: anche una terza persona");
$att_svc = \App\Core\App::per($conn)->get(\App\Risorse\ListaAttesaRisorse::class);
$mie_b = $att_svc->dellUtente(82);
prova(count($mie_b) === 1 && (int)$mie_b[0]['posizione'] === 1 && (int)$att_svc->dellUtente(83)[0]['posizione'] === 2 && ($att_svc->incoda(906, $giorno_a)[$ini_a] ?? 0) === 2, "lista d'attesa: posizione in coda e numero di persone per slot");
$EMAIL = [];
$pren_a = prenotazione_risorsa($conn, $es_a['codici'][0]);
prova(cambia_stato_prenotazione_risorsa($conn, (int)$pren_a['id'], 'annullata', false), "chi aveva prenotato annulla");
$avv = array_values(array_filter($EMAIL, fn($e) => str_contains($e['oggetto'], 'Si è liberato uno slot')));
prova(count($avv) === 1 && $avv[0]['a'] === 'eva@unical.it' && str_contains($avv[0]['corpo'], 'Prenota ora'), "annullamento: avvisa solo il primo in coda", json_encode(array_column($EMAIL, 'oggetto')));
prova($att_svc->dellUtente(82)[0]['stato'] === 'avvisata' && $att_svc->dellUtente(83)[0]['stato'] === 'attesa', "il primo è «avvisata», il secondo resta in attesa");
$EMAIL = [];
prova($att_svc->scala() === 0 && $EMAIL === [], "prima delle 24 ore l'avviso non passa al successivo");
$q("UPDATE prenotazioni_risorse_attesa SET avvisata_il = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE utente_id = 82");
prova($att_svc->scala() === 1 && ($EMAIL[0]['a'] ?? '') === 'gina.rossi@unical.it', "dopo 24 ore senza prenotare l'avviso passa al secondo in coda");
prova((string)$conn->query("SELECT stato FROM prenotazioni_risorse_attesa WHERE utente_id = 82")->fetch_row()[0] === 'scaduta' && $att_svc->dellUtente(83)[0]['stato'] === 'avvisata', "la prima attesa scade, la seconda è «avvisata»");
$es_c = $svc_rs->prenota($r906, $uc, $ini_a, 1, 'Seminario', null);
prova($es_c['errore'] === null && $att_svc->dellUtente(83) === [], "chi era in coda e prenota: la lista si chiude");
prova($svc_rs->mettiInAttesa($r906, $ua, '2020-01-06 10:00:00', 1) !== null, "lista d'attesa: non per orari passati");
// Ripetizione mensile vera: la serie si crea con le date calcolate
$es_m = $svc_rs->prenota($r906, $ua, date('Y-m-d', strtotime('+10 days')) . ' 15:00:00', 1, 'Corso', date('Y-m-d', strtotime('+80 days')), [], 'quindici');
prova($es_m['errore'] === null && count($es_m['codici']) >= 4 && $es_m['serie'] !== null, "prenotazione ripetuta ogni due settimane: serie creata", json_encode($es_m));
$conn->query("DELETE FROM prenotazioni_risorse WHERE risorsa_id = 906");
$conn->query("DELETE FROM prenotazioni_risorse_attesa WHERE risorsa_id = 906");
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

sezione("Didattica: moduli online e pratiche");
$cm = campi_modulo(json_encode([['etichetta' => 'Corso', 'tipo' => 'select', 'opzioni' => 'Biologia; Geologia, Ecologia', 'obbligatorio' => true], ['etichetta' => 'Note', 'tipo' => 'textarea'], ['etichetta' => '', 'tipo' => 'text'], ['etichetta' => 'Strano', 'tipo' => 'script']]));
prova(count($cm) === 3 && $cm[0]['nome'] === 'c1' && $cm[0]['opzioni'] === ['Biologia', 'Geologia', 'Ecologia'] && $cm[2]['tipo'] === 'text', "campi del modulo normalizzati (opzioni con virgola o punto e virgola)", json_encode($cm));
$_POST = ['campo_c1' => 'Fisica', 'campo_c2' => ' Testo '];
[$ris, $err] = leggi_risposte_modulo($cm);
prova(count($err) === 1 && str_contains($err[0], 'Corso') && $ris[1]['valore'] === 'Testo', "opzione non prevista scartata, campo obbligatorio segnalato", json_encode($err));
$_POST = ['campo_c1' => 'Geologia'];
[$ris, $err] = leggi_risposte_modulo($cm);
$_POST = [];
prova(!$err && $ris[0]['valore'] === 'Geologia', "risposte valide");
$q("INSERT INTO didattica_moduli (id, titolo, categoria, tipo, campi_json, destinatari, email_ufficio, attivo) VALUES (500, 'Riconoscimento crediti', 'Carriera', 'online', '" . $conn->real_escape_string(json_encode([['etichetta' => 'Corso', 'tipo' => 'select', 'opzioni' => 'Biologia, Geologia', 'obbligatorio' => true]])) . "', 'tutti', 'segreteria@unical.it', 1)");
$u73 = $conn->query("SELECT * FROM utenti WHERE id = 73")->fetch_assoc();
prova(utente_destinatario_modulo($conn, modulo_didattica($conn, 500), $u73) && !utente_destinatario_modulo($conn, modulo_didattica($conn, 500), null), "modulo per tutti: serve l'accesso");
$EMAIL = [];
$idp = crea_pratica($conn, modulo_didattica($conn, 500), $u73, $ris);
$pp = pratica($conn, $idp);
prova($idp > 0 && $pp['stato'] === 'inviata' && str_starts_with($pp['codice'], 'PR-') && $pp['modulo_titolo'] === 'Riconoscimento crediti', "pratica creata");
$dest = array_column($EMAIL, 'a');
prova(in_array('nuovo@unical.it', $dest, true) && in_array('segreteria@unical.it', $dest, true), "email di conferma allo studente e all'ufficio", json_encode($dest));
$EMAIL = [];
cambia_stato_pratica($conn, $idp, 'integrazione', 'Manca il piano di studi', 10);
prova(pratica($conn, $idp)['stato'] === 'integrazione' && ($EMAIL[0]['a'] ?? '') === 'nuovo@unical.it' && str_contains($EMAIL[0]['corpo'] ?? '', 'Manca il piano di studi'), "integrazione richiesta: email allo studente con la nota");
prova(messaggio_pratica($conn, $idp, 'studente', 73, '') !== null, "messaggio vuoto rifiutato");
prova(messaggio_pratica($conn, $idp, 'studente', 73, 'Ecco il piano') === null && pratica($conn, $idp)['stato'] === 'in_lavorazione', "la risposta dello studente riporta la pratica in lavorazione");
prova((int)$conn->query("SELECT COUNT(*) n FROM pratiche_eventi WHERE pratica_id = $idp")->fetch_assoc()['n'] === 4, "storico completo della pratica");
prova(!cambia_stato_pratica($conn, $idp, 'inventato', '', 10), "stato non previsto rifiutato");

sezione("Ufficio didattico, campi guidati, verbale ed Excel");
$q("INSERT INTO personale_ateneo (id, cognome, nome, email, gruppo, attivo) VALUES ('op.prova', 'Operatrice', 'Olga', 'olga.op@unical.it', 'pta', 1)");
$q("INSERT INTO utenti (id, codice_fiscale, nome, cognome, email, ruolo_id) VALUES (97, 'OPERAT97XXXXXXXX', 'Olga', 'Operatrice', 'olga.op@unical.it', 5)");
$u97 = $conn->query("SELECT * FROM utenti WHERE id = 97")->fetch_assoc();
prova(!utente_gestisce_didattica($conn, $u97), "prima di essere nell'ufficio non gestisce la Didattica");
prova(aggiungi_operatore_ufficio($conn, 'op.prova', 'Responsabile', ['pratiche', 'ricevimento', 'inventato']) === null, "operatore aggiunto dall'anagrafe");
prova(utente_gestisce_didattica($conn, $u97) && utente_operatore_ufficio($conn, $u97, 'ricevimento') && !utente_operatore_ufficio($conn, $u97, 'sedute'), "operatore: gestisce la Didattica, compiti rispettati");
prova(aggiungi_operatore_ufficio($conn, 'nessuno', '', []) !== null, "persona inesistente rifiutata");
prova(email_ufficio_didattica($conn, '') === ['olga.op@unical.it'] && email_ufficio_didattica($conn, 'uff@unical.it') === ['uff@unical.it'], "avvisi: email del modulo, altrimenti gli operatori");
$q("INSERT INTO pagine_eventi (id, titolo, slug, tipo_area) VALUES (950, 'Sportelli', 'sportelli-prova', 'calendario')");
$rid_u = crea_sportello_ufficio($conn, 950, '', 'Cubo 4B');
prova($rid_u > 0 && array_column(sportelli_utente($conn, $u97), 'id') == [$rid_u] && risorsa($conn, $rid_u)['email_notifiche'] === 'olga.op@unical.it', "sportello dell'ufficio gestito dall'operatore");
$ct = campi_modulo(json_encode([['etichetta' => 'Esami sostenuti', 'tipo' => 'tabella', 'opzioni' => 'Insegnamento, CFU, Voto', 'obbligatorio' => true],
                                 ['etichetta' => 'Anno accademico', 'tipo' => 'anno_accademico'], ['etichetta' => 'Convalide', 'tipo' => 'tabella', 'opzioni' => 'Insegnamento convalidato, CFU', 'ufficio' => true],
                                 ['etichetta' => 'Dal', 'tipo' => 'date']]));
prova(count(campi_studente($ct)) === 3 && count(campi_ufficio($ct)) === 1 && campi_ufficio($ct)[0]['etichetta'] === 'Convalide', "campi dello studente e dell'ufficio separati");
$_POST = ['campo_c1' => [['Zoologia', '', 'Botanica'], ['9', '', '6'], ['28/30', '', '30/30']], 'campo_c2' => '2025/2026', 'campo_c4' => '2026-03-01'];
[$rt, $et] = leggi_risposte_modulo(campi_studente($ct), $conn);
$_POST = [];
prova(!$et && $rt[0]['righe'] === [['Zoologia', '9', '28/30'], ['Botanica', '6', '30/30']] && $rt[0]['colonne'] === ['Insegnamento', 'CFU', 'Voto'] && $rt[1]['valore'] === '2025/2026', "tabella a righe (righe vuote scartate) e anno accademico", json_encode($rt));
$_POST = ['campo_c2' => '2025-26'];
[, $et2] = leggi_risposte_modulo(campi_studente($ct), $conn);
$_POST = [];
prova(in_array('Esami sostenuti: campo obbligatorio', $et2, true), "tabella obbligatoria vuota segnalata");
prova(str_contains(html_campo_pratica($ct[0], [['A', '1', '2']]), 'name="campo_c1[0][]" value="A"') && str_contains(html_campo_pratica($ct[0]), 'list="dl_insegnamento"'), "tabella nel modulo con l'elenco degli insegnamenti");
$q("INSERT INTO didattica_moduli (id, titolo, categoria, tipo, campi_json, verbale_json, attivo) VALUES (501, 'Passaggio di corso', 'Carriera', 'online', '" . $conn->real_escape_string(json_encode([['etichetta' => 'Esami sostenuti', 'tipo' => 'tabella', 'opzioni' => 'Insegnamento, CFU, Voto'], ['etichetta' => 'Anno accademico', 'tipo' => 'anno_accademico'], ['etichetta' => 'Dal', 'tipo' => 'date']])) . "',
    '" . $conn->real_escape_string(json_encode(['testo' => "Lo studente {STUDENTE}, matricola {MATRICOLA}, per l'a.a. {anno accademico} dal {Dal} chiede il passaggio.", 'delibera' => 'Il Consiglio approva.'])) . "', 1),
    (502, 'Lavoro finale', 'Lauree', 'online', '" . $conn->real_escape_string(json_encode([['etichetta' => 'Corso di studio', 'tipo' => 'text'], ['etichetta' => 'Relatore', 'tipo' => 'docente']])) . "',
    '" . $conn->real_escape_string(json_encode(['sezione' => 'Domande lavoro finale', 'stile' => 'elenco', 'colonne' => 'COGNOME, NOME, MATRICOLA, RELATORE', 'raggruppa' => 'Corso di studio'])) . "', 1)");
$u73['matricola_studente'] = '254671';
$pa = crea_pratica($conn, modulo_didattica($conn, 501), $u73, $rt);
$_POST = ['campo_c1' => 'Scienze naturali', 'campo_c2' => 'Sperone Emilio'];
[$rt2] = leggi_risposte_modulo(campi_modulo(modulo_didattica($conn, 502)['campi_json']), $conn);
$_POST = [];
$pb = crea_pratica($conn, modulo_didattica($conn, 502), $u73, $rt2);
$conn->query("UPDATE pratiche SET ufficio_json = '" . $conn->real_escape_string(json_encode([['etichetta' => 'Convalide', 'tipo' => 'tabella', 'colonne' => ['Insegnamento convalidato', 'CFU'], 'righe' => [['Zoologia generale', '9']], 'valore' => 'Zoologia generale | 9']])) . "' WHERE id = $pa");
$ppa = pratiche_per_esportazione($conn, [$pa])[0];
prova(testo_segnaposti_pratica(verbale_modulo($ppa)['testo'], $ppa) === "Lo studente ARRIVATO NUOVO, matricola 254671, per l'a.a. 2025/2026 dal 01/03/2026 chiede il passaggio.", "segnaposti del verbale (maiuscole, date, campi)", testo_segnaposti_pratica(verbale_modulo($ppa)['testo'], $ppa));
$q("INSERT INTO didattica_sedute (id, organo, anno_accademico, data, ora_inizio, ora_fine, luogo, odg, presenze, segretario, coordinatore) VALUES (60, 'Consiglio del Corso di Laurea in Scienze Naturali', '2026/2027', '2026-10-15', '15:30', '17:00', 'aula L3', 'Comunicazioni\nPratiche studenti\nVarie', 'Professori\nProf. Rossi | PRESENTE', 'la Dott.ssa Bianchi', 'Prof. Rossi')");
$conn->query("UPDATE pratiche SET seduta_id = 60 WHERE id IN ($pa, $pb)");
$tutte = pratiche_per_esportazione($conn, [$pa, $pb]);
$doc = genera_verbale_pratiche($conn, seduta_didattica($conn, 60), $tutte);
$xml_doc = ''; $logo_ok = false;
if ($doc && ($z = new ZipArchive())->open($doc) === true) { $xml_doc = (string)$z->getFromName('word/document.xml'); $logo_ok = $z->locateName('word/media/logo.jpg') !== false; $z->close(); }
$testo_doc = html_entity_decode(strip_tags(str_replace('</w:p>', "\n", $xml_doc)));
prova($xml_doc !== '' && (new DOMDocument())->loadXML($xml_doc) && $logo_ok, "verbale Word valido con il logo");
prova(str_contains($testo_doc, 'Il giorno 15 del mese di ottobre 2026 alle ore 15:30') && str_contains($testo_doc, '2. Pratiche studenti') && str_contains($testo_doc, 'Zoologia generale')
      && str_contains($testo_doc, 'Domande lavoro finale') && str_contains($testo_doc, 'Scienze naturali:') && str_contains($testo_doc, 'Il Consiglio approva.') && str_contains($testo_doc, 'si scioglie alle ore 17:00'), "verbale: intestazione, pratiche nel punto giusto, tabelle, elenco per corso, chiusura");
prova(strpos($testo_doc, 'Passaggio') < strpos($testo_doc, 'Domande lavoro finale'), "sezioni nell'ordine delle categorie");
$xls = genera_excel_pratiche($conn, $tutte);
$fogli = []; $s1 = '';
if ($xls && ($z = new ZipArchive())->open($xls) === true) { preg_match_all('/sheet name="([^"]+)"/', (string)$z->getFromName('xl/workbook.xml'), $mm); $fogli = $mm[1]; $s1 = (string)$z->getFromName('xl/worksheets/sheet1.xml'); $z->close(); }
prova(count($fogli) === 3 && $fogli[0] === 'Tutte le pratiche' && (new DOMDocument())->loadXML($s1) && str_contains($s1, 'Zoologia | 9 | 28/30') && str_contains($s1, 'Sperone Emilio') && str_contains($s1, '15/10/2026'), "Excel: foglio con tutte e uno per modulo, tabelle e seduta", json_encode($fogli));
@unlink($doc); @unlink($xls);

sezione("Iter delle pratiche");
$q("INSERT INTO personale_ateneo (id, cognome, nome, email, gruppo, attivo) VALUES ('man.prova', 'Manager', 'Marta', 'marta.man@unical.it', 'pta', 1), ('cdl.prova', 'Referente', 'Carlo', 'carlo.cdl@unical.it', 'docenti', 1), ('car.prova', 'Carriere', 'Carla', 'carla.car@unical.it', 'pta', 1), ('int.prova', 'Tutor', 'Irene', 'irene.int@unical.it', 'docenti', 1)");
$uff = []; foreach (uffici_didattica($conn, true) as $id_u => $u) $uff[$u['chiave']] = $id_u;
prova(count(array_intersect_key($uff, array_flip(['manager', 'referente_cdl', 'carriere', 'internazionalizzazione', 'operatore']))) === 5 && (int)uffici_didattica($conn)[$uff['manager']]['smista'] === 1 && (int)uffici_didattica($conn)[$uff['referente_cdl']]['segue_corsi'] === 1, "uffici di partenza dell'Ufficio didattico");
aggiungi_operatore_ufficio($conn, 'man.prova', '', ['pratiche'], $uff['manager']);
aggiungi_operatore_ufficio($conn, 'cdl.prova', '', ['pratiche'], $uff['referente_cdl'], ['Corso di laurea in Scienze naturali']);
aggiungi_operatore_ufficio($conn, 'car.prova', '', ['pratiche'], $uff['carriere']);
aggiungi_operatore_ufficio($conn, 'int.prova', '', ['pratiche'], $uff['internazionalizzazione']);
$op = []; foreach (operatori_ufficio($conn) as $o) if ($o['ufficio_id']) $op[uffici_didattica($conn)[(int)$o['ufficio_id']]['chiave']] = (int)$o['id'];
prova(isset($op['manager'], $op['referente_cdl'], $op['carriere'], $op['internazionalizzazione']), "personale assegnato agli uffici");
aggiungi_operatore_ufficio($conn, 'int.prova', '', ['pratiche'], $uff['internazionalizzazione'], [], [$uff['carriere'], $uff['carriere'], 99999]);
$o_multi = null; foreach (operatori_ufficio($conn) as $o) if ($o['persona_id'] === 'int.prova') $o_multi = $o;
prova($o_multi && $o_multi['_uffici'] === [$uff['internazionalizzazione'], $uff['carriere']], "operatore in più uffici: principale per primo, senza doppioni né uffici inesistenti");
prova(in_array((int)$o_multi['id'], array_map('intval', array_column(\App\Core\App::per($conn)->get(\App\Didattica\ServizioFlussoConvalide::class)->personeUfficio($uff['carriere']), 'id')), true), "l'operatore con due uffici compare anche nel secondo");
aggiungi_operatore_ufficio($conn, 'int.prova', '', ['pratiche'], $uff['internazionalizzazione']);
$q("INSERT INTO uffici (nome, smista, segue_corsi, ordine) VALUES ('Tirocini', 0, 0, 9)");
$id_tir = (int)$conn->insert_id;
prova(ufficio_didattica_id($conn, $id_tir) === null && ufficio_didattica_id($conn, 'carriere') === $uff['carriere'], "ufficio nuovo letto dopo l'aggiornamento; chiavi dei modelli riconosciute");
uffici_didattica($conn, true);
prova(ufficio_didattica_id($conn, $id_tir) === $id_tir, "ufficio creato dal pannello disponibile per l'iter");
$q("INSERT INTO didattica_moduli (id, titolo, categoria, tipo, campi_json, iter_json, attivo) VALUES (503, 'Attività all\'estero', 'Mobilità', 'online', '" . $conn->real_escape_string(json_encode([['etichetta' => 'Corso di studio', 'tipo' => 'corso_studio']])) . "', '[\"internazionalizzazione\",\"referente_cdl\",\"carriere\"]', 1)");
$m503 = modulo_didattica($conn, 503);
prova(passi_pratica($m503) === [0 => 'Ricevuta e da smistare', 1 => 'Internazionalizzazione', 2 => 'Referenti dei corsi di studio', 3 => 'Carriere studenti'], "passi dell'iter dal modulo");
prova(iter_modulo(['iter_json' => '["manager","inventato"]']) === [0] && iter_modulo(['iter_json' => json_encode([$id_tir, 'carriere'])]) === [$id_tir, $uff['carriere']], "iter: uffici per id o chiave; chi smista e uffici inesistenti esclusi");
$EMAIL = [];
$pi = crea_pratica($conn, $m503, $u73, [['etichetta' => 'Corso di studio', 'tipo' => 'corso_studio', 'valore' => 'Corso di laurea in Scienze naturali', 'file' => null, 'nome_file' => null]]);
prova(in_array('marta.man@unical.it', array_column($EMAIL, 'a'), true) && !in_array('carlo.cdl@unical.it', array_column($EMAIL, 'a'), true), "nuova pratica: avviso al manager che smista");
$pp = pratica($conn, $pi);
prova((int)operatori_suggeriti($conn, $pp, $m503, 1)[0]['id'] === $op['internazionalizzazione'] && (int)operatori_suggeriti($conn, $pp, $m503, 2)[0]['id'] === $op['referente_cdl'], "operatore proposto per il passo (e il referente del corso della pratica)");
$EMAIL = [];
prova(assegna_pratica($conn, $pi, $op['internazionalizzazione'], 1, 'Controlla il Learning Agreement', 50, 'Manager Marta') === null, "smistamento al tutor dell'internazionalizzazione");
$pp = pratica($conn, $pi);
prova((int)$pp['assegnata_a'] === $op['internazionalizzazione'] && (int)$pp['passo'] === 1 && $pp['stato'] === 'in_lavorazione' && ($EMAIL[0]['a'] ?? '') === 'irene.int@unical.it', "pratica in carico, in lavorazione, email all'operatore");
$ev_int = $conn->query("SELECT tipo, interno FROM pratiche_eventi WHERE pratica_id = $pi ORDER BY id")->fetch_all(MYSQLI_ASSOC);
prova(in_array(['tipo' => 'passaggio', 'interno' => '0'], $ev_int) && in_array(['tipo' => 'messaggio', 'interno' => '1'], $ev_int), "passaggio visibile allo studente, nota del manager interna");
$EMAIL = [];
messaggio_pratica($conn, $pi, 'ufficio', 50, 'Parere favorevole', null, true, 'messaggio', 'Tutor Irene');
prova(!in_array('nuovo@unical.it', array_column($EMAIL, 'a'), true), "nota interna: nessuna email allo studente");
messaggio_pratica($conn, $pi, 'ufficio', 50, 'Verbale del CdL', null, false, 'attivita', 'Referente Carlo');
prova((int)$conn->query("SELECT COUNT(*) n FROM pratiche_eventi WHERE pratica_id = $pi AND tipo = 'attivita' AND interno = 0")->fetch_assoc()['n'] === 1, "attività visibile allo studente");
assegna_pratica($conn, $pi, $op['carriere'], 9, '', 50);
prova((int)pratica($conn, $pi)['passo'] === 3, "il passo non va oltre l'ultimo");
cambia_stato_pratica($conn, $pi, 'integrazione', '', 50, 'Carriere Carla', ['tipo' => 'autodichiarazione', 'testo' => "di aver sostenuto l'esame di Zoologia"]);
prova((json_decode((string)pratica($conn, $pi)['richiesta_json'], true)['tipo'] ?? '') === 'autodichiarazione', "richiesta di autodichiarazione registrata");
prova(messaggio_pratica($conn, $pi, 'studente', 73, 'ecco', null) === null && pratica($conn, $pi)['stato'] === 'integrazione', "un messaggio non chiude la richiesta di autodichiarazione");
prova(autodichiarazione_pratica($conn, $pi, 73, '', false) !== null, "autodichiarazione senza conferma rifiutata");
prova(autodichiarazione_pratica($conn, $pi, 73, 'Voto 28/30', true) === null && pratica($conn, $pi)['stato'] === 'in_lavorazione' && pratica($conn, $pi)['richiesta_json'] === null, "autodichiarazione resa: la pratica torna in lavorazione");
$ad = $conn->query("SELECT testo FROM pratiche_eventi WHERE pratica_id = $pi AND tipo = 'autodich'")->fetch_assoc()['testo'] ?? '';
prova(str_contains($ad, "di aver sostenuto l'esame di Zoologia") && str_contains($ad, 'D.P.R. 445/2000') && str_contains($ad, 'Voto 28/30'), "testo dell'autodichiarazione nello storico");
cambia_stato_pratica($conn, $pi, 'integrazione', '', 50, '', ['tipo' => 'documenti', 'testo' => 'Allega il transcript']);
messaggio_pratica($conn, $pi, 'studente', 73, 'Allegato', null);
prova(pratica($conn, $pi)['stato'] === 'in_lavorazione', "integrazione di documenti: la risposta dello studente la chiude");
prova(str_contains(html_iter_pratica($conn, pratica($conn, $pi), $m503), 'aria-current="step"') && str_contains(html_iter_pratica($conn, pratica($conn, $pi), $m503), 'Carriere Carla'), "iter visibile con chi ha in carico la pratica");

sezione("Convenzione FSL compilata online");
$scuola_cv = ['ISTITUTO' => 'Liceo Prova (codice meccanografico CSPS020009)', 'ISTITUTO_FIRMA' => 'Liceo Prova', 'COMUNE' => 'Cosenza (CS)', 'INDIRIZZO' => 'Via Roma 1',
              'CF_ISTITUTO' => '80004560787', 'DIRIGENTE' => 'Dott.ssa Maria Rossi', 'DIRIGENTE_FIRMA' => 'Dott.ssa Maria Rossi', 'DIR_LUOGO_NASCITA' => 'Cosenza', 'DIR_DATA_NASCITA' => '01/01/1970', 'DIR_CF' => 'RSSMRA70A41D086X'];
$att_cv = [['TITOLO' => 'Laboratorio A', 'DESCRIZIONE' => 'Descrizione A', 'STUDENTI' => '20', 'PERIODO' => '12/11/2026', 'DURATA' => '5 ore', 'TUTOR_DIBEST' => 'Prof. X', 'TUTOR_SCUOLA' => 'Luca Bianchi'],
           ['TITOLO' => 'Laboratorio B', 'DESCRIZIONE' => '', 'STUDENTI' => '', 'PERIODO' => '13/11/2026', 'DURATA' => '3 ore', 'TUTOR_DIBEST' => 'Prof. Y', 'TUTOR_SCUOLA' => '']];
$png = tempnam(sys_get_temp_dir(), 'lg') . '.png';
file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAYAAAD0In+KAAAAEUlEQVR4nGP4z8DwHwQYGBgAJ+UE/O7Q3vYAAAAASUVORK5CYII='));
$fd = genera_docx_convenzione('convenzione', $scuola_cv, $att_cv, $png);
$xd = ''; $hd = ''; $media = false;
if ($fd && ($z = new ZipArchive())->open($fd) === true) { $xd = (string)$z->getFromName('word/document.xml'); $hd = (string)$z->getFromName('word/header1.xml'); $media = $z->locateName('word/media/logo_scuola.png') !== false; $z->close(); }
$td = html_entity_decode(strip_tags(str_replace('</w:p>', "\n", $xd)));
prova($xd !== '' && (new DOMDocument())->loadXML($xd) && (new DOMDocument())->loadXML($hd), "convenzione: documento e intestazione validi");
prova(str_contains($td, 'codice fiscale 80004560787') && str_contains($td, 'Dott.ssa Maria Rossi, nata/o a Cosenza, il 01/01/1970, codice fiscale RSSMRA70A41D086X') && !str_contains($td, '{{'), "dati della scuola e del Dirigente, nessun segnaposto rimasto");
prova(substr_count($td, 'Titolo corso:') === 2 && str_contains($td, 'Laboratorio A') && str_contains($td, 'Laboratorio B'), "Allegato A ripetuto per ogni attività");
prova(str_contains($td, 'Numero di studenti: ……………'), "campo vuoto: torna il testo originale da completare");
prova($media && str_contains($hd, 'rIdLogoScuola') && !str_contains($hd, 'Logo/intestazione Istituzione Scolastica'), "logo della scuola nell'intestazione");
@unlink($fd); @unlink($png);
$fa = genera_docx_convenzione('allegato', $scuola_cv, $att_cv);
$xa = ''; if ($fa && ($z = new ZipArchive())->open($fa) === true) { $xa = html_entity_decode(strip_tags(str_replace('</w:p>', "\n", (string)$z->getFromName('word/document.xml')))); $z->close(); } @unlink($fa);
prova(substr_count($xa, 'Titolo corso:') === 2 && str_contains($xa, 'Laboratorio B'), "Allegato A da solo con le due attività");
$istr = html_istruzioni_convenzione([], true, 'FS-PROVA01');
prova(str_contains($istr, 'convenzione_online.php?code=FS-PROVA01') && str_contains($istr, 'firma digitalmente'), "le istruzioni portano al modulo guidato");
prova(str_contains(html_istruzioni_convenzione([]), 'Al termine della prenotazione'), "nel modulo di prenotazione si annuncia la compilazione online");

sezione("Scadenze, promemoria, protocollo e statistiche");
$ieri = date('Y-m-d', strtotime('-1 day')); $domani = date('Y-m-d', strtotime('+1 day'));
prova(periodo_modulo([])[0] && periodo_modulo(['aperto_dal' => $ieri, 'aperto_al' => $domani])[0] && !periodo_modulo(['aperto_al' => $ieri])[0] && !periodo_modulo(['aperto_dal' => $domani])[0], "modulo compilabile solo nel periodo");
prova(str_starts_with(periodo_modulo(['aperto_dal' => $domani])[1], 'Si compila dal '), "testo del periodo per chi non può ancora compilare");
// Pratica ferma: assegnata, senza movimenti da 10 giorni (promemoria del modulo a 7)
$conn->query("UPDATE didattica_moduli SET giorni_promemoria = 7 WHERE id = 503");
$conn->query("UPDATE pratiche SET stato = 'in_lavorazione', aggiornata_il = NOW() - INTERVAL 10 DAY, promemoria_il = NULL WHERE id = $pi");
$EMAIL = [];
prova(promemoria_pratiche_ferme($conn) >= 1 && in_array('carla.car@unical.it', array_column($EMAIL, 'a'), true), "promemoria a chi ha in carico la pratica ferma");
$EMAIL = [];
promemoria_pratiche_ferme($conn);
prova(!in_array('carla.car@unical.it', array_column($EMAIL, 'a'), true), "un solo promemoria per periodo di attesa");
$conn->query("UPDATE pratiche SET protocollo = '1234/2026', protocollo_data = '2026-10-01' WHERE id = $pi");
$ppi = pratiche_per_esportazione($conn, [$pi])[0];
prova(testo_segnaposti_pratica('Prot. {PROTOCOLLO}', $ppi) === 'Prot. 1234/2026 del 01/10/2026', "protocollo nel testo del verbale");
$xls_p = genera_excel_pratiche($conn, [$ppi]); $s_p = '';
if ($xls_p && ($z = new ZipArchive())->open($xls_p) === true) { $s_p = (string)$z->getFromName('xl/worksheets/sheet1.xml'); $z->close(); } @unlink($xls_p);
prova(str_contains($s_p, '>Protocollo<') && str_contains($s_p, '1234/2026 del 01/10/2026'), "protocollo nell'Excel");
$stp = statistiche_pratiche($conn, date('Y-m-d', strtotime('-1 year')), date('Y-m-d'));
prova($stp['totale'] >= 1 && isset($stp['per_modulo']["Attività all'estero"]) && isset($stp['per_corso']['Corso di laurea in Scienze naturali']), "statistiche per modulo e per corso");
prova(isset($stp['tempi_passi']['Smistamento'], $stp['tempi_passi']['Internazionalizzazione']), "tempi medi per passo dell'iter", json_encode(array_keys($stp['tempi_passi'])));
// Statistiche delle risorse: area 950 con lo sportello dell'ufficio, orari lun-ven 9-13, una prenotazione confermata e una annullata
$q("INSERT INTO risorse_orari (risorsa_id, giorno, dalle, alle) VALUES ($rid_u, 1, '09:00:00', '13:00:00'), ($rid_u, 2, '09:00:00', '13:00:00')");
$lun_s = date('Y-m-d', strtotime('monday this week'));
$q("INSERT INTO prenotazioni_risorse (risorsa_id, inizio, fine, stato, codice) VALUES ($rid_u, '$lun_s 09:00:00', '$lun_s 11:00:00', 'confermata', 'ST-1'), ($rid_u, '$lun_s 11:00:00', '$lun_s 12:00:00', 'annullata', 'ST-2')");
$sr = statistiche_risorse($conn, 950, $lun_s, date('Y-m-d', strtotime($lun_s . ' +6 days')));
$sx = $sr['risorse'][$rid_u] ?? [];
prova(($sx['prenotazioni'] ?? 0) === 2 && ($sx['annullate'] ?? 0) === 1 && ($sx['ore'] ?? 0) == 2 && ($sx['ore_aperte'] ?? 0) == 8 && ($sx['utilizzo'] ?? 0) === 25, "statistiche della risorsa: ore, ore aperte, utilizzo, annullate", json_encode($sx));
prova(($sr['fasce'][1][9] ?? 0) === 1 && ($sr['fasce'][1][10] ?? 0) === 1 && empty($sr['fasce'][1][11]), "fasce più richieste (le annullate non contano)");
$fdp = genera_docx_convenzione('allegato', $scuola_cv, $att_cv, null, '456/2026 del 02/10/2026'); $xp = '';
if ($fdp && ($z = new ZipArchive())->open($fdp) === true) { $xp = (string)$z->getFromName('word/document.xml'); $z->close(); } @unlink($fdp);
prova(str_contains($xp, 'Prot. n. 456/2026 del 02/10/2026'), "protocollo nei documenti della convenzione");

sezione("Moduli: tipi di campo, colonne delle tabelle e logica condizionale");
$cl = campi_modulo(json_encode([
    ['etichetta' => 'Dove hai sostenuto gli esami', 'tipo' => 'radio', 'opzioni' => 'in questo Ateneo, in un altro Ateneo', 'obbligatorio' => true],
    ['etichetta' => 'Ateneo', 'tipo' => 'text', 'obbligatorio' => true, 'cond' => ['campo' => 'dove hai sostenuto gli esami', 'op' => 'uguale', 'valore' => 'in un altro Ateneo']],
    ['etichetta' => 'Esami', 'tipo' => 'tabella', 'opzioni' => 'Insegnamento:insegnamento, CFU:cfu, Data:data, Esito:scelta(Sì|No)'],
    ['etichetta' => 'Tipo di iscrizione', 'tipo' => 'select', 'opzioni' => 'tempo pieno, part-time'],
    ['etichetta' => 'Tasse', 'tipo' => 'text', 'auto' => ['campo' => 'Tipo di iscrizione', 'op' => 'uguale', 'valore' => 'part-time', 'imposta' => 'ridotte']],
    ['etichetta' => 'Lingue', 'tipo' => 'multicheck', 'opzioni' => 'inglese, francese, tedesco', 'obbligatorio' => true],
    ['etichetta' => 'Codice fiscale del genitore', 'tipo' => 'codice_fiscale'],
    ['etichetta' => 'Dichiaro il vero', 'tipo' => 'dichiarazione', 'obbligatorio' => true, 'aiuto' => 'ai sensi del D.P.R. 445/2000'],
    ['etichetta' => 'Insegnamento a scelta', 'tipo' => 'insegnamento_ateneo'],
    ['etichetta' => 'Sezione', 'tipo' => 'titolo'],
    ['etichetta' => 'Condizione rotta', 'tipo' => 'text', 'cond' => ['campo' => 'Inesistente', 'op' => 'uguale', 'valore' => 'x']],
]));
prova($cl[1]['cond']['nome'] === 'c1' && $cl[4]['auto']['nome'] === 'c4' && $cl[10]['cond'] === null, "condizioni collegate al campo indicato con la domanda (maiuscole indifferenti, campo inesistente ignorato)");
prova(array_column($cl[2]['colonne'], 'tipo') === ['insegnamento', 'cfu', 'data', 'scelta'] && $cl[2]['colonne'][3]['scelte'] === ['Sì', 'No'] && $cl[2]['opzioni'] === ['Insegnamento', 'CFU', 'Data', 'Esito'], "colonne della tabella con nome e tipo");
prova(array_column(colonne_tabella(['Insegnamento convalidato', 'Anno insegnamento', 'Tot CFU insegn.', 'CFU da integrare', 'Voto', 'S.S.D.', 'Relatore']), 'tipo') === ['insegnamento', 'testo', 'cfu', 'testo', 'voto', 'ssd', 'docente'], "tipi delle colonne scritte prima, riconosciuti dal nome");
prova(testo_colonne_tabella($cl[2]['colonne']) === 'Insegnamento:insegnamento, CFU:cfu, Data:data, Esito:scelta(Sì|No)', "colonne di nuovo in testo per il costruttore");
$_POST = ['campo_c1' => 'in questo Ateneo', 'campo_c3' => [['Chimica', ''], ['6', ''], ['2026-02-15', ''], ['Forse', '']], 'campo_c4' => 'part-time', 'campo_c5' => 'piene', 'campo_c6' => ['inglese', 'cinese'],
          'campo_c7' => 'abc', 'campo_c9' => 'Chimica generale – Biologia (a.a. 2024/2025)', 'campo_c9_meta' => json_encode(['id' => 7, 'nome' => 'Chimica generale', 'corso' => 'Biologia', 'aa' => '2024/2025', 'cfu' => 6, 'ssd' => 'CHIM/03'])];
[$rl, $el] = leggi_risposte_modulo($cl, $conn);
$_POST = [];
$per = []; foreach ($rl as $r) $per[$r['etichetta']] = $r;
prova(!empty($per['Ateneo']['nascosto']) && !in_array('Ateneo: campo obbligatorio', $el, true), "campo nascosto dalla condizione: non obbligatorio, resta vuoto", json_encode($el));
prova($per['Tasse']['valore'] === 'ridotte', "valore automatico imposto dal server");
prova($per['Esami']['righe'] === [['Chimica', '6', '15/02/2026', '']], "tabella: date in formato italiano, scelta non prevista scartata", json_encode($per['Esami']['righe']));
prova($per['Lingue']['valore'] === 'inglese' && in_array('Codice fiscale del genitore: codice fiscale non valido', $el, true) && in_array('Dichiaro il vero: devi accettare la dichiarazione', $el, true), "scelta multipla, codice fiscale e dichiarazione controllati", json_encode($el));
prova(($per['Insegnamento a scelta']['meta']['cfu'] ?? null) === 6.0 && !isset($per['Sezione']), "insegnamento dal catalogo con corso, a.a., CFU e S.S.D.; i titoli non sono risposte");
$_POST = ['campo_c1' => 'in un altro Ateneo', 'campo_c6' => ['tedesco'], 'campo_c8' => '1', 'campo_c4' => 'tempo pieno', 'campo_c5' => 'piene'];
[$rl2, $el2] = leggi_risposte_modulo($cl, $conn);
$_POST = [];
prova($el2 === ['Ateneo: campo obbligatorio'] && $rl2[4]['valore'] === 'piene', "campo mostrato dalla condizione: torna obbligatorio; senza condizione il valore è quello scritto", json_encode($el2));
$hc = html_campo_pratica($cl[1]) . html_campo_pratica($cl[2]) . html_campo_pratica($cl[8]);
prova(str_contains($hc, 'data-cond="') && str_contains($hc, 'ins-scegli') && str_contains($hc, 'data-col="cfu"') && str_contains($hc, 'type="date"'), "campi con la logica e la scelta dal catalogo nel modulo");
prova(str_contains(js_tabelle_pratica(), 'assets/js/campi-pratica.js'), "script dei campi nella pagina del modulo");

sezione("Catalogo di Ateneo degli insegnamenti");
$q("INSERT INTO ateneo_cds (codice, anno, nome, tipo, tipo_descrizione, dipartimento) VALUES ('0723', 2024, 'Biologia', 'L', 'Laurea', 'DiBEST'), ('0723', 2025, 'Biologia', 'L', 'Laurea', 'DiBEST'), ('0999', 2025, 'Ingegneria civile', 'LM', 'Laurea Magistrale', 'DIMES')");
prova(array_keys(catalogo_tipi_corso($conn)) === ['L', 'LM'], "tipi di corso del catalogo");
$cc = catalogo_corsi($conn, 'L');
prova(count($cc) === 1 && $cc[0]['anni'] === [2025, 2024], "corsi del tipo con gli anni di offerta");
$ni = salva_catalogo_insegnamenti($conn, '0723', 2024, [['StudyActivityID' => 91, 'StudyActivityName' => 'CHIMICA GENERALE', 'StudyActivityCdSCod' => '0723', 'StudyActivityYear' => 1, 'StudyActivityCFU' => '9', 'StudyActivitySSDCod' => 'CHIM/03'],
                                                         ['StudyActivityID' => 92, 'StudyActivityName' => 'ALTRO CORSO', 'StudyActivityCdSCod' => '0999']]);
$ci = catalogo_insegnamenti($conn, '0723', 2024, false);
prova($ni === 1 && count($ci) === 1 && (float)$ci[0]['cfu'] === 9.0 && $ci[0]['ssd_cod'] === 'CHIM/03', "insegnamenti del corso e dell'anno di offerta (solo quel corso)");
prova(catalogo_insegnamenti($conn, '0723', 2023, false) === [], "anno senza insegnamenti");

sezione("Consigli: referenti, componenti, presenze e decisioni in seduta");
$cons = consigli_didattica($conn);
prova(count($cons) === 5 && str_contains(reset($cons)['nome'], 'Scienze Naturali e Ambientali'), "i 5 consigli dei corsi di studio del Dipartimento");
$c1 = (int)array_key_first($cons);
$q("INSERT INTO personale_ateneo (id, cognome, nome, email, ruolo, gruppo, docente, attivo) VALUES ('ref.prova', 'Referente', 'Rita', 'rita.ref@unical.it', 'Professore Associato', 'docenti', 1, 1), ('ord.prova', 'Ordinario', 'Otto', 'otto.ord@unical.it', 'Professore Ordinario', 'docenti', 1, 1)");
$q("INSERT INTO utenti (id, codice_fiscale, nome, cognome, email, ruolo_id) VALUES (98, 'REFRTA80XXXXXXXX', 'Rita', 'Referente', 'rita.ref@unical.it', 5)");
$u98 = $conn->query("SELECT * FROM utenti WHERE id = 98")->fetch_assoc();
prova(aggiungi_persona_consiglio($conn, $c1, 'referente', 'ref.prova') === null && consigli_referente($conn, $u98) === [$c1] && !utente_gestisce_didattica($conn, $u98), "referente dall'anagrafe: vede il suo consiglio, non tutta la Didattica");
prova(aggiungi_persona_consiglio($conn, $c1, 'referente', 'ref.prova') !== null, "referente già presente non duplicato");
prova(aggiungi_persona_consiglio($conn, $c1, 'componente', 'ord.prova') === null && aggiungi_persona_consiglio($conn, $c1, 'componente', 'ref.prova') === null
      && aggiungi_persona_consiglio($conn, $c1, 'componente', '', 'Rappresentanti degli studenti', 'Studente Sara') === null, "componenti dall'anagrafe e scritti a mano");
prova(aggiungi_persona_consiglio($conn, $c1, 'referente', '', '', 'Senza Email') !== null, "referente a mano senza email rifiutato");
$comp = persone_consiglio($conn, $c1);
prova(array_column($comp, 'qualifica') === ['Professori ordinari', 'Professori associati', 'Rappresentanti degli studenti'], "gruppi del verbale dal ruolo dell'anagrafe, in ordine", json_encode(array_column($comp, 'qualifica')));
$q("INSERT INTO didattica_sedute (id, consiglio_id, organo, data, odg) VALUES (61, $c1, '" . $conn->real_escape_string($cons[$c1]['nome']) . "', '2026-11-05', 'Comunicazioni\nPratiche studenti')");
$s61 = seduta_didattica($conn, 61);
$pres = presenze_seduta($conn, $s61);
prova(count($pres) === 3 && !array_filter($pres, fn($r) => $r['_salvata']) && array_unique(array_column($pres, 'stato')) === ['P'], "presenze proposte: tutti i componenti presenti, non ancora registrate");
$ids_c = array_keys($pres);
salva_presenze_seduta($conn, $s61, [$ids_c[0] => 'P', $ids_c[1] => 'AG', $ids_c[2] => 'AI']);
prova(riepilogo_presenze(presenze_seduta($conn, $s61)) === ['P' => 1, 'AG' => 1, 'AI' => 1], "presenze salvate: presente, assente giustificato, ingiustificato");
// Pratica di convalida in seduta, con l'insegnamento del Dipartimento dall'anagrafe
$q("INSERT INTO insegnamenti (id, nome, cds_nome, anno_accademico, presente, cfu, ssd_cod) VALUES (501, 'Chimica generale e inorganica', 'Biologia', " . anno_accademico_corrente() . ", 1, 9, 'CHIM/03'), (502, 'Botanica', 'Biologia', " . anno_accademico_corrente() . ", 1, 6, 'BIO/01')");
$q("INSERT INTO didattica_moduli (id, titolo, categoria, tipo, campi_json, verbale_json, attivo) VALUES (504, 'Convalida esami', 'Carriera', 'online', '" . $conn->real_escape_string(json_encode([['etichetta' => 'Esami da convalidare', 'tipo' => 'tabella', 'opzioni' => 'Insegnamento sostenuto:insegnamento, CFU:cfu, Voto:voto, S.S.D.:ssd']])) . "',
    '" . $conn->real_escape_string(json_encode(['testo' => '{STUDENTE} chiede la convalida.', 'decisione' => 'convalide'])) . "', 1)");
$pc = crea_pratica($conn, modulo_didattica($conn, 504), $u73, [['etichetta' => 'Esami da convalidare', 'tipo' => 'tabella', 'valore' => '', 'colonne' => ['Insegnamento sostenuto', 'CFU', 'Voto', 'S.S.D.'], 'righe' => [['Chimica', '8', '27/30', 'CHIM/03'], ['Botanica sistematica', '6', '30/30', 'BIO/02']]]]);
$conn->query("UPDATE pratiche SET seduta_id = 61 WHERE id = $pc");
prova(utente_vede_pratica($conn, $u98, pratica($conn, $pc)) && !utente_vede_pratica($conn, $u98, pratica($conn, $pi)), "il referente vede le pratiche delle sedute del suo consiglio, non le altre");
$pcx = pratiche_per_esportazione($conn, [$pc])[0];
$dp = decisioni_pratica($pcx, 'convalide', campi_modulo($pcx['campi_json']));
prova(!empty($dp['_proposte']) && count($dp['righe']) === 2 && $dp['righe'][0]['richiesto'] === 'Chimica' && $dp['righe'][0]['voto'] === '27/30', "convalide proposte dagli esami indicati dallo studente");
$ins_d = insegnamenti_dipartimento_scelta($conn);
$et_chim = etichetta_insegnamento_scelta($ins_d[501]);
$dec = leggi_decisioni_post($conn, 'convalide', ['d_richiesto' => ['Chimica', 'Botanica sistematica', ''], 'd_cfu' => ['8', '6', ''], 'd_voto' => ['27/30', '30/30', ''], 'd_ins' => [$et_chim, 'Botanica', ''],
                                                 'd_ins_cfu' => ['', '6', ''], 'd_esito' => ['parziale', 'totale', 'totale'], 'd_cfu_ric' => ['8', '', ''], 'd_cfu_int' => ['', '', '']]);
prova(count($dec['righe']) === 2 && $dec['righe'][0]['ins_id'] === 501 && $dec['righe'][0]['ins_cfu'] === '9' && $dec['righe'][0]['cfu_int'] === '1' && $dec['righe'][1]['cfu_ric'] === '6' && $dec['righe'][1]['cfu_int'] === '0',
      "convalida parziale (CFU da integrare calcolati) e totale, insegnamento riconosciuto nell'anagrafe", json_encode($dec['righe']));
salva_decisioni_seduta($conn, $pc, 'approvata', $dec, '');
$pcx = pratiche_per_esportazione($conn, [$pc])[0];
$doc61 = genera_verbale_pratiche($conn, $s61, [$pcx]); $x61 = '';
if ($doc61 && ($z = new ZipArchive())->open($doc61) === true) { $x61 = html_entity_decode(strip_tags(str_replace('</w:p>', "\n", (string)$z->getFromName('word/document.xml')))); $z->close(); } @unlink($doc61);
prova(str_contains($x61, 'Professori ordinari') && str_contains($x61, 'ASSENTE GIUSTIFICATO') && str_contains($x61, 'ASSENTE INGIUSTIFICATO') && str_contains($x61, 'Presenti: 1'), "verbale: presenze per gruppo con il riepilogo");
prova(str_contains($x61, 'Quadro delle convalide') && str_contains($x61, 'Convalida parziale') && str_contains($x61, 'Chimica generale e inorganica') && substr_count($x61, 'Botanica sistematica') === 1, "verbale: quadro delle convalide al posto della tabella dello studente");
$xls61 = genera_excel_pratiche($conn, [$pcx]); $sx61 = '';
if ($xls61 && ($z = new ZipArchive())->open($xls61) === true) { $sx61 = (string)$z->getFromName('xl/worksheets/sheet1.xml'); $z->close(); } @unlink($xls61);
prova(str_contains($sx61, 'Esito in seduta') && str_contains($sx61, '>Approvata<') && str_contains($sx61, 'Convalida parziale'), "Excel con esito e decisioni");
$dpi = leggi_decisioni_post($conn, 'piano', ['d_richiesto' => ['Ecologia', 'Geologia'], 'd_cfu' => ['6', '9'], 'd_esito' => ['fuori_piano', 'inventato']]);
prova(array_column($dpi['righe'], 'esito') === ['fuori_piano', 'in_piano'] && tabella_decisioni($dpi)[1][0] === ['Ecologia', '6', '', 'Approvato fuori piano'], "piano di studi: in piano / fuori piano");
$EMAIL = [];
prova(applica_esiti_seduta($conn, $s61, 50, 'Referente Rita') === [1, 0, 0] && pratica($conn, $pc)['stato'] === 'accolta' && in_array('nuovo@unical.it', array_column($EMAIL, 'a'), true), "esiti applicati: pratica accolta, email allo studente");
$ev_es = $conn->query("SELECT * FROM pratiche_eventi WHERE pratica_id = $pc AND tipo = 'attivita' AND allegato LIKE '%estratto_%' ORDER BY id DESC LIMIT 1")->fetch_assoc();
$pdf_es = $ev_es ? (string)@file_get_contents(RADICE_SITO . '/' . $ev_es['allegato']) : '';
prova($ev_es && (int)$ev_es['interno'] === 0 && str_starts_with($pdf_es, '%PDF') && str_contains(implode(' ', array_column($EMAIL, 'corpo')), 'estratto del verbale'), "estratto del verbale in PDF nella pratica, visibile allo studente e citato nell'email");
prova(is_file(RADICE_SITO . '/' . DIR_PRATICHE . '.htaccess'), "la cartella degli allegati delle pratiche è protetta (.htaccess)");
if ($ev_es) @unlink(RADICE_SITO . '/' . $ev_es['allegato']);
$conn->query("UPDATE pratiche SET stato = 'in_lavorazione', esito_seduta = 'rinviata' WHERE id = $pc");
applica_esiti_seduta($conn, $s61, 50);
prova(pratica($conn, $pc)['seduta_id'] === null && pratica($conn, $pc)['stato'] === 'in_lavorazione', "pratica rinviata: torna senza seduta per la prossima");

sezione("Verbale punto per punto: testi dei punti, pratiche sotto il punto giusto, testata e piede come nel modello");
$sv62 = \App\Core\App::per($conn)->get(\App\Didattica\ServizioSedute::class);
$id62 = $sv62->salva(0, ['organo' => 'Consiglio Unificato di prova', 'anno_accademico' => '2025/2026', 'data' => '2026-09-07', 'ora_inizio' => '15:30', 'ora_fine' => '', 'luogo' => "l'aula L3", 'odg' => "Comunicazioni\n2) Pratiche studenti\nOrario lezioni\nVarie ed eventuali",
                       'presenze' => '', 'segretario' => 'Dott.ssa Segretaria', 'coordinatore' => 'Prof. Coordinatore', 'consiglio_id' => null, 'numero_verbale' => '8']);
$pu = \App\Core\App::per($conn)->get(\App\Didattica\ServizioPuntiSeduta::class);
$s62 = seduta_didattica($conn, $id62);
$p62 = $pu->punti($s62);
prova(array_column($p62, 'titolo') === ['Comunicazioni', 'Pratiche studenti', 'Orario lezioni', 'Varie ed eventuali'] && array_column($p62, 'tipo') === ['testo', 'pratiche', 'testo', 'testo'], "i punti nascono dall'o.d.g. (senza numerazione); «pratiche» nel titolo = punto delle pratiche", json_encode(array_column($p62, 'tipo')));
$t62 = []; foreach ($p62 as $pt) $t62[(int)$pt['id']] = '';
$t62[(int)$p62[0]['id']] = '<p style="color:red" onclick="x()">Il Coordinatore <strong>comunica</strong> che <em>tutto</em> procede.</p><script>alert(1)</script><p>Seconda riga.</p>';
$t62[(int)$p62[2]['id']] = '<ul><li>Primo orario</li><li>Secondo orario</li></ul>';
$pu->salva($s62, $t62, []);
$p62 = $pu->punti($s62);
prova(!str_contains($p62[0]['testo'], 'script') && !str_contains($p62[0]['testo'], 'onclick') && !str_contains($p62[0]['testo'], 'style') && str_contains($p62[0]['testo'], '<strong>comunica</strong>'), "il testo del punto è ripulito (niente script né attributi)", $p62[0]['testo']);
// Una pratica sotto il punto delle pratiche
$conn->query("UPDATE pratiche SET seduta_id = $id62 WHERE id = $pb");
$dopo62 = fn() => genera_verbale_pratiche($conn, seduta_didattica($conn, $id62), pratiche_per_esportazione($conn, \App\Core\App::per($conn)->get(\App\Didattica\SedutaRepository::class)->idPratiche($id62)));
$zip62 = function ($f, $parte) { $z = new ZipArchive(); $r = ''; if ($f && $z->open($f) === true) { $r = (string)$z->getFromName($parte); $z->close(); } return $r; };
$f62 = $dopo62(); $d62 = $zip62($f62, 'word/document.xml'); $h62 = $zip62($f62, 'word/header1.xml'); $ft62 = $zip62($f62, 'word/footer1.xml'); @unlink($f62);
$tx62 = html_entity_decode(strip_tags(str_replace('</w:p>', "\n", $d62)));
prova(@simplexml_load_string($d62) !== false && @simplexml_load_string($h62) !== false && @simplexml_load_string($ft62) !== false, "verbale: XML di corpo, testata e piede ben formato (Word lo apre senza errori)");
$cogn62 = mb_strtoupper((string)pratica($conn, $pb)['cognome']);
$tx62 = mb_substr($tx62, (int)mb_strpos($tx62, 'dichiara aperta')); // dopo l'elenco dell'o.d.g. dell'incipit
$ip = mb_strpos($tx62, $cogn62);
prova(mb_strpos($tx62, 'Il Coordinatore comunica che tutto procede.') !== false && mb_strpos($tx62, 'Seconda riga.') !== false && mb_strpos($tx62, "1. Comunicazioni") < mb_strpos($tx62, 'comunica che') && $ip > mb_strpos($tx62, '2. Pratiche studenti') && $ip < mb_strpos($tx62, '3. Orario lezioni'),
      "verbale: il testo di ogni punto sotto il suo titolo e le pratiche sotto il punto delle pratiche");
prova(str_contains($tx62, '• Primo orario') || (str_contains($tx62, '•') && str_contains($tx62, 'Primo orario') && str_contains($tx62, 'Secondo orario')), "verbale: elenchi puntati nel testo del punto");
prova(str_contains($tx62, '4. Varie ed eventuali') && str_contains($tx62, '…'), "verbale: un punto senza testo resta con «…» da completare");
prova(str_contains($h62, 'Verbale n. 8') && str_contains($h62, '07 settembre 2026') && str_contains($ft62, 'Dott.ssa Segretaria') && str_contains($ft62, 'Segretario verbalizzante') && str_contains($ft62, 'Prof. Coordinatore'), "testata «Verbale n. 8 – 07 settembre 2026» e firmatari nel piè di pagina");
// Il testo scritto resta se l'o.d.g. cambia ordine; due punti per le pratiche: ognuna sotto quello scelto
$sv62->salva($id62, ['organo' => 'Consiglio Unificato di prova', 'anno_accademico' => '2025/2026', 'data' => '2026-09-07', 'ora_inizio' => '15:30', 'ora_fine' => '', 'luogo' => "l'aula L3", 'odg' => "Orario lezioni\nComunicazioni\nPratiche studenti\nPratiche di laurea\nVarie ed eventuali",
                       'presenze' => '', 'segretario' => 'Dott.ssa Segretaria', 'coordinatore' => 'Prof. Coordinatore', 'consiglio_id' => null, 'numero_verbale' => '8']);
$p62 = $pu->punti(seduta_didattica($conn, $id62));
prova(array_column($p62, 'titolo') === ['Orario lezioni', 'Comunicazioni', 'Pratiche studenti', 'Pratiche di laurea', 'Varie ed eventuali'] && str_contains($p62[0]['testo'], 'Primo orario') && str_contains($p62[1]['testo'], 'comunica'), "o.d.g. riordinato: i testi restano ai loro punti");
$pu->salva(seduta_didattica($conn, $id62), [], ['0' => 'testo'], [$pb => (string)$p62[3]['id']]);
$f62 = $dopo62(); $tx62 = html_entity_decode(strip_tags(str_replace('</w:p>', "\n", $zip62($f62, 'word/document.xml')))); @unlink($f62);
$tx62 = mb_substr($tx62, (int)mb_strpos($tx62, 'dichiara aperta'));
$ip = mb_strpos($tx62, $cogn62);
prova($ip !== false && $ip > mb_strpos($tx62, '4. Pratiche di laurea') && $ip < mb_strpos($tx62, '5. Varie ed eventuali'), "pratica assegnata al secondo punto delle pratiche: compare lì e non nel primo");
$conn->query("DELETE FROM didattica_sedute_punti WHERE seduta_id = $id62"); $conn->query("UPDATE pratiche SET seduta_id = NULL, punto_id = NULL WHERE id = $pb");
prova(count($pu->punti(seduta_didattica($conn, $id62))) === 5, "sedute senza punti (precedenti): si ricavano dall'o.d.g. alla prima apertura");
$sv62->elimina($id62);
prova((int)$conn->query("SELECT COUNT(*) c FROM didattica_sedute_punti WHERE seduta_id = $id62")->fetch_assoc()['c'] === 0, "seduta eliminata: spariscono anche i suoi punti");

sezione("Archivio verbali: importazione di PDF vecchi, dati dal nome del file, ricerca nel testo");
use App\Didattica\ArchivioVerbali as AV;
$g1 = AV::indovina('2024-2025/Verbale_08_2025-01-20.pdf');
prova($g1 === ['data' => '2025-01-20', 'numero' => '8', 'anno_accademico' => '2024/2025'], "nome del file e cartella: data, numero e anno accademico", json_encode($g1));
prova(AV::indovina('Verbale n. 12 del 07-09-2026.pdf') === ['data' => '2026-09-07', 'numero' => '12', 'anno_accademico' => null] && AV::indovina('verbale 7 settembre 2026.pdf')['data'] === '2026-09-07' && AV::indovina('Verbale_07092026.PDF')['data'] === '2026-09-07'
      && AV::indovina('scansione.pdf') === ['data' => null, 'numero' => null, 'anno_accademico' => null] && AV::indovina('Verbale 31-02-2026.pdf')['data'] === null && AV::indovina('Verbale 07-09-2026.pdf')['numero'] === null, "varianti del nome (numero, mese in lettere, data compatta) e nomi senza dati o con date impossibili");
prova(AV::annoAccademico('2026-09-07') === '2026/2027' && AV::annoAccademico('2026-08-31') === '2025/2026' && AV::dataDa('Il giorno 07 del mese di settembre 2026 alle ore 15:30') === '2026-09-07' && AV::numeroDa('Verbale n. 8 – 07 settembre 2026') === '8', "anno accademico dalla data; data e numero dal testo del verbale");
$arch = \App\Core\App::per($conn)->get(AV::class);
$sorgente = RADICE_SITO . '/documentazione/Pratiche_verbali_iter.pdf';
$tmp_a = sys_get_temp_dir() . '/arch_a.pdf'; copy($sorgente, $tmp_a);
$r1 = $arch->importa($tmp_a, 'Verbale_08_2026-09-07.pdf', '2025-2026/Verbale_08_2026-09-07.pdf', null, 'Consiglio unificato di prova');
prova($r1['esito'] === 'ok' && $r1['completo'] === true, "verbale importato con data e organo: completo", json_encode($r1));
$v1 = seduta_didattica($conn, (int)$r1['id']);
prova($v1['origine'] === 'importato' && $v1['data'] === '2026-09-07' && $v1['numero_verbale'] === '8' && $v1['anno_accademico'] === '2025/2026' && str_contains($v1['testo_ricerca'], 'iter completo') && is_file((string)$arch->percorsoFile($v1)), "dati ricavati, testo del PDF letto, file in cartella protetta", substr((string)$v1['testo_ricerca'], 0, 80));
prova($arch->importa($tmp_a, 'copia.pdf', 'copia.pdf', null, 'X')['esito'] === 'doppio', "lo stesso PDF caricato due volte non crea un doppione");
$tmp_b = sys_get_temp_dir() . '/arch_b.pdf'; file_put_contents($tmp_b, "non sono un pdf");
prova($arch->importa($tmp_b, 'finto.pdf', 'finto.pdf', null, 'X')['esito'] === 'errore', "un file che non è un PDF viene rifiutato");
$tmp_c = sys_get_temp_dir() . '/arch_c.pdf'; file_put_contents($tmp_c, "%PDF-1.4\n" . str_repeat('x', 300) . "\n%%EOF");
$r2 = $arch->importa($tmp_c, 'scansione.pdf', 'scansione.pdf', null, '');
prova($r2['esito'] === 'ok' && $r2['completo'] === false, "PDF senza data né organo: archiviato ma da completare", json_encode($r2));
$rep_arch = \App\Core\App::per($conn)->get(\App\Didattica\SedutaRepository::class);
prova(array_column($rep_arch->archivio(['q' => 'iter completo'], null), 'id') === [(string)$r1['id']] && array_column($rep_arch->archivio(['q' => 'itercompleto'], null), 'id') === [(string)$r1['id']] && !$rep_arch->archivio(['q' => 'parolainesistente'], null), "ricerca nel testo del PDF (anche senza spazi) e nessun risultato per parole che non ci sono");
prova(array_column($rep_arch->archivio(['tipo' => 'incompleto'], null), 'id') === [(string)$r2['id']] && count($rep_arch->archivio(['tipo' => 'importato'], null)) === 2 && count($rep_arch->archivio(['anno_accademico' => '2025/2026'], null)) === 1, "filtri: da completare, importati, anno accademico");
prova(!in_array((string)$r1['id'], array_column($rep_arch->elenco(null), 'id'), true), "i verbali importati non compaiono tra le sedute del portale");
$cons_arch = (int)array_key_first(consigli_didattica($conn));
prova($arch->salvaDati((int)$r2['id'], ['consiglio_id' => $cons_arch, 'data' => '2024-11-05', 'numero_verbale' => '3', 'odg' => "Comunicazioni\nPratiche"], consigli_didattica($conn)) === null
      && ($v2 = seduta_didattica($conn, (int)$r2['id'])) && $v2['data'] === '2024-11-05' && $v2['anno_accademico'] === '2024/2025' && $v2['consiglio_id'] !== null && $v2['organo'] !== '' && $arch->salvaDati((int)$r2['id'], ['organo' => ''], []) !== null, "dati completati dopo l'importazione (consiglio, data, anno accademico dalla data); senza organo non si salva");
prova(count($rep_arch->archivio(['q' => 'Pratiche'], [$cons_arch])) >= 1 && !$rep_arch->archivio(['q' => 'Pratiche'], [$cons_arch + 999]), "un referente vede solo i verbali del proprio consiglio");
$pdf_nuovo = (new \App\Infrastructure\Pdf\Documento(['piede' => 'prova']));
$pdf_nuovo->paragrafo('Il Consiglio approva all\'unanimità il calendario degli esami.', ['dopo' => 4]);
$tmp_d = sys_get_temp_dir() . '/arch_d.pdf'; file_put_contents($tmp_d, $pdf_nuovo->pdf());
prova(str_contains((new \App\Infrastructure\Pdf\TestoPdf())->estrai((string)file_get_contents($tmp_d)), 'calendario degli esami'), "testo letto anche dai PDF generati dal portale");
$f_vecchio = (string)$arch->percorsoFile($v1);
$arch->elimina($v1);
prova(seduta_didattica($conn, (int)$r1['id']) === null && !is_file($f_vecchio), "verbale importato eliminato con il suo file");
$arch->elimina(seduta_didattica($conn, (int)$r2['id']));
foreach ([$tmp_a, $tmp_b, $tmp_c, $tmp_d] as $t_) @unlink($t_);

sezione("Pagina pubblica dei verbali con password: accessi, pubblicazione, omissis, registro");
use App\Didattica\ServizioVerbaliPubblici as VP;
$vp = \App\Core\App::per($conn)->get(VP::class);
$rep_vp = \App\Core\App::per($conn)->get(\App\Didattica\AccessoVerbaliRepository::class);
$cons_vp = consigli_didattica($conn); $cA = (int)array_key_first($cons_vp); $cB = (int)array_keys($cons_vp)[1];
prova(preg_match('/^[A-Za-z2-9]{4}-[A-Za-z2-9]{4}-[A-Za-z2-9]{4}$/', VP::generaPassword()) === 1 && VP::generaPassword() !== VP::generaPassword() && VP::ipAnonimo('151.20.33.77') === '151.20.33.0' && VP::ipAnonimo('2001:db8:1:2:3:4:5:6') === '2001:db8:1:2::' && VP::ipAnonimo('non un ip') === '', "password leggibili a gruppi; IP anonimizzato");
prova($vp->crea('', '', null, [$cA], false, $cons_vp, 'Ufficio')[0] !== null && $vp->crea('Esterni', '', null, [], false, $cons_vp, 'Ufficio')[0] !== null && $vp->crea('Esterni', 'corta', null, [$cA], false, $cons_vp, 'Ufficio')[0] !== null && $vp->crea('Esterni', '', null, [99999], false, $cons_vp, 'Ufficio')[0] !== null, "accesso senza nome, senza consigli, con password troppo corta o consiglio inesistente: rifiutato");
[$e1, $id1, $pw1] = $vp->crea('Rappresentanti A', '', null, [$cA], false, $cons_vp, 'Ufficio didattico');
[$e2, $id2, $pw2] = $vp->crea('Tutti i consigli', 'password-scelta-lunga', null, [], true, $cons_vp, 'Ufficio didattico');
$a1 = $rep_vp->perId($id1);
prova($e1 === null && $e2 === null && $pw2 === 'password-scelta-lunga' && $a1['password_hash'] !== $pw1 && password_verify($pw1, $a1['password_hash']) && $a1['ambiti_json'] === json_encode([$cA]) && $rep_vp->perId($id2)['ambiti_json'] === '["*"]', "accesso creato: password solo come impronta (hash), consigli salvati");
// Verbale firmato del consiglio A con un PDF vero
$dir_vp = RADICE_SITO . '/uploads/verbali/'; if (!is_dir($dir_vp)) mkdir($dir_vp, 0755, true);
file_put_contents($dir_vp . 'prova_pub.pdf', "%PDF-1.4\nverbale intero\n%%EOF");
$q("INSERT INTO didattica_sedute (id, consiglio_id, organo, data, anno_accademico, numero_verbale, verbale_stato, verbale_pdf) VALUES (701, $cA, 'Consiglio A', '2026-09-07', '2025/2026', '8', 'firmato', 'uploads/verbali/prova_pub.pdf')");
$q("INSERT INTO didattica_sedute (id, consiglio_id, organo, data, verbale_stato, verbale_pdf) VALUES (702, $cB, 'Consiglio B', '2026-09-08', 'firmato', 'uploads/verbali/prova_pub.pdf'), (703, $cA, 'Consiglio A bozza', '2026-09-09', 'segretario', 'uploads/verbali/prova_pub.pdf')");
$ac1 = $rep_vp->perId($id1); $ac2 = $rep_vp->perId($id2);
prova(!$vp->verbali($ac1) && $vp->file($ac1, 701) === null, "un verbale non pubblicabile non si vede né si scarica, nemmeno conoscendo l'indirizzo");
$sp = seduta_didattica($conn, 701);
prova($vp->pubblica($sp, 'integrale', false, null, 'Ufficio') !== null && $vp->pubblica(seduta_didattica($conn, 703), 'integrale', true, null, 'Ufficio') !== null && $vp->pubblica($sp, 'omissis', false, null, 'Ufficio') !== null, "si pubblica solo con la conferma «niente dati personali» o con il PDF con gli omissis, e solo se il verbale è firmato");
prova($vp->pubblica($sp, 'integrale', true, null, 'Ufficio') === null && array_map('intval', array_column($vp->verbali($ac1), 'id')) === [701] && !$vp->verbali(['ambiti_json' => json_encode([$cB])] + $ac1), "pubblicato: lo vede chi ha il consiglio giusto, non chi ha un altro consiglio");
$q("UPDATE didattica_sedute SET pubblicabile = 1 WHERE id IN (702, 703)");
prova(array_map('intval', array_column($vp->verbali($ac2), 'id')) === [702, 701] && array_map('intval', array_column($vp->verbali($ac1), 'id')) === [701], "«tutti i consigli» vede tutti i pubblicabili firmati; la bozza in firma non si vede mai", json_encode(array_column($vp->verbali($ac2), 'id')));
$f_pub = $vp->file($ac1, 701);
prova($f_pub && str_ends_with($f_pub['percorso'], 'prova_pub.pdf') && $f_pub['nome'] === 'Verbale_07_09_2026.pdf' && $vp->file($ac1, 702) === null && $vp->file($ac2, 703) === null, "download: nome e percorso corretti; consiglio non autorizzato e verbale non firmato rifiutati");
file_put_contents($dir_vp . 'omissis_tmp.pdf', "%PDF-1.4\nverbale con omissis\n%%EOF");
prova($vp->pubblica($sp, 'omissis', false, $dir_vp . 'omissis_tmp.pdf', 'Ufficio') === null && ($f_om = $vp->file($ac1, 701)) && str_contains((string)file_get_contents($f_om['percorso']), 'omissis') && str_starts_with(basename($f_om['percorso']), 'pubblico_701_'), "con la versione con omissis si scarica quella, non il PDF firmato");
file_put_contents($dir_vp . 'finto.txt', 'non pdf');
prova($vp->pubblica($sp, 'omissis', false, $dir_vp . 'finto.txt', 'Ufficio') !== null, "la versione con omissis deve essere un PDF");
$vp->ritira(seduta_didattica($conn, 701));
prova(!$vp->verbali($ac1) && !is_file($f_om['percorso']) && is_file($dir_vp . 'prova_pub.pdf'), "ritirato: sparisce dalla pagina e la versione con omissis viene cancellata (il PDF firmato resta)");
// Accesso con password
$vp->pubblica(seduta_didattica($conn, 701), 'integrale', true, null, 'Ufficio');
prova($vp->corrente() === null && $vp->accedi('password sbagliata') === 'Password non valida.' && $vp->corrente() === null, "password sbagliata: messaggio generico, nessun accesso");
prova($vp->accedi($pw1) === null && ($cur = $vp->corrente()) && (int)$cur['id'] === $id1 && array_map('intval', array_column($vp->verbali($cur), 'id')) === [701], "password giusta: entra con il suo accesso");
$rep_vp->imposta($id1, false);
prova($vp->corrente() === null, "accesso revocato: la sessione smette di valere subito");
$rep_vp->imposta($id1, true); $q("UPDATE verbali_accessi SET scade_il = '2020-01-01' WHERE id = $id1");
prova($vp->accedi($pw1) === 'Password non valida.' && $vp->corrente() === null, "accesso scaduto: la password non vale più");
$q("UPDATE verbali_accessi SET scade_il = NULL WHERE id = $id1");
$vp->accedi($pw1); $_SESSION[VP::CHIAVE]['ultimo'] = time() - 4000;
prova($vp->corrente() === null, "sessione ferma da più di un'ora: si deve rientrare");
$np = $vp->nuovaPassword($id1);
prova($np !== '' && $np !== $pw1 && $vp->accedi($pw1) !== null && $vp->accedi($np) === null, "nuova password: la vecchia smette di funzionare");
$reg = $rep_vp->registro(50);
$azioni_vp = array_column($reg, 'azione');
prova(in_array('accesso', $azioni_vp, true) && in_array('rifiutato', $azioni_vp, true) && in_array('scarica', $azioni_vp, true) && !str_contains(json_encode($reg), $pw1), "registro: accessi, tentativi rifiutati e scaricamenti (mai le password)");
for ($i = 0; $i < 10; $i++) $r_lim = $vp->accedi('tentativo ' . $i);
prova($r_lim === 'Troppi tentativi: riprova tra qualche minuto.' && $vp->accedi($np) === 'Troppi tentativi: riprova tra qualche minuto.', "troppi tentativi sbagliati: blocco temporaneo anche con la password giusta");
$vp->esci();
@unlink($dir_vp . 'prova_pub.pdf'); @unlink($dir_vp . 'omissis_tmp.pdf'); @unlink($dir_vp . 'finto.txt');

sezione("Iter: chi ha avuto la pratica la vede e la integra; email solo ai passaggi");
$q("INSERT INTO utenti (id, codice_fiscale, nome, cognome, email, ruolo_id) VALUES (99, 'TUTIRE80XXXXXXXX', 'Irene', 'Tutor', 'irene.int@unical.it', 5)");
$pj = crea_pratica($conn, $m503, $u73, [['etichetta' => 'Corso di studio', 'tipo' => 'corso_studio', 'valore' => 'Corso di laurea in Scienze naturali', 'file' => null, 'nome_file' => null]]);
$EMAIL = [];
assegna_pratica($conn, $pj, $op['internazionalizzazione'], 1, '', 50, 'Manager Marta');
prova(array_column($EMAIL, 'a') === ['irene.int@unical.it', 'nuovo@unical.it'] && str_contains($EMAIL[1]['oggetto'], 'passata a Internazionalizzazione'), "passaggio: email all'operatore e allo studente", json_encode(array_column($EMAIL, 'oggetto')));
assegna_pratica($conn, $pj, $op['referente_cdl'], 2, '', 50, 'Tutor Irene');
prova(operatori_pratica($conn, $pj) === [$op['internazionalizzazione'], $op['referente_cdl']], "gli operatori dei passi precedenti restano sulla pratica");
$EMAIL = [];
prova(richiedi_a_operatore($conn, $pj, $op['internazionalizzazione'], 'Manca il Learning Agreement firmato', 51, 'Referente Carlo') === null && array_column($EMAIL, 'a') === ['irene.int@unical.it'], "richiesta di integrazione all'operatore precedente");
prova(!in_array('nuovo@unical.it', array_column($EMAIL, 'a'), true) && (int)$conn->query("SELECT interno FROM pratiche_eventi WHERE pratica_id = $pj AND tipo = 'richiesta'")->fetch_assoc()['interno'] === 1, "la richiesta resta interna");
$EMAIL = [];
messaggio_pratica($conn, $pj, 'ufficio', 99, 'Ecco il Learning Agreement', null, false, 'attivita', 'Tutor Irene');
prova(in_array('carlo.cdl@unical.it', array_column($EMAIL, 'a'), true), "l'operatore precedente integra: avvisato chi ha la pratica in carico");
$EMAIL = [];
messaggio_pratica($conn, $pj, 'ufficio', 51, 'Nota tra noi', null, true, 'messaggio', 'Referente Carlo');
cambia_stato_pratica($conn, $pj, 'in_lavorazione', '', 51);
prova($EMAIL === [], "note interne e ritorno in lavorazione: nessuna email");
cambia_stato_pratica($conn, $pj, 'accolta', 'Approvata', 51);
prova(array_column($EMAIL, 'a') === ['nuovo@unical.it'], "esito: email allo studente");

sezione("Tutorato: lettera di incarico, conferma con SPID/CIE, firme PAdES, protocollo");
// Firma PAdES di prova: revisione incrementale del PDF con il dizionario /Sig e una firma CMS staccata (come i programmi di firma)
$crea_cert = function (string $cn, string $cf) {
    $k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $c = openssl_csr_sign(openssl_csr_new(['commonName' => $cn, 'serialNumber' => 'TINIT-' . $cf], $k, ['digest_alg' => 'sha256']), null, $k, 30, ['digest_alg' => 'sha256']);
    openssl_x509_export($c, $pem); openssl_pkey_export($k, $kp);
    return [$pem, $kp];
};
$firma_pades = function (string $pdf, array $cert) {
    preg_match('/startxref\s+(\d+)\s+%%EOF\s*$/', $pdf, $m); $prev = (int)$m[1];
    preg_match_all('#/Size (\d+)#', $pdf, $mm); $n = (int)end($mm[1]); preg_match('#/Root (\d+) 0 R#', $pdf, $r);
    $ph = str_repeat('0', 12000);
    $obj = "$n 0 obj\n<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /ETSI.CAdES.detached /ByteRange [0 AAAAAAAAAA BBBBBBBBBB CCCCCCCCCC] /Contents <$ph> /M (D:" . date('YmdHis') . ") >>\nendobj\n";
    $off = strlen($pdf); $nuovo = $pdf . $obj;
    $xref = strlen($nuovo);
    $nuovo .= "xref\n0 1\n0000000000 65535 f \n$n 1\n" . sprintf('%010d', $off) . " 00000 n \ntrailer\n<< /Size " . ($n + 1) . " /Root {$r[1]} 0 R /Prev $prev >>\nstartxref\n$xref\n%%EOF\n";
    $b = strpos($nuovo, '<' . $ph); $c = $b + strlen($ph) + 2; $d = strlen($nuovo) - $c;
    $nuovo = str_replace(['AAAAAAAAAA', 'BBBBBBBBBB', 'CCCCCCCCCC'], [sprintf('%010d', $b), sprintf('%010d', $c), sprintf('%010d', $d)], $nuovo);
    $fi = tempnam(sys_get_temp_dir(), 'pi'); $fo = tempnam(sys_get_temp_dir(), 'po');
    file_put_contents($fi, substr($nuovo, 0, $b) . substr($nuovo, $c));
    openssl_cms_sign($fi, $fo, $cert[0], $cert[1], [], OPENSSL_CMS_DETACHED | OPENSSL_CMS_BINARY, OPENSSL_ENCODING_DER);
    $hex = bin2hex((string)file_get_contents($fo)); @unlink($fi); @unlink($fo);
    return substr_replace($nuovo, str_pad($hex, strlen($ph), '0'), $b + 1, strlen($ph));
};
[$bt, $eb] = salva_bando_tutorato($conn, ['titolo' => 'Tutorato I semestre', 'anno_accademico' => '2026/2027', 'decreto_bando' => '123/2026', 'decreto_bando_data' => '2026-09-01', 'decreto_commissione' => '150/2026', 'decreto_commissione_data' => '2026-09-20',
                                          'direttore_persona_id' => 'ord.prova', 'direttore_cf' => 'RDNTTO60A01D086Z', 'operatore_id' => $op['carriere']], 1);
prova($bt > 0 && $eb === null && bando_tutorato($conn, $bt)['direttore_email'] === 'otto.ord@unical.it' && bando_tutorato($conn, $bt)['direttore_nome'] === 'Otto Ordinario', "bando con il direttore dall'anagrafe");
prova(salva_bando_tutorato($conn, ['titolo' => 'Senza decreto'], 1)[1] !== null, "bando senza decreto rifiutato");
$dati_inc = ['bando_id' => $bt, 'genere' => 'F', 'cognome' => 'Nuovo', 'nome' => 'Arrivata', 'luogo_nascita' => 'Cosenza', 'data_nascita' => '2001-04-04', 'comune_residenza' => 'Rende', 'indirizzo' => 'Via Roma', 'civico' => '3',
             'codice_fiscale' => 'nvorrv01d44d086x', 'email' => 'nuovo@unical.it', 'attivita' => "Tutorato di Chimica\nprimo anno", 'ore' => '40', 'periodo' => 'dal 01/11/2026 al 28/02/2027', 'compenso' => '1.250,50',
             'docente_persona_id' => 'ref.prova', 'data_lettera' => '2026-10-10'];
prova(salva_incarico_tutorato($conn, ['codice_fiscale' => 'XYZ'] + $dati_inc)[1] !== null, "codice fiscale del vincitore controllato");
[$it, $ei] = salva_incarico_tutorato($conn, $dati_inc);
$inc = incarico_tutorato($conn, $it);
prova($it > 0 && $ei === null && $inc['stato'] === 'bozza' && $inc['codice_fiscale'] === 'NVORRV01D44D086X' && (float)$inc['compenso'] === 1250.5 && $inc['docente_email'] === 'rita.ref@unical.it', "lettera in bozza con il docente dall'anagrafe", (string)$ei);
$dl = dati_lettera_incarico($inc);
prova($dl['TITOLO'] === 'Dott.ssa' && $dl['NATO'] === 'nata' && $dl['VINCITORE'] === 'vincitrice' && $dl['COMPENSO'] === '€ 1.250,50' && $dl['DECRETO_BANDO'] === '123/2026 del 01/09/2026', "dati della lettera al femminile, decreti e compenso");
$wd = docx_lettera_incarico($inc); $xw = '';
if ($wd && ($z = new ZipArchive())->open($wd) === true) { $xw = (string)$z->getFromName('word/document.xml'); $z->close(); } @unlink($wd);
prova($xw !== '' && (new DOMDocument())->loadXML($xw) && !str_contains($xw, '{{') && str_contains($xw, 'Arrivata Nuovo') && str_contains($xw, '150/2026 del 20/09/2026') && str_contains($xw, '<w:br/>'), "Word precompilato dal modello del Dipartimento, nessun segnaposto rimasto");
[$pdf0, $spazi] = pdf_lettera_incarico($inc);
prova(str_starts_with($pdf0, '%PDF-1.7') && str_ends_with(rtrim($pdf0), '%%EOF') && isset($spazi['docente'], $spazi['direttore']) && firme_pades_pdf($pdf0) === [], "PDF della lettera con gli spazi per le firme visibili");
$EMAIL = [];
prova(invia_incarico_studente($conn, $it, 'Carla') === null && incarico_tutorato($conn, $it)['stato'] === 'inviata' && array_column($EMAIL, 'a') === ['nuovo@unical.it'] && str_contains($EMAIL[0]['corpo'], 'incarico.php?t='), "lettera inviata: email allo studente con il link");
prova(salva_incarico_tutorato($conn, ['id' => $it] + $dati_inc)[1] !== null, "dopo l'invio i dati non cambiano");
$inc = incarico_tutorato($conn, $it);
prova(incarico_per_token($conn, 'studente', $inc['token_studente'])['id'] == $it && incarico_per_token($conn, 'docente', $inc['token_studente']) === null && incarico_per_token($conn, 'studente', 'x') === null, "link personale dello studente");
$stud = ['id' => 73, 'codice_fiscale' => 'NVORRV01D44D086X'];
prova(conferma_incarico_studente($conn, $it, $stud, ['metodo' => 'ateneo']) !== null, "con le credenziali di Ateneo non si conferma (serve SPID o CIE)");
prova(conferma_incarico_studente($conn, $it, ['id' => 5, 'codice_fiscale' => 'ALTRAPERSONA0000'], ['metodo' => 'spid']) !== null, "un'altra persona non conferma");
$EMAIL = [];
prova(segnala_errore_incarico($conn, $it, 'Il civico è 5') === null && in_array('carla.car@unical.it', array_column($EMAIL, 'a'), true), "segnalazione di un errore: email all'operatore del bando");
// Se nel .env c'è CIFRATURA_CHIAVE la lettera si accetta solo dopo la dichiarazione individuale (passi 2 e 3)
$dich_sv = \App\Core\App::per($conn)->get(\App\Tutorato\ServizioDichiarazione::class);
if ($dich_sv->richiesta()) {
    $li = incarico_tutorato($conn, $it);
    $e2 = $dich_sv->salvaPasso($it, 2, ['telefono' => '333 1234567', 'email' => 'nuovo@unical.it', 'residenza_invariata' => '1', 'non_dipendente' => '1', 'irpef' => '23', 'iban' => 'IT60X0542811101000000123456', 'banca' => 'Banca di prova'], $li)[0];
    $e3d = $dich_sv->salvaPasso($it, 3, ['inps' => 'gs35', 'inail' => ['didattica']], $li)[0];
    prova(!$e2 && !$e3d && $dich_sv->stato($it)['completa'], 'dichiarazione individuale compilata (dati bancari cifrati)');
}
$EMAIL = [];
$meta_spid = ['metodo' => 'spid', 'livello' => 2, 'idp' => 'https://id.lepida.it/idp/shibboleth', 'contesto' => 'https://www.spid.gov.it/SpidL2', 'spid_code' => 'LEPI0001ABCD', 'istante' => date('c'), 'sessione' => '_abc', 'ip' => '10.0.0.1'];
$e_conf = conferma_incarico_studente($conn, $it, $stud, $meta_spid);
prova($e_conf === null, "conferma con SPID", (string)$e_conf);
$inc = incarico_tutorato($conn, $it); $fs = json_decode($inc['studente_firma_json'], true);
$pdf1 = (string)file_get_contents(pdf_corrente_incarico($inc));
prova($inc['stato'] === 'confermata' && $fs['spid_code'] === 'LEPI0001ABCD' && $fs['livello'] === 2 && $fs['sha256_pdf'] === hash('sha256', $pdf1) && $fs['impronta'] === impronta_dati_incarico($inc), "dati SPID e impronte registrati con la conferma");
prova(array_column($EMAIL, 'a') === ['rita.ref@unical.it'] && str_contains($EMAIL[0]['corpo'], 'firma_incarico.php?t='), "email al docente responsabile per la firma");
prova(conferma_incarico_studente($conn, $it, $stud, $meta_spid) !== null, "una lettera si conferma una volta sola");
prova(firmatario_incarico($inc, 'docente', $u98) && !firmatario_incarico($inc, 'direttore', $u98) && !firmatario_incarico($inc, 'docente', $u73), "la pagina di firma riconosce il docente dall'accesso");
// Firme: un .p7m (CAdES), un PDF senza firma e un PDF modificato sono rifiutati; il PAdES del docente passa
$cert_doc = $crea_cert('Rita Referente', 'RFRRTI80A41D086K');
prova(registra_firma_incarico($conn, $it, 'docente', "0\x82\x01\x00p7m") !== null && registra_firma_incarico($conn, $it, 'docente', $pdf1) !== null, "CAdES (.p7m) e PDF senza firma rifiutati");
prova(registra_firma_incarico($conn, $it, 'direttore', $firma_pades($pdf1, $cert_doc)) !== null, "il direttore non firma prima del docente");
$pdf_doc = $firma_pades($pdf1, $cert_doc);
$alterato = $pdf_doc; $alterato[strlen($pdf1) + 20] = 'X';
prova(registra_firma_incarico($conn, $it, 'docente', $firma_pades(str_replace('%%EOF', '%%EOF ', $pdf1), $cert_doc)) !== null && verifica_pdf_firmato($pdf1, $alterato)[0] !== null, "PDF modificato o firma non integra rifiutati");
prova(analizza_firma_pdf($pdf_doc)['integra'] === true && analizza_firma_pdf($pdf_doc)['cf'] === 'RFRRTI80A41D086K' && analizza_firma_pdf($pdf_doc)['nome'] === 'Rita Referente', "firma PAdES integra, firmatario e codice fiscale dal certificato");
$EMAIL = [];
prova(registra_firma_incarico($conn, $it, 'docente', $pdf_doc, 'caricato') === null && incarico_tutorato($conn, $it)['stato'] === 'firmata_docente' && array_column($EMAIL, 'a') === ['otto.ord@unical.it'], "firma del docente: la lettera va al direttore", json_encode(array_column($EMAIL, 'a')));
$inc = incarico_tutorato($conn, $it);
prova(registra_firma_incarico($conn, $it, 'direttore', $firma_pades($pdf_doc, $crea_cert('Altra Persona', 'LTRPRS70A01D086Q'))) !== null, "firma di un certificato diverso da quello del direttore rifiutata");
$pdf_fin = $firma_pades($pdf_doc, $crea_cert('Otto Ordinario', 'RDNTTO60A01D086Z'));
$EMAIL = [];
prova(registra_firma_incarico($conn, $it, 'direttore', $pdf_fin, 'firma remota Aruba') === null && incarico_tutorato($conn, $it)['stato'] === 'firmata' && in_array('carla.car@unical.it', array_column($EMAIL, 'a'), true), "firma del direttore: email all'operatore per il protocollo");
prova(in_array('nuovo@unical.it', array_column($EMAIL, 'a'), true) && str_contains(implode(' ', array_column($EMAIL, 'corpo')), 'registro_tutorato.php?id=' . $it), "firma del direttore: il tutor riceve il link al registro delle attività");
prova(count(firme_pades_pdf((string)file_get_contents(pdf_corrente_incarico(incarico_tutorato($conn, $it))))) === 2, "PDF finale con le due firme PAdES");
$EMAIL = [];
prova(protocolla_incarico($conn, $it, '7777/2026', '2026-10-20', true, 'Carla') === null && incarico_tutorato($conn, $it)['stato'] === 'protocollata' && ($EMAIL[0]['a'] ?? '') === 'nuovo@unical.it', "protocollo registrato e copia firmata allo studente");
$ev_t = array_column($conn->query("SELECT tipo FROM tutorato_eventi WHERE incarico_id = $it ORDER BY id")->fetch_all(MYSQLI_ASSOC), 'tipo');
prova($ev_t === ['creata', 'inviata', 'errore', 'confermata', 'firma_docente', 'firma_direttore', 'protocollata', 'copia'], "storico completo della lettera", json_encode($ev_t));

sezione("Tutorato: registro delle attività, fine attività firmata dal docente, promemoria e solleciti");
prova(salva_dati_fine_attivita($conn, $it, ['docente_titolo' => 'Prof.ssa', 'insegnamento_docente' => 'Chimica generale', 'corso_laurea' => 'Scienze Biologiche', 'data_inizio' => date('Y-m-d', strtotime('-30 days')), 'data_fine' => date('Y-m-d', strtotime('+3 days'))]) === null
      && incarico_tutorato($conn, $it)['docente_titolo'] === 'Prof.ssa', "dati della fine attività salvati dall'operatore");
prova(salva_dati_fine_attivita($conn, $it, ['data_inizio' => '2026-12-01', 'data_fine' => '2026-11-01']) !== null, "periodo al contrario rifiutato");
$ieri = date('Y-m-d', strtotime('-1 day'));
prova(aggiungi_registro($conn, $it, date('Y-m-d', strtotime('+1 day')), '2', 'Esercitazioni') !== null, "registro: data nel futuro rifiutata");
prova(aggiungi_registro($conn, $it, date('Y-m-d', strtotime('-40 days')), '2', 'Esercitazioni') !== null, "registro: data prima dell'inizio rifiutata");
prova(aggiungi_registro($conn, $it, $ieri, '0,3', 'Esercitazioni') !== null && aggiungi_registro($conn, $it, $ieri, '13', 'Esercitazioni') !== null && aggiungi_registro($conn, $it, $ieri, '2', '  ') !== null, "registro: ore (mezz'ore, max 12) e attività controllate");
prova(aggiungi_registro($conn, $it, $ieri, '4,5', 'Esercitazioni di stechiometria') === null && aggiungi_registro($conn, $it, date('Y-m-d', strtotime('-3 days')), '12', 'Ricevimento studenti') === null
      && aggiungi_registro($conn, $it, date('Y-m-d', strtotime('-5 days')), '12', 'Preparazione esame') === null, "registro: tre giorni di attività segnati dal tutor");
prova(aggiungi_registro($conn, $it, $ieri, '12', 'Troppe ore') !== null, "registro: non si superano le ore dell'incarico");
$reg = registro_incarico($conn, $it);
prova(count($reg) === 3 && ore_registro($reg)['totale'] === 28.5 && $reg[0]['data'] < $reg[2]['data'], "registro in ordine di data con il totale delle ore");
$ir_t = incarichi_registro($conn, $stud); $ir_d = incarichi_registro($conn, $u98);
prova(($ir_t[0]['_ruolo'] ?? '') === 'tutor' && ($ir_d[0]['_ruolo'] ?? '') === 'docente' && incarichi_registro($conn, $u73) === [], "registro visibile al tutor e al docente responsabile, non ad altri");
prova(togli_registro($conn, $it, (int)$reg[1]['id']) === null && count(registro_incarico($conn, $it)) === 2, "il tutor toglie una riga non ancora approvata");
prova(conferma_fine_attivita($conn, $it)[1] !== null, "senza la fine dichiarata e con ore da approvare la fine non si conferma");
$EMAIL = [];
prova(richiedi_fine_attivita($conn, $it) === null && incarico_tutorato($conn, $it)['fine_stato'] === 'richiesta' && array_column($EMAIL, 'a') === ['rita.ref@unical.it'] && str_contains($EMAIL[0]['corpo'], 'Prof.ssa Rita'), "il tutor dichiara concluse le attività: email al docente");
$reg = registro_incarico($conn, $it);
prova(decidi_registro($conn, $it, 'respinta', [(int)$reg[1]['id']], 'Non pertinente') === 1 && decidi_registro($conn, $it, 'approvata') === 1, "il docente respinge una riga e approva le altre");
[$tok_f, $ef] = conferma_fine_attivita($conn, $it, 'Rita');
$inc = incarico_tutorato($conn, $it);
prova($ef === null && $inc['fine_stato'] === 'da_firmare' && (float)$inc['ore_approvate'] === 12.0 && incarico_per_token($conn, 'fine', (string)$tok_f)['id'] == $it, "fine confermata: dichiarazione con le ore approvate e link di firma", (string)$ef);
prova(aggiungi_registro($conn, $it, $ieri, '1', 'Dopo la fine') !== null, "dopo la conferma il registro è chiuso");
$df = dati_fine_attivita($inc);
prova($df['DOCENTE'] === 'Prof.ssa Rita Referente' && $df['SOTTOSCRITTO'] === 'La sottoscritta' && $df['INSEGNAMENTO'] === 'Chimica generale' && $df['CORSO'] === 'Scienze Biologiche' && $df['ORE'] === '12' && $df['TUTOR'] === 'Dott.ssa Arrivata Nuovo' && $df['DECRETO_BANDO'] === '123/2026 del 01/09/2026', "dichiarazione di fine attività compilata con i dati dell'operatore");
$pdf_f = (string)file_get_contents(pdf_fine_corrente($inc));
[, $spazi_f] = pdf_fine_attivita($inc, registro_incarico($conn, $it), 12.0);
prova(str_starts_with($pdf_f, '%PDF') && isset($spazi_f['docente']) && firme_pades_pdf($pdf_f) === [], "PDF della dichiarazione con lo spazio per la firma del docente");
$conn->query("UPDATE tutorato_incarichi SET docente_cf = 'RFRRTI80A41D086K' WHERE id = $it");
$e1 = registra_firma_fine($conn, $it, $firma_pades($pdf_f, $crea_cert("Altra Persona", "LTRPRS70A01D086Q"))); $e2 = registra_firma_fine($conn, $it, $pdf_f);
prova($e1 !== null && $e2 !== null, "dichiarazione: firma di un altro certificato o senza firma rifiutata", json_encode([$e1, $e2]));
$EMAIL = [];
$e3 = registra_firma_fine($conn, $it, $firma_pades($pdf_f, $cert_doc), "caricato");
prova($e3 === null && incarico_tutorato($conn, $it)['fine_stato'] === 'firmata'
      && in_array('carla.car@unical.it', array_column($EMAIL, 'a'), true) && in_array('nuovo@unical.it', array_column($EMAIL, 'a'), true), "firma del docente: avviso «attività completate» all'operatore e al tutor");
$m_op = array_values(array_filter($EMAIL, fn($e) => $e['a'] === 'carla.car@unical.it'))[0] ?? [];
prova(str_starts_with($m_op['oggetto'] ?? '', 'Fine attività firmata') && str_contains($m_op['corpo'] ?? '', 'Preparazione esame') && !str_contains($m_op['corpo'] ?? '', 'stechiometria') && count($m_op['allegati'] ?? []) === 1, "l'avviso ha il riepilogo delle ore approvate e il PDF firmato in allegato");
prova(protocolla_fine_attivita($conn, $it, '', 'Carla') !== null && protocolla_fine_attivita($conn, $it, '8888/2026', 'Carla') === null && incarico_tutorato($conn, $it)['fine_stato'] === 'protocollata', "protocollo della fine attività");
// Solleciti delle firme ferme: ogni FIRME_GIORNI_SOLLECITO giorni (5), al terzo lo sa l'operatore
$ns = duplica_incarico($conn, $it);
$conn->query("UPDATE tutorato_incarichi SET stato = 'confermata', confermata_il = NOW() - INTERVAL 10 DAY, token_docente = '" . str_repeat('c', 40) . "' WHERE id = $ns");
$EMAIL = [];
prova(promemoria_tutorato($conn) >= 1 && in_array('rita.ref@unical.it', array_column($EMAIL, 'a'), true) && str_contains(implode(' ', array_column($EMAIL, 'oggetto')), 'Sollecito'), "sollecito al docente per la firma ferma da 10 giorni");
$EMAIL = []; promemoria_tutorato($conn);
prova(!in_array('rita.ref@unical.it', array_column($EMAIL, 'a'), true), "nessun nuovo sollecito prima di 5 giorni");
$conn->query("UPDATE tutorato_incarichi SET solleciti = 2, sollecito_il = NOW() - INTERVAL 6 DAY WHERE id = $ns");
$EMAIL = []; promemoria_tutorato($conn);
prova(in_array('carla.car@unical.it', array_column($EMAIL, 'a'), true) && (int)incarico_tutorato($conn, $ns)['solleciti'] === 3, "terzo sollecito: avviso anche all'operatore");
$EMAIL = []; $conn->query("UPDATE tutorato_incarichi SET sollecito_il = NOW() - INTERVAL 30 DAY WHERE id = $ns"); promemoria_tutorato($conn);
prova(!in_array('rita.ref@unical.it', array_column($EMAIL, 'a'), true), "dopo tre solleciti non se ne mandano altri");
// Promemoria al tutor verso la fine del periodo
$nt = duplica_incarico($conn, $it);
$conn->query("UPDATE tutorato_incarichi SET stato = 'firmata', firmata_direttore_il = NOW() - INTERVAL 20 DAY, data_inizio = CURDATE() - INTERVAL 20 DAY, data_fine = CURDATE() + INTERVAL 2 DAY WHERE id = $nt");
$EMAIL = []; promemoria_tutorato($conn);
$m_t = array_values(array_filter($EMAIL, fn($e) => $e['a'] === 'nuovo@unical.it'))[0] ?? [];
prova(str_contains($m_t['corpo'] ?? '', 'Ho concluso le attività') && str_contains($m_t['corpo'] ?? '', 'registro_tutorato.php?id=' . $nt), "promemoria al tutor verso la fine del periodo");
$EMAIL = []; promemoria_tutorato($conn);
prova(!in_array('nuovo@unical.it', array_column($EMAIL, 'a'), true), "al tutor al massimo un promemoria a settimana");
annulla_incarico($conn, $ns, 'prova'); annulla_incarico($conn, $nt, 'prova');


$nd = duplica_incarico($conn, $it);
prova($nd > 0 && incarico_tutorato($conn, $nd)['stato'] === 'bozza' && annulla_incarico($conn, $nd, 'prova') === null && incarico_tutorato($conn, $nd)['token_studente'] === null, "copia in bozza e annullamento");
prova(firma_remota_aruba('%PDF', 'u', 'p', '1')[0] === null, "firma remota non configurata: messaggio, nessun invio");
// Conservazione: lettere protocollate da più di N mesi senza dati personali, PDF e registro
$f_fine = pdf_fine_corrente(incarico_tutorato($conn, $it));
prova(conserva_dati_tutorato($conn, 0) === 0 && conserva_dati_tutorato($conn, 12) === 0, "conservazione: niente da fare con 0 mesi o lettere recenti");
$conn->query("UPDATE tutorato_incarichi SET aggiornata_il = NOW() - INTERVAL 13 MONTH WHERE id = $it");
$ia = null;
prova(conserva_dati_tutorato($conn, 12) === 1 && ($ia = incarico_tutorato($conn, $it))['anonimizzata'] == 1 && $ia['codice_fiscale'] === '' && $ia['email'] === '' && $ia['studente_firma_json'] === null && $ia['cognome'] === 'Nuovo' && $ia['fine_protocollo'] === '8888/2026'
      && registro_incarico($conn, $it) === [] && $f_fine && !is_file($f_fine), "conservazione: dati personali, PDF e registro cancellati, restano nome e protocolli");
prova(conserva_dati_tutorato($conn, 12) === 0, "conservazione: una lettera si anonimizza una volta sola");
foreach (glob(RADICE_SITO . '/' . DIR_INCARICHI . 'TU-*.pdf') ?: [] as $f_t) @unlink($f_t);

sezione("Sedute: convocazione per email, giustificazione, importa componenti, verbale firmato in PAdES");
$d62 = date('Y-m-d', strtotime('+10 days'));
$q("INSERT INTO didattica_sedute (id, consiglio_id, organo, data, ora_inizio, luogo, odg, coordinatore) VALUES (62, $c1, 'Consiglio di prova', NULL, '15:00', 'aula L3', 'Comunicazioni\nPratiche studenti', 'Prof. Otto Ordinario')");
prova(invia_convocazione($conn, seduta_didattica($conn, 62), 'Convocazione', 'Testo', true)[2] !== null, "convocazione: serve la data della seduta");
$conn->query("UPDATE didattica_sedute SET data = '$d62' WHERE id = 62");
$s62 = seduta_didattica($conn, 62);
prova(invia_convocazione($conn, $s62, '', 'Testo', true)[2] !== null, "convocazione: oggetto e testo obbligatori");
$EMAIL = [];
[$nc, $nse, $ec] = invia_convocazione($conn, $s62, 'Convocazione {ORGANO} – {DATA}', TESTO_CONVOCAZIONE, true);
$m_ord = array_values(array_filter($EMAIL, fn($e) => $e['a'] === 'otto.ord@unical.it'))[0] ?? [];
prova($ec === null && $nc === 2 && $nse === 1 && ($m_ord['oggetto'] ?? '') === 'Convocazione Consiglio di prova – ' . date('d/m/Y', strtotime($d62)), "convocazione inviata ai componenti con l'email (1 senza email)", json_encode([$nc, $nse, $ec]));
prova(str_contains($m_ord['corpo'] ?? '', 'Gentile Ordinario Otto') && str_contains($m_ord['corpo'] ?? '', '1. Comunicazioni') && str_contains($m_ord['corpo'] ?? '', 'giustifica.php?t=') && str_contains($m_ord['corpo'] ?? '', 'aula L3'), "testo personalizzato con i segnaposti e il link per giustificare");
$EMAIL = [];
invia_convocazione($conn, $s62, 'Convocazione', TESTO_CONVOCAZIONE, false);
prova(!str_contains(implode(' ', array_column($EMAIL, 'corpo')), 'giustifica') && (int)$conn->query("SELECT COUNT(*) n FROM didattica_convocazioni WHERE seduta_id = 62")->fetch_assoc()['n'] === 2, "senza link: la riga del link sparisce e i link non si duplicano");
$tok_g = (string)$conn->query("SELECT c.token FROM didattica_convocazioni c JOIN didattica_consigli_persone p ON p.id = c.componente_id WHERE c.seduta_id = 62 AND p.email = 'otto.ord@unical.it'")->fetch_assoc()['token'];
prova(giustifica_assenza($conn, str_repeat('0', 40), '') !== null && giustifica_assenza($conn, 'x', '') !== null, "giustificazione: link non valido rifiutato");
prova(giustifica_assenza($conn, $tok_g, 'Missione a Roma') === null, "giustificazione dal link della convocazione");
$p62 = presenze_registrate($conn, seduta_didattica($conn, 62));
prova(count($p62) === 3 && riepilogo_presenze($p62) === ['P' => 2, 'AG' => 1, 'AI' => 0] && convocazioni_seduta($conn, 62)[(int)array_values(array_filter($p62, fn($r) => $r['stato'] === 'AG'))[0]['componente_id']]['motivo'] === 'Missione a Roma', "l'assenza giustificata è nelle presenze, gli altri restano presenti");
$conn->query("UPDATE didattica_sedute SET data = '2020-01-01' WHERE id = 62");
prova(giustifica_assenza($conn, $tok_g, '') !== null && invia_convocazione($conn, seduta_didattica($conn, 62), 'a', 'b', true)[2] !== null, "seduta passata: niente giustificazione né convocazione");
$conn->query("UPDATE didattica_sedute SET data = '$d62' WHERE id = 62");
// Importa i componenti in un altro consiglio
$c2 = (int)array_keys($cons)[1];
prova(importa_componenti_consiglio($conn, $c1, $c2) === 3 && importa_componenti_consiglio($conn, $c1, $c2) === 0 && array_column(persone_consiglio($conn, $c2), 'qualifica') === array_column(persone_consiglio($conn, $c1), 'qualifica'), "componenti importati da un altro consiglio con i gruppi, senza doppioni");
prova(importa_componenti_consiglio($conn, $c1, $c1) === 0, "non si importa un consiglio in se stesso");
$conn->query("DELETE FROM didattica_consigli_persone WHERE consiglio_id = $c2");
// Verbale in PDF firmato dal segretario e poi dal coordinatore
$pv = new PdfSemplice(['piede' => 'Verbale di prova']); $pv->paragrafo('Verbale della seduta di prova'); $pdf_v = $pv->pdf();
prova(invia_verbale_alla_firma($conn, 62, 'non un pdf', 'seg@unical.it', 'otto.ord@unical.it') !== null && invia_verbale_alla_firma($conn, 62, $pdf_v, 'seg@unical.it', '') !== null, "verbale: serve il PDF e le due email");
$EMAIL = [];
prova(invia_verbale_alla_firma($conn, 62, $pdf_v, 'Seg@Unical.it', 'otto.ord@unical.it') === null && seduta_didattica($conn, 62)['verbale_stato'] === 'segretario' && array_column($EMAIL, 'a') === ['seg@unical.it'] && str_contains($EMAIL[0]['corpo'], 'firma_verbale.php?t='), "verbale inviato alla firma: email al segretario");
$s62 = seduta_didattica($conn, 62);
prova(seduta_per_token_verbale($conn, (string)$s62['verbale_token'])['id'] == 62 && firmatario_verbale($s62, ['email' => 'seg@unical.it']) && !firmatario_verbale($s62, ['email' => 'otto.ord@unical.it']), "la pagina di firma riconosce il segretario dall'accesso");
prova(registra_firma_verbale($conn, 62, $pdf_v) !== null && registra_firma_verbale($conn, 62, "0\x82\x01\x00p7m") !== null, "verbale: PDF senza firma o CAdES rifiutati");
$cert_seg = $crea_cert('Sara Segretaria', 'SGRSRA80A41D086K');
$v1 = $firma_pades($pdf_v, $cert_seg);
$EMAIL = [];
prova(registra_firma_verbale($conn, 62, $v1, 'caricato') === null && seduta_didattica($conn, 62)['verbale_stato'] === 'coordinatore' && seduta_didattica($conn, 62)['verbale_token'] !== $s62['verbale_token'] && array_column($EMAIL, 'a') === ['otto.ord@unical.it'], "firma del segretario: il verbale passa al coordinatore (nuovo link)");
$EMAIL = [];
$v2 = $firma_pades($v1, $crea_cert('Otto Ordinario', 'RDNTTO60A01D086Z'));
prova(registra_firma_verbale($conn, 62, $v2, 'firma remota Aruba') === null && seduta_didattica($conn, 62)['verbale_stato'] === 'firmato' && in_array('rita.ref@unical.it', array_column($EMAIL, 'a'), true) && count($EMAIL[0]['allegati'] ?? []) === 1, "firma del coordinatore: verbale firmato inviato ai referenti con il PDF");
prova(is_file(RADICE_SITO . '/' . DIR_VERBALI . '.htaccess') && is_file(RADICE_SITO . '/' . DIR_INCARICHI . '.htaccess'), "cartelle dei verbali e delle lettere protette (.htaccess)");
prova(count(firme_pades_pdf((string)file_get_contents(percorso_verbale_pdf(seduta_didattica($conn, 62))))) === 2 && registra_firma_verbale($conn, 62, $v2) !== null, "PDF del verbale con le due firme PAdES; non si firma due volte");
$conn->query("UPDATE didattica_sedute SET verbale_stato = 'segretario', verbale_inviato_il = NOW() - INTERVAL 8 DAY, verbale_solleciti = 0, verbale_sollecito_il = NULL WHERE id = 62");
$EMAIL = [];
prova(solleciti_verbali($conn) === 1 && solleciti_verbali($conn) === 0 && ($EMAIL[0]['a'] ?? '') === 'seg@unical.it', "sollecito della firma del verbale ferma, una volta ogni 5 giorni");
foreach (glob(RADICE_SITO . '/' . DIR_VERBALI . 'verbale_62_*.pdf') ?: [] as $f_v) @unlink($f_v);
prova(conserva_dati_sedute($conn, 12) === 0, "conservazione: le convocazioni delle sedute recenti restano");
$conn->query("UPDATE didattica_sedute SET data = CURDATE() - INTERVAL 13 MONTH WHERE id = 62");
prova(conserva_dati_sedute($conn, 12) === 2 && convocazioni_seduta($conn, 62) === [] && riepilogo_presenze(presenze_registrate($conn, seduta_didattica($conn, 62)))['AG'] === 1, "conservazione: convocazioni cancellate, le presenze restano");

sezione("Convalida esami: domanda in PDF/A, protocollo, ufficio del corso, convalide, segreteria studenti");
// PDF/A: caratteri incorporati, XMP con la dichiarazione PDF/A e i metadati propri, profilo colore
$pa = new PdfSemplice(['pdfa' => true, 'info' => ['Title' => 'Prova àè €'], 'xmp' => ['codicePratica' => 'PR-X']]); $pa->paragrafo('Prova **àèìòù** “€”'); $pdf_a = $pa->pdf();
prova(str_contains($pdf_a, '<pdfaid:part>2</pdfaid:part>') && str_contains($pdf_a, '/FontFile2') && str_contains($pdf_a, '/OutputIntents') && str_contains($pdf_a, '<dibest:codicePratica>PR-X</dibest:codicePratica>') && !str_contains($pdf_a, '/Type1'), "PDF/A-2b: caratteri incorporati, XMP, metadati del portale, sRGB");
prova(!str_contains((new PdfSemplice())->pdf(), '/FontFile2'), "senza PDF/A il PDF resta con i caratteri standard");
// Uffici e modulo creati dallo schema
$u_prot = (int)array_key_first(uffici_di_tipo($conn, 'protocollo'));
$m_cv = db_riga($conn, "SELECT * FROM didattica_moduli WHERE chiave = 'convalida_esami'");
prova($u_prot && count(uffici_di_tipo($conn, 'cdl')) === count(consigli_didattica($conn)) && $m_cv && (int)$m_cv['attivo'] === 0 && config_domanda($m_cv)['pdf'], "Ufficio protocollo, un ufficio per consiglio e modulo di convalida (non attivo) creati");
prepara_flusso_convalide($conn);
prova(count(uffici_di_tipo($conn, 'protocollo')) === 1 && (int)db_valore($conn, "SELECT COUNT(*) FROM didattica_moduli WHERE chiave = 'convalida_esami'") === 1, "rieseguito non crea doppioni");
// Colonna del piano di studi
prova(valore_piano('A scelta') === [true, ''] && valore_piano('A scelta; elimina: Botanica') === [true, 'Botanica'] && valore_piano('sì') === [false, ''], "colonna del piano: «a scelta» ed eventuale insegnamento da eliminare");
$campi_cv = campi_modulo($m_cv['campi_json']);
$nome_c = fn(string $et) => array_values(array_filter($campi_cv, fn($c) => $c['etichetta'] === $et))[0]['nome'];
$_POST = []; $_FILES = [];
$_POST['campo_' . $nome_c('Ateneo della carriera precedente')] = 'Altro Ateneo';
$_POST['campo_' . $nome_c('Corso di studio')] = '';
$_POST['campo_' . $nome_c('Esami sostenuti in altro Ateneo')] = [['BIO-1', '', ''], ['Zoologia', 'Ecologia', ''], ['9', '6', ''], ['BIO/05', 'BIO/07', ''], ['28', '30', ''], ['2024-06-20', '', ''], ['A scelta; elimina: Botanica', 'falso', 'A scelta']];
[$ris_cv] = leggi_risposte_modulo(campi_studente($campi_cv), null);
$tab_cv = array_values(array_filter($ris_cv, fn($r) => $r['etichetta'] === 'Esami sostenuti in altro Ateneo'))[0];
prova(count($tab_cv['righe']) === 2 && $tab_cv['righe'][0][6] === 'A scelta; elimina: Botanica' && $tab_cv['righe'][1][6] === '' && $tab_cv['righe'][0][5] === '20/06/2024', "tabella: piano normalizzato, riga con la sola casella scartata");
$tab_unical = array_values(array_filter($ris_cv, fn($r) => $r['etichetta'] === "Esami sostenuti all'Università della Calabria"))[0];
prova(!empty($tab_unical['nascosto']), "esami dell'Unical nascosti se l'Ateneo è un altro");
$p_fin = ['risposte_json' => json_encode($ris_cv, JSON_UNESCAPED_UNICODE)];
$rr = righe_richieste_pratica($p_fin, $campi_cv);
prova(count($rr) === 2 && $rr[0]['richiesto'] === 'BIO-1 – Zoologia' && !empty($rr[0]['piano']) && $rr[0]['elimina'] === 'Botanica' && empty($rr[1]['piano']), "richieste per le convalide: codice, insegnamento scritto a mano, piano");
prova(frasi_piano($rr, 'Chiede inoltre') === ["Chiede inoltre che l'insegnamento «BIO-1 – Zoologia» sia inserito nel piano di studi come insegnamento a scelta, eliminando dal piano l'insegnamento «Botanica»."], "frase del piano di studi");
// Ufficio del corso: dai corsi del consiglio, altrimenti dal nome del consiglio
$cons_cv = consigli_didattica($conn); $c_bio = 0;
foreach ($cons_cv as $c) if (str_contains($c['nome'], 'Laurea in Biologia')) $c_bio = (int)$c['id'];
$u_bio = (int)db_valore($conn, "SELECT id FROM uffici WHERE consiglio_id = ?", [$c_bio]);
prova($c_bio && ufficio_del_corso($conn, 'Corso di laurea in Biologia') === $u_bio && ufficio_del_corso($conn, 'Corso di laurea in Bio') === null, "ufficio del corso dal nome del consiglio (senza corsi indicati)");
$conn->query("UPDATE didattica_consigli SET corsi = '[\"Corso di laurea in Chimica\"]' WHERE id = $c_bio");
prova(ufficio_del_corso($conn, 'corso di laurea in chimica') === $u_bio && ufficio_del_corso($conn, 'Corso di laurea in Biologia') === null, "con i corsi indicati nel consiglio valgono quelli");
$conn->query("UPDATE didattica_consigli SET corsi = NULL WHERE id = $c_bio");
// Pratica: domanda in PDF/A, protocollo, ufficio del corso
$q("INSERT INTO uffici_persone (email, nominativo, compiti, ufficio_id, corsi) VALUES ('prot@unical.it', 'Protocollo Pia', 'pratiche', $u_prot, NULL), ('ref.bio@unical.it', 'Referente Bea', 'pratiche', $u_bio, '[\"Corso di laurea in Biologia\"]')");
op_fix($q);
$q("INSERT INTO utenti (id, nome, cognome, email, codice_fiscale, matricola_studente, ruolo_id) VALUES (9301, 'Giulia', 'Verdi', 'giulia.v@studenti.unical.it', 'VRDGLI02A41D086K', '245678', 5)");
$ris_cv = array_map(function ($r) { if ($r['etichetta'] === 'Corso di studio') $r['valore'] = 'Corso di laurea in Biologia'; if ($r['etichetta'] === 'Luogo di nascita') $r['valore'] = 'Cosenza'; return $r; }, $ris_cv);
$EMAIL = []; $_SERVER['REMOTE_ADDR'] = '10.1.2.3'; $_SESSION['auth_meta'] = ['metodo' => 'spid', 'livello' => 2];
$id_cv = crea_pratica($conn, $m_cv, ['id' => 9301, 'nome' => 'Giulia', 'cognome' => 'Verdi', 'email' => 'giulia.v@studenti.unical.it', 'matricola_studente' => '245678'], $ris_cv);
$p_cv = pratica($conn, $id_cv);
$pdf_cv = (string)@file_get_contents(RADICE_SITO . '/' . $p_cv['domanda_pdf']);
prova(str_starts_with($pdf_cv, '%PDF') && hash('sha256', $pdf_cv) === $p_cv['domanda_sha'] && str_contains($pdf_cv, '<pdfaid:part>2</pdfaid:part>') && str_contains($pdf_cv, '<dibest:indirizzoIP>10.1.2.3</dibest:indirizzoIP>') && str_contains($pdf_cv, '<dibest:codicePratica>' . $p_cv['codice']), "domanda in PDF/A generata all'invio con impronta e metadati");
prova((int)$p_cv['ufficio_id'] === $u_prot && (int)$p_cv['passo'] === 1 && $p_cv['assegnata_a'] === null && in_array('prot@unical.it', array_column($EMAIL, 'a'), true), "la pratica parte dall'Ufficio protocollo, che riceve l'email");
prova(passi_pratica($m_cv, $p_cv) === [0 => 'Inviata', 1 => 'Ufficio protocollo', 2 => 'Ufficio del corso di studio', 3 => 'Segreteria studenti'], "passi: protocollo, ufficio del corso, segreteria");
prova(registra_protocollo_pratica($conn, $id_cv, '', date('Y-m-d'), 1)[0] !== null && registra_protocollo_pratica($conn, $id_cv, '12', date('Y-m-d', strtotime('+2 days')), 1)[0] !== null, "protocollo: numero e data (non futura) obbligatori");
[$e_b, $m_b] = registra_protocollo_pratica($conn, $id_cv, '0123456', date('Y-m-d'), 1, 'Protocollo Pia');
$p_b = pratica($conn, $id_cv);
prova($e_b === null && str_contains($m_b, 'bollo') && $p_b['protocollo'] === '0123456' && (int)$p_b['ufficio_id'] === $u_prot && $p_b['cdl_id'] === null, "marca da bollo non spuntata: protocollata ma resta all'Ufficio protocollo");
$EMAIL = [];
[$e_pr, $m_pr] = registra_protocollo_pratica($conn, $id_cv, '', '', 1, 'Protocollo Pia', true);
$p_cv = pratica($conn, $id_cv);
$o_bea = (int)db_valore($conn, "SELECT op_id FROM uffici_persone WHERE email = 'ref.bio@unical.it'");
prova($e_pr === null && $p_cv['protocollo'] === '0123456' && (int)$p_cv['cdl_id'] === $u_bio && (int)$p_cv['ufficio_id'] === $u_bio && (int)$p_cv['passo'] === 2 && (int)$p_cv['assegnata_a'] === $o_bea && $p_cv['stato'] === 'in_lavorazione', "dopo il protocollo la pratica passa da sola all'ufficio del corso (alla sola persona che lo segue)", $m_pr);
prova($p_cv['bollo_il'] !== null && (int)db_valore($conn, "SELECT COUNT(*) FROM pratiche_eventi WHERE pratica_id = ? AND testo LIKE 'Domanda protocollata%'", [$id_cv]) === 1, "bollo spuntato dopo: numero e data restano, protocollo registrato una volta");
prova(registra_protocollo_pratica($conn, $id_cv, '0123457', date('Y-m-d'), 1)[1] === 'Dati del protocollo aggiornati.' && (int)pratica($conn, $id_cv)['ufficio_id'] === $u_bio, "pratica già trasmessa: si corregge solo il protocollo");
prova(in_array('ref.bio@unical.it', array_column($EMAIL, 'a'), true) && in_array('giulia.v@studenti.unical.it', array_column($EMAIL, 'a'), true), "email all'ufficio del corso e allo studente");
// Convalide del referente con la richiesta sul piano
$dec_cv = leggi_decisioni_post($conn, 'convalide', ['d_richiesto' => ['BIO-1 – Zoologia', 'Ecologia'], 'd_cfu' => ['9', '6'], 'd_ins' => ['Zoologia generale', ''], 'd_esito' => ['totale', 'no'], 'd_piano' => ['1', ''], 'd_elimina' => ['Botanica', '']]);
prova(!empty($dec_cv['righe'][0]['piano']) && $dec_cv['righe'][0]['elimina'] === 'Botanica' && empty($dec_cv['righe'][1]['piano']), "convalide: richiesta sul piano di studi conservata");
prova(frasi_piano_decisioni($dec_cv) === ["Lo/La studente/ssa chiede che l'insegnamento «Zoologia generale» sia inserito nel piano di studi come insegnamento a scelta, eliminando dal piano l'insegnamento «Botanica»."], "nel verbale la frase usa l'insegnamento convalidato");
salva_decisioni_seduta($conn, $id_cv, '', $dec_cv, null);
// Segreteria studenti: solo pratiche esaminate in seduta
$q("INSERT INTO uffici (nome, tipo) VALUES ('Segreteria studenti di prova', 'segreteria')");
$u_seg = (int)db_valore($conn, "SELECT MAX(id) FROM uffici WHERE tipo = 'segreteria'");
$q("INSERT INTO uffici_persone (email, nominativo, compiti, ufficio_id) VALUES ('seg.stud@unical.it', 'Segreteria Sergio', 'pratiche', $u_seg)");
op_fix($q);
prova(invia_pratiche_segreteria($conn, [$id_cv], $u_prot, 1)[0] === 0, "si invia solo a un ufficio di segreteria studenti");
[$n_s, $esc_s] = invia_pratiche_segreteria($conn, [$id_cv], $u_seg, 1);
prova($n_s === 0 && count($esc_s) === 1 && str_contains($esc_s[0], 'seduta'), "pratica non ancora esaminata in seduta: non si invia");
$q("INSERT INTO didattica_sedute (id, consiglio_id, organo, data) VALUES (77, $c_bio, 'Consiglio di prova', CURDATE())");
$conn->query("UPDATE pratiche SET seduta_id = 77, esito_seduta = 'approvata' WHERE id = $id_cv");
$EMAIL = [];
[$n_s] = invia_pratiche_segreteria($conn, [$id_cv], $u_seg, 1, 'Referente Bea');
$p_cv = pratica($conn, $id_cv);
$estr = (int)db_valore($conn, "SELECT COUNT(*) FROM pratiche_eventi WHERE pratica_id = ? AND nome_allegato LIKE 'Estratto_verbale_%'", [$id_cv]);
$mail_seg = array_values(array_filter($EMAIL, fn($e) => $e['a'] === 'seg.stud@unical.it'));
prova($n_s === 1 && (int)$p_cv['segreteria_id'] === $u_seg && (int)$p_cv['ufficio_id'] === $u_seg && (int)$p_cv['passo'] === 3 && $p_cv['inviata_segreteria_il'] !== null && $estr === 1, "inviata alla segreteria studenti con l'estratto del verbale");
prova(count($mail_seg) === 1 && str_contains($mail_seg[0]['corpo'], $p_cv['codice']), "una sola email di riepilogo alla segreteria");
prova(str_contains((string)corpo_pratiche_verbale(pratiche_per_esportazione($conn, [$id_cv])), 'come insegnamento a scelta'), "nel verbale in Word c'è la richiesta sul piano di studi");
foreach (glob(RADICE_SITO . '/' . DIR_PRATICHE . '*.pdf') ?: [] as $f_d) @unlink($f_d);
$_POST = []; unset($_SESSION['auth_meta']);

sezione("Iter con firme: relatore (persona indicata nel modulo), Direttore, tempi per passo, pratiche fuori verbale");
$iter_svc = \App\Core\App::per($conn)->get(\App\Didattica\ServizioIter::class);
$fp = \App\Core\App::per($conn)->get(\App\Didattica\FirmaPratica::class);
$flusso = \App\Core\App::per($conn)->get(\App\Didattica\ServizioFlussoConvalide::class);
$q("INSERT INTO personale_ateneo (id, cognome, nome, email, gruppo, docente, attivo) VALUES ('neri.paolo', 'Neri', 'Paolo', 'paolo.neri@unical.it', 'docenti', 1, 1), ('neri.dup1', 'Gemelli', 'Anna', 'a1@unical.it', 'docenti', 1, 1), ('neri.dup2', 'Gemelli', 'Anna', 'a2@unical.it', 'docenti', 1, 1)");
$q("INSERT INTO utenti (id, nome, cognome, email, codice_fiscale, matricola_studente, ruolo_id) VALUES (9302, 'Luca', 'Bianchi', 'luca.b@studenti.unical.it', 'BNCLCU02A01D086K', '245999', 5)");
$stud_t = ['id' => 9302, 'nome' => 'Luca', 'cognome' => 'Bianchi', 'email' => 'luca.b@studenti.unical.it', 'matricola_studente' => '245999'];
$campi_t = json_encode([['etichetta' => 'Corso di studio', 'tipo' => 'corso_studio', 'obbligatorio' => true], ['etichetta' => 'Relatore', 'tipo' => 'docente', 'obbligatorio' => true]], JSON_UNESCAPED_UNICODE);
$iter_t = json_encode([['t' => 'persona', 'campo' => 'Relatore', 'gg' => 3], ['u' => $u_prot, 'gg' => 2], '@cdl', ['t' => 'direttore'], ['t' => 'inesistente'], ['t' => 'persona', 'campo' => '']], JSON_UNESCAPED_UNICODE);
$q("INSERT INTO didattica_moduli (id, titolo, categoria, tipo, campi_json, destinatari, attivo, ordine, iter_json, giorni_promemoria, domanda_json, verbale_json) VALUES (820, 'Richiesta Tesi di prova', 'Lauree', 'online', '" . $conn->real_escape_string($campi_t) . "', 'studenti', 1, 1, '" . $conn->real_escape_string($iter_t) . "', 7, '{\"pdf\":1,\"bollo\":0,\"oggetto\":\"\",\"destinatario\":\"\",\"chiede\":\"\"}', '{\"sezione\":\"Tesi\"}')");
$m_t = modulo_didattica($conn, 820);
prova(array_map(fn($d_) => array_intersect_key($d_, array_flip(['t', 'u', 'campo', 'gg'])), $iter_svc->definizione($m_t)) == [['t' => 'persona', 'u' => null, 'campo' => 'Relatore', 'gg' => 3], ['t' => 'ufficio', 'u' => $u_prot, 'campo' => '', 'gg' => 2], ['t' => 'ufficio', 'u' => '@cdl', 'campo' => '', 'gg' => 0], ['t' => 'direttore', 'u' => null, 'campo' => '', 'gg' => 0]], "passi dell'iter: ufficio, persona del modulo, Direttore; quelli non validi si saltano", json_encode($iter_svc->definizione($m_t)));
prova($iter_svc->iter($m_t) === [ITER_PERSONA, $u_prot, ITER_CDL, ITER_DIRETTORE] && $iter_svc->passi($m_t) === [0 => 'Ricevuta e da smistare', 1 => 'Firma: Relatore', 2 => 'Ufficio protocollo', 3 => 'Ufficio del corso di studio', 4 => 'Firma del Direttore'], "iter: passi con le firme e i loro nomi", json_encode($iter_svc->passi($m_t)));
prova($iter_svc->giorniPasso($m_t, 1) === 3 && $iter_svc->giorniPasso($m_t, 2) === 2 && $iter_svc->giorniPasso($m_t, 3) === 7, "tempo massimo del passo; se manca vale quello del modulo");
prova($iter_svc->iter(['iter_json' => json_encode(['protocollo', '@cdl', '@segreteria'])]) === [$u_prot, ITER_CDL, ITER_SEGRETERIA], "il vecchio formato dell'iter (elenco di uffici) continua a funzionare");
// La pratica parte dalla firma del relatore
$risp_t = [['etichetta' => 'Corso di studio', 'tipo' => 'corso_studio', 'valore' => 'Corso di laurea in Biologia', 'file' => null, 'nome_file' => null], ['etichetta' => 'Relatore', 'tipo' => 'docente', 'valore' => 'Neri Paolo', 'file' => null, 'nome_file' => null]];
$EMAIL = []; $_SERVER['REMOTE_ADDR'] = '10.1.2.3';
$id_t = crea_pratica($conn, $m_t, $stud_t, $risp_t);
$p_t = pratica($conn, $id_t);
$fa = $fp->inAttesa($id_t);
prova($fa && $fa['email'] === 'paolo.neri@unical.it' && $fa['tipo'] === 'persona' && (int)$fa['passo'] === 1 && (int)$p_t['passo'] === 1 && $p_t['ufficio_id'] === null && $p_t['assegnata_a'] === null && (int)$p_t['firme_attesa'] === 1, "iter che comincia con la firma: la richiesta parte subito al relatore indicato dallo studente");
$em_rel = array_values(array_filter($EMAIL, fn($e) => $e['a'] === 'paolo.neri@unical.it'));
prova(count($em_rel) === 1 && str_contains($em_rel[0]['corpo'], 'firma_pratica.php?t=' . $fa['token']) && in_array('luca.b@studenti.unical.it', array_column($EMAIL, 'a'), true) && !in_array('prot@unical.it', array_column($EMAIL, 'a'), true), "email al relatore con il link personale; lo studente è avvisato; il protocollo non riceve ancora nulla");
prova(\App\Didattica\FasePratica::di($p_t, '') === 'firma' && \App\Didattica\FasePratica::cosaFare('firma', $p_t)['tipo'] === 'attesa', "fase «in attesa di firma»");
$u_rel = ['id' => 1, 'email' => 'PAOLO.NERI@unical.it']; $u_alt = ['id' => 2, 'email' => 'altro@unical.it'];
prova($fp->puoFirmare($fa, $u_rel) && !$fp->puoFirmare($fa, $u_alt) && !$fp->puoFirmare($fa, null), "firma solo chi ha l'email a cui è arrivato il link");
// Firma: PDF della domanda con una firma PAdES in più
$pdf_dom = (string)file_get_contents(RADICE_SITO . '/' . $p_t['domanda_pdf']);
$cert_rel = $crea_cert('Paolo Neri', 'NRIPLA70A01D086Q');
prova($fp->caricaFirmato($fa, 'non un pdf') !== null && $fp->caricaFirmato($fa, str_replace('%%EOF', '%%EOF ', $pdf_dom)) !== null && $fp->inAttesa($id_t) !== null, "PDF non firmato o modificato rifiutato: la richiesta resta in attesa");
$EMAIL = [];
$esito_f = $fp->caricaFirmato($fa, $firma_pades($pdf_dom, $cert_rel));
prova($esito_f === null && $fp->inAttesa($id_t) === null && $fp->perToken($fa['token'])['stato'] === 'firmata' && (int)db_valore($conn, "SELECT COUNT(*) FROM pratiche_eventi WHERE pratica_id = ? AND nome_allegato LIKE 'Firmato\\_%' AND interno = 0", [$id_t]) === 1, "PDF firmato caricato: richiesta chiusa e documento firmato nella pratica, visibile allo studente");
[$e_n, $m_n] = $flusso->trasmettiAlPasso($id_t, 1, 0, 'Paolo Neri');
$p_t = pratica($conn, $id_t);
prova($e_n === null && (int)$p_t['ufficio_id'] === $u_prot && (int)$p_t['passo'] === 2 && in_array('prot@unical.it', array_column($EMAIL, 'a'), true) && $fp->perToken($fa['token'])['firmato_da'] !== '', "dopo la firma la pratica passa al protocollo, che riceve l'email", json_encode([$e_n, $m_n]));
// Rifiuto del relatore
$EMAIL = [];
$id_t2 = crea_pratica($conn, $m_t, $stud_t, $risp_t);
$fa2 = $fp->inAttesa($id_t2);
prova($fp->rifiuta($fa2, '  ') !== null && $fp->inAttesa($id_t2) !== null, "il rifiuto richiede il motivo");
$EMAIL = [];
prova($fp->rifiuta($fa2, 'Titolo non concordato') === null && $fp->inAttesa($id_t2) === null && pratica($conn, $id_t2)['stato'] === 'integrazione' && in_array('luca.b@studenti.unical.it', array_column($EMAIL, 'a'), true) && str_contains(implode(' ', array_column($EMAIL, 'corpo')), 'Titolo non concordato'), "rifiuto: la pratica torna allo studente come integrazione, con il motivo per email");
$conn->query("UPDATE pratiche SET stato = 'in_lavorazione' WHERE id = $id_t2");
prova($fp->avvia($id_t2, 1) === null && $fp->inAttesa($id_t2) !== null && $fp->inAttesa($id_t2)['token'] !== $fa2['token'] && $fp->perToken($fa2['token'])['stato'] === 'rifiutata', "dopo il rifiuto l'ufficio può mandare di nuovo alla firma: nuovo link");
// Relatore non trovato o ambiguo
$risp_amb = $risp_t; $risp_amb[1]['valore'] = 'Gemelli Anna';
$id_t3 = crea_pratica($conn, $m_t, $stud_t, $risp_amb);
prova($fp->inAttesa($id_t3) === null && str_contains((string)$fp->avvia($id_t3, 1), 'Non trovo l\'email') && (int)db_valore($conn, "SELECT COUNT(*) FROM pratiche_eventi WHERE pratica_id = ? AND interno = 1 AND testo LIKE 'La firma non è partita%'", [$id_t3]) === 1, "relatore ambiguo (due docenti con lo stesso nome): la firma non parte e l'ufficio lo legge nello storico");
// Solleciti: dopo i giorni del passo, al massimo tre
$EMAIL = [];
$conn->query("UPDATE pratiche_firme SET richiesta_il = NOW() - INTERVAL 5 DAY WHERE pratica_id = $id_t2 AND stato = 'attesa'");
prova($fp->solleciti() === 1 && $fp->solleciti() === 0 && str_contains($EMAIL[0]['oggetto'] ?? '', 'Promemoria') && ($EMAIL[0]['a'] ?? '') === 'paolo.neri@unical.it', "promemoria al relatore dopo 3 giorni, una sola volta per periodo");
// Direttore: documento dell'ufficio da firmare
$q("INSERT INTO uffici_persone (email, nominativo, compiti, ufficio_id, corsi) VALUES ('seg.cert@unical.it', 'Segreteria Sara', 'pratiche', $u_prot, NULL)");
op_fix($q);
$m_d = ['iter_json' => json_encode([$u_prot, ['t' => 'direttore', 'gg' => 4]])];
$q("INSERT INTO didattica_moduli (id, titolo, categoria, tipo, campi_json, destinatari, attivo, ordine, iter_json, giorni_promemoria, domanda_json, in_verbale) VALUES (821, 'Certificato di prova', 'Certificati', 'online', '[]', 'studenti', 1, 2, '" . $conn->real_escape_string($m_d['iter_json']) . "', 7, '{\"pdf\":1,\"bollo\":0}', 0)");
$id_d = crea_pratica($conn, modulo_didattica($conn, 821), $stud_t, []);
prova(str_contains((string)$fp->avvia($id_d, 2), 'Indica prima il Direttore'), "senza il Direttore impostato la firma non parte e dice cosa fare");
$q("INSERT INTO uffici_persone (ufficio_id, email, nominativo, ruolo) SELECT id, 'direttore@unical.it', 'Prof. Direttore', 'Direttore' FROM uffici WHERE chiave = 'direzione'");
prova(str_contains((string)$fp->avvia($id_d, 1), 'non è una firma'), "un passo che non è una firma non si può mandare alla firma");
$dir_pr_rel = \App\Core\App::per($conn)->get(\App\Didattica\AllegatiPratiche::class)->cartella(); $cartella_pr = RADICE_SITO . '/' . $dir_pr_rel; @mkdir($cartella_pr, 0755, true);
file_put_contents($cartella_pr . 'certificato_prova.pdf', $pdf_dom);
$conn->query("INSERT INTO pratiche_eventi (pratica_id, tipo, autore, testo, allegato, nome_allegato, interno) VALUES ($id_d, 'attivita', 'ufficio', 'Certificato caricato', '" . $dir_pr_rel . "certificato_prova.pdf', 'Certificato.pdf', 0)");
$err_d = $fp->avvia($id_d, 2);
prova($err_d === null && ($fd = $fp->inAttesa($id_d)) && $fd['tipo'] === 'direttore' && $fd['email'] === 'direttore@unical.it' && str_ends_with($fd['file_origine'], 'certificato_prova.pdf'), "firma del Direttore: il documento è l'ultimo caricato dall'ufficio (non la domanda)", (string)$err_d);
$conn->query("DELETE FROM pratiche_firme WHERE pratica_id = $id_d");
$fa_rel = $fp->inAttesa($id_t2);
prova(str_ends_with($fa_rel['file_origine'], basename(pratica($conn, $id_t2)['domanda_pdf'])), "firma del relatore: il documento è la domanda dello studente");
// Tempi per passo e pratiche fuori verbale
$conn->query("UPDATE pratiche SET stato = 'in_lavorazione', passo = 2, ufficio_id = $u_prot, assegnata_a = NULL, aggiornata_il = NOW() - INTERVAL 3 DAY, promemoria_il = NULL WHERE id = $id_t");
$conn->query("UPDATE pratiche_firme SET stato = 'annullata' WHERE stato = 'attesa'");
$EMAIL = [];
$prom = \App\Core\App::per($conn)->get(\App\Didattica\PromemoriaPratiche::class);
prova($prom->invia() >= 1 && in_array('prot@unical.it', array_column($EMAIL, 'a'), true) && str_contains(implode(' ', array_column($EMAIL, 'oggetto')), 'ferma da 3 giorni'), "promemoria con il tempo del passo (2 giorni) anche se il modulo ne chiede 7");
$EMAIL = [];
$conn->query("UPDATE pratiche SET aggiornata_il = NOW() - INTERVAL 1 DAY, promemoria_il = NULL WHERE id = $id_t");
prova(!in_array('prot@unical.it', array_column($EMAIL, 'a'), true) && $prom->invia() === 0 || !in_array('prot@unical.it', array_column(array_slice($EMAIL, 0), 'a'), true), "sotto il tempo del passo nessun promemoria");
prova(!in_array($id_d, array_map('intval', array_column(\App\Core\App::per($conn)->get(\App\Didattica\SedutaRepository::class)->pratichePortabili(), 'id')), true) && in_array($id_t, array_map('intval', array_column(\App\Core\App::per($conn)->get(\App\Didattica\SedutaRepository::class)->pratichePortabili(), 'id')), true), "una pratica di un modulo «fuori verbale» non si porta in seduta");
$p_d = pratica($conn, $id_d);
prova(\App\Didattica\FasePratica::cosaFare('lavorazione', $p_d)['testo'] === 'Lavora e concludi la pratica' && \App\Didattica\FasePratica::cosaFare('lavorazione', $p_t)['testo'] !== 'Lavora e concludi la pratica', "fase di lavorazione: «concludi» per le pratiche che non vanno in verbale");
// Salvataggio dal pannello: passi dell'iter, in_verbale, copia
$mod_svc = \App\Core\App::per($conn)->get(\App\Didattica\ServizioModuli::class);
$post_m = ['titolo' => 'Modulo da pannello', 'tipo' => 'online', 'categoria' => 'Prova', 'destinatari' => 'studenti', 'attivo' => '1', 'in_verbale_c' => '1', 'giorni_promemoria' => '5',
           'iter_tipo' => ['persona', 'ufficio', 'ufficio', 'direttore', 'persona'], 'iter_uff' => ['', (string)$u_prot, '@cdl', '', ''], 'iter_campo' => ['Relatore', '', '', '', ''], 'iter_gg' => ['2', '', '4', '', '0'],
           'c_etichetta' => ['Relatore'], 'c_tipo' => ['docente'], 'c_opzioni' => [''], 'c_obbl' => ['1'], 'c_uff' => ['0'], 'c_aiuto' => [''], 'c_cond_campo' => [''], 'c_cond_op' => ['uguale'], 'c_cond_val' => [''], 'c_auto_campo' => [''], 'c_auto_op' => ['uguale'], 'c_auto_val' => [''], 'c_auto_imposta' => ['']];
$es_m = $mod_svc->salva($post_m, []);
$m_sal = modulo_didattica($conn, (int)$es_m['id']);
prova(json_decode($m_sal['iter_json'], true) === [['t' => 'persona', 'campo' => 'Relatore', 'gg' => 2], $u_prot, ['t' => 'ufficio', 'u' => '@cdl', 'gg' => 4], ['t' => 'direttore']] && (int)$m_sal['in_verbale'] === 0, "modulo salvato dal pannello: passi con firme e tempi (il passo persona senza domanda si scarta), «in verbale» tolto se non spuntato", (string)$m_sal['iter_json']);
$id_cop = $mod_svc->duplica((int)$es_m['id']); $m_cop = modulo_didattica($conn, $id_cop);
prova($id_cop > 0 && $m_cop['titolo'] === 'Modulo da pannello (copia)' && (int)$m_cop['attivo'] === 0 && $m_cop['iter_json'] === $m_sal['iter_json'] && $m_cop['campi_json'] === $m_sal['campi_json'] && (int)$m_cop['in_verbale'] === 0 && ($m_cop['chiave'] ?? null) === null && $mod_svc->duplica(999999) === 0, "copia del modulo: non pubblicata, stessi campi, iter e verbale");
@unlink($cartella_pr . 'certificato_prova.pdf');

sezione("Classi OOP (MIGRATION_PLAN.md, step 1-2): autoload, Database, container, facciate");
prova(class_exists(\App\Core\Database::class) && class_exists('PdfSemplice') && (new ReflectionClass('PdfSemplice'))->getName() === \App\Infrastructure\Pdf\Documento::class, "autoload PSR-4 delle classi App\\ e vecchi nomi delle classi PDF come alias");
$db_oop = \App\Core\Database::per($conn);
prova($db_oop === \App\Core\Database::per($conn) && \App\Core\App::get(\App\Core\Database::class) instanceof \App\Core\Database, "una sola Database per connessione, registrata nel container");
$q("CREATE TABLE prova_oop (id INT AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(30), n INT NULL) ENGINE=InnoDB");
$id_oop = $db_oop->inserisci("INSERT INTO prova_oop (nome, n) VALUES (?, ?)", ['a', 1]);
db_esegui($conn, "UPDATE prova_oop SET n = 2 WHERE id = ?", [$id_oop]);
prova($id_oop > 0 && db_riga($conn, "SELECT nome, n FROM prova_oop WHERE id = ?", [$id_oop]) === ['nome' => 'a', 'n' => 2], "inserisci() restituisce l'id anche dopo un UPDATE; le facciate db_* leggono gli stessi dati");
mysqli_report(MYSQLI_REPORT_OFF); // come config.php sul sito
$silenziosi = db_righe($conn, "SELECT * FROM tabella_che_non_esiste") === [] && db_esegui($conn, "UPDATE tabella_che_non_esiste SET x = 1") === -1 && db_valore($conn, "SELECT x FROM tabella_che_non_esiste") === null && db_query($conn, "SELEC") === false;
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
prova($silenziosi, "facciate db_*: errori silenziosi come prima (con mysqli_report OFF, come sul sito)");
try { $db_oop->transazione(function (\App\Core\Database $d) { $d->esegui("INSERT INTO prova_oop (nome) VALUES ('annullata')"); throw new RuntimeException('x'); }); } catch (RuntimeException $e) {}
prova((int)db_valore($conn, "SELECT COUNT(*) FROM prova_oop WHERE nome = 'annullata'") === 0, "transazione annullata se il lavoro fallisce");
$conn->query("DROP TABLE prova_oop");
prova(\App\Core\App::get(\App\Core\Config::class) instanceof \App\Core\Config, "configurazione disponibile dal container");

// Prove dei moduli migrati a classi (una per modulo, strumenti/prove/oop/<modulo>.php: stesse variabili e funzioni di qui)
foreach (glob(__DIR__ . '/oop/*.php') ?: [] as $__prova_oop) if (!str_starts_with(basename($__prova_oop), 'pagine_')) require $__prova_oop;

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
if (getenv('PROVE_SOLO_FUNZIONI') || !function_exists('curl_init') || $http('/eventi/')['codice'] !== 200) {
    echo "\n(ambiente locale spento: prove delle pagine saltate — avvia bash strumenti/locale/avvia.sh)\n";
} else {
    sezione("Pagine dell'ambiente locale ($BASE)");
    foreach (['/eventi/' => 200, '/eventi/privacy.php' => 200, '/eventi/agenda.php' => 200, '/eventi/agenda.php?ambito=ricerca' => 200, '/eventi/orientamento.php' => 200, '/eventi/avvisi.php' => 200, '/eventi/avvisi.php?ambito=ricerca' => 200, '/eventi/agenda_ics.php?ambito=orientamento' => 200, '/eventi/fsl.php' => 200, '/eventi/openlab' => 200, '/eventi/verifica_attestato.php' => 200,
              '/eventi/assets/modelli/Convenzione_FSL_DiBEST.doc' => 200, '/eventi/assets/modelli/Allegato_A_FSL_DiBEST.doc' => 200, '/eventi/.env.locale' => 403, '/eventi/config.php' => 403,
              '/eventi/cache/' => 403, '/eventi/uploads/convenzioni/' => 403, '/eventi/modelli_documenti/convenzione_precompilabile.docx' => 403,
              '/eventi/strumenti/prove/esegui.php' => 403, '/eventi/inc/base.php' => 403, '/eventi/cron_background.php' => 403] as $u => $atteso) {
        $r = $http($u);
        $err_php = preg_match('/<b>(Fatal error|Parse error|Warning)<\/b>|Uncaught /', $r['corpo']);
        prova($r['codice'] === $atteso && !$err_php, "$u → $atteso", "risposta " . $r['codice'] . ($err_php ? ' con errore PHP' : ''));
    }
    $jar = tempnam(sys_get_temp_dir(), 'jar');
    $http('/__accesso?u=1', null, $jar);
    foreach (['inizio.php', 'inizio.php?sezione=orientamento', 'dashboard.php', 'utenti.php?p_id=1', 'aree.php', 'nuova_area.php', 'anagrafe_insegnamenti.php', 'anagrafe_personale.php?vista=corsi', 'eventi.php?p_id=1&azione=nuovo', 'progetti.php?p_id=2&azione=nuovo', 'iscritti.php?p_id=1', 'eventi.php?p_id=1', 'progetti.php?p_id=2', 'scuole.php', 'fsl.php', 'fsl.php?tab=convenzioni', 'fsl.php?tab=verifica', 'fsl.php?tab=valutazioni', 'fsl.php?tab=convenzioni&conv_mod=1', 'fsl.php?tab=convenzioni&vista=archivio', 'fsl.php?tab=convenzioni&vista=archivio&conv_rinnova=1', 'sistema.php', 'impostazioni_area.php?p_id=1', 'statistiche.php?p_id=1'] as $pag) {
        $r = $http('/eventi/admin/' . $pag, null, $jar);
        $err_php = preg_match('/<b>(Fatal error|Parse error|Warning)<\/b>|Uncaught /', $r['corpo']);
        prova($r['codice'] === 200 && !$err_php && str_contains($r['corpo'], '</html>'), "pannello: $pag", "risposta " . $r['codice'] . ($err_php ? ' con errore PHP' : ''));
    }
    $r = $http('/eventi/miei_eventi.php', null, $jar);
    prova($r['codice'] === 200 && !preg_match('/<b>(Fatal error|Parse error|Warning)<\/b>|Uncaught /', $r['corpo']) && str_contains($r['corpo'], 'pills-attive-tab'), "Eventi e prenotazioni (con i tab)");
    // Iscrizione completa a un progetto FSL (docente di prova, scuola dall'anagrafe) e pulizia
    // (dati di esempio di strumenti/locale: utente 4, progetto 20 edizione 2 = turno 201; scuola di prova ZZPR00000P sempre senza convenzione)
    $loc = new mysqli('127.0.0.1', 'root', '', (parse_ini_file($SITO . '/.env.locale')['DB_NAME'] ?? 'eventi_locale'));
    $loc->query("INSERT IGNORE INTO scuole (codice, denominazione, comune, provincia, regione, tipo) VALUES ('ZZPR00000P', 'SCUOLA DELLE PROVE AUTOMATICHE', 'COSENZA', 'COSENZA', 'CALABRIA', 'LICEO')");
    $loc->query("DELETE FROM convenzioni_scuole WHERE scuola_codice = 'ZZPR00000P'");
    $jar2 = tempnam(sys_get_temp_dir(), 'jar');
    $http('/__accesso?u=4', null, $jar2);
    $pag = $http('/eventi/fsl.php?progetto=20', null, $jar2);
    preg_match('/name="csrf_token" value="([^"]+)"/', $pag['corpo'], $m_csrf);
    if (empty($m_csrf[1]) || !str_contains($pag['corpo'], 'name="turno_id" value="201"')) prova(false, "modulo di iscrizione presente nella scheda del progetto");
    else {
        $r = $http('/eventi/fsl.php?progetto=20', ['csrf_token' => $m_csrf[1], 'invia_prenotazione' => 1, 'turno_id' => 201, 'nome' => 'Luca', 'cognome' => 'Insegnante',
                   'email' => 'prova.automatica@example.org', 'email_conferma' => 'prova.automatica@example.org', 'custom_scuola' => 'Liceo', 'scuola_codice' => ['scuola' => 'ZZPR00000P'],
                   'custom_numero_partecipanti' => 15, 'convenzione' => 'no', 'accetta_privacy' => 'on'], $jar2);
        prova(in_array($r['codice'], [302, 303], true) && str_contains($r['dove'], 'st_tipo=convenzione'), "iscrizione FSL senza convenzione: in attesa", $r['codice'] . ' ' . $r['dove']);
        $pr = $loc->query("SELECT id, stato, convenzione FROM prenotazioni WHERE email = 'prova.automatica@example.org' ORDER BY id DESC LIMIT 1")->fetch_assoc();
        prova(($pr['stato'] ?? '') === 'da_approvare' && ($pr['convenzione'] ?? '') === 'no', "prenotazione salvata da approvare, convenzione da stipulare", json_encode($pr));
        if ($pr) $loc->query("DELETE FROM prenotazioni WHERE id = " . (int)$pr['id']);
    }
    // Scelta guidata della scuola (tendine e scuole del comune)
    $r = $http('/eventi/cerca_scuole.php?elenco=regioni');
    prova($r['codice'] === 200 && str_contains($r['corpo'], '"CALABRIA"'), "cerca_scuole: regioni");
    $r = $http('/eventi/cerca_scuole.php?q=&regione=CALABRIA&provincia=COSENZA&comune=COSENZA');
    prova($r['codice'] === 200 && str_contains($r['corpo'], 'ZZPR00000P'), "cerca_scuole: scuole del comune");

    // Calendari e risorse: area di prova, pagina pubblica, prenotazione, pannello e pulizia
    $loc->query("DELETE FROM pagine_eventi WHERE slug = 'prove-calendario'");
    $loc->query("INSERT INTO pagine_eventi (titolo, slug, tipo_area, visibile) VALUES ('Prove calendario', 'prove-calendario', 'calendario', 1)");
    $pid_c = (int)$loc->insert_id;
    $loc->query("INSERT INTO risorse (pagina_id, nome, tipo, durata_slot, max_slot, anticipo_ore, max_giorni, chiede_motivo) VALUES ($pid_c, 'Aula delle prove', 'aula', 60, 2, 0, 30, 1)");
    $rid_c = (int)$loc->insert_id;
    for ($g = 1; $g <= 7; $g++) $loc->query("INSERT INTO risorse_orari (risorsa_id, giorno, dalle, alle) VALUES ($rid_c, $g, '08:00', '20:00')");
    $dom = date('Y-m-d', strtotime('+3 days'));
    $r = $http('/eventi/prove-calendario', null, $jar2);
    prova($r['codice'] === 200 && str_contains($r['corpo'], 'Aula delle prove') && !preg_match('/<b>(Fatal error|Parse error|Warning)<\/b>|Uncaught /', $r['corpo']), "pagina pubblica dell'area calendario");
    $r = $http("/eventi/prove-calendario?risorsa=$rid_c&dal=$dom", null, $jar2);
    preg_match('/name="csrf_token" value="([^"]+)"/', $r['corpo'], $m_csrf);
    prova(str_contains($r['corpo'], 'data-inizio="' . $dom . ' 10:00:00"'), "settimana con gli slot liberi prenotabili");
    $r = $http("/eventi/prove-calendario?risorsa=$rid_c", ['csrf_token' => $m_csrf[1] ?? '', 'prenota_risorsa' => 1, 'risorsa_id' => $rid_c, 'inizio' => "$dom 10:00:00", 'n_slot' => 2, 'motivo' => 'Prova automatica', 'settimana' => $dom], $jar2);
    $pc = $loc->query("SELECT * FROM prenotazioni_risorse WHERE risorsa_id = $rid_c")->fetch_assoc();
    prova(in_array($r['codice'], [302, 303], true) && ($pc['stato'] ?? '') === 'confermata' && ($pc['fine'] ?? '') === "$dom 12:00:00", "prenotazione dalla pagina pubblica", json_encode($pc));
    $r = $http('/eventi/risorsa_ics.php?code=' . urlencode($pc['codice'] ?? ''));
    prova($r['codice'] === 200 && str_contains($r['corpo'], 'BEGIN:VCALENDAR'), "file .ics della prenotazione");
    $r = $http('/eventi/miei_eventi.php', null, $jar2);
    prova(str_contains($r['corpo'], 'Aula delle prove'), "prenotazione in miei_eventi.php");
    foreach (["dashboard.php?p_id=$pid_c", "prenotazioni_risorse.php?p_id=$pid_c&periodo=tutte", "risorse.php?p_id=$pid_c", "risorse.php?p_id=$pid_c&modifica=$rid_c"] as $pag) {
        $r = $http('/eventi/admin/' . $pag, null, $jar);
        $err_php = preg_match('/<b>(Fatal error|Parse error|Warning)<\/b>|Uncaught /', $r['corpo']);
        prova($r['codice'] === 200 && !$err_php && str_contains($r['corpo'], '</html>') && str_contains($r['corpo'], 'Aula delle prove'), "pannello calendario: $pag", "risposta " . $r['codice'] . ($err_php ? ' con errore PHP' : ''));
    }
    $r = $http("/eventi/admin/prenotazioni_risorse.php?p_id=$pid_c&periodo=tutte&csv=1", null, $jar);
    prova(str_starts_with($r['corpo'], "\xEF\xBB\xBF") && str_contains($r['corpo'], 'Prova automatica'), "esportazione CSV delle prenotazioni");
    $loc->query("DELETE FROM prenotazioni_risorse WHERE risorsa_id = $rid_c");
    $loc->query("DELETE FROM risorse_orari WHERE risorsa_id = $rid_c");
    $loc->query("DELETE FROM risorse WHERE id = $rid_c");
    $loc->query("DELETE FROM pagine_eventi WHERE id = $pid_c");
    // Didattica: sedute e consigli, costruttore dei moduli, tutorato; catalogo degli insegnamenti e pagine con link personale
    $c_loc = (int)($loc->query("SELECT MIN(id) n FROM didattica_consigli")->fetch_assoc()['n'] ?? 0);
    $pagine_d = [];
    foreach (['didattica.php?tab=sedute', "didattica.php?tab=sedute&consiglio=$c_loc", "didattica.php?tab=sedute&nuova=1&consiglio_id=$c_loc", 'didattica.php?tab=moduli&nuovo=1', 'didattica.php?tab=pratiche&carico=seguite',
              'tutorato.php', 'tutorato.php?nuovo_bando=1', 'report_ambiti.php', 'report_ambiti.php?anno=2025/2026', 'inizio.php?sezione=fsl', 'testata.php?sezione=widgets'] as $pag_x) $pagine_d[] = $pag_x;
    prova(str_starts_with($http('/eventi/admin/report_ambiti.php?esporta=xlsx', null, $jar)['corpo'], 'PK'), "report per ambito in Excel dal pannello");
    $s_loc = (int)($loc->query("SELECT MIN(id) n FROM didattica_sedute")->fetch_assoc()['n'] ?? 0); $i_loc = (int)($loc->query("SELECT MIN(id) n FROM tutorato_incarichi")->fetch_assoc()['n'] ?? 0);
    if ($s_loc) $pagine_d[] = "didattica.php?tab=sedute&id=$s_loc";
    if ($i_loc) {
        $pagine_d[] = "tutorato.php?incarico=$i_loc";
        prova(str_starts_with($http("/eventi/admin/tutorato.php?incarico=$i_loc&file=fine_anteprima", null, $jar)['corpo'], '%PDF'), "anteprima della dichiarazione di fine attività in PDF");
    }
    foreach ($pagine_d as $pag) {
        $r = $http('/eventi/admin/' . $pag, null, $jar);
        $err_php = preg_match('/<b>(Fatal error|Parse error|Warning|Deprecated|Notice)<\/b>|Uncaught /', $r['corpo']);
        prova($r['codice'] === 200 && !$err_php && str_contains($r['corpo'], '</html>'), "pannello Didattica: $pag", "risposta " . $r['codice'] . ($err_php ? ' con errore PHP' : ''));
    }
    $r = $http('/eventi/cerca_insegnamenti.php?azione=tipi', null, $jar);
    prova($r['codice'] === 200 && is_array(json_decode($r['corpo'], true)), "catalogo degli insegnamenti (JSON)");
    prova($http('/eventi/cerca_insegnamenti.php?azione=tipi')['codice'] === 401, "catalogo degli insegnamenti: serve l'accesso");
    foreach (['/eventi/incarico.php?t=' . str_repeat('a', 40) => 'Lettera non disponibile', '/eventi/firma_incarico.php?t=' . str_repeat('b', 40) => 'Documento non disponibile',
              '/eventi/firma_verbale.php?t=' . str_repeat('c', 40) => 'Verbale non disponibile', '/eventi/giustifica.php?t=' . str_repeat('d', 40) => 'Link non valido', '/eventi/registro_tutorato.php' => 'Registro delle attività'] as $u => $testo) {
        $r = $http($u, null, $jar2);
        prova($r['codice'] === 200 && str_contains($r['corpo'], $testo), "link personale non valido: $u");
    }
    prova(in_array($http('/eventi/uploads/verbali/')['codice'], [403, 404], true), "verbali firmati non raggiungibili dal web");
    foreach (['/eventi/src/Core/Database.php', '/eventi/src/bootstrap.php', '/eventi/tests/bootstrap.php', '/eventi/composer.json', '/eventi/phpunit.xml.dist', '/eventi/vendor/autoload.php'] as $u)
        prova($http($u)['codice'] === 403, "codice OOP e Composer non raggiungibili dal web: $u");
    prova(str_starts_with($http('/eventi/agenda_ics.php')['corpo'], 'BEGIN:VCALENDAR'), "calendario .ics dell'agenda");
    prova($http('/eventi/uploads/incarichi/')['codice'] === 403 && $http('/eventi/modelli_documenti/lettera_incarico_tutorato.docx')['codice'] === 403, "lettere di incarico e modello Word non raggiungibili dal web");
    // Dati che le prove delle pagine si aspettano e che il database locale (condiviso e modificato a mano) può non avere:
    // area «seminari» con un evento prenotabile e una presenza con codice D1 (per l'attestato). Creati qui e tolti alla fine.
    $__fx_area = 0; $__fx_ev = 0; $__fx_pren = 0; $__fx_ev_att = 0;
    if (!($loc->query("SELECT id FROM pagine_eventi WHERE slug = 'seminari'")->fetch_assoc())) {
        $loc->query("INSERT INTO pagine_eventi (titolo, slug, tipo_area, ambito, visibile) VALUES ('Seminari', 'seminari', 'eventi', 'ricerca', 1)");
        $__fx_area = (int)$loc->insert_id;
        $loc->query("INSERT INTO eventi (pagina_id, titolo, tipo, luogo, richiede_prenotazione, archiviato) VALUES ($__fx_area, 'Seminario delle prove automatiche', 'evento', 'Aula', 1, 0)");
        $__fx_ev = (int)$loc->insert_id;
        $loc->query("INSERT INTO turni (evento_id, nome_turno, data_turno, max_posti) VALUES ($__fx_ev, 'Unico', CURDATE() + INTERVAL 20 DAY, 50)");
    }
    if (!($loc->query("SELECT id FROM prenotazioni WHERE codice_prenotazione = 'D1'")->fetch_assoc())) {
        // Evento semplice (senza attestati di gruppo) nell'area OpenLab, con una presenza già registrata
        $__fx_op = (int)($loc->query("SELECT id FROM pagine_eventi WHERE slug = 'openlab'")->fetch_assoc()['id'] ?? 1);
        $loc->query("INSERT INTO eventi (pagina_id, titolo, tipo, luogo, archiviato) VALUES ($__fx_op, 'Evento delle prove (attestato)', 'evento', 'Aula', 0)");
        $__fx_ev_att = (int)$loc->insert_id;
        $loc->query("INSERT INTO turni (evento_id, nome_turno, data_turno, max_posti) VALUES ($__fx_ev_att, 'Unico', CURDATE() - INTERVAL 1 DAY, 50)");
        $__fx_turno = (int)$loc->insert_id;
        $loc->query("INSERT INTO prenotazioni (turno_id, utente_id, codice_prenotazione, stato, num_posti, presente, nome, cognome, email) VALUES ($__fx_turno, 4, 'D1', 'confermata', 1, 1, 'Luca', 'Insegnante', 'luca.insegnante@example.org')");
        $__fx_pren = (int)$loc->insert_id;
    }
    // Pagine dei moduli migrati a classi (strumenti/prove/oop/pagine_<modulo>.php: possono usare $http, $jar (amministratore), $jar2)
    foreach (glob(__DIR__ . '/oop/pagine_*.php') ?: [] as $__prova_oop) require $__prova_oop;
    if ($__fx_pren) {
        $loc->query("DELETE FROM prenotazioni WHERE id = $__fx_pren");
        $loc->query("DELETE FROM turni WHERE evento_id = $__fx_ev_att");
        $loc->query("DELETE FROM eventi WHERE id = $__fx_ev_att");
    }
    if ($__fx_area) {
        $loc->query("DELETE FROM prenotazioni WHERE turno_id IN (SELECT id FROM turni WHERE evento_id IN (SELECT id FROM eventi WHERE pagina_id = $__fx_area))");
        $loc->query("DELETE FROM turni WHERE evento_id IN (SELECT id FROM eventi WHERE pagina_id = $__fx_area)");
        $loc->query("DELETE FROM eventi WHERE pagina_id = $__fx_area");
        $loc->query("DELETE FROM pagine_eventi WHERE id = $__fx_area");
    }
    @unlink($jar); @unlink($jar2);
}

$conn->query("DROP DATABASE IF EXISTS `$DB_N`");
$pulisci_prove();
echo "\n" . ($KO ? "\e[31m$KO prove fallite\e[0m, $OK superate.\n" : "\e[32mTutte le $OK prove superate.\e[0m\n");
exit($KO ? 1 : 0);
