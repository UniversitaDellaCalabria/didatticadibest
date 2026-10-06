<?php
// inc/liste_attesa.php - Liste d'attesa: promozione dei posti liberati e scadenze (anche senza cron).
// La logica sta in src/Iscrizioni/ (ServizioListaAttesa): qui restano le facciate.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

// =======================================================================
// MOTORE INTELLIGENTE LISTE D'ATTESA
// =======================================================================
if (!function_exists('promuovi_lista_attesa')) {
    // Offre i posti liberi del turno a chi è in coda (24 ore per confermare) e lo avvisa per email
    function promuovi_lista_attesa($conn, $turno_id) {
        \App\Core\App::per($conn)->get(\App\Iscrizioni\ServizioListaAttesa::class)->promuovi((int)$turno_id);
    }
}

if (!function_exists('check_automazioni_sistema')) {
    // Offerte scadute (il posto passa al successivo in coda) e chiusura delle code a meno di 24 ore dall'evento
    function check_automazioni_sistema($conn) {
        \App\Core\App::per($conn)->get(\App\Iscrizioni\ServizioListaAttesa::class)->controllaScadenze();
    }
}

// ESECUZIONE SILENTE (Sostituto del Cron Job)
// Esegue il controllo solo una volta ogni 5 minuti
if (!isset($_SESSION['last_cron_run']) || (time() - $_SESSION['last_cron_run']) > 300) {
    check_automazioni_sistema($conn);
    $_SESSION['last_cron_run'] = time();
}
