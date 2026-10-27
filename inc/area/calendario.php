<?php
// Tessera «calendario» del cruscotto dell'area personale: la include area_personale.php, quindi usa le variabili della pagina ($conn, $user_info, $u_id…).
// Gli appuntamenti dei prossimi sette giorni (eventi, aule, ricevimenti, scadenze): il calendario intero sta in calendario.php
$voci_cal = \App\Core\App::per($conn)->get(\App\Portale\CalendarioPersonale::class)->voci($user_info, date('Y-m-d'), date('Y-m-d', strtotime('+6 days')));
$scad_cal = count(array_filter($voci_cal, static fn (array $v): bool => $v['tipo'] === 'scadenza'));
$prossima_cal = $voci_cal ? $voci_cal[0] : null;
area_tessera('fa-calendar-days', '#0891b2', 'Il mio calendario',
    $prossima_cal
        ? count($voci_cal) . ' in questa settimana · prossimo: ' . mb_strimwidth($prossima_cal['titolo'], 0, 40, '…') . ' (' . date('d/m', strtotime($prossima_cal['inizio'])) . ')'
        : 'Niente in agenda questa settimana · eventi, aule, ricevimenti e scadenze con il calendario del telefono',
    'calendario.php', $scad_cal, 'scadenza');
