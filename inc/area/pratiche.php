<?php
// Tessera «pratiche» del cruscotto dell'area personale: la include area_personale.php, quindi usa le variabili della pagina ($conn, $user_info, $u_id…).
// Pratiche della didattica (moduli online): collegamento a Le mie pratiche se la persona ne ha
$pr_rows = \App\Core\App::get(\App\Iscrizioni\AreaPersonaleRepository::class)->riepilogoPratiche((int)$_SESSION['utente_id']);
if ($pr_rows && (int)$pr_rows['n'] > 0) {
    area_tessera('fa-folder-open', '#047857', 'Le mie pratiche', (int)$pr_rows['aperte'] . ' in corso su ' . (int)$pr_rows['n'], 'pratiche.php',
        (int)$pr_rows['integr'], 'con integrazione richiesta', [['Nuova pratica', 'modulistica.php']]);
}
