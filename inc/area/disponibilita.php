<?php // Riquadro «disponibilita» dell'area personale: lo include area_personale.php, quindi usa le variabili della pagina ($conn, $user_info, $u_id…).
// La tessera del docente per tesi e tirocini: a che punto è il semaforo di ciascuna e il collegamento per aggiornare i posti.
$dsp_rep = \App\Core\App::per($conn)->get(\App\Disponibilita\DisponibilitaRepository::class);
$dsp_ora = new DateTimeImmutable('now');
$dsp_righe = [];
$dsp_da_riconfermare = 0;
foreach (\App\Disponibilita\DisponibilitaRepository::TIPI as $dsp_tipo => $dsp_nome) {
    $dsp_d = $dsp_rep->perUtente((int)$u_id, $dsp_tipo);
    if (!$dsp_d) { $dsp_righe[] = $dsp_nome . ': non indicata'; continue; }
    $dsp_s = \App\Disponibilita\Semaforo::di($dsp_d, $dsp_ora);
    $dsp_righe[] = $dsp_nome . ': ' . mb_strtolower($dsp_s['etichetta']) . ($dsp_s['dettaglio'] !== '' && !$dsp_s['scaduta'] ? ' (' . $dsp_s['dettaglio'] . ')' : '');
    if ($dsp_s['scaduta'] && !empty($dsp_d['accetta'])) $dsp_da_riconfermare++;
}
area_tessera('fa-traffic-light', '#0e7490', 'La mia disponibilità per tesi e tirocini', implode("\n", $dsp_righe), 'la_mia_disponibilita.php', $dsp_da_riconfermare, 'da riconfermare',
    [['Pagina delle tesi', 'disponibilita_tesi.php'], ['Pagina dei tirocini', 'disponibilita_tirocini.php']]);
