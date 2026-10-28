<?php // Riquadro «lavoro» dell'area personale: lo include area_personale.php, quindi usa le variabili della pagina ($conn, $user_info, $u_id, $is_full_admin…).
// Una tessera per ogni postazione che la persona può usare (stessi permessi del pannello, admin/admin_header.php); le azioni si fanno nelle pagine del pannello.
$lav_app = \App\Core\App::per($conn);
$lav_uid = (int)$u_id;
$lav_admin = !empty($is_full_admin);
$lav_did_tutto = $lav_admin || ha_modulo($conn, $lav_uid, 'didattica') || utente_operatore_ufficio($conn, $user_info);
$lav_fsl = $lav_admin || ha_modulo($conn, $lav_uid, 'fsl');
$lav_perm = [
    'admin' => $lav_admin,
    'didattica_tutto' => $lav_did_tutto,
    'didattica' => $lav_did_tutto || (bool)consigli_referente($conn, $user_info),
    'conv_tesi' => $lav_did_tutto || $lav_app->get(\App\ConvenzioniTesi\ServizioConvenzioniTesi::class)->utenteDellUfficio($user_info),
    'tutorato' => $lav_admin || ha_modulo($conn, $lav_uid, 'didattica') || utente_operatore_ufficio($conn, $user_info, 'bandi'),
    'fsl_convenzioni' => $lav_fsl || ha_ambito($conn, $lav_uid, 'fsl_convenzioni') || $lav_app->get(\App\Uffici\ServizioUffici::class)->utenteMembro($user_info, 'convenzioni_fsl'),
    'assistenza' => $lav_app->get(\App\Assistenza\OperatoriAssistenza::class)->eOperatore($user_info),
    'aree' => $lav_admin || utente_ha_abilitazioni($conn, $lav_uid),
];
$lav_cose = $lav_app->get(\App\Portale\CoseDaFare::class);
$lav_num = [];
// Ogni numero è a sé: se una tabella manca (installazione non aggiornata) la tessera compare lo stesso, senza numero
$lav_conta = function (string $chiave, callable $f) use (&$lav_num) {
    try { $f(); } catch (\Throwable $e) { error_log("Il mio lavoro ($chiave): " . $e->getMessage()); }
};
if ($lav_perm['didattica_tutto']) {
    $lav_conta('pratiche', function () use (&$lav_num, $lav_cose) {
        $sm = $lav_cose->praticheDaSmistare(); $lav_num['smistare'] = $sm['n']; $lav_num['smistare_giorni'] = $sm['giorni'];
        $lav_num['protocollare'] = $lav_cose->praticheDaProtocollare();
    });
}
if ($lav_perm['didattica']) $lav_conta('verbali', function () use (&$lav_num, $lav_cose, $lav_perm, $user_info, $conn) {
    $lav_num['verbali'] = $lav_cose->verbaliInFirma($lav_perm['didattica_tutto'] ? null : consigli_referente($conn, $user_info));
});
if ($lav_perm['conv_tesi']) $lav_conta('convenzioni di tesi', function () use (&$lav_num, $lav_app) {
    $lav_num['conv_tesi'] = $lav_app->get(\App\ConvenzioniTesi\ServizioConvenzioniTesi::class)->daFare();
});
if ($lav_perm['tutorato']) $lav_conta('tutorato', function () use (&$lav_num, $lav_cose) { $lav_num['tutorato'] = $lav_cose->tutoratoDaValidare(); });
if ($lav_perm['fsl_convenzioni']) $lav_conta('convenzioni fsl', function () use (&$lav_num, $lav_cose) { $lav_num['conv_fsl'] = $lav_cose->convenzioniDaPrendereInCarico(); });
if ($lav_perm['assistenza']) $lav_conta('assistenza', function () use (&$lav_num, $lav_app, $lav_uid) {
    $c = $lav_app->get(\App\Assistenza\ServizioAssistenza::class)->contatori($lav_uid);
    $lav_num['assistenza_prendere'] = (int)$c['da_prendere']; $lav_num['assistenza_mie'] = (int)$c['mie'];
});
foreach (\App\Portale\Area\PostazioniLavoro::elenco($lav_perm, $lav_num) as $lav_p) {
    area_tessera($lav_p['icona'], $lav_p['colore'], $lav_p['titolo'], $lav_p['testo'], $lav_p['href'], $lav_p['novita'], $lav_p['novita_et'], $lav_p['altri']);
}
