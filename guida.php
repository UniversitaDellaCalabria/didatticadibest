<?php
// guida.php - Le guide «Come si fa» per ruolo (studente, docente, uffici, referente del consiglio, responsabile di risorsa, tutor, Direttore, amministratore).
//   guida.php            elenco dei ruoli
//   guida.php?r=ruolo    la guida del ruolo, con le schermate
//   guida.php?r=ruolo&stampa=1   versione da stampare (è la fonte dei PDF in documentazione/guide/)
// Il testo sta in src/Portale/GuidePerRuolo.php. La pagina è pubblica: non mostra dati.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
// Il testo delle guide contiene solo qualche <b> e <code> scritti da noi
$t = fn($s) => preg_replace('~&lt;(/?)(b|code)&gt;~', '<$1$2>', $h($s));
$guide = \App\Portale\GuidePerRuolo::tutte();
$r = (string)($_GET['r'] ?? '');
$g = $guide[$r] ?? null;
$stampa = $g && !empty($_GET['stampa']);

if ($stampa):
?><!doctype html>
<html lang="it"><head><meta charset="utf-8"><title>Guida · <?php echo $h($g['titolo']); ?> · Didattica DiBEST</title>
<style>
@page { size: A4; margin: 16mm 14mm 16mm; }
:root { --c: <?php echo $h($g['colore']); ?>; --ink: #1e293b; --mut: #64748b; --linea: #e2e8f0; }
* { box-sizing: border-box; }
body { margin: 0; font-family: "Segoe UI", "Helvetica Neue", Arial, sans-serif; color: var(--ink); font-size: 11.5pt; line-height: 1.45; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.testata { border-bottom: 4px solid var(--c); padding-bottom: 10px; margin-bottom: 14px; }
.testata small { color: var(--mut); letter-spacing: .06em; text-transform: uppercase; font-size: 8.5pt; font-weight: 700; }
h1 { margin: 2px 0 4px; font-size: 24pt; color: var(--c); } .a-chi { color: var(--mut); margin: 0; }
.breve { background: #f1f5f9; border-left: 5px solid var(--c); padding: 10px 14px; border-radius: 6px; margin: 12px 0 6px; }
h2 { font-size: 14.5pt; margin: 18px 0 6px; break-after: avoid; } h2 span { display: inline-flex; width: 24px; height: 24px; align-items: center; justify-content: center; background: var(--c); color: #fff; border-radius: 6px; font-size: 11pt; margin-right: 8px; }
ol { margin: 6px 0 8px; padding-left: 22px; } li { margin-bottom: 4px; }
figure { margin: 8px 0 10px; break-inside: avoid; } figure img { width: 100%; border: 1px solid var(--linea); border-radius: 6px; display: block; } figcaption { font-size: 9pt; color: var(--mut); margin-top: 3px; }
.nota { background: #fff7e6; border: 1px solid #f3d9a4; border-radius: 6px; padding: 7px 12px; font-size: 10.5pt; color: #6b4a0e; margin: 6px 0; break-inside: avoid; }
code { font-family: Consolas, monospace; font-size: 9.5pt; background: #f1f5f9; padding: 1px 4px; border-radius: 3px; word-break: break-all; }
.piede { margin-top: 18px; border-top: 1px solid var(--linea); padding-top: 6px; font-size: 8.5pt; color: var(--mut); }
</style></head><body>
<div class="testata"><small>Didattica DiBEST · Come si fa · aggiornata a <?php echo ['gennaio','febbraio','marzo','aprile','maggio','giugno','luglio','agosto','settembre','ottobre','novembre','dicembre'][(int)date('n') - 1] . date(' Y'); ?> · versione sempre aggiornata nel portale (guida.php)</small><h1><?php echo $h($g['titolo']); ?></h1><p class="a-chi"><?php echo $h($g['a_chi']); ?></p></div>
<div class="breve"><?php echo $h($g['in_breve']); ?></div>
<?php foreach ($g['sezioni'] as $i => $s): ?>
    <h2><span><?php echo $i + 1; ?></span><?php echo $h($s['titolo']); ?></h2>
    <?php if (!empty($s['immagine'])): ?><figure><img src="assets/guide/<?php echo $h($s['immagine']); ?>" alt=""><?php if (!empty($s['didascalia'])): ?><figcaption><?php echo $h($s['didascalia']); ?></figcaption><?php endif; ?></figure><?php endif; ?>
    <ol><?php foreach ($s['passi'] as $p): ?><li><?php echo $t($p); ?></li><?php endforeach; ?></ol>
    <?php if (!empty($s['nota'])): ?><div class="nota"><?php echo $t($s['nota']); ?></div><?php endif; ?>
<?php endforeach; ?>
</body></html>
<?php
    exit;
endif;

$page_cfg['titolo'] = $g ? 'Guida · ' . $g['titolo'] : 'Come si fa';
require_once 'header.php';
?>
<style>
.gd-card { border: 0; border-radius: 14px; box-shadow: 0 1px 6px rgba(15,23,42,.08); transition: transform .12s; }
.gd-card:hover, .gd-card:focus-visible { transform: translateY(-2px); box-shadow: 0 4px 14px rgba(15,23,42,.14); }
.gd-num { display: inline-flex; width: 30px; height: 30px; align-items: center; justify-content: center; border-radius: 8px; color: #fff; font-weight: 700; margin-right: 8px; }
.gd-fig img { max-width: 100%; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 1px 6px rgba(15,23,42,.1); }
</style>
<div class="container my-4" style="max-width: 1000px;">
<?php if (!$g): ?>
    <h1 class="fw-bold h3"><i class="fa fa-circle-question me-2" aria-hidden="true"></i>Come si fa</h1>
    <p class="text-secondary">Una guida breve per ogni ruolo, con le schermate. Scegli quella che ti serve.</p>
    <div class="row g-3">
    <?php foreach ($guide as $k => $x): ?>
        <div class="col-md-6"><a class="card gd-card h-100 text-decoration-none text-dark" href="guida.php?r=<?php echo $h($k); ?>" style="border-left: 5px solid <?php echo $h($x['colore']); ?> !important;"><div class="card-body d-flex gap-3">
            <i class="fa <?php echo $h($x['icona']); ?> fs-3" style="color:<?php echo $h($x['colore']); ?>;" aria-hidden="true"></i>
            <div><div class="fw-bold"><?php echo $h($x['titolo']); ?></div><div class="small text-secondary"><?php echo $h($x['a_chi']); ?></div></div>
        </div></a></div>
    <?php endforeach; ?>
    </div>
<?php else: ?>
    <nav aria-label="Percorso" class="mb-3 small"><a href="guida.php">Come si fa</a> <span class="text-secondary mx-1">/</span> <span class="text-secondary"><?php echo $h($g['titolo']); ?></span></nav>
    <div class="d-flex flex-wrap gap-2 align-items-start mb-3">
        <div><h1 class="fw-bold h3 mb-1"><i class="fa <?php echo $h($g['icona']); ?> me-2" style="color:<?php echo $h($g['colore']); ?>;" aria-hidden="true"></i><?php echo $h($g['titolo']); ?></h1>
            <div class="text-secondary"><?php echo $h($g['a_chi']); ?></div></div>
        <a class="btn btn-sm btn-outline-secondary ms-auto" href="guida.php?r=<?php echo $h($r); ?>&amp;stampa=1" target="_blank" rel="noopener"><i class="fa fa-print me-1" aria-hidden="true"></i>Versione da stampare (PDF)</a>
    </div>
    <div class="alert alert-light border" style="border-left: 5px solid <?php echo $h($g['colore']); ?> !important;"><?php echo $h($g['in_breve']); ?></div>
    <div class="row g-4">
        <div class="col-lg-3 d-none d-lg-block"><div class="position-sticky" style="top: 1rem;"><div class="small fw-bold text-secondary mb-2">IN QUESTA GUIDA</div>
            <?php foreach ($g['sezioni'] as $i => $s): ?><a class="d-block small mb-1 text-decoration-none" href="#s<?php echo $i + 1; ?>"><?php echo $i + 1; ?>. <?php echo $h($s['titolo']); ?></a><?php endforeach; ?>
            <hr><a class="small" href="guida.php"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i>Altre guide</a></div></div>
        <div class="col-lg-9">
        <?php foreach ($g['sezioni'] as $i => $s): ?>
            <section class="mb-4" id="s<?php echo $i + 1; ?>">
                <h2 class="h5 fw-bold"><span class="gd-num" style="background:<?php echo $h($g['colore']); ?>;"><?php echo $i + 1; ?></span><?php echo $h($s['titolo']); ?></h2>
                <?php if (!empty($s['immagine'])): ?><figure class="gd-fig my-3"><img src="assets/guide/<?php echo $h($s['immagine']); ?>" alt="<?php echo $h($s['didascalia'] ?? $s['titolo']); ?>" loading="lazy"><?php if (!empty($s['didascalia'])): ?><figcaption class="small text-secondary mt-1"><?php echo $h($s['didascalia']); ?></figcaption><?php endif; ?></figure><?php endif; ?>
                <ol><?php foreach ($s['passi'] as $p): ?><li class="mb-1"><?php echo $t($p); ?></li><?php endforeach; ?></ol>
                <?php if (!empty($s['nota'])): ?><div class="alert alert-warning small py-2"><?php echo $t($s['nota']); ?></div><?php endif; ?>
            </section>
        <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>
</div>
<?php require_once 'footer.php'; ?>
