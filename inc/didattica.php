<?php
// inc/didattica.php - Modulo Didattica: Ufficio didattico, modulistica (documenti da scaricare e moduli online),
// pratiche degli studenti, sedute del Consiglio con verbale in Word ed esportazione in Excel.
// Un modulo online, compilato da chi ha fatto l'accesso, apre una pratica con codice, stato, storico e messaggi con
// l'ufficio; tutto si segue dall'Area personale (pratiche.php) e dal pannello (admin/didattica.php).
// I campi possono essere guidati dalle anagrafi (corsi di studio, insegnamenti, docenti), essere tabelle a righe
// (es. esami sostenuti) e alcuni li compila solo l'ufficio durante l'istruttoria (es. convalide).
// Gli allegati delle pratiche stanno in uploads/pratiche/ (bloccata al web) e si scaricano solo da allegato_pratica.php.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

if (!defined('STATI_PRATICA')) define('STATI_PRATICA', \App\Didattica\Costanti::STATI_PRATICA);
if (!defined('TIPI_CAMPO_PRATICA')) define('TIPI_CAMPO_PRATICA', \App\Didattica\Costanti::TIPI_CAMPO_PRATICA);
if (!defined('GRUPPI_TIPI_CAMPO')) define('GRUPPI_TIPI_CAMPO', \App\Didattica\Costanti::GRUPPI_TIPI_CAMPO);
if (!defined('TIPI_COLONNA_TABELLA')) define('TIPI_COLONNA_TABELLA', \App\Didattica\Costanti::TIPI_COLONNA_TABELLA);
if (!defined('OPERATORI_CONDIZIONE')) define('OPERATORI_CONDIZIONE', \App\Didattica\Costanti::OPERATORI_CONDIZIONE);
if (!defined('TIPI_SOLO_TESTO')) define('TIPI_SOLO_TESTO', \App\Didattica\Costanti::TIPI_SOLO_TESTO);
if (!defined('DESTINATARI_MODULO')) define('DESTINATARI_MODULO', \App\Didattica\Costanti::DESTINATARI_MODULO);
if (!defined('COMPITI_UFFICIO')) define('COMPITI_UFFICIO', \App\Didattica\Costanti::COMPITI_UFFICIO);
if (!defined('DIR_PRATICHE')) define('DIR_PRATICHE', \App\Didattica\Costanti::DIR_PRATICHE);
// Passi dell'iter risolti con la pratica: ufficio del corso di studio e segreteria studenti (vedi iter_modulo)
if (!defined('ITER_CDL')) define('ITER_CDL', \App\Didattica\Costanti::ITER_CDL);
if (!defined('ITER_SEGRETERIA')) define('ITER_SEGRETERIA', \App\Didattica\Costanti::ITER_SEGRETERIA);
if (!defined('DIR_MODULISTICA')) define('DIR_MODULISTICA', \App\Didattica\Costanti::DIR_MODULISTICA);
if (!defined('LOGO_VERBALE')) define('LOGO_VERBALE', \App\Didattica\Costanti::LOGO_VERBALE);

// ==============================================================================
// UFFICIO DIDATTICO
// Facciate di App\Didattica\ServizioUffici (operatori, avvisi, sportelli) e UfficioRepository (uffici)
// ==============================================================================

if (!function_exists('uffici_didattica')) {
    // Uffici dell'Ufficio didattico (didattica_uffici), definiti dal pannello: [id => riga]. 'smista' = riceve le pratiche
    // nuove e le smista (manager); 'segue_corsi' = il personale indica i corsi di studio seguiti (referenti dei corsi).
    function uffici_didattica($conn, bool $rileggi = false): array {
        return \App\Core\App::per($conn)->get(\App\Didattica\UfficioRepository::class)->tutti($rileggi);
    }
    // Id dell'ufficio da un id o da una chiave dei modelli pronti ('referente_cdl', 'carriere'…); null se non esiste
    function ufficio_didattica_id($conn, $x): ?int {
        return \App\Core\App::per($conn)->get(\App\Didattica\UfficioRepository::class)->idDa($x);
    }
}

