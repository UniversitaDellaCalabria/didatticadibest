<?php
// Tessera «tutorato_mio» del cruscotto dell'area personale: la include area_personale.php, quindi usa le variabili della pagina ($conn, $user_info, $u_id…).
// «Il mio tutorato» del tutor: il prossimo passo; il dettaglio e i documenti stanno in il_mio_tutorato.php
require_once __DIR__ . '/_tutorato_tappe.php';
$mio_tutorato = [];
try {
    if (!empty($user_info['id'])) $mio_tutorato = \App\Core\App::per($conn)->get(\App\Tutorato\IncaricoRepository::class)->delTutor((string)($user_info['codice_fiscale'] ?? ''), (string)($user_info['email'] ?? ''));
} catch (\Throwable $e_tut) { error_log('[Area personale] tutorato non disponibile: ' . $e_tut->getMessage()); }
if ($mio_tutorato) {
    $tp_m = area_tappe_tutorato($conn, $mio_tutorato[0], !empty($pagamenti_tutorato_attivi));
    $testo_m = $tp_m['prima'] === null ? 'Tutte le tappe sono completate' : 'Prossimo passo: ' . $tp_m['tappe'][$tp_m['prima']][0];
    if (count($mio_tutorato) > 1) $testo_m = count($mio_tutorato) . ' incarichi · ' . $testo_m;
    // Quanti incarichi aspettano un'azione del tutor, e il pulsante del primo
    $azioni_m = array_values(array_filter(array_map(fn($x) => area_tappe_tutorato($conn, $x, !empty($pagamenti_tutorato_attivi))['azione'], $mio_tutorato)));
    area_tessera('fa-user-graduate', '#0f766e', 'Il mio tutorato', $testo_m, 'il_mio_tutorato.php', count($azioni_m), 'da fare', $azioni_m ? [[$azioni_m[0][0], $azioni_m[0][1], false, true]] : []);
}
