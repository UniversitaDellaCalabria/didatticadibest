<?php
// master_template.php - Motore Grafico Multi-Layout
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once 'config.php'; // apre la sessione con i parametri sicuri del cookie
require_once 'functions.php';

sync_sso_user($conn);

$current_filename = $page_slug ?? basename($_SERVER['PHP_SELF'], '.php');
$utente_logged = !empty($_SESSION['utente_id']);
$utente_ruolo_id = (int)($_SESSION['utente_ruolo_id'] ?? 5);

$stmt_p = $conn->prepare("SELECT * FROM pagine_eventi WHERE slug = ? LIMIT 1");
$stmt_p->bind_param("s", $current_filename);
$stmt_p->execute();
$res_p = $stmt_p->get_result();
if (!$res_p || $res_p->num_rows == 0) { $res_p = $conn->query("SELECT * FROM pagine_eventi ORDER BY id ASC LIMIT 1"); }
$page_cfg = ($res_p && $res_p->num_rows > 0) ? $res_p->fetch_assoc() : [];
$p_id = (int)($page_cfg['id'] ?? 1);

// CONTROLLO MANUTENZIONE E GESTORI
$is_visibile = (int)($page_cfg['visibile'] ?? 1);
$is_gestore_o_admin = false;
$is_admin_globale = false;

if ($utente_logged) {
    $sec_roles = isset($_SESSION['utente_ruoli_secondari']) ? explode(',', $_SESSION['utente_ruoli_secondari']) : [];
    $is_admin_globale = $utente_ruolo_id === 1 || in_array('1', $sec_roles, true);
    $is_gestore_o_admin = $is_admin_globale
        || in_array((int)$_SESSION['utente_id'], ids_gestori_da_campi($page_cfg['gestore_utente_id'] ?? 0, $page_cfg['gestori_utenti_ids'] ?? '', $page_cfg['permessi_gestori_json'] ?? ''), true);
}

$banner_manutenzione_admin = "";
if ($is_visibile === 0) {
    if (!$is_gestore_o_admin) {
        require_once 'header.php';
        echo '<div class="container my-5 text-center" style="max-width: 600px;">
                <div class="card shadow-sm p-5 border-top border-warning border-4" style="border-radius: 12px; margin-top: 80px;">
                    <i class="fa fa-tools text-warning mb-3" style="font-size: 4rem;"></i>
                    <h3 class="fw-bold text-dark">Pagina in Manutenzione</h3>
                    <p class="text-secondary mt-2 fs-5">L\'area è temporaneamente non disponibile.<br>Riprova più tardi.</p>
                </div>
              </div>';
        require_once 'footer.php'; exit;
    } else {
        $banner_manutenzione_admin = "<div class='alert alert-warning alert-dismissible fade show fw-bold text-center my-3 shadow-sm border-warning' style='border-radius: 8px;' role='alert'><i class='fa fa-exclamation-triangle me-2 fs-5 align-middle'></i> <strong>AVVISO AMMINISTRATORE:</strong> Questa pagina è IN MANUTENZIONE / NASCOSTA agli utenti pubblici.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
    }
}

// Aree "Calendari e risorse": pagina a slot (aule, laboratori, sportelli) invece di eventi e turni
if (tipo_area($page_cfg) === 'calendario') { require __DIR__ . '/calendario_area.php'; exit; }

$logged_u_info = null; $val_nome = ''; $val_cognome = ''; $val_email = ''; $val_matricola = '';
if ($utente_logged) {
    $u_id_logged = (int)$_SESSION['utente_id'];
    $stmt_u = $conn->prepare("SELECT * FROM utenti WHERE id = ? LIMIT 1");
    $stmt_u->bind_param("i", $u_id_logged); $stmt_u->execute(); $res_curr_u = $stmt_u->get_result();
    if ($res_curr_u && $res_curr_u->num_rows > 0) {
        $logged_u_info = $res_curr_u->fetch_assoc();
        $val_nome = $logged_u_info['nome']; $val_cognome = $logged_u_info['cognome']; $val_email = $logged_u_info['email'];
        $val_matricola = !empty($logged_u_info['matricola_studente']) ? $logged_u_info['matricola_studente'] : (!empty($logged_u_info['matricola_dipendente']) ? $logged_u_info['matricola_dipendente'] : ($logged_u_info['matricola'] ?? ''));
    }
}

