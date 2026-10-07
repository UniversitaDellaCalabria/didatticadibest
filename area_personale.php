<?php
// =========================================================================
// Fase 4: Autenticazione, SSO, dati utente — tutto centralizzato in middleware.php
// $u_id, $u_ruolo, $is_full_admin, $is_gestore, $user_info, $u_email_sql
// =========================================================================
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/middleware.php';

// =======================================================================
// AZIONE: ANNULLA UNA PRENOTAZIONE DI AULA, LABORATORIO O SPORTELLO (Calendari e risorse)
// =======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['annulla_pren_risorsa'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $p_ris = prenotazione_risorsa($conn, (int)$_POST['annulla_pren_risorsa']);
    $ok_ris = $p_ris && (int)$p_ris['utente_id'] === (int)$u_id && strtotime($p_ris['inizio']) > time()
              && cambia_stato_prenotazione_risorsa($conn, (int)$p_ris['id'], 'annullata', false);
    $_SESSION['msg_area_pers'] = $ok_ris
        ? "<div class='alert alert-success fw-bold text-center my-3 shadow-sm'><i class='fa fa-check-circle me-1'></i> Prenotazione annullata: lo slot è di nuovo libero.</div>"
        : "<div class='alert alert-warning fw-bold text-center my-3 shadow-sm'>Non è stato possibile annullare la prenotazione.</div>";
    header("Location: area_personale.php"); exit;
}

// =======================================================================
// AZIONE: INVIO MESSAGGIO ALLA SEGRETERIA (CHAT)
// =======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['invia_messaggio_utente'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $pr_id = (int)$_POST['prenotazione_id'];
    // La logica sta in src/Iscrizioni/ServizioAreaPersonale: verifica che la prenotazione sia dell'utente, salva e avvisa i gestori
    if (\App\Core\App::get(\App\Iscrizioni\ServizioAreaPersonale::class)->inviaMessaggio($pr_id, $u_id, $u_email_sql, (string)$_POST['corpo_messaggio'])) {
        $_SESSION['msg_area_pers'] = \App\Iscrizioni\Vista\MessaggiAreaPersonale::messaggioInviato();
    }
    header("Location: area_personale.php");
    exit;
}

// =======================================================================
// AZIONE: AGGIORNAMENTO EMAIL (TAB PROFILO)
// =======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aggiorna_email'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $nuova_email = strtolower(trim($_POST['nuova_email'] ?? ''));
    if (empty($nuova_email) || !filter_var($nuova_email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['msg_area_pers'] = "<div class='alert alert-danger fw-bold text-center my-3 shadow-sm'><i class='fa fa-times-circle me-1'></i> Inserisci un indirizzo email valido.</div>";
    } else {
        $risultato = aggiorna_email_utente($conn, $u_id, $nuova_email);
        if ($risultato === true) {
            $_SESSION['utente_email'] = $nuova_email;
            $_SESSION['msg_area_pers'] = "<div class='alert alert-success fw-bold text-center my-3 shadow-sm'><i class='fa fa-check-circle me-1'></i> Email aggiornata correttamente.</div>";
        } else {
            $_SESSION['msg_area_pers'] = "<div class='alert alert-danger fw-bold text-center my-3 shadow-sm'><i class='fa fa-times-circle me-1'></i> " . htmlspecialchars($risultato) . "</div>";
        }
    }
    header("Location: area_personale.php#profilo");
    exit;
}

// =======================================================================
// AZIONE: SCHEDA DI ATENEO (dati dalle API del portale, modificabili dalla persona)
// =======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aggiorna_scheda_ateneo'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $pers_sv = !empty($user_info['persona_id']) ? persona_ateneo($conn, $user_info['persona_id']) : null;
    if (!$pers_sv) {
        $_SESSION['msg_area_pers'] = "<div class='alert alert-danger fw-bold text-center my-3 shadow-sm'><i class='fa fa-times-circle me-1'></i> Il tuo profilo non è collegato all'anagrafe di Ateneo.</div>";
    } else {
        $dati_sv = isset($_POST['ripristina']) ? [] : $_POST;
        $err_sv = salva_modifiche_persona($conn, $pers_sv['id'], $dati_sv);
        if ($err_sv === null) registra_log_audit($conn, isset($_POST['ripristina']) ? "Scheda di Ateneo: ripristinati i dati del portale" : "Scheda di Ateneo modificata", ["Persona" => $pers_sv['id']]);
        $_SESSION['msg_area_pers'] = $err_sv === null
            ? "<div class='alert alert-success fw-bold text-center my-3 shadow-sm'><i class='fa fa-check-circle me-1'></i> " . (isset($_POST['ripristina']) ? "Ripristinati i dati del portale di Ateneo." : "Scheda aggiornata.") . "</div>"
            : "<div class='alert alert-danger fw-bold text-center my-3 shadow-sm'><i class='fa fa-times-circle me-1'></i> " . htmlspecialchars($err_sv) . "</div>";
    }
    header("Location: area_personale.php#profilo");
    exit;
}

// =======================================================================
// AZIONE: MODIFICA PRENOTAZIONE (SECURE - Prepared Statements + Cambio Turno)
// =======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_prenotazione_utente'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $pr_id = (int)$_POST['prenotazione_id'];
    $nuovo_turno_id = isset($_POST['nuovo_turno_id']) ? (int)$_POST['nuovo_turno_id'] : 0;

    $nome = trim($_POST['nome'] ?? '');
    $cognome = trim($_POST['cognome'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $matricola = trim($_POST['matricola'] ?? '');

    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base_dir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
    // La logica sta in src/Iscrizioni/ServizioAreaPersonale: transazione con blocco anti-overbooking sul turno di destinazione
    $_SESSION['msg_area_pers'] = \App\Core\App::get(\App\Iscrizioni\ServizioAreaPersonale::class)
        ->modifica($pr_id, $u_id, $u_email_sql, $nuovo_turno_id, $nome, $cognome, $email, $matricola, $_POST, $proto . $domain . $base_dir);
    header("Location: area_personale.php");
    exit;
}

// =======================================================================
// AZIONE: CANCELLAZIONE PRENOTAZIONE
// =======================================================================
if (isset($_GET['cancella_prenotazione'])) {
    csrf_verify($_GET['csrf'] ?? '');
    $pr_id = (int)$_GET['cancella_prenotazione'];

    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base_dir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
    // La riga resta nel DB come «annullata»; email alla persona e ai gestori, il posto passa al primo in coda
    $_SESSION['msg_area_pers'] = \App\Core\App::get(\App\Iscrizioni\ServizioAreaPersonale::class)
        ->annulla($pr_id, $u_id, $u_email_sql, $proto . $domain . $base_dir);
    header("Location: area_personale.php");
    exit;
}

// =======================================================================
// AZIONE: CONFERMA / RINUNCIA POSTO LIBERATO DALLA LISTA D'ATTESA
// Il link nell'email (promuovi_lista_attesa) apre solo il riquadro di scelta;
// la conferma vera e propria avviene via POST con CSRF.
// =======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['conferma_posto_ok']) || isset($_POST['rinuncia_posto']))) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $pr_id    = (int)($_POST['pr_id'] ?? 0);
    $conferma = isset($_POST['conferma_posto_ok']);

    // Controllo dell'offerta in transazione con la riga bloccata (src/Iscrizioni/ServizioAreaPersonale)
    $_SESSION['msg_area_pers'] = \App\Core\App::get(\App\Iscrizioni\ServizioAreaPersonale::class)->rispondiAlPosto($pr_id, $u_id, $u_email_sql, $conferma);
    header("Location: area_personale.php");
    exit;
}

$box_conferma_posto = null;
if (isset($_GET['conferma_posto'])) {
    $r_off = \App\Core\App::get(\App\Iscrizioni\ServizioAreaPersonale::class)->riquadroOfferta((int)$_GET['conferma_posto'], $u_id, $u_email_sql);
    $box_conferma_posto = $r_off['riquadro'];
    if ($r_off['messaggio'] !== null) $_SESSION['msg_area_pers'] = $r_off['messaggio'];
}

// =======================================================================
// ESTRAZIONE DATI PRENOTAZIONI DELL'UTENTE E TURNI ALTERNATIVI
// =======================================================================

