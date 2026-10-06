<?php
// inc/calendario_risorse.php - Vista a calendario delle risorse (Prenotazioni e risorse): giorno e settimana come griglia
// risorse × ore con le prenotazioni a blocchi colorati, mese come calendario con il numero di prenotazioni per giorno.
// Usata dal pannello (admin/prenotazioni_risorse.php) e dalla pagina pubblica dell'area (calendario_area.php).
// I nomi di chi ha prenotato si vedono solo ai gestori; agli altri "Prenotato" (e "La tua prenotazione" per le proprie).
// La logica sta in src/Risorse/ (CalendarioRisorse, Vista\Calendario, ServizioAuleTurni, StatisticheRisorse): qui restano le facciate.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

if (!defined('LEGENDA_CALENDARIO_RISORSE')) define('LEGENDA_CALENDARIO_RISORSE', \App\Risorse\Costanti::LEGENDA_CALENDARIO);

if (!function_exists('periodo_calendario_risorse')) {
    // Giorni da mostrare: ['giorno' | 'settimana' | 'mese', data di riferimento] → [primo giorno, ultimo giorno] (Y-m-d)
    function periodo_calendario_risorse(string $vista, string $data): array {
        return \App\Risorse\CalendarioRisorse::periodo($vista, $data);
    }
}

if (!function_exists('prenotazioni_calendario_risorse')) {
    // Prenotazioni attive (confermate e da approvare) delle risorse nel periodo, per risorsa
    function prenotazioni_calendario_risorse($conn, array $ids_risorse, string $dal, string $al): array {
        return \App\Core\App::per($conn)->get(\App\Risorse\RisorsaRepository::class)->nelPeriodo($ids_risorse, $dal, $al);
    }
}

if (!function_exists('html_calendario_risorse')) {
    // $risorse: righe di risorse (già filtrate). $o: vista (giorno|settimana|mese), data (Y-m-d), uid (utente collegato o 0),
    // gestore (vede i nomi), url (callable fn(array $cambia): string per navigazione e filtri), url_risorsa (callable fn(int $id, string $data): string),
    // filtri (['tipo' => '', 'capienza' => 0]).
    function html_calendario_risorse($conn, array $risorse, array $o): string {
        return \App\Core\App::per($conn)->get(\App\Risorse\Vista\Calendario::class)->html($risorse, $o);
    }
}

// =======================================================================
// AULE COLLEGATE AGLI EVENTI: il turno di un evento sceglie un'aula (o laboratorio) di Prenotazioni e risorse
// e lo slot viene occupato in automatico (prenotazioni_risorse.turno_id), aggiornato quando cambiano data e orari,
// liberato quando si toglie l'aula o il turno. Se l'aula è già occupata o chiusa il turno non la prenota e si avvisa.
// =======================================================================
if (!function_exists('aule_per_turni')) {
    // Risorse attive delle aree di Prenotazioni e risorse, per area: [titolo area => [id => nome], ...]
    function aule_per_turni($conn): array {
        return \App\Core\App::per($conn)->get(\App\Risorse\ServizioAuleTurni::class)->perTurni();
    }
}

if (!function_exists('sincronizza_aula_turno')) {
    // Allinea la prenotazione dell'aula al turno. $avvisi riceve i problemi da mostrare a chi salva.
    function sincronizza_aula_turno($conn, int $turno_id, array &$avvisi, int $uid = 0): void {
        \App\Core\App::per($conn)->get(\App\Risorse\ServizioAuleTurni::class)->sincronizza($turno_id, $avvisi, $uid);
    }
}

if (!function_exists('css_calendario_risorse')) {
    // Stile della vista a calendario (una volta per pagina)
    function css_calendario_risorse(): string {
        return \App\Core\App::get(\App\Risorse\Vista\Calendario::class)->css();
    }
}

if (!function_exists('statistiche_risorse')) {
    // Statistiche delle prenotazioni dell'area $pid tra $dal e $al: per risorsa (prenotazioni, ore prenotate, ore aperte
    // secondo gli orari settimanali, utilizzo, annullate e rifiutate) e fasce più richieste (giorno della settimana × ora).
    function statistiche_risorse($conn, int $pid, string $dal, string $al): array {
        return \App\Core\App::per($conn)->get(\App\Risorse\StatisticheRisorse::class)->statistiche($pid, $dal, $al);
    }
}