if (!function_exists('operatori_ufficio')) {
    // Operatori dell'Ufficio didattico (facoltativo: solo chi ha quel compito)
    function operatori_ufficio($conn, ?string $compito = null): array {
        return \App\Core\App::per($conn)->get(\App\Didattica\ServizioUffici::class)->operatori($compito);
    }
}

if (!function_exists('utente_operatore_ufficio')) {
    // L'utente è un operatore dell'Ufficio didattico (riconosciuto dall'email o dalla scheda dell'anagrafe)
    function utente_operatore_ufficio($conn, ?array $u, ?string $compito = null): bool {
        return \App\Core\App::per($conn)->get(\App\Didattica\ServizioUffici::class)->utenteOperatore($u, $compito);
    }
}

if (!function_exists('utente_gestisce_didattica')) {
    // Amministratori, abilitati al modulo Didattica e operatori dell'Ufficio didattico
    function utente_gestisce_didattica($conn, ?array $u): bool {
        return \App\Core\App::per($conn)->get(\App\Didattica\ServizioUffici::class)->gestisce($u);
    }
}

if (!function_exists('aggiungi_operatore_ufficio')) {
    // Aggiunge (o aggiorna) un operatore scelto dall'anagrafe di Ateneo. Ritorna un messaggio di errore o null.
    // $ufficio_id: ufficio dell'Ufficio didattico (didattica_uffici); $corsi: corsi di studio seguiti (uffici che seguono i corsi)
    function aggiungi_operatore_ufficio($conn, string $persona_id, string $ruolo, array $compiti, int $ufficio_id = 0, array $corsi = []): ?string {
        return \App\Core\App::per($conn)->get(\App\Didattica\ServizioUffici::class)->aggiungi($persona_id, $ruolo, $compiti, $ufficio_id, $corsi);
    }
}

if (!function_exists('operatore_ufficio')) {
    // Riga dell'operatore per id, oppure (con $u) l'operatore corrispondente all'utente
    function operatore_ufficio($conn, int $id = 0, ?array $u = null): ?array {
        return \App\Core\App::per($conn)->get(\App\Didattica\ServizioUffici::class)->operatore($id, $u);
    }
    function etichetta_operatore(?array $o): string {
        return ($conn = $GLOBALS['conn'] ?? null) ? \App\Core\App::per($conn)->get(\App\Didattica\ServizioUffici::class)->etichetta($o) : ($o ? $o['nominativo'] : '');
    }
    function nome_ufficio_operatore($conn, ?array $o): string {
        return $conn ? \App\Core\App::per($conn)->get(\App\Didattica\ServizioUffici::class)->nomeUfficio($o) : '';
    }
    function nome_autore_ufficio($conn, ?array $u): string {
        return \App\Core\App::per($conn)->get(\App\Didattica\ServizioUffici::class)->nomeAutore($u);
    }
}

if (!function_exists('email_ufficio_didattica')) {
    // Email di chi riceve gli avvisi: quelle del modulo, altrimenti gli operatori con quel compito, altrimenti gli amministratori
    function email_ufficio_didattica($conn, string $email_modulo = '', string $compito = 'pratiche'): array {
        return \App\Core\App::per($conn)->get(\App\Didattica\ServizioUffici::class)->emailUfficio($email_modulo, $compito);
    }
}

if (!function_exists('sportelli_utente')) {
    // Sportelli di ricevimento che l'utente gestisce: il proprio (docente, dall'anagrafe) e quelli dell'Ufficio didattico se ne è operatore
    function sportelli_utente($conn, ?array $u): array {
        return \App\Core\App::per($conn)->get(\App\Didattica\ServizioUffici::class)->sportelliUtente($u);
    }
}

if (!function_exists('sportelli_ufficio_didattica')) {
    function sportelli_ufficio_didattica($conn, bool $solo_attivi = false): array {
        return \App\Core\App::per($conn)->get(\App\Didattica\ServizioUffici::class)->sportelli($solo_attivi);
    }
}

