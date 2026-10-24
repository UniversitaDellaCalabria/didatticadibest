<?php
// domande_frequenti.php - Le domande frequenti dell'assistenza: le risposte di Didacticus pubblicate come pagine, per argomento, per pubblico e con la ricerca.
// Le voci stanno in didacticus_voci e si modificano dal pannello (Didacticus): qui si leggono soltanto, quelle riservate a chi accede solo dopo il login.
require_once 'config.php';
require_once 'functions.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$page_cfg['titolo'] = 'Domande frequenti';
require_once 'header.php';

$Cost = \App\Assistenza\Costanti::class;
$repo = \App\Core\App::per($conn)->get(\App\Assistenza\Didacticus\VociRepository::class);
$con_login = !empty($_SESSION['utente_id']);
$argomenti = $Cost::argomenti();
$per = isset($Cost::PUBBLICI[$_GET['per'] ?? '']) ? (string)$_GET['per'] : ''; // a chi si rivolge: vuoto = tutti
// Indirizzo della pagina mantenendo il pubblico scelto
$url = function (array $extra = []) use ($per): string {
    $q = array_filter(['per' => $per] + $extra, fn($v) => $v !== '' && $v !== null);
    return 'domande_frequenti.php' . ($q ? '?' . http_build_query($q) : '');
};
$tutte = array_values(array_filter($repo->attive(true), function (array $v) use ($per, $Cost) {
    $p = $Cost::pubblici((string)($v['pubblico'] ?? ''));
    return $per === '' || !$p || in_array($per, $p, true); // senza pubblico indicato vale per tutti
}));
$visibili = array_values(array_filter($tutte, fn(array $v) => (int)$v['solo_login'] === 0 || $con_login));
$riservate = count($tutte) - count($visibili);

$arg = isset($_GET['argomento']) && isset($argomenti[$_GET["argomento"]]) ? (string)$_GET['argomento'] : '';
$cerca = trim((string)($_GET['q'] ?? ''));

// Quante risposte per argomento (per le schede della pagina iniziale)
$per_arg = [];
foreach ($visibili as $v) { $per_arg[$v['argomento']] = ($per_arg[$v['argomento']] ?? 0) + 1; }

// Ricerca: tutte le parole devono comparire; il titolo e le parole chiave contano di più
$trovate = [];
if ($cerca !== '') {
    $parole = array_values(array_filter(preg_split('/\s+/u', mb_strtolower($cerca)), fn($p) => mb_strlen($p) >= 2));
    foreach ($visibili as $v) {
        $titolo = mb_strtolower((string)$v['titolo']); $chiavi = mb_strtolower($v['parole_chiave'] . ' ' . $v['domande']); $testo = mb_strtolower((string)$v['risposta']);
        $punti = 0;
        foreach ($parole as $p) {
            $p_ok = mb_strpos($titolo, $p) !== false ? 5 : (mb_strpos($chiavi, $p) !== false ? 3 : (mb_strpos($testo, $p) !== false ? 1 : 0));
            if ($p_ok === 0) { $punti = 0; break; }
            $punti += $p_ok;
        }
        if ($punti > 0) { $v['_punti'] = $punti; $trovate[] = $v; }
    }
    usort($trovate, fn($a, $b) => $b['_punti'] <=> $a['_punti']);
}
$elenco = $cerca !== '' ? $trovate : ($arg !== '' ? array_values(array_filter($visibili, fn($v) => $v['argomento'] === $arg)) : []);

