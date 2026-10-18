<?php
// verbali.php - Pagina pubblica dei verbali con password: per chi non ha le credenziali di Ateneo. Si entra con la password data
// dall'Ufficio didattico (sessione separata dal login SSO) e si scaricano solo i verbali firmati e pubblicabili dei consigli
// dell'accesso. Non indicizzabile; ogni accesso e scaricamento si registra. Logica in App\Didattica\ServizioVerbaliPubblici.
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
$accesso = $serv->corrente();

if ($accesso && isset($_GET['d'])) {
    $f = $serv->file($accesso, (int)$_GET['d']);
    if (!$f) { http_response_code(404); exit('Verbale non disponibile.'); }
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf'); header('X-Content-Type-Options: nosniff'); header('Content-Length: ' . filesize($f['percorso']));
    header('Content-Disposition: attachment; filename="' . $f['nome'] . '"');
    readfile($f['percorso']); exit;
}

$page_cfg['titolo'] = 'Verbali';
require_once 'header.php';
echo '<meta name="robots" content="noindex, nofollow, noarchive">';
?>
<div class="container my-4" style="max-width: 920px;">
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
    <p class="small text-secondary mt-3">Gli accessi e i verbali scaricati vengono registrati. Se non hai una password e pensi di averne diritto, scrivi all'Ufficio didattico.</p>
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

    <?php foreach ($albero as $corso => $organi): ksort($organi, SORT_NATURAL | SORT_FLAG_CASE); ?>
        <section class="mb-4" aria-labelledby="c_<?php echo md5($corso); ?>">
            <h2 class="h5 fw-bold mb-2" id="c_<?php echo md5($corso); ?>"><i class="fa fa-graduation-cap me-2 text-primary" aria-hidden="true"></i><?php echo $h($corso); ?></h2>
            <?php foreach ($organi as $chiave => $anni): $nome_organo = substr($chiave, strpos($chiave, '|') + 1); $n_org = array_sum(array_map('count', $anni)); ?>
                <div class="card border-0 shadow-sm mb-3"><div class="card-body">
                    <h3 class="h6 fw-bold mb-1"><?php echo $h($nome_organo); ?> <span class="badge text-bg-light border fw-normal"><?php echo $n_org; ?></span></h3>
                    <?php krsort($anni, SORT_STRING); foreach ($anni as $anno => $lista): ?>
                        <div class="small text-secondary text-uppercase fw-bold mt-2" style="font-size:.72rem;">a.a. <?php echo $h($anno); ?></div>
                        <?php foreach ($lista as $v): ?>
                            <a class="d-flex align-items-center gap-2 py-2 border-bottom text-decoration-none" href="verbali.php?d=<?php echo (int)$v['id']; ?>">
                                <i class="fa fa-file-pdf text-danger" aria-hidden="true"></i>
                                <span class="flex-grow-1">Verbale<?php echo $v['numero_verbale'] !== '' ? ' n. ' . $h($v['numero_verbale']) : ''; ?><?php echo $v['data'] ? ' del ' . date('d/m/Y', strtotime($v['data'])) : ''; ?></span>
                                <span class="small text-primary"><i class="fa fa-download me-1" aria-hidden="true"></i>Scarica</span>
                            </a>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div></div>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>
    <?php if (!$tutti): ?><div class="alert alert-light border">Non ci sono ancora verbali pubblicati per il tuo accesso.</div>
    <?php elseif (!$org['verbali']): ?><div class="alert alert-light border">Nessun verbale con questi filtri.</div><?php endif; ?>
<?php endif; ?>
</div>
<?php require_once 'footer.php'; ?>
