<?php
// inc/seminari.php - Seminari: relatore (dall'anagrafe di Ateneo o esterno, con ente), abstract, diretta online,
// registrazione e slide. Si compilano nel modulo dell'evento (admin/eventi.php, riquadro «Seminario») e compaiono nella
// scheda pubblica dell'evento, nell'agenda e nel calendario .ics.
// La logica sta in src/Eventi/ (ServizioSeminari, Vista\Seminario): qui restano le facciate.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

if (!function_exists('salva_seminario_evento')) {
    // Salva i dati del seminario dal POST del modulo dell'evento; le slide sono un PDF caricato ($_FILES['slide_pdf'])
    function salva_seminario_evento($conn, int $ev_id, array $post, array $files = []): void {
        \App\Core\App::per($conn)->get(\App\Eventi\ServizioSeminari::class)->salva($ev_id, $post, $files);
    }
    function e_seminario(array $ev): bool {
        return \App\Eventi\ServizioSeminari::eSeminario($ev);
    }
    // Riquadro pubblico del seminario: relatore, abstract, diretta (solo prima della fine), registrazione e slide
    function html_seminario(array $ev, bool $concluso = false): string {
        return \App\Eventi\Vista\Seminario::riquadro($ev, $concluso);
    }
}
