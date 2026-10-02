<?php
// didattica.php - Modulo Didattica (Ufficio didattico):
// - Pratiche: elenco con filtri, dettaglio, stato, messaggi, istruttoria (campi dell'ufficio, seduta, delibera), Excel e Word;
// - Sedute e verbali: sedute del Consiglio con o.d.g. e presenze, pratiche assegnate, verbale in Word ed Excel;
// - Moduli e documenti: documenti da scaricare e moduli online (campi guidati dalle anagrafi, tabelle, campi dell'ufficio,
//   come compaiono nel verbale), con modelli pronti;
// - Ufficio e ricevimento: operatori scelti dall'anagrafe di Ateneo con i loro compiti, sportello di ricevimento dell'ufficio.
// Pagine pubbliche: modulistica.php, modulo.php, pratiche.php.
require_once 'admin_header.php';

if (!$puo_didattica) nega_accesso();
function admin_redirect($url) { echo "<script>window.location.replace(" . json_encode($url) . ");</script>"; exit; }
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$tab = in_array($_GET['tab'] ?? '', ['pratiche', 'sedute', 'moduli', 'ufficio'], true) ? $_GET['tab'] : 'pratiche';
$base = "didattica.php?p_id=" . (int)$filtro_p;
$uid = (int)$u_id_curr;
$ids_post = fn() => array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
$autore_nome = nome_autore_ufficio($conn, $utente_admin);
$io_operatore = operatore_ufficio($conn, 0, $utente_admin);

