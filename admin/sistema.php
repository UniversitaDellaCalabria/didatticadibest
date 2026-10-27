<?php
// sistema.php - Configurazione Sistema Email, SMTP e Promemoria
require_once 'admin_header.php';

// Controllo Permessi RBAC (Solo Full Admin)
if (!$is_full_admin) {
    echo "<div class='alert alert-danger fw-bold shadow-sm m-4'><i class='fa fa-ban me-2'></i> Accesso negato. Questa sezione è riservata agli amministratori globali del sistema.</div>";
    require_once 'admin_footer.php';
    exit;
}

function admin_redirect($url) {
    echo "<script>window.location.replace('$url');</script>";
    exit;
}

// ==============================================================================
// BLOCCO ELABORAZIONE AZIONI BACKEND (GET / POST)
// ==============================================================================

// 1. ESECUZIONE MANUALE PROMEMORIA (CRON)
if (isset($_POST['run_reminders_manual'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    if (function_exists('registra_log_audit')) registra_log_audit($conn, "Esecuzione Manuale Promemoria Email", ["Finestra_Ore" => 72]);
    admin_redirect("cron_reminders.php?manual=1");
}

// 1b. EMAIL DI PROVA: verifica la configurazione SMTP mostrando l'errore esatto del server
if (isset($_POST['invia_email_test'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $dest_test = trim($_POST['email_test'] ?? '');
    $pr_test = \App\Core\App::get(\App\Email\InvioEmail::class)->prepara('sistema_email_prova', ['data_ora' => date('d/m/Y H:i')]);
    $ok_test = inviaNotificaEmail($dest_test, $pr_test['oggetto'], $pr_test['corpo'], $conn, $pr_test['colore']);
    if ($ok_test) flash_set("Email di prova accettata dal server per " . $dest_test . ". Se non arriva entro qualche minuto controlla lo spam.");
    else flash_set("Invio fallito: " . ($GLOBALS['ultimo_errore_email'] ?: 'errore sconosciuto'), 'danger');
    admin_redirect("sistema.php?p_id=$filtro_p&r=" . time() . "#log-email");
}

// 1c. RIEPILOGO SETTIMANALE DELLE EMAIL: invio immediato agli amministratori
if (isset($_POST['invia_report_email'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $esito_rep = invia_report_email_settimanale($conn, true);
    if ($esito_rep === true) flash_set("Riepilogo inviato agli amministratori: " . implode(', ', email_amministratori($conn)) . ".");
    else flash_set("Riepilogo non inviato: " . $esito_rep, 'danger');
    admin_redirect("sistema.php?p_id=$filtro_p&r=" . time() . "#log-email");
}

// 1d. CONTROLLO DEL SITO: esecuzione immediata (senza email: i risultati si vedono qui)
if (isset($_POST['controlla_sito'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $st_c = esegui_controllo_sito($conn, false);
    flash_set($st_c['problemi'] ? "Controllo completato: " . $st_c['problemi'] . " problemi, vedi sotto." : "Controllo completato: tutto a posto.", $st_c['problemi'] ? 'warning' : 'success');
    admin_redirect("sistema.php?p_id=$filtro_p&r=" . time() . "#controllo");
}

// 1f. PROMEMORIA AL DIRETTORE E AGLI UFFICI: si sospendono e si riattivano (alla riattivazione il cron rimanda quanto è dovuto)
if (isset($_POST['sospendi_promemoria'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $sospendi_pr = $_POST['sospendi_promemoria'] === '1';
    \App\Core\App::per($conn)->get(\App\Portale\SospensionePromemoria::class)->imposta($sospendi_pr, trim((string)($utente_admin['nome'] ?? '') . ' ' . (string)($utente_admin['cognome'] ?? '')) ?: (string)($utente_admin['email'] ?? ''));
    if (function_exists('registra_log_audit')) registra_log_audit($conn, $sospendi_pr ? "Promemoria al Direttore e agli uffici sospesi" : "Promemoria al Direttore e agli uffici riattivati", []);
    flash_set($sospendi_pr ? "Promemoria sospesi: il Direttore e gli uffici non ricevono più il riepilogo settimanale né i promemoria delle pratiche e delle firme." : "Promemoria riattivati: al prossimo passaggio del sistema partono i promemoria ancora dovuti.", $sospendi_pr ? 'warning' : 'success');
    admin_redirect("sistema.php?p_id=$filtro_p&r=" . time() . "#promemoria");
}

// 1g. RIEPILOGO SETTIMANALE CON L'AGENDA: lo accende l'amministratore (spento di partenza)
if (isset($_POST['riepilogo_agenda'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $acceso_ra = $_POST['riepilogo_agenda'] === '1';
    \App\Core\App::per($conn)->get(\App\Portale\RiepilogoAgenda::class)->imposta($acceso_ra, trim((string)($utente_admin['nome'] ?? '') . ' ' . (string)($utente_admin['cognome'] ?? '')) ?: (string)($utente_admin['email'] ?? ''));
    if (function_exists('registra_log_audit')) registra_log_audit($conn, $acceso_ra ? "Riepilogo settimanale con l'agenda acceso" : "Riepilogo settimanale con l'agenda spento", []);
    flash_set($acceso_ra ? "Riepilogo con l'agenda acceso: dal prossimo passaggio del sistema chi ha qualcosa in agenda nei sette giorni a venire riceve l'email settimanale." : "Riepilogo con l'agenda spento: l'email settimanale torna quella di sempre, solo per chi ha cose da fare.", $acceso_ra ? 'success' : 'warning');
    admin_redirect("sistema.php?p_id=$filtro_p&r=" . time() . "#riepilogo-agenda");
}

// 2. SALVATAGGIO CONFIGURAZIONI SMTP E TEMPLATE
if (isset($_POST['save_system_settings'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $modelli = [];
    foreach (\App\Sistema\ImpostazioniSistemaRepository::CAMPI_TESTO as $campo_t) if (isset($_POST[$campo_t])) $modelli[$campo_t] = (string)$_POST[$campo_t]; // i testi delle email non sono più in questa pagina
    $from_e = $conn->real_escape_string($_POST['smtp_from_email'] ?? '');
    \App\Core\App::get(\App\Sistema\ImpostazioniSistemaRepository::class)->salva(
        (string)($_POST['smtp_host'] ?? ''), (int)($_POST['smtp_port'] ?? 587), (string)($_POST['smtp_username'] ?? ''),
        (string)($_POST['smtp_password'] ?? ''), (string)($_POST['smtp_secure'] ?? 'tls'), (string)($_POST['smtp_from_email'] ?? ''),
        (string)($_POST['smtp_from_name'] ?? ''), $modelli);

    if (function_exists('registra_log_audit')) registra_log_audit($conn, "Aggiornamento Impostazioni Sistema/SMTP e Template", ["Mittente" => $from_e]);
    
    flash_set("Impostazioni SMTP salvate con successo!");
    admin_redirect("sistema.php?p_id=$filtro_p");
}
?>

<!-- FRONT-END DELLA PAGINA -->
<?php schede_sistema('sistema.php'); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div><h1 class="pn-titolo"><i class="fa fa-envelope me-2" aria-hidden="true"></i>Email e promemoria</h1><p class="pn-aiuto">Le email che il portale invia da solo, i backup e i controlli notturni.</p></div>
    
    <form method="POST" action="" class="m-0 text-end">
        <?php csrf_field(); ?>
        <button type="submit" name="run_reminders_manual" class="btn btn-warning fw-bold shadow-sm text-dark" data-confirm="Vuoi inviare ora i promemoria a tutti gli utenti con eventi programmati nelle prossime 72 ore? L\'operazione potrebbe richiedere alcuni secondi.">
            <i class="fa fa-paper-plane me-1"></i> Invia Promemoria Ora (Eventi prossime 72h)
        </button>
    </form>
</div>

<?php $dir_sis = \App\Core\App::per($conn)->get(\App\Fsl\ServizioFirmaDirettore::class)->direttore(); ?>
<div class="card shadow-sm border-0 p-4 mb-4" id="direttore"<?php echo $dir_sis['email'] === '' ? ' style="border-left:6px solid #dc2626 !important;"' : ''; ?>>
    <h5 class="fw-bold text-primary border-bottom pb-2 mb-3"><i class="fa fa-user-tie me-1"></i> Direttore del Dipartimento<?php echo $dir_sis['email'] === '' ? ' <span class="badge bg-danger">da indicare</span>' : ''; ?></h5>
    <p class="small text-secondary mb-2">Il Direttore e il vice direttore si scelgono dall'anagrafe di Ateneo nella <strong>Direzione del Dipartimento</strong>, in Gestione del portale → Uffici: valgono per tutto il portale (convenzioni FSL, firme nelle pratiche degli studenti, Cruscotto del Direttore, riepilogo settimanale).</p>
    <p class="mb-2"><?php echo $dir_sis['email'] === '' ? '<strong class="text-danger">Nessun Direttore indicato.</strong>' : 'Firma ora: <strong>' . htmlspecialchars($dir_sis['nome']) . '</strong> (' . htmlspecialchars($dir_sis['email']) . ').'; ?></p>
    <a class="btn btn-sm btn-primary fw-bold align-self-start" href="uffici.php?u=direzione"><i class="fa fa-sitemap me-1"></i>Apri la Direzione</a>
</div>

<?php $sosp_pr = \App\Core\App::per($conn)->get(\App\Portale\SospensionePromemoria::class)->stato(); ?>
<div class="card shadow-sm border-0 p-4 mb-4" id="promemoria" style="border-left:6px solid <?php echo $sosp_pr['attiva'] ? '#f59e0b' : '#15803d'; ?> !important;">
    <h5 class="fw-bold text-primary border-bottom pb-2 mb-3"><i class="fa fa-bell-slash me-1"></i> Promemoria al Direttore e agli uffici <?php echo $sosp_pr['attiva'] ? '<span class="badge bg-warning text-dark">sospesi</span>' : '<span class="badge bg-success">attivi</span>'; ?></h5>
    <p class="small text-secondary mb-2">Il portale manda al Direttore, al vice e alle persone degli uffici il <strong>riepilogo settimanale «Da fare per te»</strong>, i <strong>promemoria delle pratiche ferme</strong> e i <strong>solleciti di firma al Direttore</strong>. Per il periodo di avvio puoi sospenderli: <strong>non cambia altro</strong> (le email di una richiesta, come il link di firma, partono sempre; docenti, studenti e scuole ricevono i loro promemoria). Quando li <strong>riattivi</strong> il sistema non ha segnato nulla come mandato: al primo passaggio rimanda quello che è ancora dovuto.</p>
    <form method="POST" class="d-flex flex-wrap gap-2 align-items-center"><?php csrf_field(); ?>
        <?php if ($sosp_pr['attiva']): ?>
            <span class="small text-secondary">Sospesi<?php echo $sosp_pr['da'] !== '' ? ' da ' . htmlspecialchars($sosp_pr['da']) : ''; ?><?php echo $sosp_pr['il'] ? ' il ' . htmlspecialchars(date('d/m/Y H:i', strtotime($sosp_pr['il']))) : ''; ?>.</span>
            <button type="submit" name="sospendi_promemoria" value="0" class="btn btn-success fw-bold" data-confirm="Riattivare i promemoria? Al prossimo passaggio partono quelli ancora dovuti."><i class="fa fa-bell me-1"></i>Riattiva i promemoria</button>
        <?php else: ?>
            <button type="submit" name="sospendi_promemoria" value="1" class="btn btn-warning fw-bold" data-confirm="Sospendere i promemoria al Direttore e agli uffici?"><i class="fa fa-bell-slash me-1"></i>Sospendi i promemoria al Direttore e agli uffici</button>
        <?php endif; ?>
    </form>
</div>

<?php $ra_st = \App\Core\App::per($conn)->get(\App\Portale\RiepilogoAgenda::class)->stato(); ?>
<div class="card shadow-sm border-0 p-4 mb-4" id="riepilogo-agenda" style="border-left:6px solid <?php echo $ra_st['attivo'] ? '#15803d' : '#94a3b8'; ?> !important;">
    <h5 class="fw-bold text-primary border-bottom pb-2 mb-3"><i class="fa fa-calendar-week me-1"></i> Riepilogo settimanale con l'agenda <?php echo $ra_st['attivo'] ? '<span class="badge bg-success">acceso</span>' : '<span class="badge bg-secondary">spento</span>'; ?></h5>
    <p class="small text-secondary mb-2">Se lo accendi, l'email settimanale «Da fare per te» porta anche l'<strong>agenda dei sette giorni</strong> (eventi prenotati, aule e laboratori, ricevimenti, scadenze) e parte anche per <strong>chi ha qualcosa in agenda ma nulla da fare</strong>, studenti compresi, con un testo suo («La tua settimana»). Ognuno può disiscriversi dal link in fondo all'email. Da spento (com'è di partenza) l'email resta quella di sempre, solo per chi ha cose da fare. I testi si modificano in <strong>Email</strong>.</p>
    <form method="POST" class="d-flex flex-wrap gap-2 align-items-center"><?php csrf_field(); ?>
        <?php if ($ra_st['attivo']): ?>
            <span class="small text-secondary">Acceso<?php echo $ra_st['da'] !== '' ? ' da ' . htmlspecialchars($ra_st['da']) : ''; ?><?php echo $ra_st['il'] ? ' il ' . htmlspecialchars(date('d/m/Y H:i', strtotime($ra_st['il']))) : ''; ?>.</span>
            <button type="submit" name="riepilogo_agenda" value="0" class="btn btn-outline-secondary fw-bold" data-confirm="Spegnere il riepilogo con l'agenda? L'email settimanale tornerà quella di sempre."><i class="fa fa-toggle-off me-1"></i>Spegni il riepilogo con l'agenda</button>
        <?php else: ?>
            <button type="submit" name="riepilogo_agenda" value="1" class="btn btn-success fw-bold" data-confirm="Accendere il riepilogo settimanale con l'agenda? Dal prossimo passaggio del sistema riceveranno l'email anche studenti e altre persone con qualcosa in agenda."><i class="fa fa-toggle-on me-1"></i>Accendi il riepilogo con l'agenda</button>
        <?php endif; ?>
    </form>
</div>

<details class="card shadow-sm border-0 p-4 mb-4"<?php echo empty($sys['smtp_host']) ? ' open' : ''; ?> id="smtp">
    <summary class="fw-bold text-primary" style="cursor:pointer;"><i class="fa fa-cogs me-1" aria-hidden="true"></i> Impostazioni di invio delle email (SMTP) <span class="text-secondary fw-normal small">: servono solo se cambia il server di posta</span></summary>
    <form method="POST" class="mt-3">
        <?php csrf_field(); ?>
        
        <div class="row g-3 mb-4 bg-light p-3 border rounded">
            <div class="col-md-4">
                <label class="form-label small fw-bold">Host SMTP</label>
                <input type="text" name="smtp_host" class="form-control" value="<?php echo htmlspecialchars($sys['smtp_host'] ?? 'smtpservizi.unical.it'); ?>" required>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold">Porta SMTP</label>
                <input type="number" name="smtp_port" class="form-control" value="<?php echo (int)($sys['smtp_port'] ?? 587); ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold">Cifratura</label>
                <select name="smtp_secure" class="form-select">
                    <option value="tls" <?php echo ($sys['smtp_secure'] ?? '') == 'tls' ? 'selected' : ''; ?>>TLS</option>
                    <option value="ssl" <?php echo ($sys['smtp_secure'] ?? '') == 'ssl' ? 'selected' : ''; ?>>SSL</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold">Username SMTP</label>
                <input type="text" name="smtp_username" class="form-control" value="<?php echo htmlspecialchars($sys['smtp_username'] ?? ''); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold">Password SMTP</label>
                <input type="password" name="smtp_password" class="form-control" value="<?php echo htmlspecialchars($sys['smtp_password'] ?? ''); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold">Email Mittente (Da)</label>
                <input type="email" name="smtp_from_email" class="form-control" value="<?php echo htmlspecialchars($sys['smtp_from_email'] ?? 'noreply.eventi@unical.it'); ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold">Nome Mittente (Visualizzato)</label>
                <input type="text" name="smtp_from_name" class="form-control" value="<?php echo htmlspecialchars($sys['smtp_from_name'] ?? 'Didattica DiBEST - Unical'); ?>" required>
            </div>
        </div>

        <div class="alert alert-info border-info shadow-sm mb-0">
            <i class="fa fa-envelope-open-text me-1" aria-hidden="true"></i> <strong>I testi delle email</strong> (conferma e cancellazione delle prenotazioni, promemoria, attestato, sondaggio e tutte le altre email del portale) si cambiano ora dalla pagina
            <a href="email.php?p_id=<?php echo (int)$filtro_p; ?>" class="fw-bold">Gestione del portale → Testi delle email</a>, con anteprima, email di prova e ritorno al testo originale. I testi che avevi scritto qui ci sono già.
        </div>

        <div class="text-end mt-4 pt-3 border-top">
            <button type="submit" name="save_system_settings" onclick="tinymce.triggerSave();" class="btn btn-primary fw-bold px-4 py-3 shadow-sm">
                <i class="fa fa-save me-1"></i> Salva impostazioni SMTP
            </button>
        </div>
    </form>
</details>

<?php
// Registro invii (tabella creata al primo invio da registra_log_email())
$log_email = \App\Core\App::get(\App\Sistema\LogEmailRepository::class)->ultimi(100);
$stat_email = \App\Core\App::get(\App\Sistema\LogEmailRepository::class)->esitiSettimana();
$email_admin = (string)(\App\Core\App::get(\App\Auth\UtenteRepository::class)->emailDiUtente((int)$u_id_curr) ?? '');
?>
<div class="card shadow-sm border-0 p-4 mt-4" id="log-email">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 border-bottom pb-3 mb-3">
        <h5 class="fw-bold text-primary m-0"><i class="fa fa-list-alt me-1"></i> Verifica invio email</h5>
        <form method="POST" class="d-flex gap-2 m-0">
            <?php csrf_field(); ?>
            <input type="email" name="email_test" class="form-control form-control-sm" style="min-width:240px" placeholder="destinatario@unical.it" value="<?php echo htmlspecialchars($email_admin); ?>" required>
            <button type="submit" name="invia_email_test" class="btn btn-outline-primary btn-sm fw-bold text-nowrap"><i class="fa fa-paper-plane me-1"></i> Invia email di prova</button>
        </form>
        <form method="POST" class="m-0">
            <?php csrf_field(); ?>
            <button type="submit" name="invia_report_email" class="btn btn-outline-secondary btn-sm fw-bold text-nowrap" title="Ogni lunedì parte in automatico agli amministratori"><i class="fa fa-chart-simple me-1"></i> Invia ora il riepilogo settimanale</button>
        </form>
    </div>
    <p class="small text-muted mb-3">
        Ultimi 7 giorni: <span class="badge bg-success"><?php echo $stat_email['ok']; ?> accettate</span>
        <span class="badge bg-danger"><?php echo $stat_email['ko']; ?> fallite</span>.
        "Accettata" significa che il server SMTP ha preso in carico il messaggio; se non arriva, il problema è a valle (spam, filtri della casella).
    </p>
    <?php if (empty($log_email)): ?>
        <div class="text-muted small">Nessun invio registrato finora.</div>
    <?php else: ?>
        <div class="table-responsive" style="max-height:420px; overflow-y:auto;">
            <table class="table table-sm table-hover align-middle small m-0">
                <thead class="table-light" style="position:sticky; top:0;"><tr><th>Data</th><th>Destinatario</th><th>Oggetto</th><th>Esito</th></tr></thead>
                <tbody>
                <?php foreach ($log_email as $l): ?>
                    <tr>
                        <td class="text-nowrap"><?php echo date('d/m/Y H:i', strtotime($l['created_at'])); ?></td>
                        <td><?php echo htmlspecialchars($l['destinatario']); ?></td>
                        <td><?php echo htmlspecialchars($l['oggetto']); ?></td>
                        <td>
                            <?php if ((int)$l['esito'] === 1): ?>
                                <span class="badge bg-success">Inviata</span><?php if ($l['canale'] === 'mail()'): ?> <span class="badge bg-warning text-dark" title="<?php echo htmlspecialchars($l['errore'] ?? ''); ?>">via mail()</span><?php endif; ?>
                            <?php else: ?>
                                <span class="badge bg-danger">Fallita</span> <span class="text-danger"><?php echo htmlspecialchars($l['errore'] ?? ''); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php
// ---------------------------------------------------------------------
// BACKUP: stato dell'ultima esecuzione e configurazione (file .env del server)
// ---------------------------------------------------------------------
$bk = stato_backup();
$cfg_bk = [
    'NAS (BACKUP_NAS_PATH)' => env_valore('BACKUP_NAS_PATH') ?? '— non configurato',
    'Conservazione sul NAS' => (int)(env_valore('BACKUP_NAS_GIORNI') ?? 30) . ' giorni',
    'Copia via email (BACKUP_EMAIL)' => env_valore('BACKUP_EMAIL') ?? '— non configurata',
    'Frequenza email' => env_valore('BACKUP_EMAIL_FREQUENZA') ?? 'settimanale (il lunedì)',
    'Password della copia cifrata' => env_valore('BACKUP_PASSWORD') !== null ? (strlen(env_valore('BACKUP_PASSWORD')) >= 12 ? 'impostata' : 'troppo corta (servono 12 caratteri)') : '— non impostata',
    'Cifratura della copia via email' => (function () {
        // Prova reale su un file temporaneo: la costante AES può esserci anche con una libreria zip senza cifratura
        $tmp = tempnam(sys_get_temp_dir(), 'bk'); file_put_contents($tmp, 'prova');
        $zip_ok = cifra_zip_aes($tmp, $tmp . '.zip', 'prova-cifratura-123', $err_z); @unlink($tmp . '.zip');
        $ssl_ok = !$zip_ok && cifra_openssl_aes($tmp, $tmp . '.enc', 'prova-cifratura-123', $err_s); @unlink($tmp . '.enc'); @unlink($tmp);
        return $zip_ok ? 'ZIP AES-256 (si apre con 7-Zip)' : ($ssl_ok ? 'OpenSSL AES-256 (lo ZIP cifrato non è disponibile su questo server)' : 'NON disponibile');
    })(),
];
$icona_bk = fn($ok) => $ok === false ? '<i class="fa fa-circle-xmark text-danger me-1"></i>' : ($ok ? '<i class="fa fa-circle-check text-success me-1"></i>' : '<i class="fa fa-circle-info text-secondary me-1"></i>');
?>
<div class="card shadow-sm border-0 p-4 mt-4" id="backup">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 border-bottom pb-3 mb-3">
        <h5 class="fw-bold text-primary m-0"><i class="fa fa-database me-1"></i> Backup</h5>
        <div class="d-flex gap-2 flex-wrap">
            <a href="cron_backup.php" target="_blank" class="btn btn-outline-primary btn-sm fw-bold" data-confirm="Eseguire ora il backup completo? Può richiedere qualche minuto."><i class="fa fa-play me-1"></i> Esegui backup ora</a>
            <a href="cron_backup.php?email=1" target="_blank" class="btn btn-outline-secondary btn-sm fw-bold" data-confirm="Eseguire il backup e inviare subito la copia cifrata del database via email?"><i class="fa fa-envelope me-1"></i> Backup + invio email</a>
        </div>
    </div>
    <div class="row g-4">
        <div class="col-lg-7">
            <?php if (!$bk): ?>
                <div class="alert alert-warning small mb-0"><i class="fa fa-triangle-exclamation me-1"></i> Nessun backup registrato. Pianifica <code>admin/cron_backup.php</code> ogni notte (vedi README) oppure avvialo con il pulsante.</div>
            <?php else: ?>
                <div class="mb-2">
                    <span class="badge <?php echo !empty($bk['ok']) ? 'bg-success' : 'bg-warning text-dark'; ?> fs-6"><?php echo !empty($bk['ok']) ? 'Ultimo backup riuscito' : 'Ultimo backup con problemi'; ?></span>
                    <span class="small text-muted ms-2"><?php echo date('d/m/Y H:i', strtotime($bk['data'])); ?></span>
                    <?php if (strtotime($bk['data']) < time() - 2 * 86400): ?><span class="badge bg-danger ms-1">più di 2 giorni fa: il cron è attivo?</span><?php endif; ?>
                </div>
                <ul class="list-unstyled small mb-0">
                    <?php foreach (($bk['righe'] ?? []) as [$ok_r, $msg_r]): ?>
                        <li class="mb-1"><?php echo $icona_bk($ok_r); ?><?php echo htmlspecialchars($msg_r); ?></li>
                    <?php endforeach; ?>
                    <li class="mb-1"><?php echo $icona_bk(!empty($bk['ultima_email']) ? true : null); ?>Ultima copia via email: <?php echo !empty($bk['ultima_email']) ? date('d/m/Y H:i', strtotime($bk['ultima_email'])) : 'mai'; ?></li>
                </ul>
            <?php endif; ?>
        </div>
        <div class="col-lg-5">
            <table class="table table-sm small mb-2">
                <?php foreach ($cfg_bk as $k_bk => $v_bk): ?>
                    <tr><th class="fw-semibold text-secondary" style="width:48%;"><?php echo htmlspecialchars($k_bk); ?></th><td><?php echo htmlspecialchars($v_bk); ?></td></tr>
                <?php endforeach; ?>
            </table>
            <div class="form-text">Questi valori si impostano nel file <code>.env</code> del server (non dal pannello, per sicurezza). Il database via email parte solo cifrato.</div>
        </div>
    </div>
</div>

<?php $ctrl = is_file(dirname(__DIR__) . '/cache/controllo_sito.json') ? (json_decode((string)file_get_contents(dirname(__DIR__) . '/cache/controllo_sito.json'), true) ?: []) : []; ?>
<div class="card shadow-sm border-0 p-4 mt-4" id="controllo">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 border-bottom pb-3 mb-3">
        <h5 class="fw-bold text-primary m-0"><i class="fa fa-heart-pulse me-1"></i> Controllo del sito</h5>
        <form method="POST" class="m-0">
            <?php csrf_field(); ?>
            <button type="submit" name="controlla_sito" value="1" class="btn btn-outline-primary btn-sm fw-bold" data-attesa="Controllo in corso…"><i class="fa fa-stethoscope me-1"></i> Controlla ora</button>
        </form>
    </div>
    <p class="small text-secondary">Ogni notte il cron controlla database, cartelle, spazio su disco, backup, email non partite, pagine pubbliche e file riservati. Se qualcosa non va gli amministratori ricevono un'email (al massimo una al giorno per gli stessi problemi) e un'altra quando torna tutto a posto.</p>
    <?php if (!$ctrl): ?>
        <div class="alert alert-secondary small mb-0">Nessun controllo eseguito finora: premi <strong>Controlla ora</strong>.</div>
    <?php else: ?>
        <div class="mb-2">
            <span class="badge <?php echo empty($ctrl['problemi']) ? 'bg-success' : 'bg-danger'; ?> fs-6"><?php echo empty($ctrl['problemi']) ? 'Tutto a posto' : (int)$ctrl['problemi'] . ' problemi'; ?></span>
            <span class="small text-muted ms-2">Ultimo controllo: <?php echo date('d/m/Y H:i', strtotime($ctrl['data'])); ?></span>
        </div>
        <ul class="list-unstyled small mb-0">
            <?php foreach (($ctrl['esiti'] ?? []) as $e_c): ?>
                <li class="mb-1"><?php echo $icona_bk($e_c['ok']); ?><?php echo htmlspecialchars($e_c['voce']); ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<?php require_once 'admin_footer.php'; ?>
