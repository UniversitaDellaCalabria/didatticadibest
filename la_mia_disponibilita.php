<?php
// =========================================================================
// La mia disponibilità: il docente indica se accetta tesisti e tirocinanti, quanti posti ha, per quali corsi di laurea (dall'anagrafe di Ateneo), le attività e
// gli obiettivi formativi. Compare sulle pagine pubbliche disponibilita_tesi.php e disponibilita_tirocini.php con un semaforo che si calcola dai posti liberi.
// Autenticazione e dati utente: middleware.php ($u_id, $is_full_admin, $user_info).
// =========================================================================
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/inc/disponibilita_vista.php';

$app = \App\Core\App::per($conn);
$profili_d = $app->get(\App\Portale\Area\ProfiliPersona::class)->di($user_info ?? null);
if (!in_array(\App\Portale\Area\Profili::DOCENTE, $profili_d, true) && !$is_full_admin) {
    flash_set('La disponibilità per tesi e tirocini è riservata ai docenti.', 'warning');
    header('Location: area_personale.php');
    exit;
}
$rep = $app->get(\App\Disponibilita\DisponibilitaRepository::class);
$tipi = \App\Disponibilita\DisponibilitaRepository::TIPI;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $tipo_p = (string)($_POST['tipo'] ?? '');
    if (isset($_POST['togli'])) {
        $rep->elimina((int)$u_id, $tipo_p);
        registra_log_audit($conn, 'Disponibilità tolta dalla pagina pubblica', ['Tipo' => $tipo_p]);
        flash_set('Non compari più nella pagina pubblica (' . strtolower($tipi[$tipo_p] ?? '') . ').', 'success');
    } else {
        $err = $rep->salva((int)$u_id, $tipo_p, $_POST, date('Y-m-d H:i:s'));
        if (!$err) registra_log_audit($conn, 'Disponibilità aggiornata', ['Tipo' => $tipo_p, 'Posti' => (int)($_POST['posti'] ?? 0), 'Occupati' => (int)($_POST['occupati'] ?? 0)]);
        flash_set($err ?? 'Salvato: la pagina pubblica è già aggiornata.', $err ? 'danger' : 'success');
    }
    header('Location: la_mia_disponibilita.php#' . urlencode($tipo_p));
    exit;
}

