<?php
// report_ambiti.php - Modulo Eventi e seminari › Report per ambito (Terza missione e public engagement): per anno solare o
// accademico, numeri per ambito (eventi, incontri, ore, iscrizioni, partecipanti, presenze, scuole), andamento per mese,
// elenco degli eventi; iscritti agli avvisi per email. Excel con lo stesso contenuto. Logica in inc/report_ambiti.php.
require_once 'admin_header.php';

$puo_report = $is_full_admin || ha_modulo($conn, (int)$u_id_curr, 'orientamento');
if (!$puo_report) nega_accesso();
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$anno_c = (int)date('Y');
$anni = array_merge(array_map('strval', range($anno_c + 1, $anno_c - 5)), array_map(fn($a) => ($a - 1) . '/' . $a, range($anno_c + 1, $anno_c - 4)));
$anno = in_array($_GET['anno'] ?? '', $anni, true) ? $_GET['anno'] : (string)$anno_c;
[$dal, $al] = periodo_report($anno);
$periodo = (str_contains($anno, '/') ? 'a.a. ' : 'anno ') . $anno . ' (' . date('d/m/Y', strtotime($dal)) . ' – ' . date('d/m/Y', strtotime($al)) . ')';
$d = dati_report_ambiti($conn, $dal, $al);