// Pulsanti attestato / elenco studenti di una prenotazione (card dell'Area personale).
// Prenotazioni di classe (progetti per le scuole, eventi con attestati per gli studenti): elenco studenti
// + attestati della classe (dopo l'invio). Eventi: attestato dopo il check-in. Progetti generici: a progetto concluso.
function pulsanti_attestato_pr($conn, array $pr): string {
    $st = $pr['stato'] ?? 'confermata';
    $confermata = in_array($st, ['confermata', 'confermato', 'confirmed'], true);
    $code = urlencode($pr['codice_prenotazione']);
    $btn_att = fn($label) => '<a href="stampa_attestato.php?code=' . $code . '" target="_blank" class="btn btn-success btn-sm fw-bold" style="background:#198754;border:none;"><i class="fa fa-graduation-cap me-1"></i>' . $label . '</a>';
    // Attività FSL: elenco degli studenti e autorizzazione della scuola da consegnare prima dell'inizio (anche in attesa della convenzione)
    $docs = \App\Core\App::get(\App\Fsl\ServizioDocumentiClasse::class);
    if ($docs->consegnaAperta($pr)) {
        $s = $docs->stato($pr);
        $mancano = array_filter([!$s['elenco'] ? 'elenco' : '', !$s['autorizzazione'] ? 'autorizzazione' : '']);
        $out = '<a href="elenco_studenti.php?code=' . $code . '" class="btn btn-sm fw-bold ' . ($s['completi'] ? 'btn-outline-success' : 'btn-warning') . '"><i class="fa fa-paperclip me-1"></i>'
             . ($s['completi'] ? 'Elenco studenti (' . $s['studenti'] . ') e autorizzazione' : 'Carica ' . implode(' e ', $mancano)) . '</a>';
        if (!$s['completi'] && $s['scadenza'] !== null) $out .= ' <span class="small fw-semibold ' . ($s['scaduti'] ? 'text-danger' : 'text-secondary') . '">entro il ' . date('d/m/Y', strtotime($s['scadenza'])) . '</span>';
        if ((int)($pr['attestati'] ?? 0) === 1 && !empty($pr['attestato_inviato'])) $out .= ' <a href="attestati_gruppo.php?code=' . $code . '" target="_blank" class="btn btn-success btn-sm fw-bold" style="background:#198754;border:none;"><i class="fa fa-graduation-cap me-1"></i>Attestati degli studenti</a>';
        return $out;
    }
    $di_classe = attestati_di_classe($pr);
    if (($pr['evento_tipo'] ?? '') !== 'progetto' && !$di_classe) {
        return ((int)$pr['presente'] === 1 && $confermata) ? $btn_att('Attestato') : '';
    }
    if ((int)($pr['attestati'] ?? 0) !== 1 || !$confermata) return '';
    if ($di_classe) {
        $n = \App\Core\App::get(\App\Eventi\ProgettoRepository::class)->contaStudenti((int)$pr['id']);
        $out = '<a href="elenco_studenti.php?code=' . $code . '" class="btn btn-outline-success btn-sm fw-bold"><i class="fa fa-list-ol me-1"></i>Elenco studenti (' . $n . ')</a>';
        if (!empty($pr['attestato_inviato'])) $out .= ' <a href="attestati_gruppo.php?code=' . $code . '" target="_blank" class="btn btn-success btn-sm fw-bold" style="background:#198754;border:none;"><i class="fa fa-graduation-cap me-1"></i>Attestati degli studenti</a>';
        return $out;
    }
    $concluso = empty($pr['progetto_fine']) || $pr['progetto_fine'] < date('Y-m-d');
    return ((int)$pr['presente'] === 1 && $concluso) ? $btn_att('Attestato') : '';
}
// Prenotazioni attive (con i turni alternativi a cui passare) e passate: tre query in tutto, qualunque sia lo storico
// (src/Iscrizioni/ServizioAreaPersonale)
$lista_pr = \App\Core\App::get(\App\Iscrizioni\ServizioAreaPersonale::class)->prenotazioni($u_id, $u_email_sql);
$prenotazioni_attive = $lista_pr['attive'];
$prenotazioni_passate = $lista_pr['passate'];

// Posizione in lista d'attesa ("Sei 3° in lista") per le prenotazioni in coda
$pos_attesa = get_posizioni_lista_attesa($conn, array_column(
    array_filter($prenotazioni_attive, fn($p) => ($p['stato'] ?? '') === 'in_attesa'), 'id'));

// RECUPERO TUTTI I MESSAGGI PER LE PRENOTAZIONI DELL'UTENTE
$all_pr_ids     = array_merge(array_column($prenotazioni_attive, 'id'), array_column($prenotazioni_passate, 'id'));
$messaggi_per_pr = get_messaggi_per_prenotazioni($conn, $all_pr_ids);

// Programma FSL: attività scelte e non ancora prenotate, e documenti (Allegato A, Convenzione) delle prenotazioni FSL già fatte
$fsl_documenti = []; $fsl_programma = 0; $fsl_in_programma = [];
try {
    $fsl_documenti = \App\Core\App::per($conn)->get(\App\Fsl\ServizioConvenzioneOnline::class)->dellePrenotazioni(array_map('intval', $all_pr_ids));
    $fsl_programma = \App\Core\App::get(\App\Fsl\ServizioProgrammaFsl::class)->conta();
    if ($fsl_programma) $fsl_in_programma = \App\Core\App::get(\App\Fsl\ServizioProgrammaFsl::class)->elenco();
} catch (\Throwable $e_fsl) { error_log('[Area personale] programma FSL non disponibile: ' . $e_fsl->getMessage()); }

// Stats rapide per l'header
$all_pr_merged = array_merge($prenotazioni_attive, $prenotazioni_passate);
$stat_presenze  = 0;
$stat_attestati = 0;
foreach ($all_pr_merged as $pr_s) {
    if ((int)($pr_s['presente'] ?? 0) === 1) {
        $stat_presenze++;
        if (in_array($pr_s['stato'] ?? '', ['confermata','confermato','confirmed'])) $stat_attestati++;
    }
}

function countdown_to($data_turno, $orario_inizio) {
    if (empty($data_turno)) return null;
    $diff = strtotime($data_turno . ' ' . $orario_inizio) - time();
    if ($diff <= 0) return null;
    if ($diff < 3600)  return ['label' => 'Tra ' . max(1, round($diff / 60)) . ' min', 'cls' => 'danger'];
    if ($diff < 86400) return ['label' => 'Tra ' . round($diff / 3600) . ' ore', 'cls' => 'warning'];
    $d = (int)round($diff / 86400);
    if ($d === 1)     return ['label' => 'Domani', 'cls' => 'info'];
    if ($d <= 14)     return ['label' => 'Tra ' . $d . ' giorni', 'cls' => 'primary'];
    return null;
}

require_once 'header.php';
?>