$corsi_ateneo = $app->get(\App\Anagrafi\CorsoRepository::class)->visibili();
$per_livello = [];
foreach ($corsi_ateneo as $c) $per_livello[(string)($c['tipo_descrizione'] ?: 'Altri corsi')][] = $c;
$ora = new DateTimeImmutable('now');
$h = static fn ($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$page_cfg['titolo'] = 'La mia disponibilità';
require_once 'header.php';
echo ui_tema_script();
echo disp_stile();
?>
<div class="container my-4" style="max-width: 900px;">
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
        <?php echo ui_intestazione('La mia disponibilità', 'area_personale.php', 'Area personale', 'Indica se accetti tesisti e tirocinanti: gli studenti lo vedono subito sulle pagine pubbliche, con un semaforo.'); ?>
        <div class="d-print-none"><?php echo ui_tema_pulsante(); ?></div>
    </div>
    <?php echo flash_html(); ?>
    <p class="small text-secondary">Il semaforo si calcola da solo dai posti liberi: <strong>verde</strong> ci sono posti, <strong>arancione</strong> resta un posto solo, <strong>rosso</strong> al completo, <strong>grigio</strong> non accetti oppure non aggiorni da più di <?php echo \App\Disponibilita\Semaforo::GIORNI_VALIDITA; ?> giorni. Quando uno studente si laurea o finisce, aggiorna i posti occupati.</p>

    <?php foreach ($tipi as $tipo => $nome_tipo):
        $d = $rep->perUtente((int)$u_id, $tipo);
        $sem = $d ? \App\Disponibilita\Semaforo::di($d, $ora) : null;
        $sel = $d ? array_filter(explode(',', (string)$d['corsi'])) : [];
        $pagina = $tipo === 'tesi' ? 'disponibilita_tesi.php' : 'disponibilita_tirocini.php'; ?>
        <form method="POST" id="<?php echo $tipo; ?>" class="disp-riga mb-4">
            <?php csrf_field(); ?><input type="hidden" name="tipo" value="<?php echo $tipo; ?>">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                <h2 class="h5 fw-bold mb-0"><?php echo $tipo === 'tesi' ? 'Tesi' : 'Tirocinio'; ?></h2>
                <?php if ($sem): ?><span class="ms-2"><?php echo disp_semaforo($sem); ?></span><span class="small text-secondary ms-auto">Aggiornata <?php echo $h(\App\Disponibilita\Semaforo::quandoTesto($sem['giorni'])); ?> · <a href="<?php echo $pagina; ?>" target="_blank" rel="noopener">vedi la pagina pubblica</a></span>
                <?php else: ?><span class="small text-secondary ms-auto">Non compari ancora nella pagina pubblica</span><?php endif; ?>
            </div>
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" role="switch" id="acc_<?php echo $tipo; ?>" name="accetta" value="1" <?php echo (!$d || $d['accetta']) ? 'checked' : ''; ?>>
                <label class="form-check-label fw-semibold" for="acc_<?php echo $tipo; ?>">Accetto <?php echo $tipo === 'tesi' ? 'nuovi tesisti' : 'nuovi tirocinanti'; ?></label>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-6 col-md-3"><label class="form-label small fw-semibold" for="p_<?php echo $tipo; ?>">Posti totali</label><input type="number" class="form-control" id="p_<?php echo $tipo; ?>" name="posti" min="0" max="<?php echo \App\Disponibilita\DisponibilitaRepository::MAX_POSTI; ?>" value="<?php echo (int)($d['posti'] ?? 0); ?>"></div>
                <div class="col-6 col-md-3"><label class="form-label small fw-semibold" for="o_<?php echo $tipo; ?>"><?php echo $tipo === 'tesi' ? 'Già assegnati' : 'In corso'; ?></label><input type="number" class="form-control" id="o_<?php echo $tipo; ?>" name="occupati" min="0" max="<?php echo \App\Disponibilita\DisponibilitaRepository::MAX_POSTI; ?>" value="<?php echo (int)($d['occupati'] ?? 0); ?>"></div>
                <div class="col-md-6"><label class="form-label small fw-semibold" for="s_<?php echo $tipo; ?>"><?php echo $tipo === 'tesi' ? 'Laboratorio o sede (facoltativo)' : 'Dove si svolge: laboratorio, ente, azienda (facoltativo)'; ?></label><input type="text" class="form-control" id="s_<?php echo $tipo; ?>" name="sede" maxlength="200" value="<?php echo $h($d['sede'] ?? ''); ?>"></div>
            </div>
            <fieldset class="mb-3">
                <legend class="form-label small fw-semibold">Per quali corsi di laurea <span class="fw-normal text-secondary">(dall'anagrafe di Ateneo; nessuno = tutti)</span></legend>
                <?php if (!$per_livello): ?><div class="small text-secondary">L'elenco dei corsi non è ancora stato caricato: chiedi all'amministratore di aggiornare l'anagrafe di Ateneo.</div><?php endif; ?>
                <?php foreach ($per_livello as $livello => $lista): ?>
                    <div class="small text-secondary mt-2"><?php echo $h($livello); ?></div>
                    <?php foreach ($lista as $c): ?>
                        <div class="form-check"><input class="form-check-input" type="checkbox" name="corsi[]" id="c_<?php echo $tipo . '_' . $h($c['codice']); ?>" value="<?php echo $h($c['codice']); ?>" <?php echo in_array((string)$c['codice'], $sel, true) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="c_<?php echo $tipo . '_' . $h($c['codice']); ?>"><?php echo $h($c['nome']); ?></label></div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </fieldset>
            <div class="mb-3"><label class="form-label small fw-semibold" for="t_<?php echo $tipo; ?>">Temi o argomenti <span class="fw-normal text-secondary">(separati da virgola)</span></label><input type="text" class="form-control" id="t_<?php echo $tipo; ?>" name="temi" maxlength="300" value="<?php echo $h($d['temi'] ?? ''); ?>"></div>
            <div class="mb-3"><label class="form-label small fw-semibold" for="a_<?php echo $tipo; ?>">Attività previste</label><textarea class="form-control" id="a_<?php echo $tipo; ?>" name="attivita" rows="3" maxlength="1500" placeholder="<?php echo $tipo === 'tesi' ? 'Per esempio: campionamenti in campo, analisi di laboratorio, elaborazione dei dati' : 'Per esempio: affiancamento in laboratorio, rilievi, redazione di un rapporto'; ?>"><?php echo $h($d['attivita'] ?? ''); ?></textarea></div>
            <div class="mb-3"><label class="form-label small fw-semibold" for="b_<?php echo $tipo; ?>">Obiettivi formativi</label><textarea class="form-control" id="b_<?php echo $tipo; ?>" name="obiettivi" rows="3" maxlength="1500" placeholder="Che cosa imparerà lo studente"><?php echo $h($d['obiettivi'] ?? ''); ?></textarea></div>
            <div class="mb-3"><label class="form-label small fw-semibold" for="n_<?php echo $tipo; ?>">Come candidarsi, requisiti <span class="fw-normal text-secondary">(facoltativo)</span></label><input type="text" class="form-control" id="n_<?php echo $tipo; ?>" name="note" maxlength="500" value="<?php echo $h($d['note'] ?? ''); ?>" placeholder="Per esempio: scrivimi con l'elenco degli esami sostenuti"></div>
            <div class="d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary fw-bold"><i class="fa fa-floppy-disk me-1" aria-hidden="true"></i>Salva</button>
                <?php if ($d): ?><button type="submit" name="togli" value="1" class="btn btn-outline-secondary" onclick="return confirm('Vuoi togliere la tua disponibilità dalla pagina pubblica?');">Togli dalla pagina pubblica</button><?php endif; ?>
            </div>
        </form>
    <?php endforeach; ?>
</div>
<?php require_once 'footer.php'; ?>
