<?php
// agenda.php - Agenda unica di eventi e seminari di tutte le aree, divisa per mese, con i filtri per ambito
// (Orientamento, Ricerca, Public engagement, Didattica), «per le scuole» e la ricerca; il calendario .ics dello stesso
// filtro è agenda_ics.php. ?ambito=ricerca, ?scuole=1, ?q=… Logica in inc/agenda.php.
require_once 'config.php';
require_once 'functions.php';
// Un'area creata prima con l'indirizzo «agenda» resta raggiungibile come prima
if (\App\Core\App::per($conn)->get(\App\Eventi\AreaRepository::class)->idPerSlug('agenda')) { $_GET['slug'] = 'agenda'; require __DIR__ . '/area.php'; exit; }

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$ambito = isset(AMBITI_EVENTO[$_GET['ambito'] ?? '']) ? $_GET['ambito'] : '';
$scuole = !empty($_GET['scuole']);
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);

$tutti = eventi_agenda($conn);
$n = conta_ambiti_agenda($tutti);
$eventi = eventi_agenda($conn, ['ambito' => $ambito, 'scuole' => $scuole, 'q' => $q]);
$posti = get_riepilogo_posti($conn, 'evento', array_column($eventi, 'id'));
$mesi = [1 => 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];
$per_mese = [];
foreach ($eventi as $e) $per_mese[$e['prossima_data'] ? $mesi[(int)date('n', strtotime($e['prossima_data']))] . ' ' . date('Y', strtotime($e['prossima_data'])) : 'Data da definire'][] = $e;
$filtro = fn(array $p) => 'agenda.php' . (($qs = http_build_query(array_filter($p))) !== '' ? '?' . $qs : '');
$titolo = $ambito !== '' ? AMBITI_EVENTO[$ambito]['nome'] : ($scuole ? 'Per le scuole' : 'Eventi e seminari');

