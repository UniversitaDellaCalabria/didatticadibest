<?php
// =========================================================================
// Area personale: il cruscotto. Una tessera per argomento (pratiche, eventi, tutorato, assistenza…) con le novità in evidenza; ogni tessera porta alla
// pagina dell'argomento. Le tessere e il loro ordine dipendono dai profili della persona e li sceglie l'amministratore (Sito > Area personale).
// Le pagine: miei_eventi.php (eventi, attestati, sondaggi, aule), profilo.php, il_mio_tutorato.php.
// Autenticazione e dati utente: middleware.php ($u_id, $u_ruolo, $is_full_admin, $user_info, $u_email_sql).
// =========================================================================
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/middleware.php';

// I vecchi indirizzi (link nelle email già inviate, segnalibri) ora sono in miei_eventi.php
if (isset($_GET['conferma_posto']) || isset($_GET['cancella_prenotazione'])) {
    header('Location: miei_eventi.php?' . http_build_query(array_intersect_key($_GET, ['conferma_posto' => 1, 'cancella_prenotazione' => 1, 'csrf' => 1])));
    exit;
}

// Profili della persona (studente, docente, direttore…) e riquadri da mostrare: li sceglie l'amministratore dal pannello (Sito > Area personale).
// Con più profili si può restringere con ?p=; l'amministratore può «vedere come» un profilo con ?come= (solo per guardare, non cambia nulla).
$profili_persona = \App\Core\App::per($conn)->get(\App\Portale\Area\ProfiliPersona::class)->di($user_info ?? null);
$vedi_come = (!empty($is_full_admin) && isset($_GET['come']) && isset(\App\Portale\Area\Profili::NOMI[$_GET['come']])) ? (string)$_GET['come'] : null;
$solo_profilo = (isset($_GET['p']) && count($profili_persona) > 1 && in_array($_GET['p'], $profili_persona, true)) ? (string)$_GET['p'] : null;
$profili_mostrati = $vedi_come !== null ? [$vedi_come] : ($solo_profilo !== null ? [$solo_profilo] : $profili_persona);
$riquadri = [];
$pagamenti_tutorato_attivi = false;
try {
    $riq_repo = \App\Core\App::per($conn)->get(\App\Portale\Area\RiquadriRepository::class);
    $riquadri = $riq_repo->perProfili($profili_mostrati);
    foreach ($riq_repo->tutti() as $r_tp) if ($r_tp['chiave'] === 'tutorato_pagamenti' && $r_tp['attivo']) $pagamenti_tutorato_attivi = true;
} catch (\Throwable $e_riq) { error_log('[Area personale] riquadri non disponibili: ' . $e_riq->getMessage()); }

require_once __DIR__ . '/inc/area/_tessera.php';
$page_cfg['titolo'] = 'Area personale';
require_once 'header.php';
echo ui_tema_script();
?>

