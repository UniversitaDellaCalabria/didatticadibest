<?php
// inc/anagrafi.php - Anagrafe delle scuole (Ministero) e del personale di Ateneo, corsi di studio.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

// =======================================================================
// ANAGRAFE DELLE SCUOLE (open data del Ministero, tabella scuole): ricerca,
// nome ufficiale e campo "Scuola" dei moduli con ricerca guidata
// =======================================================================
// Costanti globali di prima, con i valori di App\Anagrafi\Anagrafe
if (!defined('API_UNICAL')) define('API_UNICAL', \App\Anagrafi\Anagrafe::API_UNICAL);
if (!defined('GRUPPI_PERSONALE')) define('GRUPPI_PERSONALE', \App\Anagrafi\Anagrafe::GRUPPI_PERSONALE);
// Codici ruolo dell'Ateneo (API roles): docenti e ricercatori, personale tecnico amministrativo e dirigenti
if (!defined('RUOLI_DOCENTI')) define('RUOLI_DOCENTI', \App\Anagrafi\Anagrafe::RUOLI_DOCENTI);
if (!defined('RUOLI_PTA')) define('RUOLI_PTA', \App\Anagrafi\Anagrafe::RUOLI_PTA);
if (!defined('CAMPI_SCHEDA_PERSONA')) define('CAMPI_SCHEDA_PERSONA', \App\Anagrafi\Anagrafe::CAMPI_SCHEDA_PERSONA);

// Funzioni di prima come facciate: la logica sta in src/Anagrafi/ (Testi, ServizioScuole, ServizioPersone, ServizioCorsi,
// SincronizzazioneAnagrafe, CollegamentoUtente, PermessiGestori e le Viste in src/Anagrafi/Vista/).

// ── Anagrafe delle scuole (open data del Ministero, tabella scuole) ──────────
if (!function_exists('maiuscole_scuola')) {
    // "LICEO SCIENTIFICO E. FERMI" -> "Liceo Scientifico E. Fermi" (l'anagrafe usa il maiuscolo)
    function maiuscole_scuola(string $s): string { return \App\Anagrafi\Testi::maiuscoleScuola($s); }
}
if (!function_exists('etichetta_scuola')) {
    // Nome da mostrare: denominazione e comune, es. "Liceo Scientifico E. Fermi – Cosenza"
    function etichetta_scuola(array $s): string { return \App\Anagrafi\Testi::etichettaScuola($s); }
}
if (!function_exists('scuola_per_codice')) {
    function scuola_per_codice($conn, ?string $codice): ?array {
        return \App\Core\App::per($conn)->get(\App\Anagrafi\ServizioScuole::class)->perCodice($codice);
    }
}
if (!function_exists('cerca_scuole')) {
    // Ricerca per parole (nome, comune, istituto o codice meccanografico) con filtri di regione, provincia e comune
    function cerca_scuole($conn, string $q, int $limite = 15, array $filtri = []): array {
        return \App\Core\App::per($conn)->get(\App\Anagrafi\ServizioScuole::class)->cerca($q, $limite, $filtri);
    }
}
if (!function_exists('luoghi_scuole')) {
    // Regioni, province di una regione o comuni di una provincia presenti nell'anagrafe delle scuole, con il numero di scuole
    function luoghi_scuole($conn, string $livello, string $regione = '', string $provincia = ''): array {
        return \App\Core\App::per($conn)->get(\App\Anagrafi\ServizioScuole::class)->luoghi($livello, $regione, $provincia);
    }
}
if (!function_exists('applica_scuola_scelta')) {
    // Se nel modulo è stata scelta una scuola dell'anagrafe, nel campo si salva il nome ufficiale; ritorna il codice meccanografico o null
    function applica_scuola_scelta($conn, array &$custom_data, $codici_post): ?string {
        return \App\Core\App::per($conn)->get(\App\Anagrafi\ServizioScuole::class)->applicaScelta($custom_data, $codici_post);
    }
}
if (!function_exists('html_campo_scuola')) {
    // Campo "Scuola" con ricerca nell'anagrafe (App\Anagrafi\Vista\CampoScuola); lo script è in assets/js/campo-scuola.js
    function html_campo_scuola(string $campo, string $valore = '', string $codice = '', string $attr = '', string $classi = 'form-control form-control-sm'): string {
        $GLOBALS['usa_campo_scuola'] = true;
        return \App\Anagrafi\Vista\CampoScuola::html(rtrim((string)parse_url(url_base_sito(), PHP_URL_PATH), '/') . '/cerca_scuole.php', $campo, $valore, $codice, $attr, $classi, 'scu_' . bin2hex(random_bytes(4)));
    }
}
if (!function_exists('nome_scuola_prenotazione')) {
    // Nome della scuola da una prenotazione: quello ufficiale se scelta dall'anagrafe, altrimenti il primo campo del form che parla di scuola/istituto
    function nome_scuola_prenotazione(?array $pr): string {
        global $conn;
        if (!empty($pr['scuola_codice']) && $conn instanceof mysqli) return \App\Core\App::per($conn)->get(\App\Anagrafi\ServizioScuole::class)->nomeDaPrenotazione($pr);
        return \App\Anagrafi\ServizioScuole::nomeScrittoNelModulo($pr);
    }
}

