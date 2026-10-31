<?php
// =========================================================================
// Il mio profilo: dati personali, scheda di Ateneo (alcuni dati modificabili) ed email. Si arriva dal cruscotto (area_personale.php).
// =========================================================================
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/middleware.php';

// =======================================================================
// AZIONE: CAMBIO EMAIL IN DUE PASSI (src/Auth/VerificaEmail): 1) nuova email → codice per posta; 2) codice → l'email cambia.
// Senza la verifica chiunque poteva scrivere l'email di un docente e ricevere conferme, firme e registri a lui destinati.
// =======================================================================
$verifica_email = \App\Core\App::per($conn)->get(\App\Auth\VerificaEmail::class);
$msg_email = fn(string $testo, bool $ok) => "<div class='alert alert-" . ($ok ? 'success' : 'danger') . " fw-bold text-center my-3 shadow-sm'><i class='fa " . ($ok ? 'fa-check-circle' : 'fa-times-circle') . " me-1'></i> " . htmlspecialchars($testo) . "</div>";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['aggiorna_email']) || isset($_POST['conferma_codice_email']) || isset($_POST['annulla_verifica_email']))) {
    csrf_verify($_POST['csrf_token'] ?? '');
    if (isset($_POST['annulla_verifica_email'])) {
        $verifica_email->annulla((int)$u_id);
    } elseif (isset($_POST['conferma_codice_email'])) {
        [$err_ve, $nuova_ve] = $verifica_email->conferma((int)$u_id, (string)($_POST['codice'] ?? ''));
        if ($err_ve === null) {
            $_SESSION['utente_email'] = $nuova_ve;
            registra_log_audit($conn, "Profilo: email cambiata con codice di verifica", ["Nuova email" => $nuova_ve]);
        }
        $_SESSION['msg_area_pers'] = $msg_email($err_ve ?? 'Email confermata e aggiornata.', $err_ve === null);
    } elseif (!check_rate_limit($conn, 'verifica_email', 10, 3600)) {
        $_SESSION['msg_area_pers'] = $msg_email('Troppe richieste: riprova tra un po\'.', false);
    } else {
        $err_ve = $verifica_email->richiedi((int)$u_id, (string)($_POST['nuova_email'] ?? ''));
        $_SESSION['msg_area_pers'] = $msg_email($err_ve ?? 'Ti abbiamo mandato un codice di 6 cifre: scrivilo qui sotto per confermare la nuova email (controlla anche lo spam).', $err_ve === null);
    }
    header("Location: profilo.php#email");
    exit;
}
$ve_in_corso = $verifica_email->inCorso((int)$u_id);

// =======================================================================
// AZIONE: SCHEDA DI ATENEO (dati dalle API del portale, modificabili dalla persona)
// =======================================================================
$pagina_iniziale = \App\Core\App::per($conn)->get(\App\Portale\PaginaIniziale::class);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pagina_iniziale']) && $pagina_iniziale->puoScegliere($user_info)) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $pagina_iniziale->imposta((int)$u_id, !empty($_POST['al_cruscotto']));
    $_SESSION['msg_area_pers'] = "<div class='alert alert-success fw-bold text-center my-3 shadow-sm'><i class='fa fa-check-circle me-1'></i> Preferenza salvata: " . (!empty($_POST['al_cruscotto']) ? 'dopo l\'accesso vai al Cruscotto del Direttore.' : 'dopo l\'accesso vai alla tua area personale.') . "</div>";
    header('Location: profilo.php'); exit;
}
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
    header("Location: profilo.php");
    exit;
}

$page_cfg['titolo'] = 'Il mio profilo';
require_once 'header.php';
echo ui_tema_script();
?>

