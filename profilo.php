<?php
// =========================================================================
// Il mio profilo: dati personali, scheda di Ateneo (alcuni dati modificabili) ed email. Si arriva dal cruscotto (area_personale.php).
// =========================================================================
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/middleware.php';

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
    header("Location: profilo.php");
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
    header("Location: profilo.php");
    exit;
}

$page_cfg['titolo'] = 'Il mio profilo';
require_once 'header.php';
?>

<div class="container my-4" style="max-width: 900px;">
    <nav aria-label="Percorso" class="mb-3 small"><a href="area_personale.php"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i>Area personale</a></nav>
    <h1 class="fw-bold h3 mb-3">Il mio profilo</h1>

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

<?php require_once 'footer.php'; ?>
