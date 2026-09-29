<?php
// scuole.php - Anagrafe delle scuole (solo amministratori): caricamento del file open data del Ministero
// dell'Istruzione (CSV o ZIP), stato dell'anagrafe, prova della ricerca e abbinamento delle scuole scritte
// a mano nelle iscrizioni passate (così report e statistiche contano ogni scuola una volta sola).
require_once 'admin_header.php';

if (!$is_full_admin) nega_accesso();

function admin_redirect($url) { echo "<script>window.location.replace(" . json_encode($url) . ");</script>"; exit; }

// Colonne del file del Ministero -> colonne della tabella (i nomi delle colonne sono confrontati in maiuscolo, senza spazi)
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
function importa_anagrafe_scuole($conn, array $file, int $statale) {
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
    $inserite = 0; $aggiornate = 0; $invariate = 0; $scartate = 0; $righe = 0;
    $conn->begin_transaction();
    while (($r = fgetcsv($fh, 0, $sep)) !== false) {
        // Codifica controllata riga per riga: le prime righe possono essere tutte in lettere semplici
        if (!mb_check_encoding(implode('', $r), 'UTF-8')) $r = array_map(fn($v) => mb_convert_encoding((string)$v, 'UTF-8', 'Windows-1252'), $r);
        $val = [];
        foreach ($cols as $c) $val[] = isset($indici[$c]) ? mb_substr(trim((string)($r[$indici[$c]] ?? '')), 0, 250) : '';
        $val[0] = strtoupper($val[0]);
        if (!preg_match('/^[A-Z0-9]{10}$/', $val[0]) || $val[1] === '') { if (implode('', $r) !== '') $scartate++; continue; }
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
    return [$inserite, $aggiornate, $invariate, $scartate];
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
        $esito = importa_anagrafe_scuole($conn, $_FILES['file_scuole'] ?? [], ($_POST['tipo_file'] ?? 'statali') === 'paritarie' ? 0 : 1);
        if (is_array($esito)) {
            [$n_nuove, $n_agg, $n_inv, $n_scar] = $esito;
            registra_log_audit($conn, "Aggiornamento anagrafe scuole", ["Nuove" => $n_nuove, "Aggiornate" => $n_agg, "Invariate" => $n_inv, "Scartate" => $n_scar]);
            flash_set("Anagrafe aggiornata: $n_nuove scuole nuove, $n_agg aggiornate, $n_inv già presenti e invariate."
                      . ($n_scar ? " $n_scar righe scartate perché incomplete o non valide." : ''), $n_scar ? 'warning' : 'success');
        } else flash_set($esito, 'danger');
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
    admin_redirect("scuole.php?p_id=$filtro_p#" . (isset($_POST['abbina']) ? 'abbina' : 'carica'));
}

$stato = $conn->query("SELECT COUNT(*) AS tot, SUM(statale = 1) AS statali, SUM(statale = 0) AS paritarie, SUM(regione = 'CALABRIA') AS calabria,
                              MAX(aggiornata_il) AS agg, MAX(anno_scolastico) AS anno FROM scuole")->fetch_assoc();
$gruppi = scuole_da_abbinare($conn);
$con_codice = (int)($conn->query("SELECT COUNT(*) AS n FROM prenotazioni WHERE scuola_codice IS NOT NULL")->fetch_assoc()['n'] ?? 0);
// Scuole collegate: per ogni scuola i docenti che l'hanno indicata (iscrizioni e profilo) e le attività
$collegate = [];
$res_c = $conn->query("SELECT pr.scuola_codice, pr.nome, pr.cognome, LOWER(pr.email) AS email, pr.created_at, e.titolo
                       FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
                       WHERE pr.scuola_codice IS NOT NULL AND IFNULL(pr.stato, 'confermata') NOT IN ('annullata', 'rifiutata', 'scaduta')
                       ORDER BY pr.created_at DESC");
while ($res_c && $x = $res_c->fetch_assoc()) {
    $c = &$collegate[$x['scuola_codice']];
    $c['iscrizioni'] = ($c['iscrizioni'] ?? 0) + 1;
    $c['attivita'][$x['titolo']] = true;
    $c['ultima'] = max($c['ultima'] ?? '', (string)$x['created_at']);
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
