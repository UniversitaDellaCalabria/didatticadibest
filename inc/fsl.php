<?php
// inc/fsl.php - Prenotazioni di classe, convenzioni con le scuole (registro, verifica, documenti precompilati) e scheda di valutazione FSL.
// La logica sta in src/Fsl/ (ServizioConvenzioni, PrecompilazioneConvenzione, DocumentoConvenzione, ServizioValutazioniFsl, RegoleClasse, ...): qui restano le facciate.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

// Convenzione scuola-Dipartimento per la Formazione Scuola Lavoro: modelli (scaricati dal portale) e PEC predefiniti,
// sostituibili per ogni area in Impostazioni area (file caricato in uploads/modelli_convenzione/ oppure link)
if (!defined('CONV_URL_MODELLO'))  define('CONV_URL_MODELLO', \App\Fsl\Costanti::URL_MODELLO);
if (!defined('CONV_URL_ALLEGATO')) define('CONV_URL_ALLEGATO', \App\Fsl\Costanti::URL_ALLEGATO);
if (!defined('CONV_PEC'))          define('CONV_PEC', \App\Fsl\Costanti::PEC);
if (!defined('CONV_DURATA_ANNI'))  define('CONV_DURATA_ANNI', \App\Fsl\Costanti::DURATA_ANNI);
// Testo originale del modello per i segnaposto lasciati vuoti (resta evidenziato in giallo da completare)
if (!defined('CONV_SEGNAPOSTI'))   define('CONV_SEGNAPOSTI', \App\Fsl\Costanti::SEGNAPOSTI);
// Scheda di valutazione della struttura ospitante (convenzione, art. 3): voti da 1 a 5 e domande aperte
if (!defined('VALUTAZIONE_FSL_ASPETTI')) define('VALUTAZIONE_FSL_ASPETTI', \App\Fsl\Costanti::ASPETTI);
if (!defined('VALUTAZIONE_FSL_APERTE'))  define('VALUTAZIONE_FSL_APERTE', \App\Fsl\Costanti::APERTE);

if (!function_exists('prenotazione_di_classe')) {
    // Prenotazione fatta da un docente per una classe/gruppo: si chiede il numero di studenti (con min/max)
    // e il docente può inserire l'elenco degli studenti.
    // Progetti: quelli dedicati alle scuole. Eventi: "Dedicato alle scuole", "Attività di Formazione Scuola Lavoro"
    // o "Attestati per gli studenti della classe". $dett = riga di progetti_dettagli (null se assente).
    function prenotazione_di_classe(bool $is_progetto, ?array $dett): bool {
        return \App\Core\App::get(\App\Fsl\RegoleClasse::class)->prenotazioneDiClasse($is_progetto, $dett);
    }
}

if (!function_exists('dati_convenzione')) {
    // $cfg = riga di pagine_eventi dell'area: campi vuoti o non validi → valori predefiniti
    // Indirizzi sempre completi (servono anche nelle email): i file del portale diventano https://…/eventi/…
    function dati_convenzione(array $cfg): array {
        return \App\Core\App::get(\App\Fsl\ModelliConvenzione::class)->dati($cfg);
    }
}

if (!function_exists('html_istruzioni_convenzione')) {
    // Cosa fare quando la scuola non ha ancora la convenzione (pagina, email, Area personale)
    // $in_attesa = false: prenotazione già confermata a cui si chiede comunque la convenzione
    // $codice (della prenotazione): se l'area usa il modello del Dipartimento si offre la convenzione già compilata
    // con i dati della prenotazione (convenzione_precompilata.php); il codice non va scritto nella PEC.
    function html_istruzioni_convenzione(array $cfg, bool $per_email = false, string $codice = '', bool $in_attesa = true): string {
        return \App\Core\App::get(\App\Fsl\Vista\Istruzioni::class)->html($cfg, $per_email, $codice, $in_attesa);
    }
}

if (!function_exists('periodo_attivita')) {
    // Periodo da coprire con la convenzione: progetto dal/al, evento il giorno del turno; senza date: oggi
    function periodo_attivita(?string $inizio, ?string $fine, ?string $data_turno = null): array {
        return \App\Core\App::get(\App\Fsl\PeriodiConvenzione::class)->attivita($inizio, $fine, $data_turno);
    }
}

if (!function_exists('periodo_prenotazione')) {
    // $p con pd_inizio, pd_fine (progetti_dettagli) e data_turno
    function periodo_prenotazione(array $p): array {
        return \App\Core\App::get(\App\Fsl\PeriodiConvenzione::class)->prenotazione($p);
    }
}

