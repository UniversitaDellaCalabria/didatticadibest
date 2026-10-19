<?php
// verbali.php - Pagina pubblica dei verbali con password: per chi non ha le credenziali di Ateneo. Si entra con la password data
// dall'Ufficio didattico (sessione separata dal login SSO) e si scaricano solo i verbali firmati e pubblicabili dei consigli
// dell'accesso. Con la sola password i verbali sono in SOLA VISUALIZZAZIONE (il PDF è disegnato nella pagina, con una filigrana, senza pulsanti di
// scaricamento o stampa); scarica solo chi si è autenticato con le credenziali di Ateneo ed è stato abilitato dall'Ufficio didattico (Gestione del portale →
// Uffici → «Download dei verbali», persone scelte dall'anagrafe) o è amministratore. Non indicizzabile; ogni accesso, lettura e scaricamento si registra.
// Logica in App\Didattica\ServizioVerbaliPubblici.
// Pagina per chi entra con una password (non con le credenziali di Ateneo): niente controllo dell'accesso SSO, che per un visitatore anonimo
// usa la sessione di SimpleSAML e può far perdere quella del portale (token del modulo non valido: «problema di sessione»).
define('PAGINA_SENZA_SSO', true);
require_once 'config.php';
require_once 'functions.php';

header('X-Robots-Tag: noindex, nofollow, noarchive');
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$serv = \App\Core\App::per($conn)->get(\App\Didattica\ServizioVerbaliPubblici::class);
$errore = null;

