<?php
// modulistica.php - Modulistica della didattica: documenti da scaricare e moduli online (con accesso) divisi per categoria.
require_once 'config.php';
require_once 'functions.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$moduli = [];
$r = @$conn->query("SELECT * FROM didattica_moduli WHERE attivo = 1 ORDER BY categoria, ordine, titolo");
while ($r && $x = $r->fetch_assoc()) $moduli[$x['categoria']][] = $x;
$loggato = !empty($_SESSION['utente_id']);
$q = mb_strtolower(trim((string)($_GET['q'] ?? '')));

$page_cfg['titolo'] = 'Modulistica';
require_once 'header.php';
?>
<style>
.mod-voce { display: flex; gap: .9rem; align-items: flex-start; padding: .9rem 0; border-top: 1px solid #e5e7eb; }
.mod-voce:first-child { border-top: 0; }
.mod-ico { width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 1.1rem; }
</style>
<div class="container my-4" style="max-width: 1000px;">
    <nav aria-label="Percorso" class="mb-3 small"><a href="index.php">Home</a> <span class="text-secondary mx-1">/</span> <span class="text-secondary">Modulistica</span></nav>
    <h1 class="fw-bold mb-1">Modulistica</h1>
    <p class="text-secondary">Documenti da scaricare e richieste da compilare online. Le richieste online aprono una <strong>pratica</strong> che puoi seguire dalla tua <a href="pratiche.php">Area personale → Le mie pratiche</a>.</p>
    <?php foreach (sportelli_ufficio_didattica($conn, true) as $sp): ?>
        <div class="alert d-flex flex-wrap align-items-center gap-2" style="background:#f5f3ff;border:1px solid #ddd6fe;">
            <i class="fa fa-user-clock fs-4" style="color:#7c3aed;" aria-hidden="true"></i>
            <div class="flex-grow-1"><strong><?php echo $h($sp['nome']); ?></strong><?php echo $sp['luogo'] ? '<div class="small text-secondary">' . $h($sp['luogo']) . '</div>' : ''; ?></div>
            <a href="<?php echo $h($sp['area_slug']); ?>.php?risorsa=<?php echo (int)$sp['id']; ?>" class="btn btn-sm fw-bold text-white" style="background:#7c3aed;">Prenota un appuntamento</a>
        </div>
    <?php endforeach; ?>
    <form method="GET" class="mb-3" role="search"><label class="visually-hidden" for="modQ">Cerca un modulo</label>
        <div class="input-group" style="max-width:420px;"><input type="search" class="form-control" id="modQ" name="q" value="<?php echo $h($_GET['q'] ?? ''); ?>" placeholder="Cerca un modulo"><button class="btn btn-outline-secondary" aria-label="Cerca"><i class="fa fa-search" aria-hidden="true"></i></button></div></form>
    <?php if (!$moduli): ?><div class="alert alert-info">Al momento non ci sono moduli pubblicati.</div><?php endif; ?>
    <?php foreach ($moduli as $cat => $voci):
        if ($q !== '') $voci = array_filter($voci, fn($m) => str_contains(mb_strtolower($m['titolo'] . ' ' . strip_tags((string)$m['descrizione']) . ' ' . $cat), $q));
        if (!$voci) continue; ?>
        <section class="card border-0 shadow-sm mb-3" style="border-radius:12px;"><div class="card-body">
            <h2 class="h5 fw-bold mb-1"><?php echo $h($cat); ?></h2>
            <?php foreach ($voci as $m): $online = $m['tipo'] === 'online'; [$aperto, $periodo] = periodo_modulo($m); ?>
                <div class="mod-voce">
                    <span class="mod-ico" style="background:<?php echo $online ? '#dcfce7' : '#dbeafe'; ?>;color:<?php echo $online ? '#15803d' : '#1d4ed8'; ?>;" aria-hidden="true"><i class="fa <?php echo $online ? 'fa-pen-to-square' : 'fa-file-arrow-down'; ?>"></i></span>
                    <div class="flex-grow-1" style="min-width:0;">
                        <h3 class="h6 fw-bold mb-1"><?php echo $h($m['titolo']); ?><?php if ($online && $periodo !== ''): ?> <span class="badge <?php echo $aperto ? 'bg-light text-dark border' : 'bg-secondary'; ?> fw-normal"><?php echo $h($periodo); ?></span><?php endif; ?></h3>
                        <?php if (trim(strip_tags((string)$m['descrizione'])) !== ''): ?><div class="small text-secondary"><?php echo strip_tags((string)$m['descrizione'], '<p><br><strong><em><ul><ol><li><a>'); ?></div><?php endif; ?>
                        <div class="d-flex flex-wrap gap-2 mt-2">
                            <?php if ($online && $aperto): ?>
                                <a href="modulo.php?id=<?php echo (int)$m['id']; ?>" class="btn btn-sm btn-success fw-bold"><i class="fa fa-pen me-1" aria-hidden="true"></i><?php echo $loggato ? 'Compila online' : 'Accedi e compila'; ?></a>
                            <?php endif; ?>
                            <?php if ($m['file_path']): ?><a href="<?php echo $h($m['file_path']); ?>" class="btn btn-sm btn-outline-primary fw-bold" download><i class="fa fa-download me-1" aria-hidden="true"></i>Scarica (<?php echo strtoupper(pathinfo($m['file_path'], PATHINFO_EXTENSION)); ?>)</a><?php endif; ?>
                            <?php if ($m['link']): ?><a href="<?php echo $h($m['link']); ?>" class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener"><i class="fa fa-up-right-from-square me-1" aria-hidden="true"></i>Approfondisci<span class="visually-hidden"> (si apre in una nuova scheda)</span></a><?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div></section>
    <?php endforeach; ?>
</div>
<?php require_once 'footer.php'; ?>
