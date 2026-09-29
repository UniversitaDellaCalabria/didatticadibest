<?php
// cron_background.php - Motore Automazioni (Da richiamare ogni 15 minuti tramite crontab Ubuntu)
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
consenti_esecuzione_cron([1]); // solo crontab, chiave CRON_KEY o admin loggato

// =========================================================================
// FASE 3: MUTEX LOCK PER PREVENIRE ESECUZIONI SOVRAPPOSTE (stesso pattern già
// in uso in cron_attestati.php). Se il crontab lancia una nuova esecuzione
// mentre la precedente sta ancora girando (es. invio email lento, tanti
// destinatari), qui evitiamo doppio invio delle email post-evento.
// =========================================================================
$cache_dir = __DIR__ . '/cache';
if (!is_dir($cache_dir)) { @mkdir($cache_dir, 0755, true); }

if (!is_dir($cache_dir) || !is_writable($cache_dir)) {
    die("ERRORE DI CONFIGURAZIONE: la cartella 'cache/' non esiste o non è scrivibile dal server web (" . $cache_dir . "). Crearla manualmente con permessi 755 e riprovare.\n");
}

$lock_file_bg = $cache_dir . '/cron_background.lock';

// Anti lock-orfano: se il lock esiste da più di 10 minuti lo consideriamo residuo
// di un'esecuzione precedente interrotta in modo anomalo e lo rimuoviamo.
if (file_exists($lock_file_bg) && (time() - filemtime($lock_file_bg)) > 600) {
    @unlink($lock_file_bg);
}

$lock_handle_bg = fopen($lock_file_bg, 'w+');

// LOCK_EX = Lock esclusivo | LOCK_NB = Non bloccante (se già in uso, fallisce subito)
if (!$lock_handle_bg || !flock($lock_handle_bg, LOCK_EX | LOCK_NB)) {
    die("PROCESSO IN ESECUZIONE: cron_background.php è già in esecuzione in un altro processo (avviato meno di 10 minuti fa).\n");
}


$now = date('Y-m-d H:i:s');
$sys = $conn->query("SELECT * FROM impostazioni_sistema WHERE id = 1")->fetch_assoc();

echo "Inizio Esecuzione CRON: $now\n";

// =========================================================================
// TASK 0: AUTO-ARCHIVIAZIONE EVENTI SCADUTI
// =========================================================================
$conn->query("UPDATE eventi e SET e.archiviato = 1 WHERE e.archiviato = 0 AND (e.blocca_auto_archivio IS NULL OR e.blocca_auto_archivio = 0) AND (SELECT MAX(data_turno) FROM turni t WHERE t.evento_id = e.id) < CURDATE() AND NOT EXISTS (SELECT 1 FROM turni t2 WHERE t2.evento_id = e.id AND t2.data_turno IS NULL)");
echo "- Auto-archiviati " . $conn->affected_rows . " eventi scaduti.\n";

// =========================================================================
// TASK 1: EMAIL POST-EVENTO (Attestati e Sondaggi)
// =========================================================================
// Seleziona i presenti a eventi finiti, a cui NON è ancora stata mandata l'email
// Progetti: solo a progetto concluso (data di fine passata)
$sql_post = "SELECT pr.*, t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine, t.evento_id, e.titolo as evento_titolo, e.luogo
             FROM prenotazioni pr
             JOIN turni t ON pr.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             LEFT JOIN progetti_dettagli pd ON pd.evento_id = e.id
             WHERE pr.presente = 1
             AND pr.email_post_evento_inviata = 0
             AND (t.data_turno IS NULL OR CONCAT(t.data_turno, ' ', COALESCE(t.orario_fine, '23:59:59')) < '$now')
             AND NOT (IFNULL(e.tipo, 'evento') = 'progetto' AND pd.data_fine IS NOT NULL AND pd.data_fine >= CURDATE())";

$res_post = $conn->query($sql_post);
$count_post = 0;

