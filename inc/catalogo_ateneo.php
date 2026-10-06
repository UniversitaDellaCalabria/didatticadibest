<?php
// inc/catalogo_ateneo.php - Catalogo di Ateneo per i moduli della Didattica: corsi di studio di tutti i dipartimenti
// per anno di offerta (API cds, aggiornati con l'anagrafe ogni settimana) e insegnamenti di un corso in un anno di offerta
// (API activities), scaricati la prima volta che uno studente li cerca e rinnovati ogni 30 giorni.
// Lo studente sceglie tipo di corso (triennale, magistrale…), corso di studio, anno accademico di offerta e insegnamento
// (cerca_insegnamenti.php + assets/js/campi-pratica.js); se non lo trova lo scrive a mano.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

if (!defined('TIPI_CORSO_ATENEO')) define('TIPI_CORSO_ATENEO', \App\Anagrafi\Anagrafe::TIPI_CORSO_ATENEO);

// Funzioni di prima come facciate di App\Anagrafi\ServizioCatalogo e ServizioCorsi.
if (!function_exists('catalogo_tipi_corso')) {
    // Tipi di corso proposti: quelli presenti nel catalogo, altrimenti quelli dell'anagrafe dei corsi del Dipartimento
    function catalogo_tipi_corso($conn): array {
        return \App\Core\App::per($conn)->get(\App\Anagrafi\ServizioCatalogo::class)->tipi();
    }
}
if (!function_exists('catalogo_corsi')) {
    // Corsi di studio di un tipo: [['codice', 'nome', 'dipartimento', 'anni' => [...]]]; $aa > 0: solo quelli offerti in quell'anno
    function catalogo_corsi($conn, string $tipo, int $aa = 0): array {
        return \App\Core\App::per($conn)->get(\App\Anagrafi\ServizioCatalogo::class)->corsi($tipo, $aa);
    }
}
if (!function_exists('catalogo_anni')) {
    // Anni accademici di offerta (anno di inizio) dei corsi di un tipo, dal più recente
    function catalogo_anni($conn, string $tipo): array {
        return \App\Core\App::per($conn)->get(\App\Anagrafi\ServizioCatalogo::class)->anni($tipo);
    }
}
if (!function_exists('catalogo_corso')) {
    // Nome del corso dal catalogo (o dall'anagrafe del Dipartimento)
    function catalogo_corso($conn, string $codice): ?array {
        return \App\Core\App::per($conn)->get(\App\Anagrafi\ServizioCatalogo::class)->corso($codice);
    }
}
if (!function_exists('catalogo_insegnamenti')) {
    // Insegnamenti di un corso per l'anno accademico di offerta: copia locale (rinnovata ogni 30 giorni) o API
    function catalogo_insegnamenti($conn, string $cds, int $coorte, bool $scarica = true): array {
        return \App\Core\App::per($conn)->get(\App\Anagrafi\ServizioCatalogo::class)->insegnamenti($cds, $coorte, $scarica);
    }
}
if (!function_exists('salva_catalogo_insegnamenti')) {
    // Salva gli insegnamenti arrivati dalle API per un corso e un anno di offerta (solo quelli di quel corso)
    function salva_catalogo_insegnamenti($conn, string $cds, int $coorte, array $el): int {
        return \App\Core\App::per($conn)->get(\App\Anagrafi\ServizioCatalogo::class)->salvaInsegnamenti($cds, $coorte, $el);
    }
}
if (!function_exists('insegnamenti_dipartimento_scelta')) {
    // Insegnamenti del Dipartimento per le decisioni in seduta (convalide, piano di studi): id => dati con corso e CFU
    function insegnamenti_dipartimento_scelta($conn): array {
        return \App\Core\App::per($conn)->get(\App\Anagrafi\ServizioCorsi::class)->insegnamentiDipartimentoScelta();
    }
    function etichetta_insegnamento_scelta(array $i): string {
        return \App\Anagrafi\Testi::etichettaInsegnamentoScelta($i);
    }
}
