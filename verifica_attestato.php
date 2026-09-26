<?php
// verifica_attestato.php - Verifica pubblica di un attestato dal codice stampato (o dal QR):
// mostra se è valido, a chi è stato rilasciato, per quale attività, periodo e ore.
require_once 'config.php';
require_once 'functions.php';

$codice = strtoupper(trim((string)($_GET['c'] ?? '')));
$esito = null; $dati = null;

if ($codice !== '') {
    if (!check_rate_limit($conn, 'verifica_attestato', 30, 300)) {
        $esito = 'limite';
    } elseif (!preg_match('/^[A-Z0-9-]{4,50}$/', $codice)) {
        $esito = 'non_valido';
    } else {
        // 1. Attestato di uno studente (progetti per le scuole)
        $stmt = $conn->prepare("SELECT pp.cognome, pp.nome, pp.escluso, pp.anonimizzato, pr.id AS pr_id FROM partecipanti_prenotazione pp JOIN prenotazioni pr ON pp.prenotazione_id = pr.id WHERE pp.codice = ? LIMIT 1");
        $stmt->bind_param("s", $codice);
        $stmt->execute();
        $s = $stmt->get_result()->fetch_assoc();
        if ($s) {
            $p = prenotazione_per_attestati($conn, (int)$s['pr_id']);
            $ok = $p && empty($s['escluso']) && (int)$p['presente'] === 1 && ($p['stato'] ?? '') === 'confermata' && (int)($p['attestati'] ?? 0) === 1;
            if ($ok) { $dati = dati_attestato($p, trim($s['nome'] . ' ' . $s['cognome']), $codice); $dati['anonimizzato'] = !empty($s['anonimizzato']); }
        } else {
            // 2. Attestato personale (codice della prenotazione)
            $stmt = $conn->prepare("SELECT id FROM prenotazioni WHERE UPPER(codice_prenotazione) = ? LIMIT 1");
            $stmt->bind_param("s", $codice);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $p = $row ? prenotazione_per_attestati($conn, (int)$row['id']) : null;
            if ($p && (int)$p['presente'] === 1 && ($p['stato'] ?? '') === 'confermata') {
                $regola = regola_attestato_evento($conn, (int)$p['evento_id']);
                if (in_array($regola, ['evento', 'singolo'], true)) {
                    $matr = '';
                    $dati = dati_attestato($p, trim($p['nome'] . ' ' . $p['cognome']), (string)$p['codice_prenotazione'], $matr);
                }
            }
        }
        $esito = $dati ? 'valido' : 'non_trovato';
    }
}

$page_cfg['titolo'] = "Verifica attestato";
require_once 'header.php';
?>
<div class="container my-5" style="max-width: 720px;">
    <h1 class="fw-bold mb-2"><i class="fa fa-shield-halved me-2 text-success" aria-hidden="true"></i>Verifica attestato</h1>
    <p class="text-secondary">Inserisci il codice di verifica stampato sull'attestato (es. <span class="font-monospace">AT-7K2M9QX4PB</span>) oppure inquadra il QR.</p>

    <form method="GET" class="d-flex gap-2 mb-4" role="search">
        <label for="codVer" class="visually-hidden">Codice di verifica</label>
        <input type="text" name="c" id="codVer" class="form-control form-control-lg font-monospace text-uppercase" value="<?php echo h($codice); ?>" placeholder="Codice di verifica" maxlength="50" required>
        <button type="submit" class="btn btn-success btn-lg fw-bold px-4">Verifica</button>
    </form>

    <?php if ($esito === 'valido'): ?>
        <div class="card border-0 shadow-sm" style="border-top: 5px solid #198754 !important; border-radius: 12px;">
            <div class="card-body p-4">
                <div class="d-flex align-items-center gap-2 text-success fw-bold fs-5 mb-3"><i class="fa fa-circle-check fs-3" aria-hidden="true"></i>Attestato valido</div>
                <dl class="row mb-0">
                    <dt class="col-sm-4 text-secondary">Rilasciato a</dt><dd class="col-sm-8 fw-bold fs-5"><?php echo h(mb_strtoupper($dati['nome'])); ?><?php if (!empty($dati['anonimizzato'])): ?><div class="small fw-normal text-secondary">Per la tutela della privacy, trascorsi <?php echo (int)MESI_CONSERVAZIONE_STUDENTI; ?> mesi dalla fine del progetto si conservano solo le iniziali: confrontale con il nome stampato sull'attestato.</div><?php endif; ?></dd>
                    <dt class="col-sm-4 text-secondary">Attività</dt><dd class="col-sm-8 fw-semibold"><?php echo h($dati['evento']); ?></dd>
                    <?php if ($dati['quando'] !== ''): ?><dt class="col-sm-4 text-secondary">Periodo</dt><dd class="col-sm-8"><?php echo h($dati['quando']); ?></dd><?php endif; ?>
                    <?php if ($dati['ore'] !== ''): ?><dt class="col-sm-4 text-secondary">Ore</dt><dd class="col-sm-8"><?php echo h($dati['ore']); ?></dd><?php endif; ?>
                    <?php if ($dati['area'] !== ''): ?><dt class="col-sm-4 text-secondary">Iniziativa</dt><dd class="col-sm-8"><?php echo h($dati['area']); ?></dd><?php endif; ?>
                    <dt class="col-sm-4 text-secondary">Codice</dt><dd class="col-sm-8 font-monospace"><?php echo h($codice); ?></dd>
                </dl>
            </div>
        </div>
    <?php elseif ($esito === 'non_trovato' || $esito === 'non_valido'): ?>
        <div class="alert alert-danger d-flex align-items-center gap-2"><i class="fa fa-circle-xmark fs-4" aria-hidden="true"></i><div><strong>Codice non riconosciuto.</strong> Controlla di averlo scritto correttamente: se il problema persiste l'attestato potrebbe non essere valido.</div></div>
    <?php elseif ($esito === 'limite'): ?>
        <div class="alert alert-warning">Troppe verifiche in poco tempo: riprova tra qualche minuto.</div>
    <?php endif; ?>
</div>
<?php require_once 'footer.php'; ?>