// ── Anagrafe del personale di Ateneo e corsi di studio (API pubbliche del portale Unical) ──────────
if (!function_exists('api_unical_tutte')) {
    // Tutte le pagine di un elenco (page_size 500): null se una pagina non arriva
    function api_unical_tutte(string $percorso, array $query = []): ?array {
        return \App\Core\App::get(\App\Anagrafi\ClientApiAteneo::class)->tutte($percorso, $query);
    }
}
if (!function_exists('maiuscole_nome')) {
    function maiuscole_nome(string $s): string { return \App\Anagrafi\Testi::maiuscoleNome($s); }
}
if (!function_exists('separa_cognome_nome')) {
    // L'elenco del portale dà "COGNOME NOME" e l'ID "nome.cognome"
    function separa_cognome_nome(string $nominativo, string $id): array { return \App\Anagrafi\Testi::separaCognomeNome($nominativo, $id); }
}
if (!function_exists('gruppo_personale')) {
    function gruppo_personale(string $ruolo_cod, bool $docente = false): string { return \App\Anagrafi\Testi::gruppoPersonale($ruolo_cod, $docente); }
}
if (!function_exists('maiuscole_corso')) {
    // "SCIENZE GEOLOGICHE" -> "Scienze geologiche"
    function maiuscole_corso(string $s): string { return \App\Anagrafi\Testi::maiuscoleCorso($s); }
}
if (!function_exists('sincronizza_anagrafe')) {
    // Scarica personale e corsi di studio delle strutture scelte; ritorna un riepilogo per struttura
    function sincronizza_anagrafe($conn, ?string $solo_struttura = null): array {
        return \App\Core\App::per($conn)->get(\App\Anagrafi\SincronizzazioneAnagrafe::class)->sincronizza($solo_struttura);
    }
}
if (!function_exists('scollega_utenti_senza_persona')) {
    // Utenti collegati a una persona tolta dall'anagrafe: collegamento azzerato
    function scollega_utenti_senza_persona($conn): void {
        \App\Core\App::per($conn)->get(\App\Anagrafi\CollegamentoUtente::class)->scollegaSenzaPersona();
    }
}
if (!function_exists('persona_ateneo')) {
    function persona_ateneo($conn, ?string $id): ?array {
        return \App\Core\App::per($conn)->get(\App\Anagrafi\ServizioPersone::class)->persona($id);
    }
}
if (!function_exists('anno_accademico_corrente')) {
    // Anno accademico in corso come lo usano le API (2026 = 2026/2027): da settembre quello nuovo
    function anno_accademico_corrente(): int { return \App\Anagrafi\Anagrafe::annoAccademico(new \DateTimeImmutable()); }
}
if (!function_exists('insegnamento')) {
    function insegnamento($conn, ?int $id): ?array {
        return \App\Core\App::per($conn)->get(\App\Anagrafi\ServizioCorsi::class)->insegnamento($id);
    }
}
if (!function_exists('etichetta_insegnamento')) {
    // "Fondamenti di informatica · 1° anno · Primo Semestre · Masciari Elio"
    function etichetta_insegnamento(array $i, bool $con_corso = false): string { return \App\Anagrafi\Testi::etichettaInsegnamento($i, $con_corso); }
}
if (!function_exists('insegnamenti_per_corso')) {
    // Insegnamenti presenti di un anno accademico, raggruppati per corso di studio
    function insegnamenti_per_corso($conn, ?int $anno = null): array {
        return \App\Core\App::per($conn)->get(\App\Anagrafi\ServizioCorsi::class)->insegnamentiPerCorso($anno);
    }
}
if (!function_exists('modifiche_persona')) {
    // Campi della scheda modificati dalla persona (Area personale); [] se non ne ha
    function modifiche_persona($conn, ?string $id): array {
        return \App\Core\App::per($conn)->get(\App\Anagrafi\ServizioPersone::class)->modifiche($id);
    }
}
if (!function_exists('scheda_persona')) {
    // Scheda da mostrare: dati del portale di Ateneo con sopra le modifiche della persona
    function scheda_persona($conn, array $p, ?array $det = null): array {
        return \App\Core\App::per($conn)->get(\App\Anagrafi\ServizioPersone::class)->scheda($p, $det);
    }
}
if (!function_exists('salva_modifiche_persona')) {
    // Salva i campi modificati dalla persona. Ritorna null o il messaggio d'errore.
    function salva_modifiche_persona($conn, string $id, array $post): ?string {
        return \App\Core\App::per($conn)->get(\App\Anagrafi\ServizioPersone::class)->salvaModifiche($id, $post);
    }
}
if (!function_exists('nome_persona')) {
    function nome_persona(array $p): string { return \App\Anagrafi\Testi::nomePersona($p); }
}
if (!function_exists('url_portale_persona')) {
    // Pagina della persona sul portale di Ateneo (scheda docente o rubrica)
    function url_portale_persona(array $p): string { return \App\Anagrafi\Testi::urlPortalePersona($p); }
}
if (!function_exists('dettaglio_persona')) {
    // Scheda completa dal portale, conservata 7 giorni; $aggiorna = false: nessuna chiamata di rete
    function dettaglio_persona($conn, array $p, bool $aggiorna = true): array {
        return \App\Core\App::per($conn)->get(\App\Anagrafi\ServizioPersone::class)->dettaglio($p, $aggiorna);
    }
}
if (!function_exists('html_avatar_persona')) {
    // Foto della persona (copia locale) o una sagoma grigio chiaro
    function html_avatar_persona(?string $foto, string $alt = '', int $lato = 48, string $base = ''): string {
        return \App\Anagrafi\Vista\Persone::avatar($foto, RADICE_SITO, $alt, $lato, $base);
    }
}
if (!function_exists('html_referente_pubblico')) {
    // Riga "Contatti" di eventi e progetti: foto (o sagoma), ruolo, nome e recapiti
    function html_referente_pubblico($conn, array $rf, string $col_testo): string {
        $servizio = \App\Core\App::per($conn)->get(\App\Anagrafi\ServizioPersone::class);
        $pers = !empty($rf['persona_id']) ? $servizio->persona($rf['persona_id']) : null;
        $det = $pers ? $servizio->dettaglio($pers, false) : [];
        return \App\Anagrafi\Vista\Persone::referentePubblico($rf, $pers, $det, $pers ? \App\Anagrafi\Testi::nomePersona($pers) : '', RADICE_SITO, $col_testo);
    }
}
if (!function_exists('html_ricerca_personale')) {
    // Pannello "Cerca nell'anagrafe di Ateneo" (pagine del pannello)
    function html_ricerca_personale($conn, string $pulsante = 'Aggiungi', string $righe = ''): string {
        $persone = \App\Core\App::per($conn)->get(\App\Anagrafi\PersonaRepository::class);
        if ($persone->conta(true) === 0) return \App\Anagrafi\Vista\Persone::ricercaAnagrafeVuota(!empty($GLOBALS['is_full_admin']));
        $GLOBALS['usa_ricerca_personale'] = true;
        return \App\Anagrafi\Vista\Persone::ricerca($persone->opzioni('ruolo'), $persone->opzioni('struttura'), $pulsante, $righe, 'rp_' . bin2hex(random_bytes(3)));
    }
}
if (!function_exists('cerca_personale')) {
    // Ricerca per parole (cognome, nome, email, settore) con filtri facoltativi. Prima chi è in servizio.
    function cerca_personale($conn, string $q, string $gruppo = '', string $ruolo = '', string $struttura = '', int $limite = 20): array {
        return \App\Core\App::per($conn)->get(\App\Anagrafi\ServizioPersone::class)->cerca($q, $gruppo, $ruolo, $struttura, $limite);
    }
}
if (!function_exists('ids_gruppi_personale')) {
    // id dei gruppi (tabella ruoli) Docenti / PTA / Altro, per chiave
    function ids_gruppi_personale($conn): array {
        return \App\Core\App::per($conn)->get(\App\Anagrafi\CollegamentoUtente::class)->idsGruppi();
    }
}
if (!function_exists('assegna_permessi_gestore')) {
    // Abilita un utente su un'area (tutta o solo alcuni eventi), togliendo prima le abilitazioni precedenti
    function assegna_permessi_gestore($conn, int $pagina_id, int $uid, array $permessi, array $eventi_ids = []): void {
        \App\Core\App::per($conn)->get(\App\Anagrafi\PermessiGestori::class)->assegna($pagina_id, $uid, $permessi, $eventi_ids);
    }
}
if (!function_exists('applica_abilitazione')) {
    // Abilita un utente a un perimetro: 'area', 'attivita', 'progetti' / 'eventi', 'fsl', 'fsl_convenzioni', 'fsl_scuole'
    function applica_abilitazione($conn, int $uid, string $ambito, int $pagina_id, array $eventi_ids = [], int $da = 0): bool {
        return \App\Core\App::per($conn)->get(\App\Anagrafi\PermessiGestori::class)->applica($uid, $ambito, $pagina_id, $eventi_ids, $da);
    }
}
if (!function_exists('revoca_permessi_gestore')) {
    function revoca_permessi_gestore($conn, int $pagina_id, int $uid): void {
        \App\Core\App::per($conn)->get(\App\Anagrafi\PermessiGestori::class)->revoca($pagina_id, $uid);
    }
}
if (!function_exists('collega_utente_anagrafe')) {
    // Al login: collega l'utente alla persona dell'anagrafe, aggiorna il gruppo automatico e attiva le abilitazioni in attesa.
    // Ritorna i gruppi secondari aggiornati (per la sessione) o null se l'utente non esiste.
    function collega_utente_anagrafe($conn, int $uid, string $email_sso = ''): ?string {
        return \App\Core\App::per($conn)->get(\App\Anagrafi\CollegamentoUtente::class)->collega($uid, $email_sso);
    }
}
if (!function_exists('corsi_studio_visibili')) {
    // Corsi proposti nel campo "Corso di studio", raggruppati per tipo (Laurea, Laurea Magistrale…)
    function corsi_studio_visibili($conn): array {
        return \App\Core\App::per($conn)->get(\App\Anagrafi\ServizioCorsi::class)->visibili();
    }
}
if (!function_exists('corso_studio')) {
    function corso_studio($conn, ?string $codice): ?array {
        return \App\Core\App::per($conn)->get(\App\Anagrafi\ServizioCorsi::class)->corso($codice);
    }
}
if (!function_exists('url_corso_studio')) {
    // Pagina del corso sul portale di Ateneo (serve l'ID del regolamento didattico)
    function url_corso_studio(?array $c): string { return \App\Anagrafi\Testi::urlCorsoStudio($c); }
}
if (!function_exists('nome_scheda_corso')) {
    // Nome proposto nella scheda: "Corso di laurea in Biologia", …
    function nome_scheda_corso(array $c): string { return \App\Anagrafi\Testi::nomeSchedaCorso($c); }
}
if (!function_exists('html_scelta_corso_scheda')) {
    // Scheda di progetti ed eventi: uno o più corsi di studio dell'anagrafe + testo libero (name=struttura).
    // $scheda = riga di progetti_dettagli (corsi_codici, corso_codice, struttura). Nelle schede più vecchie il testo era il nome del corso scelto:
    // si toglie, perché il corso compare già nell'elenco.
    function html_scelta_corso_scheda($conn, ?array $scheda, string $id = 'schedaCorso'): string {
        $corsi = \App\Core\App::per($conn)->get(\App\Anagrafi\ServizioCorsi::class);
        $scelti = $corsi->corsiDi($scheda);
        $testo = (string)($scheda['struttura'] ?? '');
        foreach ($scelti as $c) if (mb_strtolower(trim($testo)) === mb_strtolower(\App\Anagrafi\Testi::nomeSchedaCorso($c))) $testo = '';
        return \App\Anagrafi\Vista\Corsi::sceltaScheda($corsi->visibili(), $scelti, $testo, $id);
    }
}
if (!function_exists('html_corso_pubblico')) {
    // Corsi di studio / struttura nelle schede pubbliche, con il link alla pagina di ogni corso scelto dall'anagrafe
    function html_corso_pubblico($conn, ?array $d, string $stile = ''): string {
        $corsi = \App\Core\App::per($conn)->get(\App\Anagrafi\ServizioCorsi::class);
        $testo = trim((string)($d['struttura'] ?? ''));
        $elenco = [];
        foreach ($corsi->corsiDi($d) as $corso) {
            if (empty($corso['regdid_id'])) $corso = $corsi->completaRegdid($corso);
            $elenco[] = [\App\Anagrafi\Testi::nomeSchedaCorso($corso), \App\Anagrafi\Testi::urlCorsoStudio($corso)];
        }
        // Un solo corso (schede più vecchie): il testo della struttura, se c'è, fa da nome del link
        if (count($elenco) === 1 && trim((string)($d['corsi_codici'] ?? '')) === '') {
            return \App\Anagrafi\Vista\Corsi::pubblico($testo !== '' ? $testo : \App\Anagrafi\Testi::etichettaCorso($corsi->corso($d['corso_codice'])), $elenco[0][1], $stile);
        }
        return \App\Anagrafi\Vista\Corsi::pubblicoElenco($testo, $elenco, $stile);
    }
}
if (!function_exists('html_campo_corso')) {
    // Campo "Corso di studio": tendina con i corsi dei dipartimenti dell'anagrafe
    function html_campo_corso($conn, string $campo, string $valore = '', string $attr = '', string $classi = 'form-select form-select-sm', string $id = ''): string {
        return \App\Anagrafi\Vista\Corsi::campo(\App\Core\App::per($conn)->get(\App\Anagrafi\ServizioCorsi::class)->visibili(), $campo, $valore, $attr, $classi, $id);
    }
}
if (!function_exists('avvisi_anagrafe')) {
    // Gestori e referenti che non sono più nell'anagrafe di Ateneo (cessati o trasferiti): per la dashboard
    function avvisi_anagrafe($conn): array {
        return \App\Core\App::per($conn)->get(\App\Anagrafi\ServizioPersone::class)->avvisi();
    }
}
if (!function_exists('assicura_campi_progetto')) {
    // Campo "Numero di partecipanti" dell'area, creato se manca (mostrato solo nei progetti dedicati alle scuole)
    function assicura_campi_progetto($conn, int $pagina_id): void {
        \App\Core\App::per($conn)->get(\App\Anagrafi\CampiProgettoRepository::class)->assicuraPartecipanti($pagina_id, CAMPO_PARTECIPANTI);
    }
}
