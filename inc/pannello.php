<?php
// inc/pannello.php - Componenti comuni delle pagine del pannello: intestazione, schede, prossimo passo, iter,
// filtri per fase, etichette di stato, elenco vuoto. Il codice sta in src/Portale/Vista/Pannello.php (aspetto in
// assets/css/pannello.css): qui restano come facciate.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

if (!function_exists('pn_testata')) {
    // Intestazione della pagina: titolo, una frase «a cosa serve», azioni principali (HTML) a destra
    function pn_testata(string $titolo, string $icona = '', string $aiuto = '', string $azioni = '', string $sopra = ''): string {
        return \App\Portale\Vista\Pannello::testata($titolo, $icona, $aiuto, $azioni, $sopra);
    }
}

if (!function_exists('pn_schede')) {
    // Schede della pagina: [chiave => ['href', 'testo', 'icona', 'numero', 'avviso', 'titolo']]
    function pn_schede(array $schede, string $attiva, string $etichetta = 'Sezioni della pagina'): string {
        return \App\Portale\Vista\Pannello::schede($schede, $attiva, $etichetta);
    }
}

if (!function_exists('pn_prossima')) {
    // Riquadro «prossimo passo» con il pulsante per farlo
    function pn_prossima(string $titolo, string $testo = '', string $azioni = '', string $tono = ''): string {
        return \App\Portale\Vista\Pannello::prossima($titolo, $testo, $azioni, $tono);
    }
}

if (!function_exists('pn_iter')) {
    // Linea dell'iter: [['nome', 'stato' => fatto|qui|'', 'nota', 'href']]
    function pn_iter(array $passi, string $etichetta = 'Iter'): string {
        return \App\Portale\Vista\Pannello::iter($passi, $etichetta);
    }
}

if (!function_exists('pn_fasi')) {
    // Filtri per fase con il numero: [chiave => ['href', 'testo', 'numero', 'colore']]
    function pn_fasi(array $fasi, string $attiva, string $etichetta = 'Fasi'): string {
        return \App\Portale\Vista\Pannello::fasi($fasi, $attiva, $etichetta);
    }
}

if (!function_exists('pn_stato')) {
    // Etichetta di stato (tono: ok, attesa, blocco, info, lavoro, spento)
    function pn_stato(string $testo, string $tono = 'info', string $icona = ''): string {
        return \App\Portale\Vista\Pannello::stato($testo, $tono, $icona);
    }
}

if (!function_exists('pn_cosa')) {
    // Una cosa da fare («La mia scrivania»): numero, titolo, dettaglio, link, colore del modulo
    function pn_cosa(int $numero, string $titolo, string $dettaglio, string $href, string $colore = '', bool $avviso = false): string {
        return \App\Portale\Vista\Pannello::cosa($numero, $titolo, $dettaglio, $href, $colore, $avviso);
    }
}

if (!function_exists('pn_vuoto')) {
    // Elenco vuoto con una frase chiara e il pulsante per cominciare
    function pn_vuoto(string $testo, string $icona = 'fa-inbox', string $azioni = ''): string {
        return \App\Portale\Vista\Pannello::vuoto($testo, $icona, $azioni);
    }
}
