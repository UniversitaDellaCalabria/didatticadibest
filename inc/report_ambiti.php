<?php
// inc/report_ambiti.php - Report per ambito (Orientamento, Ricerca, Public engagement, Didattica) per la Terza missione e
// il public engagement: per anno solare o accademico, eventi, incontri, ore, iscrizioni, presenze, partecipanti
// (per le classi il numero di studenti dichiarato dal docente), scuole raggiunte; dettaglio degli eventi e andamento per mese.
// Pagina admin/report_ambiti.php (Excel con lo stesso contenuto). La logica sta in src/Eventi/ServizioReport.php:
// qui restano le facciate. Caricato da functions.php: non includerlo da solo.

if (!function_exists('periodo_report')) {
    // [dal, al] di un anno solare (2026) o accademico ('2025/2026': 1 ottobre – 30 settembre)
    function periodo_report(string $anno): array {
        return \App\Eventi\ServizioReport::periodo($anno);
    }
}

if (!function_exists('dati_report_ambiti')) {
    // Ritorna ['eventi' => [riga per evento], 'ambiti' => [ambito => totali], 'totale' => totali (eventi contati una volta),
    //          'mesi' => ['2026-03' => [ambito => eventi]]]. Solo turni con data nel periodo; eventi archiviati compresi.
    function dati_report_ambiti($conn, string $dal, string $al): array {
        return \App\Core\App::per($conn)->get(\App\Eventi\ServizioReport::class)->dati($dal, $al);
    }
}

if (!function_exists('excel_report_ambiti')) {
    function excel_report_ambiti(array $d, string $periodo): ?string {
        return \App\Core\App::get(\App\Eventi\ServizioReport::class)->excel($d, $periodo);
    }
}
