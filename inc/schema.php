<?php
// inc/schema.php - Aggiornamento automatico dello schema del database (tabelle e colonne mancanti).
// La logica sta in src/Core/Schema/ (Migrazioni: sequenza delle operazioni e marcatore; Definizioni: tabelle e colonne).
// Per aggiungere una tabella o una colonna: Definizioni::tabelle()/colonne() e Migrazioni::VERSIONE + 1.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

if (!function_exists('assicura_schema')) {
    function assicura_schema($conn) {
        // I dati di partenza dei moduli restano nelle loro funzioni (chiamate solo se esistono, come prima)
        (new \App\Core\Schema\Migrazioni(
            \App\Core\Database::per($conn),
            RADICE_SITO . '/cache',
            defined('GRUPPI_PERSONALE') ? array_values(GRUPPI_PERSONALE) : [],
            function () use ($conn): void { if (function_exists('prepara_flusso_convalide')) prepara_flusso_convalide($conn); },
            function (): void { if (function_exists('invalidate_configurazione_portale_cache')) invalidate_configurazione_portale_cache(); },
        ))->aggiorna();
    }
}
