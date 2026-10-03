<?php
// inc/dati.php - Lettura dei dati: sondaggi, archivio, campi dei moduli, prenotazioni, pagine, vincoli di iscrizione, statistiche e pannello.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

// =======================================================================
// SONDAGGI
// =======================================================================
if (!function_exists('get_prenotazione_by_token_sondaggio')) {
    function get_prenotazione_by_token_sondaggio($conn, string $token): ?array {
        $stmt = $conn->prepare(
            "SELECT pr.*, t.evento_id, e.titolo as evento_titolo
             FROM prenotazioni pr
             JOIN turni t ON pr.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             WHERE pr.token_sondaggio = ? LIMIT 1"
        );
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $res = $stmt->get_result();
        return ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
    }
}

if (!function_exists('get_sondaggio_attivo')) {
    function get_sondaggio_attivo($conn, int $evento_id): ?array {
        $stmt = $conn->prepare("SELECT * FROM sondaggi WHERE evento_id = ? AND attivo = 1 LIMIT 1");
        $stmt->bind_param("i", $evento_id);
        $stmt->execute();
        $res = $stmt->get_result();
        return ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
    }
}

if (!function_exists('get_domande_sondaggio')) {
    function get_domande_sondaggio($conn, int $sondaggio_id): array {
        $stmt = $conn->prepare("SELECT * FROM sondaggi_domande WHERE sondaggio_id = ? ORDER BY ordine ASC, id ASC");
        $stmt->bind_param("i", $sondaggio_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        if ($res) { while ($d = $res->fetch_assoc()) { $rows[] = $d; } }
        return $rows;
    }
}

if (!function_exists('salva_risposte_sondaggio')) {
    function salva_risposte_sondaggio($conn, int $sond_id, array $risposte, int $pr_id, ?string &$errore = null): bool {
        $errore = null;

        // Accetta solo risposte a domande di QUESTO sondaggio
        $domande = [];
        foreach (get_domande_sondaggio($conn, $sond_id) as $d) { $domande[(int)$d['id']] = $d; }

        $valori = [];
        foreach ($risposte as $d_id => $valore) {
            $d_id = (int)$d_id;
            if (!isset($domande[$d_id])) continue;
            $val = is_array($valore) ? json_encode($valore, JSON_UNESCAPED_UNICODE) : trim((string)$valore);
            if ($val !== '' && $val !== '[]') $valori[$d_id] = $val;
        }

        // Obbligatorie (le condizionali restano verificate solo lato browser, perché possono essere nascoste)
        foreach ($domande as $d_id => $d) {
            if (!empty($d['obbligatorio']) && empty($d['condizione_json']) && $d['tipo'] !== 'separator' && !isset($valori[$d_id])) {
                $errore = "Rispondi a tutte le domande obbligatorie.";
                return false;
            }
        }

        $conn->begin_transaction();
        // Segna come completato per primo: blocca il doppio invio (doppio clic, due schede)
        $stmt_upd = $conn->prepare("UPDATE prenotazioni SET sondaggio_completato = 1 WHERE id = ? AND COALESCE(sondaggio_completato, 0) = 0");
        $stmt_upd->bind_param("i", $pr_id);
        if (!$stmt_upd->execute() || $stmt_upd->affected_rows !== 1) {
            $conn->rollback();
            $errore = "Hai già compilato questo questionario. Grazie per il tuo feedback!";
            return false;
        }
        $stmt_ins = $conn->prepare("INSERT INTO sondaggi_risposte (sondaggio_id, domanda_id, risposta) VALUES (?, ?, ?)");
        foreach ($valori as $d_id => $val) {
            $stmt_ins->bind_param("iis", $sond_id, $d_id, $val);
            if (!$stmt_ins->execute()) {
                $conn->rollback();
                $errore = "Errore durante il salvataggio. Riprova.";
                return false;
            }
        }
        $conn->commit();
        return true;
    }
}

// =======================================================================
// ARCHIVIO EVENTI
// =======================================================================
if (!function_exists('get_eventi_archivio')) {
    function get_eventi_archivio($conn, int $p_id): array {
        $stmt = $conn->prepare(
            "SELECT e.*, sc.nome as nome_sottocategoria,
             (SELECT YEAR(MIN(data_turno)) FROM turni WHERE evento_id = e.id) as anno_evento
             FROM eventi e
             LEFT JOIN sottocategorie sc ON e.sottocategoria_id = sc.id
             WHERE e.pagina_id = ? AND e.archiviato = 1
             ORDER BY anno_evento DESC, sc.ordine ASC, e.ordine ASC"
        );
        $stmt->bind_param("i", $p_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        if ($res) { while ($r = $res->fetch_assoc()) { $rows[] = $r; } }
        return $rows;
    }
}

// =======================================================================
// FORM / CAMPI CUSTOM
// =======================================================================
if (!function_exists('get_campi_form')) {
    function get_campi_form($conn, int $evento_id): array {
        $stmt = $conn->prepare("SELECT * FROM campi_form WHERE evento_id = ? ORDER BY id ASC");
        $stmt->bind_param("i", $evento_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        if ($res) { while ($r = $res->fetch_assoc()) { $rows[] = $r; } }
        return $rows;
    }
}

// =======================================================================
// PRENOTAZIONI / RICEVUTA
// =======================================================================
if (!function_exists('get_attestato')) {
    function get_attestato($conn, string $code): ?array {
        $stmt = $conn->prepare(
            "SELECT pr.*, t.data_turno, t.orario_inizio, t.orario_fine,
               e.titolo as evento_titolo, e.luogo as evento_luogo,
               pe.titolo as pagina_titolo, pe.firma_nome, pe.firma_titolo, pe.logo_attestato_path,
               cp.logo_path, cp.nome_portale, cp.sottotitolo_portale,
               COALESCE(NULLIF(pr.matricola,''), u.matricola_studente, u.matricola_dipendente, u.matricola, '') as matricola_effettiva
            FROM prenotazioni pr
            JOIN turni t ON pr.turno_id = t.id
            JOIN eventi e ON t.evento_id = e.id
            LEFT JOIN pagine_eventi pe ON e.pagina_id = pe.id
            LEFT JOIN utenti u ON pr.utente_id = u.id
            JOIN configurazione_portale cp ON cp.id = 1
            WHERE pr.codice_prenotazione = ? LIMIT 1"
        );
        $stmt->bind_param("s", $code);
        $stmt->execute();
        $res = $stmt->get_result();
        return ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
    }
}

if (!function_exists('get_prenotazione_ricevuta')) {
    function get_prenotazione_ricevuta($conn, string $code, int $id): ?array {
        $select = "SELECT pr.*, t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine,
               e.titolo as evento_titolo, e.luogo as evento_luogo,
               pe.titolo as pagina_titolo, pe.colore_primario,
               cp.logo_path, cp.nome_portale, cp.sottotitolo_portale,
               COALESCE(NULLIF(pr.matricola,''), u.matricola_studente, u.matricola_dipendente, u.matricola, '') as matricola_effettiva
            FROM prenotazioni pr
            JOIN turni t ON pr.turno_id = t.id
            JOIN eventi e ON t.evento_id = e.id
            LEFT JOIN pagine_eventi pe ON e.pagina_id = pe.id
            LEFT JOIN utenti u ON pr.utente_id = u.id
            JOIN configurazione_portale cp ON cp.id = 1
            WHERE ";
        if ($id > 0) {
            $stmt = $conn->prepare($select . "pr.id = ? LIMIT 1");
            $stmt->bind_param("i", $id);
        } else {
            $stmt = $conn->prepare($select . "pr.codice_prenotazione = ? LIMIT 1");
            $stmt->bind_param("s", $code);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        return ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
    }
}

// =======================================================================
// PAGINE EVENTI
// =======================================================================
if (!function_exists('get_pagina_by_slug')) {
    function get_pagina_by_slug($conn, string $slug): ?array {
        $stmt = $conn->prepare("SELECT * FROM pagine_eventi WHERE slug = ? LIMIT 1");
        $stmt->bind_param("s", $slug);
        $stmt->execute();
        $res = $stmt->get_result();
        return ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
    }
}

// =======================================================================
// PAGINE EVENTI (HOME)
// =======================================================================
if (!function_exists('get_pagine_eventi_visibili')) {
    function get_pagine_eventi_visibili($conn): array {
        $res = $conn->query("SELECT * FROM pagine_eventi WHERE visibile = 1 ORDER BY ordine ASC, id ASC");
        $rows = [];
        if ($res) { while ($r = $res->fetch_assoc()) { $rows[] = $r; } }
        return $rows;
    }
}

// =======================================================================
// VINCOLO ISCRIZIONI PER AREA (limite_iscrizioni: nessuno | un_evento | un_turno)
// =======================================================================
// Le liste d'attesa NON contano per il vincolo: si può stare in attesa su più turni/eventi.
// Appena una prenotazione dello stesso ambito diventa 'confermata', le altre decadono
// (vedi decadi_attese_vincolate).

if (!function_exists('scope_vincolo_sql')) {
    // Condizione SQL (alias t, e) che delimita l'ambito del vincolo, oppure null se non c'è vincolo.
    function scope_vincolo_sql(string $limite, int $pagina_id, int $evento_id): ?string {
        if ($limite === 'un_evento') return "e.pagina_id = $pagina_id AND e.archiviato = 0";
        if ($limite === 'un_turno')  return "t.evento_id = $evento_id";
        return null;
    }
}

if (!function_exists('trova_iscrizione_vincolata')) {
    // Ritorna la prenotazione attiva (non in lista d'attesa) che blocca una nuova iscrizione, oppure null.
    function trova_iscrizione_vincolata($conn, string $limite, int $pagina_id, int $evento_id, int $utente_id, string $email, string $matricola): ?array {
        $scope_sql = scope_vincolo_sql($limite, $pagina_id, $evento_id);
        if ($scope_sql === null) return null;
        $stmt = $conn->prepare(
            "SELECT pr.id, pr.codice_prenotazione, e.titolo AS evento_titolo
             FROM prenotazioni pr
             JOIN turni t  ON pr.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             WHERE $scope_sql
               AND pr.stato NOT IN ('annullata', 'rifiutata', 'scaduta', 'in_attesa', 'richiesta_conferma')
               AND (LOWER(pr.email) = ? OR (? > 0 AND pr.utente_id = ?) OR (? != '' AND pr.matricola = ?))
             LIMIT 1"
        );
        $email = strtolower($email);
        $stmt->bind_param("siiss", $email, $utente_id, $utente_id, $matricola, $matricola);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row ?: null;
    }
}

if (!function_exists('get_mie_iscrizioni_area')) {
    // Mappa evento_id => [turno_id => stato] delle prenotazioni attive dell'utente nell'area.
    function get_mie_iscrizioni_area($conn, int $pagina_id, int $utente_id, string $email): array {
        $stmt = $conn->prepare(
            "SELECT t.evento_id, pr.turno_id, pr.stato
             FROM prenotazioni pr
             JOIN turni t  ON pr.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             WHERE e.pagina_id = ?
               AND pr.stato NOT IN ('annullata', 'rifiutata', 'scaduta')
               AND (pr.utente_id = ? OR (? != '' AND LOWER(pr.email) = LOWER(?)))"
        );
        $stmt->bind_param("iiss", $pagina_id, $utente_id, $email, $email);
        $stmt->execute();
        $res = $stmt->get_result();
        $map = [];
        while ($r = $res->fetch_assoc()) { $map[(int)$r['evento_id']][(int)$r['turno_id']] = (string)$r['stato']; }
        return $map;
    }
}

if (!function_exists('decadi_attese_vincolate')) {
    // Da chiamare DOPO che una prenotazione è diventata 'confermata'. Se l'area ha un limite
    // iscrizioni, annulla le altre richieste pendenti della stessa persona nello stesso ambito
    // (liste d'attesa, posti offerti in attesa di conferma, richieste da approvare), avvisa
    // l'utente con una email e ripassa i posti liberati alla lista d'attesa. Ritorna quante ne annulla.
    function decadi_attese_vincolate($conn, int $pr_id): int {
        $res = $conn->query(
            "SELECT pr.stato, pr.turno_id, pr.email, pr.utente_id, pr.matricola, pr.nome, t.evento_id, e.pagina_id,
                    e.titolo AS evento_titolo, pe.limite_iscrizioni
             FROM prenotazioni pr
             JOIN turni t ON pr.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             JOIN pagine_eventi pe ON e.pagina_id = pe.id
             WHERE pr.id = $pr_id LIMIT 1"
        );
        if (!$res || !($c = $res->fetch_assoc()) || $c['stato'] !== 'confermata') return 0;
        $scope_sql = scope_vincolo_sql((string)($c['limite_iscrizioni'] ?? 'nessuno'), (int)$c['pagina_id'], (int)$c['evento_id']);
        if ($scope_sql === null) return 0;

        $email = strtolower((string)$c['email']);
        $u_id  = (int)$c['utente_id'];
        $matr  = (string)($c['matricola'] ?? '');
        $stmt = $conn->prepare(
            "SELECT pr.id, pr.turno_id, pr.stato, e.titolo AS evento_titolo, t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine
             FROM prenotazioni pr
             JOIN turni t  ON pr.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             WHERE $scope_sql
               AND pr.id != ?
               AND pr.stato IN ('in_attesa', 'richiesta_conferma', 'da_approvare')
               AND (LOWER(pr.email) = ? OR (? > 0 AND pr.utente_id = ?) OR (? != '' AND pr.matricola = ?))"
        );
        $stmt->bind_param("isiiss", $pr_id, $email, $u_id, $u_id, $matr, $matr);
        $stmt->execute();
        $res_alt = $stmt->get_result();

        $annullate = [];
        $turni_da_ripassare = [];
        while ($a = $res_alt->fetch_assoc()) {
            $a_id = (int)$a['id'];
            $stato_old = $conn->real_escape_string($a['stato']);
            // "AND stato = ..." evita di annullare una riga cambiata nel frattempo
            $conn->query("UPDATE prenotazioni SET stato = 'annullata' WHERE id = $a_id AND stato = '$stato_old'");
            if ($conn->affected_rows !== 1) continue;
            $annullate[] = $a;
            // richiesta_conferma / da_approvare tenevano un posto: va offerto al prossimo in coda
            if ($a['stato'] !== 'in_attesa') $turni_da_ripassare[(int)$a['turno_id']] = true;
        }
        if (!$annullate) return 0;

        foreach (array_keys($turni_da_ripassare) as $tid) { promuovi_lista_attesa($conn, $tid); }

        if (function_exists('registra_log_audit')) {
            registra_log_audit($conn, "Decadenza liste d'attesa (limite iscrizioni)", ["Prenotazione confermata" => $pr_id, "Annullate" => implode(',', array_column($annullate, 'id'))]);
        }

        if ($email !== '') {
            $voci = '';
            foreach ($annullate as $a) {
                $voci .= "<li><strong>" . htmlspecialchars($a['evento_titolo']) . "</strong> — " . htmlspecialchars(etichetta_turno($a)) . "</li>";
            }
            $corpo = "<p>Gentile <strong>" . htmlspecialchars((string)$c['nome']) . "</strong>,</p>"
                   . "<p>la tua prenotazione per <strong>" . htmlspecialchars($c['evento_titolo']) . "</strong> è <strong>confermata</strong>.</p>"
                   . "<p>Poiché in quest'area è consentita una sola iscrizione, le tue altre richieste in lista d'attesa sono state annullate automaticamente:</p>"
                   . "<ul>$voci</ul>";
            inviaNotificaEmail($email, "Liste d'attesa annullate: iscrizione confermata a " . $c['evento_titolo'], $corpo, $conn, colore_area_turno($conn, $c['turno_id']));
        }
        return count($annullate);
    }
}

// =======================================================================
// TURNI
// =======================================================================
if (!function_exists('get_turno_con_evento')) {
    function get_turno_con_evento($conn, int $turno_id): ?array {
        $stmt = $conn->prepare("SELECT t.*, e.titolo as evento_titolo, e.descrizione, e.luogo FROM turni t JOIN eventi e ON t.evento_id = e.id WHERE t.id = ? LIMIT 1");
        $stmt->bind_param("i", $turno_id);
        $stmt->execute();
        $res = $stmt->get_result();
        return ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
    }
}

// =======================================================================
// PROFILO UTENTE
// =======================================================================
if (!function_exists('aggiorna_email_utente')) {
    /**
     * Aggiorna l'email dell'utente e la marca come personalizzata.
     * Ritorna true in caso di successo, oppure una stringa di errore.
     */
    function aggiorna_email_utente($conn, int $u_id, string $nuova_email) {
        $stmt_chk = $conn->prepare("SELECT id FROM utenti WHERE LOWER(email) = ? AND id != ? LIMIT 1");
        $stmt_chk->bind_param("si", $nuova_email, $u_id);
        $stmt_chk->execute();
        if ($stmt_chk->get_result()->num_rows > 0) {
            return 'Questa email è già associata a un altro account.';
        }
        $stmt_upd = $conn->prepare("UPDATE utenti SET email = ?, email_personalizzata = 1 WHERE id = ?");
        $stmt_upd->bind_param("si", $nuova_email, $u_id);
        return $stmt_upd->execute() ? true : 'Errore durante il salvataggio. Riprova.';
    }
}

// =======================================================================
// RICERCA GLOBALE
// =======================================================================
if (!function_exists('cerca_eventi')) {
    function cerca_eventi($conn, string $q): array {
        $q_like = '%' . $q . '%';
        $sql = "SELECT e.*,
                    pe.titolo as nome_area, pe.slug as slug_area, pe.colore_primario,
                    (SELECT MIN(data_turno) FROM turni WHERE evento_id = e.id AND data_turno >= CURDATE()) as prossima_data
                FROM eventi e
                JOIN pagine_eventi pe ON e.pagina_id = pe.id
                WHERE pe.visibile = 1
                  AND (e.titolo LIKE ? OR e.descrizione LIKE ? OR e.luogo LIKE ?)
                ORDER BY e.archiviato ASC, prossima_data ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sss", $q_like, $q_like, $q_like);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        if ($res) { while ($row = $res->fetch_assoc()) { $rows[] = $row; } }
        return $rows;
    }
}

// =======================================================================
// ADMIN — STATISTICHE
// =======================================================================
if (!function_exists('get_kpi_statistiche')) {
    function get_kpi_statistiche($conn, $p_id, $sql_filtro_rbac) {
        $p_id = (int)$p_id;
        $kpi  = ['confermate' => 0, 'attesa' => 0, 'perse' => 0, 'capienza' => 0];
        $res  = $conn->query(
            "SELECT
               SUM(CASE WHEN p.stato IN ('confermata','richiesta_conferma') THEN p.num_posti ELSE 0 END) as tot_confermate,
               SUM(CASE WHEN p.stato = 'in_attesa' THEN p.num_posti ELSE 0 END) as tot_attesa,
               SUM(CASE WHEN p.stato IN ('scaduta','rifiutata') THEN p.num_posti ELSE 0 END) as tot_perse
             FROM prenotazioni p
             JOIN turni t ON p.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             JOIN pagine_eventi pe ON e.pagina_id = pe.id
             WHERE e.pagina_id = $p_id $sql_filtro_rbac"
        );
        if ($res && $row = $res->fetch_assoc()) {
            $kpi['confermate'] = (int)$row['tot_confermate'];
            $kpi['attesa']     = (int)$row['tot_attesa'];
            $kpi['perse']      = (int)$row['tot_perse'];
        }
        $res_cap = $conn->query(
            "SELECT SUM(t.max_posti) as capienza_max
             FROM turni t
             JOIN eventi e ON t.evento_id = e.id
             JOIN pagine_eventi pe ON e.pagina_id = pe.id
             WHERE e.pagina_id = $p_id $sql_filtro_rbac AND t.max_posti < " . (int)POSTI_SENZA_LIMITE
        );
        if ($res_cap && $row_cap = $res_cap->fetch_assoc()) {
            $kpi['capienza'] = (int)$row_cap['capienza_max'];
        }
        return $kpi;
    }
}

if (!function_exists('get_dati_grafico_eventi')) {
    function get_dati_grafico_eventi($conn, $p_id, $sql_filtro_rbac) {
        $p_id   = (int)$p_id;
        $nomi   = []; $occupati = []; $capienza = [];
        $res = $conn->query(
            "SELECT e.id, e.titolo,
               COALESCE((SELECT SUM(max_posti) FROM turni WHERE evento_id = e.id AND max_posti < " . (int)POSTI_SENZA_LIMITE . "), 0) as cap_max
             FROM eventi e
             JOIN pagine_eventi pe ON e.pagina_id = pe.id
             WHERE e.pagina_id = $p_id $sql_filtro_rbac
             ORDER BY e.id ASC"
        );
        if ($res) {
            while ($ev = $res->fetch_assoc()) {
                $ev_id   = $ev['id'];
                $res_occ = $conn->query(
                    "SELECT COALESCE(SUM(p.num_posti), 0) as occupati
                     FROM prenotazioni p JOIN turni t ON p.turno_id = t.id
                     WHERE t.evento_id = $ev_id AND p.stato IN ('confermata','richiesta_conferma')"
                );
                $occ = ($res_occ) ? (int)$res_occ->fetch_assoc()['occupati'] : 0;
                if ($occ > 0 || $ev['cap_max'] > 0) {
                    $titolo_corto = mb_strlen($ev['titolo']) > 25 ? mb_substr($ev['titolo'], 0, 22) . '...' : $ev['titolo'];
                    $nomi[]     = '"' . addslashes($titolo_corto) . '"';
                    $occupati[] = $occ;
                    $capienza[] = $ev['cap_max'];
                }
            }
        }
        return ['nomi' => $nomi, 'occupati' => $occupati, 'capienza' => $capienza];
    }
}

if (!function_exists('get_stats_turni')) {
    function get_stats_turni($conn, $p_id, $sql_filtro_rbac) {
        $p_id = (int)$p_id;
        $res  = $conn->query(
            "SELECT e.titolo as evento_titolo, t.id as turno_id,
               t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine, t.max_posti,
               COALESCE(SUM(CASE WHEN p.stato IN ('confermata','richiesta_conferma') THEN p.num_posti ELSE 0 END), 0) as confermati,
               COALESCE(SUM(CASE WHEN p.stato = 'in_attesa' THEN p.num_posti ELSE 0 END), 0) as attesa
             FROM turni t
             JOIN eventi e ON t.evento_id = e.id
             JOIN pagine_eventi pe ON e.pagina_id = pe.id
             LEFT JOIN prenotazioni p ON p.turno_id = t.id
             WHERE e.pagina_id = $p_id $sql_filtro_rbac
             GROUP BY t.id
             ORDER BY (t.data_turno IS NULL), t.data_turno ASC, t.orario_inizio ASC, t.nome_turno ASC, t.id ASC"
        );
        $rows = [];
        if ($res) { while ($row = $res->fetch_assoc()) { $rows[] = $row; } }
        return $rows;
    }
}

if (!function_exists('get_kpi_statistiche_v2')) {
    function get_kpi_statistiche_v2($conn, $p_id, $sql_filtro_rbac) {
        $p_id = (int)$p_id;
        $kpi  = ['confermate' => 0, 'attesa' => 0, 'perse' => 0, 'annullate' => 0, 'presenti' => 0, 'capienza' => 0];
        $res  = $conn->query(
            "SELECT
               SUM(CASE WHEN p.stato IN ('confermata','richiesta_conferma') THEN p.num_posti ELSE 0 END) as tot_confermate,
               SUM(CASE WHEN p.stato = 'in_attesa' THEN p.num_posti ELSE 0 END) as tot_attesa,
               SUM(CASE WHEN p.stato IN ('scaduta','rifiutata') THEN p.num_posti ELSE 0 END) as tot_perse,
               SUM(CASE WHEN p.stato IN ('annullata','annullato','cancelled') THEN p.num_posti ELSE 0 END) as tot_annullate,
               SUM(CASE WHEN p.presente = 1 THEN p.num_posti ELSE 0 END) as tot_presenti
             FROM prenotazioni p
             JOIN turni t ON p.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             JOIN pagine_eventi pe ON e.pagina_id = pe.id
             WHERE e.pagina_id = $p_id $sql_filtro_rbac"
        );
        if ($res && $row = $res->fetch_assoc()) {
            $kpi['confermate'] = (int)$row['tot_confermate'];
            $kpi['attesa']     = (int)$row['tot_attesa'];
            $kpi['perse']      = (int)$row['tot_perse'];
            $kpi['annullate']  = (int)$row['tot_annullate'];
            $kpi['presenti']   = (int)$row['tot_presenti'];
        }
        $res_cap = $conn->query(
            "SELECT SUM(t.max_posti) as capienza_max
             FROM turni t
             JOIN eventi e ON t.evento_id = e.id
             JOIN pagine_eventi pe ON e.pagina_id = pe.id
             WHERE e.pagina_id = $p_id $sql_filtro_rbac AND t.max_posti < " . (int)POSTI_SENZA_LIMITE
        );
        if ($res_cap && $row_cap = $res_cap->fetch_assoc()) {
            $kpi['capienza'] = (int)$row_cap['capienza_max'];
        }
        return $kpi;
    }
}

if (!function_exists('get_trend_iscrizioni')) {
    function get_trend_iscrizioni($conn, $p_id, $sql_filtro_rbac, $days = 30) {
        $p_id = (int)$p_id;
        $days = (int)$days;
        $res  = $conn->query(
            "SELECT DATE(p.data_prenotazione) as giorno, COUNT(*) as cnt
             FROM prenotazioni p
             JOIN turni t ON p.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             JOIN pagine_eventi pe ON e.pagina_id = pe.id
             WHERE e.pagina_id = $p_id $sql_filtro_rbac
               AND p.data_prenotazione >= DATE_SUB(CURDATE(), INTERVAL $days DAY)
             GROUP BY DATE(p.data_prenotazione)
             ORDER BY giorno ASC"
        );
        $rows = [];
        if ($res) { while ($r = $res->fetch_assoc()) { $rows[] = $r; } }
        return $rows;
    }
}

if (!function_exists('get_stats_turni_ext')) {
    function get_stats_turni_ext($conn, $p_id, $sql_filtro_rbac) {
        $p_id = (int)$p_id;
        $res  = $conn->query(
            "SELECT e.titolo as evento_titolo, t.id as turno_id,
               t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine, t.max_posti,
               COALESCE(SUM(CASE WHEN p.stato IN ('confermata','richiesta_conferma') THEN p.num_posti ELSE 0 END), 0) as confermati,
               COALESCE(SUM(CASE WHEN p.stato = 'in_attesa' THEN p.num_posti ELSE 0 END), 0) as attesa,
               COALESCE(SUM(CASE WHEN p.stato IN ('annullata','annullato','cancelled') THEN p.num_posti ELSE 0 END), 0) as annullate,
               COALESCE(SUM(CASE WHEN p.presente = 1 THEN p.num_posti ELSE 0 END), 0) as presenti
             FROM turni t
             JOIN eventi e ON t.evento_id = e.id
             JOIN pagine_eventi pe ON e.pagina_id = pe.id
             LEFT JOIN prenotazioni p ON p.turno_id = t.id
             WHERE e.pagina_id = $p_id $sql_filtro_rbac
             GROUP BY t.id
             ORDER BY (t.data_turno IS NULL), t.data_turno ASC, t.orario_inizio ASC, t.nome_turno ASC, t.id ASC"
        );
        $rows = [];
        if ($res) { while ($row = $res->fetch_assoc()) { $rows[] = $row; } }
        return $rows;
    }
}

// =======================================================================
// ADMIN — LOOKUP (ruoli, sottocategorie)
// =======================================================================
if (!function_exists('get_sottocategorie')) {
    function get_sottocategorie($conn, $p_id) {
        $p_id = (int)$p_id;
        $res  = $conn->query("SELECT * FROM sottocategorie WHERE pagina_id = $p_id ORDER BY ordine ASC");
        $rows = [];
        if ($res) { while ($r = $res->fetch_assoc()) { $rows[] = $r; } }
        return $rows;
    }
}

if (!function_exists('get_ruoli')) {
    function get_ruoli($conn) {
        $res  = $conn->query("SELECT * FROM ruoli ORDER BY id ASC");
        $rows = [];
        if ($res) { while ($r = $res->fetch_assoc()) { $rows[] = $r; } }
        return $rows;
    }
}

// =======================================================================
// ADMIN — CHECK-IN
// =======================================================================
if (!function_exists('get_prenotazione_per_checkin_admin')) {
    function get_prenotazione_per_checkin_admin($conn, string $code): ?array {
        $stmt = $conn->prepare(
            "SELECT pr.id, pr.stato, pr.presente, pr.nome, pr.cognome,
               e.id AS evento_id, e.titolo as evento_titolo,
               e.gestori_utenti_ids as ev_gestori,
               e.permessi_gestori_json as ev_permessi_json,
               pe.gestore_utente_id as pg_gestore_singolo,
               pe.gestori_utenti_ids as pg_gestori,
               pe.permessi_gestori_json as pg_permessi_json
             FROM prenotazioni pr
             JOIN turni t ON pr.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             LEFT JOIN pagine_eventi pe ON e.pagina_id = pe.id
             WHERE pr.codice_prenotazione = ? LIMIT 1"
        );
        $stmt->bind_param("s", $code);
        $stmt->execute();
        $res = $stmt->get_result();
        return ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
    }
}

// =======================================================================
// ADMIN — MESSAGGI
// =======================================================================
if (!function_exists('get_inbox_conversazioni')) {
    function get_inbox_conversazioni($conn, $p_id, $pr_filter_sql = '') {
        $p_id = (int)$p_id;
        $res  = $conn->query(
            "SELECT p.id as prenotazione_id, p.codice_prenotazione, p.nome, p.cognome, p.email,
               e.titolo as evento_titolo, e.pagina_id,
               MAX(m.data_invio) as ultimo_messaggio_data,
               COUNT(m.id) as totale_messaggi,
               SUM(CASE WHEN m.letto = 0 AND m.mittente_tipo = 'utente' THEN 1 ELSE 0 END) as messaggi_da_leggere
             FROM messaggi_prenotazioni m
             JOIN prenotazioni p ON m.prenotazione_id = p.id
             JOIN turni t ON p.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             WHERE e.pagina_id = $p_id $pr_filter_sql
             GROUP BY p.id
             ORDER BY messaggi_da_leggere DESC, ultimo_messaggio_data DESC"
        );
        $rows = [];
        if ($res) { while ($row = $res->fetch_assoc()) { $rows[] = $row; } }
        return $rows;
    }
}

// =======================================================================
// ADMIN — ISCRITTI
// =======================================================================
if (!function_exists('get_prenotazione_con_turno_evento')) {
    function get_prenotazione_con_turno_evento($conn, $pr_id) {
        $pr_id = (int)$pr_id;
        $res = $conn->query(
            "SELECT pr.*, t.id as turno_id, t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine,
                    e.titolo as evento_titolo, e.luogo
             FROM prenotazioni pr
             JOIN turni t ON pr.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             WHERE pr.id = $pr_id LIMIT 1"
        );
        return ($res && $row = $res->fetch_assoc()) ? $row : null;
    }
}

if (!function_exists('get_destinatari_email_massiva')) {
    function get_destinatari_email_massiva($conn, $p_id, $turno_id = 0) {
        $p_id = (int)$p_id;
        $cond = $turno_id > 0 ? " AND t.id = " . (int)$turno_id : "";
        $res  = $conn->query(
            "SELECT pr.email, pr.nome, pr.cognome
             FROM prenotazioni pr
             JOIN turni t ON pr.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             WHERE e.pagina_id = $p_id
               AND IFNULL(pr.stato, 'confermata') = 'confermata'
               $cond
               AND pr.email != ''"
        );
        $dest = [];
        if ($res) { while ($row = $res->fetch_assoc()) { $dest[] = $row; } }
        return $dest;
    }
}

if (!function_exists('get_turno_admin')) {
    function get_turno_admin($conn, $turno_id) {
        $turno_id = (int)$turno_id;
        $res = $conn->query(
            "SELECT t.*, e.titolo as evento_titolo, pe.slug
             FROM turni t
             JOIN eventi e ON t.evento_id = e.id
             LEFT JOIN pagine_eventi pe ON e.pagina_id = pe.id
             WHERE t.id = $turno_id LIMIT 1"
        );
        return ($res && $row = $res->fetch_assoc()) ? $row : null;
    }
}

if (!function_exists('get_campi_custom_export')) {
    function get_campi_custom_export($conn, $p_id) {
        $p_id = (int)$p_id;
        $res  = $conn->query(
            "SELECT DISTINCT nome_campo, etichetta FROM campi_form
             WHERE pagina_id = $p_id OR evento_id IN (SELECT id FROM eventi WHERE pagina_id = $p_id)
             ORDER BY id ASC"
        );
        $cols = [];
        if ($res) { while ($cf = $res->fetch_assoc()) { $cols[$cf['nome_campo']] = $cf['etichetta']; } }
        return $cols;
    }
}

if (!function_exists('get_eventi_con_turni_admin')) {
    function get_eventi_con_turni_admin($conn, $p_id, $is_archivio, $sql_filtro_rbac) {
        $p_id        = (int)$p_id;
        $is_archivio = (int)$is_archivio;
        $eventi = [];
        $res = $conn->query(
            "SELECT id, titolo FROM eventi e
             WHERE e.pagina_id = $p_id AND e.archiviato = $is_archivio $sql_filtro_rbac
             ORDER BY e.ordine ASC, e.id DESC"
        );
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $turni = [];
                $res_t = $conn->query("SELECT * FROM turni WHERE evento_id = {$row['id']} ORDER BY (data_turno IS NULL), data_turno ASC, orario_inizio ASC, nome_turno ASC, id ASC");
                if ($res_t) { while ($t = $res_t->fetch_assoc()) { $turni[] = $t; } }
                $row['turni'] = $turni;
                $eventi[] = $row;
            }
        }
        return $eventi;
    }
}

if (!function_exists('get_messaggi_per_prenotazioni')) {
    function get_messaggi_per_prenotazioni($conn, array $pr_ids) {
        if (empty($pr_ids)) { return []; }
        $ids_str = implode(',', array_map('intval', $pr_ids));
        $res = $conn->query("SELECT * FROM messaggi_prenotazioni WHERE prenotazione_id IN ($ids_str) ORDER BY data_invio ASC");
        $messaggi = [];
        if ($res) { while ($m = $res->fetch_assoc()) { $messaggi[$m['prenotazione_id']][] = $m; } }
        return $messaggi;
    }
}

// Da chiamare subito dopo ogni UPDATE/INSERT su configurazione_portale
// (oggi solo in admin/testata.php), così le nuove impostazioni sono visibili
// immediatamente invece di aspettare la scadenza naturale della cache.
if (!function_exists('invalidate_configurazione_portale_cache')) {
    function invalidate_configurazione_portale_cache() {
        $cache_file = RADICE_SITO . '/cache/configurazione_portale.json';
        if (is_file($cache_file)) { @unlink($cache_file); }
    }
}
