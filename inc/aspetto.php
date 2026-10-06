<?php
// inc/aspetto.php - Colori delle aree, etichette di turni e orari, impaginazione delle email.
// Colori, impaginazione delle email, date e descrizioni delle card stanno in src/Portale/ (Colori, ColoriAree,
// ImpaginatoreEmailPortale, Vista\Testi): qui restano come facciate. Turni e finestre di prenotazione stanno in src/Eventi/Turni,
// l'email dell'attestato in src/Attestati/ServizioAttestati.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

// =======================================================================
// COLORE DELL'AREA (card, badge, email)
// =======================================================================
if (!function_exists('colore_valido')) {
    // Colore #RRGGBB sicuro da stampare negli attributi style; altrimenti il default.
    function colore_valido($hex, string $default = '#B30000'): string {
        return \App\Portale\Colori::valido($hex, $default);
    }
}

if (!function_exists('colore_testo_su')) {
    // Colore del testo leggibile su uno sfondo (regola di contrasto WCAG): bianco o grigio quasi nero.
    function colore_testo_su($hex_sfondo): string {
        return \App\Portale\Colori::testoSu($hex_sfondo);
    }
}

if (!function_exists('colore_area_turno')) {
    // Colore primario dell'area a cui appartiene un turno (memorizzato per la richiesta).
    function colore_area_turno($conn, $turno_id): string {
        return \App\Core\App::per($conn)->get(\App\Portale\ColoriAree::class)->delTurno((int)$turno_id);
    }
}

if (!function_exists('impagina_email')) {
    // Impaginazione comune delle email (intestazione con il colore dell'area, corpo, piè di pagina): App\Portale\ImpaginatoreEmailPortale
    function impagina_email(string $corpo, string $titolo, ?string $colore = null): string {
        return (new \App\Portale\ImpaginatoreEmailPortale())->impagina($corpo, $titolo, $colore);
    }
}

if (!function_exists('invia_email_attestato_se_concluso')) {
    // Dopo la presenza: se l'evento è concluso manda l'email con il link all'attestato (per le classi, gli attestati degli
    // studenti al docente). Ritorna true se ha inviato: facciata di App\Attestati\ServizioAttestati
    function invia_email_attestato_se_concluso($conn, $pr_id) {
        return \App\Core\App::per($conn)->get(\App\Attestati\ServizioAttestati::class)->inviaSeConcluso((int)$pr_id);
    }
}

// 3. HELPER DATE E CALENDARI (Ora Ripristinati!)
if (!function_exists('formattaDataItaliano')) {
    function formattaDataItaliano($data_str) {
        return \App\Portale\Vista\Testi::dataInItaliano($data_str);
    }
}

// Turni: nome, data e orari sono tutti facoltativi (almeno nome o data)
if (!function_exists('orario_turno')) {
    function orario_turno(array $t): string {
        return \App\Eventi\Turni::orario($t);
    }
}

if (!function_exists('etichetta_turno')) {
    // Testo semplice (da passare a htmlspecialchars): "Gruppo 1 · 22/09/2026 · 09:30–11:00"
    function etichetta_turno(array $t): string {
        return \App\Eventi\Turni::etichetta($t);
    }
}

if (!function_exists('turno_concluso')) {
    // Un turno senza data non scade mai.
    function turno_concluso(array $t): bool {
        return \App\Eventi\Turni::concluso($t);
    }
}

if (!function_exists('pulisci_descrizione_breve')) {
    // HTML della descrizione breve: solo grassetto, corsivo, sottolineato e a capo (oltre 300 caratteri: testo troncato)
    function pulisci_descrizione_breve(string $html): string {
        return \App\Portale\Vista\Testi::pulisciDescrizioneBreve($html);
    }
}

if (!function_exists('testo_card_evento')) {
    // HTML delle card: la descrizione breve; se manca, l'inizio della descrizione completa senza formattazione
    function testo_card_evento(array $ev, int $max = 220): string {
        return \App\Portale\Vista\Testi::testoCardEvento($ev, $max);
    }
}

if (!function_exists('finestra_prenotazione')) {
    // Scadenza/apertura delle prenotazioni di un turno, o di un insieme di turni (card dell'evento):
    // tra i turni non conclusi, se qualcuno è prenotabile ora -> la chiusura più vicina ("Prenota entro…"),
    // altrimenti l'apertura più vicina ("Prenotazioni dal…"), altrimenti "Prenotazioni chiuse".
    // Ritorna ['testo', 'icona', 'bg', 'fg'] oppure null se non c'è nulla da dire (nessuna data impostata).
    function finestra_prenotazione(array $turni): ?array {
        return \App\Eventi\Turni::finestraPrenotazione($turni);
    }
}

if (!function_exists('getGoogleCalendarUrl')) {
    function getGoogleCalendarUrl($title, $data_turno, $ora_inizio, $ora_fine, $location, $details) {
        return \App\Eventi\Turni::urlGoogleCalendar($title, $data_turno, $ora_inizio, $ora_fine, $location, $details);
    }
}

// getPostiOccupati() è definita in config.php (unica versione, con regola degli stati e FOR UPDATE)
