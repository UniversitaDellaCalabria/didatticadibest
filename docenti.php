<?php
// docenti.php - Cerca un docente del Dipartimento (dall'anagrafe di Ateneo) e, se ha uno sportello di ricevimento attivo, prenota l'appuntamento.
// Gli sportelli li crea la segreteria in Prenotazioni e risorse (Risorse › scelta del docente); qui si leggono soltanto.
require_once 'config.php';
require_once 'functions.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$db = \App\Core\App::per($conn)->get(\App\Core\Database::class);
$cerca = trim((string)($_GET['q'] ?? ''));
$solo_ric = !empty($_GET['ricevimento']);

// Sportelli di ricevimento attivi, per docente
$sportelli = [];
foreach ($db->righe('SELECT r.id, r.nome, r.persona_id, p.slug FROM risorse r JOIN pagine_eventi p ON p.id = r.pagina_id WHERE r.persona_id IS NOT NULL AND r.persona_id <> \'\' AND r.attiva = 1 AND IFNULL(p.visibile, 1) = 1 ORDER BY r.id') as $s) {
    $sportelli[(string)$s['persona_id']][] = $s;
}

$where = ["docente = 1", "attivo = 1", "gruppo = 'docenti'"];
$par = [];
foreach (array_filter(preg_split('/\s+/u', $cerca)) as $w) {
    $lk = '%' . addcslashes($w, '%_\\') . '%';
    $where[] = '(cognome LIKE ? OR nome LIKE ? OR ssd LIKE ? OR ssd_cod LIKE ?)';
    array_push($par, $lk, $lk, $lk, $lk);
}
$docenti = $db->righe('SELECT id, cognome, nome, email, ruolo, ssd, ssd_cod FROM personale_ateneo WHERE ' . implode(' AND ', $where) . ' ORDER BY cognome, nome LIMIT 300', $par);
if ($solo_ric) { $docenti = array_values(array_filter($docenti, fn($d) => !empty($sportelli[(string)$d['id']]))); }
$n_con_ric = count($sportelli);

$page_cfg['titolo'] = 'Docenti e ricevimento';
require_once 'header.php';
?>
<style>
.dc-card { border: 1px solid #e2e8f0; border-radius: 14px; background: #fff; padding: 16px 18px; height: 100%; display: flex; flex-direction: column; }
.dc-card h2 { font-size: 1.1rem; font-weight: 700; margin: 0; }
.dc-card .dc-sub { color: #546072; font-size: .92rem; }
.dc-card .mt-auto { padding-top: 10px; }
</style>
<div class="container my-4" style="max-width: 1000px;">
    <nav aria-label="Percorso" class="mb-3 small"><a href="index.php">Home</a> <span class="text-secondary mx-1">/</span> Docenti e ricevimento</nav>
    <h1 class="fw-bold mb-1">Docenti e ricevimento</h1>
    <p class="text-secondary">Cerca un docente per cognome, nome o settore e, se ha uno sportello attivo, prenota il ricevimento. Per gli altri scrivi un'email per fissare un appuntamento.</p>

    <form method="GET" class="row g-2 align-items-center mb-4" role="search">
        <div class="col-md-7"><label class="visually-hidden" for="dq">Cerca un docente</label><input class="form-control form-control-lg" id="dq" name="q" value="<?php echo $h($cerca); ?>" placeholder="Cognome, nome o settore (es. Rossi, BIO/05)" autocomplete="off"></div>
        <div class="col-auto"><div class="form-check"><input class="form-check-input" type="checkbox" name="ricevimento" value="1" id="dric" <?php echo $solo_ric ? 'checked' : ''; ?>><label class="form-check-label" for="dric">Solo con prenotazione online</label></div></div>
        <div class="col-auto"><button class="btn btn-danger btn-lg px-4" type="submit"><i class="fa fa-search me-1" aria-hidden="true"></i>Cerca</button></div>
    </form>

    <?php if (!$docenti): ?>
        <div class="alert alert-light border"><?php echo $cerca !== '' || $solo_ric ? 'Nessun docente corrisponde alla ricerca.' : 'L\'elenco dei docenti non è ancora disponibile.'; ?> Puoi <a href="contatti.php">consultare a chi rivolgerti</a> o <a href="assistenza.php">scrivere all'assistenza</a>.</div>
    <?php else: ?>
        <p class="small text-secondary"><?php echo count($docenti); ?> <?php echo count($docenti) === 1 ? 'docente' : 'docenti'; ?><?php echo $n_con_ric ? ' · ' . $n_con_ric . ' con prenotazione online del ricevimento' : ''; ?></p>
        <div class="row g-3">
        <?php foreach ($docenti as $d): $sp = $sportelli[(string)$d['id']] ?? []; ?>
            <div class="col-md-6"><div class="dc-card">
                <h2><?php echo $h(trim($d['cognome'] . ' ' . $d['nome'])); ?></h2>
                <div class="dc-sub"><?php echo $h(implode(' · ', array_filter([$d['ruolo'], $d['ssd_cod'] !== '' ? $d['ssd_cod'] : ''], fn($x) => $x !== ''))); ?></div>
                <?php if ($d['email'] !== ''): ?><div class="mt-1"><i class="fa fa-envelope text-danger me-1" aria-hidden="true"></i><a href="mailto:<?php echo $h($d['email']); ?>"><?php echo $h($d['email']); ?></a></div><?php endif; ?>
                <div class="mt-auto d-flex flex-wrap gap-2">
                    <?php foreach ($sp as $s): ?><a class="btn btn-sm btn-danger fw-bold" href="<?php echo $h($s['slug'] . '.php?risorsa=' . (int)$s['id']); ?>"><i class="fa fa-user-clock me-1" aria-hidden="true"></i>Prenota il ricevimento<?php echo count($sp) > 1 ? ': ' . $h($s['nome']) : ''; ?></a><?php endforeach; ?>
                    <?php if (!$sp): ?><span class="small text-secondary align-self-center">Ricevimento su appuntamento: scrivi un'email.</span><?php endif; ?>
                </div>
            </div></div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php require_once 'footer.php'; ?>
