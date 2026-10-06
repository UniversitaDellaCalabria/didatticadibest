<?php
// orientamento.php - Vetrina dell'Orientamento per futuri studenti, famiglie e scuole: le aree di orientamento
// (es. Welcome Week, Openlab), la Formazione Scuola Lavoro per le scuole e i prossimi appuntamenti con ambito Orientamento.
// Gli eventi stanno nelle loro aree (modulo Eventi e seminari): qui si raccolgono per chi deve ancora iscriversi.
require_once 'config.php';
require_once 'functions.php';
// Un'area creata prima con l'indirizzo «orientamento» resta raggiungibile come prima
if (\App\Core\App::per($conn)->get(\App\Eventi\AreaRepository::class)->idPerSlug('orientamento')) { $_GET['slug'] = 'orientamento'; require __DIR__ . '/area.php'; exit; }

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$aree = array_values(array_filter(get_pagine_eventi_visibili($conn), fn($p) => tipo_area($p) !== 'calendario' && (tipo_area($p) === 'fsl' || ambito_area($p) === 'orientamento')));
$aree_fsl = array_values(array_filter($aree, fn($p) => tipo_area($p) === 'fsl'));
$aree_or = array_values(array_filter($aree, fn($p) => tipo_area($p) !== 'fsl'));
$eventi = eventi_agenda($conn, ['ambito' => 'orientamento', 'limite' => 12]);
$posti = get_riepilogo_posti($conn, 'evento', array_column($eventi, 'id'));

$card_area = function (array $p, string $testo_bottone) use ($h): string {
    $c1 = colore_valido($p['colore_primario'] ?? '', '#0056B3'); $c2 = colore_valido($p['colore_secondario'] ?? '', $c1);
    $cop = !empty($p['copertina_path']) && preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $p['copertina_path']) ? "url('" . $h($p['copertina_path']) . "') center/cover," : '';
    return '<div class="col-md-6"><a class="or-card" href="' . $h($p['slug']) . '.php">'
        . '<span class="or-testa" style="background:' . ($cop ? $cop . ' ' : '') . 'linear-gradient(135deg,' . $c1 . ' 0%,' . $c2 . ' 100%);"><span class="or-velo" style="background:linear-gradient(135deg,' . $c1 . 'dd 0%,' . $c2 . 'bb 100%);"></span>'
        . '<span class="or-tit" style="color:' . colore_testo_su($c1) . ';">' . $h(trim($p['titolo'] . ' ' . ($p['sottotitolo'] ?? ''))) . '</span></span>'
        . '<span class="or-corpo"><span class="or-descr">' . $h(mb_strimwidth(trim(strip_tags((string)($p['hero_descrizione'] ?? ''))), 0, 220, '…')) . '</span>'
        . '<span class="or-bottone" style="color:' . $c1 . ';">' . $h($testo_bottone) . ' <i class="fa fa-arrow-right ms-1" aria-hidden="true"></i></span></span></a></div>';
};

