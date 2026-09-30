<?php
// progetti.php - Progetti (es. Formazione Scuola Lavoro): scheda completa del progetto, edizioni e iscrizioni.
// Un progetto è un evento con tipo = 'progetto', una scheda in progetti_dettagli e un solo turno di iscrizione
// per edizione. Progetti per le scuole: 1 posto per edizione (una scuola, le altre in lista d'attesa); progetti generici: posti a scelta.
// Iscritti, messaggi, archivio, form builder e notifiche funzionano come per gli altri eventi.
require_once 'admin_header.php';

if (!$can_manage_eventi) {
    echo "<div class='alert alert-danger fw-bold shadow-sm'><i class='fa fa-ban me-2'></i> Accesso negato. Non hai i permessi per gestire i progetti in quest'area.</div>";
    require_once 'admin_footer.php';
    exit;
}

function admin_redirect($url) { echo "<script>window.location.replace(" . json_encode($url) . ");</script>"; exit; }

set_exception_handler(function (Throwable $e) {
    error_log('[admin/progetti.php] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    echo "<div class='alert alert-danger fw-bold m-4'><i class='fa fa-bug me-2'></i>Errore durante il salvataggio: " . h($e->getMessage()) . "</div>";
    exit;
});

$puo_creare = $is_full_admin || $is_area_manager;

// Progetto dell'area corrente, visibile al gestore
function progetto_autorizzato($conn, int $ev_id, int $p_id, string $rbac): bool {
    $r = $conn->query("SELECT 1 FROM eventi e WHERE e.id = $ev_id AND e.pagina_id = $p_id AND e.tipo = 'progetto' $rbac LIMIT 1");
    return $r && $r->num_rows > 0;
}

// Valori del form: stringa vuota -> null
function post_testo(string $k, int $max = 255): ?string {
    $v = trim((string)($_POST[$k] ?? ''));
    return $v === '' ? null : mb_substr($v, 0, $max);
}
function intero_da($v): ?int {
    $v = trim((string)$v);
    return ($v === '' || !preg_match('/^\d+$/', $v)) ? null : (int)$v;
}
function post_intero(string $k): ?int {
    return intero_da($_POST[$k] ?? '');
}
function post_data(string $k): ?string {
    $v = trim((string)($_POST[$k] ?? ''));
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : null;
}
function post_data_ora(string $k): ?string {
    return data_ora_da($_POST[$k] ?? '');
}
function data_ora_da($v): ?string {
    $v = trim((string)$v);
    if ($v === '') return null;
    $d = DateTime::createFromFormat('Y-m-d\TH:i', $v) ?: DateTime::createFromFormat('Y-m-d H:i:s', $v);
    return $d ? $d->format('Y-m-d H:i:s') : null;
}

// =====================================================================
// SALVATAGGIO (nuovo o modifica)
// =====================================================================
if (isset($_POST['salva_progetto'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $ev_id = (int)($_POST['evento_id'] ?? 0);
    if ($ev_id > 0 && !progetto_autorizzato($conn, $ev_id, $filtro_p, $sql_filtro_eventi_rbac)) nega_accesso();
    if ($ev_id === 0 && !$puo_creare) nega_accesso();
    $url_form = "progetti.php?p_id=$filtro_p&" . ($ev_id ? "id=$ev_id" : "azione=nuovo");

    $titolo = post_testo('titolo');
    if ($titolo === null) { flash_set("Il titolo del progetto è obbligatorio.", 'danger'); admin_redirect($url_form); }
    $luogo  = post_testo('luogo') ?? '';
    $desc   = (string)($_POST['descrizione'] ?? '');
    $ord    = (int)($_POST['ordine'] ?? 0);
    $evid   = isset($_POST['is_evidenza']) ? 1 : 0;

    $d = [
        'struttura'         => post_testo('struttura') ?? '',
        'data_inizio'       => post_data('data_inizio'),
        'data_fine'         => post_data('data_fine'),
        'periodo_note'      => post_testo('periodo_note') ?? '',
        'destinatari'       => post_testo('destinatari') ?? '',
        'modalita'          => post_testo('modalita', 100) ?? '',
        'ore_totali'        => post_intero('ore_totali'),
        'incontri_previsti' => post_intero('incontri_previsti'),
        'min_studenti'      => post_intero('min_studenti'),
        'max_studenti'      => post_intero('max_studenti'),
    ];
    $apertura = post_data_ora('data_apertura');
    $chiusura = post_data_ora('data_chiusura');
    // Rimando a un'altra pagina: slug di un'area del portale oppure indirizzo http(s). Con il rimando niente edizioni/iscrizioni.
    $destinazione = null;
    $dest_tipo = (string)($_POST['dest_tipo'] ?? '');
    if ($dest_tipo === 'url') {
        $u = trim((string)($_POST['dest_url'] ?? ''));
        if ($u !== '' && !preg_match('#^https?://#i', $u)) $u = 'https://' . $u;
        if ($u === '' || !filter_var($u, FILTER_VALIDATE_URL)) { flash_set("Progetto non salvato: l'indirizzo della pagina di destinazione non è valido.", 'danger'); admin_redirect("progetti.php?p_id=$filtro_p&" . ((int)($_POST['evento_id'] ?? 0) ? "id=" . (int)$_POST['evento_id'] : "azione=nuovo")); }
        $destinazione = mb_substr($u, 0, 300);
    } elseif ($dest_tipo !== '') {
        $r_sl = $conn->prepare("SELECT slug FROM pagine_eventi WHERE slug = ? LIMIT 1");
        $r_sl->bind_param("s", $dest_tipo); $r_sl->execute();
        $destinazione = ($row_sl = $r_sl->get_result()->fetch_assoc()) ? $row_sl['slug'] : null;
    }

    $errori = [];
    // Con più edizioni i campi generali sono nascosti: contano quelli delle edizioni, controllati più sotto
    $una_edizione = count((array)($_POST['ed_nome'] ?? [])) <= 1;
    if ($d['data_inizio'] && $d['data_fine'] && $d['data_fine'] < $d['data_inizio']) $errori[] = "la fine del progetto è precedente all'inizio";
    if ($una_edizione && $apertura && $chiusura && $chiusura <= $apertura) $errori[] = "la chiusura delle iscrizioni è precedente all'apertura";
    if ($una_edizione && $d['min_studenti'] !== null && $d['max_studenti'] !== null && $d['min_studenti'] > $d['max_studenti']) $errori[] = "il numero minimo di studenti supera il massimo";
    if ($errori) { flash_set("Progetto non salvato: " . implode('; ', $errori) . ".", 'danger'); admin_redirect($url_form); }

    // Referenti e tutor: righe con almeno nome o email; email non valide scartate e segnalate
    $referenti = leggi_referenti_post($email_scartate);
    // Informazioni aggiuntive: coppie etichetta / valore
    $info_extra = [];
    foreach ((array)($_POST['info_etichetta'] ?? []) as $i => $et) {
        $et = mb_substr(trim((string)$et), 0, 80);
        $va = mb_substr(trim((string)($_POST['info_valore'][$i] ?? '')), 0, 500);
        if ($et === '' || $va === '') continue;
        $info_extra[] = ['etichetta' => $et, 'valore' => $va];
        if (count($info_extra) >= 20) break;
    }
    // Articolazione del percorso: moduli / fasi / incontri (serve almeno il titolo)
    $moduli = []; $somma_ore = 0;
    foreach ((array)($_POST['mod_titolo'] ?? []) as $i => $tit) {
        $m = [
            'titolo'      => mb_substr(trim((string)$tit), 0, 200),
            'ore'         => preg_match('/^\d{1,3}$/', trim((string)($_POST['mod_ore'][$i] ?? ''))) ? (int)$_POST['mod_ore'][$i] : null,
            'modalita'    => mb_substr(trim((string)($_POST['mod_modalita'][$i] ?? '')), 0, 60),
            'sede'        => mb_substr(trim((string)($_POST['mod_sede'][$i] ?? '')), 0, 200),
            'quando'      => mb_substr(trim((string)($_POST['mod_quando'][$i] ?? '')), 0, 100),
            'descrizione' => mb_substr(trim((string)($_POST['mod_desc'][$i] ?? '')), 0, 2000),
        ];
        if ($m['titolo'] === '') continue;
        $moduli[] = $m; $somma_ore += (int)$m['ore'];
        if (count($moduli) >= 30) break;
    }
    // Ore totali non indicate: somma delle ore dei moduli
    if ($d['ore_totali'] === null && $somma_ore > 0) $d['ore_totali'] = $somma_ore;
    // Obiettivi / conoscenze / competenze: testo con editor (come la descrizione)
    $obiettivi  = trim((string)($_POST['obiettivi'] ?? '')) ?: null;
    $conoscenze = trim((string)($_POST['conoscenze'] ?? '')) ?: null;
    $competenze = trim((string)($_POST['competenze'] ?? '')) ?: null;

    // Tipo di progetto: per le scuole (una scuola per edizione, numero di partecipanti) o generico (posti per edizione)
    $per_scuole = isset($_POST['per_scuole']) ? 1 : 0;
    $attestati  = isset($_POST['attestati']) ? 1 : 0;
    $lista_attesa = isset($_POST['lista_attesa']) ? 1 : 0; // vale per tutte le edizioni
    $approvazione = isset($_POST['approvazione']) ? 1 : 0; // iscrizioni confermate dai gestori, per tutte le edizioni
    $convenzione = isset($_POST['convenzione']) ? 1 : 0;   // attività di Formazione Scuola Lavoro: processo delle convenzioni
    if ($convenzione) $per_scuole = 1;                     // FSL è sempre dedicata alle scuole

    // Edizioni (repliche): ogni riga è un turno. ed_id = turno esistente (0 = nuova).
    // Per le scuole ogni edizione ha 1 posto; altrimenti i posti indicati (predefinito 30).
    // Una sola edizione: valgono apertura/chiusura e min/max generali. Più edizioni: ognuna ha i suoi, obbligatori
    // (min/max solo per le scuole).
    $edizioni = [];
    foreach ((array)($_POST['ed_nome'] ?? []) as $i => $nome_ed) {
        $posti_ed = (int)($_POST['ed_posti'][$i] ?? 0);
        $edizioni[] = ['id' => (int)($_POST['ed_id'][$i] ?? 0), 'nome' => mb_substr(trim((string)$nome_ed), 0, 150),
                       'posti' => $per_scuole ? 1 : ($posti_ed > 0 ? min($posti_ed, 9999) : 30),
                       'apertura' => $apertura, 'chiusura' => $chiusura, 'min' => null, 'max' => null,
                       'post' => ['ap' => $_POST['ed_apertura'][$i] ?? '', 'ch' => $_POST['ed_chiusura'][$i] ?? '', 'min' => $_POST['ed_min'][$i] ?? '', 'max' => $_POST['ed_max'][$i] ?? '']];
        if (count($edizioni) >= 20) break;
    }
    if (!$edizioni) $edizioni[] = ['id' => 0, 'nome' => '', 'posti' => $per_scuole ? 1 : 30, 'apertura' => $apertura, 'chiusura' => $chiusura, 'min' => null, 'max' => null];
    $piu_edizioni = count($edizioni) > 1;
    if ($piu_edizioni && $destinazione === null) {
        foreach ($edizioni as $k => &$ed) {
            $nome_err = $ed['nome'] !== '' ? '"' . $ed['nome'] . '"' : 'edizione ' . ($k + 1);
            $ed['apertura'] = data_ora_da($ed['post']['ap']);
            $ed['chiusura'] = data_ora_da($ed['post']['ch']);
            if (!$ed['apertura'] || !$ed['chiusura']) $errori[] = "$nome_err: indica apertura e chiusura delle iscrizioni";
            elseif ($ed['chiusura'] <= $ed['apertura']) $errori[] = "$nome_err: la chiusura delle iscrizioni è precedente all'apertura";
            if ($per_scuole) {
                $ed['min'] = intero_da($ed['post']['min']); $ed['max'] = intero_da($ed['post']['max']);
                if (!$ed['min'] || !$ed['max']) $errori[] = "$nome_err: indica il numero minimo e massimo di partecipanti";
                elseif ($ed['min'] > $ed['max']) $errori[] = "$nome_err: il minimo di partecipanti supera il massimo";
            }
        }
        unset($ed);
        if ($errori) { flash_set("Progetto non salvato: " . implode('; ', $errori) . ".", 'danger'); admin_redirect($url_form); }
    }

    $upload_dir = dirname(__DIR__) . '/uploads/';
    $new_locandina = null; $new_pdf = null;
    if (!empty($_FILES['locandina_file']['name'])) {
        $fn = secure_upload($_FILES['locandina_file'], $upload_dir, ['jpg','jpeg','png','gif','webp'], ['image/jpeg','image/png','image/gif','image/webp']);
        if ($fn) $new_locandina = "uploads/$fn";
    }
    if (!empty($_FILES['allegato_pdf']['name'])) {
        $fn = secure_upload($_FILES['allegato_pdf'], $upload_dir, ['pdf'], ['application/pdf']);
        if ($fn) $new_pdf = "uploads/$fn";
    }

    $notif_extra = normalizza_lista_email($_POST['email_notifiche_extra'] ?? '', 10, $notif_scartati);
    $notif_csv = $notif_extra ? implode(',', $notif_extra) : null;
    $ref_json  = $referenti ? json_encode($referenti, JSON_UNESCAPED_UNICODE) : null;
    $info_json = $info_extra ? json_encode($info_extra, JSON_UNESCAPED_UNICODE) : null;
    $mod_json  = $moduli ? json_encode($moduli, JSON_UNESCAPED_UNICODE) : null;
    $edizioni_non_tolte = [];

    $conn->begin_transaction();
    try {
        if ($ev_id === 0) {
            // Iscrizione solo con accesso (SSO Unical, SPID, CIE): ruolo_accesso_id = -1
            $stmt = $conn->prepare("INSERT INTO eventi (pagina_id, sottocategoria_id, titolo, luogo, descrizione, locandina_path, allegato_pdf, is_evidenza, richiede_prenotazione, abilita_presenze, ruolo_accesso_id, ordine, gestori_utenti_ids, tipo, email_notifiche_extra)
                                    VALUES (?, NULL, ?, ?, ?, ?, ?, ?, 1, ?, -1, ?, '', 'progetto', ?)");
            $loc = $new_locandina ?? '';
            $stmt->bind_param("isssssiiis", $filtro_p, $titolo, $luogo, $desc, $loc, $new_pdf, $evid, $attestati, $ord, $notif_csv); // presenze (check-in) attive se servono gli attestati
            if (!$stmt->execute()) throw new RuntimeException($conn->error);
            $ev_id = (int)$conn->insert_id;
        } else {
            $sql = "UPDATE eventi SET titolo=?, luogo=?, descrizione=?, is_evidenza=?, ordine=?, email_notifiche_extra=?, richiede_prenotazione=1, ruolo_accesso_id=-1, abilita_presenze=IF(? = 1, 1, abilita_presenze)";
            $types = "sssiisi"; $params = [$titolo, $luogo, $desc, $evid, $ord, $notif_csv, $attestati];
            if (isset($_POST['elimina_locandina'])) $sql .= ", locandina_path=''";
            if (isset($_POST['elimina_pdf']))       $sql .= ", allegato_pdf=NULL";
            if ($new_locandina !== null) { $sql .= ", locandina_path=?"; $types .= "s"; $params[] = $new_locandina; }
            if ($new_pdf !== null)       { $sql .= ", allegato_pdf=?";   $types .= "s"; $params[] = $new_pdf; }
            $sql .= " WHERE id=?"; $types .= "i"; $params[] = $ev_id;
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            if (!$stmt->execute()) throw new RuntimeException($conn->error);
        }

        $stmt_d = $conn->prepare("INSERT INTO progetti_dettagli (evento_id, struttura, data_inizio, data_fine, periodo_note, destinatari, modalita, ore_totali, incontri_previsti, min_studenti, max_studenti, referenti_json, info_extra_json, moduli_json, obiettivi, conoscenze, competenze, per_scuole, attestati, updated_at)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                                  ON DUPLICATE KEY UPDATE struttura=VALUES(struttura), data_inizio=VALUES(data_inizio), data_fine=VALUES(data_fine), periodo_note=VALUES(periodo_note),
                                      destinatari=VALUES(destinatari), modalita=VALUES(modalita), ore_totali=VALUES(ore_totali), incontri_previsti=VALUES(incontri_previsti),
                                      min_studenti=VALUES(min_studenti), max_studenti=VALUES(max_studenti),
                                      referenti_json=VALUES(referenti_json), info_extra_json=VALUES(info_extra_json), moduli_json=VALUES(moduli_json),
                                      obiettivi=VALUES(obiettivi), conoscenze=VALUES(conoscenze), competenze=VALUES(competenze),
                                      per_scuole=VALUES(per_scuole), attestati=VALUES(attestati), updated_at=NOW()");
        $stmt_d->bind_param("issssssiiiissssssii", $ev_id, $d['struttura'], $d['data_inizio'], $d['data_fine'], $d['periodo_note'], $d['destinatari'], $d['modalita'],
                            $d['ore_totali'], $d['incontri_previsti'], $d['min_studenti'], $d['max_studenti'], $ref_json, $info_json, $mod_json, $obiettivi, $conoscenze, $competenze, $per_scuole, $attestati);
        if (!$stmt_d->execute()) throw new RuntimeException($conn->error);
        // Corso di studio scelto dall'anagrafe (link alla pagina del corso nella scheda pubblica)
        $corso_sc = corso_studio($conn, (string)($_POST['corso_codice'] ?? ''));
        $corso_cod = $corso_sc['codice'] ?? null;
        $stmt_cs = $conn->prepare("UPDATE progetti_dettagli SET corso_codice = ? WHERE evento_id = ?");
        $stmt_cs->bind_param("si", $corso_cod, $ev_id); $stmt_cs->execute();
        $stmt_dest = $conn->prepare("UPDATE progetti_dettagli SET destinazione = ? WHERE evento_id = ?");
        $stmt_dest->bind_param("si", $destinazione, $ev_id);
        if (!$stmt_dest->execute()) throw new RuntimeException($conn->error);

        $stmt_cv = $conn->prepare("UPDATE progetti_dettagli SET convenzione = ? WHERE evento_id = ?");
        $stmt_cv->bind_param("ii", $convenzione, $ev_id); $stmt_cv->execute();

        // Edizioni = turni di iscrizione (posti: 1 per le scuole), lista d'attesa e approvazione dei gestori se attivate.
        // Ogni turno ha la sua finestra e i suoi limiti (con una sola edizione: quelli generali, limiti NULL = del progetto).
        if ($destinazione === null) {
        $turni_esistenti = [];
        $r_t = $conn->query("SELECT id FROM turni WHERE evento_id = $ev_id ORDER BY id ASC");
        while ($r_t && $rt = $r_t->fetch_assoc()) $turni_esistenti[] = (int)$rt['id'];
        $tenuti = [];
        $stmt_up = $conn->prepare("UPDATE turni SET nome_turno = ?, max_posti = ?, data_apertura = ?, data_chiusura = ?, min_partecipanti = ?, max_partecipanti = ?, abilita_lista_attesa = ?, abilita_multi_posto = 0, richiede_approvazione = ? WHERE id = ?");
        $stmt_in = $conn->prepare("INSERT INTO turni (evento_id, nome_turno, data_turno, orario_inizio, orario_fine, max_posti, data_apertura, data_chiusura, min_partecipanti, max_partecipanti, abilita_lista_attesa, abilita_multi_posto, richiede_approvazione)
                                   VALUES (?, ?, NULL, NULL, NULL, ?, ?, ?, ?, ?, ?, 0, ?)");
        $n_ed = count($edizioni);
        foreach ($edizioni as $k => $ed) {
            // Nome dell'edizione: quello scritto, altrimenti "Edizione N" (o "Iscrizioni" se è l'unica)
            $nome_ed = $ed['nome'] !== '' ? $ed['nome'] : ($n_ed > 1 ? 'Edizione ' . ($k + 1) : 'Iscrizioni');
            if ($ed['id'] > 0 && in_array($ed['id'], $turni_esistenti, true)) {
                $stmt_up->bind_param("sissiiiii", $nome_ed, $ed['posti'], $ed['apertura'], $ed['chiusura'], $ed['min'], $ed['max'], $lista_attesa, $approvazione, $ed['id']);
                if (!$stmt_up->execute()) throw new RuntimeException($conn->error);
                $tenuti[] = $ed['id'];
            } else {
                $stmt_in->bind_param("isissiiii", $ev_id, $nome_ed, $ed['posti'], $ed['apertura'], $ed['chiusura'], $ed['min'], $ed['max'], $lista_attesa, $approvazione);
                if (!$stmt_in->execute()) throw new RuntimeException($conn->error);
                $tenuti[] = (int)$conn->insert_id;
            }
        }
        // Edizioni tolte dalla maschera: eliminate solo se nessuno è iscritto o in attesa (restano con le loro date)
        foreach (array_diff($turni_esistenti, $tenuti) as $t_via) {
            $r_n = $conn->query("SELECT COUNT(*) AS n FROM prenotazioni WHERE turno_id = $t_via AND IFNULL(stato, 'confermata') NOT IN ('annullata', 'rifiutata', 'scaduta')");
            if ($r_n && (int)$r_n->fetch_assoc()['n'] > 0) {
                $r_nome = $conn->query("SELECT nome_turno FROM turni WHERE id = $t_via");
                $edizioni_non_tolte[] = (string)($r_nome ? $r_nome->fetch_assoc()['nome_turno'] : $t_via);
                continue;
            }
            elimina_turno($conn, $t_via);
        }
        } // fine edizioni (solo progetti senza rimando)

        assicura_campi_progetto($conn, $filtro_p);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    $avvisi = [];
    if ($notif_scartati) $avvisi[] = "indirizzi per le notifiche non validi ignorati: " . implode(', ', $notif_scartati);
    if ($email_scartate) $avvisi[] = "email dei referenti non valide ignorate: " . implode(', ', $email_scartate);
    if (!$lista_attesa) {
        $r_att = $conn->query("SELECT COUNT(*) AS n FROM prenotazioni p JOIN turni t ON p.turno_id = t.id WHERE t.evento_id = $ev_id AND p.stato = 'in_attesa'");
        $n_att = $r_att ? (int)$r_att->fetch_assoc()['n'] : 0;
        if ($n_att > 0) $avvisi[] = "lista d'attesa disattivata, ma $n_att " . ($n_att === 1 ? "iscrizione è" : "iscrizioni sono") . " già in attesa: restano in coda finché non le annulli da Iscrizioni";
    }
    if ($edizioni_non_tolte) $avvisi[] = "non ho eliminato le edizioni con iscritti o persone in attesa (" . implode(', ', $edizioni_non_tolte) . "): annulla prima le loro iscrizioni";
    registra_log_audit($conn, (int)($_POST['evento_id'] ?? 0) ? "Modifica Progetto" : "Creazione Progetto", ["Evento ID" => $ev_id, "Titolo" => $titolo]);
    flash_set("Progetto salvato." . ($avvisi ? " Attenzione: " . implode('; ', $avvisi) . "." : ''), $avvisi ? 'warning' : 'success');
    admin_redirect("progetti.php?p_id=$filtro_p");
}

if (isset($_POST['duplica_progetto'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $ev_id = (int)$_POST['duplica_progetto'];
    if (!$puo_creare || !progetto_autorizzato($conn, $ev_id, $filtro_p, $sql_filtro_eventi_rbac)) nega_accesso();
    $copia = duplica_evento($conn, $ev_id, true);
    registra_log_audit($conn, "Duplicazione Progetto", ["Progetto origine" => $ev_id, "Nuovo progetto" => $copia['evento']]);
    flash_set("Progetto duplicato senza iscrizioni: controlla titolo e date della copia.");
    admin_redirect("progetti.php?p_id=$filtro_p&id=" . (int)$copia['evento']);
}
if (isset($_POST['archivia_progetto'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $ev_id = (int)$_POST['archivia_progetto'];
    if (!$can_manage_settings || !progetto_autorizzato($conn, $ev_id, $filtro_p, $sql_filtro_eventi_rbac)) nega_accesso();
    $conn->query("UPDATE eventi SET archiviato = 1, blocca_auto_archivio = 0 WHERE id = $ev_id");
    registra_log_audit($conn, "Archiviazione Progetto", ["Evento ID" => $ev_id]);
    flash_set("Progetto archiviato: lo trovi in Archivio Storico.");
    admin_redirect("progetti.php?p_id=$filtro_p");
}
if (isset($_POST['elimina_progetto'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $ev_id = (int)$_POST['elimina_progetto'];
    if (!$can_manage_settings || !progetto_autorizzato($conn, $ev_id, $filtro_p, $sql_filtro_eventi_rbac)) nega_accesso();
    $ok = elimina_evento($conn, $ev_id);
    registra_log_audit($conn, "Eliminazione Progetto", ["Evento ID" => $ev_id]);
    flash_set($ok ? "Progetto eliminato." : "Eliminazione non riuscita: riprova.", $ok ? 'success' : 'danger');
    admin_redirect("progetti.php?p_id=$filtro_p");
}

if (isset($_POST['salva_ordine_progetti'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    if (!$puo_creare) nega_accesso();
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ordine_ids'] ?? [])))));
    $stmt_o = $conn->prepare("UPDATE eventi SET ordine = ? WHERE id = ? AND pagina_id = ? AND tipo = 'progetto'");
    foreach ($ids as $pos => $id_o) {
        $n = $pos + 1;
        $stmt_o->bind_param("iii", $n, $id_o, $filtro_p);
        $stmt_o->execute();
    }
    $stmt_o->close();
    registra_log_audit($conn, "Ordine Progetti", ["Area" => $filtro_p, "Progetti" => count($ids)]);
    flash_set("Ordine dei progetti salvato: la pagina pubblica li mostra in questa sequenza.");
    admin_redirect("progetti.php?p_id=$filtro_p");
}

$col_area = colore_valido($page_cfg['colore_primario'] ?? '', '#0056B3');
$txt_area = colore_testo_su($col_area);
$slug_area = $page_cfg['slug'] ?? '';
$layout_progetti = ($page_cfg['layout_template'] ?? '') === 'progetti';

// =====================================================================
// FORM (nuovo o modifica)
// =====================================================================
$id_modifica = (int)($_GET['id'] ?? 0);
$mostra_form = ($_GET['azione'] ?? '') === 'nuovo' || $id_modifica > 0;

if ($mostra_form):
    if ($id_modifica > 0) {
        if (!progetto_autorizzato($conn, $id_modifica, $filtro_p, $sql_filtro_eventi_rbac)) nega_accesso();
        $ev = $conn->query("SELECT * FROM eventi WHERE id = $id_modifica")->fetch_assoc();
        $dp = get_dettagli_progetti($conn, [$id_modifica])[$id_modifica] ?? [];
        $turni_ed = [];
        $r_t = $conn->query("SELECT t.*, (SELECT COUNT(*) FROM prenotazioni p WHERE p.turno_id = t.id AND IFNULL(p.stato, 'confermata') NOT IN ('annullata', 'rifiutata', 'scaduta')) AS n_iscr FROM turni t WHERE t.evento_id = $id_modifica ORDER BY t.id ASC");
        while ($r_t && $rt = $r_t->fetch_assoc()) $turni_ed[] = $rt;
        $tu = $turni_ed[0] ?? [];
    } else {
        if (!$puo_creare) nega_accesso();
        $ev = []; $dp = []; $tu = []; $turni_ed = [];
    }
    if (!$turni_ed) $turni_ed = [['id' => 0, 'nome_turno' => '', 'n_iscr' => 0]];
    $moduli = $dp['moduli'] ?? [];
    if (!$moduli) $moduli = [[]];
    $v  = fn($a, $k) => h((string)($a[$k] ?? ''));
    $dt = fn($x) => !empty($x) ? date('Y-m-d\TH:i', strtotime($x)) : '';
    $referenti  = $dp['referenti'] ?? [];
    $info_extra = $dp['info_extra'] ?? [];
    if (!$referenti) $referenti = [['ruolo' => 'Referente CdL', 'notifiche' => 1], ['ruolo' => 'Responsabile Unical', 'notifiche' => 0]];
?>
<style>
.pj-sez { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:1.25rem 1.25rem .75rem; margin-bottom:1rem; box-shadow:0 1px 4px rgba(0,0,0,.04); }
.pj-sez h2 { font-size:.8rem; text-transform:uppercase; letter-spacing:.06em; font-weight:800; color:<?php echo h($col_area); ?>; margin-bottom:1rem; }
.pj-sez .form-text { font-size:.76rem; }
.pj-riga { display:grid; grid-template-columns: 150px 1fr 1fr 150px 38px; gap:.5rem; margin-bottom:.5rem; }
.pj-riga-info { display:grid; grid-template-columns: 220px 1fr 38px; gap:.5rem; margin-bottom:.5rem; }
.pj-ref { padding-bottom:.6rem; margin-bottom:.6rem; border-bottom:1px dashed #e2e8f0; }
.pj-ref .pj-riga { margin-bottom:.4rem; }
.pj-mod { padding-bottom:.6rem; margin-bottom:.6rem; border-bottom:1px dashed #e2e8f0; display:grid; gap:.4rem; }
.pj-mod-riga { display:grid; grid-template-columns: 1fr 80px 170px 38px; gap:.5rem; }
.pj-mod-riga2 { display:grid; grid-template-columns: 1fr 1fr; gap:.5rem; padding-right:46px; }
.pj-form-generico .pj-solo-scuole, .pj-form-scuole .pj-solo-generico { display:none !important; }
.pj-ed { display:flex; gap:.5rem; align-items:center; margin-bottom:.5rem; }
.pj-ed-blocco + .pj-ed-blocco { border-top:1px dashed #e2e8f0; padding-top:.5rem; }
.pj-ed-campi { display:grid; grid-template-columns:1fr 1fr; gap:.4rem .5rem; margin-bottom:.6rem; }
.pj-ed-campi label { display:flex; flex-direction:column; gap:.15rem; margin:0; }
.pj-form-una-ed .pj-ed-campi, .pj-form-una-ed .pj-solo-piu-ed, .pj-form-piu-ed .pj-solo-una-ed { display:none !important; }
.pj-riga-2 { display:grid; grid-template-columns: 1fr auto auto; gap:.75rem; align-items:center; padding-right:46px; }
.pj-form-dest .pj-no-dest { display:none !important; }
@media (max-width: 767.98px) { .pj-riga, .pj-riga-info, .pj-riga-2, .pj-mod-riga, .pj-mod-riga2 { grid-template-columns: 1fr; padding-right:0; } .pj-riga-info { padding-bottom:.5rem; border-bottom:1px dashed #e2e8f0; } }
</style>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <h4 class="fw-bold text-dark mb-0"><i class="fa fa-diagram-project me-2" style="color:<?php echo h($col_area); ?>" aria-hidden="true"></i><?php echo $id_modifica ? 'Modifica progetto' : 'Nuovo progetto'; ?></h4>
    <a href="progetti.php?p_id=<?php echo $filtro_p; ?>" class="btn btn-outline-secondary btn-sm fw-bold"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i>Torna ai progetti</a>
</div>

<form method="POST" enctype="multipart/form-data" onsubmit="if (window.tinymce) tinymce.triggerSave();">
    <?php csrf_field(); ?>
    <input type="hidden" name="evento_id" value="<?php echo $id_modifica; ?>">
    <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">

    <div class="row g-3">
        <div class="col-xl-8">
            <section class="pj-sez">
                <h2><i class="fa fa-circle-info me-1" aria-hidden="true"></i>Dati generali</h2>
                <div class="row g-3">
                    <div class="col-12">
                        <label for="pjTitolo" class="form-label small fw-bold">Titolo del progetto <span class="text-danger">*</span></label>
                        <input type="text" name="titolo" id="pjTitolo" class="form-control" value="<?php echo $v($ev, 'titolo'); ?>" maxlength="255" required>
                    </div>
                    <div class="col-md-7">
                        <label for="pjStruttura" class="form-label small fw-bold">Corso di laurea / Struttura</label>
                        <?php echo html_scelta_corso_scheda($conn, (string)($dp['corso_codice'] ?? ''), (string)($dp['struttura'] ?? ''), 'pjStruttura'); ?>
                    </div>
                    <div class="col-md-5">
                        <label for="pjLuogo" class="form-label small fw-bold">Sede</label>
                        <input type="text" name="luogo" id="pjLuogo" class="form-control form-control-sm" value="<?php echo $v($ev, 'luogo'); ?>" placeholder="es. Cubo 4B, Unical" maxlength="255">
                    </div>
                    <div class="col-12">
                        <label for="pjDesc" class="form-label small fw-bold">Descrizione (obiettivi, moduli, calendario, contatti…)</label>
                        <textarea name="descrizione" id="pjDesc" class="form-control editor-html" rows="10"><?php echo h($ev['descrizione'] ?? ''); ?></textarea>
                    </div>
                </div>
            </section>

            <section class="pj-sez">
                <h2><i class="fa fa-list-ul me-1" aria-hidden="true"></i>Dettagli del progetto</h2>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="pjDest" class="form-label small fw-bold">Requisiti di accesso (classi ammesse)</label>
                        <input type="text" name="destinatari" id="pjDest" class="form-control form-control-sm" value="<?php echo $v($dp, 'destinatari'); ?>" placeholder="es. Classi 3ª, 4ª e 5ª" maxlength="255">
                    </div>
                    <div class="col-md-6">
                        <label for="pjMod" class="form-label small fw-bold">Modalità</label>
                        <input type="text" name="modalita" id="pjMod" class="form-control form-control-sm" value="<?php echo $v($dp, 'modalita'); ?>" list="pjModalita" placeholder="es. In presenza" maxlength="100">
                        <datalist id="pjModalita"><option value="In presenza"><option value="Online"><option value="Mista (presenza e online)"></datalist>
                    </div>
                    <div class="col-6 col-md-3">
                        <label for="pjOre" class="form-label small fw-bold">Ore totali</label>
                        <input type="number" name="ore_totali" id="pjOre" class="form-control form-control-sm" min="1" value="<?php echo $v($dp, 'ore_totali'); ?>">
                    </div>
                    <div class="col-6 col-md-3">
                        <label for="pjInc" class="form-label small fw-bold">Incontri previsti</label>
                        <input type="number" name="incontri_previsti" id="pjInc" class="form-control form-control-sm" min="1" value="<?php echo $v($dp, 'incontri_previsti'); ?>">
                    </div>
                    <div class="col-6 col-md-3 pj-solo-scuole pj-solo-una-ed">
                        <label for="pjMin" class="form-label small fw-bold">Partecipanti per iscrizione: min</label>
                        <input type="number" name="min_studenti" id="pjMin" class="form-control form-control-sm" min="1" value="<?php echo $v($dp, 'min_studenti'); ?>">
                    </div>
                    <div class="col-6 col-md-3 pj-solo-scuole pj-solo-una-ed">
                        <label for="pjMax" class="form-label small fw-bold">Partecipanti per iscrizione: max</label>
                        <input type="number" name="max_studenti" id="pjMax" class="form-control form-control-sm" min="1" value="<?php echo $v($dp, 'max_studenti'); ?>">
                    </div>
                    <div class="col-12 pj-solo-scuole"><div class="form-text mt-0">Chi iscrive il gruppo indica nel modulo il numero di partecipanti: il portale controlla che stia tra il minimo e il massimo<span class="pj-solo-piu-ed"> dell'edizione scelta (li imposti in "Edizioni e iscrizione")</span>.</div></div>
                </div>
            </section>

            <section class="pj-sez">
                <h2><i class="fa fa-route me-1" aria-hidden="true"></i>Articolazione del percorso (moduli, fasi, incontri)</h2>
                <p class="form-text mt-0 mb-2">Una riga per modulo, fase, incontro o attività. Nella scheda diventano le tappe del percorso. Se non compili "Ore totali", il portale somma le ore dei moduli.</p>
                <div id="pjModuli">
                    <?php foreach ($moduli as $m): ?>
                        <div class="pj-mod">
                            <div class="pj-mod-riga">
                                <input type="text" name="mod_titolo[]" class="form-control form-control-sm" value="<?php echo h($m['titolo'] ?? ''); ?>" placeholder="Titolo (es. Incontro 1: Introduzione alla cartografia)" aria-label="Titolo del modulo" maxlength="200">
                                <input type="number" name="mod_ore[]" class="form-control form-control-sm" value="<?php echo h((string)($m['ore'] ?? '')); ?>" placeholder="Ore" aria-label="Ore" min="0" max="999">
                                <input type="text" name="mod_modalita[]" class="form-control form-control-sm" value="<?php echo h($m['modalita'] ?? ''); ?>" list="pjModalitaMod" placeholder="Modalità" aria-label="Modalità" maxlength="60">
                                <button type="button" class="btn btn-sm btn-outline-danger pj-rimuovi" title="Rimuovi" aria-label="Rimuovi modulo"><i class="fa fa-times" aria-hidden="true"></i></button>
                            </div>
                            <div class="pj-mod-riga2">
                                <input type="text" name="mod_sede[]" class="form-control form-control-sm" value="<?php echo h($m['sede'] ?? ''); ?>" placeholder="Sede (es. Laboratorio di Cartografia DiBEST)" aria-label="Sede" maxlength="200">
                                <input type="text" name="mod_quando[]" class="form-control form-control-sm" value="<?php echo h($m['quando'] ?? ''); ?>" placeholder="Quando (es. 26/10/2026, ottobre 2026, da definire)" aria-label="Quando" maxlength="100">
                            </div>
                            <textarea name="mod_desc[]" class="form-control form-control-sm" rows="2" placeholder="Breve descrizione (facoltativa)" aria-label="Descrizione del modulo" maxlength="2000"><?php echo h($m['descrizione'] ?? ''); ?></textarea>
                        </div>
                    <?php endforeach; ?>
                </div>
                <datalist id="pjModalitaMod"><option value="Online"><option value="In presenza"><option value="Laboratorio"><option value="Online / presenza"><option value="Uscita sul campo"><option value="A scuola"></datalist>
                <button type="button" class="btn btn-sm btn-outline-secondary fw-bold mb-2" data-pj-aggiungi="pjModuli"><i class="fa fa-plus me-1" aria-hidden="true"></i>Aggiungi modulo</button>
            </section>

            <section class="pj-sez">
                <h2><i class="fa fa-bullseye me-1" aria-hidden="true"></i>Obiettivi, conoscenze e competenze</h2>
                <p class="form-text mt-0 mb-2">Facoltativi: se compilati compaiono come riquadri separati nella scheda.</p>
                <label for="pjObi" class="form-label small fw-bold">Obiettivi formativi</label>
                <textarea name="obiettivi" id="pjObi" class="form-control editor-html mb-3" rows="4"><?php echo h($dp['obiettivi'] ?? ''); ?></textarea>
                <label for="pjCon" class="form-label small fw-bold mt-3">Conoscenze</label>
                <textarea name="conoscenze" id="pjCon" class="form-control editor-html mb-3" rows="4"><?php echo h($dp['conoscenze'] ?? ''); ?></textarea>
                <label for="pjComp" class="form-label small fw-bold mt-3">Competenze attese</label>
                <textarea name="competenze" id="pjComp" class="form-control editor-html" rows="4"><?php echo h($dp['competenze'] ?? ''); ?></textarea>
            </section>

            <section class="pj-sez">
                <h2><i class="fa fa-address-book me-1" aria-hidden="true"></i>Referenti, responsabili e relatori</h2>
                <p class="form-text mt-0 mb-2">Il link alla pagina personale rende cliccabile il nome nella scheda. Chi ha <strong>"Riceve le iscrizioni"</strong> attivo riceve per email il riepilogo di ogni iscrizione e disdetta della scuola (serve l'email).</p>
                <?php echo html_ricerca_personale($conn, "Aggiungi", "pjReferenti"); ?>
                <div class="d-none d-md-grid pj-riga small fw-bold text-secondary mb-1"><span>Ruolo</span><span>Nome e cognome</span><span>Email</span><span>Telefono</span><span></span></div>
                <div id="pjReferenti">
                    <?php foreach ($referenti as $r): $notif_r = !empty($r['notifiche']); ?>
                        <div class="pj-ref">
                            <div class="pj-riga">
                                <input type="text" name="ref_ruolo[]" class="form-control form-control-sm" value="<?php echo h($r['ruolo'] ?? ''); ?>" list="pjRuoli" placeholder="Ruolo" aria-label="Ruolo">
                                <input type="text" name="ref_nome[]" class="form-control form-control-sm" value="<?php echo h($r['nome'] ?? ''); ?>" placeholder="Nome e cognome" aria-label="Nome e cognome">
                                <input type="email" name="ref_email[]" class="form-control form-control-sm" value="<?php echo h($r['email'] ?? ''); ?>" placeholder="nome@unical.it" aria-label="Email">
                                <input type="tel" name="ref_tel[]" class="form-control form-control-sm" value="<?php echo h($r['telefono'] ?? ''); ?>" placeholder="Telefono (facoltativo)" aria-label="Telefono">
                                <button type="button" class="btn btn-sm btn-outline-danger pj-rimuovi" title="Rimuovi" aria-label="Rimuovi persona"><i class="fa fa-times" aria-hidden="true"></i></button>
                            </div>
                            <div class="pj-riga-2">
                                <input type="url" name="ref_link[]" class="form-control form-control-sm" value="<?php echo h($r['link'] ?? ''); ?>" placeholder="Link alla pagina personale (facoltativo), es. https://www.unical.it/..." aria-label="Link alla pagina personale">
                                <input type="hidden" name="ref_persona[]" value="<?php echo h($r['persona_id'] ?? ''); ?>">
                                <span class="badge ref-anag text-nowrap" style="background:#e0f2fe;color:#075985;" title="Scelto dall'anagrafe di Ateneo: il nome porta alla sua pagina nel portale" <?php echo empty($r['persona_id']) ? 'hidden' : ''; ?>><i class="fa fa-address-book me-1" aria-hidden="true"></i>Anagrafe</span>
                                <input type="hidden" name="ref_notifiche[]" value="<?php echo $notif_r ? '1' : '0'; ?>">
                                <label class="form-check form-switch m-0 small fw-bold text-nowrap"><input class="form-check-input pj-notif" type="checkbox" <?php echo $notif_r ? 'checked' : ''; ?>> Riceve le iscrizioni</label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <datalist id="pjRuoli"><option value="Referente CdL"><option value="Responsabile Unical"><option value="Relatore"><option value="Tutor"><option value="Referente"><option value="Segreteria"></datalist>
                <button type="button" class="btn btn-sm btn-outline-secondary fw-bold mb-2" data-pj-aggiungi="pjReferenti"><i class="fa fa-plus me-1" aria-hidden="true"></i>Aggiungi persona</button>
            </section>

            <section class="pj-sez">
                <h2><i class="fa fa-table-list me-1" aria-hidden="true"></i>Informazioni aggiuntive</h2>
                <p class="form-text mt-0 mb-2">Voci libere mostrate nella scheda del progetto (es. "Attestato: rilasciato a fine percorso", "Materiale: a cura del dipartimento").</p>
                <div id="pjInfo">
                    <?php foreach (($info_extra ?: [['etichetta' => '', 'valore' => '']]) as $ie): ?>
                        <div class="pj-riga-info">
                            <input type="text" name="info_etichetta[]" class="form-control form-control-sm" value="<?php echo h($ie['etichetta'] ?? ''); ?>" placeholder="Voce" aria-label="Voce" maxlength="80">
                            <input type="text" name="info_valore[]" class="form-control form-control-sm" value="<?php echo h($ie['valore'] ?? ''); ?>" placeholder="Valore" aria-label="Valore" maxlength="500">
                            <button type="button" class="btn btn-sm btn-outline-danger pj-rimuovi" title="Rimuovi" aria-label="Rimuovi riga"><i class="fa fa-times" aria-hidden="true"></i></button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary fw-bold mb-2" data-pj-aggiungi="pjInfo"><i class="fa fa-plus me-1" aria-hidden="true"></i>Aggiungi voce</button>
            </section>
        </div>

        <div class="col-xl-4">
            <?php
            $dest_val = trim((string)($dp['destinazione'] ?? ''));
            $aree_dest = [];
            $r_ad = $conn->query("SELECT slug, titolo FROM pagine_eventi WHERE id <> " . (int)$filtro_p . " ORDER BY ordine ASC, titolo ASC");
            while ($r_ad && $ad = $r_ad->fetch_assoc()) $aree_dest[$ad['slug']] = $ad['titolo'];
            $dest_sel = $dest_val === '' ? '' : (isset($aree_dest[preg_replace('/\.php$/i', '', $dest_val)]) ? preg_replace('/\.php$/i', '', $dest_val) : 'url');
            ?>
            <section class="pj-sez" style="border-left:4px solid #0284c7;">
                <h2><i class="fa fa-share-from-square me-1" aria-hidden="true"></i>Rimando a un'altra pagina</h2>
                <label for="pjDestTipo" class="form-label small fw-bold">Il pulsante del progetto porta a</label>
                <select name="dest_tipo" id="pjDestTipo" class="form-select form-select-sm">
                    <option value="">Nessun rimando: scheda e iscrizioni del progetto</option>
                    <?php foreach ($aree_dest as $slug_d => $tit_d): ?><option value="<?php echo h($slug_d); ?>" <?php echo $dest_sel === $slug_d ? 'selected' : ''; ?>>Pagina dell'area: <?php echo h($tit_d); ?></option><?php endforeach; ?>
                    <option value="url" <?php echo $dest_sel === 'url' ? 'selected' : ''; ?>>Un altro indirizzo…</option>
                </select>
                <input type="url" name="dest_url" id="pjDestUrl" class="form-control form-control-sm mt-2 <?php echo $dest_sel === 'url' ? '' : 'd-none'; ?>" value="<?php echo $dest_sel === 'url' ? h($dest_val) : ''; ?>" placeholder="https://..." aria-label="Indirizzo della pagina di destinazione">
                <p class="form-text mt-2 mb-0">La card resta nell'elenco dei progetti, ma il pulsante diventa <strong>"Vai a …"</strong> e porta alla pagina scelta (anche chi apre la scheda del progetto viene portato lì). Niente edizioni né iscrizioni qui: si prenota sulla pagina di destinazione.</p>
            </section>

            <section class="pj-sez pj-no-dest" style="border-left:4px solid <?php echo h($col_area); ?>;">
                <h2><i class="fa fa-toggle-on me-1" aria-hidden="true"></i>Tipo di progetto</h2>
                <?php $per_scuole_v = !isset($dp['per_scuole']) || (int)$dp['per_scuole'] === 1; ?>
                <div class="form-check form-switch mb-1">
                    <input class="form-check-input" type="checkbox" name="per_scuole" id="pjScuole" value="1" <?php echo $per_scuole_v ? 'checked' : ''; ?>>
                    <label class="form-check-label small fw-bold" for="pjScuole">Dedicato alle scuole</label>
                </div>
                <p class="form-text mt-0 mb-3">Acceso: si iscrive il docente per la sua scuola, una scuola per edizione, indicando il numero di studenti. Spento: iscrizioni singole, con i posti di ogni edizione.</p>
                <div class="form-check form-switch mb-1">
                    <input class="form-check-input" type="checkbox" name="attestati" id="pjAttestati" value="1" <?php echo !empty($dp['attestati']) ? 'checked' : ''; ?>>
                    <label class="form-check-label small fw-bold" for="pjAttestati">Prevedi attestati di partecipazione</label>
                </div>
                <p class="form-text mt-0 mb-0"><span class="pj-solo-scuole">Il docente inserisce l'elenco degli studenti; a progetto concluso riceve per email gli attestati di tutta la classe, con codice di verifica.</span><span class="pj-solo-generico">A progetto concluso ogni partecipante presente riceve il suo attestato, con codice di verifica.</span> Serve la presenza segnata in Iscrizioni.</p>
            </section>

            <section class="pj-sez">
                <h2><i class="fa fa-calendar-days me-1" aria-hidden="true"></i>Durata del progetto</h2>
                <div class="row g-2">
                    <div class="col-6"><label for="pjIni" class="form-label small fw-bold">Dal</label><input type="date" name="data_inizio" id="pjIni" class="form-control form-control-sm" value="<?php echo $v($dp, 'data_inizio'); ?>"></div>
                    <div class="col-6"><label for="pjFin" class="form-label small fw-bold">Al</label><input type="date" name="data_fine" id="pjFin" class="form-control form-control-sm" value="<?php echo $v($dp, 'data_fine'); ?>"></div>
                    <div class="col-12">
                        <label for="pjNote" class="form-label small fw-bold">Note sul periodo</label>
                        <input type="text" name="periodo_note" id="pjNote" class="form-control form-control-sm" value="<?php echo $v($dp, 'periodo_note'); ?>" placeholder="es. Calendario da concordare con la scuola" maxlength="255">
                        <div class="form-text">Senza date il progetto compare come <strong>"Date da definire"</strong>.</div>
                    </div>
                </div>
            </section>

            <section class="pj-sez pj-no-dest">
                <h2><i class="fa fa-clone me-1" aria-hidden="true"></i>Edizioni e iscrizione</h2>
                <p class="form-text mt-0 mb-2">Una riga per edizione (le "repliche"). <span class="pj-solo-scuole">Nei progetti per le scuole ogni edizione accoglie <strong>una scuola</strong>.</span><span class="pj-solo-generico">Indica i <strong>posti</strong> di ogni edizione.</span> Con più edizioni si sceglie quella preferita.</p>
                <?php $lista_v = $id_modifica ? (int)($tu['abilita_lista_attesa'] ?? 1) === 1 : true; ?>
                <div class="form-check form-switch mb-1">
                    <input class="form-check-input" type="checkbox" name="lista_attesa" id="pjLista" value="1" <?php echo $lista_v ? 'checked' : ''; ?>>
                    <label class="form-check-label small fw-bold" for="pjLista">Lista d'attesa</label>
                </div>
                <p class="form-text mt-0 mb-3">Acceso: quando un'edizione è piena <span class="pj-solo-scuole">le altre scuole</span><span class="pj-solo-generico">gli altri</span> entrano in lista d'attesa, in ordine di arrivo, e ricevono il posto se si libera. Spento: a edizione piena le iscrizioni si chiudono.</p>
                <?php $appr_v = $id_modifica && (int)($tu['richiede_approvazione'] ?? 0) === 1; ?>
                <div class="form-check form-switch mb-1">
                    <input class="form-check-input" type="checkbox" name="approvazione" id="pjApprov" value="1" <?php echo $appr_v ? 'checked' : ''; ?>>
                    <label class="form-check-label small fw-bold" for="pjApprov">Iscrizioni da confermare dai gestori</label>
                </div>
                <p class="form-text mt-0 mb-3">Acceso: ogni iscrizione (di tutte le edizioni) resta <strong>da approvare</strong> finché un gestore non la conferma da Iscrizioni; il posto resta occupato nel frattempo.</p>
                <?php $conv_v = !empty($dp['convenzione']); ?>
                <div class="form-check form-switch mb-1">
                    <input class="form-check-input" type="checkbox" name="convenzione" id="pjConv" value="1" <?php echo $conv_v ? 'checked' : ''; ?>>
                    <label class="form-check-label small fw-bold" for="pjConv"><i class="fa fa-file-signature me-1" aria-hidden="true"></i>Attività di Formazione Scuola Lavoro</label>
                </div>
                <p class="form-text mt-0 mb-3">Attiva il processo delle convenzioni: nel modulo la scuola dichiara se ha la convenzione con il Dipartimento, che deve coprire tutto il periodo del progetto (Dal/Al, registro in Formazione Scuola Lavoro → Convenzioni). Con una convenzione valida l'iscrizione è confermata (o da approvare, se è acceso l'interruttore sopra); senza, resta da approvare con le istruzioni per inviarla (modelli e PEC in Impostazioni area). Promemoria e avvisi partono da soli. Include "Dedicato alle scuole".</p>
                <script>
                (function () {
                    var fsl = document.getElementById('pjConv'), scu = document.getElementById('pjScuole');
                    if (!fsl || !scu) return;
                    fsl.addEventListener('change', function () { if (fsl.checked && !scu.checked) { scu.checked = true; scu.dispatchEvent(new Event('change', { bubbles: true })); } });
                })();
                </script>
                <div id="pjEdizioni">
                    <?php foreach ($turni_ed as $k => $te): ?>
                        <div class="pj-ed-blocco">
                            <div class="pj-ed">
                                <input type="hidden" name="ed_id[]" value="<?php echo (int)$te['id']; ?>">
                                <input type="text" name="ed_nome[]" class="form-control form-control-sm" value="<?php echo h($te['nome_turno'] ?? ''); ?>" placeholder="es. Edizione 1 – ottobre 2026" aria-label="Nome dell'edizione" maxlength="150">
                                <input type="number" name="ed_posti[]" class="form-control form-control-sm pj-solo-generico" style="max-width:90px;" value="<?php echo (int)($te['max_posti'] ?? 0) > 1 ? (int)$te['max_posti'] : 30; ?>" min="1" max="9999" aria-label="Posti dell'edizione" title="Posti">
                                <?php if ((int)($te['n_iscr'] ?? 0) > 0): ?><span class="badge bg-success-subtle text-success-emphasis" title="Iscritti o in attesa"><i class="fa fa-user-check" aria-hidden="true"></i> <?php echo (int)$te['n_iscr']; ?></span><?php endif; ?>
                                <button type="button" class="btn btn-sm btn-outline-danger pj-rimuovi" title="Rimuovi edizione" aria-label="Rimuovi edizione"><i class="fa fa-times" aria-hidden="true"></i></button>
                            </div>
                            <div class="pj-ed-campi">
                                <label class="small fw-bold">Apertura iscrizioni *<input type="datetime-local" name="ed_apertura[]" class="form-control form-control-sm pj-ed-obbl" data-generale="pjAp" value="<?php echo $dt($te['data_apertura'] ?? ''); ?>"></label>
                                <label class="small fw-bold">Chiusura iscrizioni *<input type="datetime-local" name="ed_chiusura[]" class="form-control form-control-sm pj-ed-obbl" data-generale="pjCh" value="<?php echo $dt($te['data_chiusura'] ?? ''); ?>"></label>
                                <label class="small fw-bold pj-solo-scuole">Partecipanti min *<input type="number" name="ed_min[]" class="form-control form-control-sm pj-ed-obbl pj-ed-scuole" data-generale="pjMin" min="1" value="<?php echo (int)($te['min_partecipanti'] ?? 0) ?: ''; ?>"></label>
                                <label class="small fw-bold pj-solo-scuole">Partecipanti max *<input type="number" name="ed_max[]" class="form-control form-control-sm pj-ed-obbl pj-ed-scuole" data-generale="pjMax" min="1" value="<?php echo (int)($te['max_partecipanti'] ?? 0) ?: ''; ?>"></label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary fw-bold mb-3" data-pj-aggiungi="pjEdizioni"><i class="fa fa-plus me-1" aria-hidden="true"></i>Aggiungi edizione</button>
                <div class="row g-2 pj-solo-una-ed">
                    <div class="col-12"><label for="pjAp" class="form-label small fw-bold">Apertura iscrizioni</label><input type="datetime-local" name="data_apertura" id="pjAp" class="form-control form-control-sm" value="<?php echo $dt($tu['data_apertura'] ?? ''); ?>"></div>
                    <div class="col-12"><label for="pjCh" class="form-label small fw-bold">Chiusura iscrizioni</label><input type="datetime-local" name="data_chiusura" id="pjCh" class="form-control form-control-sm" value="<?php echo $dt($tu['data_chiusura'] ?? ''); ?>"></div>
                </div>
                <p class="form-text pj-solo-piu-ed mt-0">Con più edizioni apertura, chiusura<span class="pj-solo-scuole"> e numero di partecipanti</span> si indicano per ogni edizione e sono obbligatori.</p>
                <div class="alert alert-light border small mt-3 mb-2">
                    <i class="fa fa-circle-info me-1" aria-hidden="true"></i>
                    Le iscrizioni vanno in <strong>ordine di arrivo</strong>; ci si può iscrivere a <strong>una sola edizione</strong> dello stesso progetto.
                    Per iscriversi serve l'accesso con <strong>SPID, CIE o credenziali Unical</strong>.
                    Le domande del modulo (es. scuola, classe, contatti) si impostano dal <a href="form_builder.php?p_id=<?php echo $filtro_p; ?>">Form Builder</a>.
                </div>
            </section>

            <section class="pj-sez">
                <h2><i class="fa fa-paperclip me-1" aria-hidden="true"></i>Immagine e allegati</h2>
                <label for="pjLoc" class="form-label small fw-bold">Immagine (JPG, PNG, WEBP)</label>
                <input type="file" name="locandina_file" id="pjLoc" class="form-control form-control-sm" accept="image/png,image/jpeg,image/gif,image/webp">
                <?php if (!empty($ev['locandina_path'])): ?>
                    <div class="d-flex align-items-center gap-2 mt-2">
                        <img src="../<?php echo h($ev['locandina_path']); ?>" alt="" style="height:48px;border-radius:6px;object-fit:cover;">
                        <div class="form-check m-0"><input class="form-check-input" type="checkbox" name="elimina_locandina" id="pjDelLoc" value="1"><label class="form-check-label small text-danger fw-bold" for="pjDelLoc">Rimuovi</label></div>
                    </div>
                <?php endif; ?>
                <label for="pjPdf" class="form-label small fw-bold mt-3">Programma / calendario (PDF)</label>
                <input type="file" name="allegato_pdf" id="pjPdf" class="form-control form-control-sm" accept="application/pdf">
                <?php if (!empty($ev['allegato_pdf'])): ?>
                    <div class="d-flex align-items-center gap-2 mt-2">
                        <a href="../<?php echo h($ev['allegato_pdf']); ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary py-0"><i class="fa fa-eye me-1" aria-hidden="true"></i>Vedi</a>
                        <div class="form-check m-0"><input class="form-check-input" type="checkbox" name="elimina_pdf" id="pjDelPdf" value="1"><label class="form-check-label small text-danger fw-bold" for="pjDelPdf">Rimuovi</label></div>
                    </div>
                <?php endif; ?>
            </section>

            <section class="pj-sez">
                <h2><i class="fa fa-sliders me-1" aria-hidden="true"></i>Pubblicazione e notifiche</h2>
                <div class="row g-2 align-items-end">
                    <div class="col-5"><label for="pjOrd" class="form-label small fw-bold">Ordine</label><input type="number" name="ordine" id="pjOrd" class="form-control form-control-sm" value="<?php echo (int)($ev['ordine'] ?? 0); ?>"></div>
                    <div class="col-7 pb-1"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_evidenza" id="pjEvid" value="1" <?php echo !empty($ev['is_evidenza']) ? 'checked' : ''; ?>><label class="form-check-label small fw-bold" for="pjEvid">In evidenza</label></div></div>
                    <div class="col-12 mt-3">
                        <label for="pjNotif" class="form-label small fw-bold">Invia copia delle iscrizioni a</label>
                        <input type="text" name="email_notifiche_extra" id="pjNotif" class="form-control form-control-sm" value="<?php echo h(implode(', ', normalizza_lista_email($ev['email_notifiche_extra'] ?? ''))); ?>" placeholder="es. segreteria@unical.it">
                        <div class="form-text">Oltre ai gestori. Separa gli indirizzi con una virgola, massimo 10.</div>
                    </div>
                </div>
            </section>

            <div class="d-grid gap-2 mb-4">
                <button type="submit" name="salva_progetto" value="1" class="btn fw-bold py-2" style="background:<?php echo h($col_area); ?>;color:<?php echo $txt_area; ?>;"><i class="fa fa-save me-1" aria-hidden="true"></i>Salva progetto</button>
                <?php if ($id_modifica && $slug_area): ?>
                    <a href="../<?php echo h($slug_area); ?>.php?progetto=<?php echo $id_modifica; ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary fw-bold"><i class="fa fa-up-right-from-square me-1" aria-hidden="true"></i>Vedi la scheda pubblica</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</form>

<script>
document.addEventListener('click', function (e) {
    var add = e.target.closest('[data-pj-aggiungi]');
    if (add) {
        var box = document.getElementById(add.dataset.pjAggiungi);
        var modello = box.lastElementChild;
        if (!modello) return;
        var nuova = modello.cloneNode(true);
        svuota(nuova);
        box.appendChild(nuova);
        aggiornaEdizioni();
        nuova.querySelector('input:not([type=hidden])').focus();
        return;
    }
    var rim = e.target.closest('.pj-rimuovi');
    if (rim) {
        var riga = rim.closest('.pj-ref, .pj-riga-info, .pj-mod, .pj-ed-blocco'), box2 = riga.parentElement;
        if (box2.children.length > 1) riga.remove();
        else svuota(riga);
        aggiornaEdizioni();
    }
});
// Una edizione: campi generali. Più edizioni: campi per edizione, obbligatori (min/max solo per le scuole);
// quelli vuoti partono dai valori generali già inseriti.
function aggiornaEdizioni() {
    var box = document.getElementById('pjEdizioni'), form = box.closest('form');
    var piu = box.children.length > 1, scuole = document.getElementById('pjScuole').checked;
    form.classList.toggle('pj-form-piu-ed', piu); form.classList.toggle('pj-form-una-ed', !piu);
    box.querySelectorAll('.pj-ed-obbl').forEach(function (i) {
        var serve = piu && (scuole || !i.classList.contains('pj-ed-scuole'));
        i.required = serve;
        var gen = document.getElementById(i.dataset.generale);
        if (serve && i.value === '' && gen && gen.value !== '') i.value = gen.value;
    });
}
// Tipo di progetto: mostra i campi delle scuole (min/max per iscrizione) o quelli generici (posti per edizione)
(function () {
    var sw = document.getElementById('pjScuole'), form = sw ? sw.closest('form') : null;
    if (!form) return;
    function aggiorna() { form.classList.toggle('pj-form-scuole', sw.checked); form.classList.toggle('pj-form-generico', !sw.checked); aggiornaEdizioni(); }
    sw.addEventListener('change', aggiorna); aggiorna();
})();
// Rimando a un'altra pagina: niente tipo di progetto né edizioni; campo indirizzo solo per "Un altro indirizzo"
(function () {
    var sel = document.getElementById('pjDestTipo'), url = document.getElementById('pjDestUrl');
    if (!sel) return;
    var form = sel.closest('form');
    function aggiorna() {
        form.classList.toggle('pj-form-dest', sel.value !== '');
        url.classList.toggle('d-none', sel.value !== 'url');
        url.required = sel.value === 'url';
        // Campi obbligatori delle edizioni nascoste: non devono bloccare l'invio
        form.querySelectorAll('.pj-no-dest [required]').forEach(function (i) { i.dataset.eraObbl = '1'; i.required = false; });
        if (sel.value === '') form.querySelectorAll('.pj-no-dest [data-era-obbl]').forEach(function (i) { i.required = true; delete i.dataset.eraObbl; });
        if (sel.value === '' && typeof aggiornaEdizioni === 'function') aggiornaEdizioni();
    }
    sel.addEventListener('change', aggiorna); aggiorna();
    // Con il rimando le sezioni nascoste non contano: il browser controlla i campi obbligatori al clic su "Salva",
    // prima dell'invio, quindi si tolgono lì
    form.querySelectorAll('button[type=submit]').forEach(function (b) {
        b.addEventListener('click', function () {
            if (sel.value !== '') form.querySelectorAll('.pj-no-dest [required]').forEach(function (i) { i.required = false; });
        });
    });
})();
// Riga vuota: testi cancellati, "Riceve le iscrizioni" spento
function svuota(riga) {
    riga.querySelectorAll('.ref-anag').forEach(function (b) { b.hidden = true; });
    riga.querySelectorAll('.badge').forEach(function (b) { b.remove(); });
    riga.querySelectorAll('textarea').forEach(function (t) { t.value = ''; });
    riga.querySelectorAll('input').forEach(function (i) {
        if (i.type === 'checkbox') i.checked = false;
        else if (i.type === 'hidden') i.value = '0';
        else i.value = '';
    });
}
// L'interruttore aggiorna il campo nascosto della stessa riga (le checkbox non spuntate non vengono inviate)
document.addEventListener('change', function (e) {
    if (!e.target.classList.contains('pj-notif')) return;
    e.target.closest('.pj-riga-2').querySelector('input[name="ref_notifiche[]"]').value = e.target.checked ? '1' : '0';
});
</script>

<?php
    require_once 'admin_footer.php';
    exit;
endif;

// =====================================================================
// ELENCO DEI PROGETTI
// =====================================================================
$progetti = [];
$res = $conn->query("SELECT e.* FROM eventi e WHERE e.pagina_id = $filtro_p AND e.archiviato = 0 AND e.tipo = 'progetto' $sql_filtro_eventi_rbac ORDER BY e.ordine ASC, e.id DESC");
if ($res) while ($r = $res->fetch_assoc()) $progetti[(int)$r['id']] = $r;
$dettagli = get_dettagli_progetti($conn, array_keys($progetti));

foreach ($progetti as $id => &$p) {
    $turni_p = [];
    $r_t = $conn->query("SELECT * FROM turni WHERE evento_id = $id ORDER BY id ASC");
    while ($r_t && $rt = $r_t->fetch_assoc()) $turni_p[] = $rt;
    $p['turno'] = $turni_p[0] ?? null; // finestra di iscrizione (uguale per tutte le edizioni)
    $p['dett']  = $dettagli[$id] ?? [];
    $p['ied']   = info_edizioni_progetto($conn, $p['dett'], $turni_p);
    $p['stato'] = $p['ied']['stato'];
    $p['per_scuole'] = (int)($p['dett']['per_scuole'] ?? 1) === 1;
    $p['attestati']  = (int)($p['dett']['attestati'] ?? 0) === 1;
    $p['dest']       = destinazione_progetto($conn, $p['dett']);
}
unset($p);

$finestra_txt = fn(array $t) => (!empty($t['data_apertura']) ? 'dal ' . date('d/m/Y H:i', strtotime($t['data_apertura'])) : 'già aperte')
                               . (!empty($t['data_chiusura']) ? ' al ' . date('d/m/Y H:i', strtotime($t['data_chiusura'])) : '');
$n_eventi_normali = (int)($conn->query("SELECT COUNT(*) AS n FROM eventi WHERE pagina_id = $filtro_p AND archiviato = 0 AND IFNULL(tipo, 'evento') <> 'progetto'")->fetch_assoc()['n'] ?? 0);
?>
<style>
.pj-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.05); overflow:hidden; }
.pj-card + .pj-card { margin-top:.75rem; }
.pj-stato { display:inline-block; font-size:.72rem; font-weight:700; padding:.25rem .6rem; border-radius:999px; }
.pj-meta { font-size:.8rem; color:#475569; }
.pj-meta i { color:#94a3b8; width:14px; }
.pj-maniglia { cursor:grab; background:#f8fafc; border-right:1px solid #e2e8f0; }
.pj-maniglia:active { cursor:grabbing; }
</style>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <h4 class="fw-bold text-dark mb-0"><i class="fa fa-diagram-project me-2" style="color:<?php echo h($col_area); ?>" aria-hidden="true"></i>Progetti</h4>
    <div class="d-flex gap-2 flex-wrap">
        <a href="form_builder.php?p_id=<?php echo $filtro_p; ?>" class="btn btn-outline-secondary btn-sm fw-bold"><i class="fa fa-list-check me-1" aria-hidden="true"></i>Modulo di iscrizione</a>
        <?php if ($puo_creare): ?>
            <a href="progetti.php?p_id=<?php echo $filtro_p; ?>&azione=nuovo" class="btn btn-sm fw-bold px-3" style="background:<?php echo h($col_area); ?>;color:<?php echo $txt_area; ?>;"><i class="fa fa-plus-circle me-1" aria-hidden="true"></i>Nuovo progetto</a>
        <?php endif; ?>
    </div>
</div>

<?php if (!$layout_progetti && $can_manage_settings): ?>
    <div class="alert alert-info small py-2"><i class="fa fa-lightbulb me-1" aria-hidden="true"></i>
        Per mostrare i progetti come elenco con scheda di dettaglio, scegli il layout <strong>Progetti</strong> in <a href="impostazioni_area.php?p_id=<?php echo $filtro_p; ?>" class="alert-link">Impostazioni area</a>.
    </div>
<?php endif; ?>
<?php if ($n_eventi_normali > 0): ?>
    <div class="alert alert-light border small py-2"><i class="fa fa-calendar-alt me-1" aria-hidden="true"></i>In quest'area ci sono anche <?php echo $n_eventi_normali; ?> eventi normali: li trovi in <a href="eventi.php?p_id=<?php echo $filtro_p; ?>">Eventi e Turni</a>.</div>
<?php endif; ?>

<?php if (!$progetti): ?>
    <div class="pj-card p-5 text-center text-muted">
        <i class="fa fa-diagram-project fs-1 mb-3 d-block" style="opacity:.3;" aria-hidden="true"></i>
        <div class="fw-semibold">Nessun progetto in quest'area.</div>
        <?php if ($puo_creare): ?><a href="progetti.php?p_id=<?php echo $filtro_p; ?>&azione=nuovo" class="btn btn-sm btn-outline-secondary fw-bold mt-3">Crea il primo progetto</a><?php endif; ?>
    </div>
<?php endif; ?>

<?php $ordinabile = $puo_creare && count($progetti) > 1; ?>
<?php if ($ordinabile): ?>
    <form method="POST" id="pjOrdineForm" class="d-flex flex-wrap align-items-center gap-2 mb-3 p-2 bg-light border rounded-3">
        <?php csrf_field(); ?>
        <span class="small text-muted"><i class="fa fa-grip-vertical me-1" aria-hidden="true"></i>Trascina le schede per cambiare l'ordine della pagina pubblica, oppure</span>
        <label for="pjOrdinaPer" class="visually-hidden">Ordina per</label>
        <select id="pjOrdinaPer" class="form-select form-select-sm" style="max-width:230px;">
            <option value="">ordina per...</option>
            <option value="titolo">Titolo (A-Z)</option>
            <option value="inizio">Data di inizio</option>
            <option value="stato">Stato (iscrizioni aperte prima)</option>
            <option value="recenti">Più recenti prima</option>
        </select>
        <span id="pjOrdineAvviso" class="small fw-bold text-warning-emphasis d-none">Ordine modificato, non ancora salvato</span>
        <button type="submit" name="salva_ordine_progetti" id="pjOrdineSalva" class="btn btn-sm btn-primary fw-bold ms-auto" disabled><i class="fa fa-save me-1" aria-hidden="true"></i>Salva ordine</button>
    </form>
<?php endif; ?>

<div id="pjLista">
<?php foreach ($progetti as $id => $p):
    $d = $p['dett']; $t = $p['turno']; $st = $p['stato'];
?>
    <div class="pj-card d-flex" data-id="<?php echo $id; ?>" data-titolo="<?php echo h(mb_strtolower($p['titolo'])); ?>" data-inizio="<?php echo h($d['data_inizio'] ?? ''); ?>" data-stato="<?php echo (int)($st['ordine'] ?? 99); ?>">
        <?php if ($ordinabile): ?><div class="pj-maniglia d-flex align-items-center px-1 text-muted" title="Trascina per spostare" aria-hidden="true"><i class="fa fa-grip-vertical"></i></div><?php endif; ?>
        <div style="width:5px;flex-shrink:0;background:<?php echo h($col_area); ?>;"></div>
        <div class="flex-grow-1 p-3">
            <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                <div style="min-width:0;">
                    <span class="pj-stato" style="background:<?php echo $st['bg']; ?>;color:<?php echo $st['fg']; ?>;"><?php echo h($st['etichetta']); ?></span>
                    <?php if (!empty($p['is_evidenza'])): ?><span class="badge bg-warning text-dark ms-1" style="font-size:.65rem;">⭐ EVIDENZA</span><?php endif; ?>
                    <span class="badge ms-1 <?php echo $p['per_scuole'] ? 'bg-primary-subtle text-primary-emphasis' : 'bg-secondary-subtle text-secondary-emphasis'; ?>" style="font-size:.65rem;"><?php echo $p['per_scuole'] ? 'Scuole' : 'Iscrizioni singole'; ?></span>
                    <?php if ($p['attestati']): ?><span class="badge ms-1 bg-success-subtle text-success-emphasis" style="font-size:.65rem;"><i class="fa fa-graduation-cap me-1" aria-hidden="true"></i>Attestati</span><?php endif; ?>
                    <div class="fw-bold fs-6 text-dark mt-1"><?php echo h($p['titolo']); ?></div>
                    <div class="pj-meta d-flex flex-wrap gap-3 mt-1">
                        <span><i class="fa fa-calendar-days" aria-hidden="true"></i> <?php echo h(periodo_progetto($d)); ?></span>
                        <?php if (!empty($d['struttura'])): ?><span><i class="fa fa-building-columns" aria-hidden="true"></i> <?php echo h($d['struttura']); ?></span><?php endif; ?>
                        <?php $una_ed = count($p['ied']['edizioni']) <= 1; ?>
                        <?php if ($t && $una_ed): ?><span><i class="fa fa-door-open" aria-hidden="true"></i> Iscrizioni: <?php echo h($finestra_txt($t)); ?></span><?php endif; ?>
                        <?php if ($p['per_scuole'] && $una_ed && ($lim_t = testo_limiti_partecipanti(...array_values(limiti_partecipanti($d, $t)))) !== ''): ?><span><i class="fa fa-users" aria-hidden="true"></i> <?php echo h(ucfirst($lim_t)); ?> partecipanti per iscrizione</span><?php endif; ?>
                    </div>
                </div>
                <div class="d-flex gap-1 flex-shrink-0">
                    <a href="progetti.php?p_id=<?php echo $filtro_p; ?>&id=<?php echo $id; ?>" class="btn btn-sm btn-outline-primary" title="Modifica" aria-label="Modifica progetto"><i class="fa fa-edit" aria-hidden="true"></i></a>
                    <?php if ($slug_area): ?><a href="../<?php echo h($slug_area); ?>.php?progetto=<?php echo $id; ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary" title="Scheda pubblica" aria-label="Scheda pubblica"><i class="fa fa-eye" aria-hidden="true"></i></a><?php endif; ?>
                    <?php if ($puo_creare): ?>
                        <form method="POST" class="m-0"><?php csrf_field(); ?><input type="hidden" name="duplica_progetto" value="<?php echo $id; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-secondary" title="Duplica" aria-label="Duplica progetto" data-confirm="Duplicare il progetto? Le iscrizioni non vengono copiate."><i class="fa fa-copy" aria-hidden="true"></i></button></form>
                    <?php endif; ?>
                    <?php if ($can_manage_settings): ?>
                        <form method="POST" class="m-0"><?php csrf_field(); ?><input type="hidden" name="archivia_progetto" value="<?php echo $id; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-warning text-dark" title="Archivia" aria-label="Archivia progetto" data-confirm="Archiviare questo progetto?"><i class="fa fa-archive" aria-hidden="true"></i></button></form>
                        <form method="POST" class="m-0"><?php csrf_field(); ?><input type="hidden" name="elimina_progetto" value="<?php echo $id; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Elimina" aria-label="Elimina progetto" data-confirm="Eliminare il progetto con tutte le iscrizioni? L'operazione non si può annullare."><i class="fa fa-trash" aria-hidden="true"></i></button></form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="mt-3 pt-2 border-top small d-flex flex-column gap-1">
                <?php if ($p['dest']): ?>
                    <div><span class="badge" style="background:#E0F2FE;color:#075985;"><i class="fa fa-share-from-square me-1" aria-hidden="true"></i>Rimanda a <?php echo h($p['dest']['nome']); ?></span>
                        <a href="<?php echo $p['dest']['esterno'] ? h($p['dest']['url']) : '../' . h($p['dest']['url']); ?>" target="_blank" rel="noopener" class="ms-1">apri la pagina</a></div>
                <?php endif; ?>
                <?php if (!$p['dest']) foreach ($p['ied']['edizioni'] as $ed): $as = $ed['assegnata']; ?>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <?php if (!$una_ed): ?>
                            <span class="fw-bold text-dark" style="min-width:150px;"><?php echo h($ed['etichetta']); ?></span>
                            <span class="text-muted"><i class="fa fa-door-open me-1" aria-hidden="true"></i><?php echo h($finestra_txt($ed['t'])); ?></span>
                            <?php if ($p['per_scuole'] && ($lim_e = testo_limiti_partecipanti($ed['min'], $ed['max'])) !== ''): ?><span class="text-muted"><i class="fa fa-users me-1" aria-hidden="true"></i><?php echo h($lim_e); ?> partecipanti</span><?php endif; ?>
                            <span class="pj-stato" style="background:<?php echo $ed['stato']['bg']; ?>;color:<?php echo $ed['stato']['fg']; ?>;"><?php echo h($ed['stato']['etichetta']); ?></span>
                        <?php endif; ?>
                        <?php if (!$p['per_scuole']): // generico: posti occupati sull'edizione ?>
                            <span class="badge <?php echo $ed['libera'] ? 'bg-light text-dark border' : 'bg-danger'; ?>"><i class="fa fa-users me-1" aria-hidden="true"></i><?php echo (int)$ed['occ']; ?> / <?php echo (int)$ed['t']['max_posti']; ?> iscritti</span>
                        <?php elseif ($as):
                            $scuola = nome_scuola_prenotazione($as);
                            $lbl_st = ['confermata' => 'Assegnato', 'richiesta_conferma' => 'Posto offerto, in attesa di conferma', 'da_approvare' => 'Da approvare'][$as['stato'] ?? 'confermata'] ?? 'Assegnato';
                        ?>
                            <span class="badge bg-success"><i class="fa fa-user-check me-1" aria-hidden="true"></i><?php echo h($lbl_st); ?></span>
                            <span class="text-dark fw-semibold"><?php echo h($scuola !== '' ? $scuola : trim($as['nome'] . ' ' . $as['cognome'])); ?></span>
                            <?php if ($scuola !== ''): ?><span class="text-muted">· <?php echo h(trim($as['nome'] . ' ' . $as['cognome'])); ?></span><?php endif; ?>
                            <a href="mailto:<?php echo h($as['email']); ?>" class="text-muted"><?php echo h($as['email']); ?></a>
                            <?php if ($p['attestati'] && $can_manage_iscritti):
                                $n_stud = (int)($conn->query("SELECT COUNT(*) AS n FROM partecipanti_prenotazione WHERE prenotazione_id = " . (int)$as['id'])->fetch_assoc()['n'] ?? 0); ?>
                                <a href="partecipanti.php?p_id=<?php echo $filtro_p; ?>&pr=<?php echo (int)$as['id']; ?>" class="btn btn-sm btn-outline-success py-0 fw-bold"><i class="fa fa-graduation-cap me-1" aria-hidden="true"></i>Studenti e attestati (<?php echo $n_stud; ?>)</a>
                                <?php if (!empty($as['attestato_inviato'])): ?><span class="badge bg-success-subtle text-success-emphasis">Attestati inviati</span><?php endif; ?>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge bg-light text-secondary border"><i class="fa fa-user-clock me-1" aria-hidden="true"></i>Nessuna iscrizione</span>
                        <?php endif; ?>
                        <?php if ($ed['attesa'] > 0): ?><span class="badge" style="background:#fef3c7;color:#92400e;"><?php echo $ed['attesa']; ?> in lista d'attesa</span><?php endif; ?>
                        <?php if ($can_manage_iscritti): ?>
                            <a href="iscritti.php?p_id=<?php echo $filtro_p; ?>&f_turno=<?php echo (int)$ed['t']['id']; ?>" class="btn btn-sm btn-outline-dark py-0 ms-auto fw-bold"><i class="fa fa-users me-1" aria-hidden="true"></i>Iscrizioni</a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>

<?php if ($ordinabile): ?>
<script src="<?php echo url_vendor('jsdelivr/npm/sortablejs@1.15.2/Sortable.min.js'); ?>" integrity="sha384-BSxuMLxX+FCbTdYec3TbXlnMGEEM2QXTFdtDaveen71o+jswm2J36+xFqp8k4VHM" crossorigin="anonymous"></script>
<script>
(function () {
    var lista = document.getElementById('pjLista'), form = document.getElementById('pjOrdineForm');
    var salva = document.getElementById('pjOrdineSalva'), avviso = document.getElementById('pjOrdineAvviso');
    var modificato = function () { salva.disabled = false; avviso.classList.remove('d-none'); };
    if (window.Sortable) Sortable.create(lista, { handle: '.pj-maniglia', animation: 150, ghostClass: 'opacity-50', onEnd: modificato });

    var confronti = {
        titolo:  function (a, b) { return a.dataset.titolo.localeCompare(b.dataset.titolo, 'it'); },
        inizio:  function (a, b) { return (a.dataset.inizio || '9999') < (b.dataset.inizio || '9999') ? -1 : (a.dataset.inizio || '9999') > (b.dataset.inizio || '9999') ? 1 : 0; },
        stato:   function (a, b) { return (a.dataset.stato - b.dataset.stato) || confronti.inizio(a, b); },
        recenti: function (a, b) { return b.dataset.id - a.dataset.id; }
    };
    document.getElementById('pjOrdinaPer').addEventListener('change', function () {
        var f = confronti[this.value]; if (!f) return;
        Array.from(lista.children).sort(f).forEach(function (c) { lista.appendChild(c); });
        modificato();
    });

    form.addEventListener('submit', function () {
        form.querySelectorAll('input[name="ordine_ids[]"]').forEach(function (i) { i.remove(); });
        Array.from(lista.children).forEach(function (c) {
            var i = document.createElement('input'); i.type = 'hidden'; i.name = 'ordine_ids[]'; i.value = c.dataset.id; form.appendChild(i);
        });
        salva.disabled = false;
    });
    window.addEventListener('beforeunload', function (e) { if (!salva.disabled && !form.dataset.invio) { e.preventDefault(); e.returnValue = ''; } });
    form.addEventListener('submit', function () { form.dataset.invio = '1'; });
})();
</script>
<?php endif; ?>

<?php require_once 'admin_footer.php'; ?>