<div class="container my-4" style="max-width: 1040px;">

    <!-- INTESTAZIONE: chi sei e con quali profili (i numeri e lo scanner stanno nella parte degli eventi) -->
    <?php
    $pers_ap = !empty($user_info['persona_id']) ? persona_ateneo($conn, $user_info['persona_id']) : null;
    $voci_ap = [];
    if ($pers_ap) foreach ([GRUPPI_PERSONALE[$pers_ap['gruppo']] ?? '', $pers_ap['ruolo'], $pers_ap['struttura']] as $v_ap) { $v_ap = trim((string)$v_ap); if ($v_ap !== '') $voci_ap[mb_strtolower($v_ap)] = $v_ap; }
    $matr_ap = (string)($user_info['matricola_studente'] ?: ($user_info['matricola_dipendente'] ?? ''));
    $iniziali_ap = mb_strtoupper(mb_substr((string)$user_info['nome'], 0, 1) . mb_substr((string)$user_info['cognome'], 0, 1));
    ?>
    <div class="ui-testata mb-4">
        <div class="ui-avatar" aria-hidden="true"><?php echo htmlspecialchars($iniziali_ap !== '' ? $iniziali_ap : '?'); ?></div>
        <div class="flex-grow-1" style="min-width:0;">
            <div class="ui-etichetta">Area personale</div>
            <h1 class="ui-nome"><?php echo htmlspecialchars($user_info['nome'] . ' ' . $user_info['cognome']); ?></h1>
            <?php if ($voci_ap || $matr_ap !== ''): ?>
                <div class="small text-secondary mt-1">
                    <?php if ($voci_ap): ?><i class="fa fa-address-book me-1" aria-hidden="true"></i><?php echo htmlspecialchars(implode(' · ', $voci_ap)); ?><?php endif; ?>
                    <?php if ($matr_ap !== ''): ?><span class="ms-2 text-nowrap">Matricola <span class="font-monospace"><?php echo htmlspecialchars($matr_ap); ?></span></span><?php endif; ?>
                </div>
            <?php endif; ?>
            <div class="d-flex flex-wrap gap-2 mt-2" aria-label="I tuoi profili">
                <?php foreach ($profili_mostrati as $p_h): ?>
                    <span class="ui-chip"><i class="fa <?php echo \App\Portale\Area\Profili::ICONE[$p_h]; ?> me-1" aria-hidden="true"></i><?php echo htmlspecialchars(\App\Portale\Area\Profili::NOMI[$p_h]); ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="align-self-start d-print-none d-flex flex-column gap-2 align-items-end"><?php echo ui_tema_pulsante(); ?><button type="button" class="ui-tema-btn" data-installa-app hidden onclick="installaApp()"><i class="fa fa-mobile-screen" aria-hidden="true"></i><span>Installa l'app</span></button></div>
    </div>

    <?php if ($vedi_come !== null): ?>
        <div class="alert alert-info d-flex flex-wrap align-items-center gap-2 py-2 shadow-sm">
            <i class="fa fa-eye" aria-hidden="true"></i>
            <span>Stai guardando l'area personale come <strong><?php echo htmlspecialchars(\App\Portale\Area\Profili::NOMI[$vedi_come]); ?></strong>: è solo un'anteprima, i dati sono i tuoi.</span>
            <a href="area_personale.php" class="btn btn-sm btn-outline-primary fw-bold ms-auto">Torna alla mia</a>
            <a href="admin/area_personale.php?profilo=<?php echo urlencode($vedi_come); ?>" class="btn btn-sm btn-outline-secondary fw-bold">Cambia i riquadri</a>
        </div>
    <?php elseif (count($profili_persona) > 1): ?>
        <nav class="d-flex flex-wrap gap-2 mb-3" aria-label="Scegli il profilo">
            <a href="area_personale.php" class="btn btn-sm rounded-pill fw-bold <?php echo $solo_profilo === null ? 'btn-primary' : 'btn-outline-primary'; ?>">Tutto</a>
            <?php foreach ($profili_persona as $p_f): ?>
                <a href="area_personale.php?p=<?php echo urlencode($p_f); ?>" class="btn btn-sm rounded-pill fw-bold <?php echo $solo_profilo === $p_f ? 'btn-primary' : 'btn-outline-primary'; ?>"><i class="fa <?php echo \App\Portale\Area\Profili::ICONE[$p_f]; ?> me-1" aria-hidden="true"></i><?php echo htmlspecialchars(\App\Portale\Area\Profili::NOMI[$p_f]); ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>
    <?php if (!empty($is_full_admin) && $vedi_come === null): ?>
        <div class="small text-secondary mb-3"><i class="fa fa-eye me-1" aria-hidden="true"></i>Da amministratore puoi vedere l'area come:
            <?php foreach (\App\Portale\Area\Profili::NOMI as $k_v => $n_v): ?><a href="area_personale.php?come=<?php echo urlencode($k_v); ?>" class="ms-1 fw-semibold"><?php echo htmlspecialchars($n_v); ?></a><?php endforeach; ?>
            · <a href="admin/area_personale.php" class="fw-semibold">Gestisci i riquadri</a></div>
    <?php endif; ?>

    <?php
    if (isset($_SESSION['msg_area_pers'])) {
        echo $_SESSION['msg_area_pers'];
        unset($_SESSION['msg_area_pers']);
    }
    ?>

    <?php if (!$riquadri): ?>
        <div class="alert alert-light border text-secondary">Per ora non c'è niente da mostrare qui.</div>
    <?php endif; ?>
    <div class="row g-3 mb-4">
    <?php
    // I riquadri della persona, nell'ordine scelto dal pannello (Sito > Area personale): quelli di sistema stanno in inc/area/<chiave>.php,
    // quelli scritti dall'amministratore (e le schede) li disegna inc/area/_generico.php. Ognuno stampa una tessera (una colonna della griglia).
    foreach ($riquadri as $riq) {
        if ($riq['tipo'] === \App\Portale\Area\CatalogoRiquadri::SPECIALE) {
            $file_riq = __DIR__ . '/inc/area/' . $riq['chiave'] . '.php';
            if (is_file($file_riq)) include $file_riq;
        } else {
            include __DIR__ . '/inc/area/_generico.php';
        }
    }
    ?>
    </div>
</div>

<?php require_once 'footer.php'; ?>