if (!function_exists('crea_sportello_ufficio')) {
    // Sportello di ricevimento dell'Ufficio didattico in un'area di Prenotazioni e risorse. Ritorna l'id.
    function crea_sportello_ufficio($conn, int $pagina_id, string $nome, string $luogo): int {
        return \App\Core\App::per($conn)->get(\App\Didattica\ServizioUffici::class)->creaSportello($pagina_id, $nome, $luogo);
    }
}

// ==============================================================================
// MODULI E CAMPI
// Facciate di App\Didattica\CampiModulo (campi e colonne), ServizioModuli, ScelteAnagrafe, LetturaRisposte, AllegatiPratiche e HtmlCampi
// ==============================================================================

if (!function_exists('campi_modulo')) {
    // Campi del modulo online, normalizzati (vedi CampiModulo::da)
    function campi_modulo(?string $json): array {
        return \App\Didattica\CampiModulo::da($json);
    }
}

if (!function_exists('colonne_tabella')) {
    // Colonne di una tabella a righe da "Nome:tipo" (tipo facoltativo: si riconosce dal nome). "Esito:scelta(Sì|No)" = tendina.
    function colonne_tabella(array $spec): array {
        return \App\Didattica\CampiModulo::colonne($spec);
    }
    // Colonne scritte prima dei tipi: "Insegnamento", "CFU", "Voto", "Data", "S.S.D.", "Relatore"…
    function tipo_colonna_da_nome(string $n): string {
        return \App\Didattica\CampiModulo::tipoColonnaDaNome($n);
    }
    // Colonne di nuovo in testo per il costruttore: "Nome:tipo"
    function testo_colonne_tabella(array $colonne): string {
        return \App\Didattica\CampiModulo::testoColonne($colonne);
    }
}

if (!function_exists('condizione_vera')) {
    // Valuta una condizione ('op', 'valore') sul valore di un altro campo (testo; le scelte multiple sono separate da virgola)
    function condizione_vera(array $cond, string $valore): bool {
        return \App\Didattica\CampiModulo::condizioneVera($cond, $valore);
    }
}

if (!function_exists('campi_studente')) {
    function campi_studente(array $campi): array { return \App\Didattica\CampiModulo::studente($campi); }
    function campi_ufficio(array $campi): array { return \App\Didattica\CampiModulo::ufficio($campi); }
}

if (!function_exists('modulo_didattica')) {
    function modulo_didattica($conn, int $id): ?array {
        return \App\Core\App::per($conn)->get(\App\Didattica\ServizioModuli::class)->modulo($id);
    }
}

if (!function_exists('utente_destinatario_modulo')) {
    // Chi può compilare il modulo online (stesse regole delle risorse: gruppi dell'anagrafe e matricola)
    function utente_destinatario_modulo($conn, array $m, ?array $u): bool {
        return \App\Core\App::per($conn)->get(\App\Didattica\ServizioModuli::class)->destinatario($m, $u);
    }
}

if (!function_exists('anni_accademici_scelta')) {
    // "2025/2026" ecc.: dall'anno precedente a quello successivo all'anno in corso
    function anni_accademici_scelta(): array {
        return \App\Core\App::get(\App\Didattica\HtmlCampi::class)->anniAccademici();
    }
}

if (!function_exists('scelte_anagrafe_didattica')) {
    // Valori proposti dai campi guidati: corsi di studio (per tipo), insegnamenti e docenti
    function scelte_anagrafe_didattica($conn, string $tipo): array {
        return \App\Core\App::per($conn)->get(\App\Didattica\ScelteAnagrafe::class)->per($tipo);
    }
}

if (!function_exists('leggi_risposte_modulo')) {
    // Risposte dal POST (campo_<nome>) e allegati ($_FILES campo_<nome>). Ritorna [risposte, errori] (vedi LetturaRisposte::leggi).
    // $conn serve per controllare i corsi di studio proposti dall'anagrafe.
    function leggi_risposte_modulo(array $campi, $conn = null): array {
        return \App\Core\App::per($conn ?? $GLOBALS['conn'])->get(\App\Didattica\LetturaRisposte::class)->leggi($campi, $_POST, $_FILES, (bool)$conn);
    }
}