if (!function_exists('convenzione_valida')) {
    // Convenzione del registro (pannello Formazione Scuola Lavoro) valida per TUTTO il periodo $dal-$al (default: oggi), null se non c'è.
    // Valida dal (data_stipula) vuoto = da sempre; valida fino al (scadenza) vuoto = senza scadenza.
    // $rileggi = true dopo averne registrata o modificata una nella stessa richiesta.
    function convenzione_valida($conn, ?string $codice, bool $rileggi = false, ?string $dal = null, ?string $al = null): ?array {
        return \App\Core\App::per($conn)->get(\App\Fsl\ServizioConvenzioni::class)->valida($codice, $rileggi, $dal, $al);
    }
}

if (!function_exists('convenzioni_della_scuola')) {
    // Tutte le convenzioni della scuola nel registro, dalla più recente
    function convenzioni_della_scuola($conn, ?string $codice): array {
        return \App\Core\App::per($conn)->get(\App\Fsl\ServizioConvenzioni::class)->dellaScuola($codice);
    }
}

if (!function_exists('testo_validita_convenzione')) {
    // "dal 01/10/2026 al 30/09/2029", "fino al …", "senza scadenza"
    function testo_validita_convenzione(array $c): string {
        return \App\Fsl\PeriodiConvenzione::testoValidita($c);
    }
}

if (!function_exists('dati_prenotazione_convenzione')) {
    // Prenotazione con turno, evento, periodo dell'attività e impostazioni dell'area (modelli e PEC della convenzione)
    function dati_prenotazione_convenzione($conn, int $pr_id): ?array {
        return \App\Core\App::per($conn)->get(\App\Fsl\ServizioConvenzioni::class)->datiPrenotazione($pr_id);
    }
}

if (!function_exists('email_richiesta_convenzione')) {
    // Email alla scuola con modelli e PEC. $tipo: 'richiesta' (pulsante in Iscrizioni) | 'promemoria' (cron)
    function email_richiesta_convenzione($conn, int $pr_id, string $tipo = 'richiesta'): bool {
        return \App\Core\App::per($conn)->get(\App\Fsl\ServizioConvenzioni::class)->richiedi($pr_id, $tipo);
    }
}

if (!function_exists('salva_convenzione')) {
    // Registra (id = 0) o modifica una convenzione. $d: scuola_codice, data_stipula (valida dal), scadenza (valida fino al),
    // protocollo, note, docenti (array di ['nome' =>, 'email' =>]), file_convenzione, file_allegato (percorsi già salvati; null = invariati).
    // Ritorna [id, prenotazioni aggiornate] oppure null (dati non validi).
    function salva_convenzione($conn, array $d, int $id = 0, string $autore = ''): ?array {
        return \App\Core\App::per($conn)->get(\App\Fsl\ServizioConvenzioni::class)->salva($d, $id, $autore);
    }
}

if (!function_exists('periodo_nuova_convenzione')) {
    // Validità proposta per una convenzione appena arrivata: da oggi (o dall'inizio dell'attività, se prima)
    // per la durata predefinita, allungata se l'attività finisce dopo
    function periodo_nuova_convenzione(?string $att_dal = null, ?string $att_al = null): array {
        return \App\Core\App::get(\App\Fsl\PeriodiConvenzione::class)->nuova($att_dal, $att_al);
    }
}

if (!function_exists('convenzione_ricevuta_da_gestore')) {
    // Un gestore conferma che la convenzione è arrivata: se la scuola è dell'anagrafe e nel registro non c'è una convenzione
    // che copre il periodo dell'attività, la si registra, così vale anche per le altre prenotazioni e le prossime iscrizioni.
    function convenzione_ricevuta_da_gestore($conn, int $pr_id, string $autore = ''): bool {
        return \App\Core\App::per($conn)->get(\App\Fsl\ServizioConvenzioni::class)->ricevutaDaGestore($pr_id, $autore);
    }
}

if (!function_exists('dati_convenzione_precompilata')) {
    // Dati per la convenzione precompilata (modelli_documenti/convenzione_precompilabile.docx): scuola dall'anagrafe
    // (istituto principale se c'è), attività, studenti, periodo, durata e tutor. Vuoto = campo lasciato da compilare.
    function dati_convenzione_precompilata($conn, array $p): array {
        return \App\Core\App::per($conn)->get(\App\Fsl\PrecompilazioneConvenzione::class)->dati($p);
    }
}

if (!function_exists('genera_docx_convenzione')) {
    // Documento (.docx) dal modello del Dipartimento ('convenzione' o 'allegato'): $scuola = segnaposto della scuola e del Dirigente,
    // $attivita = una riga per attività (TITOLO, DESCRIZIONE, STUDENTI, PERIODO, DURATA, TUTOR_DIBEST, TUTOR_SCUOLA): il blocco
    // dell'Allegato A ("Titolo corso" … riga tratteggiata) si ripete per ogni attività. $logo = immagine della scuola in testa.
    // I campi compilati perdono l'evidenziazione gialla; quelli vuoti restano evidenziati con il testo originale.
    function genera_docx_convenzione(string $doc, array $scuola, array $attivita, ?string $logo = null, string $protocollo = ''): ?string {
        return \App\Core\App::get(\App\Fsl\DocumentoConvenzione::class)->genera($doc, $scuola, $attivita, $logo, $protocollo);
    }
}

