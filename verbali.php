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
    // corso, organo (il consiglio per primo) e anno accademico di ogni verbale; l'elenco si disegna nel browser (vista «Per corso» e vista «Cerca»)
    $org = \App\Didattica\ServizioVerbaliPubblici::organizza($tutti, []);
    $dati_js = array_map(fn($v) => ['id' => (int)$v['id'], 'c' => $v['corso_nome'], 'o' => $v['organo_etichetta'], 'oo' => (int)$v['organo_ordine'], 'a' => $v['aa'], 'd' => (string)($v['data'] ?? ''), 'n' => (string)($v['numero_verbale'] ?? '')], $org['verbali']);
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
    <style>
        .vb-vista { display:inline-flex; border:1px solid #cbd5e1; border-radius:.5rem; overflow:hidden; background:#fff; }
        .vb-vista button { border:0; background:transparent; padding:.45rem 1rem; font-weight:600; color:#475569; }
        .vb-vista button.on { background:#8e0f1f; color:#fff; }
        .vb-corsi { display:flex; flex-direction:column; gap:.35rem; }
        .vb-corso { text-align:left; border:1px solid #e2e8f0; background:#fff; border-radius:.5rem; padding:.55rem .75rem; display:flex; gap:.6rem; align-items:flex-start; cursor:pointer; }
        .vb-corso:hover { background:#f8fafc; }
        .vb-corso.on { border-color:#8e0f1f; background:#fdf2f4; }
        .vb-corso .t { flex:1; font-size:.9rem; line-height:1.3; display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden; }
        .vb-corso .n { font-size:.78rem; background:#f1f5f9; color:#475569; border-radius:.4rem; padding:.05rem .45rem; }
        .vb-corso.on .n { background:#8e0f1f; color:#fff; }
        .vb-chip { border:1px solid #cbd5e1; background:#fff; border-radius:2rem; padding:.3rem .9rem; font-size:.9rem; cursor:pointer; margin:0 .4rem .4rem 0; }
        .vb-chip.on { background:#1e293b; color:#fff; border-color:#1e293b; }
        .vb-chip small { opacity:.7; margin-left:.3rem; }
        .vb-anno { border:1px solid #e2e8f0; border-radius:.5rem; background:#fff; margin-bottom:.6rem; }
        .vb-anno > summary { list-style:none; cursor:pointer; padding:.65rem 1rem; display:flex; gap:.6rem; align-items:center; font-weight:600; }
        .vb-anno > summary::-webkit-details-marker { display:none; }
        .vb-anno > summary .fa-chevron-right { transition:transform .15s; font-size:.75rem; color:#8e0f1f; }
        .vb-anno[open] > summary .fa-chevron-right { transform:rotate(90deg); }
        .vb-riga { display:flex; align-items:center; gap:.7rem; padding:.55rem 1rem; border-top:1px solid #f1f5f9; }
        .vb-riga a.leggi { flex:1; color:inherit; text-decoration:none; }
        .vb-riga a.leggi:hover { text-decoration:underline; }
        .vb-tab th { cursor:pointer; white-space:nowrap; font-size:.8rem; text-transform:uppercase; letter-spacing:.03em; color:#64748b; }
        .vb-tab td { vertical-align:middle; }
        .vb-tab .cut { max-width:260px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        @media (max-width: 767px) { .vb-corsi { display:none; } .vb-sel-corso { display:block !important; } }
    </style>
    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <div class="vb-vista" role="group" aria-label="Come vedere i verbali">
            <button type="button" id="vbBCorso" class="on"><i class="fa fa-sitemap me-1" aria-hidden="true"></i>Per corso</button>
            <button type="button" id="vbBCerca"><i class="fa fa-magnifying-glass me-1" aria-hidden="true"></i>Cerca</button>
        </div>
        <span class="text-secondary small"><?php echo count($dati_js); ?> verbali</span>
    </div>

    <div id="vbCorso" class="row g-3">
        <div class="col-md-4 col-lg-3">
            <select class="form-select vb-sel-corso mb-2" id="vbSelCorso" aria-label="Corso di studi" style="display:none;"></select>
            <div class="vb-corsi" id="vbCorsi" role="list" aria-label="Corsi di studi"></div>
        </div>
        <div class="col-md-8 col-lg-9">
            <h2 class="h5 fw-bold mb-1" id="vbTitolo"></h2>
            <div class="small text-secondary mb-2" id="vbSotto"></div>
            <div id="vbOrgani" class="mb-2" role="tablist" aria-label="Organi"></div>
            <div id="vbAnni"></div>
        </div>
    </div>

    <div id="vbCerca" hidden>
        <div class="row g-2 mb-2">
            <div class="col-md-5"><label class="form-label small fw-bold mb-0" for="vbQ">Cerca</label><input type="search" class="form-control" id="vbQ" placeholder="Data, numero, anno o organo" autocomplete="off"></div>
            <div class="col-md-4"><label class="form-label small fw-bold mb-0" for="vbFC">Corso di studi</label><select class="form-select" id="vbFC"></select></div>
            <div class="col-md-3"><label class="form-label small fw-bold mb-0" for="vbFA">Anno accademico</label><select class="form-select" id="vbFA"></select></div>
        </div>
        <div id="vbFO" class="mb-2"></div>
        <div class="table-responsive bg-white border rounded"><table class="table table-sm table-hover align-middle mb-0 vb-tab">
            <thead><tr><th id="vbOrdData" title="Ordina per data">Data <i class="fa fa-arrow-down-long ms-1" aria-hidden="true"></i></th><th>Organo</th><th>Corso</th><th>Anno</th><th></th></tr></thead>
            <tbody id="vbRighe"></tbody></table></div>
        <div class="d-flex align-items-center gap-2 mt-2"><span class="small text-secondary" id="vbConta"></span>
            <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" id="vbPrec">&larr; Precedenti</button><button type="button" class="btn btn-sm btn-outline-secondary" id="vbSucc">Successivi &rarr;</button></div>
    </div>
    <script>
    (function () {
        var D = <?php echo json_encode(array_values($dati_js), JSON_UNESCAPED_UNICODE); ?>;
        var SCARICA = <?php echo $puo_scaricare ? 'true' : 'false'; ?>;
        var PAG = 20;
        var $ = function (id) { return document.getElementById(id); };
        var st = { vista: 'corso', corso: '', organo: '', q: '', fc: '', fo: '', fa: '', asc: false, pag: 0 };
        try { var v0 = localStorage.getItem('vbVista'); if (v0 === 'cerca') st.vista = 'cerca'; } catch (e) {}
        var esc = function (x) { return String(x).replace(/[&<>"']/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; }); };
        var dm = function (d) { return d ? d.slice(8, 10) + '/' + d.slice(5, 7) + '/' + d.slice(0, 4) : 'senza data'; };
        var nome = function (r) { return 'Verbale' + (r.n ? ' n. ' + r.n : '') + (r.d ? ' del ' + dm(r.d) : ''); };
        var uniq = function (a) { return a.filter(function (x, i) { return a.indexOf(x) === i; }); };
        var corsi = uniq(D.map(function (r) { return r.c; })).sort(function (a, b) { return a.localeCompare(b, 'it'); });
        var anni = uniq(D.map(function (r) { return r.a; })).sort().reverse();
        var organiTutti = uniq(D.slice().sort(function (a, b) { return a.oo - b.oo; }).map(function (r) { return r.o; }));
        var ultimo = function (L) { var m = ''; L.forEach(function (r) { if (r.d > m) m = r.d; }); return m; };
        var cmpData = function (a, b) { return (b.d || '').localeCompare(a.d || '') || b.id - a.id; };
        var riga = function (r) {
            return '<div class="vb-riga"><i class="fa fa-file-pdf text-danger" aria-hidden="true"></i><a class="leggi" href="verbali.php?v=' + r.id + '" target="_blank" rel="noopener">' + esc(nome(r)) + '</a>'
                + '<a class="small text-primary text-decoration-none" href="verbali.php?v=' + r.id + '" target="_blank" rel="noopener">Leggi</a>'
                + (SCARICA ? '<a class="btn btn-sm btn-outline-success py-0" href="verbali.php?d=' + r.id + '"><i class="fa fa-download me-1" aria-hidden="true"></i>Scarica</a>' : '') + '</div>';
        };

        // ── Vista «Per corso»: corsi a sinistra, organi a schede, anni a comparsa ──
        function disegnaCorso() {
            if (corsi.indexOf(st.corso) < 0) st.corso = corsi[0];
            var delCorso = D.filter(function (r) { return r.c === st.corso; });
            var organi = uniq(delCorso.slice().sort(function (a, b) { return a.oo - b.oo; }).map(function (r) { return r.o; }));
            if (organi.indexOf(st.organo) < 0) st.organo = organi[0];
            $('vbCorsi').innerHTML = corsi.map(function (c) {
                var n = D.filter(function (r) { return r.c === c; }).length;
                return '<button type="button" class="vb-corso' + (c === st.corso ? ' on' : '') + '" data-c="' + esc(c) + '" role="listitem"><span class="t">' + esc(c) + '</span><span class="n">' + n + '</span></button>';
            }).join('');
            $('vbSelCorso').innerHTML = corsi.map(function (c) { return '<option value="' + esc(c) + '"' + (c === st.corso ? ' selected' : '') + '>' + esc(c) + '</option>'; }).join('');
            $('vbTitolo').textContent = st.corso;
            $('vbSotto').textContent = delCorso.length + ' verbali · ultimo ' + dm(ultimo(delCorso));
            $('vbOrgani').innerHTML = organi.map(function (o) {
                var n = delCorso.filter(function (r) { return r.o === o; }).length;
                return '<button type="button" class="vb-chip' + (o === st.organo ? ' on' : '') + '" data-o="' + esc(o) + '" role="tab">' + esc(o) + '<small>' + n + '</small></button>';
            }).join('');
            var L = delCorso.filter(function (r) { return r.o === st.organo; }).sort(cmpData);
            var perAnno = {}; L.forEach(function (r) { (perAnno[r.a] = perAnno[r.a] || []).push(r); });
            var ak = Object.keys(perAnno).sort().reverse();
            $('vbAnni').innerHTML = ak.map(function (a, i) {
                return '<details class="vb-anno"' + (i === 0 ? ' open' : '') + '><summary><i class="fa fa-chevron-right" aria-hidden="true"></i>Anno accademico ' + esc(a) + '<span class="badge text-bg-light border ms-auto">' + perAnno[a].length + '</span></summary>' + perAnno[a].map(riga).join('') + '</details>';
            }).join('') || '<div class="text-secondary">Nessun verbale.</div>';
        }
        $('vbCorsi').addEventListener('click', function (e) { var b = e.target.closest('[data-c]'); if (b) { st.corso = b.getAttribute('data-c'); st.organo = ''; disegnaCorso(); } });
        $('vbSelCorso').addEventListener('change', function () { st.corso = this.value; st.organo = ''; disegnaCorso(); });
        $('vbOrgani').addEventListener('click', function (e) { var b = e.target.closest('[data-o]'); if (b) { st.organo = b.getAttribute('data-o'); disegnaCorso(); } });

        // ── Vista «Cerca»: ricerca, filtri e tabella ordinata per data ──
        function filtrati() {
            var q = st.q.trim().toLowerCase();
            return D.filter(function (r) {
                if (st.fc && r.c !== st.fc) return false;
                if (st.fo && r.o !== st.fo) return false;
                if (st.fa && r.a !== st.fa) return false;
                if (!q) return true;
                return (nome(r) + ' ' + r.a + ' ' + r.o + ' ' + r.c + ' ' + (r.d || '')).toLowerCase().indexOf(q) >= 0;
            }).sort(function (a, b) { var x = cmpData(a, b); return st.asc ? -x : x; });
        }
        function disegnaCerca() {
            $('vbFC').innerHTML = '<option value="">Tutti i corsi</option>' + corsi.map(function (c) { return '<option value="' + esc(c) + '"' + (c === st.fc ? ' selected' : '') + '>' + esc(c) + '</option>'; }).join('');
            $('vbFA').innerHTML = '<option value="">Tutti gli anni</option>' + anni.map(function (a) { return '<option value="' + esc(a) + '"' + (a === st.fa ? ' selected' : '') + '>' + esc(a) + '</option>'; }).join('');
            $('vbFO').innerHTML = '<button type="button" class="vb-chip' + (st.fo === '' ? ' on' : '') + '" data-o="">Tutti gli organi</button>' + organiTutti.map(function (o) { return '<button type="button" class="vb-chip' + (o === st.fo ? ' on' : '') + '" data-o="' + esc(o) + '">' + esc(o) + '</button>'; }).join('');
            var L = filtrati(), tot = L.length, max = Math.max(0, Math.ceil(tot / PAG) - 1);
            if (st.pag > max) st.pag = max;
            var pg = L.slice(st.pag * PAG, st.pag * PAG + PAG);
            $('vbRighe').innerHTML = pg.map(function (r) {
                return '<tr><td class="text-nowrap">' + esc(dm(r.d)) + '</td><td>' + esc(r.o) + '</td><td class="cut" title="' + esc(r.c) + '">' + esc(r.c) + '</td><td class="text-nowrap">' + esc(r.a) + '</td>'
                    + '<td class="text-end text-nowrap"><a class="small text-primary text-decoration-none me-2" href="verbali.php?v=' + r.id + '" target="_blank" rel="noopener">Leggi</a>'
                    + (SCARICA ? '<a class="btn btn-sm btn-outline-success py-0" href="verbali.php?d=' + r.id + '"><i class="fa fa-download" aria-hidden="true" title="Scarica"></i></a>' : '') + '</td></tr>';
            }).join('') || '<tr><td colspan="5" class="text-secondary p-3">Nessun verbale con questi filtri.</td></tr>';
            $('vbConta').textContent = tot ? (st.pag * PAG + 1) + '–' + (st.pag * PAG + pg.length) + ' di ' + tot : '0 verbali';
            $('vbPrec').disabled = st.pag === 0; $('vbSucc').disabled = st.pag >= max;
        }
        $('vbQ').addEventListener('input', function () { st.q = this.value; st.pag = 0; disegnaCerca(); });
        $('vbFC').addEventListener('change', function () { st.fc = this.value; st.pag = 0; disegnaCerca(); });
        $('vbFA').addEventListener('change', function () { st.fa = this.value; st.pag = 0; disegnaCerca(); });
        $('vbFO').addEventListener('click', function (e) { var b = e.target.closest('[data-o]'); if (b) { st.fo = b.getAttribute('data-o'); st.pag = 0; disegnaCerca(); } });
        $('vbOrdData').addEventListener('click', function () { st.asc = !st.asc; this.querySelector('i').className = 'fa ms-1 ' + (st.asc ? 'fa-arrow-up-long' : 'fa-arrow-down-long'); disegnaCerca(); });
        $('vbPrec').addEventListener('click', function () { st.pag = Math.max(0, st.pag - 1); disegnaCerca(); });
        $('vbSucc').addEventListener('click', function () { st.pag++; disegnaCerca(); });

        // ── Cambio di vista ──
        function vista(v) {
            st.vista = v;
            $('vbCorso').hidden = v !== 'corso'; $('vbCerca').hidden = v !== 'cerca';
            $('vbBCorso').classList.toggle('on', v === 'corso'); $('vbBCerca').classList.toggle('on', v === 'cerca');
            try { localStorage.setItem('vbVista', v); } catch (e) {}
            if (v === 'corso') disegnaCorso(); else disegnaCerca();
        }
        $('vbBCorso').addEventListener('click', function () { vista('corso'); });
        $('vbBCerca').addEventListener('click', function () { vista('cerca'); });
        vista(st.vista);
    })();
    </script>
    <?php endif; ?>
    <?php if (!$tutti): ?><div class="alert alert-light border">Non ci sono ancora verbali pubblicati per il tuo accesso.</div>
    <?php elseif (!$org['verbali']): ?><div class="alert alert-light border">Nessun verbale con questi filtri.</div><?php endif; ?>
<?php endif; ?>
</div>
<?php require_once 'footer.php'; ?>
