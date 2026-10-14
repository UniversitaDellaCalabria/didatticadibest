<?php
// risorse_cruscotto.php - Cruscotto di una risorsa (Area personale): per il responsabile (e gli amministratori). Prenotazioni e utilizzo in un periodo, fasce più
// richieste, chi la usa di più, quantità consumate, km e consumi del diario di bordo, scadenze (bollo, assicurazione…) con il rinnovo. Logica in src/Risorse/CruscottoRisorsa.php.
require_once __DIR__ . '/middleware.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$cru = \App\Core\App::per($conn)->get(\App\Risorse\CruscottoRisorsa::class);
$rid = (int)($_GET['r'] ?? $_POST['risorsa_id'] ?? 0);
$ris = $rid ? risorsa($conn, $rid) : null;
$ok = $ris && $cru->puoVedere($user_info, $rid);
$scelta = array_key_exists($_GET['p'] ?? '', \App\Risorse\CruscottoRisorsa::PERIODI) ? (string)$_GET['p'] : '30';
[$dal, $al, $etichetta] = \App\Risorse\CruscottoRisorsa::periodo($scelta, (string)($_GET['dal'] ?? ''), (string)($_GET['al'] ?? ''));
$url = 'risorse_cruscotto.php?r=' . $rid . '&p=' . urlencode($scelta) . ($scelta === 'altro' ? '&dal=' . urlencode($dal) . '&al=' . urlencode($al) : '');

