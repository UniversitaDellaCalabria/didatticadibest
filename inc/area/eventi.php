<?php
// Tessera «eventi» del cruscotto dell'area personale: la include area_personale.php, quindi usa le variabili della pagina ($conn, $user_info, $u_id…).
// Eventi, attestati e sondaggi: i numeri della persona; prenotazioni, storico, FSL e sondaggi stanno in miei_eventi.php
$lista_ev = \App\Core\App::get(\App\Iscrizioni\ServizioAreaPersonale::class)->prenotazioni($u_id, $u_email_sql);
$ev_presenze = 0; $ev_attestati = 0; $ev_offerte = 0;
foreach (array_merge($lista_ev['attive'], $lista_ev['passate']) as $pr_s) {
    if ((int)($pr_s['presente'] ?? 0) === 1) {
        $ev_presenze++;
        if (in_array($pr_s['stato'] ?? '', ['confermata', 'confermato', 'confirmed'])) $ev_attestati++;
    }
}
foreach ($lista_ev['attive'] as $pr_s) if (($pr_s['stato'] ?? '') === 'richiesta_conferma') $ev_offerte++;
// Per le scuole anche le attività FSL che aspettano un passo (convenzione, elenco studenti, autorizzazione): il numero dell'icona le conta insieme ai posti liberati
$ev_fsl = 0;
try {
    if (!empty($user_info['id'])) {
        foreach (\App\Core\App::per($conn)->get(\App\Fsl\ServizioPercorsiScuola::class)->perUtente((int)$user_info['id'], $u_email_sql) as $p_fs) {
            if ($p_fs['percorso']['azione'] !== null && !$p_fs['percorso']['fatto']) $ev_fsl++;
        }
    }
} catch (\Throwable $e_evfs) { error_log('[Area personale] attività FSL per l\'icona eventi non disponibili: ' . $e_evfs->getMessage()); }
$ev_totale = $ev_offerte + $ev_fsl;
$ev_stato = array_filter([$ev_offerte ? ($ev_offerte === 1 ? 'Posto da confermare' : $ev_offerte . ' posti da confermare') : '', $ev_fsl ? ($ev_fsl === 1 ? 'Un\'attività FSL da completare' : $ev_fsl . ' attività FSL da completare') : '']);
area_icona('fa-calendar-check', '#0d6efd', 'Eventi e attestati', 'miei_eventi.php', $ev_totale,
    count($lista_ev['attive']) . ' prossimi · ' . $ev_presenze . ' presenze · ' . $ev_attestati . ' attestati' . ($ev_offerte ? ' · posto liberato da confermare' : '') . ($ev_fsl ? ' · attività FSL da completare' : '') . '. Dalla pagina si registra anche la presenza (QR).', implode(' · ', $ev_stato));
