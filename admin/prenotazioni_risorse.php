<?php
// admin/prenotazioni_risorse.php - Calendari e risorse: agenda e prenotazioni dell'area (è anche la dashboard
// delle aree di tipo "calendario"). Approvazione, rifiuto e annullamento con email a chi ha prenotato; esportazione CSV.
if (isset($_GET['csv'])) ob_start(); // il CSV scarta la pagina prodotta dall'intestazione
require_once 'admin_header.php';

if (!$is_area_manager || tipo_area($page_cfg) !== 'calendario') {
    echo "<div class='alert alert-warning fw-bold shadow-sm m-4'><i class='fa fa-ban me-2'></i>" . (tipo_area($page_cfg) !== 'calendario' ? "Quest'area non è di tipo Calendari e risorse." : "Non gestisci quest'area.") . "</div>";
    require_once 'admin_footer.php'; exit;
}
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$pid = (int)$filtro_p;
$filtri = [
    'risorsa' => (int)($_GET['risorsa'] ?? 0),
    'stato'   => in_array($_GET['stato'] ?? '', ['confermata', 'da_approvare', 'annullata', 'rifiutata', 'attive'], true) ? $_GET['stato'] : 'attive',
    'periodo' => in_array($_GET['periodo'] ?? '', ['future', 'passate', 'tutte'], true) ? $_GET['periodo'] : 'future',
    'q'       => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80),
];
$qs = fn(array $cambia = []) => 'prenotazioni_risorse.php?' . http_build_query(array_merge(['p_id' => $pid, 'modo' => 'elenco'], array_filter($filtri, fn($v) => $v !== '' && $v !== 0), $cambia));

// ── Azioni: approva / rifiuta / annulla (anche tutta la serie settimanale) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['azione_pren'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $nuovo = ['approva' => 'confermata', 'rifiuta' => 'rifiutata', 'annulla' => 'annullata'][$_POST['azione_pren']] ?? null;
    $id = (int)($_POST['pren_id'] ?? 0);
    $p = $nuovo ? prenotazione_risorsa($conn, $id) : null;
    $fatte = 0;
    if ($p && (int)$p['pagina_id'] === $pid) {
        $nota = mb_substr(trim((string)($_POST['nota'] ?? '')), 0, 500);
        $ids = [$id];
        if (!empty($_POST['tutta_serie']) && $p['serie']) {
            $st = $conn->prepare("SELECT id FROM prenotazioni_risorse WHERE serie = ? AND risorsa_id = ? AND fine >= NOW()");
            $rid = (int)$p['risorsa_id'];
            $st->bind_param("si", $p['serie'], $rid); $st->execute();
            $ids = array_column($st->get_result()->fetch_all(MYSQLI_ASSOC), 'id');
        }
        foreach ($ids as $i) {
            if ($nota !== '') { $st_n = $conn->prepare("UPDATE prenotazioni_risorse SET nota_gestore = ? WHERE id = ?"); $i_n = (int)$i; $st_n->bind_param("si", $nota, $i_n); $st_n->execute(); }
            if (cambia_stato_prenotazione_risorsa($conn, (int)$i, $nuovo, true)) $fatte++;
        }
        registra_log_audit($conn, "Prenotazione risorsa: " . $_POST['azione_pren'], ["Risorsa" => $p['risorsa_nome'], "Codice" => $p['codice'], "Quante" => $fatte]);
    }
    flash_set($fatte ? ($fatte === 1 ? "Prenotazione aggiornata: abbiamo avvisato chi l'ha fatta." : "$fatte prenotazioni aggiornate: abbiamo avvisato chi le ha fatte.") : "Nessuna prenotazione aggiornata (forse era già stata gestita).", $fatte ? 'success' : 'warning');
    echo "<script>window.location.replace(" . json_encode($qs()) . ");</script>"; exit;
}