if (!function_exists('html_datalist_didattica')) {
    // Elenchi proposti (datalist) per i campi guidati: da stampare una volta sola nella pagina del modulo
    function html_datalist_didattica($conn, array $campi): string {
        return \App\Core\App::per($conn)->get(\App\Didattica\HtmlCampi::class)->datalist($campi);
    }
}

if (!function_exists('html_campo_pratica')) {
    // Campo del modulo online (pagina pubblica modulo.php e istruttoria dell'ufficio). $valore: testo o righe (tabella).
    // Ogni campo sta in un contenitore .campo-pratica con la logica (data-cond, data-auto) letta da assets/js/campi-pratica.js.
    // $griglia: il campo è una colonna di una riga Bootstrap (modulo.php): i campi brevi vanno affiancati a due a due
    function html_campo_pratica(array $c, $valore = '', $conn = null, bool $griglia = false): string {
        return \App\Core\App::get(\App\Didattica\HtmlCampi::class)->campo($c, $valore, (bool)$conn, $griglia);
    }
}

if (!function_exists('valore_piano')) {
    // Colonna «piano di studi»: [da inserire come a scelta?, insegnamento del piano da eliminare]
    function valore_piano(string $v): array {
        return \App\Didattica\CampiModulo::valorePiano($v);
    }
}

if (!function_exists('js_tabelle_pratica')) {
    // Righe delle tabelle, logica dei campi e scelta degli insegnamenti dal catalogo (una volta per pagina)
    function js_tabelle_pratica(): string {
        return \App\Core\App::get(\App\Didattica\HtmlCampi::class)->scriptTabelle();
    }
}

if (!function_exists('valori_post_campo')) {
    // Valore da rimettere nel campo dopo un errore (le tabelle tornano come righe, le scelte multiple come testo)
    function valori_post_campo(array $c) {
        return \App\Core\App::get(\App\Didattica\HtmlCampi::class)->valoriPostCampo($c, $_POST);
    }
}

if (!function_exists('html_risposta_pratica')) {
    // Valore di una risposta per le pagine (le tabelle diventano una tabella; il link all'allegato lo aggiunge chi chiama)
    function html_risposta_pratica(array $r): string {
        return \App\Core\App::get(\App\Didattica\HtmlCampi::class)->risposta($r);
    }
}

// ==============================================================================
// PRATICHE
// Facciate di App\Didattica\ServizioPratiche (invio, storico, stati, messaggi), ServizioIter (iter e passaggi) e NotifichePratiche (email)
// ==============================================================================

if (!function_exists('pratica')) {
    // Pratica con il titolo del modulo (per id)
    function pratica($conn, int $id): ?array {
        return \App\Core\App::per($conn)->get(\App\Didattica\ServizioPratiche::class)->pratica($id);
    }
}

if (!function_exists('crea_pratica')) {
    // Nuova pratica dal modulo compilato. Ritorna l'id (0 se non riesce).
    function crea_pratica($conn, array $m, array $u, array $risposte): int {
        return \App\Core\App::per($conn)->get(\App\Didattica\ServizioPratiche::class)->crea($m, $u, $risposte);
    }
}

if (!function_exists('cambia_stato_pratica')) {
    // $richiesta (solo per 'integrazione'): ['tipo' => 'documenti'|'autodichiarazione', 'testo' => …] da mostrare allo studente
    function cambia_stato_pratica($conn, int $id, string $stato, string $nota, int $uid, string $autore_nome = '', ?array $richiesta = null): bool {
        return \App\Core\App::per($conn)->get(\App\Didattica\ServizioPratiche::class)->cambiaStato($id, $stato, $nota, $uid, $autore_nome, $richiesta);
    }
}

