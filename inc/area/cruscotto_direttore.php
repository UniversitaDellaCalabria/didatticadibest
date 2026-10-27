<?php
// Tessera «cruscotto_direttore» del cruscotto dell'area personale: la include area_personale.php, quindi usa le variabili della pagina ($conn, $user_info, $u_id…).
$cru_dir = \App\Core\App::per($conn)->get(\App\Portale\CruscottoDirettore::class);
if ($cru_dir->puoVedere($user_info)) {
    $cd_riep = $cru_dir->riepilogo();
    area_tessera('fa-gauge-high', '#0f2a4a', 'Cruscotto del Direttore',
        ($cd_riep['totale'] ? ($cd_riep['ferme'] ? $cd_riep['ferme'] . ' ferme da oltre ' . \App\Portale\CruscottoDirettore::GIORNI_FERMA . ' giorni · ' : '') : 'Tutto in ordine · ') . 'firme, pratiche, verbali, convenzioni, tutorato, risorse',
        'cruscotto_direttore.php', (int)$cd_riep['totale'], 'da guardare');
}