// ELABORAZIONE POST PRENOTAZIONE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['invia_prenotazione'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $turno_id = (int)$_POST['turno_id']; $nome = trim($_POST['nome'] ?? ''); $cognome = trim($_POST['cognome'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? '')); $matricola = trim($_POST['matricola'] ?? '');
    $num_posti = isset($_POST['num_posti']) ? max(1, (int)$_POST['num_posti']) : 1;
    $u_id_bind = $utente_logged ? (int)$_SESSION['utente_id'] : null;

    // Il turno deve appartenere a quest'area. Dopo l'invio si torna alla scheda del progetto
    // se la prenotazione riguarda un progetto, altrimenti alla pagina dell'area.
    $r_rit = $conn->query("SELECT e.id, e.tipo FROM turni t JOIN eventi e ON t.evento_id = e.id WHERE t.id = $turno_id AND e.pagina_id = $p_id AND e.archiviato = 0 LIMIT 1");
    $ev_rit = $r_rit ? $r_rit->fetch_assoc() : null;
    if (!$ev_rit) { header("Location: {$current_filename}.php?status=error"); exit; }
    // Ritorno: scheda del progetto, scheda dell'evento (se la prenotazione parte da lì) oppure pagina dell'area
    if (($ev_rit['tipo'] ?? '') === 'progetto') $url_ritorno = "{$current_filename}.php?progetto=" . (int)$ev_rit['id'] . "&";
    elseif ((int)($_POST['da_scheda'] ?? 0) === (int)$ev_rit['id']) $url_ritorno = "{$current_filename}.php?evento=" . (int)$ev_rit['id'] . "&";
    else $url_ritorno = "{$current_filename}.php?";

    // Prenotazione pubblica (senza accesso): trappola per i robot, domanda di controllo e limite per indirizzo IP
    if (!$utente_logged) {
        $err_captcha = null;
        if (trim((string)($_POST['sito_web'] ?? '')) !== '') $err_captcha = "Prenotazione non registrata: riprova.";
        elseif (!check_rate_limit($conn, 'prenotazione_pubblica', 20, 3600)) $err_captcha = "Troppe prenotazioni da questa connessione: riprova tra un'ora o accedi con SPID/CIE.";
        else $err_captcha = captcha_verifica((string)($_POST['captcha_id'] ?? ''), (string)($_POST['captcha_risposta'] ?? ''));
        if ($err_captcha !== null) {
            $_SESSION['errore_prenotazione'] = $err_captcha;
            header("Location: {$url_ritorno}status=captcha"); exit;
        }
    }

    if (empty($nome) || empty($cognome) || empty($email)) { header("Location: {$url_ritorno}status=error"); exit; }

    // L'email si scrive a mano (non viene dall'accesso) e si ripete: le ricevute e gli attestati arrivano lì
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $_SESSION['errore_prenotazione'] = "Indirizzo email non valido."; header("Location: {$url_ritorno}status=email"); exit; }
    if (isset($_POST['email_conferma']) && strtolower(trim((string)$_POST['email_conferma'])) !== $email) { $_SESSION['errore_prenotazione'] = "Le due email non coincidono: riscrivile con attenzione."; header("Location: {$url_ritorno}status=email"); exit; }

    // CONTROLLO DUPLICATI (Email o Matricola)
    $stmt_dup = $conn->prepare("SELECT id FROM prenotazioni WHERE turno_id = ? AND (email = ? OR (matricola != '' AND matricola = ?))");
    $stmt_dup->bind_param("iss", $turno_id, $email, $matricola); 
    $stmt_dup->execute();
    if ($stmt_dup->get_result()->num_rows > 0) { header("Location: {$url_ritorno}status=dup"); exit; }

    $stmt_t = $conn->prepare("SELECT t.*, e.titolo as evento_titolo, e.luogo, e.pagina_id, e.ruolo_accesso_id, e.richiede_prenotazione, e.tipo AS evento_tipo FROM turni t JOIN eventi e ON t.evento_id = e.id WHERE t.id = ? LIMIT 1");
    $stmt_t->bind_param("i", $turno_id); $stmt_t->execute(); $res_t = $stmt_t->get_result();

    if ($res_t && $t_info = $res_t->fetch_assoc()) {
        // Chi può prenotare: controllato anche qui, non solo nascondendo il pulsante
        if ((int)($t_info['richiede_prenotazione'] ?? 1) === 0) { header("Location: {$url_ritorno}status=error"); exit; }
        $ruolo_ev = (int)($t_info['ruolo_accesso_id'] ?? 0);
        if ($ruolo_ev !== 0) {
            $sec_roles_pr = isset($_SESSION['utente_ruoli_secondari']) ? explode(',', $_SESSION['utente_ruoli_secondari']) : [];
            $ruolo_ok_pr = $utente_logged && ($ruolo_ev === -1 || $utente_ruolo_id === $ruolo_ev || in_array((string)$ruolo_ev, $sec_roles_pr, true)
                                             || $utente_ruolo_id === 1 || in_array('1', $sec_roles_pr, true));
            if (!$ruolo_ok_pr) { header("Location: {$url_ritorno}status=riservato"); exit; }
        }
        // Progetti: si partecipa a una sola edizione dello stesso progetto (anche la lista d'attesa conta)
        if (($t_info['evento_tipo'] ?? '') === 'progetto') {
            $ev_pr_id = (int)$t_info['evento_id']; $u_chk = (int)($u_id_bind ?? 0);
            $stmt_ed = $conn->prepare("SELECT 1 FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id
                                       WHERE t.evento_id = ? AND pr.turno_id <> ? AND IFNULL(pr.stato, 'confermata') NOT IN ('annullata', 'rifiutata', 'scaduta')
                                         AND ((? > 0 AND pr.utente_id = ?) OR LOWER(pr.email) = ?) LIMIT 1");
            $stmt_ed->bind_param("iiiis", $ev_pr_id, $turno_id, $u_chk, $u_chk, $email);
            $stmt_ed->execute();
            if ($stmt_ed->get_result()->num_rows > 0) { header("Location: {$url_ritorno}status=altra_edizione"); exit; }
        }

        $now = date('Y-m-d H:i:s');
        $apertura_ok = empty($t_info['data_apertura']) || ($now >= $t_info['data_apertura']);
        $chiusura_ok = empty($t_info['data_chiusura']) || ($now <= $t_info['data_chiusura']);

        if (!$apertura_ok) { header("Location: {$url_ritorno}status=notopened"); exit; }
        elseif (!$chiusura_ok) { header("Location: {$url_ritorno}status=closed"); exit; }

        $limite_isc = $page_cfg['limite_iscrizioni'] ?? 'nessuno';
        if ($limite_isc !== 'nessuno') {
            // Lock per persona+area fino a fine inserimento: due invii simultanei (doppio clic,
            // due schede, turni diversi) non possono superare entrambi il controllo.
            // Il lock FOR UPDATE sotto copre solo il singolo turno. Rilasciato a fine script.
            $lock_iscr = 'dibest_iscr_' . (int)$t_info['pagina_id'] . '_' . md5($email);
            $stmt_lk = $conn->prepare("SELECT GET_LOCK(?, 10)");
            $stmt_lk->bind_param("s", $lock_iscr); $stmt_lk->execute();
            if ((int)($stmt_lk->get_result()->fetch_row()[0] ?? 0) !== 1) { header("Location: {$url_ritorno}status=error"); exit; }

            $blocco = trova_iscrizione_vincolata($conn, $limite_isc, (int)$t_info['pagina_id'], (int)$t_info['evento_id'], (int)($u_id_bind ?? 0), $email, $matricola);
            if ($blocco) {
                header("Location: {$url_ritorno}status=limite&ev=" . urlencode($blocco['evento_titolo'])); exit;
            }
        }

        $sigla = strtoupper(substr($current_filename, 0, 2));
        $codice_p = $sigla . '-' . strtoupper(bin2hex(random_bytes(4)));
        
        $custom_data = [];
        foreach ($_POST as $k => $v) {
            if (strpos($k, 'custom_') === 0) { $custom_data[str_replace('custom_', '', $k)] = is_array($v) ? implode(', ', $v) : trim($v); }
        }
        // Campo "Scuola": nome ufficiale e codice meccanografico se scelta dall'anagrafe
        $scuola_codice_pr = applica_scuola_scelta($conn, $custom_data, $_POST['scuola_codice'] ?? []);

        // Prenotazioni di classe (progetti per le scuole, eventi con attestati per gli studenti):
        // numero di studenti obbligatorio e dentro i limiti del progetto o del turno
        $ev_pr = (int)$t_info['evento_id'];
        $dett_pr = get_dettagli_progetti($conn, [$ev_pr])[$ev_pr] ?? null;
        $is_prog_pr = ($t_info['evento_tipo'] ?? '') === 'progetto';
        if (prenotazione_di_classe($is_prog_pr, $dett_pr)) {
            $err_studenti = valida_partecipanti_progetto($custom_data, ($dett_pr ?? []) + ['per_scuole' => 1], $t_info);
            if ($err_studenti !== null) {
                $_SESSION['errore_prenotazione'] = $err_studenti;
                if (isset($lock_iscr)) { $conn->query("SELECT RELEASE_LOCK('" . $conn->real_escape_string($lock_iscr) . "')"); }
                header("Location: {$url_ritorno}status=studenti"); exit;
            }
        }

        // Convenzione con la scuola: risposta obbligatoria se il progetto/evento la chiede.
        // "No" → la prenotazione resta da approvare finché la convenzione non arriva.
        $conv_pr = null; $conv_rinnovo = false;
        if ((int)($dett_pr['convenzione'] ?? 0) === 1) {
            $conv_pr = in_array($_POST['convenzione'] ?? '', ['si', 'no'], true) ? $_POST['convenzione'] : null;
            if ($conv_pr === null) {
                $_SESSION['errore_prenotazione'] = "Indica se la scuola ha già stipulato la convenzione con il Dipartimento.";
                if (isset($lock_iscr)) { $conn->query("SELECT RELEASE_LOCK('" . $conn->real_escape_string($lock_iscr) . "')"); }
                header("Location: {$url_ritorno}status=studenti"); exit;
            }
            // Scuola scelta dall'anagrafe: conta il registro delle convenzioni, che deve coprire tutto il periodo dell'attività.
            // Coperto → non serve attendere. Registrata ma non copre il periodo → ne va stipulata una nuova, anche se ha risposto "Sì".
            if ($scuola_codice_pr) {
                [$att_dal_pr, $att_al_pr] = periodo_attivita($dett_pr['data_inizio'] ?? null, $dett_pr['data_fine'] ?? null, $t_info['data_turno'] ?? null);
                if (convenzione_valida($conn, $scuola_codice_pr, false, $att_dal_pr, $att_al_pr)) $conv_pr = 'ricevuta';
                elseif ($conv_pr === 'si' && convenzioni_della_scuola($conn, $scuola_codice_pr)) { $conv_pr = 'no'; $conv_rinnovo = true; }
            }
        }

        if (!empty($_FILES)) {
            $allowed_mimes = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
            $allowed_exts = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx'];

            foreach ($_FILES as $k => $f) {
                if (strpos($k, 'custom_') === 0) {
                    $field_name = str_replace('custom_', '', $k); $file_paths = [];
                    $upload_dir = __DIR__ . '/uploads/allegati_prenotazioni/';
                    if (!file_exists($upload_dir)) mkdir($upload_dir, 0755, true);

                    $count_files = is_array($f['name']) ? count($f['name']) : 1;
                    for ($i = 0; $i < $count_files; $i++) {
                        $tmp_name = is_array($f['tmp_name']) ? $f['tmp_name'][$i] : $f['tmp_name'];
                        $name = is_array($f['name']) ? $f['name'][$i] : $f['name'];
                        $error = is_array($f['error']) ? $f['error'][$i] : $f['error'];

                        if (!empty($name) && $error === UPLOAD_ERR_OK) {
                            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                            $finfo = finfo_open(FILEINFO_MIME_TYPE); $mime = finfo_file($finfo, $tmp_name); finfo_close($finfo);
                            if (in_array($ext, $allowed_exts) && in_array($mime, $allowed_mimes)) {
                                $filename = bin2hex(random_bytes(16)) . '.' . $ext;
                                if (move_uploaded_file($tmp_name, $upload_dir . $filename)) { $file_paths[] = 'uploads/allegati_prenotazioni/' . $filename; }
                            }
                        }
                    }
                    if (!empty($file_paths)) { $custom_data[$field_name] = implode(', ', $file_paths); }
                }
            }
        }

        $json_custom_bind = !empty($custom_data) ? json_encode($custom_data, JSON_UNESCAPED_UNICODE) : null;

        // =====================================================================
        // FASE 2: SEZIONE CRITICA - transazione + lock pessimistico anti-overbooking
        // Il lock FOR UPDATE tiene in coda le richieste concorrenti sullo stesso
        // turno finché questa transazione non fa commit/rollback, così il conteggio
        // posti letto qui dentro è sempre quello reale, mai "vecchio".
        // =====================================================================
        $conn->begin_transaction();
        $insert_ok = false;
        try {
            $occupati = getPostiOccupati($conn, $turno_id, true);

            // Anche 'da_approvare' occupa un posto: prima si verifica la capienza
            $stato_prenotazione = ((isset($t_info['richiede_approvazione']) && $t_info['richiede_approvazione'] == 1) || $conv_pr === 'no') ? 'da_approvare' : 'confermata';
            if (($occupati + $num_posti) > $t_info['max_posti']) {
                if (isset($t_info['abilita_lista_attesa']) && $t_info['abilita_lista_attesa'] == 1) {
                    $stato_prenotazione = 'in_attesa';
                } else {
                    $conn->rollback();
                    header("Location: {$url_ritorno}status=full"); exit;
                }
            }

            $stmt_ins = $conn->prepare("INSERT INTO prenotazioni (turno_id, utente_id, codice_prenotazione, stato, num_posti, nome, cognome, email, matricola, dati_custom_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt_ins->bind_param("iississsss", $turno_id, $u_id_bind, $codice_p, $stato_prenotazione, $num_posti, $nome, $cognome, $email, $matricola, $json_custom_bind);
            $insert_ok = $stmt_ins->execute();
            $nuovo_pr_id = (int)$stmt_ins->insert_id;

            if (!$insert_ok) {
                throw new Exception($conn->error ?: 'Errore sconosciuto in fase di inserimento prenotazione');
            }

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            error_log("[Prenotazione][turno_id=$turno_id] Transazione fallita: " . $e->getMessage());
            header("Location: {$url_ritorno}status=error"); exit;
        }
        // Posto confermato: le altre liste d'attesa della persona nell'ambito del limite decadono
        if ($insert_ok && $stato_prenotazione === 'confermata') { decadi_attese_vincolate($conn, (int)$stmt_ins->insert_id); }
        if (isset($lock_iscr)) { $conn->query("SELECT RELEASE_LOCK('" . $conn->real_escape_string($lock_iscr) . "')"); }
        if ($insert_ok && $conv_pr !== null) {
            $st_cv = $conn->prepare("UPDATE prenotazioni SET convenzione = ? WHERE id = ?");
            $st_cv->bind_param("si", $conv_pr, $nuovo_pr_id); $st_cv->execute();
        }
        // Scuola dall'anagrafe: codice sulla prenotazione (report) e sul profilo del docente (proposta la prossima volta)
        if ($insert_ok && $scuola_codice_pr) {
            $st_sc = $conn->prepare("UPDATE prenotazioni SET scuola_codice = ? WHERE id = ?");
            $st_sc->bind_param("si", $scuola_codice_pr, $nuovo_pr_id); $st_sc->execute();
            if ($u_id_bind) { $st_su = $conn->prepare("UPDATE utenti SET scuola_codice = ? WHERE id = ?"); $st_su->bind_param("si", $scuola_codice_pr, $u_id_bind); $st_su->execute(); }
        }
        // ================= FINE SEZIONE CRITICA =================
        // Da qui in poi: email/notifiche, FUORI dalla transazione (non tengono bloccata la riga).
        
        if ($insert_ok) {
            $data_formatted = implode(' · ', array_filter([$t_info['nome_turno'] ?? '', !empty($t_info['data_turno']) ? date('d/m/Y', strtotime($t_info['data_turno'])) : '']));
            $ora_formatted = orario_turno($t_info) ?: 'da definire';
            $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
            $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $base_dir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
            $link_ricevuta_url = $proto . $domain . $base_dir . "/stampa_ricevuta.php?code=" . urlencode($codice_p);
            $btn_ricevuta_html = "<p style='margin-top:15px;'><a href='$link_ricevuta_url' target='_blank' style='background:#B30000; color:#ffffff; padding:10px 18px; text-decoration:none; border-radius:6px; font-weight:bold;'>📄 Scarica / Stampa Ricevuta PDF</a></p>";
            $gcal_link = getGoogleCalendarUrl($t_info['evento_titolo'], $t_info['data_turno'], $t_info['orario_inizio'], $t_info['orario_fine'], $t_info['luogo'], "Prenotazione $codice_p ($num_posti posti)");
            $ics_link = $proto . $domain . $base_dir . "/genera_ics.php?t_id=" . $t_info['id'];
            $cal_html_buttons = empty($t_info['data_turno']) ? '' : "<p style='margin-top:15px;'><a href='$gcal_link' target='_blank' style='background:#4285F4; color:#fff; padding:8px 14px; text-decoration:none; border-radius:4px; font-weight:bold;'>📅 Aggiungi a Google Calendar</a> <a href='$ics_link' style='background:#334155; color:#fff; padding:8px 14px; text-decoration:none; border-radius:4px; font-weight:bold;'>📥 Scarica File .ics</a></p>";
            $r_find = ['{NOME}', '{COGNOME}', '{MATRICOLA}', '{TITOLO_EVENTO}', '{DATA_TURNO}', '{ORARIO_TURNO}', '{LUOGO}', '{CODICE_PRENOTAZIONE}', '{LINK_RICEVUTA}'];
            $r_repl = [$nome, $cognome, $matricola, $t_info['evento_titolo'], $data_formatted, $ora_formatted, $t_info['luogo'], $codice_p, $btn_ricevuta_html];
            $sys_email = $conn->query("SELECT * FROM impostazioni_sistema WHERE id = 1")->fetch_assoc();

            if ($stato_prenotazione === 'da_approvare' && $conv_pr === 'no') {
                $obj_tpl = "Prenotazione in attesa della convenzione: " . $t_info['evento_titolo'];
                $body_tpl = "<p>Gentile <strong>{NOME} {COGNOME}</strong>,</p><p>abbiamo ricevuto la prenotazione per <strong>{TITOLO_EVENTO}</strong>.</p><p>📅 {DATA_TURNO} | 🕒 {ORARIO_TURNO}<br>🎟️ Codice: <strong>{CODICE_PRENOTAZIONE}</strong></p>"
                          . ($conv_rinnovo ? "<p style='margin:0 0 8px;'><strong>La convenzione della scuola registrata al Dipartimento non copre tutto il periodo dell'attività: va stipulata una nuova convenzione.</strong></p>" : '') . html_istruzioni_convenzione($page_cfg, true, $codice_p) . "{LINK_RICEVUTA}";
            } elseif ($stato_prenotazione === 'da_approvare') {
                $obj_tpl = "Richiesta Ricevuta (In valutazione): " . $t_info['evento_titolo'];
                $body_tpl = "<p>Gentile <strong>{NOME} {COGNOME}</strong>,</p><p>La tua richiesta per <strong>$num_posti posti</strong> all'evento <strong>{TITOLO_EVENTO}</strong> è in fase di valutazione.</p><p>📅 {DATA_TURNO} | 🕒 {ORARIO_TURNO}<br>🎟️ Codice: <strong>{CODICE_PRENOTAZIONE}</strong></p>{LINK_RICEVUTA}";
            } elseif ($stato_prenotazione === 'in_attesa') {
                $obj_tpl = "Lista d'Attesa: " . $t_info['evento_titolo'];
                $body_tpl = "<p>Gentile <strong>{NOME} {COGNOME}</strong>,</p><p>Sei stato inserito in <strong>lista d'attesa</strong> per l'evento <strong>{TITOLO_EVENTO}</strong>.</p><p>📅 {DATA_TURNO} | 🕒 {ORARIO_TURNO}<br>🎟️ Codice: <strong>{CODICE_PRENOTAZIONE}</strong></p>{LINK_RICEVUTA}" . $cal_html_buttons;
                // Senza convenzione: meglio avviarla subito, così se il posto si libera la prenotazione è confermabile
                if ($conv_pr === 'no') $body_tpl .= "<p style='margin-top:16px;'><strong>Convenzione:</strong> la scuola non l'ha ancora stipulata. Ti consigliamo di avviarla già ora.</p>" . html_istruzioni_convenzione($page_cfg, true, $codice_p);
            } else {
                $obj_tpl  = $sys_email['email_conferma_oggetto'] ?: 'Conferma Prenotazione Eventi';
                $body_tpl = ($sys_email['email_conferma_corpo'] ?: "<p>Gentile <strong>{NOME} {COGNOME}</strong>,</p><p>Prenotazione confermata per <strong>{TITOLO_EVENTO}</strong>.</p><p>📅 {DATA_TURNO} | 🕒 {ORARIO_TURNO}<br>🎟️ Codice: <strong>{CODICE_PRENOTAZIONE}</strong></p>{LINK_RICEVUTA}") . $cal_html_buttons;
            }
            
            // Attestati per la classe: chi ha prenotato inserisce l'elenco degli studenti dall'Area personale
            if ($stato_prenotazione === 'confermata' && attestati_di_classe(['evento_tipo' => $t_info['evento_tipo'] ?? '', 'attestati' => $dett_pr['attestati'] ?? 0, 'per_scuole' => $dett_pr['per_scuole'] ?? 1])) {
                $link_elenco = url_base_sito() . '/elenco_studenti.php?code=' . urlencode($codice_p);
                $body_tpl .= "<p style='margin-top:18px;'>Per gli <strong>attestati di partecipazione degli studenti</strong> inserisci il loro elenco (cognome e nome) dalla tua Area personale, accedendo con SPID, CIE o credenziali Unical con questo stesso indirizzo email.</p>"
                           . "<p><a href='" . htmlspecialchars($link_elenco) . "' style='background:#198754; color:#fff; padding:10px 18px; text-decoration:none; border-radius:6px; font-weight:bold;'>Inserisci l'elenco degli studenti</a></p>";
            }
            inviaNotificaEmail($email, str_replace($r_find, $r_repl, $obj_tpl), str_replace($r_find, $r_repl, $body_tpl), $conn, colore_area_turno($conn, $turno_id));

            // Gestori dell'area/evento con notifiche attive + indirizzi aggiuntivi dell'evento:
            // ognuno riceve la propria email con il riepilogo completo (campi aggiuntivi compresi)
            $destinatari_notifica = get_destinatari_notifiche_prenotazione($conn, (int)$t_info['evento_id']);
            $riepilogo = $destinatari_notifica ? html_riepilogo_prenotazione($conn, $nuovo_pr_id) : null;
            if ($riepilogo) {
                $obj_gest = "Nuova prenotazione ($num_posti " . ($num_posti === 1 ? 'posto' : 'posti') . "): " . $t_info['evento_titolo'];
                $intro_gest = "<p>È stata registrata una nuova prenotazione per l'evento <strong>" . htmlspecialchars($t_info['evento_titolo']) . "</strong>.</p>";
                $gestori_ev = get_email_gestori_evento($conn, (int)$t_info['evento_id']);
                foreach ($destinatari_notifica as $em_gest) { inviaNotificaEmail($em_gest, $obj_gest, corpo_notifica_per($em_gest, $intro_gest, $riepilogo, $gestori_ev), $conn, colore_area_turno($conn, $turno_id)); }
            }

            $param_stato = ($stato_prenotazione === 'in_attesa') ? "&st_tipo=attesa" : (($stato_prenotazione === 'da_approvare') ? ($conv_pr === 'no' ? "&st_tipo=convenzione" : "&st_tipo=approvare") : "");
            if ($conv_pr === 'no' && $stato_prenotazione === 'in_attesa') $param_stato .= "&conv=no";
            if ($conv_rinnovo) $param_stato .= "&conv_rinnovo=1";
            header("Location: {$url_ritorno}status=success&code=" . urlencode($codice_p) . $param_stato); exit;
        } else { header("Location: {$url_ritorno}status=error"); exit; }
    }
    header("Location: {$current_filename}.php"); exit;
}

// RENDER MESSAGGI
$messaggio_prenotazione = "";
if (isset($_GET['status'])) {
    $st = $_GET['status'];
    if ($st === 'success' && isset($_GET['code'])) {
        $codice_p = htmlspecialchars($_GET['code']);
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
        $link_btn = $proto . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . "/stampa_ricevuta.php?code=" . urlencode($codice_p);
        $btn_scarica_pdf = "<a href='$link_btn' target='_blank' class='btn btn-danger btn-sm fw-bold ms-3'><i class='fa fa-file-pdf me-1'></i> Stampa Ricevuta</a>";
        $box_conv = "<div class='alert alert-warning text-start my-3 shadow-sm border-0 border-start border-5 border-warning small'><div class='fw-bold mb-1'><i class='fa fa-file-signature me-1'></i> Convenzione da stipulare</div>" . (($_GET['conv_rinnovo'] ?? '') === '1' ? "<p style='margin:0 0 8px;'><strong>La convenzione della scuola registrata al Dipartimento non copre tutto il periodo dell'attività: va stipulata una nuova convenzione.</strong></p>" : '') . html_istruzioni_convenzione($page_cfg, false, $_GET['code']) . "</div>";
        if (isset($_GET['st_tipo']) && $_GET['st_tipo'] === 'convenzione') { $messaggio_prenotazione = "<div class='alert alert-info fw-bold text-center mt-4 mb-0 shadow-sm border-0 border-start border-5 border-info'><i class='fa fa-hourglass-half me-2'></i> Prenotazione registrata, in attesa della convenzione. Codice: <span class='badge bg-info text-dark ms-2'>$codice_p</span> $btn_scarica_pdf</div>" . $box_conv; }
        elseif (isset($_GET['st_tipo']) && $_GET['st_tipo'] === 'approvare') { $messaggio_prenotazione = "<div class='alert alert-info fw-bold text-center my-4 shadow-sm border-0 border-start border-5 border-info'><i class='fa fa-hourglass-half me-2'></i> Richiesta in approvazione! Codice: <span class='badge bg-info text-dark ms-2'>$codice_p</span> $btn_scarica_pdf</div>"; } 
        elseif (isset($_GET['st_tipo']) && $_GET['st_tipo'] === 'attesa') { $messaggio_prenotazione = "<div class='alert alert-warning fw-bold text-center my-4 shadow-sm border-0 border-start border-5 border-warning'><i class='fa fa-clock me-2'></i> In Lista d'Attesa! Codice: <span class='badge bg-warning text-dark ms-2'>$codice_p</span> $btn_scarica_pdf</div>" . (($_GET['conv'] ?? '') === 'no' ? $box_conv : ''); } 
        else { $messaggio_prenotazione = "<div class='alert alert-success fw-bold text-center my-4 shadow-sm border-0 border-start border-5 border-success'><i class='fa fa-check-circle me-2'></i> Prenotazione confermata! Codice: <span class='badge bg-success ms-2'>$codice_p</span> $btn_scarica_pdf</div>"; }
    } elseif ($st === 'dup') { $messaggio_prenotazione = "<div class='alert alert-warning fw-bold text-center my-4 shadow-sm border-0 border-start border-4 border-warning'><i class='fa fa-exclamation-triangle me-2'></i> Prenotazione già esistente per questo turno con la stessa email.</div>"; } 
    elseif ($st === 'full') { $messaggio_prenotazione = "<div class='alert alert-danger fw-bold text-center my-4 shadow-sm border-0 border-start border-4 border-danger'><i class='fa fa-exclamation-circle me-2'></i> Posti esauriti per questo turno.</div>"; } 
    elseif ($st === 'closed') { $messaggio_prenotazione = "<div class='alert alert-danger fw-bold text-center my-4 shadow-sm border-0 border-start border-4 border-danger'><i class='fa fa-times-circle me-2'></i> Le prenotazioni sono chiuse.</div>"; } 
    elseif ($st === 'notopened') { $messaggio_prenotazione = "<div class='alert alert-warning fw-bold text-center my-4 shadow-sm border-0 border-start border-4 border-warning'><i class='fa fa-clock me-2'></i> Le prenotazioni non sono ancora aperte.</div>"; } 
    elseif ($st === 'limite') {
        $ev_bloc = htmlspecialchars($_GET['ev'] ?? '');
        $regola = (($page_cfg['limite_iscrizioni'] ?? '') === 'un_turno') ? "In quest'area puoi prenotare un solo turno per ciascun evento." : "In quest'area puoi iscriverti a un solo evento/gruppo.";
        $messaggio_prenotazione = "<div class='alert alert-warning fw-bold text-center my-4 shadow-sm border-0 border-start border-4 border-warning'><i class='fa fa-user-lock me-2'></i> $regola Risulti già iscritto a: <strong>$ev_bloc</strong>. Per cambiare, annulla prima la prenotazione dall'Area Personale.</div>";
    }
    elseif ($st === 'riservato') { $messaggio_prenotazione = "<div class='alert alert-warning fw-bold text-center my-4 shadow-sm border-0 border-start border-4 border-warning'><i class='fa fa-key me-2'></i> Per iscriverti devi prima accedere (SPID, CIE o credenziali Unical).</div>"; }
    elseif ($st === 'altra_edizione') { $messaggio_prenotazione = "<div class='alert alert-warning fw-bold text-center my-4 shadow-sm border-0 border-start border-4 border-warning'><i class='fa fa-school me-2'></i> La tua scuola è già iscritta (o in lista d'attesa) a un'altra edizione di questo progetto: puoi partecipare a una sola edizione. Per cambiare, annulla prima l'iscrizione dall'Area Personale.</div>"; }
    elseif ($st === 'captcha') {
        $err_cp = $_SESSION['errore_prenotazione'] ?? 'Controllo anti-robot non superato.';
        unset($_SESSION['errore_prenotazione']);
        $messaggio_prenotazione = "<div class='alert alert-warning fw-bold text-center my-4 shadow-sm border-0 border-start border-4 border-warning'><i class='fa fa-shield-halved me-2'></i> " . htmlspecialchars($err_cp) . "</div>";
    }
    elseif ($st === 'email') {
        $err_em = $_SESSION['errore_prenotazione'] ?? "Controlla l'indirizzo email.";
        unset($_SESSION['errore_prenotazione']);
        $messaggio_prenotazione = "<div class='alert alert-warning fw-bold text-center my-4 shadow-sm border-0 border-start border-4 border-warning'><i class='fa fa-envelope me-2'></i> Prenotazione non registrata: " . htmlspecialchars($err_em) . "</div>";
    }
    elseif ($st === 'studenti') {
        $err_st = $_SESSION['errore_prenotazione'] ?? 'Numero di studenti non valido.';
        unset($_SESSION['errore_prenotazione']);
        $messaggio_prenotazione = "<div class='alert alert-danger fw-bold text-center my-4 shadow-sm border-0 border-start border-4 border-danger'><i class='fa fa-users me-2'></i> Iscrizione non registrata: " . htmlspecialchars($err_st) . "</div>";
    }
    elseif ($st === 'error') { $messaggio_prenotazione = "<div class='alert alert-danger fw-bold text-center my-4 shadow-sm border-0 border-start border-4 border-danger'>Errore di registrazione. Compilare tutti i campi obbligatori.</div>"; }
}

// =========================================================================
// INIZIALIZZAZIONE VARIABILI DI LAYOUT E GRIGLIA
// =========================================================================
$col_primaria     = colore_valido($page_cfg['colore_primario'] ?? '', '#0056B3');
// Colore dell'area per TESTI su sfondo chiaro: se è troppo chiaro per essere leggibile si usa il grigio scuro
$col_testo_area   = colore_testo_su($col_primaria) === '#FFFFFF' ? $col_primaria : '#1F2937';
$layout_template  = $page_cfg['layout_template'] ?? 'list';
$chiedi_matricola = (int)($page_cfg['chiedi_matricola'] ?? 1);

// Variabili per il rendering della griglia
$num_colonne = (int)($page_cfg['num_colonne'] ?? 2);
if ($num_colonne == 1) { $col_class = "col-12"; } 
elseif ($num_colonne == 3) { $col_class = "col-lg-4 col-md-6"; } 
else { $col_class = "col-md-6"; }

$nomi_ruoli = [-1 => 'Utenti Autenticati', 1 => 'Amministratore', 2 => 'Gestore Prenotazioni', 3 => 'Studenti', 4 => 'Dipendenti', 5 => 'Esterni'];
$etichette_riservato = [-1 => 'Riservato Utenti Autenticati', 1 => 'Riservato Amministratori', 2 => 'Riservato Gestori', 3 => 'Riservato Studenti', 4 => 'Riservato Dipendenti', 5 => 'Riservato Esterni'];

// ESTRAZIONE DATI
$sottocategorie = get_sottocategorie($conn, $p_id);
$categorie_nomi = array_column($sottocategorie, 'nome');

$evento_evidenza = null; 
$all_turni_flat = []; 
$eventi_per_data = []; 
$json_events_calendar = []; 
$sezioni_superiori = [];
$eventi_macro = [];
$eventi_per_categoria = [];
$eventi_by_id = [];

$sql_ev = "SELECT e.*, sc.nome as nome_sottocategoria, sc.affiancata_in_alto FROM eventi e LEFT JOIN sottocategorie sc ON e.sottocategoria_id = sc.id WHERE e.pagina_id = $p_id AND e.archiviato = 0 ORDER BY e.is_evidenza DESC, sc.ordine ASC, e.ordine ASC, e.id DESC";
$res_ev = $conn->query($sql_ev);
if ($res_ev) {
    while ($ev = $res_ev->fetch_assoc()) {
        $turni = [];
        $res_t = $conn->query("SELECT * FROM turni WHERE evento_id = {$ev['id']} ORDER BY (data_turno IS NULL), data_turno ASC, orario_inizio ASC, nome_turno ASC, id ASC");
        while ($t = $res_t->fetch_assoc()) {
            $turni[] = $t;
            
            // Popolamento lista Piatta (Flat)
            $t_flat = $t;
            $t_flat['evento_titolo'] = $ev['titolo'];
            $t_flat['evento_luogo'] = $ev['luogo'];
            $t_flat['evento_descrizione'] = $ev['descrizione'];
            $t_flat['evento_locandina'] = $ev['locandina_path'];
            $t_flat['richiede_prenotazione'] = $ev['richiede_prenotazione'];
            $t_flat['ruolo_accesso_id'] = $ev['ruolo_accesso_id'];
            $t_flat['categoria'] = $ev['nome_sottocategoria'] ?: 'Altre Attività';
            $t_flat['evento_id'] = $ev['id'];
            $t_flat['is_evidenza'] = $ev['is_evidenza'];
            $t_flat['evento_tipo'] = $ev['tipo'] ?? 'evento';
            $all_turni_flat[] = $t_flat;

            // Popolamento Eventi per FullCalendar (solo turni con data)
            if (!empty($t['data_turno'])) {
                $json_events_calendar[] = [
                    'id' => 'turno_' . $t['id'],
                    'title' => htmlspecialchars_decode($ev['titolo']) . (!empty($t['nome_turno']) ? ' – ' . $t['nome_turno'] : ''),
                    'start' => $t['data_turno'] . (!empty($t['orario_inizio']) ? 'T' . $t['orario_inizio'] : ''),
                    'end' => !empty($t['orario_fine']) ? $t['data_turno'] . 'T' . $t['orario_fine'] : null,
                    'allDay' => empty($t['orario_inizio']),
                    'color' => $col_primaria,
                    'extendedProps' => ['turno_id' => $t['id'], 'luogo' => htmlspecialchars_decode($ev['luogo'])]
                ];
            }
        }
        $ev['turni'] = $turni;
        $eventi_by_id[(int)$ev['id']] = $ev;
        $eventi_per_categoria[$ev['nome_sottocategoria'] ?: 'Altri gruppi'][] = $ev;

        if ((int)$ev['is_evidenza'] === 1 && $evento_evidenza === null) {
            $evento_evidenza = $ev; 
            continue; 
        }

        $sezione = $ev['nome_sottocategoria'] ? $ev['nome_sottocategoria'] : 'Altre Attività';
        // Sezione affiancata in alto nel layout Griglia: opzione della sezione (admin > Eventi > Sezioni)
        $e_superiore = (int)($ev['affiancata_in_alto'] ?? 0) === 1;
        if ($e_superiore) {
            $sezioni_superiori[$sezione][] = $ev;
        } else {
            $eventi_macro[] = $ev;
        }

        if (!empty($turni)) {
            foreach ($turni as $t) {
                $d = !empty($t['data_turno']) ? $t['data_turno'] : '9999-12-31';
                $ev_giorno = $ev; $ev_giorno['turni'] = [$t]; 
                $eventi_per_data[$d][] = $ev_giorno;
            }
        } else {
            $ev_giorno = $ev; $ev_giorno['turni'] = []; 
            $eventi_per_data['9999-12-31'][] = $ev_giorno;
        }
    }
}
ksort($eventi_per_data);

// PROGETTI: schede (periodo, referenti, limiti di studenti…) e progetto richiesto con ?progetto=ID
$dettagli_progetti = get_dettagli_progetti($conn, array_keys($eventi_by_id));
foreach ($all_turni_flat as &$t_fl) {
    $d_fl = $dettagli_progetti[(int)$t_fl['evento_id']] ?? [];
    ['min' => $t_fl['limite_studenti_min'], 'max' => $t_fl['limite_studenti_max']] = limiti_partecipanti($d_fl, $t_fl);
    $t_fl['per_scuole'] = (int)($d_fl['per_scuole'] ?? 1) === 1;
    $t_fl['dett_progetto'] = $d_fl ?: null;
}
unset($t_fl);
$progetto_richiesto = (int)($_GET['progetto'] ?? 0);
$progetto_sel = ($progetto_richiesto > 0 && ($eventi_by_id[$progetto_richiesto]['tipo'] ?? '') === 'progetto') ? $eventi_by_id[$progetto_richiesto] : null;
if ($progetto_richiesto > 0 && !$progetto_sel && $messaggio_prenotazione === '') {
    $messaggio_prenotazione = "<div class='alert alert-light border fw-semibold text-center my-4'><i class='fa fa-circle-info me-2'></i>Il progetto richiesto non è più disponibile: ecco l'elenco aggiornato.</div>";
}
// Scheda di un evento (?evento=ID): stessi dati delle card, in una pagina dedicata e condivisibile
$evento_richiesto = (int)($_GET['evento'] ?? 0);
$evento_sel = null;
if ($evento_richiesto > 0 && isset($eventi_by_id[$evento_richiesto])) {
    if (($eventi_by_id[$evento_richiesto]['tipo'] ?? '') === 'progetto') $progetto_sel = $eventi_by_id[$evento_richiesto];
    else $evento_sel = $eventi_by_id[$evento_richiesto];
} elseif ($evento_richiesto > 0 && $messaggio_prenotazione === '') {
    $messaggio_prenotazione = "<div class='alert alert-light border fw-semibold text-center my-4'><i class='fa fa-circle-info me-2'></i>L'evento richiesto non è più disponibile: ecco il programma aggiornato.</div>";
}
// Progetto che rimanda a un'altra pagina (es. OpenLab): la scheda porta direttamente lì
if ($progetto_sel && ($dest_sel = destinazione_progetto($conn, $dettagli_progetti[(int)$progetto_sel['id']] ?? null))) {
    header('Location: ' . $dest_sel['url']); exit;
}
$GLOBALS['evento_scheda_id'] = $evento_sel ? (int)$evento_sel['id'] : 0;
// Link alla scheda dell'evento (card dei layout)
$url_scheda_evento = fn(array $ev) => htmlspecialchars($current_filename) . '.php?' . (($ev['tipo'] ?? '') === 'progetto' ? 'progetto=' : 'evento=') . (int)$ev['id'];

// Dati di un progetto per elenco e scheda: scheda, edizioni (turni), stato, iscrizione dell'utente, tipo (scuole o generico)
$info_progetto = function (array $ev) use ($conn, $dettagli_progetti, &$mie_iscrizioni): array {
    $d  = $dettagli_progetti[(int)$ev['id']] ?? [];
    $ie = info_edizioni_progetto($conn, $d, $ev['turni'] ?? [], $mie_iscrizioni[(int)$ev['id']] ?? []);
    $mio_ed = null;
    foreach ($ie['edizioni'] as $ed) if ($ed['mio'] !== null) { $mio_ed = $ed; break; }
    $min_ed = $ie['edizioni'] ? min(array_column($ie['edizioni'], 'min')) : null;
    $max_ed = array_filter(array_column($ie['edizioni'], 'max'));
    // Con limiti diversi tra le edizioni: dal minimo più basso al massimo più alto (senza massimo se un'edizione non ne ha)
    $max_ed = ($max_ed && count($max_ed) === count($ie['edizioni'])) ? max($max_ed) : null;
    // Progetto con rimando: niente iscrizioni qui, lo stato dice dove prenotare (finché il progetto non è concluso)
    $dest = destinazione_progetto($conn, $d);
    if ($dest && $ie['stato']['codice'] !== 'concluso') {
        $ie['stato'] = ['codice' => 'aperte', 'etichetta' => 'Prenotazioni su ' . $dest['nome'], 'bg' => '#E0F2FE', 'fg' => '#075985', 'ordine' => 1];
    }
    return ['dest' => $dest, 'd' => $d, 't' => $ev['turni'][0] ?? null, 'edizioni' => $ie['edizioni'], 'liberi' => $ie['liberi'],
            'prossima_apertura' => $ie['prossima_apertura'], 'limiti' => testo_limiti_partecipanti($min_ed, $max_ed), 'max_studenti' => $max_ed,
            // Senza date, la nota sul periodo (es. "novembre-dicembre 2026") vale più di "Date da definire"
            'stato' => $ie['stato'], 'periodo' => (empty($d['data_inizio']) && empty($d['data_fine']) && !empty($d['periodo_note'])) ? $d['periodo_note'] : periodo_progetto($d), 'mio' => $ie['mio'], 'mio_ed' => $mio_ed,
            'attesa' => array_sum(array_column($ie['edizioni'], 'attesa')), 'scuole' => (int)($d['per_scuole'] ?? 1) === 1];
};

// Pulsante di iscrizione (testi "scuola" nei progetti per le scuole). Con $ed: pulsante di quella edizione (scheda);
// senza: pulsante del progetto (elenco), che con più edizioni porta alla scheda per scegliere.
$pulsante_progetto = function (array $ev, array $ip, ?array $ed = null) use ($col_primaria, $utente_logged, $current_filename): string {
    $stile = 'background-color:' . $col_primaria . ';color:' . colore_testo_su($col_primaria) . ';border:none;';
    $url_scheda = htmlspecialchars($current_filename) . '.php?progetto=' . (int)$ev['id'];
    if (!empty($ip['dest'])) return html_pulsante_destinazione($ip['dest'], $stile);
    $mio = $ed ? $ed['mio'] : $ip['mio'];
    if ($mio === 'richiesta_conferma') return '<a href="area_personale.php" class="btn btn-warning fw-bold text-dark w-100"><i class="fa fa-bell me-1" aria-hidden="true"></i>Posto offerto: conferma</a>';
    if ($mio === 'in_attesa') return '<a href="area_personale.php" class="btn btn-outline-warning fw-bold text-dark w-100"><i class="fa fa-hourglass-half me-1" aria-hidden="true"></i>Sei in lista d\'attesa</a>';
    $sc = $ip['scuole'];
    if ($mio !== null) return '<a href="area_personale.php" class="btn btn-success fw-bold w-100"><i class="fa fa-check me-1" aria-hidden="true"></i>' . ($sc ? 'La tua scuola è iscritta' : 'Sei iscritto') . '</a>';
    // Si partecipa a una sola edizione: già iscritti (o in attesa) su un'altra
    if ($ed && $ip['mio'] !== null) return '';
    if (!$ip['edizioni'] || (int)($ev['richiede_prenotazione'] ?? 1) === 0) return '';
    // Ogni edizione ha la sua finestra: con $ed vale lo stato di quell'edizione, altrimenti quello del progetto
    $c = $ed ? $ed['stato']['codice'] : $ip['stato']['codice'];
    $apre = $ed ? ($ed['t']['data_apertura'] ?? null) : ($ip['prossima_apertura'] ?? null);
    if ($c === 'concluso') return '<button class="btn btn-secondary fw-bold w-100" disabled>Progetto concluso</button>';
    if ($c === 'arrivo' && $apre) return '<button class="btn btn-outline-secondary fw-bold w-100" disabled>Iscrizioni dal ' . date('d/m/Y H:i', strtotime($apre)) . '</button>';
    if (!in_array($c, ['aperte', 'attesa'], true)) return '<button class="btn btn-outline-secondary fw-bold w-100" disabled>Iscrizioni chiuse</button>';
    if (!$utente_logged && (int)($ev['ruolo_accesso_id'] ?? 0) !== 0) {
        $rit = urlencode($current_filename . '.php?progetto=' . (int)$ev['id']);
        return '<a href="saml_login.php?redirect=' . $rit . '" class="btn fw-bold w-100" style="' . $stile . '"><i class="fa fa-key me-1" aria-hidden="true"></i>' . ($sc ? 'Accedi con SPID/CIE per iscrivere la scuola' : 'Accedi per iscriverti') . '</a>';
    }
    if (!$ed) {
        if (count($ip['edizioni']) > 1) return '<a href="' . $url_scheda . '#iscrizione" class="btn fw-bold w-100" style="' . $stile . '"><i class="fa fa-school me-1" aria-hidden="true"></i>Scegli l\'edizione</a>';
        $ed = $ip['edizioni'][0];
    }
    $t_id = (int)$ed['t']['id'];
    if (!$ed['libera'] && empty($ed['t']['abilita_lista_attesa'])) return '<button class="btn btn-outline-secondary fw-bold w-100" disabled>' . ($sc ? 'Edizione già assegnata' : 'Posti esauriti') . '</button>';
    if (!$ed['libera']) return '<button type="button" class="btn btn-warning fw-bold text-dark w-100" data-bs-toggle="modal" data-bs-target="#modPrenota' . $t_id . '"><i class="fa fa-hourglass-half me-1" aria-hidden="true"></i>Mettiti in lista d\'attesa</button>';
    return '<button type="button" class="btn fw-bold w-100" style="' . $stile . '" data-bs-toggle="modal" data-bs-target="#modPrenota' . $t_id . '"><i class="fa ' . ($sc ? 'fa-school' : 'fa-user-plus') . ' me-1" aria-hidden="true"></i>' . ($sc ? 'Iscrivi la scuola' : 'Iscriviti') . '</button>';
};

// Intestazione di un giorno nei template cronologici. I turni senza data stanno sotto la chiave
// sentinella 9999-12-31 (ultima dopo ksort): va mostrata come "Senza data fissa", non come data.
if (!defined('GIORNO_SENZA_DATA')) define('GIORNO_SENZA_DATA', '9999-12-31');
$titolo_giorno = function (string $d): string {
    return $d === GIORNO_SENZA_DATA
        ? '<i class="fa fa-infinity me-2" aria-hidden="true"></i>Senza data fissa'
        : formattaDataItaliano($d);
};

// Discipline nell'ordine definito in admin, poi eventuali gruppi senza categoria
$ordine_cat = array_flip($categorie_nomi);
uksort($eventi_per_categoria, fn($a, $b) => ($ordine_cat[$a] ?? PHP_INT_MAX) <=> ($ordine_cat[$b] ?? PHP_INT_MAX));

// Griglia: moduli raggruppati per sezione (ordine definito in admin), quelli senza sezione in fondo.
// L'intestazione compare per le sezioni con un nome; "Altre attività" solo se serve a distinguerle.
$gruppi_griglia = [];
foreach ($eventi_macro as $ev_g) { $gruppi_griglia[$ev_g['nome_sottocategoria'] ?: ''][] = $ev_g; }
uksort($gruppi_griglia, fn($a, $b) => [($a === ''), $ordine_cat[$a] ?? PHP_INT_MAX] <=> [($b === ''), $ordine_cat[$b] ?? PHP_INT_MAX]);
$titolo_gruppo_griglia = function (string $nome) use (&$gruppi_griglia, &$sezioni_superiori): string {
    if ($nome !== '') return $nome;
    return (count($gruppi_griglia) > 1 || !empty($sezioni_superiori)) ? 'Altre attività' : '';
};

$limite_iscrizioni = $page_cfg['limite_iscrizioni'] ?? 'nessuno';
$mie_iscrizioni = $utente_logged ? get_mie_iscrizioni_area($conn, $p_id, (int)$_SESSION['utente_id'], (string)$val_email) : [];

usort($all_turni_flat, function($a, $b) {
    $dateA = ($a['data_turno'] ?: '9999-12-31') . ' ' . $a['orario_inizio'] . ' ' . $a['nome_turno'];
    $dateB = ($b['data_turno'] ?: '9999-12-31') . ' ' . $b['orario_inizio'] . ' ' . $b['nome_turno'];
    return strcmp($dateA, $dateB);
});

// =========================================================================
// FUNZIONI DI RENDER (MODALE e CARD)
// =========================================================================
if (!function_exists('renderCardUniversal')) {
    function renderCardUniversal($ev, $col_primaria, $utente_logged, $utente_ruolo_id, $nomi_ruoli, $etichette_riservato, $val_nome, $val_cognome, $val_email, $val_matricola, $conn, $chiedi_matricola = 1) {
        $ruolo_richiesto = (int)($ev['ruolo_accesso_id'] ?? 0);
        $sec_roles = isset($_SESSION['utente_ruoli_secondari']) ? explode(',', $_SESSION['utente_ruoli_secondari']) : [];
        $is_admin = ($utente_ruolo_id === 1 || in_array('1', $sec_roles));
        
        if ($ruolo_richiesto === 0) { $ruolo_ok = true; } 
        elseif ($ruolo_richiesto === -1) { $ruolo_ok = $utente_logged; } 
        else { $ruolo_ok = ($utente_logged && ($utente_ruolo_id === $ruolo_richiesto || in_array((string)$ruolo_richiesto, $sec_roles) || $is_admin)); }

        $req_prenotazione = (int)($ev['richiede_prenotazione'] ?? 1);
        $first_turno_id = !empty($ev['turni'][0]['id']) ? $ev['turni'][0]['id'] : rand(100, 999);
        $collapse_id = "colTurni_" . $ev['id'] . "_" . $first_turno_id;

        echo '<div class="card card-evento-u shadow-sm border-0 h-100 d-flex flex-column" style="border-radius: 8px; overflow: hidden; border-top: 4px solid '.$col_primaria.' !important; background: #ffffff;">';
        
        if (!empty($ev['locandina_path']) && preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $ev['locandina_path'])) {
            echo '<img src="'.htmlspecialchars($ev['locandina_path']).'" class="card-img-top border-bottom" alt="Locandina" style="max-height: 250px; object-fit: cover;">';
        }
        
        echo '<div class="card-body p-4 d-flex flex-column">';
        echo '<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">';
        $url_sch = htmlspecialchars($GLOBALS['current_filename'] ?? '') . '.php?' . (($ev['tipo'] ?? '') === 'progetto' ? 'progetto=' : 'evento=') . (int)$ev['id'];
        echo '<h4 class="fw-bold fs-5 m-0"><a href="' . $url_sch . '" class="text-decoration-none" style="color: '.$col_primaria.';">'.htmlspecialchars($ev['titolo']).'</a></h4>';
        if ($ruolo_richiesto === -1) echo '<span class="badge bg-warning text-dark">🔑 Solo Autenticati</span>';
        elseif ($ruolo_richiesto > 0) echo '<span class="badge bg-dark">🔒 Solo '.htmlspecialchars($nomi_ruoli[$ruolo_richiesto] ?? '').'</span>';
        echo '</div>';
        
        // Nelle card solo la descrizione breve: la completa è nella scheda dell'evento
        $testo_card = testo_card_evento($ev);
        if ($testo_card !== '') echo '<p class="text-secondary mb-3 flex-grow-1" style="font-size: 0.95rem; line-height: 1.55;">' . $testo_card . '</p>';
        
        if (!empty($ev['allegato_pdf'])) {
            echo '<div class="mb-3 p-3 rounded" style="background-color: #f8f9fa; border-left: 4px solid '.$col_primaria.'; font-size:0.9rem;">
                    <strong class="d-block mb-1 text-dark">Materiale Informativo:</strong>
                    <a href="'.htmlspecialchars($ev['allegato_pdf']).'" target="_blank" class="btn btn-outline-danger btn-sm fw-bold">
                        <i class="fa fa-file-pdf me-1"></i> Visualizza / Scarica Programma
                    </a>
                  </div>';
        }

        if (!empty($ev['luogo'])) {
            echo '<div class="bg-light p-2 rounded mt-2 text-dark shadow-sm" style="border-left: 4px solid '.$col_primaria.'; font-size: 1rem;">
                    <i class="fa fa-map-pin text-danger me-2"></i> <strong class="text-primary">Luogo:</strong> '.htmlspecialchars($ev['luogo']).'
                  </div>';
        }
        
        echo '</div>';

        // Fondo della card: disponibilità, scadenza delle prenotazioni e pulsante verso la scheda completa
        // (turni, prenotazione e contatti sono nella scheda dell'evento)
        $txt_btn = colore_testo_su($col_primaria);
        $badge_disp_html = '';
        if ($req_prenotazione == 0) {
            $badge_disp_html = '<span class="badge bg-success" style="font-size:0.8rem;"><i class="fa fa-unlock me-1" aria-hidden="true"></i>Ingresso libero</span>';
        } elseif (!empty($ev['turni'])) {
            $posti_badge_disp = 0; $badge_has_waitlist = false; $badge_all_ended = true; $illimitato = false;
            foreach ($ev['turni'] as $_bt) {
                if (turno_concluso($_bt)) continue;
                $badge_all_ended = false;
                if ((int)$_bt['max_posti'] >= 9000) { $illimitato = true; continue; }
                $_disp_bt = (int)$_bt['max_posti'] - getPostiOccupati($conn, $_bt['id']);
                if ($_disp_bt <= 0 && !empty($_bt['abilita_lista_attesa'])) $badge_has_waitlist = true;
                $posti_badge_disp += max(0, $_disp_bt);
            }
            if ($badge_all_ended) $badge_disp_html = '<span class="badge bg-secondary" style="font-size:0.8rem;">Concluso</span>';
            elseif ($illimitato) $badge_disp_html = '<span class="badge bg-success" style="font-size:0.8rem;">Posti disponibili</span>';
            elseif ($posti_badge_disp > 0) $badge_disp_html = '<span class="badge bg-success" style="font-size:0.8rem;">' . $posti_badge_disp . ' ' . ($posti_badge_disp == 1 ? 'posto libero' : 'posti liberi') . '</span>';
            elseif ($badge_has_waitlist) $badge_disp_html = '<span class="badge bg-warning text-dark" style="font-size:0.8rem;">Lista d\'attesa</span>';
            else $badge_disp_html = '<span class="badge bg-danger" style="font-size:0.8rem;">Posti esauriti</span>';
        }
        $finestra = ($req_prenotazione == 1 && !empty($ev['turni'])) ? finestra_prenotazione($ev['turni']) : null;
        $n_turni = count($ev['turni'] ?? []);
        echo '<div class="card-footer border-top-0 p-3 d-flex flex-column gap-2 mt-auto" style="background:#f8fafc;">';
        echo '<div class="d-flex flex-wrap align-items-center gap-2">' . $badge_disp_html;
        if ($n_turni > 1) echo '<span class="badge bg-light text-dark border" style="font-size:0.8rem;"><i class="fa fa-clock me-1" aria-hidden="true"></i>' . $n_turni . ' turni</span>';
        if ($finestra) echo '<span class="badge" style="font-size:0.8rem; background:' . $finestra['bg'] . '; color:' . $finestra['fg'] . ';"><i class="fa ' . $finestra['icona'] . ' me-1" aria-hidden="true"></i>' . htmlspecialchars($finestra['testo']) . '</span>';
        echo '</div>';
        echo '<a href="' . $url_sch . '" class="btn fw-bold w-100 py-2 shadow-sm" style="background-color:' . $col_primaria . '; color:' . $txt_btn . '; border:none; border-radius:8px;">'
           . ($req_prenotazione == 1 ? 'Scheda completa, turni e prenotazione' : 'Scheda completa, orari e contatti') . ' <i class="fa fa-arrow-right ms-1" aria-hidden="true"></i></a>';
        echo '</div>';
        echo '</div>'; 
    }
}