if (!function_exists('messaggio_pratica')) {
    // Messaggio o attività (es. verbale caricato) dello studente o dell'ufficio, con allegato facoltativo ($file = elemento di $_FILES).
    // $interno: nota tra i referenti (lo studente non la vede e non riceve email). Lo studente che risponde a un'integrazione
    // di documenti la chiude e la pratica torna in lavorazione.
    function messaggio_pratica($conn, int $id, string $autore, int $uid, string $testo, ?array $file = null, bool $interno = false, string $tipo = 'messaggio', string $autore_nome = ''): ?string {
        return \App\Core\App::per($conn)->get(\App\Didattica\ServizioPratiche::class)->messaggio($id, $autore, $uid, $testo, $file, $interno, $tipo, $autore_nome);
    }
}

if (!function_exists('iter_modulo')) {
    // Uffici che ricevono la pratica, in ordine (dal modulo: id o chiavi dei modelli pronti); vuoto = un solo passo generico (0).
    // "@cdl" = l'ufficio del corso di studio della pratica (ITER_CDL), "@segreteria" = la segreteria studenti scelta (ITER_SEGRETERIA):
    // con la pratica si risolvono nell'ufficio vero (cdl_id, segreteria_id della pratica).
    function iter_modulo(?array $m, ?array $p = null): array {
        return \App\Core\App::get(\App\Didattica\ServizioIter::class)->iter($m, $p);
    }
    // Passi mostrati: 0 = ricevuta (da smistare), 1..n = uffici dell'iter, n+1 = conclusa
    function passi_pratica(?array $m, ?array $p = null): array {
        return \App\Core\App::get(\App\Didattica\ServizioIter::class)->passi($m, $p);
    }
}

if (!function_exists('operatori_suggeriti')) {
    // Operatori per il passo: prima chi è nell'ufficio del passo (e, negli uffici che seguono i corsi, chi segue il corso della pratica)
    function operatori_suggeriti($conn, array $p, ?array $m, int $passo): array {
        return \App\Core\App::per($conn)->get(\App\Didattica\ServizioIter::class)->operatoriSuggeriti($p, $m, $passo);
    }
}

if (!function_exists('assegna_pratica')) {
    // Smista o passa la pratica all'operatore $op_id al passo $passo dell'iter; lo studente vede il passaggio, la nota resta interna.
    function assegna_pratica($conn, int $id, int $op_id, int $passo, string $nota, int $uid, string $autore_nome = ''): ?string {
        return \App\Core\App::per($conn)->get(\App\Didattica\ServizioIter::class)->assegna($id, $op_id, $passo, $nota, $uid, $autore_nome);
    }
}

if (!function_exists('operatori_pratica')) {
    // Operatori che hanno avuto (o hanno) in carico la pratica: id
    function operatori_pratica($conn, int $id): array {
        return \App\Core\App::per($conn)->get(\App\Didattica\ServizioIter::class)->operatoriPratica($id);
    }
}

if (!function_exists('richiedi_a_operatore')) {
    // Chi ha in carico la pratica chiede un'integrazione a un operatore che l'ha avuta prima (nota interna + email a quell'operatore)
    function richiedi_a_operatore($conn, int $id, int $op_id, string $testo, int $uid, string $autore_nome = ''): ?string {
        return \App\Core\App::per($conn)->get(\App\Didattica\ServizioIter::class)->richiediAOperatore($id, $op_id, $testo, $uid, $autore_nome);
    }
}

if (!function_exists('autodichiarazione_pratica')) {
    // Lo studente rende l'autodichiarazione richiesta (DPR 445/2000): resta nello storico con data e ora, la pratica torna in lavorazione
    function autodichiarazione_pratica($conn, int $id, int $uid, string $aggiunta, bool $conferma): ?string {
        return \App\Core\App::per($conn)->get(\App\Didattica\ServizioPratiche::class)->autodichiarazione($id, $uid, $aggiunta, $conferma);
    }
}

if (!function_exists('html_iter_pratica')) {
    // Iter della pratica a passi (pannello e Area personale): fatti, attuale con chi l'ha in carico, da fare, conclusione
    function html_iter_pratica($conn, array $p, ?array $m): string {
        return \App\Core\App::per($conn)->get(\App\Didattica\ServizioIter::class)->html($p, $m);
    }
}

