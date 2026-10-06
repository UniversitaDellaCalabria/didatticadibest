<?php
// inc/eventi_progetti.php - Eventi, turni e progetti: permessi, duplicazione, eliminazione, widget della home, schede dei progetti.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

// =======================================================================
// EVENTI E TURNI: permessi, duplicazione, eliminazione (usate da admin/eventi.php e admin/archivio.php)
// =======================================================================
if (!function_exists('ev_autorizzato')) {
    // Evento dell'area corrente e visibile al gestore ($sql_filtro_eventi_rbac di admin_header.php)
    function ev_autorizzato($conn, int $ev_id, int $p_id, string $rbac): bool {
        return \App\Core\App::per($conn)->get(\App\Auth\Abilitazioni\ServizioAbilitazioni::class)->attivitaAutorizzata($ev_id, $p_id, $rbac);
    }
}
if (!function_exists('turno_autorizzato')) {
    function turno_autorizzato($conn, int $t_id, int $p_id, string $rbac): bool {
        return \App\Core\App::per($conn)->get(\App\Auth\Abilitazioni\ServizioAbilitazioni::class)->turnoAutorizzato($t_id, $p_id, $rbac);
    }
}
if (!function_exists('pren_autorizzata')) {
    // Prenotazione di un evento dell'area corrente visibile al gestore
    function pren_autorizzata($conn, int $pr_id, int $p_id, string $rbac): bool {
        return \App\Core\App::per($conn)->get(\App\Auth\Abilitazioni\ServizioAbilitazioni::class)->prenotazioneAutorizzata($pr_id, $p_id, $rbac);
    }
}
if (!function_exists('nega_accesso')) {
    function nega_accesso(): void { \App\Auth\RispostaHttp::negaAccesso(); }
}

if (!function_exists('colonne_copiabili')) {
    // Colonne di una tabella (escluso id): la copia resta corretta anche se lo schema cambia
    function colonne_copiabili($conn, string $tabella, array $escludi = []): array {
        return \App\Core\App::per($conn)->get(\App\Eventi\EventoRepository::class)->colonneCopiabili($tabella, $escludi);
    }
}

if (!function_exists('duplica_turno')) {
    // Duplica un turno (stessi dati, nessuna prenotazione) nell'evento indicato. Ritorna il nuovo id.
    function duplica_turno($conn, int $t_id, int $ev_dest, bool $segna_copia): int {
        return \App\Core\App::per($conn)->get(\App\Eventi\ServizioEventi::class)->duplicaTurno($t_id, $ev_dest, $segna_copia);
    }
}

if (!function_exists('duplica_evento')) {
    // Copia un evento (titolo "(copia)", non archiviato) con campi del form e sondaggi (non attivi,
    // condizioni "mostra se" ricollegate alle domande nuove). $con_turni: copia anche i turni, senza iscritti.
    // Ritorna ['evento' => id, 'turni' => n, 'sondaggi' => n]; in caso di errore annulla tutto e lancia l'eccezione.
    function duplica_evento($conn, int $ev_id, bool $con_turni = true): array {
        return \App\Core\App::per($conn)->get(\App\Eventi\ServizioEventi::class)->duplicaEvento($ev_id, $con_turni);
    }
}

if (!function_exists('elimina_turno')) {
    // Elimina un turno con le sue prenotazioni e i messaggi collegati. Da usare dentro una transazione se serve.
    function elimina_turno($conn, int $t_id): void {
        \App\Core\App::per($conn)->get(\App\Eventi\ServizioEventi::class)->eliminaTurno($t_id);
    }
}

if (!function_exists('elimina_evento')) {
    // Elimina definitivamente un evento e TUTTO ciò che dipende da lui (turni, prenotazioni, messaggi,
    // campi del form, sondaggi con domande e risposte), in un'unica transazione: niente dati orfani.
    function elimina_evento($conn, int $ev_id): bool {
        return \App\Core\App::per($conn)->get(\App\Eventi\ServizioEventi::class)->eliminaEvento($ev_id);
    }
}

// =======================================================================
// WIDGET HOME: configurazione (JSON in configurazione_portale.widgets_home)
// =======================================================================
if (!function_exists('get_prenotazioni_attive_utente')) {
    // Prenotazioni ancora da vivere dell'utente (turno non concluso), la più urgente per prima:
    // prima i posti offerti da confermare, poi per data; i turni senza data in coda.
    function get_prenotazioni_attive_utente($conn, int $u_id, int $limite = 10): array {
        return \App\Core\App::per($conn)->get(\App\Iscrizioni\ServizioDisponibilita::class)->attiveDellUtente($u_id, $limite);
    }
}

if (!function_exists('get_posizioni_lista_attesa')) {
    // Posizione in coda (1 = il prossimo a essere promosso) delle prenotazioni 'in_attesa' indicate.
    // Stesso ordine di promuovi_lista_attesa: data di prenotazione, a parità l'id.
    // Ritorna [pr_id => ['posizione' => n, 'totale' => persone in coda nel turno]].
    function get_posizioni_lista_attesa($conn, array $pr_ids): array {
        return \App\Core\App::per($conn)->get(\App\Iscrizioni\ServizioDisponibilita::class)->posizioniInCoda($pr_ids);
    }
}

if (!function_exists('get_turni_ultimi_posti')) {
    // Turni prenotabili adesso con pochi posti (<= 10% della capienza, almeno 1)
    // o con iscrizioni che chiudono entro 48 ore. Un solo turno per evento, i più urgenti prima.
    function get_turni_ultimi_posti($conn, array $pagine_ids, int $limite = 4): array {
        return \App\Core\App::per($conn)->get(\App\Iscrizioni\ServizioDisponibilita::class)->turniUltimiPosti($pagine_ids, $limite);
    }
}