// ── Elenco filtrato ──
$where = ["r.pagina_id = $pid"]; $par = []; $tipi = '';
if ($filtri['risorsa']) $where[] = "r.id = " . $filtri['risorsa'];
$where[] = match ($filtri['stato']) { 'attive' => "pr.stato IN ('confermata', 'da_approvare')", default => "pr.stato = '" . $filtri['stato'] . "'" };
if ($filtri['periodo'] === 'future') $where[] = "pr.fine >= NOW()";
elseif ($filtri['periodo'] === 'passate') $where[] = "pr.fine < NOW()";
if ($filtri['q'] !== '') {
    $where[] = "(pr.nome LIKE ? OR pr.cognome LIKE ? OR pr.email LIKE ? OR pr.codice LIKE ? OR pr.motivo LIKE ?)";
    $like = '%' . addcslashes($filtri['q'], '%_\\') . '%';
    array_push($par, $like, $like, $like, $like, $like); $tipi .= 'sssss';
}
$sql = "SELECT pr.*, r.nome AS risorsa_nome, r.luogo FROM prenotazioni_risorse pr JOIN risorse r ON r.id = pr.risorsa_id WHERE " . implode(' AND ', $where)
     . " ORDER BY pr.inizio " . ($filtri['periodo'] === 'passate' ? 'DESC' : 'ASC') . " LIMIT 1000";
$st = $conn->prepare($sql);
if ($par) $st->bind_param($tipi, ...$par);
$st->execute();
$righe = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$NOMI_STATO = ['confermata' => ['Confermata', 'success'], 'da_approvare' => ['Da approvare', 'warning'], 'annullata' => ['Annullata', 'secondary'], 'rifiutata' => ['Rifiutata', 'danger']];

if (isset($_GET['csv'])) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="prenotazioni_' . preg_replace('/[^a-z0-9]+/i', '_', $page_cfg['slug']) . '_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Codice', 'Risorsa', 'Data', 'Dalle', 'Alle', 'Cognome', 'Nome', 'Email', 'Motivo', 'Stato', 'Serie', 'Nota gestore', 'Creata il'], ';');
    foreach ($righe as $p) fputcsv($out, [$p['codice'], $p['risorsa_nome'], date('d/m/Y', strtotime($p['inizio'])), date('H:i', strtotime($p['inizio'])), date('H:i', strtotime($p['fine'])),
        $p['cognome'], $p['nome'], $p['email'], $p['motivo'], $NOMI_STATO[$p['stato']][0] ?? $p['stato'], $p['serie'], $p['nota_gestore'], $p['creata_il']], ';');
    exit;
}