if (!function_exists('badge_stato_pratica')) {
    function badge_stato_pratica(string $stato): string {
        return \App\Didattica\ServizioIter::badge($stato);
    }
}

// ==============================================================================
// SEDUTE, VERBALE (WORD) ED ESPORTAZIONE (EXCEL)
// ==============================================================================

if (!function_exists('seduta_didattica')) {
    function seduta_didattica($conn, int $id): ?array {
        return \App\Core\App::per($conn)->get(\App\Didattica\ServizioSedute::class)->seduta($id);
    }
    function etichetta_seduta(array $s): string {
        return \App\Didattica\ServizioSedute::etichetta($s);
    }
}

// ==============================================================================
// CONSIGLI DEI CORSI DI STUDIO: REFERENTI, COMPONENTI, PRESENZE E DECISIONI IN SEDUTA
// L'Ufficio didattico sceglie i referenti di ogni consiglio; i referenti inseriscono una volta sola i componenti (docenti
// dall'anagrafe, rappresentanti scritti a mano) e in ogni seduta segnano presente / assente giustificato / ingiustificato.
// Per ogni pratica portata in seduta si registrano l'esito e le decisioni: convalide degli esami (insegnamento del
// Dipartimento dall'anagrafe, totale o parziale, CFU riconosciuti e da integrare) o insegnamenti in piano / fuori piano.
// Facciate di App\Didattica\ServizioConsigli, ServizioSedute, DecisioniSeduta e TestiPratica.
// ==============================================================================

if (!defined('STATI_PRESENZA')) define('STATI_PRESENZA', \App\Didattica\Costanti::STATI_PRESENZA);
if (!defined('QUALIFICHE_CONSIGLIO')) define('QUALIFICHE_CONSIGLIO', \App\Didattica\Costanti::QUALIFICHE_CONSIGLIO);
if (!defined('ESITI_SEDUTA')) define('ESITI_SEDUTA', \App\Didattica\Costanti::ESITI_SEDUTA);
if (!defined('DECISIONI_SEDUTA')) define('DECISIONI_SEDUTA', \App\Didattica\Costanti::DECISIONI_SEDUTA);
if (!defined('ESITI_CONVALIDA')) define('ESITI_CONVALIDA', \App\Didattica\Costanti::ESITI_CONVALIDA);
if (!defined('ESITI_PIANO')) define('ESITI_PIANO', \App\Didattica\Costanti::ESITI_PIANO);

if (!function_exists('consigli_didattica')) {
    function consigli_didattica($conn, bool $solo_attivi = false): array {
        return \App\Core\App::per($conn)->get(\App\Didattica\ServizioConsigli::class)->tutti($solo_attivi);
    }
    function consiglio_didattica($conn, int $id): ?array {
        return \App\Core\App::per($conn)->get(\App\Didattica\ServizioConsigli::class)->consiglio($id);
    }
    // Componenti (ordinati per qualifica come nel verbale) o referenti del consiglio
    function persone_consiglio($conn, int $cid, string $ruolo = 'componente'): array {
        return \App\Core\App::per($conn)->get(\App\Didattica\ServizioConsigli::class)->persone($cid, $ruolo);
    }
}

if (!function_exists('consigli_referente')) {
    // Consigli di cui l'utente è referente (riconosciuto dall'email o dalla scheda dell'anagrafe): id
    function consigli_referente($conn, ?array $u): array {
        return \App\Core\App::per($conn)->get(\App\Didattica\ServizioConsigli::class)->referenteDi($u);
    }
}

if (!function_exists('aggiungi_persona_consiglio')) {
    // Referente o componente dall'anagrafe ($persona_id) o scritto a mano ($nominativo, es. rappresentanti degli studenti).
    // Ritorna un messaggio di errore o null. Le persone già presenti non si duplicano.
    function aggiungi_persona_consiglio($conn, int $cid, string $ruolo, string $persona_id, string $qualifica = '', string $nominativo = '', string $email = ''): ?string {
        return \App\Core\App::per($conn)->get(\App\Didattica\ServizioConsigli::class)->aggiungiPersona($cid, $ruolo, $persona_id, $qualifica, $nominativo, $email);
    }
}

