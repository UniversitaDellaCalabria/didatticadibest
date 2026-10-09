<?php
// eventi.php - Gestione Eventi e Turni (RBAC Pulito e Isolamento Eventi)
require_once 'admin_header.php';

if (!$can_manage_eventi) {
    echo "<div class='alert alert-danger fw-bold shadow-sm'><i class='fa fa-ban me-2'></i> Accesso negato. Non hai i permessi per gestire gli eventi in quest'area.</div>";
    require_once 'admin_footer.php';
    exit;
}

function admin_redirect($url) { echo "<script>window.location.replace('$url');</script>"; exit; }

// Filtro evento anche nei POST (i form lo inviano come campo nascosto), per tornare alla stessa vista
$filtro_ev = isset($_GET['f_ev']) ? (int)$_GET['f_ev'] : (int)($_POST['f_ev'] ?? 0);

// Mostra l'errore invece della pagina bianca
set_exception_handler(function (Throwable $e) {
    error_log('[admin/eventi.php] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    echo "<div class='alert alert-danger fw-bold m-4'><i class='fa fa-bug me-2'></i>Errore durante il salvataggio: "
       . htmlspecialchars($e->getMessage()) . " <small class='d-block fw-normal mt-1'>(riga " . (int)$e->getLine() . ")</small></div>";
    exit;
});

// Data e ora da <input type="datetime-local"> (o già in formato MySQL) -> 'Y-m-d H:i:s', altrimenti null
function data_ora_turno($v): ?string {
    $v = trim((string)$v);
    if ($v === '') return null;
    $d = DateTime::createFromFormat('Y-m-d\TH:i', $v) ?: DateTime::createFromFormat('Y-m-d H:i:s', $v) ?: DateTime::createFromFormat('Y-m-d H:i', $v);
    return $d ? $d->format('Y-m-d H:i:s') : null;
}

// Turni dalla pagina dell'evento: una riga per turno (t_id = turno esistente, 0 = nuovo).
// Righe nuove completamente vuote ignorate; ogni turno deve avere almeno il nome oppure la data.
// $classe = evento con attestati per la classe: si leggono anche min/max studenti.
function leggi_turni_post(bool $classe, array &$errori): array {
    $turni = [];
    $txt = fn($k, $i, $max = 150) => mb_substr(trim((string)($_POST[$k][$i] ?? '')), 0, $max);
    foreach ((array)($_POST['t_id'] ?? []) as $i => $id) {
        $t = [
            'id'   => (int)$id,
            'nome' => $txt('t_nome', $i) ?: null,
            'data' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $txt('t_data', $i)) ? $txt('t_data', $i) : null,
            'in'   => preg_match('/^\d{2}:\d{2}/', $txt('t_in', $i)) ? substr($txt('t_in', $i), 0, 5) : null,
            'fi'   => preg_match('/^\d{2}:\d{2}/', $txt('t_fi', $i)) ? substr($txt('t_fi', $i), 0, 5) : null,
            'max'  => max(1, min(99999, (int)($_POST['t_posti'][$i] ?? 30))),
            'ap'   => data_ora_turno($_POST['t_ap'][$i] ?? ''),
            'ch'   => data_ora_turno($_POST['t_ch'][$i] ?? ''),
            'ann'  => data_ora_turno($_POST['t_ann'][$i] ?? ''),
            'min'  => $classe && preg_match('/^\d+$/', $txt('t_min', $i)) ? (int)$txt('t_min', $i) : null,
            'maxs' => $classe && preg_match('/^\d+$/', $txt('t_maxs', $i)) ? (int)$txt('t_maxs', $i) : null,
            'wa'   => ($_POST['t_wa'][$i] ?? '0') === '1' ? 1 : 0,
            'mp'   => ($_POST['t_mp'][$i] ?? '0') === '1' ? 1 : 0,
            'app'  => ($_POST['t_app'][$i] ?? '0') === '1' ? 1 : 0,
            // Aula di Prenotazioni e risorse occupata dal turno (0 = nessuna)
            'aula' => (int)($_POST['t_aula'][$i] ?? 0) ?: null,
        ];
        $vuota = $t['nome'] === null && $t['data'] === null;
        if ($vuota && $t['id'] === 0) continue;
        $nome_err = $t['nome'] ?? ($t['data'] ? date('d/m/Y', strtotime($t['data'])) : 'turno ' . ($i + 1));
        if ($vuota) $errori[] = "turno " . ($i + 1) . ": indica almeno il nome oppure la data";
        if ($t['ap'] && $t['ch'] && $t['ch'] <= $t['ap']) $errori[] = "$nome_err: la chiusura delle prenotazioni è precedente all'apertura";
        if ($t['in'] && $t['fi'] && $t['fi'] <= $t['in']) $errori[] = "$nome_err: l'orario di fine è precedente all'inizio";
        if ($t['min'] && $t['maxs'] && $t['min'] > $t['maxs']) $errori[] = "$nome_err: il minimo di studenti supera il massimo";
        $turni[] = $t;
        if (count($turni) >= 100) break;
    }
    return $turni;
}

// Posti liberati (capienza aumentata): conferma chi è in lista d'attesa, in ordine di arrivo, e lo avvisa per email
function promuovi_attesa_turno($conn, int $t_id): int {
    return \App\Core\App::per($conn)->get(\App\Eventi\ServizioTurni::class)->promuoviAttesa($t_id);
}

// Salva i turni dell'evento: aggiorna gli esistenti, crea i nuovi, elimina quelli tolti dalla pagina
// (solo se senza prenotazioni attive). Ritorna quante persone sono state confermate dalla lista d'attesa.
function salva_turni_evento($conn, int $ev_id, array $turni, array &$avvisi): int {
    return \App\Core\App::per($conn)->get(\App\Eventi\ServizioTurni::class)->salvaTurniEvento($ev_id, $turni, $avvisi, (int)($_SESSION['utente_id'] ?? 0));
}

// Opzione "Attestati per gli studenti della classe": progetti_dettagli.attestati dell'evento
// "Dedicato alle scuole" (dedicata_scuole). La Formazione Scuola Lavoro (convenzione: domanda sulla convenzione nel modulo e tutto il
// processo delle convenzioni, programma della scuola, documenti) è dei PROGETTI: spuntando «Formazione Scuola Lavoro» nel modulo evento si apre
// il modulo del progetto (admin/progetti.php) e l'evento diventa un progetto. Qui il flag esistente non si cambia.
function salva_attestati_classe_evento($conn, int $ev_id, int $attivi): void {
    $conv = (int)(get_dettagli_progetti($conn, [$ev_id])[$ev_id]['convenzione'] ?? 0);   // invariato: si cambia solo trasformando l'evento in progetto
    $scuole = ($conv || isset($_POST['dedicata_scuole'])) ? 1 : 0;
    \App\Core\App::per($conn)->get(\App\Eventi\ProgettoRepository::class)->salvaOpzioniScuole($ev_id, $attivi, $conv, $scuole);
}

// Creazione di un nuovo evento: come il pulsante "Crea evento" (solo amministratori)
$puo_creare_ev = $puo_creare_eventi; // amministratori, chi gestisce tutta l'area o tutti gli eventi dell'area

$repo_eventi = \App\Core\App::per($conn)->get(\App\Eventi\EventoRepository::class);
$repo_turni  = \App\Core\App::per($conn)->get(\App\Eventi\TurnoRepository::class);