<div class="container my-4" style="max-width: 900px;">
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
        <?php echo ui_intestazione('Il mio profilo'); ?>
        <div class="d-print-none"><?php echo ui_tema_pulsante(); ?></div>
    </div>

    <?php
    if (isset($_SESSION['msg_area_pers'])) {
        echo $_SESSION['msg_area_pers'];
        unset($_SESSION['msg_area_pers']);
    }
    ?>

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

            <?php if ($pagina_iniziale->puoScegliere($user_info)): ?>
            <!-- Pagina dopo l'accesso (Direttore e vice) -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header fw-bold"><i class="fa fa-door-open me-2 text-secondary" aria-hidden="true"></i>Dopo l'accesso</div>
                <div class="card-body">
                    <form method="POST">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="pagina_iniziale" value="1">
                        <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="al_cruscotto" value="1" id="alCruscotto"<?php echo $pagina_iniziale->sceltaCruscotto((int)$u_id) ? ' checked' : ''; ?>>
                            <label class="form-check-label" for="alCruscotto">Portami subito al Cruscotto del Direttore (invece che alla mia area personale)</label></div>
                        <button type="submit" class="btn btn-outline-primary btn-sm fw-bold">Salva</button>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <?php if (\App\Core\App::per($conn)->get(\App\Assistenza\Push\ServizioPush::class)->attivo()): ?>
            <!-- Avvisi sul dispositivo per i compiti in scadenza -->
            <div class="card shadow-sm border-0 mb-4" id="avvisi-dispositivo">
                <div class="card-header fw-bold"><i class="fa fa-bell me-2 text-secondary" aria-hidden="true"></i>Avvisi sul dispositivo</div>
                <div class="card-body">
                    <p class="small text-secondary mb-2">Ricevi una notifica su questo dispositivo quando una cosa da fare sta per scadere (al massimo una al giorno, nei giorni feriali). Non dice nulla di personale: basta toccarla per aprire «Da fare per te».</p>
                    <div id="pushPersonaStato" class="small mb-2" aria-live="polite"></div>
                    <button type="button" class="btn btn-outline-primary btn-sm fw-bold" id="pushPersonaBtn" hidden>Attiva gli avvisi</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="pushPersonaProva" hidden>Manda una prova</button>
                </div>
            </div>
            <script src="assets/js/assistenza-push.js"></script>
            <script>
            (function () {
                var btn = document.getElementById('pushPersonaBtn'), prova = document.getElementById('pushPersonaProva'), st = document.getElementById('pushPersonaStato'), P = window.AssistenzaPush;
                var csrf = <?php echo json_encode(csrf_token()); ?>, chiave = '', iscritto = false, endpoint = '';
                if (!P || !P.supportato()) { st.textContent = 'Questo browser non supporta le notifiche (su iPhone servono l\'app installata e iOS recente).'; return; }
                function chiama(dati) { var b = new URLSearchParams(Object.assign({ csrf_token: csrf }, dati)); return fetch('push_persona_ajax.php', { method: 'POST', body: b, credentials: 'same-origin' }).then(function (r) { return r.json(); }); }
                function mostra() { btn.hidden = false; btn.textContent = iscritto ? 'Disattiva gli avvisi' : 'Attiva gli avvisi'; prova.hidden = !iscritto; st.textContent = iscritto ? 'Gli avvisi sono attivi su questo dispositivo.' : 'Gli avvisi non sono attivi su questo dispositivo.'; }
                P.stato().then(function (s) {
                    endpoint = s.sottoscrizione ? s.sottoscrizione.endpoint : '';
                    return fetch('push_persona_ajax.php?endpoint=' + encodeURIComponent(endpoint), { credentials: 'same-origin' }).then(function (r) { return r.json(); });
                }).then(function (j) { chiave = j.chiave || ''; iscritto = !!j.iscritto; mostra(); });
                btn.addEventListener('click', function () {
                    btn.disabled = true;
                    var op = iscritto
                        ? P.disiscrivi().then(function (e) { return chiama({ azione: 'disiscrivi', endpoint: e || endpoint }); }).then(function () { iscritto = false; })
                        : P.iscrivi(chiave).then(function (s) { endpoint = s.endpoint; return chiama({ azione: 'iscrivi', sottoscrizione: JSON.stringify(s.toJSON()) }); }).then(function (j) { if (!j.ok) throw new Error(j.errore); iscritto = true; });
                    op.then(mostra).catch(function (e) { st.textContent = e.message || 'Non è stato possibile.'; }).then(function () { btn.disabled = false; });
                });
                prova.addEventListener('click', function () { chiama({ azione: 'prova' }).then(function (j) { st.textContent = j.ok ? 'Prova inviata: dovresti vederla subito.' : (j.errore || 'Prova non riuscita.'); }); });
            })();
            </script>
            <?php endif; ?>

            <!-- Email -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header fw-bold" id="email"><i class="fa fa-envelope me-2 text-secondary"></i>Indirizzo email</div>
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
                        L'email fornita dall'Università al momento del login viene salvata automaticamente; puoi sostituirla con un'altra:
                        ti mandiamo un <strong>codice di 6 cifre</strong> al nuovo indirizzo e l'email cambia solo dopo che lo hai scritto qui.
                    </p>
                    <?php if ($ve_in_corso): ?>
                        <div class="border rounded-3 p-3 mb-3" style="background:#f8fafc;">
                            <p class="mb-2">Abbiamo mandato il codice a <strong><?php echo htmlspecialchars($ve_in_corso['email']); ?></strong>: vale fino alle <?php echo date('H:i', strtotime($ve_in_corso['scade_il'])); ?>.</p>
                            <form method="POST" class="d-flex flex-wrap gap-2 align-items-end">
                                <?php csrf_field(); ?>
                                <div><label class="form-label small fw-bold mb-0" for="codiceEmail">Codice</label>
                                    <input type="text" id="codiceEmail" name="codice" class="form-control font-monospace" style="max-width:160px;letter-spacing:4px;" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" required autofocus></div>
                                <button type="submit" name="conferma_codice_email" value="1" class="btn btn-primary fw-bold"><i class="fa fa-check me-1" aria-hidden="true"></i>Conferma la nuova email</button>
                                <button type="submit" name="annulla_verifica_email" value="1" class="btn btn-link text-secondary" formnovalidate>Annulla</button>
                            </form>
                            <div class="form-text">Non è arrivato? Controlla lo spam o chiedi un nuovo codice qui sotto (dopo un minuto).</div>
                        </div>
                    <?php endif; ?>
                    <form method="POST" novalidate>
                        <?php csrf_field(); ?>
                        <input type="hidden" name="aggiorna_email" value="1">
                        <label class="form-label small fw-bold mb-1" for="nuovaEmail">Nuova email</label>
                        <div class="input-group">
                            <input type="email" id="nuovaEmail" name="nuova_email" class="form-control"
                                   placeholder="nuova@email.it"
                                   value="<?php echo htmlspecialchars($ve_in_corso['email'] ?? ''); ?>"
                                   required>
                            <button type="submit" class="btn btn-outline-primary fw-bold"><i class="fa fa-paper-plane me-1" aria-hidden="true"></i> <?php echo $ve_in_corso ? 'Manda un nuovo codice' : 'Mandami il codice'; ?></button>
                        </div>
                    </form>
                </div>
            </div>
</div>

<?php require_once 'footer.php'; ?>