if (!function_exists('presenze_seduta')) {
    // Presenze della seduta: quelle salvate più i componenti del consiglio non ancora segnati (presenti). [componente_id => riga]
    function presenze_seduta($conn, array $s): array {
        return \App\Core\App::per($conn)->get(\App\Didattica\ServizioSedute::class)->presenze($s);
    }
    // Salva gli stati (P / AG / AI) per componente: nome e qualifica restano nella seduta anche se il componente cambia dopo
    function salva_presenze_seduta($conn, array $s, array $stati): int {
        return \App\Core\App::per($conn)->get(\App\Didattica\ServizioSedute::class)->salvaPresenze($s, $stati);
    }
    function riepilogo_presenze(array $presenze): array {
        return \App\Didattica\ServizioSedute::riepilogo($presenze);
    }
}

if (!function_exists('utente_vede_pratica')) {
    // Chi gestisce la Didattica vede tutte le pratiche; il referente di un consiglio quelle portate nelle sedute del suo consiglio
    function utente_vede_pratica($conn, ?array $u, array $p): bool {
        return \App\Core\App::per($conn)->get(\App\Didattica\ServizioSedute::class)->utenteVedePratica($u, $p);
    }
}

if (!function_exists('decisione_modulo')) {
    function decisione_modulo(?array $m): string {
        return \App\Didattica\TestiPratica::decisioneModulo($m);
    }
}

if (!function_exists('righe_richieste_pratica')) {
    // Insegnamenti indicati dallo studente (righe delle tabelle con una colonna "insegnamento", campi Insegnamento):
    // [['richiesto', 'cfu', 'voto', 'ssd', 'data']]
    function righe_richieste_pratica(array $p, array $campi = []): array {
        return \App\Didattica\TestiPratica::righeRichieste($p, $campi);
    }
}

if (!function_exists('decisioni_pratica')) {
    // Decisioni salvate in seduta, altrimenti le righe proposte dalle richieste dello studente: ['tipo', 'righe' => [...]]
    function decisioni_pratica(array $p, string $tipo, array $campi = []): array {
        return \App\Didattica\TestiPratica::decisioniPratica($p, $tipo, $campi);
    }
}

if (!function_exists('leggi_decisioni_post')) {
    // Righe delle decisioni dal POST (array paralleli d_<campo>[]); l'insegnamento convalidato si riconosce nell'anagrafe
    // del Dipartimento (id e CFU); CFU riconosciuti e da integrare si completano se mancano
    function leggi_decisioni_post($conn, string $tipo, array $post): array {
        return \App\Core\App::per($conn)->get(\App\Didattica\DecisioniSeduta::class)->leggiDalPost($tipo, $post);
    }
}

if (!function_exists('tabella_decisioni')) {
    // Intestazioni e righe delle decisioni per il verbale, la pagina e l'Excel
    function tabella_decisioni(array $d): array {
        return \App\Didattica\TestiPratica::tabellaDecisioni($d);
    }
    function testo_decisioni(?string $json): string {
        return \App\Didattica\TestiPratica::testoDecisioni($json);
    }
}

if (!function_exists('salva_decisioni_seduta')) {
    // Esito, decisioni e delibera di una pratica in seduta
    function salva_decisioni_seduta($conn, int $id, string $esito, ?array $decisioni, ?string $delibera): bool {
        return \App\Core\App::per($conn)->get(\App\Didattica\DecisioniSeduta::class)->salva($id, $esito, $decisioni, $delibera);
    }
}

if (!function_exists('applica_esiti_seduta')) {
    // A seduta conclusa: approvate → accolte, respinte → respinte (lo studente riceve l'email), rinviate → tolte dalla seduta
    // (restano aperte per la prossima). Ritorna [accolte, respinte, rinviate].
    function applica_esiti_seduta($conn, array $s, int $uid, string $autore_nome = ''): array {
        return \App\Core\App::per($conn)->get(\App\Didattica\DecisioniSeduta::class)->applicaEsiti($s, $uid, $autore_nome);
    }
}

