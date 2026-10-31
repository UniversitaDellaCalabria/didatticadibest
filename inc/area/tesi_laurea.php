<?php
// Tessera «tesi_laurea» del cruscotto dell'area personale: la include area_personale.php, quindi usa le variabili della pagina ($conn, $user_info…).
// «Tesi e laurea» dello studente: a che punto è la richiesta di tesi, la convenzione dell'azienda scelta e i passi successivi (cerca un relatore, fai la richiesta).
// Le regole stanno in App\Didattica\TesiStudente. Compare sempre agli studenti: anche senza una richiesta serve a ricordare da dove si parte.
$tesi_dati = ['pratica' => null, 'azienda' => null, 'modulo' => null];
try {
    $tesi_dati = \App\Core\App::per($conn)->get(\App\Didattica\TesiStudente::class)->perUtente($user_info ?? null);
} catch (\Throwable $e_tesi) { error_log('[Area personale] tesi e laurea non disponibile: ' . $e_tesi->getMessage()); }
$tesi_r = \App\Didattica\TesiStudente::riepilogo($tesi_dati);
area_tessera('fa-graduation-cap', '#7c3aed', 'Tesi e laurea', $tesi_r['testo'], 'percorso_tesi.php', $tesi_r['novita'], 'da fare', $tesi_r['azioni']);
