<?php
// diario_bordo.php - Diario di bordo di auto e mezzi: si compila dopo l'uso (chi ha prenotato, un altro conducente indicato o un responsabile della risorsa).
// I campi li decide l'admin per ogni risorsa (Risorse → Diario di bordo). Logica in src/Risorse/ServizioDiarioBordo.php.
require_once __DIR__ . '/middleware.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$svc = \App\Core\App::per($conn)->get(\App\Risorse\ServizioDiarioBordo::class);
$pren = prenotazione_risorsa($conn, preg_replace('/[^A-Z0-9-]/', '', strtoupper((string)($_GET['c'] ?? ''))));
$ris = $pren ? risorsa($conn, (int)$pren['risorsa_id']) : null;
$ok = $pren && $ris && (int)$ris['diario_attivo'] === 1 && $svc->puoCompilare($pren, $user_info);
$resp = $ok && $svc->eResponsabile($pren, $user_info);

if ($ok && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    if (isset($_POST['verifica_diario'])) {
        $err = $svc->verifica($pren, $user_info);
        flash_set($err ?? "Diario verificato.", $err ? 'danger' : 'success');
    } else {
        $err = $svc->salva($pren, $user_info, $_POST);
        if (!$err) registra_log_audit($conn, "Diario di bordo compilato", ["Risorsa" => $ris['nome'], "Prenotazione" => $pren['codice']]);
        flash_set($err ?? "Diario di bordo salvato. Grazie!", $err ? 'danger' : 'success');
    }
    header('Location: diario_bordo.php?c=' . urlencode((string)$pren['codice']) . '&r=' . time()); exit;
}

