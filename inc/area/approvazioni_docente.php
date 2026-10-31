<?php
// Tessera «approvazioni_docente» del cruscotto dell'area personale: la include area_personale.php, quindi usa le variabili della pagina ($conn, $user_info…).
// «Le mie approvazioni» del docente: firme (pratiche come relatore, lettere di tutorato, verbali, convenzioni), conferme degli attestati e registri dei tutor da
// controllare, con i numeri separati e un pulsante per gruppo. Le regole stanno in App\Portale\ApprovazioniDocente. Compare solo a chi ha qualcosa da approvare.
$appr_d = ['firme' => 0, 'firme_per_tipo' => [], 'conferme' => 0, 'registri' => 0];
try {
    $appr_d = \App\Core\App::per($conn)->get(\App\Portale\ApprovazioniDocente::class)->perUtente($user_info ?? null);
} catch (\Throwable $e_appr) { error_log('[Area personale] approvazioni non disponibili: ' . $e_appr->getMessage()); }
$appr_r = \App\Portale\ApprovazioniDocente::riepilogo($appr_d);
if ($appr_r['totale'] > 0) {
    area_tessera('fa-stamp', '#b45309', 'Le mie approvazioni', $appr_r['testo'], $appr_r['azioni'][0][1], $appr_r['totale'], 'da approvare', $appr_r['azioni']);
}