if (!function_exists('verbale_modulo')) {
    // Come compare il modulo nel verbale: titolo della sezione, stile (scheda = un paragrafo per pratica con le sue tabelle,
    // elenco = una tabella con una riga per pratica), testo per ogni pratica con i segnaposto, delibera, colonne, raggruppamento
    function verbale_modulo(array $m): array {
        return \App\Didattica\TestiPratica::verbaleModulo($m);
    }
}

if (!function_exists('testo_segnaposti_pratica')) {
    // {STUDENTE} (COGNOME NOME), {NOME}, {COGNOME}, {MATRICOLA}, {EMAIL}, {CODICE}, {MODULO}, {DATA}, {PROTOCOLLO},
    // {ATENEO_PRECEDENTE} (Ateneo scritto o scelto della carriera precedente), {Etichetta di un campo}
    function testo_segnaposti_pratica(string $tpl, array $p): string {
        return \App\Didattica\TestiPratica::segnaposti($tpl, $p);
    }
}

if (!function_exists('pratiche_per_esportazione')) {
    // Pratiche complete (con modulo) per id, nell'ordine del verbale: categoria e ordine del modulo, poi cognome e nome
    function pratiche_per_esportazione($conn, array $ids): array {
        return \App\Core\App::per($conn)->get(\App\Didattica\EsportazioneVerbale::class)->pratichePerEsportazione($ids);
    }
}

if (!function_exists('corpo_pratiche_verbale')) {
    // Parte "Pratiche studenti" del verbale (XML di Word): una sezione per modulo
    function corpo_pratiche_verbale(array $pratiche): string {
        return \App\Core\App::get(\App\Didattica\EsportazioneVerbale::class)->corpoPratiche($pratiche);
    }
}

if (!function_exists('genera_verbale_pratiche')) {
    // Verbale in Word: con la seduta è il verbale completo (intestazione, o.d.g., presenze, punti, firme) con le pratiche
    // al punto "Pratiche studenti"; senza seduta è solo la parte delle pratiche. Ritorna il percorso di un file temporaneo.
    function genera_verbale_pratiche($conn, ?array $s, array $pratiche): ?string {
        return \App\Core\App::per($conn)->get(\App\Didattica\EsportazioneVerbale::class)->generaVerbale($s, $pratiche);
    }
}

if (!function_exists('genera_excel_pratiche')) {
    // Excel delle pratiche: un foglio con tutte (colonne di tutti i moduli) e un foglio per ogni modulo
    function genera_excel_pratiche($conn, array $pratiche): ?string {
        return \App\Core\App::per($conn)->get(\App\Didattica\EsportazioneVerbale::class)->generaExcel($pratiche);
    }
}

if (!function_exists('periodo_modulo')) {
    // Il modulo online si compila solo nel periodo aperto_dal–aperto_al (vuoti = sempre). Ritorna [aperto, testo da mostrare].
    function periodo_modulo(array $m): array {
        return \App\Core\App::get(\App\Didattica\ServizioModuli::class)->periodo($m);
    }
}

if (!function_exists('promemoria_pratiche_ferme')) {
    // Pratiche aperte senza movimenti da più dei giorni indicati nel modulo: email a chi le ha in carico (o a chi smista,
    // se non sono ancora assegnate). Un promemoria per ogni periodo di attesa. Ritorna il numero di email inviate.
    function promemoria_pratiche_ferme($conn): int {
        return \App\Core\App::per($conn)->get(\App\Didattica\PromemoriaPratiche::class)->invia();
    }
}

if (!function_exists('statistiche_pratiche')) {
    // Statistiche delle pratiche inviate tra $dal e $al: per modulo e per corso di studio (stati ed esiti),
    // tempi medi per passo dell'iter (dal passaggio al passaggio successivo o alla conclusione) e tempo medio di chiusura.
    function statistiche_pratiche($conn, string $dal, string $al): array {
        return \App\Core\App::per($conn)->get(\App\Didattica\StatisticheDidattica::class)->calcola($dal, $al);
    }
}