if ($res_post && $res_post->num_rows > 0) {
    // Calcoliamo l'URL di base dinamicamente (puoi anche hardcodarlo se preferisci)
    $domain = "https://" . ($_SERVER['HTTP_HOST'] ?? 'il-tuo-sito.unical.it') . rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
    $link_area = "<a href='$domain/area_personale.php' style='color:#B30000; font-weight:bold;'>Area Personale</a>";

    while ($p = $res_post->fetch_assoc()) {
        $r_find = ['{NOME}', '{COGNOME}', '{MATRICOLA}', '{TITOLO_EVENTO}', '{DATA_TURNO}', '{ORARIO_TURNO}', '{LUOGO}', '{LINK_AREA_PERSONALE}'];
        $ora_f = orario_turno($p) ?: 'da definire';
        $data_f = implode(' · ', array_filter([$p['nome_turno'] ?? '', !empty($p['data_turno']) ? date('d/m/Y', strtotime($p['data_turno'])) : '']));
        $r_repl = [$p['nome'], $p['cognome'], $p['matricola'], $p['evento_titolo'], $data_f, $ora_f, $p['luogo'], $link_area];

        // 1. Invia Avviso Attestato Disponibile (se configurato e se l'attestato personale esiste:
        //    non nei progetti senza attestati né in quelli per le scuole, dove li riceve il docente per la classe)
        $regola_att = regola_attestato_evento($conn, (int)$p['evento_id']);
        if (!empty($sys['email_attestato_corpo']) && in_array($regola_att, ['evento', 'singolo'], true)) {
            inviaNotificaEmail($p['email'], str_replace($r_find, $r_repl, $sys['email_attestato_oggetto']), str_replace($r_find, $r_repl, $sys['email_attestato_corpo']), $conn, colore_area_turno($conn, $p['turno_id']));
        }
        // 2. Invia Sondaggio (se configurato)
        if (!empty($sys['email_sondaggio_corpo'])) {
            inviaNotificaEmail($p['email'], str_replace($r_find, $r_repl, $sys['email_sondaggio_oggetto']), str_replace($r_find, $r_repl, $sys['email_sondaggio_corpo']), $conn, colore_area_turno($conn, $p['turno_id']));
        }

        // Segna come inviata per non spammare l'utente al prossimo giro di Cron
        $conn->query("UPDATE prenotazioni SET email_post_evento_inviata = 1 WHERE id = {$p['id']}");
        $count_post++;
    }
}
echo "- Inviate $count_post email post-evento (Attestati/Sondaggi).\n";

// =========================================================================
// TASK 1b: PROMEMORIA AL DOCENTE PER L'ELENCO DEGLI STUDENTI
// Prenotazioni di classe con attestati ed elenco ancora vuoto: chi ha prenotato riceve un'email (una sola volta)
// con il link per compilarlo. Progetti per le scuole: a 7 giorni (o meno) dalla fine.
// Eventi con attestati per la classe: da 3 giorni prima del turno fino a 14 giorni dopo.
// =========================================================================
$sql_prom = "SELECT pr.id, pr.nome, pr.cognome, pr.email, pr.codice_prenotazione, pr.turno_id, e.titolo, e.tipo, pd.data_fine, t.data_turno
             FROM prenotazioni pr
             JOIN turni t ON pr.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             JOIN progetti_dettagli pd ON pd.evento_id = e.id
             WHERE e.archiviato = 0 AND pd.attestati = 1
               AND IFNULL(pr.stato, 'confermata') = 'confermata' AND pr.promemoria_elenco_inviato = 0
               AND ((e.tipo = 'progetto' AND pd.per_scuole = 1 AND pd.data_fine BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY)
                 OR (IFNULL(e.tipo, 'evento') <> 'progetto' AND t.data_turno BETWEEN CURDATE() - INTERVAL 14 DAY AND CURDATE() + INTERVAL 3 DAY))
               AND NOT EXISTS (SELECT 1 FROM partecipanti_prenotazione pp WHERE pp.prenotazione_id = pr.id)";
