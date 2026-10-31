<?php
// Tessera «miei_studenti» del cruscotto dell'area personale: la include area_personale.php, quindi usa le variabili della pagina ($conn, $user_info…).
// «I miei studenti» del docente, in sola lettura: le richieste in cui è indicato come relatore, con lo stato. Le regole stanno in App\Didattica\StudentiDelDocente.
// Compare solo a chi ha almeno una richiesta; il numero in rosso conta quelle che aspettano la sua firma (la firma si dà da «Le mie approvazioni»).
$stud_d = [];
try {
    $stud_d = \App\Core\App::per($conn)->get(\App\Didattica\StudentiDelDocente::class)->perUtente($user_info ?? null);
} catch (\Throwable $e_stud) { error_log('[Area personale] i miei studenti non disponibili: ' . $e_stud->getMessage()); }
$stud_r = \App\Didattica\StudentiDelDocente::riepilogo($stud_d);
if ($stud_r['totale'] > 0) {
    area_tessera('fa-user-graduate', '#7c3aed', 'I miei studenti', $stud_r['testo'], 'miei_studenti.php', $stud_r['attesa'], 'da firmare', [['Apri l\'elenco', 'miei_studenti.php', false, $stud_r['attesa'] > 0]]);
}