if ($ok && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rinnova_scadenza'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $err = \App\Core\App::per($conn)->get(\App\Risorse\ServizioScadenzeRisorsa::class)->rinnova($rid, (int)$_POST['rinnova_scadenza'], (string)($_POST['nuova_data'] ?? ''));
    if (!$err) registra_log_audit($conn, "Risorsa: scadenza rinnovata", ["Risorsa" => $ris['nome'], "Scadenza" => (int)$_POST['rinnova_scadenza']]);
    flash_set($err ?? "Scadenza rinnovata: l'avviso si è azzerato.", $err ? 'danger' : 'success');
    header('Location: ' . $url . '&r2=' . time() . '#scadenze'); exit;
}
if ($ok && isset($_GET['xlsx']) && ($f_x = $cru->esporta($ris, $dal, $al))) {
    while (ob_get_level() > 0) ob_end_clean();
    invia_file_scaricabile($f_x, 'Cruscotto_' . preg_replace('/[^A-Za-z0-9]+/', '_', (string)$ris['nome']) . '_' . date('Ymd') . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
}

$page_cfg['titolo'] = 'Cruscotto della risorsa';
require_once 'header.php';
?>
<div class="container my-4" style="max-width: 1100px;">
<?php if (!$ok): ?>
    <h1 class="fw-bold h3">Cruscotto non disponibile</h1><p>Il cruscotto è per i responsabili della risorsa. Se pensi di averne diritto, scrivi all'amministratore del portale.</p>
    <a class="btn btn-outline-secondary" href="area_personale.php">Area personale</a>
<?php else:
    $d = $cru->dati($ris, $dal, $al);
    $n = $d['conteggi'];
    $col_sc = ['ok' => ['success', 'in regola'], 'in_scadenza' => ['warning', 'in scadenza'], 'scaduta' => ['danger', 'scaduta']];
    $ore_max = $d['fasce'] ? max(1, ...array_map(fn($g) => max($g), $d['fasce'])) : 1;
    $h_da = 7; $h_a = 20;
    foreach ($d['fasce'] as $gg) foreach (array_keys($gg) as $oo) { $h_da = min($h_da, $oo); $h_a = max($h_a, $oo); }
    $mese_max = $d['mesi'] ? max(array_column($d['mesi'], 'ore')) : 1;
?>
    <style>
    .cr-kpi { border: 0; border-radius: 14px; box-shadow: 0 1px 6px rgba(15,23,42,.08); }
    .cr-kpi .num { font-size: 1.9rem; font-weight: 800; line-height: 1.1; }
    .cr-heat td { width: 34px; height: 28px; text-align: center; font-size: .7rem; border: 1px solid #fff; }
    .cr-bar { height: 10px; border-radius: 5px; background: #e2e8f0; overflow: hidden; } .cr-bar > i { display: block; height: 100%; background: #0f766e; }
    </style>
    <nav aria-label="Percorso" class="mb-3 small"><a href="area_personale.php">Area personale</a> <span class="text-secondary mx-1">/</span> <a href="risorse_responsabile.php">Le mie risorse</a> <span class="text-secondary mx-1">/</span> <span class="text-secondary">Cruscotto</span></nav>
    <div class="d-flex flex-wrap gap-2 align-items-end mb-3">
        <div><h1 class="fw-bold h3 mb-0"><i class="fa fa-chart-line me-2" style="color:#0f766e;" aria-hidden="true"></i><?php echo $h($ris['nome']); ?></h1>
            <div class="text-secondary small"><?php echo $h($etichetta); ?> · <?php echo $h(\App\Risorse\Costanti::TIPI[$ris['tipo']][0] ?? ''); ?><?php echo $ris['luogo'] !== '' ? ' · ' . $h($ris['luogo']) : ''; ?></div></div>
        <form method="GET" class="d-flex flex-wrap gap-2 align-items-end ms-auto"><input type="hidden" name="r" value="<?php echo $rid; ?>">
            <select class="form-select form-select-sm w-auto" name="p" id="crP" aria-label="Periodo"><?php foreach (\App\Risorse\CruscottoRisorsa::PERIODI as $k => $nome_p): ?><option value="<?php echo $k; ?>"<?php echo $scelta === (string)$k ? ' selected' : ''; ?>><?php echo $h($nome_p); ?></option><?php endforeach; ?></select>
            <span class="gap-1 <?php echo $scelta === 'altro' ? 'd-flex' : 'd-none'; ?>" id="crAltro"><input type="date" class="form-control form-control-sm" name="dal" value="<?php echo $h($dal); ?>" aria-label="Dal"><input type="date" class="form-control form-control-sm" name="al" value="<?php echo $h($al); ?>" aria-label="Al"></span>
            <button class="btn btn-sm btn-primary fw-bold" type="submit">Mostra</button>
            <a class="btn btn-sm btn-outline-success" href="<?php echo $h($url . '&xlsx=1'); ?>"><i class="fa fa-file-excel me-1" aria-hidden="true"></i>Excel</a>
        </form>
    </div>
    <script>document.getElementById('crP').addEventListener('change', function () { document.getElementById('crAltro').className = 'gap-1 ' + (this.value === 'altro' ? 'd-flex' : 'd-none'); });</script>
    <?php echo flash_html(); ?>

    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3"><div class="card cr-kpi h-100"><div class="card-body"><div class="text-secondary small">Prenotazioni confermate</div><div class="num"><?php echo (int)$n['confermata']; ?></div>
            <div class="small text-secondary"><?php echo (int)$n['da_approvare'] ? (int)$n['da_approvare'] . ' da approvare · ' : ''; ?><?php echo (int)$n['annullata']; ?> annullate · <?php echo (int)$n['rifiutata']; ?> rifiutate</div></div></div></div>
        <div class="col-6 col-md-3"><div class="card cr-kpi h-100"><div class="card-body"><div class="text-secondary small">Ore prenotate</div><div class="num"><?php echo $h(str_replace('.', ',', (string)$d['ore'])); ?></div><div class="small text-secondary">su <?php echo $h(str_replace('.', ',', (string)$d['ore_aperte'])); ?> ore di apertura</div></div></div></div>
        <div class="col-6 col-md-3"><div class="card cr-kpi h-100"><div class="card-body"><div class="text-secondary small">Utilizzo</div><div class="num"><?php echo $d['utilizzo'] !== null ? (int)$d['utilizzo'] . '%' : '—'; ?></div>
            <div class="cr-bar mt-1"><i style="width:<?php echo min(100, (int)($d['utilizzo'] ?? 0)); ?>%"></i></div></div></div></div>
        <div class="col-6 col-md-3"><div class="card cr-kpi h-100"><div class="card-body"><div class="text-secondary small">Richieste rifiutate</div><div class="num"><?php echo $d['rifiutate_pct'] !== null ? (int)$d['rifiutate_pct'] . '%' : '—'; ?></div><div class="small text-secondary"><?php echo $d['rifiutate_pct'] !== null ? 'tra quelle decise' : 'la risorsa non richiede approvazione'; ?></div></div></div></div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-7"><div class="card cr-kpi h-100"><div class="card-body">
            <h2 class="h6 fw-bold">Quando è più richiesta</h2>
            <?php if (!$d['fasce']): ?><p class="small text-muted mb-0">Nessuna prenotazione confermata nel periodo.</p><?php else: ?>
            <div class="table-responsive"><table class="cr-heat"><thead><tr><th></th><?php for ($o = $h_da; $o <= $h_a; $o++): ?><th class="small text-secondary fw-normal"><?php echo $o; ?></th><?php endfor; ?></tr></thead><tbody>
                <?php foreach (\App\Risorse\Costanti::GIORNI as $g => $nome_g): ?>
                <tr><th class="small text-secondary fw-normal pe-2 text-nowrap"><?php echo $h(mb_substr($nome_g, 0, 3)); ?></th>
                <?php for ($o = $h_da; $o <= $h_a; $o++): $v = $d['fasce'][$g][$o] ?? 0; $a = $v / $ore_max; ?><td style="background:rgba(15,118,110,<?php echo round(0.08 + $a * .85, 2); ?>);color:<?php echo $a > .55 ? '#fff' : '#334155'; ?>;<?php echo $v ? '' : 'background:#f1f5f9;'; ?>" title="<?php echo $h($nome_g . ' ore ' . $o . ': ' . $v); ?>"><?php echo $v ?: ''; ?></td><?php endfor; ?></tr>
                <?php endforeach; ?></tbody></table></div>
            <?php endif; ?>
        </div></div></div>
        <div class="col-lg-5"><div class="card cr-kpi h-100"><div class="card-body">
            <h2 class="h6 fw-bold">Chi la usa di più</h2>
            <?php if (!$d['utenti']): ?><p class="small text-muted mb-0">Nessuna prenotazione confermata nel periodo.</p><?php else: ?>
            <table class="table table-sm small align-middle mb-0"><thead class="table-light"><tr><th>Persona</th><th class="text-end">Prenotazioni</th><th class="text-end">Ore</th></tr></thead><tbody>
            <?php foreach ($d['utenti'] as $u): ?><tr><td><?php echo $h($u['nome']); ?></td><td class="text-end"><?php echo (int)$u['prenotazioni']; ?></td><td class="text-end"><?php echo $h(str_replace('.', ',', (string)round($u['ore'], 1))); ?></td></tr><?php endforeach; ?></tbody></table>
            <?php endif; ?>
        </div></div></div>
    </div>

    <?php if ($d['mesi']): ?>
    <div class="card cr-kpi mb-3"><div class="card-body"><h2 class="h6 fw-bold">Mese per mese (ore prenotate)</h2>
        <?php foreach ($d['mesi'] as $m => $x): ?><div class="d-flex align-items-center gap-2 small mb-1"><span style="width:72px;" class="text-secondary"><?php echo $h(date('m/Y', strtotime($m . '-01'))); ?></span>
            <div class="cr-bar flex-grow-1"><i style="width:<?php echo (int)round($x['ore'] * 100 / max(1, $mese_max)); ?>%"></i></div><span style="width:120px;" class="text-end"><?php echo (int)$x['prenotazioni']; ?> pren. · <?php echo $h(str_replace('.', ',', (string)round($x['ore'], 1))); ?> h</span></div><?php endforeach; ?>
    </div></div>
    <?php endif; ?>

    <div class="row g-3 mb-3">
        <?php if ($d['contatori']): ?>
        <div class="col-lg-6"><div class="card cr-kpi h-100"><div class="card-body"><h2 class="h6 fw-bold"><i class="fa fa-gauge-high me-1" style="color:#b45309;" aria-hidden="true"></i>Quantità prenotate</h2>
            <?php foreach ($d['contatori'] as $c): ?><div class="d-flex justify-content-between small border-bottom py-1"><span><?php echo $h($c['nome']); ?></span>
                <span><strong><?php echo (int)$c['usato']; ?></strong> <?php echo $h($c['unita']); ?><?php echo $c['saldo'] !== null ? ' · scorta rimasta <strong>' . (int)$c['saldo'] . '</strong>' : ($c['capacita'] ? ' · capacità ' . (int)$c['capacita'] . ' ' . $h(\App\Risorse\ServizioContatoriRisorse::PERIODI[$c['periodo']] ?? '') : ''); ?></span></div><?php endforeach; ?>
        </div></div></div>
        <?php endif; ?>
        <?php if ($d['diario']): $dg = $d['diario']; ?>
        <div class="col-lg-6"><div class="card cr-kpi h-100"><div class="card-body"><h2 class="h6 fw-bold"><i class="fa fa-book-open me-1" style="color:#0369a1;" aria-hidden="true"></i>Diario di bordo</h2>
            <div class="row text-center g-2">
                <div class="col-4"><div class="num" style="font-size:1.5rem;font-weight:800;"><?php echo (int)$dg['viaggi']; ?></div><div class="small text-secondary">viaggi</div></div>
                <div class="col-4"><div class="num" style="font-size:1.5rem;font-weight:800;"><?php echo (int)$dg['km']; ?></div><div class="small text-secondary">km</div></div>
                <div class="col-4"><div class="num" style="font-size:1.5rem;font-weight:800;"><?php echo $h(number_format($dg['litri'], 1, ',', '.')); ?></div><div class="small text-secondary">litri</div></div>
            </div>
            <div class="small mt-2"><?php echo $dg['consumo'] !== null ? 'Consumo medio <strong>' . $h(str_replace('.', ',', (string)$dg['consumo'])) . ' l/100 km</strong>' : 'Consumo non calcolabile (mancano i litri)'; ?><?php echo $dg['km_medi'] !== null ? ' · ' . (int)$dg['km_medi'] . ' km a viaggio' : ''; ?>.
                <?php if ((int)$dg['senza_diario']): ?><div class="text-danger fw-bold mt-1"><?php echo (int)$dg['senza_diario']; ?> viaggi senza diario.</div><?php endif; ?></div>
        </div></div></div>
        <?php endif; ?>
    </div>

    <div class="card cr-kpi mb-3" id="scadenze"><div class="card-body"><h2 class="h6 fw-bold"><i class="fa fa-bell me-1 text-danger" aria-hidden="true"></i>Scadenze</h2>
        <?php if (!$d['scadenze']): ?><p class="small text-muted mb-0">Nessuna scadenza inserita: l'amministratore le aggiunge dalla scheda della risorsa (bollo, assicurazione, tagliando…).</p>
        <?php else: foreach ($d['scadenze'] as $sc): [$cl_s, $tx_s] = $col_sc[$sc['stato']]; ?>
            <form method="POST" class="d-flex flex-wrap gap-2 align-items-center border-bottom py-2 small"><?php csrf_field(); ?><input type="hidden" name="risorsa_id" value="<?php echo $rid; ?>">
                <strong style="min-width:130px;"><?php echo $h($sc['nome']); ?></strong>
                <span><?php echo date('d/m/Y', strtotime($sc['scade_il'])); ?></span>
                <span class="badge text-bg-<?php echo $cl_s; ?>"><?php echo $tx_s; ?><?php echo $sc['stato'] === 'ok' ? '' : ($sc['giorni'] < 0 ? ' da ' . abs((int)$sc['giorni']) . ' giorni' : ' tra ' . (int)$sc['giorni'] . ' giorni'); ?></span>
                <?php if (trim((string)$sc['note']) !== ''): ?><span class="text-secondary"><?php echo $h($sc['note']); ?></span><?php endif; ?>
                <span class="ms-auto d-flex gap-1 align-items-center"><input type="date" class="form-control form-control-sm" name="nuova_data" required aria-label="Nuova scadenza di <?php echo $h($sc['nome']); ?>"><button type="submit" name="rinnova_scadenza" value="<?php echo (int)$sc['id']; ?>" class="btn btn-sm btn-outline-success fw-bold">Rinnovata</button></span>
            </form>
        <?php endforeach; endif; ?>
    </div></div>
<?php endif; ?>
</div>
<?php require_once 'footer.php'; ?>
