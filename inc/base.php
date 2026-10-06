<?php
// inc/base.php - Utilità di base: escaping, messaggi flash, limiti di richieste, invio delle email, destinatari delle notifiche, indirizzo del sito.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

// ── Utility: output escaping ──────────────────────────────────────────────────
if (!function_exists('h')) {
    // Facciata di App\Sistema\Html::h
    function h($s) {
        return \App\Sistema\Html::h($s);
    }
}

// ── Flash messages (sessione) ─────────────────────────────────────────────────
// Facciate di App\Auth\Flash (messaggio mostrato una volta sola dopo un rimando)
if (!function_exists('flash_set')) {
    function flash_set($msg, $type = 'success') {
        (new \App\Auth\Flash(new \App\Auth\SessioneNativa()))->imposta($msg, $type);
    }
    function flash_get() {
        return (new \App\Auth\Flash(new \App\Auth\SessioneNativa()))->prendi();
    }
    function flash_html() {
        return (new \App\Auth\Flash(new \App\Auth\SessioneNativa()))->html();
    }
}

// ── Rate limiting (protezione endpoint da flooding) ───────────────────────────
if (!function_exists('check_rate_limit')) {
    // true se la richiesta è permessa, false se l'IP ha superato il limite: facciata di App\Auth\LimiteRichieste
    function check_rate_limit($conn, $endpoint, $max = 10, $window_sec = 300) {
        return \App\Core\App::per($conn)->get(\App\Auth\LimiteRichieste::class)
            ->consenti((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), (string)$endpoint, (int)$max, (int)$window_sec);
    }
}

if (!defined('COOKIE_USCITO')) define('COOKIE_USCITO', 'dibest_uscito');
if (!function_exists('imposta_cookie_uscito')) {
    // true = l'utente ha fatto "Esci" (30 giorni); false = ha rifatto l'accesso (App\Auth\CookieUscito)
    function imposta_cookie_uscito(bool $uscito): void {
        (new \App\Auth\CookieUscito())->imposta($uscito);
    }
}

// 1. GESTIONE AUTENTICAZIONE SSO UNIFICATA (Con Auto-Riparazione Email)
if (!function_exists('db_query')) {
    // Query con parametri (prepared statement): i valori non entrano mai nel testo SQL.
    // db_righe($conn, "SELECT * FROM t WHERE id = ? AND stato = ?", [5, 'ok']) → righe; db_riga → una riga o null;
    // db_valore → primo campo della prima riga o null; db_esegui → righe toccate (-1 se la query non riesce).
    // Tipo di ogni parametro: int → i, float → d, il resto (anche null) → s.
    // Facciate della classe App\Core\Database (src/Core/Database.php): stessi risultati di prima; il codice nuovo usa la classe.
    function db_query($conn, string $sql, array $par = []) {
        return \App\Core\Database::per($conn)->grezza($sql, $par);
    }
    function db_righe($conn, string $sql, array $par = []): array {
        return \App\Core\Database::per($conn)->righe($sql, $par);
    }
    function db_riga($conn, string $sql, array $par = []): ?array {
        return \App\Core\Database::per($conn)->riga($sql, $par);
    }
    function db_valore($conn, string $sql, array $par = []) {
        return \App\Core\Database::per($conn)->valore($sql, $par);
    }
    function db_esegui($conn, string $sql, array $par = []): int {
        return \App\Core\Database::per($conn)->esegui($sql, $par);
    }
}

