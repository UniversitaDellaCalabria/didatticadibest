<?php
// Tessera «tutorato_registro» del cruscotto dell'area personale: la include area_personale.php, quindi usa le variabili della pagina ($conn, $user_info, $u_id…).
// Tutorato: registro delle attività per il tutor (lettera firmata) e per il docente responsabile
$reg_tut = function_exists('incarichi_registro') ? incarichi_registro($conn, $user_info ?? null) : [];
if ($reg_tut) {
    $da_fare_t = count(array_filter($reg_tut, fn($i) => $i['_ruolo'] === 'docente' && ($i['fine_stato'] === 'richiesta' || \App\Core\App::get(\App\Iscrizioni\AreaPersonaleRepository::class)->registriTutoratoInviati((int)$i['id']) > 0)));
    area_tessera('fa-clipboard-list', '#0f766e', 'Registro delle attività di tutorato', count($reg_tut) . ' incarichi · registro, lettera di incarico e fine attività', 'registro_tutorato.php', $da_fare_t, 'da controllare');
}