if (!function_exists('genera_convenzione_precompilata')) {
    // Convenzione o Allegato A precompilati con i dati di una prenotazione (link "già compilata" delle email precedenti)
    function genera_convenzione_precompilata($conn, int $pr_id, string $doc = 'convenzione'): ?string {
        return \App\Core\App::per($conn)->get(\App\Fsl\PrecompilazioneConvenzione::class)->genera($pr_id, $doc);
    }
}

if (!function_exists('verifica_convenzioni_fsl')) {
    // Controllo delle iscrizioni delle attività di Formazione Scuola Lavoro non ancora concluse, anche già confermate:
    // - scuola dell'anagrafe con una convenzione che copre il periodo → "ricevuta" (chi era in attesa viene confermato e avvisato);
    // - nessuna convenzione valida per il periodo → "da stipulare" (lo stato della prenotazione non cambia e non parte
    //   nessuna email: la richiesta la invia il gestore da Iscrizioni, poi seguono i promemoria);
    // - scuola scritta a mano → non verificabile (va abbinata all'anagrafe).
    // Ritorna ['coperte' => n, 'da_stipulare' => n, 'nuove_da_stipulare' => n, 'senza_codice' => n].
    function verifica_convenzioni_fsl($conn): array {
        return \App\Core\App::per($conn)->get(\App\Fsl\ServizioConvenzioni::class)->verifica();
    }
}

// =======================================================================
// SCHEDA DI VALUTAZIONE DELLA STRUTTURA OSPITANTE (FSL)
// La convenzione (art. 3) prevede che la scuola valuti la struttura ospitante: a fine attività FSL il docente
// che ha prenotato riceve un link personale (valutazione_fsl.php?t=…). Le risposte sono legate alla scuola.
// =======================================================================
if (!function_exists('prenotazione_da_valutazione')) {
    // Prenotazione (con attività, periodo e scuola) dal link personale della scheda di valutazione
    function prenotazione_da_valutazione($conn, string $token): ?array {
        return \App\Core\App::per($conn)->get(\App\Fsl\ServizioValutazioniFsl::class)->prenotazioneDaToken($token);
    }
}

if (!function_exists('invia_invito_valutazione')) {
    // Email al docente con il link alla scheda. $promemoria = true per il secondo invio.
    function invia_invito_valutazione($conn, int $pr_id, bool $promemoria = false): bool {
        return \App\Core\App::per($conn)->get(\App\Fsl\ServizioValutazioniFsl::class)->invia($pr_id, $promemoria);
    }
}

if (!function_exists('salva_valutazione_fsl')) {
    // Salva la scheda (una sola per prenotazione). Ritorna null se va bene, altrimenti il messaggio d'errore.
    function salva_valutazione_fsl($conn, array $p, array $post): ?string {
        return \App\Core\App::per($conn)->get(\App\Fsl\ServizioValutazioniFsl::class)->salva($p, $post);
    }
}

if (!function_exists('attestati_di_classe')) {
    // Attestati per ogni studente dell'elenco inserito da chi ha prenotato.
    // $p = riga con evento_tipo (o tipo), per_scuole e attestati (es. prenotazione_per_attestati).
    function attestati_di_classe(array $p): bool {
        return \App\Core\App::get(\App\Fsl\RegoleClasse::class)->attestatiDiClasse($p);
    }
}

if (!function_exists('attivita_conclusa_classe')) {
    // Quando si possono emettere gli attestati della classe: progetti dopo la data di fine,
    // eventi dopo il giorno del turno (turno senza data: subito, cioè dopo il check-in).
    function attivita_conclusa_classe(array $p): bool {
        return \App\Core\App::get(\App\Fsl\RegoleClasse::class)->attivitaConclusaClasse($p);
    }
}

if (!function_exists('campo_form_visibile')) {
    // Il campo "numero di partecipanti" vale solo per le prenotazioni di classe (progetti per le scuole,
    // eventi con attestati per gli studenti): altrove non va mostrato né richiesto.
    // $dett = riga di progetti_dettagli dell'evento/progetto (null se assente).
    function campo_form_visibile(array $cf, bool $is_progetto, ?array $dett): bool {
        return \App\Core\App::get(\App\Fsl\RegoleClasse::class)->campoFormVisibile($cf, $is_progetto, $dett);
    }
}
