<?php
// =========================================================================
// Il mio tutorato: a che punto è ogni incarico del tutor e i suoi documenti. Si arriva dal cruscotto (area_personale.php).
// =========================================================================
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/inc/area/_tutorato_tappe.php';

// Le tappe dei pagamenti si vedono solo se il riquadro «Ufficio pagamenti del tutorato» è acceso dal pannello (Sito > Area personale)
$pagamenti_tutorato_attivi = false;
$mio_tutorato = [];
try {
    foreach (\App\Core\App::per($conn)->get(\App\Portale\Area\RiquadriRepository::class)->tutti() as $r_tp) if ($r_tp['chiave'] === 'tutorato_pagamenti' && $r_tp['attivo']) $pagamenti_tutorato_attivi = true;
    $pag_sv = \App\Core\App::per($conn)->get(\App\Tutorato\ServizioPagamenti::class);
    if (!empty($user_info['id'])) $mio_tutorato = \App\Core\App::per($conn)->get(\App\Tutorato\IncaricoRepository::class)->delTutor((string)($user_info['codice_fiscale'] ?? ''), (string)($user_info['email'] ?? ''));
} catch (\Throwable $e_tut) { error_log('[Il mio tutorato] non disponibile: ' . $e_tut->getMessage()); }

$page_cfg['titolo'] = 'Il mio tutorato';
require_once 'header.php';
echo ui_tema_script();
?>

<div class="container my-4" style="max-width: 900px;">
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
        <?php echo ui_intestazione('Il mio tutorato'); ?>
        <div class="d-print-none"><?php echo ui_tema_pulsante(); ?></div>
    </div>
    <?php if (!$mio_tutorato): ?>
        <?php echo ui_vuoto('fa-user-graduate', 'Nessun incarico di tutorato', 'Non risultano incarichi di tutorato a tuo nome. Se pensi che ci sia un errore, scrivici dall\'assistenza.', 'assistenza.php', 'Chiedi assistenza', '#0f766e'); ?>
    <?php endif; ?>
<?php
foreach ($mio_tutorato as $mt):
    $tp_t = area_tappe_tutorato($conn, $mt, !empty($pagamenti_tutorato_attivi)); $tappe_t = $tp_t['tappe']; $prima_non_fatta = $tp_t['prima']; $firmata_t = $tp_t['firmata']; ?>
    <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px; border-left: 5px solid #0f766e !important;"><div class="card-body">
        <h2 class="h6 fw-bold mb-0"><i class="fa fa-user-graduate me-1" style="color:#0f766e;" aria-hidden="true"></i>Il mio tutorato · <?php echo htmlspecialchars($mt['bando_titolo']); ?></h2>
        <div class="small text-secondary mb-3"><?php echo htmlspecialchars(\App\Tutorato\DatiLettera::oreTesto($mt['ore'])); ?> ore · <?php echo htmlspecialchars(mb_strimwidth((string)preg_replace('/\s+/', ' ', (string)$mt['attivita']), 0, 80, '…')); ?></div>
        <div class="row g-3">
            <div class="col-md-6"><div class="small fw-bold mb-2">A che punto sei</div>
                <ol class="list-unstyled mb-0 small">
                <?php foreach ($tappe_t as $k => [$tt, $fatto_t, $dett_t]): ?>
                    <li class="mb-2 d-flex gap-2"><i class="fa <?php echo $fatto_t ? 'fa-circle-check text-success' : ($k === $prima_non_fatta ? 'fa-location-dot text-danger' : 'fa-circle text-secondary opacity-50'); ?> mt-1" aria-hidden="true"></i>
                        <span<?php echo $k === $prima_non_fatta ? ' class="fw-bold"' : ''; ?>><?php echo htmlspecialchars($tt); ?><?php echo $dett_t !== '' ? '<span class="d-block text-secondary fw-normal" style="font-size:.78rem;">' . htmlspecialchars($dett_t) . '</span>' : ''; ?></span></li>
                <?php endforeach; ?>
                </ol></div>
            <div class="col-md-6"><div class="small fw-bold mb-2">I tuoi documenti</div>
                <?php $docs_t = array_filter($pag_sv->documenti($mt), fn($d) => $d['disponibile']);
                foreach ($docs_t as $d): ?>
                    <div class="d-flex justify-content-between align-items-center gap-2 border-bottom py-1 small"><span><i class="fa fa-file-pdf text-danger me-1" aria-hidden="true"></i><?php echo htmlspecialchars($d['etichetta']); ?></span>
                        <a class="btn btn-sm btn-outline-secondary py-0" href="documento_tutorato.php?i=<?php echo (int)$mt['id']; ?>&amp;f=<?php echo htmlspecialchars($d['chiave']); ?>" target="_blank" rel="noopener">Apri</a></div>
                <?php endforeach; ?>
                <?php if (!$docs_t): ?><div class="small text-secondary">I documenti compaiono qui man mano che la pratica avanza.</div><?php endif; ?>
                <?php if ($firmata_t): ?><a class="btn btn-sm btn-outline-success fw-bold mt-2" href="registro_tutorato.php?id=<?php echo (int)$mt['id']; ?>"><i class="fa fa-clipboard-list me-1" aria-hidden="true"></i>Registro delle attività</a><?php endif; ?></div>
        </div>
    </div></div>
<?php endforeach; ?>
</div>

<?php require_once 'footer.php'; ?>