if (!function_exists('get_riepilogo_posti')) {
    // Capienza e posti occupati dei turni ancora prenotabili, raggruppati per evento o per area.
    // $per = 'evento' | 'pagina'. Ritorna [id => ['capienza'=>, 'occupati'=>, 'liberi'=>]].
    // Esclusi: eventi senza prenotazione, turni senza limite (>= POSTI_SENZA_LIMITE), conclusi o con iscrizioni chiuse.
    function get_riepilogo_posti($conn, string $per, array $ids): array {
        return \App\Core\App::per($conn)->get(\App\Iscrizioni\ServizioDisponibilita::class)->riepilogoPosti($per, $ids);
    }
}

if (!function_exists('widgets_home_default')) {
    // Facciata di App\Portale\WidgetHome::predefiniti
    function widgets_home_default(): array {
        return \App\Portale\WidgetHome::predefiniti();
    }
}

if (!function_exists('get_widgets_home')) {
    // Legge la configurazione salvata e la normalizza (valori non validi -> default): App\Portale\WidgetHome
    function get_widgets_home(?array $cfg_portale): array {
        return \App\Core\App::get(\App\Portale\WidgetHome::class)->widgets($cfg_portale['widgets_home'] ?? null);
    }
}

// =======================================================================
// PROGETTI (es. Formazione Scuola Lavoro): un progetto è un evento con tipo = 'progetto',
// una scheda in progetti_dettagli e un turno per edizione.
// - Dedicato alle scuole (per_scuole = 1): ogni edizione accoglie UNA scuola (1 posto), le altre
//   in lista d'attesa in ordine di arrivo; la scuola indica il numero di partecipanti.
// - Generico: ogni edizione ha i suoi posti, una persona per posto, come gli eventi.
// =======================================================================
if (!defined('CAMPO_PARTECIPANTI')) define('CAMPO_PARTECIPANTI', 'numero_partecipanti');
// Turni con almeno tanti posti = senza un limite reale (es. 5000 per un evento online): niente contatore né barra,
// solo «Posti disponibili». Prima la soglia era 9000 e le somme dei turni mostravano «100.000 posti liberi».
if (!defined('POSTI_SENZA_LIMITE')) define('POSTI_SENZA_LIMITE', 1000);
if (!function_exists('testo_posti_liberi')) {
    function testo_posti_liberi(int $liberi): string {
        return \App\Iscrizioni\ServizioDisponibilita::testoPostiLiberi($liberi);
    }
}
// Dopo quanti mesi dalla fine del progetto i nomi degli studenti vengono ridotti alle iniziali (cron_background.php)
if (!defined('MESI_CONSERVAZIONE_STUDENTI')) define('MESI_CONSERVAZIONE_STUDENTI', 12);

if (!function_exists('get_dettagli_progetti')) {
    // Schede dei progetti indicati: [evento_id => riga di progetti_dettagli con referenti e info già decodificati]
    function get_dettagli_progetti($conn, array $ev_ids): array {
        return \App\Core\App::per($conn)->get(\App\Eventi\EventoRepository::class)->dettagliProgetti($ev_ids);
    }
}

if (!function_exists('destinazione_progetto')) {
    // Progetto che rimanda a un'altra pagina (es. OpenLab): la card resta nell'elenco dei progetti ma "Dettagli"
    // porta lì. progetti_dettagli.destinazione = slug di un'area del portale oppure indirizzo http(s).
    // Ritorna null se il progetto è normale, altrimenti ['url', 'nome', 'esterno'] (url relativo alla radice del sito).
    function destinazione_progetto($conn, ?array $d): ?array {
        return \App\Core\App::per($conn)->get(\App\Eventi\ServizioProgetti::class)->destinazione($d);
    }
}

if (!function_exists('html_pulsante_destinazione')) {
    // Pulsante "Vai a OPENLAB" dei progetti con rimando ($stile = colori del pulsante dell'area)
    function html_pulsante_destinazione(array $dest, string $stile): string {
        return \App\Eventi\Vista\Progetti::pulsanteDestinazione($dest, $stile);
    }
}

if (!function_exists('periodo_progetto')) {
    // "Dal 13/10/2026 al 18/12/2026", "Dal 13/10/2026", "Entro il 18/12/2026" oppure "Date da definire"
    function periodo_progetto(?array $d): string {
        return \App\Eventi\Progetti::periodo($d);
    }
}

if (!function_exists('stato_progetto')) {
    // Stato calcolato dalle date del progetto e dal turno di iscrizione.
    // $occupati = posti occupati del turno (0 o 1). Ritorna ['codice', 'etichetta', 'bg', 'fg', 'ordine'].
    function stato_progetto(?array $d, ?array $turno, int $occupati): array {
        return \App\Eventi\Progetti::stato($d, $turno, $occupati);
    }
}

if (!function_exists('info_edizioni_progetto')) {
    // Edizioni (repliche) di un progetto: ogni turno è un'edizione da 1 scuola con la sua lista d'attesa.
    // $mie = [turno_id => stato] dell'utente corrente. Ritorna le edizioni con posti, coda e scuola assegnata,
    // lo stato complessivo (stato_progetto su un turno "riassuntivo") e l'eventuale iscrizione dell'utente.
    function info_edizioni_progetto($conn, ?array $d, array $turni, array $mie = []): array {
        return \App\Core\App::per($conn)->get(\App\Eventi\ServizioProgetti::class)->infoEdizioni($d, $turni, $mie);
    }
}
