<?php
// Tessera «firme» del cruscotto dell'area personale: la include area_personale.php, quindi usa le variabili della pagina ($conn, $user_info, $u_id…).
// «Da firmare»: quanti documenti aspettano la firma della persona; l'elenco con le anteprime sta in firme.php. Compare solo a chi ha qualcosa da firmare.
$da_firmare_n = 0;
try {
    if (!empty($user_info['id'])) $da_firmare_n = count(\App\Core\App::per($conn)->get(\App\Firme\ScrivaniaFirme::class)->documenti($user_info));
} catch (\Throwable $e_fir) { error_log('[Area personale] firme non disponibili: ' . $e_fir->getMessage()); }
if ($da_firmare_n > 0) {
    area_tessera('fa-file-signature', '#0f766e', 'Da firmare', $da_firmare_n === 1 ? 'Un documento aspetta la tua firma' : $da_firmare_n . ' documenti aspettano la tua firma', 'firme.php', $da_firmare_n, 'da firmare', [['Firma ora (' . $da_firmare_n . ')', 'firme.php', false, true]]);
}
