<?php
// Tessera «direttore_assente» del cruscotto dell'area personale: la include area_personale.php, quindi usa le variabili della pagina ($conn, $user_info, $u_id…).
// «Direttore assente»: lo attivano Direttore, vice, segreteria di direzione e amministratori
$dir_sv = \App\Core\App::per($conn)->get(\App\Uffici\Direzione::class);
if ($dir_sv->puoImpostareAssenza($user_info)) {
    $dir_ass = $dir_sv->assenza();
    area_tessera('fa-user-clock', $dir_ass['attiva'] ? '#b45309' : '#15803d', 'Direttore assente',
        $dir_ass['attiva'] ? 'Attivo: firme e avvisi vanno al vice direttore' . ($dir_ass['fino'] ? ' fino al ' . date('d/m/Y', strtotime($dir_ass['fino'])) : '') : 'Spento: firme e avvisi vanno al Direttore',
        'direttore_assente.php');
}