$risorse = $conn->query("SELECT id, nome, attiva FROM risorse WHERE pagina_id = $pid ORDER BY ordine, nome")->fetch_all(MYSQLI_ASSOC);
// Vista: calendario (griglia risorse × ore, mese) oppure elenco con filtri
$modo = ($_GET['modo'] ?? 'calendario') === 'elenco' ? 'elenco' : 'calendario';
$kpi = $conn->query("SELECT
        SUM(pr.stato = 'confermata' AND DATE(pr.inizio) = CURDATE()) AS oggi,
        SUM(pr.stato = 'confermata' AND pr.inizio >= NOW() AND pr.inizio < NOW() + INTERVAL 7 DAY) AS settimana,
        SUM(pr.stato = 'da_approvare' AND pr.fine >= NOW()) AS da_approvare
     FROM prenotazioni_risorse pr JOIN risorse r ON r.id = pr.risorsa_id WHERE r.pagina_id = $pid")->fetch_assoc();
// Agenda di oggi
$oggi = $conn->query("SELECT pr.*, r.nome AS risorsa_nome FROM prenotazioni_risorse pr JOIN risorse r ON r.id = pr.risorsa_id
                      WHERE r.pagina_id = $pid AND pr.stato IN ('confermata', 'da_approvare') AND DATE(pr.inizio) = CURDATE() ORDER BY pr.inizio, r.nome")->fetch_all(MYSQLI_ASSOC);
$url_pub = '../' . $page_cfg['slug'] . '.php';
?>
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <h4 class="fw-bold text-dark mb-0"><i class="fa fa-calendar-check me-2 text-primary" aria-hidden="true"></i>Prenotazioni · <?php echo $h($page_cfg['titolo']); ?></h4>
    <div class="d-flex gap-2">
        <a href="<?php echo $h($url_pub); ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm"><i class="fa fa-calendar-plus me-1" aria-hidden="true"></i>Prenota dalla pagina pubblica</a>
        <a href="risorse.php?p_id=<?php echo $pid; ?>" class="btn btn-outline-primary btn-sm"><i class="fa fa-door-open me-1" aria-hidden="true"></i>Risorse e orari</a>
    </div>
</div>

<?php if (!$risorse): ?>
    <div class="card border-0 shadow-sm"><div class="card-body text-center py-5">
        <p class="mb-3">Quest'area non ha ancora risorse prenotabili.</p>
        <a href="risorse.php?p_id=<?php echo $pid; ?>&amp;nuova=1" class="btn btn-primary fw-bold"><i class="fa fa-plus-circle me-1" aria-hidden="true"></i>Crea la prima risorsa</a>
    </div></div>
<?php require_once 'admin_footer.php'; exit; endif; ?>

<ul class="nav nav-pills gap-1 mb-3" style="--bs-nav-pills-link-active-bg:#1e293b;">
    <li class="nav-item"><a class="nav-link fw-bold<?php echo $modo === 'calendario' ? ' active' : ' bg-light text-dark'; ?>" href="prenotazioni_risorse.php?p_id=<?php echo $pid; ?>&amp;modo=calendario"<?php echo $modo === 'calendario' ? ' aria-current="page"' : ''; ?>><i class="fa fa-calendar-days me-1" aria-hidden="true"></i>Calendario</a></li>
    <li class="nav-item"><a class="nav-link fw-bold<?php echo $modo === 'elenco' ? ' active' : ' bg-light text-dark'; ?>" href="prenotazioni_risorse.php?p_id=<?php echo $pid; ?>&amp;modo=elenco"<?php echo $modo === 'elenco' ? ' aria-current="page"' : ''; ?>><i class="fa fa-list me-1" aria-hidden="true"></i>Elenco, approvazioni e CSV</a></li>
</ul>

<?php if ($modo === 'calendario'):
    // Griglia di tutte le risorse dell'area (filtri per tipo e capienza); i gestori vedono chi ha prenotato
    $f_tipo = isset(TIPI_RISORSA[$_GET['tipo_ris'] ?? '']) ? $_GET['tipo_ris'] : '';
    $f_cap = max(0, (int)($_GET['capienza'] ?? 0));
    $ris_cal = $conn->query("SELECT * FROM risorse WHERE pagina_id = $pid ORDER BY ordine, nome")->fetch_all(MYSQLI_ASSOC);
    $ris_cal = array_values(array_filter($ris_cal, fn($r) => ($f_tipo === '' || $r['tipo'] === $f_tipo) && (!$f_cap || (int)$r['capienza'] >= $f_cap)));
    $url_cal = fn(array $c) => 'prenotazioni_risorse.php?' . http_build_query(array_filter(array_merge(['p_id' => $pid, 'modo' => 'calendario', 'vista' => $_GET['vista'] ?? 'settimana', 'data' => $_GET['data'] ?? '', 'tipo_ris' => $f_tipo, 'capienza' => $f_cap ?: ''], $c), fn($v) => $v !== '' && $v !== null));
    echo css_calendario_risorse();
    echo '<div class="card border-0 shadow-sm mb-3"><div class="card-body">';
    echo html_calendario_risorse($conn, $ris_cal, ['vista' => $_GET['vista'] ?? 'settimana', 'data' => $_GET['data'] ?? date('Y-m-d'), 'uid' => (int)$u_id_curr, 'gestore' => true,
        'url' => $url_cal, 'url_risorsa' => fn(int $id, string $g) => $url_pub . '?risorsa=' . $id . '&dal=' . $g,
        'filtri' => ['tipo' => $f_tipo, 'capienza' => $f_cap], 'campi_nascosti' => ['p_id' => $pid, 'modo' => 'calendario']]);
    echo '<p class="small text-secondary mt-2 mb-0"><i class="fa fa-circle-info me-1" aria-hidden="true"></i>Clicca una riga per prenotare la risorsa dalla pagina pubblica; per approvare o annullare usa la scheda Elenco.</p></div></div>';
    require_once 'admin_footer.php'; exit;
endif; ?>

<div class="row g-3 mb-3">
    <?php foreach ([['Oggi', $kpi['oggi'], 'fa-sun', '#0056B3', ''], ['Prossimi 7 giorni', $kpi['settimana'], 'fa-calendar-week', '#047857', ''],
                    ['Da approvare', $kpi['da_approvare'], 'fa-hourglass-half', '#b45309', $qs(['stato' => 'da_approvare', 'periodo' => 'future'])],
                    ['Risorse prenotabili', count(array_filter($risorse, fn($r) => (int)$r['attiva'])), 'fa-door-open', '#7c3aed', 'risorse.php?p_id=' . $pid]] as [$et, $val, $ico, $col, $link]): ?>
    <div class="col-6 col-lg-3">
        <<?php echo $link ? 'a href="' . $h($link) . '"' : 'div'; ?> class="card border-0 shadow-sm text-decoration-none h-100" style="border-left:4px solid <?php echo $col; ?> !important;">
            <div class="card-body py-3 d-flex align-items-center gap-3">
                <i class="fa <?php echo $ico; ?> fs-4" style="color:<?php echo $col; ?>;" aria-hidden="true"></i>
                <div><div class="fs-4 fw-bold text-dark lh-1"><?php echo (int)$val; ?></div><div class="small text-secondary"><?php echo $et; ?></div></div>
            </div>
        </<?php echo $link ? 'a' : 'div'; ?>>
    </div>
    <?php endforeach; ?>
</div>

<?php if ($oggi): ?>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white fw-bold small"><i class="fa fa-sun me-1 text-warning" aria-hidden="true"></i>Agenda di oggi, <?php echo date('d/m/Y'); ?></div>
    <ul class="list-group list-group-flush small">
        <?php foreach ($oggi as $p): ?>
        <li class="list-group-item d-flex gap-3 flex-wrap">
            <strong class="font-monospace"><?php echo date('H:i', strtotime($p['inizio'])) . '–' . date('H:i', strtotime($p['fine'])); ?></strong>
            <span><?php echo $h($p['risorsa_nome']); ?></span>
            <span class="fw-semibold"><?php echo $h(trim($p['cognome'] . ' ' . $p['nome'])); ?></span>
            <?php if ($p['motivo'] !== ''): ?><span class="text-secondary"><?php echo $h($p['motivo']); ?></span><?php endif; ?>
            <?php if ($p['stato'] === 'da_approvare'): ?><span class="badge bg-warning text-dark">Da approvare</span><?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="GET" action="prenotazioni_risorse.php" class="card border-0 shadow-sm mb-3">
    <input type="hidden" name="p_id" value="<?php echo $pid; ?>"><input type="hidden" name="modo" value="elenco">
    <div class="card-body row g-2 align-items-end">
        <div class="col-md-3"><label class="form-label small fw-bold mb-0" for="fRis">Risorsa</label>
            <select class="form-select form-select-sm" id="fRis" name="risorsa"><option value="0">Tutte</option><?php foreach ($risorse as $r): ?><option value="<?php echo (int)$r['id']; ?>"<?php echo $filtri['risorsa'] === (int)$r['id'] ? ' selected' : ''; ?>><?php echo $h($r['nome']); ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label small fw-bold mb-0" for="fSt">Stato</label>
            <select class="form-select form-select-sm" id="fSt" name="stato"><?php foreach (['attive' => 'Attive', 'da_approvare' => 'Da approvare', 'confermata' => 'Confermate', 'annullata' => 'Annullate', 'rifiutata' => 'Rifiutate'] as $k => $n): ?><option value="<?php echo $k; ?>"<?php echo $filtri['stato'] === $k ? ' selected' : ''; ?>><?php echo $n; ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label small fw-bold mb-0" for="fPer">Periodo</label>
            <select class="form-select form-select-sm" id="fPer" name="periodo"><?php foreach (['future' => 'In arrivo', 'passate' => 'Passate', 'tutte' => 'Tutte'] as $k => $n): ?><option value="<?php echo $k; ?>"<?php echo $filtri['periodo'] === $k ? ' selected' : ''; ?>><?php echo $n; ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label small fw-bold mb-0" for="fQ">Cerca</label><input type="search" class="form-control form-control-sm" id="fQ" name="q" value="<?php echo $h($filtri['q']); ?>" placeholder="Nome, email, codice, motivo"></div>
        <div class="col-md-2 d-flex gap-1">
            <button type="submit" class="btn btn-sm btn-primary fw-bold flex-grow-1"><i class="fa fa-filter me-1" aria-hidden="true"></i>Filtra</button>
            <a class="btn btn-sm btn-outline-success" href="<?php echo $h($qs(['csv' => 1])); ?>" title="Esporta CSV" aria-label="Esporta in CSV"><i class="fa fa-file-csv" aria-hidden="true"></i></a>
        </div>
    </div>
</form>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 small">
            <thead class="table-light"><tr><th>Quando</th><th>Risorsa</th><th>Chi</th><th>Motivo</th><th>Stato</th><th class="text-end">Azioni</th></tr></thead>
            <tbody>
            <?php if (!$righe): ?><tr><td colspan="6" class="text-center text-muted py-4">Nessuna prenotazione con questi filtri.</td></tr><?php endif; ?>
            <?php foreach ($righe as $p): [$n_st, $c_st] = $NOMI_STATO[$p['stato']] ?? [$p['stato'], 'secondary']; $futura = strtotime($p['fine']) >= time(); ?>
                <tr>
                    <td class="text-nowrap"><strong><?php echo $h(quando_risorsa($p)); ?></strong><div class="text-muted font-monospace" style="font-size:.7rem;"><?php echo $h($p['codice']); ?><?php echo $p['serie'] ? ' · serie ' . $h($p['serie']) : ''; ?></div></td>
                    <td><?php echo $h($p['risorsa_nome']); ?></td>
                    <td><?php echo $h(trim($p['cognome'] . ' ' . $p['nome'])); ?><div><a href="mailto:<?php echo $h($p['email']); ?>" class="text-decoration-none"><?php echo $h($p['email']); ?></a></div></td>
                    <td style="max-width:260px;"><?php echo $h($p['motivo']); ?><?php if ($p['nota_gestore'] !== ''): ?><div class="text-secondary fst-italic">Nota: <?php echo $h($p['nota_gestore']); ?></div><?php endif; ?></td>
                    <td><span class="badge bg-<?php echo $c_st; ?><?php echo $c_st === 'warning' ? ' text-dark' : ''; ?>"><?php echo $n_st; ?></span></td>
                    <td class="text-end">
                        <?php if ($futura && in_array($p['stato'], ['confermata', 'da_approvare'], true)): ?>
                        <form method="POST" action="prenotazioni_risorse.php?p_id=<?php echo $pid; ?>" class="d-inline-flex gap-1 flex-wrap justify-content-end align-items-center">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="pren_id" value="<?php echo (int)$p['id']; ?>">
                            <input type="text" name="nota" class="form-control form-control-sm" style="width:130px;" placeholder="Nota (facolt.)" aria-label="Nota per chi ha prenotato" maxlength="500">
                            <?php if ($p['serie']): ?><label class="small text-nowrap"><input type="checkbox" name="tutta_serie" value="1" class="form-check-input me-1">tutta la serie</label><?php endif; ?>
                            <?php if ($p['stato'] === 'da_approvare'): ?>
                                <button type="submit" name="azione_pren" value="approva" class="btn btn-sm btn-success fw-bold" title="Approva"><i class="fa fa-check" aria-hidden="true"></i><span class="visually-hidden">Approva</span></button>
                                <button type="submit" name="azione_pren" value="rifiuta" class="btn btn-sm btn-outline-danger" title="Rifiuta" data-confirm="Rifiutare la richiesta? Chi l'ha fatta riceverà un'email."><i class="fa fa-xmark" aria-hidden="true"></i><span class="visually-hidden">Rifiuta</span></button>
                            <?php else: ?>
                                <button type="submit" name="azione_pren" value="annulla" class="btn btn-sm btn-outline-danger" title="Annulla" data-confirm="Annullare la prenotazione? Chi l'ha fatta riceverà un'email."><i class="fa fa-ban" aria-hidden="true"></i><span class="visually-hidden">Annulla</span></button>
                            <?php endif; ?>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once 'admin_footer.php'; ?>