$page_cfg['titolo'] = 'Orientamento';
require_once 'header.php';
echo css_agenda();
?>
<style>
.or-hero { background: linear-gradient(135deg, #0b3a75 0%, #0056B3 60%, #2a7de1 100%); color: #fff; border-radius: 16px; padding: 32px; }
.or-hero h1 { color: #fff; }
.or-passi { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px; }
.or-passo { background: rgba(255,255,255,.12); border-radius: 12px; padding: 12px 14px; font-size: .9rem; }
.or-passo i { font-size: 1.2rem; margin-bottom: 6px; display: block; }
.or-card { display: flex; flex-direction: column; height: 100%; border-radius: 14px; overflow: hidden; border: 1px solid #e2e8f0; background: #fff; text-decoration: none; transition: transform .2s, box-shadow .2s; }
.or-card:hover, .or-card:focus { transform: translateY(-3px); box-shadow: 0 10px 22px rgba(0,0,0,.1); }
.or-testa { position: relative; min-height: 110px; display: flex; align-items: flex-end; padding: 16px; }
.or-velo { position: absolute; inset: 0; }
.or-tit { position: relative; font-weight: 800; font-size: 1.2rem; text-shadow: 0 1px 4px rgba(0,0,0,.35); }
.or-corpo { padding: 14px 16px; display: flex; flex-direction: column; gap: 10px; flex-grow: 1; }
.or-descr { color: #475569; font-size: .92rem; }
.or-bottone { font-weight: 800; font-size: .85rem; text-transform: uppercase; margin-top: auto; }
</style>
<div class="container my-4" style="max-width: 1100px;">
    <nav aria-label="Percorso" class="mb-3 small"><a href="index.php">Home</a> <span class="text-secondary mx-1">/</span> <span class="text-secondary">Orientamento</span></nav>
    <section class="or-hero mb-4">
        <h1 class="fw-bold mb-2">Orientamento</h1>
        <p class="mb-3" style="max-width:720px;opacity:.92;">Vuoi conoscere i corsi di Biologia, Ecologia e Scienze della Terra? Vieni a trovarci: laboratori, giornate di accoglienza, incontri con docenti e studenti. Per le scuole: percorsi di Formazione Scuola Lavoro.</p>
        <div class="or-passi">
            <div class="or-passo"><i class="fa fa-calendar-check" aria-hidden="true"></i><strong>Scegli un appuntamento</strong><br>Welcome Week, laboratori, open day</div>
            <div class="or-passo"><i class="fa fa-pen-to-square" aria-hidden="true"></i><strong>Prenota il posto</strong><br>con le credenziali Unical, SPID o CIE</div>
            <div class="or-passo"><i class="fa fa-qrcode" aria-hidden="true"></i><strong>Presentati con il QR</strong><br>check-in veloce all'ingresso</div>
            <div class="or-passo"><i class="fa fa-certificate" aria-hidden="true"></i><strong>Ricevi l'attestato</strong><br>dove previsto, nell'Area personale</div>
        </div>
    </section>

    <?php if ($aree_or): ?>
    <h2 class="h4 fw-bold mb-3"><i class="fa fa-compass me-2 text-primary" aria-hidden="true"></i>Per i futuri studenti</h2>
    <div class="row g-3 mb-4"><?php foreach ($aree_or as $p) echo $card_area($p, 'Programma e prenotazioni'); ?></div>
    <?php endif; ?>

    <?php if ($aree_fsl): ?>
    <h2 class="h4 fw-bold mb-1"><i class="fa fa-school me-2" style="color:#B30000;" aria-hidden="true"></i>Per le scuole</h2>
    <p class="text-secondary small mb-3">Percorsi di Formazione Scuola Lavoro (ex PCTO) per le classi delle scuole superiori: il docente prenota per la classe, con convenzione e attestati.</p>
    <div class="row g-3 mb-4"><?php foreach ($aree_fsl as $p) echo $card_area($p, 'Progetti per le scuole'); ?></div>
    <?php endif; ?>

    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <h2 class="h4 fw-bold mb-0"><i class="fa fa-calendar-days me-2 text-primary" aria-hidden="true"></i>Prossimi appuntamenti</h2>
        <a class="ms-auto small fw-bold" href="agenda.php?ambito=orientamento">Tutta l'agenda dell'orientamento <i class="fa fa-arrow-right" aria-hidden="true"></i></a>
    </div>
    <?php if ($eventi): ?>
        <div class="card border-0 shadow-sm overflow-hidden mb-4" style="border-radius:12px;"><?php foreach ($eventi as $e) echo html_voce_agenda($e, $posti[(int)$e['id']] ?? null); ?></div>
    <?php else: ?>
        <div class="card border-0 shadow-sm mb-4"><div class="card-body text-secondary">Al momento non ci sono appuntamenti in programma: torna a trovarci presto.</div></div>
    <?php endif; ?>
</div>
<?php require_once 'footer.php'; ?>