$res_prom = $conn->query($sql_prom);
$count_prom = 0;
while ($res_prom && $pm = $res_prom->fetch_assoc()) {
    if (empty($pm['email'])) continue;
    $link_el = url_base_sito() . '/elenco_studenti.php?code=' . urlencode($pm['codice_prenotazione']);
    $corpo_pm = "<p>Gentile <strong>" . htmlspecialchars($pm['nome'] . ' ' . $pm['cognome']) . "</strong>,</p>"
              . ($pm['tipo'] === 'progetto'
                  ? "<p>il progetto <strong>" . htmlspecialchars($pm['titolo']) . "</strong> si conclude il <strong>" . date('d/m/Y', strtotime($pm['data_fine'])) . "</strong>.</p>"
                  : "<p>l'attività <strong>" . htmlspecialchars($pm['titolo']) . "</strong> " . ($pm['data_turno'] < date('Y-m-d') ? "si è svolta" : "si svolge") . " il <strong>" . date('d/m/Y', strtotime($pm['data_turno'])) . "</strong>.</p>")
              . "<p>Per ricevere gli <strong>attestati di partecipazione</strong> dei tuoi studenti inserisci il loro elenco (cognome e nome): puoi scriverlo, incollarlo da Excel o caricare il modello compilato.</p>"
              . "<p style='text-align:center; margin:28px 0;'><a href='" . htmlspecialchars($link_el) . "' style='background-color:#198754; color:white; padding:12px 24px; text-decoration:none; border-radius:6px; font-weight:bold;'>Inserisci l'elenco degli studenti</a></p>";
    inviaNotificaEmail($pm['email'], "Promemoria: elenco degli studenti per gli attestati - " . $pm['titolo'], $corpo_pm, $conn, colore_area_turno($conn, (int)$pm['turno_id']));
    $conn->query("UPDATE prenotazioni SET promemoria_elenco_inviato = 1 WHERE id = " . (int)$pm['id']);
    $count_prom++;
}
echo "- Inviati $count_prom promemoria per l'elenco degli studenti.\n";

