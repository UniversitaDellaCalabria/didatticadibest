<?php
// inc/dati.php - Lettura dei dati: sondaggi, archivio, campi dei moduli, prenotazioni, pagine, vincoli di iscrizione, statistiche e pannello.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

// =======================================================================
// SONDAGGI (logica in src/Sondaggi/: ServizioSondaggi, SondaggioRepository)
// =======================================================================
if (!function_exists('get_prenotazione_by_token_sondaggio')) {
    function get_prenotazione_by_token_sondaggio($conn, string $token): ?array {
        return \App\Core\App::per($conn)->get(\App\Sondaggi\ServizioSondaggi::class)->prenotazionePerToken($token);
    }
}

if (!function_exists('get_sondaggio_attivo')) {
    function get_sondaggio_attivo($conn, int $evento_id): ?array {
        return \App\Core\App::per($conn)->get(\App\Sondaggi\ServizioSondaggi::class)->attivoDelEvento($evento_id);
    }
}

if (!function_exists('get_domande_sondaggio')) {
    function get_domande_sondaggio($conn, int $sondaggio_id): array {
        return \App\Core\App::per($conn)->get(\App\Sondaggi\ServizioSondaggi::class)->domande($sondaggio_id);
    }
}

if (!function_exists('salva_risposte_sondaggio')) {
    function salva_risposte_sondaggio($conn, int $sond_id, array $risposte, int $pr_id, ?string &$errore = null): bool {
        return \App\Core\App::per($conn)->get(\App\Sondaggi\ServizioSondaggi::class)->salvaRisposte($sond_id, $risposte, $pr_id, $errore);
    }
}

// =======================================================================
// ARCHIVIO EVENTI
// =======================================================================
if (!function_exists('get_eventi_archivio')) {
    function get_eventi_archivio($conn, int $p_id): array {
        return \App\Core\App::per($conn)->get(\App\Eventi\EventoRepository::class)->archivio($p_id);
    }
}

// =======================================================================
// FORM / CAMPI CUSTOM
// =======================================================================
if (!function_exists('get_campi_form')) {
    function get_campi_form($conn, int $evento_id): array {
        return \App\Core\App::per($conn)->get(\App\Iscrizioni\CampiFormRepository::class)->delEvento($evento_id);
    }
}

// =======================================================================
// PRENOTAZIONI / RICEVUTA
// =======================================================================
if (!function_exists('get_attestato')) {
    function get_attestato($conn, string $code): ?array {
        return \App\Core\App::per($conn)->get(\App\Attestati\ServizioAttestati::class)->perCodice($code);
    }
}

if (!function_exists('get_prenotazione_ricevuta')) {
    function get_prenotazione_ricevuta($conn, string $code, int $id): ?array {
        return \App\Core\App::per($conn)->get(\App\Iscrizioni\PrenotazioneRepository::class)->ricevuta($code, $id);
    }
}

// =======================================================================
// PAGINE EVENTI
// =======================================================================
if (!function_exists('get_pagina_by_slug')) {
    function get_pagina_by_slug($conn, string $slug): ?array {
        return \App\Core\App::per($conn)->get(\App\Eventi\AreaRepository::class)->perSlug($slug);
    }
}

// =======================================================================
// PAGINE EVENTI (HOME)
// =======================================================================
if (!function_exists('get_pagine_eventi_visibili')) {
    function get_pagine_eventi_visibili($conn): array {
        return \App\Core\App::per($conn)->get(\App\Eventi\AreaRepository::class)->visibili();
    }
}

// =======================================================================
// VINCOLO ISCRIZIONI PER AREA (limite_iscrizioni: nessuno | un_evento | un_turno)
// =======================================================================
// Le liste d'attesa NON contano per il vincolo: si può stare in attesa su più turni/eventi.
// Appena una prenotazione dello stesso ambito diventa 'confermata', le altre decadono
// (vedi decadi_attese_vincolate).

if (!function_exists('scope_vincolo_sql')) {
    // Condizione SQL (alias t, e) che delimita l'ambito del vincolo, oppure null se non c'è vincolo.
    function scope_vincolo_sql(string $limite, int $pagina_id, int $evento_id): ?string {
        return \App\Iscrizioni\ServizioVincoli::condizioneAmbito($limite, $pagina_id, $evento_id);
    }
}

