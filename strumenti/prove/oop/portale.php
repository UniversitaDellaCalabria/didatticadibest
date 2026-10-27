<?php
// Prove del modulo Portale (testata, menu, home): le funzioni di inc/ come facciate di App\Portale (stessi risultati di prima).
// Chiamato da esegui.php: stesse variabili ($conn, $q, $EMAIL) e funzioni (prova, sezione).
sezione("Modulo Portale: facciate di inc/");

prova(colore_valido('#abc') === '#AABBCC' && colore_valido('x') === '#B30000' && colore_valido('x', '#112233') === '#112233', "colore_valido(): #RGB espanso, valori non validi sostituiti");
prova(colore_testo_su('#000000') === '#FFFFFF' && colore_testo_su('#FFFFFF') === '#1F2937', "colore_testo_su(): contrasto leggibile");
prova(str_contains(impagina_email('<p>Ciao</p>', 'Titolo', '#112233'), '#112233') && str_contains(impagina_email('<p>Ciao</p>', 'Titolo'), '<p>Ciao</p>'), "impagina_email(): corpo e colore dell'area");

// Widget della home
$w = get_widgets_home(['widgets_home' => json_encode(['slideshow' => 0, 'aree_colonne' => 7, 'eventi_num' => 12, 'ordine' => ['statistiche', 'x']])]);
prova($w['slideshow'] === 0 && $w['aree_colonne'] === 2 && $w['eventi_num'] === 12 && end($w['ordine']) === 'statistiche' && count($w['ordine']) === count(\App\Portale\WidgetHome::predefiniti()['ordine']), "get_widgets_home(): valori non validi riportati ai predefiniti, ordine completo");
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

// Percorso FSL: regole dei passi e numeri per fase (senza database)
sezione("FSL: percorso delle scuole");
$pf_p = \App\Fsl\PercorsoFsl::passi(true, false, false, null, null, null);
prova(count($pf_p) === 3 && $pf_p[0]['stato'] === 'fatto' && $pf_p[1]['stato'] === 'qui' && $pf_p[2]['stato'] === '', "PercorsoFsl::passi(): senza convenzione il passo corrente è la convenzione; i passi senza oggetto non compaiono");
$pf_q = \App\Fsl\PercorsoFsl::passi(true, true, true, false, false, false);
prova(count($pf_q) === 6 && $pf_q[3]['stato'] === 'qui' && $pf_q[4]['stato'] === '' && $pf_q[5]['stato'] === '', "PercorsoFsl::passi(): un solo passo «qui», il primo che manca");
prova(array_unique(array_column(\App\Fsl\PercorsoFsl::passi(true, true, true, true, true, true), 'stato')) === ['fatto'], "PercorsoFsl::passi(): tutto fatto, nessun passo «qui»");
$r_a = ['stato' => 'confermata', 'tipo' => 'progetto', 'pd_attestati' => 1, 'pd_per_scuole' => 1, 'al' => '2026-09-10', 'n_elenco' => 0, 'attestato_inviato' => 0, 'scuola_codice' => 'AAA', 'scuola_nome' => 'A'];
$r_b = ['stato' => 'confermata', 'tipo' => 'evento', 'pd_attestati' => 1, 'pd_per_scuole' => 0, 'al' => '2026-09-12', 'n_elenco' => 12, 'attestato_inviato' => 1, 'scuola_codice' => 'BBB', 'scuola_nome' => 'B'];
$r_c = ['stato' => 'confermata', 'tipo' => 'progetto', 'pd_attestati' => 1, 'pd_per_scuole' => 0, 'al' => '2026-09-10', 'n_elenco' => 0, 'scuola_codice' => 'CCC', 'scuola_nome' => 'C'];
$r_d = ['stato' => 'in_attesa', 'tipo' => 'progetto', 'pd_attestati' => 1, 'pd_per_scuole' => 1, 'al' => '2026-09-10', 'n_elenco' => 0, 'scuola_codice' => '', 'scuola_nome' => 'D'];
$r_e = ['stato' => 'confermata', 'tipo' => 'progetto', 'pd_attestati' => 1, 'pd_per_scuole' => 1, 'al' => '2026-12-10', 'n_elenco' => 0, 'scuola_codice' => 'EEE', 'scuola_nome' => 'E'];
$ct = \App\Fsl\PercorsoFsl::contatori([$r_a, $r_b, $r_c, $r_d, $r_e], '2026-10-11');
prova($ct['scuole'] === 5 && $ct['in_attesa'] === 1, "PercorsoFsl::contatori(): scuole e iscrizioni in attesa");
prova(count($ct['elenchi_mancanti']) === 1 && $ct['elenchi_mancanti'][0]['scuola_nome'] === 'A', "PercorsoFsl::contatori(): manca l'elenco solo per attività finite con attestati (non per progetti generici, in attesa o non finiti)");
prova($ct['attestati_dovuti'] === 2 && $ct['attestati_inviati'] === 1, "PercorsoFsl::contatori(): attestati dovuti e inviati");
prova(\App\Fsl\PercorsoFsl::conclusa(['al' => '2026-10-10'], '2026-10-11') && !\App\Fsl\PercorsoFsl::conclusa(['al' => '2026-10-11'], '2026-10-11') && !\App\Fsl\PercorsoFsl::conclusa([], '2026-10-11'), "PercorsoFsl::conclusa(): finita solo se la data di fine è passata");
prova(str_contains(pn_percorso([['numero' => '2/5', 'testo' => 'T', 'avviso' => true, 'href' => 'x.php?a=1&b=2'], ['numero' => 0, 'testo' => 'U']]), 'class="avviso"') && str_contains(pn_percorso([['numero' => 1, 'testo' => 'T', 'href' => 'a&b']]), 'a&amp;b'), "pn_percorso(): tappe con avviso e link ripuliti");
