<?php
// Prove del modulo Portale (testata, menu, home): le funzioni di inc/ come facciate di App\Portale (stessi risultati di prima).
// Chiamato da esegui.php: stesse variabili ($conn, $q, $EMAIL) e funzioni (prova, sezione).
sezione("Modulo Portale: facciate di inc/");

prova(colore_valido('#abc') === '#AABBCC' && colore_valido('x') === '#B30000' && colore_valido('x', '#112233') === '#112233', "colore_valido(): #RGB espanso, valori non validi sostituiti");
prova(colore_testo_su('#000000') === '#FFFFFF' && colore_testo_su('#FFFFFF') === '#1F2937', "colore_testo_su(): contrasto leggibile");
prova(str_contains(impagina_email('<p>Ciao</p>', 'Titolo', '#112233'), '#112233') && str_contains(impagina_email('<p>Ciao</p>', 'Titolo'), '<p>Ciao</p>'), "impagina_email(): corpo e colore dell'area");

// Widget della home
$w = get_widgets_home(['widgets_home' => json_encode(['slideshow' => 0, 'aree_colonne' => 7, 'eventi_num' => 12, 'ordine' => ['statistiche', 'x']])]);
prova($w['slideshow'] === 0 && $w['aree_colonne'] === 2 && $w['eventi_num'] === 12 && end($w['ordine']) === 'statistiche' && count($w['ordine']) === 10, "get_widgets_home(): valori non validi riportati ai predefiniti, ordine completo");
prova(widgets_home_default()['eventi_layout'] === 'scroll' && get_widgets_home(null) === get_widgets_home([]), "widgets_home_default() e configurazione assente");

// Configurazione del portale con cache su file
$cache_f = RADICE_SITO . '/cache/configurazione_portale.json';
@unlink($cache_f);
$cfg = get_configurazione_portale($conn);
prova(is_array($cfg) && is_file($cache_f), "get_configurazione_portale(): scrive la cache su file");
invalidate_configurazione_portale_cache();
prova(!is_file($cache_f), "invalidate_configurazione_portale_cache(): elimina il file");
@unlink($cache_f);

// Aree e sezioni
$a_cal = ['tipo_area' => 'calendario'];
prova(tipo_area($a_cal) === 'calendario' && modulo_di_area(['tipo_area' => 'fsl']) === 'fsl' && modulo_di_area(null) === 'orientamento', "tipo_area() e modulo_di_area()");

// Menu proposto
$prop = menu_proposto($conn);
prova($prop[0][0] === 'Home' && end($prop)[1] === 'area_personale.php' && count($prop) === 5, "menu_proposto(): cinque voci di primo livello");

// Pannello: componenti comuni (pn_*) e conteggi di «La mia scrivania»
sezione("Pannello: componenti e cose da fare");
$h_t = pn_testata('Titolo <b>', 'fa-house', 'Aiuto', '<a href="#">Nuovo</a>', '<a href="#">Indietro</a>');
prova(str_contains($h_t, 'Titolo &lt;b&gt;') && str_contains($h_t, 'pn-testata-azioni') && str_contains($h_t, 'Indietro'), "pn_testata(): titolo ripulito, azioni e percorso");
$h_s = pn_schede(['a' => ['href' => 'a.php', 'testo' => 'A', 'numero' => 3, 'avviso' => true], 'b' => ['href' => 'b.php', 'testo' => 'B', 'numero' => 0]], 'a');
prova(str_contains($h_s, 'aria-current="page"') && str_contains($h_s, 'pn-num avviso') && substr_count($h_s, 'pn-num') === 1, "pn_schede(): scheda attiva e numero solo se maggiore di zero");
$h_i = pn_iter([['nome' => 'Uno', 'stato' => 'fatto'], ['nome' => 'Due', 'stato' => 'qui'], ['nome' => 'Tre', 'stato' => 'x']]);
prova(str_contains($h_i, 'class="fatto"') && str_contains($h_i, 'aria-current="step"') && str_contains($h_i, '<li class="">'), "pn_iter(): passi fatti, passo corrente e passi da fare");
prova(str_contains(pn_cosa(4, 'Cosa', 'Dove', 'x.php?a=1&b=2', '#b30000', true), 'a=1&amp;b=2') && str_contains(pn_cosa(1, 'C', '', 'x.php', 'rosso'), 'class="pn-cosa"') && !str_contains(pn_cosa(1, 'C', '', 'x.php', 'rosso'), 'style='), "pn_cosa(): link ripulito e colore non valido ignorato");
prova(str_contains(pn_stato('Fatto', 'ok'), 'pn-tono-ok') && str_contains(pn_stato('?', 'inesistente'), 'pn-tono-info'), "pn_stato(): tono sconosciuto torna a «info»");
$cdf_p = \App\Core\App::per($conn)->get(\App\Portale\CoseDaFare::class);
$q('INSERT INTO pratiche (modulo_id, utente_id, codice, stato, creata_il) VALUES (1, 1, "PROVA-CDF-1", "inviata", NOW() - INTERVAL 5 DAY)');
$sm0 = $cdf_p->praticheDaSmistare();
prova($sm0['n'] >= 1 && $sm0['giorni'] >= 5, "CoseDaFare: pratica senza ufficio né persona conteggiata, con i giorni della più vecchia");
$q('UPDATE pratiche SET stato = "chiusa" WHERE codice = "PROVA-CDF-1"');
prova($cdf_p->praticheDaSmistare()['n'] === $sm0['n'] - 1, "CoseDaFare: una pratica chiusa non è più da smistare");
$q('DELETE FROM pratiche WHERE codice = "PROVA-CDF-1"');
prova($cdf_p->messaggiNonLetti([]) === [] && $cdf_p->prenotazioniRisorseDaApprovare([]) === [] && is_int($cdf_p->verbaliInFirma([])) && is_int($cdf_p->verbaliInFirma(null)), "CoseDaFare: nessuna area, nessun conteggio");
