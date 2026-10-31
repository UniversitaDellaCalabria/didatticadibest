<?php
// cruscotto_direttore.php - Il cruscotto del Direttore (Area personale): in una pagina le cose che aspettano la sua firma e quelle che si sono fermate
// (pratiche degli studenti, conferme dei docenti, verbali, convenzioni, tutorato, risorse). In testa «Firma ora» per i documenti da firmare; ogni riga porta all'azione. Il gruppo dei pagamenti del tutorato compare solo con l'ufficio pagamenti acceso. Logica in src/Portale/CruscottoDirettore.php.
require_once __DIR__ . '/middleware.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$cru = \App\Core\App::per($conn)->get(\App\Portale\CruscottoDirettore::class);
$ok = $cru->puoVedere($user_info ?? null);

$page_cfg['titolo'] = 'Cruscotto del Direttore';
require_once 'header.php';
?>
<div class="container my-4" style="max-width: 1100px;">
<?php if (!$ok): ?>
    <h1 class="fw-bold h3">Cruscotto non disponibile</h1><p>Il cruscotto è per il Direttore del Dipartimento e per gli amministratori del portale.</p>
    <a class="btn btn-outline-secondary" href="area_personale.php">Area personale</a>
<?php else:
    $gruppi = $cru->gruppi();
    $tot = array_sum(array_column($gruppi, 'n'));
    $giorni_ferma = \App\Portale\CruscottoDirettore::GIORNI_FERMA;
?>
    <style>
    .cd-gruppo { border: 0; border-radius: 14px; box-shadow: 0 1px 6px rgba(15,23,42,.08); }
    .cd-num { min-width: 2.6rem; height: 2.6rem; border-radius: 50%; color: #fff; display: inline-flex; align-items: center; justify-content: center; font-weight: 800; }
    .cd-riga { border-top: 1px solid #e2e8f0; padding: .55rem 0; }
    </style>
    <nav aria-label="Percorso" class="mb-3 small"><a href="area_personale.php">Area personale</a> <span class="text-secondary mx-1">/</span> <span class="text-secondary">Cruscotto del Direttore</span></nav>
    <div class="d-flex flex-wrap gap-2 align-items-end mb-3">
        <div><h1 class="fw-bold h3 mb-0"><i class="fa fa-gauge-high me-2" style="color:#0f2a4a;" aria-hidden="true"></i>Cruscotto del Direttore</h1>
            <div class="text-secondary small">Le cose che aspettano la tua firma o che si sono fermate, in tutti i moduli del portale · aggiornato alle <?php echo date('H:i'); ?></div></div>
        <span class="ms-auto badge rounded-pill text-bg-<?php echo $tot ? 'warning' : 'success'; ?> fs-6"><?php echo $tot ? $tot . ' da guardare' : 'Tutto in ordine'; ?></span>
    </div>
    <?php $da_firmare_c = 0; try { $da_firmare_c = count(\App\Core\App::per($conn)->get(\App\Firme\ScrivaniaFirme::class)->documenti($user_info ?? null)); } catch (\Throwable $e_c) { error_log('[Cruscotto] firme non disponibili: ' . $e_c->getMessage()); }
    if ($da_firmare_c > 0): ?>
    <div class="alert alert-danger d-flex flex-wrap gap-2 align-items-center py-2"><i class="fa fa-file-signature" aria-hidden="true"></i><span><?php echo $da_firmare_c === 1 ? 'Un documento aspetta la tua firma' : $da_firmare_c . ' documenti aspettano la tua firma'; ?>.</span><a class="btn btn-sm btn-danger fw-bold ms-auto" href="firme.php">Firma ora (<?php echo $da_firmare_c; ?>)</a></div>
    <?php endif; ?>
    <?php $dz_c = \App\Core\App::per($conn)->get(\App\Uffici\Direzione::class); $ass_c = $dz_c->assenza(); $firma_c = $dz_c->chiFirma(); ?>
    <div class="alert alert-<?php echo $ass_c['attiva'] ? 'warning' : 'light border'; ?> small d-flex flex-wrap gap-2 align-items-center py-2">
        <i class="fa fa-user-clock" aria-hidden="true"></i>
        <span><?php echo $ass_c['attiva'] ? '<strong>Direttore assente</strong>: firme, link e avvisi vanno a ' . $h($firma_c['nome'] ?: 'il vice direttore') . ($ass_c['fino'] ? ' fino al ' . $h(date('d/m/Y', strtotime($ass_c['fino']))) : '') . '.' : 'Firme e avvisi vanno a <strong>' . $h($firma_c['nome'] ?: '(Direttore non indicato)') . '</strong>.'; ?></span>
        <?php if ($dz_c->puoImpostareAssenza($user_info ?? null)): ?><a class="ms-auto fw-bold ui-tocco" href="direttore_assente.php"><?php echo $ass_c['attiva'] ? 'Il Direttore è tornato' : 'Direttore assente?'; ?></a><?php endif; ?>
    </div>
    <div class="row g-3">
    <?php foreach ($gruppi as $g): ?>
        <div class="col-lg-6"><div class="card cd-gruppo h-100" id="g-<?php echo $h($g['chiave']); ?>" style="border-left: 5px solid <?php echo $h($g['colore']); ?> !important;"><div class="card-body">
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="cd-num" style="background:<?php echo $g['n'] ? $h($g['colore']) : '#94a3b8'; ?>;"><?php echo (int)$g['n']; ?></span>
                <h2 class="h6 fw-bold mb-0"><i class="fa <?php echo $h($g['icona']); ?> me-1" style="color:<?php echo $h($g['colore']); ?>;" aria-hidden="true"></i><?php echo $h($g['titolo']); ?></h2>
                <?php if ($g['link'] !== '' && $g['n']): ?><a class="ms-auto small ui-tocco" href="<?php echo $h($g['link']); ?>">Apri</a><?php endif; ?>
            </div>
            <?php if (!$g['righe']): ?><p class="small text-secondary mb-0 mt-2"><i class="fa fa-circle-check text-success me-1" aria-hidden="true"></i><?php echo $h($g['vuoto']); ?></p>
            <?php else: foreach ($g['righe'] as $r): ?>
                <a class="cd-riga d-block text-decoration-none text-dark" href="<?php echo $h($r['link']); ?>">
                    <div class="d-flex gap-2 align-items-baseline"><span class="fw-semibold small flex-grow-1"><?php echo $h($r['testo']); ?></span>
                        <?php if ($r['giorni'] >= $giorni_ferma): ?><span class="badge text-bg-danger"><?php echo (int)$r['giorni']; ?> giorni</span><?php elseif ($r['giorni'] > 0): ?><span class="badge text-bg-light border"><?php echo (int)$r['giorni']; ?> g</span><?php endif; ?></div>
                    <div class="small text-secondary"><?php echo $h($r['dettaglio']); ?></div>
                </a>
            <?php endforeach; if ($g['n'] > count($g['righe'])): ?><div class="small text-secondary pt-2">… e altre <?php echo $g['n'] - count($g['righe']); ?>.</div><?php endif; endif; ?>
        </div></div></div>
    <?php endforeach; ?>
    </div>
    <p class="small text-secondary mt-3 mb-0">Le righe con il numero di giorni in rosso aspettano da <?php echo $giorni_ferma; ?> giorni o più. Il cruscotto non cambia nulla: serve a vedere dove intervenire.</p>
<?php endif; ?>
</div>
<?php require_once 'footer.php'; ?>
