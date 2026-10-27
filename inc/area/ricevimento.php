<?php
// Tessera «ricevimento» del cruscotto dell'area personale: la include area_personale.php, quindi usa le variabili della pagina ($conn, $user_info, $u_id…).
// Sportelli di ricevimento (del docente o, per i suoi operatori, dell'Ufficio didattico): gestione e prossimi appuntamenti
$ids_ric = array_map('intval', array_column(sportelli_utente($conn, $user_info), 'id'));
if ($ids_ric) {
    $ric_rows = \App\Core\App::get(\App\Iscrizioni\AreaPersonaleRepository::class)->riepilogoSportelli($ids_ric);
    if ($ric_rows) {
        $n_ric = array_sum(array_column($ric_rows, 'n')); $n_appr = array_sum(array_column($ric_rows, 'da_appr'));
        area_tessera('fa-user-clock', '#7c3aed', 'Il mio ricevimento', $n_ric . ' appuntamenti in programma · giorni, orari e assenze', 'ricevimento.php', (int)$n_appr, 'da approvare');
    }
}