function printModalPrenotazione($t, $col_primaria, $utente_logged, $val_nome, $val_cognome, $val_email, $val_matricola, $chiedi_matricola, $conn, $p_id) {
    $read_nome = ($utente_logged && !empty($val_nome)) ? 'readonly' : '';
    $read_cognome = ($utente_logged && !empty($val_cognome)) ? 'readonly' : '';
    $read_matricola = ($utente_logged && !empty($val_matricola)) ? 'readonly' : '';

    $occ = getPostiOccupati($conn, $t['id']);
    $disponibili = $t['max_posti'] - $occ;
    $soldout = ($disponibili <= 0);
    $is_waitlist = ($soldout && isset($t['abilita_lista_attesa']) && $t['abilita_lista_attesa'] == 1);
    $is_progetto = ($t['evento_tipo'] ?? '') === 'progetto';
    $per_scuole_m = $is_progetto && !empty($t['per_scuole']); // progetto per le scuole: testi "scuola" e numero di partecipanti
    $classe_m = prenotazione_di_classe($is_progetto, $t['dett_progetto'] ?? null); // numero di studenti (progetti per le scuole, eventi con attestati di classe)
    ?>
    <div class="modal fade" id="modPrenota<?php echo $t['id']; ?>" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content shadow-lg border-0" style="border-radius: 12px;">
                <form method="POST" enctype="multipart/form-data">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="turno_id" value="<?php echo $t['id']; ?>">
                    <?php if (!empty($GLOBALS['evento_scheda_id'])): ?><input type="hidden" name="da_scheda" value="<?php echo (int)$GLOBALS['evento_scheda_id']; ?>"><?php endif; ?>
                    <div class="modal-header py-2 bg-light border-bottom-0">
                        <h6 class="modal-title fw-bold text-dark"><i class="fa <?php echo $per_scuole_m ? 'fa-school' : 'fa-ticket-alt'; ?> me-1" style="color:<?php echo $col_primaria; ?>;"></i> <?php echo $per_scuole_m ? 'Iscrizione della scuola' : ($is_progetto ? 'Iscrizione' : 'Prenotazione'); ?>: <?php echo htmlspecialchars($t['evento_titolo']); ?><?php if ($is_progetto && !empty($t['nome_turno']) && !in_array($t['nome_turno'], ['Iscrizione scuole', 'Iscrizioni'], true)): ?> <span class="badge ms-1" style="background: <?php echo $col_primaria; ?>; color: <?php echo colore_testo_su($col_primaria); ?>;"><?php echo htmlspecialchars($t['nome_turno']); ?></span><?php endif; ?></h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body text-start">
                        <?php if ($is_progetto): ?>
                        <div class="alert <?php echo $is_waitlist ? 'alert-warning' : 'alert-info'; ?> border-0 small mb-3">
                            <i class="fa fa-circle-info me-1" aria-hidden="true"></i>
                            <?php if ($per_scuole_m && $is_waitlist): ?>
                                L'edizione è già stata assegnata a un'altra scuola: la tua richiesta entra in <strong>lista d'attesa</strong>, in ordine di arrivo. Se il posto si libera riceverai un'email per confermarlo.
                            <?php elseif ($per_scuole_m): ?>
                                Ogni edizione accoglie <strong>una sola scuola</strong>, in ordine di arrivo. Compila i dati come docente referente della scuola.
                            <?php elseif ($is_waitlist): ?>
                                I posti sono esauriti: entri in <strong>lista d'attesa</strong>, in ordine di arrivo. Se un posto si libera riceverai un'email per confermarlo.
                            <?php else: ?>
                                Puoi iscriverti a <strong>una sola edizione</strong> del progetto.
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <?php if (!$is_progetto && $classe_m): ?>
                        <div class="alert alert-info border-0 small mb-3">
                            <i class="fa fa-school me-1" aria-hidden="true"></i>
                            Prenotazione per una <strong>classe</strong>: indica il numero di studenti. Dopo la conferma potrai inserire l'elenco degli studenti per gli <strong>attestati</strong> dalla tua Area personale (accesso con SPID, CIE o credenziali Unical, con la stessa email).
                        </div>
                        <?php endif; ?>
                        <div class="alert alert-light border shadow-sm mb-3" <?php echo $is_progetto ? 'hidden' : ''; ?>>
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <?php if (!empty($t['nome_turno'])): ?><span class="badge" style="background:<?php echo $col_primaria; ?>;">🏷️ <?php echo htmlspecialchars($t['nome_turno']); ?></span><?php endif; ?>
                                <?php if (!empty($t['data_turno'])): ?><span class="badge bg-dark">📅 <?php echo date('d/m/Y', strtotime($t['data_turno'])); ?></span><?php endif; ?>
                                <?php if (orario_turno($t) !== ''): ?><span class="badge bg-secondary">🕒 <?php echo orario_turno($t); ?></span><?php endif; ?>
                            </div>
                            <?php if(!empty($t['evento_luogo'])): ?><small class="text-muted fw-bold d-block"><i class="fa fa-map-marker-alt text-danger me-1"></i> <?php echo htmlspecialchars($t['evento_luogo']); ?></small><?php endif; ?>
                            <?php if(!empty($t['annullabile_fino'])): ?><small class="text-muted d-block mt-1"><i class="fa fa-rotate-left me-1" aria-hidden="true"></i> Potrai annullare o cambiare turno fino al <strong><?php echo date('d/m/Y \a\l\l\e H:i', strtotime($t['annullabile_fino'])); ?></strong>.</small><?php endif; ?>
                        </div>
                        
                        <?php if (isset($t['abilita_multi_posto']) && $t['abilita_multi_posto'] == 1): ?>
                            <div class="mb-3 p-2 bg-light rounded border border-primary">
                                <label class="form-label small fw-bold text-primary mb-1"><i class="fa fa-users me-1"></i> Posti da Prenotare <span class="text-danger">*</span></label>
                                <input type="number" name="num_posti" class="form-control form-control-sm fw-bold text-primary" value="1" min="1" max="<?php echo max(1, $disponibili); ?>" required>
                            </div>
                        <?php endif; ?>

                        <!-- DATI ANAGRAFICI A DUE A DUE -->
                        <div class="row g-3 mb-2">
                            <div class="col-md-6"><label class="form-label small fw-bold">Nome <span class="text-danger">*</span></label><input type="text" name="nome" class="form-control form-control-sm" value="<?php echo htmlspecialchars($val_nome); ?>" required <?php echo $read_nome; ?>></div>
                            <div class="col-md-6"><label class="form-label small fw-bold">Cognome <span class="text-danger">*</span></label><input type="text" name="cognome" class="form-control form-control-sm" value="<?php echo htmlspecialchars($val_cognome); ?>" required <?php echo $read_cognome; ?>></div>
                            
                            <?php if ($chiedi_matricola == 1): ?>
                                <div class="col-md-6"><label class="form-label small fw-bold">Matricola <span class="text-muted fw-normal">(Opzionale)</span></label><input type="text" name="matricola" class="form-control form-control-sm" value="<?php echo htmlspecialchars($val_matricola); ?>" <?php echo $read_matricola; ?>></div>
                                <div class="col-md-6"><label class="form-label small fw-bold" for="em<?php echo $t['id']; ?>">Email <span class="text-danger">*</span></label><input type="email" name="email" id="em<?php echo $t['id']; ?>" class="form-control form-control-sm pren-email" autocomplete="email" required></div>
                                <div class="col-md-6"><label class="form-label small fw-bold" for="emc<?php echo $t['id']; ?>">Ripeti l'email <span class="text-danger">*</span></label><input type="email" name="email_conferma" id="emc<?php echo $t['id']; ?>" class="form-control form-control-sm pren-email-conf" autocomplete="off" required></div>
                                <div class="col-12 form-text mt-1">Scrivi l'indirizzo a cui vuoi ricevere conferma, promemoria e attestati.</div>
                            <?php else: ?>
                                <div class="col-md-6"><label class="form-label small fw-bold" for="em<?php echo $t['id']; ?>">Email <span class="text-danger">*</span></label><input type="email" name="email" id="em<?php echo $t['id']; ?>" class="form-control form-control-sm pren-email" autocomplete="email" required></div>
                                <div class="col-md-6"><label class="form-label small fw-bold" for="emc<?php echo $t['id']; ?>">Ripeti l'email <span class="text-danger">*</span></label><input type="email" name="email_conferma" id="emc<?php echo $t['id']; ?>" class="form-control form-control-sm pren-email-conf" autocomplete="off" required></div>
                                <div class="col-12 form-text mt-1">Scrivi l'indirizzo a cui vuoi ricevere conferma, promemoria e attestati.</div>
                            <?php endif; ?>
                        </div>

                        <!-- CAMPI PERSONALIZZATI (con campi condizionali e nuovi tipi) -->
                        <?php
                            $campi_array = [];
                            $res_cf = $conn->query("SELECT * FROM campi_form WHERE (pagina_id = $p_id AND (evento_id IS NULL OR evento_id = 0)) OR evento_id = {$t['evento_id']} ORDER BY ordine ASC, id ASC");
                            // Il numero di partecipanti compare solo nei progetti per le scuole, con l'etichetta "studenti"
                            if ($res_cf) while ($cfr = $res_cf->fetch_assoc()) {
                                if (!campo_form_visibile($cfr, $is_progetto, $t['dett_progetto'] ?? null)) continue;
                                if ($cfr['nome_campo'] === CAMPO_PARTECIPANTI) $cfr['etichetta'] = 'Numero di studenti partecipanti';
                                $campi_array[] = $cfr;
                            }
                            // Mappa id→nome_campo per risoluzione condizioni
                            $cf_id_to_name = [];
                            foreach ($campi_array as $cfr) $cf_id_to_name[(int)$cfr['id']] = $cfr['nome_campo'];
                            $has_conds = false;
                            foreach ($campi_array as $cfr) { if (!empty($cfr['condizione_json'])) { $has_conds = true; break; } }
                            if (!empty($campi_array)):
                        ?>
                            <div class="border-top pt-2 mt-2">
                                <div class="fw-bold small text-primary mb-2"><i class="fa fa-list-check me-1"></i> Informazioni Aggiuntive:</div>
                                <div class="row g-3" id="cfRow_<?php echo $t['id']; ?>">
                                <?php foreach ($campi_array as $cf):
                                    $type       = $cf['tipo_campo'];
                                    $input_name = 'custom_' . $cf['nome_campo'];
                                    $opts       = !empty($cf['opzioni_select']) ? array_map('trim', explode(',', $cf['opzioni_select'])) : [];
                                    $wrap_id    = 'cfWrap_' . $t['id'] . '_' . $cf['id'];
                                    $is_cond    = !empty($cf['condizione_json']);
                                    $cond_attrs = '';
                                    if ($is_cond) {
                                        $cj = json_decode($cf['condizione_json'], true) ?: [];
                                        $se_name = isset($cj['se_id']) ? ($cf_id_to_name[(int)$cj['se_id']] ?? '') : '';
                                        $se_val  = $cj['se_val'] ?? '';
                                        $cond_attrs = 'data-cond-name="' . htmlspecialchars($se_name) . '" data-cond-val="' . htmlspecialchars($se_val) . '"';
                                    }
                                    $req_attr  = ($cf['obbligatorio'] && !$is_cond) ? 'required' : '';
                                    $req_data  = $cf['obbligatorio'] ? 'data-orig-req="1"' : '';
                                    $asterisk  = ($cf['obbligatorio'] && !$is_cond) ? ' <span class="text-danger">*</span>' : '';
                                    $col_size  = in_array($type, ['separator','textarea','checkboxes','radio']) ? 'col-12' : 'col-md-6';
                                    $hide_init = $is_cond ? 'style="display:none;"' : '';
                                ?>

                                <?php if ($type === 'hidden'): ?>
                                    <input type="hidden" name="<?php echo htmlspecialchars($input_name); ?>" value="<?php echo htmlspecialchars($opts[0] ?? ''); ?>">

                                <?php elseif ($type === 'separator'): ?>
                                    <div class="col-12" id="<?php echo $wrap_id; ?>" <?php echo $cond_attrs; ?> <?php echo $hide_init; ?>>
                                        <hr class="my-1">
                                        <?php if (!empty($cf['etichetta']) && $cf['etichetta'] !== '-'): ?>
                                            <div class="fw-bold small text-uppercase text-secondary" style="letter-spacing:.05em;"><?php echo htmlspecialchars($cf['etichetta']); ?></div>
                                        <?php endif; ?>
                                    </div>

                                <?php else: ?>
                                    <div class="<?php echo $col_size; ?> mb-1" id="<?php echo $wrap_id; ?>" <?php echo $cond_attrs; ?> <?php echo $hide_init; ?>>
                                        <label class="form-label small fw-bold mb-1"><?php echo htmlspecialchars($cf['etichetta']) . $asterisk; ?></label>

                                        <?php if ($type === 'file'): ?>
                                            <input type="file" name="<?php echo htmlspecialchars($input_name); ?>[]" class="form-control form-control-sm" multiple <?php echo $req_attr; ?> <?php echo $req_data; ?>>

                                        <?php elseif ($type === 'select'): ?>
                                            <select name="<?php echo htmlspecialchars($input_name); ?>" class="form-select form-select-sm" <?php echo $req_attr; ?> <?php echo $req_data; ?>>
                                                <option value="">-- Seleziona --</option>
                                                <?php foreach ($opts as $opt): ?><option value="<?php echo htmlspecialchars($opt); ?>"><?php echo htmlspecialchars($opt); ?></option><?php endforeach; ?>
                                            </select>

                                        <?php elseif ($type === 'radio'): ?>
                                            <div class="d-flex flex-wrap gap-2 mt-1">
                                                <?php foreach ($opts as $idx => $opt): ?>
                                                    <div class="form-check form-check-inline m-0 me-2">
                                                        <input class="form-check-input" type="radio" name="<?php echo htmlspecialchars($input_name); ?>" id="<?php echo $wrap_id . '_r' . $idx; ?>" value="<?php echo htmlspecialchars($opt); ?>" <?php echo $req_attr; ?> <?php echo $req_data; ?>>
                                                        <label class="form-check-label small" for="<?php echo $wrap_id . '_r' . $idx; ?>"><?php echo htmlspecialchars($opt); ?></label>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>

                                        <?php elseif ($type === 'checkboxes'): ?>
                                            <div class="d-flex flex-wrap gap-2 mt-1">
                                                <?php foreach ($opts as $idx => $opt): ?>
                                                    <div class="form-check form-check-inline m-0 me-2">
                                                        <input class="form-check-input" type="checkbox" name="<?php echo htmlspecialchars($input_name); ?>[]" id="<?php echo $wrap_id . '_c' . $idx; ?>" value="<?php echo htmlspecialchars($opt); ?>">
                                                        <label class="form-check-label small" for="<?php echo $wrap_id . '_c' . $idx; ?>"><?php echo htmlspecialchars($opt); ?></label>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>

                                        <?php elseif ($type === 'checkbox'): ?>
                                            <div class="form-check mt-1">
                                                <input class="form-check-input" type="checkbox" name="<?php echo htmlspecialchars($input_name); ?>" id="<?php echo $wrap_id; ?>_chk" value="Sì" <?php echo $req_attr; ?> <?php echo $req_data; ?>>
                                                <label class="form-check-label small" for="<?php echo $wrap_id; ?>_chk"><?php echo htmlspecialchars($cf['etichetta']); ?></label>
                                            </div>

                                        <?php elseif ($type === 'textarea'): ?>
                                            <textarea name="<?php echo htmlspecialchars($input_name); ?>" class="form-control form-control-sm" rows="2" <?php echo $req_attr; ?> <?php echo $req_data; ?>></textarea>

                                        <?php elseif ($type === 'rating'): ?>
                                            <div class="d-flex gap-1 mt-1">
                                                <input type="hidden" name="<?php echo htmlspecialchars($input_name); ?>" id="<?php echo $wrap_id; ?>_rating" value="" <?php echo $req_attr; ?> <?php echo $req_data; ?>>
                                                <?php $nstars = max(5, count($opts)); for ($s = 1; $s <= $nstars; $s++): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-warning px-2 py-1 star-btn"
                                                        data-val="<?php echo $s; ?>"
                                                        data-target="<?php echo $wrap_id; ?>_rating"
                                                        style="font-size:1.2rem;line-height:1;"
                                                        onclick="evSetRating(this)">☆</button>
                                                <?php endfor; ?>
                                            </div>

                                        <?php elseif ($type === 'date'): ?>
                                            <input type="date" name="<?php echo htmlspecialchars($input_name); ?>" class="form-control form-control-sm" <?php echo $req_attr; ?> <?php echo $req_data; ?>>
                                        <?php elseif ($type === 'number'):
                                            // Progetti per le scuole: numero di partecipanti dentro i limiti del progetto (ricontrollato dal server)
                                            $lim_attr = '';
                                            if ($classe_m && $cf['nome_campo'] === CAMPO_PARTECIPANTI) {
                                                $lim_attr = 'min="' . (int)($t['limite_studenti_min'] ?? 1) . '" step="1"' . (!empty($t['limite_studenti_max']) ? ' max="' . (int)$t['limite_studenti_max'] . '"' : '');
                                            }
                                        ?>
                                            <input type="number" name="<?php echo htmlspecialchars($input_name); ?>" class="form-control form-control-sm" <?php echo $lim_attr; ?> <?php echo $req_attr; ?> <?php echo $req_data; ?>>
                                            <?php if ($lim_attr !== ''): ?><div class="form-text">Tra <?php echo (int)($t['limite_studenti_min'] ?? 1); ?> e <?php echo !empty($t['limite_studenti_max']) ? (int)$t['limite_studenti_max'] : 'il massimo previsto'; ?> studenti.</div><?php endif; ?>
                                        <?php elseif ($type === 'email'): ?>
                                            <input type="email" name="<?php echo htmlspecialchars($input_name); ?>" class="form-control form-control-sm" autocomplete="email" <?php echo $req_attr; ?> <?php echo $req_data; ?>>
                                        <?php elseif ($type === 'tel'): ?>
                                            <input type="tel" name="<?php echo htmlspecialchars($input_name); ?>" class="form-control form-control-sm" placeholder="+39 333 0000000" <?php echo $req_attr; ?> <?php echo $req_data; ?>>
                                        <?php elseif ($type === 'url'): ?>
                                            <input type="url" name="<?php echo htmlspecialchars($input_name); ?>" class="form-control form-control-sm" placeholder="https://" <?php echo $req_attr; ?> <?php echo $req_data; ?>>
                                        <?php elseif ($type === 'time'): ?>
                                            <input type="time" name="<?php echo htmlspecialchars($input_name); ?>" class="form-control form-control-sm" <?php echo $req_attr; ?> <?php echo $req_data; ?>>
                                        <?php elseif ($type === 'corso_studio'): ?>
                                            <?php echo html_campo_corso($conn, $cf['nome_campo'], '', trim($req_attr . ' ' . $req_data)); ?>
                                        <?php elseif ($type === 'scuola'):
                                            // Scuola dall'anagrafe del Ministero: proposta quella indicata l'ultima volta dal docente
                                            $scu_pre = scuola_per_codice($conn, $GLOBALS['logged_u_info']['scuola_codice'] ?? '');
                                            echo html_campo_scuola($cf['nome_campo'], $scu_pre ? etichetta_scuola($scu_pre) : '', $scu_pre['codice'] ?? '', trim($req_attr . ' ' . $req_data));
                                        ?>
                                        <?php else: ?>
                                            <input type="text" name="<?php echo htmlspecialchars($input_name); ?>" class="form-control form-control-sm" <?php echo $req_attr; ?> <?php echo $req_data; ?>>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php endforeach; ?>
                                </div>
                            </div>

                        <?php if ($has_conds): ?>
                        <script>
                        (function() {
                            var row = document.getElementById('cfRow_<?php echo $t['id']; ?>');
                            if (!row) return;
                            function getVal(name) {
                                var inputs = row.querySelectorAll('[name="custom_' + name + '"]');
                                if (!inputs.length) return '';
                                var first = inputs[0];
                                if (first.type === 'radio') {
                                    var chk = row.querySelector('[name="custom_' + name + '"]:checked');
                                    return chk ? chk.value.trim() : '';
                                }
                                if (first.type === 'checkbox') return first.checked ? first.value.trim() : '';
                                if (first.tagName === 'SELECT') return first.value.trim();
                                return first.value.trim();
                            }
                            function aggiorna() {
                                row.querySelectorAll('[data-cond-name]').forEach(function(wrap) {
                                    var trigName = wrap.dataset.condName;
                                    var trigVal  = wrap.dataset.condVal.trim().toLowerCase();
                                    var curVal   = getVal(trigName).toLowerCase();
                                    var show     = (curVal === trigVal);
                                    wrap.style.display = show ? '' : 'none';
                                    wrap.querySelectorAll('input,select,textarea').forEach(function(inp) {
                                        if (show && inp.dataset.origReq === '1') inp.required = true;
                                        else { inp.required = false; if (!show) inp.value = ''; }
                                    });
                                });
                            }
                            row.addEventListener('change', aggiorna);
                            row.addEventListener('input', aggiorna);
                        })();
                        </script>
                        <?php endif; ?>

                        <?php endif; // end !empty($campi_array) ?>
                        
                        <?php if ((int)($t['dett_progetto']['convenzione'] ?? 0) === 1): $cid = 'conv' . (int)$t['id'];
                            [$cv_dal, $cv_al] = periodo_attivita($t['dett_progetto']['data_inizio'] ?? null, $t['dett_progetto']['data_fine'] ?? null, $t['data_turno'] ?? null); ?>
                        <!-- CONVENZIONE CON LA SCUOLA: "No" → prenotazione in attesa finché la convenzione non arriva.
                             La convenzione deve coprire tutto il periodo dell'attività (data-dal / data-al) -->
                        <fieldset class="mt-3 p-3 rounded border conv-box" data-dal="<?php echo $cv_dal; ?>" data-al="<?php echo $cv_al; ?>">
                            <legend class="form-label small fw-bold mb-2 float-start w-100 p-0" style="font-size:.875rem; white-space:normal; overflow:visible; text-overflow:clip; position:static; line-height:1.4;"><i class="fa fa-file-signature me-1" aria-hidden="true"></i>La scuola ha già stipulato la convenzione con il Dipartimento per la Formazione Scuola Lavoro? <span class="text-danger">*</span></legend>
                            <div class="clearfix"></div>
                            <div class="form-check form-check-inline"><input class="form-check-input conv-radio" type="radio" name="convenzione" id="<?php echo $cid; ?>si" value="si" required><label class="form-check-label small" for="<?php echo $cid; ?>si">Sì, è già stipulata</label></div>
                            <div class="form-check form-check-inline"><input class="form-check-input conv-radio" type="radio" name="convenzione" id="<?php echo $cid; ?>no" value="no" required><label class="form-check-label small" for="<?php echo $cid; ?>no">No, non ancora</label></div>
                            <div class="conv-scad alert alert-danger small mt-2 mb-0" hidden><i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i>La convenzione della scuola registrata al Dipartimento non copre tutto il periodo dell'attività (<?php echo $cv_dal === $cv_al ? date('d/m/Y', strtotime($cv_dal)) : date('d/m/Y', strtotime($cv_dal)) . ' – ' . date('d/m/Y', strtotime($cv_al)); ?>): <strong>va stipulata una nuova convenzione</strong>.</div>
                            <div class="conv-reg alert alert-success small mt-2 mb-0" hidden><i class="fa fa-circle-check me-1" aria-hidden="true"></i>La scuola scelta ha già una convenzione con il Dipartimento valida per il periodo dell'attività (<span class="conv-reg-sc"></span>): non devi inviare nulla.</div>
                            <div class="conv-no alert alert-warning small mt-2 mb-0" hidden><?php echo html_istruzioni_convenzione($GLOBALS['page_cfg'] ?? []); ?></div>
                        </fieldset>
                        <script>
                        if (!window.convInit) { window.convInit = true;
                            document.addEventListener('change', function (e) {
                                if (!e.target.classList || !e.target.classList.contains('conv-radio')) return;
                                var box = e.target.closest('.conv-box'); box.querySelector('.conv-no').hidden = e.target.value !== 'no';
                            });
                            // Scuola scelta dall'anagrafe (campo-scuola.js): se nel registro c'è una convenzione che copre tutto il periodo
                            // dell'attività si risponde "Sì" da soli; se ce n'è una che non lo copre, "No" (ne va stipulata una nuova)
                            document.addEventListener('scuola-scelta', function (e) {
                                var form = e.target.closest('form'), box = form && form.querySelector('.conv-box');
                                if (!box) return;
                                var s = e.detail, reg = box.querySelector('.conv-reg'), scad = box.querySelector('.conv-scad');
                                reg.hidden = true; scad.hidden = true;
                                if (!s || !Array.isArray(s.conv) || !s.conv.length) return;
                                var dal = box.dataset.dal, al = box.dataset.al;
                                var fmt = function (d) { return d.split('-').reverse().join('/'); };
                                var ok = s.conv.filter(function (p) { return (!p[0] || p[0] <= dal) && (!p[1] || p[1] >= al); })[0];
                                box.querySelector('.conv-radio[value="' + (ok ? 'si' : 'no') + '"]').checked = true;
                                box.querySelector('.conv-no').hidden = !!ok;
                                if (ok) {
                                    box.querySelector('.conv-reg-sc').textContent = ok[1] ? (ok[0] ? 'dal ' + fmt(ok[0]) + ' al ' : 'fino al ') + fmt(ok[1]) : 'senza scadenza';
                                    reg.hidden = false;
                                } else scad.hidden = false;
                            });
                        }
                        </script>
                        <?php endif; ?>

                        <?php if (!$utente_logged): $cap = captcha_prenotazione(); ?>
                        <!-- CONTROLLO ANTI-ROBOT (solo prenotazioni senza accesso) -->
                        <div class="mt-3 p-3 rounded border bg-light">
                            <label for="captcha_<?php echo $t['id']; ?>" class="form-label small fw-bold mb-1"><i class="fa fa-shield-halved me-1" aria-hidden="true"></i>Controllo anti-robot: <?php echo htmlspecialchars($cap['domanda']); ?> <span class="text-danger">*</span></label>
                            <input type="text" name="captcha_risposta" id="captcha_<?php echo $t['id']; ?>" class="form-control form-control-sm" style="max-width:140px;" inputmode="numeric" pattern="[0-9]*" maxlength="3" autocomplete="off" required>
                            <input type="hidden" name="captcha_id" value="<?php echo htmlspecialchars($cap['id']); ?>">
                            <div class="form-text">Con l'accesso SPID, CIE o Unical questa domanda non viene chiesta.</div>
                        </div>
                        <!-- Campo trappola: invisibile alle persone, i robot lo compilano -->
                        <div style="position:absolute; left:-10000px; width:1px; height:1px; overflow:hidden;" aria-hidden="true">
                            <label for="sitoWeb_<?php echo $t['id']; ?>">Sito web (lascia vuoto)</label>
                            <input type="text" name="sito_web" id="sitoWeb_<?php echo $t['id']; ?>" value="" tabindex="-1" autocomplete="off">
                        </div>
                        <?php endif; ?>

                        <!-- CHECKBOX PRIVACY OBBLIGATORIO -->
                        <div class="form-check mt-3 mb-1 p-3 bg-light rounded border border-secondary shadow-sm">
                            <input class="form-check-input border-secondary" type="checkbox" name="accetta_privacy" id="privacyCheck_<?php echo $t['id']; ?>" required>
                            <label class="form-check-label text-dark" for="privacyCheck_<?php echo $t['id']; ?>" style="font-size: 0.85rem; line-height: 1.4;">
                                <?php // Base giuridica: compito di interesse pubblico (art. 6.1.e GDPR), non il consenso: si dichiara solo la presa visione ?>
                                Ho letto l'<a href="privacy.php" target="_blank" rel="noopener" class="fw-bold" style="color: <?php echo colore_testo_su($col_primaria) === '#FFFFFF' ? $col_primaria : '#1F2937'; ?>; text-decoration: underline;">informativa sul trattamento dei dati personali</a>.
                            </label>
                        </div>
                        
                    </div>
                    <div class="modal-footer py-2 bg-light border-top-0">
                        <?php if($is_waitlist): ?>
                            <button type="submit" name="invia_prenotazione" class="btn btn-warning btn-sm fw-bold w-100 text-dark border-0"><?php echo $per_scuole_m ? "Metti la scuola in lista d'attesa" : "Aggiungimi in Lista d'Attesa"; ?></button>
                        <?php else: ?>
                            <button type="submit" name="invia_prenotazione" class="btn btn-primary btn-sm fw-bold w-100 shadow-sm" style="background-color: <?php echo $col_primaria; ?>; border: none;"><?php echo $per_scuole_m ? 'Conferma l\'iscrizione della scuola' : ($is_progetto ? 'Conferma l\'iscrizione' : 'Conferma e Prenota'); ?></button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php
}

