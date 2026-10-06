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
