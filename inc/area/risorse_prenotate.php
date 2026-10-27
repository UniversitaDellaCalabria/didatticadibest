<?php
// Tessera «risorse_prenotate» del cruscotto dell'area personale: la include area_personale.php, quindi usa le variabili della pagina ($conn, $user_info, $u_id…).
// Aule, laboratori e appuntamenti prenotati dalla persona (aree Calendari e risorse): l'elenco e l'annullamento stanno in miei_eventi.php
$mie_risorse = \App\Core\App::get(\App\Iscrizioni\AreaPersonaleRepository::class)->risorsePrenotate((int)$u_id);
if ($mie_risorse) {
    $in_attesa_r = count(array_filter($mie_risorse, fn($m) => $m['stato'] === 'da_approvare'));
    area_tessera('fa-door-open', '#0056B3', 'Aule e appuntamenti', count($mie_risorse) . ' prenotazioni' . ($in_attesa_r ? ' · ' . $in_attesa_r . ' in attesa di approvazione' : ''), 'miei_eventi.php#aule');
}
