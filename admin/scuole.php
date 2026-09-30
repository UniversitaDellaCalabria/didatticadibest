<?php
// scuole.php - Anagrafe delle scuole (solo amministratori): caricamento del file open data del Ministero
// dell'Istruzione (CSV o ZIP), stato dell'anagrafe, prova della ricerca, abbinamento delle scuole scritte
// a mano nelle iscrizioni passate (così report e statistiche contano ogni scuola una volta sola)
// e registro delle convenzioni scuola-Dipartimento.
require_once 'admin_header.php';

if (!$is_full_admin) nega_accesso();

function admin_redirect($url) { echo "<script>window.location.replace(" . json_encode($url) . ");</script>"; exit; }

// Colonne del file del Ministero -> colonne della tabella (i nomi delle colonne sono confrontati in maiuscolo, senza spazi)
// Regioni selezionabili all'importazione: nome => inizio del nome nel file del Ministero (solo lettere, maiuscolo),
// così "EMILIA ROMAGNA", "FRIULI-VENEZIA G." e simili vengono riconosciute comunque
const REGIONI_SCUOLE = [
    'Abruzzo' => 'ABRUZZO', 'Basilicata' => 'BASILICATA', 'Calabria' => 'CALABRIA', 'Campania' => 'CAMPANIA',
    'Emilia-Romagna' => 'EMILIA', 'Friuli Venezia Giulia' => 'FRIULI', 'Lazio' => 'LAZIO', 'Liguria' => 'LIGURIA',
    'Lombardia' => 'LOMBARDIA', 'Marche' => 'MARCHE', 'Molise' => 'MOLISE', 'Piemonte' => 'PIEMONTE', 'Puglia' => 'PUGLIA',
    'Sardegna' => 'SARDEGNA', 'Sicilia' => 'SICILIA', 'Toscana' => 'TOSCANA', 'Trentino-Alto Adige' => 'TRENTINO',
    'Umbria' => 'UMBRIA', "Valle d'Aosta" => 'VALLE', 'Veneto' => 'VENETO',
];

const MAPPA_COLONNE_SCUOLE = [
    'codice'                 => ['CODICESCUOLA'],
    'denominazione'          => ['DENOMINAZIONESCUOLA'],
    'istituto_codice'        => ['CODICEISTITUTORIFERIMENTO'],
    'istituto_denominazione' => ['DENOMINAZIONEISTITUTORIFERIMENTO'],
    'tipo'                   => ['DESCRIZIONETIPOLOGIAGRADOISTRUZIONESCUOLA', 'TIPOLOGIAGRADOISTRUZIONESCUOLA'],
    'comune'                 => ['DESCRIZIONECOMUNE', 'COMUNE'],
    'provincia'              => ['PROVINCIA'],
    'regione'                => ['REGIONE'],
    'indirizzo'              => ['INDIRIZZOSCUOLA', 'INDIRIZZO'],
    'cap'                    => ['CAPSCUOLA', 'CAP'],
    'email'                  => ['INDIRIZZOEMAILSCUOLA', 'EMAIL'],
    'pec'                    => ['INDIRIZZOPECSCUOLA', 'PEC'],
    'anno_scolastico'        => ['ANNOSCOLASTICO'],
];

// Legge il file caricato (CSV, o ZIP che contiene un CSV) e aggiorna la tabella. Ritorna [inserite, aggiornate] o un messaggio d'errore.
function importa_anagrafe_scuole($conn, array $file, int $statale, array $regioni = []) {
    $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) return "Il file supera il limite di caricamento del server (" . ini_get('upload_max_filesize') . "): caricalo compresso in ZIP.";
    if ($err !== UPLOAD_ERR_OK) return "Caricamento non riuscito (codice $err).";
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    $percorso = $file['tmp_name']; $temp_zip = null;
    if ($ext === 'zip') {
        if (!class_exists('ZipArchive')) return "Il server non può aprire i file ZIP: carica direttamente il CSV.";
        $zip = new ZipArchive();
        if ($zip->open($percorso) !== true) return "File ZIP non leggibile.";
        $nome_csv = null;
        for ($i = 0; $i < $zip->numFiles; $i++) { $n = $zip->getNameIndex($i); if (preg_match('/\.csv$/i', $n) && strpos($n, '__MACOSX') === false) { $nome_csv = $n; break; } }
        if ($nome_csv === null) { $zip->close(); return "Nello ZIP non c'è un file CSV."; }
        $temp_zip = tempnam(sys_get_temp_dir(), 'scu');
        file_put_contents($temp_zip, $zip->getFromName($nome_csv));
        $zip->close();
        $percorso = $temp_zip;
    } elseif (!in_array($ext, ['csv', 'txt'], true)) {
        return "Formato non supportato: carica il file CSV del Ministero (anche compresso in ZIP).";
    }

    $fh = fopen($percorso, 'r');
    if (!$fh) return "File non leggibile.";
    $prima = (string)fgets($fh);
    $prima = preg_replace('/^\xEF\xBB\xBF/', '', $prima);
    $conta = ['sep' => [';' => substr_count($prima, ';'), ',' => substr_count($prima, ','), "\t" => substr_count($prima, "\t")]];
    arsort($conta['sep']);
    $sep = array_key_first($conta['sep']);
    $intestazioni = array_map(fn($c) => strtoupper(preg_replace('/[\s"\']+/', '', $c)), str_getcsv(trim($prima), $sep));
    $indici = [];
    foreach (MAPPA_COLONNE_SCUOLE as $col => $nomi) {
        foreach ($nomi as $nm) { $pos = array_search($nm, $intestazioni, true); if ($pos !== false) { $indici[$col] = $pos; break; } }
    }
    if (!isset($indici['codice'], $indici['denominazione'])) {
        fclose($fh); if ($temp_zip) @unlink($temp_zip);
        return "File non riconosciuto: mancano le colonne CODICESCUOLA e DENOMINAZIONESCUOLA. Usa il file dell'anagrafe scuole del Ministero.";
    }

    @set_time_limit(600);
    $cols = array_keys(MAPPA_COLONNE_SCUOLE);
    $sql = "INSERT INTO scuole (" . implode(', ', $cols) . ", statale, aggiornata_il) VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ", ?, NOW())
            ON DUPLICATE KEY UPDATE " . implode(', ', array_map(fn($c) => "$c = VALUES($c)", array_diff($cols, ['codice']))) . ", statale = VALUES(statale), aggiornata_il = NOW()";
    $st = $conn->prepare($sql);
    $inserite = 0; $aggiornate = 0; $invariate = 0; $scartate = 0; $righe = 0; $fuori = 0;
    $conn->begin_transaction();
    while (($r = fgetcsv($fh, 0, $sep)) !== false) {
        // Codifica controllata riga per riga: le prime righe possono essere tutte in lettere semplici
        if (!mb_check_encoding(implode('', $r), 'UTF-8')) $r = array_map(fn($v) => mb_convert_encoding((string)$v, 'UTF-8', 'Windows-1252'), $r);
        $val = [];
        foreach ($cols as $c) $val[] = isset($indici[$c]) ? mb_substr(trim((string)($r[$indici[$c]] ?? '')), 0, 250) : '';
        $val[0] = strtoupper($val[0]);
        if (!preg_match('/^[A-Z0-9]{10}$/', $val[0]) || $val[1] === '') { if (implode('', $r) !== '') $scartate++; continue; }
        // Solo le scuole di una regione (es. CALABRIA): le altre righe si saltano
        if ($regioni) {
            $reg_riga = preg_replace('/[^A-Z]/', '', strtoupper((string)$val[7]));
            $ok_reg = false;
            foreach ($regioni as $pref) if (str_starts_with($reg_riga, $pref)) { $ok_reg = true; break; }
            if (!$ok_reg) { $fuori++; continue; }
        }
        foreach ([2, 3] as $k) if ($val[$k] === '' || strtoupper($val[$k]) === 'NON DISPONIBILE') $val[$k] = null; // istituto di riferimento
        $val[] = $statale;
        $st->bind_param(str_repeat('s', count($cols)) . 'i', ...$val);
        try { $ok = $st->execute(); } catch (Throwable $e) { $ok = false; }
        if (!$ok) { $scartate++; continue; }
        if ($st->affected_rows === 1) $inserite++; elseif ($st->affected_rows === 2) $aggiornate++; else $invariate++;
        if (++$righe % 2000 === 0) { $conn->commit(); $conn->begin_transaction(); }
    }
    $conn->commit();
    fclose($fh); if ($temp_zip) @unlink($temp_zip);
    return [$inserite, $aggiornate, $invariate, $scartate, $fuori];
}