function getPulsanteAzione($t, $col_primaria, $utente_logged, $utente_ruolo_id, $conn, $nomi_ruoli) {
    $ruolo_richiesto = (int)($t['ruolo_accesso_id'] ?? 0);
    $sec_roles = isset($_SESSION['utente_ruoli_secondari']) ? explode(',', $_SESSION['utente_ruoli_secondari']) : [];
    $is_admin = ($utente_ruolo_id === 1 || in_array('1', $sec_roles));
    
    if ($ruolo_richiesto === 0) { $ruolo_ok = true; } 
    elseif ($ruolo_richiesto === -1) { $ruolo_ok = $utente_logged; } 
    else { $ruolo_ok = ($utente_logged && ($utente_ruolo_id === $ruolo_richiesto || in_array((string)$ruolo_richiesto, $sec_roles) || $is_admin)); }

    if ((int)$t['richiede_prenotazione'] == 0) {
        return '<span class="badge bg-success py-2 px-3 shadow-sm fs-6"><i class="fa fa-unlock me-1"></i> Libero</span>';
    }

    $occ = getPostiOccupati($conn, $t['id']);
    $disponibili = $t['max_posti'] - $occ;
    $soldout = ($disponibili <= 0);
    $is_waitlist = ($soldout && isset($t['abilita_lista_attesa']) && $t['abilita_lista_attesa'] == 1);
    
    $now = date('Y-m-d H:i:s');

    if (turno_concluso($t)) {
        return '<button class="btn btn-secondary btn-sm fw-bold py-1 px-3 shadow-sm" disabled><i class="fa fa-flag-checkered me-1"></i> Evento Concluso</button>';
    }

    if (!empty($t['data_apertura']) && $now < $t['data_apertura']) {
        return '<button class="btn btn-secondary btn-sm fw-bold py-1 px-3 shadow-sm" disabled>Apertura: '.date('d/m H:i', strtotime($t['data_apertura'])).'</button>';
    } elseif (!empty($t['data_chiusura']) && $now > $t['data_chiusura']) {
        return '<button class="btn btn-secondary btn-sm fw-bold py-1 px-3 shadow-sm" disabled>Prenotazioni Chiuse</button>';
    }

    if ($soldout && !$is_waitlist) {
        return '<button class="btn btn-danger btn-sm fw-bold py-1 px-3 shadow-sm" disabled>Sold Out</button>';
    } elseif (($ruolo_richiesto > 0 || $ruolo_richiesto === -1) && !$utente_logged) {
        return '<a href="saml_login.php" class="btn btn-primary btn-sm fw-bold py-1 px-3 shadow-sm" style="background-color: '.$col_primaria.'; color: '.colore_testo_su($col_primaria).'; border: none;"><i class="fa fa-key me-1"></i> Accedi</a>';
    } elseif (($ruolo_richiesto > 0 || $ruolo_richiesto === -1) && $utente_logged && !$ruolo_ok) {
        return '<button class="btn btn-secondary btn-sm fw-bold py-1 px-3" disabled>🔒 Riservato</button>';
    } elseif ($is_waitlist) {
        return '<button type="button" class="btn btn-warning btn-sm fw-bold text-dark py-1 px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#modPrenota'.$t['id'].'">Lista d\'Attesa</button>';
    } else {
        return '<button type="button" class="btn btn-primary btn-sm fw-bold py-1 px-4 shadow-sm" style="background-color: '.$col_primaria.'; color: '.colore_testo_su($col_primaria).'; border: none;" data-bs-toggle="modal" data-bs-target="#modPrenota'.$t['id'].'">Prenota Ora</button>';
    }
}