<style>
    .custom-tabs .nav-link { color: #475569; transition: all 0.2s ease; }
    .custom-tabs .nav-link:hover { color: #0d6efd; background-color: #f8f9fa; }
    .custom-tabs .nav-link.active { background-color: #0d6efd !important; color: #ffffff !important; }
    .btn-glass {
        background-color: rgba(255,255,255,0.1);
        border: 2px solid rgba(255,255,255,0.6);
        color: #ffffff;
        backdrop-filter: blur(5px);
        transition: all 0.3s ease;
    }
    .btn-glass:hover { background-color: rgba(255,255,255,0.25); border-color: #ffffff; color: #ffffff; transform: translateY(-2px); }
    .stat-kpi { text-align: center; padding: 10px 16px; }
    .stat-kpi .val { font-size: 1.8rem; font-weight: 700; line-height: 1; }
    .stat-kpi .lbl { font-size: 0.7rem; opacity: 0.75; text-transform: uppercase; letter-spacing: .05em; margin-top: 3px; }
    .countdown-badge { font-size: 0.7rem; font-weight: 600; padding: 3px 10px; border-radius: 20px; letter-spacing: .02em; }
    .card-evento-attivo { border-radius: 12px; border: none; border-left: 5px solid #dee2e6; }
    .card-evento-passato { border-radius: 12px; border: none; border-left: 5px solid #dee2e6; opacity: .92; }
    .azioni-desktop { display: flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end; }
    @media (max-width: 575px) { .azioni-desktop { justify-content: flex-start; } }
    .presenza-grande { padding: 8px 14px; border-radius: 10px; font-weight: 600; font-size: .9rem; display: inline-flex; align-items: center; gap: 6px; }
</style>

<div class="container my-4" style="max-width: 900px;">
    
    <!-- INTESTAZIONE CON STATS + SCANNER -->
    <div class="card shadow-sm border-0 mb-4 overflow-hidden" style="border-radius: 14px;">
        <div class="p-4 text-center" style="background: linear-gradient(135deg, #1e293b 0%, #334155 100%); color: white;">
            <div class="d-inline-flex justify-content-center align-items-center bg-white text-dark rounded-circle mb-2 shadow" style="width: 52px; height: 52px;">
                <i class="fa fa-user-circle fs-3 text-primary"></i>
            </div>
            <h4 class="fw-bold m-0 mb-1">Area Personale</h4>
            <p class="opacity-75 m-0 small fw-bold text-uppercase"><?php echo htmlspecialchars($user_info['nome'] . ' ' . $user_info['cognome']); ?></p>
            <?php if (!empty($user_info['matricola_studente']) || !empty($user_info['matricola_dipendente'])): ?>
                <div class="mt-1"><span class="badge bg-light text-dark font-monospace shadow-sm">Matricola: <?php echo htmlspecialchars($user_info['matricola_studente'] ?: $user_info['matricola_dipendente']); ?></span></div>
            <?php endif; ?>
            <?php $pers_ap = !empty($user_info['persona_id']) ? persona_ateneo($conn, $user_info['persona_id']) : null; if ($pers_ap): ?>
                <?php $voci_ap = []; foreach ([GRUPPI_PERSONALE[$pers_ap['gruppo']] ?? '', $pers_ap['ruolo'], $pers_ap['struttura']] as $v_ap) { $v_ap = trim((string)$v_ap); if ($v_ap !== '') $voci_ap[mb_strtolower($v_ap)] = $v_ap; } ?>
                <div class="mt-1 small"><i class="fa fa-address-book me-1" aria-hidden="true"></i><?php echo htmlspecialchars(implode(' · ', $voci_ap)); ?></div>
            <?php endif; ?>

            <!-- MINI KPI -->
            <div class="row g-0 mt-3 pt-3 border-top border-secondary">
                <div class="col-4 stat-kpi border-end border-secondary">
                    <div class="val"><?php echo count($prenotazioni_attive); ?></div>
                    <div class="lbl">Prossimi</div>
                </div>
                <div class="col-4 stat-kpi border-end border-secondary">
                    <div class="val"><?php echo $stat_presenze; ?></div>
                    <div class="lbl">Presenze</div>
                </div>
                <div class="col-4 stat-kpi">
                    <div class="val"><?php echo $stat_attestati; ?></div>
                    <div class="lbl">Attestati</div>
                </div>
            </div>

            <!-- SCANNER -->
            <div class="mt-3 pt-3 border-top border-secondary">
                <a href="scanner_studente.php" class="btn btn-glass btn-lg fw-bold rounded-pill px-4 shadow-sm">
                    <i class="fa fa-camera me-2"></i> Registra Presenza (Scanner QR)
                </a>
                <p class="small mt-2 mb-0" style="color:rgba(255,255,255,0.6);">Inquadra il QR Code in aula per il check-in.</p>
            </div>
        </div>
    </div>

    <?php 
    if (isset($_SESSION['msg_area_pers'])) {
        echo $_SESSION['msg_area_pers'];
        unset($_SESSION['msg_area_pers']);
    }
    ?>

    <?php if ($box_conferma_posto): $bc = $box_conferma_posto; ?>
        <div class="card border-0 shadow my-4" style="border-left: 6px solid #198754 !important; border-radius: 10px;">
            <div class="card-body p-4">
                <h4 class="fw-bold text-success mb-2"><i class="fa fa-bell me-2"></i>Si è liberato un posto per te!</h4>
                <p class="mb-1 fs-5 fw-bold text-dark"><?php echo htmlspecialchars($bc['evento_titolo']); ?></p>
                <p class="mb-2 text-secondary fw-semibold">
                    <i class="fa fa-calendar-day me-1"></i><?php echo htmlspecialchars(etichetta_turno($bc)); ?>
                    <?php if (!empty($bc['luogo'])): ?> · <i class="fa fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($bc['luogo']); ?><?php endif; ?>
                    <?php if ((int)$bc['num_posti'] > 1): ?> · <?php echo (int)$bc['num_posti']; ?> posti<?php endif; ?>
                </p>
                <?php if (!empty($bc['scadenza_conferma'])): ?>
                    <div class="alert alert-warning py-2 small fw-bold mb-3"><i class="fa fa-hourglass-half me-1"></i> Conferma entro il <?php echo date('d/m/Y \a\l\l\e H:i', strtotime($bc['scadenza_conferma'])); ?>, altrimenti il posto passerà al prossimo in lista.</div>
                <?php endif; ?>
                <form method="POST" action="area_personale.php" class="d-flex flex-wrap gap-2">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="pr_id" value="<?php echo (int)$bc['id']; ?>">
                    <button type="submit" name="conferma_posto_ok" value="1" class="btn btn-success fw-bold px-4"><i class="fa fa-check me-1"></i>Conferma il mio posto</button>
                    <button type="submit" name="rinuncia_posto" value="1" class="btn btn-outline-secondary fw-bold" onclick="return confirm('Rinunci al posto? Verrà assegnato al prossimo in lista d\'attesa.');"><i class="fa fa-times me-1"></i>Rinuncio</button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php
    // Aule, laboratori e appuntamenti prenotati (aree Calendari e risorse)
    $mie_risorse = \App\Core\App::get(\App\Iscrizioni\AreaPersonaleRepository::class)->risorsePrenotate((int)$u_id);
    if ($mie_risorse): ?>
        <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
            <div class="card-body">
                <h2 class="h6 fw-bold mb-3"><i class="fa fa-door-open me-1 text-primary" aria-hidden="true"></i>Aule, laboratori e appuntamenti (<?php echo count($mie_risorse); ?>)</h2>
                <ul class="list-unstyled mb-0 small">
                <?php foreach ($mie_risorse as $mr): $col_mr = colore_valido($mr['colore_primario'] ?? '', '#0056B3'); ?>
                    <li class="d-flex flex-wrap align-items-center gap-2 py-2 border-bottom" style="border-left: 4px solid <?php echo $col_mr; ?>; padding-left: 10px;">
                        <div>
                            <strong><?php echo htmlspecialchars(quando_risorsa($mr)); ?></strong>
                            <div><?php echo htmlspecialchars($mr['risorsa_nome']); ?><?php echo $mr['luogo'] !== '' ? ' · ' . htmlspecialchars($mr['luogo']) : ''; ?> · <a href="<?php echo htmlspecialchars($mr['area_slug']); ?>.php?risorsa=<?php echo (int)$mr['risorsa_id']; ?>" class="text-decoration-none"><?php echo htmlspecialchars($mr['area_titolo']); ?></a></div>
                            <?php if ($mr['motivo'] !== ''): ?><div class="text-secondary"><?php echo htmlspecialchars($mr['motivo']); ?></div><?php endif; ?>
                        </div>
                        <?php if ($mr['stato'] === 'da_approvare'): ?><span class="badge bg-warning text-dark">In attesa di approvazione</span><?php endif; ?>
                        <span class="ms-auto d-flex gap-2 align-items-center">
                            <a href="risorsa_ics.php?code=<?php echo urlencode($mr['codice']); ?>" class="btn btn-outline-secondary btn-sm" title="Aggiungi al calendario"><i class="fa fa-calendar-plus" aria-hidden="true"></i><span class="visually-hidden">Aggiungi al calendario</span></a>
                            <form method="POST" action="area_personale.php" class="m-0"><?php csrf_field(); ?>
                                <button type="submit" name="annulla_pren_risorsa" value="<?php echo (int)$mr['id']; ?>" class="btn btn-outline-danger btn-sm fw-bold" onclick="return confirm('Annullare la prenotazione?');">Annulla</button></form>
                        </span>
                    </li>
                <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <?php
    // Sportelli di ricevimento (del docente o, per i suoi operatori, dell'Ufficio didattico): gestione e prossimi appuntamenti
    $ids_ric = array_map('intval', array_column(sportelli_utente($conn, $user_info), 'id'));
    if ($ids_ric):
        $ric_rows = \App\Core\App::get(\App\Iscrizioni\AreaPersonaleRepository::class)->riepilogoSportelli($ids_ric);
        if ($ric_rows): $n_ric = array_sum(array_column($ric_rows, 'n')); $n_appr = array_sum(array_column($ric_rows, 'da_appr')); ?>
        <a href="ricevimento.php" class="card border-0 shadow-sm mb-4 text-decoration-none" style="border-radius: 12px; border-left: 5px solid #7c3aed !important;">
            <div class="card-body d-flex align-items-center gap-3 flex-wrap">
                <i class="fa fa-user-clock fs-3" style="color:#7c3aed;" aria-hidden="true"></i>
                <div class="flex-grow-1">
                    <div class="fw-bold text-dark">Il mio ricevimento</div>
                    <div class="small text-secondary"><?php echo $n_ric; ?> appuntamenti in programma<?php echo $n_appr ? " · $n_appr da approvare" : ''; ?> · giorni, orari e assenze</div>
                </div>
                <span class="btn btn-sm fw-bold text-white" style="background:#7c3aed;">Gestisci</span>
            </div>
        </a>
    <?php endif; endif; ?>

    <?php
    // Pratiche della didattica (moduli online): collegamento a Le mie pratiche se l'utente ne ha
    $pr_rows = \App\Core\App::get(\App\Iscrizioni\AreaPersonaleRepository::class)->riepilogoPratiche((int)$_SESSION['utente_id']);
    if ($pr_rows && (int)$pr_rows['n'] > 0): ?>
        <a href="pratiche.php" class="card border-0 shadow-sm mb-4 text-decoration-none" style="border-radius: 12px; border-left: 5px solid #047857 !important;">
            <div class="card-body d-flex align-items-center gap-3 flex-wrap">
                <i class="fa fa-folder-open fs-3" style="color:#047857;" aria-hidden="true"></i>
                <div class="flex-grow-1">
                    <div class="fw-bold text-dark">Le mie pratiche</div>
                    <div class="small text-secondary"><?php echo (int)$pr_rows['aperte']; ?> in corso su <?php echo (int)$pr_rows['n']; ?><?php echo (int)$pr_rows['integr'] ? ' · <strong class="text-danger">' . (int)$pr_rows['integr'] . ' con integrazione richiesta</strong>' : ''; ?> · <span class="text-decoration-underline">modulistica</span></div>
                </div>
                <span class="btn btn-sm fw-bold text-white" style="background:#047857;">Apri</span>
            </div>
        </a>
    <?php endif; ?>

    <?php
    // Tutorato: registro delle attività per il tutor (lettera firmata) e per il docente responsabile
    $reg_tut = function_exists('incarichi_registro') ? incarichi_registro($conn, $user_info ?? null) : [];
    if ($reg_tut):
        $da_fare = count(array_filter($reg_tut, fn($i) => $i['_ruolo'] === 'docente' && ($i['fine_stato'] === 'richiesta' || \App\Core\App::get(\App\Iscrizioni\AreaPersonaleRepository::class)->registriTutoratoInviati((int)$i['id']) > 0))); ?>
        <a href="registro_tutorato.php" class="card border-0 shadow-sm mb-4 text-decoration-none" style="border-radius: 12px; border-left: 5px solid #0f766e !important;">
            <div class="card-body d-flex align-items-center gap-3 flex-wrap">
                <i class="fa fa-clipboard-list fs-3" style="color:#0f766e;" aria-hidden="true"></i>
                <div class="flex-grow-1">
                    <div class="fw-bold text-dark">Registro delle attività di tutorato</div>
                    <div class="small text-secondary"><?php echo count($reg_tut); ?> incarichi<?php echo $da_fare ? ' · <strong class="text-danger">' . $da_fare . ' da controllare</strong>' : ''; ?></div>
                </div>
                <span class="btn btn-sm fw-bold text-white" style="background:#0f766e;">Apri</span>
            </div>
        </a>
    <?php endif; ?>

    <!-- MENU A TAB (RESTYLING COLORI) -->
    <ul class="nav nav-pills nav-fill gap-2 p-1 bg-light rounded-pill border mb-4 shadow-sm custom-tabs" id="pills-tab" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active rounded-pill fw-bold" id="pills-attive-tab" data-bs-toggle="pill" data-bs-target="#pills-attive" type="button" role="tab">
                <i class="fa fa-calendar-check me-1"></i> Prossimi Eventi (<?php echo count($prenotazioni_attive); ?>)
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link rounded-pill fw-bold" id="pills-passate-tab" data-bs-toggle="pill" data-bs-target="#pills-passate" type="button" role="tab">
                <i class="fa fa-history me-1"></i> Storico & Attestati (<?php echo count($prenotazioni_passate); ?>)
            </button>
        </li>
        <?php if ($fsl_documenti || $fsl_programma): ?>
        <li class="nav-item" role="presentation">
            <button class="nav-link rounded-pill fw-bold" id="pills-fsl-tab" data-bs-toggle="pill" data-bs-target="#pills-fsl" type="button" role="tab">
                <i class="fa fa-clipboard-list me-1"></i> Programma FSL<?php echo $fsl_programma ? ' (' . $fsl_programma . ')' : ''; ?>
            </button>
        </li>
        <?php endif; ?>
        <li class="nav-item" role="presentation">
            <button class="nav-link rounded-pill fw-bold" id="pills-sondaggi-tab" data-bs-toggle="pill" data-bs-target="#pills-sondaggi" type="button" role="tab">
                <i class="fa fa-poll me-1"></i> Sondaggi
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link rounded-pill fw-bold" id="pills-profilo-tab" data-bs-toggle="pill" data-bs-target="#pills-profilo" type="button" role="tab">
                <i class="fa fa-user-edit me-1"></i> Profilo
            </button>
        </li>
    </ul>

    <div class="tab-content" id="pills-tabContent">
        
        <!-- TAB 1: PRENOTAZIONI ATTIVE -->
        <div class="tab-pane fade show active" id="pills-attive" role="tabpanel" tabindex="0">
            <?php if (empty($prenotazioni_attive)): ?>
                <div class="alert alert-light text-center border p-5 shadow-sm rounded-4 text-muted">
                    <i class="fa fa-ticket-alt fs-1 d-block mb-3 opacity-50"></i>
                    <h5 class="fw-bold">Nessuna prenotazione futura</h5>
                    <p class="m-0">Non hai ancora prenotato eventi imminenti. Visita la Home per scoprire i prossimi appuntamenti.</p>
                    <a href="index.php" class="btn btn-primary fw-bold mt-3 px-4 shadow-sm rounded-pill">Sfoglia Eventi</a>
                </div>
            <?php else: ?>
                <div class="d-flex flex-column gap-3">
                    <?php foreach ($prenotazioni_attive as $pr): ?>
                        <?php
                            $col_p   = colore_valido($pr['colore_primario'] ?? '', '#0056B3');
                            $st      = $pr['stato'];
                            $cd      = countdown_to($pr['data_turno'], $pr['orario_inizio']);
                            $unread  = 0;
                            if (isset($messaggi_per_pr[$pr['id']])) {
                                foreach ($messaggi_per_pr[$pr['id']] as $m) {
                                    if ($m['mittente_tipo'] === 'admin' && $m['letto'] == 0) $unread++;
                                }
                            }
                            $href_annulla = '?cancella_prenotazione=' . $pr['id'] . '&csrf=' . urlencode(csrf_token());
                        ?>
                        <div class="card card-evento-attivo shadow-sm mb-1" style="border-left-color:<?php echo $col_p; ?>;">
                            <div class="card-body p-3 p-md-4">
                                <!-- TOP ROW: titolo + countdown -->
                                <div class="d-flex align-items-start justify-content-between gap-2 mb-2 flex-wrap">
                                    <div class="d-flex align-items-center gap-2">
                                        <?php if (!empty($pr['locandina_path'])): ?>
                                            <img src="<?php echo htmlspecialchars($pr['locandina_path']); ?>" alt="" class="rounded d-none d-sm-block flex-shrink-0" style="width:48px;height:48px;object-fit:cover;">
                                        <?php endif; ?>
                                        <div>
                                            <h5 class="fw-bold m-0 mb-1" style="color:<?php echo $col_p; ?>;"><?php echo htmlspecialchars($pr['evento_titolo']); ?></h5>
                                            <div class="small text-muted fw-bold text-uppercase"><i class="fa fa-layer-group me-1"></i><?php echo htmlspecialchars($pr['pagina_titolo']); ?></div>
                                        </div>
                                    </div>
                                    <?php if ($cd): ?>
                                        <span class="countdown-badge bg-<?php echo $cd['cls']; ?> <?php echo $cd['cls'] === 'warning' ? 'text-dark' : 'text-white'; ?> flex-shrink-0">
                                            <i class="fa fa-clock me-1"></i><?php echo $cd['label']; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <!-- META INFO -->
                                <div class="d-flex flex-wrap gap-3 small fw-semibold text-secondary bg-light p-2 rounded mb-3">
                                    <?php if (!empty($pr['nome_turno'])): ?><span><i class="fa fa-tag text-secondary me-1"></i><?php echo htmlspecialchars($pr['nome_turno']); ?></span><?php endif; ?>
                                    <?php if (!empty($pr['data_turno'])): ?><span><i class="fa fa-calendar-day text-danger me-1"></i><?php echo date('d/m/Y', strtotime($pr['data_turno'])); ?></span><?php endif; ?>
                                    <?php if (!empty($pr['orario_inizio'])): ?><span><i class="fa fa-clock text-primary me-1"></i><?php echo substr($pr['orario_inizio'], 0, 5); ?></span><?php endif; ?>
                                    <?php if (!empty($pr['evento_luogo'])): ?><span><i class="fa fa-map-marker-alt text-success me-1"></i><?php echo htmlspecialchars($pr['evento_luogo']); ?></span><?php endif; ?>
                                    <span><i class="fa fa-hashtag text-secondary me-1"></i>Ticket: <strong class="text-dark font-monospace"><?php echo $pr['codice_prenotazione']; ?></strong></span>
                                    <?php $bloccata_pr = annullamento_scaduto($pr) && $st !== 'in_attesa';
                                          if (!empty($pr['annullabile_fino']) && !in_array($st, ['annullata', 'rifiutata'], true)): ?>
                                        <span class="<?php echo $bloccata_pr ? 'text-danger' : ''; ?>"><i class="fa <?php echo $bloccata_pr ? 'fa-lock' : 'fa-rotate-left'; ?> me-1"></i><?php echo $bloccata_pr ? 'Non più annullabile' : 'Annullabile fino al ' . date('d/m/Y H:i', strtotime($pr['annullabile_fino'])); ?></span>
                                    <?php endif; ?>
                                </div>

                                <!-- STATO + AZIONI -->
                                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                                    <div>
                                        <?php
                                        if ($st === 'in_attesa') {
                                            echo '<span class="badge bg-warning text-dark px-3 py-2"><i class="fa fa-clock me-1" aria-hidden="true"></i>Lista d\'Attesa</span>';
                                            if (isset($pos_attesa[(int)$pr['id']])) {
                                                $pa = $pos_attesa[(int)$pr['id']];
                                                echo ' <span class="badge bg-light text-dark border px-3 py-2" title="Persone in lista d\'attesa per questo turno: ' . $pa['totale'] . '">'
                                                   . ($pa['posizione'] === 1 ? 'Sei il prossimo in lista!' : 'Sei ' . $pa['posizione'] . '° in lista')
                                                   . ' <span class="fw-normal text-muted">su ' . $pa['totale'] . '</span></span>';
                                            }
                                        }
                                        elseif ($st === 'richiesta_conferma') echo '<span class="badge bg-warning text-dark px-3 py-2"><i class="fa fa-bell me-1"></i>Posto disponibile</span> <a href="area_personale.php?conferma_posto=' . (int)$pr['id'] . '" class="btn btn-success btn-sm fw-bold ms-1"><i class="fa fa-check me-1"></i>Conferma ora</a>';
                                        elseif ($st === 'da_approvare' && ($pr['convenzione'] ?? '') === 'no') echo '<span class="badge bg-warning text-dark px-3 py-2"><i class="fa fa-file-signature me-1"></i>In attesa della convenzione</span>';
                                        elseif ($st === 'da_approvare') echo '<span class="badge bg-info text-dark px-3 py-2"><i class="fa fa-hourglass-half me-1"></i>In Valutazione</span>';
                                        elseif ($st === 'rifiutata')  echo '<span class="badge bg-secondary px-3 py-2"><i class="fa fa-times me-1"></i>Rifiutata</span>';
                                        elseif ($st === 'annullata')  echo '<span class="badge bg-danger px-3 py-2"><i class="fa fa-ban me-1"></i>Annullata</span>';
                                        else                          echo '<span class="badge bg-success px-3 py-2"><i class="fa fa-check-circle me-1"></i>Confermata</span>';
                                        ?>
                                    </div>
                                    <div class="azioni-desktop">
                                        <button type="button" class="btn btn-outline-secondary btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#modChatStudente<?php echo $pr['id']; ?>">
                                            <i class="fa fa-comments me-1"></i>Assistenza<?php if ($unread > 0): ?> <span class="badge bg-danger"><?php echo $unread; ?></span><?php endif; ?>
                                        </button>
                                        <?php if ($st !== 'rifiutata'): ?>
                                        <a href="stampa_ricevuta.php?code=<?php echo urlencode($pr['codice_prenotazione']); ?>" target="_blank" class="btn btn-outline-dark btn-sm fw-bold">
                                            <i class="fa fa-file-pdf me-1"></i>Ricevuta&nbsp;/&nbsp;QR
                                        </a>
                                        <?php endif; ?>
                                        <?php echo pulsanti_attestato_pr($conn, $pr); ?>
                                        <?php if ($st !== 'annullata' && $st !== 'rifiutata'): ?>
                                        <button type="button" class="btn btn-sm fw-bold text-white" style="background:<?php echo $col_p; ?>;border:none;" data-bs-toggle="modal" data-bs-target="#modEditUser<?php echo $pr['id']; ?>">
                                            <i class="fa fa-edit me-1"></i>Modifica
                                        </button>
                                        <?php if (!$bloccata_pr): ?>
                                        <button type="button" class="btn btn-outline-danger btn-sm fw-bold"
                                            data-href="<?php echo htmlspecialchars($href_annulla); ?>"
                                            data-titolo="<?php echo htmlspecialchars($pr['evento_titolo']); ?>"
                                            onclick="apriFinestraAnnulla(this)">
                                            <i class="fa fa-times me-1"></i>Annulla
                                        </button>
                                        <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- MODALE CHAT STUDENTE -->
                        <div class="modal fade" id="modChatStudente<?php echo $pr['id']; ?>" tabindex="-1">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content shadow-lg border-0">
                                    <div class="modal-header py-3 bg-secondary text-white">
                                        <h6 class="modal-title fw-bold"><i class="fa fa-comments me-2"></i> Assistenza Segreteria - <?php echo htmlspecialchars($pr['evento_titolo']); ?></h6>
                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body p-0 bg-light text-start">
                                        <div class="p-4" style="max-height: 400px; overflow-y: auto; background: #f8fafc;">
                                            <?php 
                                            $chat_msgs = $messaggi_per_pr[$pr['id']] ?? [];
                                            if(empty($chat_msgs)): 
                                            ?>
                                                <div class="text-center text-muted my-4 small">
                                                    <i class="fa fa-comment-slash fs-3 mb-2 opacity-50 d-block"></i>
                                                    Hai bisogno di informazioni su questo evento? Invia un messaggio alla segreteria.
                                                </div>
                                            <?php else: ?>
                                                <?php foreach($chat_msgs as $msg): ?>
                                                    <?php if($msg['mittente_tipo'] === 'utente'): ?>
                                                        <div class="d-flex justify-content-end mb-3">
                                                            <div style="max-width: 80%;">
                                                                <div class="small text-muted text-end mb-1" style="font-size: 0.7rem;">Tu - <?php echo date('d/m/Y H:i', strtotime($msg['data_invio'])); ?></div>
                                                                <div class="p-2 rounded-3 text-dark shadow-sm bg-white border" style="border-bottom-right-radius: 0 !important;">
                                                                    <?php echo nl2br(htmlspecialchars($msg['messaggio'])); ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="d-flex justify-content-start mb-3">
                                                            <div style="max-width: 80%;">
                                                                <div class="small text-muted mb-1" style="font-size: 0.7rem;">Segreteria - <?php echo date('d/m/Y H:i', strtotime($msg['data_invio'])); ?></div>
                                                                <?php $col_bolla = colore_valido($pr['colore_primario'] ?? '', '#B80000'); ?><div class="p-2 rounded-3 shadow-sm" style="background-color: <?php echo $col_bolla; ?>; color: <?php echo colore_testo_su($col_bolla); ?>; border-bottom-left-radius: 0 !important;">
                                                                    <?php echo strip_tags($msg['messaggio'], '<b><strong><i><em><u><br><p><ul><ol><li><span>'); ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <form method="POST" class="border-top p-3 bg-white">
                                            <?php csrf_field(); ?>
                                            <input type="hidden" name="prenotazione_id" value="<?php echo $pr['id']; ?>">
                                            <label class="form-label small fw-bold text-primary">Invia un messaggio</label>
                                            <textarea name="corpo_messaggio" class="form-control" rows="3" placeholder="Scrivi qui la tua richiesta..." required></textarea>
                                            <div class="text-end mt-3">
                                                <button type="submit" name="invia_messaggio_utente" class="btn btn-primary fw-bold px-4 shadow-sm"><i class="fa fa-paper-plane me-1"></i> Invia</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- MODALE MODIFICA UTENTE -->
                        <div class="modal fade" id="modEditUser<?php echo $pr['id']; ?>" tabindex="-1">
                            <div class="modal-dialog modal-dialog-centered modal-lg">
                                <div class="modal-content shadow-lg border-0" style="border-radius: 12px;">
                                    <form method="POST">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="prenotazione_id" value="<?php echo $pr['id']; ?>">
                                        <input type="hidden" name="edit_prenotazione_utente" value="1">
                                        <div class="modal-header py-3 text-white" style="background-color: <?php echo $col_p; ?>; border-radius: 12px 12px 0 0;">
                                            <h6 class="modal-title fw-bold m-0"><i class="fa fa-edit me-2"></i> Modifica Prenotazione: <?php echo htmlspecialchars($pr['evento_titolo']); ?></h6>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body text-start p-4">
                                            
                                            <div class="mb-4 p-3 border rounded shadow-sm bg-light">
                                                <label class="form-label small fw-bold text-dark"><i class="fa fa-exchange-alt me-1"></i> Modifica Orario / Turno (Opzionale)</label>
                                                <?php if ($bloccata_pr): ?><div class="small text-danger fw-semibold mb-2"><i class="fa fa-lock me-1"></i>Il termine per cambiare turno è scaduto: puoi modificare solo i dati.</div><?php endif; ?>
                                                <select name="nuovo_turno_id" class="form-select border-primary fw-bold" <?php echo $bloccata_pr ? 'disabled' : ''; ?>>
                                                    <?php
                                                    foreach ($pr['turni_alternativi'] as $ta) {
                                                        $sel = ($ta['id'] == $pr['turno_id']) ? 'selected' : '';
                                                        $lbl_ta = htmlspecialchars(etichetta_turno($ta));

                                                        if ($ta['id'] == $pr['turno_id']) {
                                                            echo "<option value='{$ta['id']}' selected>📅 $lbl_ta — [Il tuo turno attuale]</option>";
                                                        } elseif (!$ta['is_closed']) {
                                                            if ($ta['posti_liberi'] >= $pr['num_posti']) {
                                                                echo "<option value='{$ta['id']}'>📅 $lbl_ta — ✅ Disponibile ({$ta['posti_liberi']} posti)</option>";
                                                            } else {
                                                                echo "<option value='{$ta['id']}'>📅 $lbl_ta — ⏳ Esaurito (Finirai in Lista d'Attesa)</option>";
                                                            }
                                                        }
                                                    }
                                                    ?>
                                                </select>
                                            </div>

                                            <div class="row g-3 mb-3">
                                                <div class="col-md-6"><label class="form-label small fw-bold">Nome</label><input type="text" name="nome" class="form-control" value="<?php echo htmlspecialchars($pr['nome']); ?>" required></div>
                                                <div class="col-md-6"><label class="form-label small fw-bold">Cognome</label><input type="text" name="cognome" class="form-control" value="<?php echo htmlspecialchars($pr['cognome']); ?>" required></div>
                                                <div class="col-md-6"><label class="form-label small fw-bold">Email</label><input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($pr['email']); ?>" required></div>
                                                <div class="col-md-6"><label class="form-label small fw-bold">Matricola</label><input type="text" name="matricola" class="form-control" value="<?php echo htmlspecialchars($pr['matricola'] ?? ''); ?>"></div>
                                            </div>
                                            
                                            <?php 
                                                $json_c = json_decode($pr['dati_custom_json'] ?? '', true) ?: [];
                                                $campi_pr = \App\Core\App::get(\App\Iscrizioni\ServizioAreaPersonale::class)->campiModulo((int)$pr['p_id'], (int)$pr['evento_id']);
                                                if ($campi_pr):
                                            ?>
                                                <div class="border-top pt-3 mt-4">
                                                    <h6 class="fw-bold text-primary mb-3"><i class="fa fa-list-check me-1"></i> Le tue risposte aggiuntive:</h6>
                                                    <div class="row g-3">
                                                    <?php foreach ($campi_pr as $cf):
                                                        if (!campo_form_visibile($cf, ($pr['evento_tipo'] ?? '') === 'progetto', ['per_scuole' => $pr['per_scuole'] ?? 1, 'attestati' => $pr['attestati'] ?? 0])) continue;
                                                        if ($cf['nome_campo'] === CAMPO_PARTECIPANTI) $cf['etichetta'] = 'Numero di studenti partecipanti'; ?>
                                                        <?php 
                                                            $input_name = htmlspecialchars($cf['nome_campo']);
                                                            $val_c = $json_c[$input_name] ?? '';
                                                        ?>
                                                        <div class="col-md-6">
                                                            <label class="form-label small fw-bold mb-1"><?php echo htmlspecialchars($cf['etichetta']); ?></label>
                                                            <?php if (strpos($val_c, 'uploads/allegati_prenotazioni/') !== false): ?>
                                                                <div class="p-2 border rounded bg-light text-muted small">
                                                                    <i class="fa fa-file-pdf text-danger me-1"></i> File allegato. Impossibile modificarlo da qui. Se occorre cambiarlo, contatta la segreteria.
                                                                </div>
                                                            <?php elseif ($cf['tipo_campo'] === 'corso_studio'): ?>
                                                                <?php echo html_campo_corso($conn, $cf['nome_campo'], (string)$val_c, '', 'form-select'); ?>
                                                            <?php elseif ($cf['tipo_campo'] === 'scuola'): ?>
                                                                <?php echo html_campo_scuola($cf['nome_campo'], (string)$val_c, (string)($pr['scuola_codice'] ?? ''), '', 'form-control'); ?>
                                                            <?php else: ?>
                                                                <input type="text" name="custom_<?php echo $input_name; ?>" class="form-control" value="<?php echo htmlspecialchars($val_c); ?>">
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="modal-footer py-2 bg-light border-top-0" style="border-radius: 0 0 12px 12px;">
                                            <button type="button" class="btn btn-secondary fw-bold px-4" data-bs-dismiss="modal">Chiudi</button>
                                            <button type="submit" class="btn btn-primary fw-bold px-4 shadow-sm" style="background-color: <?php echo $col_p; ?>; border:none;" onclick="return confirm('Sei sicuro di voler salvare queste modifiche?');"><i class="fa fa-save me-1"></i> Salva Modifiche</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- TAB 2: STORICO E ATTESTATI -->
        <div class="tab-pane fade" id="pills-passate" role="tabpanel" tabindex="0">
            <?php if (empty($prenotazioni_passate)): ?>
                <div class="alert alert-light text-center border p-5 shadow-sm rounded-4 text-muted">
                    <i class="fa fa-history fs-1 d-block mb-3 opacity-50"></i>
                    <h5 class="fw-bold">Nessun evento passato</h5>
                    <p class="m-0">Il tuo storico delle attività è vuoto.</p>
                </div>
            <?php else: ?>
                <div class="d-flex flex-column gap-3">
                    <?php foreach ($prenotazioni_passate as $pr): ?>
                        <?php
                            $st      = empty($pr['stato']) ? 'confermata' : $pr['stato'];
                            $presente = (int)($pr['presente'] ?? 0);
                            $col_p   = $presente === 1 ? '#198754' : '#6c757d';
                            $unread  = 0;
                            if (isset($messaggi_per_pr[$pr['id']])) {
                                foreach ($messaggi_per_pr[$pr['id']] as $m) {
                                    if ($m['mittente_tipo'] === 'admin' && $m['letto'] == 0) $unread++;
                                }
                            }
                        ?>
                        <div class="card card-evento-passato shadow-sm" style="border-left-color:<?php echo $col_p; ?>;">
                            <div class="card-body p-3 p-md-4">
                                <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-2">
                                    <div>
                                        <h5 class="fw-bold text-dark m-0 mb-1"><?php echo htmlspecialchars($pr['evento_titolo']); ?></h5>
                                        <div class="small text-muted fw-bold text-uppercase"><i class="fa fa-layer-group me-1"></i><?php echo htmlspecialchars($pr['pagina_titolo']); ?></div>
                                    </div>
                                    <?php if ($presente === 1): ?>
                                        <div class="presenza-grande bg-success bg-opacity-10 text-success">
                                            <i class="fa fa-user-check"></i> Presenza registrata
                                        </div>
                                    <?php else: ?>
                                        <div class="presenza-grande bg-secondary bg-opacity-10 text-secondary">
                                            <i class="fa fa-user-times"></i> Assente
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="d-flex flex-wrap gap-3 small fw-semibold text-secondary mb-3">
                                    <span><i class="fa fa-calendar-day me-1"></i><?php echo htmlspecialchars(etichetta_turno($pr)); ?></span>
                                    <span class="font-monospace"><i class="fa fa-hashtag me-1"></i><?php echo $pr['codice_prenotazione']; ?></span>
                                </div>

                                <div class="azioni-desktop">
                                    <button type="button" class="btn btn-outline-secondary btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#modChatStudente<?php echo $pr['id']; ?>">
                                        <i class="fa fa-comments me-1"></i>Assistenza<?php if ($unread > 0): ?> <span class="badge bg-danger"><?php echo $unread; ?></span><?php endif; ?>
                                    </button>
                                    <?php if ($st !== 'rifiutata'): ?>
                                    <a href="stampa_ricevuta.php?code=<?php echo urlencode($pr['codice_prenotazione']); ?>" target="_blank" class="btn btn-outline-dark btn-sm fw-bold">
                                        <i class="fa fa-file-pdf me-1"></i>Ricevuta
                                    </a>
                                    <?php endif; ?>
                                        <?php echo pulsanti_attestato_pr($conn, $pr); ?>
                                </div>
                            </div>
                        </div>

                        <!-- MODALE CHAT STUDENTE -->
                        <div class="modal fade" id="modChatStudente<?php echo $pr['id']; ?>" tabindex="-1">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content shadow-lg border-0">
                                    <div class="modal-header py-3 bg-secondary text-white">
                                        <h6 class="modal-title fw-bold"><i class="fa fa-comments me-2"></i> Assistenza Segreteria - <?php echo htmlspecialchars($pr['evento_titolo']); ?></h6>
                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body p-0 bg-light text-start">
                                        <div class="p-4" style="max-height: 400px; overflow-y: auto; background: #f8fafc;">
                                            <?php 
                                            $chat_msgs = $messaggi_per_pr[$pr['id']] ?? [];
                                            if(empty($chat_msgs)): 
                                            ?>
                                                <div class="text-center text-muted my-4 small">
                                                    <i class="fa fa-comment-slash fs-3 mb-2 opacity-50 d-block"></i>
                                                    Hai bisogno di informazioni su questo evento? Invia un messaggio alla segreteria.
                                                </div>
                                            <?php else: ?>
                                                <?php foreach($chat_msgs as $msg): ?>
                                                    <?php if($msg['mittente_tipo'] === 'utente'): ?>
                                                        <div class="d-flex justify-content-end mb-3">
                                                            <div style="max-width: 80%;">
                                                                <div class="small text-muted text-end mb-1" style="font-size: 0.7rem;">Tu - <?php echo date('d/m/Y H:i', strtotime($msg['data_invio'])); ?></div>
                                                                <div class="p-2 rounded-3 text-dark shadow-sm bg-white border" style="border-bottom-right-radius: 0 !important;">
                                                                    <?php echo nl2br(htmlspecialchars($msg['messaggio'])); ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="d-flex justify-content-start mb-3">
                                                            <div style="max-width: 80%;">
                                                                <div class="small text-muted mb-1" style="font-size: 0.7rem;">Segreteria - <?php echo date('d/m/Y H:i', strtotime($msg['data_invio'])); ?></div>
                                                                <?php $col_bolla = colore_valido($pr['colore_primario'] ?? '', '#B80000'); ?><div class="p-2 rounded-3 shadow-sm" style="background-color: <?php echo $col_bolla; ?>; color: <?php echo colore_testo_su($col_bolla); ?>; border-bottom-left-radius: 0 !important;">
                                                                    <?php echo strip_tags($msg['messaggio'], '<b><strong><i><em><u><br><p><ul><ol><li><span>'); ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <form method="POST" class="border-top p-3 bg-white">
                                            <?php csrf_field(); ?>
                                            <input type="hidden" name="prenotazione_id" value="<?php echo $pr['id']; ?>">
                                            <label class="form-label small fw-bold text-primary">Invia un messaggio</label>
                                            <textarea name="corpo_messaggio" class="form-control" rows="3" placeholder="Scrivi qui la tua richiesta..." required></textarea>
                                            <div class="text-end mt-3">
                                                <button type="submit" name="invia_messaggio_utente" class="btn btn-primary fw-bold px-4 shadow-sm"><i class="fa fa-paper-plane me-1"></i> Invia</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>

                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- TAB 3: SONDAGGI (AGGIORNATO AL MOTORE INTERNO) -->
        <div class="tab-pane fade" id="pills-sondaggi" role="tabpanel" tabindex="0">
            <?php 

                // Questionari da compilare (eventi conclusi con presenza): src/Iscrizioni/ServizioAreaPersonale
                $sond_dispo = \App\Core\App::get(\App\Iscrizioni\ServizioAreaPersonale::class)->sondaggiDisponibili($prenotazioni_passate);
                $sondaggi_disponibili = $sond_dispo['disponibili'];
                $eventi_con_sondaggio = $sond_dispo['eventi'];
            ?>

            <?php if ($sondaggi_disponibili === 0): ?>
                <div class="alert alert-light text-center border p-5 shadow-sm rounded-4 text-muted">
                    <i class="fa fa-poll-h fs-1 d-block mb-3 opacity-50"></i>
                    <h5 class="fw-bold">Nessun sondaggio attivo</h5>
                    <p class="m-0">Non ci sono questionari da compilare al momento per gli eventi a cui hai partecipato (oppure li hai già completati tutti).</p>
                </div>
            <?php else: ?>
                <div class="d-flex flex-column gap-3">
                    <div class="alert alert-info border-0 border-start border-5 border-info shadow-sm mb-2">
                        <i class="fa fa-info-circle me-2"></i> La tua opinione è importante! Compila i sondaggi di gradimento per gli eventi a cui hai partecipato.
                    </div>
                    <?php foreach ($eventi_con_sondaggio as $sondaggio): ?>
                        <div class="card shadow-sm border-0 overflow-hidden" style="border-radius: 12px; border-left: 6px solid #0dcaf0 !important;">
                            <div class="card-body p-4 d-flex flex-column flex-md-row justify-content-between align-items-center gap-3">
                                <div>
                                    <h5 class="fw-bold text-dark m-0 mb-1"><?php echo htmlspecialchars($sondaggio['titolo']); ?></h5>
                                    <?php if (!empty($sondaggio['data'])): ?><span class="text-muted small fw-bold"><i class="fa fa-calendar-day me-1"></i> Evento del: <?php echo date('d/m/Y', strtotime($sondaggio['data'])); ?></span><?php endif; ?>
                                </div>
                                <div>
                                    <a href="<?php echo htmlspecialchars($sondaggio['link']); ?>" target="_blank" class="btn btn-info text-white fw-bold shadow-sm px-4 rounded-pill">
                                        <i class="fa fa-edit me-1"></i> Compila il Sondaggio
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- TAB: PROGRAMMA FSL (attività da prenotare, Allegato A e Convenzione) -->
        <?php if ($fsl_documenti || $fsl_programma): ?>
        <div class="tab-pane fade" id="pills-fsl" role="tabpanel" tabindex="0">
            <?php if ($fsl_programma): ?>
                <div class="card border-0 shadow-sm rounded-4 mb-4" style="border-left:5px solid #B30000 !important;"><div class="card-body">
                    <h2 class="h5 fw-bold"><i class="fa fa-clipboard-list me-1" style="color:#B30000;" aria-hidden="true"></i>Il programma in preparazione (<?php echo (int)$fsl_programma; ?>)</h2>
                    <p class="small text-secondary mb-2">Attività scelte e <strong>non ancora prenotate</strong>: i posti si assegnano alla conferma.</p>
                    <ul class="mb-3"><?php foreach ($fsl_in_programma as $v): ?><li><strong><?php echo htmlspecialchars($v['titolo']); ?></strong><?php echo $v['turno'] !== '' ? ' – ' . htmlspecialchars($v['turno']) : ''; ?><?php echo $v['nota'] !== '' ? ' <span class="text-danger small">(' . htmlspecialchars($v['nota']) . ')</span>' : ''; ?></li><?php endforeach; ?></ul>
                    <a href="programma_fsl.php" class="btn fw-bold text-white" style="background:#B30000;"><i class="fa fa-arrow-right me-1" aria-hidden="true"></i>Apri il programma e prenota</a>
                </div></div>
            <?php endif; ?>

            <?php if (!$fsl_documenti): ?>
                <div class="alert alert-light border text-center text-muted p-4 rounded-4"><i class="fa fa-file-signature fs-3 d-block mb-2 opacity-50"></i>Quando prenoterai le attività dal programma, qui troverai sempre l'<strong>Allegato A</strong> (PDF) e la <strong>Convenzione</strong> (Word) già compilati.</div>
            <?php endif; ?>
            <?php
            $stati_fsl = ['confermata' => ['Confermata', 'success'], 'da_approvare' => ['In attesa', 'info'], 'in_attesa' => ["Lista d'attesa", 'warning'], 'richiesta_conferma' => ['Posto disponibile', 'warning'],
                          'annullata' => ['Annullata', 'secondary'], 'rifiutata' => ['Rifiutata', 'secondary'], 'scaduta' => ['Scaduta', 'secondary']];
            foreach ($fsl_documenti as $d_fsl): $tk_fsl = htmlspecialchars($d_fsl['token']); ?>
                <div class="card border-0 shadow-sm rounded-4 mb-3"><div class="card-body">
                    <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                        <h2 class="h5 fw-bold mb-0"><i class="fa fa-school me-1 text-secondary" aria-hidden="true"></i><?php echo htmlspecialchars($d_fsl['scuola']); ?></h2>
                        <?php if ($d_fsl['protocollo'] !== ''): ?><span class="badge bg-light text-dark border">Prot. <?php echo htmlspecialchars($d_fsl['protocollo']); ?></span><?php endif; ?>
                    </div>
                    <ul class="list-unstyled small mb-3">
                        <?php foreach ($d_fsl['attivita'] as $a_fsl): [$et_fsl, $col_fsl] = $stati_fsl[$a_fsl['stato']] ?? [$a_fsl['stato'], 'secondary']; ?>
                            <li class="mb-1"><span class="badge bg-<?php echo $col_fsl; ?><?php echo $col_fsl === 'warning' ? ' text-dark' : ''; ?> me-1"><?php echo htmlspecialchars($et_fsl); ?></span>
                                <strong><?php echo htmlspecialchars($a_fsl['titolo']); ?></strong><?php echo $a_fsl['turno'] !== '' ? ' – ' . htmlspecialchars($a_fsl['turno']) : ''; ?>
                                <span class="font-monospace text-secondary ms-1"><?php echo htmlspecialchars($a_fsl['codice']); ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="d-flex flex-wrap gap-2">
                        <a class="btn btn-success btn-sm fw-bold" href="convenzione_online.php?t=<?php echo $tk_fsl; ?>&amp;scarica=allegato_pdf"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>Allegato A (PDF)</a>
                        <a class="btn <?php echo $d_fsl['convenzione'] === 'si' ? 'btn-outline-success' : 'btn-success'; ?> btn-sm fw-bold" href="convenzione_online.php?t=<?php echo $tk_fsl; ?>&amp;scarica=convenzione_sola"><i class="fa fa-file-word me-1" aria-hidden="true"></i>Convenzione (Word)</a>
                        <a class="btn btn-outline-secondary btn-sm fw-bold" href="convenzione_online.php?t=<?php echo $tk_fsl; ?>"><i class="fa fa-pen me-1" aria-hidden="true"></i>Correggi i dati, logo e attività</a>
                    </div>
                    <?php if ($d_fsl['convenzione'] === 'si'): ?><p class="small text-secondary mt-2 mb-0">La scuola ha già la convenzione con il Dipartimento: serve solo l'Allegato A.</p>
                    <?php else: ?><p class="small text-secondary mt-2 mb-0">Dopo la firma digitale PAdES del Dirigente, la scuola invia i documenti via PEC al Dipartimento.</p><?php endif; ?>
                </div></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- TAB 4: PROFILO -->
        <div class="tab-pane fade" id="pills-profilo" role="tabpanel" tabindex="0">
            <?php $ruoli_label = [1=>'Amministratore',2=>'Gestore',3=>'Studente',4=>'Dipendente',5=>'Esterno']; ?>

            <!-- Dati anagrafici -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header fw-bold"><i class="fa fa-id-card me-2 text-secondary"></i>Dati personali</div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4 text-muted">Nome e cognome</dt>
                        <dd class="col-sm-8"><?php echo htmlspecialchars(trim(($user_info['nome'] ?? '') . ' ' . ($user_info['cognome'] ?? ''))); ?></dd>

                        <dt class="col-sm-4 text-muted">Codice fiscale</dt>
                        <dd class="col-sm-8 font-monospace"><?php echo htmlspecialchars($user_info['codice_fiscale'] ?? '—'); ?></dd>

                        <dt class="col-sm-4 text-muted">Ruolo</dt>
                        <dd class="col-sm-8">
                            <?php
                            $label_ruolo = $ruoli_label[$u_ruolo] ?? 'Sconosciuto';
                            $badge_color = ['Amministratore'=>'danger','Gestore'=>'warning text-dark','Studente'=>'primary','Dipendente'=>'success','Esterno'=>'secondary'];
                            $bc = $badge_color[$label_ruolo] ?? 'secondary';
                            echo '<span class="badge bg-' . $bc . '">' . htmlspecialchars($label_ruolo) . '</span>';
                            if (!empty($user_info['ruoli_secondari'])) {
                                foreach (explode(',', $user_info['ruoli_secondari']) as $rs) {
                                    $rs = trim($rs);
                                    if ($rs !== '' && isset($ruoli_label[(int)$rs])) {
                                        $lrs = $ruoli_label[(int)$rs];
                                        $brs = $badge_color[$lrs] ?? 'secondary';
                                        echo ' <span class="badge bg-' . $brs . ' opacity-75">' . htmlspecialchars($lrs) . '</span>';
                                    }
                                }
                            }
                            ?>
                        </dd>

                        <?php if (!empty($user_info['matricola_studente'])): ?>
                        <dt class="col-sm-4 text-muted">Matricola studente</dt>
                        <dd class="col-sm-8 font-monospace"><?php echo htmlspecialchars($user_info['matricola_studente']); ?></dd>
                        <?php endif; ?>

                        <?php if (!empty($user_info['matricola_dipendente'])): ?>
                        <dt class="col-sm-4 text-muted">Matricola dipendente</dt>
                        <dd class="col-sm-8 font-monospace"><?php echo htmlspecialchars($user_info['matricola_dipendente']); ?></dd>
                        <?php endif; ?>
                    </dl>
                </div>
            </div>

            <?php $pers_sc = !empty($user_info['persona_id']) ? persona_ateneo($conn, $user_info['persona_id']) : null;
            if ($pers_sc):
                $det_sc = dettaglio_persona($conn, $pers_sc);   // aggiornata dal portale al massimo una volta a settimana
                $sc = scheda_persona($conn, $pers_sc, $det_sc);
                $voci_sc = []; foreach ([GRUPPI_PERSONALE[$pers_sc['gruppo']] ?? '', $pers_sc['ruolo']] as $v_sc) { $v_sc = trim((string)$v_sc); if ($v_sc !== '') $voci_sc[mb_strtolower($v_sc)] = $v_sc; } ?>
            <!-- Scheda di Ateneo: dati dalle API del portale, alcuni modificabili -->
            <div class="card shadow-sm border-0 mb-4" id="scheda-ateneo">
                <div class="card-header fw-bold d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <span><i class="fa fa-address-book me-2 text-secondary" aria-hidden="true"></i>Scheda di Ateneo</span>
                    <a href="<?php echo htmlspecialchars(url_portale_persona($pers_sc)); ?>" target="_blank" rel="noopener" class="small fw-normal">Pagina sul portale di Ateneo<span class="visually-hidden"> (si apre in una nuova scheda)</span> <i class="fa fa-arrow-up-right-from-square" aria-hidden="true"></i></a>
                </div>
                <div class="card-body">
                    <div class="d-flex gap-3 align-items-center mb-3 flex-wrap">
                        <?php echo html_avatar_persona($det_sc['foto'] ?? '', 'La tua foto sul portale di Ateneo', 72); ?>
                        <dl class="row mb-0 flex-grow-1 small">
                            <dt class="col-sm-4 text-muted">Ruolo</dt><dd class="col-sm-8"><?php echo htmlspecialchars(implode(' · ', $voci_sc) ?: '—'); ?></dd>
                            <dt class="col-sm-4 text-muted">Struttura</dt><dd class="col-sm-8"><?php echo htmlspecialchars($pers_sc['struttura'] ?: '—'); ?></dd>
                            <?php if ($pers_sc['ssd'] !== ''): ?><dt class="col-sm-4 text-muted">Settore</dt><dd class="col-sm-8"><?php echo htmlspecialchars($pers_sc['ssd_cod'] . ' – ' . $pers_sc['ssd']); ?></dd><?php endif; ?>
                            <dt class="col-sm-4 text-muted">Email di Ateneo</dt><dd class="col-sm-8"><?php echo htmlspecialchars($pers_sc['email'] ?: '—'); ?></dd>
                        </dl>
                    </div>
                    <p class="text-muted small mb-3"><i class="fa fa-info-circle me-1" aria-hidden="true"></i>Ruolo, struttura, foto ed email arrivano dal portale dell'Università e si aggiornano ogni settimana: per cambiarli rivolgiti agli uffici di Ateneo. I campi qui sotto puoi correggerli tu: valgono sul portale degli eventi (pagina pubblica di referente e moduli) e l'aggiornamento settimanale non li sovrascrive. Lascia un campo vuoto per usare il dato del portale di Ateneo.</p>
                    <form method="POST">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="aggiorna_scheda_ateneo" value="1">
                        <div class="row g-3">
                            <?php foreach (CAMPI_SCHEDA_PERSONA as $k_sc => $lbl_sc):
                                $mod_sc = in_array($k_sc, $sc['modificati'], true);
                                $val_sc = $mod_sc ? $sc['valori'][$k_sc] : '';
                                $port_sc = $sc['portale'][$k_sc];
                                $lungo = in_array($k_sc, ['ricevimento', 'bio'], true); ?>
                                <div class="<?php echo $lungo ? 'col-12' : 'col-md-4'; ?>">
                                    <label class="form-label small fw-bold mb-1" for="sc_<?php echo $k_sc; ?>"><?php echo $lbl_sc; ?><?php if ($mod_sc): ?> <span class="badge bg-info text-dark fw-normal">modificato da te</span><?php endif; ?></label>
                                    <?php if ($lungo): ?>
                                        <textarea class="form-control form-control-sm" name="<?php echo $k_sc; ?>" id="sc_<?php echo $k_sc; ?>" rows="<?php echo $k_sc === 'bio' ? 5 : 3; ?>" maxlength="<?php echo $k_sc === 'bio' ? 3000 : 1000; ?>" placeholder="<?php echo htmlspecialchars($port_sc !== '' ? mb_strimwidth(str_replace("\n", ' ', $port_sc), 0, 160, '…') : 'Nessun dato sul portale di Ateneo'); ?>"><?php echo htmlspecialchars($val_sc); ?></textarea>
                                    <?php else: ?>
                                        <input type="<?php echo $k_sc === 'sito' ? 'url' : ($k_sc === 'telefono' ? 'tel' : 'text'); ?>" class="form-control form-control-sm" name="<?php echo $k_sc; ?>" id="sc_<?php echo $k_sc; ?>" value="<?php echo htmlspecialchars($val_sc); ?>" maxlength="<?php echo $k_sc === 'telefono' ? 60 : 255; ?>" placeholder="<?php echo htmlspecialchars($port_sc !== '' ? $port_sc : ($k_sc === 'sito' ? 'https://…' : 'Nessun dato sul portale')); ?>">
                                    <?php endif; ?>
                                    <div class="form-text">Dal portale di Ateneo: <?php echo $port_sc !== '' ? htmlspecialchars(mb_strimwidth(str_replace("\n", ' ', $port_sc), 0, 120, '…')) : '<em>nessun dato</em>'; ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="d-flex gap-2 flex-wrap mt-3">
                            <button type="submit" class="btn btn-primary btn-sm fw-bold"><i class="fa fa-save me-1" aria-hidden="true"></i>Salva la scheda</button>
                            <?php if ($sc['modificati']): ?><button type="submit" name="ripristina" value="1" class="btn btn-outline-secondary btn-sm fw-bold" data-confirm="Tornare ai dati del portale di Ateneo per tutti i campi?"><i class="fa fa-rotate-left me-1" aria-hidden="true"></i>Ripristina i dati del portale</button><?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- Email -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header fw-bold"><i class="fa fa-envelope me-2 text-secondary"></i>Indirizzo email</div>
                <div class="card-body">
                    <p class="mb-3">Email attuale:
                        <?php if (!empty($user_info['email'])): ?>
                            <strong><?php echo htmlspecialchars($user_info['email']); ?></strong>
                        <?php else: ?>
                            <span class="text-muted fst-italic">non impostata</span>
                        <?php endif; ?>
                    </p>
                    <p class="text-muted small mb-3">
                        <i class="fa fa-info-circle me-1"></i>
                        Questa email viene usata per le notifiche di conferma, promemoria e comunicazioni relative alle tue prenotazioni.
                        L'email fornita dall'Università al momento del login viene salvata automaticamente; puoi sovrascriverla qui se preferisci usarne un'altra.
                    </p>
                    <form method="POST" novalidate>
                        <?php csrf_field(); ?>
                        <input type="hidden" name="aggiorna_email" value="1">
                        <div class="input-group">
                            <input type="email" name="nuova_email" class="form-control"
                                   placeholder="nuova@email.it"
                                   value="<?php echo htmlspecialchars($user_info['email'] ?? ''); ?>"
                                   required aria-label="Nuovo indirizzo email">
                            <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i> Salva</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- MODALE GLOBALE CONFERMA ANNULLAMENTO -->
<div class="modal fade" id="modConfermaAnnulla" tabindex="-1" aria-labelledby="modAnnullaLabel">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-danger shadow-lg" style="border-radius:14px;">
            <div class="modal-header bg-danger text-white py-2" style="border-radius:14px 14px 0 0;">
                <h6 class="modal-title fw-bold" id="modAnnullaLabel"><i class="fa fa-exclamation-triangle me-2"></i>Conferma annullamento</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center py-3">
                <p class="mb-1 fw-bold" id="modAnnullaEv"></p>
                <p class="text-muted small mb-0">Perderai il posto e verrà inviata notifica ai gestori. Operazione irreversibile.</p>
            </div>
            <div class="modal-footer py-2 justify-content-center gap-2">
                <button class="btn btn-secondary btn-sm fw-bold px-4" data-bs-dismiss="modal">No, torna</button>
                <a id="modAnnullaBtn" href="#" class="btn btn-danger btn-sm fw-bold px-4"><i class="fa fa-times me-1"></i>Sì, annulla</a>
            </div>
        </div>
    </div>
</div>

<script>
function apriFinestraAnnulla(btn) {
    document.getElementById('modAnnullaEv').textContent = btn.dataset.titolo;
    document.getElementById('modAnnullaBtn').href = btn.dataset.href;
    new bootstrap.Modal(document.getElementById('modConfermaAnnulla')).show();
}
// Auto-scroll alla tab storico se si ritorna dopo un'azione
(function(){
    var hash = window.location.hash;
    var tabMap = { '#storico': 'pills-passate-tab', '#sondaggi': 'pills-sondaggi-tab', '#profilo': 'pills-profilo-tab', '#programma-fsl': 'pills-fsl-tab' };
    if (tabMap[hash]) {
        var t = document.getElementById(tabMap[hash]);
        if (t) { bootstrap.Tab.getOrCreateInstance(t).show(); }
    }
})();
</script>

<?php require_once 'footer.php'; ?>