$page_cfg['titolo'] = 'Diario di bordo';
require_once 'header.php';
?>
<div class="container my-4" style="max-width: 820px;">
    <?php echo flash_html(); ?>
    <?php if (!$ok): ?>
        <h1 class="fw-bold h3">Diario di bordo non disponibile</h1>
        <p>Il diario si compila dalla prenotazione di chi ha usato il mezzo (o da un altro conducente indicato o da un responsabile della risorsa). Se pensi di averne diritto, scrivi al responsabile.</p>
        <a class="btn btn-outline-secondary" href="area_personale.php">Area personale</a>
    <?php else:
        [$campi_d] = $svc->campi($ris);
        $valori = $svc->valoriIniziali($ris, $pren);
        $d = $svc->diarioDi((int)$pren['id']);
        $ultimi = $svc->ultimiKm($pren);
        $altri = \App\Risorse\ServizioDiarioBordo::coConducenti($pren);
        $km_ruolo = array_flip(($svc->campi($ris))[1]);
    ?>
        <style>
        .db-testata { background: linear-gradient(135deg, #0f2a4a, #0056B3); color: #fff; border-radius: 14px; padding: 18px 20px; }
        .db-testata .chip { background: rgba(255,255,255,.16); border-radius: 999px; padding: 3px 12px; font-size: .8rem; display: inline-block; margin: 2px 4px 2px 0; }
        .db-card { border: 0; border-radius: 14px; box-shadow: 0 1px 6px rgba(15,23,42,.08); }
        .db-km { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; border-radius: 10px; padding: 8px 12px; font-size: .9rem; }
        </style>
        <div class="db-testata mb-3">
            <div class="small opacity-75"><i class="fa fa-book-open me-1" aria-hidden="true"></i>Diario di bordo</div>
            <h1 class="h4 fw-bold mb-1"><?php echo $h($ris['nome']); ?></h1>
            <div>
                <span class="chip"><i class="fa fa-calendar me-1" aria-hidden="true"></i><?php echo $h(\App\Risorse\Prenotazioni::quando($pren)); ?></span>
                <span class="chip"><i class="fa fa-user me-1" aria-hidden="true"></i><?php echo $h(trim($pren['nome'] . ' ' . $pren['cognome'])); ?></span>
                <?php foreach ($altri as $c_a): ?><span class="chip"><i class="fa fa-user-group me-1" aria-hidden="true"></i><?php echo $h($c_a['nome']); ?></span><?php endforeach; ?>
                <span class="chip font-monospace"><?php echo $h($pren['codice']); ?></span>
            </div>
        </div>
        <?php if ($d): ?>
            <div class="alert alert-<?php echo $d['verificato_il'] ? 'success' : 'info'; ?> small"><i class="fa fa-circle-check me-1" aria-hidden="true"></i>Diario compilato da <strong><?php echo $h($d['compilato_da']); ?></strong> il <?php echo date('d/m/Y H:i', strtotime($d['modificato_il'] ?: $d['compilato_il'])); ?><?php echo $d['km_percorsi'] !== null ? ' · <strong>' . (int)$d['km_percorsi'] . ' km</strong> percorsi' : ''; ?>.
                <?php echo $d['verificato_il'] ? ' Verificato da ' . $h($d['verificato_da']) . '.' : ' Puoi correggerlo finché il responsabile non lo verifica.'; ?></div>
        <?php endif; ?>
        <form method="POST" class="card db-card p-3 p-md-4" id="fDiario"><?php csrf_field(); ?>
            <?php if ($ultimi !== null && isset($km_ruolo['km_iniziali'])): ?><div class="db-km mb-3"><i class="fa fa-gauge-high me-1" aria-hidden="true"></i>Ultimi km finali registrati: <strong><?php echo (int)$ultimi; ?></strong>. I km iniziali non possono essere inferiori.</div><?php endif; ?>
            <?php echo html_datalist_didattica($conn, $campi_d); ?>
            <div class="row gx-4">
                <?php foreach ($campi_d as $c): echo html_campo_pratica($c, $valori[$c['nome']] ?? '', $conn, true); endforeach; ?>
            </div>
            <?php if (isset($km_ruolo['km_iniziali'], $km_ruolo['km_finali'])): ?><div class="fw-bold mb-2" id="dbPercorsi" aria-live="polite"></div><?php endif; ?>
            <div class="d-flex flex-wrap gap-2 mt-2">
                <button type="submit" class="btn btn-success fw-bold btn-lg" name="salva_diario" value="1"><i class="fa fa-save me-1" aria-hidden="true"></i><?php echo $d ? 'Aggiorna il diario' : 'Salva il diario'; ?></button>
                <?php if ($resp && $d && !$d['verificato_il']): ?><button type="submit" class="btn btn-outline-success" name="verifica_diario" value="1" formnovalidate><i class="fa fa-check-double me-1" aria-hidden="true"></i>Verificato</button><?php endif; ?>
                <a class="btn btn-outline-secondary" href="<?php echo $resp ? 'risorse_responsabile.php#ris' . (int)$ris['id'] : 'area_personale.php'; ?>">Indietro</a>
            </div>
        </form>
        <?php if (isset($km_ruolo['km_iniziali'], $km_ruolo['km_finali'])): ?>
        <script>
        (function () {
            var ki = document.querySelector('[name="campo_<?php echo $h($km_ruolo['km_iniziali']); ?>"]'), kf = document.querySelector('[name="campo_<?php echo $h($km_ruolo['km_finali']); ?>"]'), out = document.getElementById('dbPercorsi');
            function agg() { var a = parseFloat(ki.value), b = parseFloat(kf.value); out.innerHTML = (!isNaN(a) && !isNaN(b)) ? (b >= a ? '<i class="fa fa-road me-1" aria-hidden="true"></i>Km percorsi: ' + Math.round(b - a) : '<span class="text-danger">I km finali sono inferiori a quelli iniziali.</span>') : ''; }
            [ki, kf].forEach(function (x) { if (x) x.addEventListener('input', agg); }); agg();
        })();
        </script>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php require_once 'footer.php'; ?>
