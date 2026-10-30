<?php
// tirocini_motorie.php - Tirocini di Scienze motorie (visibile dopo l'accesso): per ogni azienda convenzionata se è libera o satura e quando si libera un posto.
// Nessun nome di studente: i dati si compilano da admin/tirocini_motorie.php. Logica in src/Tirocini/ServizioMotorie.php.
require_once 'config.php';
require_once 'functions.php';
require_once 'accesso_richiesto.php';
richiedi_accesso($conn, 'Tirocini di Scienze motorie', 'Qui vedi quali aziende hanno ancora posti per il tirocinio e quando se ne libera uno.'); // solo dopo l'accesso

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$aziende = \App\Core\App::per($conn)->get(\App\Tirocini\ServizioMotorie::class)->stato();
$cerca = trim((string)($_GET['q'] ?? ''));
$solo = ($_GET['solo'] ?? '') === 'libere';
$aziende = array_values(array_filter($aziende, fn(array $a): bool => (!$solo || $a['stato'] !== 'satura') && ($cerca === '' || mb_stripos($a['nome'] . ' ' . $a['comune'] . ' ' . $a['settore'], $cerca) !== false)));
$etichetta = ['libera' => ['success', 'Libera', 'fa-circle-check'], 'ultimo' => ['warning', 'Ultimo posto', 'fa-circle-exclamation'], 'satura' => ['danger', 'Satura', 'fa-circle-xmark']];
$data_it = fn(string $d) => date('d/m/Y', strtotime($d));
$page_cfg['titolo'] = 'Tirocini di Scienze motorie';
require_once 'header.php';
?>
<div class="container my-4" style="max-width: 1000px;">
    <nav aria-label="Percorso" class="mb-3 small"><a href="index.php">Home</a> <span class="text-secondary mx-1">/</span> <a href="tirocini.php">Tirocini curriculari</a> <span class="text-secondary mx-1">/</span> Scienze motorie</nav>
    <h1 class="fw-bold mb-1">Tirocini di Scienze motorie</h1>
    <p class="text-secondary">Ogni azienda può accogliere solo un certo numero di tirocinanti insieme (di solito due). Qui vedi quali sono libere e, per quelle sature, da quando si libera un posto. Prima di accordarti con un'azienda controlla che non sia satura.</p>
    <form method="GET" class="row g-2 mb-3" role="search">
        <div class="col-12 col-md"><label class="visually-hidden" for="q">Cerca</label><input class="form-control" id="q" name="q" value="<?php echo $h($cerca); ?>" placeholder="Cerca per nome, comune o settore"></div>
        <div class="col-auto d-flex align-items-center"><div class="form-check"><input class="form-check-input" type="checkbox" id="solo" name="solo" value="libere"<?php echo $solo ? ' checked' : ''; ?> onchange="this.form.submit()"><label class="form-check-label" for="solo">Solo con posti liberi</label></div></div>
        <div class="col-auto"><button class="btn btn-danger" type="submit"><i class="fa fa-search" aria-hidden="true"></i><span class="visually-hidden"> Cerca</span></button>
            <?php if ($cerca !== '' || $solo): ?><a class="btn btn-outline-secondary" href="tirocini_motorie.php">Azzera</a><?php endif; ?></div>
    </form>
    <?php if (!$aziende): ?>
        <p class="text-muted"><?php echo $cerca !== '' || $solo ? 'Nessuna azienda corrisponde alla ricerca.' : 'L\'elenco è in preparazione.'; ?></p>
    <?php else: ?>
    <div class="list-group mb-3">
        <?php foreach ($aziende as $a): [$cls, $txt, $ico] = $etichetta[$a['stato']]; ?>
        <div class="list-group-item d-flex flex-wrap gap-2 align-items-center justify-content-between">
            <div><strong><?php echo $h($a['nome']); ?></strong>
                <div class="small text-secondary"><?php echo $h(trim($a['comune'] . ($a['provincia'] !== '' ? ' (' . $a['provincia'] . ')' : '') . ($a['settore'] !== '' ? ' · ' . $a['settore'] : ''), ' ·')); ?></div></div>
            <div class="text-end">
                <span class="badge text-bg-<?php echo $cls; ?> fs-6 fw-normal"><i class="fa <?php echo $ico; ?> me-1" aria-hidden="true"></i><?php echo $txt; ?></span>
                <div class="small text-secondary mt-1"><?php
                    if ($a['stato'] === 'satura') {
                        echo $a['libera_dal'] ? 'Un posto si libera dal ' . $h($data_it($a['libera_dal'])) : 'Posti pieni';
                    } else {
                        echo (int)$a['liberi'] . ($a['liberi'] === 1 ? ' posto libero' : ' posti liberi') . ' su ' . (int)$a['posti'];
                    } ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <p class="small text-secondary">L'elenco è aggiornato dall'ufficio tirocini: un posto conta dal momento in cui la richiesta è approvata, anche se il tirocinio non è ancora cominciato. L'attivazione del tirocinio si fa dalla piattaforma <a href="https://unical.tsp.esse3.cineca.it" target="_blank" rel="noopener">TSP 2.0</a>.</p>
</div>
<?php require_once 'footer.php'; ?>