// Una risposta in fisarmonica (<details>: funziona senza JavaScript e con la tastiera)
$scheda = function (array $v) use ($h, $Cost, $url): string {
    $out = '<details class="gs-voce" id="v' . (int)$v['id'] . '"><summary>' . $h($v['titolo']) . '</summary><div class="gs-corpo">';
    foreach (preg_split('/\n{2,}/', trim((string)$v['risposta'])) as $par) {
        if (trim($par) !== '') { $out .= '<p>' . nl2br($h(trim($par))) . '</p>'; }
    }
    $link = \App\Assistenza\Didacticus\VociRepository::link($v);
    $alleg = \App\Assistenza\Didacticus\VociRepository::allegati($v);
    if ($link || $alleg) {
        $out .= '<div class="gs-azioni">';
        foreach ($link as $l) {
            $esterno = preg_match('#^https?://#i', $l['u']) === 1;
            $out .= '<a class="btn btn-sm btn-outline-danger" href="' . $h($l['u']) . '"' . ($esterno ? ' target="_blank" rel="noopener noreferrer"' : '') . '><i class="fa ' . ($esterno ? 'fa-arrow-up-right-from-square' : 'fa-arrow-right') . ' me-1" aria-hidden="true"></i>' . $h($l['e']) . '</a>';
        }
        foreach ($alleg as $i => $a) {
            $out .= '<a class="btn btn-sm btn-outline-secondary" href="didacticus_file.php?v=' . (int)$v['id'] . '&amp;i=' . (int)$i . '"><i class="fa fa-file-arrow-down me-1" aria-hidden="true"></i>' . $h($a['n']) . '</a>';
        }
        $out .= '</div>';
    }
    if ($cerca !== '') { $out .= '<div class="gs-arg small text-secondary mt-2">Argomento: <a href="' . $h($url(['argomento' => $v['argomento']])) . '">' . $h($Cost::nomeArgomento($v['argomento'])) . '</a></div>'; }
    return $out . '</div></details>';
};
?>
<style>
.gs-arg-card { display: flex; gap: 14px; align-items: flex-start; height: 100%; padding: 18px; border: 1px solid #e2e8f0; border-radius: 14px; background: #fff; color: inherit; text-decoration: none; transition: box-shadow .15s, border-color .15s; }
.gs-arg-card:hover, .gs-arg-card:focus-visible { border-color: #990000; box-shadow: 0 6px 18px rgba(0,0,0,.08); color: inherit; }
.gs-ico { flex: 0 0 46px; width: 46px; height: 46px; border-radius: 12px; background: #fdecec; color: #990000; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; }
.gs-arg-card strong { display: block; font-size: 1.05rem; color: #1f2937; }
.gs-arg-card span.gs-sub { display: block; font-size: .9rem; color: #546072; margin-top: 2px; }
.gs-arg-card .gs-n { font-size: .85rem; color: #990000; font-weight: 600; margin-top: 6px; display: block; }
.gs-voce { border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; margin-bottom: 10px; }
.gs-voce > summary { cursor: pointer; padding: 14px 18px; font-weight: 700; font-size: 1.05rem; list-style: none; display: flex; justify-content: space-between; gap: 12px; align-items: center; }
.gs-voce > summary::-webkit-details-marker { display: none; }
.gs-voce > summary::after { content: "\f078"; font-family: "Font Awesome 6 Free", "Font Awesome 5 Free"; font-weight: 900; font-size: .8rem; color: #990000; transition: transform .15s; }
.gs-voce[open] > summary::after { transform: rotate(180deg); }
.gs-voce[open] { border-color: #990000; }
.gs-corpo { padding: 0 18px 16px; font-size: 1rem; line-height: 1.6; }
.gs-azioni { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
.gs-voce:target { box-shadow: 0 0 0 3px rgba(153,0,0,.25); }
</style>
<div class="container my-4" style="max-width: 960px;">
    <nav aria-label="Percorso" class="mb-3 small"><a href="index.php">Home</a> <span class="text-secondary mx-1">/</span> <a href="assistenza.php">Assistenza</a> <span class="text-secondary mx-1">/</span>
        <?php if ($arg !== '' || $cerca !== ''): ?><a href="<?php echo $h($url()); ?>">Domande frequenti</a> <span class="text-secondary mx-1">/</span> <?php echo $cerca !== '' ? 'Ricerca' : $h($Cost::nomeArgomento($arg)); ?>
        <?php else: ?>Domande frequenti<?php endif; ?></nav>

    <h1 class="fw-bold mb-1"><?php echo $arg !== '' && $cerca === '' ? $h($Cost::nomeArgomento($arg)) : 'Domande frequenti'; ?></h1>
    <p class="text-secondary"><?php echo $arg !== '' && $cerca === '' ? $h($argomenti[$arg]['aiuto']) : 'Le risposte alle domande più frequenti. Scegli a chi ti rivolgi, cerca una parola o apri un argomento.'; ?></p>

    <nav class="d-flex flex-wrap gap-2 mb-3" aria-label="A chi ti rivolgi">
        <span class="small text-secondary align-self-center me-1">Per:</span>
        <?php foreach (['' => 'Tutti'] + $Cost::PUBBLICI as $k_p => $n_p): ?>
            <a class="btn btn-sm <?php echo $per === $k_p ? 'btn-danger' : 'btn-outline-secondary'; ?>" href="<?php echo $h('domande_frequenti.php' . ($k_p !== '' ? '?per=' . $k_p : '')); ?>"<?php echo $per === $k_p ? ' aria-current="true"' : ''; ?>><?php echo $h($n_p); ?></a>
        <?php endforeach; ?>
    </nav>
    <form method="GET" class="d-flex gap-2 mb-4" role="search">
        <?php if ($per !== ''): ?><input type="hidden" name="per" value="<?php echo $h($per); ?>"><?php endif; ?>
        <label class="visually-hidden" for="q">Cerca nelle domande frequenti</label>
        <input class="form-control form-control-lg" id="q" name="q" value="<?php echo $h($cerca); ?>" placeholder="Per esempio: certificato, tasse, tirocinio, tesi" autocomplete="off">
        <button class="btn btn-danger btn-lg px-4" type="submit"><i class="fa fa-search" aria-hidden="true"></i><span class="visually-hidden"> Cerca</span></button>
    </form>

    <?php if ($cerca !== '' || $arg !== ''): ?>
        <?php if ($cerca !== ''): ?><p class="small text-secondary"><?php echo count($elenco); ?> <?php echo count($elenco) === 1 ? 'risposta trovata' : 'risposte trovate'; ?> per «<?php echo $h($cerca); ?>»</p><?php endif; ?>
        <?php if (!$elenco): ?>
            <div class="alert alert-light border">Non ho trovato risposte. Prova con una parola diversa o <a href="<?php echo $h($url()); ?>">scegli un argomento</a>.</div>
        <?php else: foreach ($elenco as $v) { echo $scheda($v); } endif; ?>
    <?php else: ?>
        <div class="row g-3 mb-4">
        <?php foreach ($argomenti as $k => $a): if (empty($per_arg[$k])) continue; ?>
            <div class="col-md-6"><a class="gs-arg-card" href="<?php echo $h($url(["argomento" => $k])); ?>">
                <span class="gs-ico"><i class="fa <?php echo $h($a['icona']); ?>" aria-hidden="true"></i></span>
                <span><strong><?php echo $h($a['nome']); ?></strong><span class="gs-sub"><?php echo $h($a['aiuto']); ?></span><span class="gs-n"><?php echo (int)$per_arg[$k]; ?> <?php echo $per_arg[$k] === 1 ? 'risposta' : 'risposte'; ?></span></span>
            </a></div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($riservate > 0 && !$con_login): ?>
        <p class="small text-secondary"><i class="fa fa-lock me-1" aria-hidden="true"></i><?php echo $riservate; ?> <?php echo $riservate === 1 ? 'risposta è riservata' : 'risposte sono riservate'; ?> a chi ha effettuato l'accesso: <a href="saml_login.php?redirect=<?php echo rawurlencode('domande_frequenti.php'); ?>">accedi con le credenziali Unical</a>.</p>
    <?php endif; ?>

    <div class="card border-0 shadow-sm mt-4" style="border-radius:14px;background:#f8fafc;"><div class="card-body d-flex flex-wrap align-items-center gap-3">
        <i class="fa fa-headset fs-3 text-danger" aria-hidden="true"></i>
        <div class="flex-grow-1"><strong>Non hai trovato quello che cerchi?</strong><div class="small text-secondary">Scrivi la tua domanda all'assistenza: risponde l'assistente virtuale e, se serve, una persona dell'Ufficio didattico.</div></div>
        <a class="btn btn-danger fw-bold" href="assistenza.php">Chiedi all'assistenza</a>
    </div></div>
</div>
<script>
// Un indirizzo con #v12 apre subito quella risposta
(function () {
    function apri() { var m = location.hash.match(/^#v\d+$/); if (m) { var d = document.querySelector(m[0]); if (d && d.tagName === 'DETAILS') { d.open = true; d.scrollIntoView({ block: 'center' }); } } }
    apri(); window.addEventListener('hashchange', apri);
})();
</script>
<?php require_once 'footer.php'; ?>