// ==============================================================================
// AZIONI
// ==============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');

    // ── Pratiche: stato, integrazioni, messaggi, attività, iter, istruttoria, seduta ──
    if (isset($_POST['stato_pratica'])) {
        $id = (int)$_POST['pratica_id'];
        $ok = cambia_stato_pratica($conn, $id, (string)$_POST['stato_pratica'], mb_substr(trim((string)($_POST['nota'] ?? '')), 0, 2000), $uid, $autore_nome);
        if ($ok) registra_log_audit($conn, "Pratica: cambio di stato", ["Pratica" => $id, "Stato" => $_POST['stato_pratica']]);
        flash_set($ok ? "Stato aggiornato: lo studente riceve un'email." : "Stato non valido.", $ok ? 'success' : 'danger');
        admin_redirect("$base&tab=pratiche&id=$id&r=" . time());
    }
    if (isset($_POST['richiedi_integrazione'])) {
        $id = (int)$_POST['pratica_id'];
        $testo_r = mb_substr(trim((string)($_POST['testo_richiesta'] ?? '')), 0, 3000);
        if ($testo_r === '') flash_set("Scrivi cosa deve integrare o dichiarare lo studente.", 'danger');
        else {
            cambia_stato_pratica($conn, $id, 'integrazione', '', $uid, $autore_nome, ['tipo' => (string)($_POST['tipo_richiesta'] ?? 'documenti'), 'testo' => $testo_r]);
            registra_log_audit($conn, "Pratica: integrazione richiesta", ["Pratica" => $id, "Tipo" => $_POST['tipo_richiesta'] ?? '']);
            flash_set("Richiesta inviata allo studente: la vede nella pratica e riceve un'email.");
        }
        admin_redirect("$base&tab=pratiche&id=$id&r=" . time());
    }
    if (isset($_POST['messaggio_pratica']) || isset($_POST['attivita_pratica'])) {
        $id = (int)$_POST['pratica_id'];
        $att = isset($_POST['attivita_pratica']);
        $interno = $att ? empty($_POST['visibile']) : !empty($_POST['interno']);
        $err = messaggio_pratica($conn, $id, 'ufficio', $uid, (string)($_POST['testo'] ?? ''), $_FILES['allegato'] ?? null, $interno, $att ? 'attivita' : 'messaggio', $autore_nome);
        flash_set($err ?? ($att ? "Attività aggiunta alla pratica." : ($interno ? "Nota interna salvata (lo studente non la vede)." : "Messaggio inviato allo studente.")), $err ? 'danger' : 'success');
        admin_redirect("$base&tab=pratiche&id=$id&r=" . time());
    }
    if (isset($_POST['assegna_pratica'])) {
        $id = (int)$_POST['pratica_id'];
        $err = assegna_pratica($conn, $id, (int)($_POST['operatore_id'] ?? 0), (int)($_POST['passo'] ?? 1), (string)($_POST['nota_passaggio'] ?? ''), $uid, $autore_nome);
        if (!$err) registra_log_audit($conn, "Pratica: assegnata", ["Pratica" => $id, "Operatore" => $_POST['operatore_id'] ?? '']);
        flash_set($err ?? "Pratica assegnata: l'operatore riceve un'email, lo studente vede il passaggio.", $err ? 'danger' : 'success');
        admin_redirect("$base&tab=pratiche&id=$id&r=" . time());
    }
    if (isset($_POST['salva_istruttoria'])) {
        $id = (int)$_POST['pratica_id'];
        $p = pratica($conn, $id);
        if ($p) {
            $m = modulo_didattica($conn, (int)$p['modulo_id']);
            [$ris_u] = leggi_risposte_modulo(campi_ufficio(campi_modulo($m['campi_json'] ?? '')), $conn);
            $json_u = $ris_u ? json_encode($ris_u, JSON_UNESCAPED_UNICODE) : null;
            $sed = (int)($_POST['seduta_id'] ?? 0) ?: null;
            $del = mb_substr(trim((string)($_POST['delibera'] ?? '')), 0, 5000);
            $st = $conn->prepare("UPDATE pratiche SET ufficio_json = ?, seduta_id = ?, delibera = ?, aggiornata_il = NOW() WHERE id = ?");
            $st->bind_param("sisi", $json_u, $sed, $del, $id); $st->execute();
            registra_log_audit($conn, "Pratica: istruttoria salvata", ["Pratica" => $p['codice']]);
            flash_set("Istruttoria salvata.");
        }
        admin_redirect("$base&tab=pratiche&id=$id&r=" . time());
    }
    if (isset($_POST['assegna_seduta'])) {
        $ids = $ids_post(); $sed = (int)($_POST['seduta_id'] ?? 0);
        if ($ids && ($sed === 0 || seduta_didattica($conn, $sed))) {
            $conn->query("UPDATE pratiche SET seduta_id = " . ($sed ?: 'NULL') . " WHERE id IN (" . implode(',', $ids) . ")");
            flash_set(count($ids) . ($sed ? " pratiche portate alla seduta." : " pratiche tolte dalla seduta."));
        } else flash_set("Scegli le pratiche e la seduta.", 'warning');
        admin_redirect((string)($_POST['torna'] ?? "$base&tab=pratiche") . '&r=' . time());
    }

    // ── Sedute ──
    if (isset($_POST['salva_seduta'])) {
        $id = (int)($_POST['seduta_id'] ?? 0);
        $f = [];
        foreach (['organo' => 500, 'anno_accademico' => 20, 'luogo' => 255, 'segretario' => 200, 'coordinatore' => 200, 'odg' => 5000, 'presenze' => 20000] as $k => $max) $f[$k] = mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
        $data = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['data'] ?? '')) ? $_POST['data'] : null;
        $oi = preg_match('/^\d{2}:\d{2}$/', (string)($_POST['ora_inizio'] ?? '')) ? $_POST['ora_inizio'] : '';
        $of = preg_match('/^\d{2}:\d{2}$/', (string)($_POST['ora_fine'] ?? '')) ? $_POST['ora_fine'] : '';
        if ($f['organo'] === '') { flash_set("Indica l'organo (es. Consiglio del Corso di Laurea in …).", 'danger'); admin_redirect("$base&tab=sedute&" . ($id ? "modifica=$id" : "nuova=1")); }
        if ($id) {
            $st = $conn->prepare("UPDATE didattica_sedute SET organo=?, anno_accademico=?, data=?, ora_inizio=?, ora_fine=?, luogo=?, odg=?, presenze=?, segretario=?, coordinatore=? WHERE id=?");
            $st->bind_param("ssssssssssi", $f['organo'], $f['anno_accademico'], $data, $oi, $of, $f['luogo'], $f['odg'], $f['presenze'], $f['segretario'], $f['coordinatore'], $id);
        } else {
            $st = $conn->prepare("INSERT INTO didattica_sedute (organo, anno_accademico, data, ora_inizio, ora_fine, luogo, odg, presenze, segretario, coordinatore) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $st->bind_param("ssssssssss", $f['organo'], $f['anno_accademico'], $data, $oi, $of, $f['luogo'], $f['odg'], $f['presenze'], $f['segretario'], $f['coordinatore']);
        }
        $st->execute();
        if (!$id) $id = (int)$conn->insert_id;
        registra_log_audit($conn, "Seduta del Consiglio salvata", ["Organo" => $f['organo'], "Data" => $data]);
        flash_set("Seduta salvata: ora porta le pratiche e scarica il verbale.");
        admin_redirect("$base&tab=sedute&id=$id");
    }
    if (isset($_POST['elimina_seduta'])) {
        $id = (int)$_POST['elimina_seduta'];
        $conn->query("UPDATE pratiche SET seduta_id = NULL WHERE seduta_id = $id");
        $conn->query("DELETE FROM didattica_sedute WHERE id = $id");
        flash_set("Seduta eliminata (le pratiche restano, senza seduta).", 'warning');
        admin_redirect("$base&tab=sedute");
    }

    // ── Moduli: salvataggio ed eliminazione ──
    if (isset($_POST['salva_modulo'])) {
        $id = (int)($_POST['modulo_id'] ?? 0);
        $titolo = mb_substr(trim((string)($_POST['titolo'] ?? '')), 0, 200);
        if ($titolo === '') { flash_set("Il titolo è obbligatorio.", 'danger'); admin_redirect("$base&tab=moduli&" . ($id ? "modifica=$id" : "nuovo=1")); }
        $tipo = ($_POST['tipo'] ?? '') === 'online' ? 'online' : 'documento';
        $cat = mb_substr(trim((string)($_POST['categoria'] ?? '')), 0, 100) ?: 'Altro';
        $descr = mb_substr(trim(strip_tags((string)($_POST['descrizione'] ?? ''), '<p><br><strong><em><ul><ol><li><a>')), 0, 5000);
        $dest = isset(DESTINATARI_MODULO[$_POST['destinatari'] ?? '']) ? $_POST['destinatari'] : 'tutti';
        $emails = implode(', ', array_filter(array_map('trim', preg_split('/[,;\s]+/', (string)($_POST['email_ufficio'] ?? ''))), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
        $link = trim((string)($_POST['link'] ?? ''));
        if ($link !== '' && !preg_match('#^https?://#i', $link)) $link = 'https://' . $link;
        if ($link !== '' && !filter_var($link, FILTER_VALIDATE_URL)) $link = '';
        $attivo = isset($_POST['attivo']) ? 1 : 0; $ordine = (int)($_POST['ordine'] ?? 0);
        // Campi del modulo online (righe del costruttore)
        $campi = [];
        foreach ((array)($_POST['c_etichetta'] ?? []) as $i => $et) {
            $et = trim((string)$et); if ($et === '') continue;
            $campi[] = ['etichetta' => $et, 'tipo' => (string)($_POST['c_tipo'][$i] ?? 'text'), 'opzioni' => (string)($_POST['c_opzioni'][$i] ?? ''),
                        'obbligatorio' => ($_POST['c_obbl'][$i] ?? '0') === '1', 'ufficio' => ($_POST['c_uff'][$i] ?? '0') === '1', 'aiuto' => (string)($_POST['c_aiuto'][$i] ?? '')];
        }
        $campi_json = $campi ? json_encode($campi, JSON_UNESCAPED_UNICODE) : null;
        $verbale = [];
        foreach (['sezione' => 200, 'stile' => 10, 'intro' => 3000, 'testo' => 3000, 'delibera' => 3000, 'chiusura' => 3000, 'colonne' => 500, 'raggruppa' => 200] as $k => $max) $verbale[$k] = mb_substr(trim((string)($_POST['v_' . $k] ?? '')), 0, $max);
        $verbale_json = json_encode($verbale, JSON_UNESCAPED_UNICODE);
        $iter = [];
        foreach ((array)($_POST['iter'] ?? []) as $x) if (($id_u = ufficio_didattica_id($conn, (int)$x)) && !(int)(uffici_didattica($conn)[$id_u]['smista'] ?? 0)) $iter[] = $id_u;
        $iter_json = $iter ? json_encode($iter) : null;
        if ($id) {
            $st = $conn->prepare("UPDATE didattica_moduli SET titolo=?, categoria=?, descrizione=?, tipo=?, link=?, campi_json=?, verbale_json=?, iter_json=?, destinatari=?, email_ufficio=?, attivo=?, ordine=?, aggiornato_il=NOW() WHERE id=?");
            $st->bind_param("ssssssssssiii", $titolo, $cat, $descr, $tipo, $link, $campi_json, $verbale_json, $iter_json, $dest, $emails, $attivo, $ordine, $id); $st->execute();
        } else {
            $st = $conn->prepare("INSERT INTO didattica_moduli (titolo, categoria, descrizione, tipo, link, campi_json, verbale_json, iter_json, destinatari, email_ufficio, attivo, ordine, aggiornato_il) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $st->bind_param("ssssssssssii", $titolo, $cat, $descr, $tipo, $link, $campi_json, $verbale_json, $iter_json, $dest, $emails, $attivo, $ordine); $st->execute();
            $id = (int)$conn->insert_id;
        }
        // Documento da scaricare (pubblico)
        if (($_FILES['file_modulo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $fn = secure_upload($_FILES['file_modulo'], RADICE_SITO . '/' . DIR_MODULISTICA, ['pdf', 'doc', 'docx', 'odt', 'xls', 'xlsx', 'ods', 'rtf'],
                ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.oasis.opendocument.text',
                 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.oasis.opendocument.spreadsheet', 'text/rtf', 'application/rtf', 'application/zip', 'application/octet-stream']);
            if ($fn) {
                $vecchio = modulo_didattica($conn, $id)['file_path'] ?? null;
                $path = DIR_MODULISTICA . $fn;
                $st = $conn->prepare("UPDATE didattica_moduli SET file_path = ? WHERE id = ?"); $st->bind_param("si", $path, $id); $st->execute();
                if ($vecchio && is_file(RADICE_SITO . '/' . $vecchio)) @unlink(RADICE_SITO . '/' . $vecchio);
            } else flash_set("Il file non è stato caricato: usa PDF, Word, LibreOffice o Excel.", 'warning');
        }
        registra_log_audit($conn, "Modulo della didattica salvato", ["Titolo" => $titolo, "Tipo" => $tipo]);
        if (!isset($_SESSION['_flash'])) flash_set("Modulo \"$titolo\" salvato.");
        admin_redirect("$base&tab=moduli");
    }
    if (isset($_POST['elimina_modulo'])) {
        $id = (int)$_POST['elimina_modulo'];
        $n = (int)$conn->query("SELECT COUNT(*) n FROM pratiche WHERE modulo_id = $id")->fetch_assoc()['n'];
        if ($n > 0) {
            $conn->query("UPDATE didattica_moduli SET attivo = 0 WHERE id = $id");
            flash_set("Il modulo ha $n pratiche: non l'ho eliminato ma nascosto agli studenti.", 'warning');
        } else {
            $m = modulo_didattica($conn, $id);
            if ($m && $m['file_path'] && is_file(RADICE_SITO . '/' . $m['file_path'])) @unlink(RADICE_SITO . '/' . $m['file_path']);
            $conn->query("DELETE FROM didattica_moduli WHERE id = $id");
            registra_log_audit($conn, "Modulo della didattica eliminato", ["ID" => $id]);
            flash_set("Modulo eliminato.", 'warning');
        }
        admin_redirect("$base&tab=moduli");
    }

    // ── Ufficio didattico: operatori e sportello ──
    if (isset($_POST['salva_operatore'])) {
        $err = aggiungi_operatore_ufficio($conn, (string)($_POST['persona_id'] ?? ''), (string)($_POST['ruolo'] ?? ''), (array)($_POST['compiti'] ?? []), (int)($_POST['ufficio_id'] ?? 0), (array)($_POST['corsi'] ?? []));
        if (!$err) registra_log_audit($conn, "Ufficio didattico: operatore salvato", ["Persona" => $_POST['persona_id'] ?? '']);
        flash_set($err ?? "Operatore salvato: entra nel pannello Didattica con le sue credenziali Unical.", $err ? 'danger' : 'success');
        admin_redirect("$base&tab=ufficio&r=" . time());
    }
    if (isset($_POST['togli_operatore'])) {
        $id = (int)$_POST['togli_operatore'];
        $conn->query("DELETE FROM ufficio_didattica WHERE id = $id");
        registra_log_audit($conn, "Ufficio didattico: operatore tolto", ["ID" => $id]);
        flash_set("Operatore tolto dall'Ufficio didattico.", 'warning');
        admin_redirect("$base&tab=ufficio&r=" . time());
    }
    if (isset($_POST['crea_sportello'])) {
        $pag = (int)($_POST['pagina_id'] ?? 0);
        $ok_area = $pag && tipo_area($conn->query("SELECT * FROM pagine_eventi WHERE id = $pag")->fetch_assoc() ?: []) === 'calendario';
        $rid = $ok_area ? crea_sportello_ufficio($conn, $pag, (string)($_POST['nome'] ?? ''), (string)($_POST['luogo'] ?? '')) : 0;
        if ($rid) registra_log_audit($conn, "Ufficio didattico: sportello di ricevimento creato", ["Risorsa" => $rid]);
        flash_set($rid ? "Sportello creato: ora imposta giorni e orari del ricevimento." : "Scegli un'area di Prenotazioni e risorse.", $rid ? 'success' : 'danger');
        admin_redirect("$base&tab=ufficio&r=" . time());
    }
}

// ==============================================================================
// DATI
// ==============================================================================
$moduli = $conn->query("SELECT m.*, (SELECT COUNT(*) FROM pratiche p WHERE p.modulo_id = m.id) AS n_pratiche,
                               (SELECT COUNT(*) FROM pratiche p WHERE p.modulo_id = m.id AND p.stato IN ('inviata', 'in_lavorazione', 'integrazione')) AS n_aperte
                        FROM didattica_moduli m ORDER BY m.categoria, m.ordine, m.titolo")->fetch_all(MYSQLI_ASSOC);
$categorie = array_values(array_unique(array_column($moduli, 'categoria')));
$n_aperte = (int)$conn->query("SELECT COUNT(*) n FROM pratiche WHERE stato IN ('inviata', 'in_lavorazione', 'integrazione')")->fetch_assoc()['n'];
$sedute = $conn->query("SELECT s.*, (SELECT COUNT(*) FROM pratiche p WHERE p.seduta_id = s.id) AS n_pratiche FROM didattica_sedute s ORDER BY s.data DESC, s.id DESC")->fetch_all(MYSQLI_ASSOC);
$sedute_future = array_values(array_filter($sedute, fn($s) => !$s['data'] || $s['data'] >= date('Y-m-d', strtotime('-60 days'))));
$operatori = operatori_ufficio($conn);

// Filtri dell'elenco delle pratiche (servono anche per l'esportazione)
$f_stato = isset(STATI_PRATICA[$_GET['stato'] ?? '']) ? $_GET['stato'] : (in_array($_GET['stato'] ?? '', ['tutte', 'aperte'], true) ? $_GET['stato'] : 'aperte');
$f_mod = (int)($_GET['modulo'] ?? 0); $f_q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
$f_sed = (string)($_GET['seduta'] ?? '');
$f_car = in_array($_GET['carico'] ?? '', ['me', 'smistare'], true) ? $_GET['carico'] : '';
$f_dal = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['dal'] ?? '')) ? $_GET['dal'] : '';
$f_al = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['al'] ?? '')) ? $_GET['al'] : '';
$elenco_pratiche = function () use ($conn, $f_stato, $f_mod, $f_q, $f_sed, $f_dal, $f_al, $f_car, $io_operatore) {
    $where = ['1=1']; $tipi = ''; $par = [];
    if ($f_stato === 'aperte') $where[] = "p.stato IN ('inviata', 'in_lavorazione', 'integrazione')"; elseif ($f_stato !== 'tutte') { $where[] = "p.stato = ?"; $tipi .= 's'; $par[] = $f_stato; }
    if ($f_mod) $where[] = "p.modulo_id = $f_mod";
    if ($f_sed === 'nessuna') $where[] = "p.seduta_id IS NULL"; elseif ((int)$f_sed > 0) $where[] = "p.seduta_id = " . (int)$f_sed;
    if ($f_car === 'me') $where[] = "p.assegnata_a = " . (int)($io_operatore['id'] ?? -1); elseif ($f_car === 'smistare') $where[] = "p.assegnata_a IS NULL AND p.stato NOT IN ('accolta', 'respinta', 'chiusa')";
    if ($f_dal !== '') { $where[] = "p.creata_il >= ?"; $tipi .= 's'; $par[] = "$f_dal 00:00:00"; }
    if ($f_al !== '') { $where[] = "p.creata_il <= ?"; $tipi .= 's'; $par[] = "$f_al 23:59:59"; }
    if ($f_q !== '') { $like = '%' . addcslashes($f_q, '%_\\') . '%'; $where[] = "(p.nome LIKE ? OR p.cognome LIKE ? OR p.email LIKE ? OR p.codice LIKE ? OR p.matricola LIKE ?)"; $tipi .= 'sssss'; array_push($par, $like, $like, $like, $like, $like); }
    $st = $conn->prepare("SELECT p.*, m.titolo AS modulo_titolo, s.data AS seduta_data FROM pratiche p JOIN didattica_moduli m ON m.id = p.modulo_id LEFT JOIN didattica_sedute s ON s.id = p.seduta_id WHERE " . implode(' AND ', $where) . " ORDER BY p.aggiornata_il DESC LIMIT 1000");
    if ($par) $st->bind_param($tipi, ...$par);
    $st->execute();
    return $st->get_result()->fetch_all(MYSQLI_ASSOC);
};

// ── Esportazioni (Excel e Word): scartano la pagina già prodotta dall'intestazione ──
if (isset($_GET['esporta']) && in_array($_GET['esporta'], ['xlsx', 'docx'], true)) {
    $seduta_exp = $tab === 'sedute' ? seduta_didattica($conn, (int)($_GET['id'] ?? 0)) : null;
    if ($seduta_exp) $ids = array_column($conn->query("SELECT id FROM pratiche WHERE seduta_id = " . (int)$seduta_exp['id'])->fetch_all(MYSQLI_ASSOC), 'id');
    elseif (!empty($_GET['ids'])) $ids = array_map('intval', explode(',', (string)$_GET['ids']));
    else $ids = array_column($elenco_pratiche(), 'id');
    $pr_exp = pratiche_per_esportazione($conn, $ids);
    $nome_f = ($seduta_exp ? 'Verbale_' . ($seduta_exp['data'] ? date('d_m_Y', strtotime($seduta_exp['data'])) : 'seduta') : 'Pratiche_' . date('d_m_Y'));
    $file = $_GET['esporta'] === 'xlsx' ? genera_excel_pratiche($conn, $pr_exp) : genera_verbale_pratiche($conn, $seduta_exp, $pr_exp);
    if ($file) {
        registra_log_audit($conn, "Pratiche esportate", ["Formato" => $_GET['esporta'], "Pratiche" => count($pr_exp)]);
        invia_file_scaricabile($file, ($_GET['esporta'] === 'xlsx' ? str_replace('Verbale_', 'Pratiche_seduta_', $nome_f) : $nome_f) . '.' . $_GET['esporta'],
            $_GET['esporta'] === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }
    flash_set("Esportazione non riuscita (manca l'estensione ZIP di PHP).", 'danger');
}
?>
<style>
.dd-ev { border-left: 3px solid #e2e8f0; padding: 4px 0 10px 14px; position: relative; }
.dd-ev::before { content: ''; position: absolute; left: -7px; top: 6px; width: 11px; height: 11px; border-radius: 50%; background: #94a3b8; }
.dd-ev.uff::before { background: #047857; } .dd-ev.stu::before { background: #0056B3; }
.dd-campo { display: grid; grid-template-columns: 2fr 1.3fr 2fr auto auto 1.6fr 34px; gap: .4rem; align-items: center; margin-bottom: .4rem; padding: .3rem; border-radius: 6px; }
.dd-campo.uff { background: #ecfdf5; }
@media (max-width: 991.98px) { .dd-campo { grid-template-columns: 1fr 1fr; } }
.dd-preset { border: 1px dashed #94a3b8; border-radius: 10px; padding: .6rem .8rem; background: #f8fafc; }
</style>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <h4 class="fw-bold text-dark mb-0"><i class="fa fa-folder-open me-2" style="color:#047857;" aria-hidden="true"></i>Didattica · Ufficio didattico</h4>
    <a href="../modulistica.php" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary"><i class="fa fa-up-right-from-square me-1" aria-hidden="true"></i>Pagina pubblica della modulistica</a>
</div>
<ul class="nav nav-pills gap-1 mb-3" style="--bs-nav-pills-link-active-bg:#047857;">
    <?php foreach (['pratiche' => ['fa-inbox', 'Pratiche' . ($n_aperte ? " <span class='badge bg-warning text-dark'>$n_aperte aperte</span>" : '')],
                    'sedute' => ['fa-gavel', 'Sedute e verbali (' . count($sedute) . ')'],
                    'moduli' => ['fa-file-lines', 'Moduli e documenti (' . count($moduli) . ')'],
                    'ufficio' => ['fa-people-group', 'Ufficio e ricevimento']] as $k => [$ico, $txt]): ?>
        <li class="nav-item"><a class="nav-link fw-bold<?php echo $tab === $k ? ' active' : ' bg-light text-dark'; ?>" href="<?php echo $base; ?>&amp;tab=<?php echo $k; ?>"><i class="fa <?php echo $ico; ?> me-1" aria-hidden="true"></i><?php echo $txt; ?></a></li>
    <?php endforeach; ?>
</ul>

<?php if ($tab === 'pratiche'):
    $id_sel = (int)($_GET['id'] ?? 0);
    $p = $id_sel ? pratica($conn, $id_sel) : null;
    if ($p):
        $risposte = json_decode((string)$p['risposte_json'], true) ?: [];
        $eventi = $conn->query("SELECT * FROM pratiche_eventi WHERE pratica_id = " . (int)$p['id'] . " ORDER BY creato_il, id")->fetch_all(MYSQLI_ASSOC);
        $m_p = modulo_didattica($conn, (int)$p['modulo_id']);
        $c_uff = campi_ufficio(campi_modulo($m_p['campi_json'] ?? ''));
        $val_uff = [];
        foreach (json_decode((string)$p['ufficio_json'], true) ?: [] as $r) $val_uff[mb_strtolower($r['etichetta'])] = ($r['tipo'] ?? '') === 'tabella' ? ($r['righe'] ?? []) : (string)$r['valore'];
        $v_m = verbale_modulo($m_p ?: ['titolo' => $p['modulo_titolo']]);
        $passi = passi_pratica($m_p);
        $passo_dopo = min(count($passi) - 1, max(1, (int)$p['passo'] + 1));
        $o_carico = $p['assegnata_a'] ? operatore_ufficio($conn, (int)$p['assegnata_a']) : null;
        $conclusa = in_array($p['stato'], ['accolta', 'respinta', 'chiusa'], true);
        $richiesta = json_decode((string)($p['richiesta_json'] ?? ''), true) ?: null;
        $tipi_ev = ['passaggio' => ['fa-route', 'Passaggio'], 'attivita' => ['fa-paperclip', 'Attività'], 'autodich' => ['fa-file-signature', 'Autodichiarazione'], 'messaggio' => ['fa-comment', ''], 'stato' => ['fa-flag', '']];
?>
    <nav class="small mb-2"><a href="<?php echo $base; ?>&amp;tab=pratiche" class="text-decoration-none"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i>Tutte le pratiche</a></nav>

    <div class="card border-0 shadow-sm mb-3" style="border-left:4px solid #0056B3 !important;"><div class="card-body">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
            <h6 class="fw-bold mb-0"><i class="fa fa-route me-1 text-primary" aria-hidden="true"></i>Iter della pratica</h6>
            <span class="small text-secondary"><?php echo $o_carico ? 'In carico a <strong>' . $h(etichetta_operatore($o_carico)) . '</strong>' . ($io_operatore && (int)$io_operatore['id'] === (int)$o_carico['id'] ? ' <span class="badge bg-success">a te</span>' : '') : ($conclusa ? 'Conclusa' : '<span class="badge bg-warning text-dark">da smistare</span>'); ?></span>
        </div>
        <?php echo html_iter_pratica($conn, $p, $m_p); ?>
        <?php if (!$conclusa): $sugg = operatori_suggeriti($conn, $p, $m_p, $passo_dopo); ?>
        <form method="POST" class="row g-2 align-items-end mt-2">
            <?php csrf_field(); ?><input type="hidden" name="pratica_id" value="<?php echo (int)$p['id']; ?>">
            <div class="col-md-3"><label class="form-label small fw-bold mb-0" for="itPasso"><?php echo $p['assegnata_a'] ? 'Passa al passo' : 'Smista al passo'; ?></label>
                <select class="form-select form-select-sm" id="itPasso" name="passo"><?php foreach ($passi as $i => $n): if ($i === 0) continue; ?><option value="<?php echo $i; ?>"<?php echo $i === $passo_dopo ? ' selected' : ''; ?>><?php echo $i . '. ' . $h($n); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label small fw-bold mb-0" for="itOp">Operatore</label>
                <select class="form-select form-select-sm" id="itOp" name="operatore_id" required><option value="">--</option>
                    <?php foreach ($sugg as $o): ?><option value="<?php echo (int)$o['id']; ?>"><?php echo ($o['_consigliato'] ? '★ ' : '') . $h(etichetta_operatore($o)); ?></option><?php endforeach; ?></select>
                <?php if (!$operatori): ?><div class="form-text text-danger">Aggiungi prima gli operatori in «Ufficio e ricevimento».</div><?php endif; ?></div>
            <div class="col-md-3"><label class="form-label small fw-bold mb-0" for="itNota">Nota per l'operatore (interna)</label><input type="text" class="form-control form-control-sm" id="itNota" name="nota_passaggio" maxlength="3000" placeholder="facoltativa"></div>
            <div class="col-md-2"><button type="submit" name="assegna_pratica" value="1" class="btn btn-sm btn-primary fw-bold w-100"><i class="fa fa-share me-1" aria-hidden="true"></i><?php echo $p['assegnata_a'] ? 'Passa' : 'Smista'; ?></button></div>
            <div class="col-12 small text-secondary">★ = persona dell'ufficio previsto per quel passo (e che segue il corso della pratica). Chi riceve la pratica ha un'email; lo studente vede il passaggio, non la nota.</div>
        </form>
        <?php endif; ?>
    </div></div>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm mb-3"><div class="card-body">
                <div class="d-flex justify-content-between flex-wrap gap-2 mb-2">
                    <div><h5 class="fw-bold mb-0"><?php echo $h($p['modulo_titolo']); ?></h5><div class="small text-secondary font-monospace"><?php echo $h($p['codice']); ?> · inviata il <?php echo date('d/m/Y H:i', strtotime($p['creata_il'])); ?></div></div>
                    <div class="d-flex gap-1 align-items-start"><?php echo badge_stato_pratica($p['stato']); ?>
                        <a class="btn btn-sm btn-outline-success py-0" href="<?php echo $base; ?>&amp;tab=pratiche&amp;esporta=xlsx&amp;ids=<?php echo (int)$p['id']; ?>" title="Excel"><i class="fa fa-file-excel" aria-hidden="true"></i><span class="visually-hidden">Excel</span></a>
                        <a class="btn btn-sm btn-outline-primary py-0" href="<?php echo $base; ?>&amp;tab=pratiche&amp;esporta=docx&amp;ids=<?php echo (int)$p['id']; ?>" title="Word"><i class="fa fa-file-word" aria-hidden="true"></i><span class="visually-hidden">Word</span></a></div>
                </div>
                <p class="mb-3"><strong><?php echo $h(trim($p['cognome'] . ' ' . $p['nome'])); ?></strong> · <a href="mailto:<?php echo $h($p['email']); ?>"><?php echo $h($p['email']); ?></a><?php echo $p['matricola'] !== '' ? ' · matricola ' . $h($p['matricola']) : ''; ?></p>
                <dl class="row small mb-0">
                    <?php foreach ($risposte as $i => $r): ?>
                        <dt class="col-sm-4"><?php echo $h($r['etichetta']); ?></dt>
                        <dd class="col-sm-8"><?php if (!empty($r['file'])): ?><a href="../allegato_pratica.php?p=<?php echo (int)$p['id']; ?>&amp;r=<?php echo $i; ?>"><i class="fa fa-paperclip me-1" aria-hidden="true"></i><?php echo $h($r['nome_file']); ?></a><?php else: echo html_risposta_pratica($r); endif; ?></dd>
                    <?php endforeach; ?>
                </dl>
            </div></div>

            <form method="POST" class="card border-0 shadow-sm mb-3" style="border-left:4px solid #047857 !important;"><div class="card-body">
                <?php csrf_field(); ?><input type="hidden" name="pratica_id" value="<?php echo (int)$p['id']; ?>">
                <h6 class="fw-bold"><i class="fa fa-scale-balanced me-1 text-success" aria-hidden="true"></i>Istruttoria e verbale <span class="small text-secondary fw-normal">(non visibile allo studente)</span></h6>
                <?php echo html_datalist_didattica($conn, $c_uff); foreach ($c_uff as $c): $c['obbligatorio'] = false; echo html_campo_pratica($c, $val_uff[mb_strtolower($c['etichetta'])] ?? '', $conn); endforeach; ?>
                <div class="row g-2">
                    <div class="col-md-5"><label class="form-label small fw-bold" for="ddSed">Seduta del Consiglio</label>
                        <select class="form-select form-select-sm" id="ddSed" name="seduta_id"><option value="0">Nessuna</option>
                            <?php foreach ($sedute as $s): if (!in_array($s, $sedute_future, true) && (int)$s['id'] !== (int)$p['seduta_id']) continue; ?><option value="<?php echo (int)$s['id']; ?>"<?php echo (int)$p['seduta_id'] === (int)$s['id'] ? ' selected' : ''; ?>><?php echo $h(etichetta_seduta($s)); ?></option><?php endforeach; ?></select>
                        <div class="form-text"><a href="<?php echo $base; ?>&amp;tab=sedute&amp;nuova=1">Nuova seduta</a></div></div>
                    <div class="col-md-7"><label class="form-label small fw-bold" for="ddDel">Delibera nel verbale</label>
                        <textarea class="form-control form-control-sm" id="ddDel" name="delibera" rows="3" maxlength="5000" placeholder="<?php echo $h($v_m['delibera'] ?: 'Testo della delibera'); ?>"><?php echo $h($p['delibera']); ?></textarea>
                        <div class="form-text">Vuoto = testo predefinito del modulo. Puoi usare {STUDENTE}, {MATRICOLA} e le domande tra graffe.</div></div>
                </div>
                <button type="submit" name="salva_istruttoria" value="1" class="btn btn-sm btn-success fw-bold mt-2"><i class="fa fa-save me-1" aria-hidden="true"></i>Salva l'istruttoria</button>
            </div></form>
            <?php echo js_tabelle_pratica(); ?>

            <div class="card border-0 shadow-sm"><div class="card-body">
                <h6 class="fw-bold">Storico, attività e messaggi</h6>
                <?php foreach ($eventi as $e): [$ico_e, $lab_e] = $tipi_ev[$e['tipo']] ?? ['fa-circle', '']; ?>
                    <div class="dd-ev <?php echo $e['autore'] === 'ufficio' ? 'uff' : 'stu'; ?>"<?php echo (int)$e['interno'] ? ' style="background:#fffbeb;"' : ''; ?>>
                        <div class="small text-secondary"><?php echo date('d/m/Y H:i', strtotime($e['creato_il'])); ?> · <?php echo $e['autore'] === 'ufficio' ? $h($e['autore_nome'] ?: 'Ufficio') : 'Studente'; ?>
                            <?php if ($lab_e): ?> · <span class="badge bg-light text-dark border"><i class="fa <?php echo $ico_e; ?> me-1" aria-hidden="true"></i><?php echo $lab_e; ?></span><?php endif; ?>
                            <?php if ((int)$e['interno']): ?> · <span class="badge bg-warning text-dark"><i class="fa fa-lock me-1" aria-hidden="true"></i>interna</span><?php endif; ?>
                            <?php if ($e['stato']): ?> · <?php echo badge_stato_pratica((string)$e['stato']); ?><?php endif; ?></div>
                        <?php if ((string)$e['testo'] !== ''): ?><div class="small"><?php echo nl2br($h($e['testo'])); ?></div><?php endif; ?>
                        <?php if ($e['allegato']): ?><div class="small"><a href="../allegato_pratica.php?p=<?php echo (int)$p['id']; ?>&amp;e=<?php echo (int)$e['id']; ?>"><i class="fa fa-paperclip me-1" aria-hidden="true"></i><?php echo $h($e['nome_allegato']); ?></a></div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <form method="POST" enctype="multipart/form-data" class="mt-3 border-top pt-3">
                    <?php csrf_field(); ?><input type="hidden" name="pratica_id" value="<?php echo (int)$p['id']; ?>">
                    <label class="form-label small fw-bold" for="ddMsg">Messaggio allo studente o nota tra i referenti</label>
                    <textarea class="form-control form-control-sm mb-2" id="ddMsg" name="testo" rows="3" maxlength="5000"></textarea>
                    <div class="d-flex gap-2 flex-wrap align-items-center"><input type="file" name="allegato" class="form-control form-control-sm" style="max-width:280px;" accept=".pdf,.jpg,.jpeg,.png,.p7m" aria-label="Allegato (facoltativo)">
                        <label class="form-check small m-0"><input class="form-check-input" type="checkbox" name="interno" value="1"> <i class="fa fa-lock" aria-hidden="true"></i> nota interna (solo referenti)</label>
                        <button type="submit" name="messaggio_pratica" value="1" class="btn btn-sm btn-primary fw-bold"><i class="fa fa-paper-plane me-1" aria-hidden="true"></i>Invia</button></div>
                </form>
            </div></div>
        </div>
        <div class="col-lg-5">
            <form method="POST" enctype="multipart/form-data" class="card border-0 shadow-sm mb-3"><div class="card-body">
                <?php csrf_field(); ?><input type="hidden" name="pratica_id" value="<?php echo (int)$p['id']; ?>">
                <h6 class="fw-bold"><i class="fa fa-paperclip me-1" aria-hidden="true"></i>Aggiungi un'attività</h6>
                <p class="small text-secondary mb-2">Es. verbale del corso di studio, parere, documento istruttorio: resta nello storico della pratica.</p>
                <textarea class="form-control form-control-sm mb-2" name="testo" rows="2" maxlength="5000" placeholder="Cosa è stato fatto (es. Verbale del CdL del 15/10/2026)" aria-label="Descrizione dell'attività"></textarea>
                <input type="file" name="allegato" class="form-control form-control-sm mb-2" accept=".pdf,.jpg,.jpeg,.png,.p7m" aria-label="File dell'attività">
                <div class="d-flex flex-wrap gap-2 align-items-center"><label class="form-check small m-0"><input class="form-check-input" type="checkbox" name="visibile" value="1" checked> visibile allo studente</label>
                    <button type="submit" name="attivita_pratica" value="1" class="btn btn-sm btn-outline-primary fw-bold ms-auto"><i class="fa fa-plus me-1" aria-hidden="true"></i>Aggiungi</button></div>
            </div></form>

            <form method="POST" class="card border-0 shadow-sm mb-3" style="border-left:4px solid #b45309 !important;"><div class="card-body">
                <?php csrf_field(); ?><input type="hidden" name="pratica_id" value="<?php echo (int)$p['id']; ?>">
                <h6 class="fw-bold"><i class="fa fa-circle-exclamation me-1" style="color:#b45309;" aria-hidden="true"></i>Chiedi un'integrazione allo studente</h6>
                <?php if ($richiesta): ?><div class="alert alert-warning small py-1 px-2">In attesa: <?php echo $richiesta['tipo'] === 'autodichiarazione' ? 'autodichiarazione' : 'documenti'; ?> – <?php echo $h($richiesta['testo']); ?></div><?php endif; ?>
                <div class="d-flex gap-3 small mb-1">
                    <label class="form-check m-0"><input class="form-check-input" type="radio" name="tipo_richiesta" value="documenti" checked> Documenti</label>
                    <label class="form-check m-0"><input class="form-check-input" type="radio" name="tipo_richiesta" value="autodichiarazione"> Autodichiarazione</label>
                </div>
                <textarea class="form-control form-control-sm mb-2" name="testo_richiesta" rows="3" maxlength="3000" placeholder="Documenti: cosa allegare. Autodichiarazione: il testo che lo studente dichiara (es. di aver sostenuto l'esame di … il …)" aria-label="Testo della richiesta"></textarea>
                <button type="submit" name="richiedi_integrazione" value="1" class="btn btn-sm fw-bold text-white" style="background:#b45309;">Invia la richiesta</button>
                <p class="small text-secondary mt-2 mb-0">Lo studente riceve un'email e risponde dalla pratica allegando i file o rendendo l'autodichiarazione (D.P.R. 445/2000); poi la pratica torna «In lavorazione».</p>
            </div></form>

            <div class="card border-0 shadow-sm"><div class="card-body">
                <h6 class="fw-bold">Cambia stato</h6>
                <form method="POST">
                    <?php csrf_field(); ?><input type="hidden" name="pratica_id" value="<?php echo (int)$p['id']; ?>">
                    <label class="form-label small fw-bold" for="ddNota">Nota per lo studente (facoltativa)</label>
                    <textarea class="form-control form-control-sm mb-2" id="ddNota" name="nota" rows="3" maxlength="2000" placeholder="Es. esito, motivo, prossimi passi"></textarea>
                    <div class="d-grid gap-1">
                        <?php foreach (STATI_PRATICA as $k => [$n, $col, $ico]): if ($k === $p['stato'] || in_array($k, ['inviata', 'integrazione'], true)) continue; ?>
                            <button type="submit" name="stato_pratica" value="<?php echo $k; ?>" class="btn btn-sm fw-bold text-white text-start" style="background:<?php echo $col; ?>;"><i class="fa <?php echo $ico; ?> me-1" aria-hidden="true"></i><?php echo $n; ?></button>
                        <?php endforeach; ?>
                    </div>
                    <p class="small text-secondary mt-2 mb-0">Lo studente riceve un'email con il nuovo stato e la nota.</p>
                </form>
            </div></div>
        </div>
    </div>
    <?php else:
        $elenco = $elenco_pratiche();
        $qs_filtri = http_build_query(['stato' => $f_stato, 'modulo' => $f_mod ?: null, 'q' => $f_q ?: null, 'seduta' => $f_sed ?: null, 'dal' => $f_dal ?: null, 'al' => $f_al ?: null, 'carico' => $f_car ?: null]);
    ?>
    <div class="d-flex flex-wrap gap-1 mb-2">
        <?php foreach (['' => ['fa-inbox', 'Tutte'], 'smistare' => ['fa-shuffle', 'Da smistare'], 'me' => ['fa-user-check', 'Assegnate a me']] as $k_c => [$ico_c, $txt_c]): if ($k_c === 'me' && !$io_operatore) continue; ?>
            <a class="btn btn-sm <?php echo $f_car === $k_c ? 'btn-dark' : 'btn-outline-dark'; ?>" href="<?php echo $base; ?>&amp;tab=pratiche&amp;carico=<?php echo $k_c; ?>"><i class="fa <?php echo $ico_c; ?> me-1" aria-hidden="true"></i><?php echo $txt_c; ?></a>
        <?php endforeach; ?>
    </div>
    <form method="GET" class="card border-0 shadow-sm mb-3"><div class="card-body row g-2 align-items-end">
        <input type="hidden" name="p_id" value="<?php echo (int)$filtro_p; ?>"><input type="hidden" name="tab" value="pratiche"><input type="hidden" name="carico" value="<?php echo $h($f_car); ?>">
        <div class="col-md-2"><label class="form-label small fw-bold mb-0" for="fSt">Stato</label><select class="form-select form-select-sm" id="fSt" name="stato">
            <option value="aperte"<?php echo $f_stato === 'aperte' ? ' selected' : ''; ?>>Aperte</option>
            <?php foreach (STATI_PRATICA as $k => [$n]): ?><option value="<?php echo $k; ?>"<?php echo $f_stato === $k ? ' selected' : ''; ?>><?php echo $n; ?></option><?php endforeach; ?>
            <option value="tutte"<?php echo $f_stato === 'tutte' ? ' selected' : ''; ?>>Tutte</option></select></div>
        <div class="col-md-3"><label class="form-label small fw-bold mb-0" for="fMod">Modulo</label><select class="form-select form-select-sm" id="fMod" name="modulo"><option value="0">Tutti</option>
            <?php foreach ($moduli as $m): if ($m['tipo'] !== 'online') continue; ?><option value="<?php echo (int)$m['id']; ?>"<?php echo $f_mod === (int)$m['id'] ? ' selected' : ''; ?>><?php echo $h($m['titolo']); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label small fw-bold mb-0" for="fSed">Seduta</label><select class="form-select form-select-sm" id="fSed" name="seduta"><option value="">Qualsiasi</option><option value="nessuna"<?php echo $f_sed === 'nessuna' ? ' selected' : ''; ?>>Senza seduta</option>
            <?php foreach ($sedute as $s): ?><option value="<?php echo (int)$s['id']; ?>"<?php echo $f_sed === (string)$s['id'] ? ' selected' : ''; ?>><?php echo $h(etichetta_seduta($s)); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-1"><label class="form-label small fw-bold mb-0" for="fDal">Dal</label><input type="date" class="form-control form-control-sm" id="fDal" name="dal" value="<?php echo $h($f_dal); ?>"></div>
        <div class="col-md-1"><label class="form-label small fw-bold mb-0" for="fAl">Al</label><input type="date" class="form-control form-control-sm" id="fAl" name="al" value="<?php echo $h($f_al); ?>"></div>
        <div class="col-md-2"><label class="form-label small fw-bold mb-0" for="fQ">Cerca</label><input type="search" class="form-control form-control-sm" id="fQ" name="q" value="<?php echo $h($f_q); ?>" placeholder="Nome, matricola, codice"></div>
        <div class="col-md-1"><button class="btn btn-sm btn-primary fw-bold w-100" aria-label="Filtra"><i class="fa fa-filter" aria-hidden="true"></i></button></div>
    </div></form>
    <form method="POST" id="ddElenco">
        <?php csrf_field(); ?><input type="hidden" name="torna" value="<?php echo $h("$base&tab=pratiche&$qs_filtri"); ?>">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
            <span class="small text-secondary"><?php echo count($elenco); ?> pratiche</span>
            <a class="btn btn-sm btn-outline-success fw-bold dd-esp" data-formato="xlsx" href="<?php echo $base; ?>&amp;tab=pratiche&amp;<?php echo $h($qs_filtri); ?>&amp;esporta=xlsx"><i class="fa fa-file-excel me-1" aria-hidden="true"></i>Excel</a>
            <a class="btn btn-sm btn-outline-primary fw-bold dd-esp" data-formato="docx" href="<?php echo $base; ?>&amp;tab=pratiche&amp;<?php echo $h($qs_filtri); ?>&amp;esporta=docx"><i class="fa fa-file-word me-1" aria-hidden="true"></i>Word (per il verbale)</a>
            <span class="small text-secondary">Esporta le pratiche filtrate o, se ne selezioni alcune, solo quelle.</span>
            <span class="ms-auto d-flex gap-1 align-items-center">
                <label class="small fw-bold" for="ddAss">Porta le selezionate alla seduta</label>
                <select class="form-select form-select-sm" id="ddAss" name="seduta_id" style="max-width:260px;"><option value="0">Nessuna (togli)</option>
                    <?php foreach ($sedute_future as $s): ?><option value="<?php echo (int)$s['id']; ?>"><?php echo $h(etichetta_seduta($s)); ?></option><?php endforeach; ?></select>
                <button type="submit" name="assegna_seduta" value="1" class="btn btn-sm btn-dark fw-bold">Assegna</button>
            </span>
        </div>
        <div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-sm align-middle small mb-0">
            <thead class="table-light"><tr><th style="width:28px;"><input type="checkbox" class="form-check-input" id="ddTutte" aria-label="Seleziona tutte"></th><th>Pratica</th><th>Studente</th><th>Stato</th><th>In carico a</th><th>Seduta</th><th>Ultimo aggiornamento</th></tr></thead>
            <tbody>
            <?php if (!$elenco): ?><tr><td colspan="7" class="text-center text-muted py-4">Nessuna pratica con questi filtri.<?php echo !$moduli ? ' Crea prima un modulo online nella scheda "Moduli e documenti".' : ''; ?></td></tr><?php endif; ?>
            <?php foreach ($elenco as $x): ?>
                <tr>
                    <td><input type="checkbox" class="form-check-input dd-sel" name="ids[]" value="<?php echo (int)$x['id']; ?>" aria-label="Seleziona <?php echo $h($x['codice']); ?>"></td>
                    <td><a class="fw-bold text-decoration-none" href="<?php echo $base; ?>&amp;tab=pratiche&amp;id=<?php echo (int)$x['id']; ?>"><?php echo $h($x['modulo_titolo']); ?></a><div class="font-monospace text-secondary" style="font-size:.7rem;"><?php echo $h($x['codice']); ?></div></td>
                    <td><?php echo $h(trim($x['cognome'] . ' ' . $x['nome'])); ?><div class="text-secondary"><?php echo $h($x['email']); ?><?php echo $x['matricola'] !== '' ? ' · ' . $h($x['matricola']) : ''; ?></div></td>
                    <td><?php echo badge_stato_pratica($x['stato']); ?></td>
                    <td><?php $o_x = $x['assegnata_a'] ? operatore_ufficio($conn, (int)$x['assegnata_a']) : null; echo $o_x ? $h($o_x['nominativo']) . '<div class="text-secondary">' . $h(nome_ufficio_operatore($conn, $o_x)) . '</div>' : (in_array($x['stato'], ['accolta', 'respinta', 'chiusa'], true) ? '—' : '<span class="badge bg-warning text-dark">da smistare</span>'); ?></td>
                    <td class="text-nowrap"><?php echo $x['seduta_data'] ? '<a href="' . $base . '&amp;tab=sedute&amp;id=' . (int)$x['seduta_id'] . '">' . date('d/m/Y', strtotime($x['seduta_data'])) . '</a>' : ($x['seduta_id'] ? 'sì' : '—'); ?></td>
                    <td class="text-nowrap"><?php echo date('d/m/Y H:i', strtotime($x['aggiornata_il'] ?: $x['creata_il'])); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div></div>
    </form>
    <script>
    (function () {
        var tutte = document.getElementById('ddTutte');
        if (tutte) tutte.addEventListener('change', function () { document.querySelectorAll('.dd-sel').forEach(function (c) { c.checked = tutte.checked; }); });
        document.querySelectorAll('.dd-esp').forEach(function (a) {
            a.addEventListener('click', function () {
                var ids = Array.prototype.map.call(document.querySelectorAll('.dd-sel:checked'), function (c) { return c.value; });
                a.href = a.href.replace(/&ids=[^&]*/, '') + (ids.length ? '&ids=' + ids.join(',') : '');
            });
        });
    })();
    </script>
    <?php endif; ?>

<?php elseif ($tab === 'sedute'):
    $sel = !empty($_GET['id']) ? seduta_didattica($conn, (int)$_GET['id']) : null;
    $mod_s = !empty($_GET['modifica']) ? seduta_didattica($conn, (int)$_GET['modifica']) : null;
    if ($mod_s || !empty($_GET['nuova'])):
        // Nuova seduta: organo, luogo, o.d.g., presenze, segretario e coordinatore ripresi dall'ultima seduta
        $ult = $sedute[0] ?? null;
        $f = $mod_s ?: ['id' => 0, 'organo' => $ult['organo'] ?? '', 'anno_accademico' => anno_accademico_corrente() . '/' . (anno_accademico_corrente() + 1), 'data' => '', 'ora_inizio' => '', 'ora_fine' => '',
                        'luogo' => $ult['luogo'] ?? '', 'odg' => $ult['odg'] ?? "Comunicazioni\nPratiche studenti\nVarie ed eventuali", 'presenze' => $ult['presenze'] ?? '', 'segretario' => $ult['segretario'] ?? '', 'coordinatore' => $ult['coordinatore'] ?? ''];
        $organi = array_values(array_unique(array_filter(array_column($sedute, 'organo'))));
?>
    <form method="POST" class="card border-0 shadow-sm"><div class="card-body">
        <?php csrf_field(); ?><input type="hidden" name="seduta_id" value="<?php echo (int)$f['id']; ?>">
        <h5 class="fw-bold mb-1"><?php echo $f['id'] ? 'Modifica seduta' : 'Nuova seduta del Consiglio'; ?></h5>
        <?php if (!$f['id'] && $ult): ?><p class="small text-secondary">Ho ripreso organo, luogo, ordine del giorno e presenze dall'ultima seduta: aggiorna quello che cambia.</p><?php endif; ?>
        <div class="row g-2">
            <div class="col-md-9"><label class="form-label small fw-bold" for="sOrg">Organo <span class="text-danger">*</span></label><input type="text" class="form-control" id="sOrg" name="organo" value="<?php echo $h($f['organo']); ?>" list="sOrgList" required maxlength="500" placeholder="Consiglio del Corso di Laurea in …">
                <datalist id="sOrgList"><?php foreach ($organi as $o): ?><option value="<?php echo $h($o); ?>"><?php endforeach; ?></datalist></div>
            <div class="col-md-3"><label class="form-label small fw-bold" for="sAa">Anno accademico</label><select class="form-select" id="sAa" name="anno_accademico"><?php foreach (anni_accademici_scelta() as $a): ?><option<?php echo $a === $f['anno_accademico'] ? ' selected' : ''; ?>><?php echo $a; ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label small fw-bold" for="sData">Data</label><input type="date" class="form-control" id="sData" name="data" value="<?php echo $h($f['data']); ?>"></div>
            <div class="col-md-2"><label class="form-label small fw-bold" for="sOi">Ora di inizio</label><input type="time" class="form-control" id="sOi" name="ora_inizio" value="<?php echo $h($f['ora_inizio']); ?>"></div>
            <div class="col-md-2"><label class="form-label small fw-bold" for="sOf">Ora di fine</label><input type="time" class="form-control" id="sOf" name="ora_fine" value="<?php echo $h($f['ora_fine']); ?>"></div>
            <div class="col-md-5"><label class="form-label small fw-bold" for="sLuogo">Luogo</label><input type="text" class="form-control" id="sLuogo" name="luogo" value="<?php echo $h($f['luogo']); ?>" placeholder="l'aula L3 del cubo 4A" maxlength="255"></div>
            <div class="col-md-6"><label class="form-label small fw-bold" for="sOdg">Ordine del giorno</label><textarea class="form-control" id="sOdg" name="odg" rows="10"><?php echo $h($f['odg']); ?></textarea>
                <div class="form-text">Un punto per riga. Le pratiche vanno nel punto che contiene la parola «pratiche».</div></div>
            <div class="col-md-6"><label class="form-label small fw-bold" for="sPres">Presenze</label><textarea class="form-control font-monospace" id="sPres" name="presenze" rows="10" style="font-size:.8rem;" placeholder="Professori Ordinari e Associati&#10;Prof. Rossi Mario (Coordinatore) | PRESENTE&#10;Prof.ssa Bianchi Anna | GIUSTIFICATA"><?php echo $h($f['presenze']); ?></textarea>
                <div class="form-text">Una riga per persona: <code>Nome | PRESENTE</code> (o ASSENTE, GIUSTIFICATO). Le righe senza «|» diventano titoli di gruppo.</div></div>
            <div class="col-md-6"><label class="form-label small fw-bold" for="sSeg">Segretario verbalizzante</label><input type="text" class="form-control" id="sSeg" name="segretario" value="<?php echo $h($f['segretario']); ?>" placeholder="la Dott.ssa …" maxlength="200"></div>
            <div class="col-md-6"><label class="form-label small fw-bold" for="sCoo">Coordinatore</label><input type="text" class="form-control" id="sCoo" name="coordinatore" value="<?php echo $h($f['coordinatore']); ?>" placeholder="Prof. …" maxlength="200"></div>
        </div>
        <div class="mt-3 d-flex gap-2">
            <button type="submit" name="salva_seduta" value="1" class="btn btn-primary fw-bold"><i class="fa fa-save me-1" aria-hidden="true"></i>Salva</button>
            <a href="<?php echo $base; ?>&amp;tab=sedute<?php echo $f['id'] ? '&amp;id=' . (int)$f['id'] : ''; ?>" class="btn btn-outline-secondary">Annulla</a>
        </div>
    </div></form>
    <?php elseif ($sel):
        $pr_sel = pratiche_per_esportazione($conn, array_column($conn->query("SELECT id FROM pratiche WHERE seduta_id = " . (int)$sel['id'])->fetch_all(MYSQLI_ASSOC), 'id'));
        $libere = $conn->query("SELECT p.id, p.codice, p.cognome, p.nome, p.matricola, p.stato, m.titolo AS modulo_titolo FROM pratiche p JOIN didattica_moduli m ON m.id = p.modulo_id
                                WHERE p.seduta_id IS NULL AND p.stato NOT IN ('chiusa', 'respinta') ORDER BY m.titolo, p.cognome, p.nome")->fetch_all(MYSQLI_ASSOC);
        $url_sel = "$base&tab=sedute&id=" . (int)$sel['id'];
    ?>
    <nav class="small mb-2"><a href="<?php echo $base; ?>&amp;tab=sedute" class="text-decoration-none"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i>Tutte le sedute</a></nav>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body d-flex flex-wrap gap-3 align-items-start">
        <div class="flex-grow-1" style="min-width:260px;">
            <h5 class="fw-bold mb-1"><?php echo $h($sel['organo']); ?></h5>
            <div class="small text-secondary"><?php echo $sel['data'] ? date('d/m/Y', strtotime($sel['data'])) : 'data da definire'; ?><?php echo $sel['ora_inizio'] ? ' · ore ' . $h($sel['ora_inizio']) : ''; ?><?php echo $sel['luogo'] ? ' · ' . $h($sel['luogo']) : ''; ?><?php echo $sel['anno_accademico'] ? ' · a.a. ' . $h($sel['anno_accademico']) : ''; ?></div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-primary fw-bold" href="<?php echo $h($url_sel); ?>&amp;esporta=docx"><i class="fa fa-file-word me-1" aria-hidden="true"></i>Verbale in Word</a>
            <a class="btn btn-success fw-bold" href="<?php echo $h($url_sel); ?>&amp;esporta=xlsx"><i class="fa fa-file-excel me-1" aria-hidden="true"></i>Excel</a>
            <a class="btn btn-outline-secondary" href="<?php echo $base; ?>&amp;tab=sedute&amp;modifica=<?php echo (int)$sel['id']; ?>"><i class="fa fa-pen me-1" aria-hidden="true"></i>Modifica</a>
            <form method="POST" class="m-0"><?php csrf_field(); ?><button type="submit" name="elimina_seduta" value="<?php echo (int)$sel['id']; ?>" class="btn btn-outline-danger" data-confirm="Eliminare la seduta? Le pratiche restano, senza seduta." aria-label="Elimina la seduta"><i class="fa fa-trash" aria-hidden="true"></i></button></form>
        </div>
    </div></div>
    <div class="row g-3">
        <div class="col-lg-7">
            <form method="POST" class="card border-0 shadow-sm"><div class="card-body">
                <?php csrf_field(); ?><input type="hidden" name="torna" value="<?php echo $h($url_sel); ?>"><input type="hidden" name="seduta_id" value="0">
                <h6 class="fw-bold">Pratiche portate in seduta (<?php echo count($pr_sel); ?>)</h6>
                <?php if (!$pr_sel): ?><p class="small text-muted mb-0">Nessuna: aggiungile dall'elenco a destra o dalla scheda Pratiche.</p><?php endif; ?>
                <?php $mod_corr = null; foreach ($pr_sel as $x): if ($mod_corr !== $x['modulo_titolo']): $mod_corr = $x['modulo_titolo']; ?><div class="small fw-bold text-secondary text-uppercase mt-2" style="font-size:.7rem;"><?php echo $h($mod_corr); ?></div><?php endif; ?>
                    <label class="d-flex gap-2 align-items-center small py-1 border-bottom"><input type="checkbox" class="form-check-input" name="ids[]" value="<?php echo (int)$x['id']; ?>">
                        <a href="<?php echo $base; ?>&amp;tab=pratiche&amp;id=<?php echo (int)$x['id']; ?>" class="text-decoration-none"><?php echo $h(trim($x['cognome'] . ' ' . $x['nome'])); ?></a>
                        <span class="text-secondary"><?php echo $h($x['matricola']); ?></span><span class="ms-auto"><?php echo badge_stato_pratica($x['stato']); ?></span>
                        <?php if (trim((string)$x['delibera']) === ''): ?><span class="badge bg-light text-secondary border" title="Si userà la delibera predefinita del modulo">delibera predefinita</span><?php endif; ?></label>
                <?php endforeach; ?>
                <?php if ($pr_sel): ?><button type="submit" name="assegna_seduta" value="1" class="btn btn-sm btn-outline-danger mt-2">Togli le selezionate dalla seduta</button><?php endif; ?>
            </div></form>
        </div>
        <div class="col-lg-5">
            <form method="POST" class="card border-0 shadow-sm"><div class="card-body">
                <?php csrf_field(); ?><input type="hidden" name="torna" value="<?php echo $h($url_sel); ?>"><input type="hidden" name="seduta_id" value="<?php echo (int)$sel['id']; ?>">
                <h6 class="fw-bold">Pratiche ancora senza seduta (<?php echo count($libere); ?>)</h6>
                <div style="max-height:420px;overflow:auto;">
                <?php foreach ($libere as $x): ?>
                    <label class="d-flex gap-2 align-items-center small py-1 border-bottom"><input type="checkbox" class="form-check-input" name="ids[]" value="<?php echo (int)$x['id']; ?>">
                        <span><?php echo $h(trim($x['cognome'] . ' ' . $x['nome'])); ?><span class="d-block text-secondary"><?php echo $h($x['modulo_titolo']); ?></span></span><span class="ms-auto"><?php echo badge_stato_pratica($x['stato']); ?></span></label>
                <?php endforeach; ?>
                <?php if (!$libere): ?><p class="small text-muted mb-0">Tutte le pratiche aperte sono già in una seduta.</p><?php endif; ?>
                </div>
                <?php if ($libere): ?><button type="submit" name="assegna_seduta" value="1" class="btn btn-sm btn-dark fw-bold mt-2"><i class="fa fa-plus me-1" aria-hidden="true"></i>Porta in seduta</button><?php endif; ?>
            </div></form>
        </div>
    </div>
    <?php else: ?>
    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <a href="<?php echo $base; ?>&amp;tab=sedute&amp;nuova=1" class="btn btn-primary btn-sm fw-bold"><i class="fa fa-plus-circle me-1" aria-hidden="true"></i>Nuova seduta</a>
        <span class="small text-secondary">Crea la seduta del Consiglio, porta le pratiche e scarica il verbale in Word già impaginato (logo, o.d.g., presenze, pratiche, firme).</span>
    </div>
    <div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-sm align-middle small mb-0">
        <thead class="table-light"><tr><th>Data</th><th>Organo</th><th>Pratiche</th><th class="text-end">Esporta</th></tr></thead><tbody>
        <?php if (!$sedute): ?><tr><td colspan="4" class="text-center text-muted py-4">Nessuna seduta.</td></tr><?php endif; ?>
        <?php foreach ($sedute as $s): ?>
            <tr><td class="text-nowrap fw-bold"><?php echo $s['data'] ? date('d/m/Y', strtotime($s['data'])) : '—'; ?></td>
                <td><a class="text-decoration-none" href="<?php echo $base; ?>&amp;tab=sedute&amp;id=<?php echo (int)$s['id']; ?>"><?php echo $h($s['organo']); ?></a></td>
                <td><?php echo (int)$s['n_pratiche']; ?></td>
                <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-primary py-0" href="<?php echo $base; ?>&amp;tab=sedute&amp;id=<?php echo (int)$s['id']; ?>&amp;esporta=docx"><i class="fa fa-file-word me-1" aria-hidden="true"></i>Word</a>
                    <a class="btn btn-sm btn-outline-success py-0" href="<?php echo $base; ?>&amp;tab=sedute&amp;id=<?php echo (int)$s['id']; ?>&amp;esporta=xlsx"><i class="fa fa-file-excel me-1" aria-hidden="true"></i>Excel</a></td></tr>
        <?php endforeach; ?>
        </tbody></table></div></div>
    <?php endif; ?>

<?php elseif ($tab === 'ufficio'):
    $persone = $conn->query("SELECT id, cognome, nome, email, ruolo, gruppo FROM personale_ateneo WHERE attivo = 1 AND email <> '' ORDER BY FIELD(gruppo, 'pta', 'docenti', 'altro'), cognome, nome")->fetch_all(MYSQLI_ASSOC);
    $corsi_sc = scelte_anagrafe_didattica($conn, 'corso_studio');
    $html_profilo = function (string $sel) use ($h) { $o = ''; foreach (PROFILI_UFFICIO as $k => $n) $o .= '<option value="' . $k . '"' . ($k === $sel ? ' selected' : '') . '>' . $h($n) . '</option>'; return $o; };
    $html_corsi = function (array $sel) use ($h, $corsi_sc) { $o = ''; foreach ($corsi_sc as $g => $cc) { $o .= '<optgroup label="' . $h($g) . '">'; foreach ($cc as $c) $o .= '<option' . (in_array($c, $sel, true) ? ' selected' : '') . '>' . $h($c) . '</option>'; $o .= '</optgroup>'; } return $o; };
    $gruppi_p = ['pta' => 'Personale tecnico-amministrativo', 'docenti' => 'Docenti', 'altro' => 'Altro personale'];
    $sportelli_uff = sportelli_ufficio_didattica($conn);
    $aree_cal = array_values(array_filter($conn->query("SELECT * FROM pagine_eventi ORDER BY titolo")->fetch_all(MYSQLI_ASSOC), fn($a) => tipo_area($a) === 'calendario'));
    $sono_operatore = utente_operatore_ufficio($conn, $utente_admin);
?>
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm"><div class="card-body">
                <h5 class="fw-bold mb-1"><i class="fa fa-people-group me-1 text-success" aria-hidden="true"></i>Operatori dell'Ufficio didattico</h5>
                <p class="small text-secondary">Scelti dall'anagrafe di Ateneo: entrano nel pannello Didattica con le loro credenziali Unical e gestiscono pratiche, sedute, modulistica e ricevimento. Il <strong>profilo</strong> decide il ruolo nell'iter delle pratiche: il manager le smista, poi passano al tutor dell'internazionalizzazione, al referente del corso (che segue i suoi corsi) o alle carriere studenti secondo il modulo. I compiti decidono chi riceve gli altri avvisi.</p>
                <?php if (!$operatori): ?><div class="alert alert-light border small">Nessun operatore: aggiungi il personale dell'ufficio qui sotto.</div><?php endif; ?>
                <?php foreach ($operatori as $o): ?>
                    <form method="POST" class="border rounded p-2 mb-2">
                        <?php csrf_field(); ?><input type="hidden" name="persona_id" value="<?php echo $h($o['persona_id']); ?>">
                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <div class="flex-grow-1"><strong><?php echo $h($o['nominativo']); ?></strong> <span class="small text-secondary"><?php echo $h($o['email']); ?></span></div>
                            <select class="form-select form-select-sm dd-prof" style="max-width:230px;" name="profilo" aria-label="Profilo nell'iter delle pratiche"><?php echo $html_profilo((string)$o['profilo']); ?></select>
                            <input type="text" class="form-control form-control-sm" style="max-width:180px;" name="ruolo" value="<?php echo $h($o['ruolo']); ?>" placeholder="Ruolo (es. Responsabile)" aria-label="Ruolo">
                        </div>
                        <div class="d-flex flex-wrap gap-3 align-items-center mt-1 small">
                            <?php foreach (COMPITI_UFFICIO as $k => $n): ?><label class="form-check m-0"><input class="form-check-input" type="checkbox" name="compiti[]" value="<?php echo $k; ?>"<?php echo in_array($k, $o['_compiti'], true) ? ' checked' : ''; ?>> <?php echo $h($n); ?></label><?php endforeach; ?>
                            <span class="ms-auto d-flex gap-1"><button type="submit" name="salva_operatore" value="1" class="btn btn-sm btn-outline-primary py-0">Salva</button>
                                <button type="submit" name="togli_operatore" value="<?php echo (int)$o['id']; ?>" class="btn btn-sm btn-outline-danger py-0" data-confirm="Togliere <?php echo $h($o['nominativo']); ?> dall'Ufficio didattico?" aria-label="Togli"><i class="fa fa-user-minus" aria-hidden="true"></i></button></span>
                        </div>
                        <div class="dd-corsi mt-1"<?php echo $o['profilo'] !== 'referente_cdl' ? ' hidden' : ''; ?>><label class="small fw-bold">Corsi di studio seguiti</label><select class="form-select form-select-sm" name="corsi[]" multiple size="4" aria-label="Corsi di studio seguiti"><?php echo $html_corsi(json_decode((string)$o['corsi'], true) ?: []); ?></select></div>
                        <?php if ($o['profilo'] === 'referente_cdl' && ($cs = json_decode((string)$o['corsi'], true))): ?><div class="small text-secondary mt-1"><i class="fa fa-graduation-cap me-1" aria-hidden="true"></i><?php echo $h(implode(' · ', $cs)); ?></div><?php endif; ?>
                    </form>
                <?php endforeach; ?>
                <form method="POST" class="bg-light rounded p-2 mt-3">
                    <?php csrf_field(); ?>
                    <h6 class="fw-bold small mb-2">Aggiungi un operatore dall'anagrafe</h6>
                    <input type="search" class="form-control form-control-sm mb-1" id="opCerca" placeholder="Filtra per cognome…" aria-label="Filtra l'elenco del personale">
                    <select class="form-select form-select-sm mb-2" id="opPersona" name="persona_id" required size="6" aria-label="Persona dell'anagrafe">
                        <?php $g_corr = null; foreach ($persone as $pp): if ($g_corr !== $pp['gruppo']): if ($g_corr !== null) echo '</optgroup>'; $g_corr = $pp['gruppo']; ?><optgroup label="<?php echo $h($gruppi_p[$g_corr] ?? $g_corr); ?>"><?php endif; ?>
                            <option value="<?php echo $h($pp['id']); ?>"><?php echo $h($pp['cognome'] . ' ' . $pp['nome'] . ' · ' . ($pp['ruolo'] ?: $pp['email'])); ?></option>
                        <?php endforeach; if ($g_corr !== null) echo '</optgroup>'; ?>
                    </select>
                    <div class="d-flex flex-wrap gap-3 align-items-center small">
                        <select class="form-select form-select-sm dd-prof" style="max-width:230px;" name="profilo" aria-label="Profilo nell'iter delle pratiche"><?php echo $html_profilo('operatore'); ?></select>
                        <input type="text" class="form-control form-control-sm" style="max-width:200px;" name="ruolo" placeholder="Ruolo (facoltativo)" aria-label="Ruolo">
                        <?php foreach (COMPITI_UFFICIO as $k => $n): ?><label class="form-check m-0"><input class="form-check-input" type="checkbox" name="compiti[]" value="<?php echo $k; ?>"<?php echo $k !== 'bandi' ? ' checked' : ''; ?>> <?php echo $h($n); ?></label><?php endforeach; ?>
                        <button type="submit" name="salva_operatore" value="1" class="btn btn-sm btn-success fw-bold ms-auto"><i class="fa fa-user-plus me-1" aria-hidden="true"></i>Aggiungi</button>
                    </div>
                    <div class="dd-corsi mt-1" hidden><label class="small fw-bold">Corsi di studio seguiti</label><select class="form-select form-select-sm" name="corsi[]" multiple size="4" aria-label="Corsi di studio seguiti"><?php echo $html_corsi([]); ?></select><div class="form-text">Con Ctrl si scelgono più corsi: le pratiche di questi corsi gli vengono proposte per prime.</div></div>
                    <?php if (!$persone): ?><div class="small text-danger mt-1">L'anagrafe del personale è vuota: aggiornala da Gestione del portale → Anagrafi.</div><?php endif; ?>
                </form>
            </div></div>
        </div>
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm"><div class="card-body">
                <h5 class="fw-bold mb-1"><i class="fa fa-user-clock me-1" style="color:#7c3aed;" aria-hidden="true"></i>Ricevimento dell'ufficio</h5>
                <p class="small text-secondary">Sportello a appuntamenti per gli studenti: si prenota dalla pagina pubblica (anche dalla Modulistica); gli operatori impostano giorni, orari e assenze e vedono gli appuntamenti.</p>
                <?php foreach ($sportelli_uff as $sp):
                    $orari = $conn->query("SELECT giorno, dalle, alle FROM risorse_orari WHERE risorsa_id = " . (int)$sp['id'] . " ORDER BY giorno, dalle")->fetch_all(MYSQLI_ASSOC);
                    $n_app = (int)$conn->query("SELECT COUNT(*) n FROM prenotazioni_risorse WHERE risorsa_id = " . (int)$sp['id'] . " AND stato IN ('confermata', 'da_approvare') AND fine >= NOW()")->fetch_assoc()['n']; ?>
                    <div class="border rounded p-2 mb-2">
                        <div class="fw-bold"><?php echo $h($sp['nome']); ?> <?php echo (int)$sp['attiva'] ? '<span class="badge bg-success">prenotabile</span>' : '<span class="badge bg-secondary">non prenotabile</span>'; ?></div>
                        <div class="small text-secondary"><?php echo $h($sp['area_titolo']); ?><?php echo $sp['luogo'] ? ' · ' . $h($sp['luogo']) : ''; ?> · <?php echo $n_app; ?> appuntamenti in programma</div>
                        <div class="small"><?php echo $orari ? $h(implode(', ', array_map(fn($o) => GIORNI_SETTIMANA[(int)$o['giorno']] . ' ' . substr($o['dalle'], 0, 5) . '–' . substr($o['alle'], 0, 5), $orari))) : '<span class="text-danger">Orari non ancora impostati</span>'; ?></div>
                        <div class="d-flex flex-wrap gap-1 mt-2">
                            <?php if ($sono_operatore): ?><a class="btn btn-sm btn-primary fw-bold py-0" href="../ricevimento.php"><i class="fa fa-clock me-1" aria-hidden="true"></i>Orari e appuntamenti</a><?php endif; ?>
                            <?php if ($is_full_admin): ?><a class="btn btn-sm btn-outline-primary py-0" href="risorse.php?p_id=<?php echo (int)$sp['pagina_id']; ?>&amp;modifica=<?php echo (int)$sp['id']; ?>">Impostazioni</a>
                                <a class="btn btn-sm btn-outline-dark py-0" href="prenotazioni_risorse.php?p_id=<?php echo (int)$sp['pagina_id']; ?>">Prenotazioni</a><?php endif; ?>
                            <a class="btn btn-sm btn-outline-secondary py-0" href="../<?php echo $h($sp['area_slug']); ?>.php?risorsa=<?php echo (int)$sp['id']; ?>" target="_blank" rel="noopener">Pagina di prenotazione</a>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (!$sono_operatore && $sportelli_uff): ?><p class="small text-secondary">Gli orari li imposta un operatore dell'ufficio dalla sua Area personale → Il mio ricevimento.</p><?php endif; ?>
                <form method="POST" class="bg-light rounded p-2 mt-2">
                    <?php csrf_field(); ?>
                    <h6 class="fw-bold small mb-2"><?php echo $sportelli_uff ? 'Aggiungi un altro sportello' : 'Crea lo sportello di ricevimento'; ?></h6>
                    <?php if (!$aree_cal): ?><div class="small text-danger">Serve un'area di tipo «Aule, laboratori e sportelli» in Prenotazioni e risorse.</div><?php else: ?>
                    <label class="form-label small fw-bold mb-0" for="spArea">Area di Prenotazioni e risorse</label>
                    <select class="form-select form-select-sm mb-1" id="spArea" name="pagina_id"><?php foreach ($aree_cal as $a): ?><option value="<?php echo (int)$a['id']; ?>"><?php echo $h($a['titolo']); ?></option><?php endforeach; ?></select>
                    <input type="text" class="form-control form-control-sm mb-1" name="nome" value="Ufficio didattico – ricevimento studenti" maxlength="150" aria-label="Nome dello sportello">
                    <input type="text" class="form-control form-control-sm mb-2" name="luogo" placeholder="Luogo (es. Cubo 4B, piano terra)" maxlength="255" aria-label="Luogo">
                    <button type="submit" name="crea_sportello" value="1" class="btn btn-sm fw-bold text-white" style="background:#7c3aed;"><i class="fa fa-plus me-1" aria-hidden="true"></i>Crea</button>
                    <?php endif; ?>
                </form>
            </div></div>
        </div>
    </div>
    <script>
    (function () {
        var c = document.getElementById('opCerca'), s = document.getElementById('opPersona');
        if (!c || !s) return;
        c.addEventListener('input', function () { var q = c.value.toLowerCase(); Array.prototype.forEach.call(s.options, function (o) { o.hidden = q && o.text.toLowerCase().indexOf(q) === -1; }); });
        document.querySelectorAll('.dd-prof').forEach(function (sp) { sp.addEventListener('change', function () { var d = sp.closest('form').querySelector('.dd-corsi'); if (d) d.hidden = sp.value !== 'referente_cdl'; }); });
    })();
    </script>

<?php else: // ── MODULI E DOCUMENTI ──
    $mod_m = !empty($_GET['modifica']) ? modulo_didattica($conn, (int)$_GET['modifica']) : null;
    $mostra_form = $mod_m || !empty($_GET['nuovo']);
    $f = $mod_m ?: ['id' => 0, 'titolo' => '', 'categoria' => '', 'descrizione' => '', 'tipo' => 'documento', 'file_path' => null, 'link' => '', 'campi_json' => null, 'verbale_json' => null, 'destinatari' => 'tutti', 'email_ufficio' => '', 'attivo' => 1, 'ordine' => 0];
    $campi_f = json_decode((string)$f['campi_json'], true) ?: [];
    $v_f = verbale_modulo($f + ['titolo' => '']); $v_raw = json_decode((string)($f['verbale_json'] ?? ''), true) ?: [];
    // Modelli pronti: riempiono titolo, categoria, campi e parte del verbale (poi si modifica tutto)
    $modelli = [
        'tesi' => ['Domanda di lavoro finale (tesi)', 'Lauree', 'Assegnazione dell\'argomento del lavoro finale con relatore ed eventuale correlatore.',
            [['Corso di studio', 'corso_studio', '', 1, 0], ['Anno accademico', 'anno_accademico', '', 1, 0], ['Titolo provvisorio del lavoro finale', 'text', '', 1, 0], ['Insegnamento di riferimento', 'insegnamento', '', 0, 0], ['Relatore', 'docente', '', 1, 0], ['Correlatore', 'docente', '', 0, 0]],
            ['sezione' => 'Domande lavoro finale', 'stile' => 'elenco', 'colonne' => 'COGNOME, NOME, MATRICOLA, RELATORE, CORRELATORE', 'raggruppa' => 'Corso di studio', 'chiusura' => 'Il Consiglio approva.', 'iter' => ['referente_cdl', 'carriere']]],
        'passaggio' => ['Passaggio di corso, trasferimento o rinuncia/decadenza', 'Carriera', 'Richiesta di passaggio di corso, trasferimento in entrata o iscrizione dopo rinuncia o decadenza, con gli esami da convalidare.',
            [['Tipo di richiesta', 'select', 'passaggio di corso di studio, trasferimento in entrata, iscrizione per rinuncia e decadenza', 1, 0], ['Corso di provenienza', 'text', '', 1, 0], ['Ateneo di provenienza', 'text', '', 1, 0],
             ['Corso di destinazione', 'corso_studio', '', 1, 0], ['Anno accademico', 'anno_accademico', '', 1, 0], ['Esami sostenuti', 'tabella', 'Insegnamento sostenuto, CFU, Voto, S.S.D., Data', 1, 0],
             ['Certificato degli esami', 'file', '', 1, 0], ['Quadro delle convalide', 'tabella', 'Insegnamento convalidato, CFU, Voto, Data, S.S.D., Anno insegnamento, Tot CFU insegn., CFU convalidati, CFU da integrare', 0, 1],
             ['Anno di iscrizione deliberato', 'select', 'primo, secondo, terzo', 0, 1]],
            ['sezione' => 'Domande di passaggio, trasferimento e iscrizione', 'stile' => 'scheda',
             'testo' => 'Lo studente {STUDENTE}, matricola {MATRICOLA}, iscritto per l\'a.a. {Anno accademico} al {Corso di provenienza} presso {Ateneo di provenienza}, chiede {Tipo di richiesta} per l\'a.a. {Anno accademico} al {Corso di destinazione}. Valutati i programmi e la loro corrispondenza con gli insegnamenti erogati, il Consiglio approva la richiesta con il seguente quadro di convalide:',
             'delibera' => 'Il Consiglio delibera l\'iscrizione dello studente al {Anno di iscrizione deliberato} anno del {Corso di destinazione}, con attribuzione del piano di studi secondo il regolamento dell\'anno accademico di riferimento.', 'iter' => ['referente_cdl', 'carriere']]],
        'estero' => ['Autorizzazione ad attività all\'estero', 'Mobilità internazionale', 'Richiesta di autorizzazione allo svolgimento di attività formative all\'estero (Erasmus+ e altri programmi).',
            [['Corso di studio', 'corso_studio', '', 1, 0], ['Programma o bando', 'text', '', 1, 0], ['Ente ospitante', 'text', '', 1, 0], ['Paese', 'text', '', 1, 0], ['Dal', 'date', '', 1, 0], ['Al', 'date', '', 1, 0],
             ['Attività', 'select', 'attività di studio, tirocinio, ricerca tesi, tirocinio e ricerca tesi', 1, 0], ['Learning Agreement', 'file', '', 1, 0]],
            ['sezione' => 'Autorizzazione a svolgere attività all\'estero', 'stile' => 'scheda', 'intro' => 'Sono pervenute le richieste di autorizzazione allo svolgimento di attività formative all\'estero da parte degli studenti di seguito elencati.',
             'testo' => '{STUDENTE}, matricola {MATRICOLA}, regolarmente iscritto al {Corso di studio} e vincitore del bando {Programma o bando} presso {Ente ospitante}, {Paese}, orientativamente dal {Dal} al {Al} per {Attività}.', 'delibera' => '',
             'chiusura' => 'Il Consiglio prende atto delle richieste presentate e approva preventivamente le richieste di riconoscimento come da Learning Agreement, previa verifica documentale a fine delle attività.', 'iter' => ['internazionalizzazione', 'referente_cdl', 'carriere']]],
        'rientro' => ['Riconoscimento delle attività svolte all\'estero', 'Mobilità internazionale', 'Richiesta di riconoscimento dei crediti al rientro dalla mobilità.',
            [['Corso di studio', 'corso_studio', '', 1, 0], ['Programma o bando', 'text', '', 1, 0], ['Ente ospitante', 'text', '', 1, 0], ['Periodo', 'text', '', 1, 0],
             ['Attività svolte', 'tabella', 'Attività svolta, CFU / ore, Esito', 1, 0], ['Transcript of Records o attestato', 'file', '', 1, 0],
             ['Riconoscimenti', 'tabella', 'Insegnamento riconosciuto, Anno insegnamento, Tot CFU insegn., CFU riconosciuti, Voto, CFU da integrare, Data', 0, 1]],
            ['sezione' => 'Comunicazione fine attività di studio all\'estero', 'stile' => 'scheda', 'intro' => 'Il Consiglio esamina la documentazione presentata dagli studenti rientrati dalle attività svolte all\'estero, ai fini del riconoscimento dei crediti formativi preventivamente autorizzati.',
             'testo' => '{STUDENTE}, matricola {MATRICOLA}, regolarmente iscritto al {Corso di studio} e vincitore del bando {Programma o bando} presso {Ente ospitante} ({Periodo}), chiede il riconoscimento delle attività svolte:',
             'delibera' => 'Il Consiglio prende atto della documentazione prodotta e approva il riconoscimento richiesto.', 'iter' => ['internazionalizzazione', 'referente_cdl', 'carriere']]],
    ];
?>
    <?php if ($mostra_form): ?>
    <form method="POST" enctype="multipart/form-data" class="card border-0 shadow-sm mb-3" id="ddForm"><div class="card-body">
        <?php csrf_field(); ?><input type="hidden" name="modulo_id" value="<?php echo (int)$f['id']; ?>">
        <h5 class="fw-bold mb-3"><?php echo $f['id'] ? 'Modifica modulo' : 'Nuovo modulo'; ?></h5>
        <?php if (!$f['id']): ?>
        <div class="dd-preset mb-3">
            <div class="small fw-bold mb-1"><i class="fa fa-wand-magic-sparkles me-1 text-success" aria-hidden="true"></i>Parti da un modello pronto (poi modifichi tutto)</div>
            <div class="d-flex flex-wrap gap-1"><?php foreach ($modelli as $k => $mm): ?><button type="button" class="btn btn-sm btn-outline-success dd-modello" data-modello="<?php echo $k; ?>"><?php echo $h($mm[0]); ?></button><?php endforeach; ?></div>
        </div>
        <?php endif; ?>
        <div class="row g-2">
            <div class="col-md-6"><label class="form-label small fw-bold" for="mTit">Titolo <span class="text-danger">*</span></label><input type="text" class="form-control" id="mTit" name="titolo" value="<?php echo $h($f['titolo']); ?>" required maxlength="200"></div>
            <div class="col-md-3"><label class="form-label small fw-bold" for="mCat">Categoria</label><input type="text" class="form-control" id="mCat" name="categoria" value="<?php echo $h($f['categoria']); ?>" list="mCatList" placeholder="es. Tirocini, Piani di studio" maxlength="100">
                <datalist id="mCatList"><?php foreach ($categorie as $c): ?><option value="<?php echo $h($c); ?>"><?php endforeach; ?></datalist></div>
            <div class="col-md-3"><label class="form-label small fw-bold" for="mTipo">Tipo</label><select class="form-select" id="mTipo" name="tipo">
                <option value="documento"<?php echo $f['tipo'] === 'documento' ? ' selected' : ''; ?>>Documento da scaricare</option>
                <option value="online"<?php echo $f['tipo'] === 'online' ? ' selected' : ''; ?>>Modulo online (apre una pratica)</option></select></div>
            <div class="col-12"><label class="form-label small fw-bold" for="mDes">Descrizione e istruzioni</label><textarea class="form-control editor-html" id="mDes" name="descrizione" rows="4"><?php echo $h($f['descrizione']); ?></textarea></div>
            <div class="col-md-6 dd-doc"><label class="form-label small fw-bold" for="mFile">File da scaricare<?php echo $f['file_path'] ? ' (carica solo per sostituirlo)' : ''; ?></label><input type="file" class="form-control" id="mFile" name="file_modulo" accept=".pdf,.doc,.docx,.odt,.xls,.xlsx,.ods,.rtf">
                <?php if ($f['file_path']): ?><div class="form-text"><a href="../<?php echo $h($f['file_path']); ?>" target="_blank" rel="noopener">File attuale</a></div><?php endif; ?></div>
            <div class="col-md-6"><label class="form-label small fw-bold" for="mLink">Link esterno (facoltativo)</label><input type="url" class="form-control" id="mLink" name="link" value="<?php echo $h($f['link']); ?>" placeholder="https://www.unical.it/..."></div>
            <div class="col-md-4"><label class="form-label small fw-bold" for="mDest">Chi può compilare (moduli online)</label><select class="form-select" id="mDest" name="destinatari"><?php foreach (DESTINATARI_MODULO as $k => $n): ?><option value="<?php echo $k; ?>"<?php echo $f['destinatari'] === $k ? ' selected' : ''; ?>><?php echo $h($n); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-5"><label class="form-label small fw-bold" for="mEm">Email che riceve le pratiche (facoltativa)</label><input type="text" class="form-control" id="mEm" name="email_ufficio" value="<?php echo $h($f['email_ufficio']); ?>" placeholder="segreteria.didattica@unical.it"><div class="form-text">Vuoto = operatori dell'Ufficio didattico con il compito «Pratiche».</div></div>
            <div class="col-md-1"><label class="form-label small fw-bold" for="mOrd">Ordine</label><input type="number" class="form-control" id="mOrd" name="ordine" value="<?php echo (int)$f['ordine']; ?>"></div>
            <div class="col-md-2 d-flex align-items-end"><label class="form-check"><input class="form-check-input" type="checkbox" name="attivo" value="1"<?php echo (int)$f['attivo'] ? ' checked' : ''; ?>> Pubblicato</label></div>
        </div>

        <fieldset class="dd-online mt-3 border rounded p-2">
            <legend class="form-label small fw-bold float-none w-auto px-1 mb-1">Campi del modulo online</legend>
            <p class="small text-secondary mb-2">Nome, cognome, email e matricola si prendono dall'accesso. <strong>Guidati</strong>: «Corso di studio», «Insegnamento» e «Docente» propongono i valori delle anagrafi; «Tabella a righe» chiede più righe con le colonne scritte in «Opzioni» (es. <em>Insegnamento, CFU, Voto, Data</em>). Spunta <strong>ufficio</strong> per i campi che compila solo l'ufficio nell'istruttoria (es. quadro delle convalide). Opzioni separate da virgole.</p>
            <div id="ddCampi">
                <?php foreach ($campi_f ?: [['etichetta' => '', 'tipo' => 'text']] as $c): ?>
                <div class="dd-campo<?php echo !empty($c['ufficio']) ? ' uff' : ''; ?>">
                    <input type="text" class="form-control form-control-sm" name="c_etichetta[]" value="<?php echo $h($c['etichetta'] ?? ''); ?>" placeholder="Domanda" aria-label="Domanda">
                    <select class="form-select form-select-sm" name="c_tipo[]" aria-label="Tipo"><?php foreach (TIPI_CAMPO_PRATICA as $k => $n): ?><option value="<?php echo $k; ?>"<?php echo ($c['tipo'] ?? '') === $k ? ' selected' : ''; ?>><?php echo $h($n); ?></option><?php endforeach; ?></select>
                    <input type="text" class="form-control form-control-sm" name="c_opzioni[]" value="<?php echo $h(is_array($c['opzioni'] ?? null) ? implode(', ', $c['opzioni']) : ($c['opzioni'] ?? '')); ?>" placeholder="Opzioni o colonne" aria-label="Opzioni o colonne">
                    <span><input type="hidden" name="c_obbl[]" value="<?php echo !empty($c['obbligatorio']) ? '1' : '0'; ?>"><label class="form-check small text-nowrap m-0"><input class="form-check-input dd-chk" type="checkbox"<?php echo !empty($c['obbligatorio']) ? ' checked' : ''; ?>> obbligatorio</label></span>
                    <span><input type="hidden" name="c_uff[]" value="<?php echo !empty($c['ufficio']) ? '1' : '0'; ?>"><label class="form-check small text-nowrap m-0"><input class="form-check-input dd-chk dd-uff" type="checkbox"<?php echo !empty($c['ufficio']) ? ' checked' : ''; ?>> ufficio</label></span>
                    <input type="text" class="form-control form-control-sm" name="c_aiuto[]" value="<?php echo $h($c['aiuto'] ?? ''); ?>" placeholder="Aiuto (facoltativo)" aria-label="Testo di aiuto">
                    <button type="button" class="btn btn-sm btn-outline-danger dd-togli" aria-label="Togli il campo"><i class="fa fa-times" aria-hidden="true"></i></button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary fw-bold" id="ddAggiungi"><i class="fa fa-plus me-1" aria-hidden="true"></i>Aggiungi campo</button>
        </fieldset>

        <fieldset class="dd-online mt-3 border rounded p-2">
            <legend class="form-label small fw-bold float-none w-auto px-1 mb-1"><i class="fa fa-route me-1 text-primary" aria-hidden="true"></i>Iter della pratica</legend>
            <p class="small text-secondary mb-2">Chi riceve la pratica, in ordine, dopo lo smistamento del manager (es. tutor dell'internazionalizzazione → referente del corso → carriere studenti). Lo studente vede a che punto è. Nessun passo = un solo passo «Operatore».</p>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <?php $iter_f = json_decode((string)($f['iter_json'] ?? ''), true) ?: []; for ($ip = 0; $ip < 4; $ip++): ?>
                    <?php if ($ip): ?><span class="text-secondary" aria-hidden="true">→</span><?php endif; ?>
                    <select class="form-select form-select-sm dd-iter" name="iter[]" style="max-width:240px;" aria-label="Passo <?php echo $ip + 1; ?>"><option value="">— passo <?php echo $ip + 1; ?> —</option>
                        <?php foreach (PROFILI_UFFICIO as $k => $n): if ($k === 'manager') continue; ?><option value="<?php echo $k; ?>"<?php echo ($iter_f[$ip] ?? '') === $k ? ' selected' : ''; ?>><?php echo $h($n); ?></option><?php endforeach; ?></select>
                <?php endfor; ?>
            </div>
        </fieldset>

        <fieldset class="dd-online mt-3 border rounded p-2">
            <legend class="form-label small fw-bold float-none w-auto px-1 mb-1"><i class="fa fa-file-word me-1 text-primary" aria-hidden="true"></i>Nel verbale del Consiglio</legend>
            <p class="small text-secondary mb-2">Segnaposto: <code>{STUDENTE}</code> (COGNOME NOME), <code>{NOME}</code>, <code>{COGNOME}</code>, <code>{MATRICOLA}</code>, <code>{MODULO}</code>, <code>{DATA}</code> e ogni domanda tra graffe, es. <code>{Corso di studio}</code>. <code>**testo**</code> = grassetto. Le tabelle a righe compaiono sotto il testo di ogni pratica.</p>
            <div class="row g-2">
                <div class="col-md-6"><label class="form-label small fw-bold" for="vSez">Titolo della sezione</label><input type="text" class="form-control form-control-sm" id="vSez" name="v_sezione" value="<?php echo $h($v_raw['sezione'] ?? ''); ?>" placeholder="Vuoto = titolo del modulo"></div>
                <div class="col-md-6"><label class="form-label small fw-bold" for="vStile">Impaginazione</label><select class="form-select form-select-sm" id="vStile" name="v_stile">
                    <option value="scheda"<?php echo $v_f['stile'] === 'scheda' ? ' selected' : ''; ?>>Un paragrafo per pratica (con tabelle e delibera)</option>
                    <option value="elenco"<?php echo $v_f['stile'] === 'elenco' ? ' selected' : ''; ?>>Una tabella con una riga per pratica (es. domande di tesi)</option></select></div>
                <div class="col-12"><label class="form-label small fw-bold" for="vIntro">Testo introduttivo (facoltativo)</label><textarea class="form-control form-control-sm" id="vIntro" name="v_intro" rows="2"><?php echo $h($v_raw['intro'] ?? ''); ?></textarea></div>
                <div class="col-md-7 dd-v-scheda"><label class="form-label small fw-bold" for="vTesto">Testo per ogni pratica</label><textarea class="form-control form-control-sm" id="vTesto" name="v_testo" rows="4" placeholder="<?php echo $h(verbale_modulo(['titolo' => ''])['testo']); ?>"><?php echo $h($v_raw['testo'] ?? ''); ?></textarea></div>
                <div class="col-md-5 dd-v-scheda"><label class="form-label small fw-bold" for="vDel">Delibera predefinita per ogni pratica</label><textarea class="form-control form-control-sm" id="vDel" name="v_delibera" rows="4"><?php echo $h($v_raw['delibera'] ?? 'Il Consiglio approva.'); ?></textarea><div class="form-text">Si può cambiare pratica per pratica nell'istruttoria.</div></div>
                <div class="col-md-7 dd-v-elenco"><label class="form-label small fw-bold" for="vCol">Colonne della tabella</label><input type="text" class="form-control form-control-sm" id="vCol" name="v_colonne" value="<?php echo $h($v_raw['colonne'] ?? ''); ?>" placeholder="COGNOME, NOME, MATRICOLA, RELATORE"><div class="form-text">COGNOME, NOME, MATRICOLA, CODICE, DELIBERA o le domande del modulo. Vuoto = tutte.</div></div>
                <div class="col-md-5 dd-v-elenco"><label class="form-label small fw-bold" for="vRag">Raggruppa per la domanda</label><input type="text" class="form-control form-control-sm" id="vRag" name="v_raggruppa" value="<?php echo $h($v_raw['raggruppa'] ?? ''); ?>" placeholder="es. Corso di studio"></div>
                <div class="col-12"><label class="form-label small fw-bold" for="vChi">Testo finale della sezione (facoltativo)</label><textarea class="form-control form-control-sm" id="vChi" name="v_chiusura" rows="2" placeholder="es. Il Consiglio approva."><?php echo $h($v_raw['chiusura'] ?? ''); ?></textarea></div>
            </div>
        </fieldset>
        <div class="mt-3 d-flex gap-2">
            <button type="submit" name="salva_modulo" value="1" class="btn btn-primary fw-bold"><i class="fa fa-save me-1" aria-hidden="true"></i>Salva</button>
            <a href="<?php echo $base; ?>&amp;tab=moduli" class="btn btn-outline-secondary">Annulla</a>
        </div>
    </div></form>
    <script>
    (function () {
        var modelli = <?php echo json_encode($modelli, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        var tipo = document.getElementById('mTipo'), box = document.getElementById('ddCampi'), stile = document.getElementById('vStile');
        function aggiorna() {
            document.querySelectorAll('.dd-online').forEach(function (x) { x.hidden = tipo.value !== 'online'; });
            document.querySelectorAll('.dd-v-scheda').forEach(function (x) { x.hidden = stile.value !== 'scheda'; });
            document.querySelectorAll('.dd-v-elenco').forEach(function (x) { x.hidden = stile.value !== 'elenco'; });
        }
        tipo.addEventListener('change', aggiorna); stile.addEventListener('change', aggiorna); aggiorna();
        function nuovaRiga() {
            var n = box.lastElementChild.cloneNode(true);
            n.querySelectorAll('input[type=text]').forEach(function (i) { i.value = ''; });
            n.querySelectorAll('input[type=hidden]').forEach(function (i) { i.value = '0'; });
            n.querySelectorAll('.dd-chk').forEach(function (i) { i.checked = false; });
            n.classList.remove('uff'); n.querySelector('select').selectedIndex = 0;
            box.appendChild(n); return n;
        }
        document.getElementById('ddAggiungi').addEventListener('click', function () { nuovaRiga().querySelector('input').focus(); });
        box.addEventListener('click', function (e) { var b = e.target.closest('.dd-togli'); if (!b) return; var r = b.closest('.dd-campo'); if (box.children.length > 1) r.remove(); else r.querySelectorAll('input[type=text]').forEach(function (i) { i.value = ''; }); });
        box.addEventListener('change', function (e) {
            if (!e.target.classList.contains('dd-chk')) return;
            e.target.closest('span').querySelector('input[type=hidden]').value = e.target.checked ? '1' : '0';
            if (e.target.classList.contains('dd-uff')) e.target.closest('.dd-campo').classList.toggle('uff', e.target.checked);
        });
        document.querySelectorAll('.dd-modello').forEach(function (b) {
            b.addEventListener('click', function () {
                var m = modelli[b.dataset.modello]; if (!m) return;
                document.getElementById('mTit').value = m[0]; document.getElementById('mCat').value = m[1]; tipo.value = 'online';
                if (window.tinymce && tinymce.get('mDes')) tinymce.get('mDes').setContent('<p>' + m[2] + '</p>'); else document.getElementById('mDes').value = '<p>' + m[2] + '</p>';
                while (box.children.length > 1) box.lastElementChild.remove();
                m[3].forEach(function (c, i) {
                    var r = i === 0 ? box.firstElementChild : nuovaRiga();
                    r.querySelector('[name="c_etichetta[]"]').value = c[0]; r.querySelector('select').value = c[1]; r.querySelector('[name="c_opzioni[]"]').value = c[2];
                    var chk = r.querySelectorAll('.dd-chk'); chk[0].checked = !!c[3]; chk[1].checked = !!c[4];
                    r.querySelector('[name="c_obbl[]"]').value = c[3] ? '1' : '0'; r.querySelector('[name="c_uff[]"]').value = c[4] ? '1' : '0'; r.classList.toggle('uff', !!c[4]);
                });
                var v = m[4];
                document.getElementById('vSez').value = v.sezione || ''; stile.value = v.stile || 'scheda';
                document.getElementById('vIntro').value = v.intro || ''; document.getElementById('vTesto').value = v.testo || '';
                document.getElementById('vDel').value = v.delibera !== undefined ? v.delibera : 'Il Consiglio approva.';
                document.getElementById('vCol').value = v.colonne || ''; document.getElementById('vRag').value = v.raggruppa || ''; document.getElementById('vChi').value = v.chiusura || '';
                document.querySelectorAll('.dd-iter').forEach(function (s, i) { s.value = (v.iter || [])[i] || ''; });
                aggiorna(); document.getElementById('mTit').focus();
            });
        });
    })();
    </script>
    <?php else: ?>
        <a href="<?php echo $base; ?>&amp;tab=moduli&amp;nuovo=1" class="btn btn-primary btn-sm fw-bold mb-3"><i class="fa fa-plus-circle me-1" aria-hidden="true"></i>Nuovo modulo o documento</a>
    <?php endif; ?>

    <?php if (!$moduli): ?>
        <div class="card border-0 shadow-sm"><div class="card-body text-muted">Nessun modulo: crea il primo documento da scaricare o il primo modulo online (anche da un modello pronto: tesi, passaggi di corso, attività all'estero).</div></div>
    <?php endif; ?>
    <?php foreach ($categorie as $cat): ?>
        <h6 class="fw-bold text-secondary text-uppercase mt-3 mb-2" style="font-size:.75rem;letter-spacing:.05em;"><?php echo $h($cat); ?></h6>
        <div class="card border-0 shadow-sm"><ul class="list-group list-group-flush small">
        <?php foreach ($moduli as $m): if ($m['categoria'] !== $cat) continue; $cm = campi_modulo($m['campi_json']); ?>
            <li class="list-group-item d-flex flex-wrap align-items-center gap-2">
                <i class="fa <?php echo $m['tipo'] === 'online' ? 'fa-pen-to-square text-success' : 'fa-file-arrow-down text-primary'; ?>" aria-hidden="true"></i>
                <strong><?php echo $h($m['titolo']); ?></strong>
                <span class="text-secondary"><?php echo $m['tipo'] === 'online' ? 'Modulo online · ' . count(campi_studente($cm)) . ' campi' . (count(campi_ufficio($cm)) ? ' + ' . count(campi_ufficio($cm)) . ' dell\'ufficio' : '') . ' · ' . (int)$m['n_pratiche'] . ' pratiche' . ($m['n_aperte'] ? ' (' . (int)$m['n_aperte'] . ' aperte)' : '') : ($m['file_path'] ? 'Documento' : ($m['link'] ? 'Link' : 'Documento senza file')); ?></span>
                <?php if (!(int)$m['attivo']): ?><span class="badge bg-secondary">non pubblicato</span><?php endif; ?>
                <span class="ms-auto d-flex gap-1">
                    <?php if ($m['tipo'] === 'online' && $m['n_pratiche']): ?><a class="btn btn-sm btn-outline-dark py-0" href="<?php echo $base; ?>&amp;tab=pratiche&amp;modulo=<?php echo (int)$m['id']; ?>&amp;stato=tutte">Pratiche</a><?php endif; ?>
                    <?php if ($m['tipo'] === 'online'): ?><a class="btn btn-sm btn-outline-secondary py-0" href="../modulo.php?id=<?php echo (int)$m['id']; ?>" target="_blank" rel="noopener">Anteprima</a><?php endif; ?>
                    <a class="btn btn-sm btn-outline-primary py-0" href="<?php echo $base; ?>&amp;tab=moduli&amp;modifica=<?php echo (int)$m['id']; ?>">Modifica</a>
                    <form method="POST" class="m-0"><?php csrf_field(); ?><button type="submit" name="elimina_modulo" value="<?php echo (int)$m['id']; ?>" class="btn btn-sm btn-outline-danger py-0" data-confirm="Eliminare il modulo? Se ha pratiche viene solo nascosto." aria-label="Elimina"><i class="fa fa-trash" aria-hidden="true"></i></button></form>
                </span>
            </li>
        <?php endforeach; ?>
        </ul></div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require_once 'admin_footer.php'; ?>