if (!function_exists('sync_sso_user')) {
    // Accesso automatico dalla sessione SSO di Ateneo: facciata di App\Auth\Saml\AccessoSso::sincronizza
    function sync_sso_user($conn) {
        return \App\Core\App::per($conn)->get(\App\Auth\Saml\AccessoSso::class)->sincronizza(
            !empty($_COOKIE[COOKIE_USCITO]), '/opt/simplesamlphp/lib/_autoload.php',
            (string)($_SERVER['REMOTE_ADDR'] ?? ''), (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    }
}

// 1a. EMAIL DAGLI ATTRIBUTI SAML (facciate di App\Auth\Saml\AttributiSaml)
if (!function_exists('estrai_email_saml')) {
    function estrai_email_saml(array $attributes, string $tipo = 'esterno'): string {
        return \App\Auth\Saml\AttributiSaml::email($attributes, $tipo);
    }
}

if (!function_exists('tipo_utente_saml')) {
    function tipo_utente_saml(string $matr_stud, string $matr_dip): string {
        return \App\Auth\Saml\AttributiSaml::tipoUtente($matr_stud, $matr_dip);
    }
}

// 1b. LOG ACCESSI SSO
if (!function_exists('registra_accesso_sso')) {
    // Facciata di App\Auth\LogAccessiRepository::registra (IP e browser della richiesta)
    function registra_accesso_sso($conn, $utente_id, $email, $nome, $cognome, $tipo = 'sso') {
        \App\Core\App::per($conn)->get(\App\Auth\LogAccessiRepository::class)->registra((int)$utente_id, $email, $nome, $cognome,
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512), (string)$tipo);
    }
}

// 2. INVIO EMAIL UNIFICATO
// Ultimo errore di invio (mostrato dal pulsante "Email di prova" in admin/sistema.php)
$GLOBALS['ultimo_errore_email'] = '';

if (!function_exists('inviaNotificaEmail')) {
    // Invio di un'email del portale: facciata di App\Infrastructure\Mail\Mailer (client SMTP in src/Infrastructure/Mail/SmtpMailer.php).
    // $colore: colore dell'area (es. colore_area_turno()); null = rosso istituzionale
    // $allegati = [['path' => file sul server, 'nome' => nome del file nell'email], ...] (facoltativi)
    // L'ultimo errore resta in $GLOBALS['ultimo_errore_email'] (pulsante "Email di prova" di admin/sistema.php).
    function inviaNotificaEmail($to, $subject, $body_html, $conn, $colore = null, array $allegati = []) {
        $mailer = \App\Core\App::mailer($conn);
        $ok = $mailer->invia((string)$to, (string)$subject, (string)$body_html, $colore, $allegati);
        $GLOBALS['ultimo_errore_email'] = $mailer->ultimoErrore();
        return $ok;
    }
}

// ── Destinatari notifiche gestori ────────────────────────────────────────────
// Facciate di App\Auth\Abilitazioni\ServizioAbilitazioni (gestori delle aree e delle attività, perimetri, notifiche)
if (!function_exists('ids_gestori_da_campi')) {
    // ID gestore dai tre formati del DB: campo singolo, CSV legacy, JSON permessi (chiavi = ID utente)
    function ids_gestori_da_campi($singolo, $csv, $json): array {
        return \App\Auth\Abilitazioni\IdsGestori::daCampi($singolo, $csv, $json);
    }
}

if (!function_exists('get_gestori_ids_area')) {
    // Tutti i gestori di un'area: quelli dell'intera area + quelli assegnati ai singoli eventi.
    function get_gestori_ids_area($conn, int $pagina_id): array {
        return \App\Core\App::per($conn)->get(\App\Auth\Abilitazioni\ServizioAbilitazioni::class)->gestoriIdsArea($pagina_id);
    }
}

if (!function_exists('get_notifiche_gestori_attive')) {
    // ID dei gestori che ricevono le email sulle prenotazioni dell'area (null = mai configurato → tutti i gestori).
    function get_notifiche_gestori_attive($conn, int $pagina_id): ?array {
        return \App\Core\App::per($conn)->get(\App\Auth\Abilitazioni\ServizioAbilitazioni::class)->notificheGestoriAttive($pagina_id);
    }
}

if (!function_exists('set_notifica_gestore')) {
    function set_notifica_gestore($conn, int $pagina_id, int $utente_id, bool $attiva) {
        \App\Core\App::per($conn)->get(\App\Auth\Abilitazioni\ServizioAbilitazioni::class)->impostaNotificaGestore($pagina_id, $utente_id, $attiva);
    }
}

if (!function_exists('get_email_gestori_evento')) {
    // Email dei gestori da avvisare per un evento ($solo_notifiche_attive: interruttore "Notifiche prenotazioni")
    function get_email_gestori_evento($conn, int $evento_id, bool $solo_notifiche_attive = true): array {
        return \App\Core\App::per($conn)->get(\App\Auth\Abilitazioni\ServizioAbilitazioni::class)->emailGestoriEvento($evento_id, $solo_notifiche_attive);
    }
}

// ── Abilitazioni per perimetro ───────────────────────────────────────────────
// Oltre a "tutta l'area" (JSON dell'area) e "singole attività" (JSON dell'attività) si può abilitare un utente a:
//   'progetti' / 'eventi'  tutte le attività di quel tipo di un'area, anche quelle create dopo (pagina_id = area)
//   'fsl'                  pannello Formazione Scuola Lavoro + tutte le attività FSL di tutte le aree
//   'fsl_convenzioni'      solo il registro delle convenzioni;  'fsl_scuole'  solo l'anagrafe delle scuole
// Dentro il perimetro l'utente vede e gestisce tutto: attività, iscritti, sondaggi, moduli, attestati, statistiche.
if (!defined('TIPI_AMBITO')) define('TIPI_AMBITO', [
    'progetti'        => "Tutti i progetti dell'area",
    'eventi'          => "Tutti gli eventi dell'area",
    'fsl'             => "Formazione Scuola Lavoro: tutto",
    'fsl_convenzioni' => "Formazione Scuola Lavoro: solo convenzioni",
    'fsl_scuole'      => "Formazione Scuola Lavoro: solo anagrafe scuole",
    // Moduli interi (pagina_id 0): tutte le aree del modulo come "tutta l'area"; Orientamento comprende la FSL
    'modulo_orientamento' => "Modulo Eventi e seminari (tutte le aree; comprende anche la Formazione Scuola Lavoro, come prima)",
    'modulo_fsl'          => "Modulo Formazione Scuola Lavoro (aree FSL, convenzioni, verifica delle iscrizioni, valutazioni)",
    'modulo_calendari'    => "Modulo Prenotazioni e risorse (tutte le aree)",
    'modulo_didattica'    => "Modulo Didattica",
]);

if (!function_exists('servizio_abilitazioni')) {
    // Il servizio delle abilitazioni della connessione (usato dalle facciate qui sotto)
    function servizio_abilitazioni($conn): \App\Auth\Abilitazioni\ServizioAbilitazioni {
        return \App\Core\App::per($conn)->get(\App\Auth\Abilitazioni\ServizioAbilitazioni::class);
    }
}

if (!function_exists('ha_modulo')) {
    // Abilitato al modulo intero (il modulo FSL vale anche per chi ha Orientamento o il perimetro «fsl»)
    function ha_modulo($conn, int $uid, string $modulo): bool {
        return servizio_abilitazioni($conn)->haModulo($uid, $modulo);
    }
}

if (!function_exists('area_nel_modulo_utente')) {
    // L'area appartiene a un modulo a cui l'utente è abilitato per intero
    function area_nel_modulo_utente($conn, int $uid, ?array $pagina): bool {
        return servizio_abilitazioni($conn)->areaNelModuloUtente($uid, $pagina);
    }
}

if (!function_exists('ha_ambito')) {
    function ha_ambito($conn, int $uid, string $tipo, int $pagina_id = 0): bool {
        return servizio_abilitazioni($conn)->haAmbito($uid, $tipo, $pagina_id);
    }
}

if (!function_exists('sql_attivita_ambiti')) {
    // Condizione SQL (alias e = eventi) con le attività dell'area comprese nei perimetri dell'utente; '' se nessuna
    function sql_attivita_ambiti($conn, int $uid, int $pagina_id): string {
        return servizio_abilitazioni($conn)->sqlAttivitaAmbiti($uid, $pagina_id);
    }
}

if (!function_exists('attivita_da_ambiti')) {
    // ID delle attività dell'area comprese nei perimetri dell'utente
    function attivita_da_ambiti($conn, int $uid, int $pagina_id): array {
        return servizio_abilitazioni($conn)->attivitaDaAmbiti($uid, $pagina_id);
    }
}

if (!function_exists('aree_da_ambiti')) {
    // Aree in cui l'utente lavora grazie ai perimetri (tipo di attività, oppure attività FSL)
    function aree_da_ambiti($conn, int $uid): array {
        return servizio_abilitazioni($conn)->areeDaAmbiti($uid);
    }
}

if (!function_exists('ids_ambito_attivita')) {
    // Utenti che vedono un'attività grazie a un perimetro ('progetti'/'eventi' della sua area; con $con_fsl anche 'fsl')
    function ids_ambito_attivita($conn, int $ev_id, bool $con_fsl = true): array {
        return servizio_abilitazioni($conn)->idsAmbitoAttivita($ev_id, $con_fsl);
    }
}

if (!function_exists('utente_gestisce_attivita')) {
    // L'utente lavora su questa attività: gestore dell'area, dell'attività o con un perimetro che la comprende
    function utente_gestisce_attivita($conn, int $uid, int $ev_id): bool {
        return servizio_abilitazioni($conn)->utenteGestisceAttivita($uid, $ev_id);
    }
}

if (!function_exists('utente_ha_abilitazioni')) {
    // Ha almeno un'abilitazione: su un'area, su un'attività o un perimetro (progetti, eventi, FSL)
    function utente_ha_abilitazioni($conn, int $uid): bool {
        return servizio_abilitazioni($conn)->utenteHaAbilitazioni($uid);
    }
}

if (!function_exists('assegna_ambito')) {
    function assegna_ambito($conn, int $uid, string $tipo, int $pagina_id = 0, int $da = 0): bool {
        return servizio_abilitazioni($conn)->assegnaAmbito($uid, $tipo, $pagina_id, $da, TIPI_AMBITO);
    }
}

if (!function_exists('revoca_ambito')) {
    // $tipo null = tutti i perimetri dell'utente nell'area $pagina_id (con $pagina_id 0: quelli FSL)
    function revoca_ambito($conn, int $uid, ?string $tipo, int $pagina_id = 0): void {
        servizio_abilitazioni($conn)->revocaAmbito($uid, $tipo, $pagina_id);
    }
}

if (!function_exists('normalizza_lista_email')) {
    // Testo libero -> indirizzi validi, minuscoli, senza doppioni ($scartati: quelli non validi): App\Sistema\ListaEmail
    function normalizza_lista_email(?string $testo, int $max = 10, ?array &$scartati = null): array {
        return \App\Sistema\ListaEmail::normalizza($testo, $max, $scartati);
    }
}

if (!function_exists('get_destinatari_notifiche_prenotazione')) {
    // Chi riceve il riepilogo di prenotazioni e disdette: gestori con notifiche attive + indirizzi aggiuntivi dell'evento
    // + referenti del progetto con "Riceve le iscrizioni" attivo.
    function get_destinatari_notifiche_prenotazione($conn, int $evento_id): array {
        return \App\Core\App::per($conn)->get(\App\Iscrizioni\NotifichePrenotazione::class)->destinatari($evento_id);
    }
}

if (!function_exists('corpo_notifica_per')) {
    // Il pulsante "Apri gli iscritti del turno" solo per i gestori (hanno accesso all'amministrazione);
    // referenti dei progetti e indirizzi in copia ricevono il riepilogo senza link all'admin.
    function corpo_notifica_per(string $email, string $intro, array $riepilogo, array $email_gestori): string {
        return \App\Iscrizioni\NotifichePrenotazione::corpoPer($email, $intro, $riepilogo, $email_gestori);
    }
}

if (!function_exists('html_riepilogo_prenotazione')) {
    // Riepilogo completo di una prenotazione per le email a gestori e indirizzi aggiuntivi:
    // dati anagrafici, evento e turno, stato, e TUTTI i campi aggiuntivi del form con la loro etichetta
    // (gli allegati diventano link). Ritorna ['oggetto_evento' => titolo, 'html' => tabella] oppure null.
    function html_riepilogo_prenotazione($conn, int $pr_id): ?array {
        return \App\Core\App::per($conn)->get(\App\Iscrizioni\NotifichePrenotazione::class)->riepilogo($pr_id);
    }
}

// ── Attestato: invio immediato se evento concluso ─────────────────────────────
if (!function_exists('url_base_sito')) {
    // URL della radice del portale (es. https://dibest2.unical.it/didattica), senza slash finale.
    // Calcolato dalla posizione di functions.php: corretto anche se chiamato da /admin o da un cron.
    // Indirizzo del portale senza "/" finale: facciata di App\Core\Sito::urlBase()
    function url_base_sito(): string {
        return (new \App\Core\Sito(RADICE_SITO, function_exists('env_valore') ? env_valore('URL_SITO') : null))->urlBase();
    }
}