if (isset($_POST['duplica_turno'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $t_id = (int)$_POST['duplica_turno'];
    if (!turno_autorizzato($conn, $t_id, $filtro_p, $sql_filtro_eventi_rbac)) nega_accesso();
    $ev_id = $repo_turni->eventoDelTurno($t_id);
    $nuovo = duplica_turno($conn, $t_id, $ev_id, true);
    if (function_exists('registra_log_audit')) registra_log_audit($conn, "Duplicazione Turno", ["Turno origine" => $t_id, "Nuovo turno" => $nuovo]);
    flash_set("Turno duplicato: in fondo all'elenco dei turni trovi la copia, modifica data e orari e salva.");
    admin_redirect("eventi.php?p_id=$filtro_p&id=$ev_id&r=" . time() . "#turni");
}

if (isset($_POST['duplica_evento'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $ev_id = (int)$_POST['duplica_evento'];
    if (!ev_autorizzato($conn, $ev_id, $filtro_p, $sql_filtro_eventi_rbac)) nega_accesso();
    try {
        $copia = duplica_evento($conn, $ev_id, true);
    } catch (Throwable $e) {
        error_log('[duplica_evento] ' . $e->getMessage());
        flash_set("Duplicazione non riuscita: " . $e->getMessage(), 'danger');
        admin_redirect("eventi.php?p_id=$filtro_p&f_ev=$filtro_ev");
    }
    [$nuovo_ev, $n_turni, $n_sond] = [$copia['evento'], $copia['turni'], $copia['sondaggi']];
    if (function_exists('registra_log_audit')) registra_log_audit($conn, "Duplicazione Evento", ["Evento origine" => $ev_id, "Nuovo evento" => $nuovo_ev, "Turni" => $n_turni, "Sondaggi" => $n_sond]);
    flash_set("Evento duplicato con $n_turni turni" . ($n_sond ? " e $n_sond sondaggio" . ($n_sond > 1 ? "i" : "") . " (da attivare)" : "") . ", senza iscritti: controlla titolo e date della copia.");
    admin_redirect("eventi.php?p_id=$filtro_p&id=$nuovo_ev");
}


// Sezione "affiancata in alto" nel layout Griglia (attiva/disattiva)
if (isset($_POST['toggle_sezione_alto'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $sub_id = (int)$_POST['toggle_sezione_alto'];
    $val = (int)($_POST['val'] ?? 0) === 1 ? 1 : 0;
    $repo_eventi->impostaSezioneInAlto($sub_id, (int)$filtro_p, $val);
    flash_set($val ? "La sezione sarà mostrata in alto, affiancata alle altre (layout Griglia)." : "La sezione tornerà nell'elenco normale.");
    admin_redirect("eventi.php?p_id=$filtro_p&f_ev=$filtro_ev");
}
if (isset($_POST['add_sottocategoria'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $p_id = $filtro_p; // i permessi sono calcolati sull'area corrente: non fidarsi del campo POST
    $nome_sub = $_POST['nome_sottocategoria'] ?? '';
    $ord_sub = (int)($_POST['ordine_sottocategoria'] ?? 0);
    $affiancata = isset($_POST['affiancata_in_alto']) ? 1 : 0;
    $repo_eventi->creaSezione((int)$p_id, (string)$nome_sub, $ord_sub, $affiancata);
    if (function_exists('registra_log_audit')) registra_log_audit($conn, "Creazione Sezione", ["Nome" => $_POST['nome_sottocategoria']]);
    flash_set("Sezione creata con successo!");
    admin_redirect("eventi.php?p_id=$p_id");
}

if (isset($_POST['add_evento'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    if (!$puo_creare_ev) nega_accesso();
    $sub_id = !empty($_POST['sottocategoria_id']) ? (int)$_POST['sottocategoria_id'] : null;
    $titolo = trim((string)($_POST['titolo'] ?? ''));
    if ($titolo === '') { flash_set("Il titolo dell'evento è obbligatorio.", 'danger'); admin_redirect("eventi.php?p_id=$filtro_p&azione=nuovo"); }
    $luogo = $_POST['luogo'] ?? '';
    $desc = $_POST['descrizione'] ?? '';
    $desc_breve = pulisci_descrizione_breve((string)($_POST['descrizione_breve'] ?? ''));
    $desc_breve = $desc_breve === '' ? null : $desc_breve;
    $ord = (int)($_POST['ordine_evento'] ?? 0);
    $evid = isset($_POST['is_evidenza']) ? 1 : 0;
    $req_pren = isset($_POST['richiede_prenotazione']) ? 1 : 0;
    $abilita_pres = isset($_POST['abilita_presenze']) ? 1 : 0;
    $ruolo_acc = (int)($_POST['ruolo_accesso_id'] ?? 0);
    $att_classe = isset($_POST['attestati_classe']) ? 1 : 0;
    if ($att_classe) $abilita_pres = 1; // gli attestati della classe richiedono il check-in
    $classe_ev = $att_classe || isset($_POST['dedicata_scuole']); // prenota il docente per la classe
    $errori_t = [];
    $turni_post = leggi_turni_post($classe_ev, $errori_t);
    if ($errori_t) { flash_set("Evento non creato: " . implode('; ', $errori_t) . ".", 'danger'); admin_redirect("eventi.php?p_id=$filtro_p&azione=nuovo"); }

    $upload_dir = dirname(__DIR__) . '/uploads/';
    $locandina_path = "";
    if (isset($_FILES['locandina_file'])) {
        $fn = secure_upload($_FILES['locandina_file'], $upload_dir, ['jpg','jpeg','png','gif','webp'], ['image/jpeg','image/png','image/gif','image/webp']);
        if ($fn) $locandina_path = "uploads/$fn";
    }

    $allegato_pdf = null;
    if (isset($_FILES['allegato_pdf'])) {
        $fn = secure_upload($_FILES['allegato_pdf'], $upload_dir, ['pdf'], ['application/pdf']);
        if ($fn) $allegato_pdf = "uploads/$fn";
    }

    $ev_id = $repo_eventi->inserisciEvento((int)$filtro_p, ['sub_id' => $sub_id, 'titolo' => $titolo, 'luogo' => $luogo, 'desc' => $desc, 'locandina' => $locandina_path, 'pdf' => $allegato_pdf,
        'evid' => $evid, 'req_pren' => $req_pren, 'abilita_pres' => $abilita_pres, 'ruolo_acc' => $ruolo_acc, 'ord' => $ord]);
    // Indirizzi aggiuntivi per le notifiche delle prenotazioni (validati, max 10)
    $notif_extra = normalizza_lista_email($_POST['email_notifiche_extra'] ?? '', 10, $notif_scartati);
    $notif_csv = $notif_extra ? implode(',', $notif_extra) : null;
    $repo_eventi->impostaNotificheExtra($ev_id, $notif_csv);
    $repo_eventi->impostaDescrizioneBreve($ev_id, $desc_breve);
    $avviso_notif = $notif_scartati ? " Indirizzi non validi ignorati: " . htmlspecialchars(implode(', ', $notif_scartati)) . "." : '';
    $ref_ev = leggi_referenti_post($ref_scartate);
    if ($ref_ev) salva_referenti_evento($conn, $ev_id, $ref_ev);
    salva_corso_evento($conn, $ev_id);
    $repo_eventi->impostaAmbiti((int)$ev_id, ambiti_da_post($_POST, $page_cfg));
    salva_seminario_evento($conn, (int)$ev_id, $_POST, $_FILES);
    if ($ref_scartate) $avviso_notif .= " Email dei referenti non valide ignorate: " . implode(", ", $ref_scartate) . ".";

    salva_attestati_classe_evento($conn, $ev_id, $att_classe);
    salva_insegnamento_evento($conn, $ev_id);
    if ($classe_ev) assicura_campi_progetto($conn, $filtro_p); // campo "numero di partecipanti" del modulo
    $avvisi_t = [];
    salva_turni_evento($conn, $ev_id, $turni_post, $avvisi_t);
    if ($avvisi_t) $avviso_notif .= " Attenzione: " . implode('; ', $avvisi_t) . ".";

    if (function_exists('registra_log_audit')) registra_log_audit($conn, "Creazione Evento", ["Evento ID" => $ev_id]);
    flash_set("Evento creato con " . count($turni_post) . " " . (count($turni_post) === 1 ? 'turno' : 'turni') . "!" . $avviso_notif, $avviso_notif ? 'warning' : 'success');
    admin_redirect("eventi.php?p_id=$filtro_p&f_ev=$ev_id");
}

if (isset($_POST['edit_evento'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $ev_id = (int)$_POST['evento_id'];
    if (!ev_autorizzato($conn, $ev_id, $filtro_p, $sql_filtro_eventi_rbac)) nega_accesso();
    $sub_id = !empty($_POST['sottocategoria_id']) ? (int)$_POST['sottocategoria_id'] : null;
    $titolo = trim((string)($_POST['titolo'] ?? ''));
    if ($titolo === '') { flash_set("Il titolo dell'evento è obbligatorio.", 'danger'); admin_redirect("eventi.php?p_id=$filtro_p&id=$ev_id"); }
    $luogo = $_POST['luogo'] ?? '';
    $desc = $_POST['descrizione'] ?? '';
    $desc_breve = pulisci_descrizione_breve((string)($_POST['descrizione_breve'] ?? ''));
    $desc_breve = $desc_breve === '' ? null : $desc_breve;
    $ord = (int)($_POST['ordine_evento'] ?? 0);
    $evid = isset($_POST['is_evidenza']) ? 1 : 0;
    $req_pren = isset($_POST['richiede_prenotazione']) ? 1 : 0;
    $abilita_pres = isset($_POST['abilita_presenze']) ? 1 : 0;
    $ruolo_acc = (int)($_POST['ruolo_accesso_id'] ?? 0);
    $att_classe = isset($_POST['attestati_classe']) ? 1 : 0;
    if ($att_classe) $abilita_pres = 1; // gli attestati della classe richiedono il check-in
    $classe_ev = $att_classe || isset($_POST['dedicata_scuole']); // prenota il docente per la classe
    $errori_t = [];
    $turni_post = leggi_turni_post($classe_ev, $errori_t);
    if ($errori_t) { flash_set("Evento non salvato: " . implode('; ', $errori_t) . ".", 'danger'); admin_redirect("eventi.php?p_id=$filtro_p&id=$ev_id"); }

    if (isset($_POST['elimina_locandina']) && $_POST['elimina_locandina'] == '1') $repo_eventi->eliminaLocandina($ev_id);
    if (isset($_POST['elimina_pdf']) && $_POST['elimina_pdf'] == '1') $repo_eventi->eliminaAllegatoPdf($ev_id);

    $upload_dir = dirname(__DIR__) . '/uploads/';
    $new_locandina = null;
    if (isset($_FILES['locandina_file'])) {
        $fn = secure_upload($_FILES['locandina_file'], $upload_dir, ['jpg','jpeg','png','gif','webp'], ['image/jpeg','image/png','image/gif','image/webp']);
        if ($fn) $new_locandina = "uploads/$fn";
    }

    $new_pdf = null;
    if (isset($_FILES['allegato_pdf'])) {
        $fn = secure_upload($_FILES['allegato_pdf'], $upload_dir, ['pdf'], ['application/pdf']);
        if ($fn) $new_pdf = "uploads/$fn";
    }

    $repo_eventi->aggiornaEvento($ev_id, ['sub_id' => $sub_id, 'titolo' => $titolo, 'luogo' => $luogo, 'desc' => $desc, 'ord' => $ord, 'evid' => $evid, 'req_pren' => $req_pren,
        'abilita_pres' => $abilita_pres, 'ruolo_acc' => $ruolo_acc], $new_locandina, $new_pdf);
    // Indirizzi aggiuntivi per le notifiche delle prenotazioni (validati, max 10)
    $notif_extra = normalizza_lista_email($_POST['email_notifiche_extra'] ?? '', 10, $notif_scartati);
    $notif_csv = $notif_extra ? implode(',', $notif_extra) : null;
    $repo_eventi->impostaNotificheExtra($ev_id, $notif_csv);
    $repo_eventi->impostaDescrizioneBreve($ev_id, $desc_breve);
    $avviso_notif = $notif_scartati ? " Indirizzi non validi ignorati: " . htmlspecialchars(implode(', ', $notif_scartati)) . "." : '';
    $ref_ev = leggi_referenti_post($ref_scartate);
    salva_referenti_evento($conn, $ev_id, $ref_ev);
    salva_corso_evento($conn, $ev_id);
    $repo_eventi->impostaAmbiti((int)$ev_id, ambiti_da_post($_POST, $page_cfg));
    salva_seminario_evento($conn, (int)$ev_id, $_POST, $_FILES);
    if ($ref_scartate) $avviso_notif .= " Email dei referenti non valide ignorate: " . implode(", ", $ref_scartate) . ".";
    salva_attestati_classe_evento($conn, $ev_id, $att_classe);
    salva_insegnamento_evento($conn, $ev_id);
    if ($classe_ev) assicura_campi_progetto($conn, $filtro_p); // campo "numero di partecipanti" del modulo
    $avvisi_t = [];
    $promossi = salva_turni_evento($conn, $ev_id, $turni_post, $avvisi_t);
    if ($avvisi_t) $avviso_notif .= " Attenzione: " . implode('; ', $avvisi_t) . ".";
    if (function_exists('registra_log_audit')) registra_log_audit($conn, "Modifica Evento", ["Evento ID" => $ev_id, "Turni" => count($turni_post)]);
    flash_set("Evento modificato!" . ($promossi > 0 ? " Confermate $promossi prenotazioni dalla lista d'attesa." : "") . $avviso_notif, $avviso_notif ? 'warning' : 'success');
    admin_redirect("eventi.php?p_id=$filtro_p&f_ev=$filtro_ev");
}

if (isset($_POST['archivia_conclusi'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $now = date('Y-m-d H:i:s');
    $repo_eventi->archiviaConclusi((int)$filtro_p, $now);
    if (function_exists('registra_log_audit')) registra_log_audit($conn, "Archiviazione Bulk Eventi", ["Pagina ID" => $filtro_p]);
    flash_set("Eventi passati archiviati.");
    admin_redirect("eventi.php?p_id=$filtro_p&f_ev=$filtro_ev");
}
if (isset($_POST['archivia_ev'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $arch_ev_id = (int)$_POST['archivia_ev'];
    if (!ev_autorizzato($conn, $arch_ev_id, $filtro_p, $sql_filtro_eventi_rbac)) nega_accesso();
    $repo_eventi->archivia($arch_ev_id);
    if (function_exists('registra_log_audit')) registra_log_audit($conn, "Archiviazione Evento", ["Evento ID" => $arch_ev_id]);
    flash_set("Evento archiviato!");
    admin_redirect("eventi.php?p_id=$filtro_p&f_ev=$filtro_ev");
}
if (isset($_POST['del_ev'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $ev_id = (int)$_POST['del_ev'];
    if (!ev_autorizzato($conn, $ev_id, $filtro_p, $sql_filtro_eventi_rbac)) nega_accesso();
    $ev_titolo_log = $repo_eventi->titolo($ev_id);
    $ok_del = elimina_evento($conn, $ev_id); // turni, prenotazioni, messaggi, campi form, sondaggi con domande e risposte
    if (function_exists('registra_log_audit')) registra_log_audit($conn, "Eliminazione Evento", ["Evento ID" => $ev_id, "Titolo" => $ev_titolo_log]);
    flash_set($ok_del ? "Evento eliminato!" : "Eliminazione non riuscita: riprova.", $ok_del ? 'success' : 'danger');
    admin_redirect("eventi.php?p_id=$filtro_p&f_ev=$filtro_ev");
}
if (isset($_POST['del_turno'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $t_id = (int)$_POST['del_turno'];
    if (!turno_autorizzato($conn, $t_id, $filtro_p, $sql_filtro_eventi_rbac)) nega_accesso();
    elimina_turno($conn, $t_id); // prenotazioni e messaggi collegati compresi
    if (function_exists('registra_log_audit')) registra_log_audit($conn, "Eliminazione Turno", ["Turno ID" => $t_id]);
    flash_set("Turno eliminato!");
    admin_redirect("eventi.php?p_id=$filtro_p&f_ev=$filtro_ev");
}

$sottocategorie = get_sottocategorie($conn, $filtro_p);
$ruoli          = get_ruoli($conn);

// =====================================================================
// SCHEDA DELL'EVENTO IN PAGINA (nuovo o modifica), stessa impostazione di progetti.php
// =====================================================================
$id_modifica = (int)($_GET['id'] ?? 0);
$mostra_form = ($_GET['azione'] ?? '') === 'nuovo' || $id_modifica > 0;

if ($mostra_form):
    $turni_ev = [];
    if ($id_modifica > 0) {
        if (!ev_autorizzato($conn, $id_modifica, $filtro_p, $sql_filtro_eventi_rbac)) nega_accesso();
        $ev = $repo_eventi->perId($id_modifica) ?: [];
        if (($ev['tipo'] ?? '') === 'progetto') admin_redirect("progetti.php?p_id=$filtro_p&id=$id_modifica");
        $turni_ev = $repo_turni->conIscritti($id_modifica, true);
        $dett_f = get_dettagli_progetti($conn, [$id_modifica])[$id_modifica] ?? [];
        $referenti = $dett_f['referenti'] ?? [];
    } else {
        if (!$puo_creare_ev) nega_accesso();
        $ev = []; $referenti = []; $dett_f = [];
    }
    $att_classe_v = (int)($dett_f['attestati'] ?? 0) === 1;
    $fsl_v = (int)($dett_f['convenzione'] ?? 0) === 1;   // evento già marcato FSL (da trasformare in progetto)
    $scuole_v = $fsl_v || (int)($dett_f['dedicata_scuole'] ?? 0) === 1;
    // Nuovo evento in un'area di Formazione Scuola Lavoro: "Dedicato alle scuole" già acceso
    if (empty($ev) && tipo_area($page_cfg) === 'fsl') { $scuole_v = true; }
    if (!$referenti) $referenti = [['ruolo' => 'Referente', 'notifiche' => 1]];
    $col_f = colore_valido($page_cfg['colore_primario'] ?? '', '#0056B3');
    $txt_f = colore_testo_su($col_f);
    $slug_f = $page_cfg['slug'] ?? '';
    $v = fn($k) => h((string)($ev[$k] ?? ''));
    $nuovo = $id_modifica === 0;
    $sw = fn($k, $pred) => ($nuovo ? $pred : (int)($ev[$k] ?? $pred) === 1) ? 'checked' : '';
?>
<style>
.pj-sez { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:1.25rem 1.25rem .75rem; margin-bottom:1rem; box-shadow:0 1px 4px rgba(0,0,0,.04); }
.pj-sez h2 { font-size:.8rem; text-transform:uppercase; letter-spacing:.06em; font-weight:800; color:<?php echo h($col_f); ?>; margin-bottom:1rem; }
.pj-sez .form-text { font-size:.76rem; }
.pj-riga { display:grid; grid-template-columns: 150px 1fr 1fr 150px 38px; gap:.5rem; margin-bottom:.5rem; }
.pj-ref { padding-bottom:.6rem; margin-bottom:.6rem; border-bottom:1px dashed #e2e8f0; }
.pj-ref .pj-riga { margin-bottom:.4rem; }
.pj-riga-2 { display:grid; grid-template-columns: 1fr auto auto; gap:.75rem; align-items:center; padding-right:46px; }
.ev-t { padding:.7rem; margin-bottom:.6rem; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; display:grid; gap:.45rem; }
.ev-t-passato { opacity:.6; }
.ev-t-riga1 { display:grid; grid-template-columns: 1fr 150px 105px 105px 115px 38px; gap:.45rem; }
.ev-t-riga2 { display:grid; grid-template-columns: repeat(3, 1fr) 110px 110px; gap:.45rem; }
.ev-t-riga2 label { display:flex; flex-direction:column; gap:.15rem; margin:0; font-size:.72rem; font-weight:700; color:#475569; }
.ev-t-riga3 { display:flex; flex-wrap:wrap; align-items:center; gap:.4rem 1.2rem; }
form:not(.ev-form-classe) .ev-solo-classe { display:none !important; }
form:not(.ev-form-classe) .ev-t-riga2 { grid-template-columns: repeat(3, 1fr); }
@media (max-width: 991.98px) { .ev-t-riga1 { grid-template-columns: 1fr 1fr; } .ev-t-riga1 .pj-rimuovi { justify-self:end; } .ev-t-riga2, form:not(.ev-form-classe) .ev-t-riga2 { grid-template-columns: 1fr 1fr; } }
@media (max-width: 767.98px) { .pj-riga, .pj-riga-2 { grid-template-columns: 1fr; padding-right:0; } }
</style>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <h4 class="fw-bold text-dark mb-0"><i class="fa fa-calendar-alt me-2" style="color:<?php echo h($col_f); ?>" aria-hidden="true"></i><?php echo $nuovo ? 'Nuovo evento' : 'Modifica evento'; ?></h4>
    <a href="eventi.php?p_id=<?php echo $filtro_p; ?><?php echo $filtro_ev ? '&f_ev=' . $filtro_ev : ''; ?>" class="btn btn-outline-secondary btn-sm fw-bold"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i>Torna agli eventi</a>
</div>

<form method="POST" enctype="multipart/form-data" onsubmit="if (window.tinymce) tinymce.triggerSave();">
    <?php csrf_field(); ?>
    <input type="hidden" name="evento_id" value="<?php echo $id_modifica; ?>">
    <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
    <input type="hidden" name="f_ev" value="<?php echo $filtro_ev; ?>">

    <div class="row g-3">
        <div class="col-xl-8">
            <section class="pj-sez">
                <h2><i class="fa fa-circle-info me-1" aria-hidden="true"></i>Dati generali</h2>
                <div class="row g-3">
                    <div class="col-12">
                        <label for="evTitolo" class="form-label small fw-bold">Titolo dell'evento <span class="text-danger">*</span></label>
                        <input type="text" name="titolo" id="evTitolo" class="form-control" value="<?php echo $v('titolo'); ?>" maxlength="255" required>
                    </div>
                    <?php $ins_sel = (int)($dett_f['insegnamento_id'] ?? 0);
                    if (tipo_area($page_cfg) === 'gruppi' || $ins_sel):
                        $ins_gruppi = insegnamenti_per_corso($conn);
                        $ins_cur = insegnamento($conn, $ins_sel);
                        $ins_in_lista = false;
                        foreach ($ins_gruppi as $l_g) foreach ($l_g as $i_g) if ((int)$i_g['id'] === $ins_sel) $ins_in_lista = true;
                        $dati_opt = fn($i) => ' data-nome="' . h($i['nome'] . ($i['partizione'] !== '' ? ' (' . $i['partizione'] . ')' : '')) . '" data-corso="' . h($i['cds_cod']) . '" data-corso-nome="' . h($i['cds_nome']) . '"'; ?>
                    <div class="col-12">
                        <label for="evIns" class="form-label small fw-bold"><i class="fa fa-book-open me-1" aria-hidden="true"></i>Insegnamento <span class="fw-normal text-muted">(anagrafe di Ateneo)</span></label>
                        <input type="search" id="evInsCerca" class="form-control form-control-sm mb-1" placeholder="Filtra per nome, corso o docente" aria-label="Filtra gli insegnamenti">
                        <select name="insegnamento_id" id="evIns" class="form-select form-select-sm">
                            <option value="0">— Nessun insegnamento —</option>
                            <?php if ($ins_cur && !$ins_in_lista): ?><option value="<?php echo (int)$ins_cur['id']; ?>" selected<?php echo $dati_opt($ins_cur); ?>><?php echo h(etichetta_insegnamento($ins_cur, true) . ' – ' . $ins_cur['anno_accademico'] . '/' . ($ins_cur['anno_accademico'] + 1)); ?></option><?php endif; ?>
                            <?php foreach ($ins_gruppi as $cds_g => $lista_g): ?>
                                <optgroup label="<?php echo h($cds_g); ?>">
                                    <?php foreach ($lista_g as $i_g): ?><option value="<?php echo (int)$i_g['id']; ?>" <?php echo (int)$i_g['id'] === $ins_sel ? 'selected' : ''; ?><?php echo $dati_opt($i_g); ?>><?php echo h(etichetta_insegnamento($i_g)); ?></option><?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text"><?php echo $ins_gruppi ? "Insegnamenti dell'anno accademico in corso. Scegliendolo si compilano titolo e corso di studio (puoi modificarli); i gruppi sono i turni qui sotto." : "L'anagrafe degli insegnamenti è vuota: aggiornala da Anagrafi → Strutture e aggiornamento."; ?></div>
                    </div>
                    <script>
                    (function () {
                        var sel = document.getElementById('evIns'), cerca = document.getElementById('evInsCerca'), tit = document.getElementById('evTitolo');
                        var auto = sel.options[sel.selectedIndex] && sel.options[sel.selectedIndex].dataset.nome === tit.value;
                        cerca.addEventListener('input', function () {
                            var q = cerca.value.toLowerCase().trim();
                            sel.querySelectorAll('optgroup').forEach(function (g) {
                                var vis = 0;
                                g.querySelectorAll('option').forEach(function (o) { var ok = !q || (g.label + ' ' + o.textContent).toLowerCase().indexOf(q) !== -1; o.hidden = !ok; if (ok) vis++; });
                                g.hidden = vis === 0;
                            });
                        });
                        sel.addEventListener('change', function () {
                            var o = sel.options[sel.selectedIndex];
                            if (!o || !o.dataset.nome) return;
                            if (tit.value.trim() === '' || auto) { tit.value = o.dataset.nome; auto = true; }
                            var corso = document.getElementById('evCorso'), testo = document.getElementById('evCorsoTesto');
                            if (corso && corso.querySelector('option[value="' + o.dataset.corso + '"]')) { corso.value = o.dataset.corso; corso.dispatchEvent(new Event('change')); }
                            else if (testo && testo.value.trim() === '') testo.value = o.dataset.corsoNome;
                        });
                        tit.addEventListener('input', function () { auto = false; });
                    })();
                    </script>
                    <?php endif; ?>
                    <div class="col-md-6">
                        <label for="evSez" class="form-label small fw-bold">Sottocategoria / Sezione</label>
                        <select name="sottocategoria_id" id="evSez" class="form-select form-select-sm" <?php echo !$can_manage_settings ? 'disabled' : ''; ?>>
                            <option value="">-- Nessuna --</option>
                            <?php foreach ($sottocategorie as $sub): ?><option value="<?php echo (int)$sub['id']; ?>" <?php echo (int)($ev['sottocategoria_id'] ?? 0) === (int)$sub['id'] ? 'selected' : ''; ?>><?php echo h($sub['nome'] ?? ''); ?></option><?php endforeach; ?>
                        </select>
                        <?php if (!$can_manage_settings && !empty($ev['sottocategoria_id'])): ?><input type="hidden" name="sottocategoria_id" value="<?php echo (int)$ev['sottocategoria_id']; ?>"><?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label for="evLuogo" class="form-label small fw-bold">Luogo / Aula</label>
                        <input type="text" name="luogo" id="evLuogo" class="form-control form-control-sm" value="<?php echo $v('luogo'); ?>" placeholder="es. Aula Magna, Cubo 4C, Online (Teams)" maxlength="255">
                    </div>
                    <div class="col-12">
                        <label for="evCorso" class="form-label small fw-bold">Corso di laurea / Struttura <span class="fw-normal text-muted">(facoltativo)</span></label>
                        <?php echo html_scelta_corso_scheda($conn, $dett_f ?? null, 'evCorso'); ?>
                    </div>
                    <div class="col-12">
                        <span class="form-label small fw-bold d-block mb-1">Ambiti <span class="fw-normal text-muted">(dove compare nell'agenda del sito; predefinito dell'area: <?php echo h(AMBITI_EVENTO[ambito_area($page_cfg)]['nome']); ?>)</span></span>
                        <?php echo html_scelta_ambiti(ambiti_evento($ev ?: [], $page_cfg)); ?>
                    </div>
                    <div class="col-12">
                        <details class="border rounded p-2" id="evSeminario"<?php echo !empty($ev) && (e_seminario($ev) || !empty($ev['link_streaming']) || !empty($ev['link_registrazione'])) ? ' open' : ''; ?>>
                            <summary class="small fw-bold" style="cursor:pointer;"><i class="fa fa-chalkboard-user me-1 text-primary" aria-hidden="true"></i>Seminario: relatore, abstract, diretta, registrazione e slide <span class="fw-normal text-muted">(facoltativo)</span></summary>
                            <div class="row g-2 mt-1">
                                <div class="col-12"><?php echo html_ricerca_personale($conn, 'Relatore dall\'anagrafe'); ?></div>
                                <input type="hidden" name="relatore_persona_id" id="evRelPid" value="<?php echo $v('relatore_persona_id'); ?>">
                                <div class="col-md-6"><label for="evRel" class="form-label small fw-bold">Relatore</label><input type="text" name="relatore" id="evRel" class="form-control form-control-sm" value="<?php echo $v('relatore'); ?>" maxlength="255" placeholder="es. Prof.ssa Elena Mari"></div>
                                <div class="col-md-6"><label for="evRelEnte" class="form-label small fw-bold">Ente / affiliazione</label><input type="text" name="relatore_ente" id="evRelEnte" class="form-control form-control-sm" value="<?php echo $v('relatore_ente'); ?>" maxlength="255" placeholder="es. CNR – Istituto di Scienze Marine"></div>
                                <div class="col-12"><label for="evAbs" class="form-label small fw-bold">Abstract</label><textarea name="abstract" id="evAbs" class="form-control form-control-sm" rows="4" maxlength="5000"><?php echo $v('abstract'); ?></textarea></div>
                                <div class="col-md-6"><label for="evLive" class="form-label small fw-bold">Link della diretta <span class="fw-normal text-muted">(Teams, Meet, YouTube…)</span></label><input type="url" name="link_streaming" id="evLive" class="form-control form-control-sm" value="<?php echo $v('link_streaming'); ?>" placeholder="https://"></div>
                                <div class="col-md-6"><label for="evRec" class="form-label small fw-bold">Link della registrazione <span class="fw-normal text-muted">(dopo il seminario)</span></label><input type="url" name="link_registrazione" id="evRec" class="form-control form-control-sm" value="<?php echo $v('link_registrazione'); ?>" placeholder="https://"></div>
                                <div class="col-md-6"><label for="evSlide" class="form-label small fw-bold">Slide (PDF)</label><input type="file" name="slide_pdf" id="evSlide" class="form-control form-control-sm" accept=".pdf,application/pdf">
                                    <?php if (!empty($ev['slide_pdf'])): ?><div class="small mt-1"><a href="../<?php echo h($ev['slide_pdf']); ?>" target="_blank" rel="noopener">Slide attuali</a> · <label class="form-check-label"><input type="checkbox" class="form-check-input" name="elimina_slide" value="1"> togli</label></div><?php endif; ?></div>
                                <div class="col-12 small text-secondary">Compaiono nella scheda dell'evento e nell'agenda; la diretta si vede fino alla fine dell'evento, poi la registrazione.</div>
                            </div>
                        </details>
                    </div>
                    <script>
                    document.getElementById('evSeminario').addEventListener('persona-scelta', function (e) {
                        var p = e.detail; document.getElementById('evRelPid').value = p.id; document.getElementById('evRel').value = p.nome || '';
                        if (!document.getElementById('evRelEnte').value) document.getElementById('evRelEnte').value = p.struttura ? p.struttura + ' – Università della Calabria' : 'Università della Calabria';
                    });
                    document.getElementById('evRel').addEventListener('input', function () { document.getElementById('evRelPid').value = ''; });
                    </script>
                    <div class="col-12">
                        <label for="evDescBreve" class="form-label small fw-bold">Descrizione breve <span class="fw-normal text-muted">(compare nelle card)</span></label>
                        <textarea name="descrizione_breve" id="evDescBreve" class="form-control form-control-sm editor-breve" rows="2" placeholder="Una o due frasi che invogliano ad aprire la scheda dell'evento"><?php echo $v('descrizione_breve'); ?></textarea>
                        <div class="form-text"><span class="desc-breve-conta">0</span>/300 caratteri. Se è vuota, nelle card compare l'inizio della descrizione completa.</div>
                    </div>
                    <div class="col-12">
                        <label for="evDesc" class="form-label small fw-bold">Descrizione completa <span class="fw-normal text-muted">(scheda dell'evento)</span></label>
                        <textarea name="descrizione" id="evDesc" class="form-control editor-html" rows="10"><?php echo $v('descrizione'); ?></textarea>
                    </div>
                </div>
            </section>

            <section class="pj-sez">
                <h2><i class="fa fa-address-book me-1" aria-hidden="true"></i>Referenti, responsabili e relatori</h2>
                <p class="form-text mt-0 mb-2">Compaiono nella scheda dell'evento; il link alla pagina personale rende cliccabile il nome. Chi ha <strong>"Riceve le prenotazioni"</strong> attivo riceve per email il riepilogo di ogni prenotazione e disdetta (serve l'email).</p>
                <?php echo html_ricerca_personale($conn, "Aggiungi", "evReferenti"); ?>
                <div class="d-none d-md-grid pj-riga small fw-bold text-secondary mb-1"><span>Ruolo</span><span>Nome e cognome</span><span>Email</span><span>Telefono</span><span></span></div>
                <div id="evReferenti">
                    <?php foreach ($referenti as $r): $notif_r = !empty($r['notifiche']); ?>
                        <div class="pj-ref">
                            <div class="pj-riga">
                                <input type="text" name="ref_ruolo[]" class="form-control form-control-sm" value="<?php echo h($r['ruolo'] ?? ''); ?>" list="evRuoli" placeholder="Ruolo" aria-label="Ruolo">
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
                                <label class="form-check form-switch m-0 small fw-bold text-nowrap"><input class="form-check-input pj-notif" type="checkbox" <?php echo $notif_r ? 'checked' : ''; ?>> Riceve le prenotazioni</label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <datalist id="evRuoli"><option value="Referente"><option value="Docente referente"><option value="Responsabile Unical"><option value="Relatore"><option value="Tutor"><option value="Segreteria"></datalist>
                <button type="button" class="btn btn-sm btn-outline-secondary fw-bold mb-2" data-pj-aggiungi="evReferenti"><i class="fa fa-plus me-1" aria-hidden="true"></i>Aggiungi persona</button>
            </section>

            <section class="pj-sez" id="turni">
                <h2><i class="fa fa-clock me-1" aria-hidden="true"></i>Turni e prenotazioni</h2>
                <p class="form-text mt-0 mb-2">Una riga per turno. Serve almeno il nome oppure la data; gli orari sono facoltativi. <strong>Annullabile fino a</strong>: dopo questa data e ora chi ha prenotato non può più annullare né cambiare turno dall'Area personale (vuoto = sempre possibile).<span class="ev-solo-classe"> Con gli attestati per la classe ogni posto è una classe: indica anche quanti studenti può avere.</span></p>
                <div id="evTurni">
                    <?php
                    $turni_righe = $turni_ev ?: [['id' => 0, 'max_posti' => 30, 'n_iscr' => 0]];
                    $aule_turni = aule_per_turni($conn); // aule e laboratori di Prenotazioni e risorse
                    $dtl = fn($x) => !empty($x) ? date('Y-m-d\TH:i', strtotime($x)) : '';
                    foreach ($turni_righe as $t):
                        $tid = (int)($t['id'] ?? 0); $n_i = (int)($t['n_iscr'] ?? 0);
                        $flag = fn($k) => (int)($t[$k] ?? 0) === 1;
                    ?>
                    <div class="ev-t <?php echo $tid && turno_concluso($t) ? 'ev-t-passato' : ''; ?>">
                        <input type="hidden" name="t_id[]" value="<?php echo $tid; ?>">
                        <div class="ev-t-riga1">
                            <input type="text" name="t_nome[]" class="form-control form-control-sm" value="<?php echo h($t['nome_turno'] ?? ''); ?>" placeholder="Nome turno (facoltativo)" aria-label="Nome del turno" maxlength="150">
                            <input type="date" name="t_data[]" class="form-control form-control-sm" value="<?php echo h($t['data_turno'] ?? ''); ?>" aria-label="Data">
                            <input type="time" name="t_in[]" class="form-control form-control-sm" value="<?php echo h(substr((string)($t['orario_inizio'] ?? ''), 0, 5)); ?>" aria-label="Ora di inizio" title="Inizio">
                            <input type="time" name="t_fi[]" class="form-control form-control-sm" value="<?php echo h(substr((string)($t['orario_fine'] ?? ''), 0, 5)); ?>" aria-label="Ora di fine" title="Fine">
                            <div class="input-group input-group-sm" title="Posti">
                                <span class="input-group-text"><i class="fa fa-users" aria-hidden="true"></i></span>
                                <input type="number" name="t_posti[]" class="form-control" value="<?php echo (int)($t['max_posti'] ?? 30); ?>" min="1" max="99999" aria-label="Posti">
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-danger pj-rimuovi" title="Rimuovi turno" aria-label="Rimuovi turno" <?php echo $n_i > 0 ? 'data-iscritti="' . $n_i . '"' : ''; ?>><i class="fa fa-times" aria-hidden="true"></i></button>
                        </div>
                        <div class="ev-t-riga2">
                            <label>Apertura prenotazioni<input type="datetime-local" name="t_ap[]" class="form-control form-control-sm" value="<?php echo $dtl($t['data_apertura'] ?? ''); ?>"></label>
                            <label>Chiusura prenotazioni<input type="datetime-local" name="t_ch[]" class="form-control form-control-sm" value="<?php echo $dtl($t['data_chiusura'] ?? ''); ?>"></label>
                            <label>Annullabile fino a<input type="datetime-local" name="t_ann[]" class="form-control form-control-sm" value="<?php echo $dtl($t['annullabile_fino'] ?? ''); ?>"></label>
<?php if ($aule_turni): ?>
                            <label title="L'aula viene prenotata in automatico nel calendario di Prenotazioni e risorse (servono data e orari)">Aula<select name="t_aula[]" class="form-select form-select-sm"><option value="0">Nessuna</option>
                                <?php foreach ($aule_turni as $area_a => $aule_a): ?><optgroup label="<?php echo h($area_a); ?>"><?php foreach ($aule_a as $id_a => $nome_a): ?><option value="<?php echo (int)$id_a; ?>"<?php echo (int)($t['risorsa_id'] ?? 0) === (int)$id_a ? ' selected' : ''; ?>><?php echo h($nome_a); ?></option><?php endforeach; ?></optgroup><?php endforeach; ?>
                            </select></label>
<?php endif; ?>
                            <label class="ev-solo-classe">Studenti min<input type="number" name="t_min[]" class="form-control form-control-sm" min="1" value="<?php echo (int)($t['min_partecipanti'] ?? 0) ?: ''; ?>"></label>
                            <label class="ev-solo-classe">Studenti max<input type="number" name="t_maxs[]" class="form-control form-control-sm" min="1" value="<?php echo (int)($t['max_partecipanti'] ?? 0) ?: ''; ?>" placeholder="es. 18"></label>
                        </div>
                        <div class="ev-t-riga3">
                            <?php foreach (['t_wa' => ['abilita_lista_attesa', "Lista d'attesa"], 't_mp' => ['abilita_multi_posto', 'Multi-posto'], 't_app' => ['richiede_approvazione', 'Approvazione']] as $nome_f => [$col_f_db, $lbl_f]): ?>
                                <span class="ev-flag-box">
                                    <input type="hidden" name="<?php echo $nome_f; ?>[]" value="<?php echo $flag($col_f_db) ? '1' : '0'; ?>">
                                    <label class="form-check form-switch m-0 small fw-bold"><input class="form-check-input ev-flag" type="checkbox" <?php echo $flag($col_f_db) ? 'checked' : ''; ?>> <?php echo $lbl_f; ?></label>
                                </span>
                            <?php endforeach; ?>
                            <?php if ($tid > 0): ?>
                                <span class="ms-auto d-flex gap-1 flex-wrap ev-t-esistente">
                                    <span class="badge bg-light text-dark border align-self-center" title="Prenotazioni attive (anche in attesa)"><i class="fa fa-user-check me-1" aria-hidden="true"></i><?php echo $n_i; ?></span>
                                    <?php if ($can_manage_iscritti): ?><a href="iscritti.php?p_id=<?php echo $filtro_p; ?>&f_turno=<?php echo $tid; ?>" class="btn btn-sm btn-outline-dark py-0 fw-bold"><i class="fa fa-users me-1" aria-hidden="true"></i>Iscritti</a><?php endif; ?>
                                    <a href="stampa_qr_aula.php?t_id=<?php echo $tid; ?>&p_id=<?php echo $filtro_p; ?>" class="btn btn-sm btn-outline-success py-0 fw-bold"><i class="fa fa-qrcode me-1" aria-hidden="true"></i>QR aula</a>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary fw-bold mb-2" data-pj-aggiungi="evTurni"><i class="fa fa-plus me-1" aria-hidden="true"></i>Aggiungi turno</button>
                <span class="form-text ms-2">Il nuovo turno parte dagli stessi orari e posti dell'ultimo: cambia la data.</span>
            </section>
        </div>

        <div class="col-xl-4">
            <section class="pj-sez" style="border-left:4px solid <?php echo h($col_f); ?>;">
                <h2><i class="fa fa-toggle-on me-1" aria-hidden="true"></i>Accesso e prenotazione</h2>
                <label for="evRuolo" class="form-label small fw-bold">Prenotabile da</label>
                <select name="ruolo_accesso_id" id="evRuolo" class="form-select form-select-sm mb-3">
                    <option value="0">🌐 Tutti (pubblico)</option>
                    <option value="-1" <?php echo (int)($ev['ruolo_accesso_id'] ?? 0) === -1 ? 'selected' : ''; ?>>🔑 Solo utenti autenticati (SPID, CIE, Unical)</option>
                    <?php foreach ($ruoli as $r): ?><option value="<?php echo (int)$r['id']; ?>" <?php echo (int)($ev['ruolo_accesso_id'] ?? 0) === (int)$r['id'] ? 'selected' : ''; ?>>🔒 Solo: <?php echo h($r['nome']); ?></option><?php endforeach; ?>
                </select>
                <div class="form-check form-switch mb-1">
                    <input class="form-check-input" type="checkbox" name="richiede_prenotazione" id="evReqPren" value="1" <?php echo $sw('richiede_prenotazione', 1); ?>>
                    <label class="form-check-label small fw-bold" for="evReqPren">Richiede prenotazione</label>
                </div>
                <p class="form-text mt-0 mb-3">Spento: evento ad accesso libero, senza modulo di prenotazione.</p>
                <div class="form-check form-switch mb-1">
                    <input class="form-check-input" type="checkbox" name="abilita_presenze" id="evPres" value="1" <?php echo $sw('abilita_presenze', 1); ?>>
                    <label class="form-check-label small fw-bold" for="evPres">Check-in con QR</label>
                </div>
                <p class="form-text mt-0 mb-3">Registra le presenze con lo scanner o il QR d'aula: serve per rilasciare gli attestati.</p>
                <div class="form-check form-switch mb-1">
                    <input class="form-check-input" type="checkbox" name="attestati_classe" id="evAttClasse" value="1" <?php echo $att_classe_v ? 'checked' : ''; ?>>
                    <label class="form-check-label small fw-bold" for="evAttClasse"><i class="fa fa-graduation-cap me-1" aria-hidden="true"></i>Attestati per gli studenti della classe</label>
                </div>
                <p class="form-text mt-0 mb-3">Per le prenotazioni di classi (es. scuole): chi prenota indica il numero di studenti e, dalla sua Area personale, inserisce i loro nomi. Dopo l'evento, se la presenza è registrata con il check-in, riceve per email gli attestati di tutti gli studenti, con codice di verifica. Il min/max di studenti si imposta in ogni turno.</p>
                <div class="form-check form-switch mb-1">
                    <input class="form-check-input" type="checkbox" name="dedicata_scuole" id="evScuole" value="1" <?php echo $scuole_v ? 'checked' : ''; ?>>
                    <label class="form-check-label small fw-bold" for="evScuole"><i class="fa fa-school me-1" aria-hidden="true"></i>Dedicato alle scuole</label>
                </div>
                <p class="form-text mt-0 mb-3">Prenota il docente per la sua classe, indicando il numero di studenti (min/max in ogni turno).</p>
                <?php $url_fsl = $nuovo ? "progetti.php?p_id=$filtro_p&azione=nuovo" : "progetti.php?p_id=$filtro_p&id=$id_modifica&converti=1"; ?>
                <div class="form-check form-switch mb-1">
                    <input class="form-check-input" type="checkbox" id="evFsl" data-vai="<?php echo h($url_fsl); ?>" data-nuovo="<?php echo $nuovo ? '1' : '0'; ?>">
                    <label class="form-check-label small fw-bold" for="evFsl"><i class="fa fa-file-signature me-1" aria-hidden="true"></i>Formazione Scuola Lavoro</label>
                </div>
                <p class="form-text mt-0 mb-0">Spuntandola si apre il modulo del <strong>progetto</strong> (corso di laurea, periodo, moduli, edizioni, convenzione…) e l'evento <strong>diventa un progetto</strong>: ha convenzioni, Allegato A, programma della scuola ed elenco degli studenti. Le prenotazioni già ricevute restano e i turni diventano edizioni.</p>
                <?php if (!$nuovo && $fsl_v): ?>
                    <div class="alert alert-warning small mt-2 mb-0"><i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i>Questo evento è ancora segnato come Formazione Scuola Lavoro ma non è un progetto. <a href="<?php echo h($url_fsl); ?>" class="fw-bold">Trasformalo in progetto</a> (le prenotazioni restano).</div>
                <?php endif; ?>
            </section>

            <section class="pj-sez">
                <h2><i class="fa fa-paperclip me-1" aria-hidden="true"></i>Immagine e allegati</h2>
                <label for="evLoc" class="form-label small fw-bold">Locandina (JPG, PNG, WEBP)</label>
                <input type="file" name="locandina_file" id="evLoc" class="form-control form-control-sm" accept="image/png,image/jpeg,image/gif,image/webp">
                <?php if (!empty($ev['locandina_path'])): ?>
                    <div class="d-flex align-items-center gap-2 mt-2">
                        <img src="../<?php echo h($ev['locandina_path']); ?>" alt="" style="height:48px;border-radius:6px;object-fit:cover;">
                        <div class="form-check m-0"><input class="form-check-input" type="checkbox" name="elimina_locandina" id="evDelLoc" value="1"><label class="form-check-label small text-danger fw-bold" for="evDelLoc">Rimuovi</label></div>
                    </div>
                <?php endif; ?>
                <label for="evPdf" class="form-label small fw-bold mt-3">Programma / allegato (PDF)</label>
                <input type="file" name="allegato_pdf" id="evPdf" class="form-control form-control-sm" accept="application/pdf">
                <?php if (!empty($ev['allegato_pdf'])): ?>
                    <div class="d-flex align-items-center gap-2 mt-2 mb-2">
                        <a href="../<?php echo h($ev['allegato_pdf']); ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary py-0"><i class="fa fa-eye me-1" aria-hidden="true"></i>Vedi</a>
                        <div class="form-check m-0"><input class="form-check-input" type="checkbox" name="elimina_pdf" id="evDelPdf" value="1"><label class="form-check-label small text-danger fw-bold" for="evDelPdf">Rimuovi</label></div>
                    </div>
                <?php endif; ?>
            </section>

            <section class="pj-sez">
                <h2><i class="fa fa-sliders me-1" aria-hidden="true"></i>Pubblicazione e notifiche</h2>
                <div class="row g-2 align-items-end">
                    <div class="col-5"><label for="evOrd" class="form-label small fw-bold">Ordine</label><input type="number" name="ordine_evento" id="evOrd" class="form-control form-control-sm" value="<?php echo (int)($ev['ordine'] ?? 0); ?>" <?php echo !$can_manage_settings ? 'readonly' : ''; ?>></div>
                    <div class="col-7 pb-1">
                        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_evidenza" id="evEvid" value="1" <?php echo !empty($ev['is_evidenza']) ? 'checked' : ''; ?> <?php echo !$can_manage_settings ? 'disabled' : ''; ?>><label class="form-check-label small fw-bold" for="evEvid">⭐ In evidenza</label></div>
                        <?php if (!$can_manage_settings && !empty($ev['is_evidenza'])): ?><input type="hidden" name="is_evidenza" value="1"><?php endif; ?>
                    </div>
                    <div class="col-12 mt-3">
                        <label for="evNotif" class="form-label small fw-bold">Invia copia delle prenotazioni a</label>
                        <input type="text" name="email_notifiche_extra" id="evNotif" class="form-control form-control-sm" value="<?php echo h(implode(', ', normalizza_lista_email($ev['email_notifiche_extra'] ?? ''))); ?>" placeholder="es. segreteria@unical.it">
                        <div class="form-text">Oltre ai gestori e ai referenti con "Riceve le prenotazioni". Separa gli indirizzi con una virgola, massimo 10.</div>
                    </div>
                </div>
            </section>

            <div class="d-grid gap-2 mb-4">
                <button type="submit" name="<?php echo $nuovo ? 'add_evento' : 'edit_evento'; ?>" value="1" class="btn fw-bold py-2" style="background:<?php echo h($col_f); ?>;color:<?php echo $txt_f; ?>;"><i class="fa fa-save me-1" aria-hidden="true"></i><?php echo $nuovo ? 'Crea evento' : 'Salva evento'; ?></button>
                <?php if (!$nuovo && $slug_f): ?>
                    <a href="../<?php echo h($slug_f); ?>.php?evento=<?php echo $id_modifica; ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary fw-bold"><i class="fa fa-up-right-from-square me-1" aria-hidden="true"></i>Vedi la scheda pubblica</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</form>

<script>
// Referenti e turni: aggiungi / rimuovi righe. Gli interruttori aggiornano un campo nascosto della stessa riga
// (le checkbox spente non vengono inviate, e servono valori allineati riga per riga).
document.addEventListener('click', function (e) {
    var add = e.target.closest('[data-pj-aggiungi]');
    if (add) {
        var box = document.getElementById(add.dataset.pjAggiungi), ultima = box.lastElementChild, nuova = ultima.cloneNode(true);
        if (nuova.classList.contains('ev-t')) {
            // Turno nuovo: stessi orari, posti e opzioni dell'ultimo; data, nome e scadenze da indicare
            nuova.querySelectorAll('.ev-t-esistente').forEach(function (x) { x.remove(); });
            nuova.classList.remove('ev-t-passato');
            nuova.querySelector('input[name="t_id[]"]').value = '0';
            ['t_nome[]', 't_data[]', 't_ap[]', 't_ch[]', 't_ann[]'].forEach(function (n) { nuova.querySelector('input[name="' + n + '"]').value = ''; });
            nuova.querySelector('.pj-rimuovi').removeAttribute('data-iscritti');
        } else {
            svuota(nuova);
        }
        box.appendChild(nuova);
        (nuova.querySelector('input[type=date]') || nuova.querySelector('input:not([type=hidden])')).focus();
        return;
    }
    var rim = e.target.closest('.pj-rimuovi');
    if (rim) {
        var riga = rim.closest('.pj-ref, .ev-t');
        if (rim.dataset.iscritti && !confirm('Questo turno ha ' + rim.dataset.iscritti + ' prenotazioni attive: non verrà eliminato finché non le annulli da Iscritti. Toglierlo comunque dalla pagina?')) return;
        if (riga.parentElement.children.length > 1) riga.remove(); else svuota(riga);
    }
});
function svuota(riga) {
    riga.querySelectorAll('.ref-anag').forEach(function (b) { b.hidden = true; });
    riga.querySelectorAll('.ev-t-esistente').forEach(function (x) { x.remove(); });
    riga.querySelectorAll('input').forEach(function (i) {
        if (i.type === 'checkbox') i.checked = false; else if (i.type === 'hidden') i.value = '0'; else i.value = '';
    });
    var posti = riga.querySelector('input[name="t_posti[]"]'); if (posti) posti.value = '30';
}
document.addEventListener('change', function (e) {
    if (e.target.classList.contains('pj-notif')) e.target.closest('.pj-riga-2').querySelector('input[name="ref_notifiche[]"]').value = e.target.checked ? '1' : '0';
    if (e.target.classList.contains('ev-flag')) e.target.closest('.ev-flag-box').querySelector('input[type=hidden]').value = e.target.checked ? '1' : '0';
});
// Attestati per la classe: attiva il check-in (serve per gli attestati). Classe (attestati, dedicato alle scuole o FSL):
// mostra min/max studenti nei turni. FSL accende anche "Dedicato alle scuole".
(function () {
    var sw = document.getElementById('evAttClasse'), pres = document.getElementById('evPres');
    var scu = document.getElementById('evScuole'), fsl = document.getElementById('evFsl');
    if (!sw) return;
    var form = sw.closest('form');
    function aggiorna() {
        form.classList.toggle('ev-form-classe', sw.checked || (scu && scu.checked));
        if (sw.checked) pres.checked = true;
        pres.disabled = sw.checked;
    }
    [sw, scu].forEach(function (x) { if (x) x.addEventListener('change', aggiorna); }); aggiorna();
    // Formazione Scuola Lavoro: l'evento diventa un progetto, si apre il modulo del progetto
    if (fsl) fsl.addEventListener('change', function () {
        if (!fsl.checked) return;
        var testo = fsl.dataset.nuovo === '1' ? 'Un\'attività di Formazione Scuola Lavoro è un progetto: si apre il modulo del progetto. Continuare?'
            : 'L\'evento diventa un progetto di Formazione Scuola Lavoro: si apre il modulo del progetto (le prenotazioni restano; le modifiche non salvate di questa pagina vanno perse). Continuare?';
        if (confirm(testo)) window.location.href = fsl.dataset.vai; else fsl.checked = false;
    });
    form.addEventListener('submit', function () { if (scu) scu.disabled = false; });
    // Un interruttore disattivato non viene inviato: prima dell'invio lo si riattiva
    form.addEventListener('submit', function () { pres.disabled = false; });
})();
</script>
<?php
    require_once 'admin_footer.php';
    exit;
endif;

$eventi = []; $tutti_gli_eventi = [];
$filtro_ev = isset($_GET['f_ev']) ? (int)$_GET['f_ev'] : 0;

// ESTRAZIONE CON APPLICAZIONE VARIABILE MAGICA RBAC
// I progetti (tipo = 'progetto') hanno la loro scheda in progetti.php
$n_progetti = $repo_eventi->contaProgetti((int)$filtro_p, $sql_filtro_eventi_rbac);
foreach ($repo_eventi->normaliAdmin((int)$filtro_p, $sql_filtro_eventi_rbac) as $row) {
    $row['turni'] = $repo_turni->perEventoPerData((int)$row['id']);
    $tutti_gli_eventi[] = $row;
    if ($filtro_ev === 0 || $filtro_ev == $row['id']) { $eventi[] = $row; }
}
$schede_ev = get_dettagli_progetti($conn, array_column($eventi, 'id'));
?>

<?php
$col_area = htmlspecialchars($page_cfg['colore_primario'] ?? '#0056b3');
?>
<style>
.ev-card { border-radius:12px; border:1px solid #e2e8f0; background:#fff; box-shadow:0 2px 8px rgba(0,0,0,.06); transition:box-shadow .2s; overflow:hidden; }
.ev-card:hover { box-shadow:0 6px 20px rgba(0,0,0,.10); }
.ev-card-accent { width:5px; flex-shrink:0; border-radius:0; }
.turno-chip { display:flex; align-items:center; justify-content:space-between; gap:8px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:8px 12px; font-size:.82rem; }
.turno-chip:hover { background:#f1f5f9; }
.add-turno-toggle { background:none; border:1px dashed #94a3b8; color:#64748b; border-radius:8px; padding:7px 16px; font-size:.82rem; font-weight:600; cursor:pointer; width:100%; transition:all .15s; }
.add-turno-toggle:hover { background:#f1f5f9; border-color:#475569; color:#1e293b; }
</style>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <h4 class="fw-bold text-dark mb-0"><i class="fa fa-calendar-alt me-2" style="color:<?php echo $col_area; ?>"></i>Gestione Eventi e Turni</h4>
    <div class="d-flex gap-2 align-items-center">
        <form method="GET" id="formFiltroEv" class="d-flex align-items-center gap-2 m-0">
            <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
            <i class="fa fa-filter text-secondary"></i>
            <select name="f_ev" class="form-select form-select-sm fw-bold border-0 shadow-sm" style="min-width:200px;" onchange="document.getElementById('formFiltroEv').submit();">
                <option value="0">Tutti i tuoi Eventi</option>
                <?php foreach($tutti_gli_eventi as $e_opt): ?>
                    <option value="<?php echo $e_opt['id']; ?>" <?php echo $filtro_ev == $e_opt['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($e_opt['titolo']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php if ($can_manage_settings): ?>
            <form method="POST" class="m-0">
                <?php csrf_field(); ?>
                <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                <button type="submit" name="archivia_conclusi" class="btn btn-outline-secondary btn-sm fw-bold" data-confirm="Archiviare tutti gli eventi passati?" title="Archivia conclusi"><i class="fa fa-archive me-1"></i>Archivia vecchi</button>
            </form>
        <?php endif; ?>
        <?php if ($puo_creare_ev): ?>
            <a href="eventi.php?p_id=<?php echo $filtro_p; ?>&azione=nuovo" class="btn btn-sm fw-bold px-3 shadow-sm text-white" style="background:<?php echo $col_area; ?>;border:none;border-radius:8px;"><i class="fa fa-plus-circle me-1"></i>Crea Evento</a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-0">
    <?php if ($can_manage_settings): ?>
    <div class="col-md-3 pe-md-3 mb-4">
        <div class="ev-card p-3" style="border-top:3px solid <?php echo $col_area; ?>;">
            <div class="fw-bold mb-3 text-uppercase" style="font-size:.72rem;letter-spacing:.06em;color:<?php echo $col_area; ?>;"><i class="fa fa-tags me-1"></i>Sottocategorie / Sezioni</div>
            <form method="POST" class="mb-3">
                <?php csrf_field(); ?>
                <input type="hidden" name="pagina_id" value="<?php echo $filtro_p; ?>">
                <div class="d-flex gap-1 mb-2">
                    <input type="text" name="nome_sottocategoria" class="form-control form-control-sm" placeholder="Nome sezione..." required>
                    <input type="number" name="ordine_sottocategoria" class="form-control form-control-sm" value="0" style="width:60px;" required>
                </div>
                <div class="form-check mb-2" style="font-size:.78rem;">
                    <input class="form-check-input" type="checkbox" name="affiancata_in_alto" value="1" id="nuovaSezAlto">
                    <label class="form-check-label" for="nuovaSezAlto">Affiancata in alto (layout Griglia)</label>
                </div>
                <button type="submit" name="add_sottocategoria" class="btn btn-sm w-100 fw-bold text-white" style="background:<?php echo $col_area; ?>;border-radius:7px;">Aggiungi</button>
            </form>
            <div class="d-flex flex-column gap-1">
                <?php foreach($sottocategorie as $sub): ?>
                    <div class="d-flex align-items-center gap-2 p-2 rounded" style="background:#f8fafc;border:1px solid #e2e8f0;font-size:.82rem;">
                        <span class="badge text-white fw-bold" style="background:<?php echo $col_area; ?>;min-width:24px;"><?php echo $sub['ordine']; ?></span>
                        <span class="fw-semibold text-dark"><?php echo htmlspecialchars($sub['nome'] ?? ''); ?></span>
                        <form method="POST" class="ms-auto m-0">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="toggle_sezione_alto" value="<?php echo (int)$sub['id']; ?>">
                            <input type="hidden" name="val" value="<?php echo !empty($sub['affiancata_in_alto']) ? 0 : 1; ?>">
                            <input type="hidden" name="f_ev" value="<?php echo $filtro_ev; ?>">
                            <button type="submit" class="btn btn-sm py-0 px-2 <?php echo !empty($sub['affiancata_in_alto']) ? 'btn-dark' : 'btn-outline-secondary'; ?>" style="font-size:.68rem;border-radius:6px;" title="Nel layout Griglia: mostra questa sezione in alto, affiancata alle altre sezioni con la stessa opzione" aria-pressed="<?php echo !empty($sub['affiancata_in_alto']) ? 'true' : 'false'; ?>"><i class="fa fa-table-columns me-1" aria-hidden="true"></i>In alto</button>
                        </form>
                    </div>
                <?php endforeach; ?>
                <?php if(empty($sottocategorie)): ?>
                    <div class="text-muted small text-center py-2">Nessuna sezione</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="<?php echo $can_manage_settings ? 'col-md-9' : 'col-12'; ?>">
        <?php if ($n_progetti > 0): ?>
            <div class="alert alert-light border small py-2 mb-3"><i class="fa fa-diagram-project me-1" aria-hidden="true"></i>In quest'area ci sono <?php echo $n_progetti; ?> <?php echo $n_progetti === 1 ? 'progetto' : 'progetti'; ?>: li gestisci da <a href="progetti.php?p_id=<?php echo $filtro_p; ?>" class="fw-bold">Progetti</a>.</div>
        <?php endif; ?>
        <?php if(empty($eventi)): ?>
            <div class="ev-card p-5 text-center text-muted">
                <i class="fa fa-folder-open fs-1 mb-3 d-block" style="opacity:.3;"></i>
                <div class="fw-semibold">Nessun evento trovato.</div>
            </div>
        <?php else: ?>
        <div class="d-flex flex-column gap-3">
        <?php foreach($eventi as $ev): ?>
            <div class="ev-card d-flex">
                <!-- Striscia colore sinistra -->
                <div class="ev-card-accent" style="background:<?php echo $col_area; ?>;"></div>

                <div class="flex-grow-1 p-3">
                    <!-- ── HEADER EVENTO ── -->
                    <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
                        <div>
                            <?php if($ev['is_evidenza']): ?>
                                <span class="badge bg-warning text-dark me-1" style="font-size:.65rem;">⭐ EVIDENZA</span>
                            <?php endif; ?>
                            <span class="fw-bold fs-6 text-dark"><?php echo htmlspecialchars($ev['titolo'] ?? ''); ?></span>
                            <div class="text-muted mt-1 d-flex flex-wrap gap-2" style="font-size:.8rem;">
                                <span><i class="fa fa-folder me-1 text-secondary"></i><?php echo $ev['nome_sottocategoria'] ? htmlspecialchars($ev['nome_sottocategoria']) : 'Nessuna Sezione'; ?></span>
                                <?php if(!empty($ev['luogo'])): ?>
                                    <span><i class="fa fa-map-marker-alt me-1 text-secondary"></i><?php echo htmlspecialchars($ev['luogo']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <!-- Bottoni azione -->
                        <div class="d-flex gap-1 flex-shrink-0">
                            <a href="eventi.php?p_id=<?php echo $filtro_p; ?>&id=<?php echo (int)$ev['id']; ?><?php echo $filtro_ev ? '&f_ev=' . $filtro_ev : ''; ?>" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center justify-content-center" style="border-radius:8px;width:34px;height:34px;padding:0;" title="Modifica evento" aria-label="Modifica evento"><i class="fa fa-edit" style="font-size:.85rem;" aria-hidden="true"></i></a>
                            <?php if (!empty($page_cfg['slug'])): ?>
                                <a href="../<?php echo htmlspecialchars($page_cfg['slug']); ?>.php?evento=<?php echo (int)$ev['id']; ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center justify-content-center" style="border-radius:8px;width:34px;height:34px;padding:0;" title="Scheda pubblica" aria-label="Scheda pubblica"><i class="fa fa-eye" style="font-size:.85rem;" aria-hidden="true"></i></a>
                            <?php endif; ?>
                            <form method="POST" class="d-inline m-0">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="duplica_evento" value="<?php echo $ev['id']; ?>">
                                <input type="hidden" name="f_ev" value="<?php echo $filtro_ev; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary" style="border-radius:8px;width:34px;height:34px;padding:0;" data-confirm="Duplicare questo evento con turni, campi del form e sondaggio? Gli iscritti e le risposte non vengono copiati." title="Duplica evento" aria-label="Duplica evento"><i class="fa fa-copy" aria-hidden="true"></i></button>
                            </form>
                            <?php if ($can_manage_settings): ?>
                                <form method="POST" class="d-inline m-0">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="archivia_ev" value="<?php echo $ev['id']; ?>">
                                    <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                                    <input type="hidden" name="f_ev" value="<?php echo $filtro_ev; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-warning text-dark" style="border-radius:8px;width:34px;height:34px;padding:0;" data-confirm="Archiviare questo evento?" title="Archivia"><i class="fa fa-archive" style="font-size:.85rem;"></i></button>
                                </form>
                                <form method="POST" class="d-inline m-0">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="del_ev" value="<?php echo $ev['id']; ?>">
                                    <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                                    <input type="hidden" name="f_ev" value="<?php echo $filtro_ev; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" style="border-radius:8px;width:34px;height:34px;padding:0;" data-confirm="Eliminare questo evento e tutti i suoi turni e prenotazioni?" title="Elimina"><i class="fa fa-trash" style="font-size:.85rem;"></i></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Badge features -->
                    <div class="d-flex flex-wrap gap-1 mb-3">
                        <?php if($ev['richiede_prenotazione'] == 0): ?>
                            <span class="badge" style="background:#dcfce7;color:#166534;font-size:.68rem;">Accesso Libero</span>
                        <?php else: ?>
                            <span class="badge" style="background:#dbeafe;color:#1e40af;font-size:.68rem;">Prenotabile</span>
                        <?php endif; ?>
                        <?php if(($ev['abilita_presenze'] ?? 1) == 1): ?>
                            <span class="badge" style="background:#ede9fe;color:#5b21b6;font-size:.68rem;"><i class="fa fa-qrcode me-1"></i>Check-in</span>
                        <?php endif; ?>
                        <?php if(!empty($ev['locandina_path'])): ?>
                            <span class="badge" style="background:#fef9c3;color:#854d0e;font-size:.68rem;"><i class="fa fa-image me-1"></i>Locandina</span>
                        <?php endif; ?>
                        <?php if(!empty($ev['allegato_pdf'])): ?>
                            <span class="badge" style="background:#f1f5f9;color:#475569;font-size:.68rem;"><i class="fa fa-file-pdf me-1"></i>PDF</span>
                        <?php endif; ?>
                        <?php $notif_extra_ev = normalizza_lista_email($ev['email_notifiche_extra'] ?? ''); if ($notif_extra_ev): ?>
                            <span class="badge" style="background:#e0e7ff;color:#3730a3;font-size:.68rem;" title="<?php echo htmlspecialchars(implode(', ', $notif_extra_ev)); ?>"><i class="fa fa-envelope me-1" aria-hidden="true"></i>Notifiche in copia: <?php echo count($notif_extra_ev); ?></span>
                        <?php endif; ?>
                        <?php if ((int)($schede_ev[(int)$ev['id']]['attestati'] ?? 0) === 1): ?>
                            <span class="badge" style="background:#d1fae5;color:#065f46;font-size:.68rem;"><i class="fa fa-graduation-cap me-1" aria-hidden="true"></i>Attestati per la classe</span>
                        <?php endif; ?>
                        <?php $ref_notif_ev = array_filter($schede_ev[(int)$ev['id']]['referenti'] ?? [], fn($r) => !empty($r['notifiche']) && !empty($r['email'])); if ($ref_notif_ev): ?>
                            <span class="badge" style="background:#dcfce7;color:#166534;font-size:.68rem;" title="<?php echo htmlspecialchars(implode(', ', array_column($ref_notif_ev, 'email'))); ?>"><i class="fa fa-bell me-1" aria-hidden="true"></i>Referenti avvisati: <?php echo count($ref_notif_ev); ?></span>
                        <?php endif; ?>
                    </div>

                    <!-- ── TURNI ESISTENTI ── -->
                    <?php if(!empty($ev['turni'])): ?>
                    <div class="d-flex flex-column gap-2 mb-3">
                        <?php foreach($ev['turni'] as $t):
                            $t_passato = turno_concluso($t);
                        ?>
                        <div class="turno-chip <?php echo $t_passato ? 'opacity-50' : ''; ?>">
                            <div class="d-flex align-items-center gap-3 flex-wrap">
                                <?php if (!empty($t['nome_turno'])): ?>
                                    <span class="fw-bold text-dark" style="font-size:.85rem;"><i class="fa fa-tag me-1 text-secondary"></i><?php echo htmlspecialchars($t['nome_turno']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($t['data_turno'])): ?>
                                    <span class="fw-bold" style="color:<?php echo $col_area; ?>;font-size:.85rem;">
                                        <i class="fa fa-calendar me-1"></i><?php echo date('d/m/Y', strtotime($t['data_turno'])); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if (orario_turno($t) !== ''): ?>
                                    <span class="text-dark" style="font-size:.82rem;">
                                        <i class="fa fa-clock text-secondary me-1"></i><?php echo orario_turno($t); ?>
                                    </span>
                                <?php endif; ?>
                                <span class="badge" style="background:#f1f5f9;color:#334155;font-size:.72rem;font-weight:600;">
                                    <i class="fa fa-users me-1"></i><?php echo $t['max_posti']; ?> posti
                                </span>
                                <?php if($t['abilita_lista_attesa']): ?><span class="badge" style="background:#fef3c7;color:#92400e;font-size:.68rem;">L. Attesa</span><?php endif; ?>
                                <?php if($t['abilita_multi_posto']): ?><span class="badge" style="background:#e0f2fe;color:#0c4a6e;font-size:.68rem;">Multi-Posto</span><?php endif; ?>
                                <?php if($t['richiede_approvazione']): ?><span class="badge" style="background:#fee2e2;color:#991b1b;font-size:.68rem;">Approvazione</span><?php endif; ?>
                                <?php if(!empty($t['annullabile_fino'])): ?><span class="badge" style="background:#f1f5f9;color:#334155;font-size:.68rem;" title="Dopo questa data non si può più annullare né cambiare turno"><i class="fa fa-rotate-left me-1" aria-hidden="true"></i>Annullabile fino al <?php echo date('d/m H:i', strtotime($t['annullabile_fino'])); ?></span><?php endif; ?>
                            </div>
                            <div class="d-flex align-items-center gap-1 flex-shrink-0">
                                <a href="stampa_qr_aula.php?t_id=<?php echo $t['id']; ?>&p_id=<?php echo $filtro_p; ?>" class="btn btn-sm btn-outline-success py-0 px-2 fw-bold" style="border-radius:6px;font-size:.75rem;" title="QR Aula"><i class="fa fa-qrcode me-1"></i>QR Aula</a>
                                <a href="eventi.php?p_id=<?php echo $filtro_p; ?>&id=<?php echo (int)$ev['id']; ?>#turni" class="btn btn-sm btn-outline-primary py-0 px-2" style="border-radius:6px;" title="Modifica turno" aria-label="Modifica turno"><i class="fa fa-edit" aria-hidden="true"></i></a>
<form method="POST" class="d-inline m-0">                                    <?php csrf_field(); ?>                                    <input type="hidden" name="duplica_turno" value="<?php echo $t['id']; ?>">                                    <input type="hidden" name="f_ev" value="<?php echo $filtro_ev; ?>">                                    <button type="submit" class="btn btn-sm btn-outline-secondary py-0 px-2" style="border-radius:6px;" title="Duplica turno" aria-label="Duplica turno"><i class="fa fa-copy" aria-hidden="true"></i></button>                                </form>
                                <form method="POST" class="d-inline m-0">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="del_turno" value="<?php echo $t['id']; ?>">
                                    <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                                    <input type="hidden" name="f_ev" value="<?php echo $filtro_ev; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" style="border-radius:6px;" data-confirm="Eliminare questo turno?" title="Elimina turno"><i class="fa fa-times"></i></button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- ── AGGIUNGI / MODIFICA TURNI: nella pagina dell'evento ── -->
                    <a href="eventi.php?p_id=<?php echo $filtro_p; ?>&id=<?php echo (int)$ev['id']; ?>#turni" class="add-turno-toggle d-block text-center text-decoration-none">
                        <i class="fa fa-plus me-1" aria-hidden="true"></i> Aggiungi o modifica turni
                    </a>

                </div>
            </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'admin_footer.php'; ?>
