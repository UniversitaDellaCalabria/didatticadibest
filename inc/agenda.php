<?php
// inc/agenda.php - Agenda unica del sito: eventi e progetti in programma in tutte le aree visibili (non i calendari a slot),
// con gli ambiti (Orientamento, Ricerca, Public engagement, Didattica), il prossimo turno e i posti.
// Usata dalla home (widget «Agenda»), da agenda.php, da orientamento.php e dal calendario agenda_ics.php.
// La logica sta in src/Eventi/ (ServizioAgenda, AgendaRepository, Vista\Agenda): qui restano le facciate.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

if (!function_exists('eventi_agenda')) {
    // $f: ambito (chiave di AMBITI_EVENTO o ''), scuole (bool: solo attività per le scuole), q (testo), limite (0 = tutti),
    //     pagine (id delle aree da considerare; vuoto = tutte quelle visibili), solo_home (bool: solo aree mostrate in home)
    // Ritorna eventi ordinati per prossima data (quelli «data da definire» in fondo) con: prossima_data, prossimo_orario,
    // ambiti, per_scuole, area (riga di pagine_eventi), url.
    function eventi_agenda($conn, array $f = []): array {
        return \App\Core\App::per($conn)->get(\App\Eventi\ServizioAgenda::class)->eventi($f);
    }
}

if (!function_exists('conta_ambiti_agenda')) {
    // Quanti eventi in programma per ambito (per i filtri): ['orientamento' => 4, …, '' => totale]
    function conta_ambiti_agenda(array $eventi): array {
        return \App\Core\App::get(\App\Eventi\ServizioAgenda::class)->contaAmbiti($eventi);
    }
}

if (!function_exists('ics_agenda')) {
    // Calendario .ics dei turni in programma degli eventi dati (iscrizione da Google Calendar, Outlook…)
    function ics_agenda($conn, array $eventi, string $nome): string {
        return \App\Core\App::per($conn)->get(\App\Eventi\ServizioAgenda::class)->ics($eventi, $nome);
    }
}

if (!function_exists('html_voce_agenda')) {
    // Una riga dell'agenda: data, titolo, area e ambiti, luogo e ora, posti. $posti = riga di get_riepilogo_posti() o null.
    function html_voce_agenda(array $e, ?array $posti = null): string {
        return \App\Core\App::get(\App\Eventi\Vista\Agenda::class)->voce($e, $posti);
    }
    // Stile comune delle voci dell'agenda (home, agenda.php, orientamento.php)
    function css_agenda(): string {
        return \App\Core\App::get(\App\Eventi\Vista\Agenda::class)->css();
    }
}