if (($_GET['esporta'] ?? '') === 'xlsx') {
    $f = excel_report_ambiti($d, $periodo);
    if ($f) { registra_log_audit($conn, "Report per ambito esportato", ["Periodo" => $anno]); invia_file_scaricabile($f, 'Report_ambiti_' . str_replace('/', '-', $anno) . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); }
    flash_set("Esportazione non riuscita (manca l'estensione ZIP di PHP).", 'danger');
}
$iscr = conta_iscritti_avvisi($conn);
$n_fmt = fn($v) => number_format((float)$v, (float)$v == floor((float)$v) ? 0 : 1, ',', '.');
$max_m = 1; foreach ($d['mesi'] as $x) $max_m = max($max_m, array_sum($x));
?>
<style>
.rp-kpi { background: #fff; border-radius: 12px; padding: 14px 16px; border: 1px solid #e2e8f0; height: 100%; }
.rp-kpi .v { font-size: 1.6rem; font-weight: 800; line-height: 1.1; }
.rp-kpi .l { font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; color: #64748b; font-weight: 700; }
.rp-mese { display: flex; align-items: flex-end; gap: 6px; height: 140px; }
.rp-col { flex: 1; display: flex; flex-direction: column-reverse; min-width: 14px; border-radius: 4px 4px 0 0; overflow: hidden; }
.rp-col span { display: block; }
</style>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <h4 class="fw-bold text-dark mb-0"><i class="fa fa-chart-column me-2 text-primary" aria-hidden="true"></i>Report per ambito</h4>
    <span class="small text-secondary">Terza missione e public engagement</span>
    <form method="GET" class="ms-auto d-flex gap-2"><input type="hidden" name="p_id" value="<?php echo (int)$filtro_p; ?>">
        <select class="form-select form-select-sm" name="anno" onchange="this.form.submit()" aria-label="Periodo">
            <optgroup label="Anno solare"><?php foreach ($anni as $a): if (str_contains($a, '/')) continue; ?><option<?php echo $a === $anno ? ' selected' : ''; ?>><?php echo $a; ?></option><?php endforeach; ?></optgroup>
            <optgroup label="Anno accademico"><?php foreach ($anni as $a): if (!str_contains($a, '/')) continue; ?><option value="<?php echo $a; ?>"<?php echo $a === $anno ? ' selected' : ''; ?>>a.a. <?php echo $a; ?></option><?php endforeach; ?></optgroup>
        </select>
        <a class="btn btn-sm btn-success fw-bold text-nowrap" href="report_ambiti.php?p_id=<?php echo (int)$filtro_p; ?>&amp;anno=<?php echo urlencode($anno); ?>&amp;esporta=xlsx"><i class="fa fa-file-excel me-1" aria-hidden="true"></i>Excel</a>
    </form>
</div>
<p class="small text-secondary">Periodo: <?php echo $h($periodo); ?>. Contano i turni con data nel periodo, anche degli eventi archiviati. Partecipanti: per le prenotazioni delle classi il numero di studenti dichiarato dal docente. Un evento con più ambiti compare in ciascuno; il totale lo conta una volta.</p>

<div class="row g-3 mb-3">
    <?php foreach ([['eventi', 'Eventi', '#0f172a'], ['incontri', 'Incontri', '#0f172a'], ['ore', 'Ore', '#0f172a'], ['partecipanti', 'Partecipanti', '#0056B3'], ['presenze', 'Presenze', '#047857'], ['scuole', 'Scuole', '#B30000']] as [$k, $l, $c]): ?>
        <div class="col-6 col-md-4 col-xl-2"><div class="rp-kpi"><div class="v" style="color:<?php echo $c; ?>;"><?php echo $n_fmt($d['totale'][$k] ?? 0); ?></div><div class="l"><?php echo $l; ?></div></div></div>
    <?php endforeach; ?>
</div>

<div class="row g-3">
    <div class="col-xl-8">
        <div class="card border-0 shadow-sm mb-3"><div class="table-responsive"><table class="table table-sm align-middle mb-0 small">
            <thead class="table-light"><tr><th>Ambito</th><th class="text-end">Eventi</th><th class="text-end">Incontri</th><th class="text-end">Ore</th><th class="text-end">Iscrizioni</th><th class="text-end">Partecipanti</th><th class="text-end">Presenze</th><th class="text-end">Scuole</th></tr></thead><tbody>
            <?php foreach ($d['ambiti'] ?: array_fill_keys(array_keys(AMBITI_EVENTO), []) as $k => $a): $A = AMBITI_EVENTO[$k]; ?>
                <tr><td><i class="fa <?php echo $A['icona']; ?> me-1" style="color:<?php echo $A['colore']; ?>;" aria-hidden="true"></i><strong><?php echo $h($A['nome']); ?></strong></td>
                    <?php foreach (['eventi', 'incontri', 'ore', 'iscrizioni', 'partecipanti', 'presenze', 'scuole'] as $c): ?><td class="text-end"><?php echo $n_fmt($a[$c] ?? 0); ?></td><?php endforeach; ?></tr>
            <?php endforeach; ?>
            <tr class="table-light fw-bold"><td>Totale</td><?php foreach (['eventi', 'incontri', 'ore', 'iscrizioni', 'partecipanti', 'presenze', 'scuole'] as $c): ?><td class="text-end"><?php echo $n_fmt($d['totale'][$c] ?? 0); ?></td><?php endforeach; ?></tr>
            </tbody></table></div></div>

        <div class="card border-0 shadow-sm"><div class="card-body">
            <h6 class="fw-bold">Eventi del periodo (<?php echo count($d['eventi']); ?>)</h6>
            <?php if (!$d['eventi']): ?><p class="small text-muted mb-0">Nessun evento con date nel periodo.</p><?php else: ?>
            <div class="table-responsive" style="max-height:520px;overflow:auto;"><table class="table table-sm align-middle small mb-0">
                <thead class="table-light" style="position:sticky;top:0;"><tr><th>Evento</th><th>Ambiti</th><th>Date</th><th class="text-end">Ore</th><th class="text-end">Partecipanti</th><th class="text-end">Presenze</th></tr></thead><tbody>
                <?php foreach ($d['eventi'] as $e): ?>
                    <tr><td><strong><?php echo $h($e['titolo']); ?></strong><div class="text-secondary"><?php echo $h($e['area']); ?><?php echo $e['relatore'] !== '' ? ' · ' . $h($e['relatore']) : ''; ?></div></td>
                        <td><?php echo html_badge_ambiti($e['ambiti']); ?></td>
                        <td class="text-nowrap"><?php echo date('d/m/Y', strtotime($e['prima'])); ?><?php echo $e['ultima'] !== $e['prima'] ? ' – ' . date('d/m/Y', strtotime($e['ultima'])) : ''; ?><div class="text-secondary"><?php echo $e['incontri']; ?> <?php echo $e['incontri'] === 1 ? 'incontro' : 'incontri'; ?></div></td>
                        <td class="text-end"><?php echo $n_fmt($e['ore']); ?></td><td class="text-end"><?php echo $e['partecipanti']; ?><?php echo $e['scuole'] ? '<div class="text-secondary">' . $e['scuole'] . ' scuole</div>' : ''; ?></td><td class="text-end"><?php echo $e['presenze']; ?></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
        </div></div>
    </div>
    <div class="col-xl-4">
        <div class="card border-0 shadow-sm mb-3"><div class="card-body">
            <h6 class="fw-bold">Eventi per mese</h6>
            <?php if (!$d['mesi']): ?><p class="small text-muted mb-0">Nessun dato.</p><?php else: ?>
            <div class="rp-mese" role="img" aria-label="Eventi per mese e ambito">
                <?php foreach ($d['mesi'] as $m => $x): ?>
                    <div class="rp-col" title="<?php echo $h($m . ': ' . implode(', ', array_map(fn($k) => AMBITI_EVENTO[$k]['nome'] . ' ' . $x[$k], array_keys($x)))); ?>" style="height:<?php echo max(4, round(array_sum($x) / $max_m * 100)); ?>%;">
                        <?php foreach (AMBITI_EVENTO as $k => $A): if (empty($x[$k])) continue; ?><span style="flex:<?php echo (int)$x[$k]; ?>;background:<?php echo $A['colore']; ?>;"></span><?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="d-flex justify-content-between small text-secondary mt-1"><span><?php echo $h(array_key_first($d['mesi'])); ?></span><span><?php echo $h(array_key_last($d['mesi'])); ?></span></div>
            <div class="d-flex flex-wrap gap-2 small mt-2"><?php foreach (AMBITI_EVENTO as $A): ?><span><i class="fa fa-square me-1" style="color:<?php echo $A['colore']; ?>;" aria-hidden="true"></i><?php echo $h($A['nome']); ?></span><?php endforeach; ?></div>
            <?php endif; ?>
        </div></div>
        <div class="card border-0 shadow-sm"><div class="card-body">
            <h6 class="fw-bold"><i class="fa fa-envelope-open-text me-1 text-primary" aria-hidden="true"></i>Iscritti agli avvisi per email</h6>
            <p class="small text-secondary">Persone che ricevono un'email quando si pubblica un nuovo evento dei loro argomenti (<a href="../avvisi.php" target="_blank" rel="noopener">pagina di iscrizione</a>).</p>
            <ul class="list-unstyled small mb-0">
                <?php foreach (AMBITI_EVENTO as $k => $A): ?><li class="d-flex justify-content-between border-bottom py-1"><span><i class="fa <?php echo $A['icona']; ?> me-1" style="color:<?php echo $A['colore']; ?>;" aria-hidden="true"></i><?php echo $h($A['nome']); ?></span><strong><?php echo $iscr[$k]; ?></strong></li><?php endforeach; ?>
                <li class="d-flex justify-content-between border-bottom py-1"><span><i class="fa fa-school me-1" aria-hidden="true"></i>Attività per le scuole</span><strong><?php echo $iscr['scuole']; ?></strong></li>
                <li class="d-flex justify-content-between py-1 fw-bold"><span>Iscritti confermati</span><span><?php echo $iscr['']; ?></span></li>
            </ul>
        </div></div>
    </div>
</div>
<?php require_once 'admin_footer.php'; ?>