if (!function_exists('trova_iscrizione_vincolata')) {
    // Ritorna la prenotazione attiva (non in lista d'attesa) che blocca una nuova iscrizione, oppure null.
    function trova_iscrizione_vincolata($conn, string $limite, int $pagina_id, int $evento_id, int $utente_id, string $email, string $matricola): ?array {
        return \App\Core\App::per($conn)->get(\App\Iscrizioni\ServizioVincoli::class)->iscrizioneVincolata($limite, $pagina_id, $evento_id, $utente_id, $email, $matricola);
    }
}

if (!function_exists('get_mie_iscrizioni_area')) {
    // Mappa evento_id => [turno_id => stato] delle prenotazioni attive dell'utente nell'area.
    function get_mie_iscrizioni_area($conn, int $pagina_id, int $utente_id, string $email): array {
        return \App\Core\App::per($conn)->get(\App\Iscrizioni\ServizioVincoli::class)->mieIscrizioniArea($pagina_id, $utente_id, $email);
    }
}

if (!function_exists('decadi_attese_vincolate')) {
    // Da chiamare DOPO che una prenotazione è diventata 'confermata'. Se l'area ha un limite
    // iscrizioni, annulla le altre richieste pendenti della stessa persona nello stesso ambito
    // (liste d'attesa, posti offerti in attesa di conferma, richieste da approvare), avvisa
    // l'utente con una email e ripassa i posti liberati alla lista d'attesa. Ritorna quante ne annulla.
    function decadi_attese_vincolate($conn, int $pr_id): int {
        return \App\Core\App::per($conn)->get(\App\Iscrizioni\ServizioVincoli::class)->decadiAttese($pr_id);
    }
}

// =======================================================================
// TURNI
// =======================================================================
if (!function_exists('get_turno_con_evento')) {
    function get_turno_con_evento($conn, int $turno_id): ?array {
        return \App\Core\App::per($conn)->get(\App\Iscrizioni\PrenotazioneRepository::class)->turnoConEvento($turno_id);
    }
}

// =======================================================================
// PROFILO UTENTE
// =======================================================================
if (!function_exists('aggiorna_email_utente')) {
    /**
     * Aggiorna l'email dell'utente e la marca come personalizzata.
     * Ritorna true in caso di successo, oppure una stringa di errore.
     */
    function aggiorna_email_utente($conn, int $u_id, string $nuova_email) {
        return \App\Core\App::per($conn)->get(\App\Auth\ServizioUtenti::class)->aggiornaEmail($u_id, $nuova_email);
    }
}

// =======================================================================
// RICERCA GLOBALE
// =======================================================================
if (!function_exists('cerca_eventi')) {
    function cerca_eventi($conn, string $q): array {
        return \App\Core\App::per($conn)->get(\App\Eventi\EventoRepository::class)->cerca($q);
    }
}

// =======================================================================
// ADMIN — STATISTICHE
// =======================================================================
if (!function_exists('get_kpi_statistiche')) {
    function get_kpi_statistiche($conn, $p_id, $sql_filtro_rbac) {
        return \App\Core\App::per($conn)->get(\App\Eventi\StatisticheRepository::class)->kpi((int)$p_id, (string)$sql_filtro_rbac);
    }
}

if (!function_exists('get_dati_grafico_eventi')) {
    function get_dati_grafico_eventi($conn, $p_id, $sql_filtro_rbac) {
        return \App\Core\App::per($conn)->get(\App\Eventi\StatisticheRepository::class)->graficoEventi((int)$p_id, (string)$sql_filtro_rbac);
    }
}

if (!function_exists('get_stats_turni')) {
    function get_stats_turni($conn, $p_id, $sql_filtro_rbac) {
        return \App\Core\App::per($conn)->get(\App\Eventi\StatisticheRepository::class)->turni((int)$p_id, (string)$sql_filtro_rbac);
    }
}

if (!function_exists('get_kpi_statistiche_v2')) {
    function get_kpi_statistiche_v2($conn, $p_id, $sql_filtro_rbac) {
        return \App\Core\App::per($conn)->get(\App\Eventi\StatisticheRepository::class)->kpiCompleti((int)$p_id, (string)$sql_filtro_rbac);
    }
}

