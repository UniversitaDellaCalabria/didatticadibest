<?php
// inc/organizzazione.php - Organizzazione proposta del sito pubblico, per pubblico invece che per area (home e menu).
// La logica sta in src/Portale/ (OrganizzazioneProposta, MenuRepository): qui restano le funzioni di prima come facciate,
// per admin/testata.php e le prove automatiche.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

if (!function_exists('menu_proposto')) {
    // Voci proposte: [etichetta, url, [figli…]] (tre livelli come il menu del sito: voce, colonna, collegamenti)
    function menu_proposto($conn): array {
        return \App\Core\App::per($conn)->get(\App\Portale\OrganizzazioneProposta::class)->menuProposto();
    }
    // Voci del menu attuale che la proposta già comprende (home, aree, archivi): si tolgono, le altre restano in fondo
    function voci_menu_da_tenere($conn): array {
        return \App\Core\App::per($conn)->get(\App\Portale\OrganizzazioneProposta::class)->vociDaTenere();
    }
    // Applica home e menu proposti (prima una copia di sicurezza). Ritorna il numero di voci del nuovo menu.
    function applica_organizzazione_proposta($conn, string $autore, bool $home = true, bool $menu = true): int {
        return \App\Core\App::per($conn)->get(\App\Portale\OrganizzazioneProposta::class)->applica($autore, $home, $menu);
    }
    // Voci del menu proposto già create nascoste (crea_menu_proposto_nascosto): id delle voci di primo livello, in ordine
    function voci_menu_proposto($conn): array {
        return \App\Core\App::per($conn)->get(\App\Portale\OrganizzazioneProposta::class)->vociMenuNascosto();
    }
    // Crea le voci del menu proposto NASCOSTE (il menu attuale non cambia). Ritorna le voci create.
    function crea_menu_proposto_nascosto($conn, string $autore = ''): int {
        return \App\Core\App::per($conn)->get(\App\Portale\OrganizzazioneProposta::class)->creaMenuNascosto($autore);
    }
    // Mostra le voci create nascoste e nasconde quelle del menu attuale che la proposta comprende (prima una copia)
    function mostra_menu_proposto($conn, string $autore = ''): bool {
        return \App\Core\App::per($conn)->get(\App\Portale\OrganizzazioneProposta::class)->mostraMenuNascosto($autore);
    }
    function ultima_copia_configurazione($conn): ?array {
        return \App\Core\App::per($conn)->get(\App\Portale\OrganizzazioneProposta::class)->ultimaCopia();
    }
    // Ripristina home e menu com'erano prima dell'ultima applicazione della proposta
    function ripristina_organizzazione($conn): bool {
        return \App\Core\App::per($conn)->get(\App\Portale\OrganizzazioneProposta::class)->ripristina();
    }
}