// Scuole scritte a mano nelle iscrizioni passate (senza codice), raggruppate per testo
function scuole_da_abbinare($conn): array {
    $gruppi = [];
    $r = $conn->query("SELECT id, dati_custom_json FROM prenotazioni WHERE scuola_codice IS NULL AND dati_custom_json IS NOT NULL
                       AND (dati_custom_json LIKE '%scuol%' OR dati_custom_json LIKE '%istitut%') AND IFNULL(stato, 'confermata') NOT IN ('annullata', 'rifiutata', 'scaduta')");
    while ($r && $p = $r->fetch_assoc()) {
        $nome = nome_scuola_prenotazione($p);
        if ($nome === '') continue;
        $chiave = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $nome)));
        $gruppi[$chiave]['testo'] = $gruppi[$chiave]['testo'] ?? $nome;
        $gruppi[$chiave]['ids'][] = (int)$p['id'];
    }
    uasort($gruppi, fn($a, $b) => count($b['ids']) <=> count($a['ids']));
    return $gruppi;
}

// Scuole più simili a un nome scritto a mano: parole significative, poi via via meno parole
function suggerisci_scuole($conn, string $testo): array {
    $vuote = ['di', 'del', 'della', 'dei', 'de', 'e', 'ed', 'la', 'il', 'lo', 'le', 'gli', 'a', 'da', 'in', 'per', 'statale', 'istituto', 'scuola', 'superiore', 'secondaria', 'grado', 'primo', 'secondo', 'ist', 'sc', 'sede', 'via'];
    $parole = array_values(array_filter(preg_split('/[\s,;.\-–"\'()\/]+/u', mb_strtolower($testo)), fn($w) => mb_strlen($w) >= 3 && !in_array($w, $vuote, true)));
    $parole = array_slice($parole, 0, 5);
    while ($parole) {
        $ris = cerca_scuole($conn, implode(' ', $parole), 5);
        if ($ris) return $ris;
        array_pop($parole);
    }
    return [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    if (isset($_POST['importa'])) {
        $esito = importa_anagrafe_scuole($conn, $_FILES['file_scuole'] ?? [], ($_POST['tipo_file'] ?? 'statali') === 'paritarie' ? 0 : 1, array_values(array_intersect(REGIONI_SCUOLE, (array)($_POST['regioni'] ?? []))));
        if (is_array($esito)) {
            [$n_nuove, $n_agg, $n_inv, $n_scar, $n_fuori] = $esito;
            registra_log_audit($conn, "Aggiornamento anagrafe scuole", ["Nuove" => $n_nuove, "Aggiornate" => $n_agg, "Invariate" => $n_inv, "Scartate" => $n_scar]);
            flash_set("Anagrafe aggiornata: $n_nuove scuole nuove, $n_agg aggiornate, $n_inv già presenti e invariate."
                      . ($n_scar ? " $n_scar righe scartate perché incomplete o non valide." : '') . ($n_fuori ? " $n_fuori scuole delle regioni non scelte saltate." : ''), $n_scar ? 'warning' : 'success');
        } else flash_set($esito, 'danger');
    }
    // Tiene solo le scuole della Calabria; quelle di altre regioni già scelte in un'iscrizione o in un profilo restano
    if (isset($_POST['solo_calabria_pulisci'])) {
        $usate = [];
        foreach (['prenotazioni', 'utenti'] as $tab_u) {
            $r_u = $conn->query("SELECT DISTINCT scuola_codice FROM $tab_u WHERE scuola_codice IS NOT NULL");
            while ($r_u && $x = $r_u->fetch_assoc()) $usate[] = "'" . $conn->real_escape_string($x['scuola_codice']) . "'";
        }
        $conn->query("DELETE FROM scuole WHERE regione <> 'CALABRIA'" . ($usate ? " AND codice NOT IN (" . implode(',', array_unique($usate)) . ")" : ''));
        $n_del = $conn->affected_rows;
        registra_log_audit($conn, "Anagrafe scuole: tenute solo quelle della Calabria", ["Cancellate" => $n_del]);
        flash_set("Tolte $n_del scuole di altre regioni. Restano le scuole della Calabria" . ($usate ? " e quelle già scelte nelle iscrizioni." : "."));
    }
    if (isset($_POST['abbina'])) {
        $s = scuola_per_codice($conn, (string)($_POST['codice'] ?? ''));
        $chiave = (string)($_POST['chiave'] ?? '');
        $gruppi = scuole_da_abbinare($conn);
        if (!$s) flash_set("Codice meccanografico non trovato nell'anagrafe.", 'danger');
        elseif (!isset($gruppi[$chiave])) flash_set("Questo nome non è più da abbinare.", 'warning');
        else {
            $ids = implode(',', array_map('intval', $gruppi[$chiave]['ids']));
            $st = $conn->prepare("UPDATE prenotazioni SET scuola_codice = ? WHERE id IN ($ids) AND scuola_codice IS NULL");
            $st->bind_param("s", $s['codice']); $st->execute();
            registra_log_audit($conn, "Abbinamento scuola", ["Testo" => $gruppi[$chiave]['testo'], "Codice" => $s['codice'], "Iscrizioni" => $st->affected_rows]);
            flash_set("\"" . $gruppi[$chiave]['testo'] . "\" abbinata a " . etichetta_scuola($s) . " (" . $st->affected_rows . " iscrizioni).");
        }
    }
    // Registro delle convenzioni: registrazione o modifica (con i file firmati e i docenti dell'Allegato A)
    if (isset($_POST['conv_salva'])) {
        $id_cv = (int)($_POST['conv_id'] ?? 0);
        $cod_cv = strtoupper(trim((string)($_POST['scuola_codice']['conv'] ?? '')));
        $dir_cv = dirname(__DIR__) . '/uploads/convenzioni/';
        if (!is_dir($dir_cv)) @mkdir($dir_cv, 0755, true);
        // Documenti firmati: mai raggiungibili dal web, si scaricano solo da convenzione_file.php (pannello)
        if (!is_file($dir_cv . '.htaccess')) @file_put_contents($dir_cv . '.htaccess', "# Convenzioni firmate: si scaricano solo dal pannello (admin/convenzione_file.php)\nRequire all denied\n");
        $mimes_cv = ['application/pdf', 'application/pkcs7-mime', 'application/x-pkcs7-mime', 'application/pkcs7-signature', 'application/octet-stream'];
        $file_cv = []; $errori_file = [];
        foreach (['file_convenzione' => 'convenzione', 'file_allegato' => 'Allegato A'] as $campo => $nome_f) {
            if (($_FILES[$campo]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            $fn = secure_upload($_FILES[$campo], $dir_cv, ['pdf', 'p7m'], $mimes_cv);
            if ($fn) $file_cv[$campo] = 'uploads/convenzioni/' . $fn; else $errori_file[] = $nome_f;
        }
        $docenti_cv = [];
        foreach ((array)($_POST['doc_nome'] ?? []) as $i => $nome_d) $docenti_cv[] = ['nome' => $nome_d, 'email' => $_POST['doc_email'][$i] ?? ''];
        // File sostituiti: si cancellano i vecchi
        $vecchia = $id_cv > 0 ? ($conn->query("SELECT * FROM convenzioni_scuole WHERE id = $id_cv")->fetch_assoc() ?: null) : null;
        if ($id_cv > 0 && !$vecchia) $id_cv = -1;
        $esito_cv = $id_cv < 0 ? null : salva_convenzione($conn, ['scuola_codice' => $cod_cv, 'data_stipula' => $_POST['data_stipula'] ?? null, 'scadenza' => $_POST['scadenza'] ?? null,
                                                          'protocollo' => $_POST['protocollo'] ?? '', 'note' => $_POST['note'] ?? '', 'docenti' => $docenti_cv] + $file_cv,
                                                    max(0, $id_cv), (string)($_SESSION['utente_email'] ?? ''));
        if ($esito_cv === null) {
            foreach ($file_cv as $f_nuovo) @unlink(dirname(__DIR__) . '/' . $f_nuovo);
            flash_set("Convenzione non salvata: scegli la scuola dall'elenco dell'anagrafe e controlla le date (\"valida fino al\" non può precedere \"valida dal\").", 'danger');
            admin_redirect("scuole.php?p_id=$filtro_p" . ($id_cv > 0 ? "&conv_mod=$id_cv" : '') . "#convForm");
        }
        [$id_cv, $n_cv] = $esito_cv;
        if ($vecchia) foreach ($file_cv as $campo => $f_nuovo) if (!empty($vecchia[$campo])) @unlink(dirname(__DIR__) . '/' . $vecchia[$campo]);
        $s_cv = scuola_per_codice($conn, $cod_cv);
        registra_log_audit($conn, $vecchia ? "Convenzione modificata" : "Convenzione registrata", ["Scuola" => $cod_cv, "Valida dal" => $_POST['data_stipula'] ?? '', "Valida fino al" => $_POST['scadenza'] ?? '', "Prenotazioni aggiornate" => $n_cv]);
        flash_set("Convenzione " . ($vecchia ? "aggiornata" : "registrata") . " per " . etichetta_scuola($s_cv) . "."
                  . ($n_cv ? " $n_cv prenotazioni della scuola coperte dalla convenzione (chi era in attesa è stato avvisato per email)." : '')
                  . ($errori_file ? " File non caricati (servono PDF o .p7m): " . implode(', ', $errori_file) . "." : ''), $errori_file ? 'warning' : 'success');
    }
    if (isset($_POST['conv_elimina'])) {
        $id_cv = (int)$_POST['conv_elimina'];
        $vecchia = $conn->query("SELECT * FROM convenzioni_scuole WHERE id = $id_cv")->fetch_assoc();
        if ($vecchia) {
            foreach (['file_convenzione', 'file_allegato'] as $campo) if (!empty($vecchia[$campo])) @unlink(dirname(__DIR__) . '/' . $vecchia[$campo]);
            $conn->query("DELETE FROM convenzioni_scuole WHERE id = $id_cv");
            registra_log_audit($conn, "Convenzione eliminata dal registro", ["ID" => $id_cv, "Scuola" => $vecchia['scuola_codice']]);
            flash_set("Convenzione eliminata dal registro con i suoi file. Premi \"Verifica ora\" per aggiornare le iscrizioni della scuola.", 'warning');
        }
    }
    if (isset($_POST['conv_verifica'])) {
        $v = verifica_convenzioni_fsl($conn);
        registra_log_audit($conn, "Verifica convenzioni FSL", $v);
        flash_set("Verifica completata: {$v['coperte']} iscrizioni coperte da una convenzione, {$v['da_stipulare']} senza convenzione valida per il periodo"
                  . ($v['nuove_da_stipulare'] ? " ({$v['nuove_da_stipulare']} segnate ora come \"da stipulare\")" : '')
                  . ($v['senza_codice'] ? ", {$v['senza_codice']} con la scuola scritta a mano (abbinala all'anagrafe per verificarla)" : '') . ".",
                  $v['da_stipulare'] || $v['senza_codice'] ? 'warning' : 'success');
    }
    $anc = isset($_POST['abbina']) ? 'abbina' : (isset($_POST['conv_salva']) || isset($_POST['conv_elimina']) || isset($_POST['conv_verifica']) ? 'convenzioni' : 'carica');
    admin_redirect("scuole.php?p_id=$filtro_p&r=" . time() . "#" . $anc);
}

// Scuole già in anagrafe per regione (chiave: inizio del nome, come REGIONI_SCUOLE)
$per_regione = [];
$r_reg = $conn->query("SELECT regione, COUNT(*) n FROM scuole GROUP BY regione");
while ($r_reg && $x = $r_reg->fetch_assoc()) {
    $norm = preg_replace('/[^A-Z]/', '', strtoupper((string)$x['regione']));
    foreach (REGIONI_SCUOLE as $pref) if ($norm !== '' && str_starts_with($norm, $pref)) { $per_regione[$pref] = ($per_regione[$pref] ?? 0) + (int)$x['n']; break; }
}
$stato = $conn->query("SELECT COUNT(*) AS tot, SUM(statale = 1) AS statali, SUM(statale = 0) AS paritarie, SUM(regione = 'CALABRIA') AS calabria,
                              MAX(aggiornata_il) AS agg, MAX(anno_scolastico) AS anno FROM scuole")->fetch_assoc();
$gruppi = scuole_da_abbinare($conn);
$con_codice = (int)($conn->query("SELECT COUNT(*) AS n FROM prenotazioni WHERE scuola_codice IS NOT NULL")->fetch_assoc()['n'] ?? 0);
// Scuole collegate: per ogni scuola i docenti che l'hanno indicata (iscrizioni e profilo) e le attività
$collegate = [];
$res_c = $conn->query("SELECT pr.scuola_codice, pr.nome, pr.cognome, LOWER(pr.email) AS email, pr.data_prenotazione, e.titolo
                       FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
                       WHERE pr.scuola_codice IS NOT NULL AND IFNULL(pr.stato, 'confermata') NOT IN ('annullata', 'rifiutata', 'scaduta')
                       ORDER BY pr.data_prenotazione DESC");
while ($res_c && $x = $res_c->fetch_assoc()) {
    $c = &$collegate[$x['scuola_codice']];
    $c['iscrizioni'] = ($c['iscrizioni'] ?? 0) + 1;
    $c['attivita'][$x['titolo']] = true;
    $c['ultima'] = max($c['ultima'] ?? '', (string)$x['data_prenotazione']);
    $k = $x['email'] ?: mb_strtolower($x['nome'] . ' ' . $x['cognome']);
    if (!isset($c['docenti'][$k])) $c['docenti'][$k] = ['nome' => trim($x['nome'] . ' ' . $x['cognome']), 'email' => (string)$x['email'], 'n' => 0];
    $c['docenti'][$k]['n']++;
    unset($c);
}
$res_c = $conn->query("SELECT scuola_codice, nome, cognome, LOWER(email) AS email FROM utenti WHERE scuola_codice IS NOT NULL");
while ($res_c && $x = $res_c->fetch_assoc()) {
    $k = $x['email'] ?: mb_strtolower($x['nome'] . ' ' . $x['cognome']);
    if (!isset($collegate[$x['scuola_codice']]['docenti'][$k])) $collegate[$x['scuola_codice']]['docenti'][$k] = ['nome' => trim($x['nome'] . ' ' . $x['cognome']), 'email' => (string)$x['email'], 'n' => 0];
    $collegate[$x['scuola_codice']]['docenti'][$k]['profilo'] = true;
}
uasort($collegate, fn($a, $b) => ($b['iscrizioni'] ?? 0) <=> ($a['iscrizioni'] ?? 0));
// Registro delle convenzioni raggruppato per scuola (la più recente per prima)
$conv_per_scuola = [];
$r_cv = $conn->query("SELECT c.*, (SELECT COUNT(*) FROM prenotazioni p WHERE p.scuola_codice = c.scuola_codice AND IFNULL(p.stato, 'confermata') NOT IN ('annullata', 'rifiutata', 'scaduta')) AS n_iscr
                      FROM convenzioni_scuole c ORDER BY c.scuola_codice, (c.scadenza IS NULL) DESC, c.scadenza DESC, c.id DESC");
while ($r_cv && $x = $r_cv->fetch_assoc()) $conv_per_scuola[$x['scuola_codice']][] = $x;
uasort($conv_per_scuola, fn($a, $b) => strcmp(etichetta_scuola(scuola_per_codice($conn, $a[0]['scuola_codice']) ?? ['denominazione' => $a[0]['scuola_codice']]),
                                              etichetta_scuola(scuola_per_codice($conn, $b[0]['scuola_codice']) ?? ['denominazione' => $b[0]['scuola_codice']])));
// Iscrizioni senza convenzione valida: da stipulare (attività FSL o richiesta dai gestori) e scuole scritte a mano nelle attività FSL
$iscr_da_stipulare = [];
$r_ds = $conn->query("SELECT pr.id, pr.scuola_codice, pr.nome, pr.cognome, pr.email, pr.codice_prenotazione, pr.stato, pr.dati_custom_json,
                             t.data_turno, pd.data_inizio AS pd_inizio, pd.data_fine AS pd_fine, e.titolo, e.pagina_id, pe.titolo AS area
                      FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id JOIN pagine_eventi pe ON e.pagina_id = pe.id
                      LEFT JOIN progetti_dettagli pd ON pd.evento_id = e.id
                      WHERE e.archiviato = 0 AND IFNULL(pr.stato, 'confermata') IN ('confermata', 'in_attesa', 'da_approvare', 'richiesta_conferma')
                        AND COALESCE(pd.data_fine, t.data_turno, CURDATE()) >= CURDATE()
                        AND (pr.convenzione = 'no' OR (pd.convenzione = 1 AND pr.scuola_codice IS NULL AND IFNULL(pr.convenzione, '') <> 'ricevuta'))
                      ORDER BY COALESCE(pd.data_inizio, t.data_turno), pr.data_prenotazione");
while ($r_ds && $x = $r_ds->fetch_assoc()) $iscr_da_stipulare[] = $x;
// Modifica di una convenzione, oppure nuova con scuola e periodo proposti (pulsante "Registra" della verifica)
$conv_mod = null;
if (!empty($_GET['conv_mod'])) $conv_mod = $conn->query("SELECT * FROM convenzioni_scuole WHERE id = " . (int)$_GET['conv_mod'])->fetch_assoc() ?: null;
$conv_nuova_s = !$conv_mod && !empty($_GET['conv_nuova']) ? scuola_per_codice($conn, (string)$_GET['conv_nuova']) : null;
$data_get = fn($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET[$k] ?? '')) ? $_GET[$k] : null;
[$conv_def_dal, $conv_def_al] = periodo_nuova_convenzione($data_get('dal'), $data_get('al'));
$oggi_cv = date('Y-m-d'); $tra60_cv = date('Y-m-d', strtotime('+60 days'));
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<style>
.scu-sez { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:1.25rem; margin-bottom:1rem; box-shadow:0 1px 4px rgba(0,0,0,.04); scroll-margin-top: 80px; }
.scu-sez h2 { font-size:.8rem; text-transform:uppercase; letter-spacing:.06em; font-weight:800; color:#1e293b; margin-bottom:1rem; }
.scu-num { font-size:1.6rem; font-weight:800; color:#0f172a; line-height:1; }
</style>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <h4 class="fw-bold text-dark mb-0"><i class="fa fa-school me-2" style="color:#0891b2;" aria-hidden="true"></i>Anagrafe scuole</h4>
</div>
<p class="text-secondary small">Elenco ufficiale delle scuole italiane (open data del Ministero dell'Istruzione). Nei moduli di iscrizione il campo di tipo <strong>"Scuola (anagrafe del Ministero)"</strong> cerca qui mentre il docente scrive: ogni iscrizione viene collegata al codice meccanografico e report e statistiche contano ogni scuola una volta sola.</p>

<section class="scu-sez">
    <h2><i class="fa fa-database me-1" aria-hidden="true"></i>Stato</h2>
    <?php if ((int)$stato['tot'] === 0): ?>
        <div class="alert alert-warning mb-0"><i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i>L'anagrafe è vuota: carica il file del Ministero qui sotto. Finché è vuota il campo "Scuola" funziona come un normale campo di testo.</div>
    <?php else: ?>
        <div class="row g-3 text-center text-md-start">
            <div class="col-6 col-md-3"><div class="scu-num"><?php echo number_format((int)$stato['tot'], 0, ',', '.'); ?></div><div class="small text-secondary">scuole in anagrafe</div></div>
            <div class="col-6 col-md-3"><div class="scu-num"><?php echo number_format((int)$stato['calabria'], 0, ',', '.'); ?></div><div class="small text-secondary">in Calabria</div></div>
            <div class="col-6 col-md-3"><div class="scu-num"><?php echo number_format((int)$stato['statali'], 0, ',', '.'); ?> / <?php echo number_format((int)$stato['paritarie'], 0, ',', '.'); ?></div><div class="small text-secondary">statali / paritarie</div></div>
            <div class="col-6 col-md-3"><div class="scu-num"><?php echo $con_codice; ?></div><div class="small text-secondary">iscrizioni collegate a una scuola</div></div>
        </div>
        <p class="small text-secondary mt-3 mb-0">Ultimo aggiornamento: <?php echo $stato['agg'] ? date('d/m/Y H:i', strtotime($stato['agg'])) : '—'; ?><?php if (!empty($stato['anno'])): ?> · dati dell'anno scolastico <?php echo $h($stato['anno']); ?><?php endif; ?></p>
        <?php if ((int)$stato['tot'] > (int)$stato['calabria']): ?>
            <form method="POST" class="mt-2 mb-0">
                <?php csrf_field(); ?>
                <button type="submit" name="solo_calabria_pulisci" value="1" class="btn btn-sm btn-outline-danger fw-bold" data-confirm="Togliere le <?php echo number_format((int)$stato['tot'] - (int)$stato['calabria'], 0, ',', '.'); ?> scuole delle altre regioni? Restano quelle della Calabria e quelle già scelte in un'iscrizione."><i class="fa fa-filter me-1" aria-hidden="true"></i>Tieni solo le scuole della Calabria</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</section>

<div class="row g-3">
    <div class="col-lg-6">
        <section class="scu-sez h-100" id="carica">
            <h2><i class="fa fa-upload me-1" aria-hidden="true"></i>Carica o aggiorna l'anagrafe</h2>
            <ol class="small ps-3">
                <li>Apri il portale Open Data del Ministero dell'Istruzione (<a href="https://dati.istruzione.it/opendata/" target="_blank" rel="noopener">dati.istruzione.it/opendata</a>), sezione <strong>Scuole</strong>.</li>
                <li>Scarica in formato CSV l'<strong>Anagrafe scuole statali</strong> dell'anno scolastico in corso e caricala qui. Poi, se vuoi, fai lo stesso con l'<strong>Anagrafe scuole paritarie</strong>.</li>
                <li>Ripeti una volta l'anno, a inizio anno scolastico: le scuole già presenti vengono aggiornate, nessuna iscrizione cambia.</li>
            </ol>
            <form method="POST" enctype="multipart/form-data">
                <?php csrf_field(); ?>
                <div class="mb-2">
                    <label class="form-label small fw-bold d-block">Il file contiene</label>
                    <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="tipo_file" id="tfS" value="statali" checked><label class="form-check-label small" for="tfS">scuole statali</label></div>
                    <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="tipo_file" id="tfP" value="paritarie"><label class="form-check-label small" for="tfP">scuole paritarie</label></div>
                    <fieldset class="mt-2">
                        <legend class="form-label small fw-bold mb-1">Regioni da importare</legend>
                        <div class="d-flex flex-wrap gap-1 mb-1">
                            <?php foreach (REGIONI_SCUOLE as $reg_nome => $reg_pref): $reg_id = 'reg' . $reg_pref; ?>
                                <input type="checkbox" class="btn-check" name="regioni[]" value="<?php echo $h($reg_pref); ?>" id="<?php echo $reg_id; ?>" <?php echo $reg_pref === 'CALABRIA' ? 'checked' : ''; ?> autocomplete="off">
                                <label class="btn btn-sm btn-outline-secondary py-0 px-2" for="<?php echo $reg_id; ?>"><?php echo $h($reg_nome); ?><?php if (!empty($per_regione[$reg_pref])): ?> <span class="badge bg-light text-dark"><?php echo number_format($per_regione[$reg_pref], 0, ',', '.'); ?></span><?php endif; ?></label>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-text">Il file del Ministero è nazionale: si importano solo le regioni scelte (nessuna = tutta Italia). Puoi aggiungerne altre in seguito ricaricando lo stesso file: le scuole già presenti vengono solo aggiornate. Il numero indica le scuole già in anagrafe.</div>
                    </fieldset>
                </div>
                <label for="fileScuole" class="form-label small fw-bold">File CSV (anche compresso in ZIP)</label>
                <input type="file" name="file_scuole" id="fileScuole" class="form-control form-control-sm mb-2" accept=".csv,.zip,.txt" required>
                <div class="form-text mb-2">Limite di caricamento del server: <?php echo $h(ini_get('upload_max_filesize')); ?>. Se il CSV è più grande, comprimilo in ZIP. L'importazione può richiedere qualche decina di secondi.</div>
                <button type="submit" name="importa" value="1" class="btn btn-primary btn-sm fw-bold"><i class="fa fa-file-import me-1" aria-hidden="true"></i>Importa</button>
            </form>
        </section>
    </div>
    <div class="col-lg-6">
        <section class="scu-sez h-100">
            <h2><i class="fa fa-magnifying-glass me-1" aria-hidden="true"></i>Prova la ricerca</h2>
            <p class="small text-secondary">È la stessa casella che vede il docente nel modulo di iscrizione. Prova con il nome, il comune o il codice meccanografico.</p>
            <div id="provaScuola"><?php echo html_campo_scuola('prova_ricerca'); ?></div>
        </section>
    </div>
</div>

<section class="scu-sez" id="collegate">
    <h2><i class="fa fa-chalkboard-user me-1" aria-hidden="true"></i>Scuole collegate e docenti di riferimento (<?php echo count($collegate); ?>)</h2>
    <?php if (!$collegate): ?>
        <p class="text-muted small mb-0">Ancora nessuna: compaiono qui le scuole scelte dall'anagrafe nelle iscrizioni.</p>
    <?php else: ?>
        <p class="small text-secondary">Ogni iscrizione resta collegata alla scuola scelta, anche se il docente in seguito ne indica un'altra. <span class="badge bg-light text-dark border">profilo</span> = scuola proposta al docente nelle prossime iscrizioni (l'ultima indicata).</p>
        <div class="d-flex flex-wrap gap-2 mb-2">
            <input type="search" id="cercaCollegate" class="form-control form-control-sm" style="max-width:320px;" placeholder="Cerca scuola, comune o docente" aria-label="Cerca tra le scuole collegate">
            <button type="button" class="btn btn-sm btn-outline-secondary fw-bold" id="csvCollegate"><i class="fa fa-file-csv me-1" aria-hidden="true"></i>Scarica CSV</button>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle" id="tabCollegate">
                <thead class="table-light small"><tr><th>Scuola</th><th>Docenti di riferimento</th><th class="text-center">Iscrizioni</th><th>Attività</th><th>Ultima</th></tr></thead>
                <tbody>
                <?php foreach ($collegate as $cod => $c): $s = scuola_per_codice($conn, $cod); ?>
                    <tr>
                        <td><div class="fw-semibold"><?php echo $h($s ? etichetta_scuola($s) : $cod); ?></div><div class="small text-secondary"><span class="font-monospace"><?php echo $h($cod); ?></span><?php if ($s): ?> · <?php echo $h(maiuscole_scuola((string)$s['tipo'])); ?><?php if ($s['provincia'] !== ''): ?> (<?php echo $h(maiuscole_scuola($s['provincia'])); ?>)<?php endif; ?><?php endif; ?></div></td>
                        <td class="small">
                            <?php foreach ($c['docenti'] ?? [] as $d): ?>
                                <div><?php echo $h($d['nome']); ?><?php if ($d['email'] !== ''): ?> · <a href="mailto:<?php echo $h($d['email']); ?>"><?php echo $h($d['email']); ?></a><?php endif; ?><?php if (!empty($d['profilo'])): ?> <span class="badge bg-light text-dark border">profilo</span><?php endif; ?></div>
                            <?php endforeach; ?>
                        </td>
                        <td class="text-center"><?php echo (int)($c['iscrizioni'] ?? 0); ?></td>
                        <td class="small"><?php echo $h(implode(', ', array_keys($c['attivita'] ?? []))); ?></td>
                        <td class="small text-nowrap"><?php echo !empty($c['ultima']) ? date('d/m/Y', strtotime($c['ultima'])) : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <script>
        (function () {
            var tab = document.getElementById('tabCollegate');
            document.getElementById('cercaCollegate').addEventListener('input', function () {
                var q = this.value.toLowerCase().trim();
                tab.querySelectorAll('tbody tr').forEach(function (tr) { tr.style.display = !q || tr.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none'; });
            });
            // CSV delle righe visibili (separatore ; per Excel in italiano)
            document.getElementById('csvCollegate').addEventListener('click', function () {
                var righe = [['Codice', 'Scuola', 'Docenti', 'Iscrizioni', 'Attività', 'Ultima']];
                tab.querySelectorAll('tbody tr').forEach(function (tr) {
                    if (tr.style.display === 'none') return;
                    var td = tr.children;
                    righe.push([td[0].querySelector('.font-monospace').textContent, td[0].querySelector('.fw-semibold').textContent,
                                Array.prototype.map.call(td[1].children, function (d) { return d.textContent.replace(/\s+/g, ' ').trim(); }).join(' | '),
                                td[2].textContent.trim(), td[3].textContent.trim(), td[4].textContent.trim()]);
                });
                var csv = '﻿' + righe.map(function (r) { return r.map(function (v) { return '"' + String(v).replace(/"/g, '""') + '"'; }).join(';'); }).join('\r\n');
                var a = document.createElement('a');
                a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
                a.download = 'scuole_collegate.csv'; document.body.appendChild(a); a.click(); a.remove();
            });
        })();
        </script>
    <?php endif; ?>
</section>

<section class="scu-sez" id="convenzioni">
    <h2><i class="fa fa-file-signature me-1" aria-hidden="true"></i>Convenzioni con le scuole (<?php echo count($conv_per_scuola); ?> scuole)</h2>
    <p class="small text-secondary">Registro delle convenzioni per la Formazione Scuola Lavoro, con i file firmati (convenzione e Allegato A), il periodo di validità e i docenti di riferimento. Nelle attività con l'interruttore <strong>Attività di Formazione Scuola Lavoro</strong> la convenzione deve coprire <strong>tutto il periodo</strong> del progetto (Dal/Al) o il giorno del turno dell'evento: se lo copre, la scuola non deve inviare nulla; se non lo copre, ne va stipulata una nuova. Registrando o modificando una convenzione, le prenotazioni della scuola in attesa si confermano da sole (se il turno non chiede anche l'approvazione) e la scuola riceve l'email. Gli amministratori ricevono un avviso 60 giorni prima della scadenza.</p>

    <!-- VERIFICA SULLE ISCRIZIONI DELLE ATTIVITÀ FSL (anche già confermate) -->
    <div class="border rounded p-3 mb-3" style="background:#f8fafc;" id="verifica">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <h3 class="h6 fw-bold mb-0"><i class="fa fa-list-check me-1" aria-hidden="true"></i>Verifica delle iscrizioni alle attività FSL</h3>
            <form method="POST" class="ms-auto m-0">
                <?php csrf_field(); ?>
                <button type="submit" name="conv_verifica" value="1" class="btn btn-sm btn-outline-dark fw-bold" data-attesa="Verifica in corso…"><i class="fa fa-rotate me-1" aria-hidden="true"></i>Verifica ora</button>
            </form>
        </div>
        <p class="small text-secondary mb-2">Controlla tutte le iscrizioni (anche confermate) alle attività FSL non ancora concluse, per OpenLab, Formazione Scuola Lavoro e ogni altra area: dove la convenzione copre il periodo l'iscrizione risulta <strong>Convenzione ricevuta</strong>; dove non lo copre diventa <strong>Convenzione da stipulare</strong>, senza cambiare lo stato della prenotazione e senza email automatiche. La richiesta alla scuola la invii da Iscrizioni ("Chiedi la convenzione per email"); da lì partono anche i promemoria. La verifica si ripete ogni giorno da sola.</p>
        <?php if ($iscr_da_stipulare): ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle small mb-0">
                    <thead class="table-light"><tr><th>Scuola</th><th>Attività</th><th>Periodo</th><th>Convenzione in registro</th><th>Docente</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($iscr_da_stipulare as $x): $s_x = !empty($x['scuola_codice']) ? scuola_per_codice($conn, $x['scuola_codice']) : null;
                        [$pdal, $pal] = periodo_prenotazione($x); $ult = $s_x ? (convenzioni_della_scuola($conn, $x['scuola_codice'])[0] ?? null) : null; ?>
                        <tr>
                            <td><?php echo $h($s_x ? etichetta_scuola($s_x) : (nome_scuola_prenotazione($x) ?: '—')); ?><div class="text-secondary <?php echo $s_x ? 'font-monospace' : ''; ?>"><?php echo $s_x ? $h($x['scuola_codice']) : 'scritta a mano: abbinala all\'anagrafe'; ?></div></td>
                            <td><?php echo $h($x['titolo']); ?><div class="text-secondary"><?php echo $h($x['area']); ?> · <span class="font-monospace"><?php echo $h($x['codice_prenotazione']); ?></span> · <?php echo $h($x['stato'] ?: 'confermata'); ?></div></td>
                            <td class="text-nowrap"><?php echo date('d/m/Y', strtotime($pdal)) . ($pal !== $pdal ? ' – ' . date('d/m/Y', strtotime($pal)) : ''); ?></td>
                            <td><?php if ($ult): ?><span class="badge" style="background:#fee2e2;color:#991b1b;">non copre il periodo</span><div class="text-secondary"><?php echo $h(testo_validita_convenzione($ult)); ?></div><?php elseif ($s_x): ?><span class="badge bg-light text-dark border">nessuna</span><?php else: ?>—<?php endif; ?></td>
                            <td><?php echo $h(trim($x['nome'] . ' ' . $x['cognome'])); ?><div class="text-secondary"><?php echo $h($x['email']); ?></div></td>
                            <td class="text-nowrap"><a href="iscritti.php?p_id=<?php echo (int)$x['pagina_id']; ?>&f_cerca=<?php echo urlencode($x['codice_prenotazione']); ?>" class="btn btn-sm btn-outline-secondary fw-bold py-0">Iscrizioni</a>
                                <?php if ($s_x): ?><a href="scuole.php?p_id=<?php echo $filtro_p; ?>&conv_nuova=<?php echo urlencode($x['scuola_codice']); ?>&dal=<?php echo $pdal; ?>&al=<?php echo $pal; ?>#convForm" class="btn btn-sm btn-success fw-bold py-0">Registra</a><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="small text-success mb-0"><i class="fa fa-circle-check me-1" aria-hidden="true"></i>Nessuna iscrizione alle attività FSL in corso senza convenzione.</p>
        <?php endif; ?>
    </div>

    <!-- REGISTRA / MODIFICA -->
    <h3 class="h6 fw-bold mt-3" id="convForm"><?php echo $conv_mod ? 'Modifica la convenzione' : 'Registra una convenzione'; ?></h3>
    <?php $cm = $conv_mod ?: []; $cm_doc = json_decode((string)($cm['docenti_json'] ?? ''), true) ?: [['nome' => '', 'email' => '']];
          $cm_s = !empty($cm['scuola_codice']) ? scuola_per_codice($conn, $cm['scuola_codice']) : ($conv_nuova_s ?? null); ?>
    <form method="POST" enctype="multipart/form-data" class="row g-2 align-items-end mb-3 border rounded p-2">
        <?php csrf_field(); ?>
        <input type="hidden" name="conv_id" value="<?php echo (int)($cm['id'] ?? 0); ?>">
        <div class="col-lg-6"><label class="form-label small fw-bold mb-1">Scuola *</label><?php echo html_campo_scuola('conv', $cm_s ? etichetta_scuola($cm_s) : '', $cm_s['codice'] ?? '', 'required'); ?></div>
        <div class="col-6 col-lg-3"><label class="form-label small fw-bold mb-1" for="cvStip">Valida dal *</label><input type="date" name="data_stipula" id="cvStip" class="form-control form-control-sm" required value="<?php echo $h($cm['data_stipula'] ?? $conv_def_dal); ?>" onchange="var s=document.getElementById('cvScad'); if (this.value && !s.dataset.toccata) { var d=new Date(this.value); d.setFullYear(d.getFullYear()+<?php echo CONV_DURATA_ANNI; ?>); d.setDate(d.getDate()-1); s.value=d.toISOString().slice(0,10); }"></div>
        <div class="col-6 col-lg-3"><label class="form-label small fw-bold mb-1" for="cvScad">Valida fino al *</label><input type="date" name="scadenza" id="cvScad" class="form-control form-control-sm" required value="<?php echo $h($cm['scadenza'] ?? $conv_def_al); ?>" oninput="this.dataset.toccata='1'"></div>
        <div class="col-md-6">
            <label class="form-label small fw-bold mb-1" for="cvFileC">Convenzione firmata (PDF o .p7m)<?php echo empty($cm['file_convenzione']) ? '' : ' – carica solo per sostituirla'; ?></label>
            <input type="file" name="file_convenzione" id="cvFileC" class="form-control form-control-sm" accept=".pdf,.p7m">
            <?php if (!empty($cm['file_convenzione'])): ?><div class="form-text"><a href="convenzione_file.php?id=<?php echo (int)$cm['id']; ?>&f=conv" target="_blank"><i class="fa fa-file-pdf me-1"></i>File attuale</a></div><?php endif; ?>
        </div>
        <div class="col-md-6">
            <label class="form-label small fw-bold mb-1" for="cvFileA">Allegato A firmato (PDF o .p7m)<?php echo empty($cm['file_allegato']) ? '' : ' – carica solo per sostituirlo'; ?></label>
            <input type="file" name="file_allegato" id="cvFileA" class="form-control form-control-sm" accept=".pdf,.p7m">
            <?php if (!empty($cm['file_allegato'])): ?><div class="form-text"><a href="convenzione_file.php?id=<?php echo (int)$cm['id']; ?>&f=all" target="_blank"><i class="fa fa-file-pdf me-1"></i>File attuale</a></div><?php endif; ?>
        </div>
        <div class="col-12">
            <label class="form-label small fw-bold mb-1">Docenti di riferimento (Allegato A)</label>
            <div id="cvDocenti">
                <?php foreach ($cm_doc as $d): ?>
                    <div class="d-flex gap-2 mb-1 cv-doc">
                        <input type="text" name="doc_nome[]" class="form-control form-control-sm" placeholder="Nome e cognome" value="<?php echo $h($d['nome'] ?? ''); ?>" maxlength="150" aria-label="Nome e cognome del docente">
                        <input type="email" name="doc_email[]" class="form-control form-control-sm" placeholder="Email" value="<?php echo $h($d['email'] ?? ''); ?>" aria-label="Email del docente">
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="var r=this.closest('.cv-doc'); if (document.querySelectorAll('#cvDocenti .cv-doc').length > 1) r.remove(); else r.querySelectorAll('input').forEach(function(i){i.value='';});" aria-label="Togli il docente">×</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary fw-bold py-0" onclick="var c=document.querySelector('#cvDocenti .cv-doc').cloneNode(true); c.querySelectorAll('input').forEach(function(i){i.value='';}); document.getElementById('cvDocenti').appendChild(c);"><i class="fa fa-plus me-1" aria-hidden="true"></i>Aggiungi docente</button>
        </div>
        <div class="col-6 col-lg-3"><label class="form-label small fw-bold mb-1" for="cvProt">Protocollo</label><input type="text" name="protocollo" id="cvProt" class="form-control form-control-sm" maxlength="100" value="<?php echo $h($cm['protocollo'] ?? ''); ?>" placeholder="es. n. 1234 del 01/10/2026"></div>
        <div class="col-6 col-lg-6"><label class="form-label small fw-bold mb-1" for="cvNote">Note</label><input type="text" name="note" id="cvNote" class="form-control form-control-sm" maxlength="500" value="<?php echo $h($cm['note'] ?? ''); ?>"></div>
        <div class="col-12 col-lg-3 d-flex gap-2">
            <button type="submit" name="conv_salva" value="1" class="btn btn-sm btn-primary fw-bold flex-grow-1"><i class="fa fa-save me-1" aria-hidden="true"></i><?php echo $conv_mod ? 'Salva le modifiche' : 'Registra'; ?></button>
            <?php if ($conv_mod || !empty($conv_nuova_s)): ?><a href="scuole.php?p_id=<?php echo $filtro_p; ?>#convenzioni" class="btn btn-sm btn-outline-secondary fw-bold">Annulla</a><?php endif; ?>
        </div>
    </form>

    <!-- REGISTRO PER SCUOLA -->
    <?php if ($conv_per_scuola): ?>
        <div class="d-flex flex-wrap gap-2 mb-2">
            <input type="search" id="cercaConv" class="form-control form-control-sm" style="max-width:320px;" placeholder="Cerca scuola, docente o protocollo" aria-label="Cerca nel registro delle convenzioni">
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle small" id="tabConv">
                <thead class="table-light"><tr><th>Scuola</th><th>Validità</th><th>Docenti di riferimento</th><th>File</th><th>Protocollo e note</th><th></th></tr></thead>
                <?php foreach ($conv_per_scuola as $cod => $lista): $s_c = scuola_per_codice($conn, $cod); ?>
                    <tbody class="conv-scuola border-top">
                    <?php foreach ($lista as $i => $c):
                        $dal_c = $c['data_stipula']; $al_c = $c['scadenza'];
                        [$bg_c, $fg_c, $lbl_c] = ($al_c !== null && $al_c < $oggi_cv) ? ['#fee2e2', '#991b1b', 'Scaduta']
                            : (($dal_c !== null && $dal_c > $oggi_cv) ? ['#e0f2fe', '#0c4a6e', 'Non ancora valida']
                            : (($al_c !== null && $al_c <= $tra60_cv) ? ['#fef3c7', '#92400e', 'In scadenza'] : ['#f0fdf4', '#166534', 'Valida']));
                        $docs = json_decode((string)($c['docenti_json'] ?? ''), true) ?: []; ?>
                        <tr>
                            <?php if ($i === 0): ?><td rowspan="<?php echo count($lista); ?>" class="align-top"><div class="fw-semibold"><?php echo $h($s_c ? etichetta_scuola($s_c) : $cod); ?></div><div class="text-secondary font-monospace"><?php echo $h($cod); ?></div><div class="text-secondary"><?php echo (int)$c['n_iscr']; ?> iscrizioni</div></td><?php endif; ?>
                            <td class="text-nowrap"><?php echo $h(testo_validita_convenzione($c)); ?> <span class="badge" style="background:<?php echo $bg_c; ?>;color:<?php echo $fg_c; ?>;"><?php echo $lbl_c; ?></span></td>
                            <td><?php foreach ($docs as $d): ?><div><?php echo $h($d['nome']); ?><?php if (!empty($d['email'])): ?> · <a href="mailto:<?php echo $h($d['email']); ?>"><?php echo $h($d['email']); ?></a><?php endif; ?></div><?php endforeach; ?><?php if (!$docs): ?><span class="text-secondary">—</span><?php endif; ?></td>
                            <td class="text-nowrap">
                                <?php if (!empty($c['file_convenzione'])): ?><a href="convenzione_file.php?id=<?php echo (int)$c['id']; ?>&f=conv" target="_blank" class="me-2"><i class="fa fa-file-pdf me-1"></i>Convenzione</a><?php else: ?><span class="badge bg-light text-secondary border me-1">convenzione mancante</span><?php endif; ?>
                                <?php if (!empty($c['file_allegato'])): ?><a href="convenzione_file.php?id=<?php echo (int)$c['id']; ?>&f=all" target="_blank"><i class="fa fa-file-pdf me-1"></i>Allegato A</a><?php else: ?><span class="badge bg-light text-secondary border">Allegato A mancante</span><?php endif; ?>
                            </td>
                            <td><?php echo $h($c['protocollo']); ?><?php if ($c['note'] !== ''): ?><div class="text-secondary"><?php echo $h($c['note']); ?></div><?php endif; ?></td>
                            <td class="text-nowrap">
                                <a href="scuole.php?p_id=<?php echo $filtro_p; ?>&conv_mod=<?php echo (int)$c['id']; ?>#convForm" class="btn btn-sm btn-outline-primary py-0" title="Modifica" aria-label="Modifica la convenzione"><i class="fa fa-pen"></i></a>
                                <form method="POST" class="d-inline">
                                    <?php csrf_field(); ?>
                                    <button type="submit" name="conv_elimina" value="<?php echo (int)$c['id']; ?>" class="btn btn-sm btn-outline-danger py-0" data-confirm="Eliminare questa convenzione dal registro, con i suoi file?" title="Elimina" aria-label="Elimina la convenzione"><i class="fa fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                <?php endforeach; ?>
            </table>
        </div>
        <script>
        document.getElementById('cercaConv').addEventListener('input', function () {
            var q = this.value.toLowerCase().trim();
            document.querySelectorAll('#tabConv tbody.conv-scuola').forEach(function (tb) { tb.style.display = !q || tb.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none'; });
        });
        </script>
    <?php else: ?>
        <p class="text-muted small mb-0">Nessuna convenzione registrata.</p>
    <?php endif; ?>
</section>

<section class="scu-sez mt-3" id="abbina">
    <h2><i class="fa fa-link me-1" aria-hidden="true"></i>Scuole scritte a mano da abbinare (<?php echo count($gruppi); ?>)</h2>
    <?php if (!$gruppi): ?>
        <p class="text-muted small mb-0">Nessuna: tutte le iscrizioni con una scuola sono collegate all'anagrafe.</p>
    <?php elseif ((int)$stato['tot'] === 0): ?>
        <p class="text-muted small mb-0">Carica prima l'anagrafe: poi qui trovi i suggerimenti per collegare le <?php echo count($gruppi); ?> scuole scritte a mano.</p>
    <?php else: ?>
        <p class="small text-secondary">Nomi di scuole scritti a mano nelle iscrizioni (prima del campo con ricerca o con "non è in elenco"). Scegli la scuola giusta: tutte le iscrizioni con quel nome vengono collegate. Il testo originale resta nelle iscrizioni.</p>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead class="table-light small"><tr><th>Scritto nelle iscrizioni</th><th class="text-center">Iscrizioni</th><th>Scuola dell'anagrafe</th><th></th></tr></thead>
                <tbody>
                <?php foreach (array_slice($gruppi, 0, 20, true) as $chiave => $g): $sugg = suggerisci_scuole($conn, $g['testo']); ?>
                    <tr>
                        <td class="fw-semibold"><?php echo $h($g['testo']); ?></td>
                        <td class="text-center"><?php echo count($g['ids']); ?></td>
                        <td colspan="2">
                            <form method="POST" class="d-flex flex-wrap gap-2 align-items-center m-0">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="chiave" value="<?php echo $h($chiave); ?>">
                                <label class="visually-hidden" for="abb<?php echo md5($chiave); ?>">Scuola per <?php echo $h($g['testo']); ?></label>
                                <select name="codice" id="abb<?php echo md5($chiave); ?>" class="form-select form-select-sm" style="max-width: 460px;" required>
                                    <?php if (!$sugg): ?><option value="">Nessun suggerimento: cerca il codice nella casella di prova</option><?php endif; ?>
                                    <?php foreach ($sugg as $sg): ?><option value="<?php echo $h($sg['codice']); ?>"><?php echo $h($sg['nome'] . ' · ' . $sg['tipo'] . ' · ' . $sg['codice']); ?></option><?php endforeach; ?>
                                </select>
                                <input type="text" name="codice_manuale" class="form-control form-control-sm font-monospace" style="max-width: 140px;" placeholder="o codice" maxlength="10" pattern="[A-Za-z0-9]{10}" title="Codice meccanografico di 10 caratteri" oninput="var s=this.form.codice; if (this.value.length===10) { var o=s.querySelector('option[data-manuale]') || s.appendChild(document.createElement('option')); o.dataset.manuale='1'; o.value=this.value.toUpperCase(); o.textContent='Codice ' + this.value.toUpperCase(); s.value=o.value; }">
                                <button type="submit" name="abbina" value="1" class="btn btn-sm btn-success fw-bold"><i class="fa fa-link me-1" aria-hidden="true"></i>Abbina</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($gruppi) > 20): ?><p class="small text-secondary mb-0">Mostrati i primi 20 (quelli con più iscrizioni): gli altri compaiono man mano.</p><?php endif; ?>
    <?php endif; ?>
</section>

<?php require_once 'admin_footer.php'; ?>