if (!function_exists('get_trend_iscrizioni')) {
    function get_trend_iscrizioni($conn, $p_id, $sql_filtro_rbac, $days = 30) {
        return \App\Core\App::per($conn)->get(\App\Eventi\StatisticheRepository::class)->trendIscrizioni((int)$p_id, (string)$sql_filtro_rbac, (int)$days);
    }
}

if (!function_exists('get_stats_turni_ext')) {
    function get_stats_turni_ext($conn, $p_id, $sql_filtro_rbac) {
        return \App\Core\App::per($conn)->get(\App\Eventi\StatisticheRepository::class)->turniCompleti((int)$p_id, (string)$sql_filtro_rbac);
    }
}

// =======================================================================
// ADMIN — LOOKUP (ruoli, sottocategorie)
// =======================================================================
if (!function_exists('get_sottocategorie')) {
    function get_sottocategorie($conn, $p_id) {
        return \App\Core\App::per($conn)->get(\App\Eventi\EventoRepository::class)->sottocategorie((int)$p_id);
    }
}

if (!function_exists('get_ruoli')) {
    function get_ruoli($conn) {
        return \App\Core\App::per($conn)->get(\App\Auth\RuoloRepository::class)->tutti();
    }
}

// =======================================================================
// ADMIN — CHECK-IN
// =======================================================================
if (!function_exists('get_prenotazione_per_checkin_admin')) {
    function get_prenotazione_per_checkin_admin($conn, string $code): ?array {
        return \App\Core\App::per($conn)->get(\App\Iscritti\IscrittiRepository::class)->prenotazionePerCheckinAdmin($code);
    }
}

// =======================================================================
// ADMIN — MESSAGGI
// =======================================================================
if (!function_exists('get_inbox_conversazioni')) {
    function get_inbox_conversazioni($conn, $p_id, $pr_filter_sql = '') {
        return \App\Core\App::per($conn)->get(\App\Iscritti\MessaggiRepository::class)->conversazioni((int)$p_id, (string)$pr_filter_sql);
    }
}

// =======================================================================
// ADMIN — ISCRITTI
// =======================================================================
if (!function_exists('get_prenotazione_con_turno_evento')) {
    function get_prenotazione_con_turno_evento($conn, $pr_id) {
        return \App\Core\App::per($conn)->get(\App\Iscritti\IscrittiRepository::class)->prenotazioneConTurnoEvento((int)$pr_id);
    }
}

if (!function_exists('get_destinatari_email_massiva')) {
    function get_destinatari_email_massiva($conn, $p_id, $turno_id = 0) {
        return \App\Core\App::per($conn)->get(\App\Iscritti\IscrittiRepository::class)->destinatariEmailMassiva((int)$p_id, (int)$turno_id);
    }
}

if (!function_exists('get_turno_admin')) {
    function get_turno_admin($conn, $turno_id) {
        return \App\Core\App::per($conn)->get(\App\Iscritti\IscrittiRepository::class)->turnoAdmin((int)$turno_id);
    }
}

if (!function_exists('get_campi_custom_export')) {
    function get_campi_custom_export($conn, $p_id) {
        return \App\Core\App::per($conn)->get(\App\Iscritti\IscrittiRepository::class)->campiCustomExport((int)$p_id);
    }
}

if (!function_exists('get_eventi_con_turni_admin')) {
    function get_eventi_con_turni_admin($conn, $p_id, $is_archivio, $sql_filtro_rbac) {
        return \App\Core\App::per($conn)->get(\App\Eventi\EventoRepository::class)->conTurniAdmin((int)$p_id, (int)$is_archivio, (string)$sql_filtro_rbac);
    }
}

if (!function_exists('get_messaggi_per_prenotazioni')) {
    function get_messaggi_per_prenotazioni($conn, array $pr_ids) {
        return \App\Core\App::per($conn)->get(\App\Iscritti\MessaggiRepository::class)->perPrenotazioni($pr_ids);
    }
}

// Da chiamare subito dopo ogni UPDATE/INSERT su configurazione_portale
// (oggi solo in admin/testata.php), così le nuove impostazioni sono visibili
// immediatamente invece di aspettare la scadenza naturale della cache.
if (!function_exists('invalidate_configurazione_portale_cache')) {
    function invalidate_configurazione_portale_cache() {
        \App\Core\App::get(\App\Portale\CacheConfigurazione::class)->invalida();
    }
}
