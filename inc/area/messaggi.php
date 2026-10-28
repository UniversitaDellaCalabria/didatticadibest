<?php
// Icona «messaggi» del cruscotto dell'area personale: la include area_personale.php, quindi usa le variabili della pagina ($conn, $user_info, $u_id…).
// La casella unica di chi gestisce (aree, convenzioni FSL, assistenza): compare solo a chi ha almeno una di queste postazioni, col numero delle conversazioni da leggere.
require_once __DIR__ . '/_permessi_lavoro.php';
$msg_perm = area_permessi_lavoro($conn, $user_info, (int)$u_id, !empty($is_full_admin));
if ($msg_perm['aree'] || $msg_perm['fsl_convenzioni'] || $msg_perm['assistenza']) {
    $msg_n = 0;
    try { $msg_n = \App\Core\App::get(\App\Iscritti\CasellaMessaggi::class)->nonLetti((int)$u_id, $msg_perm['admin'], $msg_perm['fsl_convenzioni'], $msg_perm['assistenza']); }
    catch (\Throwable $e_msg) { error_log('[Area personale] messaggi: ' . $e_msg->getMessage()); }
    area_icona('fa-envelope', '#1d4ed8', 'Messaggi', 'admin/messaggi.php', $msg_n, 'La casella unica: messaggi delle aree, delle convenzioni FSL e dell\'assistenza');
}
