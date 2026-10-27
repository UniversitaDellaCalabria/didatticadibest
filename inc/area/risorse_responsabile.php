<?php
// Tessera «risorse_responsabile» del cruscotto dell'area personale: la include area_personale.php, quindi usa le variabili della pagina ($conn, $user_info, $u_id…).
// Risorse di cui la persona è responsabile (scelta dall'admin dall'anagrafe): richieste da approvare e prenotazioni in arrivo
$mie_ris = \App\Core\App::per($conn)->get(\App\Risorse\ServizioResponsabiliRisorse::class)->risorseDi($user_info);
if ($mie_ris) {
    $n_da_appr_r = array_sum(array_column($mie_ris, 'da_approvare')); $n_fut_r = array_sum(array_column($mie_ris, 'future'));
    area_tessera('fa-user-shield', '#0f766e', 'Le mie risorse', count($mie_ris) . ' risorse di cui sei responsabile · ' . $n_fut_r . ' prenotazioni in programma', 'risorse_responsabile.php', (int)$n_da_appr_r, 'richieste da approvare');
}