$page_cfg['titolo'] = $titolo === 'Eventi e seminari' ? 'Eventi e seminari' : 'Eventi e seminari · ' . $titolo;
require_once 'header.php';
echo css_agenda();
?>
<div class="container my-4" style="max-width: 1000px;">
    <nav aria-label="Percorso" class="mb-3 small"><a href="index.php">Home</a> <span class="text-secondary mx-1">/</span> <?php echo $ambito !== '' || $scuole ? '<a href="agenda.php">Eventi e seminari</a> <span class="text-secondary mx-1">/</span> ' : ''; ?><span class="text-secondary"><?php echo $h($titolo); ?></span></nav>
    <div class="d-flex flex-wrap align-items-end gap-2 mb-2">
        <div class="flex-grow-1">
            <h1 class="fw-bold mb-1"><?php echo $h($titolo); ?></h1>
            <p class="text-secondary mb-0"><?php echo $ambito !== '' ? $h(AMBITI_EVENTO[$ambito]['descr']) . ': tutti gli appuntamenti in programma.' : 'Tutti gli appuntamenti del Dipartimento: orientamento, seminari di ricerca, eventi aperti alla cittadinanza e attività per gli studenti.'; ?></p>
        </div>
        <a class="btn btn-sm btn-outline-secondary" href="<?php echo $h(str_replace('agenda.php', 'agenda_ics.php', $filtro(['ambito' => $ambito, 'scuole' => $scuole ? 1 : 0]))); ?>" title="Aggiungi a Google Calendar, Outlook o al calendario del telefono"><i class="fa fa-calendar-plus me-1" aria-hidden="true"></i>Aggiungi al tuo calendario</a>
    </div>
    <nav class="ag-filtri my-3" aria-label="Filtra per ambito">
        <a href="<?php echo $h($filtro(['q' => $q])); ?>" class="<?php echo $ambito === '' && !$scuole ? 'attivo' : ''; ?>"<?php echo $ambito === '' && !$scuole ? ' aria-current="page"' : ''; ?>>Tutti<span class="n"><?php echo $n['']; ?></span></a>
        <?php foreach (AMBITI_EVENTO as $k => $a): if (!$n[$k] && $ambito !== $k) continue; ?>
            <a href="<?php echo $h($filtro(['ambito' => $k, 'q' => $q])); ?>" class="<?php echo $ambito === $k ? 'attivo' : ''; ?>"<?php echo $ambito === $k ? ' aria-current="page"' : ''; ?>><i class="fa <?php echo $a['icona']; ?> me-1" aria-hidden="true"></i><?php echo $h($a['nome']); ?><span class="n"><?php echo $n[$k]; ?></span></a>
        <?php endforeach; ?>
        <?php if ($n['scuole'] || $scuole): ?><a href="<?php echo $h($filtro(['scuole' => 1, 'q' => $q])); ?>" class="<?php echo $scuole ? 'attivo' : ''; ?>"><i class="fa fa-school me-1" aria-hidden="true"></i>Per le scuole<span class="n"><?php echo $n['scuole']; ?></span></a><?php endif; ?>
    </nav>
    <form method="GET" class="mb-3" role="search">
        <?php if ($ambito !== ''): ?><input type="hidden" name="ambito" value="<?php echo $h($ambito); ?>"><?php endif; ?>
        <?php if ($scuole): ?><input type="hidden" name="scuole" value="1"><?php endif; ?>
        <label class="visually-hidden" for="agQ">Cerca un evento</label>
        <div class="input-group" style="max-width:420px;"><input type="search" class="form-control" id="agQ" name="q" value="<?php echo $h($q); ?>" placeholder="Cerca per titolo, luogo, area"><button class="btn btn-outline-secondary" type="submit" aria-label="Cerca"><i class="fa fa-search" aria-hidden="true"></i></button></div>
    </form>
    <?php if (!$eventi): ?>
        <div class="card border-0 shadow-sm"><div class="card-body text-center text-secondary py-5"><i class="fa fa-calendar-xmark fa-2x mb-2 d-block" aria-hidden="true"></i>Nessun appuntamento in programma<?php echo $q !== '' ? ' per «' . $h($q) . '»' : ($ambito !== '' ? ' in questo ambito' : ''); ?>. <a href="agenda.php">Vedi tutta l'agenda</a></div></div>
    <?php endif; ?>
    <?php foreach ($per_mese as $mese => $voci): ?>
        <h2 class="h6 fw-bold text-uppercase text-secondary mt-4 mb-2" style="letter-spacing:.05em;"><?php echo $h($mese); ?></h2>
        <div class="card border-0 shadow-sm overflow-hidden" style="border-radius:12px;">
            <?php foreach ($voci as $e) echo html_voce_agenda($e, $posti[(int)$e['id']] ?? null); ?>
        </div>
    <?php endforeach; ?>
    <div class="alert d-flex flex-wrap align-items-center gap-2 mt-4" style="background:#eff6ff;border:1px solid #bfdbfe;">
        <i class="fa fa-bell fs-4 text-primary" aria-hidden="true"></i>
        <div class="flex-grow-1"><strong>Ricevi gli avvisi dei nuovi eventi</strong><div class="small text-secondary">Un'email quando esce un nuovo evento <?php echo $ambito !== '' ? 'di ' . $h(AMBITI_EVENTO[$ambito]['nome']) : 'degli argomenti che scegli'; ?>.</div></div>
        <a class="btn btn-sm btn-primary fw-bold" href="avvisi.php<?php echo $ambito !== '' ? '?ambito=' . $h($ambito) : ($scuole ? '?scuole=1' : ''); ?>">Iscriviti</a>
    </div>
    <p class="small text-secondary mt-4"><i class="fa fa-box-archive me-1" aria-hidden="true"></i>Gli eventi passati restano nell'archivio di ciascuna area.</p>
</div>
<?php require_once 'footer.php'; ?>