// HTML HEADER
require_once 'header.php'; 
?>
<style>
    /* FIX STICKY: Rimuove l'overflow nascosto che blocca lo scrolling della sidebar nel framework AGID */
    html, body { overflow-x: visible !important; overflow-y: visible !important;}
    .it-header-wrapper { overflow-x: clip !important; } /* Limitiamo l'overflow-x nascosto solo all'header */
    main, .container-fluid { overflow: visible !important; }
    
    .sidebar-sticky-fix {
        position: -webkit-sticky !important;
        position: sticky !important;
        top: 110px !important;
        z-index: 1020;
    }
    /* Bootstrap Italia aggiunge 48px sotto ogni card (::after): nelle card degli eventi il pulsante resta sul fondo */
    .card-evento-u::after { display: none; }
    .star-btn { transition: color .1s; }
    .star-btn.active, .star-btn:hover, .star-btn.hover { color: #f59e0b !important; border-color: #f59e0b !important; }
    .star-btn.active::before { content: '★'; position:absolute; }
</style>
<script>
function evSetRating(btn) {
    var targetId = btn.dataset.target;
    var val = btn.dataset.val;
    var container = btn.parentElement;
    container.querySelectorAll('.star-btn').forEach(function(b) {
        b.textContent = parseInt(b.dataset.val) <= parseInt(val) ? '★' : '☆';
        b.classList.toggle('active', parseInt(b.dataset.val) <= parseInt(val));
    });
    document.getElementById(targetId).value = val;
}
</script>

<!-- BANNER MESSAGGI E TASTO CALENDARIO RAPIDO (Per tutti tranne Calendar Layout) -->
<?php if ($layout_template !== 'calendar' || $progetto_sel || $evento_sel): ?>
<div class="container mt-3" style="max-width: <?php echo htmlspecialchars($page_cfg['larghezza_contenitore'] ?? '85%'); ?>;">
    <?php echo $banner_manutenzione_admin; ?>
    <?php echo $messaggio_prenotazione; ?>
        
</div>
<?php endif; ?>

<!-- ======================================================= -->
<!-- SCHEDA DI UN PROGETTO (?progetto=ID), con qualsiasi layout dell'area -->
<!-- ======================================================= -->
<?php if ($progetto_sel):
    $ev_p = $progetto_sel;
    $ip   = $info_progetto($ev_p);
    $dp   = $ip['d']; $st_p = $ip['stato']; $tp = $ip['t'];
    $txt_on_p = colore_testo_su($col_primaria);
    // Periodo, sede e destinatari stanno già nell'intestazione
    $sintesi = array_filter([
        ['fa-calendar-days', 'Calendario', (!empty($dp['data_inizio']) || !empty($dp['data_fine'])) ? ($dp['periodo_note'] ?? '') : ''],
        ['fa-clock', 'Ore totali', !empty($dp['ore_totali']) ? (int)$dp['ore_totali'] . ' ore' : ''],
        ['fa-clone', 'Edizioni', count($ip['edizioni']) > 1 ? count($ip['edizioni']) . ($ip['scuole'] ? ' (una scuola per edizione)' : '') : ''],
        ['fa-people-arrows', 'Incontri previsti', !empty($dp['incontri_previsti']) ? (string)(int)$dp['incontri_previsti'] : ''],
        ['fa-users', 'Studenti per scuola', $ip['scuole'] ? $ip['limiti'] . (count($ip['edizioni']) > 1 && $ip['limiti'] !== '' ? ' (secondo l\'edizione)' : '') : ''],
        ['fa-laptop-house', 'Modalità', $dp['modalita'] ?? ''],
        ['fa-graduation-cap', 'Attestato', !empty($dp['attestati']) ? ($ip['scuole'] ? 'Per ogni studente partecipante' : 'Di partecipazione') : ''],
    ], fn($r) => trim((string)$r[2]) !== '');
?>
    <style>
        .pj-hero { background: <?php echo $col_primaria; ?>; color: <?php echo $txt_on_p; ?>; border-radius: 18px; padding: 2rem 2rem 1.75rem; position: relative; overflow: hidden; }
        .pj-hero::after { content: ""; position: absolute; right: -80px; top: -80px; width: 260px; height: 260px; border-radius: 50%; background: rgba(255,255,255,.10); }
        .pj-hero h1 { color: inherit; font-weight: 800; letter-spacing: -.5px; font-size: clamp(1.4rem, 2.6vw, 2.25rem); line-height: 1.2; max-width: 900px; }
        .pj-hero .pj-fatti { display: flex; flex-wrap: wrap; gap: .5rem 1.5rem; font-weight: 600; opacity: .95; }
        .pj-corso { font-size: 1.05rem; font-weight: 600; opacity: .95; }
        .pj-corso a:hover, .pj-corso a:focus { opacity: .85; }
        .pj-stato { display: inline-flex; align-items: center; gap: .35rem; font-size: .78rem; font-weight: 700; padding: .3rem .75rem; border-radius: 999px; }
        .pj-box { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; box-shadow: 0 2px 10px rgba(15,23,42,.05); }
        .pj-box h2 { font-size: .8rem; text-transform: uppercase; letter-spacing: .07em; font-weight: 800; color: #475569; margin-bottom: 1rem; }
        .pj-desc { font-size: 1rem; line-height: 1.75; color: #1f2937; overflow-wrap: anywhere; }
        .pj-desc img { max-width: 100%; height: auto; }
        .pj-sintesi { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: .75rem; }
        .pj-fatto { display: flex; gap: .75rem; align-items: center; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: .8rem 1rem; }
        .pj-fatto-ico { flex: 0 0 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; background: <?php echo $col_primaria; ?>1A; color: <?php echo $col_testo_area; ?>; font-size: 1.05rem; }
        .pj-sintesi dt { font-size: .7rem; text-transform: uppercase; letter-spacing: .05em; color: #64748b; font-weight: 700; }
        .pj-sintesi dd { margin: 0; font-weight: 700; color: #111827; line-height: 1.3; }
        .pj-persona { display: flex; gap: .75rem; align-items: flex-start; padding: .75rem 0; border-top: 1px solid #f1f5f9; }
        .pj-persona:first-of-type { border-top: 0; padding-top: 0; }
        .pj-avatar { flex: 0 0 42px; height: 42px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 800; background: <?php echo $col_primaria; ?>; color: <?php echo $txt_on_p; ?>; }
        .pj-persona a { color: #1f2937; text-decoration: none; overflow-wrap: anywhere; }
        .pj-persona a:hover { text-decoration: underline; }
        .pj-posto { display: flex; align-items: center; gap: .75rem; padding: .75rem 1rem; border-radius: 12px; background: #f8fafc; }
        .pj-percorso { list-style: none; padding: 0; margin: 0; position: relative; }
        .pj-percorso::before { content: ""; position: absolute; left: 17px; top: 8px; bottom: 8px; width: 2px; background: #e5e7eb; }
        .pj-percorso li { display: flex; gap: 1rem; padding-bottom: 1.25rem; position: relative; }
        .pj-percorso li:last-child { padding-bottom: 0; }
        .pj-tappa { flex: 0 0 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 800; background: <?php echo $col_primaria; ?>; color: <?php echo $txt_on_p; ?>; position: relative; z-index: 1; }
        .pj-tappa-tit { font-size: 1.02rem; font-weight: 800; margin: .35rem 0 .4rem; color: #111827; }
        .pj-tag { display: inline-flex; align-items: center; gap: .35rem; background: #f1f5f9; color: #334155; border-radius: 999px; padding: .15rem .6rem; font-size: .78rem; font-weight: 600; }
        @media (min-width: 992px) { .pj-side { position: sticky; top: 110px; } }
        @media (max-width: 575.98px) {
            .pj-hero { padding: 1.25rem; border-radius: 14px; }
            .pj-sintesi { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .5rem; }
            .pj-fatto { flex-direction: column; align-items: flex-start; gap: .4rem; padding: .7rem; }
        }
    </style>

    <div class="container mb-5" style="max-width: <?php echo htmlspecialchars($page_cfg['larghezza_contenitore'] ?? '85%'); ?>;">
        <nav aria-label="Percorso" class="my-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <a href="<?php echo htmlspecialchars($current_filename); ?>.php" class="fw-bold text-decoration-none" style="color: <?php echo $col_testo_area; ?>;"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i><?php echo htmlspecialchars(!empty($page_cfg['titolo']) ? $page_cfg['titolo'] : 'Tutti i progetti'); ?></a>
            <button type="button" class="btn btn-sm btn-outline-secondary fw-bold" id="pjCopiaLink"><i class="fa fa-link me-1" aria-hidden="true"></i><span>Copia il link</span></button>
        </nav>

        <header class="pj-hero shadow-sm mb-4">
            <div class="d-flex flex-wrap gap-2 mb-3 position-relative" style="z-index:1;">
                <span class="pj-stato" style="background: <?php echo $st_p['bg']; ?>; color: <?php echo $st_p['fg']; ?>;"><?php echo htmlspecialchars($st_p['etichetta']); ?></span>
            </div>
            <h1 class="mb-2 position-relative" style="z-index:1;"><?php echo htmlspecialchars($ev_p['titolo']); ?></h1>
            <?php $corso_p = html_corso_pubblico($conn, $dp, 'text-decoration:underline;text-underline-offset:3px;'); ?>
            <?php if ($corso_p !== ''): ?><p class="pj-corso position-relative mb-3" style="z-index:1;"><i class="fa fa-building-columns me-2" aria-hidden="true"></i><?php echo $corso_p; ?></p><?php else: ?><div class="mb-3"></div><?php endif; ?>
            <div class="pj-fatti position-relative" style="z-index:1;">
                <span><i class="fa fa-calendar-days me-1" aria-hidden="true"></i><?php echo htmlspecialchars($ip['periodo']); ?></span>
                <?php if (!empty($ev_p['luogo'])): ?><span><i class="fa fa-location-dot me-1" aria-hidden="true"></i><?php echo htmlspecialchars($ev_p['luogo']); ?></span><?php endif; ?>
                <?php if (!empty($dp['destinatari'])): ?><span><i class="fa fa-user-graduate me-1" aria-hidden="true"></i><?php echo htmlspecialchars($dp['destinatari']); ?></span><?php endif; ?>
            </div>
        </header>

        <?php if ($sintesi): ?>
            <h2 class="visually-hidden">In sintesi</h2>
            <dl class="pj-sintesi mb-4">
                <?php foreach ($sintesi as [$ico, $lbl, $val]): ?>
                    <div class="pj-fatto">
                        <span class="pj-fatto-ico" aria-hidden="true"><i class="fa <?php echo $ico; ?>"></i></span>
                        <div><dt><?php echo $lbl; ?></dt><dd><?php echo htmlspecialchars($val); ?></dd></div>
                    </div>
                <?php endforeach; ?>
            </dl>
        <?php endif; ?>

        <div class="row g-4 align-items-start">
            <div class="col-lg-8">
                <?php if (!empty($ev_p['locandina_path']) && preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $ev_p['locandina_path'])): ?>
                    <img src="<?php echo htmlspecialchars($ev_p['locandina_path']); ?>" alt="" class="w-100 mb-4 shadow-sm" style="border-radius: 14px; max-height: 380px; object-fit: cover;">
                <?php endif; ?>

                <article class="pj-box p-4 mb-4">
                    <h2><i class="fa fa-book-open me-1" aria-hidden="true"></i>Il progetto</h2>
                    <div class="pj-desc"><?php echo !empty($ev_p['descrizione']) ? $ev_p['descrizione'] : '<p class="text-muted">Descrizione in arrivo.</p>'; ?></div>
                    <?php if (!empty($ev_p['allegato_pdf'])): ?>
                        <a href="<?php echo htmlspecialchars($ev_p['allegato_pdf']); ?>" target="_blank" rel="noopener" class="btn btn-outline-danger fw-bold mt-3"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>Programma e calendario (PDF)</a>
                    <?php endif; ?>
                </article>

                <?php if (!empty($dp['moduli'])): ?>
                    <section class="pj-box p-4 mb-4">
                        <h2><i class="fa fa-route me-1" aria-hidden="true"></i>Articolazione del percorso</h2>
                        <ol class="pj-percorso">
                            <?php foreach ($dp['moduli'] as $mi => $mo): ?>
                                <li>
                                    <span class="pj-tappa" aria-hidden="true"><?php echo $mi + 1; ?></span>
                                    <div>
                                        <h3 class="pj-tappa-tit"><?php echo htmlspecialchars($mo['titolo'] ?? ''); ?></h3>
                                        <div class="d-flex flex-wrap gap-2 mb-1">
                                            <?php if (!empty($mo['ore'])): ?><span class="pj-tag"><i class="fa fa-clock" aria-hidden="true"></i><?php echo (int)$mo['ore']; ?> <?php echo (int)$mo['ore'] === 1 ? 'ora' : 'ore'; ?></span><?php endif; ?>
                                            <?php if (!empty($mo['modalita'])): ?><span class="pj-tag"><i class="fa <?php echo stripos($mo['modalita'], 'online') !== false ? 'fa-laptop' : (stripos($mo['modalita'], 'laborator') !== false ? 'fa-flask' : 'fa-people-group'); ?>" aria-hidden="true"></i><?php echo htmlspecialchars($mo['modalita']); ?></span><?php endif; ?>
                                            <?php if (!empty($mo['quando'])): ?><span class="pj-tag"><i class="fa fa-calendar-day" aria-hidden="true"></i><?php echo htmlspecialchars($mo['quando']); ?></span><?php endif; ?>
                                            <?php if (!empty($mo['sede'])): ?><span class="pj-tag"><i class="fa fa-location-dot" aria-hidden="true"></i><?php echo htmlspecialchars($mo['sede']); ?></span><?php endif; ?>
                                        </div>
                                        <?php if (!empty($mo['descrizione'])): ?><p class="mb-0 text-secondary" style="font-size: .93rem; line-height: 1.6;"><?php echo nl2br(htmlspecialchars($mo['descrizione'])); ?></p><?php endif; ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    </section>
                <?php endif; ?>

                <?php foreach ([['obiettivi', 'fa-bullseye', 'Obiettivi formativi'], ['conoscenze', 'fa-book', 'Conoscenze'], ['competenze', 'fa-award', 'Competenze attese']] as [$k_s, $ico_s, $tit_s]):
                    if (trim(strip_tags((string)($dp[$k_s] ?? ''))) === '') continue; ?>
                    <section class="pj-box p-4 mb-4">
                        <h2><i class="fa <?php echo $ico_s; ?> me-1" aria-hidden="true"></i><?php echo $tit_s; ?></h2>
                        <div class="pj-desc"><?php echo $dp[$k_s]; ?></div>
                    </section>
                <?php endforeach; ?>

                <?php if (!empty($dp['info_extra'])): ?>
                    <section class="pj-box p-4 mb-4">
                        <h2><i class="fa fa-table-list me-1" aria-hidden="true"></i>Altre informazioni</h2>
                        <dl class="row mb-0">
                            <?php foreach ($dp['info_extra'] as $ie): ?>
                                <dt class="col-sm-4 text-secondary fw-semibold"><?php echo htmlspecialchars($ie['etichetta'] ?? ''); ?></dt>
                                <dd class="col-sm-8 fw-semibold"><?php echo nl2br(htmlspecialchars($ie['valore'] ?? '')); ?></dd>
                            <?php endforeach; ?>
                        </dl>
                    </section>
                <?php endif; ?>
            </div>

            <aside class="col-lg-4">
                <div class="pj-side d-flex flex-column gap-4">
                    <?php if ($tp && (int)($ev_p['richiede_prenotazione'] ?? 1) === 1):
                        $piu_ed = count($ip['edizioni']) > 1;
                        // Il login e gli stati "chiuso / in arrivo / concluso" valgono per tutto il progetto: un solo pulsante
                        $pulsante_unico = !$utente_logged || !in_array($st_p['codice'], ['aperte', 'attesa'], true) || !$piu_ed;
                    ?>
                    <section class="pj-box p-4" id="iscrizione" style="border-top: 4px solid <?php echo $col_primaria; ?>;">
                        <?php $sc_p = $ip['scuole']; ?>
                        <h2><i class="fa <?php echo $sc_p ? 'fa-school' : 'fa-user-plus'; ?> me-1" aria-hidden="true"></i><?php echo $piu_ed ? 'Edizioni e iscrizione' : ($sc_p ? 'Iscrizione della scuola' : 'Iscrizione'); ?></h2>
                        <?php if ($piu_ed): ?>
                            <p class="small text-secondary mb-2">Il progetto si ripete in <strong><?php echo count($ip['edizioni']); ?> edizioni</strong><?php echo $sc_p ? ': ognuna accoglie una scuola' : ''; ?>. Puoi iscriverti a una sola edizione.</p>
                        <?php endif; ?>
                        <div class="d-flex flex-column gap-2 mb-3">
                            <?php foreach ($ip['edizioni'] as $ed):
                                $liberi_ed = max(0, (int)$ed['t']['max_posti'] - (int)$ed['occ']);
                                if ($sc_p) $txt_ed = $ed['libera'] ? 'Posto disponibile' : 'Già assegnata a una scuola';
                                else $txt_ed = $ed['libera'] ? $liberi_ed . ($liberi_ed === 1 ? ' posto libero' : ' posti liberi') . ' su ' . (int)$ed['t']['max_posti'] : 'Posti esauriti';
                            ?>
                                <div class="pj-posto d-block">
                                  <div class="d-flex align-items-center gap-3">
                                    <i class="fa <?php echo ($ed['mio'] !== null || $ed['libera']) ? 'fa-circle-check text-success' : 'fa-lock text-warning'; ?> fs-4" aria-hidden="true"></i>
                                    <div class="flex-grow-1" style="min-width: 0;">
                                        <?php if ($piu_ed):
                                            $ta_ed = $ed['t']['data_apertura'] ?? null; $tc_ed = $ed['t']['data_chiusura'] ?? null;
                                            $lim_ed = $sc_p ? testo_limiti_partecipanti($ed['min'], $ed['max']) : ''; ?>
                                            <div class="fw-bold"><?php echo htmlspecialchars($ed['etichetta']); ?></div>
                                            <div class="small text-secondary"><i class="fa fa-door-open me-1" aria-hidden="true"></i>Iscrizioni <?php echo $ta_ed ? 'dal ' . date('d/m/Y H:i', strtotime($ta_ed)) : 'già aperte'; ?><?php echo $tc_ed ? ' al ' . date('d/m/Y H:i', strtotime($tc_ed)) : ''; ?></div>
                                            <?php if ($lim_ed !== ''): ?><div class="small text-secondary"><i class="fa fa-users me-1" aria-hidden="true"></i><?php echo htmlspecialchars(ucfirst($lim_ed)); ?> studenti</div><?php endif; ?>
                                            <?php if (!in_array($ed['stato']['codice'], ['aperte', 'attesa'], true)): ?><span class="badge mt-1" style="background: <?php echo $ed['stato']['bg']; ?>; color: <?php echo $ed['stato']['fg']; ?>;"><?php echo htmlspecialchars($ed['stato']['etichetta']); ?></span><?php endif; ?>
                                        <?php endif; ?>
                                        <div class="<?php echo $piu_ed ? 'small text-secondary' : 'fw-bold'; ?>"><?php echo htmlspecialchars($txt_ed); ?></div>
                                        <?php if (!$ed['libera'] && !empty($ed['t']['abilita_lista_attesa'])): ?><div class="small text-secondary"><?php echo $ed['attesa'] > 0 ? $ed['attesa'] . ($sc_p ? ($ed['attesa'] === 1 ? ' scuola' : ' scuole') : ($ed['attesa'] === 1 ? ' persona' : ' persone')) . " in lista d'attesa" : "Lista d'attesa vuota"; ?></div><?php endif; ?>
                                        <?php if ($sc_p && !$piu_ed && $ed['libera']): ?><div class="small text-secondary">Il progetto accoglie una sola scuola.</div><?php endif; ?>
                                        <?php if (!$sc_p && $ed['libera'] && (int)$ed['t']['max_posti'] > 0 && (int)$ed['t']['max_posti'] < 9000):
                                            $pct_ed = min(100, (int)round($ed['occ'] / max(1, (int)$ed['t']['max_posti']) * 100)); ?>
                                            <div class="progress mt-1" style="height: 6px;" role="progressbar" aria-label="Posti occupati" aria-valuenow="<?php echo $pct_ed; ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar <?php echo $pct_ed >= 75 ? 'bg-warning' : 'bg-success'; ?>" style="width: <?php echo $pct_ed; ?>%;"></div></div>
                                        <?php endif; ?>
                                    </div>
                                  </div>
                                    <?php if (!$pulsante_unico): $btn_ed = $pulsante_progetto($ev_p, $ip, $ed); ?>
                                        <?php if ($btn_ed !== ''): ?><div class="mt-2"><?php echo $btn_ed; ?></div><?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if (!$piu_ed): ?>
                        <ul class="list-unstyled small mb-3">
                            <li class="mb-1"><i class="fa fa-door-open me-2 text-secondary" aria-hidden="true"></i>Apertura: <strong><?php echo !empty($tp['data_apertura']) ? date('d/m/Y \o\r\e H:i', strtotime($tp['data_apertura'])) : 'già aperte'; ?></strong></li>
                            <?php if (!empty($tp['data_chiusura'])): ?><li><i class="fa fa-door-closed me-2 text-secondary" aria-hidden="true"></i>Chiusura: <strong><?php echo date('d/m/Y \o\r\e H:i', strtotime($tp['data_chiusura'])); ?></strong></li><?php endif; ?>
                        </ul>
                        <?php endif; ?>
                        <?php if ($pulsante_unico) echo $pulsante_progetto($ev_p, $ip, $piu_ed ? null : $ip['edizioni'][0]); ?>
                        <?php if ($ip['mio_ed'] && $piu_ed): ?><p class="small text-success fw-semibold mt-2 mb-0"><i class="fa fa-check me-1" aria-hidden="true"></i><?php echo $sc_p ? 'La tua scuola' : 'La tua edizione'; ?>: <?php echo htmlspecialchars($ip['mio_ed']['etichetta']); ?></p><?php endif; ?>
                        <?php if ((int)($ev_p['ruolo_accesso_id'] ?? 0) !== 0 && !$utente_logged): ?>
                            <p class="small text-secondary mt-2 mb-0"><?php echo $sc_p ? "L'iscrizione la effettua il docente referente della scuola, con SPID, CIE o credenziali Unical." : "Per iscriverti accedi con SPID, CIE o credenziali Unical."; ?></p>
                        <?php endif; ?>
                    </section>
                    <?php endif; ?>

                    <?php if (!empty($dp['referenti'])): ?>
                    <section class="pj-box p-4">
                        <h2><i class="fa fa-address-book me-1" aria-hidden="true"></i>Contatti</h2>
                        <?php foreach ($dp['referenti'] as $rf) echo html_referente_pubblico($conn, $rf, $col_testo_area); ?>
                    </section>
                    <?php endif; ?>
                </div>
            </aside>
        </div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var btn = document.getElementById('pjCopiaLink');
        if (!btn) return;
        btn.addEventListener('click', function () {
            var url = location.origin + location.pathname + '?progetto=<?php echo (int)$ev_p['id']; ?>';
            var fatto = function () { btn.querySelector('span').textContent = 'Link copiato!'; setTimeout(function () { btn.querySelector('span').textContent = 'Copia il link'; }, 2000); };
            if (navigator.clipboard) navigator.clipboard.writeText(url).then(fatto, function () { prompt('Copia il link:', url); });
            else prompt('Copia il link:', url);
        });
    });
    </script>

<!-- ======================================================= -->
<!-- SCHEDA DI UN EVENTO (?evento=ID), con qualsiasi layout dell'area -->
<!-- ======================================================= -->
<?php elseif ($evento_sel):
    $ev_s   = $evento_sel;
    $dett_s = $dettagli_progetti[(int)$ev_s['id']] ?? [];
    $txt_on_s = colore_testo_su($col_primaria);
    $req_s  = (int)($ev_s['richiede_prenotazione'] ?? 1) === 1;
    $ruolo_s = (int)($ev_s['ruolo_accesso_id'] ?? 0);
    // Turni con posti e stato
    $turni_s = []; $liberi_s = 0; $prossima_s = null; $illimitati_s = false;
    foreach ($ev_s['turni'] as $t) {
        $occ = $req_s ? getPostiOccupati($conn, $t['id']) : 0;
        $max = (int)$t['max_posti'];
        $concluso = turno_concluso($t);
        if (!$concluso) {
            if ($max >= 9000) $illimitati_s = true; else $liberi_s += max(0, $max - $occ);
            if (!empty($t['data_turno']) && ($prossima_s === null || $t['data_turno'] < $prossima_s)) $prossima_s = $t['data_turno'];
        }
        $turni_s[] = ['t' => $t, 'occ' => $occ, 'max' => $max, 'liberi' => max(0, $max - $occ), 'concluso' => $concluso];
    }
    $mie_s = $mie_iscrizioni[(int)$ev_s['id']] ?? [];
    // Google Maps: ricerca del luogo (se non è un indirizzo completo si aggiunge l'Università della Calabria)
    $luogo_s = trim((string)($ev_s['luogo'] ?? ''));
    $query_maps = $luogo_s !== '' ? (preg_match('/unical|universit|via |piazza|rende|cosenza/i', $luogo_s) ? $luogo_s : $luogo_s . ', Università della Calabria, Rende') : '';
    $url_maps = $query_maps !== '' ? 'https://www.google.com/maps/search/?api=1&query=' . urlencode($query_maps) : '';
?>
    <style>
        .ev-hero { background: <?php echo $col_primaria; ?>; color: <?php echo $txt_on_s; ?>; border-radius: 18px; padding: 2rem; position: relative; overflow: hidden; }
        .ev-hero::after { content: ""; position: absolute; right: -80px; top: -80px; width: 260px; height: 260px; border-radius: 50%; background: rgba(255,255,255,.10); }
        .ev-hero > * { position: relative; z-index: 1; }
        .ev-hero h1 { color: inherit; font-weight: 800; letter-spacing: -.5px; font-size: clamp(1.4rem, 2.6vw, 2.25rem); line-height: 1.2; }
        .ev-hero a { color: inherit; }
        .ev-chip { display: inline-flex; align-items: center; gap: .35rem; font-size: .78rem; font-weight: 700; padding: .3rem .75rem; border-radius: 999px; background: rgba(255,255,255,.18); }
        .ev-box { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; box-shadow: 0 2px 10px rgba(15,23,42,.05); }
        .ev-box h2 { font-size: .8rem; text-transform: uppercase; letter-spacing: .07em; font-weight: 800; color: #475569; margin-bottom: 1rem; }
        .ev-desc { font-size: 1rem; line-height: 1.75; color: #1f2937; overflow-wrap: anywhere; }
        .ev-desc img { max-width: 100%; height: auto; }
        .ev-fatti { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: .75rem; }
        .ev-fatto { display: flex; gap: .75rem; align-items: center; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: .8rem 1rem; }
        .ev-fatto-ico { flex: 0 0 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; background: <?php echo $col_primaria; ?>1A; color: <?php echo $col_testo_area; ?>; }
        .ev-fatto dt { font-size: .7rem; text-transform: uppercase; letter-spacing: .05em; color: #64748b; font-weight: 700; }
        .ev-fatto dd { margin: 0; font-weight: 700; color: #111827; }
        .ev-turno { border: 1px solid #e5e7eb; border-radius: 12px; padding: .85rem 1rem; background: #fff; }
        .ev-turno.concluso { opacity: .6; }
        .ev-persona { display: flex; gap: .75rem; align-items: flex-start; padding: .75rem 0; border-top: 1px solid #f1f5f9; }
        .ev-persona:first-of-type { border-top: 0; padding-top: 0; }
        .ev-avatar { flex: 0 0 42px; height: 42px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 800; background: <?php echo $col_primaria; ?>; color: <?php echo $txt_on_s; ?>; }
        .ev-persona a { color: #1f2937; text-decoration: none; overflow-wrap: anywhere; }
        .ev-persona a:hover { text-decoration: underline; }
        @media (min-width: 992px) { .ev-side { position: sticky; top: 110px; } }
        @media (max-width: 575.98px) { .ev-hero { padding: 1.25rem; border-radius: 14px; } .ev-fatti { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .5rem; } .ev-fatto { flex-direction: column; align-items: flex-start; gap: .4rem; padding: .7rem; } }
    </style>

    <div class="container mb-5" style="max-width: <?php echo htmlspecialchars($page_cfg['larghezza_contenitore'] ?? '85%'); ?>;">
        <nav aria-label="Percorso" class="my-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <a href="<?php echo htmlspecialchars($current_filename); ?>.php" class="fw-bold text-decoration-none" style="color: <?php echo $col_testo_area; ?>;"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i><?php echo htmlspecialchars(!empty($page_cfg['titolo']) ? $page_cfg['titolo'] : 'Tutti gli eventi'); ?></a>
            <button type="button" class="btn btn-sm btn-outline-secondary fw-bold" id="evCopiaLink"><i class="fa fa-link me-1" aria-hidden="true"></i><span>Copia il link</span></button>
        </nav>

        <header class="ev-hero shadow-sm mb-4">
            <div class="d-flex flex-wrap gap-2 mb-3">
                <?php if (!empty($ev_s['nome_sottocategoria'])): ?><span class="ev-chip"><i class="fa fa-folder-open" aria-hidden="true"></i><?php echo htmlspecialchars($ev_s['nome_sottocategoria']); ?></span><?php endif; ?>
                <?php if (!$req_s): ?><span class="ev-chip"><i class="fa fa-unlock" aria-hidden="true"></i>Ingresso libero</span>
                <?php elseif ($ruolo_s === -1): ?><span class="ev-chip"><i class="fa fa-key" aria-hidden="true"></i>Solo utenti autenticati</span>
                <?php elseif ($ruolo_s > 0): ?><span class="ev-chip"><i class="fa fa-lock" aria-hidden="true"></i><?php echo htmlspecialchars($etichette_riservato[$ruolo_s] ?? 'Riservato'); ?></span><?php endif; ?>
            </div>
            <h1 class="mb-3"><?php echo htmlspecialchars($ev_s['titolo']); ?></h1>
            <?php $breve_s = pulisci_descrizione_breve((string)($ev_s['descrizione_breve'] ?? '')); if ($breve_s !== ''): ?><p class="fs-5 mb-3" style="opacity:.92; max-width: 820px;"><?php echo $breve_s; ?></p><?php endif; ?>
            <div class="d-flex flex-wrap gap-3 fw-semibold">
                <?php if ($prossima_s): ?><span><i class="fa fa-calendar-days me-1" aria-hidden="true"></i><?php echo count($turni_s) > 1 ? 'Prossimo: ' : ''; ?><?php echo date('d/m/Y', strtotime($prossima_s)); ?></span><?php endif; ?>
                <?php if ($luogo_s !== ''): ?><span><i class="fa fa-location-dot me-1" aria-hidden="true"></i><?php echo htmlspecialchars($luogo_s); ?><?php if ($url_maps): ?> · <a href="<?php echo htmlspecialchars($url_maps); ?>" target="_blank" rel="noopener" class="text-decoration-underline">Apri in Google Maps<span class="visually-hidden"> (nuova scheda)</span></a><?php endif; ?></span><?php endif; ?>
                <?php $corso_s = html_corso_pubblico($conn, $dett_s); if ($corso_s !== ''): ?><span><i class="fa fa-building-columns me-1" aria-hidden="true"></i><?php echo $corso_s; ?></span><?php endif; ?>
                <?php $ins_s = !empty($dett_s['insegnamento_id']) ? insegnamento($conn, (int)$dett_s['insegnamento_id']) : null;
                if ($ins_s): $info_ins = array_filter([!empty($ins_s['anno_corso']) ? (int)$ins_s['anno_corso'] . '° anno' : '', $ins_s['semestre'], $ins_s['docente'] !== '' ? 'Docente ' . $ins_s['docente'] : '']); ?>
                    <span><i class="fa fa-book-open me-1" aria-hidden="true"></i>Insegnamento: <?php echo htmlspecialchars($ins_s['nome'] . ($info_ins ? ' · ' . implode(' · ', $info_ins) : '')); ?></span>
                <?php endif; ?>
            </div>
        </header>

        <?php if ($req_s && $turni_s): ?>
            <dl class="ev-fatti mb-4">
                <div class="ev-fatto"><span class="ev-fatto-ico" aria-hidden="true"><i class="fa fa-clock"></i></span><div><dt><?php echo count($turni_s) === 1 ? 'Turno' : 'Turni'; ?></dt><dd><?php echo count($turni_s); ?></dd></div></div>
                <div class="ev-fatto"><span class="ev-fatto-ico" aria-hidden="true"><i class="fa fa-users"></i></span><div><dt>Posti liberi</dt><dd><?php echo $illimitati_s ? 'Senza limite' : ($liberi_s > 0 ? $liberi_s : 'Esauriti'); ?></dd></div></div>
                <?php $fin_s = finestra_prenotazione($ev_s['turni']); if ($fin_s): ?><div class="ev-fatto"><span class="ev-fatto-ico" aria-hidden="true"><i class="fa <?php echo $fin_s['icona']; ?>"></i></span><div><dt>Prenotazioni</dt><dd><?php echo htmlspecialchars(ucfirst(preg_replace('/^Prenota(zioni)? /', '', $fin_s['testo']))); ?></dd></div></div><?php endif; ?>
                <?php if ($luogo_s !== ''): ?><div class="ev-fatto"><span class="ev-fatto-ico" aria-hidden="true"><i class="fa fa-map-location-dot"></i></span><div><dt>Luogo</dt><dd><?php echo htmlspecialchars($luogo_s); ?></dd></div></div><?php endif; ?>
                <?php if ((int)($ev_s['abilita_presenze'] ?? 1) === 1): ?><div class="ev-fatto"><span class="ev-fatto-ico" aria-hidden="true"><i class="fa fa-qrcode"></i></span><div><dt>Presenza</dt><dd>Check-in con QR</dd></div></div><?php endif; ?>
            </dl>
        <?php endif; ?>

        <div class="row g-4 align-items-start">
            <div class="col-lg-7">
                <?php if (!empty($ev_s['locandina_path']) && preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $ev_s['locandina_path'])): ?>
                    <img src="<?php echo htmlspecialchars($ev_s['locandina_path']); ?>" alt="" class="w-100 mb-4 shadow-sm" style="border-radius: 14px; max-height: 420px; object-fit: cover;">
                <?php endif; ?>
                <article class="ev-box p-4 mb-4">
                    <h2><i class="fa fa-book-open me-1" aria-hidden="true"></i>L'evento</h2>
                    <div class="ev-desc"><?php echo !empty($ev_s['descrizione']) ? $ev_s['descrizione'] : '<p class="text-muted">Descrizione in arrivo.</p>'; ?></div>
                    <?php if (!empty($ev_s['allegato_pdf'])): ?>
                        <a href="<?php echo htmlspecialchars($ev_s['allegato_pdf']); ?>" target="_blank" rel="noopener" class="btn btn-outline-danger fw-bold mt-3"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>Programma (PDF)</a>
                    <?php endif; ?>
                </article>
            </div>

            <aside class="col-lg-5">
                <div class="ev-side d-flex flex-column gap-4">
                    <?php if ($turni_s): ?>
                    <section class="ev-box p-4" id="turni" style="border-top: 4px solid <?php echo $col_primaria; ?>;">
                        <h2><i class="fa fa-calendar-check me-1" aria-hidden="true"></i><?php echo $req_s ? (count($turni_s) > 1 ? 'Turni e prenotazione' : 'Prenotazione') : (count($turni_s) > 1 ? 'Date e orari' : 'Data e orario'); ?></h2>
                        <div class="d-flex flex-column gap-2">
                            <?php foreach ($turni_s as $rs): $t = $rs['t'];
                                $tf = $t + ['richiede_prenotazione' => $ev_s['richiede_prenotazione'], 'ruolo_accesso_id' => $ev_s['ruolo_accesso_id']];
                                $mio_t = $mie_s[(int)$t['id']] ?? null;
                                $pct = $rs['max'] > 0 && $rs['max'] < 9000 ? min(100, (int)round($rs['occ'] / $rs['max'] * 100)) : 0;
                            ?>
                                <div class="ev-turno<?php echo $rs['concluso'] ? ' concluso' : ''; ?>">
                                    <div class="fw-bold">
                                        <?php echo !empty($t['data_turno']) ? formattaDataItaliano($t['data_turno']) : '<i class="fa fa-infinity me-1" aria-hidden="true"></i>Data da definire'; ?>
                                    </div>
                                    <div class="small text-secondary d-flex flex-wrap gap-2">
                                        <?php if (!empty($t['nome_turno'])): ?><span><i class="fa fa-tag me-1" aria-hidden="true"></i><?php echo htmlspecialchars($t['nome_turno']); ?></span><?php endif; ?>
                                        <?php if (orario_turno($t) !== ''): ?><span><i class="fa fa-clock me-1" aria-hidden="true"></i><?php echo orario_turno($t); ?></span><?php endif; ?>
                                    </div>
                                    <?php $fin_t = $req_s ? finestra_prenotazione([$t]) : null; if ($fin_t): ?>
                                        <div class="mt-2"><span class="badge" style="font-size:.78rem; background:<?php echo $fin_t['bg']; ?>; color:<?php echo $fin_t['fg']; ?>;"><i class="fa <?php echo $fin_t['icona']; ?> me-1" aria-hidden="true"></i><?php echo htmlspecialchars($fin_t['testo']); ?></span></div>
                                    <?php endif; ?>
                                    <?php if ($req_s && !$rs['concluso'] && $rs['max'] > 0 && $rs['max'] < 9000): ?>
                                        <div class="d-flex align-items-center gap-2 mt-2">
                                            <div class="progress flex-grow-1" style="height: 6px;" role="progressbar" aria-label="Posti occupati" aria-valuenow="<?php echo $pct; ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar <?php echo $pct >= 100 ? 'bg-danger' : ($pct >= 75 ? 'bg-warning' : 'bg-success'); ?>" style="width: <?php echo $pct; ?>%;"></div></div>
                                            <span class="small fw-bold text-nowrap <?php echo $rs['liberi'] > 0 ? 'text-success' : 'text-danger'; ?>"><?php echo $rs['liberi'] > 0 ? $rs['liberi'] . ' su ' . $rs['max'] : 'Completo'; ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <div class="d-flex flex-wrap gap-2 align-items-center mt-2">
                                        <?php if ($mio_t === 'in_attesa' || $mio_t === 'richiesta_conferma'): ?>
                                            <a href="area_personale.php" class="btn btn-sm btn-outline-warning text-dark fw-bold"><i class="fa fa-hourglass-half me-1" aria-hidden="true"></i><?php echo $mio_t === 'in_attesa' ? "Sei in lista d'attesa" : 'Posto offerto: conferma'; ?></a>
                                        <?php elseif ($mio_t !== null): ?>
                                            <a href="area_personale.php" class="btn btn-sm btn-success fw-bold"><i class="fa fa-check me-1" aria-hidden="true"></i>Sei iscritto</a>
                                        <?php else: ?>
                                            <?php echo getPulsanteAzione($tf, $col_primaria, $utente_logged, $utente_ruolo_id, $conn, $nomi_ruoli); ?>
                                        <?php endif; ?>
                                        <?php if (!empty($t['data_turno']) && !$rs['concluso']): ?>
                                            <a href="<?php echo htmlspecialchars(getGoogleCalendarUrl($ev_s['titolo'], $t['data_turno'], $t['orario_inizio'], $t['orario_fine'], $luogo_s, $ev_s['titolo'])); ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary" title="Aggiungi a Google Calendar" aria-label="Aggiungi a Google Calendar"><i class="fa-brands fa-google" aria-hidden="true"></i></a>
                                            <a href="genera_ics.php?t_id=<?php echo (int)$t['id']; ?>" class="btn btn-sm btn-outline-secondary" title="Aggiungi al calendario (Outlook, Apple)" aria-label="Scarica il file per il calendario"><i class="fa fa-calendar-plus" aria-hidden="true"></i></a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($req_s && ($ruolo_s !== 0) && !$utente_logged): ?>
                            <p class="small text-secondary mt-3 mb-0">Per prenotare accedi con SPID, CIE o credenziali Unical.</p>
                        <?php endif; ?>
                    </section>
                    <?php endif; ?>

                    <?php if (!empty($dett_s['referenti'])): ?>
                    <section class="ev-box p-4">
                        <h2><i class="fa fa-address-book me-1" aria-hidden="true"></i>Contatti</h2>
                        <?php foreach ($dett_s['referenti'] as $rf) echo html_referente_pubblico($conn, $rf, $col_testo_area); ?>
                    </section>
                    <?php endif; ?>
                </div>
            </aside>
        </div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var btn = document.getElementById('evCopiaLink');
        if (!btn) return;
        btn.addEventListener('click', function () {
            var url = location.origin + location.pathname + '?evento=<?php echo (int)$ev_s['id']; ?>';
            var fatto = function () { btn.querySelector('span').textContent = 'Link copiato!'; setTimeout(function () { btn.querySelector('span').textContent = 'Copia il link'; }, 2000); };
            if (navigator.clipboard) navigator.clipboard.writeText(url).then(fatto, function () { prompt('Copia il link:', url); });
            else prompt('Copia il link:', url);
        });
    });
    </script>

<!-- ======================================================= -->
<!-- LAYOUT 1: CALENDARIO INTERATTIVO A TUTTO SCHERMO -->
<!-- ======================================================= -->
<?php elseif ($layout_template === 'calendar'): ?>
    <div class="container mb-5 mt-4" style="max-width: <?php echo htmlspecialchars($page_cfg['larghezza_contenitore'] ?? '85%'); ?>;">
        <?php echo $banner_manutenzione_admin; ?>
        <?php echo $messaggio_prenotazione; ?>
        
        <div class="card shadow-lg border-0 rounded-4 overflow-hidden mt-3">
            <div class="card-header p-4" style="background-color: <?php echo $col_primaria; ?>; color: <?php echo colore_testo_su($col_primaria); ?>;">
                <h2 class="fw-bold m-0"><i class="fa fa-calendar-days me-2" aria-hidden="true"></i> <?php echo htmlspecialchars(!empty($page_cfg['titolo']) ? $page_cfg['titolo'] : 'Calendario Eventi'); ?></h2>
                <p class="m-0 mt-1 opacity-75">Clicca su un evento nel calendario per visualizzare i dettagli e prenotarti.</p>
            </div>
            <div class="card-body p-4 bg-white">
                <div id="fullCalendarDiv"></div>
            </div>
        </div>

        <?php
        // I turni senza data non possono stare nel calendario: elencati qui sotto, prenotabili come gli altri
        $turni_senza_data = array_values(array_filter($all_turni_flat, fn($t) => empty($t['data_turno'])));
        ?>
        <?php if ($turni_senza_data): ?>
        <div class="card shadow-sm border-0 rounded-4 mt-4">
            <div class="card-body p-4">
                <h2 class="fw-bold fs-4 mb-1" style="color: <?php echo $col_testo_area; ?>;"><i class="fa fa-infinity me-2" aria-hidden="true"></i>Attività senza data fissa</h2>
                <p class="text-secondary small mb-3">Non compaiono nel calendario perché non hanno un giorno stabilito.</p>
                <div class="list-group list-group-flush">
                    <?php foreach ($turni_senza_data as $t_nd): ?>
                        <div class="list-group-item px-0 d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <div>
                                <div class="fw-bold"><a href="<?php echo htmlspecialchars($current_filename); ?>.php?evento=<?php echo (int)$t_nd['evento_id']; ?>" class="text-dark"><?php echo htmlspecialchars($t_nd['evento_titolo']); ?></a></div>
                                <div class="small text-secondary">
                                    <?php echo htmlspecialchars(etichetta_turno($t_nd)); ?>
                                    <?php if (!empty($t_nd['evento_luogo'])): ?> · <i class="fa fa-map-marker-alt" aria-hidden="true"></i> <?php echo htmlspecialchars($t_nd['evento_luogo']); ?><?php endif; ?>
                                </div>
                            </div>
                            <div><?php echo getPulsanteAzione($t_nd, $col_primaria, $utente_logged, $utente_ruolo_id, $conn, $nomi_ruoli); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php
    // Il calendario si apre sul mese del primo turno ancora da svolgere (non sempre sul mese corrente)
    $data_iniziale_cal = null;
    foreach ($all_turni_flat as $t_c) {
        if (!empty($t_c['data_turno']) && !turno_concluso($t_c)) { $data_iniziale_cal = $t_c['data_turno']; break; }
    }
    ?>
    <script src="<?php echo url_vendor('jsdelivr/npm/fullcalendar@6.1.11/index.global.min.js'); ?>" integrity="sha384-5JIwZN3kuxX2zKsavvNmbZ3zhZZMUtu/eQiK3BbXukpSXp0Cd2ZP4OAYKx7mrPgI" crossorigin="anonymous"></script>
    <script>
      document.addEventListener('DOMContentLoaded', function() {
        var calendarEl = document.getElementById('fullCalendarDiv');
        var calendar = new FullCalendar.Calendar(calendarEl, {
          initialView: 'dayGridMonth', locale: 'it',
          <?php if ($data_iniziale_cal): ?>initialDate: <?php echo json_encode($data_iniziale_cal); ?>,<?php endif; ?>
          headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,listWeek' },
          events: <?php echo json_encode($json_events_calendar, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
          eventClick: function(info) {
              info.jsEvent.preventDefault();
              var myModal = new bootstrap.Modal(document.getElementById('modPrenota' + info.event.extendedProps.turno_id));
              myModal.show();
          },
          eventContent: function(arg) {
            // Costruito con nodi DOM (textContent): titolo e luogo non vengono mai interpretati come HTML
            var box = document.createElement('div');
            box.className = 'p-1';
            box.style.cssText = 'white-space:normal; font-size:0.8rem; font-weight:bold; overflow:hidden;';
            box.appendChild(document.createTextNode(arg.event.title));
            if (arg.event.extendedProps.luogo) {
              box.appendChild(document.createElement('br'));
              var s = document.createElement('small'); s.textContent = '📍 ' + arg.event.extendedProps.luogo; box.appendChild(s);
            }
            return { domNodes: [box] };
          }
        });
        calendar.render();
      });
    </script>

<!-- ======================================================= -->
<!-- LAYOUT 2: ELENCO AVANZATO CON RICERCA -->
<!-- ======================================================= -->
<?php elseif ($layout_template === 'advanced_list'): ?>
    <div class="container mb-5" style="max-width: <?php echo htmlspecialchars($page_cfg['larghezza_contenitore'] ?? '85%'); ?>;">
        
        <!-- HERO / TESTATA EVENTO -->
        <div class="card shadow-sm border-0 mb-4 p-4 text-center" style="background-color: #fdfbfb; border-bottom: 5px solid <?php echo $col_primaria; ?> !important; border-radius: 12px;">
            <h1 class="fw-black display-5 m-0 mb-2" style="color: <?php echo $col_primaria; ?>; font-weight: 900; letter-spacing: -1px;">
                <i class="fa fa-calendar-check me-2"></i> <?php echo htmlspecialchars(!empty($page_cfg['titolo']) ? $page_cfg['titolo'] : 'EVENTI'); ?>
            </h1>
            <div class="row justify-content-center border-top border-bottom py-3 my-3">
                <div class="col-md-3 border-end">
                    <span class="text-muted small fw-bold text-uppercase d-block">Da</span>
<?php $date_con_valore = array_values(array_filter(array_column($all_turni_flat, 'data_turno'))); ?>
                    <strong class="fs-5"><?php echo $date_con_valore ? date('d/m/Y', strtotime(min($date_con_valore))) : '-'; ?></strong>
                </div>
                <div class="col-md-3 border-end">
                    <span class="text-muted small fw-bold text-uppercase d-block">A</span>
                    <strong class="fs-5"><?php echo $date_con_valore ? date('d/m/Y', strtotime(max($date_con_valore))) : '-'; ?></strong>
                </div>
                <div class="col-md-3">
                    <span class="text-muted small fw-bold text-uppercase d-block">Accesso</span>
                    <strong class="fs-5 text-success">Prenotazione Libera</strong>
                </div>
            </div>
            <p class="text-secondary mb-0 mx-auto" style="max-width: 800px; line-height: 1.6;">
                <?php echo !empty($page_cfg['hero_descrizione']) ? $page_cfg['hero_descrizione'] : 'Scopri il programma completo ed iscriviti alle attività di tuo interesse.'; ?>
            </p>
        </div>

        <div class="row g-4 align-items-start">
            <!-- SIDEBAR RICERCA E DOCUMENTI (LEFT) -->
            <div class="col-lg-3">
                <div class="card shadow-sm border-0 rounded-3 sidebar-sticky-fix">
                    <div class="card-header text-white fw-bold py-3" style="background-color: #1e293b;">
                        <i class="fa fa-search me-1"></i> Ricerca
                    </div>
                    <div class="card-body p-4 bg-white">
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-secondary"><i class="fa fa-font me-1"></i> Ricerca testo</label>
                            <input type="text" id="advSearchText" class="form-control form-control-sm" placeholder="Cerca...">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-secondary"><i class="fa fa-folder-open me-1"></i> Sezione / Categoria</label>
                            <select id="advSearchCat" class="form-select form-select-sm">
                                <option value="">Tutte le sezioni</option>
                                <?php foreach(array_unique($categorie_nomi) as $cat_n): ?>
                                    <?php if(!empty($cat_n)): ?><option value="<?php echo htmlspecialchars(strtolower($cat_n)); ?>"><?php echo htmlspecialchars($cat_n); ?></option><?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-secondary"><i class="fa fa-calendar-day me-1"></i> Da data</label>
                            <input type="date" id="advSearchDateFrom" class="form-control form-control-sm">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-secondary"><i class="fa fa-calendar-day me-1"></i> A data</label>
                            <input type="date" id="advSearchDateTo" class="form-control form-control-sm">
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="advSearchSenzaData" checked>
                            <label class="form-check-label small fw-bold text-secondary" for="advSearchSenzaData">Includi attività senza data</label>
                        </div>
                        <div class="mb-4">
                            <label class="form-label small fw-bold text-secondary"><i class="fa fa-users-slash me-1"></i> Mostra Soldout</label>
                            <select id="advSearchSoldout" class="form-select form-select-sm">
                                <option value="1">Includi soldout (Tutti)</option>
                                <option value="0">Nascondi soldout (Solo posti liberi)</option>
                            </select>
                        </div>
                        
                        <!-- DOCUMENTI SIDEBAR SPOSTATI IN BASSO -->
                        <?php if (!empty($page_cfg['allegati_sidebar'])): ?>
                            <div class="d-flex flex-column gap-2 mt-3 pt-3 border-top">
                                <?php 
                                    $allegati_sb = explode(',', $page_cfg['allegati_sidebar']);
                                    foreach($allegati_sb as $asb): 
                                        $asb = trim($asb);
                                        if(empty($asb)) continue;
                                ?>
                                    <a href="<?php echo htmlspecialchars($asb); ?>" target="_blank" class="btn btn-outline-danger fw-bold shadow-sm py-2">
                                        <i class="fa fa-file-pdf me-1"></i> Scarica Guida
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- RISULTATI LISTA (RIGHT) -->
            <div class="col-lg-9">
                <div id="advEventsContainer" class="d-flex flex-column gap-3">
                    <?php if (empty($all_turni_flat)): ?>
                        <div class="alert alert-light text-center border p-5 shadow-sm">Nessun evento in programma.</div>
                    <?php else: ?>
                        <?php foreach($all_turni_flat as $t_flat): ?>
                            <?php 
                                $occ = getPostiOccupati($conn, $t_flat['id']);
                                $disponibili = max(0, $t_flat['max_posti'] - $occ);
                                $is_soldout_flag = ($disponibili <= 0) ? '1' : '0';
                                
                                $data_text = strtolower($t_flat['evento_titolo'] . ' ' . $t_flat['evento_luogo'] . ' ' . $t_flat['categoria'] . ' ' . ($t_flat['nome_turno'] ?? ''));
                                $data_cat  = strtolower($t_flat['categoria']);
                                $data_date = (string)($t_flat['data_turno'] ?? ''); // vuoto = turno senza data
                            ?>
                            <div class="card shadow-sm border-0 adv-event-item" style="border-radius: 12px; overflow: hidden;" data-text="<?php echo htmlspecialchars($data_text); ?>" data-cat="<?php echo htmlspecialchars($data_cat); ?>" data-date="<?php echo $data_date; ?>" data-soldout="<?php echo $is_soldout_flag; ?>">
                                <div class="row g-0">
                                    <?php if (!empty($t_flat['evento_locandina']) && preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $t_flat['evento_locandina'])): ?>
                                    <div class="col-md-2 position-relative" style="min-height: 150px;">
                                        <img src="<?php echo htmlspecialchars($t_flat['evento_locandina']); ?>" alt="Locandina" style="position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover;object-position:center;">
                                    </div>
                                    <?php else: ?>
                                    <div class="col-md-2 d-flex flex-column align-items-center justify-content-center text-white" style="background-color:<?php echo $col_primaria; ?>;min-height:150px;">
                                        <i class="fa fa-calendar-star fs-2 opacity-75 mb-1"></i>
                                        <span class="small fw-bold text-uppercase text-center px-2 lh-sm" style="letter-spacing:0.5px;font-size:0.7rem!important;"><?php echo htmlspecialchars($t_flat['categoria']); ?></span>
                                    </div>
                                    <?php endif; ?>

                                    <div class="col-md-10 p-4 d-flex flex-column justify-content-between bg-white">
                                        <div>
                                            <div class="small fw-bold mb-1" style="color: #64748b;"><i class="fa fa-folder-open me-1"></i> <?php echo htmlspecialchars($t_flat['categoria']); ?></div>
                                            <h2 class="fw-bold fs-4 mb-2"><a href="<?php echo htmlspecialchars($current_filename); ?>.php?evento=<?php echo (int)$t_flat['evento_id']; ?>" class="text-decoration-none" style="color: <?php echo $col_primaria; ?>;"><?php echo htmlspecialchars($t_flat['evento_titolo']); ?></a></h2>
                                            
                                            <!-- --- AGGIORNAMENTO UI: BOX LUOGO INGRANDITO (LISTA AVANZATA) --- -->
                                            <?php if(!empty($t_flat['evento_luogo'])): ?>
                                            <div class="bg-light p-2 rounded mb-3 text-dark border-start border-3 border-danger shadow-sm" style="font-size: 1rem;">
                                                <i class="fa fa-map-marker-alt text-danger me-1"></i> <strong class="text-primary">Luogo:</strong> <?php echo htmlspecialchars($t_flat['evento_luogo']); ?>
                                            </div>
                                            <?php endif; ?>

                                            <div class="d-flex flex-wrap gap-3 mb-3 text-secondary small fw-semibold">
                                                <?php if (!empty($t_flat['nome_turno'])): ?><span><i class="fa fa-tag text-secondary me-1"></i> <?php echo htmlspecialchars($t_flat['nome_turno']); ?></span><?php endif; ?>
                                                <?php if (!empty($t_flat['data_turno'])): ?><span><i class="fa fa-calendar-alt text-danger me-1"></i> <?php echo date('d/m/Y', strtotime($t_flat['data_turno'])); ?></span><?php endif; ?>
                                                <?php if (orario_turno($t_flat) !== ''): ?><span><i class="fa fa-clock text-primary me-1"></i> <?php echo orario_turno($t_flat); ?></span><?php endif; ?>
                                            </div>
                                        </div>

                                        <div class="d-flex justify-content-between align-items-center mt-auto border-top pt-3">
                                            <span class="badge bg-light text-dark border fs-6 px-3 py-2">
                                                <i class="fa fa-users text-secondary me-1"></i> Posti occupati: <strong><?php echo $occ; ?>/<?php echo $t_flat['max_posti']; ?></strong>
                                            </span>
                                            <div><?php echo getPulsanteAzione($t_flat, $col_primaria, $utente_logged, $utente_ruolo_id, $conn, $nomi_ruoli); ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Script Ricerca Avanzata -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('advSearchText');
            const catSelect = document.getElementById('advSearchCat');
            const dateFrom = document.getElementById('advSearchDateFrom');
            const dateTo = document.getElementById('advSearchDateTo');
            const soldoutSelect = document.getElementById('advSearchSoldout');
            const senzaData = document.getElementById('advSearchSenzaData');
            
            function filterEvents() {
                const textVal = searchInput.value.toLowerCase();
                const catVal = catSelect.value.toLowerCase();
                const fromVal = dateFrom.value;
                const toVal = dateTo.value;
                const soldoutVal = soldoutSelect.value;
                
                document.querySelectorAll('.adv-event-item').forEach(el => {
                    let show = true;
                    if (textVal && !el.dataset.text.includes(textVal)) show = false;
                    if (catVal && el.dataset.cat !== catVal) show = false;
                    // Turni senza data: esclusi dai filtri per data, mostrati se la casella è spuntata
                    if (!el.dataset.date) { if ((fromVal || toVal) && !senzaData.checked) show = false; }
                    else {
                        if (fromVal && el.dataset.date < fromVal) show = false;
                        if (toVal && el.dataset.date > toVal) show = false;
                    }
                    if (soldoutVal === '0' && el.dataset.soldout === '1') show = false;
                    
                    if(show) { el.classList.remove('d-none'); } else { el.classList.add('d-none'); }
                });
            }

            if(searchInput) {
                searchInput.addEventListener('input', filterEvents);
                catSelect.addEventListener('change', filterEvents);
                dateFrom.addEventListener('change', filterEvents);
                dateTo.addEventListener('change', filterEvents);
                soldoutSelect.addEventListener('change', filterEvents);
                senzaData.addEventListener('change', filterEvents);
            }
        });
    </script>


<!-- ======================================================= -->
<!-- LAYOUT 5: TIMELINE VERTICALE -->
<!-- ======================================================= -->
<?php elseif ($layout_template === 'timeline'): ?>
    <div class="container mb-5" style="max-width: <?php echo htmlspecialchars($page_cfg['larghezza_contenitore'] ?? '85%'); ?>;">
        
        <!-- Hero Testata Timeline -->
        <div class="text-center mb-5 mt-4">
            <h1 class="fw-black display-5 m-0 my-2" style="color: <?php echo $col_primaria; ?>; font-weight: 900; letter-spacing: -1px;">
                <?php echo htmlspecialchars(!empty($page_cfg['titolo']) ? $page_cfg['titolo'] : 'PROGRAMMA EVENTI'); ?>
            </h1>
            <p class="text-secondary fs-5 mx-auto" style="max-width: 700px;">
                <?php echo !empty($page_cfg['hero_descrizione']) ? $page_cfg['hero_descrizione'] : 'Segui il programma cronologico delle attività.'; ?>
            </p>
        </div>

        <div class="timeline-container mx-auto" style="max-width: 900px; position: relative; padding-left: 3rem; border-left: 4px solid <?php echo $col_primaria; ?>;">
            <?php if (empty($eventi_per_data)): ?>
                <div class="alert alert-light text-center border p-4 shadow-sm">Nessun evento in programma.</div>
            <?php else: ?>
                <?php foreach ($eventi_per_data as $data_giorno => $lista_ev_giorno): ?>
                    <div class="timeline-group mb-5 position-relative">
                        <!-- Nodo Data -->
                        <div class="timeline-badge shadow-sm" style="position: absolute; left: -4.3rem; top: 0; width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; background-color: <?php echo $col_primaria; ?>; border: 4px solid #fff; z-index: 2;">
                            <i class="fa <?php echo $data_giorno === GIORNO_SENZA_DATA ? 'fa-infinity' : 'fa-calendar-day'; ?> fs-5" aria-hidden="true"></i>
                        </div>
                        
                        <h2 class="fw-bold fs-3 mb-4 ms-2 text-dark pt-2" style="color: <?php echo $col_primaria; ?> !important;">
                            <?php echo $titolo_giorno($data_giorno); ?>
                        </h2>
                        
                        <div class="row g-4 ms-1">
                            <?php foreach ($lista_ev_giorno as $ev): ?>
                                <div class="col-12 position-relative">
                                    <!-- Linea orizzontale di collegamento -->
                                    <div style="position: absolute; left: -2rem; top: 2rem; width: 2rem; height: 3px; background-color: <?php echo $col_primaria; ?>; opacity: 0.3; z-index: 1;"></div>
                                    <?php renderCardUniversal($ev, $col_primaria, $utente_logged, $utente_ruolo_id, $nomi_ruoli, $etichette_riservato, $val_nome, $val_cognome, $val_email, $val_matricola, $conn, $chiedi_matricola); ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>


<!-- ======================================================= -->
<!-- LAYOUT 6: GRUPPI / CORSI (discipline -> gruppi -> turni) -->
<!-- ======================================================= -->
<?php elseif ($layout_template === 'agenda'): ?>
    <?php
    // AGENDA A SCHEDE PER GIORNO: una scheda per ogni giorno (+ "Senza data"), turni in ordine di orario
    $agenda = [];
    foreach ($all_turni_flat as $t_ag) {
        $agenda[!empty($t_ag['data_turno']) ? $t_ag['data_turno'] : GIORNO_SENZA_DATA][] = $t_ag;
    }
    ksort($agenda);
    // Giorno aperto all'inizio: oggi se c'è, altrimenti il primo con turni non conclusi, altrimenti il primo
    $giorno_attivo = isset($agenda[date('Y-m-d')]) ? date('Y-m-d') : null;
    if ($giorno_attivo === null) {
        foreach ($agenda as $g => $lista_g) {
            foreach ($lista_g as $t_g) { if (!turno_concluso($t_g)) { $giorno_attivo = $g; break 2; } }
        }
    }
    if ($giorno_attivo === null && $agenda) $giorno_attivo = array_key_first($agenda);
    $giorni_brevi = ['Dom', 'Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab'];
    $mesi_ag      = ['', 'gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];
    $txt_on_area  = colore_testo_su($col_primaria);
    ?>
    <style>
        .ag-giorni { display: flex; gap: .5rem; overflow-x: auto; padding-bottom: .5rem; scrollbar-width: thin; }
        .ag-giorno { flex: 0 0 auto; min-width: 84px; border: 2px solid #e2e8f0; background: #fff; border-radius: 12px; padding: .5rem .75rem; text-align: center; line-height: 1.15; cursor: pointer; }
        .ag-giorno .ag-gs { font-size: .72rem; text-transform: uppercase; font-weight: 700; color: #64748b; }
        .ag-giorno .ag-gn { font-size: 1.5rem; font-weight: 800; color: #1f2937; }
        .ag-giorno .ag-gm { font-size: .72rem; font-weight: 600; color: #64748b; }
        .ag-giorno.attivo { background: <?php echo $col_primaria; ?>; border-color: <?php echo $col_primaria; ?>; }
        .ag-giorno.attivo * { color: <?php echo $txt_on_area; ?> !important; }
        .ag-riga { display: grid; grid-template-columns: 92px 1fr auto; gap: 1rem; align-items: center; padding: 1rem 1.25rem; border-bottom: 1px solid #eef2f7; }
        .ag-riga:last-child { border-bottom: 0; }
        .ag-riga.conclusa { opacity: .55; }
        .ag-ora { font-size: 1.35rem; font-weight: 800; color: <?php echo $col_testo_area; ?>; line-height: 1; }
        .ag-ora small { display: block; font-size: .78rem; font-weight: 600; color: #64748b; margin-top: .25rem; }
        @media (max-width: 575.98px) {
            .ag-riga { grid-template-columns: 64px 1fr; }
            .ag-riga .ag-azione { grid-column: 1 / -1; }
            .ag-ora { font-size: 1.1rem; }
        }
    </style>
    <div class="container mb-5" style="max-width: <?php echo htmlspecialchars($page_cfg['larghezza_contenitore'] ?? '85%'); ?>;">
        <div class="text-center mb-4 mt-4">
            <h1 class="fw-black display-5 m-0 my-2" style="color: <?php echo $col_testo_area; ?>; font-weight: 900; letter-spacing: -1px;">
                <?php echo htmlspecialchars(!empty($page_cfg['titolo']) ? $page_cfg['titolo'] : 'AGENDA'); ?>
            </h1>
            <?php if (!empty($page_cfg['hero_descrizione'])): ?>
                <div class="text-secondary fs-5 mx-auto" style="max-width: 760px;"><?php echo $page_cfg['hero_descrizione']; ?></div>
            <?php endif; ?>
        </div>

        <?php if (!empty($page_cfg['box_info_html'])): ?>
            <div class="card shadow-sm border-0 p-4 mb-4" style="border-left: 5px solid <?php echo $col_primaria; ?> !important; border-radius: 8px;">
                <div class="fs-6 text-dark" style="line-height: 1.7;"><?php echo $page_cfg['box_info_html']; ?></div>
            </div>
        <?php endif; ?>

        <?php if (!$agenda): ?>
            <div class="alert alert-light text-center border p-4 shadow-sm">Nessuna attività in programma.</div>
        <?php else: ?>
            <!-- Selettore giorni -->
            <div class="ag-giorni mb-3" role="tablist" aria-label="Giorni del programma">
                <?php foreach ($agenda as $g => $lista_g):
                    $attivo = ($g === $giorno_attivo);
                    $id_g = 'ag-' . preg_replace('/[^0-9]/', '', $g);
                ?>
                    <button type="button" class="ag-giorno<?php echo $attivo ? ' attivo' : ''; ?>" role="tab" id="tab-<?php echo $id_g; ?>"
                            aria-selected="<?php echo $attivo ? 'true' : 'false'; ?>" aria-controls="<?php echo $id_g; ?>" tabindex="<?php echo $attivo ? '0' : '-1'; ?>">
                        <?php if ($g === GIORNO_SENZA_DATA): ?>
                            <div class="ag-gs">Senza</div><div class="ag-gn"><i class="fa fa-infinity" aria-hidden="true"></i></div><div class="ag-gm">data fissa</div>
                        <?php else: $ts_g = strtotime($g); ?>
                            <div class="ag-gs"><?php echo $g === date('Y-m-d') ? 'Oggi' : $giorni_brevi[(int)date('w', $ts_g)]; ?></div>
                            <div class="ag-gn"><?php echo date('j', $ts_g); ?></div>
                            <div class="ag-gm"><?php echo $mesi_ag[(int)date('n', $ts_g)] . (date('Y', $ts_g) !== date('Y') ? ' ' . date('Y', $ts_g) : ''); ?></div>
                        <?php endif; ?>
                        <span class="visually-hidden"><?php echo count($lista_g); ?> attività</span>
                    </button>
                <?php endforeach; ?>
            </div>

            <!-- Filtri -->
            <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                <label for="agCerca" class="visually-hidden">Cerca attività</label>
                <input type="search" id="agCerca" class="form-control form-control-sm" style="max-width: 280px;" placeholder="Cerca attività, luogo...">
                <div class="form-check form-switch mb-0 ms-md-auto">
                    <input class="form-check-input" type="checkbox" id="agSoloLiberi">
                    <label class="form-check-label small fw-bold" for="agSoloLiberi">Solo con posti liberi</label>
                </div>
            </div>

            <!-- Pannelli dei giorni -->
            <?php foreach ($agenda as $g => $lista_g):
                $id_g = 'ag-' . preg_replace('/[^0-9]/', '', $g);
                usort($lista_g, fn($a, $b) => strcmp(($a['orario_inizio'] ?? '99') . $a['evento_titolo'], ($b['orario_inizio'] ?? '99') . $b['evento_titolo']));
            ?>
                <div class="card shadow-sm border-0 rounded-4 overflow-hidden" role="tabpanel" id="<?php echo $id_g; ?>" aria-labelledby="tab-<?php echo $id_g; ?>" <?php echo $g === $giorno_attivo ? '' : 'hidden'; ?>>
                    <div class="px-4 py-3 border-bottom bg-light">
                        <h2 class="fw-bold fs-5 m-0"><?php echo $titolo_giorno($g); ?></h2>
                    </div>
                    <?php foreach ($lista_g as $t_ag):
                        $conclusa  = turno_concluso($t_ag);
                        $req_pren  = (int)($t_ag['richiede_prenotazione'] ?? 1) === 1;
                        $occ_ag    = $req_pren ? getPostiOccupati($conn, $t_ag['id']) : 0;
                        $max_ag    = max(0, (int)$t_ag['max_posti']);
                        $lib_ag    = max(0, $max_ag - $occ_ag);
                        $illimitato = $max_ag >= 9000;
                        $pct_ag    = ($max_ag > 0 && !$illimitato) ? min(100, (int)round($occ_ag / $max_ag * 100)) : 0;
                        $mio_stato = $mie_iscrizioni[(int)$t_ag['evento_id']][(int)$t_ag['id']] ?? null;
                        $cerca_ag  = mb_strtolower($t_ag['evento_titolo'] . ' ' . ($t_ag['nome_turno'] ?? '') . ' ' . ($t_ag['evento_luogo'] ?? '') . ' ' . $t_ag['categoria']);
                    ?>
                        <div class="ag-riga<?php echo $conclusa ? ' conclusa' : ''; ?>" data-cerca="<?php echo htmlspecialchars($cerca_ag); ?>" data-liberi="<?php echo (!$req_pren || $illimitato) ? 1 : $lib_ag; ?>">
                            <div class="ag-ora">
                                <?php if (!empty($t_ag['orario_inizio'])): ?>
                                    <?php echo substr($t_ag['orario_inizio'], 0, 5); ?>
                                    <?php if (!empty($t_ag['orario_fine'])): ?><small>fino alle <?php echo substr($t_ag['orario_fine'], 0, 5); ?></small><?php endif; ?>
                                <?php else: ?>
                                    <i class="fa fa-clock" aria-hidden="true"></i><small>orario da definire</small>
                                <?php endif; ?>
                            </div>
                            <div style="min-width: 0;">
                                <div class="d-flex flex-wrap gap-1 mb-1">
                                    <span class="badge" style="background: #f1f5f9; color: #334155; font-size: .68rem;"><?php echo htmlspecialchars($t_ag['categoria']); ?></span>
                                    <?php if ($mio_stato === 'in_attesa' || $mio_stato === 'richiesta_conferma'): ?>
                                        <span class="badge bg-warning text-dark" style="font-size: .68rem;"><i class="fa fa-hourglass-half me-1" aria-hidden="true"></i>In lista d'attesa</span>
                                    <?php elseif ($mio_stato !== null): ?>
                                        <span class="badge bg-success" style="font-size: .68rem;"><i class="fa fa-check me-1" aria-hidden="true"></i>Sei iscritto</span>
                                    <?php endif; ?>
                                </div>
                                <h3 class="fw-bold fs-6 m-0"><a href="<?php echo htmlspecialchars($current_filename); ?>.php?evento=<?php echo (int)$t_ag['evento_id']; ?>" class="text-dark"><?php echo htmlspecialchars($t_ag['evento_titolo']); ?></a><?php if (!empty($t_ag['nome_turno'])): ?> <span class="fw-semibold text-secondary">· <?php echo htmlspecialchars($t_ag['nome_turno']); ?></span><?php endif; ?></h3>
                                <?php if (!empty($t_ag['evento_luogo'])): ?>
                                    <div class="small text-secondary mt-1"><i class="fa fa-map-marker-alt me-1" aria-hidden="true"></i><?php echo htmlspecialchars($t_ag['evento_luogo']); ?></div>
                                <?php endif; ?>
                                <?php if ($req_pren && !$illimitato && $max_ag > 0 && !$conclusa): ?>
                                    <div class="d-flex align-items-center gap-2 mt-2" style="max-width: 320px;">
                                        <div class="progress flex-grow-1" style="height: 6px;" role="progressbar" aria-label="Posti occupati: <?php echo $pct_ag; ?>%" aria-valuenow="<?php echo $pct_ag; ?>" aria-valuemin="0" aria-valuemax="100">
                                            <div class="progress-bar <?php echo $pct_ag >= 100 ? 'bg-danger' : ($pct_ag >= 75 ? 'bg-warning' : 'bg-success'); ?>" style="width: <?php echo $pct_ag; ?>%;"></div>
                                        </div>
                                        <span class="small fw-bold text-nowrap <?php echo $lib_ag > 0 ? 'text-success' : 'text-danger'; ?>"><?php echo $lib_ag > 0 ? $lib_ag . ($lib_ag === 1 ? ' posto' : ' posti') : 'Completo'; ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="ag-azione text-end">
                                <?php if ($mio_stato !== null && !in_array($mio_stato, ['in_attesa', 'richiesta_conferma'], true)): ?>
                                    <a href="area_personale.php" class="btn btn-sm btn-outline-success fw-bold">La mia prenotazione</a>
                                <?php else: ?>
                                    <?php echo getPulsanteAzione($t_ag, $col_primaria, $utente_logged, $utente_ruolo_id, $conn, $nomi_ruoli); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <div class="ag-vuoto p-4 text-center text-muted d-none">Nessuna attività corrisponde ai filtri in questo giorno.</div>
                </div>
            <?php endforeach; ?>

            <script>
            document.addEventListener('DOMContentLoaded', function () {
                var tabs = Array.prototype.slice.call(document.querySelectorAll('.ag-giorno'));
                function apri(tab) {
                    tabs.forEach(function (t) {
                        var sel = t === tab;
                        t.classList.toggle('attivo', sel);
                        t.setAttribute('aria-selected', sel ? 'true' : 'false');
                        t.tabIndex = sel ? 0 : -1;
                        document.getElementById(t.getAttribute('aria-controls')).hidden = !sel;
                    });
                    filtra();
                }
                tabs.forEach(function (t, i) {
                    t.addEventListener('click', function () { apri(t); });
                    // Tastiera: frecce sinistra/destra tra i giorni (pattern ARIA delle schede)
                    t.addEventListener('keydown', function (e) {
                        var j = e.key === 'ArrowRight' ? i + 1 : e.key === 'ArrowLeft' ? i - 1 : null;
                        if (j === null || !tabs[j]) return;
                        e.preventDefault(); tabs[j].focus(); apri(tabs[j]);
                    });
                });
                var attiva = document.querySelector('.ag-giorno.attivo');
                if (attiva) attiva.scrollIntoView({ block: 'nearest', inline: 'center' });

                var cerca = document.getElementById('agCerca'), liberi = document.getElementById('agSoloLiberi');
                function filtra() {
                    var q = cerca.value.trim().toLowerCase(), soloLiberi = liberi.checked;
                    document.querySelectorAll('[role="tabpanel"]').forEach(function (p) {
                        var visibili = 0;
                        p.querySelectorAll('.ag-riga').forEach(function (r) {
                            var ok = (!q || r.dataset.cerca.indexOf(q) !== -1) && (!soloLiberi || parseInt(r.dataset.liberi, 10) > 0);
                            r.classList.toggle('d-none', !ok);
                            if (ok) visibili++;
                        });
                        p.querySelector('.ag-vuoto').classList.toggle('d-none', visibili > 0);
                    });
                }
                cerca.addEventListener('input', filtra);
                liberi.addEventListener('change', filtra);
            });
            </script>
        <?php endif; ?>
    </div>

<?php elseif ($layout_template === 'gruppi'): ?>
    <style>
        .gruppo-card { border-top: 4px solid <?php echo $col_primaria; ?> !important; border-radius: 12px; transition: box-shadow .2s ease; }
        .gruppo-card:hover { box-shadow: 0 8px 22px rgba(0,0,0,.09) !important; }
        .gruppo-card.gruppo-iscritto { outline: 2px solid #198754; }
        .gruppo-desc { font-size: .9rem; line-height: 1.5; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
        .gruppo-desc.aperta { -webkit-line-clamp: unset; display: block; }
        .gruppi-pill { border-radius: 999px; font-weight: 600; font-size: .85rem; }
        .gruppi-pill.active { background-color: <?php echo $col_primaria; ?> !important; border-color: <?php echo $col_primaria; ?> !important; color: #fff !important; }
    </style>
    <div class="container mb-5" style="max-width: <?php echo htmlspecialchars($page_cfg['larghezza_contenitore'] ?? '85%'); ?>;">

        <div class="card shadow-sm border-0 p-4 mb-4" style="border-left: 5px solid <?php echo $col_primaria; ?> !important; border-radius: 12px;">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                <div>
                    <h1 class="fw-bold m-0" style="color: <?php echo $col_primaria; ?>; letter-spacing: -0.5px;"><?php echo htmlspecialchars(!empty($page_cfg['titolo']) ? $page_cfg['titolo'] : 'GRUPPI'); ?></h1>
                    <?php if (!empty($page_cfg['sottotitolo'])): ?><div class="fs-5 fw-semibold text-secondary"><?php echo htmlspecialchars($page_cfg['sottotitolo']); ?></div><?php endif; ?>
                </div>
                <?php if (!empty($page_cfg['sidebar_intervallo_date'])): ?>
                    <span class="badge bg-warning text-dark fw-bold px-3 py-2 fs-6 rounded-pill">📝 <?php echo htmlspecialchars($page_cfg['sidebar_intervallo_date']); ?></span>
                <?php endif; ?>
            </div>
            <?php if (!empty($page_cfg['hero_descrizione'])): ?>
                <div class="text-dark mt-3" style="font-size: .95rem; line-height: 1.6;"><?php echo $page_cfg['hero_descrizione']; ?></div>
            <?php endif; ?>
            <?php if ($limite_iscrizioni !== 'nessuno'): ?>
                <div class="alert alert-info border-0 mb-0 mt-3 py-2 small fw-semibold">
                    <i class="fa fa-circle-info me-1"></i>
                    <?php echo $limite_iscrizioni === 'un_evento' ? "Puoi iscriverti a <strong>un solo gruppo</strong> di quest'area." : "Per ogni gruppo puoi scegliere <strong>un solo turno</strong>."; ?>
                    Per cambiare, annulla prima la prenotazione dall'<a href="area_personale.php" class="alert-link">Area Personale</a>.
                    Puoi invece metterti in <strong>lista d'attesa</strong> su più <?php echo $limite_iscrizioni === 'un_evento' ? 'gruppi' : 'turni'; ?>: appena ottieni un posto confermato, le altre liste d'attesa vengono annullate automaticamente.
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($page_cfg['box_info_html']) || !empty($page_cfg['allegati_box_info'])): ?>
            <div class="card shadow-sm border-0 p-4 mb-4" style="border-left: 5px solid <?php echo $col_primaria; ?> !important; border-radius: 8px;">
                <?php if (!empty($page_cfg['box_info_html'])): ?><div class="fs-6 text-dark" style="line-height: 1.7;"><?php echo $page_cfg['box_info_html']; ?></div><?php endif; ?>
                <?php if (!empty($page_cfg['allegati_box_info'])): ?>
                    <div class="mt-3 pt-3 border-top d-flex flex-wrap gap-2">
                        <?php foreach (explode(',', $page_cfg['allegati_box_info']) as $all): $all = trim($all); if ($all === '') continue; ?>
                            <a href="<?php echo htmlspecialchars($all); ?>" target="_blank" class="btn btn-outline-danger btn-sm fw-bold"><i class="fa fa-file-pdf me-1"></i> Guida / Allegato (PDF)</a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (empty($eventi_per_categoria)): ?>
            <div class="alert alert-light text-center border p-4 shadow-sm">Nessun gruppo disponibile al momento.</div>
        <?php else: ?>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-4 p-3 bg-light border rounded-3">
                <input type="search" id="gruppiCerca" class="form-control form-control-sm" placeholder="Cerca gruppo, luogo, istruttore..." style="max-width: 280px;" aria-label="Cerca gruppo">
                <?php if (count($eventi_per_categoria) > 1): ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary gruppi-pill active" data-gruppi-cat="__all">Tutte</button>
                    <?php foreach (array_keys($eventi_per_categoria) as $cat_nome): ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary gruppi-pill" data-gruppi-cat="<?php echo htmlspecialchars($cat_nome); ?>"><?php echo htmlspecialchars($cat_nome); ?></button>
                    <?php endforeach; ?>
                <?php endif; ?>
                <div class="form-check form-switch ms-lg-auto mb-0">
                    <input class="form-check-input" type="checkbox" id="gruppiSoloLiberi">
                    <label class="form-check-label small fw-bold" for="gruppiSoloLiberi">Solo con posti liberi</label>
                </div>
            </div>

            <?php foreach ($eventi_per_categoria as $cat_nome => $lista_gruppi): ?>
                <section class="gruppi-sezione mb-5" data-cat="<?php echo htmlspecialchars($cat_nome); ?>">
                    <h2 class="fw-bold fs-4 border-bottom pb-2 mb-3" style="color: <?php echo $col_primaria; ?>;">
                        <i class="fa fa-people-group me-2"></i><?php echo htmlspecialchars($cat_nome); ?>
                        <span class="badge bg-light text-secondary border ms-2 align-middle" style="font-size: .75rem;"><?php echo count($lista_gruppi); ?> <?php echo count($lista_gruppi) === 1 ? 'gruppo' : 'gruppi'; ?></span>
                    </h2>
                    <div class="row g-4">
                        <?php foreach ($lista_gruppi as $ev):
                            $ev_id = (int)$ev['id'];
                            // Solo le iscrizioni "vere" bloccano: le liste d'attesa no (decadono alla prima conferma)
                            $miei_turni_ev = $mie_iscrizioni[$ev_id] ?? [];
                            $iscritto_ev = (bool)array_diff($miei_turni_ev, ['in_attesa', 'richiesta_conferma']);
                            $iscritto_altrove = false;
                            foreach ($mie_iscrizioni as $ev_x => $turni_x) {
                                if ($ev_x !== $ev_id && array_diff($turni_x, ['in_attesa', 'richiesta_conferma'])) { $iscritto_altrove = true; break; }
                            }
                            $bloccato_area = ($limite_iscrizioni === 'un_evento' && $iscritto_altrove && !$iscritto_ev);
                            $bloccato_turni = ($limite_iscrizioni === 'un_turno' && $iscritto_ev);
                            $ruolo_ev = (int)($ev['ruolo_accesso_id'] ?? 0);

                            $righe_turni = []; $liberi_tot = 0;
                            foreach ($ev['turni'] as $t) {
                                $occ_g = getPostiOccupati($conn, $t['id']);
                                $max_g = max(0, (int)$t['max_posti']);
                                $disp_g = max(0, $max_g - $occ_g);
                                if (!turno_concluso($t)) $liberi_tot += $disp_g;
                                $righe_turni[] = ['t' => $t, 'occ' => $occ_g, 'max' => $max_g, 'disp' => $disp_g];
                            }
                            $search_txt = mb_strtolower($ev['titolo'] . ' ' . ($ev['luogo'] ?? '') . ' ' . strip_tags($ev['descrizione'] ?? '') . ' ' . $cat_nome);
                        ?>
                            <div class="<?php echo $col_class; ?> gruppo-item" data-search="<?php echo htmlspecialchars($search_txt); ?>" data-liberi="<?php echo (int)($ev['richiede_prenotazione'] ?? 1) === 0 ? 1 : $liberi_tot; ?>">
                                <div class="card h-100 border-0 shadow-sm gruppo-card <?php echo $iscritto_ev ? 'gruppo-iscritto' : ''; ?>">
                                    <?php if (!empty($ev['locandina_path']) && preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $ev['locandina_path'])): ?>
                                        <img src="<?php echo htmlspecialchars($ev['locandina_path']); ?>" class="card-img-top" alt="" style="height: 160px; object-fit: cover; border-radius: 8px 8px 0 0;">
                                    <?php endif; ?>
                                    <div class="card-body p-4 d-flex flex-column">
                                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                            <h3 class="fw-bold fs-5 m-0"><a href="<?php echo htmlspecialchars($current_filename); ?>.php?evento=<?php echo (int)$ev['id']; ?>" class="text-decoration-none" style="color: <?php echo $col_primaria; ?>;"><?php echo htmlspecialchars($ev['titolo']); ?></a></h3>
                                            <div class="d-flex flex-column align-items-end gap-1">
                                                <?php if ($iscritto_ev): ?><span class="badge bg-success"><i class="fa fa-check me-1"></i>Iscritto</span><?php endif; ?>
                                                <?php if ($ruolo_ev === -1): ?><span class="badge bg-warning text-dark">🔑 Solo Autenticati</span>
                                                <?php elseif ($ruolo_ev > 0): ?><span class="badge bg-dark">🔒 Solo <?php echo htmlspecialchars($nomi_ruoli[$ruolo_ev] ?? ''); ?></span><?php endif; ?>
                                            </div>
                                        </div>

                                        <?php if (!empty($ev['luogo'])): ?>
                                            <div class="small fw-semibold text-dark mb-2"><i class="fa fa-location-dot text-danger me-1"></i><?php echo htmlspecialchars($ev['luogo']); ?></div>
                                        <?php endif; ?>

                                        <?php $testo_g = testo_card_evento($ev); if ($testo_g !== ''): ?>
                                            <p class="text-secondary mb-3" style="font-size: .9rem; line-height: 1.5;"><?php echo $testo_g; ?></p>
                                        <?php endif; ?>

                                        <div class="mt-auto">
                                            <?php if (empty($righe_turni)): ?>
                                                <div class="small text-muted fst-italic">Orari in definizione.</div>
                                            <?php endif; ?>
                                            <?php foreach ($righe_turni as $rt):
                                                $t = $rt['t'];
                                                $pct = $rt['max'] > 0 ? min(100, round($rt['occ'] / $rt['max'] * 100)) : 100;
                                                $bar = $pct >= 100 ? 'bg-danger' : ($pct >= 75 ? 'bg-warning' : 'bg-success');
                                                $tf = $t + ['richiede_prenotazione' => $ev['richiede_prenotazione'], 'ruolo_accesso_id' => $ev['ruolo_accesso_id']];

                                                $mio_stato_t = $miei_turni_ev[(int)$t['id']] ?? null;
                                                if ($mio_stato_t === 'in_attesa' || $mio_stato_t === 'richiesta_conferma') {
                                                    $btn = '<span class="badge bg-warning text-dark py-2 px-3"><i class="fa fa-hourglass-half me-1"></i>' . ($mio_stato_t === 'in_attesa' ? "In lista d'attesa" : 'Posto da confermare') . '</span>';
                                                } elseif ($mio_stato_t !== null) {
                                                    $btn = '<span class="badge bg-success py-2 px-3"><i class="fa fa-check me-1"></i>Sei iscritto</span>';
                                                } else {
                                                    $btn = getPulsanteAzione($tf, $col_primaria, $utente_logged, $utente_ruolo_id, $conn, $nomi_ruoli);
                                                    if (strpos($btn, 'modPrenota') !== false && ($bloccato_area || $bloccato_turni)) {
                                                        $motivo = $bloccato_area ? 'Hai già un gruppo' : 'Già iscritto a un turno';
                                                        $btn = '<button class="btn btn-outline-secondary btn-sm fw-bold py-1 px-3" disabled><i class="fa fa-user-lock me-1"></i>' . $motivo . '</button>';
                                                    }
                                                }
                                            ?>
                                                <div class="border rounded-3 p-2 px-3 mb-2 bg-white">
                                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                                        <div class="small">
                                                            <?php if (!empty($t['nome_turno'])): ?><strong class="text-dark" style="font-size: .95rem;"><?php echo htmlspecialchars($t['nome_turno']); ?></strong><?php endif; ?>
                                                            <?php if (!empty($t['data_turno'])): ?><?php echo !empty($t['nome_turno']) ? '<span class="text-muted">·</span> ' : ''; ?><strong><?php echo formattaDataItaliano($t['data_turno']); ?></strong><?php endif; ?>
                                                            <?php if (orario_turno($t) !== ''): ?><span class="text-muted">· <?php echo orario_turno($t); ?></span><?php endif; ?>
                                                        </div>
                                                        <div><?php echo $btn; ?></div>
                                                    </div>
                                                    <?php if ((int)($ev['richiede_prenotazione'] ?? 1) === 1): ?>
                                                        <div class="progress mt-2" style="height: 6px;" role="progressbar" aria-label="Posti occupati" aria-valuenow="<?php echo $pct; ?>" aria-valuemin="0" aria-valuemax="100">
                                                            <div class="progress-bar <?php echo $bar; ?>" style="width: <?php echo $pct; ?>%;"></div>
                                                        </div>
                                                        <div class="d-flex justify-content-between mt-1" style="font-size: .75rem;">
                                                            <span class="text-muted"><?php echo $rt['occ']; ?>/<?php echo $rt['max']; ?> iscritti</span>
                                                            <span class="fw-bold <?php echo $rt['disp'] > 0 ? 'text-success' : 'text-danger'; ?>"><?php echo $rt['disp'] > 0 ? $rt['disp'] . ($rt['disp'] === 1 ? ' posto libero' : ' posti liberi') : 'Completo'; ?></span>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
            <div id="gruppiVuoto" class="alert alert-light text-center border p-4" style="display: none;">Nessun gruppo corrisponde ai filtri.</div>
        <?php endif; ?>
    </div>
    <script>
    (function () {
        var cerca = document.getElementById('gruppiCerca');
        if (!cerca) return;
        var soloLiberi = document.getElementById('gruppiSoloLiberi');
        var pills = document.querySelectorAll('[data-gruppi-cat]');
        var catAttiva = '__all';

        function applica() {
            var term = cerca.value.trim().toLowerCase();
            var totVisibili = 0;
            document.querySelectorAll('.gruppi-sezione').forEach(function (sec) {
                var catOk = (catAttiva === '__all' || sec.dataset.cat === catAttiva);
                var vis = 0;
                sec.querySelectorAll('.gruppo-item').forEach(function (it) {
                    var ok = catOk && (!term || it.dataset.search.indexOf(term) !== -1) && (!soloLiberi.checked || parseInt(it.dataset.liberi, 10) > 0);
                    it.style.display = ok ? '' : 'none';
                    if (ok) vis++;
                });
                sec.style.display = vis ? '' : 'none';
                totVisibili += vis;
            });
            document.getElementById('gruppiVuoto').style.display = totVisibili ? 'none' : '';
        }

        cerca.addEventListener('input', applica);
        soloLiberi.addEventListener('change', applica);
        pills.forEach(function (p) {
            p.addEventListener('click', function () {
                pills.forEach(function (x) { x.classList.remove('active'); });
                p.classList.add('active');
                catAttiva = p.dataset.gruppiCat;
                applica();
            });
        });
        document.querySelectorAll('.gruppo-desc-toggle').forEach(function (btn) {
            var desc = btn.previousElementSibling;
            if (desc.scrollHeight <= desc.clientHeight + 2) { btn.remove(); return; }
            btn.addEventListener('click', function () {
                var aperta = desc.classList.toggle('aperta');
                btn.textContent = aperta ? 'Mostra meno' : 'Mostra dettagli';
            });
        });
    })();
    </script>


<!-- ======================================================= -->
<!-- LAYOUT 7: PROGETTI (elenco con stato, periodo e scheda di dettaglio) -->
<!-- ======================================================= -->
<?php elseif ($layout_template === 'progetti'): ?>
    <?php
    $mesi_pj = ['', 'gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];
    $lista_pj = []; $altri_eventi = [];
    foreach ($eventi_by_id as $ev_l) {
        if (($ev_l['tipo'] ?? '') !== 'progetto') { $altri_eventi[] = $ev_l; continue; }
        $lista_pj[] = ['ev' => $ev_l, 'ip' => $info_progetto($ev_l)];
    }
    // Prima l'ordine scelto in admin (trascinamento); a parità: iscrizioni aperte, in arrivo, chiuse, in corso, concluse, poi data di inizio
    usort($lista_pj, fn($a, $b) => [(int)$a['ev']['ordine'], $a['ip']['stato']['ordine'], empty($a['ip']['d']['data_inizio']), $a['ip']['d']['data_inizio'] ?? '', (int)$a['ev']['id']]
                               <=> [(int)$b['ev']['ordine'], $b['ip']['stato']['ordine'], empty($b['ip']['d']['data_inizio']), $b['ip']['d']['data_inizio'] ?? '', (int)$b['ev']['id']]);
    $filtri_stato = ['aperte' => 'Iscrizioni aperte', 'arrivo' => 'In arrivo', 'attesa' => "Con lista d'attesa", 'in_corso' => 'In corso', 'chiuse' => 'Iscrizioni chiuse', 'concluso' => 'Conclusi'];
    $conta_stato = array_count_values(array_map(fn($x) => $x['ip']['stato']['codice'], $lista_pj));
    $strutture_pj = array_values(array_unique(array_filter(array_map(fn($x) => trim((string)($x['ip']['d']['struttura'] ?? '')), $lista_pj))));
    sort($strutture_pj);
    $txt_on_l = colore_testo_su($col_primaria);
    ?>
    <style>
        .pjl-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; box-shadow: 0 2px 10px rgba(15,23,42,.05); display: grid; grid-template-columns: 110px 1fr 240px; overflow: hidden; transition: box-shadow .2s, transform .2s; }
        .pjl-card:hover { box-shadow: 0 10px 28px rgba(15,23,42,.10); transform: translateY(-2px); }
        .pjl-card.concluso { opacity: .7; }
        .pjl-data { background: <?php echo $col_primaria; ?>; color: <?php echo $txt_on_l; ?>; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 1rem .5rem; line-height: 1.1; }
        .pjl-data .g { font-size: 2.1rem; font-weight: 800; }
        .pjl-data .m { font-size: .85rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; }
        .pjl-data .a { font-size: .75rem; opacity: .85; }
        .pjl-corpo { padding: 1.25rem 1.5rem; min-width: 0; }
        .pjl-corpo h3 { font-size: 1.2rem; font-weight: 800; margin: .35rem 0 .4rem; }
        .pjl-corpo h3 a { color: #111827; text-decoration: none; }
        .pjl-corpo h3 a:hover { color: <?php echo $col_testo_area; ?>; text-decoration: underline; }
        .pjl-estratto { color: #475569; font-size: .92rem; line-height: 1.55; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; margin: .5rem 0 0; }
        .pjl-chip { display: inline-flex; align-items: center; gap: .35rem; background: #f1f5f9; color: #334155; border-radius: 999px; padding: .2rem .65rem; font-size: .78rem; font-weight: 600; }
        .pjl-azioni { padding: 1.25rem; border-left: 1px solid #f1f5f9; display: flex; flex-direction: column; justify-content: center; gap: .5rem; background: #fcfcfd; }
        .pjl-stato { display: inline-flex; font-size: .72rem; font-weight: 700; padding: .25rem .65rem; border-radius: 999px; }
        .pjl-pill { border-radius: 999px; font-weight: 600; font-size: .85rem; }
        .pjl-pill.active { background-color: <?php echo $col_primaria; ?> !important; border-color: <?php echo $col_primaria; ?> !important; color: <?php echo $txt_on_l; ?> !important; }
        @media (max-width: 991.98px) { .pjl-card { grid-template-columns: 90px 1fr; } .pjl-azioni { grid-column: 1 / -1; border-left: 0; border-top: 1px solid #f1f5f9; flex-direction: row; flex-wrap: wrap; } .pjl-azioni > * { flex: 1 1 180px; } }
        @media (max-width: 575.98px) { .pjl-card { grid-template-columns: 1fr; } .pjl-data { flex-direction: row; gap: .5rem; padding: .6rem; } .pjl-data .g { font-size: 1.3rem; } }
    </style>
    <div class="container mb-5" style="max-width: <?php echo htmlspecialchars($page_cfg['larghezza_contenitore'] ?? '85%'); ?>;">
        <div class="pj-box-intro card shadow-sm border-0 p-4 mb-4 mt-2" style="border-left: 5px solid <?php echo $col_primaria; ?> !important; border-radius: 14px;">
            <h1 class="fw-bold m-0" style="color: <?php echo $col_testo_area; ?>; letter-spacing: -0.5px;"><?php echo htmlspecialchars(!empty($page_cfg['titolo']) ? $page_cfg['titolo'] : 'Progetti'); ?></h1>
            <?php if (!empty($page_cfg['sottotitolo'])): ?><div class="fs-5 fw-semibold text-secondary"><?php echo htmlspecialchars($page_cfg['sottotitolo']); ?></div><?php endif; ?>
            <?php if (!empty($page_cfg['hero_descrizione'])): ?><div class="text-dark mt-3" style="font-size: .95rem; line-height: 1.6;"><?php echo $page_cfg['hero_descrizione']; ?></div><?php endif; ?>
        </div>

        <?php if (!empty($page_cfg['box_info_html']) || !empty($page_cfg['allegati_box_info'])): ?>
            <div class="card shadow-sm border-0 p-4 mb-4" style="border-left: 5px solid <?php echo $col_primaria; ?> !important; border-radius: 8px;">
                <?php if (!empty($page_cfg['box_info_html'])): ?><div class="fs-6 text-dark" style="line-height: 1.7;"><?php echo $page_cfg['box_info_html']; ?></div><?php endif; ?>
                <?php if (!empty($page_cfg['allegati_box_info'])): ?>
                    <div class="mt-3 pt-3 border-top d-flex flex-wrap gap-2">
                        <?php foreach (explode(',', $page_cfg['allegati_box_info']) as $all): $all = trim($all); if ($all === '') continue; ?>
                            <a href="<?php echo htmlspecialchars($all); ?>" target="_blank" class="btn btn-outline-danger btn-sm fw-bold"><i class="fa fa-file-pdf me-1"></i> Guida / Allegato (PDF)</a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!$lista_pj): ?>
            <div class="alert alert-light text-center border p-4 shadow-sm">Nessun progetto pubblicato al momento.</div>
        <?php else: ?>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-4 p-3 bg-light border rounded-3">
                <label for="pjlCerca" class="visually-hidden">Cerca progetto</label>
                <input type="search" id="pjlCerca" class="form-control form-control-sm" placeholder="Cerca progetto, struttura, referente..." style="max-width: 300px;">
                <button type="button" class="btn btn-sm btn-outline-secondary pjl-pill active" data-pjl-stato="">Tutti <span>(<?php echo count($lista_pj); ?>)</span></button>
                <?php foreach ($filtri_stato as $cod => $lbl): if (empty($conta_stato[$cod])) continue; ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary pjl-pill" data-pjl-stato="<?php echo $cod; ?>"><?php echo $lbl; ?> <span>(<?php echo $conta_stato[$cod]; ?>)</span></button>
                <?php endforeach; ?>
                <?php if (count($strutture_pj) > 1): ?>
                    <label for="pjlStruttura" class="visually-hidden">Struttura</label>
                    <select id="pjlStruttura" class="form-select form-select-sm ms-lg-auto" style="max-width: 320px;">
                        <option value="">Tutte le strutture</option>
                        <?php foreach ($strutture_pj as $s_pj): ?><option value="<?php echo htmlspecialchars(mb_strtolower($s_pj)); ?>"><?php echo htmlspecialchars($s_pj); ?></option><?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <?php if (count($lista_pj) > 1): ?>
                    <label for="pjlOrdina" class="small fw-semibold text-secondary mb-0 <?php echo count($strutture_pj) > 1 ? '' : 'ms-lg-auto'; ?>">Ordina per</label>
                    <select id="pjlOrdina" class="form-select form-select-sm" style="max-width: 220px;">
                        <option value="">Predefinito</option>
                        <option value="stato">Iscrizioni aperte prima</option>
                        <option value="inizio">Data di inizio</option>
                        <option value="titolo">Titolo (A-Z)</option>
                    </select>
                <?php endif; ?>
            </div>

            <div class="d-flex flex-column gap-3" id="pjlLista">
                <?php foreach ($lista_pj as $pos_l => ['ev' => $ev_l, 'ip' => $ip_l]):
                    $d_l = $ip_l['d']; $st_l = $ip_l['stato'];
                    $dest_l = $ip_l['dest'] ?? null;
                    $url_scheda = $dest_l ? htmlspecialchars($dest_l['url']) : htmlspecialchars($current_filename) . '.php?progetto=' . (int)$ev_l['id'];
                    $target_l = ($dest_l && $dest_l['esterno']) ? ' target="_blank" rel="noopener"' : '';
                    $cerca_l = mb_strtolower($ev_l['titolo'] . ' ' . ($d_l['struttura'] ?? '') . ' ' . ($d_l['destinatari'] ?? '') . ' ' . ($ev_l['luogo'] ?? '') . ' '
                             . implode(' ', array_map(fn($r) => ($r['nome'] ?? ''), $d_l['referenti'] ?? [])) . ' ' . strip_tags($ev_l['descrizione'] ?? ''));
                    $estratto = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', $ev_l['descrizione'] ?? '')), ENT_QUOTES, 'UTF-8')));
                ?>
                    <article class="pjl-card<?php echo $st_l['codice'] === 'concluso' ? ' concluso' : ''; ?>" data-cerca="<?php echo htmlspecialchars($cerca_l); ?>" data-stato="<?php echo $st_l['codice']; ?>" data-struttura="<?php echo htmlspecialchars(mb_strtolower(trim((string)($d_l['struttura'] ?? '')))); ?>" data-pos="<?php echo (int)$pos_l; ?>" data-ord-stato="<?php echo (int)$st_l['ordine']; ?>" data-inizio="<?php echo htmlspecialchars($d_l['data_inizio'] ?? ''); ?>" data-titolo="<?php echo htmlspecialchars(mb_strtolower($ev_l['titolo'])); ?>">
                        <div class="pjl-data" aria-hidden="true">
                            <?php if (!empty($d_l['data_inizio'])): $ts_l = strtotime($d_l['data_inizio']); ?>
                                <span class="g"><?php echo date('j', $ts_l); ?></span><span class="m"><?php echo $mesi_pj[(int)date('n', $ts_l)]; ?></span><span class="a"><?php echo date('Y', $ts_l); ?></span>
                            <?php else: ?>
                                <i class="fa fa-calendar-plus fs-3 mb-1"></i><span class="m" style="font-size:.7rem;">Date da definire</span>
                            <?php endif; ?>
                        </div>
                        <div class="pjl-corpo">
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <span class="pjl-stato" style="background: <?php echo $st_l['bg']; ?>; color: <?php echo $st_l['fg']; ?>;"><?php echo htmlspecialchars($st_l['etichetta']); ?></span>
                                <?php if (!empty($d_l['struttura'])): ?><span class="small text-secondary fw-semibold"><i class="fa fa-building-columns me-1" aria-hidden="true"></i><?php echo htmlspecialchars($d_l['struttura']); ?></span><?php endif; ?>
                            </div>
                            <h3><a href="<?php echo $url_scheda; ?>"<?php echo $target_l; ?>><?php echo htmlspecialchars($ev_l['titolo']); ?></a></h3>
                            <div class="d-flex flex-wrap gap-2">
                                <span class="pjl-chip"><i class="fa fa-calendar-days" aria-hidden="true"></i><?php echo htmlspecialchars($ip_l['periodo']); ?></span>
                                <?php if (count($ip_l['edizioni']) > 1): ?><span class="pjl-chip"><i class="fa fa-clone" aria-hidden="true"></i><?php echo count($ip_l['edizioni']); ?> edizioni</span><?php endif; ?>
                                <?php if (!empty($d_l['ore_totali'])): ?><span class="pjl-chip"><i class="fa fa-clock" aria-hidden="true"></i><?php echo (int)$d_l['ore_totali']; ?> ore</span><?php endif; ?>
                                <?php if ($ip_l['scuole'] && $ip_l['limiti'] !== ''): ?><span class="pjl-chip"><i class="fa fa-users" aria-hidden="true"></i><?php echo htmlspecialchars($ip_l['limiti']); ?> studenti</span><?php endif; ?>
                                <?php if (!empty($d_l['destinatari'])): ?><span class="pjl-chip"><i class="fa fa-user-graduate" aria-hidden="true"></i><?php echo htmlspecialchars($d_l['destinatari']); ?></span><?php endif; ?>
                            </div>
                            <?php if ($estratto !== ''): ?><p class="pjl-estratto"><?php echo htmlspecialchars(mb_strimwidth($estratto, 0, 320, '…')); ?></p><?php endif; ?>
                        </div>
                        <div class="pjl-azioni">
                            <?php if ($dest_l): ?>
                                <div class="small fw-semibold" style="color: #075985;"><i class="fa fa-up-right-from-square me-1" aria-hidden="true"></i>Prenotazioni sulla pagina <?php echo htmlspecialchars($dest_l['nome']); ?></div>
                            <?php elseif ($ip_l['t'] && in_array($st_l['codice'], ['aperte', 'attesa', 'arrivo'], true)):
                                $n_ed_l = count($ip_l['edizioni']);
                                if (!$ip_l['scuole']) $txt_disp = $ip_l['liberi'] > 0 ? $ip_l['liberi'] . ($ip_l['liberi'] === 1 ? ' posto libero' : ' posti liberi') : 'Posti esauriti' . ($ip_l['attesa'] ? ' · ' . $ip_l['attesa'] . ' in attesa' : '');
                                elseif ($ip_l['liberi'] > 0) $txt_disp = $n_ed_l > 1 ? $ip_l['liberi'] . ' ' . ($ip_l['liberi'] === 1 ? 'edizione disponibile' : 'edizioni disponibili') . ' su ' . $n_ed_l : 'Posto disponibile';
                                else $txt_disp = ($n_ed_l > 1 ? 'Edizioni assegnate' : 'Assegnato') . ($ip_l['attesa'] ? ' · ' . $ip_l['attesa'] . ' in attesa' : '');
                            ?>
                                <div class="small fw-semibold <?php echo $ip_l['liberi'] > 0 ? 'text-success' : 'text-warning'; ?>">
                                    <i class="fa <?php echo $ip_l['liberi'] > 0 ? 'fa-circle-check' : 'fa-lock'; ?> me-1" aria-hidden="true"></i><?php echo htmlspecialchars($txt_disp); ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($dest_l): ?>
                                <a href="<?php echo $url_scheda; ?>"<?php echo $target_l; ?> class="btn btn-outline-secondary fw-bold w-100">Vai a <?php echo htmlspecialchars($dest_l['nome']); ?> <i class="fa fa-arrow-right ms-1" aria-hidden="true"></i></a>
                            <?php else: ?>
                                <a href="<?php echo $url_scheda; ?>" class="btn btn-outline-secondary fw-bold w-100">Dettagli<span class="visually-hidden"> di <?php echo htmlspecialchars($ev_l['titolo']); ?></span> <i class="fa fa-arrow-right ms-1" aria-hidden="true"></i></a>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <div id="pjlVuoto" class="alert alert-light text-center border p-4 mt-3" style="display: none;">Nessun progetto corrisponde ai filtri.</div>

            <script>
            (function () {
                var cerca = document.getElementById('pjlCerca'), strutt = document.getElementById('pjlStruttura');
                var pills = document.querySelectorAll('[data-pjl-stato]'), statoAtt = '';
                function applica() {
                    var q = cerca.value.trim().toLowerCase(), s = strutt ? strutt.value : '', vis = 0;
                    document.querySelectorAll('#pjlLista .pjl-card').forEach(function (c) {
                        var ok = (!q || c.dataset.cerca.indexOf(q) !== -1) && (!statoAtt || c.dataset.stato === statoAtt) && (!s || c.dataset.struttura === s);
                        c.style.display = ok ? '' : 'none';
                        if (ok) vis++;
                    });
                    document.getElementById('pjlVuoto').style.display = vis ? 'none' : '';
                }
                cerca.addEventListener('input', applica);
                if (strutt) strutt.addEventListener('change', applica);
                var ordina = document.getElementById('pjlOrdina'), lista = document.getElementById('pjlLista');
                var inizio = function (c) { return c.dataset.inizio || '9999'; };
                var confronti = {
                    '':     function (a, b) { return a.dataset.pos - b.dataset.pos; },
                    stato:  function (a, b) { return (a.dataset.ordStato - b.dataset.ordStato) || inizio(a).localeCompare(inizio(b)) || (a.dataset.pos - b.dataset.pos); },
                    inizio: function (a, b) { return inizio(a).localeCompare(inizio(b)) || (a.dataset.pos - b.dataset.pos); },
                    titolo: function (a, b) { return a.dataset.titolo.localeCompare(b.dataset.titolo, 'it'); }
                };
                if (ordina) ordina.addEventListener('change', function () {
                    Array.from(lista.querySelectorAll('.pjl-card')).sort(confronti[ordina.value] || confronti['']).forEach(function (c) { lista.appendChild(c); });
                });
                pills.forEach(function (p) {
                    p.addEventListener('click', function () {
                        pills.forEach(function (x) { x.classList.remove('active'); x.setAttribute('aria-pressed', 'false'); });
                        p.classList.add('active'); p.setAttribute('aria-pressed', 'true');
                        statoAtt = p.dataset.pjlStato; applica();
                    });
                });
            })();
            </script>
        <?php endif; ?>

        <?php if ($altri_eventi): ?>
            <h2 class="fw-bold fs-4 border-bottom pb-2 mt-5 mb-3" style="color: <?php echo $col_testo_area; ?>;">Altre attività</h2>
            <div class="row g-4">
                <?php foreach ($altri_eventi as $ev): ?>
                    <div class="<?php echo $col_class; ?>"><?php renderCardUniversal($ev, $col_primaria, $utente_logged, $utente_ruolo_id, $nomi_ruoli, $etichette_riservato, $val_nome, $val_cognome, $val_email, $val_matricola, $conn, $chiedi_matricola); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

<!-- ======================================================= -->
<!-- LAYOUT 3 e 4: GRID O LIST SEMPLICE (I Vecchi Layout) -->
<!-- ======================================================= -->
<?php else: ?>
    <div class="container mb-5" style="max-width: <?php echo htmlspecialchars($page_cfg['larghezza_contenitore'] ?? '85%'); ?>;">
        
        <!-- RIGA 1: TESTO BENVENUTO E BANNER -->
        <div class="row align-items-center mb-4 g-4">
            <div class="col-lg-7">
                <div class="card shadow-sm border-0 p-4" style="border-left: 5px solid <?php echo $col_primaria; ?> !important; background: #ffffff; border-radius: 12px;">
                    <div class="text-dark" style="font-size: 0.95rem; line-height: 1.6;">
                        <?php if (!empty($page_cfg['hero_descrizione'])): echo $page_cfg['hero_descrizione']; else: ?><strong style="color: #000;">Benvenuto/a:</strong> scopri il programma ed iscriviti alle attività di tuo interesse.<br><br><strong style="color: <?php echo $col_primaria; ?>;">I posti sono limitati!</strong><?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card shadow-sm border-0 text-center p-4 w-100 d-flex flex-column justify-content-center align-items-center position-relative overflow-hidden" style="border: 2px solid <?php echo $col_primaria; ?> !important; border-radius: 14px; background: #fdfbfb;">
                    <div>
                        <?php if (!empty($page_cfg['sidebar_intervallo_date'])): ?><div class="d-inline-block badge bg-warning text-dark fw-bold px-3 py-1 fs-6 rounded-pill mb-2 border shadow-sm">📝 <?php echo htmlspecialchars($page_cfg['sidebar_intervallo_date']); ?></div><?php endif; ?>
                        <h1 class="fw-bold display-5 m-0 my-1" style="color: <?php echo $col_primaria; ?>; font-weight: 900; letter-spacing: -1px;"><?php echo htmlspecialchars(!empty($page_cfg['titolo']) ? $page_cfg['titolo'] : 'EVENTI'); ?></h1>
                        <h2 class="fw-bold fs-3 mb-0" style="color: <?php echo $col_testo_area; ?> !important; letter-spacing: 2px;"><?php echo htmlspecialchars(!empty($page_cfg['sottotitolo']) ? $page_cfg['sottotitolo'] : 'DiBEST'); ?></h2>
                    </div>
                    <div class="mt-auto pt-3 w-100 text-center" style="color: <?php echo $col_primaria; ?>;">
                        <?php if (!empty($page_cfg['hero_banner_path'])): ?><img src="uploads/<?php echo htmlspecialchars(basename($page_cfg['hero_banner_path'])); ?>" class="img-fluid mt-auto" style="max-height: 100px; object-fit: contain;" alt="Banner Custom"><?php else: ?><svg viewBox="0 0 500 80" class="w-100 mt-auto" style="max-height: 60px;" fill="currentColor"><path d="M10,80 L35,40 L60,80 Z M30,50 L40,80 Z"></path><circle cx="48" cy="42" r="3"></circle><path d="M70,80 C70,60 85,50 100,50 C115,50 120,60 120,80 Z"></path><path d="M130,80 C130,55 145,40 160,40 C175,40 190,55 190,80 Z"></path><circle cx="210" cy="50" r="4"></circle><path d="M210,55 L210,75 M203,62 L217,62 M205,80 L210,75 L215,80"></path><circle cx="225" cy="45" r="4"></circle><path d="M225,50 L225,72 M218,55 L232,55 M220,80 L225,72 L230,80"></path><circle cx="240" cy="48" r="4"></circle><path d="M240,53 L240,75 M233,60 L247,60 M235,80 L240,75 L245,80"></path><circle cx="255" cy="42" r="4"></circle><path d="M255,47 L255,70 M248,52 L262,52 M250,80 L255,70 L260,80"></path><path d="M320,80 L320,50 L360,50 L360,80 Z M330,80 L330,60 L350,60 L350,80 Z M340,40 L320,50 L360,50 Z"></path></svg><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGA 2: EVENTO IN EVIDENZA -->
        <?php if ($evento_evidenza): ?>
            <?php $data_head_ev = !empty($evento_evidenza['turni'][0]['data_turno']) ? $evento_evidenza['turni'][0]['data_turno'] : ''; ?>
            
            <div class="mb-5 shadow-sm rounded-4" style="border: 3px solid <?php echo $col_primaria; ?>; background-color: #fff; overflow: hidden;">
                
                <div class="text-white px-3 py-2 d-flex align-items-center flex-wrap gap-3" style="background-color: <?php echo $col_primaria; ?>;">
                    <span class="badge bg-warning text-dark fw-bold px-3 py-2 text-uppercase d-flex align-items-center gap-2 shadow-sm" style="letter-spacing: 1px; font-size: 0.9rem;">
                        <i class="fa fa-star text-dark fs-6"></i> IN EVIDENZA
                    </span>
                    
                    <?php if ($data_head_ev): ?>
                        <span class="fs-4 d-flex align-items-center gap-2 ms-md-2 date-white-force">
                            <i class="fa fa-calendar-alt fs-5"></i> <?php echo formattaDataItaliano($data_head_ev); ?>
                        </span>
                    <?php endif; ?>
                </div>

                <div class="p-2 p-md-3 bg-white box-evidenza">
                    <style>
                        .box-evidenza .card { box-shadow: none !important; margin-bottom: 0 !important; border: none !important; }
                        .date-white-force, .date-white-force * { color: #ffffff !important; font-weight: 700 !important; }
                    </style>
                    <?php renderCardUniversal($evento_evidenza, $col_primaria, $utente_logged, $utente_ruolo_id, $nomi_ruoli, $etichette_riservato, $val_nome, $val_cognome, $val_email, $val_matricola, $conn, $chiedi_matricola); ?>
                </div>
                
            </div>
        <?php endif; ?>

        <?php if (!empty($page_cfg['box_info_html']) || !empty($page_cfg['allegati_box_info'])): ?>
            <div class="card shadow-sm border-0 p-4 mb-4" style="border-left: 5px solid <?php echo $col_primaria; ?> !important; background: #ffffff; border-radius: 8px;">
                <?php if (!empty($page_cfg['box_info_html'])): ?>
                    <div class="fs-6 text-dark" style="line-height: 1.7;"><?php echo $page_cfg['box_info_html']; ?></div>
                <?php endif; ?>
                
                <?php if (!empty($page_cfg['allegati_box_info'])): ?>
                    <div class="mt-3 pt-3 border-top d-flex flex-wrap gap-2">
                        <?php 
                            $allegati = explode(',', $page_cfg['allegati_box_info']);
                            foreach($allegati as $all): 
                                $all = trim($all);
                                if(empty($all)) continue;
                        ?>
                            <a href="<?php echo htmlspecialchars($all); ?>" target="_blank" class="btn btn-outline-danger btn-sm fw-bold shadow-sm">
                                <i class="fa fa-file-pdf me-1"></i> Guida / Allegato (PDF)
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- CONTROLLO SIDEBAR PER GRID E LIST -->
        <?php if (!empty($page_cfg['mostra_sidebar']) && $page_cfg['mostra_sidebar'] == 1): ?>
            <div class="row g-4 mt-1 align-items-start">
                <div class="col-lg-3">
                    <div class="card shadow-sm border-0 p-4 text-center sidebar-sticky-fix" style="border-top: 4px solid <?php echo $col_primaria; ?> !important; border-radius: 8px; background: #ffffff;">
                        <h3 class="fw-bold fs-4 mb-2" style="color: <?php echo $col_primaria; ?>;"><?php echo htmlspecialchars(!empty($page_cfg['sidebar_titolo']) ? $page_cfg['sidebar_titolo'] : 'Scegli il tuo evento'); ?></h3>
                        <?php if (!empty($page_cfg['sidebar_intervallo_date'])): ?>
                            <div class="badge bg-danger p-2 fs-6 mb-3">📅 <?php echo htmlspecialchars($page_cfg['sidebar_intervallo_date']); ?></div>
                        <?php endif; ?>
                        
                        <div class="text-secondary small text-start mt-2 mb-4" style="line-height: 1.6;"><?php echo !empty($page_cfg['sidebar_testo']) ? $page_cfg['sidebar_testo'] : 'Naviga il calendario qui a fianco per scoprire tutte le iniziative previste.'; ?></div>
                        
                        <!-- DOCUMENTI SIDEBAR SPOSTATI IN BASSO - GRID/LIST -->
                        <?php if (!empty($page_cfg['allegati_sidebar'])): ?>
                            <div class="d-flex flex-column gap-2 mt-auto border-top pt-3">
                                <?php 
                                    $allegati_sb = explode(',', $page_cfg['allegati_sidebar']);
                                    foreach($allegati_sb as $asb): 
                                        $asb = trim($asb);
                                        if(empty($asb)) continue;
                                ?>
                                    <a href="<?php echo htmlspecialchars($asb); ?>" target="_blank" class="btn btn-outline-danger fw-bold shadow-sm py-2">
                                        <i class="fa fa-file-pdf me-1"></i> Scarica Guida
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="col-lg-9">
                    <?php if ($layout_template == 'list'): ?>
                        <?php foreach ($eventi_per_data as $data_giorno => $lista_ev_giorno): ?>
                            <h3 class="fw-bold mb-3 fs-3 text-dark border-bottom pb-2"><?php echo $titolo_giorno($data_giorno); ?></h3>
                            <div class="row g-4 mb-5">
                                <?php $dinamic_col = (count($lista_ev_giorno) == 1) ? 'col-12' : $col_class; ?>
                                <?php foreach ($lista_ev_giorno as $ev): ?>
                                    <div class="<?php echo $dinamic_col; ?>">
                                        <?php renderCardUniversal($ev, $col_primaria, $utente_logged, $utente_ruolo_id, $nomi_ruoli, $etichette_riservato, $val_nome, $val_cognome, $val_email, $val_matricola, $conn, $chiedi_matricola); ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <!-- GRIGLIA -->
                        <div class="row g-4 mb-4 align-items-start">
                            <?php foreach ($sezioni_superiori as $nome_sezione => $lista_ev_sup): ?>
                                <div class="col-md-6 mb-2">
                                    <h2 class="p-3 mb-3 rounded shadow-sm text-center text-uppercase fw-bold fs-6" style="background-color: <?php echo $col_primaria; ?>; color: <?php echo colore_testo_su($col_primaria); ?>; letter-spacing: 1px;"><?php echo htmlspecialchars($nome_sezione); ?></h2>
                                    <div class="row g-4">
                                        <?php foreach ($lista_ev_sup as $ev): ?><div class="col-12"><?php renderCardUniversal($ev, $col_primaria, $utente_logged, $utente_ruolo_id, $nomi_ruoli, $etichette_riservato, $val_nome, $val_cognome, $val_email, $val_matricola, $conn, $chiedi_matricola); ?></div><?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php foreach ($gruppi_griglia as $nome_gruppo => $lista_gruppo): $tit_g = $titolo_gruppo_griglia((string)$nome_gruppo); ?>
                            <?php if ($tit_g !== ''): ?>
                                <h2 class="p-3 my-4 rounded shadow-sm text-center text-uppercase fw-bold fs-5" style="background-color: <?php echo $col_primaria; ?>; color: <?php echo colore_testo_su($col_primaria); ?>; letter-spacing: 1px;"><?php echo htmlspecialchars($tit_g); ?></h2>
                            <?php endif; ?>
                            <div class="row g-4 mb-4">
                                <?php foreach ($lista_gruppo as $ev): ?><div class="<?php echo $col_class; ?>"><?php renderCardUniversal($ev, $col_primaria, $utente_logged, $utente_ruolo_id, $nomi_ruoli, $etichette_riservato, $val_nome, $val_cognome, $val_email, $val_matricola, $conn, $chiedi_matricola); ?></div><?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <!-- SENZA SIDEBAR -->
            <?php if ($layout_template == 'list'): ?>
                <?php foreach ($eventi_per_data as $data_giorno => $lista_ev_giorno): ?>
                    <h3 class="fw-bold mb-3 fs-3 text-dark border-bottom pb-2"><?php echo $titolo_giorno($data_giorno); ?></h3>
                    <div class="row g-4 mb-5">
                        <?php $dinamic_col = (count($lista_ev_giorno) == 1) ? 'col-12' : $col_class; ?>
                        <?php foreach ($lista_ev_giorno as $ev): ?>
                            <div class="<?php echo $dinamic_col; ?>">
                                <?php renderCardUniversal($ev, $col_primaria, $utente_logged, $utente_ruolo_id, $nomi_ruoli, $etichette_riservato, $val_nome, $val_cognome, $val_email, $val_matricola, $conn, $chiedi_matricola); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <!-- GRIGLIA -->
                <div class="row g-4 mb-4 align-items-start">
                    <?php foreach ($sezioni_superiori as $nome_sezione => $lista_ev_sup): ?>
                        <div class="col-md-6 mb-2">
                            <h2 class="p-3 mb-3 rounded shadow-sm text-center text-uppercase fw-bold fs-6" style="background-color: <?php echo $col_primaria; ?>; color: <?php echo colore_testo_su($col_primaria); ?>; letter-spacing: 1px;"><?php echo htmlspecialchars($nome_sezione); ?></h2>
                            <div class="row g-4">
                                <?php foreach ($lista_ev_sup as $ev): ?><div class="col-12"><?php renderCardUniversal($ev, $col_primaria, $utente_logged, $utente_ruolo_id, $nomi_ruoli, $etichette_riservato, $val_nome, $val_cognome, $val_email, $val_matricola, $conn, $chiedi_matricola); ?></div><?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php foreach ($gruppi_griglia as $nome_gruppo => $lista_gruppo): $tit_g = $titolo_gruppo_griglia((string)$nome_gruppo); ?>
                    <?php if ($tit_g !== ''): ?>
                        <h2 class="p-3 my-4 rounded shadow-sm text-center text-uppercase fw-bold fs-5" style="background-color: <?php echo $col_primaria; ?>; color: <?php echo colore_testo_su($col_primaria); ?>; letter-spacing: 1px;"><?php echo htmlspecialchars($tit_g); ?></h2>
                    <?php endif; ?>
                    <div class="row g-4 mb-4">
                        <?php foreach ($lista_gruppo as $ev): ?><div class="<?php echo $col_class; ?>"><?php renderCardUniversal($ev, $col_primaria, $utente_logged, $utente_ruolo_id, $nomi_ruoli, $etichette_riservato, $val_nome, $val_cognome, $val_email, $val_matricola, $conn, $chiedi_matricola); ?></div><?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php
// Barra di modifica rapida: solo per chi gestisce l'area o l'evento/progetto aperto (i permessi veri li controlla l'admin)
$ev_ctx = $progetto_sel ?: ($evento_sel ?: null);
$gestisce_ev = $utente_logged && $ev_ctx && utente_gestisce_attivita($conn, (int)$_SESSION['utente_id'], (int)$ev_ctx['id']);
if ($is_gestore_o_admin || $gestisce_ev):
    $puo_impostazioni = $is_gestore_o_admin; // chi gestisce tutta l'area ha anche le impostazioni
    $ha_progetti = (bool)array_filter($eventi_by_id, fn($e) => ($e['tipo'] ?? '') === 'progetto');
    $link_admin = [];
    if ($ev_ctx && ($ev_ctx['tipo'] ?? '') === 'progetto') $link_admin[] = ['admin/progetti.php?p_id=' . $p_id . '&id=' . (int)$ev_ctx['id'], 'fa-pen', 'Modifica progetto', true];
    elseif ($ev_ctx) $link_admin[] = ['admin/eventi.php?p_id=' . $p_id . '&id=' . (int)$ev_ctx['id'], 'fa-pen', 'Modifica evento', true];
    if ($is_gestore_o_admin) {
        $link_admin[] = ['admin/eventi.php?p_id=' . $p_id, 'fa-calendar-alt', 'Eventi', false];
        if ($ha_progetti || ($page_cfg['layout_template'] ?? '') === 'progetti') $link_admin[] = ['admin/progetti.php?p_id=' . $p_id, 'fa-diagram-project', 'Progetti', false];
        if ($puo_impostazioni) $link_admin[] = ['admin/impostazioni_area.php?p_id=' . $p_id, 'fa-sliders', 'Impostazioni area', false];
    }
    $link_admin[] = ['admin/dashboard.php?p_id=' . $p_id, 'fa-gauge', 'Pannello', false];
?>
<style>
    .barra-gestore { position: fixed; left: 16px; bottom: 16px; z-index: 1030; display: flex; flex-wrap: wrap; align-items: center; gap: 4px;
                     max-width: calc(100vw - 32px); background: #1e293b; color: #fff; border-radius: 999px; padding: 5px 6px 5px 14px; box-shadow: 0 6px 20px rgba(0,0,0,.25); font-size: .82rem; }
    .barra-gestore .etichetta { font-weight: 700; color: #cbd5e1; margin-right: 4px; }
    .barra-gestore a { color: #fff; text-decoration: none; padding: 5px 11px; border-radius: 999px; font-weight: 600; white-space: nowrap; }
    .barra-gestore a:hover, .barra-gestore a:focus-visible { background: rgba(255,255,255,.15); }
    .barra-gestore a.principale { background: #f59e0b; color: #1e293b; }
    .barra-gestore a.principale:hover { background: #fbbf24; }
    .barra-gestore button { background: none; border: 0; color: #94a3b8; padding: 4px 8px; border-radius: 999px; }
    .barra-gestore.chiusa > :not(.apri) { display: none; }
    .barra-gestore:not(.chiusa) .apri { display: none; }
    .barra-gestore.chiusa { padding: 5px; }
    @media (max-width: 575.98px) { .barra-gestore { border-radius: 14px; } .barra-gestore .etichetta { display: none; } }
    @media print { .barra-gestore { display: none !important; } }
</style>
<nav class="barra-gestore" id="barraGestore" aria-label="Strumenti del gestore">
    <span class="etichetta"><i class="fa fa-user-gear me-1" aria-hidden="true"></i>Gestore</span>
    <?php foreach ($link_admin as [$url_a, $ico_a, $txt_a, $princ_a]): ?>
        <a href="<?php echo htmlspecialchars($url_a); ?>"<?php echo $princ_a ? ' class="principale"' : ''; ?>><i class="fa <?php echo $ico_a; ?> me-1" aria-hidden="true"></i><?php echo htmlspecialchars($txt_a); ?></a>
    <?php endforeach; ?>
    <button type="button" class="chiudi" aria-label="Riduci la barra del gestore" title="Riduci"><i class="fa fa-chevron-left" aria-hidden="true"></i></button>
    <button type="button" class="apri" aria-label="Mostra la barra del gestore" title="Strumenti del gestore" style="color:#fff;"><i class="fa fa-user-gear" aria-hidden="true"></i></button>
</nav>
<script>
(function () {
    var b = document.getElementById('barraGestore');
    var ridotta = function (v) { b.classList.toggle('chiusa', v); try { localStorage.setItem('barra_gestore_chiusa', v ? '1' : '0'); } catch (e) {} };
    try { if (localStorage.getItem('barra_gestore_chiusa') === '1') b.classList.add('chiusa'); } catch (e) {}
    b.querySelector('.chiudi').addEventListener('click', function () { ridotta(true); });
    b.querySelector('.apri').addEventListener('click', function () { ridotta(false); });
})();
</script>
<?php endif; ?>

<?php foreach($all_turni_flat as $t_flat): ?>
    <?php printModalPrenotazione($t_flat, $col_primaria, $utente_logged, $val_nome, $val_cognome, $val_email, $val_matricola, $chiedi_matricola, $conn, $p_id); ?>
<?php endforeach; ?>

<script>
    // Email ripetuta: avviso subito se le due non coincidono (il server ricontrolla)
    document.addEventListener('input', function (e) {
        if (!e.target.matches('.pren-email, .pren-email-conf')) return;
        var f = e.target.form, a = f.querySelector('.pren-email'), b = f.querySelector('.pren-email-conf');
        if (!a || !b) return;
        b.setCustomValidity(b.value && a.value.trim().toLowerCase() !== b.value.trim().toLowerCase() ? 'Le due email non coincidono' : '');
    });
    if (window.history.replaceState) {
        const url = new URL(window.location);
        // Toglie i parametri dell'esito, lasciando quelli di navigazione (es. ?progetto=ID)
        if (url.searchParams.has('status')) { ['status', 'code', 'st_tipo', 'ev'].forEach(function (k) { url.searchParams.delete(k); }); window.history.replaceState({path:url.href}, '', url.href); }
    }
</script>
<?php require_once 'footer.php'; ?>