if (isset($_GET['esci'])) { $serv->esci(); header('Location: verbali.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accedi'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $errore = $serv->accedi((string)($_POST['password'] ?? ''));
    unset($_POST['password']);
    if (!$errore) { header('Location: verbali.php'); exit; }
}
// Credenziali di Ateneo (login separato dalla password): chi è abilitato al download scarica; chi entra solo con la password vede i verbali senza scaricarli
$utente_sso = !empty($_SESSION['utente_id']) ? \App\Core\App::get(\App\Auth\UtenteRepository::class)->perId((int)$_SESSION['utente_id']) : null;
$puo_scaricare = $serv->puoScaricare($utente_sso);
$accesso = $serv->corrente() ?? $serv->accessoDaCredenziali($utente_sso);
$url_accedi_sso = 'saml_login.php?redirect=' . urlencode('verbali.php');

// Scaricamento: solo chi è abilitato (credenziali di Ateneo)
if ($accesso && isset($_GET['d'])) {
    if (!$puo_scaricare) { http_response_code(403); exit('Il download non è consentito con questo accesso: i verbali si consultano in sola visualizzazione.'); }
    $f = $serv->file($accesso, (int)$_GET['d'], 'scarica');
    if (!$f) { http_response_code(404); exit('Verbale non disponibile.'); }
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf'); header('X-Content-Type-Options: nosniff'); header('Content-Length: ' . filesize($f['percorso']));
    header('Content-Disposition: attachment; filename="' . $f['nome'] . '"');
    readfile($f['percorso']); exit;
}
// Lettura del PDF da parte del visualizzatore: permesso monouso, mai aprire l'indirizzo direttamente, niente cache
if ($accesso && isset($_GET['f'])) {
    $id_f = (int)$_GET['f'];
    $dest = strtolower((string)($_SERVER['HTTP_SEC_FETCH_DEST'] ?? ''));
    if (in_array($dest, ['document', 'iframe', 'embed', 'object', 'frame'], true) || !$serv->consumaToken((string)($_GET['t'] ?? ''), $id_f)) { http_response_code(403); exit('Non disponibile.'); }
    $f = $serv->file($accesso, $id_f, 'visualizza');
    if (!$f) { http_response_code(404); exit('Verbale non disponibile.'); }
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf'); header('X-Content-Type-Options: nosniff'); header('Content-Length: ' . filesize($f['percorso']));
    header('Cache-Control: no-store, no-cache, must-revalidate, private'); header('Pragma: no-cache'); header('Content-Disposition: inline');
    readfile($f['percorso']); exit;
}
// Visualizzatore: il PDF è disegnato nella pagina (PDF.js), con filigrana e senza pulsanti di scaricamento o stampa
if ($accesso && isset($_GET['v'])) {
    $id_v = (int)$_GET['v'];
    $dv = $serv->dati($accesso, $id_v);
    if (!$dv) { http_response_code(404); exit('Verbale non disponibile.'); }
    $token_v = $serv->tokenVista($id_v);
    $titolo_v = 'Verbale' . ($dv['numero_verbale'] !== '' ? ' n. ' . $dv['numero_verbale'] : '') . ($dv['data'] ? ' del ' . date('d/m/Y', strtotime($dv['data'])) : '');
    $filigrana = 'Consultazione riservata · ' . $accesso['etichetta'] . ' · ' . date('d/m/Y H:i');
    $vend = 'assets/vendor/cdnjs/ajax/libs/pdf.js/3.11.174/';
    header('Cache-Control: no-store, private'); header('X-Frame-Options: DENY');
    ?><!doctype html>
<html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow, noarchive">
<title><?php echo $h($titolo_v); ?></title>
<style>
body { margin:0; background:#475569; font-family:system-ui,sans-serif; -webkit-user-select:none; user-select:none; }
.vv-barra { position:sticky; top:0; z-index:5; background:#1e293b; color:#fff; padding:.55rem 1rem; display:flex; flex-wrap:wrap; gap:.6rem; align-items:center; }
.vv-barra a { color:#fff; text-decoration:none; background:#334155; padding:.3rem .7rem; border-radius:.4rem; font-size:.9rem; }
.vv-barra a:hover { background:#475569; }
.vv-barra .vv-nota { margin-left:auto; font-size:.8rem; color:#cbd5e1; }
#vvPagine { padding:1rem 0 2rem; display:flex; flex-direction:column; align-items:center; gap:1rem; }
#vvPagine canvas { max-width:96vw; height:auto !important; background:#fff; box-shadow:0 2px 10px rgba(0,0,0,.4); pointer-events:none; }
#vvStato { color:#fff; text-align:center; padding:2rem 1rem; }
@media print { body * { display:none !important; } body::after { content:'La stampa non è consentita.'; display:block; padding:2rem; } }
</style></head>
<body oncontextmenu="return false" ondragstart="return false">
<div class="vv-barra">
    <a href="verbali.php">&larr; Elenco dei verbali</a><strong><?php echo $h($titolo_v); ?></strong>
    <?php if ($puo_scaricare): ?><a href="verbali.php?d=<?php echo $id_v; ?>" style="background:#15803d;">Scarica il PDF</a>
    <?php else: ?><span class="vv-nota">Sola visualizzazione: il download è riservato alle persone abilitate (accesso con le credenziali di Ateneo).</span><?php endif; ?>
</div>
<div id="vvStato">Caricamento del verbale…</div>
<div id="vvPagine" aria-label="Pagine del verbale"></div>
<script src="<?php echo $h($vend); ?>pdf.min.js"></script>
<script>
(function () {
    var stato = document.getElementById('vvStato'), box = document.getElementById('vvPagine');
    var testo = <?php echo json_encode($filigrana, JSON_UNESCAPED_UNICODE); ?>;
    document.addEventListener('keydown', function (e) { var k = (e.key || '').toLowerCase(); if ((e.ctrlKey || e.metaKey) && ['s', 'p', 'u', 'c', 'a'].indexOf(k) !== -1) { e.preventDefault(); } });
    document.addEventListener('copy', function (e) { e.preventDefault(); });
    if (typeof pdfjsLib === 'undefined') { stato.textContent = 'Il visualizzatore non è disponibile (file mancante sul server).'; return; }
    pdfjsLib.GlobalWorkerOptions.workerSrc = <?php echo json_encode($vend . 'pdf.worker.min.js'); ?>;
    function filigrana(ctx, w, h) {
        ctx.save(); ctx.globalAlpha = 0.13; ctx.fillStyle = '#7f1d1d'; ctx.font = Math.round(w / 40) + 'px sans-serif';
        ctx.rotate(-Math.PI / 6);
        var larg = ctx.measureText(testo).width + w / 10, passo = Math.round(w / 6);
        for (var y = -h; y < h * 1.6; y += passo) { for (var x = -w, k = 0; x < w * 1.6; x += larg, k++) { ctx.fillText(testo, x + (Math.round(y / passo) % 2 ? larg / 2 : 0), y); } }
        ctx.restore();
    }
    pdfjsLib.getDocument({ url: <?php echo json_encode('verbali.php?f=' . $id_v . '&t=' . $token_v); ?>, disableRange: true, disableStream: true, disableAutoFetch: true }).promise.then(function (pdf) {
        stato.textContent = '';
        var scala = Math.min(2.2, Math.max(1.4, (window.devicePixelRatio || 1) * 1.4));
        var vai = function (n) {
            if (n > pdf.numPages) return;
            pdf.getPage(n).then(function (pagina) {
                var vp = pagina.getViewport({ scale: scala });
                var c = document.createElement('canvas'); c.width = vp.width; c.height = vp.height; box.appendChild(c);
                var ctx = c.getContext('2d');
                pagina.render({ canvasContext: ctx, viewport: vp }).promise.then(function () { filigrana(ctx, vp.width, vp.height); vai(n + 1); });
            });
        };
        vai(1);
    }).catch(function () { stato.textContent = 'Non è stato possibile aprire il verbale. Torna all\'elenco e riprova.'; });
})();
</script></body></html><?php
    exit;
}

$page_cfg['titolo'] = 'Verbali';
require_once 'header.php';
echo '<meta name="robots" content="noindex, nofollow, noarchive">';
?>
<div class="container my-4" style="max-width: 1140px;">
    <h1 class="fw-bold mb-1"><i class="fa fa-file-signature me-2 text-primary" aria-hidden="true"></i>Verbali dei consigli e degli organi</h1>
<?php if (!$accesso): ?>
    <p class="text-secondary">Area riservata: per vedere i verbali inserisci la password che ti ha comunicato l'Ufficio didattico del Dipartimento.</p>
    <?php if ($errore): ?><div class="alert alert-danger" role="alert"><?php echo $h($errore); ?></div><?php endif; ?>
    <form method="POST" class="card border-0 shadow-sm" style="max-width:460px;"><div class="card-body">
        <?php csrf_field(); ?>
        <label class="form-label fw-bold" for="vpPw">Password</label>
        <input type="password" class="form-control mb-3" id="vpPw" name="password" autocomplete="off" required maxlength="100" autofocus>
        <button type="submit" name="accedi" value="1" class="btn btn-primary fw-bold"><i class="fa fa-unlock me-1" aria-hidden="true"></i>Entra</button>
    </div></form>
    <p class="small text-secondary mt-3">Gli accessi e le letture dei verbali vengono registrati. Se non hai una password e pensi di averne diritto, scrivi all'Ufficio didattico.</p>
    <div class="card border-0 shadow-sm mt-3" style="max-width:460px;"><div class="card-body">
        <div class="fw-bold mb-1"><i class="fa fa-id-badge me-1 text-primary" aria-hidden="true"></i>Hai le credenziali di Ateneo?</div>
        <p class="small text-secondary mb-2">Le persone abilitate dall'Ufficio didattico accedono con le credenziali di Ateneo, vedono tutti i verbali e possono scaricarli.</p>
        <a class="btn btn-outline-primary btn-sm fw-bold" href="<?php echo $h($url_accedi_sso); ?>"><i class="fa fa-right-to-bracket me-1" aria-hidden="true"></i>Accedi con le credenziali di Ateneo</a>
    </div></div>
<?php else:
    $tutti = $serv->verbali($accesso);
    $filtro = ['corso' => (string)($_GET['corso'] ?? ''), 'organo' => (string)($_GET['organo'] ?? ''), 'aa' => (string)($_GET['aa'] ?? '')];
    $org = \App\Didattica\ServizioVerbaliPubblici::organizza($tutti, $filtro);
    $sel = ['corso' => in_array($filtro['corso'], $org['corsi'], true) ? $filtro['corso'] : '', 'organo' => in_array($filtro['organo'], $org['organi'], true) ? $filtro['organo'] : '', 'aa' => in_array($filtro['aa'], $org['anni'], true) ? $filtro['aa'] : ''];
    // per corso → per organo (il consiglio per primo) → per anno accademico
    $albero = [];
    foreach ($org['verbali'] as $v) $albero[$v['corso_nome']][$v['organo_ordine'] . '|' . $v['organo_etichetta']][$v['aa']][] = $v;
    ksort($albero, SORT_NATURAL | SORT_FLAG_CASE);
?>
    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <p class="text-secondary mb-0">Accesso: <strong><?php echo $h($accesso['etichetta']); ?></strong><?php echo $accesso['scade_il'] ? ' · valido fino al ' . date('d/m/Y', strtotime($accesso['scade_il'])) : ''; ?></p>
        <a class="btn btn-sm btn-outline-secondary ms-auto" href="verbali.php?esci=1"><i class="fa fa-right-from-bracket me-1" aria-hidden="true"></i>Esci</a>
    </div>
    <?php if ($puo_scaricare): ?>
        <div class="alert alert-success small py-2"><i class="fa fa-download me-1" aria-hidden="true"></i><strong>Puoi scaricare i verbali</strong>: sei abilitato (accesso con le credenziali di Ateneo).</div>
    <?php else: ?>
        <div class="alert alert-info small py-2"><i class="fa fa-eye me-1" aria-hidden="true"></i><strong>Sola visualizzazione.</strong> I verbali si leggono qui nella pagina ma non si possono scaricare né stampare.
            <?php if ($utente_sso): ?>Il tuo account (<?php echo $h($utente_sso['email'] ?? ''); ?>) non è abilitato al download: se ne hai bisogno scrivi all'Ufficio didattico.
            <?php else: ?>Le persone abilitate dall'Ufficio didattico possono scaricarli <a class="fw-bold" href="<?php echo $h($url_accedi_sso); ?>">accedendo con le credenziali di Ateneo</a>.<?php endif; ?></div>
    <?php endif; ?>

    <?php if ($tutti): ?>
    <form method="GET" class="card border-0 shadow-sm mb-3" aria-label="Filtra i verbali"><div class="card-body">
        <div class="row g-2 align-items-end">
            <?php if (count($org['corsi']) > 1): ?>
            <div class="col-md-4"><label class="form-label small fw-bold mb-0" for="vpCorso">Corso di studi</label>
                <select class="form-select" id="vpCorso" name="corso" onchange="document.getElementById('vpOrg').selectedIndex = 0; this.form.submit();">
                    <option value="">Tutti i corsi</option>
                    <?php foreach ($org['corsi'] as $c): ?><option value="<?php echo $h($c); ?>"<?php echo $sel['corso'] === $c ? ' selected' : ''; ?>><?php echo $h(mb_strimwidth($c, 0, 90, '…')); ?></option><?php endforeach; ?>
                </select></div>
            <?php endif; ?>
            <div class="col-md-4"><label class="form-label small fw-bold mb-0" for="vpOrg">Organo</label>
                <select class="form-select" id="vpOrg" name="organo" onchange="this.form.submit();">
                    <option value="">Tutti gli organi</option>
                    <?php foreach ($org['organi'] as $o): ?><option value="<?php echo $h($o); ?>"<?php echo $sel['organo'] === $o ? ' selected' : ''; ?>><?php echo $h($o); ?></option><?php endforeach; ?>
                </select></div>
            <div class="col-md-3"><label class="form-label small fw-bold mb-0" for="vpAa">Anno accademico</label>
                <select class="form-select" id="vpAa" name="aa" onchange="this.form.submit();">
                    <option value="">Tutti gli anni</option>
                    <?php foreach ($org['anni'] as $a): ?><option value="<?php echo $h($a); ?>"<?php echo $sel['aa'] === $a ? ' selected' : ''; ?>><?php echo $h($a); ?></option><?php endforeach; ?>
                </select></div>
            <div class="col-md-1 d-flex gap-1"><noscript><button class="btn btn-primary" type="submit">Filtra</button></noscript><?php if (array_filter($sel)): ?><a class="btn btn-outline-secondary" href="verbali.php" title="Azzera i filtri" aria-label="Azzera i filtri"><i class="fa fa-xmark" aria-hidden="true"></i></a><?php endif; ?></div>
        </div>
        <div class="small text-secondary mt-2"><?php echo count($org['verbali']); ?> verbali<?php echo array_filter($sel) ? ' con questi filtri' : ''; ?>.</div>
    </div></form>
    <?php endif; ?>

    <style>
        .vp-organo { background: #fff; border-radius: .6rem; box-shadow: 0 1px 6px rgba(0,0,0,.09); overflow: hidden; margin-bottom: .9rem; }
        .vp-organo > summary { list-style: none; cursor: pointer; display: flex; align-items: center; gap: .8rem; flex-wrap: wrap; padding: 1rem 1.3rem; background: #8e0f1f; border-bottom: 5px solid #5a0a14; color: #fff; }
        .vp-organo > summary::-webkit-details-marker { display: none; }
        .vp-organo > summary:hover { background: #7c0d1b; }
        .vp-organo > summary .vp-freccia { transition: transform .15s; font-size: 1rem; }
        .vp-organo[open] > summary .vp-freccia { transform: rotate(90deg); }
        .vp-organo h3 { font-size: 1.55rem; line-height: 1.2; font-weight: 800; margin: 0; flex: 1 1 auto; color: #fff; }
        .vp-organo summary .badge { background: rgba(255,255,255,.18) !important; color: #fff !important; border: 1px solid rgba(255,255,255,.45) !important; font-size: .82rem; font-weight: 500; }
        .vp-ultimo { font-size: .82rem; color: rgba(255,255,255,.85); }
        .vp-aa > summary { list-style: none; cursor: pointer; display: flex; align-items: center; gap: .55rem; padding: .6rem 1.3rem .6rem 1.6rem; font-size: 1.02rem; font-weight: 600; color: #3d4852; background: #f7f8fa; border-bottom: 1px solid #e3e7ec; }
        .vp-aa > summary::-webkit-details-marker { display: none; }
        .vp-aa > summary .vp-freccia { transition: transform .15s; color: #8e0f1f; font-size: .8rem; }
        .vp-aa[open] > summary .vp-freccia { transform: rotate(90deg); }
        .vp-aa > summary:hover { background: #eef1f5; }
        .vp-aa > summary .badge { font-size: .75rem; font-weight: 600; }
        .vp-lista a { display: flex; align-items: center; gap: .6rem; padding: .65rem 1.3rem .65rem 2.4rem; border-bottom: 1px solid #f0f2f5; text-decoration: none; }
        .vp-lista a:hover { background: #f8fafc; }
        .vp-lista .vp-nome { flex: 1 1 auto; }
        .vp-lista .vp-apri { font-size: .85rem; white-space: nowrap; }
    </style>
    <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
        <button type="button" class="btn btn-sm btn-outline-primary" id="vpApri"><i class="fa fa-angles-down me-1" aria-hidden="true"></i>Espandi tutto</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="vpChiudi"><i class="fa fa-angles-up me-1" aria-hidden="true"></i>Comprimi tutto</button>
    </div>
    <?php
        $n_organi_tot = 0; foreach ($albero as $os) $n_organi_tot += count($os);
        $aperti = array_filter($sel) || $n_organi_tot === 1; // con un filtro, o con un solo organo, si parte già aperti
    ?>
    <?php foreach ($albero as $corso => $organi): ksort($organi, SORT_NATURAL | SORT_FLAG_CASE); ?>
        <section class="mb-4" aria-labelledby="c_<?php echo md5($corso); ?>">
            <h2 class="h4 fw-bold mb-3" id="c_<?php echo md5($corso); ?>"><i class="fa fa-graduation-cap me-2 text-primary" aria-hidden="true"></i><?php echo $h($corso); ?></h2>
            <?php foreach ($organi as $chiave => $anni):
                $nome_organo = substr($chiave, strpos($chiave, '|') + 1);
                krsort($anni, SORT_STRING);
                $n_org = array_sum(array_map('count', $anni));
                $ultimo = null; foreach ($anni as $lista_u) foreach ($lista_u as $v_u) { if ($v_u['data'] && ($ultimo === null || $v_u['data'] > $ultimo)) $ultimo = $v_u['data']; }
            ?>
                <details class="vp-organo"<?php echo $aperti ? ' open' : ''; ?>>
                    <summary>
                        <i class="fa fa-chevron-right vp-freccia" aria-hidden="true"></i>
                        <h3><?php echo $h($nome_organo); ?></h3>
                        <span class="badge"><?php echo $n_org; ?> verbali</span>
                        <?php if ($ultimo): ?><span class="vp-ultimo">ultimo: <?php echo date('d/m/Y', strtotime($ultimo)); ?></span><?php endif; ?>
                    </summary>
                    <?php foreach ($anni as $anno => $lista): ?>
                        <details class="vp-aa"<?php echo $sel['aa'] !== '' ? ' open' : ''; ?>>
                            <summary><i class="fa fa-chevron-right vp-freccia" aria-hidden="true"></i><span>Anno accademico <?php echo $h($anno); ?></span><span class="badge text-bg-light border ms-auto"><?php echo count($lista); ?></span></summary>
                            <div class="vp-lista">
                                <?php foreach ($lista as $v): ?>
                                    <div class="d-flex align-items-center">
                                    <a href="verbali.php?v=<?php echo (int)$v['id']; ?>" target="_blank" rel="noopener" class="flex-grow-1">
                                        <i class="fa fa-file-pdf text-danger" aria-hidden="true"></i>
                                        <span class="vp-nome">Verbale<?php echo $v['numero_verbale'] !== '' ? ' n. ' . $h($v['numero_verbale']) : ''; ?><?php echo $v['data'] ? ' del ' . date('d/m/Y', strtotime($v['data'])) : ''; ?></span>
                                        <span class="vp-apri text-primary">Leggi <i class="fa fa-arrow-up-right-from-square ms-1" aria-hidden="true"></i></span>
                                    </a>
                                    <?php if ($puo_scaricare): ?><a class="btn btn-sm btn-outline-success me-3 py-0 flex-shrink-0" style="padding:.2rem .6rem !important;" href="verbali.php?d=<?php echo (int)$v['id']; ?>"><i class="fa fa-download me-1" aria-hidden="true"></i>Scarica</a><?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </details>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>
    <script>
    (function () {
        var tutti = function (aperto) { document.querySelectorAll('details.vp-organo, details.vp-aa').forEach(function (d) { d.open = aperto; }); };
        var a = document.getElementById('vpApri'), c = document.getElementById('vpChiudi');
        if (a) a.addEventListener('click', function () { tutti(true); });
        if (c) c.addEventListener('click', function () { tutti(false); });
    })();
    </script>
    <?php if (!$tutti): ?><div class="alert alert-light border">Non ci sono ancora verbali pubblicati per il tuo accesso.</div>
    <?php elseif (!$org['verbali']): ?><div class="alert alert-light border">Nessun verbale con questi filtri.</div><?php endif; ?>
<?php endif; ?>
</div>
<?php require_once 'footer.php'; ?>