// Attestati degli studenti ad attività conclusa (se il cron degli attestati non è pianificato a parte):
// progetti per le scuole dopo la data di fine, eventi con attestati per la classe dopo il giorno del turno
$res_grp = $conn->query("SELECT pr.id FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
                         JOIN progetti_dettagli pd ON pd.evento_id = e.id
                         WHERE pd.attestati = 1
                           AND ((e.tipo = 'progetto' AND pd.per_scuole = 1 AND pd.data_fine < CURDATE())
                             OR (IFNULL(e.tipo, 'evento') <> 'progetto' AND (t.data_turno IS NULL OR t.data_turno < CURDATE())))
                           AND pr.presente = 1 AND IFNULL(pr.stato, 'confermata') = 'confermata' AND pr.attestato_inviato = 0");
$count_grp = 0;
while ($res_grp && $g = $res_grp->fetch_assoc()) { if (invia_attestati_gruppo($conn, (int)$g['id']) === true) $count_grp++; }
echo "- Inviati attestati degli studenti per $count_grp iscrizioni.\n";

// =========================================================================
// TASK 1c: CONSERVAZIONE DEI NOMI DEGLI STUDENTI (privacy)
// - Iscrizioni annullate/rifiutate/scadute: l'elenco senza attestati emessi non serve più e si cancella.
// - Dopo MESI_CONSERVAZIONE_STUDENTI dalla fine del progetto o dal giorno dell'evento (o dall'inserimento, se non
//   c'è una data) i nomi si riducono alle iniziali: i codici degli attestati restano verificabili.
// =========================================================================
$conn->query("DELETE pp FROM partecipanti_prenotazione pp JOIN prenotazioni pr ON pp.prenotazione_id = pr.id
              WHERE pr.stato IN ('annullata', 'rifiutata', 'scaduta') AND pp.codice IS NULL");
$cancellati_pp = $conn->affected_rows;
$mesi_cons = (int)MESI_CONSERVAZIONE_STUDENTI;
$conn->query("UPDATE partecipanti_prenotazione pp
              JOIN prenotazioni pr ON pp.prenotazione_id = pr.id
              JOIN turni t ON pr.turno_id = t.id
              LEFT JOIN progetti_dettagli pd ON pd.evento_id = t.evento_id
              SET pp.cognome = CONCAT(LEFT(pp.cognome, 1), '.'),
                  pp.nome = IF(pp.nome = '', '', CONCAT(LEFT(pp.nome, 1), '.')),
                  pp.anonimizzato = 1
              WHERE pp.anonimizzato = 0 AND COALESCE(pd.data_fine, t.data_turno, DATE(pp.created_at)) < CURDATE() - INTERVAL $mesi_cons MONTH");
echo "- Elenchi studenti: $cancellati_pp nomi cancellati (iscrizioni annullate), " . $conn->affected_rows . " ridotti alle iniziali dopo $mesi_cons mesi.\n";

// =========================================================================
// TASK 1e: CONSERVAZIONE DEI DATI (art. 5.1.e GDPR) - durate nel file .env, descritte in privacy.php
// - Registri tecnici (accessi con IP e browser, email inviate): CONSERVAZIONE_LOG_MESI, predefinito 12
// - Registro delle azioni amministrative: CONSERVAZIONE_AUDIT_MESI, predefinito 24
// - Prenotazioni: CONSERVAZIONE_PRENOTAZIONI_MESI dopo l'attività (0 = mai). Non si cancellano: nome e cognome
//   ridotti alle iniziali, email/matricola/campi del modulo svuotati, messaggi e allegati eliminati.
//   Restano codice, turno, stato e presenza: statistiche e codici degli attestati continuano a funzionare.
// - Account senza accesso da CONSERVAZIONE_UTENTI_MESI (0 = mai), esclusi amministratori e gestori.
// =========================================================================
$mesi_log   = max(1, (int)(env_valore('CONSERVAZIONE_LOG_MESI') ?? 12));
$mesi_audit = max(1, (int)(env_valore('CONSERVAZIONE_AUDIT_MESI') ?? 24));
@$conn->query("DELETE FROM log_accessi WHERE created_at < NOW() - INTERVAL $mesi_log MONTH");
$tolti_acc = max(0, $conn->affected_rows);
@$conn->query("DELETE FROM log_email WHERE created_at < NOW() - INTERVAL $mesi_log MONTH");
$tolti_em = max(0, $conn->affected_rows);
@$conn->query("DELETE FROM log_attivita WHERE data_ora < NOW() - INTERVAL $mesi_audit MONTH");
$tolti_aud = max(0, $conn->affected_rows);
echo "- Conservazione registri: eliminati $tolti_acc accessi e $tolti_em email più vecchi di $mesi_log mesi, $tolti_aud azioni più vecchie di $mesi_audit mesi.\n";

$mesi_pren = max(0, (int)(env_valore('CONSERVAZIONE_PRENOTAZIONI_MESI') ?? 0));
if ($mesi_pren > 0) {
    $res_an = $conn->query("SELECT pr.id, pr.dati_custom_json FROM prenotazioni pr
                            JOIN turni t ON pr.turno_id = t.id LEFT JOIN progetti_dettagli pd ON pd.evento_id = t.evento_id
                            WHERE IFNULL(pr.email, '') <> ''
                              AND COALESCE(pd.data_fine, t.data_turno, DATE(pr.data_prenotazione)) < CURDATE() - INTERVAL $mesi_pren MONTH
                            LIMIT 500");
    $n_an = 0;
    while ($res_an && $pa = $res_an->fetch_assoc()) {
        $id_an = (int)$pa['id'];
        // Allegati caricati nel modulo (solo file dentro uploads/allegati_prenotazioni)
        foreach ((json_decode((string)$pa['dati_custom_json'], true) ?: []) as $val) {
            if (!is_string($val)) continue;
            foreach (array_map('trim', explode(',', $val)) as $perc) {
                if (preg_match('#^uploads/allegati_prenotazioni/[a-f0-9]{32}\.[a-z0-9]{2,5}$#', $perc)) @unlink(__DIR__ . '/' . $perc);
            }
        }
        @$conn->query("DELETE FROM messaggi_prenotazioni WHERE prenotazione_id = $id_an");
        $conn->query("UPDATE prenotazioni SET nome = CONCAT(LEFT(nome, 1), '.'), cognome = IF(cognome = '', '', CONCAT(LEFT(cognome, 1), '.')),
                      email = '', matricola = '', dati_custom_json = NULL, utente_id = NULL WHERE id = $id_an");
        $n_an++;
    }
    echo "- Conservazione prenotazioni: $n_an anonimizzate (attività concluse da più di $mesi_pren mesi).\n";
}

$mesi_ut = max(0, (int)(env_valore('CONSERVAZIONE_UTENTI_MESI') ?? 0));
if ($mesi_ut > 0) {
    // Esclusi anche i gestori abilitati da "Utenti & Abilitazioni" su un'area o un evento,
    // qualunque sia il loro ruolo (spesso Dipendente): perderebbero l'accesso al pannello
    $ids_gestori = [];
    foreach (['pagine_eventi' => 'gestore_utente_id', 'eventi' => '0'] as $tab_g => $col_singolo) {
        $r_g = $conn->query("SELECT $col_singolo AS singolo, gestori_utenti_ids, permessi_gestori_json FROM $tab_g");
        while ($r_g && $g = $r_g->fetch_assoc()) {
            foreach (ids_gestori_da_campi($g['singolo'], $g['gestori_utenti_ids'], $g['permessi_gestori_json']) as $id_g) $ids_gestori[$id_g] = true;
        }
    }
    $esclusi_gestori = $ids_gestori ? ' AND id NOT IN (' . implode(',', array_map('intval', array_keys($ids_gestori))) . ')' : '';
    $cond_ut = "ruolo_id NOT IN (1, 2) AND FIND_IN_SET('1', IFNULL(ruoli_secondari, '')) = 0 AND FIND_IN_SET('2', IFNULL(ruoli_secondari, '')) = 0
                AND COALESCE(ultimo_accesso, '1970-01-01') < NOW() - INTERVAL $mesi_ut MONTH" . $esclusi_gestori;
    // Le prenotazioni restano (collegate all'email, o già anonimizzate): si toglie solo il legame con l'account
    $conn->query("UPDATE prenotazioni SET utente_id = NULL WHERE utente_id IN (SELECT id FROM (SELECT id FROM utenti WHERE $cond_ut) AS x)");
    $conn->query("DELETE FROM utenti WHERE $cond_ut");
    echo "- Conservazione account: " . max(0, $conn->affected_rows) . " account eliminati (nessun accesso da $mesi_ut mesi).\n";
}

// =========================================================================
// TASK 1d: RIEPILOGO SETTIMANALE DELLE EMAIL AGLI AMMINISTRATORI (dal lunedì, una volta a settimana)
// =========================================================================
if (date('N') >= 1) {
    $esito_rep = invia_report_email_settimanale($conn);
    echo "- Riepilogo settimanale email: " . ($esito_rep === true ? "inviato agli amministratori" : $esito_rep) . ".\n";
}

// =========================================================================
// TASK 2: PROMEMORIA PRE-EVENTO E ALTRE AUTOMAZIONI
// =========================================================================
$file_reminders = __DIR__ . '/admin/cron_reminders.php';
if (file_exists($file_reminders)) {
    ob_start();
    include $file_reminders;
    ob_end_clean();
    echo "- Promemoria (admin/cron_reminders.php) eseguiti.\n";
}

echo "Esecuzione CRON terminata con successo.\n";

// Rilascio esplicito del lock (viene comunque rilasciato dal sistema alla chiusura dello script)
flock($lock_handle_bg, LOCK_UN);
fclose($lock_handle_bg);
?>
