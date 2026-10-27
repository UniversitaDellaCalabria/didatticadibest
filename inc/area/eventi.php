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
area_tessera('fa-calendar-check', '#0d6efd', 'Eventi, attestati e sondaggi',
    count($lista_ev['attive']) . ' prossimi · ' . $ev_presenze . ' presenze · ' . $ev_attestati . ' attestati', 'miei_eventi.php', $ev_offerte, 'posto liberato da confermare',
    [['Registra presenza (QR)', 'scanner_studente.php']]);
