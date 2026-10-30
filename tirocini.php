<?php
// tirocini.php - Pagina (visibile dopo l'accesso) dei tirocini curriculari: aziende esterne con convenzione attiva (con indirizzo, ricerca per regione, provincia, comune e settore)
// e laboratori interni dell'Ateneo. Solo ciò che è completo e pubblicabile (vedi admin/tirocini.php). Logica in src/Tirocini/.
require_once 'config.php';
require_once 'functions.php';
require_once 'accesso_richiesto.php';
richiedi_accesso($conn, 'Tirocini curriculari', "Qui trovi le aziende e gli enti convenzionati e i laboratori dell'Ateneo dove svolgere il tirocinio."); // solo dopo l'accesso

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$app_t = \App\Core\App::per($conn);
$tipo = ($_GET['tipo'] ?? '') === 'interni' ? 'interni' : 'esterni';
$cerca = trim((string)($_GET['q'] ?? ''));
$trova = fn(string $testo) => $cerca === '' || mb_stripos($testo, $cerca) !== false;
$url = fn(string $t) => 'tirocini.php?tipo=' . $t;

if ($tipo === 'esterni') {
    $tutte = $app_t->get(\App\Tirocini\ServizioAziende::class)->pubbliche();
    $f = ['regione' => trim((string)($_GET['regione'] ?? '')), 'provincia' => trim((string)($_GET['provincia'] ?? '')), 'comune' => trim((string)($_GET['comune'] ?? '')), 'settore' => trim((string)($_GET['settore'] ?? ''))];
    $opzioni = [];
    foreach (['regione', 'provincia', 'comune', 'settore'] as $c) {
        $v = array_values(array_unique(array_filter(array_map(fn($a) => (string)$a[$c], $tutte))));
        sort($v, SORT_NATURAL | SORT_FLAG_CASE);
        $opzioni[$c] = $v;
    }
    $elenco = array_values(array_filter($tutte, function (array $a) use ($f, $trova): bool {
        foreach ($f as $c => $v) if ($v !== '' && (string)$a[$c] !== $v) return false;
        return $trova($a['nome'] . ' ' . $a['comune'] . ' ' . $a['indirizzo'] . ' ' . $a['settore']);
    }));
} else {
    $tutte = $app_t->get(\App\Tirocini\ServizioLaboratori::class)->pubblici();
    $f = ['dipartimento' => trim((string)($_GET['dipartimento'] ?? '')), 'area' => trim((string)($_GET['area'] ?? ''))];
    $opzioni = [];
    foreach (['dipartimento', 'area'] as $c) {
        $v = array_values(array_unique(array_filter(array_map(fn($a) => (string)$a[$c], $tutte))));
        sort($v, SORT_NATURAL | SORT_FLAG_CASE);
        $opzioni[$c] = $v;
    }
    $elenco = array_values(array_filter($tutte, function (array $a) use ($f, $trova): bool {
        foreach ($f as $c => $v) if ($v !== '' && (string)$a[$c] !== $v) return false;
        return $trova($a['nome'] . ' ' . $a['sigla'] . ' ' . $a['direttore'] . ' ' . $a['docenti'] . ' ' . $a['descrizione']);
    }));
}
$etichette = ['regione' => 'Tutte le regioni', 'provincia' => 'Tutte le province', 'comune' => 'Tutti i comuni', 'settore' => 'Tutti i settori', 'dipartimento' => 'Tutti i dipartimenti', 'area' => 'Tutte le aree'];
$filtrato = $cerca !== '' || array_filter($f);
$page_cfg['titolo'] = 'Tirocini curriculari';
require_once 'header.php';
?>
<style>.nav-pills .nav-link.active{background:#b30000}.nav-pills .nav-link{color:#7a0a18}.nav-pills .nav-link.active{color:#fff}</style>
<div class="container my-4" style="max-width: 1000px;">
    <nav aria-label="Percorso" class="mb-3 small"><a href="index.php">Home</a> <span class="text-secondary mx-1">/</span> Tirocini curriculari</nav>
    <h1 class="fw-bold mb-1">Tirocini curriculari</h1>
    <p class="text-secondary">Dove si può svolgere il tirocinio: le aziende e gli enti esterni che hanno una convenzione attiva con l'Ateneo per il Dipartimento e i laboratori interni dell'Università. Il tirocinio si attiva dalla piattaforma TSP dell'Ateneo.</p>
    <ul class="nav nav-pills mb-3" aria-label="Tipo di tirocinio">
        <li class="nav-item"><a class="nav-link<?php echo $tipo === 'esterni' ? ' active' : ''; ?>" href="<?php echo $h($url('esterni')); ?>"<?php echo $tipo === 'esterni' ? ' aria-current="page"' : ''; ?>><i class="fa fa-building me-1" aria-hidden="true"></i>Aziende ed enti</a></li>
        <li class="nav-item"><a class="nav-link<?php echo $tipo === 'interni' ? ' active' : ''; ?>" href="<?php echo $h($url('interni')); ?>"<?php echo $tipo === 'interni' ? ' aria-current="page"' : ''; ?>><i class="fa fa-flask me-1" aria-hidden="true"></i>Laboratori dell'Ateneo</a></li>
    </ul>
    <form method="GET" class="row g-2 mb-3" role="search"><input type="hidden" name="tipo" value="<?php echo $h($tipo); ?>">
        <div class="col-12 col-md"><label class="visually-hidden" for="q">Cerca</label><input class="form-control" id="q" name="q" value="<?php echo $h($cerca); ?>" placeholder="<?php echo $tipo === 'esterni' ? 'Cerca per nome, indirizzo o settore' : 'Cerca per nome, responsabile o argomento'; ?>"></div>
        <?php foreach ($opzioni as $c => $voci): if (!$voci) continue; ?>
        <div class="col-6 col-md-auto"><label class="visually-hidden" for="f_<?php echo $h($c); ?>"><?php echo $h($etichette[$c]); ?></label>
            <select class="form-select" id="f_<?php echo $h($c); ?>" name="<?php echo $h($c); ?>" onchange="this.form.submit()"><option value=""><?php echo $h($etichette[$c]); ?></option>
                <?php foreach ($voci as $v): ?><option value="<?php echo $h($v); ?>"<?php echo $f[$c] === $v ? ' selected' : ''; ?>><?php echo $h($c === 'provincia' ? (\App\Tirocini\Province::nome($v) ?: $v) : (mb_strlen($v) > 60 ? mb_substr($v, 0, 57) . '…' : $v)); ?></option><?php endforeach; ?></select></div>
        <?php endforeach; ?>
        <div class="col-auto"><button class="btn btn-danger" type="submit"><i class="fa fa-search" aria-hidden="true"></i><span class="visually-hidden"> Cerca</span></button>
            <?php if ($filtrato): ?><a class="btn btn-outline-secondary" href="<?php echo $h($url($tipo)); ?>">Azzera</a><?php endif; ?></div>
    </form>
    <?php if (!$elenco): ?>
        <p class="text-muted"><?php echo $filtrato ? 'Nessun risultato per la ricerca.' : 'L\'elenco è in preparazione.'; ?></p>
    <?php else: ?>
    <p class="small text-secondary"><?php echo count($elenco); ?> <?php echo $tipo === 'esterni' ? (count($elenco) === 1 ? 'azienda' : 'aziende') : (count($elenco) === 1 ? 'laboratorio' : 'laboratori'); ?></p>
    <div class="row g-3">
        <?php if ($tipo === 'esterni'): foreach ($elenco as $a):
            $via = trim($a['indirizzo'] . ($a['cap'] !== '' || $a['comune'] !== '' ? ', ' : '') . trim($a['cap'] . ' ' . $a['comune']) . ($a['provincia'] !== '' ? ' (' . $a['provincia'] . ')' : ''), ', ');
            $mappa = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($a['nome'] . ', ' . $via); ?>
        <div class="col-md-6"><div class="card border-0 shadow-sm h-100" style="border-radius:12px;"><div class="card-body">
            <h2 class="h6 fw-bold mb-1"><?php echo $h($a['nome']); ?></h2>
            <?php if ($a['settore'] !== ''): ?><div class="small"><i class="fa fa-tag me-1 text-secondary" aria-hidden="true"></i><?php echo $h($a['settore']); ?></div><?php endif; ?>
            <div class="small"><i class="fa fa-location-dot me-1 text-secondary" aria-hidden="true"></i><?php echo $h($via); ?> · <?php echo $h($a['regione']); ?>
                <a href="<?php echo $h($mappa); ?>" target="_blank" rel="noopener noreferrer" class="ms-1">Mappa<span class="visually-hidden"> di <?php echo $h($a['nome']); ?> (si apre in una nuova scheda)</span></a></div>
            <?php if ($a['sito'] !== ''): ?><div class="small"><i class="fa fa-globe me-1 text-secondary" aria-hidden="true"></i><a href="<?php echo $h($a['sito']); ?>" target="_blank" rel="noopener noreferrer"><?php echo $h(preg_replace('#^https?://(www\.)?#', '', rtrim($a['sito'], '/'))); ?></a></div><?php endif; ?>
        </div></div></div>
        <?php endforeach; else: foreach ($elenco as $l): ?>
        <div class="col-md-6"><div class="card border-0 shadow-sm h-100" style="border-radius:12px;"><div class="card-body">
            <h2 class="h6 fw-bold mb-1"><?php echo $h($l['nome']); ?><?php echo $l['sigla'] !== '' ? ' <span class="text-secondary fw-normal">(' . $h($l['sigla']) . ')</span>' : ''; ?></h2>
            <div class="small"><i class="fa fa-building-columns me-1 text-secondary" aria-hidden="true"></i><?php echo $h(trim(preg_replace('/,?\s*DIBEST$/i', '', $l['dipartimento']))); ?><?php echo (int)$l['interdipartimentale'] ? ' · interdipartimentale' : ''; ?></div>
            <?php if ($l['direttore'] !== ''): ?><div class="small"><i class="fa fa-user me-1 text-secondary" aria-hidden="true"></i>Responsabile scientifico: <?php echo $h($l['direttore']); ?></div><?php endif; ?>
            <?php if ($l['ambiti'] !== ''): ?><div class="small"><i class="fa fa-flask me-1 text-secondary" aria-hidden="true"></i><?php echo $h($l['ambiti']); ?><?php echo $l['area'] !== '' ? ' · ' . $h($l['area']) : ''; ?></div><?php endif; ?>
            <?php $doc_l = array_values(array_filter(array_map('trim', explode(';', (string)$l['docenti'])))); if ($doc_l): ?>
            <div class="small"><i class="fa fa-users me-1 text-secondary" aria-hidden="true"></i>Docenti coinvolti: <?php echo $h(implode(', ', array_slice($doc_l, 0, 5))); ?><?php if (count($doc_l) > 5): ?>
                <details class="d-inline"><summary class="d-inline text-primary" style="cursor:pointer">e altri <?php echo count($doc_l) - 5; ?></summary> <?php echo $h(implode(', ', array_slice($doc_l, 5))); ?></details><?php endif; ?></div><?php endif; ?>
            <?php if ($l['descrizione'] !== ''): $breve_l = mb_strlen($l['descrizione']) > 150; ?>
            <p class="small text-secondary mt-2 mb-0"><?php echo $h($breve_l ? rtrim((string)preg_replace('/\s+\S*$/u', '', mb_substr($l['descrizione'], 0, 150)), ' ,;:.') . '…' : $l['descrizione']); ?></p>
            <?php if ($breve_l): ?><details class="small mt-1"><summary class="text-primary" style="cursor:pointer">Leggi tutta la descrizione</summary><p class="text-secondary mt-1 mb-0"><?php echo $h($l['descrizione']); ?></p></details><?php endif; ?>
            <?php endif; ?>
        </div></div></div>
        <?php endforeach; endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php require_once 'footer.php'; ?>
