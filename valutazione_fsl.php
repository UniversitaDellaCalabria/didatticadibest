<?php
// valutazione_fsl.php - Scheda di valutazione della struttura ospitante (Formazione Scuola Lavoro).
// Il docente che ha prenotato la riceve per email a fine attività (link personale ?t=…, cron_background.php).
require_once 'config.php';
require_once 'functions.php';

$token = (string)($_GET['t'] ?? '');
$p = prenotazione_da_valutazione($conn, $token);
$errore = null; $fatto = false;

if ($p && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $errore = salva_valutazione_fsl($conn, $p, $_POST);
    if ($errore === null) {
        registra_log_audit($conn, "Scheda di valutazione FSL compilata", ["Prenotazione" => $p['codice_prenotazione']]);
        $fatto = true;
    }
}

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$page_cfg['titolo'] = "Scheda di valutazione";
$col_v = $p ? colore_area_turno($conn, (int)$p['turno_id']) : '#B30000';
$col_v_txt = colore_testo_su($col_v);
require_once 'header.php';
?>
<style>
.vf-voti { display:flex; gap:.4rem; flex-wrap:wrap; }
.vf-voti input { position:absolute; opacity:0; }
.vf-voti label { min-width:44px; text-align:center; padding:.45rem .6rem; border:2px solid #cbd5e1; border-radius:8px; font-weight:700; cursor:pointer; background:#fff; }
.vf-voti input:checked + label { background:<?php echo $col_v; ?>; border-color:<?php echo $col_v; ?>; color:<?php echo $col_v_txt; ?>; }
.vf-voti input:focus-visible + label { outline:3px solid #1d4ed8; outline-offset:2px; }
.vf-riga { padding:.9rem 0; border-bottom:1px solid #e5e7eb; }
</style>
<div class="container my-4" style="max-width: 820px;">
<?php if (!$p): ?>
    <div class="alert alert-warning my-5"><i class="fa fa-circle-info me-1" aria-hidden="true"></i>Il link non è valido. Usa il link ricevuto per email oppure scrivi alla segreteria dell'attività.</div>
<?php elseif ($fatto || (!empty($p['valutazione_id']) && $_SERVER['REQUEST_METHOD'] !== 'POST')): ?>
    <div class="card border-0 shadow-sm text-center p-5 my-4">
        <i class="fa fa-circle-check text-success mb-3" style="font-size:3.5rem;" aria-hidden="true"></i>
        <h1 class="h3 fw-bold">Grazie!</h1>
        <p class="text-secondary mb-0">La scheda di valutazione per <strong><?php echo $h($p['evento_titolo']); ?></strong> è stata registrata.</p>
    </div>
<?php else:
    $s_sc = !empty($p['scuola_codice']) ? scuola_per_codice($conn, $p['scuola_codice']) : null;
    [$v_dal, $v_al] = periodo_prenotazione($p); ?>
    <h1 class="h3 fw-bold mt-3">Scheda di valutazione della struttura ospitante</h1>
    <p class="text-secondary">Formazione Scuola Lavoro · Dipartimento di Biologia, Ecologia e Scienze della Terra</p>
    <div class="card border-0 shadow-sm p-3 mb-3">
        <div class="row small g-2">
            <div class="col-md-6"><strong>Attività:</strong> <?php echo $h($p['evento_titolo']); ?><?php echo etichetta_turno($p) !== '' ? ' · ' . $h(etichetta_turno($p)) : ''; ?></div>
            <div class="col-md-6"><strong>Periodo:</strong> <?php echo date('d/m/Y', strtotime($v_dal)) . ($v_al !== $v_dal ? ' – ' . date('d/m/Y', strtotime($v_al)) : ''); ?></div>
            <div class="col-md-6"><strong>Scuola:</strong> <?php echo $h($s_sc ? etichetta_scuola($s_sc) : (nome_scuola_prenotazione($p) ?: '—')); ?></div>
            <div class="col-md-6"><strong>Codice prenotazione:</strong> <?php echo $h($p['codice_prenotazione']); ?></div>
        </div>
    </div>
    <?php if ($errore): ?><div class="alert alert-danger" role="alert"><?php echo $h($errore); ?></div><?php endif; ?>
    <form method="POST" class="card border-0 shadow-sm p-3 p-md-4">
        <?php csrf_field(); ?>
        <p class="small text-secondary mb-2">Dai un voto da <strong>1</strong> (per nulla soddisfacente) a <strong>5</strong> (del tutto soddisfacente). I campi con * sono obbligatori.</p>
        <?php foreach (VALUTAZIONE_FSL_ASPETTI as $k => $etichetta): $sel = (int)($_POST['voto'][$k] ?? 0); ?>
            <fieldset class="vf-riga">
                <legend class="fs-6 fw-semibold mb-2" style="float:none;"><?php echo $h($etichetta); ?> <span class="text-danger">*</span></legend>
                <div class="vf-voti">
                    <?php for ($v = 1; $v <= 5; $v++): ?>
                        <input type="radio" name="voto[<?php echo $k; ?>]" id="v_<?php echo $k . $v; ?>" value="<?php echo $v; ?>" required <?php echo $sel === $v ? 'checked' : ''; ?>>
                        <label for="v_<?php echo $k . $v; ?>"><?php echo $v; ?></label>
                    <?php endfor; ?>
                </div>
            </fieldset>
        <?php endforeach; ?>
        <fieldset class="vf-riga">
            <legend class="fs-6 fw-semibold mb-2" style="float:none;">Riproporresti l'attività ad altre classi? <span class="text-danger">*</span></legend>
            <?php foreach (['si' => 'Sì', 'forse' => 'Forse', 'no' => 'No'] as $k => $t): ?>
                <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="ripeterebbe" id="rip_<?php echo $k; ?>" value="<?php echo $k; ?>" required <?php echo ($_POST['ripeterebbe'] ?? '') === $k ? 'checked' : ''; ?>><label class="form-check-label" for="rip_<?php echo $k; ?>"><?php echo $t; ?></label></div>
            <?php endforeach; ?>
        </fieldset>
        <?php foreach (VALUTAZIONE_FSL_APERTE as $k => $etichetta): ?>
            <div class="vf-riga">
                <label class="form-label fw-semibold" for="t_<?php echo $k; ?>"><?php echo $h($etichetta); ?></label>
                <textarea class="form-control" name="testo[<?php echo $k; ?>]" id="t_<?php echo $k; ?>" rows="3" maxlength="2000"><?php echo $h($_POST['testo'][$k] ?? ''); ?></textarea>
            </div>
        <?php endforeach; ?>
        <div class="vf-riga border-0">
            <label class="form-label fw-semibold" for="compilata_da">Compilata da (docente tutor)</label>
            <input type="text" class="form-control" name="compilata_da" id="compilata_da" maxlength="150" value="<?php echo $h($_POST['compilata_da'] ?? trim($p['nome'] . ' ' . $p['cognome'])); ?>">
        </div>
        <p class="small text-secondary">Le risposte sono usate dal Dipartimento per valutare e migliorare i percorsi, come previsto dalla convenzione. <a href="privacy.php" target="_blank" rel="noopener">Informativa privacy</a>.</p>
        <button type="submit" class="btn fw-bold px-4" style="background:<?php echo $col_v; ?>; color:<?php echo $col_v_txt; ?>;"><i class="fa fa-paper-plane me-1" aria-hidden="true"></i>Invia la scheda</button>
    </form>
<?php endif; ?>
</div>
<?php require_once 'footer.php'; ?>
